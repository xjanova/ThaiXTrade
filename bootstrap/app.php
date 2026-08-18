<?php

/**
 * TPIX TRADE - Application Bootstrap
 * Developed by Xman Studio.
 */

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminRole;
use App\Http\Middleware\AuditAdmin;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureKycApproved;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TurnstileVerify;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * อ่าน IP จริงของผู้ใช้จากหลัง Cloudflare
         *
         * ⚠️ ไม่ตั้งตรงนี้ = $request->ip() คืน IP ของ Cloudflare เหมือนกันหมดทุกคน
         *    ผลคือ rate limit ทุกตัวกลายเป็นโควตารวมของทั้งเว็บ ผู้ใช้เจอ 429
         *    ทั้งที่เพิ่งกดครั้งแรก (วัดได้จริง 2026-08-18) และการแบน IP ใช้ไม่ได้เลย
         *
         * รายการพร็อกซีอยู่ใน config/trustedproxy.php พร้อมเหตุผลว่าทำไมไม่ใช้ '*'
         */
        $middleware->trustProxies(
            // ⚠️ require ไฟล์ตรงๆ ห้ามใช้ config()
            //    closure นี้รันตอน resolve HTTP Kernel ซึ่ง "ก่อน" config service ถูกผูก
            //    เรียก config() ตรงนี้ = fatal ทุกคำขอ HTTP (เว็บล่มทั้งเว็บ)
            //    และเทสต์จับไม่ได้ เพราะตอนเทสต์ Laravel โหลด config ก่อน resolve kernel
            at: (require __DIR__.'/../config/trustedproxy.php')['proxies'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->web(prepend: [
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'admin.auth' => AdminAuth::class,
            'admin.role' => AdminRole::class,
            'admin.audit' => AuditAdmin::class,
            'turnstile' => TurnstileVerify::class,
            // ใช้แบบ ->middleware('kyc:ai_bot') — ชื่อฟีเจอร์อยู่ใน config/kyc.php
            'kyc' => EnsureKycApproved::class,
        ]);

        // Rate limiting for trading endpoints
        $middleware->throttleApi('60,1');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // แสดง Error Page แบบ Inertia สำหรับ HTTP errors ทุกประเภท
        $exceptions->respond(function ($response, $exception, Request $request) {
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : ($response->getStatusCode() ?? 500);

            // เฉพาะ error codes ที่มีหน้าเฉพาะ + เป็น web request (ไม่ใช่ API)
            if (in_array($status, [401, 403, 404, 419, 429, 500, 503])
                && ! $request->is('api/*', 'admin/*')
                && ! $request->expectsJson()
            ) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })
    ->create()
    ->usePublicPath(__DIR__.'/../public_html'); // Use public_html instead of public
