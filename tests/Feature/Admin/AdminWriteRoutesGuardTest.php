<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminRole;
use Closure;
use Illuminate\Routing\Route as AppRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — route เขียนของหลังบ้านทุกเส้นต้องถูกจัดประเภท.
 *
 * ผลตรวจเส้นทางเงินเจอบั๊กแบบเดิมซ้ำ: admin.auth ตรวจแค่ว่าล็อกอิน route ใหม่ที่ขยับเงินจึงเปิดให้แอดมินทุกระดับ
 * ตั้งแต่บรรทัดแรก และไม่มีอะไรบังคับให้คิดเรื่อง role ตอนเพิ่ม
 *
 * เทสนี้กลับค่าเริ่มต้น: route เขียนใหม่ตก CI จนกว่าจะเลือกอย่างใดอย่างหนึ่ง
 * - ใส่ admin.role:super_admin ใน routes/admin.php (ค่าปกติของอะไรก็ตามที่แตะเงินหรือสิทธิ์)
 * - ถามเจ้าของ แล้วเพิ่มชื่อใน LOWER_ADMINS_ALLOWED พร้อมเหตุผล
 *
 * Developed by Xman Studio.
 */
class AdminWriteRoutesGuardTest extends TestCase
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** ระดับต่ำกว่า super_admin ใช้ได้ ทุกกลุ่มต้องบอกเหตุผล */
    private const LOWER_ADMINS_ALLOWED = [
        // ก่อนล็อกอิน — ยังไม่มีตัวแอดมินให้ตรวจ role
        'admin.login.submit',
        'admin.setup.store',

        // บัญชีและการแจ้งเตือนของตัวเอง
        'admin.logout',
        'admin.notifications.mark-all',
        'admin.notifications.read',
        'admin.profile.password',
        'admin.profile.update',

        // ขั้นบันไดค่าบริการแก้ได้ทุกระดับ — กระเป๋ารับเงินแยกไปอยู่ trading-fees.settings
        'admin.trading-fees.destroy',
        'admin.trading-fees.store',
        'admin.trading-fees.update',

        // admin.role:super_admin,admin — กันเฉพาะ moderator/support ตามเหตุผลใน routes/admin.php
        'admin.token-factory.approve',
        'admin.token-factory.list',
        'admin.token-factory.logo',
        'admin.token-factory.recheck',
        'admin.token-factory.reject',
        'admin.token-factory.reset-stuck',
        'admin.token-factory.retry',
        'admin.token-factory.verify',
        'admin.tokens.destroy',
        'admin.tokens.store',
        'admin.tokens.update',
        'admin.validators.kyc.confirm',
    ];

    /**
     * ยังไม่มีใครตัดสิน — ยกมาตามสภาพวันที่เพิ่มเทสนี้ (2026-10-30) ไม่ได้แปลว่าอนุมัติ.
     *
     * เจ้าของตอบแล้วให้ย้ายออก ห้ามเอา route ใหม่มาซ่อนไว้ที่นี่
     */
    private const AWAITING_OWNER = [
        'admin.ai-bots.ban',
        'admin.ai-bots.pause',
        'admin.ai-bots.resume',
        'admin.ai-bots.unban',
        'admin.ai.analyze',
        'admin.ai.news.destroy',
        'admin.ai.news.generate',
        'admin.ai.news.publish',
        'admin.ai.news.update',
        'admin.app-releases.refresh',
        'admin.app-releases.set-active',
        'admin.banners.destroy',
        'admin.banners.store',
        'admin.banners.toggle',
        'admin.banners.update',
        'admin.bug-reports.refresh',
        'admin.carbon-credits.destroy',
        'admin.carbon-credits.store',
        'admin.carbon-credits.update',
        'admin.chains.destroy',
        'admin.chains.store',
        'admin.chains.toggle',
        'admin.chains.update',
        'admin.content.destroy',
        'admin.content.generate',
        'admin.content.generate-image',
        'admin.content.store',
        'admin.content.update',
        'admin.fees.destroy',
        'admin.fees.store',
        'admin.fees.toggle',
        'admin.fees.update',
        'admin.food-passport.certificates.revoke',
        'admin.food-passport.devices.destroy',
        'admin.food-passport.devices.regenerate-key',
        'admin.food-passport.devices.status',
        'admin.food-passport.products.status',
        'admin.food-passport.products.suspend',
        'admin.kyc.approve',
        'admin.kyc.deletions.complete',
        'admin.kyc.deletions.reject',
        'admin.kyc.reject',
        'admin.kyc.settings',
        'admin.languages.default',
        'admin.languages.destroy',
        'admin.languages.store',
        'admin.languages.toggle',
        'admin.languages.translations.update',
        'admin.languages.update',
        'admin.masternode.allowlist.cleanup',
        'admin.masternode.allowlist.destroy',
        'admin.masternode.allowlist.revoke',
        'admin.masternode.config',
        'admin.masternode.toggle',
        'admin.members.ban',
        'admin.members.kyc',
        'admin.members.unban',
        'admin.settings.advisor',
        'admin.settings.advisor.test',
        'admin.settings.ai',
        'admin.settings.content',
        'admin.settings.email',
        'admin.settings.email.test',
        'admin.settings.general',
        'admin.settings.logo',
        'admin.settings.map',
        'admin.settings.seo',
        'admin.settings.social',
        'admin.settings.update',
        'admin.support.assign',
        'admin.support.reply',
        'admin.support.status',
        'admin.system-alerts.resolve',
        // role admin สั่งได้ และ refund.auto เข้าคิวเซ็นเลยโดยไม่ผ่านการอนุมัติในหน้าคลัง
        'admin.token-factory.refund.auto',
        'admin.token-factory.refund.manual',
        'admin.trading-pairs.destroy',
        'admin.trading-pairs.store',
        'admin.trading-pairs.toggle',
        'admin.trading-pairs.update',
        'admin.validators.approve',
        'admin.validators.propose-vote',
        'admin.validators.reject',
    ];

    /** ตรงกับจำนวนแถวเสมอ รายการนี้จึงโตเงียบ ๆ ไม่ได้: ย้ายออกแล้วลดเลขนี้ตาม ห้ามเพิ่ม */
    private const AWAITING_OWNER_COUNT = 82;

    /** @return array<int, AppRoute> */
    private function writeRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (AppRoute $route): bool => array_intersect($route->methods(), self::WRITE_METHODS) !== []
                && $this->isAdminRoute($route),
        ));
    }

    private function isAdminRoute(AppRoute $route): bool
    {
        return Str::is(['admin', 'admin/*'], $route->uri())
            || Str::startsWith($route->getName() ?? '', 'admin.')
            || in_array(AdminAuth::class, $this->runningMiddleware($route), true);
    }

    /** @return array<int, mixed> middleware ที่รันจริง — หัก withoutMiddleware และแปลงชื่อย่อเป็นคลาสแล้ว */
    private function runningMiddleware(AppRoute $route): array
    {
        return Route::gatherRouteMiddleware($route);
    }

    /** ต้องตรงตัว — admin.role:super_admin,admin ยังเปิดให้ role admin อยู่ จึงไม่นับ */
    private function isSuperAdminOnly(AppRoute $route): bool
    {
        return in_array(AdminRole::class.':super_admin', $this->runningMiddleware($route), true);
    }

    /**
     * นับชื่อซ้ำเป็นยังไม่จัดประเภทด้วย — คัดลอกบรรทัดเดิมแล้วลืมเปลี่ยนชื่อต้องไม่ได้สิทธิ์ของเส้นเดิมไป.
     *
     * @return array<int, string>
     */
    private function unclassified(): array
    {
        $classified = array_merge(self::LOWER_ADMINS_ALLOWED, self::AWAITING_OWNER);
        $seen = [];
        $unclassified = [];

        foreach ($this->writeRoutes() as $route) {
            $name = $route->getName();

            if (! $this->isSuperAdminOnly($route) && (! in_array($name, $classified, true) || in_array($name, $seen, true))) {
                $unclassified[] = implode('|', $route->methods()).' '.$route->uri().' ('.($name ?? 'ไม่มีชื่อ').')';
            }

            $seen[] = $name;
        }

        return $unclassified;
    }

    #[Test]
    public function routeเขียนทุกเส้นต้องถูกจัดประเภท(): void
    {
        $this->assertSame(
            [],
            $this->unclassified(),
            'ใส่ admin.role:super_admin ใน routes/admin.php หรือถามเจ้าของแล้วเพิ่มชื่อใน LOWER_ADMINS_ALLOWED',
        );
    }

    /** ชื่อที่ค้างในรายการจะกลายเป็นใบผ่านทันทีที่วันหลังมีคนถอดด่านออก */
    #[Test]
    public function รายการต้องตรงกับrouteที่ยังเปิดอยู่(): void
    {
        $open = array_map(
            fn (AppRoute $route): ?string => $route->getName(),
            array_filter($this->writeRoutes(), fn (AppRoute $route): bool => ! $this->isSuperAdminOnly($route)),
        );
        $listed = array_merge(self::LOWER_ADMINS_ALLOWED, self::AWAITING_OWNER);

        $this->assertSame([], array_values(array_diff($listed, $open)), 'ลบชื่อเหล่านี้ออกจากรายการ: ไม่มี route นี้แล้ว หรือล็อก super_admin แล้ว');
        $this->assertSame(array_values(array_unique($listed)), $listed, 'มีชื่อซ้ำในรายการ');
    }

    #[Test]
    public function รายการรอเจ้าของห้ามโตขึ้น(): void
    {
        $this->assertCount(
            self::AWAITING_OWNER_COUNT,
            self::AWAITING_OWNER,
            'route ใหม่ต้องล็อก super_admin หรือให้เจ้าของตัดสิน ย้ายออกจากรายการแล้วให้ลด AWAITING_OWNER_COUNT ตาม',
        );
    }

    /** @return array<string, array{Closure}> */
    public static function unclassifiedRoutes(): array
    {
        return [
            'เปิดทุกระดับ' => [fn () => Route::post('admin/probe', fn () => null)
                ->middleware('admin.auth')->name('admin.probe')],
            'เปิดให้ role admin' => [fn () => Route::post('admin/probe', fn () => null)
                ->middleware(['admin.auth', 'admin.role:super_admin,admin'])->name('admin.probe')],
            'ใส่ด่านแล้วถอดออก' => [fn () => Route::post('admin/probe', fn () => null)
                ->middleware(['admin.auth', 'admin.role:super_admin'])->withoutMiddleware('admin.role:super_admin')->name('admin.probe')],
            'ไม่มีชื่อ' => [fn () => Route::delete('admin/probe', fn () => null)],
            'อ่านและเขียนในเส้นเดียว' => [fn () => Route::match(['get', 'post'], 'admin/probe', fn () => null)
                ->middleware('admin.auth')->name('admin.probe')],
            'ชื่อซ้ำกับเส้นที่จัดประเภทแล้ว' => [fn () => Route::post('admin/probe', fn () => null)
                ->middleware('admin.auth')->name('admin.fees.store')],
            'อยู่นอก prefix แต่ใช้ admin.auth' => [fn () => Route::post('probe', fn () => null)
                ->middleware('admin.auth')->name('probe')],
        ];
    }

    /** ด่านนี้ต้องล้มจริง ไม่ใช่ผ่านเพราะไล่ไม่เจออะไร */
    #[Test]
    #[DataProvider('unclassifiedRoutes')]
    public function routeใหม่ที่ไม่ได้จัดประเภทตกเทส(Closure $addRoute): void
    {
        $before = $this->unclassified();

        $addRoute();

        $added = array_values(array_diff($this->unclassified(), $before));

        $this->assertCount(1, $added);
        $this->assertStringContainsString('probe', $added[0]);
    }

    #[Test]
    public function routeที่ล็อกsuper_adminและหน้าอ่านไม่ต้องจัดประเภท(): void
    {
        $before = $this->unclassified();

        Route::post('admin/probe', fn () => null)
            ->middleware(['admin.auth', 'admin.role:super_admin'])
            ->name('admin.probe');
        Route::get('admin/probe-read', fn () => null)
            ->middleware('admin.auth')
            ->name('admin.probe-read');

        $this->assertSame($before, $this->unclassified());
    }
}
