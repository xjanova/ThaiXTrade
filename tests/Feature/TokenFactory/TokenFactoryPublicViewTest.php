<?php

namespace Tests\Feature\TokenFactory;

use App\Models\FactoryToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ข้อมูลเหรียญที่คนนอกเห็น — ห้ามหลุด metadata (stderr ตอน deploy, ตัวเลือก, ค่าธรรมเนียม, เหตุผลที่ถูกปฏิเสธ).
 */
class TokenFactoryPublicViewTest extends TestCase
{
    use RefreshDatabase;

    private string $creator = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function failedToken(): FactoryToken
    {
        return FactoryToken::create([
            'name' => 'Pending', 'symbol' => 'PEND', 'total_supply' => '1000', 'creator_address' => $this->creator,
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => 'rejected',
            'reject_reason' => 'สแปม', 'fee_tx_hash' => '0x'.str_repeat('a', 64),
            'deploy_raw_tx' => '0x02deadbeef',
            'metadata' => ['deploy_error' => 'Error: stderr /var/www/secret/path', 'sub_options' => ['pausable' => true], 'fee_wallet' => '0x99'],
        ]);
    }

    #[Test]
    public function คนทั่วไปไม่เห็น_metadata_ของคำขอ(): void
    {
        $token = $this->failedToken();

        $response = $this->getJson("/api/v1/token-factory/{$token->id}")->assertOk();

        $this->assertArrayNotHasKey('metadata', $response->json('data'));
        $this->assertArrayNotHasKey('reject_reason', $response->json('data'));
        $this->assertArrayNotHasKey('fee_tx_hash', $response->json('data'));
        $this->assertNull($response->json('data.creator_address'), 'คำขอที่ยังไม่ deploy ไม่บอกว่าใครขอ');
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('deadbeef', $response->getContent());
    }

    #[Test]
    public function ใส่กระเป๋าผู้สร้างโดยไม่ได้พิสูจน์ก็ไม่เห็น(): void
    {
        $token = $this->failedToken();

        $response = $this->getJson("/api/v1/token-factory/{$token->id}?wallet_address={$this->creator}")->assertOk();

        $this->assertArrayNotHasKey('metadata', $response->json('data'));
    }

    #[Test]
    public function ผู้สร้างที่พิสูจน์กระเป๋าแล้วเห็นรายละเอียดของตัวเอง_ยกเว้นธุรกรรมดิบ(): void
    {
        $token = $this->failedToken();
        Cache::put("wallet_verified:{$this->creator}", ['ip' => '127.0.0.1'], 3600);

        $response = $this->getJson("/api/v1/token-factory/{$token->id}?wallet_address={$this->creator}")->assertOk();

        $this->assertSame('สแปม', $response->json('data.reject_reason'));
        $this->assertArrayHasKey('deploy_error', $response->json('data.metadata'));
        $this->assertStringNotContainsString('deadbeef', $response->getContent());
    }

    #[Test]
    public function รายการสาธารณะส่งเฉพาะช่องที่เปิดเผยได้(): void
    {
        FactoryToken::create([
            'name' => 'Listed', 'symbol' => 'LIST', 'total_supply' => '1000', 'creator_address' => $this->creator,
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => 'deployed', 'is_listed' => true,
            'contract_address' => '0x'.str_repeat('c', 40), 'metadata' => ['fee_wallet' => '0x99', 'deploy_error' => 'x'],
        ]);

        $response = $this->getJson('/api/v1/token-factory')->assertOk();

        $this->assertSame('LIST', $response->json('data.0.symbol'));
        $this->assertSame('0x'.str_repeat('c', 40), $response->json('data.0.contract_address'));
        $this->assertArrayNotHasKey('metadata', $response->json('data.0'));
    }
}
