<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\TreasuryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * keystore ต้องเป็นกระเป๋าใบเดียวกับที่ระบบจ่ายเหรียญออก.
 *
 * ที่อยู่กระเป๋าจ่ายเหรียญมาจากหลังบ้าน (revenue.tpix_wallet) ส่วน keystore เป็นไฟล์ที่วางไว้เอง
 * สองอย่างนี้หลุดจากกันได้ง่าย และเคยหลุดจริง (prod ตั้ง 0x2112b98e… ขณะที่ค่าปริยายในโค้ด
 * เป็น 0x78B81dF…) ผลคือ HotWalletSigner ปฏิเสธการเซ็นตอนจ่ายจริง ซึ่งไปรู้เอาตอนที่
 * ลูกค้าจ่ายเงินมาแล้ว — ด่านนี้ทำให้รู้ตั้งแต่ตอนตรวจความพร้อม
 */
class TreasuryKeystoreMatchTest extends TestCase
{
    private const WALLET = '0x2112b98e3ec5A252b7b2A8f02d498B64a2186A7f';

    private const OTHER = '0x78B81dF5345e69ef7A1af231dE1C5b1b30869C8f';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // readiness อ่านยอดจากเชน — ตอบให้จบเร็ว ๆ ไม่ต้องยิงจริง
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0']));
        SiteSetting::set('revenue', 'tpix_wallet', self::WALLET);
    }

    private function writeKeystore(?string $address): string
    {
        $path = storage_path('framework/testing/hot-wallet.keystore.json');
        @mkdir(dirname($path), 0755, true);
        $body = ['version' => 3, 'crypto' => ['cipher' => 'aes-128-ctr']];
        if ($address !== null) {
            $body['address'] = ltrim($address, '0x');
        }
        file_put_contents($path, json_encode($body));
        config(['treasury.hot_wallet.keystore_path' => $path]);

        return $path;
    }

    private function checkFor(string $key): array
    {
        $checks = app(TreasuryService::class)->readiness()['checks'];

        return collect($checks)->firstWhere('key', $key) ?? [];
    }

    public function test_matching_keystore_passes(): void
    {
        $this->writeKeystore(self::WALLET);

        $check = $this->checkFor('keystore_matches_wallet');

        $this->assertTrue($check['ok']);
    }

    public function test_a_keystore_for_another_wallet_is_reported_before_any_payout(): void
    {
        $this->writeKeystore(self::OTHER);

        $check = $this->checkFor('keystore_matches_wallet');

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString(strtolower(substr(self::OTHER, 0, 10)), strtolower($check['hint']));
    }

    public function test_a_keystore_without_an_address_field_is_not_silently_accepted(): void
    {
        $this->writeKeystore(null);

        $check = $this->checkFor('keystore_matches_wallet');

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('อ่านที่อยู่', $check['hint']);
    }

    public function test_no_keystore_at_all_says_so_instead_of_blaming_the_wallet(): void
    {
        config(['treasury.hot_wallet.keystore_path' => storage_path('framework/testing/missing.json')]);

        $check = $this->checkFor('keystore_matches_wallet');

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('ยังไม่มี keystore', $check['hint']);
    }
}
