<?php

/**
 * TPIX TRADE - Web Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\Api\AppUpdateController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\CarbonCreditController;
use App\Http\Controllers\FoodPassportController;
use App\Http\Controllers\MasterNodeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TokenFactoryController;
use App\Http\Controllers\TokenSaleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// User Authentication
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->name('login.submit')->middleware('turnstile');
    Route::get('register', [RegisterController::class, 'showRegister'])->name('register');
    Route::post('register', [RegisterController::class, 'register'])->name('register.submit')->middleware('turnstile');
});
Route::post('logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Social OAuth (accessible by both guests and authenticated users)
Route::get('auth/{provider}', [SocialController::class, 'redirect'])
    ->where('provider', 'google|facebook|line')
    ->name('social.redirect');
Route::get('auth/{provider}/callback', [SocialController::class, 'callback'])
    ->where('provider', 'google|facebook|line')
    ->name('social.callback');

// User Profile (authenticated)
Route::middleware('auth')->group(function () {
    Route::get('profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.delete');
    Route::delete('auth/{provider}/unlink', [SocialController::class, 'unlink'])
        ->where('provider', 'google|facebook|line')
        ->name('social.unlink');
});

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
    })->where('pair', '[A-Za-z0-9]+-[A-Za-z0-9]+')->name('trade.pair');
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

// Blog — บทความ AI-generated + content marketing
Route::get('/blog', function () {
    return Inertia::render('Blog/Index');
})->name('blog');
Route::get('/blog/{slug}', function ($slug) {
    return Inertia::render('Blog/Show', ['slug' => $slug]);
})->where('slug', '[a-zA-Z0-9\-_]+')->name('blog.show');

// Settings
Route::get('/settings', function () {
    return Inertia::render('Settings');
})->name('settings');

// Token Sale — หน้าขายเหรียญ TPIX (ICO/IDO)
Route::get('/token-sale', [TokenSaleController::class, 'index'])->name('token-sale');

// Token Factory — สร้างเหรียญบน TPIX Chain
Route::get('/token-factory', [TokenFactoryController::class, 'index'])->name('token-factory');

// Carbon Credits — ระบบ Carbon Credit
Route::get('/carbon-credits', [CarbonCreditController::class, 'index'])->name('carbon-credits');

// FoodPassport — ระบบตรวจสอบที่มาอาหารบน Blockchain + IoT
Route::get('/food-passport', [FoodPassportController::class, 'index'])->name('food-passport');
Route::get('/food-passport/verify/{productId}', [FoodPassportController::class, 'verify'])
    ->where('productId', '[0-9]+')->name('food-passport.verify');

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

// Master Node — ตั้งโหนด + Network Dashboard (แทนที่ Staking เดิม)
Route::get('/masternode', [MasterNodeController::class, 'index'])->name('masternode');
Route::get('/masternode/guide', [MasterNodeController::class, 'guide'])->name('masternode.guide');
Route::get('/staking', fn () => redirect()->route('masternode'))->name('staking'); // redirect เก่า

// Download — ดาวน์โหลดแอป TPIX TRADE (ดึง release ล่าสุดจาก API ของเราเอง)
Route::get('/download', function () {
    $release = null;

    try {
        $controller = app(AppUpdateController::class);
        $response = $controller->latest();
        $json = json_decode($response->getContent(), true);

        if (($json['success'] ?? false) && isset($json['data'])) {
            $d = $json['data'];
            $release = [
                'version' => 'v'.$d['version'],
                'name' => $d['name'],
                'publishedAt' => $d['published_at'],
                'body' => $d['notes'],
                'apkUrl' => $d['download_url'],
                'apkSize' => $d['file_size'] ? round($d['file_size'] / 1024 / 1024) : null,
                'apkName' => $d['file_name'],
            ];
        }
    } catch (Exception) {
        // Fallback: frontend will fetch on mount
    }

    return Inertia::render('Download', [
        'latestRelease' => $release,
    ]);
})->name('download');

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'app' => config('app.name'),
        'version' => json_decode(file_get_contents(base_path('version.json')), true)['version'] ?? 'unknown',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');
