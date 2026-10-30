<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Support\Wei;
use Illuminate\Support\Facades\Log;

/**
 * ถามเงินฝากกับ RPC ทุก provider ของเชนต้นทางก่อน mint.
 *
 * ตัวสแกนเชื่อ RPC ตัวแรกที่ตอบ — provider ที่ถูกเจาะสร้างเงินฝากปลอมได้เอง แล้วระบบจะ mint จนถึงเพดาน minter
 * จึงต้องมีอย่างน้อยสองโฮสต์เห็น Transfer เดียวกัน ถ้าโฮสต์ไหนเห็นต่างให้หยุดเลย ไม่นับเสียงข้างมาก
 *
 * "ยังไม่เห็นใบเสร็จ" ไม่ใช่ข้อขัดแย้ง — โหนดตามหัวไม่ทันเกิดได้เป็นปกติ จึงแค่รอรอบหน้า
 */
class BridgeDepositCrossCheck
{
    public const CONFIRMED = 'confirmed';

    /** ตั้ง RPC ไว้โฮสต์เดียว — ไม่มีอะไรให้เทียบ ทำงานแบบเดิม */
    public const SINGLE_SOURCE = 'single_source';

    public const PENDING = 'pending';

    public const MISMATCH = 'mismatch';

    private const REQUIRED_SOURCES = 2;

    public function __construct(private BridgeConfig $config) {}

    public function check(BridgeDeposit $deposit): string
    {
        $rpc = $this->config->rpc((int) $deposit->source_chain_id);
        if ($rpc === null) {
            return self::PENDING;
        }

        $byHost = [];
        foreach ($rpc->urls() as $url) {
            $byHost[(string) parse_url($url, PHP_URL_HOST)] ??= $url;
        }

        if (count($byHost) < self::REQUIRED_SOURCES) {
            Log::warning('bridge: เชนนี้มี RPC โฮสต์เดียว ตรวจเงินฝากไขว้ไม่ได้', ['chain' => $deposit->source_chain_id, 'deposit' => $deposit->id]);

            return self::SINGLE_SOURCE;
        }

        $seen = 0;
        foreach ($byHost as $host => $url) {
            $receipt = $rpc->only($url)->receipt((string) $deposit->tx_hash);

            if ($receipt === null) {
                continue;
            }

            if (! $this->receiptHasDeposit($receipt, $deposit)) {
                Log::critical('bridge: RPC เห็นเงินฝากไม่ตรงกัน', ['chain' => $deposit->source_chain_id, 'deposit' => $deposit->id, 'host' => $host]);

                return self::MISMATCH;
            }

            $seen++;
        }

        return $seen >= self::REQUIRED_SOURCES ? self::CONFIRMED : self::PENDING;
    }

    private function receiptHasDeposit(array $receipt, BridgeDeposit $deposit): bool
    {
        if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            return false;
        }

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

            if (strtolower((string) ($log['address'] ?? '')) === strtolower((string) $deposit->token_address)
                && (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0')) === (int) $deposit->log_index
                && ($topics[0] ?? null) === Abi::topic(Abi::TRANSFER_EVENT)
                && Abi::topicAddress($topics[1] ?? null) === strtolower((string) $deposit->from_address)
                && Abi::topicAddress($topics[2] ?? null) === strtolower((string) $deposit->to_address)
                && bccomp(Wei::hexToInt((string) ($log['data'] ?? '0x0')), (string) $deposit->amount_raw, 0) === 0) {
                return true;
            }
        }

        return false;
    }
}
