<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use App\Services\AppReleaseRetention;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TPIX TRADE — App Releases เก็บแอปละ 5 รุ่นล่าสุด ที่เหลือลบทิ้ง (เจ้าของ 2026-11-06).
 *
 * ห้ามหลุด:
 *   1. นับแยกแอป — release ของเว็บ (ไม่มีไฟล์) ปล่อยวันละหลายรอบ ห้ามดัน APK ตัวที่แจกอยู่ออกจาก 5 อันดับ
 *   2. รุ่นที่แอดมินล็อกไว้ ห้ามลบไม่ว่าจะเก่าแค่ไหน
 *   3. draft / prerelease ไม่โดนแตะ
 *   4. อ่านรายการไม่ครบ = repo นั้นไม่ลบอะไรเลย
 *   5. --dry-run ไม่ยิง DELETE สักครั้ง
 *   6. ปุ่มในหลังบ้าน เฉพาะ super_admin
 *
 * Developed by Xman Studio.
 */
class AppReleaseRetentionTest extends TestCase
{
    /** @var array<string, list<array>> */
    private array $github = [];

    /** @var list<string> repo/id ที่โดน DELETE */
    private array $deleted = [];

    /** @var array<string, int> repo ที่ให้ตอบ error ตอนอ่านรายการ */
    private array $listFails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Sleep::fake();
        $this->withoutVite();

        config([
            'services.github.owner' => 'xjanova',
            'services.github.repo' => 'ThaiXTrade',
            'services.github.chain_repo' => 'TPIX-Coin',
            'services.github.masternode_repo' => 'TPIX-Masternode',
            'services.github.token' => 'test-token',
            'services.github.release_keep' => 5,
        ]);

        // ThaiXTrade: เว็บ 12 รุ่น (ใหม่กว่า) + APK 8 รุ่น (เก่ากว่า)
        $trade = [];
        for ($i = 1; $i <= 12; $i++) {
            $trade[] = $this->release(1000 + $i, 'v1.1.'.(200 + $i), "2026-11-0{$this->day($i)}T0{$this->hour($i)}:00:00Z");
        }
        for ($i = 1; $i <= 8; $i++) {
            $trade[] = $this->release(2000 + $i, 'v1.1.'.(100 + $i), sprintf('2026-10-%02dT00:00:00Z', 10 + $i), ["TPIX-TRADE-v1.1.{$i}.apk"]);
        }
        // draft + prerelease เก่ามาก ต้องไม่โดนแตะ
        $trade[] = $this->release(3001, 'v0.0.1', '2025-01-01T00:00:00Z', [], draft: true);
        $trade[] = $this->release(3002, 'v0.0.2', '2025-01-02T00:00:00Z', [], prerelease: true);

        // TPIX-Coin: wallet 7 รุ่น
        $coin = [];
        for ($i = 1; $i <= 7; $i++) {
            $coin[] = $this->release(4000 + $i, "v1.13.{$i}", sprintf('2026-10-%02dT00:00:00Z', 10 + $i), [
                "TPIX-Wallet-v1.13.{$i}.apk", 'TPIX-Master-Node-Setup-1.14.11.exe', 'latest.yml',
            ]);
        }

        // TPIX-Masternode: 6 รุ่น
        $mn = [];
        for ($i = 1; $i <= 6; $i++) {
            $mn[] = $this->release(5000 + $i, "v1.14.{$i}", sprintf('2026-10-%02dT00:00:00Z', 10 + $i), [
                "TPIX-Master-Node-Setup-1.14.{$i}.exe", 'latest.yml',
            ]);
        }

        $this->github = ['ThaiXTrade' => $trade, 'TPIX-Coin' => $coin, 'TPIX-Masternode' => $mn];

        Http::fake(function (Request $request) {
            if (! preg_match('#^https://api\.github\.com/repos/xjanova/([^/]+)/releases(?:/(\d+))?#', $request->url(), $m)) {
                return Http::response([], 404);
            }
            $repo = $m[1];

            if ($request->method() === 'DELETE') {
                $this->deleted[] = "{$repo}/{$m[2]}";

                return Http::response(null, 204);
            }

            if (isset($this->listFails[$repo])) {
                return Http::response(['message' => 'boom'], $this->listFails[$repo]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $page = (int) ($q['page'] ?? 1);

            return Http::response(array_slice($this->github[$repo] ?? [], ($page - 1) * 100, 100), 200);
        });
    }

    private function day(int $i): int
    {
        return intdiv($i - 1, 4) + 1;
    }

    private function hour(int $i): int
    {
        return ($i - 1) % 4 + 1;
    }

    private function release(int $id, string $tag, string $at, array $assets = [], bool $draft = false, bool $prerelease = false): array
    {
        return [
            'id' => $id,
            'tag_name' => $tag,
            'name' => $tag,
            'published_at' => $at,
            'created_at' => $at,
            'draft' => $draft,
            'prerelease' => $prerelease,
            'assets' => array_map(fn ($n) => ['name' => $n, 'size' => 10 * 1024 * 1024], $assets),
        ];
    }

    public function test_counts_each_app_separately_so_web_releases_never_push_out_the_apk(): void
    {
        $delete = AppReleaseRetention::selectForDeletion('trade', $this->github['ThaiXTrade'], 5);
        $ids = array_column($delete, 'id');

        // เว็บ: เก็บ 5 ใหม่สุด (1012..1008) ลบ 1001..1007
        $this->assertSame(range(1001, 1007), $this->sorted(array_filter($ids, fn ($id) => $id < 2000)));
        // APK: เก็บ 2008..2004 ลบ 2001..2003 — ถ้านับรวม repo APK ทั้ง 8 ตัวจะโดนลบหมด
        $this->assertSame([2001, 2002, 2003], $this->sorted(array_filter($ids, fn ($id) => $id >= 2000 && $id < 3000)));
        // draft / prerelease ไม่อยู่ในรายการ
        $this->assertNotContains(3001, $ids);
        $this->assertNotContains(3002, $ids);

        $groups = array_unique(array_column($delete, 'group'));
        sort($groups);
        $this->assertSame(['mobile', 'web'], $groups);
    }

    public function test_pinned_tag_is_never_deleted(): void
    {
        $delete = AppReleaseRetention::selectForDeletion('trade', $this->github['ThaiXTrade'], 5, ['v1.1.101']);

        $this->assertNotContains(2001, array_column($delete, 'id'));
        $this->assertContains(2002, array_column($delete, 'id'));
    }

    public function test_keep_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppReleaseRetention::selectForDeletion('trade', $this->github['ThaiXTrade'], 0);
    }

    public function test_dry_run_lists_but_deletes_nothing(): void
    {
        $this->artisan('app-releases:prune', ['--dry-run' => true])
            ->expectsOutputToContain('รวมจะลบ 13 release')
            ->assertSuccessful();

        $this->assertSame([], $this->deleted);
    }

    public function test_force_deletes_the_old_ones_across_all_three_repos(): void
    {
        SiteSetting::set('app_release', 'masternode_active_tag', 'v1.14.1');

        $this->artisan('app-releases:prune', ['--force' => true])->assertSuccessful();

        sort($this->deleted);
        $expected = array_merge(
            array_map(fn ($id) => "ThaiXTrade/{$id}", [...range(1001, 1007), 2001, 2002, 2003]),
            ['TPIX-Coin/4001', 'TPIX-Coin/4002'],
            // 5001 ถูกล็อกไว้ — ไม่ลบ
        );
        sort($expected);
        $this->assertSame($expected, $this->deleted);
    }

    public function test_incomplete_listing_deletes_nothing_in_that_repo(): void
    {
        $this->listFails['ThaiXTrade'] = 502;

        $this->artisan('app-releases:prune', ['--force' => true])->assertFailed();

        $this->assertSame([], array_values(array_filter($this->deleted, fn ($d) => str_starts_with($d, 'ThaiXTrade/'))));
        $this->assertContains('TPIX-Coin/4001', $this->deleted);
    }

    public function test_zero_keep_is_refused(): void
    {
        $this->artisan('app-releases:prune', ['--force' => true, '--keep' => '0'])->assertFailed();

        $this->assertSame([], $this->deleted);
    }

    public function test_admin_button_is_super_admin_only(): void
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ช่วย',
            'email' => 'helper@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->signIn($admin)->post('/admin/app-releases/prune')->assertForbidden();
        $this->assertSame([], $this->deleted);
    }

    public function test_admin_button_deletes_one_batch_and_reports_what_is_left(): void
    {
        $owner = AdminUser::create([
            'name' => 'เจ้าของ',
            'email' => 'owner@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // 40 release เว็บเพิ่ม → รวมเกิน 15 ตัวที่ปุ่มลบได้ต่อครั้ง
        for ($i = 1; $i <= 40; $i++) {
            $this->github['ThaiXTrade'][] = $this->release(6000 + $i, 'v1.0.'.$i, '2026-01-01T00:00:00Z');
        }

        $this->signIn($owner)
            ->from('/admin/app-releases')
            ->post('/admin/app-releases/prune')
            ->assertRedirect('/admin/app-releases')
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ลบรุ่นเก่าแล้ว 15 รุ่น') && str_contains($m, 'เหลืออีก'));

        $this->assertCount(15, $this->deleted);
    }

    /** ล็อกอินแบบเบราว์เซอร์ — actingAs สลับ guard ตั้งแต่ก่อน request ซ่อนบั๊กเรื่อง guard */
    private function signIn(AdminUser $admin): self
    {
        return $this->withSession([Auth::guard('admin')->getName() => $admin->id]);
    }

    /** @param  iterable<int>  $ids */
    private function sorted(iterable $ids): array
    {
        $ids = array_values(is_array($ids) ? $ids : iterator_to_array($ids));
        sort($ids);

        return $ids;
    }
}
