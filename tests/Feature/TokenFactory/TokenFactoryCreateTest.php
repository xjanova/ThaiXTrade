<?php

namespace Tests\Feature\TokenFactory;

use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * ด่านฝั่งเซิร์ฟเวอร์ของการสร้างเหรียญ — ไม่ว่าหน้าเว็บจะส่งอะไรมา.
 *
 * กัน: ตัวเลือกที่ปิด/ล็อก · ไม่ติ๊กยอมรับความเสี่ยง · สัญลักษณ์สงวน · testnet ฟรี (4290)
 *      · สวมรอยเป็นผู้สร้าง · โลโก้จากโดเมนอื่น · สัญลักษณ์ unicode · สร้างถี่เกินโควตา
 */
class TokenFactoryCreateTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
        Queue::fake();
    }

    private function quote(array $form)
    {
        return $this->postJson('/api/v1/token-factory/quote', $form);
    }

    #[Test]
    public function ขอใบเสนอราคาเหรียญมาตรฐานได้_ยอดมาจากนโยบาย(): void
    {
        $response = $this->quote($this->tokenForm())->assertOk();

        $response->assertJsonPath('data.amount', '50');
        $response->assertJsonPath('data.amount_wei', '50000000000000000000');
        $response->assertJsonPath('data.fee_wallet', $this->feeWallet);
        $response->assertJsonPath('data.chain_id', 4289);
        $this->assertSame(1, TokenFactoryFeeQuote::count());
    }

    #[Test]
    public function ตัวเลือกที่ผู้ดูแลปิดไว้ถูกปฏิเสธ_แม้หน้าเว็บจะส่งมา(): void
    {
        // pausable ปิดเป็นค่าตั้งต้น
        $this->quote($this->tokenForm(['sub_options' => ['pausable' => true], 'risk_ack' => ['pausable']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sub_options.pausable');

        // mintable มาเป็นชนิดเหรียญ (token_type) ก็ต้องโดนเหมือนกัน
        $this->quote($this->tokenForm(['token_type' => 'mintable', 'risk_ack' => ['mintable']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sub_options.mintable');

        $this->assertSame(0, TokenFactoryFeeQuote::count());
    }

    #[Test]
    public function ของที่ล็อกถูกปฏิเสธพร้อมเหตุผล(): void
    {
        $this->quote($this->tokenForm(['token_type' => 'burnable', 'sub_options' => ['auto_burn_enabled' => true, 'auto_burn_rate' => 1]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sub_options.auto_burn');

        // ชื่อเก่า (auto_burn) ที่สคริปต์เคยรับเป็น alias ก็ต้องโดน
        $this->quote($this->tokenForm(['sub_options' => ['auto_burn' => '1']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sub_options.auto_burn');

        $response = $this->quote($this->tokenForm(['token_type' => 'utility']))->assertStatus(422);
        $this->assertStringContainsString('ล็อก', $response->json('errors.token_type.0'));
    }

    #[Test]
    public function เปิดของเสี่ยงแล้วต้องติ๊กยอมรับความเสี่ยงก่อน(): void
    {
        $this->setPolicy(['options' => ['pausable' => ['state' => 'on']]]);

        $this->quote($this->tokenForm(['sub_options' => ['pausable' => true]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('risk_ack');

        $this->quote($this->tokenForm(['sub_options' => ['pausable' => true], 'risk_ack' => ['pausable']]))
            ->assertOk()
            ->assertJsonPath('data.amount', '55');
    }

    #[Test]
    public function สัญลักษณ์สงวนใช้ไม่ได้(): void
    {
        foreach (['TPIX', 'usdt', 'IDTC'] as $symbol) {
            $this->quote($this->tokenForm(['symbol' => $symbol]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('symbol');
        }
    }

    #[Test]
    public function สัญลักษณ์ต้องเป็น_ascii_เท่านั้น(): void
    {
        $this->quote($this->tokenForm(['symbol' => 'ไทย']))->assertStatus(422)->assertJsonValidationErrors('symbol');
        $this->quote($this->tokenForm(['symbol' => 'ＧＯＯＤ']))->assertStatus(422)->assertJsonValidationErrors('symbol');
        $this->quote($this->tokenForm(['symbol' => 'GO-OD']))->assertStatus(422)->assertJsonValidationErrors('symbol');
    }

    #[Test]
    public function เชนทดสอบ_4290_ถูกปฏิเสธ_รับเฉพาะ_4289(): void
    {
        $this->quote($this->tokenForm(['chain_id' => 4290]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('chain_id');

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['chain_id' => 4290, 'quote_id' => (string) str()->uuid()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('chain_id');

        $this->assertSame(0, FactoryToken::count());
    }

    #[Test]
    public function สวมรอยเป็นผู้สร้างคนอื่นไม่ได้(): void
    {
        $victim = '0x2222222222222222222222222222222222222222';

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['creator_address' => $victim]))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');

        $this->quote($this->tokenForm(['creator_address' => $victim]))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');

        $this->assertSame(0, FactoryToken::count());
    }

    #[Test]
    public function โลโก้ต้องเป็นไฟล์ที่กระเป๋านี้อัปโหลดเอง(): void
    {
        Storage::fake('public');

        $this->quote($this->tokenForm(['logo_url' => 'https://evil.example/logo.png']))
            ->assertStatus(422)->assertJsonValidationErrors('logo_url');

        $other = '0x3333333333333333333333333333333333333333';
        Storage::disk('public')->put("token-logos/{$other}/a.png", 'x');
        $this->quote($this->tokenForm(['logo_url' => "/storage/token-logos/{$other}/a.png"]))
            ->assertStatus(422)->assertJsonValidationErrors('logo_url');

        Storage::disk('public')->put("token-logos/{$this->creator}/mine.png", 'x');
        $this->quote($this->tokenForm(['logo_url' => "/storage/token-logos/{$this->creator}/mine.png"]))->assertOk();
    }

    #[Test]
    public function ประเภทที่ยังไม่มีแฟกทอรีบนเชนสร้างไม่ได้(): void
    {
        // เปิด NFT ในนโยบาย แต่ไม่มีแฟกทอรี NFT — เดิมผ่านเพราะ "มีแฟกทอรีสักตัว" ก็ถือว่าพร้อม
        $this->setPolicy(['types' => ['nft' => ['state' => 'on']]]);
        \App\Models\SiteSetting::set(\App\Services\ContractRegistry::GROUP, 'nft_factory', '');

        $this->quote($this->tokenForm(['token_type' => 'nft', 'decimals' => 0, 'total_supply' => '1', 'risk_ack' => ['nft']]))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'FACTORY_NOT_READY');
    }

    #[Test]
    public function เหรียญฟรีสร้างได้โดยไม่ต้องมีธุรกรรมค่าธรรมเนียม(): void
    {
        $this->setPolicy(['types' => ['erc20' => ['fee' => 0]]]);

        $quoteId = $this->quote($this->tokenForm())->assertOk()->assertJsonPath('data.is_free', true)->json('data.quote_id');

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['quote_id' => $quoteId]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.chain_id', 4289);

        // ใบเสนอราคาใช้ได้ครั้งเดียว
        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['quote_id' => $quoteId, 'symbol' => 'GOOD2']))
            ->assertStatus(409);
    }

    #[Test]
    public function ใบเสนอราคาผูกกับตัวเลือก_เปลี่ยนตัวเลือกแล้วใช้ไม่ได้(): void
    {
        $this->setPolicy(['options' => ['pausable' => ['state' => 'on']]]);

        $quoteId = $this->quote($this->tokenForm())->assertOk()->json('data.quote_id');

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm([
            'quote_id' => $quoteId,
            'sub_options' => ['pausable' => true],
            'risk_ack' => ['pausable'],
            'fee_tx_hash' => '0x'.str_repeat('a', 64),
        ]))->assertStatus(422)->assertJsonPath('error.code', 'QUOTE_MISMATCH');
    }

    #[Test]
    public function สร้างได้ไม่เกินโควตาต่อกระเป๋าต่อวัน(): void
    {
        $this->setPolicy(['daily_limit_per_wallet' => 1]);

        FactoryToken::create([
            'name' => 'Old', 'symbol' => 'OLD', 'total_supply' => '1', 'creator_address' => $this->creator,
            'status' => 'rejected', 'chain_id' => 4289,
        ]);

        $this->quote($this->tokenForm())->assertStatus(429)->assertJsonPath('error.code', 'DAILY_LIMIT');
    }
}
