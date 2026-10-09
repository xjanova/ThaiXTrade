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
     * โควตาของ AI TRADE (/api/v1/ai-bot/*) — ครั้งต่อนาที ต่อ "กระเป๋า + IP".
     *
     *   ai-bot              ทุก endpoint ของกลุ่ม (สถานะ · พอร์ตทดลอง · ตั้งค่าบอท ฯลฯ)
     *   ai-bot-poll         endpoint ที่หน้าเว็บเรียกเป็นระยะ (สั่งบอทฟรีเดิน · ไม้บนกราฟ · ประวัติการคิด)
     *                       แยกงบออกมาเพราะเรียกถี่กว่าตัวอื่นโดยธรรมชาติ
     *   ai-bot-advice       ขอคำแนะนำ AI (ยิง LLM จริง)
     *   ai-bot-wallet-sync  อ่านยอดกระเป๋าบอทจากเชน
     *   ai-bot-withdraw     ขอถอนจากกระเป๋าบอท
     *
     * ตัวเลขเท่าของเดิมที่ตั้งไว้ใน routes/api.php — ต่างกันที่ตอนนี้ "มีผลจริง" แล้ว (ดู aiBotLimits)
     */
    private const AI_BOT_RATE_LIMITS = [
        'ai-bot' => 60,
        'ai-bot-poll' => 120,
        'ai-bot-advice' => 10,
        'ai-bot-wallet-sync' => 10,
        'ai-bot-withdraw' => 5,
    ];

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

        /*
         * เชื่อมกระเป๋า / ขอข้อความ / ยืนยันลายเซ็น
         *
         * นับสองชั้นพร้อมกัน เพราะสองภัยคนละแบบ:
         *
         *   ต่อกระเป๋า — กันคนคนเดียวกดรัวจนเปลืองทรัพยากร
         *                แต่ต้องพอให้เชื่อมใหม่/สลับกระเป๋าได้หลายรอบ
         *                (หนึ่งครั้งที่เชื่อม = 3 คำขอ: connect + sign + verify)
         *
         *   ต่อ IP     — กันเดาลายเซ็นด้วยการหมุนเลขกระเป๋าไปเรื่อยๆ
         *                ซึ่งการนับต่อกระเป๋าอย่างเดียวกันไม่ได้เลย
         *                ตั้งสูงกว่าเพราะผู้ใช้มือถือหลายคนใช้ IP เดียวกัน (NAT)
         *
         * ⚠️ ตัวเลขนี้จะมีความหมายก็ต่อเมื่ออ่าน IP จริงได้เท่านั้น
         *    ดู config/trustedproxy.php — ถ้าไม่เชื่อพร็อกซี ทุกคนจะกลายเป็น IP
         *    เดียวกันแล้วโควตาต่อ IP จะกลายเป็นโควตารวมของทั้งเว็บ
         */
        RateLimiter::for('wallet-bootstrap', function (Request $request) {
            $wallet = strtolower((string) $request->input('wallet_address', ''));

            return [
                Limit::perMinute(20)->by('wallet:'.($wallet ?: $request->ip())),
                Limit::perMinute(60)->by('ip:'.$request->ip()),
            ];
        });

        foreach (self::AI_BOT_RATE_LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => $this->aiBotLimits($request, $perMinute));
        }

        // Configure mail from database settings
        $this->configureMailFromDatabase();
    }

    /**
     * โควตาของกลุ่ม AI TRADE — นับต่อ "กระเป๋า + IP" บนตัวนับของตัวเอง.
     *
     * ⚠️ เดิมกลุ่มนี้ใช้ `throttle:30,1` แบบไม่มีชื่อ ซึ่งทุกตัวที่ไม่มีชื่อในเว็บใช้ "ตัวนับเดียวกัน"
     *    (คีย์ = โดเมน|IP) — คำขอของบอทหนึ่งครั้งถูกนับสองรอบ (กลุ่ม 30 + throttleApi 60 ของทั้ง API)
     *    และนับรวมกับ API สาธารณะทุกตัวจาก IP นั้น หน้าคู่ TPIX/DEX ถามราคา 3 ตัวทุก 5 วิ
     *    (36 ครั้ง/นาที) ก็กินโควตาหมดแล้ว — /status ได้ 429 → การ์ดพลิกเป็น "ยังไม่ได้เช่า"
     *    ส่วน `throttle:60,1` รายเส้นทาง (/tick · /trades · /decisions) ก็ไม่มีความหมาย เพราะ
     *    ตัวนับเดียวกันโดนเพดาน 30 ของกลุ่มตัดไปก่อนเสมอ
     *
     * กระเป๋าที่ส่งมาแปลงเป็นตัวเล็ก — ที่อยู่แบบ checksum (ตัวใหญ่ปน) กับตัวเล็กคือกระเป๋าเดียวกัน
     * ถ้านับแยก ผู้ใช้คนเดียวได้โควตาสองเท่า · เพดานต่อ IP (10 เท่า) กันหมุนเลขกระเป๋า
     * แต่สูงพอสำหรับผู้ใช้มือถือหลายคนที่ออกเน็ตด้วย IP เดียวกัน (NAT)
     *
     * @return list<Limit>
     */
    private function aiBotLimits(Request $request, int $perMinute): array
    {
        // ไม่ใช่สตริง (ส่ง array มา) = ถือว่าไม่ได้ระบุกระเป๋า — ห้าม cast ตรงๆ จะกลายเป็น 500
        $raw = $request->input('wallet_address');
        $wallet = is_string($raw) ? strtolower(trim($raw)) : '';
        $ip = (string) $request->ip();

        return [
            Limit::perMinute($perMinute)->by(($wallet !== '' ? $wallet : '-').'|'.$ip),
            Limit::perMinute($perMinute * 10)->by('ip|'.$ip),
        ];
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
