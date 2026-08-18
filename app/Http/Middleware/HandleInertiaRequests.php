<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Kyc\KycGate;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $socialLinks = SiteSetting::getGroup('social');

        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name', 'TPIX TRADE'),
                'url' => config('app.url'),
                'walletconnect_project_id' => SiteSetting::get('trading', 'walletconnect_project_id', ''),
            ],
            'social' => [
                'twitter' => $socialLinks['twitter_url'] ?? null,
                'telegram' => $socialLinks['telegram_url'] ?? null,
                'discord' => $socialLinks['discord_url'] ?? null,
                'github' => $socialLinks['github_url'] ?? null,
            ],
            /*
             * ผู้ใช้ที่เข้าระบบด้วยการเซ็นกระเป๋าจะไม่มีชื่อและไม่มีอีเมล
             *
             * ต้องส่ง wallet_address มาด้วย ไม่งั้นหน้าเว็บที่แสดงชื่อผู้ใช้จะว่างเปล่า
             * และไม่มีทางรู้ว่าบัญชีที่ล็อกอินอยู่ผูกกับกระเป๋าใบไหน — ซึ่งเป็นสิ่งที่
             * ต้องรู้เพื่อเตือนเมื่อผู้ใช้สลับกระเป๋าไปคนละใบกับที่ผูกไว้
             */
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->avatar,
                    'wallet_address' => $request->user()->wallet_address,
                    'has_password' => $request->user()->password !== null,
                    'kyc_status' => $request->user()->kyc_status,
                ] : null,
            ],
            /*
             * สถานะด่านยืนยันตัวตนของทุกฟีเจอร์ ส่งไปกับทุกหน้า
             *
             * ⚠️ instanceof User ไม่ใช่ความระแวงเกินเหตุ
             *    หน้าหลังบ้านก็เป็น Inertia และใช้ guard 'admin' — ตรงนั้น $request->user()
             *    คืน AdminUser ไม่ใช่ User ส่งเข้าไปตรงๆ แล้วหลังบ้านพังทุกหน้า
             *    (เกิดจริงตอนเขียน จับได้ด้วยเทสต์ของหน้าตั้งค่าที่มีอยู่แล้ว)
             *
             * ⚠️ อันนี้มีไว้ปิดปุ่มให้ผู้ใช้รู้ตัวก่อนกด ไม่ใช่ตัวกัน
             *    ตัวกันจริงคือ middleware 'kyc:<feature>' ที่ route
             */
            'kyc' => function () use ($request) {
                $user = $request->user();

                return app(KycGate::class)->statusFor($user instanceof User ? $user : null);
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
