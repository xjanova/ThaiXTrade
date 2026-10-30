<?php

namespace Tests\Feature\TokenFactory;

use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * ค่าธรรมเนียมต้องจ่ายจริงบนเชน 4289 — ตรวจทุกช่องจากเชน ไม่เชื่อ client.
 *
 * เดิม fee_tx_hash ว่างได้และไม่เคยถูกตรวจ = เหรียญบน mainnet ฟรีไม่จำกัด
 */
class TokenFactoryFeeVerificationTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    private string $hash = '0xabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabca';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
        Queue::fake();
    }

    private function newQuote(array $form = []): string
    {
        return $this->postJson('/api/v1/token-factory/quote', $this->tokenForm($form))
            ->assertOk()
            ->json('data.quote_id');
    }

    private function create(string $quoteId, array $form = [])
    {
        return $this->postJson('/api/v1/token-factory/create', $this->tokenForm(array_merge([
            'quote_id' => $quoteId,
            'fee_tx_hash' => $this->hash,
        ], $form)));
    }

    #[Test]
    public function จ่ายถูกต้องครบบล็อกยืนยันแล้วสร้างคำขอได้_และบันทึก_hash(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash); // บล็อก 90, ล่าสุด 100 → 11 confirmations

        $this->create($quoteId)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.fee_tx_hash', $this->hash);

        $token = FactoryToken::firstOrFail();
        $this->assertSame($quoteId, $token->fee_quote_id);
        $this->assertSame(11, $token->metadata['fee_confirmations']);
        $this->assertNotNull(TokenFactoryFeeQuote::find($quoteId)->used_at);
    }

    #[Test]
    public function ไม่ส่ง_hash_มาเลยสร้างไม่ได้(): void
    {
        $quoteId = $this->newQuote();

        $this->create($quoteId, ['fee_tx_hash' => null])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'FEE_REQUIRED');
    }

    #[Test]
    public function โอนผิดกระเป๋าปฏิเสธ(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, ['to' => '0x4444444444444444444444444444444444444444']);

        $this->create($quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_WRONG_RECIPIENT');
        $this->assertSame(0, FactoryToken::count());
    }

    #[Test]
    public function โอนจากกระเป๋าอื่นปฏิเสธ(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, ['from' => '0x5555555555555555555555555555555555555555']);

        $this->create($quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_WRONG_SENDER');
    }

    #[Test]
    public function ยอดไม่ถึงปฏิเสธ(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, ['value' => self::tpixHex('49.999999')]);

        $this->create($quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_TOO_LOW');
    }

    #[Test]
    public function ธุรกรรมที่_revert_ปฏิเสธ(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, [], ['status' => '0x0']);

        $this->create($quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_TX_FAILED');
    }

    #[Test]
    public function ผิดเชนปฏิเสธ(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, ['chainId' => '0x38']); // BSC

        $this->create($quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_WRONG_CHAIN');
    }

    #[Test]
    public function ใช้_hash_เดิมซ้ำไม่ได้(): void
    {
        // ขอใบที่สองไว้ก่อน — หลังคำขอแรกเข้าไป กระเป๋านี้ขอใบเสนอราคาใหม่ไม่ได้จนกว่าคำขอแรกจะจบ
        $first = $this->newQuote();
        $second = $this->newQuote(['symbol' => 'AGAIN']);
        $this->putFeeTx($this->hash);
        $this->create($first)->assertCreated();

        $this->create($second, ['symbol' => 'AGAIN'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FEE_TX_USED');

        $this->assertSame(1, FactoryToken::count());
    }

    #[Test]
    public function บล็อกยืนยันไม่ครบตอบ_pending_ให้ถามใหม่(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, [], [], null, 100); // บล็อกเดียวกับล่าสุด = 1 confirmation (ต้องการ 2)

        $this->create($quoteId)
            ->assertStatus(202)
            ->assertJsonPath('error.code', 'FEE_PENDING')
            ->assertJsonPath('error.confirmations', 1)
            ->assertJsonPath('error.required', 2);

        $this->assertSame(0, FactoryToken::count());
        $this->assertNull(TokenFactoryFeeQuote::find($quoteId)->used_at, 'ยังไม่ครบต้องไม่เผาใบเสนอราคาทิ้ง');

        // เชนเดินต่อ → ครบแล้วสร้างได้
        $this->chain['latest'] = '0x65';
        $this->create($quoteId)->assertCreated();
    }

    #[Test]
    public function ธุรกรรมยังไม่ขึ้นบล็อกตอบ_pending(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, ['blockNumber' => null], ['__none__' => true]);

        $this->postJson('/api/v1/token-factory/fee-status', [
            'wallet_address' => $this->creator,
            'quote_id' => $quoteId,
            'fee_tx_hash' => $this->hash,
        ])->assertStatus(202)->assertJsonPath('error.code', 'FEE_PENDING');

        $this->create($quoteId)->assertStatus(202)->assertJsonPath('error.code', 'FEE_PENDING');
    }

    #[Test]
    public function ธุรกรรมที่เกิดก่อนออกใบเสนอราคาใช้ไม่ได้(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash, [], [], now()->subHour()->getTimestamp());

        $this->create($quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_TX_BEFORE_QUOTE');
    }

    #[Test]
    public function ใบเสนอราคาของกระเป๋าอื่นใช้ไม่ได้(): void
    {
        $quoteId = $this->newQuote();

        $other = '0x6666666666666666666666666666666666666666';
        $this->verifyWallet($other);
        $this->putFeeTx($this->hash, ['from' => $other]);

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm([
            'wallet_address' => $other,
            'creator_address' => $other,
            'quote_id' => $quoteId,
            'fee_tx_hash' => $this->hash,
        ]))->assertStatus(422)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
    }

    #[Test]
    public function สถานะค่าธรรมเนียมครบแล้วตอบ_confirmed(): void
    {
        $quoteId = $this->newQuote();
        $this->putFeeTx($this->hash);

        $this->postJson('/api/v1/token-factory/fee-status', [
            'wallet_address' => $this->creator,
            'quote_id' => $quoteId,
            'fee_tx_hash' => $this->hash,
        ])->assertOk()->assertJsonPath('data.confirmed', true)->assertJsonPath('data.confirmations', 11);
    }
}
