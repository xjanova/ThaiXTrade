<?php

/**
 * TPIX TRADE - Admin Routes
 * Developed by Xman Studio.
 *
 * All admin panel routes are prefixed with /admin and use the 'admin.' name prefix.
 * Authentication is handled via the 'admin' guard with session-based auth.
 */

use App\Http\Controllers\Admin\AiController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ChainController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeeController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SwapController;
use App\Http\Controllers\Admin\TokenController;
use App\Http\Controllers\Admin\TradingPairController;
use App\Http\Controllers\Admin\TransactionController;
use Illuminate\Support\Facades\Route;

// Admin Auth (public - no auth required)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.submit')->middleware('turnstile');
    Route::post('setup', [AuthController::class, 'setup'])->name('setup');

    // Protected admin routes
    Route::middleware(['admin.auth', 'admin.audit'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Settings
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/logo', [SettingController::class, 'updateLogo'])->name('settings.logo');

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

        // Audit Logs (super_admin only)
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->name('audit-logs.index')
            ->middleware('admin.role:super_admin');
    });
});
