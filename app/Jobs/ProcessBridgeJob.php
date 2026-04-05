<?php

namespace App\Jobs;

use App\Models\BridgeTransaction;
use App\Services\BridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Bridge Transaction Job
 * ดำเนินการ bridge: verify source tx → execute target transfer → complete
 *
 * Pattern จาก DeployTokenJob:
 * - 5 retries, 30s backoff, 120s timeout
 * - failed() handler marks transaction as failed
 *
 * Developed by Xman Studio
 */
class ProcessBridgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * จำนวนครั้งที่ retry (tx อาจยังไม่ confirmed → ต้องรอ)
     */
    public int $tries = 5;

    /**
     * เวลา backoff ระหว่าง retry (วินาที)
     * TPIX Chain = 2s blocks, BSC = 3s blocks → 30s น่าจะ confirm แล้ว
     */
    public int $backoff = 30;

    /**
     * Timeout ต่อ attempt (วินาที)
     * Node.js script อาจใช้เวลา sign + wait confirmation
     */
    public int $timeout = 120;

    public function __construct(
        private readonly BridgeTransaction $bridgeTransaction,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BridgeService $bridgeService): void
    {
        $tx = $this->bridgeTransaction->fresh();

        if (! $tx) {
            Log::warning('ProcessBridgeJob: transaction not found', ['id' => $this->bridgeTransaction->id]);

            return;
        }

        // Skip ถ้าเสร็จแล้วหรือ fail ถาวร
        if (in_array($tx->status, ['completed', 'failed'])) {
            return;
        }

        $bridgeService->processBridgeTransaction($tx);
    }

    /**
     * Called after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        $tx = $this->bridgeTransaction->fresh();
        if ($tx && $tx->status !== 'completed') {
            $tx->update([
                'status' => 'failed',
                'error_message' => 'All retries exhausted: '.$exception->getMessage(),
            ]);
        }

        Log::error('ProcessBridgeJob failed permanently', [
            'bridge_id' => $this->bridgeTransaction->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
