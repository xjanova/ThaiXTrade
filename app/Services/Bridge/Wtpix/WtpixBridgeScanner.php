<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\SiteSetting;
use App\Models\SystemAlert;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\EvmRpc;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * ตัวสแกนของสะพาน TPIX ⇄ wTPIX — อ่าน event จากเชนจริงแล้วบันทึก ครั้งเดียวต่อหนึ่ง log
 * และเฝ้าเหตุที่ระบบไม่ได้สั่ง.
 *
 * เชน 4289 (TPIXBridgeVault):
 *   Locked   → รายการ to_bsc (mint wTPIX)  · อ่านถึง ล่าสุด − 2 (IBFT ไม่มีแท็ก finalized)
 *   Released → ต้องเป็นธุรกรรมที่ระบบส่งเอง (dest_tx_hash) ไม่งั้น = คีย์ relayer หลุด → หยุดสะพาน
 *
 * BSC (wTPIX) อ่านถึงหัวที่ปลอดภัย (finalized และ ≥ 15 confirmation):
 *   Transfer(x, 0x0)   → รายการ to_tpix (ปล่อย TPIX ให้ x)
 *   Transfer(0x0, x)   → mint ทุกครั้งต้องเป็นธุรกรรมที่ระบบส่งเอง ไม่งั้นหยุดสะพาน
 *   MinterQueued / MinterSet(x, true) ที่ x ไม่ใช่ WTPIXBridgeMinter ของเรา → หยุดสะพาน
 *     (นี่คือ "ฟัน" ของ timelock 2 วันใน wTPIX — คีย์เจ้าของหลุดแล้วเข้าคิวเพิ่ม minter
 *      เราเห็นทันทีแล้ว pause ก่อนที่ minter ตัวนั้นจะใช้งานได้)
 *
 * กติกาเคอร์เซอร์เดียวกับสะพาน USDT: ขยับเฉพาะหลังอ่านช่วงนั้นครบและบันทึกทุก log สำเร็จ
 * เคอร์เซอร์ผูกกับ (เชน, ที่อยู่สัญญา) — เปลี่ยนสัญญาแล้วรอบแรกเริ่มที่หัวปัจจุบัน ไม่ไล่ย้อนหลัง
 *
 * ทำงานแม้สวิตช์หลังบ้านปิด — คนที่ล็อก/เผาระหว่างหยุดต้องถูกบันทึกไว้ และตัวเฝ้าเหตุต้องเดินตลอด
 */
class WtpixBridgeScanner
{
    /** @var array<int, array{reason: string, note: string, context: array}> */
    private array $incidents = [];

    public function __construct(
        private WtpixBridgeConfig $config,
        private WtpixBridgePolicy $policy,
        private WtpixBridgeGuard $guard,
    ) {}

    // =========================================================================
    // เชน TPIX — Locked / Released
    // =========================================================================

    /**
     * @return array{chain: int, recorded: int, from: ?int, to: ?int, head: ?int, error: ?string, incidents: int}
     */
    public function scanLocks(): array
    {
        $chainId = $this->config->tpixChainId();
        $summary = ['chain' => $chainId, 'recorded' => 0, 'from' => null, 'to' => null, 'head' => null, 'error' => null, 'incidents' => 0];
        $vault = $this->config->vaultAddress();

        if ($vault === null) {
            return ['error' => 'vault_not_configured'] + $summary;
        }

        $rpc = $this->config->tpixRpc();
        $latest = $rpc->blockNumber();
        if ($latest === null) {
            return ['error' => 'rpc_unavailable'] + $summary;
        }

        $head = $latest - $this->config->tpixConfirmations();
        $summary['head'] = $head;
        $cursorKey = $this->config->lockCursorKey();
        $from = $this->startCursor($cursorKey, config('wtpix_bridge.tpix.start_block'), $head);
        $end = min($head, $from + max(1, (int) config('wtpix_bridge.tpix.max_blocks_per_run', 18000)) - 1);
        // polygon-edge ปฏิเสธ eth_getLogs ช่วงเกิน 1000 บล็อก
        $chunk = min(900, max(1, (int) config('wtpix_bridge.tpix.log_chunk', 900)));
        $summary['from'] = $from;

        $topics = [[Abi::topic(WtpixAbi::LOCKED_EVENT), Abi::topic(WtpixAbi::RELEASED_EVENT)]];

        while ($from <= $end) {
            $to = min($from + $chunk - 1, $end);
            $logs = $rpc->logs($vault, $topics, $from, $to);

            if ($logs === null) {
                $summary['error'] = 'rpc_logs_failed';
                break;
            }

            // ตามลำดับบนเชน — วงเงินรายวันของกระเป๋าต้องนับคนที่ล็อกก่อนก่อน
            $this->sortLogs($logs);

            foreach ($logs as $log) {
                $topic0 = strtolower((string) ($log['topics'][0] ?? ''));

                if ($topic0 === Abi::topic(WtpixAbi::RELEASED_EVENT)) {
                    $this->checkRelease($vault, $log);

                    continue;
                }

                $result = $this->recordLock($vault, $rpc, $log);
                if ($result === null) {
                    // อ่านข้อมูลประกอบ (โค้ดผู้ส่ง/ธุรกรรม) ไม่ได้ — ไม่ขยับเคอร์เซอร์ผ่านช่วงนี้
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
            SiteSetting::set(WtpixBridgeConfig::CURSOR_GROUP, "scan_ok_at_{$chainId}", now()->toIso8601String(), 'string');
        }

        $summary['incidents'] = $this->flushIncidents();

        return $summary;
    }

    /** บันทึก Locked หนึ่ง log — true = ใหม่ · false = มีแล้ว/ไม่เกี่ยว · null = อ่านข้อมูลประกอบไม่ได้ */
    public function recordLock(string $vault, EvmRpc $rpc, array $log): ?bool
    {
        $parsed = $this->parseLog($log, $vault, Abi::topic(WtpixAbi::LOCKED_EVENT));
        if ($parsed === null) {
            return false;
        }

        $from = Abi::topicAddress($parsed['topics'][1] ?? null);
        $amount = Abi::decUint((string) ($log['data'] ?? ''));

        if ($from === null || $amount === null) {
            Log::warning('wtpix-bridge: ถอด Locked ไม่ได้ — ข้าม', ['tx' => $parsed['tx'], 'log' => $parsed['index']]);

            return false;
        }

        return $this->record(WtpixBridgeTransfer::TO_BSC, $this->config->tpixChainId(), $vault, $rpc, $log, $parsed, $from, $amount);
    }

    /** Released ต้องมาจากธุรกรรมที่ระบบส่งเอง (บันทึก hash ไว้ก่อน broadcast เสมอ) */
    private function checkRelease(string $vault, array $log): void
    {
        $parsed = $this->parseLog($log, $vault, Abi::topic(WtpixAbi::RELEASED_EVENT));
        if ($parsed === null) {
            return;
        }

        $ours = WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_TPIX)->where('dest_tx_hash', $parsed['tx'])->exists();
        if ($ours) {
            return;
        }

        $to = Abi::topicAddress($parsed['topics'][2] ?? null);
        $this->incidents[] = [
            'reason' => 'unordered_release',
            'note' => "คลังปล่อย TPIX ในธุรกรรม {$parsed['tx']} ที่ระบบไม่ได้ส่ง (ผู้รับ {$to}) — คีย์ relayer อาจหลุด",
            'context' => ['tx' => $parsed['tx'], 'to' => $to, 'block' => $parsed['block']],
        ];
    }

    // =========================================================================
    // BSC — เผา / mint / เปลี่ยน minter ของ wTPIX
    // =========================================================================

    /**
     * @return array{chain: int, recorded: int, from: ?int, to: ?int, head: ?int, error: ?string, incidents: int}
     */
    public function scanBurns(): array
    {
        $chainId = $this->config->bscChainId();
        $summary = ['chain' => $chainId, 'recorded' => 0, 'from' => null, 'to' => null, 'head' => null, 'error' => null, 'incidents' => 0];
        $wtpix = $this->config->wtpixAddress();
        $minter = $this->config->minterAddress();

        if ($wtpix === null || $minter === null) {
            return ['error' => 'wtpix_not_configured'] + $summary;
        }

        $bsc = $this->config->bsc();
        $rpc = $this->config->bscRpc();
        $head = $rpc->safeHead(
            (string) ($bsc['finality'] ?? 'finalized'),
            (int) ($bsc['min_confirmations'] ?? 15),
            (int) ($bsc['fallback_confirmations'] ?? 30),
        );
        $summary['head'] = $head;

        if ($head === null) {
            return ['error' => 'rpc_unavailable'] + $summary;
        }

        $cursorKey = $this->config->burnCursorKey();
        $from = $this->startCursor($cursorKey, $bsc['start_block'] ?? null, $head);
        $end = min($head, $from + max(1, (int) ($bsc['max_blocks_per_run'] ?? 20000)) - 1);
        $chunk = max(1, (int) ($bsc['log_chunk'] ?? 1000));
        $summary['from'] = $from;

        $transfer = Abi::topic(Abi::TRANSFER_EVENT);
        $zero = WtpixAbi::zeroTopic();

        while ($from <= $end) {
            $to = min($from + $chunk - 1, $end);

            // สามคำถามต่อช่วง (เผา / mint / เปลี่ยน minter) — กรองที่ RPC เพื่อไม่ดึงการโอนทั่วไปของพูลมาทั้งหมด
            $admin = $rpc->logs($wtpix, [[Abi::topic(WtpixAbi::MINTER_QUEUED_EVENT), Abi::topic(WtpixAbi::MINTER_SET_EVENT)]], $from, $to);
            $mints = $admin === null ? null : $rpc->logs($wtpix, [$transfer, $zero], $from, $to);
            $burns = $mints === null ? null : $rpc->logs($wtpix, [$transfer, null, $zero], $from, $to);

            if ($admin === null || $mints === null || $burns === null) {
                $summary['error'] = 'rpc_logs_failed';
                break;
            }

            // เหตุร้ายแรงก่อน — ไม่ต้องยิง RPC เพิ่ม และต้องไม่ถูกบังด้วยการอ่านข้อมูลประกอบที่ล้ม
            foreach ($admin as $log) {
                $this->checkMinterChange($wtpix, $minter, $log);
            }
            foreach ($mints as $log) {
                $this->checkMint($wtpix, $log);
            }

            $this->sortLogs($burns);
            foreach ($burns as $log) {
                $result = $this->recordBurn($wtpix, $rpc, $log);
                if ($result === null) {
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
            SiteSetting::set(WtpixBridgeConfig::CURSOR_GROUP, "scan_ok_at_{$chainId}", now()->toIso8601String(), 'string');
        }

        $summary['incidents'] = $this->flushIncidents();

        return $summary;
    }

    /** บันทึก Transfer(x, 0x0) หนึ่ง log */
    public function recordBurn(string $wtpix, EvmRpc $rpc, array $log): ?bool
    {
        $parsed = $this->parseLog($log, $wtpix, Abi::topic(Abi::TRANSFER_EVENT));
        if ($parsed === null || count($parsed['topics']) < 3 || strtolower($parsed['topics'][2]) !== WtpixAbi::zeroTopic()) {
            return false;
        }

        $from = Abi::topicAddress($parsed['topics'][1]);
        $amount = Abi::decUint((string) ($log['data'] ?? ''));

        if ($from === null || $amount === null || $from === Abi::ZERO_ADDRESS) {
            return false;
        }

        return $this->record(WtpixBridgeTransfer::TO_TPIX, $this->config->bscChainId(), $wtpix, $rpc, $log, $parsed, $from, $amount);
    }

    /** ทุก mint ของ wTPIX ต้องเป็นธุรกรรมที่ระบบส่งเอง (hash ถูกบันทึกก่อน broadcast) */
    private function checkMint(string $wtpix, array $log): void
    {
        $parsed = $this->parseLog($log, $wtpix, Abi::topic(Abi::TRANSFER_EVENT));
        if ($parsed === null || strtolower((string) ($parsed['topics'][1] ?? '')) !== WtpixAbi::zeroTopic()) {
            return;
        }

        if (WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_BSC)->where('dest_tx_hash', $parsed['tx'])->exists()) {
            return;
        }

        $to = Abi::topicAddress($parsed['topics'][2] ?? null);
        $amount = Abi::decUint((string) ($log['data'] ?? '')) ?? '0';
        $this->incidents[] = [
            'reason' => 'unordered_mint',
            'note' => 'wTPIX ถูก mint '.Wei::format($amount, 18)." ในธุรกรรม {$parsed['tx']} ที่ระบบไม่ได้ส่ง (ผู้รับ {$to})",
            'context' => ['tx' => $parsed['tx'], 'to' => $to, 'amount_wei' => $amount, 'block' => $parsed['block']],
        ];
    }

    /**
     * MinterQueued(x) / MinterSet(x, true) ที่ x ไม่ใช่ minter ของเรา = มีคนกำลังเพิ่มทางลัด mint ที่ข้ามเพดานรายวัน.
     * MinterSet(ของเรา, false) = minter ของเราถูกถอด — mint ต่อไม่ได้ ปิดสวิตช์ + แจ้ง (ไม่ใช่การขโมยเงิน).
     */
    private function checkMinterChange(string $wtpix, string $ourMinter, array $log): void
    {
        $topics = array_map('strtolower', (array) ($log['topics'] ?? []));
        if (strtolower((string) ($log['address'] ?? '')) !== $wtpix || ($log['removed'] ?? false) === true || count($topics) < 2) {
            return;
        }

        $who = Abi::topicAddress($topics[1]);
        $tx = strtolower((string) ($log['transactionHash'] ?? ''));
        $block = (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0'));

        if ($topics[0] === Abi::topic(WtpixAbi::MINTER_QUEUED_EVENT)) {
            if ($who === $ourMinter) {
                return; // deploy-wtpix-bsc.js เข้าคิว minter ของเราเองตอนใช้ wTPIX ตัวเดิมซ้ำ
            }

            $executeAfter = Abi::decUint((string) ($log['data'] ?? ''));
            $when = $executeAfter !== null && bccomp($executeAfter, (string) PHP_INT_MAX, 0) <= 0
                ? gmdate('Y-m-d H:i', (int) $executeAfter).' UTC' : '?';
            $this->incidents[] = [
                'reason' => 'minter_queued',
                'note' => "มีการเข้าคิวเพิ่ม minter {$who} ให้ wTPIX (ใช้ได้หลัง {$when}) ในธุรกรรม {$tx} — ถ้าไม่ใช่เจ้าของสั่ง ให้ cancelMinter + ย้ายเจ้าของทันที",
                'context' => ['tx' => $tx, 'minter' => $who, 'execute_after' => $executeAfter, 'block' => $block],
            ];

            return;
        }

        if ($topics[0] !== Abi::topic(WtpixAbi::MINTER_SET_EVENT)) {
            return;
        }

        $status = Abi::decBool((string) ($log['data'] ?? ''));

        if ($status === true && $who !== $ourMinter) {
            $this->incidents[] = [
                'reason' => 'minter_added',
                'note' => "wTPIX เพิ่ม minter {$who} ในธุรกรรม {$tx} — ไม่ใช่ WTPIXBridgeMinter ของสะพาน ให้ removeMinter ทันที",
                'context' => ['tx' => $tx, 'minter' => $who, 'block' => $block],
            ];

            return;
        }

        if ($status === false && $who === $ourMinter) {
            $this->config->setSiteSwitch(false, "หยุดอัตโนมัติ (minter_removed): WTPIXBridgeMinter ถูกถอดสิทธิ์ mint ในธุรกรรม {$tx}");
            Log::warning('wtpix-bridge: minter ของสะพานถูกถอดสิทธิ์', ['tx' => $tx]);
            SystemAlert::raise(
                WtpixBridgeGuard::ALERT_NODE,
                'wtpix_bridge_minter_removed',
                'warning',
                "สะพาน TPIX⇄wTPIX หยุด: WTPIXBridgeMinter ถูกถอดสิทธิ์ mint (tx {$tx}) — mint ต่อไม่ได้จนกว่าเจ้าของจะเพิ่มกลับ",
                ['tx' => $tx]
            );
        }
    }

    // =========================================================================
    // ส่วนกลาง
    // =========================================================================

    /**
     * บันทึกหนึ่งรายการของทิศใดทิศหนึ่ง — คำนวณค่าธรรมเนียม · ถามข้อมูลประกอบ · ตัดสินตามกฎ.
     */
    private function record(string $direction, int $chainId, string $contract, EvmRpc $rpc, array $log, array $parsed, string $from, string $amount): ?bool
    {
        // ยอดศูนย์ไม่มีอะไรให้ทำและไม่มีอะไรให้ตรวจ — ไม่บันทึก (กันถังขยะในหลังบ้าน)
        if (bccomp($amount, '0', 0) <= 0) {
            return false;
        }

        if (WtpixBridgeTransfer::where('source_chain_id', $chainId)->where('tx_hash', $parsed['tx'])->where('log_index', $parsed['index'])->exists()) {
            return false;
        }

        $fee = $this->config->limitWei($this->config->limitKey($direction, 'fee'));
        $payout = bccomp($amount, $fee, 0) > 0 ? bcsub($amount, $fee, 0) : '0';

        $facts = [
            'from_address' => $from,
            'amount_wei' => $amount,
            'payout_wei' => $payout,
            'sender_is_contract' => false,
            'sender_mismatch' => false,
        ];

        // ข้อมูลประกอบต้องยิง RPC เพิ่ม — ถามเฉพาะรายการที่มีสิทธิ์ได้ทำจริง
        $decision = $this->policy->evaluate($direction, $facts);

        if ($decision['status'] !== WtpixBridgeTransfer::STATUS_IGNORED) {
            $code = $rpc->code($from);
            $tx = $rpc->transaction($parsed['tx']);

            if ($code === null || $tx === null) {
                return null;
            }

            // EIP-7702: EOA ที่มอบสิทธิ์ให้โค้ด (0xef0100…) ยังเป็นกระเป๋าที่เจ้าของถือคีย์
            $facts['sender_is_contract'] = $code !== '0x' && ! str_starts_with($code, '0xef0100');
            $facts['sender_mismatch'] = strtolower((string) ($tx['from'] ?? '')) !== $from;
            $decision = $this->policy->evaluate($direction, $facts);
        }

        $route = $this->config->route($direction);

        try {
            WtpixBridgeTransfer::create([
                'direction' => $direction,
                'source_chain_id' => $chainId,
                'source_contract' => $contract,
                'tx_hash' => $parsed['tx'],
                'log_index' => $parsed['index'],
                'block_number' => $parsed['block'],
                'block_hash' => is_string($log['blockHash'] ?? null) ? strtolower($log['blockHash']) : null,
                'from_address' => $from,
                'recipient' => $from,
                'amount_wei' => $amount,
                'fee_wei' => bccomp($payout, '0', 0) > 0 ? $fee : $amount,
                'payout_wei' => $payout,
                'deposit_id' => Abi::depositId($chainId, $parsed['tx'], $parsed['index']),
                'status' => $decision['status'],
                'review_reason' => $decision['reason'],
                'dest_chain_id' => $route['dest_chain'],
            ]);
        } catch (QueryException $e) {
            // อีกโปรเซสบันทึกพร้อมกันพอดี — unique (เชน, tx, log) กันไว้แล้ว
            if (WtpixBridgeTransfer::where('source_chain_id', $chainId)->where('tx_hash', $parsed['tx'])->where('log_index', $parsed['index'])->exists()) {
                return false;
            }

            throw $e;
        }

        Log::info('wtpix-bridge: บันทึกรายการ', [
            'direction' => $direction,
            'chain' => $chainId,
            'tx' => $parsed['tx'],
            'log' => $parsed['index'],
            'from' => $from,
            'wei' => $amount,
            'status' => $decision['status'],
            'reason' => $decision['reason'],
        ]);

        return true;
    }

    /**
     * ตรวจรูปร่างของ log — ต้องมาจากสัญญาตัวจริง ไม่ถูกถอน (reorg) และมีเลขบล็อก/ธุรกรรมครบ.
     *
     * @return array{tx: string, index: int, block: int, topics: array<int, string>}|null
     */
    private function parseLog(array $log, string $contract, string $topic0): ?array
    {
        $txHash = strtolower((string) ($log['transactionHash'] ?? ''));
        $index = (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0'));
        $block = (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0'));
        $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

        if (strtolower((string) ($log['address'] ?? '')) !== strtolower($contract)
            || ($log['removed'] ?? false) === true
            || ! preg_match('/^0x[0-9a-f]{64}$/', $txHash)
            || $block <= 0
            || count($topics) < 2
            || $topics[0] !== $topic0) {
            return null;
        }

        return ['tx' => $txHash, 'index' => $index, 'block' => $block, 'topics' => $topics];
    }

    private function sortLogs(array &$logs): void
    {
        usort($logs, fn ($a, $b) => [Wei::hexToInt($a['blockNumber'] ?? '0x0'), Wei::hexToInt($a['logIndex'] ?? '0x0')]
            <=> [Wei::hexToInt($b['blockNumber'] ?? '0x0'), Wei::hexToInt($b['logIndex'] ?? '0x0')]);
    }

    /** เหตุที่เจอในรอบนี้ → หยุดสะพานครั้งเดียว (ไม่ pause ซ้ำทีละ log) */
    private function flushIncidents(): int
    {
        $incidents = $this->incidents;
        $this->incidents = [];

        if ($incidents === []) {
            return 0;
        }

        $first = $incidents[0];
        $notes = array_column($incidents, 'note');
        $this->guard->halt($first['reason'], implode(' · ', array_slice($notes, 0, 5)), [
            'incidents' => array_map(fn ($i) => ['reason' => $i['reason']] + $i['context'], array_slice($incidents, 0, 20)),
        ]);

        return count($incidents);
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

        return $start;
    }
}
