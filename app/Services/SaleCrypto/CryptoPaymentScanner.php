<?php

namespace App\Services\SaleCrypto;

use App\Models\SaleCryptoOrder;
use App\Models\SaleCryptoPayment;
use App\Models\TokenSale;
use App\Services\Bridge\Abi;
use App\Services\Bridge\EvmRpc;
use App\Services\TokenSaleService;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * ตัวสแกนเงินเข้ากระเป๋ารับเงินของรอบขาย — แบบเดียวกับ BridgeScanner ทุกกติกา.
 *
 *   - อ่านถึง "หัวที่ปลอดภัย" (finalized และ/หรือ N confirmations ตาม config/bridge.php) เท่านั้น
 *     บล็อกที่ยังย้อนได้ไม่ถูกบันทึก reorg จึงทำให้เกิดการชำระเงินผีไม่ได้
 *   - eth_getLogs หั่นเป็นช่วงตาม log_chunk ของเชน · RPC สำรองหลายตัวผ่าน EvmRpc
 *   - เคอร์เซอร์ขยับเฉพาะหลังอ่านช่วงนั้นครบและบันทึกทุก log สำเร็จ — RPC ล้มกลางคัน = เริ่มที่เดิมรอบหน้า
 *     (อ่านซ้ำได้ปลอดภัยเพราะ unique (เชน, tx, log))
 *   - รอบแรกของแต่ละกระเป๋าเริ่มที่หัวปัจจุบัน ไม่ไล่ย้อน — คำสั่งซื้อเปิดได้เฉพาะเมื่อตัวสแกนทำงานอยู่แล้ว
 *     (SaleCryptoConfig::openChains) จึงไม่มีการจ่ายเงินของคำสั่งซื้อใดเกิดก่อนเคอร์เซอร์
 *
 * บันทึกอย่างเดียว ไม่ตัดสินใจ — การจับคู่/ออกเหรียญอยู่ที่ CryptoPaymentCrediter
 */
class CryptoPaymentScanner
{
    public function __construct(
        private SaleCryptoConfig $config,
        private TokenSaleService $sales,
    ) {}

    /** รอบขายที่ตัวสแกนเฝ้ากระเป๋า — รอบที่ active ก่อน ถ้าไม่มี (เพิ่งจบ) ใช้รอบล่าสุดที่ตั้งกระเป๋าไว้ */
    public function watchedSale(): ?TokenSale
    {
        $active = $this->sales->getActiveSale();
        if ($active !== null && $this->config->receiveAddress($active) !== null) {
            return $active;
        }

        return TokenSale::whereNotNull('sale_wallet_address')->where('sale_wallet_address', '!=', '')->latest('id')->first();
    }

    /**
     * กระเป๋าที่ต้องเฝ้า — กระเป๋ารับเงินปัจจุบัน + ที่อยู่ของคำสั่งซื้อที่ยังจองยอดไว้ (ยังอยู่ในช่วงโอนช้า).
     *
     * ถ้าเจ้าของเปลี่ยนกระเป๋ารับเงินระหว่างที่มีคนกำลังโอน คำสั่งซื้อเดิมยังชี้ที่อยู่เดิม
     * เฝ้าแค่ที่อยู่ใหม่ = เงินที่เข้าที่อยู่เดิมไม่มีแถวบันทึกเลย (หายเงียบ)
     *
     * @return array<int, string>
     */
    public function watchedAddresses(int $chainId): array
    {
        $list = [];

        if ($current = $this->config->receiveAddress($this->watchedSale())) {
            $list[] = $current;
        }

        $pending = SaleCryptoOrder::where('chain_id', $chainId)->whereNotNull('amount_lock')->distinct()->pluck('pay_to_address');
        foreach ($pending as $address) {
            $list[] = strtolower((string) $address);
        }

        // vault ของสะพาน / กระเป๋าที่เซิร์ฟเวอร์ถือคีย์ — ไม่ใช่รายได้ขาย ห้ามนับเป็นการชำระเงิน
        $internal = $this->config->internalAddresses();

        return array_values(array_filter(array_unique($list), fn ($a) => preg_match('/^0x[0-9a-f]{40}$/', $a) && ! in_array($a, $internal, true)));
    }

    /**
     * สแกนทุกกระเป๋าที่ต้องเฝ้าบนเชนนี้.
     *
     * @return array{chain: int, recorded: int, from: ?int, to: ?int, head: ?int, error: ?string}
     */
    public function scanChain(int $chainId): array
    {
        $addresses = $this->watchedAddresses($chainId);
        if ($addresses === []) {
            $current = $this->config->receiveAddress($this->watchedSale());

            return ['chain' => $chainId, 'recorded' => 0, 'from' => null, 'to' => null, 'head' => null,
                'error' => $current === null ? 'no_receive_address' : 'receive_address_rejected'];
        }

        $summary = null;
        foreach ($addresses as $address) {
            $one = $this->scanAddress($chainId, $address);

            $summary = $summary === null ? $one : [
                'chain' => $chainId,
                'recorded' => $summary['recorded'] + $one['recorded'],
                'from' => $summary['from'],
                'to' => $summary['to'],
                'head' => $summary['head'] ?? $one['head'],
                'error' => $summary['error'] ?? $one['error'],
            ];
        }

        return $summary;
    }

    /**
     * @return array{chain: int, recorded: int, from: ?int, to: ?int, head: ?int, error: ?string}
     */
    public function scanAddress(int $chainId, string $address): array
    {
        $summary = ['chain' => $chainId, 'recorded' => 0, 'from' => null, 'to' => null, 'head' => null, 'error' => null];
        $chain = $this->config->chain($chainId);
        $raw = $this->config->bridgeChain($chainId);
        $rpc = $this->config->rpc($chainId);

        if (! $chain || ! $raw || ! $rpc) {
            return ['error' => 'unknown_chain'] + $summary;
        }

        $head = $rpc->safeHead(
            (string) ($raw['finality'] ?? 'confirmations'),
            (int) ($raw['min_confirmations'] ?? 12),
            (int) ($raw['fallback_confirmations'] ?? 64),
        );
        $summary['head'] = $head;

        if ($head === null) {
            return ['error' => 'rpc_unavailable'] + $summary;
        }

        $cursorKey = $this->config->cursorKey($chainId, $address);
        $from = $this->config->cursor($cursorKey);
        if ($from === null) {
            $from = $head + 1;
            $this->config->setCursor($cursorKey, $from);
        }

        $end = min($head, $from + max(1, (int) ($raw['max_blocks_per_run'] ?? 20000)) - 1);
        $chunk = max(1, (int) ($raw['log_chunk'] ?? 1000));
        $summary['from'] = $from;

        // USDT ที่รับ + เหรียญที่คนมักโอนผิด (บันทึกไว้คืนเงิน ไม่ใช่รับชำระ)
        $tokens = [['symbol' => $chain['symbol'], 'address' => $chain['token'], 'decimals' => $chain['decimals']]];
        foreach ($this->config->watchTokens($chainId) as $watch) {
            $tokens[] = $watch;
        }

        $topics = [Abi::topic(Abi::TRANSFER_EVENT), null, '0x'.Abi::encAddress($address)];
        $blockTimes = [];

        while ($from <= $end) {
            $to = min($from + $chunk - 1, $end);
            $batch = [];

            foreach ($tokens as $token) {
                $logs = $rpc->logs($token['address'], $topics, $from, $to);

                if ($logs === null) {
                    $summary['error'] = 'rpc_logs_failed';
                    break 2;
                }

                foreach ($logs as $log) {
                    $batch[] = [$token, $log];
                }
            }

            // เรียงตามบล็อก/ตำแหน่ง — คนที่จ่ายก่อนต้องได้โควตาก่อนตอนออกเหรียญ
            usort($batch, fn ($a, $b) => [Wei::hexToInt($a[1]['blockNumber'] ?? '0x0'), Wei::hexToInt($a[1]['logIndex'] ?? '0x0')]
                <=> [Wei::hexToInt($b[1]['blockNumber'] ?? '0x0'), Wei::hexToInt($b[1]['logIndex'] ?? '0x0')]);

            foreach ($batch as [$token, $log]) {
                $result = $this->recordPayment($chainId, $token, $address, $rpc, $log, $blockTimes);

                if ($result === null) {
                    // อ่านเวลาบล็อกไม่ได้ — ไม่ขยับเคอร์เซอร์ผ่านช่วงนี้
                    $summary['error'] = 'rpc_facts_failed';
                    break 2;
                }

                if ($result === true) {
                    $summary['recorded']++;
                }
            }

            $this->config->setCursor($cursorKey, $to + 1);
            $summary['to'] = $to;
            $from = $to + 1;
        }

        if ($summary['error'] === null) {
            $this->config->markScanOk($chainId, $address);
        }

        return $summary;
    }

    /**
     * บันทึก Transfer หนึ่ง log — true = บันทึกใหม่ · false = มีอยู่แล้ว/ไม่เกี่ยว/ฝุ่น · null = อ่านเวลาบล็อกไม่ได้.
     *
     * @param  array{symbol:string, address:string, decimals:int}  $token
     * @param  array<int, int>  $blockTimes  แคชเวลาบล็อกในรอบเดียวกัน
     */
    public function recordPayment(int $chainId, array $token, string $address, EvmRpc $rpc, array $log, array &$blockTimes = []): ?bool
    {
        $txHash = strtolower((string) ($log['transactionHash'] ?? ''));
        $logIndex = (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0'));
        $blockNumber = (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0'));
        $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

        // log ต้องมาจากสัญญาตัวจริงเท่านั้น — สัญญาปลอมยิง event หน้าตาเดียวกันได้ด้วยต้นทุนหลักสิบบาท
        if (strtolower((string) ($log['address'] ?? '')) !== $token['address']
            || ($log['removed'] ?? false) === true
            || ! preg_match('/^0x[0-9a-f]{64}$/', $txHash)
            || $blockNumber <= 0
            || count($topics) < 3
            || $topics[0] !== Abi::topic(Abi::TRANSFER_EVENT)
            || Abi::topicAddress($topics[2]) !== $address) {
            return false;
        }

        if (SaleCryptoPayment::where('chain_id', $chainId)->where('tx_hash', $txHash)->where('log_index', $logIndex)->exists()) {
            return false;
        }

        $from = Abi::topicAddress($topics[1]);
        if ($from === null) {
            return false;
        }

        $amountRaw = Wei::hexToInt((string) ($log['data'] ?? '0x0'));

        // ยอดศูนย์/ฝุ่น — address poisoning ยิงใส่ได้ฟรีเป็นพันครั้ง ไม่บันทึก ไม่ยิง RPC ตาม
        if (bccomp($amountRaw, $this->config->dustRaw($token['decimals']), 0) < 0) {
            return false;
        }

        // เวลาบล็อกตัดสินว่า "โอนทันเวลาราคาที่ล็อกไว้ไหม" — ไม่ใช่เวลาที่ตัวสแกนเห็น
        $timestamp = $blockTimes[$blockNumber] ??= $rpc->blockTimestamp($blockNumber);
        if ($timestamp === null) {
            unset($blockTimes[$blockNumber]);

            return null;
        }

        try {
            SaleCryptoPayment::create([
                'chain_id' => $chainId,
                'tx_hash' => $txHash,
                'log_index' => $logIndex,
                'block_number' => $blockNumber,
                'block_timestamp' => $timestamp,
                'from_address' => $from,
                'to_address' => $address,
                'token_address' => $token['address'],
                'token_symbol' => $token['symbol'],
                'token_decimals' => $token['decimals'],
                'amount_raw' => $amountRaw,
                'status' => SaleCryptoPayment::STATUS_DETECTED,
            ]);
        } catch (QueryException $e) {
            // อีกโปรเซสบันทึกไปพร้อมกันพอดี — unique (เชน, tx, log) กันไว้แล้ว
            if (SaleCryptoPayment::where('chain_id', $chainId)->where('tx_hash', $txHash)->where('log_index', $logIndex)->exists()) {
                return false;
            }

            throw $e;
        }

        Log::info('token-sale: เห็นเงินเข้ากระเป๋ารับเงิน', [
            'chain' => $chainId,
            'tx' => $txHash,
            'log' => $logIndex,
            'from' => $from,
            'token' => $token['symbol'],
            'raw' => $amountRaw,
        ]);

        return true;
    }
}
