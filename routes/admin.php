<?php

/**
 * TPIX TRADE - Admin Routes
 * Developed by Xman Studio.
 *
 * All admin panel routes are prefixed with /admin and use the 'admin.' name prefix.
 * Authentication is handled via the 'admin' guard with session-based auth.
 */

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AiController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CarbonCreditController as AdminCarbonCreditController;
use App\Http\Controllers\Admin\ChainController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeeController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SwapController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\Admin\TokenFactoryController;
use App\Http\Controllers\Admin\TokenSaleController;
use App\Http\Controllers\Admin\TradingPairController;
use App\Http\Controllers\Admin\TransactionController;
use App\Models\WalletConnection;
use App\Services\UserWalletService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Admin Auth (public - no auth required)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.submit')->middleware('turnstile');
    Route::post('setup', [AuthController::class, 'setup'])->name('setup')->middleware('throttle:5,60');

    // Protected admin routes
    Route::middleware(['admin.auth', 'admin.audit'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Settings — ทุก tab (general, seo, trading, security, social)
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/logo', [SettingController::class, 'updateLogo'])->name('settings.logo');
        Route::post('settings/general', [SettingController::class, 'updateGeneral'])->name('settings.general');
        Route::post('settings/seo', [SettingController::class, 'updateSeo'])->name('settings.seo');
        Route::put('settings/trading', [SettingController::class, 'updateTab'])->name('settings.trading');
        Route::put('settings/security', [SettingController::class, 'updateTab'])->name('settings.security');
        Route::put('settings/social', [SettingController::class, 'updateTab'])->name('settings.social');
        Route::put('settings/payment', [SettingController::class, 'updateTab'])->name('settings.payment');
        Route::put('settings/ai', [SettingController::class, 'updateTab'])->name('settings.ai');
        Route::put('settings/email', [SettingController::class, 'updateEmail'])->name('settings.email');
        Route::post('settings/email/test', [SettingController::class, 'sendTestEmail'])->name('settings.email.test');

        // Fees
        Route::resource('fees', FeeController::class)->except(['create', 'show', 'edit']);
        Route::patch('fees/{fee}/toggle', [FeeController::class, 'toggleActive'])->name('fees.toggle');

        // Chains
        Route::resource('chains', ChainController::class)->except(['create', 'show', 'edit']);
        Route::patch('chains/{chain}/toggle', [ChainController::class, 'toggleActive'])->name('chains.toggle');

        // Tokens (flat listing + nested under chains)
        Route::get('tokens', [TokenController::class, 'all'])->name('tokens.all');
        Route::get('chains/{chain}/tokens', [TokenController::class, 'index'])->name('tokens.index');
        Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
        Route::put('tokens/{token}', [TokenController::class, 'update'])->name('tokens.update');
        Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');

        // Trading Pairs
        Route::resource('trading-pairs', TradingPairController::class)->except(['create', 'show', 'edit']);
        Route::patch('trading-pairs/{trading_pair}/toggle', [TradingPairController::class, 'toggleActive'])->name('trading-pairs.toggle');

        // Transactions (read-only)
        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');

        // Support Tickets
        Route::get('support', [SupportController::class, 'index'])->name('support.index');
        Route::get('support/{ticket}', [SupportController::class, 'show'])->name('support.show');
        Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.reply');
        Route::patch('support/{ticket}/status', [SupportController::class, 'updateStatus'])->name('support.status');
        Route::patch('support/{ticket}/assign', [SupportController::class, 'assign'])->name('support.assign');

        // Languages & Translations
        Route::resource('languages', LanguageController::class)->except(['create', 'show', 'edit']);
        Route::patch('languages/{language}/toggle', [LanguageController::class, 'toggleActive'])->name('languages.toggle');
        Route::patch('languages/{language}/default', [LanguageController::class, 'setDefault'])->name('languages.default');
        Route::get('languages/{language}/translations', [LanguageController::class, 'translations'])->name('languages.translations');
        Route::put('languages/{language}/translations', [LanguageController::class, 'updateTranslations'])->name('languages.translations.update');

        // Swap Configurations
        Route::resource('swap', SwapController::class)->except(['create', 'show', 'edit']);
        Route::patch('swap/{swap}/toggle', [SwapController::class, 'toggleActive'])->name('swap.toggle');

        // AI System
        Route::prefix('ai')->name('ai.')->group(function () {
            Route::get('/', [AiController::class, 'index'])->name('index');
            Route::post('/analyze', [AiController::class, 'analyze'])->name('analyze');
            Route::get('/history', [AiController::class, 'analyzeHistory'])->name('history');
            Route::get('/news', [AiController::class, 'newsIndex'])->name('news');
            Route::post('/news/generate', [AiController::class, 'generateNews'])->name('news.generate');
            Route::put('/news/{news}', [AiController::class, 'newsUpdate'])->name('news.update');
            Route::patch('/news/{news}/publish', [AiController::class, 'newsPublish'])->name('news.publish');
            Route::delete('/news/{news}', [AiController::class, 'newsDestroy'])->name('news.destroy');
        });

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all');

        // Admin Profile
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Admin User Management (super_admin only)
        Route::resource('users', AdminUserController::class)
            ->except(['create', 'show', 'edit'])
            ->middleware('admin.role:super_admin');
        Route::patch('users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])
            ->name('users.reset-password')
            ->middleware('admin.role:super_admin');

        // Token Sales — จัดการรอบขายเหรียญ TPIX (ICO/IDO) + Token Control
        Route::prefix('token-sales')->name('token-sales.')->group(function () {
            Route::get('/', [TokenSaleController::class, 'index'])->name('index');
            Route::post('/', [TokenSaleController::class, 'store'])->name('store');
            Route::post('/phase', [TokenSaleController::class, 'updatePhase'])->name('phase.update');
        });

        // Token Factory — จัดการ Token ที่สร้างจาก Factory
        Route::prefix('token-factory')->name('token-factory.')->group(function () {
            Route::get('/', [TokenFactoryController::class, 'index'])->name('index');
            Route::post('/{id}/approve', [TokenFactoryController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject', [TokenFactoryController::class, 'reject'])->name('reject');
            Route::patch('/{id}/verify', [TokenFactoryController::class, 'toggleVerified'])->name('verify');
        });

        // Carbon Credits — จัดการระบบ Carbon Credit
        Route::prefix('carbon-credits')->name('carbon-credits.')->group(function () {
            Route::get('/', [AdminCarbonCreditController::class, 'index'])->name('index');
            Route::post('/', [AdminCarbonCreditController::class, 'store'])->name('store');
            Route::put('/{id}', [AdminCarbonCreditController::class, 'update'])->name('update');
            Route::delete('/{id}', [AdminCarbonCreditController::class, 'destroy'])->name('destroy');
        });

        // Wallets — ภาพรวม wallet ทั้งระบบ
        Route::get('wallets', function () {
            $service = app(UserWalletService::class);
            $stats = $service->getStats();
            $recent = WalletConnection::orderByDesc('connected_at')->limit(20)->get();

            return Inertia::render('Admin/Wallets/Index', [
                'stats' => $stats,
                'recentConnections' => $recent,
            ]);
        })->name('wallets.index');

        // Members — จัดการสมาชิก (Traders)
        Route::prefix('members')->name('members.')->group(function () {
            Route::get('/', [MemberController::class, 'index'])->name('index');
            Route::get('/{member}', [MemberController::class, 'show'])->name('show');
            Route::patch('/{member}/ban', [MemberController::class, 'ban'])->name('ban');
            Route::patch('/{member}/unban', [MemberController::class, 'unban'])->name('unban');
            Route::patch('/{member}/kyc', [MemberController::class, 'updateKyc'])->name('kyc');
        });

        // Content — ระบบบทความ AI + Blog
        Route::prefix('content')->name('content.')->group(function () {
            Route::get('/', [ContentController::class, 'index'])->name('index');
            Route::get('/{id}/edit', [ContentController::class, 'edit'])->name('edit');
            Route::post('/generate', [ContentController::class, 'generate'])->name('generate');
            Route::put('/{id}', [ContentController::class, 'update'])->name('update');
            Route::delete('/{id}', [ContentController::class, 'destroy'])->name('destroy');
            Route::post('/generate-image', [ContentController::class, 'generateImage'])->name('generate-image');
        });

        // Banners — จัดการป้ายโฆษณา (Image, Google AdSense, HTML)
        Route::resource('banners', BannerController::class)->except(['create', 'show', 'edit']);
        Route::patch('banners/{banner}/toggle', [BannerController::class, 'toggleActive'])->name('banners.toggle');

        // Audit Logs (super_admin only)
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->name('audit-logs.index')
            ->middleware('admin.role:super_admin');
    });
});
