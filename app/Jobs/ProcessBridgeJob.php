<?php

namespace App\Jobs;

use App\Models\BridgeTransaction;
use App\Services\BridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Bridge Transaction Job
 * ดำเนินการ bridge: verify source tx → execute target transfer → complete.
 *
 * Pattern จาก DeployTokenJob:
 * - 5 retries, 30s backoff, 120s timeout
 * - failed() handler marks transaction as failed
 *
 * Developed by Xman Studio
 */
class ProcessBridgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * หนึ่งธุรกรรม = หนึ่งงานในคิว ห้ามซ้ำ.
     *
     * 2026-09-07 ตอนคิวยังเป็น sync งานรันคาอยู่ในรีเควสต์ ซ้ำกันไม่ได้อยู่แล้ว
     * แต่พอเข้าคิวจริงจะมีสองทางที่ปล่อยงานตัวเดียวกันพร้อมกันได้
     *   1. ผู้ใช้ยิง /bridge ที่มี source_tx_hash เข้ามา
     *   2. bridge:process-stuck ใน routes/console.php เก็บ tx ที่ค้างเกิน 2 นาทีมาปล่อยซ้ำทุกนาที
     * ด่าน in_array($tx->status, ['completed','failed']) ใน handle() กันได้แค่ของที่จบแล้ว
     * ธุรกรรมที่ยัง processing อยู่จะโดนสองงานจับพร้อมกัน = โอนซ้ำ = เงินหาย
     *
     * ล็อกตัวนี้อยู่บน cache store ซึ่งเพิ่งย้ายจากไฟล์มาเป็น Redis เมื่อ 2026-09-07
     * ตอนเป็นไฟล์ล็อกไม่ atomic จริง จึงกันไม่ได้แม้จะใส่ไว้
     */
    public function uniqueId(): string
    {
        return (string) $this->bridgeTransaction->id;
    }

    /**
     * เพดานอายุล็อก (วินาที) กันค้างถ้า worker ตายกลางคัน.
     *
     * อายุจริงที่งานหนึ่งใช้ได้สูงสุด = tries 5 × (timeout 120 + backoff 30) = 750 วินาที
     * ตั้ง 900 ให้เผื่อ ไม่สั้นกว่าอายุงานจริง (ล็อกหลุดก่อน = เปิดช่องให้ซ้ำ)
     */
    public int $uniqueFor = 900;

    /**
     * จำนวนครั้งที่ retry (tx อาจยังไม่ confirmed → ต้องรอ).
     */
    public int $tries = 5;

    /**
     * เวลา backoff ระหว่าง retry (วินาที)
     * TPIX Chain = 2s blocks, BSC = 3s blocks → 30s น่าจะ confirm แล้ว.
     */
    public int $backoff = 30;

    /**
     * Timeout ต่อ attempt (วินาที)
     * Node.js script อาจใช้เวลา sign + wait confirmation.
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
