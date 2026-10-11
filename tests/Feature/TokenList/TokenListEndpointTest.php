<?php

namespace Tests\Feature\TokenList;

use App\Models\Chain;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Services\Bridge\Abi;
use App\Services\ContractRegistry;
use App\Services\TokenFactory\FactoryDeploymentFinalizer;
use App\Services\TokenList\TokenListService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /tokenlist.json + /api/v1/tokens/list — รายการโทเคน TPIX Chain (Uniswap Token List).
 *
 * explorer และ TPIX Wallet ถือรายการนี้เป็น "ของจริง" สิ่งที่กันในชุดนี้:
 *   - เหรียญจาก Token Factory ที่ยังไม่ลิสต์/ถูกเลิกลิสต์/ยังไม่ deploy หลุดเข้ามา
 *   - แถวที่ dex:sync เพิ่มเอง (ใครก็เปิดพูลได้) และแถวดัชนีที่อยู่ placeholder หลุดเข้ามา
 *   - รูปแบบผิดมาตรฐาน (ที่อยู่ไม่ checksum, โลโก้ไม่ใช่ https, ชื่อ/สัญลักษณ์/ทศนิยมเกินกำหนด)
 *   - แคชค้างหลังเปลี่ยนโลโก้/ลิสต์ · ETag/304 · CORS เปิดเฉพาะสอง route นี้
 */
class TokenListEndpointTest extends TestCase
{
    private const WTPIX = '0x1111111111111111111111111111111111111111';

    private const USDT = '0x2222222222222222222222222222222222222222';

    /** เวกเตอร์ทดสอบจาก EIP-55 */
    private const EIP55_LOWER = '0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed';

    private const EIP55_CHECKSUM = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

    private const CREATOR = '0x9999999999999999999999999999999999999999';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        config([
            'app.url' => 'https://tpix.online',
            'blockchain.tpix_chain_id' => 4289,
            'blockchain.dex.wtpix' => '',
            'blockchain.dex.usdt' => '',
        ]);
    }

    private function tpixChain(): Chain
    {
        return Chain::where('chain_id', 4289)->firstOrFail();
    }

    private function registerDexTokens(): void
    {
        SiteSetting::set(ContractRegistry::GROUP, 'wtpix', self::WTPIX);
        SiteSetting::set(ContractRegistry::GROUP, 'usdt_tpix', self::USDT);
        Cache::flush();
    }

    /** @param  array<string, mixed>  $overrides */
    private function coreToken(array $overrides = []): Token
    {
        return Token::create(array_merge([
            'chain_id' => $this->tpixChain()->id,
            'name' => 'Core Coin',
            'symbol' => 'CORE',
            'contract_address' => '0x'.str_repeat('c', 40),
            'decimals' => 18,
            'is_active' => true,
            'is_verified' => true,
            'sort_order' => 5,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function factoryToken(array $overrides = []): FactoryToken
    {
        return FactoryToken::create(array_merge([
            'name' => 'Factory Coin',
            'symbol' => 'FAC',
            'decimals' => 18,
            'total_supply' => '1000000',
            'creator_address' => self::CREATOR,
            'contract_address' => '0x'.str_repeat('f', 40),
            'chain_id' => 4289,
            'status' => 'deployed',
            'is_listed' => true,
        ], $overrides));
    }

    private function list(string $url = '/tokenlist.json'): array
    {
        return $this->get($url)->assertOk()->json();
    }

    /** @return array<string, array<string, mixed>> */
    private function bySymbol(array $list): array
    {
        return collect($list['tokens'])->keyBy('symbol')->all();
    }

    // =========================================================================
    //  รูปแบบตามมาตรฐาน
    // =========================================================================

    #[Test]
    public function รูปแบบตรงตาม_schema_ของ_uniswap_token_list(): void
    {
        $this->registerDexTokens();
        $this->coreToken();
        $this->factoryToken();

        $response = $this->get('/tokenlist.json')->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $list = $response->json();

        // ระดับรายการ
        $this->assertSame('TPIX Chain', $list['name']);
        $this->assertMatchesRegularExpression('/^[\w ]{1,30}$/', $list['name']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/', $list['timestamp']);
        $this->assertSame(['major', 'minor', 'patch'], array_keys($list['version']));
        $this->assertSame(1, $list['version']['major']);
        $this->assertSame(count($list['tokens']), $list['version']['minor']);
        $this->assertIsInt($list['version']['patch']);
        $this->assertGreaterThanOrEqual(0, $list['version']['patch']);
        $this->assertSame('https://tpix.online/images/brand/tpix-coin.png', $list['logoURI']);
        $this->assertLessThanOrEqual(20, count($list['keywords']));
        $this->assertSame($list['keywords'], array_values(array_unique($list['keywords'])));
        foreach ($list['keywords'] as $keyword) {
            $this->assertMatchesRegularExpression('/^[\w ]{1,20}$/', $keyword);
        }

        // ระดับเหรียญ
        $this->assertCount(4, $list['tokens']);
        $allowed = ['chainId', 'address', 'decimals', 'name', 'symbol', 'logoURI', 'tags', 'extensions'];
        foreach ($list['tokens'] as $token) {
            $this->assertSame([], array_diff(array_keys($token), $allowed), 'มีฟิลด์ที่ schema ไม่รู้จัก');
            $this->assertSame(4289, $token['chainId']);
            $this->assertMatchesRegularExpression('/^0x[a-fA-F0-9]{40}$/', $token['address']);
            $this->assertSame(Abi::checksum($token['address']), $token['address']);
            $this->assertIsInt($token['decimals']);
            $this->assertTrue($token['decimals'] >= 0 && $token['decimals'] <= 36);
            $this->assertTrue(mb_strlen($token['name']) >= 1 && mb_strlen($token['name']) <= 60);
            $this->assertMatchesRegularExpression('/^[ \S]+$/u', $token['name']);
            $this->assertTrue(mb_strlen($token['symbol']) >= 1 && mb_strlen($token['symbol']) <= 20);
            $this->assertMatchesRegularExpression('/^\S+$/u', $token['symbol']);
            if (isset($token['logoURI'])) {
                $this->assertStringStartsWith('https://', $token['logoURI']);
            }
        }

        // TPIX native ไม่ใช่ ERC-20 → ไม่อยู่ใน tokens แต่บอกไว้ใน extensions
        $this->assertNotContains('TPIX', array_column($list['tokens'], 'symbol'));
        $this->assertSame('TPIX', $list['extensions']['nativeCurrency']['symbol']);
        $this->assertSame(18, $list['extensions']['nativeCurrency']['decimals']);
    }

    #[Test]
    public function สอง_endpoint_ได้เนื้อหาเดียวกันทุกไบต์(): void
    {
        $this->registerDexTokens();
        $this->factoryToken();

        $a = $this->get('/tokenlist.json')->assertOk();
        $b = $this->get('/api/v1/tokens/list')->assertOk();

        $this->assertSame($a->getContent(), $b->getContent());
        $this->assertSame($a->headers->get('ETag'), $b->headers->get('ETag'));
    }

    #[Test]
    public function ทะเบียนสัญญาให้_wtpix_กับ_usdt_แม้ยังไม่มีแถว_tokens(): void
    {
        $this->registerDexTokens();

        $tokens = $this->bySymbol($this->list());

        $this->assertSame(Abi::checksum(self::WTPIX), $tokens['WTPIX']['address']);
        $this->assertSame('Wrapped TPIX', $tokens['WTPIX']['name']);
        $this->assertSame(18, $tokens['WTPIX']['decimals']);
        // WTPIX ใช้โลโก้เหรียญ TPIX
        $this->assertSame('https://tpix.online/images/brand/tpix-coin.png', $tokens['WTPIX']['logoURI']);
        $this->assertSame(6, $tokens['USDT']['decimals']);
    }

    #[Test]
    public function แถว_usdt_ที่_dex_sync_ดูแลให้ชื่อทศนิยมและโลโก้_แม้ยังไม่ได้กดรับรอง(): void
    {
        $this->registerDexTokens();
        $this->coreToken([
            'name' => 'Tether USD',
            'symbol' => 'USDT',
            'contract_address' => self::USDT,
            'decimals' => 6,
            'logo' => 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xdAC17F958D2ee523a2206206994597C13D831ec7/logo.png',
            'is_verified' => false,
        ]);

        $usdt = $this->bySymbol($this->list())['USDT'];

        $this->assertSame('Tether USD', $usdt['name']);
        $this->assertStringStartsWith('https://assets-cdn.trustwallet.com/', $usdt['logoURI']);
    }

    #[Test]
    public function ที่อยู่ออกเป็น_checksum_eip55_เสมอ(): void
    {
        $this->factoryToken(['contract_address' => self::EIP55_LOWER]);

        $this->assertSame(self::EIP55_CHECKSUM, $this->list()['tokens'][0]['address']);
    }

    #[Test]
    public function ลำดับคงที่_เรียงตาม_symbol_ไม่ขึ้นกับลำดับในฐานข้อมูล(): void
    {
        $this->coreToken(['symbol' => 'ZED', 'contract_address' => '0x'.str_repeat('a', 40), 'sort_order' => 1]);
        $this->factoryToken(['symbol' => 'MID', 'contract_address' => '0x'.str_repeat('b', 40)]);
        $this->coreToken(['symbol' => 'abc', 'contract_address' => '0x'.str_repeat('d', 40), 'sort_order' => 99]);

        $first = $this->get('/tokenlist.json');
        $this->assertSame(['abc', 'MID', 'ZED'], array_column($first->json('tokens'), 'symbol'));

        // แก้ sort_order (ไม่ใช่เนื้อหาของรายการ) → ลำดับ/เนื้อหา/ETag เหมือนเดิม
        Token::where('symbol', 'ZED')->first()->update(['sort_order' => 500]);
        $second = $this->get('/tokenlist.json');

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    // =========================================================================
    //  กันปลอม — อะไรเข้ารายการได้บ้าง
    // =========================================================================

    #[Test]
    public function เหรียญจากโรงงานเข้าได้เฉพาะที่_deploy_แล้วและแอดมินลิสต์(): void
    {
        $this->factoryToken(['symbol' => 'LISTED', 'contract_address' => '0x'.str_repeat('1a', 20)]);
        $this->factoryToken(['symbol' => 'UNLISTED', 'contract_address' => '0x'.str_repeat('2b', 20), 'is_listed' => false]);
        $this->factoryToken(['symbol' => 'PENDING', 'contract_address' => null, 'status' => 'pending', 'is_listed' => true]);
        $this->factoryToken(['symbol' => 'FAILED', 'contract_address' => '0x'.str_repeat('3c', 20), 'status' => 'failed', 'is_listed' => true]);
        $this->factoryToken(['symbol' => 'REJECTED', 'contract_address' => '0x'.str_repeat('4d', 20), 'status' => 'rejected', 'is_listed' => true]);
        $this->factoryToken(['symbol' => 'OTHERCHAIN', 'contract_address' => '0x'.str_repeat('5e', 20), 'chain_id' => 56]);

        $this->assertSame(['LISTED'], array_column($this->list()['tokens'], 'symbol'));
    }

    #[Test]
    public function เลิกลิสต์แล้ว_แถว_tokens_ที่ค้างจากตอนลิสต์ก็ไม่พาเข้ารายการ(): void
    {
        // dex:sync สร้างแถว tokens ตอนเหรียญยังลิสต์อยู่ — แถวนั้นไม่หายไปเองตอนเลิกลิสต์
        $address = '0x'.str_repeat('6f', 20);
        $this->factoryToken(['symbol' => 'GONE', 'contract_address' => $address, 'is_listed' => false]);
        $this->coreToken(['symbol' => 'GONE', 'contract_address' => $address, 'is_verified' => true]);

        $this->assertSame([], $this->list()['tokens']);
    }

    #[Test]
    public function แถว_tokens_ต้องรับรองและเปิดใช้งาน_บนเชน_tpix_และเป็นสัญญาจริง(): void
    {
        $this->coreToken(['symbol' => 'GOOD', 'contract_address' => '0x'.str_repeat('a1', 20)]);
        // dex:sync เพิ่มเอง — ใครก็เปิดพูลได้
        $this->coreToken(['symbol' => 'SPOOF', 'name' => 'Tether USD', 'contract_address' => '0x'.str_repeat('b2', 20), 'is_verified' => false]);
        $this->coreToken(['symbol' => 'OFF', 'contract_address' => '0x'.str_repeat('c3', 20), 'is_active' => false]);
        // TPIX native และแถวดัชนีที่อยู่ placeholder (hex ของ "BTC")
        $this->coreToken(['symbol' => 'TPIX', 'contract_address' => '0x'.str_repeat('0', 40)]);
        $this->coreToken(['symbol' => 'BTC', 'contract_address' => '0x'.str_pad('425443', 40, '0', STR_PAD_LEFT)]);
        // เชนอื่น
        $bsc = Chain::where('chain_id', 56)->firstOrFail();
        $this->coreToken(['chain_id' => $bsc->id, 'symbol' => 'CAKE', 'contract_address' => '0x'.str_repeat('d4', 20)]);

        $this->assertSame(['GOOD'], array_column($this->list()['tokens'], 'symbol'));
    }

    #[Test]
    public function dex_sync_ไม่รับรองเหรียญที่เจอเองบนเชน(): void
    {
        $token = new Token([
            'chain_id' => $this->tpixChain()->id,
            'symbol' => 'NEW',
            'name' => 'New Coin',
            'contract_address' => '0x'.str_repeat('e5', 20),
            'decimals' => 18,
            'is_active' => true,
        ]);
        $token->save();

        // ค่าตั้งต้นของคอลัมน์ต้องเป็น "ไม่รับรอง" — ทางไหนสร้างแถวโดยไม่คิด ต้องไม่หลุดเข้ารายการ
        $this->assertFalse($token->fresh()->is_verified);
        $this->assertSame([], $this->list()['tokens']);
    }

    #[Test]
    public function ข้อมูลผิดมาตรฐานถูกข้ามทีละเหรียญ_ไม่ทำให้ทั้งรายการพัง(): void
    {
        $this->coreToken(['symbol' => 'OK', 'contract_address' => '0x'.str_repeat('a6', 20)]);
        $this->coreToken(['symbol' => 'BIGDEC', 'decimals' => 40, 'contract_address' => '0x'.str_repeat('b7', 20)]);
        $this->coreToken(['symbol' => 'HAS SPACE', 'contract_address' => '0x'.str_repeat('c8', 20)]);
        $this->coreToken(['symbol' => 'LONGNAME', 'name' => str_repeat('n', 61), 'contract_address' => '0x'.str_repeat('d9', 20)]);
        $this->coreToken(['symbol' => str_repeat('S', 21), 'contract_address' => '0x'.str_repeat('ea', 20)]);
        $this->coreToken(['symbol' => 'BADADDR', 'contract_address' => '0x1234']);

        $this->assertSame(['OK'], array_column($this->list()['tokens'], 'symbol'));
    }

    // =========================================================================
    //  โลโก้ — https เท่านั้น
    // =========================================================================

    #[Test]
    public function โลโก้ต้องเป็น_https_เต็ม_และไฟล์ต้องมีอยู่จริง(): void
    {
        Storage::disk('public')->put('token-logos/4289/up.png', 'png');
        Storage::disk('public')->put('token-logos/'.self::CREATOR.'/creator.png', 'png');

        $this->coreToken(['symbol' => 'EXT', 'contract_address' => '0x'.str_repeat('a1', 20), 'logo' => 'https://cdn.example.com/ext.png']);
        $this->coreToken(['symbol' => 'PLAIN', 'contract_address' => '0x'.str_repeat('a2', 20), 'logo' => 'http://cdn.example.com/plain.png']);
        $this->coreToken(['symbol' => 'UPLOAD', 'contract_address' => '0x'.str_repeat('a3', 20), 'logo' => 'token-logos/4289/up.png']);
        $this->coreToken(['symbol' => 'MISSING', 'contract_address' => '0x'.str_repeat('a4', 20), 'logo' => 'token-logos/4289/missing.png']);
        $this->coreToken(['symbol' => 'BRAND', 'contract_address' => '0x'.str_repeat('a5', 20), 'logo' => '/images/brand/tpix-coin.png']);
        $this->coreToken(['symbol' => 'JS', 'contract_address' => '0x'.str_repeat('a6', 20), 'logo' => 'javascript:alert(1)']);
        $this->coreToken(['symbol' => 'TRAVERSE', 'contract_address' => '0x'.str_repeat('a7', 20), 'logo' => '../../.env']);
        $this->factoryToken(['symbol' => 'FACLOGO', 'contract_address' => '0x'.str_repeat('a8', 20), 'logo_url' => '/storage/token-logos/'.self::CREATOR.'/creator.png']);

        $tokens = $this->bySymbol($this->list());

        $this->assertSame('https://cdn.example.com/ext.png', $tokens['EXT']['logoURI']);
        $this->assertArrayNotHasKey('logoURI', $tokens['PLAIN']);
        $this->assertSame('https://tpix.online/storage/token-logos/4289/up.png', $tokens['UPLOAD']['logoURI']);
        $this->assertArrayNotHasKey('logoURI', $tokens['MISSING']);
        $this->assertSame('https://tpix.online/images/brand/tpix-coin.png', $tokens['BRAND']['logoURI']);
        $this->assertArrayNotHasKey('logoURI', $tokens['JS']);
        $this->assertArrayNotHasKey('logoURI', $tokens['TRAVERSE']);
        $this->assertSame('https://tpix.online/storage/token-logos/'.self::CREATOR.'/creator.png', $tokens['FACLOGO']['logoURI']);
    }

    #[Test]
    public function app_url_ที่ไม่ใช่_https_ไม่ส่งโลโก้ภายในออกไป(): void
    {
        config(['app.url' => 'http://localhost']);
        Storage::disk('public')->put('token-logos/4289/up.png', 'png');
        $this->coreToken(['logo' => 'token-logos/4289/up.png']);

        $list = $this->list();

        $this->assertArrayNotHasKey('logoURI', $list['tokens'][0]);
        $this->assertArrayNotHasKey('logoURI', $list);
    }

    // =========================================================================
    //  HTTP — ETag/304, CORS, คุกกี้
    // =========================================================================

    #[Test]
    public function etag_และ_304(): void
    {
        $this->factoryToken();

        $first = $this->get('/api/v1/tokens/list')->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $raw = trim($etag, '"');

        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag);
        $this->assertNotNull($first->headers->get('Last-Modified'));
        $this->assertStringContainsString('max-age=300', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));

        $this->get('/api/v1/tokens/list', ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
        // Cloudflare ทำให้เป็น weak, Apache mod_deflate ต่อ -gzip
        $this->get('/tokenlist.json', ['If-None-Match' => 'W/'.$etag])->assertStatus(304);
        $this->get('/tokenlist.json', ['If-None-Match' => '"'.$raw.'-gzip"'])->assertStatus(304);
        $this->get('/tokenlist.json', ['If-None-Match' => '"other", '.$etag])->assertStatus(304);
        $this->get('/tokenlist.json', ['If-None-Match' => '"other"'])->assertOk();

        $lastModified = (string) $first->headers->get('Last-Modified');
        $this->get('/tokenlist.json', ['If-Modified-Since' => $lastModified])->assertStatus(304);
        $this->get('/tokenlist.json', ['If-Modified-Since' => 'Mon, 01 Jan 2001 00:00:00 GMT'])->assertOk();
        // มี If-None-Match ที่ไม่ตรง → ห้ามตอบ 304 ตาม If-Modified-Since
        $this->get('/tokenlist.json', ['If-None-Match' => '"other"', 'If-Modified-Since' => $lastModified])->assertOk();
    }

    #[Test]
    public function เนื้อหาเดิม_timestamp_และ_etag_เดิม_แม้แคชหมดอายุ(): void
    {
        $this->factoryToken();
        $first = $this->get('/tokenlist.json');

        $this->travel(10)->minutes();
        Cache::flush(); // แคช 5 นาทีหมดอายุ + ค่า SiteSetting ในแคชหาย

        $second = $this->get('/tokenlist.json');

        $this->assertSame($first->json('timestamp'), $second->json('timestamp'));
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    #[Test]
    public function cors_เปิดทุก_origin_เฉพาะสอง_route_นี้_และไม่มี_credentials(): void
    {
        $this->factoryToken();
        $headers = ['Origin' => 'https://explorer.tpix.online'];

        foreach (['/tokenlist.json', '/api/v1/tokens/list'] as $url) {
            $response = $this->get($url, $headers)->assertOk();
            $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'), $url);
            $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'), $url);
        }

        // API อื่นยังใช้ CORS เดิม (origin เดียวของเว็บ) — ไม่ได้เปิดกว้างไปด้วย
        $other = $this->get('/api/v1/tokens/0x'.str_repeat('1', 40), ['Origin' => 'https://evil.example']);
        $this->assertNotSame('*', $other->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('https://evil.example', $other->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function tokenlist_json_ไม่สร้าง_session_ไม่มีคุกกี้(): void
    {
        $response = $this->get('/tokenlist.json')->assertOk();

        $this->assertSame([], $response->headers->getCookies());
    }

    #[Test]
    public function api_tokens_address_เดิมยังทำงาน_ไม่ถูก_list_แย่ง(): void
    {
        // /list ต้องไม่ไปตกที่ /{address} และ /{address} ต้องไม่ถูกเส้นทางใหม่บัง
        $this->get('/api/v1/tokens/list')->assertOk()->assertJsonPath('name', 'TPIX Chain');
        $this->get('/api/v1/tokens/0x'.str_repeat('1', 40))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'TOKEN_NOT_FOUND');
    }

    // =========================================================================
    //  แคช 5 นาที — ต้องล้างทันทีเมื่อโลโก้/การลิสต์เปลี่ยน
    // =========================================================================

    #[Test]
    public function เปลี่ยนโลโก้แถว_tokens_แล้วรายการเปลี่ยนทันที(): void
    {
        $token = $this->coreToken(['logo' => 'https://cdn.example.com/old.png']);
        $before = $this->list();

        $token->update(['logo' => 'https://cdn.example.com/new.png']);
        $after = $this->list();

        $this->assertSame('https://cdn.example.com/new.png', $after['tokens'][0]['logoURI']);
        $this->assertNotSame($before['version']['patch'], $after['version']['patch']);
        $this->assertSame($before['version']['minor'], $after['version']['minor']);
    }

    #[Test]
    public function ลิสต์และเลิกลิสต์เหรียญจากโรงงานแล้วรายการเปลี่ยนทันที(): void
    {
        $token = $this->factoryToken(['is_listed' => false]);
        $this->assertSame([], $this->list()['tokens']);

        $token->update(['is_listed' => true]);
        $this->assertCount(1, $this->list()['tokens']);

        $token->update(['logo_url' => '/storage/token-logos/4289/x.png']);
        Storage::disk('public')->put('token-logos/4289/x.png', 'png');
        Cache::forget(TokenListService::CACHE_KEY); // ไฟล์เพิ่งถูกวางหลัง update
        $this->assertSame('https://tpix.online/storage/token-logos/4289/x.png', $this->list()['tokens'][0]['logoURI']);

        $token->update(['is_listed' => false]);
        $this->assertSame([], $this->list()['tokens']);
    }

    #[Test]
    public function deploy_สำเร็จผ่าน_query_builder_ก็ล้างแคช(): void
    {
        Queue::fake(); // งานยืนยันซอร์สบน explorer ไม่เกี่ยวกับเทสต์นี้
        $tx = '0x'.str_repeat('ab', 32);
        $token = $this->factoryToken(['status' => 'deploying', 'contract_address' => null, 'is_listed' => false, 'tx_hash' => $tx]);

        $this->get('/tokenlist.json');
        $this->assertTrue(Cache::has(TokenListService::CACHE_KEY));

        // FactoryDeploymentFinalizer::markDeployed อัปเดตด้วย query builder → model event ไม่ทำงาน
        $outcome = app(FactoryDeploymentFinalizer::class)->apply($token->id, [
            'success' => true,
            'contractAddress' => '0x'.str_repeat('7a', 20),
            'txHash' => $tx,
        ], 'test', $tx);

        $this->assertSame('deployed', $outcome);
        $this->assertFalse(Cache::has(TokenListService::CACHE_KEY));
    }

    #[Test]
    public function เปลี่ยนที่อยู่_wtpix_usdt_ในทะเบียนแล้วล้างแคช(): void
    {
        $this->get('/tokenlist.json');
        $this->assertTrue(Cache::has(TokenListService::CACHE_KEY));

        app(ContractRegistry::class)->forget('usdt_tpix');

        $this->assertFalse(Cache::has(TokenListService::CACHE_KEY));
    }
}
