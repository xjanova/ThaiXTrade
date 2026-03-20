<?php

/**
 * TPIX TRADE - API Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\BannerController as ApiBannerController;
use App\Http\Controllers\Api\BridgeApiController;
use App\Http\Controllers\Api\CarbonCreditApiController;
use App\Http\Controllers\Api\ChainController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\FoodPassportApiController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\StakingApiController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SwapApiController;
use App\Http\Controllers\Api\TokenFactoryApiController;
use App\Http\Controllers\Api\TokenSaleApiController;
use App\Http\Controllers\Api\TradingController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\VerifyWalletOwnership;
use App\Models\SiteSetting;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
    // Site — logo จาก admin settings (ใช้ใน Explorer + ที่อื่น)
    Route::get('/site/logo', function () {
        $logo = SiteSetting::get('general', 'logo');
        if ($logo && Storage::disk('public')->exists($logo)) {
            return response()->file(storage_path('app/public/'.$logo));
        }

        // Fallback: ใช้ logo.png ที่อยู่ใน public_html
        $fallback = public_path('logo.png');
        if (file_exists($fallback)) {
            return response()->file($fallback);
        }

        abort(404);
    });

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

    // Token Factory — ระบบสร้างเหรียญ (public endpoints)
    Route::prefix('token-factory')->group(function () {
        Route::get('/', [TokenFactoryApiController::class, 'index']);
        Route::get('/{id}', [TokenFactoryApiController::class, 'show']);
    });

    // Carbon Credits — ระบบ Carbon Credit (public endpoints)
    Route::prefix('carbon-credits')->group(function () {
        Route::get('/projects', [CarbonCreditApiController::class, 'projects']);
        Route::get('/projects/{slug}', [CarbonCreditApiController::class, 'project']);
        Route::get('/stats', [CarbonCreditApiController::class, 'stats']);
    });

    // Bridge — cross-chain TPIX Chain ↔ BSC (read-only public)
    Route::prefix('bridge')->group(function () {
        Route::get('/info', [BridgeApiController::class, 'info']);
        Route::get('/history/{wallet}', [BridgeApiController::class, 'history']);
        Route::get('/status/{id}', [BridgeApiController::class, 'status']);
    });

    // Staking — read-only public endpoints
    Route::prefix('staking')->group(function () {
        Route::get('/pools', [StakingApiController::class, 'pools']);
        Route::get('/stats', [StakingApiController::class, 'stats']);
        Route::get('/positions/{wallet}', [StakingApiController::class, 'positions']);
    });

    // Articles / Blog — บทความ (public)
    Route::prefix('articles')->group(function () {
        Route::get('/', [ArticleController::class, 'index']);
        Route::get('/{slug}', [ArticleController::class, 'show']);
    });

    // FoodPassport — ระบบตรวจสอบที่มาอาหาร (public endpoints)
    Route::prefix('food-passport')->group(function () {
        Route::get('/products', [FoodPassportApiController::class, 'products']);
        Route::get('/verify/{productId}', [FoodPassportApiController::class, 'verify']);
        Route::get('/stats', [FoodPassportApiController::class, 'stats']);
        Route::get('/certificates', [FoodPassportApiController::class, 'certificates']);
        Route::get('/sensor-data/{productId}', [FoodPassportApiController::class, 'sensorData']);
        Route::get('/fdp-token', [FoodPassportApiController::class, 'fdpTokenInfo']);

        // IoT — Ingestion + test + config
        Route::post('/iot/ingest', [FoodPassportApiController::class, 'iotIngest'])
            ->middleware('throttle:120,1');
        Route::post('/iot/batch-ingest', [FoodPassportApiController::class, 'iotBatchIngest'])
            ->middleware('throttle:30,1');
        Route::get('/iot/test/{deviceId}', [FoodPassportApiController::class, 'testDevice']);
        Route::get('/iot/config/{deviceId}', [FoodPassportApiController::class, 'deviceConfig']);
    });

    // AI Chatbot — ถามตอบอัจฉริยะ (rate limited)
    Route::post('/chatbot', [ChatbotController::class, 'chat'])
        ->middleware('throttle:30,1');

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

    // Token Factory — สร้างเหรียญ (ต้อง verify wallet)
    Route::prefix('token-factory')->group(function () {
        Route::get('/my-tokens', [TokenFactoryApiController::class, 'myTokens']);
        Route::post('/create', [TokenFactoryApiController::class, 'store']);
    });

    // FoodPassport — จัดการสินค้า/IoT (ต้อง verify wallet)
    Route::prefix('food-passport')->group(function () {
        Route::post('/register', [FoodPassportApiController::class, 'register']);
        Route::post('/trace/{productId}', [FoodPassportApiController::class, 'addTrace']);
        Route::post('/mint/{productId}', [FoodPassportApiController::class, 'mint']);
        Route::get('/my-products', [FoodPassportApiController::class, 'myProducts']);
        Route::post('/iot/register-device', [FoodPassportApiController::class, 'registerDevice']);
        Route::get('/iot/my-devices', [FoodPassportApiController::class, 'myDevices']);
    });

    // Carbon Credits — ซื้อ/retire (ต้อง verify wallet)
    Route::prefix('carbon-credits')->group(function () {
        Route::post('/purchase', [CarbonCreditApiController::class, 'purchase']);
        Route::post('/retire', [CarbonCreditApiController::class, 'retire']);
        Route::get('/my-credits/{walletAddress}', [CarbonCreditApiController::class, 'myCredits']);
        Route::get('/my-retirements/{walletAddress}', [CarbonCreditApiController::class, 'myRetirements']);
    });

    // Bridge — write operations (ต้อง verify wallet)
    Route::post('/bridge/initiate', [BridgeApiController::class, 'initiate']);

    // Staking — write operations (ต้อง verify wallet)
    Route::prefix('staking')->group(function () {
        Route::post('/stake', [StakingApiController::class, 'stake']);
        Route::post('/claim/{id}', [StakingApiController::class, 'claim']);
        Route::post('/unstake/{id}', [StakingApiController::class, 'unstake']);
    });

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
