<?php

/**
 * TPIX TRADE - Web Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\TokenSaleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Home
Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');

// Trading
Route::prefix('trade')->group(function () {
    Route::get('/', function () {
        return Inertia::render('Trade', ['pair' => 'BTC-USDT']);
    })->name('trade');

    Route::get('/{pair}', function ($pair) {
        return Inertia::render('Trade', ['pair' => $pair]);
    })->name('trade.pair');
});

// Swap
Route::get('/swap', function () {
    return Inertia::render('Swap');
})->name('swap');

// Markets
Route::prefix('markets')->group(function () {
    Route::get('/', function () {
        return Inertia::render('Markets/Index');
    })->name('markets');

    Route::get('/spot', function () {
        return Inertia::render('Markets/Spot');
    })->name('markets.spot');

    Route::get('/defi', function () {
        return Inertia::render('Markets/DeFi');
    })->name('markets.defi');

    Route::get('/nft', function () {
        return Inertia::render('Markets/NFT');
    })->name('markets.nft');
});

// Portfolio
Route::get('/portfolio', function () {
    return Inertia::render('Portfolio');
})->name('portfolio');

// AI Assistant
Route::get('/ai-assistant', function () {
    return Inertia::render('AIAssistant');
})->name('ai-assistant');

// Settings
Route::get('/settings', function () {
    return Inertia::render('Settings');
})->name('settings');

// Token Sale — หน้าขายเหรียญ TPIX (ICO/IDO)
Route::get('/token-sale', [TokenSaleController::class, 'index'])->name('token-sale');

// Whitepaper — เอกสาร whitepaper แบบ interactive
Route::get('/whitepaper', [TokenSaleController::class, 'whitepaper'])->name('whitepaper');
Route::get('/whitepaper/download', [TokenSaleController::class, 'downloadWhitepaper'])->name('whitepaper.download');

// Explorer — เชื่อมไปยัง Blockscout (TPIX Chain Block Explorer)
Route::get('/explorer', function () {
    return Inertia::render('Explorer');
})->name('explorer');

// Bridge — สะพานเชื่อม TPIX Chain ↔ BSC (Coming Soon)
Route::get('/bridge', function () {
    return Inertia::render('Bridge');
})->name('bridge');

// Staking — ระบบ Staking TPIX (Coming Soon)
Route::get('/staking', function () {
    return Inertia::render('Staking');
})->name('staking');

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'app' => config('app.name'),
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');
