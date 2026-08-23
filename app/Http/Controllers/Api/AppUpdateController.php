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

    /** repo เชน (สาธารณะ) — ที่มาของ TPIX Wallet APK */
    private string $chainRepo;

    /** repo โปรแกรมมาสเตอร์โหนด (ไพรเวท) — แยกออกจาก repo เชนแล้ว */
    private string $masternodeRepo;

    private ?string $githubToken;

    public function __construct()
    {
        $this->githubOwner = config('services.github.owner', 'xjanova');
        $this->githubRepo = config('services.github.repo', 'ThaiXTrade');
        $this->chainRepo = config('services.github.chain_repo', 'TPIX-Coin');
        $this->masternodeRepo = config('services.github.masternode_repo', 'TPIX-Masternode');
        $this->githubToken = config('services.github.token');
    }

    /**
     * ดึง mobile release (TRADE APK) พร้อม cache แบบกัน negative-caching.
     * สำเร็จ → cache 5 นาที, ล้มเหลว (null) → cache แค่ 60 วิ (sentinel 'none')
     * เพื่อไม่ให้ผลว่างจาก GitHub สะดุดค้างนาน.
     */
    private function cachedLatestRelease(): ?array
    {
        $key = 'app_update_android';
        $cached = Cache::get($key);

        if ($cached === 'none') {
            return null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        $fetched = $this->fetchLatestRelease();
        Cache::put($key, $fetched ?: 'none', $fetched ? 300 : 60);

        return $fetched;
    }

    /**
     * คีย์ cache สำหรับ chain releases — ผูกกับ active tag ปัจจุบัน.
     * ใช้ร่วมกันทั้ง chainLatest / chainDownload / notifyRelease
     * เพื่อไม่ให้ key หลุดกัน (เดิม chainDownload ใช้ 'chain_releases' เปล่า ๆ คนละ key).
     */
    private function chainCacheKey(?string $walletTag = null, ?string $masternodeTag = null): string
    {
        $walletTag ??= SiteSetting::get('app_release', 'wallet_active_tag');
        $masternodeTag ??= SiteSetting::get('app_release', 'masternode_active_tag');

        return 'chain_releases_'.md5((string) $walletTag.'|'.(string) $masternodeTag);
    }

    /**
     * ดึง releases จาก TPIX-Coin (wallet + masternode) พร้อม cache แบบกัน negative-caching.
     * สำเร็จ (มี asset อย่างน้อย 1) → cache 30 นาที, ล้มเหลว/ว่าง → cache แค่ 60 วิ.
     */
    private function cachedChainReleases(): array
    {
        $walletTag = SiteSetting::get('app_release', 'wallet_active_tag');
        $masternodeTag = SiteSetting::get('app_release', 'masternode_active_tag');
        $key = $this->chainCacheKey($walletTag, $masternodeTag);

        $cached = Cache::get($key);
        if (is_array($cached) && (! empty($cached['wallet']) || ! empty($cached['masternode']))) {
            return $cached;
        }

        $data = $this->fetchChainReleases($walletTag, $masternodeTag);
        $ttl = (! empty($data['wallet']) || ! empty($data['masternode'])) ? 1800 : 60;
        Cache::put($key, $data, $ttl);

        return $data;
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

        $releaseInfo = $this->cachedLatestRelease();

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

                // มี tag ใหม่กว่าที่ผู้ใช้ถืออยู่ แต่ไฟล์ APK ยังไม่ถูกแนบ = CI ยังบิลด์ไม่เสร็จ
                'pending_build' => ! empty($releaseInfo['newest_version'])
                    && version_compare($releaseInfo['newest_version'], $releaseInfo['version'], '>')
                    && version_compare($releaseInfo['newest_version'], $currentVersion, '>'),
                'newest_version' => $releaseInfo['newest_version'] ?? null,
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
        $releaseInfo = $this->cachedLatestRelease();

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
        $releaseInfo = $this->cachedLatestRelease();

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
     * POST /api/v1/app/notify-release?secret=xxx&tag=v1.0.262.
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
        Cache::forget($this->chainCacheKey());
        Cache::forget('chain_s3_url_wallet');
        Cache::forget('chain_s3_url_masternode');

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
            //
            // ระหว่างทางเก็บเลขรุ่นของ tag ใหม่สุดไว้ด้วย แม้ตัวนั้นจะยังไม่มี APK แนบ
            // (CI ยังบิลด์ไม่เสร็จ) เพื่อให้แอปบอกผู้ใช้ได้ว่า "รุ่นใหม่กำลังบิลด์อยู่"
            // แทนที่จะบอกว่าเป็นรุ่นล่าสุดแล้วทั้งที่ไม่ใช่
            $newestVersion = null;

            foreach ($releases as $release) {
                if ($release['draft'] || $release['prerelease']) {
                    continue;
                }

                if ($newestVersion === null) {
                    preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $newest);
                    $newestVersion = $newest[1] ?? null;
                }

                $result = $this->parseRelease($release);

                if ($result) {
                    $result['newest_version'] = $newestVersion;

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
     * แปลง release จาก GitHub เป็นรูปแบบของเรา.
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
        $data = $this->cachedChainReleases();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * เช็กอัปเดตของ TPIX Wallet — รูปแบบผลลัพธ์เดียวกับ /app/update-check
     *
     * มีไว้เพราะ /app/chain-latest บอกแค่ว่า release ล่าสุดคืออะไร ไม่ได้บอกว่า
     * "ใหม่กว่าที่เครื่องนี้ถืออยู่ไหม" ซึ่งเป็นสิ่งที่แอปต้องรู้ และการเทียบเวอร์ชัน
     * ควรอยู่ฝั่งเซิร์ฟเวอร์ที่แก้ทีเดียวมีผลทุกเครื่อง ไม่ต้องรอผู้ใช้อัปเดตแอปก่อน
     *
     * GET /api/v1/app/wallet-update-check?version=1.2.3
     */
    public function walletUpdateCheck(Request $request): JsonResponse
    {
        $currentVersion = $request->query('version', '0.0.0');
        $wallet = $this->cachedChainReleases()['wallet'] ?? null;

        if (! $wallet) {
            return response()->json([
                'success' => true,
                'data' => [
                    'available' => false,
                    'current_version' => $currentVersion,
                ],
            ]);
        }

        $isNewer = version_compare($wallet['version'], $currentVersion, '>');
        $currentMajor = (int) explode('.', $currentVersion)[0];
        $latestMajor = (int) explode('.', $wallet['version'])[0];

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $isNewer,
                'latest_version' => $wallet['version'],
                'current_version' => $currentVersion,
                'release_name' => $wallet['name'] ?? null,
                'release_notes' => $wallet['notes'] ?? null,
                'download_url' => $isNewer ? url('/api/v1/app/chain-download?type=wallet') : null,
                'published_at' => $wallet['published_at'],
                'mandatory' => $latestMajor > $currentMajor,
                'file_size' => $wallet['file_size'],
            ],
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
        $data = $this->cachedChainReleases();

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

        // wallet ยังอยู่ repo เชน (สาธารณะ) — masternode ย้ายไป repo ไพรเวทของตัวเองแล้ว
        // จึงต้องยิงคนละที่ และตัวไหนล่มอีกตัวต้องยังขึ้นได้ ไม่ใช่ดับทั้งหน้าดาวน์โหลด
        $wallet = $this->pickReleaseAsset(
            $this->chainRepo,
            $walletTag,
            fn (array $a) => str_contains(strtolower($a['name']), 'wallet')
                && str_ends_with(strtolower($a['name']), '.apk')
        );

        $isInstaller = fn (array $a) => str_ends_with(strtolower($a['name']), '.exe');

        $masternode = $this->pickReleaseAsset($this->masternodeRepo, $masternodeTag, $isInstaller);

        // ช่วงเปลี่ยนผ่าน — repo ใหม่ยังไม่มี release ให้ถอยไปหยิบจาก repo เชนไปก่อน
        // ไม่งั้นการ์ดดาวน์โหลดบนหน้าเว็บจะว่างทันทีที่ deploy ทั้งที่ยังไม่มีอะไรเสีย
        // ถอดบล็อกนี้ออกได้เมื่อ TPIX-Masternode ปล่อยรุ่นแรกเรียบร้อยแล้ว
        if (! $masternode && $this->masternodeRepo !== $this->chainRepo) {
            $masternode = $this->pickReleaseAsset($this->chainRepo, $masternodeTag, $isInstaller);
        }

        if ($wallet) {
            $result['wallet'] = $wallet['asset'];
        }

        if ($masternode) {
            $result['masternode'] = $masternode['asset'];
        }

        // ฟิลด์ระดับบนคงไว้เพื่อความเข้ากันได้กับของเดิม — ยึด masternode ก่อน ไม่มีค่อยใช้ wallet
        $headline = $masternode ?: $wallet;

        if ($headline) {
            $result['tag'] = $headline['tag'];
            $result['version'] = $headline['version'];
            $result['name'] = $headline['name'];
            $result['published_at'] = $headline['published_at'];
            $result['notes'] = $headline['notes'];
        }

        return $result;
    }

    /**
     * ดึง release ของ repo หนึ่งตัวแล้วคัดไฟล์แนบที่ต้องการ (ไล่จากใหม่ไปเก่า).
     *
     * @param  string  $repo  ชื่อ repo ไม่รวม owner
     * @param  string|null  $pinnedTag  tag ที่แอดมินล็อกไว้ — null = เอาตัวล่าสุดที่มีไฟล์นั้นจริง
     * @param  callable  $matches  fn(array $asset): bool — เงื่อนไขคัดไฟล์
     * @return array|null  null เมื่อดึงไม่ได้ หรือไม่เจอไฟล์ที่ตรงเงื่อนไข
     */
    private function pickReleaseAsset(string $repo, ?string $pinnedTag, callable $matches): ?array
    {
        try {
            $headers = [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'TPIX-TRADE-Server',
            ];

            // repo ไพรเวทต้องมี token ถึงจะเห็น — ถ้าไม่มี GitHub ตอบ 404 (ไม่ใช่ 403)
            // จึงต้อง log has_token ไว้ ไม่งั้นจะแยกไม่ออกว่า "ยังไม่มี release" หรือ "token หาย"
            if ($this->githubToken) {
                $headers['Authorization'] = "Bearer {$this->githubToken}";
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get("https://api.github.com/repos/{$this->githubOwner}/{$repo}/releases?per_page=30");

            if (! $response->successful()) {
                Log::warning('GitHub releases fetch failed', [
                    'repo' => $repo,
                    'status' => $response->status(),
                    'has_token' => (bool) $this->githubToken,
                ]);

                return null;
            }

            foreach ($response->json() as $release) {
                if ($release['draft'] || $release['prerelease']) {
                    continue;
                }

                if ($pinnedTag && $release['tag_name'] !== $pinnedTag) {
                    continue;
                }

                $asset = collect($release['assets'] ?? [])->first($matches);

                if (! $asset) {
                    continue;
                }

                preg_match('/v?(\d+\.\d+\.\d+)/', $release['tag_name'], $tagVer);
                $version = $tagVer[1] ?? $release['tag_name'];

                // เลขรุ่นจากชื่อไฟล์เชื่อถือได้กว่าชื่อ tag เช่น TPIX-Master-Node-1.7.1.exe
                preg_match('/(\d+\.\d+\.\d+)/', $asset['name'], $fileVer);

                return [
                    'asset' => [
                        'file_name' => $asset['name'],
                        'file_size' => $asset['size'],
                        'download_url' => $asset['url'],
                        'downloads' => $asset['download_count'],
                        'version' => $fileVer[1] ?? $version,
                        'tag' => $release['tag_name'],
                        'published_at' => $release['published_at'],

                        // ติดมากับไฟล์แต่ละตัว ไม่งั้นวอลเล็ตจะไปได้ notes ของมาสเตอร์โหนด
                        'name' => $release['name'] ?: "v{$version}",
                        'notes' => $release['body'] ?? '',
                    ],
                    'tag' => $release['tag_name'],
                    'version' => $version,
                    'name' => $release['name'] ?: "v{$version}",
                    'published_at' => $release['published_at'],
                    'notes' => $release['body'] ?? '',
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('GitHub release fetch failed', ['repo' => $repo, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // =====================================================================
    //  ฟีดอัปเดตโปรแกรมมาสเตอร์โหนด (electron-updater แบบ generic provider)
    //
    //  ตัวแอปห้ามยิง GitHub ตรง ๆ เพราะ repo เป็นไพรเวท = ต้องฝัง token ลงไฟล์
    //  .exe ที่แจกผู้ใช้ ซึ่งใครก็แกะออกมาได้ เซิร์ฟเวอร์จึงเป็นคนถือ token
    //  ไปดึงแทน แล้วส่งต่อให้แอป — ไม่มีความลับอยู่ในเครื่องผู้ใช้เลย
    // =====================================================================

    /**
     * ไฟล์ทั้งหมดของ release มาสเตอร์โหนดที่ใช้งานอยู่ (คีย์ = ชื่อไฟล์).
     * แคช 30 นาทีเมื่อเจอ, 60 วิเมื่อไม่เจอ (กัน negative-caching ค้างนาน).
     */
    private function masternodeReleaseAssets(): array
    {
        $pinnedTag = SiteSetting::get('app_release', 'masternode_active_tag');
        $key = 'masternode_feed_assets_'.md5((string) $pinnedTag);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $assets = $this->scanFeedAssets($this->masternodeRepo, $pinnedTag);

        // ช่วงเปลี่ยนผ่าน — รุ่นเปลี่ยนผ่านยังถูกปล่อยจาก repo เชนอยู่
        // เครื่องที่อัปเดตมาแล้วต้องหาไฟล์เจอทันที ไม่ใช่รอ repo ใหม่ปล่อยรุ่นแรกก่อน
        // ถอดบล็อกนี้ออกได้เมื่อ TPIX-Masternode ปล่อยรุ่นแรกเรียบร้อยแล้ว
        if (! $assets && $this->masternodeRepo !== $this->chainRepo) {
            $assets = $this->scanFeedAssets($this->chainRepo, $pinnedTag);
        }

        Cache::put($key, $assets, $assets ? 1800 : 60);

        return $assets;
    }

    /**
     * สแกน release ของ repo หนึ่งตัว หาตัวล่าสุดที่ไฟล์อัปเดตครบ
     * แล้วคืนไฟล์ทั้งหมดของ release นั้น (คีย์ = ชื่อไฟล์).
     */
    private function scanFeedAssets(string $repo, ?string $pinnedTag): array
    {
        $assets = [];

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
                ->get("https://api.github.com/repos/{$this->githubOwner}/{$repo}/releases?per_page=30");

            if (! $response->successful()) {
                Log::warning('masternode feed: releases fetch failed', [
                    'repo' => $repo,
                    'status' => $response->status(),
                    'has_token' => (bool) $this->githubToken,
                ]);
            } else {
                foreach ($response->json() as $release) {
                    if ($release['draft'] || $release['prerelease']) {
                        continue;
                    }

                    if ($pinnedTag && $release['tag_name'] !== $pinnedTag) {
                        continue;
                    }

                    // ข้าม release ที่ไฟล์ไม่ครบ — เคยพลาดมาแล้วเมื่อ 2026-08-22
                    // (ชี้ไป release ที่ไม่มี latest.yml → ตัวอัปเดตเด้ง 404 ใส่หน้าผู้ใช้)
                    $names = array_column($release['assets'] ?? [], 'name');

                    if (! in_array('latest.yml', $names, true) || ! preg_grep('/\.exe$/i', $names)) {
                        continue;
                    }

                    foreach ($release['assets'] as $asset) {
                        $assets[$asset['name']] = [
                            'url' => $asset['url'],
                            'size' => $asset['size'],
                        ];
                    }

                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('masternode feed: fetch failed', ['repo' => $repo, 'error' => $e->getMessage()]);
        }

        return $assets;
    }

    /**
     * ขอ URL ปลายทางที่เซ็นแล้วจาก GitHub (ใช้ได้ชั่วคราวโดยไม่ต้องมี token).
     */
    private function signedAssetUrl(string $apiUrl): ?string
    {
        $headers = [
            'Accept: application/octet-stream',
            'User-Agent: TPIX-TRADE-Server',
        ];

        if ($this->githubToken) {
            $headers[] = "Authorization: Bearer {$this->githubToken}";
        }

        $ch = curl_init($apiUrl);
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
    }

    /**
     * เสิร์ฟไฟล์ให้ electron-updater — latest.yml, *.exe, *.blockmap.
     *
     * GET /updates/masternode/{file}
     */
    public function masternodeUpdateFile(string $file): mixed
    {
        // ด่านที่ 1: รูปแบบชื่อไฟล์ (กัน path traversal และการยิงมั่ว)
        // ด่านที่ 2 อยู่ข้างล่าง: ต้องตรงกับชื่อไฟล์จริงใน release เท่านั้น
        if (! preg_match('/^[A-Za-z0-9._-]{1,120}$/', $file) || str_contains($file, '..')) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'BAD_REQUEST', 'message' => 'Invalid file name'],
            ], 400);
        }

        $assets = $this->masternodeReleaseAssets();

        if (! isset($assets[$file])) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'File not available'],
            ], 404);
        }

        // latest.yml เล็กและต้องอ่านเนื้อได้ตรง ๆ — ส่งเนื้อไฟล์เลย ไม่ redirect
        // (URL ที่เซ็นแล้วมีอายุจำกัด ถ้าโดนแคชกลางทางจะพังแบบหาสาเหตุยาก)
        if (str_ends_with(strtolower($file), '.yml')) {
            $headers = [
                'Accept' => 'application/octet-stream',
                'User-Agent' => 'TPIX-TRADE-Server',
            ];

            if ($this->githubToken) {
                $headers['Authorization'] = "Bearer {$this->githubToken}";
            }

            $response = Http::withHeaders($headers)->timeout(10)->get($assets[$file]['url']);

            if (! $response->successful()) {
                Log::warning('masternode feed: yml fetch failed', [
                    'file' => $file,
                    'status' => $response->status(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'UPSTREAM_FAILED', 'message' => 'Unable to read update feed'],
                ], 502);
            }

            return response($response->body(), 200, [
                'Content-Type' => 'text/yaml; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        $signed = $this->signedAssetUrl($assets[$file]['url']);

        if (! $signed) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DOWNLOAD_FAILED', 'message' => 'Unable to prepare download'],
            ], 502);
        }

        // นับเฉพาะตัวติดตั้งจริง ไม่นับ .blockmap ที่ตัวอัปเดตดึงประกอบ
        if (str_ends_with(strtolower($file), '.exe')) {
            $this->incrementDownloadCount('masternode_exe');
        }

        return redirect()->away($signed);
    }
}
