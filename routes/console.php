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
