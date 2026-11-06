<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\AppReleaseRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * AppReleaseController — จัดการ App Releases (ดึงจาก GitHub).
 * Developed by Xman Studio.
 */
class AppReleaseController extends Controller
{
    /** ลบได้มากสุดต่อการกดปุ่มหนึ่งครั้ง — เว้น 1 วิต่อตัว + อ่านรายการ ≈ 20 วิ ต่ำกว่า max_execution_time 30 วิ */
    private const PRUNE_BATCH = 15;

    /**
     * แสดง releases ทั้งหมดจาก GitHub.
     */
    public function index(): InertiaResponse
    {
        $result = Cache::remember('admin_app_releases', 300, function () {
            return $this->fetchAllReleases();
        });

        return Inertia::render('Admin/AppReleases/Index', [
            'releases' => $result['releases'],
            'error' => $result['error'],
            'hasToken' => ! empty(config('services.github.token')),
            'activeTag' => SiteSetting::get('app_release', 'active_tag'),
            'activeTags' => [
                'trade' => SiteSetting::get('app_release', 'active_tag'),
                'wallet' => SiteSetting::get('app_release', 'wallet_active_tag'),
                'masternode' => SiteSetting::get('app_release', 'masternode_active_tag'),
            ],
            'releaseKeep' => (int) config('services.github.release_keep', AppReleaseRetention::DEFAULT_KEEP),
            'activeVersions' => [
                'trade' => SiteSetting::get('app_release', 'version'),
                'wallet' => SiteSetting::get('app_release', 'wallet_version'),
                'masternode' => SiteSetting::get('app_release', 'masternode_version'),
            ],
        ]);
    }

    /**
     * ล้าง cache เพื่อดึง releases ล่าสุด.
     */
    public function refresh()
    {
        Cache::forget('admin_app_releases');
        $this->clearReleaseCaches();

        // ดึงใหม่ทันทีเพื่อเช็ค error
        $result = $this->fetchAllReleases();
        Cache::put('admin_app_releases', $result, 300);

        if ($result['error']) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', 'Refreshed — found '.count($result['releases']).' releases.');
    }

    /**
     * ตั้ง release ที่ใช้งานสำหรับ Download page + In-app update.
     */
    public function setActive(Request $request)
    {
        $request->validate([
            'tag' => 'required|string',
            'app' => 'required|string|in:trade,wallet,masternode',
        ]);

        $tag = $request->input('tag');
        $app = $request->input('app');

        // "auto" = เลิกล็อก กลับไปใช้ release ล่าสุดที่ไฟล์ครบ
        //
        // เดิมหน้านี้ล็อกได้อย่างเดียว ปลดไม่ได้ ล็อกครั้งเดียวแล้วค้างตลอดไป
        // เจอจริง 2026-08-23: แอปเทรดค้างที่ v1.1.66 ทั้งที่ปล่อยถึง v1.1.141
        // วอลเล็ตค้าง v1.13.12 มาสเตอร์โหนดค้าง v1.12.0 — เว็บโฆษณารุ่นเก่า
        // ให้ผู้ใช้ทุกคนอยู่เป็นเดือนโดยไม่มีใครรู้
        if ($tag === 'auto') {
            $key = match ($app) {
                'wallet' => 'wallet_active_tag',
                'masternode' => 'masternode_active_tag',
                default => 'active_tag',
            };

            SiteSetting::set('app_release', $key, '');
            $this->clearReleaseCaches();

            return back()->with('success', __('กลับไปใช้รุ่นล่าสุดอัตโนมัติแล้ว'));
        }

        // หา release ที่ตรงกับ tag จาก cache
        $result = Cache::get('admin_app_releases', ['releases' => [], 'error' => null]);
        $release = collect($result['releases'])->firstWhere('tag', $tag);

        if (! $release) {
            return back()->with('error', 'Release not found.');
        }

        // ตรวจว่า release มี asset ที่ตรงกับ app ที่เลือก
        $appLabel = match ($app) {
            'trade' => 'TPIX TRADE',
            'wallet' => 'TPIX Wallet',
            'masternode' => 'Master Node',
        };

        if ($app === 'trade' && ! $release['has_apk']) {
            return back()->with('error', "This release has no APK for {$appLabel}.");
        }
        if ($app === 'wallet' && ! $release['has_wallet_apk']) {
            return back()->with('error', 'This release has no Wallet APK.');
        }
        if ($app === 'masternode' && ! $release['has_exe']) {
            return back()->with('error', "This release has no EXE for {$appLabel}.");
        }

        // บันทึกเฉพาะ app ที่เลือก — ไม่ set ข้ามประเภท
        match ($app) {
            'trade' => (function () use ($tag, $release) {
                SiteSetting::set('app_release', 'active_tag', $tag);
                SiteSetting::set('app_release', 'version', $release['version']);
                SiteSetting::set('app_release', 'name', $release['name']);
                SiteSetting::set('app_release', 'notes', $release['notes']);
                SiteSetting::set('app_release', 'published_at', $release['published_at']);
                SiteSetting::set('app_release', 'apk_size', (string) $release['apk_size']);
            })(),
            'wallet' => (function () use ($tag, $release) {
                SiteSetting::set('app_release', 'wallet_active_tag', $tag);
                SiteSetting::set('app_release', 'wallet_version', $release['version']);
            })(),
            'masternode' => (function () use ($tag, $release) {
                SiteSetting::set('app_release', 'masternode_active_tag', $tag);
                SiteSetting::set('app_release', 'masternode_version', $release['version']);
            })(),
        };

        // ล้าง cache ทั้งหมดเพื่อให้หน้า Download อัปเดตทันที
        $this->clearReleaseCaches();

        return back()->with('success', "Set {$appLabel} active: {$release['name']} (v{$release['version']})");
    }

    /**
     * ลบ release เก่า เก็บแต่ละแอปไว้ N รุ่นล่าสุด (กติกาเดียวกับ app-releases:prune รายวัน).
     * ลบได้รอบละไม่เกิน PRUNE_BATCH ตัว — GitHub ให้เว้น 1 วิระหว่างการลบ
     * ถ้ารอบเดียวลบหลายร้อยตัว request จะหมดเวลากลางทาง ที่เหลือกดซ้ำหรือรอรอบรายวัน.
     */
    public function prune(AppReleaseRetention $retention)
    {
        if (! config('services.github.token')) {
            return back()->with('error', __('ยังไม่ได้ตั้ง GITHUB_TOKEN บนเซิร์ฟเวอร์'));
        }

        $keep = (int) config('services.github.release_keep', AppReleaseRetention::DEFAULT_KEEP);
        if ($keep < 1) {
            return back()->with('error', __('GITHUB_RELEASE_KEEP ต้องเป็นจำนวนเต็มบวก'));
        }

        $plan = $retention->plan($keep);

        if (! $plan['delete']) {
            return $plan['errors']
                ? back()->with('error', implode(' | ', $plan['errors']))
                : back()->with('success', __('ไม่มีรุ่นเก่าให้ลบ (เก็บแอปละ :keep รุ่นล่าสุด)', ['keep' => $keep]));
        }

        $result = $retention->execute($plan, self::PRUNE_BATCH);

        Log::info('app releases pruned by admin', [
            'admin_id' => auth('admin')->id(),
            'keep' => $keep,
            'deleted' => $result['deleted'],
            'remaining' => $result['remaining'],
        ]);

        Cache::forget('admin_app_releases');
        $this->clearReleaseCaches();

        $message = __('ลบรุ่นเก่าแล้ว :deleted รุ่น คืนพื้นที่ :mb MB', [
            'deleted' => $result['deleted'],
            'mb' => number_format($result['bytes'] / 1024 / 1024, 1),
        ]);
        if ($result['remaining'] > 0) {
            $message .= ' · '.__('เหลืออีก :n รุ่น กดซ้ำได้ หรือรอรอบลบอัตโนมัติ 04:30', ['n' => $result['remaining']]);
        }

        return $result['failed'] > 0
            ? back()->with('error', $message.' · '.($result['stopped'] ?? __('ลบบางรุ่นไม่สำเร็จ')))
            : back()->with('success', $message);
    }

    /**
     * ล้าง cache ที่เกี่ยวข้องกับ releases ทั้งหมด.
     */
    private function clearReleaseCaches(): void
    {
        Cache::forget('app_update_android');
        Cache::forget('chain_releases');
        Cache::forget('chain_s3_url_wallet');
        Cache::forget('chain_s3_url_masternode');
        Cache::forget('apk_s3_url');

        $walletTag = (string) SiteSetting::get('app_release', 'wallet_active_tag');
        $masternodeTag = (string) SiteSetting::get('app_release', 'masternode_active_tag');

        // ตัวคั่น '|' ต้องตรงกับ AppUpdateController::chainCacheKey() เป๊ะ ๆ
        // เดิมที่นี่ต่อสตริงเฉย ๆ ไม่มีตัวคั่น = คนละคีย์กับที่ API เขียนไว้
        // ปุ่มรีเฟรชจึงไม่เคยล้างแคชจริงเลยสักครั้ง หน้าเว็บค้างรุ่นเก่าแบบหาสาเหตุไม่เจอ
        Cache::forget('chain_releases_'.md5($walletTag.'|'.$masternodeTag));

        // ฟีดของ electron-updater มีคีย์ของตัวเอง ลืมข้อนี้แล้วโปรแกรมมาสเตอร์โหนด
        // จะยังเห็นรุ่นเก่าไปอีกครึ่งชั่วโมงทั้งที่หน้าเว็บอัปเดตแล้ว
        Cache::forget('masternode_feed_assets_'.md5($masternodeTag));
        Cache::forget('masternode_feed_assets_'.md5(''));
    }

    /**
     * ดึง releases ทั้งหมดจาก GitHub API (ทั้ง ThaiXTrade + TPIX-Coin repos).
     */
    private function fetchAllReleases(): array
    {
        $owner = config('services.github.owner', 'xjanova');
        $token = config('services.github.token');

        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'TPIX-TRADE-Server',
        ];

        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        // repo เดียวกับที่ AppReleaseRetention ดูแล — มาสเตอร์โหนดแยก repo ออกมาแล้ว
        // เดิมดึงแค่ 2 repo หน้านี้จึงเห็นแค่ตัวที่ก๊อปไว้ใน repo เชน ไม่เห็นรุ่นจริงของ TPIX-Masternode
        $repos = [
            ['repo' => config('services.github.repo', 'ThaiXTrade'), 'source' => 'trade'],
            ['repo' => config('services.github.chain_repo', 'TPIX-Coin'), 'source' => 'chain'],
            ['repo' => config('services.github.masternode_repo', 'TPIX-Masternode'), 'source' => 'masternode'],
        ];

        $allReleases = [];
        $errors = [];

        foreach ($repos as $repoConfig) {
            try {
                $repo = $repoConfig['repo'];
                $source = $repoConfig['source'];

                $response = Http::withHeaders($headers)
                    ->timeout(15)
                    ->get("https://api.github.com/repos/{$owner}/{$repo}/releases?per_page=50");

                if (! $response->successful()) {
                    $errors[] = "{$repo}: HTTP {$response->status()}";

                    continue;
                }

                $data = $response->json();
                if (! is_array($data)) {
                    continue;
                }

                foreach ($data as $release) {
                    $assets = collect($release['assets'] ?? []);

                    $apkAsset = $assets->first(fn ($a) => str_ends_with(strtolower($a['name']), '.apk'));
                    $exeAsset = $assets->first(fn ($a) => str_ends_with(strtolower($a['name']), '.exe'));
                    $walletApk = $assets->first(fn ($a) => str_contains(strtolower($a['name']), 'wallet') && str_ends_with(strtolower($a['name']), '.apk'));

                    preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $matches);
                    $version = $matches[1] ?? $release['tag_name'];

                    // ตรวจประเภท release
                    $type = 'web';
                    if ($walletApk) {
                        $type = 'wallet';
                    } elseif ($apkAsset) {
                        $type = 'mobile';
                    } elseif ($exeAsset) {
                        $type = 'desktop';
                    } elseif ($source === 'chain') {
                        $type = 'chain';
                    }

                    $allReleases[] = [
                        'id' => $release['id'],
                        'tag' => $release['tag_name'],
                        'version' => $version,
                        'name' => $release['name'] ?: "v{$version}",
                        'notes' => $release['body'] ?? '',
                        'published_at' => $release['published_at'],
                        'is_draft' => $release['draft'],
                        'is_prerelease' => $release['prerelease'],
                        'is_mobile' => $type === 'mobile',
                        'type' => $type,
                        'source' => $source,
                        'repo' => $repo,
                        'has_apk' => $apkAsset !== null,
                        'has_wallet_apk' => $walletApk !== null,
                        'has_exe' => $exeAsset !== null,
                        'apk_name' => $apkAsset['name'] ?? null,
                        'wallet_apk_name' => $walletApk['name'] ?? null,
                        'exe_name' => $exeAsset['name'] ?? null,
                        'apk_size' => $apkAsset ? round($apkAsset['size'] / 1024 / 1024, 1) : null,
                        'apk_downloads' => $apkAsset['download_count'] ?? 0,
                        'total_assets' => count($release['assets'] ?? []),
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch releases from {$repoConfig['repo']}", ['error' => $e->getMessage()]);
                $errors[] = "{$repoConfig['repo']}: {$e->getMessage()}";
            }
        }

        // เรียงตามวันที่ publish ล่าสุดก่อน
        usort($allReleases, fn ($a, $b) => strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));

        $error = ! empty($errors) && empty($allReleases)
            ? implode(' | ', $errors)
            : null;

        return ['releases' => $allReleases, 'error' => $error];
    }
}
