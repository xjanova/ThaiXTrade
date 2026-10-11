<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ปุ่ม "ดาวน์โหลดโปรแกรม PC" บนหน้า /masternode และ /download.
 *
 * GET /api/v1/app/chain-download?type=masternode → redirect ไปลิงก์ที่ GitHub เซ็นให้
 *
 * บั๊กที่กัน: ลิงก์ที่ GitHub เซ็นให้หมดอายุใน 30 นาที (jwt) แต่เดิมแคชไว้ 1 ชั่วโมง
 * ครึ่งหลังของทุกรอบแคช ทุกคนที่กดดาวน์โหลดถูกส่งไปลิงก์ที่ใช้ไม่ได้แล้ว
 */
class ChainDownloadSignedUrlTest extends TestCase
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

    /** release v1.14.6 ตามไฟล์แนบจริงบน GitHub (portable + Setup + latest.yml + blockmap) */
    private function masternodeRelease(): array
    {
        $asset = fn (int $id, string $name) => [
            'name' => $name,
            'size' => 1024,
            'download_count' => 0,
            'url' => "https://api.github.com/repos/xjanova/TPIX-Masternode/releases/assets/{$id}",
        ];

        return [
            'tag_name' => 'v1.14.6',
            'name' => '1.14.6',
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-10-11T05:42:44Z',
            'body' => '',
            'assets' => [
                $asset(1, 'latest.yml'),
                $asset(2, 'TPIX-Master-Node-1.14.6.exe'),
                $asset(3, 'TPIX-Master-Node-Setup-1.14.6.exe'),
                $asset(4, 'TPIX-Master-Node-Setup-1.14.6.exe.blockmap'),
            ],
        ];
    }

    public function test_download_link_is_re_signed_before_github_expires_it(): void
    {
        $signed = 0;
        Http::fake(function ($request) use (&$signed) {
            $url = $request->url();

            if ($request->method() === 'HEAD' && str_contains($url, '/releases/assets/')) {
                $signed++;

                return Http::response('', 302, ['Location' => "https://release-assets.example/file?jwt=signed-{$signed}"]);
            }

            if (str_contains($url, '/repos/xjanova/TPIX-Masternode/releases')) {
                return Http::response([$this->masternodeRelease()]);
            }

            return Http::response([]);
        });

        $this->get('/api/v1/app/chain-download?type=masternode')
            ->assertRedirect('https://release-assets.example/file?jwt=signed-1');

        // ยังอยู่ในรอบแคช — ใช้ลิงก์เดิม ไม่ยิง GitHub ทุกครั้งที่มีคนกด
        $this->travel(60)->seconds();
        $this->get('/api/v1/app/chain-download?type=masternode')
            ->assertRedirect('https://release-assets.example/file?jwt=signed-1');

        // เลยอายุลิงก์รุ่นสั้นสุดของ GitHub (5 นาที) แล้ว — ต้องเซ็นใหม่ ไม่ใช่ส่งลิงก์ที่หมดอายุ
        $this->travel(5)->minutes();
        $this->get('/api/v1/app/chain-download?type=masternode')
            ->assertRedirect('https://release-assets.example/file?jwt=signed-2');

        // ขอลิงก์ด้วย HEAD + token + Accept octet-stream (ไม่ใช่ดาวน์โหลดไฟล์ทั้งก้อนผ่านเซิร์ฟเวอร์)
        Http::assertSent(fn ($request) => $request->method() === 'HEAD'
            && $request->hasHeader('Accept', 'application/octet-stream')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_no_redirect_from_github_means_502_not_a_broken_link(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'HEAD') {
                return Http::response('', 404);
            }

            if (str_contains($request->url(), '/repos/xjanova/TPIX-Masternode/releases')) {
                return Http::response([$this->masternodeRelease()]);
            }

            return Http::response([]);
        });

        $this->get('/api/v1/app/chain-download?type=masternode')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'DOWNLOAD_FAILED');
    }
}
