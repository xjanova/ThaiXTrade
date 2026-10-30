<?php

namespace App\Console\Commands;

use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\FactoryToken;
use App\Models\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ค่ายืนยันซอร์สซื้อซ้ำไม่ได้ งานในคิวลอง 6 รอบแล้วเลิก — explorer ล่มนานกว่านั้นผู้ใช้เสียเงินฟรี
 * คำสั่งนี้วนส่งเหรียญที่จ่ายแล้วแต่ยังไม่ขึ้น verified ให้ลองใหม่โดยไม่คิดเงินซ้ำ
 * เกินวันแล้วยังไม่ได้ เตือนแอดมินให้แก้ explorer หรือคืนเงิน.
 */
class TokenFactorySourceVerifyRetry extends Command
{
    protected $signature = 'tpix:token-factory-source-verify';

    protected $description = 'ลองส่งซอร์สใหม่ให้เหรียญที่จ่ายค่ายืนยันแล้วแต่ explorer ยังไม่ยืนยัน';

    /** ให้งานรอบก่อนทำจบก่อน — รอบหนึ่งกินเวลาไม่กี่นาที */
    private const QUIET_MINUTES = 30;

    private const ALERT_AFTER_HOURS = 24;

    private const BATCH = 100;

    public function handle(): int
    {
        $tokens = FactoryToken::query()
            ->where('status', 'deployed')
            ->whereNotNull('contract_address')
            ->where('metadata->source_verify->paid', true)
            ->where(fn ($query) => $query
                ->whereNull('metadata->explorer_verification->status')
                ->orWhere('metadata->explorer_verification->status', '!=', 'verified'))
            ->where('updated_at', '<', now()->subMinutes(self::QUIET_MINUTES))
            ->orderBy('updated_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($tokens as $token) {
            VerifyFactoryTokenOnExplorerJob::dispatch($token->id);
            $this->alertIfLate($token);
        }

        $this->info("ส่งลองใหม่ {$tokens->count()} เหรียญ");

        return self::SUCCESS;
    }

    private function alertIfLate(FactoryToken $token): void
    {
        $paidAt = $token->metadata['source_verify']['paid_at'] ?? null;
        if ($paidAt === null || Carbon::parse($paidAt)->gt(now()->subHours(self::ALERT_AFTER_HOURS))) {
            return;
        }

        SystemAlert::raise(
            'token-factory',
            "source_verify_undelivered_{$token->id}",
            'warning',
            "เหรียญ {$token->symbol} (#{$token->id}) จ่ายค่ายืนยันซอร์สเกิน ".self::ALERT_AFTER_HOURS.' ชม. แล้วยังไม่ได้บริการ ต้องแก้ explorer หรือคืนเงิน',
            ['status' => $token->metadata['explorer_verification']['status'] ?? null],
        );
    }
}
