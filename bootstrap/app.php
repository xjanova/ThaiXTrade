<?php

/**
 * TPIX TRADE - Application Bootstrap
 * Developed by Xman Studio.
 */

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminRole;
use App\Http\Middleware\AuditAdmin;
use App\Http\Middleware\EnsureEmailIsVerified;
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
