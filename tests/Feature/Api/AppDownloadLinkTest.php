<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ลิงก์ดาวน์โหลด APK ต้องตรงกับรุ่นที่ฟีดอัปเดตบอกแอปเสมอ.
 *
 * เจอจริง 2026-10-20: ออก v1.1.239 แล้วฟีดบอกแอปว่ามีรุ่นใหม่ แต่ลิงก์ที่เซ็นแล้ว
 * แคชด้วยคีย์ตายตัว ('apk_s3_url') ยังชี้ไฟล์รุ่นก่อน → แอปเทรดโหลด APK เก่ามาติดตั้ง
 */
class AppDownloadLinkTest extends TestCase
{
    private const ASSET_238 = 'https://api.github.com/repos/xjanova/ThaiXTrade/releases/assets/238';

    private const ASSET_239 = 'https://api.github.com/repos/xjanova/ThaiXTrade/releases/assets/239';

    private function release(string $tag, string $assetUrl): array
    {
        return [
            'tag_name' => $tag,
            'name' => "TPIX TRADE {$tag}",
            'draft' => false,
            'prerelease' => false,
            'body' => '',
            'published_at' => '2026-10-20T00:00:00Z',
            'assets' => [[
                'name' => "TPIX-TRADE-{$tag}.apk",
                'url' => $assetUrl,
                'size' => 1000,
            ]],
        ];
    }

    /** รายการ release ที่ GitHub ตอบตอนนี้ — เปลี่ยนกลางเทสต์ได้ (Http::fake ซ้ำไม่ทับของเดิม) */
    private array $releases = [];

    private function fakeGithub(): void
    {
        Http::fake([
            'api.github.com/repos/*/releases?*' => fn () => Http::response($this->releases),
            self::ASSET_238 => Http::response('', 302, ['Location' => 'https://signed.example/v1.1.238.apk']),
            self::ASSET_239 => Http::response('', 302, ['Location' => 'https://signed.example/v1.1.239.apk']),
        ]);
    }

    public function test_new_release_gets_its_own_download_link_while_old_link_is_still_cached(): void
    {
        Cache::flush();

        $this->releases = [$this->release('v1.1.238', self::ASSET_238)];
        $this->fakeGithub();
        $this->get('/api/v1/app/download')
            ->assertRedirect('https://signed.example/v1.1.238.apk');

        // ออกรุ่นใหม่: ฟีดหมดแคช (หรือถูกล้างจาก CI) แต่ลิงก์เซ็นของรุ่นเก่ายังไม่หมดอายุ
        Cache::forget('app_update_android');
        $this->releases = [
            $this->release('v1.1.239', self::ASSET_239),
            $this->release('v1.1.238', self::ASSET_238),
        ];

        $this->getJson('/api/v1/app/latest')->assertJsonPath('data.version', '1.1.239');
        $this->get('/api/v1/app/download')
            ->assertRedirect('https://signed.example/v1.1.239.apk');
    }
}
