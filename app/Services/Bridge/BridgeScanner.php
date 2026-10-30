<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeWithdrawal;
use App\Models\SiteSetting;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * ตัวสแกนของสะพาน — อ่าน event จากเชนจริงแล้วบันทึกเป็นรายการ ครั้งเดียวต่อหนึ่ง log.
 *
 * ฝาก: Transfer(from, to = vault, value) ของสัญญา USDT บนแต่ละเชน — อ่านถึง "หัวที่ปลอดภัย"
 *      (finalized และ/หรือ N confirmations) เท่านั้น บล็อกที่ยังย้อนได้ไม่ถูกบันทึกเลย
 *      reorg จึงทำให้เกิดรายการผีไม่ได้ ราคาที่จ่ายคือรอ ~15 นาทีบน Ethereum
 * ถอน: Burned ของ USDT_TPIX บนเชน 4289 (IBFT ปิดบล็อกแล้วจบ ใช้ 2 confirmations)
 *
 * เคอร์เซอร์เก็บใน site_settings (แคชถูกล้างตอน deploy แล้วต้องไล่ใหม่ทั้งหมด — ไม่เก็บในแคช)
 * กติกาเคอร์เซอร์: ขยับเฉพาะหลังอ่านช่วงนั้นครบและบันทึกทุก log สำเร็จ
 *   RPC ล้มกลางคัน → หยุดตรงนั้น รอบหน้าเริ่มที่เดิม (อ่านซ้ำได้ปลอดภัยเพราะคีย์ไม่ซ้ำ)
 *
 * รอบแรกของแต่ละเชนเริ่มที่หัวปัจจุบัน ไม่ไล่ย้อนหลัง — vault เคยรับ USDT มาก่อนเปิดสะพาน
 * (เงินหนุน 19.99 เดิม) ถ้าไล่ย้อนจะ mint ซ้ำให้เงินที่หนุนอยู่แล้ว
 */
class BridgeScanner
{
    public function __construct(
        private BridgeConfig $config,
        private BridgePolicy $policy,
    ) {}

    /**
     * @return array{chain: int, recorded: int, from: ?int, to: ?int, head: ?int, error: ?string}
     */
    public function scanDeposits(int $chainId): array
    {
        $summary = ['chain' => $chainId, 'recorded' => 0, 'from' => null, 'to' => null, 'head' => null, 'error' => null];
        $chain = $this->config->chain($chainId);
        $rpc = $this->config->rpc($chainId);

        if (! $chain || ! $rpc) {
            return ['error' => 'unknown_chain'] + $summary;
        }

        $token = strtolower((string) ($chain['usdt']['address'] ?? ''));
        $decimals = (int) ($chain['usdt']['decimals'] ?? 6);
        if (! preg_match('/^0x[0-9a-f]{40}$/', $token)) {
            return ['error' => 'token_not_configured'] + $summary;
        }

        $head = $rpc->safeHead(
            (string) ($chain['finality'] ?? 'confirmations'),
            (int) ($chain['min_confirmations'] ?? 12),
            (int) ($chain['fallback_confirmations'] ?? 64),
        );
        $summary['head'] = $head;

        if ($head === null) {
            return ['error' => 'rpc_unavailable'] + $summary;
        }

        $cursorKey = "deposit_{$chainId}";
        $cursor = $this->startCursor($cursorKey, $chain['start_block'] ?? null, $head);
        $from = $this->rescanFrom($cursorKey, $cursor, (int) ($chain['rescan_blocks'] ?? 0));
        // งบบล็อกต่อรอบนับจากเคอร์เซอร์ (บล็อกใหม่) — ช่วงย้อนทับไม่กินงบ
        $end = min($head, $cursor + max(1, (int) ($chain['max_blocks_per_run'] ?? 20000)) - 1);
        $chunk = max(1, (int) ($chain['log_chunk'] ?? 1000));
        $summary['from'] = $from;

        $topics = [Abi::topic(Abi::TRANSFER_EVENT), null, '0x'.Abi::encAddress($this->config->vaultAddress())];

        while ($from <= $end) {
            $to = min($from + $chunk - 1, $end);
            $logs = $rpc->logs($token, $topics, $from, $to);

            if ($logs === null) {
                $summary['error'] = 'rpc_logs_failed';
                break;
            }

            // เรียงตามบล็อก/ตำแหน่ง — ลำดับมีผลกับวงเงินรายวันของกระเป๋า (คนที่ฝากก่อนต้องได้ก่อน)
            usort($logs, fn ($a, $b) => [Wei::hexToInt($a['blockNumber'] ?? '0x0'), Wei::hexToInt($a['logIndex'] ?? '0x0')]
                <=> [Wei::hexToInt($b['blockNumber'] ?? '0x0'), Wei::hexToInt($b['logIndex'] ?? '0x0')]);

            foreach ($logs as $log) {
                $result = $this->recordDeposit($chainId, $token, $decimals, $rpc, $log);

                if ($result === null) {
                    // อ่านข้อมูลประกอบ (โค้ดผู้ส่ง/ธุรกรรม) ไม่ได้ — ไม่ขยับเคอร์เซอร์ผ่านช่วงนี้
                    $summary['error'] = 'rpc_facts_failed';
                    break 2;
                }

                if ($result === true) {
                    $summary['recorded']++;
                }
            }

            $cursor = $this->advanceCursor($cursorKey, $cursor, $to + 1);
            $summary['to'] = $to;
            $from = $to + 1;
        }

        if ($summary['error'] === null) {
            SiteSetting::set(BridgeConfig::CURSOR_GROUP, "scan_ok_at_{$chainId}", now()->toIso8601String(), 'string');
        }

        return $summary;
    }

    /**
     * บันทึก Transfer หนึ่ง log — true = บันทึกใหม่ · false = มีอยู่แล้ว/ไม่เกี่ยว · null = อ่านข้อมูลประกอบไม่ได้.
     */
    public function recordDeposit(int $chainId, string $token, int $decimals, EvmRpc $rpc, array $log): ?bool
    {
        $txHash = strtolower((string) ($log['transactionHash'] ?? ''));
        $logIndex = (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0'));
        $blockNumber = (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0'));
        $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

        // log ต้องมาจากสัญญา USDT ตัวจริงเท่านั้น — สัญญาปลอมยิง event หน้าตาเดียวกันได้
        if (strtolower((string) ($log['address'] ?? '')) !== $token
            || ($log['removed'] ?? false) === true
            || ! preg_match('/^0x[0-9a-f]{64}$/', $txHash)
            || $blockNumber <= 0
            || count($topics) < 3
            || $topics[0] !== Abi::topic(Abi::TRANSFER_EVENT)
            || Abi::topicAddress($topics[2]) !== $this->config->vaultAddress()) {
            return false;
        }

        if (BridgeDeposit::where('source_chain_id', $chainId)->where('tx_hash', $txHash)->where('log_index', $logIndex)->exists()) {
            return false;
        }

        $from = Abi::topicAddress($topics[1]);
        if ($from === null) {
            return false;
        }

        $amountRaw = Wei::hexToInt((string) ($log['data'] ?? '0x0'));
        ['units' => $units, 'dust' => $dust] = Abi::toUnits6($amountRaw, $decimals);

        // ยอดศูนย์ / ฝุ่นที่ไม่ถึง 0.000001 — ไม่มีอะไรให้ mint และไม่มีอะไรให้ตรวจ ไม่บันทึกเลย
        // (Transfer มูลค่า 0 ของ USDT ใครก็ยิงใส่ vault ได้ฟรีด้วย transferFrom — address poisoning
        //  ถ้าบันทึกทุกแถว ฐานข้อมูลกับหน้าหลังบ้านจะถูกถมด้วยขยะ)
        if (bccomp($units, '0', 0) === 0) {
            return false;
        }
        $fee = $this->config->limitUnits('deposit_fee');
        $mint = bccomp($units, $fee, 0) > 0 ? bcsub($units, $fee, 0) : '0';

        $facts = [
            'from_address' => $from,
            'amount_units' => $units,
            'mint_units' => $mint,
            'sender_is_contract' => false,
            'sender_mismatch' => false,
        ];

        // ข้อมูลประกอบต้องยิง RPC เพิ่ม — ถามเฉพาะรายการที่มีสิทธิ์ได้ mint จริง
        // (Transfer มูลค่า 0 / ฝุ่น ถูกยิงใส่ vault ได้ฟรีเป็นพัน ๆ ครั้ง ไม่ควรกิน RPC ตามไปด้วย)
        $cheap = $this->policy->evaluateDeposit($facts);
        $decision = $cheap;

        if ($cheap['status'] !== BridgeDeposit::STATUS_IGNORED) {
            $sender = $this->policy->senderFacts($rpc, $from, $txHash);

            if ($sender === null) {
                return null;
            }

            $facts = $sender + $facts;
            $decision = $this->policy->evaluateDeposit($facts);
        }

        try {
            BridgeDeposit::create([
                'source_chain_id' => $chainId,
                'tx_hash' => $txHash,
                'log_index' => $logIndex,
                'block_number' => $blockNumber,
                'block_hash' => is_string($log['blockHash'] ?? null) ? strtolower($log['blockHash']) : null,
                'from_address' => $from,
                'to_address' => $this->config->vaultAddress(),
                'token_address' => $token,
                'token_decimals' => $decimals,
                'amount_raw' => $amountRaw,
                'dust_raw' => $dust,
                'amount_units' => $units,
                'fee_units' => bccomp($units, '0', 0) > 0 ? bcsub($units, $mint, 0) : '0',
                'mint_units' => $mint,
                'deposit_id' => Abi::depositId($chainId, $txHash, $logIndex),
                'status' => $decision['status'],
                'review_reason' => $decision['reason'],
            ]);
        } catch (QueryException $e) {
            // อีกโปรเซสบันทึกไปพร้อมกันพอดี — unique (เชน, tx, log) กันไว้แล้ว ไม่ใช่ความผิดพลาด
            if (BridgeDeposit::where('source_chain_id', $chainId)->where('tx_hash', $txHash)->where('log_index', $logIndex)->exists()) {
                return false;
            }

            throw $e;
        }

        Log::info('bridge: บันทึกเงินฝาก', [
            'chain' => $chainId,
            'tx' => $txHash,
            'log' => $logIndex,
            'from' => $from,
            'units' => $units,
            'status' => $decision['status'],
            'reason' => $decision['reason'],
        ]);

        return true;
    }

    /**
     * @return array{chain: int, recorded: int, from: ?int, to: ?int, head: ?int, error: ?string}
     */
    public function scanWithdrawals(): array
    {
        $chainId = $this->config->tpixChainId();
        $summary = ['chain' => $chainId, 'recorded' => 0, 'from' => null, 'to' => null, 'head' => null, 'error' => null];
        $token = $this->config->usdtTpixAddress();

        if ($token === null) {
            return ['error' => 'usdt_tpix_not_configured'] + $summary;
        }

        $rpc = $this->config->tpixRpc();
        $latest = $rpc->blockNumber();
        if ($latest === null) {
            return ['error' => 'rpc_unavailable'] + $summary;
        }

        $head = $latest - (int) config('bridge.tpix.confirmations', 2);
        $summary['head'] = $head;

        $cursorKey = "burn_{$chainId}";
        $cursor = $this->startCursor($cursorKey, config('bridge.tpix.start_block'), $head);
        $from = $this->rescanFrom($cursorKey, $cursor, (int) config('bridge.tpix.rescan_blocks', 0));
        $end = min($head, $cursor + max(1, (int) config('bridge.tpix.max_blocks_per_run', 18000)) - 1);
        // polygon-edge ปฏิเสธ eth_getLogs ช่วงเกิน 1000 บล็อก
        $chunk = min(900, max(1, (int) config('bridge.tpix.log_chunk', 900)));
        $summary['from'] = $from;

        while ($from <= $end) {
            $to = min($from + $chunk - 1, $end);
            $logs = $rpc->logs($token, [Abi::topic(Abi::BURNED_EVENT)], $from, $to);

            if ($logs === null) {
                $summary['error'] = 'rpc_logs_failed';
                break;
            }

            foreach ($logs as $log) {
                if ($this->recordWithdrawal($token, $log)) {
                    $summary['recorded']++;
                }
            }

            $cursor = $this->advanceCursor($cursorKey, $cursor, $to + 1);
            $summary['to'] = $to;
            $from = $to + 1;
        }

        if ($summary['error'] === null) {
            SiteSetting::set(BridgeConfig::CURSOR_GROUP, "scan_ok_at_{$chainId}", now()->toIso8601String(), 'string');
        }

        return $summary;
    }

    /** บันทึก Burned หนึ่ง log — true = บันทึกใหม่ */
    public function recordWithdrawal(string $token, array $log): bool
    {
        $txHash = strtolower((string) ($log['transactionHash'] ?? ''));
        $logIndex = (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0'));
        $blockNumber = (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0'));
        $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

        if (strtolower((string) ($log['address'] ?? '')) !== strtolower($token)
            || ($log['removed'] ?? false) === true
            || ! preg_match('/^0x[0-9a-f]{64}$/', $txHash)
            || $blockNumber <= 0
            || count($topics) < 3
            || $topics[0] !== Abi::topic(Abi::BURNED_EVENT)) {
            return false;
        }

        if (BridgeWithdrawal::where('tx_hash', $txHash)->where('log_index', $logIndex)->exists()) {
            return false;
        }

        $from = Abi::topicAddress($topics[1]);
        $decoded = Abi::decodeBurnData((string) ($log['data'] ?? ''));
        if ($from === null || $decoded === null) {
            Log::warning('bridge: ถอด Burned ไม่ได้ — ข้าม', ['tx' => $txHash, 'log' => $logIndex]);

            return false;
        }

        // chain id ใน event เป็น uint256 — ใครจะใส่เลขมหาศาลก็ได้ เก็บดิบไว้ ใช้ได้เฉพาะที่อยู่ในช่วง int
        $chainRaw = Wei::hexToInt($topics[2]);
        $targetChainId = bccomp($chainRaw, (string) PHP_INT_MAX, 0) <= 0 ? (int) $chainRaw : null;
        $chain = $targetChainId !== null ? $this->config->chain($targetChainId) : null;

        $target = $this->sanitizeTarget($decoded['target']);
        $amount = $decoded['amount'];
        $fee = $chain ? $this->config->withdrawFeeUnits($targetChainId) : '0';
        $payout = bccomp($amount, $fee, 0) > 0 ? bcsub($amount, $fee, 0) : '0';
        $tokenDecimals = (int) ($chain['usdt']['decimals'] ?? 6);

        $decision = $this->policy->evaluateWithdrawal([
            'target_chain_id' => $chain ? $targetChainId : null,
            'target_address' => $target,
            'amount_units' => $amount,
            'payout_units' => $payout,
            'from_address' => $from,
        ]);

        try {
            BridgeWithdrawal::create([
                'tx_hash' => $txHash,
                'log_index' => $logIndex,
                'block_number' => $blockNumber,
                'from_address' => $from,
                'amount_units' => $amount,
                'target_chain_raw' => mb_substr($chainRaw, 0, 80),
                'target_chain_id' => $chain ? $targetChainId : null,
                'target_address' => $target,
                'fee_units' => $chain ? bcsub($amount, $payout, 0) : '0',
                'payout_units' => $payout,
                'payout_amount_raw' => $chain ? Abi::fromUnits6($payout, $tokenDecimals) : '0',
                'payout_token' => $chain ? strtolower((string) $chain['usdt']['address']) : null,
                'status' => $decision['status'],
                'review_reason' => $decision['reason'],
            ]);
        } catch (QueryException $e) {
            if (BridgeWithdrawal::where('tx_hash', $txHash)->where('log_index', $logIndex)->exists()) {
                return false;
            }

            throw $e;
        }

        Log::info('bridge: บันทึกคำขอถอน', [
            'tx' => $txHash,
            'log' => $logIndex,
            'from' => $from,
            'units' => $amount,
            'target_chain' => $chainRaw,
            'status' => $decision['status'],
            'reason' => $decision['reason'],
        ]);

        return true;
    }

    /**
     * ที่อยู่ปลายทางเป็นข้อความที่ผู้ใช้พิมพ์เอง — อาจมีช่องว่าง ตัวอักษรควบคุม หรือไบต์ที่ไม่ใช่ UTF-8
     * ตัดช่องว่างหัวท้ายอย่างเดียว (ห้ามแก้ตัวอักษรข้างใน ไม่งั้นเราจ่ายไปที่อยู่ที่ผู้ใช้ไม่ได้พิมพ์)
     * ข้อความที่เก็บลงฐานข้อมูลไม่ได้ เก็บเป็น hex แทน แล้วกฎจะปัดเป็น needs_review เอง.
     */
    private function sanitizeTarget(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || ! mb_check_encoding($trimmed, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            return 'hex:'.substr(bin2hex($raw), 0, 190);
        }

        return mb_substr($trimmed, 0, 200);
    }

    /** เคอร์เซอร์ของรอบนี้ — ครั้งแรกเริ่มที่ start_block (ถ้าตั้ง) หรือหัวปัจจุบัน + 1 */
    private function startCursor(string $key, mixed $startBlock, int $head): int
    {
        $cursor = $this->config->cursor($key);

        if ($cursor !== null) {
            return $cursor;
        }

        $start = is_numeric($startBlock) && (int) $startBlock > 0 ? (int) $startBlock : $head + 1;
        $this->config->setCursor($key, $start);
        $this->config->setCursor("{$key}_floor", $start);

        return $start;
    }

    /**
     * จุดเริ่มอ่านของรอบนี้ = ย้อนจากเคอร์เซอร์ไป N บล็อก แต่ไม่ต่ำกว่า "บล็อกแรกที่เคยสแกน" (floor).
     *
     * ทำไมต้องย้อนทับ: RPC สาธารณะกระจายคำขอไปหลายโหนด — หัวที่ปลอดภัยอาจมาจากโหนด A ส่วน eth_getLogs
     * ไปตกที่โหนด B ที่ตามหัวไม่ทัน ซึ่งตอบ [] สำหรับบล็อกที่ตัวเองยังไม่มีโดยไม่ error เคอร์เซอร์จึงขยับผ่าน
     * แล้วเงินฝากในช่วงนั้นหายเงียบ ๆ การอ่านช่วงล่าสุดซ้ำทุกรอบให้โอกาสโหนดอื่นตอบครบในรอบถัดไป
     * (อ่านซ้ำปลอดภัยเสมอ เพราะ unique (เชน, tx, log) กันบันทึกซ้ำ)
     *
     * ทำไมต้องมี floor: รอบแรกเริ่มที่หัวปัจจุบันโดยตั้งใจ (vault มีเงินหนุนเดิมที่ห้าม mint ซ้ำ)
     * การย้อนทับต้องไม่พาไปอ่านก่อนจุดนั้น — เคอร์เซอร์เก่าที่ไม่มี floor ใช้ตำแหน่งปัจจุบันเป็น floor
     */
    private function rescanFrom(string $key, int $cursor, int $overlap): int
    {
        $floor = $this->config->cursor("{$key}_floor");

        if ($floor === null) {
            $floor = $cursor;
            $this->config->setCursor("{$key}_floor", $floor);
        }

        return max($floor, $cursor - max(0, $overlap));
    }

    /** เคอร์เซอร์ขยับไปข้างหน้าอย่างเดียว — รอบที่อ่านแค่ช่วงย้อนทับต้องไม่ดึงเคอร์เซอร์ถอยหลัง */
    private function advanceCursor(string $key, int $cursor, int $next): int
    {
        if ($next > $cursor) {
            $this->config->setCursor($key, $next);

            return $next;
        }

        return $cursor;
    }
}
