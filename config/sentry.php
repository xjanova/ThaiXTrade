<?php

use App\Sentry\ScrubPiiBeforeSend;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/*
 * TPIX Trade — Sentry config (Laravel).
 *
 * To activate:
 *   1. composer require sentry/sentry-laravel
 *   2. add to .env:  SENTRY_LARAVEL_DSN=https://...@sentry.io/...
 *   3. (optional) php artisan sentry:test
 *
 * Already wired into bootstrap/app.php exception handler.
 */

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // release used to tag events — อ่านจากไฟล์ VERSION ก่อน แล้วค่อย fallback ไป git sha
    //
    // ห้ามเรียก shell_exec() ตรงๆ ตรงนี้: php-fpm ปิด shell_exec/proc_open ไว้ใน
    // disable_functions (ต่างจาก CLI ที่เปิด) ไฟล์ config ถูก require ตอน bootstrap
    // ทุก request ที่ config cache ยังไม่ถูกสร้าง ถ้าเรียกฟังก์ชันที่ถูกปิดจะเป็น
    // fatal Error ตั้งแต่ LoadConfiguration ทำให้ทั้งเว็บ 500 ไม่ใช่แค่ Sentry เสีย
    'release' => env('SENTRY_RELEASE')
        ?: (is_readable(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : '')
        ?: (function_exists('shell_exec') ? trim((string) @shell_exec('git rev-parse --short HEAD 2>/dev/null')) : ''),

    'environment' => env('APP_ENV', 'production'),

    // Sample 100% of errors but only 10% of perf transactions
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.10),
    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    // Performance — capture HTTP, DB, Redis, queue, console commands
    'breadcrumbs' => [
        'logs' => true,
        'sql_queries' => true,
        'sql_bindings' => false, // ⚠️ never log SQL bindings — may contain PII/secrets
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
    ],

    // Don't send these errors (noise filter)
    'ignore_exceptions' => [
        NotFoundHttpException::class,        // 404
        AuthenticationException::class,                              // expected login redirect
        ValidationException::class,                            // 422 form errors
        AccessDeniedHttpException::class,    // 403
        TooManyRequestsHttpException::class, // 429 rate limit
    ],

    // Strip PII from events sent to Sentry
    'send_default_pii' => false,

    // Capture deprecations and silenced errors — empty array is config:cache-safe
    'integrations' => [],

    // Tags every event + scrub PII — invokable class (not closure) so config:cache works
    'before_send' => ScrubPiiBeforeSend::class,
];
