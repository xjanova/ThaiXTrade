<?php

namespace Tests\Feature\TokenFactory;

use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use App\Services\TokenFactory\TokenFactoryException;
use App\Services\TokenFactory\TokenFactoryFeeVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * ยืนยันซอร์สโค้ดบน explorer เป็นบริการเสียเงิน (1,000 TPIX) — ติ๊กตอนสร้าง หรือซื้อทีหลังเมื่อ deploy แล้ว.
 */
class TokenFactorySourceVerifyTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    private string $hash = '0x4444444444444444444444444444444444444444444444444444444444444444';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
        Queue::fake();

        // ต้องมีไฟล์ standard input ถึงจะรับเงินค่ายืนยัน (ยืนยันไม่ได้ = ห้ามเก็บเงิน)
        $this->dir = storage_path('framework/testing/verify-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/FactoryERC20V2.json', '{}');
        config(['blockchain.explorer_standard_input_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function deployed(array $attributes = []): FactoryToken
    {
        return FactoryToken::create(array_merge([
            'name' => 'Good Coin', 'symbol' => 'GOOD', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => $this->creator, 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'deployed',
            'contract_address' => '0x'.str_repeat('c', 40), 'metadata' => ['factory_version' => 'v2'],
        ], $attributes));
    }

    private function quote(FactoryToken $token, ?string $wallet = null)
    {
        return $this->postJson("/api/v1/token-factory/{$token->id}/source-verify/quote", ['wallet_address' => $wallet ?? $this->creator]);
    }

    private function purchase(FactoryToken $token, string $quoteId, ?string $hash = null)
    {
        return $this->postJson("/api/v1/token-factory/{$token->id}/source-verify", [
            'wallet_address' => $this->creator,
            'quote_id' => $quoteId,
            'fee_tx_hash' => $hash ?? $this->hash,
        ]);
    }

    #[Test]
    public function ซื้อทีหลัง_จ่าย_1000_tpi_x_แล้วส่งให้_explorer(): void
    {
        $token = $this->deployed(['metadata' => ['factory_version' => 'v2', 'explorer_verification' => ['status' => 'skipped']]]);

        $quoteId = $this->quote($token)
            ->assertOk()
            ->assertJsonPath('data.amount', '1000')
            ->assertJsonPath('data.fee_wallet', $this->feeWallet)
            ->json('data.quote_id');
        $this->assertSame(TokenFactoryFeeQuote::PURPOSE_SOURCE_VERIFY, TokenFactoryFeeQuote::find($quoteId)->purpose);

        $this->putFeeTx($this->hash, ['value' => self::tpixHex('1000')]);
        $this->purchase($token, $quoteId)->assertOk()->assertJsonPath('success', true);

        $fresh = $token->fresh();
        $this->assertTrue($fresh->sourceVerifyPaid());
        $this->assertSame('purchase', $fresh->metadata['source_verify']['via']);
        $this->assertSame($this->hash, $fresh->source_verify_tx_hash);
        $this->assertArrayNotHasKey('explorer_verification', $fresh->metadata, 'ผล skipped เดิมต้องถูกล้าง ไม่งั้นงานยืนยันจะข้าม');
        $this->assertNotNull(TokenFactoryFeeQuote::find($quoteId)->used_at);
        Queue::assertPushed(VerifyFactoryTokenOnExplorerJob::class);

        // จ่ายแล้วขอใบใหม่ไม่ได้
        $this->quote($token)->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_VERIFY_ALREADY_PAID');
    }

    #[Test]
    public function โอนไม่ครบยอดถูกปฏิเสธ(): void
    {
        $token = $this->deployed();
        $quoteId = $this->quote($token)->json('data.quote_id');
        $this->putFeeTx($this->hash, ['value' => self::tpixHex('999')]);

        $this->purchase($token, $quoteId)->assertStatus(422)->assertJsonPath('error.code', 'FEE_TOO_LOW');
        $this->assertFalse($token->fresh()->sourceVerifyPaid());
        Queue::assertNotPushed(VerifyFactoryTokenOnExplorerJob::class);
    }

    #[Test]
    public function ธุรกรรมค่าสร้างเหรียญเอามาจ่ายค่ายืนยันซ้ำไม่ได้(): void
    {
        $this->deployed(['symbol' => 'OLD', 'fee_tx_hash' => $this->hash, 'contract_address' => '0x'.str_repeat('d', 40)]);
        $token = $this->deployed();

        $quoteId = $this->quote($token)->json('data.quote_id');
        $this->putFeeTx($this->hash, ['value' => self::tpixHex('1000')]);

        $this->purchase($token, $quoteId)->assertStatus(409)->assertJsonPath('error.code', 'FEE_TX_USED');
    }

    #[Test]
    public function ใบเสนอราคาสองแบบใช้แทนกันไม่ได้(): void
    {
        $token = $this->deployed();
        $createQuote = $this->postJson('/api/v1/token-factory/quote', $this->tokenForm(['symbol' => 'NEW']))->json('data.quote_id');

        $this->putFeeTx($this->hash, ['value' => self::tpixHex('1000')]);
        $this->purchase($token, $createQuote)->assertStatus(422)->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');

        $verifyQuote = $this->quote($token)->json('data.quote_id');
        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['symbol' => 'NEW', 'quote_id' => $verifyQuote, 'fee_tx_hash' => $this->hash]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
    }

    #[Test]
    public function ซื้อให้เหรียญของคนอื่น_หรือเหรียญที่ยังไม่_deploy_ไม่ได้(): void
    {
        $other = '0x2222222222222222222222222222222222222222';
        $this->verifyWallet($other);

        $mine = $this->deployed();
        $this->quote($mine, $other)->assertNotFound()->assertJsonPath('error.code', 'TOKEN_NOT_FOUND');

        $pending = $this->deployed(['symbol' => 'WAIT', 'status' => 'pending', 'contract_address' => null]);
        $this->quote($pending)->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_VERIFY_NOT_DEPLOYED');

        $verified = $this->deployed(['symbol' => 'DONE', 'contract_address' => '0x'.str_repeat('e', 40), 'metadata' => ['explorer_verification' => ['status' => 'verified']]]);
        $this->quote($verified)->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_VERIFY_ALREADY_VERIFIED');
    }

    #[Test]
    public function ยังไม่มีไฟล์ซอร์สของสัญญานี้_ไม่รับเงิน(): void
    {
        File::delete($this->dir.'/FactoryERC20V2.json');

        $this->quote($this->deployed())->assertStatus(422)->assertJsonPath('error.code', 'SOURCE_VERIFY_UNSUPPORTED');
        $this->assertSame(0, TokenFactoryFeeQuote::count());
    }

    #[Test]
    public function ปิดบริการในนโยบาย_ขอใบเสนอราคาไม่ได้(): void
    {
        $this->setPolicy(['options' => ['source_verify' => ['state' => 'off']]]);

        $this->quote($this->deployed())->assertForbidden()->assertJsonPath('error.code', 'SOURCE_VERIFY_DISABLED');
    }

    #[Test]
    public function ติ๊กตอนสร้างแต่ยังไม่มีไฟล์ซอร์ส_ไม่ออกใบเสนอราคา(): void
    {
        File::delete($this->dir.'/FactoryERC20V2.json');

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm(['sub_options' => ['source_verify' => true]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_VERIFY_UNSUPPORTED');

        $this->assertSame(0, TokenFactoryFeeQuote::count());
    }

    #[Test]
    public function ไม่ติ๊กยืนยันซอร์ส_ไม่ต้องมีไฟล์ก็สร้างเหรียญได้(): void
    {
        File::delete($this->dir.'/FactoryERC20V2.json');

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk();
    }

    #[Test]
    public function ปิดการส่งให้_explorer_อยู่_ไม่รับเงินทั้งตอนสร้างและซื้อทีหลัง(): void
    {
        config(['blockchain.explorer_verify_enabled' => false]);

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm(['sub_options' => ['source_verify' => true]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_VERIFY_UNSUPPORTED');
        $this->quote($this->deployed())->assertStatus(422)->assertJsonPath('error.code', 'SOURCE_VERIFY_UNSUPPORTED');

        $this->assertSame(0, TokenFactoryFeeQuote::count());
    }

    #[Test]
    public function ติ๊กตอนสร้าง_ใบเสนอราคารวม_1000_และบันทึกว่าจ่ายแล้ว(): void
    {
        $form = $this->tokenForm(['sub_options' => ['source_verify' => true]]);

        $quote = $this->postJson('/api/v1/token-factory/quote', $form)->assertOk();
        $this->assertSame('1050', $quote->json('data.amount')); // 50 (มาตรฐาน) + 1,000

        $this->putFeeTx($this->hash, ['value' => self::tpixHex('1050')]);
        $this->postJson('/api/v1/token-factory/create', $form + ['quote_id' => $quote->json('data.quote_id'), 'fee_tx_hash' => $this->hash])
            ->assertCreated();

        $token = FactoryToken::firstOrFail();
        $this->assertTrue($token->sourceVerifyPaid());
        $this->assertSame('create', $token->metadata['source_verify']['via']);
        $this->assertSame('1050000000000000000000', $token->metadata['fee_value_wei']);
    }

    #[Test]
    public function ล็อกที่_hash_ถามซ้ำก่อนเขียน_กันสองคำขอพร้อมกันใช้ธุรกรรมเดียว(): void
    {
        // จำลองคำขออีกฝั่งที่เขียนเสร็จระหว่างที่ฝั่งนี้ตรวจบนเชน
        $this->deployed(['source_verify_tx_hash' => $this->hash]);

        $wrote = false;

        try {
            TokenFactoryFeeVerifier::claimTx(strtoupper($this->hash), function () use (&$wrote) {
                $wrote = true;
            });
            $this->fail('ต้องปฏิเสธธุรกรรมที่ถูกใช้แล้ว');
        } catch (TokenFactoryException $e) {
            $this->assertSame('FEE_TX_USED', $e->errorCode);
        }

        $this->assertFalse($wrote);
    }
}
