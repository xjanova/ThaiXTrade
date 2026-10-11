<?php

namespace App\Jobs;

use App\Models\FactoryToken;
use App\Services\BlockscoutVerifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ยืนยันซอร์สของเหรียญบน Blockscout — งานแยกจาก deploy.
 *
 * เดิมอยู่ในงาน deploy (sleep 5 วินาที + HTTP 60 วินาที) กินเวลาจนงาน deploy timeout
 * แล้วถูกรันซ้ำ = ส่งธุรกรรมสร้างเหรียญซ้ำ ตอนนี้ล้มหรือช้าแค่ไหนก็ไม่กระทบเหรียญ
 *
 * รอบแรกส่งคำขอ รอบถัดไปถามผล (release) — ผลเก็บใน metadata.explorer_verification
 * ⚠️ explorer_verification คือ "ซอร์สบน explorer" ไม่ใช่ป้าย Verified ของเว็บ (is_verified = แอดมินตัดสิน)
 */
class VerifyFactoryTokenOnExplorerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 90;

    public int $tokenId;

    public function __construct(FactoryToken|int $token)
    {
        $this->tokenId = $token instanceof FactoryToken ? (int) $token->id : $token;
    }

    public function handle(BlockscoutVerifyService $explorer): void
    {
        $token = FactoryToken::find($this->tokenId);
        if (! $token || $token->status !== 'deployed' || ! $token->contract_address) {
            return;
        }

        $state = $token->metadata['explorer_verification']['status'] ?? null;
        if (in_array($state, ['verified', 'skipped'], true)) {
            return;
        }

        if ($state !== 'submitted') {
            $result = $explorer->submit($token);

            $status = match ($result['status']) {
                'already_verified' => 'verified',
                default => $result['status'],
            };

            $this->record($token, [
                'status' => $status,
                'message' => $result['message'],
                'contract_name' => $result['contract_name'],
            ]);

            if ($status === 'submitted' || $status === 'error') {
                $this->retryLater(20);
            }

            return;
        }

        $verified = $explorer->isVerified($token->contract_address);
        if ($verified === true) {
            $this->record($token, ['status' => 'verified', 'message' => null]);

            return;
        }

        $this->retryLater(30);
    }

    private function retryLater(int $seconds): void
    {
        if ($this->job !== null && $this->attempts() < $this->tries) {
            $this->release($seconds);
        }
    }

    private function record(FactoryToken $token, array $values): void
    {
        $fresh = $token->fresh();
        if (! $fresh) {
            return;
        }

        $metadata = $fresh->metadata ?? [];
        $metadata['explorer_verification'] = array_merge(
            $metadata['explorer_verification'] ?? [],
            $values,
            ['updated_at' => now()->toIso8601String()]
        );

        // เขียนเฉพาะ metadata — ไม่แตะสถานะ / is_verified / is_listed
        FactoryToken::whereKey($fresh->id)->update([
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
}
