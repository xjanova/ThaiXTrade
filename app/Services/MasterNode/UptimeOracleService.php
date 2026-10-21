<?php

namespace App\Services\MasterNode;

use App\Models\MasternodeUptimePeriod;
use App\Services\TokenFactory\TokenFactoryChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * UptimeOracleService — ปิดรอบ uptime แล้วตั้งค่าบนเชนผ่าน updateUptimeBatch.
 *
 * ลำดับ
 *   1. อ่านโหนดที่ active ทั้งหมดจากสัญญา (ไม่ใช่แค่คนที่ส่ง heartbeat — คนที่ stake
 *      เฉย ๆ ไม่รันโหนดต้องได้ 0 รวมถึงโหนดที่ลงทะเบียนก่อนตั้ง oracle ซึ่งเริ่มที่ 100%)
 *   2. UptimeCalculator → uptime ของรอบ
 *   3. รอบที่ข้อมูลฝั่งเราขาด (degraded) ไม่ลดใคร: ค่าเป้าหมาย = max(ที่วัดได้, ที่อยู่บนเชน)
 *   4. ส่งเฉพาะโหนดที่ค่าเปลี่ยน ทีละ batch_size
 *
 * โหมดเงา (shadow): ถ้าเชนยังไม่ได้ตั้งกุญแจของเราเป็น uptimeOracle หรือยังไม่ได้ตั้งกุญแจ
 * ในเว็บ — คำนวณและบันทึกครบทุกอย่าง แต่ไม่ส่งธุรกรรม ใช้ดูตัวเลขก่อนเจ้าของเรียก setUptimeOracle
 *
 * Developed by Xman Studio
 */
class UptimeOracleService
{
    /** updateUptimeBatch(address[],uint256[]) */
    public const SEL_UPDATE_UPTIME_BATCH = '0xb949fb14';

    /** รอใบเสร็จแต่ละชุดนานสุดเท่านี้ (บล็อก 2-6 วิ) */
    private const RECEIPT_TIMEOUT_SECONDS = 90;

    /** @var callable(int):void */
    private $sleeper;

    /** @var callable():?string ที่อยู่ของ DEPLOYER_PRIVATE_KEY (lowercase) */
    private $deployerAddress;

    public function __construct(
        private NodeRegistryContract $registry,
        private UptimeCalculator $calculator,
        private UptimeOracleSigner $signer,
        private ChainHeadService $chain,
        ?callable $sleeper = null,
        ?callable $deployerAddress = null,
    ) {
        $this->sleeper = $sleeper ?? fn (int $s) => sleep($s);
        $this->deployerAddress = $deployerAddress
            ?? fn () => app(TokenFactoryChain::class)->deployerAddress();
    }

    /**
     * ส่งได้จริงไหม — คืนเหตุผลถ้าไม่ได้ (null = ส่งได้).
     */
    public function shadowReason(): ?string
    {
        if (! $this->signer->configured()) {
            return 'oracle_key_not_configured';
        }

        // กุญแจ oracle ต้องเป็นกุญแจร้อนแยก — ห้ามเป็น owner ของสัญญา หรือกุญแจ deployer ของเซิร์ฟเวอร์
        // (กุญแจ owner บนเครื่องเว็บ = ใครยึดเว็บได้ก็ slash / ปิดรับสมัครได้)
        // อ่าน owner ไม่ได้ = ยืนยันไม่ได้ว่าแยกกุญแจจริง → ไม่ส่ง (fail closed)
        $mine = $this->signer->expectedAddress();
        $owner = $this->registry->owner();
        if ($owner === null) {
            return 'cannot_read_owner';
        }
        if ($mine === $owner || $mine === ($this->deployerAddress)()) {
            return 'oracle_key_is_owner_or_deployer';
        }

        $onchain = $this->registry->uptimeOracle();
        if ($onchain === null) {
            return 'contract_has_no_uptime_oracle';
        }
        if ($onchain === '0x'.str_repeat('0', 40)) {
            return 'oracle_not_set_on_chain';
        }
        if ($onchain !== $this->signer->expectedAddress()) {
            return 'oracle_on_chain_is_another_key';
        }

        return null;
    }

    /**
     * @param  bool  $dryRun  คำนวณแล้วคืนผลอย่างเดียว — ไม่บันทึก ไม่ส่ง (ไม่ทับผลของรอบที่ส่งจริงไปแล้ว)
     * @return array{period_start:int, mode:string, shadow_reason:?string, degraded:bool, reasons:array,
     *               feed_coverage:float, nodes:int, changed:int, confirmed:int, failed:int,
     *               results:array<string,array{expected:int, ok:int, bps:int, onchain:int, target:int}>}
     */
    public function run(int $periodStart, bool $dryRun = false): array
    {
        // รอบที่ส่งขึ้นเชนไปแล้ว — รันซ้ำ (cron + มือ) ห้ามทับประวัติ tx
        if (! $dryRun && MasternodeUptimePeriod::where('period_start', $periodStart)
            ->whereIn('status', [MasternodeUptimePeriod::SUBMITTED, MasternodeUptimePeriod::CONFIRMED])
            ->exists()) {
            return [
                'period_start' => $periodStart, 'mode' => 'already_processed', 'shadow_reason' => null,
                'degraded' => false, 'reasons' => [], 'feed_coverage' => 0.0, 'nodes' => 0,
                'changed' => 0, 'confirmed' => 0, 'failed' => 0, 'results' => [],
            ];
        }

        if ($this->registry->deploymentState() !== true) {
            throw new RuntimeException('NodeRegistry ยังไม่ deploy หรืออ่านสถานะสัญญาไม่ได้');
        }

        // ที่อยู่มาจาก ContractRegistry (ตั้งในหลังบ้านได้) — ต้องเป็น build ที่ตรวจรับรองแล้วเท่านั้น
        // ไม่ตรงแม้แต่โหมดเงาก็ไม่ทำ: ตัวเลขที่คิดจากสัญญาที่ไม่รู้จักใช้ตัดสินอะไรไม่ได้
        $expected = (string) config('masternode.uptime.registry_code_hash');
        $actual = $this->registry->runtimeCodeHash();
        if ($actual === null || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('bytecode ของ NodeRegistry ไม่ตรงกับ build ที่ปักไว้ ('.($actual ?? 'อ่านไม่ได้').') — ไม่ทำรอบนี้');
        }

        // ไล่ operators(i) ทีละ index — ไม่ใช่ getActiveOperators() ที่พังเมื่อเกิน ~1,100 กระเป๋า
        $nodes = $this->registry->activeNodes();
        if ($nodes === null) {
            throw new RuntimeException('อ่านรายชื่อโหนดจากสัญญาไม่ครบ — ไม่ตั้งค่าจากข้อมูลครึ่งเดียว');
        }

        $registeredAt = [];
        $onchain = [];
        foreach ($nodes as $wallet => $info) {
            $registeredAt[$wallet] = (int) $info['registered_at'];
            $onchain[$wallet] = (int) $info['uptime'];
        }

        $calc = $this->calculator->compute($periodStart, $registeredAt);
        $shadowReason = $dryRun ? 'dry_run' : $this->shadowReason();
        $live = $shadowReason === null;

        $pending = [];
        $results = [];
        foreach ($calc['results'] as $wallet => $r) {
            // ข้อมูลฝั่งเราขาด = ความผิดเรา — ยกขึ้นได้ ลดลงไม่ได้
            $target = $calc['degraded'] ? max($r['bps'], $onchain[$wallet]) : $r['bps'];
            $results[$wallet] = $r + ['onchain' => $onchain[$wallet], 'target' => $target];
            if ($dryRun) {
                continue;
            }

            $status = ! $live
                ? MasternodeUptimePeriod::SHADOW
                : ($target === $onchain[$wallet] ? MasternodeUptimePeriod::UNCHANGED : 'pending');

            MasternodeUptimePeriod::updateOrCreate(
                ['wallet_address' => $wallet, 'period_start' => $periodStart],
                [
                    'slots_expected' => $r['expected'],
                    'slots_ok' => $r['ok'],
                    'uptime_bps' => $r['bps'],
                    'onchain_before_bps' => $onchain[$wallet],
                    'target_bps' => $target,
                    'degraded' => $calc['degraded'],
                    'status' => $status,
                    'tx_hash' => null,
                    'error' => null,
                ],
            );

            if ($status === 'pending') {
                $pending[$wallet] = $target;
            }
        }

        $confirmed = 0;
        $failed = 0;
        $batchSize = max(1, min(100, (int) config('masternode.uptime.batch_size', 50)));
        foreach (array_chunk($pending, $batchSize, true) as $batch) {
            $pending = array_diff_key($pending, $batch);
            if ($this->submitBatch($periodStart, $batch)) {
                $confirmed += count($batch);

                continue;
            }

            // ชุดหนึ่งล้ม (กุญแจผิด/สิทธิ์ถูกถอด/RPC ล่ม) ชุดถัดไปก็จะล้มแบบเดียวกัน — หยุดไว้ก่อน
            $failed += count($batch) + count($pending);
            $this->markFailed($periodStart, array_keys($pending), 'skipped after an earlier batch failed');
            break;
        }

        if (! $dryRun) {
            $this->prune();
        }

        return [
            'period_start' => $periodStart,
            'mode' => $dryRun ? 'dry_run' : ($live ? 'live' : 'shadow'),
            'shadow_reason' => $shadowReason,
            'degraded' => $calc['degraded'],
            'reasons' => $calc['reasons'],
            'feed_coverage' => $calc['feed_coverage'],
            'nodes' => count($calc['results']),
            'changed' => $confirmed + $failed,
            'confirmed' => $confirmed,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * calldata ของ updateUptimeBatch(address[] operators, uint256[] uptimes).
     *
     * @param  array<int,string>  $wallets
     * @param  array<int,int>  $uptimes
     */
    public static function encodeBatch(array $wallets, array $uptimes): string
    {
        $n = count($wallets);
        if ($n !== count($uptimes)) {
            throw new RuntimeException('Length mismatch');
        }

        $word = fn (string $hex) => str_pad($hex, 64, '0', STR_PAD_LEFT);
        // หัว: offset ของ array แรก (2 word) และ array ที่สอง (หลัง array แรกที่ยาว 1 + n word)
        $out = self::SEL_UPDATE_UPTIME_BATCH
            .$word(dechex(64))
            .$word(dechex(64 + 32 * (1 + $n)))
            .$word(dechex($n));

        foreach ($wallets as $w) {
            if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $w)) {
                throw new RuntimeException("ที่อยู่ไม่ถูกต้อง: {$w}");
            }
            $out .= $word(strtolower(substr($w, 2)));
        }

        $out .= $word(dechex($n));
        foreach ($uptimes as $u) {
            if (! is_int($u) || $u < 0 || $u > 10000) {
                throw new RuntimeException('uptime ต้องอยู่ระหว่าง 0-10000');
            }
            $out .= $word(dechex($u));
        }

        return $out;
    }

    /**
     * @param  array<string,int>  $batch  wallet => target bps
     */
    private function submitBatch(int $periodStart, array $batch): bool
    {
        $wallets = array_keys($batch);
        $contract = (string) $this->registry->address();

        $signed = $this->signer->sign($contract, self::encodeBatch($wallets, array_values($batch)));
        if (! ($signed['ok'] ?? false) || empty($signed['raw']) || empty($signed['txHash'])) {
            $this->markFailed($periodStart, $wallets, 'sign: '.($signed['code'] ?? '').' '.($signed['error'] ?? ''));

            return false;
        }

        // บันทึก hash ก่อนส่ง — เครื่องดับกลางคันจะยังตามใบเสร็จได้
        $this->rows($periodStart, $wallets)->update([
            'status' => MasternodeUptimePeriod::SUBMITTED,
            'tx_hash' => $signed['txHash'],
            'updated_at' => now(),
        ]);

        $sent = $this->signer->send($signed['raw']);
        if (! ($sent['ok'] ?? false)) {
            $this->markFailed($periodStart, $wallets, 'send: '.($sent['code'] ?? '').' '.($sent['error'] ?? ''));

            return false;
        }

        $deadline = time() + self::RECEIPT_TIMEOUT_SECONDS;
        do {
            $receipt = $this->chain->transactionReceipt($signed['txHash']);
            if ($receipt !== null) {
                if (! $receipt['status']) {
                    $this->markFailed($periodStart, $wallets, 'transaction reverted');

                    return false;
                }
                $this->rows($periodStart, $wallets)->update([
                    'status' => MasternodeUptimePeriod::CONFIRMED,
                    'updated_at' => now(),
                ]);

                return true;
            }
            ($this->sleeper)(3);
        } while (time() < $deadline);

        // ยังไม่ขึ้นบล็อก — ปล่อยสถานะ submitted ไว้ รอบหน้าค่าบนเชนจะบอกเองว่าผ่านไหม
        Log::warning('uptime-oracle: ธุรกรรมยังไม่ขึ้นบล็อกภายในเวลา', ['tx' => $signed['txHash']]);

        return false;
    }

    private function rows(int $periodStart, array $wallets)
    {
        return DB::table('masternode_uptime_periods')
            ->where('period_start', $periodStart)
            ->whereIn('wallet_address', $wallets);
    }

    private function markFailed(int $periodStart, array $wallets, string $error): void
    {
        if ($wallets === []) {
            return;
        }

        Log::error('uptime-oracle: ตั้ง uptime ไม่สำเร็จ', ['period' => $periodStart, 'nodes' => count($wallets), 'error' => $error]);
        $this->rows($periodStart, $wallets)
            ->whereIn('status', ['pending', MasternodeUptimePeriod::SUBMITTED])
            ->update([
                'status' => MasternodeUptimePeriod::FAILED,
                'error' => mb_substr(trim($error), 0, 255),
                'updated_at' => now(),
            ]);
    }

    /** ข้อมูลดิบเก่ากว่า retention_days ไม่มีใครใช้แล้ว — ตัดทิ้งทุกรอบ */
    private function prune(): void
    {
        $days = max(2, (int) config('masternode.uptime.retention_days', 30));
        $cutoff = now()->subDays($days)->timestamp;
        $cutoffSlot = NodeUptimeRecorder::slotOf($cutoff);

        DB::table('masternode_uptime_slots')->where('slot', '<', $cutoffSlot)->delete();
        DB::table('masternode_peer_sightings')->where('slot', '<', $cutoffSlot)->delete();
        DB::table('masternode_peer_reports')->where('slot', '<', $cutoffSlot)->delete();
        DB::table('masternode_uptime_periods')->where('period_start', '<', $cutoff)->delete();
    }
}
