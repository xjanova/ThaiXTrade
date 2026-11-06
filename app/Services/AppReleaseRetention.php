<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * AppReleaseRetention — เก็บ release ของแต่ละแอปไว้แค่ N รุ่นล่าสุด ที่เหลือลบทิ้ง.
 *
 * ไฟล์แอปทั้งหมดอยู่บน GitHub Releases ของ 3 repo (เว็บไม่ได้เก็บไฟล์เอง แค่ไปดึงแทนผู้ใช้)
 * ลบ release = ไฟล์แนบของ release นั้นหายไปด้วย แต่ tag ไม่โดนแตะ สร้าง release ใหม่จาก tag ได้เสมอ
 *
 * นับแยกเป็นกลุ่ม (repo + ชนิด) ไม่ใช่รวมทั้ง repo:
 *   ThaiXTrade      → trade (มี .apk) / web (release ของเว็บ ไม่มีไฟล์แนบ)
 *   TPIX-Coin       → wallet (มี Wallet .apk) / desktop (มีแต่ .exe) / chain (ไม่มีไฟล์)
 *   TPIX-Masternode → desktop
 * ถ้านับรวม repo เดียว เว็บปล่อยรุ่นวันละหลายรอบ 5 อันดับแรกจะเป็นของเว็บหมด
 * แล้ว APK ตัวที่หน้าดาวน์โหลดแจกอยู่จะโดนลบทิ้ง
 *
 * ไม่ลบเด็ดขาด:
 *   - draft / prerelease (ไม่ได้อยู่ในชุดที่นับ)
 *   - release ที่แอดมินล็อกไว้ในหน้า App Releases (active tag ทั้งสามแอป)
 *   - ทุกอย่าง ถ้าอ่านรายการของ repo นั้นไม่ครบทุกหน้า (อ่านครึ่งเดียวแล้วลบ = ลบผิดตัว)
 *
 * Developed by Xman Studio
 */
class AppReleaseRetention
{
    public const DEFAULT_KEEP = 5;

    /** หน้าละ 100 × 20 หน้า = 2,000 release ต่อ repo พอสำหรับหลายปี */
    private const MAX_PAGES = 20;

    /** GitHub ขอให้เว้นอย่างน้อย 1 วิระหว่างคำสั่งที่แก้ข้อมูล ไม่งั้นโดน secondary rate limit */
    private const PAUSE_MICROS = 1_000_000;

    private string $owner;

    private ?string $token;

    public function __construct()
    {
        $this->owner = (string) config('services.github.owner', 'xjanova');
        $this->token = config('services.github.token');
    }

    /**
     * repo ทั้งหมดที่หน้า App Releases ดูแล (role ใช้แยกชนิด release).
     *
     * @return list<array{repo: string, role: string}>
     */
    public function repos(): array
    {
        return [
            ['repo' => (string) config('services.github.repo', 'ThaiXTrade'), 'role' => 'trade'],
            ['repo' => (string) config('services.github.chain_repo', 'TPIX-Coin'), 'role' => 'chain'],
            ['repo' => (string) config('services.github.masternode_repo', 'TPIX-Masternode'), 'role' => 'masternode'],
        ];
    }

    /**
     * ชนิดของ release — กติกาเดียวกับหน้า App Releases (AppReleaseController).
     */
    public static function classify(string $role, array $release): string
    {
        $names = array_map(fn ($a) => strtolower((string) ($a['name'] ?? '')), $release['assets'] ?? []);

        $hasApk = (bool) array_filter($names, fn ($n) => str_ends_with($n, '.apk'));
        $hasWalletApk = (bool) array_filter($names, fn ($n) => str_contains($n, 'wallet') && str_ends_with($n, '.apk'));
        $hasExe = (bool) array_filter($names, fn ($n) => str_ends_with($n, '.exe'));

        return match (true) {
            $hasWalletApk => 'wallet',
            $hasApk => 'mobile',
            $hasExe => 'desktop',
            $role === 'chain' => 'chain',
            default => 'web',
        };
    }

    /**
     * เลือก release ที่จะลบ — ฟังก์ชันล้วน ไม่ยิงเน็ต (เทสต์ตรงนี้).
     *
     * @param  list<array>  $releases  release ดิบจาก GitHub API ของ repo เดียว
     * @param  list<string>  $protectedTags
     * @return list<array{id: int, tag: string, group: string, published_at: ?string, assets: int, bytes: int}>
     */
    public static function selectForDeletion(string $role, array $releases, int $keep, array $protectedTags = []): array
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('keep ต้องเป็นจำนวนเต็มบวก');
        }

        $protected = array_flip(array_filter($protectedTags, fn ($t) => is_string($t) && $t !== ''));
        $groups = [];

        foreach ($releases as $release) {
            if (! empty($release['draft']) || ! empty($release['prerelease'])) {
                continue;
            }
            $groups[self::classify($role, $release)][] = $release;
        }

        $delete = [];
        foreach ($groups as $group => $items) {
            // ใหม่ไปเก่า — published_at ก่อน ตามที่หน้าแอดมินเรียง ตัวที่ไม่มีวันที่ใช้ created_at แทน
            usort($items, fn ($a, $b) => strcmp(
                (string) ($b['published_at'] ?? $b['created_at'] ?? ''),
                (string) ($a['published_at'] ?? $a['created_at'] ?? ''),
            ));

            foreach (array_slice($items, $keep) as $release) {
                if (isset($protected[$release['tag_name'] ?? ''])) {
                    continue;
                }

                $delete[] = [
                    'id' => (int) $release['id'],
                    'tag' => (string) ($release['tag_name'] ?? ''),
                    'group' => $group,
                    'published_at' => $release['published_at'] ?? null,
                    'assets' => count($release['assets'] ?? []),
                    'bytes' => (int) array_sum(array_column($release['assets'] ?? [], 'size')),
                ];
            }
        }

        return $delete;
    }

    /**
     * tag ที่แอดมินล็อกไว้ในหน้า App Releases — ห้ามลบไม่ว่าจะเก่าแค่ไหน.
     *
     * @return list<string>
     */
    public function protectedTags(): array
    {
        return array_values(array_filter([
            (string) SiteSetting::get('app_release', 'active_tag'),
            (string) SiteSetting::get('app_release', 'wallet_active_tag'),
            (string) SiteSetting::get('app_release', 'masternode_active_tag'),
        ]));
    }

    /**
     * แผนการลบของทุก repo.
     *
     * @return array{delete: list<array>, scanned: array<string, int>, errors: list<string>}
     */
    public function plan(int $keep): array
    {
        $protected = $this->protectedTags();
        $delete = [];
        $scanned = [];
        $errors = [];

        foreach ($this->repos() as ['repo' => $repo, 'role' => $role]) {
            $releases = $this->fetchAll($repo);
            if ($releases === null) {
                $errors[] = "{$repo}: อ่านรายการ release ไม่ครบ จึงไม่ลบอะไรใน repo นี้";

                continue;
            }

            $scanned[$repo] = count($releases);
            foreach (self::selectForDeletion($role, $releases, $keep, $protected) as $item) {
                $delete[] = ['repo' => $repo] + $item;
            }
        }

        return ['delete' => $delete, 'scanned' => $scanned, 'errors' => $errors];
    }

    /**
     * ลบตามแผน. หยุดทันทีเมื่อ GitHub บอกว่าโดนจำกัดอัตรา — รอบหน้าค่อยลบต่อ.
     *
     * @param  int  $limit  ลบได้มากสุดกี่ตัวในรอบนี้ (0 = ไม่จำกัด)
     * @return array{deleted: int, failed: int, remaining: int, bytes: int, stopped: ?string}
     */
    public function execute(array $plan, int $limit = 0, ?callable $onEach = null): array
    {
        $deleted = 0;
        $failed = 0;
        $bytes = 0;
        $stopped = null;
        $items = $plan['delete'] ?? [];

        foreach ($items as $i => $item) {
            if ($limit > 0 && $deleted + $failed >= $limit) {
                break;
            }
            if ($i > 0) {
                Sleep::usleep(self::PAUSE_MICROS);
            }

            $status = $this->deleteRelease($item['repo'], $item['id']);

            // 404 = มีคนลบไปก่อนแล้ว ผลลัพธ์เดียวกับที่ต้องการ
            if ($status === 204 || $status === 404) {
                $deleted++;
                $bytes += $item['bytes'];
                $onEach && $onEach($item, true);

                continue;
            }

            $failed++;
            $onEach && $onEach($item, false);

            if ($status === 403 || $status === 429) {
                $stopped = "GitHub ตอบ HTTP {$status} (สิทธิ์ไม่พอหรือโดนจำกัดอัตรา) หยุดลบไว้ก่อน";
                break;
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'remaining' => count($items) - $deleted,
            'bytes' => $bytes,
            'stopped' => $stopped,
        ];
    }

    /**
     * release ทั้งหมดของ repo. คืน null ถ้าอ่านไม่ครบทุกหน้า.
     */
    private function fetchAll(string $repo): ?array
    {
        $all = [];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $response = Http::withHeaders($this->headers())
                    ->timeout(20)
                    ->get("https://api.github.com/repos/{$this->owner}/{$repo}/releases", [
                        'per_page' => 100,
                        'page' => $page,
                    ]);

                if (! $response->successful() || ! is_array($response->json())) {
                    Log::warning('release retention: list failed', ['repo' => $repo, 'page' => $page, 'status' => $response->status()]);

                    return null;
                }

                $batch = $response->json();
                array_push($all, ...$batch);

                if (count($batch) < 100) {
                    return $all;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('release retention: list error', ['repo' => $repo, 'error' => class_basename($e)]);

            return null;
        }

        // เกิน MAX_PAGES = ยังอ่านไม่จบ อย่าลบจากรายการครึ่งเดียว
        Log::warning('release retention: too many pages', ['repo' => $repo]);

        return null;
    }

    private function deleteRelease(string $repo, int $id): int
    {
        try {
            return Http::withHeaders($this->headers())
                ->timeout(20)
                ->delete("https://api.github.com/repos/{$this->owner}/{$repo}/releases/{$id}")
                ->status();
        } catch (\Throwable $e) {
            Log::warning('release retention: delete error', ['repo' => $repo, 'id' => $id, 'error' => class_basename($e)]);

            return 0;
        }
    }

    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TPIX-TRADE-Server',
        ];

        if ($this->token) {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        return $headers;
    }
}
