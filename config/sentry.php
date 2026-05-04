<?php

/**
 * TPIX Trade — Sentry config (Laravel)
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

    // release used to tag events — falls back to git short sha at build time
    'release' => env('SENTRY_RELEASE') ?: trim(@shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: ''),

    'environment' => env('APP_ENV', 'production'),

    // Sample 100% of errors but only 10% of perf transactions
    'sample_rate'             => (float) env('SENTRY_SAMPLE_RATE', 1.0),
    'traces_sample_rate'      => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.10),
    'profiles_sample_rate'    => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    // Performance — capture HTTP, DB, Redis, queue, console commands
    'breadcrumbs' => [
        'logs'         => true,
        'sql_queries'  => true,
        'sql_bindings' => false, // ⚠️ never log SQL bindings — may contain PII/secrets
        'queue_info'   => true,
        'command_info' => true,
        'http_client_requests' => true,
    ],

    // Don't send these errors (noise filter)
    'ignore_exceptions' => [
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,        // 404
        \Illuminate\Auth\AuthenticationException::class,                              // expected login redirect
        \Illuminate\Validation\ValidationException::class,                            // 422 form errors
        \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,    // 403
        \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException::class, // 429 rate limit
    ],

    // Strip PII from events sent to Sentry
    'send_default_pii' => false,

    // Capture deprecations and silenced errors
    'integrations' => fn() => [],

    // Tags every event gets
    'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
        $event->setTag('site', 'tpix-trade');
        $event->setTag('chain_id', '4289');

        // Strip wallet addresses, mnemonic, private key fragments from messages
        if ($message = $event->getMessage()) {
            $cleaned = preg_replace('/0x[a-fA-F0-9]{40,64}/', '0x[REDACTED]', $message);
            $cleaned = preg_replace('/\b([a-z]+ ){11,23}[a-z]+\b/i', '[MNEMONIC_REDACTED]', $cleaned);
            if ($cleaned !== $message) {
                $event->setMessage($cleaned);
            }
        }

        return $event;
    },
];
