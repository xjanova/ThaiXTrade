<?php

namespace App\Providers;

use App\Models\SiteSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Line\LineExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * TPIX TRADE - Application Service Provider
 * Developed by Xman Studio.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind public path to public_html
        $this->app->bind('path.public', function () {
            return base_path('public_html');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register Line Socialite provider
        Event::listen(SocialiteWasCalled::class, [LineExtendSocialite::class, 'handle']);

        // Rate limiters for API routes
        RateLimiter::for('trading', function (Request $request) {
            return Limit::perMinute(30)->by($request->input('wallet_address') ?? $request->ip());
        });

        // Configure mail from database settings
        $this->configureMailFromDatabase();
    }

    /**
     * Apply email config from database (Resend API Key, from address/name).
     */
    private function configureMailFromDatabase(): void
    {
        try {
            $apiKey = SiteSetting::get('email', 'resend_api_key');
            if ($apiKey) {
                config(['services.resend.key' => $apiKey]);
                config(['mail.default' => 'resend']);
            }

            $fromAddress = SiteSetting::get('email', 'mail_from_address');
            if ($fromAddress) {
                config(['mail.from.address' => $fromAddress]);
            }

            $fromName = SiteSetting::get('email', 'mail_from_name');
            if ($fromName) {
                config(['mail.from.name' => $fromName]);
            }
        } catch (\Exception) {
            // Database may not exist during migrations
        }
    }
}
