<?php

namespace App\Console\Commands;

use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\Wallet\BotWalletSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ตัวส่งจริงของคิวถอนกระเป๋าบอท (CLI/cron เท่านั้น).
 *
 * ลำดับต่อรายการ (ห้ามสลับ):
 *   queued → signing (ถอดกุญแจ → เซ็น) → บันทึก tx_hash + raw → broadcasting → ส่งขึ้นเชน
 *   → ตาม receipt จนครบ confirmations → confirmed (แล้วอ่านยอดใหม่)
 *
 * "บันทึก hash ก่อนส่ง" คือหัวใจ: ถ้าเครื่องดับหลังส่งแต่ก่อนบันทึก รอบหน้าจะไม่รู้
 * ว่าเคยส่งแล้ว เซ็นใหม่ด้วย nonce ใหม่ = โอนซ้ำสองรอบ
 *
 * ═══ ไม่มีรายการไหนค้างตลอดกาล (ผู้ใช้ไม่มีกุญแจของกระเป๋าบอท — ค้าง = เงินถอนไม่ได้) ═══
 *   signing ไม่มี hash นานเกินรอบปกติ  → โปรเซสตายก่อนบันทึก ยังไม่มีอะไรออกจากเครื่อง → คืนเข้าคิว
 *   ส่งไม่ผ่าน (RPC ล่ม/หมดเวลา)       → ไม่ตีว่าล้ม (โหนดอาจรับไปแล้ว) คงรอผล แล้วตามด้วยใบเสร็จ
 *   เชนไม่รู้จัก tx                    → ส่ง raw เดิมซ้ำ (nonce เดิม ลงบล็อกได้ครั้งเดียว)
 *   ไม่รู้จัก + nonce ถูกตัวอื่นใช้     → (นานพอ) failed/nonce_consumed — ของเราไม่มีวันลงบล็อกแล้ว
 *   ไม่รู้จัก + nonce ยังว่าง นานเกิน   → failed/dropped — ปลอดภัย: รายการใหม่ได้ nonce เดิม ลงได้ตัวเดียว
 *   ตัดสินจาก "ไม่เห็น" แล้วโผล่มาลงบล็อกทีหลัง → แก้กลับเป็น confirmed (ตามต่ออีก recheck_failed_hours)
 *
 * ทุกการเปลี่ยนสถานะเป็น compare-and-set — cron สองรอบที่ซ้อนกัน (รอบหนึ่งช้าเกิน mutex)
 * หยิบรายการเดียวกันไปเซ็นสองครั้งไม่ได้ ตัวที่แพ้ทิ้งธุรกรรมที่เซ็นไว้โดยไม่ส่ง
 *
 * ทีละหนึ่งรายการต่อกระเป๋าต่อรอบ — กระเป๋าเดียวกันห้ามมีสอง nonce ค้างพร้อมกัน
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransfers extends Command
{
    /** เชนไม่รู้จัก tx นานเกินนี้ (วินาที) ถึงเริ่มถามต่อ/ส่ง raw เดิมซ้ำ — กันยิง RPC ทุกรอบตั้งแต่วินาทีแรก */
    public const REBROADCAST_AFTER_SECONDS = 60;

    protected $signature = 'aibot:wallet-transfers {--limit=10 : จำนวนกระเป๋าสูงสุดที่ส่งต่อรอบ}';

    protected $description = 'เซ็นและส่งรายการถอนของกระเป๋าบอทที่เข้าคิวไว้ แล้วตามผลบนเชน';

    public function handle(BotWalletService $wallets, BotWalletKeyring $keyring, BotWalletSigner $signer): int
    {
        if (! $wallets->enabled()) {
            $this->info('กระเป๋าบอทยังไม่เปิดใช้ (AIBOT_BOT_WALLET_ENABLED=false)');

            return self::SUCCESS;
        }

        if (! $signer->isAvailable()) {
            $this->error('เซ็นได้เฉพาะฝั่ง CLI — รอบนี้ข้าม');

            return self::FAILURE;
        }

        $this->requeueStaleSigning();
        $this->trackBroadcasting($wallets, $signer);
        $this->recheckUnseenFailures($wallets, $signer);

        $queued = AiBotWalletTransfer::withdrawals()
            ->where('status', AiBotWalletTransfer::STATUS_QUEUED)
            ->orderBy('id')
            ->get()
            ->unique('ai_bot_wallet_id')   // กระเป๋าละหนึ่งรายการต่อรอบ
            ->take(max(1, (int) $this->option('limit')));

        if ($queued->isEmpty()) {
            $this->info('ไม่มีรายการถอนรอส่ง');

            return self::SUCCESS;
        }

        foreach ($queued as $transfer) {
            $this->sendOne($transfer, $keyring, $signer);
        }

        return self::SUCCESS;
    }

    private function sendOne(AiBotWalletTransfer $transfer, BotWalletKeyring $keyring, BotWalletSigner $signer): void
    {
        $wallet = $transfer->wallet;

        // กระเป๋าเดียวกันต้องไม่มีรายการที่กำลังส่งอยู่ (กันเคสรอบก่อนยังตาม receipt ไม่จบ)
        $busy = $wallet->transfers()->withdrawals()
            ->whereIn('status', [AiBotWalletTransfer::STATUS_SIGNING, AiBotWalletTransfer::STATUS_BROADCASTING])
            ->where('id', '!=', $transfer->id)
            ->exists();

        if ($busy) {
            $this->line("  #{$transfer->id} รอ — กระเป๋านี้มีรายการกำลังส่งอยู่");

            return;
        }

        // ปลายทางต้องเป็นเจ้าของเสมอ — ตรวจซ้ำตอนจะส่งจริง ไม่เชื่อค่าที่บันทึกไว้อย่างเดียว
        if (strcasecmp($transfer->to_address, $wallet->owner_address) !== 0) {
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_QUEUED, AiBotWalletTransfer::FAIL_OWNER_MISMATCH, 'to_address != owner_address');

            return;
        }

        // หยิบรายการแบบ compare-and-set — ยกเลิกไปแล้ว/อีกรอบหยิบไปก่อน = ไม่แตะกุญแจเลย
        if (! $this->transition($transfer, AiBotWalletTransfer::STATUS_QUEUED, [
            'status' => AiBotWalletTransfer::STATUS_SIGNING,
            'signing_at' => now(),
        ])) {
            $this->line("  #{$transfer->id} ข้าม — สถานะเปลี่ยนไปแล้ว (ยกเลิก หรือรอบอื่นหยิบไปก่อน)");

            return;
        }

        try {
            $privateKey = $keyring->open($wallet);
        } catch (\Throwable $e) {
            // ข้อความจากตัวถอดกุญแจ (เช่น "The MAC is invalid") ไปอยู่ใน log เท่านั้น ไม่ถึงผู้ใช้
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_SIGNING, AiBotWalletTransfer::FAIL_KEY_UNREADABLE, $e->getMessage());

            return;
        }

        $signed = $signer->sign(
            $privateKey,
            $wallet->address,
            $transfer->to_address,
            (string) $transfer->amount_wei,
            $transfer->token_address,
        );
        unset($privateKey);

        if (! ($signed['ok'] ?? false) || empty($signed['txHash']) || empty($signed['raw'])) {
            // ยังไม่มีอะไรออกจากเครื่อง — ล้มได้อย่างปลอดภัย เงินยังอยู่ในกระเป๋าบอท
            $code = ($signed['code'] ?? '') === 'estimate_failed' ? AiBotWalletTransfer::FAIL_INSUFFICIENT : AiBotWalletTransfer::FAIL_SIGN;
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_SIGNING, $code, ($signed['code'] ?? 'error').': '.($signed['error'] ?? ''));

            return;
        }

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับ
        // compare-and-set: ระหว่างเซ็นมีคนปิดรายการ/อีกรอบคืนเข้าคิว = ทิ้งธุรกรรมที่เซ็นไว้ (ยังไม่ส่ง nonce ไม่ถูกใช้)
        $recorded = $this->transition($transfer, AiBotWalletTransfer::STATUS_SIGNING, [
            'status' => AiBotWalletTransfer::STATUS_BROADCASTING,
            'tx_hash' => strtolower((string) $signed['txHash']),
            'nonce' => $signed['nonce'] ?? null,
            'raw_tx' => (string) $signed['raw'],
            'broadcast_at' => now(),
        ]);

        if (! $recorded) {
            Log::warning('bot-wallet: รายการเปลี่ยนสถานะระหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', ['transfer_id' => $transfer->id]);
            $this->warn("  #{$transfer->id} สถานะเปลี่ยนระหว่างเซ็น — ไม่ส่ง");

            return;
        }

        $result = $signer->send((string) $signed['raw']);

        if (! ($result['ok'] ?? false)) {
            /*
             * ส่งไม่ผ่าน ≠ ไม่ได้โอน — โหนดอาจรับไปแล้วแต่ตอบไม่ทัน (หมดเวลา 120 วินาที / เน็ตหลุด)
             * เดิมตีว่า failed ทันที รายการหลุดจากโควตาวันและล็อกต่อกระเป๋า ทั้งที่ธุรกรรมลงบล็อกได้
             * → คงสถานะรอผล ให้ trackBroadcasting ตัดสินจากใบเสร็จ/ส่ง raw เดิมซ้ำ
             */
            Log::warning('bot-wallet: ส่งขึ้นเชนไม่ผ่าน — คงสถานะรอผล รอบหน้าตรวจใบเสร็จ/ส่งซ้ำ', [
                'transfer_id' => $transfer->id,
                'code' => $result['code'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
            $this->warn("  #{$transfer->id} ส่งไม่ผ่าน (".($result['code'] ?? 'error').') — รอตรวจ receipt รอบหน้า');

            return;
        }

        Log::info('bot-wallet: ส่งรายการถอนขึ้นเชนแล้ว', [
            'transfer_id' => $transfer->id,
            'wallet' => $wallet->address,
            'asset' => $transfer->asset,
            'tx_hash' => $signed['txHash'],
        ]);

        $this->info("  #{$transfer->id} ส่งแล้ว {$transfer->amount} {$transfer->asset} → {$signed['txHash']}");
    }

    /**
     * "กำลังเซ็น" ค้างโดยยังไม่มี hash = โปรเซสตายระหว่างถอดกุญแจ/เซ็น.
     *
     * ปลอดภัยที่จะคืนเข้าคิว เพราะ hash ถูกบันทึกก่อนส่งเสมอ — ไม่มี hash = ไม่เคยส่ง
     * เงื่อนไข tx_hash IS NULL อยู่ใน UPDATE ด้วย ไม่ใช่แค่ตอนเลือก
     */
    private function requeueStaleSigning(): void
    {
        $cutoff = now()->subMinutes(max(1, (int) config('aibot.bot_wallet.signing_stale_minutes', 10)));

        $stale = AiBotWalletTransfer::withdrawals()
            ->where('status', AiBotWalletTransfer::STATUS_SIGNING)
            ->whereNull('tx_hash')
            ->where(fn ($q) => $q->where('signing_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('signing_at')->where('updated_at', '<', $cutoff)))
            ->get();

        foreach ($stale as $transfer) {
            $done = AiBotWalletTransfer::whereKey($transfer->id)
                ->where('status', AiBotWalletTransfer::STATUS_SIGNING)
                ->whereNull('tx_hash')
                ->update(['status' => AiBotWalletTransfer::STATUS_QUEUED, 'signing_at' => null]);

            if ($done === 1) {
                Log::warning('bot-wallet: รายการค้างสถานะกำลังเซ็น (ยังไม่มี hash) — คืนเข้าคิว', ['transfer_id' => $transfer->id]);
                $this->warn("  #{$transfer->id} ค้างกำลังเซ็น — คืนเข้าคิว");
            }
        }
    }

    private function trackBroadcasting(BotWalletService $wallets, BotWalletSigner $signer): void
    {
        $pending = AiBotWalletTransfer::where('status', AiBotWalletTransfer::STATUS_BROADCASTING)
            ->whereNotNull('tx_hash')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $need = max(1, (int) config('aibot.bot_wallet.confirmations', 3));
        $stuckMinutes = max(1, (int) config('aibot.bot_wallet.stuck_after_minutes', 30));
        $head = $signer->blockNumber();

        foreach ($pending as $transfer) {
            $hash = (string) $transfer->tx_hash;
            $receipt = $signer->receipt($hash);

            if ($receipt !== null) {
                $this->settle($wallets, $transfer, $receipt, $head, $need);

                continue;
            }

            $since = $transfer->broadcast_at ?? $transfer->updated_at;
            $ageSeconds = $since ? (int) abs(now()->diffInSeconds($since)) : PHP_INT_MAX;

            // เพิ่งส่ง — ยังไม่ลงบล็อกเป็นเรื่องปกติ ไม่ต้องถามเชนเพิ่ม
            if ($ageSeconds < self::REBROADCAST_AFTER_SECONDS) {
                continue;
            }

            $known = $signer->transactionKnown($hash);
            if ($known === null) {
                continue;   // อ่านไม่ได้ ≠ หาย — รอ
            }

            if ($known) {
                // อยู่ใน mempool แต่ไม่ลงบล็อกนานผิดปกติ (แก๊สต่ำ) — ยังลงได้อยู่ ห้ามตัดสินเอง แจ้งทีมงานครั้งเดียวต่อชั่วโมง
                if ($ageSeconds >= $stuckMinutes * 180 && Cache::add("bot-wallet:stuck-warned:{$transfer->id}", 1, 3600)) {
                    Log::warning('bot-wallet: ธุรกรรมค้างใน mempool นานผิดปกติ — ตรวจด้วย aibot:wallet-transfer-resolve', ['transfer_id' => $transfer->id, 'tx_hash' => $hash]);
                }

                continue;
            }

            // เชนไม่รู้จัก tx — nonce ของเราถูกใช้ไปแล้วหรือยัง
            $latest = $signer->nonceOf((string) $transfer->wallet->address, 'latest');
            if ($latest === null) {
                continue;
            }

            $stuck = $ageSeconds >= $stuckMinutes * 60;

            if ($transfer->nonce !== null && $latest > $transfer->nonce) {
                // nonce ถูกใช้แล้ว — อาจเป็นของเราเองที่เพิ่งลงบล็อก (RPC ตามไม่ทัน) ถามใบเสร็จอีกรอบก่อนตัดสิน
                $again = $signer->receipt($hash);
                if ($again !== null) {
                    $this->settle($wallets, $transfer, $again, $head, $need);
                } elseif ($stuck) {
                    $this->markFailed(
                        $transfer,
                        AiBotWalletTransfer::STATUS_BROADCASTING,
                        AiBotWalletTransfer::FAIL_NONCE_CONSUMED,
                        "nonce {$transfer->nonce} ถูกใช้โดยธุรกรรมอื่น (latest {$latest})"
                    );
                }

                continue;
            }

            if ($stuck) {
                // ไม่ลงบล็อก และ nonce ยังว่าง — รายการใหม่จะได้ nonce เดิม (ลงได้ตัวเดียว) จึงปิดได้ไม่เสี่ยงจ่ายซ้ำ
                $this->markFailed(
                    $transfer,
                    AiBotWalletTransfer::STATUS_BROADCASTING,
                    AiBotWalletTransfer::FAIL_DROPPED,
                    "ไม่เห็นบนเชนหลัง {$stuckMinutes} นาที (nonce {$transfer->nonce} ยังว่าง)"
                );

                continue;
            }

            if (! $transfer->raw_tx) {
                continue;   // แถวก่อนมี raw — ส่งซ้ำไม่ได้ รอจนครบเวลาแล้วปิดเป็น dropped
            }

            // ส่ง raw เดิมซ้ำ — nonce เดิม ลงบล็อกได้ครั้งเดียว ไม่มีทางกลายเป็นสองรายการ
            $sent = $signer->send((string) $transfer->raw_tx);
            Log::info('bot-wallet: ส่ง raw เดิมซ้ำ', ['transfer_id' => $transfer->id, 'ok' => $sent['ok'] ?? false, 'code' => $sent['code'] ?? null]);
        }
    }

    /** ตัดสินจากใบเสร็จ — revert = ล้ม · ครบยืนยัน = สำเร็จ · ยังไม่ครบ = รอ */
    private function settle(BotWalletService $wallets, AiBotWalletTransfer $transfer, array $receipt, ?int $head, int $need): void
    {
        $block = isset($receipt['blockNumber']) ? (int) hexdec((string) $receipt['blockNumber']) : null;

        if (($receipt['status'] ?? '0x0') !== '0x1') {
            $transfer->forceFill(['block_number' => $block])->save();
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_BROADCASTING, AiBotWalletTransfer::FAIL_REVERTED, 'receipt status 0x0');

            return;
        }

        $confirmations = ($head !== null && $block !== null) ? max(0, $head - $block + 1) : 0;

        if ($confirmations < $need) {
            $transfer->update(['block_number' => $block, 'confirmations' => $confirmations]);

            return;
        }

        if ($this->transition($transfer, AiBotWalletTransfer::STATUS_BROADCASTING, [
            'status' => AiBotWalletTransfer::STATUS_CONFIRMED,
            'block_number' => $block,
            'confirmations' => $confirmations,
            'confirmed_at' => now(),
        ])) {
            $wallets->refreshBalances($transfer->wallet);
            $this->info("  #{$transfer->id} สำเร็จ (บล็อก {$block})");
        }
    }

    /**
     * รายการที่ตัดสินว่า "ไม่ได้โอน" จากการไม่เห็นบนเชน (ไม่ใช่จากใบเสร็จ) — ตามใบเสร็จต่ออีกพักหนึ่ง.
     *
     * ธุรกรรมที่โหนดเราไม่รู้จักอาจยังลอยอยู่ในโหนดอื่นแล้วลงบล็อกทีหลัง ถ้าไม่แก้กลับ
     * ประวัติจะบอกว่าไม่ได้โอนทั้งที่เงินถึงเจ้าของแล้ว และโควตารายวันนับขาด
     */
    private function recheckUnseenFailures(BotWalletService $wallets, BotWalletSigner $signer): void
    {
        $rows = AiBotWalletTransfer::withdrawals()
            ->where('status', AiBotWalletTransfer::STATUS_FAILED)
            ->whereIn('failure_code', AiBotWalletTransfer::UNSEEN_FAILURES)
            ->whereNotNull('tx_hash')
            ->where('updated_at', '>=', now()->subHours(max(1, (int) config('aibot.bot_wallet.recheck_failed_hours', 24))))
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $need = max(1, (int) config('aibot.bot_wallet.confirmations', 3));
        $head = $signer->blockNumber();

        foreach ($rows as $transfer) {
            $receipt = $signer->receipt((string) $transfer->tx_hash);
            if ($receipt === null || ($receipt['status'] ?? '0x0') !== '0x1') {
                continue;
            }

            $block = isset($receipt['blockNumber']) ? (int) hexdec((string) $receipt['blockNumber']) : null;
            $confirmations = ($head !== null && $block !== null) ? max(0, $head - $block + 1) : 0;
            if ($confirmations < $need) {
                continue;
            }

            if ($this->transition($transfer, AiBotWalletTransfer::STATUS_FAILED, [
                'status' => AiBotWalletTransfer::STATUS_CONFIRMED,
                'block_number' => $block,
                'confirmations' => $confirmations,
                'confirmed_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ])) {
                Log::warning('bot-wallet: รายการที่ตัดสินว่าไม่ได้โอน ลงบล็อกทีหลัง — แก้เป็นสำเร็จ', ['transfer_id' => $transfer->id, 'block' => $block]);
                $wallets->refreshBalances($transfer->wallet);
                $this->warn("  #{$transfer->id} ลงบล็อกทีหลัง — แก้เป็นสำเร็จ (บล็อก {$block})");
            }
        }
    }

    /** compare-and-set: เปลี่ยนสถานะเฉพาะเมื่อยังเป็นสถานะเดิม — คืน true ถ้าเราเป็นคนเปลี่ยน */
    private function transition(AiBotWalletTransfer $transfer, string $from, array $attributes): bool
    {
        $done = AiBotWalletTransfer::whereKey($transfer->id)->where('status', $from)->update($attributes) === 1;

        if ($done) {
            $transfer->refresh();
        }

        return $done;
    }

    /**
     * ชื่อไม่ใช่ fail() — Command ของ Laravel 11 มี fail() สาธารณะอยู่แล้ว ชนกันแล้วบูตไม่ขึ้น.
     *
     * failure_reason = ข้อความที่ผู้ใช้อ่านได้ตามรหัส · รายละเอียดดิบ ($detail) ลง log เท่านั้น
     */
    private function markFailed(AiBotWalletTransfer $transfer, string $from, string $code, string $detail = ''): void
    {
        $message = AiBotWalletTransfer::FAILURE_MESSAGES[$code] ?? AiBotWalletTransfer::FAILURE_MESSAGES[AiBotWalletTransfer::FAIL_SIGN];

        $done = $this->transition($transfer, $from, [
            'status' => AiBotWalletTransfer::STATUS_FAILED,
            'failure_code' => $code,
            'failure_reason' => $message,
        ]);

        if (! $done) {
            return;   // สถานะเปลี่ยนไปแล้ว (อีกรอบ/แอดมินจัดการ) — ไม่ทับ
        }

        Log::warning('bot-wallet: รายการถอนล้มเหลว', ['transfer_id' => $transfer->id, 'code' => $code, 'detail' => mb_substr($detail, 0, 500)]);
        $this->error("  #{$transfer->id} {$message}");
    }
}
