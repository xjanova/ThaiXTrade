<?php

/**
 * TPIX TRADE - Service Provider Registration
 *
 * Laravel 11 โหลด provider จากไฟล์นี้เท่านั้น — ถ้าไฟล์นี้หายไป
 * AppServiceProvider จะไม่ถูก register เลย ทำให้ rate limiter 'trading'
 * ไม่ถูกนิยาม และทุก route ในกลุ่ม throttle:trading ตอบ 500
 * (MissingRateLimiterException) รวมถึง HTTPS forcing / LINE login /
 * mail config ก็หายไปด้วย
 *
 * Developed by Xman Studio.
 */

return [
    App\Providers\AppServiceProvider::class,
];
