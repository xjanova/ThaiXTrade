<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TPIX TRADE — ห้ามให้ที่อยู่ GitHub หลุดไปถึงผู้ใช้.
 *
 * เจอจริง 2026-09-06: กล่อง "มีเวอร์ชันใหม่" ในแอปเทรดโชว์บันทึกรุ่นดิบจาก GitHub
 * ซึ่งมี "Full Changelog: https://github.com/<owner>/<repo>/compare/..." ติดมาเต็ม ๆ
 * สองรอบ (CI ใส่ generate_release_notes มาให้ แล้วเราส่ง body ต่อไปเลยไม่ได้ล้าง)
 * repo เป็นไพรเวท ผู้ใช้กดก็เจอแค่ 404 แต่ตัวลิงก์แจกชื่อเจ้าของกับชื่อ repo ฟรี ๆ
 *
 * ด่านนี้อยู่ฝั่งเซิร์ฟเวอร์เพราะเครื่องที่ลงแอปไปแล้วแก้ไม่ได้ — กล่องที่รั่วคือ
 * กล่องเดียวกับที่ใช้ชวนให้อัปเดต
 *
 * Developed by Xman Studio.
 */
class ReleaseNotesPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.github.token' => 'test-token']);
        config(['services.github.owner' => 'xjanova']);
        config(['services.github.repo' => 'ThaiXTrade']);
        config(['services.github.chain_repo' => 'TPIX-Coin']);
        config(['services.github.masternode_repo' => 'TPIX-Masternode']);
    }

    /** บันทึกรุ่นจริงของ v1.1.165 บน production ตอนที่เจอปัญหา */
    private const LEAKY_NOTES = <<<'MD'
        **Full Changelog**: https://github.com/xjanova/ThaiXTrade/compare/v1.1.164...v1.1.165

        ---
        ## Android APK (Flutter)

        | | |
        |---|---|
        | **Version** | v1.1.165 |
        | **Size** | 72.7MB |

        ### Highlights
        - Glass morphism dark theme matching web
        - Auto-update from GitHub Releases
        - Thai/English language support

        **Full Changelog**: https://github.com/xjanova/ThaiXTrade/compare/v1.1.164...v1.1.165
        MD;

    private function fakeTradeRelease(string $version, string $body): void
    {
        Http::fake([
            '*/ThaiXTrade/releases?per_page=100' => Http::response([[
                'tag_name' => "v{$version}",
                'name' => "TPIX TRADE v{$version}",
                'draft' => false,
                'prerelease' => false,
                'published_at' => '2026-09-05T08:22:22Z',
                'body' => $body,
                'assets' => [[
                    'name' => "TPIX-TRADE-v{$version}.apk",
                    'size' => 76282998,
                    'download_count' => 1,
                    'url' => 'https://api.github.com/repos/xjanova/ThaiXTrade/releases/assets/1',
                ]],
            ]]),
        ]);
    }

    private function fakeChainReleases(string $body = 'บันทึกการเปลี่ยนแปลง'): void
    {
        Http::fake([
            '*/TPIX-Coin/releases?per_page=30' => Http::response([[
                'tag_name' => 'v1.13.28',
                'name' => '1.13.28',
                'draft' => false,
                'prerelease' => false,
                'published_at' => '2026-09-03T12:13:47Z',
                'body' => $body,
                'assets' => [[
                    'name' => 'TPIX-Wallet-v1.13.28.apk',
                    'size' => 75552331,
                    'download_count' => 1,
                    'url' => 'https://api.github.com/repos/xjanova/TPIX-Coin/releases/assets/542726095',
                ]],
            ]]),
            '*/TPIX-Masternode/releases?per_page=30' => Http::response([[
                'tag_name' => 'v1.14.1',
                'name' => '1.14.1',
                'draft' => false,
                'prerelease' => false,
                'published_at' => '2026-09-03T03:51:38Z',
                'body' => $body,
                'assets' => [[
                    'name' => 'TPIX-Master-Node-1.14.1.exe',
                    'size' => 85050307,
                    'download_count' => 0,
                    'url' => 'https://api.github.com/repos/xjanova/TPIX-Masternode/releases/assets/542158240',
                ]],
            ]]),
        ]);
    }

    // =====================================================================
    //  แอปเทรด — กล่องอัปเดต
    // =====================================================================

    public function test_update_check_notes_carry_no_github_trace(): void
    {
        $this->fakeTradeRelease('1.1.165', self::LEAKY_NOTES);

        $notes = $this->getJson('/api/v1/app/update-check?version=1.1.164')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->json('data.release_notes');

        $this->assertStringNotContainsString('github', strtolower($notes));
        $this->assertStringNotContainsString('xjanova', strtolower($notes));
        $this->assertStringNotContainsString('ThaiXTrade', $notes);
    }

    public function test_update_check_keeps_the_part_users_actually_read(): void
    {
        $this->fakeTradeRelease('1.1.165', self::LEAKY_NOTES);

        $notes = $this->getJson('/api/v1/app/update-check?version=1.1.164')
            ->json('data.release_notes');

        // ล้างลิงก์ ไม่ใช่ล้างเนื้อหา — ตารางกับหัวข้อต้องอยู่ครบ
        $this->assertStringContainsString('## Android APK (Flutter)', $notes);
        $this->assertStringContainsString('| **Version** | v1.1.165 |', $notes);
        $this->assertStringContainsString('|---|---|', $notes);
        $this->assertStringContainsString('Glass morphism dark theme matching web', $notes);
        $this->assertStringContainsString('Thai/English language support', $notes);

        // ขึ้นต้นด้วยเนื้อหา ไม่ใช่เส้นคั่นที่ห้อยอยู่เพราะบรรทัดบนถูกลบไป
        $this->assertStringStartsWith('## Android APK', $notes);
    }

    public function test_release_info_for_web_download_page_is_clean_too(): void
    {
        $this->fakeTradeRelease('1.1.165', self::LEAKY_NOTES);

        $notes = $this->getJson('/api/v1/app/latest')->assertOk()->json('data.notes');

        $this->assertStringNotContainsString('github', strtolower($notes));
    }

    // =====================================================================
    //  วอลเล็ต + มาสเตอร์โหนด
    // =====================================================================

    public function test_wallet_update_check_notes_carry_no_github_trace(): void
    {
        $this->fakeChainReleases("อัปเดตวอลเล็ต\n\n**Full Changelog**: https://github.com/xjanova/TPIX-Coin/compare/v1.13.27...v1.13.28");

        $notes = $this->getJson('/api/v1/app/wallet-update-check?version=1.13.27')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->json('data.release_notes');

        $this->assertStringNotContainsString('github', strtolower($notes));
        $this->assertSame('อัปเดตวอลเล็ต', $notes);
    }

    /**
     * endpoint นี้เปิดสาธารณะ ใครเปิดดูก็ได้ — เดิมมันคืน
     * api.github.com/repos/<owner>/<repo>/releases/assets/<id> ออกไปดิบ ๆ
     * ทั้งที่หน้าดาวน์โหลดไม่เคยใช้ค่านี้ (ชี้ /chain-download เองอยู่แล้ว)
     * เท่ากับแจกชื่อ repo วอลเล็ตกับมาสเตอร์โหนดให้คนนอกฟรี ๆ.
     */
    public function test_chain_latest_never_exposes_raw_github_asset_urls(): void
    {
        $this->fakeChainReleases();

        $response = $this->getJson('/api/v1/app/chain-latest')->assertOk();

        $this->assertStringNotContainsString('github', strtolower($response->getContent()));

        $this->assertStringContainsString(
            '/api/v1/app/chain-download?type=wallet',
            $response->json('data.wallet.download_url')
        );
        $this->assertStringContainsString(
            '/api/v1/app/chain-download?type=masternode',
            $response->json('data.masternode.download_url')
        );
    }

    public function test_chain_download_still_reaches_the_real_asset(): void
    {
        $this->fakeChainReleases();

        // chain-latest ปิดที่อยู่จริงไว้เฉพาะขาตอบผู้ใช้ — ขาดาวน์โหลดต้องยังหาไฟล์เจอ
        $this->getJson('/api/v1/app/chain-latest')->assertOk();

        $response = $this->get('/api/v1/app/chain-download?type=wallet');

        // ไม่มี token จริงในเทสต์ → ขอ signed URL ไม่ได้ แต่ต้องไม่ใช่ 404 "ไม่รู้จักไฟล์"
        $this->assertNotSame(404, $response->getStatusCode());
    }

    // =====================================================================
    //  รูปแบบลิงก์อื่น ๆ ที่ GitHub ชอบใส่มาให้
    // =====================================================================

    #[DataProvider('leakyBodies')]
    public function test_every_shape_of_github_link_is_scrubbed(string $body, string $mustKeep): void
    {
        $this->fakeTradeRelease('1.1.165', $body);

        $notes = $this->getJson('/api/v1/app/update-check?version=1.1.164')
            ->json('data.release_notes');

        $this->assertStringNotContainsString('github', strtolower($notes), "ยังรั่วจาก: {$body}");

        if ($mustKeep !== '') {
            $this->assertStringContainsString($mustKeep, $notes);
        }
    }

    public static function leakyBodies(): array
    {
        return [
            'ลิงก์เปล่า' => [
                "แก้บั๊ก\nดูเพิ่ม https://github.com/xjanova/ThaiXTrade/issues/12",
                'แก้บั๊ก',
            ],
            'ลิงก์แบบมาร์กดาวน์' => [
                'อ่าน [บันทึกฉบับเต็ม](https://github.com/xjanova/ThaiXTrade/releases) ได้',
                'บันทึกฉบับเต็ม',
            ],
            'บรรทัดที่ GitHub สร้างให้' => [
                "## What's Changed\n* ปรับหน้าเทรด by @xjanova in https://github.com/xjanova/ThaiXTrade/pull/9",
                'ปรับหน้าเทรด',
            ],
            'ลิงก์ในวงเล็บมุม' => [
                'รายละเอียด <https://github.com/xjanova/ThaiXTrade> ครับ',
                'รายละเอียด',
            ],
            'raw.githubusercontent' => [
                'โลโก้ https://raw.githubusercontent.com/xjanova/ThaiXTrade/main/logo.png',
                '',
            ],
            'github.io' => [
                "เปิดคู่มือในแอปได้เลย ไม่ต้องเข้าเว็บนอก\nเอกสาร https://xjanova.github.io/docs",
                'เปิดคู่มือในแอปได้เลย',
            ],
            'เอ่ยชื่อเฉย ๆ ไม่มีลิงก์' => [
                "อัปเดตแล้ว\n- Auto-update from GitHub Releases",
                'อัปเดตแล้ว',
            ],
        ];
    }

    /**
     * บรรทัดที่มีแค่ป้ายสั้น ๆ กับลิงก์ ("Full Changelog:", "เอกสาร ...") ตายไปทั้งบรรทัด
     * ไม่ใช่เหลือป้ายลอยชี้ไปไหนไม่ได้ ส่วนบรรทัดที่มีเนื้อความจริงรอบลิงก์ต้องอยู่ต่อ.
     */
    public function test_a_line_that_only_carried_a_link_is_dropped_whole(): void
    {
        $this->fakeTradeRelease('1.1.165', implode("\n", [
            'เอกสาร https://xjanova.github.io/docs',
            'ย้ายปุ่มสลับเชนมาไว้บนสุด https://github.com/xjanova/ThaiXTrade/pull/9 แล้ว',
        ]));

        $notes = $this->getJson('/api/v1/app/update-check?version=1.1.164')
            ->json('data.release_notes');

        $this->assertStringNotContainsString('เอกสาร', $notes);
        $this->assertSame('ย้ายปุ่มสลับเชนมาไว้บนสุด  แล้ว', $notes);
    }

    public function test_notes_without_github_pass_through_untouched(): void
    {
        $body = "## TPIX TRADE v1.1.165\n\n- เพิ่มคู่เทรดบนเชน TPIX\n- แก้กราฟค้าง\n\n**Website:** https://tpix.online";

        $this->fakeTradeRelease('1.1.165', $body);

        $notes = $this->getJson('/api/v1/app/update-check?version=1.1.164')
            ->json('data.release_notes');

        $this->assertSame($body, $notes);
    }

    public function test_notes_that_were_only_a_link_come_back_empty_not_broken(): void
    {
        $this->fakeTradeRelease('1.1.165', '**Full Changelog**: https://github.com/xjanova/ThaiXTrade/compare/v1.1.164...v1.1.165');

        $response = $this->getJson('/api/v1/app/update-check?version=1.1.164')->assertOk();

        // กล่องอัปเดตยังต้องขึ้นได้ แค่ไม่มีอะไรจะเล่า
        $response->assertJsonPath('data.available', true);
        $response->assertJsonPath('data.release_notes', '');
    }
}
