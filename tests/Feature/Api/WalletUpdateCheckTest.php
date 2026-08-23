<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — เช็กอัปเดตของแอป TPIX Wallet.
 *
 * GET /api/v1/app/wallet-update-check?version=x.y.z
 *
 * แอปวอลเล็ตเคยยิง GitHub เอง แต่ repo เชนกำลังจะเป็นไพรเวท ถ้ายังยิงเองต้องฝัง
 * token ลงไฟล์ APK ที่แจก ซึ่งใครก็แกะออกมาได้ จึงย้ายมาถามผ่านเซิร์ฟเวอร์แทน
 *
 * Developed by Xman Studio.
 */
class WalletUpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.github.token' => 'test-token']);
        config(['services.github.owner' => 'xjanova']);
        config(['services.github.chain_repo' => 'TPIX-Coin']);
        config(['services.github.masternode_repo' => 'TPIX-Masternode']);
    }

    private function fakeWalletRelease(string $version): void
    {
        Http::fake([
            '*/TPIX-Coin/releases?per_page=30' => Http::response([[
                'tag_name' => "v{$version}",
                'name' => "Wallet v{$version}",
                'draft' => false,
                'prerelease' => false,
                'published_at' => '2026-08-23T00:00:00Z',
                'body' => 'บันทึกการเปลี่ยนแปลง',
                'assets' => [[
                    'name' => "TPIX-Wallet-v{$version}.apk",
                    'size' => 8000,
                    'download_count' => 3,
                    'url' => 'https://api.github.com/repos/xjanova/TPIX-Coin/releases/assets/1',
                ]],
            ]]),
            '*/TPIX-Masternode/releases?per_page=30' => Http::response([]),
        ]);
    }

    public function test_reports_update_when_release_is_newer(): void
    {
        $this->fakeWalletRelease('1.14.0');

        $response = $this->getJson('/api/v1/app/wallet-update-check?version=1.13.12');

        $response->assertOk();
        $response->assertJsonPath('data.available', true);
        $response->assertJsonPath('data.latest_version', '1.14.0');
        $response->assertJsonPath('data.release_notes', 'บันทึกการเปลี่ยนแปลง');
        // ต้องชี้กลับมาที่เซิร์ฟเวอร์เรา ไม่ใช่ GitHub — ไม่งั้นเครื่องผู้ใช้โหลด repo ไพรเวทไม่ได้
        $this->assertStringContainsString(
            '/api/v1/app/chain-download?type=wallet',
            $response->json('data.download_url')
        );
    }

    public function test_reports_no_update_when_already_current(): void
    {
        $this->fakeWalletRelease('1.13.12');

        $response = $this->getJson('/api/v1/app/wallet-update-check?version=1.13.12');

        $response->assertOk();
        $response->assertJsonPath('data.available', false);
        $response->assertJsonPath('data.download_url', null);
    }

    /** ไม่มี release เลย ต้องตอบว่า "ไม่มีอัปเดต" ไม่ใช่พัง */
    public function test_survives_when_no_release_exists(): void
    {
        Http::fake(['*/releases?per_page=30' => Http::response([])]);

        $response = $this->getJson('/api/v1/app/wallet-update-check?version=1.13.12');

        $response->assertOk();
        $response->assertJsonPath('data.available', false);
    }

    /** ขึ้น major = บังคับอัปเดต */
    public function test_marks_major_bump_as_mandatory(): void
    {
        $this->fakeWalletRelease('2.0.0');

        $this->getJson('/api/v1/app/wallet-update-check?version=1.13.12')
            ->assertOk()
            ->assertJsonPath('data.mandatory', true);
    }
}
