<?php

namespace App\Jobs;

use App\Models\FactoryToken;
use App\Services\TokenFactory\FactoryDeploymentFinalizer;
use App\Services\Web3DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ตรวจผลธุรกรรมสร้างเหรียญที่ส่งไปแล้ว — ทางที่ "รันซ้ำได้" ของการ deploy.
 *
 * ไม่เซ็นธุรกรรมใหม่เด็ดขาด อ่าน receipt อย่างเดียว (ถ้าโหนดลืมธุรกรรมไปแล้วและ nonce ยังว่าง
 * สคริปต์ส่ง rawTx เดิมซ้ำ — hash เดิม จึงได้เหรียญได้แค่ตัวเดียว)
 *
 * ยังไม่มีผล → release() ถามใหม่ทุก 30 วินาที ราว 20 นาที
 * ครบแล้วยังไม่ได้ผล → ไม่ mark failed (ธุรกรรมอาจยังขึ้นเชนได้) แต่จดคำเตือนให้แอดมินกด "ตรวจผลอีกครั้ง"
 */
class CheckFactoryDeployReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 40;

    public int $timeout = 90;

    public int $tokenId;

    public function __construct(FactoryToken|int $token)
    {
        $this->tokenId = $token instanceof FactoryToken ? (int) $token->id : $token;
    }

    public function handle(Web3DeploymentService $deploymentService, FactoryDeploymentFinalizer $finalizer): void
    {
        $token = FactoryToken::find($this->tokenId);

        if (! $token || $token->status !== 'deploying' || ! $token->tx_hash) {
            return;
        }

        $result = $deploymentService->checkDeployment(
            $token->tx_hash,
            $token->getRawOriginal('deploy_raw_tx'),
            $token->token_type,
            $token->metadata['sub_options'] ?? [],
        );

        $outcome = $finalizer->apply($token->id, $result, 'receipt');

        if ($outcome !== 'pending' || $this->job === null) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release(30);

            return;
        }

        $this->warnAdmin('ยังไม่ได้ผลธุรกรรมหลังถามซ้ำหลายรอบ');
    }

    public function failed(\Throwable $exception): void
    {
        $this->warnAdmin($exception->getMessage());
    }

    /** ไม่ mark failed — ธุรกรรมอาจยังขึ้นเชนได้ จดคำเตือนให้แอดมินตามต่อ */
    private function warnAdmin(string $reason): void
    {
        $token = FactoryToken::find($this->tokenId);
        if (! $token || $token->status !== 'deploying') {
            return;
        }

        Log::error('CheckFactoryDeployReceiptJob: ตรวจผลไม่สำเร็จหลายครั้ง', [
            'token_id' => $token->id,
            'tx' => $token->tx_hash,
            'reason' => $reason,
        ]);

        FactoryToken::whereKey($token->id)->where('status', 'deploying')->update([
            'metadata' => json_encode(array_merge($token->metadata ?? [], [
                'deploy_warning' => 'ตรวจผลธุรกรรมไม่สำเร็จหลายครั้ง — กด "ตรวจผลอีกครั้ง" หรือดู tx บน explorer',
            ]), JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
}
