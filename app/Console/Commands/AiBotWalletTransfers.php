<?php

namespace App\Console\Commands;

use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\Wallet\BotWalletSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ตัวส่งจริงของคิวถอนกระเป๋าบอท (CLI/cron เท่านั้น).
 *
 * ลำดับต่อรายการ (ห้ามสลับ):
 *   queued → signing (ถอดกุญแจ → เซ็น) → บันทึก tx_hash → broadcasting → ส่งขึ้นเชน
 *   → ตาม receipt จนครบ confirmations → confirmed (แล้วอ่านยอดใหม่)
 *
 * "บันทึก hash ก่อนส่ง" คือหัวใจ: ถ้าเครื่องดับหลังส่งแต่ก่อนบันทึก รอบหน้าจะไม่รู้
 * ว่าเคยส่งแล้ว เซ็นใหม่ด้วย nonce ใหม่ = โอนซ้ำสองรอบ
 *
 * ทีละหนึ่งรายการต่อกระเป๋าต่อรอบ — กระเป๋าเดียวกันห้ามมีสอง nonce ค้างพร้อมกัน
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransfers extends Command
{
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

        $this->trackBroadcasting($wallets, $signer);

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
            $this->markFailed($transfer,'ปลายทางไม่ใช่กระเป๋าของเจ้าของ');

            return;
        }

        $transfer->update(['status' => AiBotWalletTransfer::STATUS_SIGNING]);

        try {
            $privateKey = $keyring->open($wallet);
        } catch (\Throwable $e) {
            $this->markFailed($transfer,'ถอดกุญแจไม่สำเร็จ: '.$e->getMessage());

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

        if (! ($signed['ok'] ?? false)) {
            $this->markFailed($transfer,'เซ็นไม่สำเร็จ: '.($signed['error'] ?? 'ไม่ทราบสาเหตุ'));

            return;
        }

        // ⚠️ บันทึก hash ก่อนส่ง — ห้ามสลับลำดับ
        $transfer->update([
            'status' => AiBotWalletTransfer::STATUS_BROADCASTING,
            'tx_hash' => $signed['txHash'],
            'nonce' => $signed['nonce'] ?? null,
            'broadcast_at' => now(),
        ]);

        $result = $signer->send($signed['raw']);

        if (! ($result['ok'] ?? false)) {
            if (($result['code'] ?? '') === 'nonce_used') {
                $this->warn("  #{$transfer->id} nonce ถูกใช้แล้ว — รอตรวจ receipt");

                return;
            }

            $this->markFailed($transfer,'ส่งขึ้นเชนไม่สำเร็จ: '.($result['error'] ?? 'ไม่ทราบสาเหตุ'));

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

    private function trackBroadcasting(BotWalletService $wallets, BotWalletSigner $signer): void
    {
        $pending = AiBotWalletTransfer::where('status', AiBotWalletTransfer::STATUS_BROADCASTING)
            ->whereNotNull('tx_hash')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $need = max(1, (int) config('aibot.bot_wallet.confirmations', 3));
        $head = $signer->blockNumber();

        foreach ($pending as $transfer) {
            $receipt = $signer->receipt((string) $transfer->tx_hash);

            if ($receipt === null) {
                continue;   // ยังไม่เข้าบล็อก — รอรอบหน้า
            }

            $block = isset($receipt['blockNumber']) ? (int) hexdec((string) $receipt['blockNumber']) : null;

            if (($receipt['status'] ?? '0x0') !== '0x1') {
                $transfer->update([
                    'status' => AiBotWalletTransfer::STATUS_FAILED,
                    'block_number' => $block,
                    'failure_reason' => 'เชนปฏิเสธธุรกรรม (status = 0x0)',
                ]);
                $this->error("  #{$transfer->id} เชนปฏิเสธ");

                continue;
            }

            $confirmations = ($head !== null && $block !== null) ? max(0, $head - $block + 1) : 0;

            if ($confirmations < $need) {
                $transfer->update(['block_number' => $block, 'confirmations' => $confirmations]);

                continue;
            }

            $transfer->update([
                'status' => AiBotWalletTransfer::STATUS_CONFIRMED,
                'block_number' => $block,
                'confirmations' => $confirmations,
                'confirmed_at' => now(),
            ]);

            $wallets->refreshBalances($transfer->wallet);
            $this->info("  #{$transfer->id} สำเร็จ (บล็อก {$block})");
        }
    }

    /** ชื่อไม่ใช่ fail() — Command ของ Laravel 11 มี fail() สาธารณะอยู่แล้ว ชนกันแล้วบูตไม่ขึ้น */
    private function markFailed(AiBotWalletTransfer $transfer, string $reason): void
    {
        $transfer->update(['status' => AiBotWalletTransfer::STATUS_FAILED, 'failure_reason' => $reason]);
        Log::warning('bot-wallet: รายการถอนล้มเหลว', ['transfer_id' => $transfer->id, 'reason' => $reason]);
        $this->error("  #{$transfer->id} {$reason}");
    }
}
