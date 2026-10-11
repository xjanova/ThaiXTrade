<?php

namespace App\Jobs;

use App\Models\FactoryToken;
use App\Services\TokenFactory\FactoryDeploymentFinalizer;
use App\Services\TokenFactory\TokenFactoryChain;
use App\Services\TokenFactory\TokenFactoryPolicy;
use App\Services\TokenFactoryService;
use App\Services\Web3DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * DeployTokenJob — ส่งธุรกรรมสร้างเหรียญ **ไม่เกินหนึ่งครั้งต่อการอนุมัติ**.
 *
 * ทำไมต้องเข้มขนาดนี้: เหรียญอย่าง IDTC (supply 1.1 ล้านล้าน) ถ้าส่งซ้ำ = มีสัญญา
 * supply เต็มหลายตัวบนเชน แก้คืนไม่ได้ ของเดิมมีทางส่งซ้ำได้หลายทาง:
 *   - tries=3 + timeout เท่ากับ process timeout + Blockscout sleep ในงานเดียวกัน
 *   - retry_after ของคิว (90) น้อยกว่า timeout (120) → คิวหยิบไปรันซ้ำทั้งที่ตัวแรกยังทำอยู่
 *   - handle() ไม่ดูสถานะ · tx hash ได้ตอนจบเท่านั้น · tx.wait() ไม่มี timeout
 *
 * ด่านที่ใส่:
 *   1. tries = 1 — ห้าม Laravel รันซ้ำเอง งานตรวจผล (CheckFactoryDeployReceiptJob) แยกต่างหาก
 *   2. แถวต้องอยู่ในสถานะ deploying (approveToken เปลี่ยนแบบ atomic) ไม่งั้นออกเลย
 *   3. แถวที่มี tx_hash แล้ว → ไม่ส่งใหม่ ส่งไปตรวจผลอย่างเดียว
 *   4. claim สิทธิ์ส่งแบบ atomic (deploy_started_at) — งานซ้อนกันได้สิทธิ์แค่ตัวเดียว
 *   5. ล็อกต่อกระเป๋าผู้ส่ง — ธุรกรรมของคีย์เดียวกันวิ่งทีละใบ (nonce ไม่ชน)
 *   6. สคริปต์บอก hash "ก่อน" ส่งขึ้นเชน และ PHP บันทึกทันที
 *   7. failed() ไม่แตะแถวที่ deployed แล้ว และไม่ mark failed ถ้ามีธุรกรรมค้างอยู่
 *
 * ⚠️ retry_after ของคิวต้องมากกว่า $timeout เสมอ (config/queue.php ตั้งไว้ 360)
 */
class DeployTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    /** ล็อกกระเป๋าผู้ส่งนานกว่าที่งานหนึ่งรันได้ */
    private const LOCK_SECONDS = 240;

    /** null ได้เพราะงานรุ่นเก่าในคิว (ก่อน hardening) ไม่มีช่องนี้ — ดู resolveTokenId() */
    public ?int $tokenId = null;

    /**
     * ช่องของงานรุ่นเก่า (เคยเก็บทั้งโมเดล) — ประกาศไว้ให้ SerializesModels คืนค่าได้
     * ไม่งั้นงานเก่าที่ค้างในคิวตอนอัปเกรดจะอ่าน $tokenId ที่ไม่ได้ตั้งค่า → Error ทั้งใน handle() และ failed()
     */
    public $token = null;

    /** แถวถูกลบไปแล้ว = ทิ้งงานเงียบ ๆ ไม่ต้องโยนตอนคืนค่าโมเดล */
    public bool $deleteWhenMissingModels = true;

    public function __construct(FactoryToken|int $token)
    {
        $this->tokenId = $token instanceof FactoryToken ? (int) $token->id : $token;
    }

    public function handle(
        Web3DeploymentService $deploymentService,
        FactoryDeploymentFinalizer $finalizer,
        TokenFactoryPolicy $policy,
        TokenFactoryChain $chain,
        TokenFactoryService $service,
    ): void {
        $tokenId = $this->resolveTokenId();
        if ($tokenId === null) {
            Log::error('DeployTokenJob: งานในคิวไม่มีรหัสเหรียญ (payload รุ่นเก่า/เสีย) — ทิ้งงาน ไม่ส่งอะไร');

            return;
        }

        $token = FactoryToken::find($tokenId);

        if (! $token || $token->status !== 'deploying') {
            Log::info('DeployTokenJob: แถวไม่ได้อยู่ในสถานะ deploying — ไม่ทำอะไร', [
                'token_id' => $tokenId,
                'status' => $token?->status,
            ]);

            return;
        }

        // มีธุรกรรมแล้ว = เคยส่งแล้ว ห้ามส่งใหม่เด็ดขาด
        if ($token->tx_hash) {
            $finalizer->scheduleReceiptCheck($token->id, 0);

            return;
        }

        // ตรวจซ้ำตอนจะส่งจริง — นโยบายอาจถูกปิดระหว่างรอคิว และงานรุ่นเก่าอาจพาแถวที่ไม่ได้ตรวจค่าธรรมเนียมมา
        $blocker = $service->deployBlocker($token);
        if ($blocker !== null) {
            Log::warning('DeployTokenJob: ไม่ส่งธุรกรรม — '.$blocker, ['token_id' => $token->id]);
            $finalizer->markFailed($token->id, $blocker, clearTx: false);

            return;
        }

        $deployer = $chain->deployerAddress() ?? 'unconfigured';
        $lock = Cache::lock('token-factory:deploy:'.strtolower($deployer), self::LOCK_SECONDS);

        if (! $lock->get()) {
            // คิวแบบ sync (เครื่องนักพัฒนา) รันงานที่ dispatch ทันที — ต่อคิวใหม่ตรงนี้จะวนเรียกตัวเองไม่จบ
            // รอให้ล็อกว่างแทน ไม่ได้ก็ล้มแบบปลอดภัย (ยังไม่ได้ส่งอะไร แอดมินกด retry ได้)
            if ($this->job instanceof SyncJob) {
                try {
                    $lock->block(Web3DeploymentService::PROCESS_TIMEOUT);
                } catch (LockTimeoutException) {
                    $finalizer->markFailed($token->id, 'มีการสร้างเหรียญอื่นใช้กระเป๋าเดียวกันอยู่ — ลองใหม่อีกครั้ง', clearTx: false);

                    return;
                }
            } else {
                // อีกเหรียญกำลังส่งด้วยคีย์เดียวกันอยู่ — ต่อคิวใหม่ (ไม่ใช่ retry ของงานนี้)
                self::dispatch($token->id)->delay(now()->addSeconds(20));

                return;
            }
        }

        try {
            $claimed = FactoryToken::whereKey($token->id)
                ->where('status', 'deploying')
                ->whereNull('tx_hash')
                ->whereNull('deploy_started_at')
                ->update(['deploy_started_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                Log::warning('DeployTokenJob: มีงานอื่นได้สิทธิ์ส่งธุรกรรมของเหรียญนี้ไปแล้ว — ไม่ส่งซ้ำ', [
                    'token_id' => $token->id,
                ]);

                return;
            }

            $metadata = $token->metadata ?? [];

            Log::info("DeployTokenJob: deploying {$token->symbol}", ['token_id' => $token->id]);

            $result = $deploymentService->deployToken([
                'name' => $token->name,
                'symbol' => $token->symbol,
                'decimals' => $token->decimals,
                'total_supply' => $token->total_supply,
                'creator_address' => $token->creator_address,
                'token_type' => $token->token_type,
                'token_category' => $token->token_category,
                // ตัวเลือกที่ไม่ได้เปิดในนโยบาย ณ ตอนนี้ถูกบังคับปิดอีกชั้นก่อนถึงสคริปต์
                'sub_options' => $policy->sanitizeSubOptions($token->token_type, $metadata['sub_options'] ?? []),
                'logo_url' => $token->logo_url,
            ], function (string $txHash, ?int $nonce, ?string $rawTx) use ($finalizer, $token) {
                $finalizer->recordSigned($token->id, $txHash, $nonce, $rawTx);
            });

            // ผลนี้เป็นของธุรกรรมที่งานนี้เซ็นเท่านั้น (null = ยังไม่ได้เซ็นอะไร)
            $signedTx = is_string($result['signed']['txHash'] ?? null) ? strtolower($result['signed']['txHash']) : null;
            $outcome = $finalizer->apply($token->id, $result, 'deploy', $signedTx);

            if ($outcome === 'pending') {
                $finalizer->scheduleReceiptCheck($token->id);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * งานล้ม (exception / timeout / ถูกหยิบซ้ำเกิน tries).
     *
     * ห้ามเขียนทับแถวที่ deployed แล้ว และห้าม mark failed ถ้ามีธุรกรรมค้างอยู่
     * — อาจกำลังจะขึ้นเชน ส่งให้งานตรวจผลถามต่อแทน
     */
    public function failed(\Throwable $exception): void
    {
        $tokenId = $this->resolveTokenId();
        if ($tokenId === null) {
            Log::error('DeployTokenJob failed: payload ไม่มีรหัสเหรียญ', ['error' => $exception->getMessage()]);

            return;
        }

        $token = FactoryToken::find($tokenId);

        if (! $token || $token->status !== 'deploying') {
            return;
        }

        $finalizer = app(FactoryDeploymentFinalizer::class);

        if ($token->tx_hash) {
            Log::warning('DeployTokenJob failed หลังส่งธุรกรรมแล้ว — ส่งต่อให้งานตรวจผล', [
                'token_id' => $token->id,
                'tx' => $token->tx_hash,
                'error' => $exception->getMessage(),
            ]);
            $finalizer->scheduleReceiptCheck($token->id);

            return;
        }

        $finalizer->markFailed($token->id, $exception->getMessage(), clearTx: false);
    }

    /** รหัสเหรียญจากงานรุ่นใหม่ ($tokenId) หรือรุ่นเก่า ($token เป็นโมเดล) */
    private function resolveTokenId(): ?int
    {
        if ($this->tokenId !== null) {
            return $this->tokenId;
        }

        return $this->token instanceof FactoryToken ? (int) $this->token->getKey() : null;
    }
}
