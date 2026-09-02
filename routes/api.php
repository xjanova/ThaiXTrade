<?php

/*
 * TPIX TRADE - API Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\Api\AiBotController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AppUpdateController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\BannerController as ApiBannerController;
use App\Http\Controllers\Api\BridgeApiController;
use App\Http\Controllers\Api\CarbonCreditApiController;
use App\Http\Controllers\Api\ChainController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\CmcController;
use App\Http\Controllers\Api\ContractRegistryController;
use App\Http\Controllers\Api\FeeConfigController;
use App\Http\Controllers\Api\FoodPassportApiController;
use App\Http\Controllers\Api\InfraAlertController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\NodeHeartbeatController;
use App\Http\Controllers\Api\StakingApiController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\SwapApiController;
use App\Http\Controllers\Api\TokenFactoryApiController;
use App\Http\Controllers\Api\TokenSaleApiController;
use App\Http\Controllers\Api\TpixPriceController;
use App\Http\Controllers\Api\TradingController;
use App\Http\Controllers\Api\TradingFeeController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\MasterNodeController;
use App\Http\Controllers\ValidatorController;
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

/*
 * TPIX Token Icon — public endpoint for MetaMask, wallets, exchanges.
 * Returns the official TPIX token logo for adding custom tokens.
 * URL: https://tpix.online/api/v1/token-icon
 * Use in MetaMask: "Add Token" → paste contract + this URL as icon
 */
Route::get('v1/token-icon', function () {
    $path = public_path('tpixlogo.webp');
    if (file_exists($path)) {
        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    return response()->json(['error' => 'Token icon not found'], 404);
});

// Also serve as PNG for wallets that don't support WebP
Route::get('v1/token-icon.png', function () {
    $path = public_path('tpixlogo.webp');
    if (file_exists($path)) {
        // Browsers/wallets will accept webp even with .png extension
        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    return response()->json(['error' => 'Token icon not found'], 404);
});

/*
 * Masternode / Validator Auto-Allowlist
 * - POST /api/v1/node/heartbeat — operator ส่ง heartbeat (signed) → server เพิ่ม IP เข้า CF allowlist
 * - GET  /api/v1/node/status/{wallet} — เช็คสถานะ allowlist ของ wallet
 *
 * Throttle: 10 req/min per IP (heartbeat) — เผื่อ retry แต่ไม่ flood
 * เก็บไว้นอก v1 group เพราะ throttle/middleware เฉพาะ
 */
Route::prefix('v1/node')->middleware(['throttle:'.config('masternode.heartbeat.rate_limit_per_minute', 10).',1'])->group(function () {
    Route::post('heartbeat', [NodeHeartbeatController::class, 'heartbeat'])->name('node.heartbeat');
    Route::get('status/{wallet}', [NodeHeartbeatController::class, 'status'])->name('node.status');
});

/*
 * Infra Alerts — คาดแดงหลังบ้าน (คนละระบบกับ v1/node ข้างบนซึ่งเป็นของ masternode ผู้ใช้)
 * - POST /api/infra/heartbeat — watchdog เซิร์ฟเวอร์เชนยิงทุก 1 นาทีตอน check ผ่านครบ
 * - POST /api/infra/alert     — watchdog ยกเหตุ (chain_stalled / chain_down / ...)
 *
 * Auth: Bearer token = TPIX_INFRA_ALERT_TOKEN (.env) เทียบ hash_equals ใน controller
 * Throttle: 30 req/min per IP — heartbeat ปกติ 1/นาที/เครื่อง เผื่อไว้หลายเครื่อง+retry
 */
Route::prefix('infra')->middleware(['throttle:30,1'])->group(function () {
    Route::post('heartbeat', [InfraAlertController::class, 'heartbeat'])->name('infra.heartbeat');
    Route::post('alert', [InfraAlertController::class, 'alert'])->name('infra.alert');

    /*
     * ที่อยู่สัญญา — สคริปต์ deploy ยิงมาลงทะเบียนเองหลัง deploy เสร็จ
     * เพื่อให้เหลือขั้นตอนเดียวคือ "deploy" ไม่ต้อง ssh ไปแก้ .env แล้ว config:cache อีก
     *
     * Auth: Bearer token = CONTRACT_REGISTRY_TOKEN (.env) เทียบ hash_equals ใน controller
     * ที่อยู่ทุกตัวถูกตรวจ eth_getCode กับเชนจริงก่อนรับ
     */
    Route::get('contracts', [ContractRegistryController::class, 'show'])->name('infra.contracts.show');
    Route::post('contracts', [ContractRegistryController::class, 'store'])->name('infra.contracts.store');
});

// Public Routes (No Auth Required) — rate limited
Route::prefix('v1')->middleware(['throttle:60,1'])->group(function () {
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
        // เส้นกราฟย่อ (sparkline) หลายคู่ในคำขอเดียว — ใช้ในรายการคู่เทรด
        Route::get('/sparklines', [MarketController::class, 'sparklines']);
        Route::get('/pairs', [MarketController::class, 'pairs']);
    });

    // TPIX Token — price feed, order book, trades, klines, info
    Route::prefix('tpix')->group(function () {
        Route::get('/price', [TpixPriceController::class, 'price']);
        Route::get('/ticker', [TpixPriceController::class, 'ticker']);
        Route::get('/summary', [TpixPriceController::class, 'summary']);
        Route::get('/klines', [TpixPriceController::class, 'klines']);
        Route::get('/orderbook', [TpixPriceController::class, 'orderbook']);
        Route::get('/trades', [TpixPriceController::class, 'trades']);
        Route::get('/info', [TpixPriceController::class, 'info']);
    });

    /*
     * TPIX Supply — CoinGecko plain-text spec + JSON snapshot.
     * Verifiable on-chain via RPC: circulating = total - sum(balance of locked genesis addresses).
     *   GET /api/v1/supply                      → full JSON breakdown
     *   GET /api/v1/supply/total_supply         → "7000000000" (text/plain)
     *   GET /api/v1/supply/circulating_supply   → "<computed>" (text/plain)
     *   GET /api/v1/supply/max_supply           → "7000000000" (text/plain)
     */
    Route::prefix('supply')->group(function () {
        Route::get('/', [SupplyController::class, 'index']);
        Route::get('/total_supply', [SupplyController::class, 'total']);
        Route::get('/circulating_supply', [SupplyController::class, 'circulating']);
        Route::get('/max_supply', [SupplyController::class, 'max']);
    });

    /*
     * CoinMarketCap / CoinGecko DEX API specification.
     * Ref: https://github.com/CoinMarketCap/dex-api-specification
     *   GET /api/v1/cmc/summary              → all pairs condensed
     *   GET /api/v1/cmc/assets               → traded asset metadata
     *   GET /api/v1/cmc/tickers              → tickers per pair
     *   GET /api/v1/cmc/orderbook/{market}   → order book for BASE_QUOTE
     */
    Route::prefix('cmc')->group(function () {
        Route::get('/summary', [CmcController::class, 'summary']);
        Route::get('/assets', [CmcController::class, 'assets']);
        Route::get('/tickers', [CmcController::class, 'tickers']);
        Route::get('/orderbook/{market}', [CmcController::class, 'orderbook']);
    });

    // Banners — ป้ายโฆษณา (public, cached)
    Route::get('/banners', [ApiBannerController::class, 'index']);
    Route::post('/banners/{banner}/click', [ApiBannerController::class, 'click']);

    // Unified Fee Configuration — swap + bridge fees for wallet apps
    Route::get('/fees', [FeeConfigController::class, 'index']);

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

    // Swap API (read-only public; execute requires wallet verification)
    Route::prefix('swap')->group(function () {
        Route::get('quote', [SwapApiController::class, 'quote']);
        Route::get('routes', [SwapApiController::class, 'routes']);
        // POST execute moved to protected routes — requires wallet verification
    });

    // Token Sale — ระบบขายเหรียญ TPIX (public endpoints)
    Route::prefix('token-sale')->group(function () {
        Route::get('/', [TokenSaleApiController::class, 'index']);
        Route::get('/stats', [TokenSaleApiController::class, 'stats']);
        Route::post('/preview', [TokenSaleApiController::class, 'preview']);

        /*
         * ประวัติการซื้อและตาราง vesting ของกระเป๋าหนึ่งๆ ต้องพิสูจน์ตัวตนก่อน
         *
         * เดิมเป็น public — ใครก็เปิดดูได้ว่ากระเป๋าไหนซื้อไปเท่าไร ทำให้เล็ง
         * ผู้ถือรายใหญ่ไปหลอกลวงต่อได้แม่นยำ และ tx_hash ที่หลุดออกไปยังเป็น
         * วัตถุดิบให้การโจมตีอื่นด้วย
         */
        Route::middleware(VerifyWalletOwnership::class)->group(function () {
            Route::get('/purchases/{walletAddress}', [TokenSaleApiController::class, 'purchases']);
            Route::get('/vesting/{walletAddress}', [TokenSaleApiController::class, 'vesting']);
        });

        // Stripe Checkout — สร้าง session สำหรับซื้อด้วยบัตรเครดิต/เดบิต
        Route::get('/stripe/status/{sessionId}', [TokenSaleApiController::class, 'stripeStatus']);
    });

    // Token Factory — ระบบสร้างเหรียญ (public endpoints)
    Route::prefix('token-factory')->group(function () {
        Route::get('/', [TokenFactoryApiController::class, 'index']);
        Route::get('/config', [TokenFactoryApiController::class, 'config']);
        Route::post('/calculate-fee', [TokenFactoryApiController::class, 'calculateFee']);
        Route::get('/{id}', [TokenFactoryApiController::class, 'show'])->where('id', '[0-9]+');
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

    // Staking — read-only public endpoints (legacy)
    Route::prefix('staking')->group(function () {
        Route::get('/pools', [StakingApiController::class, 'pools']);
        Route::get('/stats', [StakingApiController::class, 'stats']);
        Route::get('/positions/{wallet}', [StakingApiController::class, 'positions']);
    });

    // Master Node — network stats (public, read-only)
    Route::prefix('masternode')->group(function () {
        Route::get('/stats', [MasterNodeController::class, 'stats']);
        // ค่าคอนฟิกแต่ละชั้นตามที่อยู่บนเชนจริง — หน้าเว็บใช้เช็กโควตาก่อนให้กดซื้อ
        Route::get('/tiers', [MasterNodeController::class, 'tiers']);
    });

    // Validators — network dashboard + applications (public)
    Route::prefix('validators')->group(function () {
        Route::get('/stats', [ValidatorController::class, 'stats']);
        Route::get('/list', [ValidatorController::class, 'list']);
        Route::get('/rewards', [ValidatorController::class, 'checkRewards']);
        Route::post('/apply', [ValidatorController::class, 'submitApplication'])->middleware('throttle:5,60');
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

    // App Update — ตรวจสอบ + ดาวน์โหลด APK (ไม่ต้องเปิด GitHub)
    Route::prefix('app')->group(function () {
        Route::get('/update-check', [AppUpdateController::class, 'check']);
        Route::get('/download', [AppUpdateController::class, 'download']);
        Route::get('/latest', [AppUpdateController::class, 'latest']);
        Route::get('/chain-latest', [AppUpdateController::class, 'chainLatest']);
        // แอปวอลเล็ตถามผ่านนี่แทนการยิง GitHub เอง (repo เชนกำลังจะเป็นไพรเวท)
        Route::get('/wallet-update-check', [AppUpdateController::class, 'walletUpdateCheck']);
        Route::get('/chain-download', [AppUpdateController::class, 'chainDownload']);
        Route::get('/download-stats', [AppUpdateController::class, 'downloadStats']);
        // แอปถามตอนเปิดว่ารุ่นที่ถืออยู่ยังใช้ได้ไหม — ใช้บังคับให้ย้ายไปรุ่นใหม่
        Route::get('/support-status', [AppUpdateController::class, 'supportStatus']);
        // CI webhook — auto-set active release after build (protected by deploy secret)
        Route::post('/notify-release', [AppUpdateController::class, 'notifyRelease'])
            ->middleware('throttle:10,1');
    });

    // AI Trade (Cloud Bot) — แคตตาล็อกแพลน/กลยุทธ์/แพ็กเครดิต
    // public เพื่อให้เว็บ + แอพแสดงราคาได้ก่อนเชื่อม wallet (ไม่มีข้อมูลส่วนตัว)
    Route::get('/ai-bot/catalog', [AiBotController::class, 'catalog']);

    /*
     * มุมมองตลาดของ AI — เปิดสาธารณะเหมือน catalog
     *
     * ตั้งใจให้คนที่ยังไม่ได้เช่าดูได้ด้วย: เป็นหลักฐานว่าระบบคิดอะไรอยู่จริง
     * ก่อนตัดสินใจจ่ายเงิน ดีกว่าให้เห็นแค่คำโฆษณาว่า "มี AI"
     * (ไม่มีข้อมูลส่วนตัวของใครอยู่ในนั้น — เป็นภาพตลาดล้วน)
     */
    Route::get('/ai-bot/market-view', [AiBotController::class, 'marketView']);
    // ความเสี่ยงของคู่เทรด + พาดหัวข่าวที่ทำให้บอทตัดสินใจ — เป็นข้อมูลตลาด ไม่ใช่ข้อมูลส่วนตัว
    Route::get('/ai-bot/risk', [AiBotController::class, 'risk']);

    // ตารางค่าบริการวางไม้ — public เพื่อให้ดูราคาได้ก่อนเชื่อมกระเป๋า
    // เจ้าของสั่งว่าต้อง "ชี้แจงรายละเอียดให้ครบ" ก่อนผู้ใช้ตัดสินใจ
    Route::get('/trading-fee/tiers', [TradingFeeController::class, 'tiers']);

    // AI Chatbot — ถามตอบอัจฉริยะ (rate limited)
    Route::post('/chatbot', [ChatbotController::class, 'chat'])
        ->middleware('throttle:30,1');

    // Stripe Webhook — รับ event จาก Stripe (ไม่ต้อง auth, ไม่ rate limit)
    // Stripe retries webhooks → ต้องไม่โดน throttle:60,1 ของ group นี้
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
        ->withoutMiddleware([VerifyCsrfToken::class, 'throttle:60,1'])
        ->middleware('throttle:300,1');

    // Wallet Bootstrap — connect/sign/verify must be PUBLIC (before wallet is verified)
    // นับสองชั้น: ต่อกระเป๋า (กันกดรัว) + ต่อ IP (กันเดาลายเซ็นด้วยการหมุนเลขกระเป๋า)
    // ดู RateLimiter::for('wallet-bootstrap') ใน AppServiceProvider
    Route::prefix('wallet')->middleware(['throttle:wallet-bootstrap'])->group(function () {
        Route::post('/connect', [WalletController::class, 'connect']);
        Route::post('/disconnect', [WalletController::class, 'disconnect']);
        Route::post('/sign', [WalletController::class, 'requestSignature']);
        Route::post('/verify-signature', [WalletController::class, 'verifySignature']);
    });
});

// Protected Routes (Wallet Ownership Verified)
Route::prefix('v1')->middleware(['throttle:trading', VerifyWalletOwnership::class])->group(function () {
    // Trading Operations
    Route::prefix('trading')->group(function () {
        Route::post('/order', [TradingController::class, 'createOrder']);
        Route::post('/order/{orderId}/confirm', [TradingController::class, 'confirmOrder']);
        Route::post('/order/{orderId}/fail', [TradingController::class, 'failOrder']);
        Route::delete('/order/{orderId}', [TradingController::class, 'cancelOrder']);
        Route::get('/orders', [TradingController::class, 'getOrders']);
        Route::get('/order/{orderId}', [TradingController::class, 'getOrder']);
        Route::get('/history', [TradingController::class, 'getHistory']);
        Route::get('/fee-info', [TradingController::class, 'getFeeInfo']);
    });

    // Wallet Operations (requires verified wallet)
    Route::prefix('wallet')->group(function () {
        Route::get('/balances', [WalletController::class, 'balances']);
        Route::get('/transactions', [WalletController::class, 'transactions']);
        // Profile sync — name/email/avatar/preferences across mobile ↔ web
        Route::get('/profile', [WalletController::class, 'getProfile']);
        Route::put('/profile', [WalletController::class, 'updateProfile']);
    });

    // Swap Operations
    Route::prefix('swap')->group(function () {
        Route::post('/quote', [TradingController::class, 'getSwapQuote']);
        Route::post('/execute', [TradingController::class, 'executeSwap'])->middleware('kyc:trading');
        Route::get('/routes', [TradingController::class, 'getSwapRoutes']);
    });

    // Token Sale Purchase + Claim — ซื้อ/เคลมเหรียญ (rate limit: 10 ครั้ง/นาที)
    Route::middleware('throttle:10,1')->group(function () {
        /*
         * ตรวจล่วงหน้าก่อนเงินออกจากกระเป๋า — ต้องอยู่ในกลุ่มเดียวกับ purchase
         * เพื่อให้ผ่านด่านเดียวกันทั้ง VerifyWalletOwnership และ KYC
         * ถ้าย้ายออกไปเป็นปลายทางสาธารณะ มันจะกลับไปมีปัญหาเดิมของ /preview ทันที
         */
        Route::post('/token-sale/precheck', [TokenSaleApiController::class, 'precheck'])->middleware('kyc:token_sale');
        Route::post('/token-sale/purchase', [TokenSaleApiController::class, 'purchase'])->middleware('kyc:token_sale');

        /*
         * ทางรับเงินจริงสองทาง — ต้องอยู่ในกลุ่มนี้ทั้งคู่
         *
         * ทั้ง Stripe และการโอนเงินผูก "กระเป๋าปลายทางที่จะได้รับเหรียญ" เข้ากับ
         * คำสั่งซื้อ ถ้าปลายทางเหล่านี้เปิดสาธารณะ ใครก็ยิงคำสั่งซื้อโดยใส่
         * กระเป๋าของคนอื่นได้ และเราจะไม่มีทางรู้เลยว่าคนสั่งคือเจ้าของกระเป๋าจริง
         */
        Route::post('/token-sale/bank-order', [TokenSaleApiController::class, 'bankOrder'])->middleware('kyc:token_sale');
        Route::post('/token-sale/stripe/checkout', [TokenSaleApiController::class, 'stripeCheckout'])->middleware('kyc:token_sale');
        Route::post('/token-sale/claim', [TokenSaleApiController::class, 'claim'])->middleware('kyc:token_sale');
    });

    // Token Factory — สร้างเหรียญ (ต้อง verify wallet)
    Route::prefix('token-factory')->group(function () {
        Route::get('/my-tokens', [TokenFactoryApiController::class, 'myTokens']);
        Route::post('/create', [TokenFactoryApiController::class, 'store'])
            ->middleware(['throttle:5,60', 'kyc:token_factory']); // สร้างได้ 5 ครั้งต่อ 60 นาที
        // ด่านเดียวกับ /create — เดิมมีแค่ throttle ทำให้ใครมีกระเป๋าก็อัปไฟล์
        // ขึ้นโดเมนได้ ทั้งที่ "สร้างเหรียญ" ซึ่งเป็นปลายทางจริงต้องผ่าน KYC
        Route::post('/upload-logo', [TokenFactoryApiController::class, 'uploadLogo'])
            ->middleware(['throttle:10,60', 'kyc:token_factory']);
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
    Route::post('/bridge/initiate', [BridgeApiController::class, 'initiate'])->middleware('kyc:bridge');
    Route::post('/bridge/retry/{id}', [BridgeApiController::class, 'retry']);
    // แนบ tx hash เข้ารายการที่จองไว้ — ต้องจองก่อนโอนเหรียญเสมอ ดูเหตุผลใน attachTx()
    Route::post('/bridge/{id}/tx', [BridgeApiController::class, 'attachTx'])->where('id', '[0-9]+');

    // Staking — write operations (ต้อง verify wallet)
    Route::prefix('staking')->group(function () {
        Route::post('/stake', [StakingApiController::class, 'stake'])->middleware('kyc:masternode');
        Route::post('/claim/{id}', [StakingApiController::class, 'claim']);
        Route::post('/unstake/{id}', [StakingApiController::class, 'unstake']);
    });

    // Master Node — wallet-specific queries (ต้อง verify wallet)
    Route::get('/masternode/my-nodes', [MasterNodeController::class, 'myNodes']);

    /*
     * AI Trade (Cloud Bot) — เช่าบอท, เครดิตการทำงาน, ตั้งค่ากลยุทธ์
     * ทุก endpoint ผูกกับ wallet ของผู้เรียก (VerifyWalletOwnership ตรวจลายเซ็นแล้ว)
     * throttle แยก 30/นาที — หน้าเทรด poll สถานะทุก 60 วิ + การกดเช่า/แก้บอท
     */
    Route::prefix('ai-bot')->middleware(['throttle:30,1'])->group(function () {
        Route::get('/status', [AiBotController::class, 'status']);
        Route::get('/credits', [AiBotController::class, 'credits']);
        /*
         * ด่าน KYC ลงเฉพาะปลายทางที่ "ได้ของ" ไม่ใช่ปลายทางที่แค่อ่านสถานะ
         *
         * ถ้าไปแปะที่ /status หรือ /credits ด้วย หน้า AI Trade จะพังทั้งหน้า
         * ผู้ใช้ที่ยังไม่ยืนยันตัวตนจะเปิดดูไม่ได้เลยว่ามีอะไรให้ใช้บ้าง
         * ซึ่งเท่ากับปิดไม่ให้เขารู้ว่าต้องยืนยันตัวตนไปเพื่ออะไร
         *
         * /welcome อยู่ในด่านด้วย เพราะเป็นเครดิตฟรี — ไม่มีด่านก็เปิดบัญชีรับซ้ำได้ไม่จำกัด
         */
        Route::post('/welcome', [AiBotController::class, 'claimWelcome'])->middleware('kyc:ai_bot');
        Route::post('/topup', [AiBotController::class, 'topup'])->middleware('kyc:ai_bot');
        Route::post('/subscribe', [AiBotController::class, 'subscribe'])->middleware('kyc:ai_bot');
        Route::post('/cancel', [AiBotController::class, 'cancel']);

        Route::get('/bots', [AiBotController::class, 'index']);
        Route::post('/bots', [AiBotController::class, 'store'])->middleware('kyc:ai_bot');
        Route::put('/bots/{id}', [AiBotController::class, 'update'])->where('id', '[0-9]+');
        Route::post('/bots/{id}/state', [AiBotController::class, 'setState'])->where('id', '[0-9]+');
        Route::post('/bots/{id}/mode', [AiBotController::class, 'setMode'])->where('id', '[0-9]+');
        // แพลนฟรีเดินบอทจากหน้าเว็บ — throttle สูงกว่ากลุ่มอื่นเพราะหน้าเว็บเรียกเป็นระยะ
        Route::post('/bots/{id}/tick', [AiBotController::class, 'tickFromBrowser'])
            ->where('id', '[0-9]+')
            ->middleware('throttle:60,1');
        Route::delete('/bots/{id}', [AiBotController::class, 'destroy'])->where('id', '[0-9]+');

        // โหมดทดลอง — พอร์ตกระดาษที่ใช้ราคาจริง ให้ลองก่อนตัดสินใจเช่า
        // สถิติย้อนหลังของกลยุทธ์ + คำแนะนำจากที่ปรึกษา AI (ตามแพลน)
        /*
         * ประวัติการตัดสินใจของบอท — หัวใจของการมอนิเตอร์ ใช้ร่วมกันทั้งเว็บและแอพ
         *
         * ตาราง ai_bot_decisions เก็บ "ทุกครั้งที่บอทคิด" รวมรอบที่ตัดสินใจไม่ทำอะไร
         * ซึ่งเดิมเปิดอ่านได้เฉพาะหลังบ้าน เจ้าของบอทเห็นแค่เหตุผลรอบล่าสุดรอบเดียว
         *
         * throttle สูงกว่ากลุ่มเพราะหน้ามอนิเตอร์เลื่อนดูย้อนหลังทีละหน้า
         */
        Route::get('/decisions', [AiBotController::class, 'decisions'])
            ->middleware('throttle:60,1');

        Route::get('/analytics', [AiBotController::class, 'analytics']);
        Route::post('/advice', [AiBotController::class, 'advice'])->middleware('throttle:10,1');

        Route::get('/demo', [AiBotController::class, 'demo']);
        Route::post('/demo/reset', [AiBotController::class, 'resetDemo']);

        /*
         * ไม้ของบอททุกโหมด — ป้ายเข้า/ออกบนกราฟของหน้าเทรดและแอพ
         *
         * /demo ให้เฉพาะไม้กระดาษและตัดจำนวนไว้ พอมีโหมดจริง กราฟจะเห็นไม่ครบ
         * throttle สูงกว่ากลุ่มเพราะหน้าเทรดยิงซ้ำทุกครั้งที่สลับคู่
         */
        Route::get('/trades', [AiBotController::class, 'trades'])->middleware('throttle:60,1');

        /*
         * กระเป๋าบอท — กระเป๋าแยกที่บอทใช้ในโหมดจริง (ผู้ใช้โอนเข้า / ถอนกลับหาตัวเองเท่านั้น)
         *
         * สร้าง/ถอนอยู่หลังด่าน KYC เหมือนของที่ "ได้ของ" ตัวอื่น — กระเป๋าที่ถือเงินจริง
         * ต้องรู้ว่าเป็นของใคร · ถอนจำกัด 5 ครั้ง/นาที เพราะทุกครั้งอ่านยอดจากเชน
         */
        Route::get('/wallet', [\App\Http\Controllers\Api\AiBotWalletController::class, 'show']);
        Route::post('/wallet', [\App\Http\Controllers\Api\AiBotWalletController::class, 'store'])->middleware('kyc:ai_bot');
        Route::post('/wallet/refresh', [\App\Http\Controllers\Api\AiBotWalletController::class, 'refresh'])->middleware('throttle:10,1');
        Route::post('/wallet/withdraw', [\App\Http\Controllers\Api\AiBotWalletController::class, 'withdraw'])->middleware(['kyc:ai_bot', 'throttle:5,1']);
        Route::post('/wallet/withdraw/{id}/cancel', [\App\Http\Controllers\Api\AiBotWalletController::class, 'cancel'])->where('id', '[0-9]+');
    });

    /*
     * คลัง TPIX + ใบอนุญาตวางไม้
     *
     * ค่าบริการถูกเก็บตอนขอใบอนุญาต ก่อนผู้ใช้เซ็นธุรกรรมของไม้ — เป็นจุดเดียว
     * ที่เก็บได้จริง เพราะเส้นทางเทรดบน BSC ผู้ใช้เซ็นกับ PancakeSwap ตรงๆ
     * เหรียญไม่ผ่านเราเลย
     *
     * quote ถูกเรียกทุกครั้งที่ผู้ใช้พิมพ์จำนวน จึงให้โควตาสูงกว่าตัวที่เขียนข้อมูล
     */
    Route::prefix('trading-fee')->group(function () {
        Route::post('/quote', [TradingFeeController::class, 'quote'])->middleware('throttle:120,1');
        Route::get('/balance', [TradingFeeController::class, 'balance'])->middleware('throttle:60,1');

        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/tickets', [TradingFeeController::class, 'issueTicket']);
            Route::post('/tickets/{uuid}/consume', [TradingFeeController::class, 'consumeTicket']);
            Route::post('/tickets/{uuid}/refund', [TradingFeeController::class, 'refundTicket']);
            Route::post('/topup/confirm', [TradingFeeController::class, 'confirmTopup']);
        });
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
