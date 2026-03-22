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

        if (! $release || ! $release['has_apk']) {
            return back()->with('error', 'Release not found or has no APK.');
        }

        // บันทึกลง SiteSetting
        SiteSetting::set('app_release', 'active_tag', $tag);
        SiteSetting::set('app_release', 'version', $release['version']);
        SiteSetting::set('app_release', 'name', $release['name']);
        SiteSetting::set('app_release', 'notes', $release['notes']);
        SiteSetting::set('app_release', 'published_at', $release['published_at']);
        SiteSetting::set('app_release', 'apk_size', (string) $release['apk_size']);

        // ล้าง cache ของ API เพื่อให้ใช้ตัวใหม่
        Cache::forget('app_update_android');

        return back()->with('success', "Set active release: {$release['name']} (v{$release['version']})");
    }

    /**
     * ดึง releases ทั้งหมดจาก GitHub API.
     */
    private function fetchAllReleases(): array
    {
        $empty = ['releases' => [], 'error' => null];

        try {
            $owner = config('services.github.owner', 'xjanova');
            $repo = config('services.github.repo', 'ThaiXTrade');
            $token = config('services.github.token');

            $headers = [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'TPIX-TRADE-Server',
            ];

            if ($token) {
                $headers['Authorization'] = "Bearer {$token}";
            }

            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->get("https://api.github.com/repos/{$owner}/{$repo}/releases?per_page=30");

            if (! $response->successful()) {
                $status = $response->status();
                Log::warning('GitHub API failed for admin releases', ['status' => $status]);

                $errorMsg = match ($status) {
                    401 => 'GitHub API: Unauthorized — GITHUB_TOKEN ไม่ถูกต้องหรือหมดอายุ',
                    403 => 'GitHub API: Forbidden — Rate limit exceeded หรือ token ไม่มีสิทธิ์',
                    404 => "GitHub API: Repo {$owner}/{$repo} ไม่พบ — ตรวจสอบ GITHUB_OWNER / GITHUB_REPO",
                    default => "GitHub API error (HTTP {$status})",
                };

                if (! $token) {
                    $errorMsg .= ' | ⚠️ GITHUB_TOKEN ไม่ได้ตั้งค่าใน .env — จำเป็นสำหรับ private repo';
                }

                return ['releases' => [], 'error' => $errorMsg];
            }

            $data = $response->json();

            if (! is_array($data)) {
                return ['releases' => [], 'error' => 'GitHub API returned invalid data'];
            }

            $releases = [];
            foreach ($data as $release) {
                $apkAsset = collect($release['assets'] ?? [])->first(function ($asset) {
                    return str_ends_with(strtolower($asset['name']), '.apk');
                });

                preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $matches);
                $version = $matches[1] ?? $release['tag_name'];

                $releases[] = [
                    'id' => $release['id'],
                    'tag' => $release['tag_name'],
                    'version' => $version,
                    'name' => $release['name'] ?: "v{$version}",
                    'notes' => $release['body'] ?? '',
                    'published_at' => $release['published_at'],
                    'is_draft' => $release['draft'],
                    'is_prerelease' => $release['prerelease'],
                    'is_mobile' => str_contains($release['tag_name'], 'mobile'),
                    'has_apk' => $apkAsset !== null,
                    'apk_name' => $apkAsset['name'] ?? null,
                    'apk_size' => $apkAsset ? round($apkAsset['size'] / 1024 / 1024, 1) : null,
                    'apk_downloads' => $apkAsset['download_count'] ?? 0,
                    'total_assets' => count($release['assets'] ?? []),
                ];
            }

            return ['releases' => $releases, 'error' => null];
        } catch (\Exception $e) {
            Log::error('Failed to fetch GitHub releases for admin', ['error' => $e->getMessage()]);

            return ['releases' => [], 'error' => 'Connection failed: '.$e->getMessage()];
        }
    }
}
