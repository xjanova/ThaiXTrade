<?php

/**
 * ThaiXTrade - API Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\ChainController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\TradingController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// API Version
Route::get('/', function () {
    return response()->json([
        'name' => 'ThaiXTrade API',
        'version' => '1.0.0',
        'status' => 'operational',
        'developer' => 'Xman Studio',
    ]);
});

// Public Routes (No Auth Required)
Route::prefix('v1')->group(function () {
    // Market Data
    Route::prefix('market')->group(function () {
        Route::get('/tickers', [MarketController::class, 'tickers']);
        Route::get('/ticker/{symbol}', [MarketController::class, 'ticker']);
        Route::get('/orderbook/{symbol}', [MarketController::class, 'orderbook']);
        Route::get('/trades/{symbol}', [MarketController::class, 'trades']);
        Route::get('/klines/{symbol}', [MarketController::class, 'klines']);
        Route::get('/pairs', [MarketController::class, 'pairs']);
    });

    // Chain Configuration
    Route::prefix('chains')->group(function () {
        Route::get('/', [ChainController::class, 'index']);
        Route::get('/{chainId}', [ChainController::class, 'show']);
        Route::get('/{chainId}/tokens', [ChainController::class, 'tokens']);
        Route::get('/{chainId}/gas', [ChainController::class, 'gasPrice']);
    });

    // Token Info
    Route::prefix('tokens')->group(function () {
        Route::get('/{address}', [MarketController::class, 'tokenInfo']);
        Route::get('/{address}/price', [MarketController::class, 'tokenPrice']);
    });
});

// Protected Routes (Wallet Signature Required)
Route::prefix('v1')->middleware(['throttle:trading'])->group(function () {
    // Trading Operations
    Route::prefix('trading')->group(function () {
        Route::post('/order', [TradingController::class, 'createOrder']);
        Route::delete('/order/{orderId}', [TradingController::class, 'cancelOrder']);
        Route::get('/orders', [TradingController::class, 'getOrders']);
        Route::get('/order/{orderId}', [TradingController::class, 'getOrder']);
        Route::get('/history', [TradingController::class, 'getHistory']);
    });

    // Wallet Operations
    Route::prefix('wallet')->group(function () {
        Route::post('/connect', [WalletController::class, 'connect']);
        Route::post('/disconnect', [WalletController::class, 'disconnect']);
        Route::get('/balances', [WalletController::class, 'balances']);
        Route::get('/transactions', [WalletController::class, 'transactions']);
        Route::post('/sign', [WalletController::class, 'requestSignature']);
    });

    // Swap Operations
    Route::prefix('swap')->group(function () {
        Route::post('/quote', [TradingController::class, 'getSwapQuote']);
        Route::post('/execute', [TradingController::class, 'executeSwap']);
        Route::get('/routes', [TradingController::class, 'getSwapRoutes']);
    });

    // AI Assistant
    Route::prefix('ai')->group(function () {
        Route::post('/analyze', [AIController::class, 'analyze']);
        Route::post('/predict', [AIController::class, 'predict']);
        Route::post('/suggest', [AIController::class, 'suggest']);
        Route::get('/insights/{symbol}', [AIController::class, 'insights']);
    });
});

// WebSocket Authentication
Route::post('/broadcasting/auth', function (Request $request) {
    return true; // Implement proper auth for private channels
})->middleware('web');
