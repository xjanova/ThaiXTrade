<?php

namespace App\Console\Commands;

use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ทางออกของทีมงานสำหรับรายการถอนของกระเป๋าบอทที่ค้าง.
 *
 * ตัวส่ง (aibot:wallet-transfers) ปิดรายการค้างได้เองเกือบทุกกรณีแล้ว คำสั่งนี้มีไว้สำหรับ
 * กรณีที่ระบบ "ตั้งใจไม่ตัดสินเอง" — เช่นธุรกรรมค้างใน mempool นานผิดปกติ (แก๊สต่ำ) ซึ่งยังลงบล็อกได้
 *
 *   aibot:wallet-transfer-resolve 42             ดูสถานะในฐานข้อมูลเทียบกับบนเชน (ไม่เปลี่ยนอะไร)
 *   aibot:wallet-transfer-resolve 42 --requeue   คืนเข้าคิว — เฉพาะ "กำลังเซ็น" ที่ยังไม่มี hash (ยังไม่เคยส่ง)
 *   aibot:wallet-transfer-resolve 42 --fail      ปิดเป็นไม่ได้โอน — เฉพาะเมื่อไม่มีใบเสร็จและเชนไม่รู้จัก tx
 *   aibot:wallet-transfer-resolve 42 --fail --force   ปิดทั้งที่ tx ยังอยู่ใน mempool (ทีมงานรับผิดชอบเอง)
 *
 * ⚠️ ไม่มีทาง "ตีว่าสำเร็จ" ด้วยมือ — สำเร็จต้องมาจากใบเสร็จบนเชนเท่านั้น (ตัวส่งปิดให้เอง)
 *    รายการที่ปิดเป็นไม่ได้โอนแล้วโผล่มาลงบล็อกทีหลัง ตัวส่งแก้กลับเป็นสำเร็จให้เอง
 *
 * ไม่ใช้กุญแจใด ๆ — อ่านเชนอย่างเดียว
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransferResolve extends Command
{
    protected $signature = 'aibot:wallet-transfer-resolve
        {id : เลขรายการถอน (ai_bot_wallet_transfers.id)}
        {--requeue : คืนรายการ "กำลังเซ็น" ที่ยังไม่มี hash เข้าคิว}
        {--fail : ปิดรายการเป็นไม่ได้โอน (ต้องไม่มีใบเสร็จบนเชน)}
        {--force : ยอมปิดทั้งที่ tx ยังอยู่ใน mempool}';

    protected $description = 'ตรวจ/ปิดรายการถอนของกระเป๋าบอทที่ค้าง โดยเทียบกับบนเชนก่อนทุกครั้ง';

    public function handle(BotWalletSigner $signer): int
    {
        $transfer = AiBotWalletTransfer::withdrawals()->find((int) $this->argument('id'));

        if (! $transfer) {
            $this->error('ไม่พบรายการถอนนี้');

            return self::FAILURE;
        }

        $hash = $transfer->tx_hash;
        $receipt = $hash ? $signer->receipt($hash) : null;
        $known = $hash ? $signer->transactionKnown($hash) : false;
        $latest = $transfer->wallet ? $signer->nonceOf((string) $transfer->wallet->address, 'latest') : null;

        $this->table(['ช่อง', 'ค่า'], [
            ['สถานะ', $transfer->status.($transfer->failure_code ? " ({$transfer->failure_code})" : '')],
            ['จำนวน', "{$transfer->amount} {$transfer->asset}"],
            ['tx hash', $hash ?? '—'],
            ['nonce ของรายการ', $transfer->nonce ?? '—'],
            ['nonce ที่ลงบล็อกแล้วของกระเป๋า', $latest ?? 'อ่านไม่ได้'],
            ['ใบเสร็จ', $receipt === null ? 'ไม่มี' : (($receipt['status'] ?? '') === '0x1' ? 'สำเร็จ' : 'revert')],
            ['โหนดรู้จัก tx', $known === null ? 'อ่านไม่ได้' : ($known ? 'ใช่ (mempool/ลงบล็อก)' : 'ไม่')],
            ['มี raw tx ให้ส่งซ้ำ', $transfer->raw_tx ? 'มี' : 'ไม่มี'],
        ]);

        if ($this->option('requeue')) {
            return $this->requeue($transfer);
        }

        if ($this->option('fail')) {
            return $this->closeAsFailed($transfer, $receipt, $known);
        }

        return self::SUCCESS;
    }

    private function requeue(AiBotWalletTransfer $transfer): int
    {
        // มี hash = เคยมีธุรกรรมที่อาจออกไปแล้ว — คืนเข้าคิวคือเซ็นใหม่ด้วย nonce ใหม่ = เสี่ยงโอนซ้ำ
        $done = AiBotWalletTransfer::whereKey($transfer->id)
            ->where('status', AiBotWalletTransfer::STATUS_SIGNING)
            ->whereNull('tx_hash')
            ->update(['status' => AiBotWalletTransfer::STATUS_QUEUED, 'signing_at' => null]);

        if ($done !== 1) {
            $this->error('คืนเข้าคิวได้เฉพาะรายการ "กำลังเซ็น" ที่ยังไม่มี tx hash เท่านั้น');

            return self::FAILURE;
        }

        Log::warning('bot-wallet: ทีมงานคืนรายการเข้าคิว', ['transfer_id' => $transfer->id]);
        $this->info("#{$transfer->id} คืนเข้าคิวแล้ว — ตัวส่งจะเซ็นใหม่ในรอบถัดไป");

        return self::SUCCESS;
    }

    /** ชื่อไม่ใช่ fail() — Command ของ Laravel 11 มี fail() สาธารณะอยู่แล้ว */
    private function closeAsFailed(AiBotWalletTransfer $transfer, ?array $receipt, ?bool $known): int
    {
        if (! in_array($transfer->status, [AiBotWalletTransfer::STATUS_SIGNING, AiBotWalletTransfer::STATUS_BROADCASTING], true)) {
            $this->error("ปิดได้เฉพาะรายการที่กำลังเซ็น/รอผล (ตอนนี้: {$transfer->status})");

            return self::FAILURE;
        }

        if ($receipt !== null) {
            // มีใบเสร็จแล้ว = ผลจริงอยู่บนเชน (สำเร็จหรือ revert) — ตัวส่งปิดตามนั้นเอง ห้ามทับด้วยมือ
            $this->error('ธุรกรรมนี้มีใบเสร็จบนเชนแล้ว — รอ aibot:wallet-transfers ปิดรายการตามผลจริง');

            return self::FAILURE;
        }

        if ($transfer->tx_hash !== null && $known === null) {
            $this->error('อ่านเชนไม่ได้ตอนนี้ — ห้ามตัดสินโดยไม่รู้สถานะบนเชน ลองใหม่อีกครั้ง');

            return self::FAILURE;
        }

        if ($known === true && ! $this->option('force')) {
            $this->error('tx ยังอยู่ใน mempool และยังลงบล็อกได้ — ใช้ --force ถ้าตรวจแล้วว่าต้องการปิด (ตัวส่งจะแก้กลับเป็นสำเร็จเองถ้ามันลงบล็อกทีหลัง)');

            return self::FAILURE;
        }

        $done = AiBotWalletTransfer::whereKey($transfer->id)
            ->where('status', $transfer->status)
            ->update([
                'status' => AiBotWalletTransfer::STATUS_FAILED,
                'failure_code' => AiBotWalletTransfer::FAIL_RESOLVED_BY_ADMIN,
                'failure_reason' => AiBotWalletTransfer::FAILURE_MESSAGES[AiBotWalletTransfer::FAIL_RESOLVED_BY_ADMIN],
            ]);

        if ($done !== 1) {
            $this->error('สถานะเปลี่ยนระหว่างตรวจ — รันคำสั่งนี้อีกครั้ง');

            return self::FAILURE;
        }

        Log::warning('bot-wallet: ทีมงานปิดรายการถอนเป็นไม่ได้โอน', [
            'transfer_id' => $transfer->id,
            'tx_hash' => $transfer->tx_hash,
            'forced' => (bool) $this->option('force'),
        ]);
        $this->info("#{$transfer->id} ปิดเป็นไม่ได้โอนแล้ว — ผู้ใช้ขอถอนใหม่ได้");

        return self::SUCCESS;
    }
}
