<?php

namespace Tests\Feature\TokenFactory;

use App\Models\FactoryToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * คำขอที่ยังไม่จบ + ตัวนับความถี่ของ Token Factory.
 *
 * กัน: จ่ายค่าธรรมเนียมใบใหม่ทั้งที่คำขอเดิมยังค้าง · ผู้ใช้ที่จ่ายแล้วกด "ส่งคำขอต่อ" ไม่ได้
 *      · คนอื่นจองสัญลักษณ์ที่สร้างไม่สำเร็จ (ทีมงานยังสั่งสร้างใหม่ได้)
 *      · /create โดน 429 เพราะใช้ตัวนับเดียวกับ API ทั้งเว็บ (เกิดจริงกับ IDTC 2026-10-27)
 */
class TokenFactoryInProgressTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    private string $hash = '0xdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefdefd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
        Queue::fake();
    }

    private function existing(string $status, array $attrs = []): FactoryToken
    {
        return FactoryToken::create(array_merge([
            'name' => 'Open Coin', 'symbol' => 'OPEN', 'total_supply' => '1', 'creator_address' => $this->creator,
            'status' => $status, 'chain_id' => 4289,
        ], $attrs));
    }

    #[Test]
    public function มีคำขอค้าง_ขอใบเสนอราคาใหม่ไม่ได้_พร้อมบอกขั้นตอนชัดเจน(): void
    {
        foreach (['pending' => 'รอผู้ดูแล', 'deploying' => 'กำลังสร้างเหรียญบนเชน', 'failed' => 'ค่าธรรมเนียมที่จ่ายแล้วไม่หาย'] as $status => $stage) {
            FactoryToken::query()->delete();
            $this->existing($status);

            $response = $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'REQUEST_IN_PROGRESS');

            $message = $response->json('error.message');
            $this->assertStringContainsString('OPEN', $message, $status);
            $this->assertStringContainsString($stage, $message, $status);
        }
    }

    #[Test]
    public function คำขอเดิมจบแล้ว_สร้างใหม่ได้(): void
    {
        foreach (['deployed', 'rejected'] as $status) {
            FactoryToken::query()->delete();
            $this->existing($status);

            $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk();
        }
    }

    #[Test]
    public function คำขอค้างของกระเป๋าอื่นไม่กันกระเป๋านี้(): void
    {
        $this->existing('pending', ['creator_address' => '0x9999999999999999999999999999999999999999']);

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk();
    }

    #[Test]
    public function จ่ายแล้วส่งคำขอต่อได้_แม้ขอใบเสนอราคาไว้ก่อนคำขออื่นจะค้าง(): void
    {
        // ด่านคำขอค้างอยู่ที่ใบเสนอราคาเท่านั้น — /create ต้องไม่ติด ไม่งั้นคนที่จ่ายแล้วค้างตลอด
        $quoteId = $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk()->json('data.quote_id');
        $this->existing('failed');
        $this->putFeeTx($this->hash);

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['quote_id' => $quoteId, 'fee_tx_hash' => $this->hash]))
            ->assertCreated()
            ->assertJsonPath('data.symbol', 'GOOD');
    }

    #[Test]
    public function สัญลักษณ์ที่สร้างไม่สำเร็จยังถูกจอง_จนกว่าจะถูกปฏิเสธ(): void
    {
        $other = '0x9999999999999999999999999999999999999999';
        $failed = $this->existing('failed', ['symbol' => 'GOOD', 'creator_address' => $other]);

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())
            ->assertStatus(422)
            ->assertJsonValidationErrors('symbol');

        $failed->update(['status' => 'rejected']);
        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk();
    }

    #[Test]
    public function เรียก_api_อื่นถี่ๆ_แล้วยังส่งคำขอสร้างได้(): void
    {
        $quoteId = $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk()->json('data.quote_id');
        $this->putFeeTx($this->hash);

        // หน้าเว็บถาม fee-status ระหว่างรอยืนยัน + ดึงราคา/คอนฟิก — เดิมนับรวมกับ throttle:10,60 ของ /create
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/v1/token-factory/config')->assertOk();
        }

        $this->postJson('/api/v1/token-factory/create', $this->tokenForm(['quote_id' => $quoteId, 'fee_tx_hash' => $this->hash]))
            ->assertCreated();
    }
}
