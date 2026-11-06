<?php

namespace Tests\Feature\TokenFactory;

use App\Models\FactoryToken;
use App\Services\TokenFactory\TokenOnchainFacts;
use App\Services\TokenFactory\TpixRpcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ไวท์เปเปอร์มาตรฐานของเหรียญ Token Factory — ข้อมูลเหรียญมาจากเชน อ่านไม่ได้ต้องบอกว่าอ่านไม่ได้ ไม่เดา.
 *
 * Developed by Xman Studio.
 */
class TokenWhitepaperTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function token(array $overrides = []): FactoryToken
    {
        return FactoryToken::create(array_merge([
            'name' => 'Siam Farm Token',
            'symbol' => 'SFARM',
            'decimals' => 18,
            'total_supply' => '1000000',
            'creator_address' => '0x'.str_repeat('ab', 20),
            'contract_address' => self::CONTRACT,
            'chain_id' => 4289,
            'token_type' => 'standard',
            'status' => 'deployed',
        ], $overrides));
    }

    private function word(string $hex): string
    {
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private function abiString(string $s): string
    {
        $hex = bin2hex($s);

        return '0x'.$this->word('20').$this->word(dechex(strlen($s))).str_pad($hex, (int) ceil(strlen($hex) / 64) * 64, '0');
    }

    /** RPC ปลอมตอบตาม selector — ฟังก์ชันที่ไม่อยู่ในแผนที่ตอบ revert */
    private function fakeChain(array $answers): void
    {
        $bySelector = [];
        foreach ($answers as $sig => $result) {
            $bySelector[TokenOnchainFacts::selector($sig)] = $result;
        }

        $this->mock(TpixRpcClient::class, function ($mock) use ($bySelector) {
            $mock->shouldReceive('call')->andReturnUsing(function (string $method, array $params) use ($bySelector) {
                $data = $params[0]['data'] ?? '';
                if (! array_key_exists($data, $bySelector)) {
                    throw new RuntimeException('execution reverted');
                }

                return $bySelector[$data];
            });
        });
    }

    private function fullChain(): void
    {
        $this->fakeChain([
            'name()' => $this->abiString('Siam Farm Token'),
            'symbol()' => $this->abiString('SFARM'),
            'decimals()' => '0x'.$this->word('12'),
            'totalSupply()' => '0x'.$this->word('d3c21bcecceda1000000'),   // 1,000,000 × 10^18
            'owner()' => '0x'.$this->word(''),                              // สละสิทธิ์แล้ว
            'isMintable()' => '0x'.$this->word('1'),
            'isPausable()' => '0x'.$this->word('0'),
            'isBlacklistEnabled()' => '0x'.$this->word('0'),
            'isAutoBurnEnabled()' => '0x'.$this->word('0'),
        ]);
    }

    #[Test]
    public function อ่านข้อเท็จจริงจากเชนได้ครบ(): void
    {
        $this->fullChain();

        $facts = app(TokenOnchainFacts::class)->for(self::CONTRACT);

        $this->assertTrue($facts['read']);
        $this->assertSame('Siam Farm Token', $facts['name']);
        $this->assertSame('SFARM', $facts['symbol']);
        $this->assertSame(18, $facts['decimals']);
        $this->assertStringStartsWith('1000000', str_replace(',', '', (string) $facts['total_supply']));
        $this->assertTrue($facts['renounced']);
        $this->assertSame(['mintable' => true, 'pausable' => false, 'blacklist' => false, 'auto_burn' => false], $facts['flags']);
    }

    #[Test]
    public function เชนล่ม_ทุกค่าเป็น_null_ไม่เดา(): void
    {
        $this->fakeChain([]);

        $facts = app(TokenOnchainFacts::class)->for(self::CONTRACT);

        $this->assertFalse($facts['read']);
        $this->assertNull($facts['name']);
        $this->assertNull($facts['total_supply']);
        $this->assertNull($facts['renounced']);
        $this->assertSame(['mintable' => null, 'pausable' => null, 'blacklist' => null, 'auto_burn' => null], $facts['flags']);
    }

    #[Test]
    public function หน้าไวท์เปเปอร์แสดงข้อมูลจากเชนและคำเตือน(): void
    {
        $this->fullChain();
        $token = $this->token();

        $this->get("/token-factory/{$token->id}/whitepaper")
            ->assertOk()
            ->assertSee('SFARM')
            ->assertSee(self::CONTRACT)
            ->assertSee(route('token-factory.whitepaper.pdf', $token->id), false);
    }

    #[Test]
    public function หน้าไวท์เปเปอร์บอกว่าอ่านจากเชนไม่ได้เมื่อ_rpc_ล่ม(): void
    {
        $this->fakeChain([]);
        $token = $this->token();

        $this->get("/token-factory/{$token->id}/whitepaper")
            ->assertOk()
            ->assertSee('อ่านจากเชนไม่ได้');
    }

    #[Test]
    public function ดาวน์โหลด_pd_f_ได้(): void
    {
        $this->fullChain();
        $token = $this->token();

        $res = $this->get("/token-factory/{$token->id}/whitepaper/pdf")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertStringContainsString('SFARM-Whitepaper-TPIX.pdf', (string) $res->headers->get('Content-Disposition'));
    }

    #[Test]
    public function เหรียญที่ยังไม่ขึ้นเชนไม่มีไวท์เปเปอร์(): void
    {
        Http::fake();
        $pending = $this->token(['status' => 'pending', 'contract_address' => null]);

        $this->get("/token-factory/{$pending->id}/whitepaper")->assertNotFound();
        $this->get("/token-factory/{$pending->id}/whitepaper/pdf")->assertNotFound();
        $this->get('/token-factory/999999/whitepaper')->assertNotFound();
        Http::assertNothingSent();
    }
}
