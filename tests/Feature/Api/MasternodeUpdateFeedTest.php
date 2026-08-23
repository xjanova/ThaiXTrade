<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — ฟีดอัปเดตโปรแกรมมาสเตอร์โหนด.
 *
 * GET /updates/masternode/{file} — electron-updater (generic provider) มาอ่านที่นี่
 *
 * ทำไมต้องมีด่านนี้: repo โปรแกรมมาสเตอร์โหนดเป็นไพรเวท ตัวแอปจึงอ่าน GitHub ตรง ๆ
 * ไม่ได้ (ต้องฝัง token ในไฟล์ .exe ที่แจก = ใครก็แกะออกมาได้) เซิร์ฟเวอร์เป็นคนถือ
 * token ไปดึงแทนแล้วส่งต่อ
 *
 * Developed by Xman Studio.
 */
class MasternodeUpdateFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // แต่ละเทสต์ต้องเริ่มจาก cache เปล่า ไม่งั้นผลของเทสต์ก่อนรั่วข้ามมา
        Cache::flush();

        config(['services.github.token' => 'test-token']);
        config(['services.github.owner' => 'xjanova']);
        config(['services.github.masternode_repo' => 'TPIX-Masternode']);
    }

    /** โครง release ของ GitHub แบบย่อ */
    private function release(string $tag, array $assetNames): array
    {
        return [
            'tag_name' => $tag,
            'name' => "Master Node {$tag}",
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-08-23T00:00:00Z',
            'body' => '',
            'assets' => array_map(fn ($n) => [
                'name' => $n,
                'size' => 1024,
                'download_count' => 0,
                'url' => "https://api.github.com/repos/xjanova/TPIX-Masternode/releases/assets/{$n}",
            ], $assetNames),
        ];
    }

    public function test_serves_latest_yml_as_yaml_for_electron_updater(): void
    {
        $yml = "version: 1.7.2\npath: TPIX-Master-Node-1.7.2.exe\n";

        Http::fake([
            '*/releases?per_page=30' => Http::response([
                $this->release('v1.7.2', ['latest.yml', 'TPIX-Master-Node-1.7.2.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response($yml),
        ]);

        $response = $this->get('/updates/masternode/latest.yml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/yaml; charset=utf-8');
        $this->assertSame($yml, $response->getContent());
    }

    /**
     * กับดักเดิมเมื่อ 2026-08-22 — เคยชี้ไป release ที่ไม่มี latest.yml
     * ทำให้ตัวอัปเดตเด้ง 404 พร้อม stack ใส่หน้าผู้ใช้ดิบ ๆ
     * release ที่ไฟล์ไม่ครบต้องถูกข้ามไปหาตัวถัดไปเสมอ
     */
    public function test_skips_release_that_has_no_latest_yml(): void
    {
        $yml = "version: 1.7.0\npath: TPIX-Master-Node-1.7.0.exe\n";

        Http::fake([
            '*/releases?per_page=30' => Http::response([
                // ตัวใหม่กว่าแต่ไฟล์ไม่ครบ — ต้องถูกข้าม
                $this->release('v1.7.1', ['TPIX-Master-Node-1.7.1.exe']),
                // ตัวเก่ากว่าแต่ครบ — ต้องได้ตัวนี้
                $this->release('v1.7.0', ['latest.yml', 'TPIX-Master-Node-1.7.0.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response($yml),
        ]);

        $response = $this->get('/updates/masternode/latest.yml');

        $response->assertOk();
        $this->assertStringContainsString('1.7.0', $response->getContent());
    }

    public function test_returns_404_for_file_not_in_release(): void
    {
        Http::fake([
            '*/releases?per_page=30' => Http::response([
                $this->release('v1.7.2', ['latest.yml', 'TPIX-Master-Node-1.7.2.exe']),
            ]),
        ]);

        $response = $this->get('/updates/masternode/somethingelse.exe');

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    /** ไม่มี release ที่ใช้ได้ ต้องตอบ 404 เฉย ๆ ไม่ใช่ 500 */
    public function test_survives_empty_release_list(): void
    {
        Http::fake(['*/releases?per_page=30' => Http::response([])]);

        $this->get('/updates/masternode/latest.yml')->assertNotFound();
    }

    /** repo ไพรเวทที่ token หาย GitHub ตอบ 404 — ต้องไม่ทำให้ทั้ง endpoint พัง */
    public function test_survives_upstream_failure(): void
    {
        Http::fake(['*/releases?per_page=30' => Http::response([], 404)]);

        $this->get('/updates/masternode/latest.yml')->assertNotFound();
    }

    /**
     * ช่วงเปลี่ยนผ่าน — repo ไพรเวทยังไม่มี release แต่เครื่องที่อัปเดตมาแล้ว
     * ยิงมาที่นี่ทันที ต้องถอยไปหยิบจาก repo เชนให้ ไม่ใช่ตอบ 404
     */
    public function test_falls_back_to_chain_repo_while_new_repo_is_empty(): void
    {
        config(['services.github.chain_repo' => 'TPIX-Coin']);

        Http::fake([
            '*/TPIX-Masternode/releases?per_page=30' => Http::response([]),
            '*/TPIX-Coin/releases?per_page=30' => Http::response([
                $this->release('v1.13.13', ['latest.yml', 'TPIX-Master-Node-1.13.13.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response("version: 1.13.13\n"),
        ]);

        $response = $this->get('/updates/masternode/latest.yml');

        $response->assertOk();
        $this->assertStringContainsString('1.13.13', $response->getContent());
    }

    /** repo ใหม่มีของแล้ว ต้องไม่ไปแตะ repo เชนอีก */
    public function test_does_not_touch_chain_repo_once_new_repo_has_a_release(): void
    {
        Http::fake([
            '*/TPIX-Masternode/releases?per_page=30' => Http::response([
                $this->release('v1.7.2', ['latest.yml', 'TPIX-Master-Node-1.7.2.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response("version: 1.7.2\n"),
        ]);

        $this->get('/updates/masternode/latest.yml')->assertOk();

        $touchedChain = collect(Http::recorded())
            ->contains(fn ($pair) => str_contains($pair[0]->url(), 'TPIX-Coin'));

        $this->assertFalse($touchedChain, 'ไม่ควรยิงไป repo เชนเมื่อ repo ใหม่มีของแล้ว');
    }

    /**
     * tag ที่แอดมินล็อกไว้อาจถูกลบหรือแก้ทีหลัง — ถ้ายึดตายตัวฟีดจะว่างเปล่า
     * ทั้งที่มี release ใหม่ที่ใช้ได้อยู่ ต้องถอยไปตัวล่าสุดที่ไฟล์ครบแทน
     */
    public function test_falls_back_when_pinned_tag_no_longer_exists(): void
    {
        \App\Models\SiteSetting::set('app_release', 'masternode_active_tag', 'v9.9.9');

        Http::fake([
            '*/TPIX-Masternode/releases?per_page=30' => Http::response([
                $this->release('v1.7.2', ['latest.yml', 'TPIX-Master-Node-1.7.2.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response("version: 1.7.2\n"),
        ]);

        $response = $this->get('/updates/masternode/latest.yml');

        $response->assertOk();
        $this->assertStringContainsString('1.7.2', $response->getContent());
    }

    /** CI ปล่อยรุ่นเสร็จแล้วเว็บต้องเห็นทันที ไม่ใช่รอแคชหมดอายุครึ่งชั่วโมง */
    public function test_release_webhook_busts_the_feed_cache(): void
    {
        config(['services.github.deploy_secret' => 'test-secret']);

        Http::fake([
            '*/releases?per_page=30' => Http::response([
                $this->release('v1.7.2', ['latest.yml', 'TPIX-Master-Node-1.7.2.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response("version: 1.7.2\n"),
        ]);

        $this->get('/updates/masternode/latest.yml')->assertOk();

        $this->postJson('/api/v1/app/notify-release?secret=test-secret&type=masternode&tag=v1.7.3')
            ->assertOk()
            ->assertJsonPath('data.type', 'masternode');

        $this->get('/updates/masternode/latest.yml')->assertOk();

        $listCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'releases?per_page=30'))
            ->count();

        $this->assertGreaterThan(1, $listCalls, 'หลัง webhook ต้องไปถาม GitHub ใหม่ ไม่ใช้ของเก่าในแคช');
    }

    /**
     * webhook ต้องไม่ล็อก tag ให้เอง
     *
     * เดิมล็อกทุกครั้ง พอ CI แจ้งไม่สำเร็จสักรอบ ค่าที่ล็อกก็ค้างแล้วไม่มีอะไรมาปลด
     * เว็บโฆษณารุ่นเก่าให้ทุกคนอยู่เป็นเดือนโดยไม่มีใครรู้ (เจอจริง 2026-08-23)
     */
    public function test_release_webhook_does_not_pin_by_default(): void
    {
        config(['services.github.deploy_secret' => 'test-secret']);
        Http::fake(['*/releases?per_page=30' => Http::response([])]);

        $this->postJson('/api/v1/app/notify-release?secret=test-secret&type=masternode&tag=v1.7.3')
            ->assertOk();

        $this->assertEmpty(
            \App\Models\SiteSetting::get('app_release', 'masternode_active_tag'),
            'ห้ามล็อก tag ให้เองเด็ดขาด'
        );
    }

    /** ล็อกได้อยู่ แต่ต้องสั่งมาอย่างจงใจ */
    public function test_release_webhook_pins_only_when_asked(): void
    {
        config(['services.github.deploy_secret' => 'test-secret']);
        Http::fake(['*/releases?per_page=30' => Http::response([])]);

        $this->postJson('/api/v1/app/notify-release?secret=test-secret&type=masternode&tag=v1.7.3&pin=1')
            ->assertOk();

        $this->assertSame(
            'v1.7.3',
            \App\Models\SiteSetting::get('app_release', 'masternode_active_tag')
        );
    }

    /** webhook ต้องปฏิเสธคนที่ไม่มีรหัส */
    public function test_release_webhook_rejects_wrong_secret(): void
    {
        config(['services.github.deploy_secret' => 'test-secret']);

        $this->postJson('/api/v1/app/notify-release?secret=wrong&type=masternode&tag=v1.7.3')
            ->assertForbidden();
    }

    public function test_rejects_path_traversal_attempt(): void
    {
        Http::fake(['*/releases?per_page=30' => Http::response([])]);

        // ชื่อไฟล์ที่มี .. ต้องไม่มีวันถูกส่งต่อไปยัง GitHub
        $response = $this->get('/updates/masternode/..');

        $this->assertContains($response->getStatusCode(), [400, 404]);
        Http::assertNothingSent();
    }

    /** ผลลัพธ์ต้องถูกแคช ไม่ใช่ยิง GitHub ใหม่ทุกครั้งที่แอปเช็กอัปเดต */
    public function test_caches_release_lookup(): void
    {
        Http::fake([
            '*/releases?per_page=30' => Http::response([
                $this->release('v1.7.2', ['latest.yml', 'TPIX-Master-Node-1.7.2.exe']),
            ]),
            '*/releases/assets/latest.yml' => Http::response("version: 1.7.2\n"),
        ]);

        $this->get('/updates/masternode/latest.yml')->assertOk();
        $this->get('/updates/masternode/latest.yml')->assertOk();

        $listCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'releases?per_page=30'))
            ->count();

        $this->assertSame(1, $listCalls, 'รายการ release ต้องถูกเรียกครั้งเดียวแล้วใช้ cache');
    }
}
