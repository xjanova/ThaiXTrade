<?php

/**
 * TPIX TRADE - API Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\ChainController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\BannerController as ApiBannerController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SwapApiController;
use App\Http\Controllers\Api\TokenSaleApiController;
use App\Http\Controllers\Api\TradingController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\VerifyWalletOwnership;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// API Version
Route::get('/', function () {
    return response()->json([
        'name' => 'TPIX TRADE API',
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

    // Banners — ป้ายโฆษณา (public, cached)
    Route::get('/banners', [ApiBannerController::class, 'index']);
    Route::post('/banners/{banner}/click', [ApiBannerController::class, 'click']);

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

    // Swap API
    Route::prefix('swap')->group(function () {
        Route::get('quote', [SwapApiController::class, 'quote']);
        Route::get('routes', [SwapApiController::class, 'routes']);
        Route::post('execute', [SwapApiController::class, 'execute']);
    });

    // Token Sale — ระบบขายเหรียญ TPIX (public endpoints)
    Route::prefix('token-sale')->group(function () {
        Route::get('/', [TokenSaleApiController::class, 'index']);
        Route::get('/stats', [TokenSaleApiController::class, 'stats']);
        Route::post('/preview', [TokenSaleApiController::class, 'preview']);
        Route::get('/purchases/{walletAddress}', [TokenSaleApiController::class, 'purchases']);
        Route::get('/vesting/{walletAddress}', [TokenSaleApiController::class, 'vesting']);

        // Stripe Checkout — สร้าง session สำหรับซื้อด้วยบัตรเครดิต/เดบิต
        Route::post('/stripe/checkout', [TokenSaleApiController::class, 'stripeCheckout']);
        Route::get('/stripe/status/{sessionId}', [TokenSaleApiController::class, 'stripeStatus']);
    });

    // Stripe Webhook — รับ event จาก Stripe (ไม่ต้อง auth)
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
        ->withoutMiddleware([VerifyCsrfToken::class]);
});

// Protected Routes (Wallet Ownership Verified)
Route::prefix('v1')->middleware(['throttle:trading', VerifyWalletOwnership::class])->group(function () {
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

    // Token Sale Purchase — ซื้อเหรียญ (ต้อง verify wallet)
    Route::post('/token-sale/purchase', [TokenSaleApiController::class, 'purchase']);

    // AI Assistant (stricter rate limit: 10 requests per minute)
    Route::prefix('ai')->middleware(['throttle:10,1'])->group(function () {
        Route::post('/analyze', [AIController::class, 'analyze']);
        Route::post('/predict', [AIController::class, 'predict']);
        Route::post('/suggest', [AIController::class, 'suggest']);
        Route::get('/insights/{symbol}', [AIController::class, 'insights']);
    });
});

// WebSocket Authentication - use Laravel's built-in broadcasting auth
Broadcast::routes(['middleware' => ['web']]);
