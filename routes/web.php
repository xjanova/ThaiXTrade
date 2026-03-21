<?php

/**
 * TPIX TRADE - Web Routes
 * Developed by Xman Studio.
 */

use App\Http\Controllers\Api\AppUpdateController;
use App\Http\Controllers\CarbonCreditController;
use App\Http\Controllers\FoodPassportController;
use App\Http\Controllers\TokenFactoryController;
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
})->where('slug', '[a-z0-9\-]+')->name('blog.show');

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

// Staking — ระบบ Staking TPIX (Coming Soon)
Route::get('/staking', function () {
    return Inertia::render('Staking');
})->name('staking');

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
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');
