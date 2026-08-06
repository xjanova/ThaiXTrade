<?php

namespace App\Console\Commands;

use App\Models\TreasuryLedger;
use App\Models\TreasuryPayout;
use App\Services\HotWalletSigner;
use App\Services\TreasuryService;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * เซ็น ส่ง และตามผลธุรกรรมจ่ายเงินจากกระเป๋าร้อน.
 *
 * รันทุกนาทีจาก scheduler — หน้าเว็บทำได้แค่เปลี่ยนสถานะเป็น broadcasting
 * เพราะ php-fpm ปิด proc_open การเซ็นจึงต้องเกิดฝั่ง CLI เท่านั้น
 *
 * ═══ ลำดับที่กันจ่ายซ้ำ ═══
 *
 * รายการหนึ่งมีสองระยะ แยกกันด้วย "มี tx_hash แล้วหรือยัง":
 *
 *   ยังไม่มี tx_hash → เซ็น → **บันทึก hash ลงฐานข้อมูลก่อน** → ค่อยส่ง
 *   มี tx_hash แล้ว  → ไม่เซ็นใหม่เด็ดขาด ไปตามผลอย่างเดียว
 *
 * ที่ต้องบันทึกก่อนส่ง เพราะถ้าเครื่องดับหลังส่งแต่ก่อนบันทึก รอบหน้าจะไม่รู้ว่า
 * เคยส่งไปแล้ว แล้วเซ็นใหม่ด้วย nonce ใหม่ = เงินออกสองรอบ
 *
 * ในทางกลับกัน ถ้าดับหลังบันทึกแต่ก่อนส่ง รอบหน้าจะเห็นว่ามี hash แล้วและ
 * ไปตามผล ซึ่งจะไม่เจอ receipt แล้วส่ง raw เดิมซ้ำ — ปลอดภัยเพราะ raw เดิม
 * มี nonce เดิม เชนจึงรับได้ครั้งเดียวเท่านั้น
 *
 * ทำทีละรายการต่อรอบโดยตั้งใจ ไม่ยิงพร้อมกันหลายรายการ เพราะการจัดการ nonce
 * หลายตัวพร้อมกันแล้วมีตัวใดตัวหนึ่งพลาด จะทำให้ตัวที่ nonce สูงกว่าค้างทั้งแถว
 */
class TreasuryProcessPayouts extends Command
{
    protected $signature = 'tpix:treasury-payouts
                            {--limit=1 : จำนวนรายการที่จะส่งต่อรอบ (แนะนำ 1)}
                            {--confirmations=1 : จำนวน confirmation ที่ถือว่าสำเร็จ}';

    protected $description = 'เซ็น ส่ง และตามผลรายการจ่ายเงินจากกระเป๋าร้อน';

    public function __construct(
        private TreasuryService $treasury,
        private HotWalletSigner $signer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // ── ระยะที่ 1: ตามผลของที่ส่งไปแล้ว ──────────────────────────────
        // ทำก่อนเสมอ เพราะถ้ามีรายการค้างอยู่ควรรู้ผลก่อนจะส่งตัวถัดไป
        $this->trackSent((int) $this->option('confirmations'));

        // ── ระยะที่ 2: ส่งตัวใหม่ ────────────────────────────────────────
        $readiness = $this->treasury->readiness();

        if (! $readiness['ready']) {
            $waiting = TreasuryPayout::where('status', TreasuryPayout::STATUS_BROADCASTING)
                ->whereNull('tx_hash')
                ->count();

            if ($waiting > 0) {
                $this->warn("มี {$waiting} รายการรอส่ง แต่ระบบยังไม่พร้อม — ยังขาด: "
                    .implode(' · ', array_column($readiness['blocking'], 'label')));
            }

            return self::SUCCESS;
        }

        if (! $this->signer->isAvailable()) {
            $this->error('เซ็นไม่ได้ในบริบทนี้ (ต้องรันจาก CLI)');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;

        for ($i = 0; $i < $limit; $i++) {
            $payout = $this->claimNext();

            if (! $payout) {
                break;
            }

            if ($this->sendPayout($payout)) {
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->info("ส่งขึ้นเชนแล้ว {$sent} รายการ");
        }

        return self::SUCCESS;
    }

    /**
     * หยิบรายการถัดไปมาทำ — ล็อกแถวกันสองโปรเซสหยิบตัวเดียวกัน.
     */
    private function claimNext(): ?TreasuryPayout
    {
        return DB::transaction(function () {
            return TreasuryPayout::where('status', TreasuryPayout::STATUS_BROADCASTING)
                ->whereNull('tx_hash')
                ->oldest()
                ->lockForUpdate()
                ->first();
        });
    }

    /**
     * เซ็น บันทึก hash แล้วส่ง — ตามลำดับนี้เท่านั้น.
     */
    private function sendPayout(TreasuryPayout $payout): bool
    {
        // ตรวจกฎอีกรอบก่อนปล่อยเงินจริง — วงเงินหรือ whitelist อาจถูกแก้
        // ระหว่างที่รายการรออยู่ในคิว
        $check = $this->treasury->validatePayout(
            $payout->to_address,
            (string) $payout->amount_wei,
            $payout->id,
        );

        if (! $check['ok']) {
            $payout->update([
                'status' => TreasuryPayout::STATUS_FAILED,
                'failure_reason' => 'ตกกฎตอนจะส่งจริง: '.implode(' / ', $check['errors']),
            ]);
            $this->error("  #{$payout->id} ตกกฎ: ".implode(' / ', $check['errors']));

            return false;
        }

        $this->line("  #{$payout->id} เซ็น ".Wei::format((string) $payout->amount_wei)." TPIX -> {$payout->to_address}");

        $signed = $this->signer->sign($payout->to_address, (string) $payout->amount_wei);

        if (! ($signed['ok'] ?? false)) {
            $payout->update([
                'status' => TreasuryPayout::STATUS_FAILED,
                'failure_reason' => 'เซ็นไม่สำเร็จ: '.($signed['error'] ?? 'ไม่ทราบสาเหตุ'),
            ]);
            $this->error('  เซ็นไม่สำเร็จ: '.($signed['error'] ?? '?'));

            return false;
        }

        // ⚠️ บันทึก hash ก่อนส่ง — ห้ามสลับลำดับสองบรรทัดนี้เด็ดขาด
        $payout->update([
            'tx_hash' => $signed['txHash'],
            'broadcast_at' => now(),
        ]);

        $result = $this->signer->send($signed['raw']);

        if (! ($result['ok'] ?? false)) {
            // nonce ถูกใช้ไปแล้ว = อาจเป็น tx ของเราเองที่ผ่านไปแล้ว
            // ปล่อยให้ระยะตามผลไปตรวจ receipt เอา ห้ามเซ็นใหม่
            if (($result['code'] ?? '') === 'nonce_used') {
                $this->warn("  #{$payout->id} nonce ถูกใช้แล้ว — รอตรวจ receipt");

                return false;
            }

            $payout->update([
                'status' => TreasuryPayout::STATUS_FAILED,
                'failure_reason' => 'ส่งขึ้นเชนไม่สำเร็จ: '.($result['error'] ?? 'ไม่ทราบสาเหตุ'),
            ]);
            $this->error('  ส่งไม่สำเร็จ: '.($result['error'] ?? '?'));

            return false;
        }

        Log::info('treasury: ส่งธุรกรรมจ่ายเงินขึ้นเชนแล้ว', [
            'payout_id' => $payout->id,
            'tx_hash' => $signed['txHash'],
            'nonce' => $signed['nonce'] ?? null,
            'already_known' => $result['alreadyKnown'] ?? false,
        ]);

        $this->info("  ส่งแล้ว: {$signed['txHash']}");

        return true;
    }

    /**
     * ตามผลของรายการที่ส่งไปแล้ว — สำเร็จ/ล้มเหลวตาม receipt.
     */
    private function trackSent(int $needConfirmations): void
    {
        $pending = TreasuryPayout::where('status', TreasuryPayout::STATUS_BROADCASTING)
            ->whereNotNull('tx_hash')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $head = $this->treasury->blockNumber();

        foreach ($pending as $payout) {
            $receipt = $this->receipt((string) $payout->tx_hash);

            if ($receipt === null) {
                // ยังไม่เข้าบล็อก หรืออ่านไม่ได้ — รอรอบหน้า ไม่เปลี่ยนสถานะ
                continue;
            }

            $blockNumber = isset($receipt['blockNumber']) ? (int) Wei::hexToInt($receipt['blockNumber']) : null;
            $success = ($receipt['status'] ?? '0x0') === '0x1';

            if (! $success) {
                $payout->update([
                    'status' => TreasuryPayout::STATUS_FAILED,
                    'block_number' => $blockNumber,
                    'failure_reason' => 'เชนปฏิเสธธุรกรรม (status = 0x0)',
                ]);
                $this->error("  #{$payout->id} เชนปฏิเสธ");

                continue;
            }

            $confirmations = ($head !== null && $blockNumber !== null) ? max(0, $head - $blockNumber + 1) : 0;

            if ($confirmations < $needConfirmations) {
                $payout->update(['block_number' => $blockNumber, 'confirmations' => $confirmations]);

                continue;
            }

            DB::transaction(function () use ($payout, $blockNumber, $confirmations) {
                $payout->update([
                    'status' => TreasuryPayout::STATUS_CONFIRMED,
                    'block_number' => $blockNumber,
                    'confirmations' => $confirmations,
                    'confirmed_at' => now(),
                ]);

                // บันทึกลงสมุด — ถ้า tpix:treasury-sync เก็บไปก่อนแล้วก็ไม่เป็นไร
                // unique (tx_hash, wallet_key, direction) กันซ้ำอยู่
                TreasuryLedger::firstOrCreate(
                    [
                        'tx_hash' => $payout->tx_hash,
                        'wallet_key' => 'hot_wallet',
                        'direction' => TreasuryLedger::DIRECTION_DEBIT,
                    ],
                    [
                        'wallet_address' => (string) config('treasury.hot_wallet.address'),
                        'amount_wei' => (string) $payout->amount_wei,
                        'source' => 'payout',
                        'payout_id' => $payout->id,
                        'block_number' => $blockNumber,
                        'note' => 'จ่ายจากคิว #'.$payout->id,
                    ],
                );
            });

            $this->info("  #{$payout->id} สำเร็จ (บล็อก {$blockNumber})");
        }
    }

    /**
     * ดึง receipt — คืน null เมื่อยังไม่เข้าบล็อกหรืออ่านไม่ได้.
     */
    private function receipt(string $txHash): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('treasury.rpc_user_agent'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('treasury.rpc_timeout', 10))
                ->post((string) config('treasury.rpc_url'), [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getTransactionReceipt',
                    'params' => [$txHash],
                    'id' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) && is_array($data['result'] ?? null) ? $data['result'] : null;
        } catch (\Throwable $e) {
            Log::warning('treasury: อ่าน receipt ไม่สำเร็จ', ['tx' => $txHash, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
