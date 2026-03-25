<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
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
        ]);
    }

    /**
     * ล้าง cache เพื่อดึง releases ล่าสุด.
     */
    public function refresh()
    {
        Cache::forget('admin_app_releases');
        Cache::forget('app_update_android');
        Cache::forget('chain_releases');
        Cache::forget('chain_s3_url_wallet');
        Cache::forget('chain_s3_url_masternode');
        Cache::forget('apk_s3_url');

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
        $request->validate(['tag' => 'required|string']);

        $tag = $request->input('tag');

        // หา release ที่ตรงกับ tag จาก cache
        $result = Cache::get('admin_app_releases', ['releases' => [], 'error' => null]);
        $release = collect($result['releases'])->firstWhere('tag', $tag);

        if (! $release || (! $release['has_apk'] && ! $release['has_wallet_apk'] && ! $release['has_exe'])) {
            return back()->with('error', 'Release not found or has no downloadable assets.');
        }

        // บันทึกลง SiteSetting
        SiteSetting::set('app_release', 'active_tag', $tag);
        SiteSetting::set('app_release', 'version', $release['version']);
        SiteSetting::set('app_release', 'name', $release['name']);
        SiteSetting::set('app_release', 'notes', $release['notes']);
        SiteSetting::set('app_release', 'published_at', $release['published_at']);
        SiteSetting::set('app_release', 'apk_size', (string) $release['apk_size']);

        // ล้าง cache ทั้งหมดเพื่อให้หน้า Download อัปเดตทันที
        Cache::forget('app_update_android');
        Cache::forget('chain_releases');
        Cache::forget('chain_s3_url_wallet');
        Cache::forget('chain_s3_url_masternode');
        Cache::forget('apk_s3_url');

        return back()->with('success', "Set active release: {$release['name']} (v{$release['version']})");
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

        // ดึงจากทั้ง 2 repos
        $repos = [
            ['repo' => config('services.github.repo', 'ThaiXTrade'), 'source' => 'trade'],
            ['repo' => 'TPIX-Coin', 'source' => 'chain'],
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
