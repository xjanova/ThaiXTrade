<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes — TPIX TRADE Scheduler
|--------------------------------------------------------------------------
*/

// Auto-generate AI content ทุก 30 นาที (command จะตรวจ time slot เอง)
Schedule::command('content:generate-scheduled')->everyThirtyMinutes();

// Bridge: ตรวจ pending/processing tx ที่ค้าง > 2 นาที → re-dispatch job
Schedule::call(function () {
    $stuck = \App\Models\BridgeTransaction::whereIn('status', ['processing', 'pending'])
        ->whereNotNull('source_tx_hash')
        ->where('updated_at', '<', now()->subMinutes(2))
        ->where('retry_count', '<', 5)
        ->get();

    foreach ($stuck as $tx) {
        \App\Jobs\ProcessBridgeJob::dispatch($tx);
        \Illuminate\Support\Facades\Log::info('Bridge: re-dispatched stuck tx', ['id' => $tx->id]);
    }
})->everyMinute()->name('bridge:process-stuck');

// Masternode allowlist: ลบ entries ที่หมดอายุ + cleanup CF rules ทุก 5 นาที
Schedule::command('masternode:cleanup')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('masternode:cleanup');

// ชั้นคลัง: เก็บรายการเคลื่อนไหวของกระเป๋าคลังจากเชนเข้าสมุดบัญชี ทุก 5 นาที
//
// ต้องมีตัวนี้ ไม่งั้นพอมีใครโอนเงินออกจากคลังผ่าน Masternode UI (ทางเดียวที่โอนได้
// เพราะระบบเว็บไม่มีคีย์ของคลัง) สมุดจะไม่รู้เรื่อง แล้วตัวกระทบยอดจะฟ้องว่า
// ไม่ตรงทั้งที่เป็นการโอนที่ถูกต้อง — เคสแรกที่จะเจอคือตอนเติมเงินกระเป๋าร้อน
Schedule::command('tpix:treasury-sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('treasury:sync');
