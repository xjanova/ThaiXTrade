<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $releases = Cache::remember('admin_app_releases', 300, function () {
            return $this->fetchAllReleases();
        });

        return Inertia::render('Admin/AppReleases/Index', [
            'releases' => $releases,
        ]);
    }

    /**
     * ล้าง cache เพื่อดึง releases ล่าสุด.
     */
    public function refresh()
    {
        Cache::forget('admin_app_releases');
        Cache::forget('app_update_android');

        return back()->with('success', 'Refreshed release data from GitHub.');
    }

    /**
     * ดึง releases ทั้งหมดจาก GitHub API.
     */
    private function fetchAllReleases(): array
    {
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
                ->get("https://api.github.com/repos/{$owner}/{$repo}/releases?per_page=20");

            if (! $response->successful()) {
                Log::warning('GitHub API failed for admin releases', ['status' => $response->status()]);

                return [];
            }

            $releases = [];
            foreach ($response->json() as $release) {
                $apkAsset = collect($release['assets'])->first(function ($asset) {
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
                    'total_assets' => count($release['assets']),
                ];
            }

            return $releases;
        } catch (\Exception $e) {
            Log::error('Failed to fetch GitHub releases for admin', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
