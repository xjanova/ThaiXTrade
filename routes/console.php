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
 * รอบขายเหรียญ — เลื่อนสถานะเฟสตามเวลาจริง ทุกชั่วโมง
 *
 * ★ ก่อนหน้านี้ไม่มีอะไรทำหน้าที่นี้เลย ผลคือเฟส "Private Sale" ค้างสถานะ active
 *   ไว้ 3 เดือนหลังเลยวันปิด (25 พ.ค. → 21 ส.ค.) หน้าเว็บยังโชว์ปุ่มซื้อ
 *   แต่หลังบ้านปฏิเสธทุกคำสั่ง — ผู้ใช้โอนเงินบน BSC ไปแล้วถึงจะรู้
 *
 * รายชั่วโมงพอ ไม่ต้องรายนาที เพราะขอบเขตของเฟสเป็นระดับวัน
 * และด่านจริงคือ assertPhaseOpen() ที่เช็ควันสดทุกครั้งอยู่แล้ว
 * ตัวนี้มีไว้ให้ "ป้ายสถานะตรงกับความจริง" เท่านั้น
 */
Schedule::command('sale:advance-phases')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('sale:advance-phases');

/*
 * รอบขายเหรียญ — เปิดขายเองทันทีที่ระบบพร้อมจริง ทุกชั่วโมง
 *
 * เจ้าของกำหนด: "พร้อมจำหน่ายเมื่อไหร่ ก็เริ่มเฟสการขายใหม่แต่แรกวันนั้น"
 * ตัวนี้จึงไม่ได้ดูปฏิทิน แต่ดูความพร้อมจริง (ช่องทางรับเงิน · กระเป๋าจ่ายเหรียญ ·
 * ยอด TPIX บนเชน · สวิตช์จ่ายเหรียญ) แล้วปักหมุดวันเปิดให้เองในชั่วโมงที่ครบ
 *
 * ด่านสามชั้นก่อนแตะอะไร — ต้องติดอาวุธไว้ที่ /admin/token-sales ก่อน
 * ต้องยังไม่เคยเปิด และต้องผ่านความพร้อมครบทุกข้อ (--force ใช้กับ --auto ไม่ได้)
 * ไม่ครบข้อไหนก็แค่เงียบแล้วรอรอบหน้า ไม่ถือเป็นความผิดพลาด
 */
Schedule::command('sale:launch', ['--auto'])
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('sale:launch-auto');

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
 * AI TRADE — รอบวิเคราะห์ตลาดด้วย AI (สองจังหวะ)
 *
 * เจ้าของกำหนด 28 ส.ค. 2026: แพลนต่ำได้รอบใหญ่ 4 ชั่วโมงอย่างเดียว
 * แพลนสูงได้สองจังหวะ คือรอบสั้น 15 นาทีเพิ่มเข้ามาด้วย
 *
 * ⚠️ ทำไมไม่ยิง AI ต่อไม้: 7 บอท × 1,440 นาที = 10,080 ครั้ง/วัน ซึ่งนอกจาก
 *    แพงแล้วยังให้ผลแย่กว่า — บริบทรอบเดียวกันถูกถามซ้ำหลายพันครั้งด้วยคำตอบ
 *    ที่ไม่คงที่ บอทสองตัวบนเหรียญเดียวกันจะได้คำตอบคนละทางในนาทีเดียวกัน
 *    สรุปเป็นรอบแล้วให้ทุกบอทอ่านใบเดียวกันจึงทั้งถูกกว่าและสอดคล้องกว่า
 *
 * รอบสั้นตั้งเวลาเหลื่อมจาก sync-news 2 นาที เพื่อให้ข่าวรอบล่าสุดลงฐานข้อมูล
 * เสร็จก่อน — ยิงพร้อมกันแล้ว AI จะวิเคราะห์จากข่าวของรอบที่แล้วเสมอ
 */
Schedule::command('aibot:analyze', ['--scope' => 'strategic'])
    ->cron('5 */4 * * *')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('aibot:analyze:strategic');

Schedule::command('aibot:analyze', ['--scope' => 'tactical'])
    ->cron('2,17,32,47 * * * *')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('aibot:analyze:tactical');

/*
 * AI TRADE — เรียกทุกนาที แล้วให้แต่ละบอทตัดสินเองว่าถึงรอบหรือยัง
 *
 * ⚠️ ทุกนาทีไม่ได้แปลว่าบอททุกตัวเดินทุกนาที — `AiBotTick::isDue()` กรองตามระดับ
 *    แพลน (config `aibot.tick_interval_minutes`) เพื่อให้ "รอบประมวลผลถี่ขึ้น"
 *    ที่หน้าเช่าโฆษณาไว้เป็นเรื่องจริง แทนที่จะเท่ากันหมดทุกแพลน
 *
 * ที่ไม่ตั้ง schedule แยกต่อระดับ เพราะสองตารางจะซ้อนกันในนาทีที่หารลงตัว
 * แล้วบอทเดิมเดินสองรอบติดจนเปิดไม้ซ้ำ
 *
 * withoutOverlapping ยังจำเป็น — รอบที่ยังไม่จบต้องไม่ถูกซ้อนโดยรอบถัดไป
 */
/*
 * แยกวอร์กเกอร์ต่อกลยุทธ์ — กลยุทธ์ละหนึ่งคำสั่ง มีล็อกของตัวเอง
 *
 * ทำไมไม่ใช้คำสั่งเดียวรันทุกกลยุทธ์: `withoutOverlapping` เป็นล็อกก้อนเดียว
 * กลยุทธ์ที่ค้าง (ตลาดตอบช้า/คู่เทรดมีปัญหา) จะกันกลยุทธ์อื่นไม่ให้เดินไปด้วย
 * ทั้งรอบ — บอทของลูกค้าที่ไม่เกี่ยวข้องกันเลยหยุดพร้อมกันหมด
 *
 * ชื่อล็อกต้องไม่ซ้ำกัน (ต่อท้ายด้วยรหัสกลยุทธ์) ไม่งั้นกลับไปใช้ล็อกร่วมเหมือนเดิม
 *
 * อ่านรายชื่อจาก config เพื่อให้เพิ่มกลยุทธ์ใหม่แล้วมีวอร์กเกอร์เองอัตโนมัติ
 * ไม่ต้องมาแก้ไฟล์นี้ซ้ำ (จุดที่ลืมกันบ่อยที่สุดเวลาเพิ่มของใหม่)
 */
foreach (config('aibot.strategies', []) as $strategy) {
    $code = $strategy['code'] ?? null;

    if (! $code) {
        continue;
    }

    Schedule::command('aibot:tick', ['--strategy' => $code])
        ->everyMinute()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->name("aibot:tick:{$code}");
}

/*
 * คืนค่าบริการของใบอนุญาตวางไม้ที่หมดอายุ
 *
 * ⚠️ ตั๋วค้างสถานะ issued = เงินที่หักจากคลังผู้ใช้ไปแล้วแต่ไม่มีใครได้อะไร
 *    ผู้ใช้ที่ขอตั๋วแล้วปิดแท็บทิ้งจะเสีย TPIX ฟรีถ้าตัวนี้ไม่เดิน
 *    และไม่มีทางรู้ตัว เพราะยอดหายทีละนิดโดยไม่มีอะไรแจ้ง
 *
 * ทุกนาที เพราะตั๋วอายุสั้น (ปริยาย 15 นาที) และการคืนช้าคือเงินของคนอื่นค้างอยู่กับเรา
 */
Schedule::command('trading:expire-tickets')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('trading:expire-tickets');

/*
 * ล้างเอกสารยืนยันตัวตนที่ครบกำหนดเก็บแล้ว — ทุกวันตี 3
 *
 * PDPA ให้เก็บข้อมูลส่วนบุคคลเท่าที่จำเป็นตามวัตถุประสงค์ ไม่ใช่ตลอดกาล
 * ไม่มีตัวนี้ = รูปบัตรประชาชนทุกใบที่เคยส่งเข้ามาอยู่บนดิสก์เราไปเรื่อยๆ
 * ผิดกฎหมายข้อหนึ่ง และเป็นกองข้อมูลที่รอวันรั่วอีกข้อหนึ่ง
 *
 * ตี 3 เพราะการลบไฟล์จำนวนมากกิน I/O และไม่มีใครรอผลอยู่
 */
Schedule::command('kyc:purge')
    ->dailyAt('03:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('kyc:purge');
