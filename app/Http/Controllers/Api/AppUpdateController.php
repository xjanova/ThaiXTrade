<?php

/**
 * App Update Controller
 * ตรวจสอบเวอร์ชันและให้ดาวน์โหลด APK ผ่าน API ของเราเอง
 * ไม่ต้องเปิด GitHub repo เป็น public
 *
 * Developed by Xman Studio.
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppUpdateController extends Controller
{
    private string $githubOwner;
    private string $githubRepo;
    private ?string $githubToken;

    public function __construct()
    {
        $this->githubOwner = config('services.github.owner', 'xjanova');
        $this->githubRepo = config('services.github.repo', 'ThaiXTrade');
        $this->githubToken = config('services.github.token');
    }

    /**
     * Check for latest app version
     * ตรวจสอบเวอร์ชันล่าสุดของแอป
     *
     * GET /api/v1/app/update-check
     */
    public function check(Request $request): JsonResponse
    {
        $currentVersion = $request->query('version', '0.0.0');
        $platform = $request->query('platform', 'android');

        // Cache 5 minutes / แคช 5 นาที
        $cacheKey = "app_update_{$platform}";
        $releaseInfo = Cache::remember($cacheKey, 300, function () {
            return $this->fetchLatestRelease();
        });

        if (! $releaseInfo) {
            return response()->json([
                'success' => true,
                'data' => [
                    'available' => false,
                    'current_version' => $currentVersion,
                ],
            ]);
        }

        $isNewer = version_compare($releaseInfo['version'], $currentVersion, '>');
        $currentMajor = (int) explode('.', $currentVersion)[0];
        $latestMajor = (int) explode('.', $releaseInfo['version'])[0];

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $isNewer,
                'latest_version' => $releaseInfo['version'],
                'current_version' => $currentVersion,
                'release_name' => $releaseInfo['name'],
                'release_notes' => $releaseInfo['notes'],
                'download_url' => $isNewer ? url('/api/v1/app/download') : null,
                'published_at' => $releaseInfo['published_at'],
                'mandatory' => $latestMajor > $currentMajor,
                'file_size' => $releaseInfo['file_size'],
            ],
        ]);
    }

    /**
     * Download latest APK
     * ดาวน์โหลด APK เวอร์ชันล่าสุด (proxy ผ่าน server)
     *
     * GET /api/v1/app/download
     */
    public function download(): StreamedResponse|JsonResponse
    {
        $releaseInfo = Cache::remember('app_update_android', 300, function () {
            return $this->fetchLatestRelease();
        });

        if (! $releaseInfo || ! $releaseInfo['download_url']) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NO_UPDATE', 'message' => 'No APK available'],
            ], 404);
        }

        $githubUrl = $releaseInfo['download_url'];
        $fileName = "TPIX-TRADE-v{$releaseInfo['version']}.apk";

        // Stream the APK from GitHub through our server
        // สตรีม APK จาก GitHub ผ่าน server ของเรา
        return response()->streamDownload(function () use ($githubUrl) {
            $headers = [
                'Accept' => 'application/octet-stream',
                'User-Agent' => 'TPIX-TRADE-Server',
            ];

            if ($this->githubToken) {
                $headers['Authorization'] = "Bearer {$this->githubToken}";
            }

            $response = Http::withHeaders($headers)
                ->withOptions(['stream' => true])
                ->get($githubUrl);

            if ($response->successful()) {
                echo $response->body();
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Get release info (for web Download page)
     * ข้อมูล release สำหรับหน้าดาวน์โหลดบนเว็บ
     *
     * GET /api/v1/app/latest
     */
    public function latest(): JsonResponse
    {
        $releaseInfo = Cache::remember('app_update_android', 300, function () {
            return $this->fetchLatestRelease();
        });

        if (! $releaseInfo) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NO_RELEASE', 'message' => 'No release found'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'version' => $releaseInfo['version'],
                'name' => $releaseInfo['name'],
                'notes' => $releaseInfo['notes'],
                'download_url' => url('/api/v1/app/download'),
                'published_at' => $releaseInfo['published_at'],
                'file_size' => $releaseInfo['file_size'],
                'file_name' => "TPIX-TRADE-v{$releaseInfo['version']}.apk",
            ],
        ]);
    }

    /**
     * Fetch latest mobile release from GitHub
     * ดึงข้อมูล release ล่าสุดจาก GitHub
     */
    private function fetchLatestRelease(): ?array
    {
        try {
            $headers = [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'TPIX-TRADE-Server',
            ];

            if ($this->githubToken) {
                $headers['Authorization'] = "Bearer {$this->githubToken}";
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get("https://api.github.com/repos/{$this->githubOwner}/{$this->githubRepo}/releases");

            if (! $response->successful()) {
                Log::warning('GitHub API failed', ['status' => $response->status()]);
                return null;
            }

            $releases = $response->json();

            // Find latest mobile release with APK
            // หา release มือถือล่าสุดที่มี APK
            foreach ($releases as $release) {
                if ($release['draft'] || $release['prerelease']) {
                    continue;
                }

                if (! str_contains($release['tag_name'], 'mobile')) {
                    continue;
                }

                $apkAsset = collect($release['assets'])->first(function ($asset) {
                    return str_ends_with(strtolower($asset['name']), '.apk');
                });

                if (! $apkAsset) {
                    continue;
                }

                // Extract version from tag (e.g., "mobile-v1.0.145" → "1.0.145")
                preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $matches);
                $version = $matches[1] ?? null;

                if (! $version) {
                    continue;
                }

                return [
                    'version' => $version,
                    'name' => $release['name'] ?: "v{$version}",
                    'notes' => $release['body'] ?? '',
                    'download_url' => $apkAsset['browser_download_url'],
                    'published_at' => $release['published_at'],
                    'file_size' => $apkAsset['size'],
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('GitHub release check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
