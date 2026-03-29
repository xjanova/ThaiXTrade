<?php

/**
 * App Update Controller.
 * ตรวจสอบเวอร์ชันและให้ดาวน์โหลด APK ผ่าน API ของเราเอง.
 * ไม่ต้องเปิด GitHub repo เป็น public.
 *
 * Developed by Xman Studio.
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Check for latest app version.
     * ตรวจสอบเวอร์ชันล่าสุดของแอป.
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
     * Download latest APK.
     * ดาวน์โหลด APK เวอร์ชันล่าสุด (proxy ผ่าน server).
     *
     * GET /api/v1/app/download
     */
    public function download(): JsonResponse|RedirectResponse
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
        $fileSize = $releaseInfo['file_size'] ?? null;

        // Step 1: ดึง S3 redirect URL จาก GitHub API (แคช 1 ชั่วโมง)
        $s3Url = Cache::remember('apk_s3_url', 3600, function () use ($githubUrl) {
            $headers = [
                'Accept' => 'application/octet-stream',
                'User-Agent' => 'TPIX-TRADE-Server',
            ];

            if ($this->githubToken) {
                $headers['Authorization'] = "Bearer {$this->githubToken}";
            }

            $ch = curl_init($githubUrl);
            $curlHeaders = [];
            foreach ($headers as $k => $v) {
                $curlHeaders[] = "{$k}: {$v}";
            }

            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOBODY => true,
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            return $redirectUrl ?: null;
        });

        if (! $s3Url) {
            Log::warning('APK download: failed to get S3 URL', ['github_url' => $githubUrl]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'DOWNLOAD_FAILED', 'message' => 'Unable to prepare download'],
            ], 502);
        }

        // Step 2: นับสถิติดาวน์โหลด
        $this->incrementDownloadCount('trade_apk');

        // Step 3: Redirect ไป S3 โดยตรง (เร็วมาก ไม่ผ่าน server)
        return redirect()->away($s3Url);
    }

    /**
     * Get release info (for web Download page).
     * ข้อมูล release สำหรับหน้าดาวน์โหลดบนเว็บ.
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
     * Fetch latest mobile release from GitHub.
     * ดึงข้อมูล release ล่าสุดจาก GitHub.
     */
    /**
     * CI webhook — auto-set active release after build.
     * POST /api/v1/app/notify-release?secret=xxx&tag=v1.0.262
     */
    public function notifyRelease(Request $request): JsonResponse
    {
        $secret = $request->query('secret');
        $expectedSecret = config('services.github.deploy_secret', '');

        if (! $expectedSecret || ! hash_equals($expectedSecret, (string) $secret)) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $tag = $request->query('tag', '');
        if ($tag) {
            SiteSetting::set('app_release', 'active_tag', $tag);
        }

        // เคลียร์แคช update เพื่อให้ดึง release ใหม่ทันที
        Cache::forget('app_update_android');
        Cache::forget('apk_s3_url');

        Log::info('Release notified via CI', ['tag' => $tag]);

        return response()->json(['success' => true, 'data' => ['active_tag' => $tag]]);
    }

    private function fetchLatestRelease(): ?array
    {
        // เช็ค admin-selected release ก่อน
        $activeTag = SiteSetting::get('app_release', 'active_tag');

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
                ->get("https://api.github.com/repos/{$this->githubOwner}/{$this->githubRepo}/releases?per_page=100");

            if (! $response->successful()) {
                Log::warning('GitHub API failed', ['status' => $response->status()]);

                return null;
            }

            $releases = $response->json();

            // Pass 1: ถ้ามี active tag → หาตัวที่ตรง
            if ($activeTag) {
                foreach ($releases as $release) {
                    if ($release['draft'] || $release['prerelease']) {
                        continue;
                    }
                    if ($release['tag_name'] !== $activeTag) {
                        continue;
                    }
                    $result = $this->parseRelease($release);
                    if ($result) {
                        return $result;
                    }
                }
                // active_tag ไม่ตรงกับ release ใดเลย → fallback ไป latest
                Log::info('Active tag not found, falling back to latest', ['active_tag' => $activeTag]);
            }

            // Pass 2: ใช้ release ล่าสุดที่มี APK
            foreach ($releases as $release) {
                if ($release['draft'] || $release['prerelease']) {
                    continue;
                }
                $result = $this->parseRelease($release);
                if ($result) {
                    return $result;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('GitHub release check failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Parse a GitHub release into our format.
     * แปลง release จาก GitHub เป็นรูปแบบของเรา
     */
    private function parseRelease(array $release): ?array
    {
        $apkAsset = collect($release['assets'])->first(function ($asset) {
            return str_ends_with(strtolower($asset['name']), '.apk');
        });

        if (! $apkAsset) {
            return null;
        }

        preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $matches);
        $version = $matches[1] ?? null;

        if (! $version) {
            return null;
        }

        return [
            'version' => $version,
            'name' => $release['name'] ?: "v{$version}",
            'notes' => $release['body'] ?? '',
            'download_url' => $apkAsset['url'],
            'published_at' => $release['published_at'],
            'file_size' => $apkAsset['size'],
        ];
    }

    // =====================================================================
    //  TPIX-Coin repo (Wallet + Master Node)
    // =====================================================================

    /**
     * Get latest releases from TPIX-Coin repo.
     * ดึงข้อมูล releases จาก TPIX-Coin (wallet APK + masternode EXE).
     *
     * GET /api/v1/app/chain-latest
     */
    public function chainLatest(): JsonResponse
    {
        // ดึง active tags ที่ admin เลือก
        $walletTag = SiteSetting::get('app_release', 'wallet_active_tag');
        $masternodeTag = SiteSetting::get('app_release', 'masternode_active_tag');

        $cacheKey = 'chain_releases_'.md5($walletTag.$masternodeTag);
        $data = Cache::remember($cacheKey, 300, function () use ($walletTag, $masternodeTag) {
            return $this->fetchChainReleases($walletTag, $masternodeTag);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Download asset from TPIX-Coin repo.
     * ดาวน์โหลดไฟล์จาก TPIX-Coin (proxy ผ่าน server).
     *
     * GET /api/v1/app/chain-download?type=wallet|masternode
     */
    public function chainDownload(Request $request): JsonResponse|RedirectResponse
    {
        $type = $request->query('type', 'wallet');
        $data = Cache::remember('chain_releases', 300, function () {
            return $this->fetchChainReleases();
        });

        $asset = $type === 'wallet' ? ($data['wallet'] ?? null) : ($data['masternode'] ?? null);

        if (! $asset || ! $asset['download_url']) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NO_ASSET', 'message' => "No {$type} download available"],
            ], 404);
        }

        $cacheKey = "chain_s3_url_{$type}";
        $s3Url = Cache::remember($cacheKey, 3600, function () use ($asset) {
            $headers = [
                'Accept: application/octet-stream',
                'User-Agent: TPIX-TRADE-Server',
            ];

            if ($this->githubToken) {
                $headers[] = "Authorization: Bearer {$this->githubToken}";
            }

            $ch = curl_init($asset['download_url']);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOBODY => true,
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            curl_exec($ch);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            return $redirectUrl ?: null;
        });

        if (! $s3Url) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DOWNLOAD_FAILED', 'message' => 'Unable to prepare download'],
            ], 502);
        }

        // นับสถิติดาวน์โหลด
        $this->incrementDownloadCount($type === 'wallet' ? 'wallet_apk' : 'masternode_exe');

        return redirect()->away($s3Url);
    }

    /**
     * Fetch releases from TPIX-Coin repo.
     * ดึง releases จาก TPIX-Coin (wallet + masternode).
     */
    /**
     * สถิติดาวน์โหลดทั้งหมด.
     *
     * GET /api/v1/app/download-stats
     */
    public function downloadStats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'trade_apk' => (int) SiteSetting::get('downloads', 'trade_apk', '0'),
                'wallet_apk' => (int) SiteSetting::get('downloads', 'wallet_apk', '0'),
                'masternode_exe' => (int) SiteSetting::get('downloads', 'masternode_exe', '0'),
                'total' => (int) SiteSetting::get('downloads', 'trade_apk', '0')
                    + (int) SiteSetting::get('downloads', 'wallet_apk', '0')
                    + (int) SiteSetting::get('downloads', 'masternode_exe', '0'),
            ],
        ]);
    }

    /**
     * นับจำนวนดาวน์โหลด.
     */
    private function incrementDownloadCount(string $type): void
    {
        $current = (int) SiteSetting::get('downloads', $type, '0');
        SiteSetting::set('downloads', $type, (string) ($current + 1));
    }

    private function fetchChainReleases(?string $walletTag = null, ?string $masternodeTag = null): array
    {
        $result = ['wallet' => null, 'masternode' => null, 'tag' => null];

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
                ->get("https://api.github.com/repos/{$this->githubOwner}/TPIX-Coin/releases?per_page=10");

            if (! $response->successful()) {
                return $result;
            }

            foreach ($response->json() as $release) {
                if ($release['draft'] || $release['prerelease']) {
                    continue;
                }

                $assets = collect($release['assets'] ?? []);
                preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $matches);
                $version = $matches[1] ?? $release['tag_name'];

                $walletApk = $assets->first(fn ($a) => str_contains(strtolower($a['name']), 'wallet') && str_ends_with(strtolower($a['name']), '.apk'));
                $masternodeExe = $assets->first(fn ($a) => str_ends_with(strtolower($a['name']), '.exe'));

                $result['tag'] = $release['tag_name'];
                $result['version'] = $version;
                $result['name'] = $release['name'] ?: "v{$version}";
                $result['published_at'] = $release['published_at'];
                $result['notes'] = $release['body'] ?? '';

                // เก็บ wallet — ถ้า admin เลือก tag → ใช้เฉพาะ tag นั้น, ถ้าไม่ → ใช้ล่าสุด
                $walletMatch = ! $walletTag || $release['tag_name'] === $walletTag;
                if ($walletApk && ! $result['wallet'] && $walletMatch) {
                    // parse version จากชื่อไฟล์ เช่น TPIX-Wallet-v1.1.1.apk → 1.1.1
                    preg_match('/v?(\d+\.\d+\.\d+)/', $walletApk['name'], $fileVer);
                    $walletVersion = $fileVer[1] ?? $version;

                    $result['wallet'] = [
                        'file_name' => $walletApk['name'],
                        'file_size' => $walletApk['size'],
                        'download_url' => $walletApk['url'],
                        'downloads' => $walletApk['download_count'],
                        'version' => $walletVersion,
                        'tag' => $release['tag_name'],
                        'published_at' => $release['published_at'],
                    ];
                }

                // เก็บ masternode — ถ้า admin เลือก tag → ใช้เฉพาะ tag นั้น, ถ้าไม่ → ใช้ล่าสุด
                $masternodeMatch = ! $masternodeTag || $release['tag_name'] === $masternodeTag;
                if ($masternodeExe && ! $result['masternode'] && $masternodeMatch) {
                    // parse version จากชื่อไฟล์ เช่น TPIX-Master-Node-1.0.0.exe → 1.0.0
                    preg_match('/(\d+\.\d+\.\d+)/', $masternodeExe['name'], $fileVer);
                    $mnVersion = $fileVer[1] ?? $version;

                    $result['masternode'] = [
                        'file_name' => $masternodeExe['name'],
                        'file_size' => $masternodeExe['size'],
                        'download_url' => $masternodeExe['url'],
                        'downloads' => $masternodeExe['download_count'],
                        'version' => $mnVersion,
                        'tag' => $release['tag_name'],
                        'published_at' => $release['published_at'],
                    ];
                }

                // หยุดเมื่อเจอทั้ง wallet + masternode แล้ว
                if ($result['wallet'] && $result['masternode']) {
                    break;
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('TPIX-Coin release fetch failed', ['error' => $e->getMessage()]);

            return $result;
        }
    }
}
