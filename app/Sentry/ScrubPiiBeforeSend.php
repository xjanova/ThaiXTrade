<?php

namespace App\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry before_send hook — strip wallet addresses / mnemonic / private key fragments
 *
 * เก็บเป็น invokable class (ไม่ใช่ closure ใน config/sentry.php) เพื่อให้ `php artisan config:cache`
 * serialize ได้ — ตั้งใน config เป็น string class name
 *
 * Developed by Xman Studio
 */
class ScrubPiiBeforeSend
{
    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $event->setTag('site', 'tpix-trade');
        $event->setTag('chain_id', '4289');

        $message = $event->getMessage();
        if ($message) {
            $cleaned = preg_replace('/0x[a-fA-F0-9]{40,64}/', '0x[REDACTED]', $message);
            $cleaned = preg_replace('/\b([a-z]+ ){11,23}[a-z]+\b/i', '[MNEMONIC_REDACTED]', $cleaned);
            if ($cleaned !== $message) {
                $event->setMessage($cleaned);
            }
        }

        return $event;
    }
}
