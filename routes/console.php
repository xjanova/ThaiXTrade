<?php

use App\Jobs\ProcessBridgeJob;
use App\Models\BridgeTransaction;
use Illuminate\Support\Facades\Log;
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
    $stuck = BridgeTransaction::whereIn('status', ['processing', 'pending'])
        ->whereNotNull('source_tx_hash')
        ->where('updated_at', '<', now()->subMinutes(2))
        ->where('retry_count', '<', 5)
        ->get();

    foreach ($stuck as $tx) {
        ProcessBridgeJob::dispatch($tx);
        Log::info('Bridge: re-dispatched stuck tx', ['id' => $tx->id]);
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

// คาดแดง: ตรวจ heartbeat ของเครื่องโครงสร้างพื้นฐาน (เซิร์ฟเวอร์เชน) ทุกนาที
//
// นี่คือชั้นเดียวที่จับ "ทั้งเครื่องเชนดับ" ได้ — watchdog ฝั่งนั้นแจ้งเหตุเอง
// ได้ทุกกรณียกเว้นกรณีที่ตัวมันดับไปด้วย เครื่องเว็บจึงต้องเป็นคนจับความเงียบ
Schedule::command('infra:check-heartbeats')
    ->everyMinute()
    ->onOneServer()
    ->name('infra:check-heartbeats');

// ชั้นคลัง: เซ็น ส่ง และตามผลรายการจ่ายเงินจากกระเป๋าร้อน ทุกนาที
//
// ต้องอยู่ฝั่ง CLI เพราะการเซ็นเรียก ethers ผ่าน Node ซึ่ง php-fpm ทำไม่ได้
// (ปิด proc_open ไว้) หน้าเว็บทำได้แค่เปลี่ยนสถานะเป็น broadcasting
//
// withoutOverlapping สำคัญมาก — ถ้าสองรอบทำงานทับกันจะแย่ง nonce กัน
Schedule::command('tpix:treasury-payouts')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('treasury:payouts');

/*
 * AI TRADE — ดึงข่าวตลาดทุก 15 นาที
 *
 * ความถี่นี้ตั้งใจ: ข่าวแพนิค (แฮก ล้มละลาย ถอนเงินไม่ได้) ราคาไหลภายในไม่กี่สิบนาที
 * ดึงถี่กว่านี้เปลืองโดยไม่ได้อะไรเพิ่ม ห่างกว่านี้บอทจะรู้ตัวช้าเกินไป
 */
Schedule::command('aibot:sync-news')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('aibot:sync-news');

/*
 * AI TRADE — ให้บอททุกตัวคิดหนึ่งรอบทุก 5 นาที
 *
 * ถี่พอสำหรับ timeframe ต่ำสุดที่เปิดให้ใช้ (1m ยังพอไหว เพราะกลยุทธ์รอบสั้น
 * ตัดสินจากหลายแท่ง ไม่ใช่แท่งเดียว) และไม่ถี่จนยิง Binance เกินโควตา
 */
Schedule::command('aibot:tick')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('aibot:tick');
