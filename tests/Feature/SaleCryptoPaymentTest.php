<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\SaleCryptoOrder;
use App\Models\SaleCryptoPayment;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Services\SaleCrypto\CryptoPaymentCrediter;
use App\Services\SaleCrypto\CryptoPaymentScanner;
use App\Services\SaleCrypto\SaleCryptoConfig;
use App\Services\TokenSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * ซื้อ TPIX ด้วย USDT — คำสั่งซื้อล็อกราคา + ยอดเฉพาะ → ตัวสแกนเห็นเงินเข้า → ออกเหรียญเอง.
 *
 * สิ่งที่ห้ามพัง (แต่ละข้อพลาดแล้วเสียเงินจริง):
 *   1. ด่านทั้งหมดต้องอยู่ "ก่อน" เงินออก (เหตุผลที่ทางคริปโตเดิมถูกถอด) และตรงกับบัตรทุกข้อ
 *   2. หนึ่ง log ออกเหรียญได้ครั้งเดียว ไม่ว่าจะสแกนซ้ำกี่รอบ
 *   3. เงินที่เข้ามาแต่ออกเหรียญเองไม่ได้ต้องมีแถวเสมอ (needs_review) ไม่หายเงียบ
 *   4. ราคาที่ล็อกตัดสินด้วย "เวลาบล็อก" ไม่ใช่เวลาที่ตัวสแกนเห็น
 *   5. ทางยื่น tx_hash แบบเก่ายังปิดอยู่ แม้รอบขายจะเปิดรับ CRYPTO แล้ว
 */
class SaleCryptoPaymentTest extends TestCase
{
    use FakesBridgeChains;
    use RefreshDatabase;

    private string $saleWallet = '0x5a1e000000000000000000000000000000005a1e';

    private string $buyer = '0xbbbb00000000000000000000000000000000bbbb';

    private string $exchange = '0xec00000000000000000000000000000000000ec0';

    private string $usdcBsc = '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d';

    private TokenSale $sale;

    private SalePhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();

        config([
            'sale_crypto.enabled' => true,
            'sale_crypto.chains' => [56, 1],
            'treasury.hot_wallet.address' => '0x4444444444444444444444444444444444444444',
        ]);

        // บล็อก 1000 ของ BSC ≈ ตอนนี้ — เทสต์เรื่อง "โอนทันเวลาไหม" ต้องใช้เวลาบล็อกที่สมจริง
        $this->chainState[56]['time_base'] = now()->timestamp - 2000;
        $this->chainState[1]['time_base'] = now()->timestamp - 10000;

        // กระเป๋ารับเงินเคยเซ็นธุรกรรมออกแล้ว = มีคนถือคีย์จริง
        $this->chainState[56]['nonce'][$this->saleWallet] = 1;

        $this->sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'accept_currencies' => ['CARD', 'CRYPTO'],
            'accept_chain_id' => 4289,
            'sale_wallet_address' => $this->saleWallet,
            'ends_at' => now()->addDays(60),
        ]);

        $this->phase = SalePhase::create([
            'token_sale_id' => $this->sale->id,
            'name' => 'Private Sale',
            'slug' => 'private-sale',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 1000000,
            'min_purchase' => 100,
            'max_purchase' => 1000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);

        $this->verifyWallet($this->buyer);

        // รอบแรกของตัวสแกน — ตั้งเคอร์เซอร์ที่หัวปัจจุบัน + บอกว่าตัวสแกนทำงานอยู่ (เปิดรับคำสั่งซื้อได้)
        $this->scan();
    }

    // ── ตัวช่วย ──────────────────────────────────────────────────────────

    private function verifyWallet(string $wallet): void
    {
        Cache::put('wallet_verified:'.strtolower($wallet), [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    private function scan(): void
    {
        $this->artisan('sale:scan-crypto')->assertSuccessful();
    }

    /** ให้บล็อกที่โอนไว้นิ่งแล้ว (BSC: หัวที่ปลอดภัย = min(finalized, latest − 15)) แล้วสแกน */
    private function finalizeAndScan(int $latest = 1100): void
    {
        $this->setLatest(56, $latest, $latest - 10);
        $this->scan();
    }

    private function order(float $usd = 10, int $chainId = 56, ?string $wallet = null): array
    {
        $response = $this->postJson('/api/v1/token-sale/crypto-order', [
            'wallet_address' => $wallet ?? $this->buyer,
            'phase_id' => $this->phase->id,
            'amount_usd' => $usd,
            'chain_id' => $chainId,
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        return $response->json('data');
    }

    private function pay(array $order, ?string $from = null, ?string $raw = null, int $block = 1000, ?string $token = null): string
    {
        return $this->fakeTransfer(56, $from ?? $this->buyer, $this->saleWallet, $raw ?? $order['pay_amount_raw'], $block, token: $token);
    }

    // ── เส้นทางปกติ ──────────────────────────────────────────────────────

    #[Test]
    public function an_exact_payment_is_credited_once_through_the_same_pipeline_as_a_card_purchase(): void
    {
        $order = $this->order(10);

        // ยอดเฉพาะ = ค่าเหรียญ + เศษท้ายไม่ถึง 1 เซ็นต์ (ไม่มีค่าบริการบัตร)
        $this->assertSame(strtolower($this->saleWallet), $order['pay_to_address']);
        $this->assertSame('USDT', $order['token_symbol']);
        $this->assertSame(18, $order['token_decimals']);
        $this->assertSame(1, bccomp($order['pay_amount_raw'], '10000000000000000000', 0));
        $this->assertSame(-1, bccomp($order['pay_amount_raw'], '10010000000000000000', 0));
        $this->assertEquals(200, $order['tpix_amount']);

        $txHash = $this->pay($order);
        $this->finalizeAndScan();
        $this->scan(); // สแกนซ้ำต้องไม่ออกเหรียญซ้ำ

        $this->assertSame(1, SaleTransaction::count());
        $tx = SaleTransaction::first();
        $this->assertSame('confirmed', $tx->status);
        $this->assertSame(strtolower($this->buyer), $tx->wallet_address);
        $this->assertSame('USDT_BSC', $tx->payment_currency);
        $this->assertEquals(10, (float) $tx->payment_usd_value);
        $this->assertEquals(200, (float) $tx->tpix_amount);
        $this->assertSame("crypto_56_{$txHash}_0", $tx->tx_hash);
        // vesting เริ่มที่วันจบเฟส เหมือนบัตร
        $this->assertSame($this->phase->ends_at->toDateTimeString(), $tx->vesting_start_at->toDateTimeString());

        $this->assertEquals(200, (float) $this->phase->fresh()->sold);
        $this->assertEquals(10, (float) $this->sale->fresh()->total_raised_usd);

        $status = $this->getJson("/api/v1/token-sale/crypto-order/{$order['id']}?wallet_address={$this->buyer}")
            ->assertOk()
            ->json('data');
        $this->assertSame('paid', $status['status']);
        $this->assertSame($tx->uuid, $status['transaction_id']);

        // สายจ่ายเหรียญเดียวกับบัตร: เคลมส่วน TGE 20% → เข้าคิวจ่ายจากกระเป๋าจ่ายเหรียญ
        $this->postJson('/api/v1/token-sale/claim', [
            'wallet_address' => $this->buyer,
            'transaction_id' => $tx->uuid,
            'amount' => 40,
        ])->assertOk();
        $this->assertSame(1, TreasuryPayout::where('purpose', 'token_sale')->count());
    }

    #[Test]
    public function a_payment_from_an_exchange_with_the_exact_amount_still_credits_the_order_wallet(): void
    {
        $order = $this->order(10);
        $this->pay($order, from: $this->exchange);
        $this->finalizeAndScan();

        $this->assertSame(strtolower($this->buyer), SaleTransaction::sole()->wallet_address);
    }

    #[Test]
    public function a_payment_made_in_time_but_seen_after_expiry_is_still_credited(): void
    {
        $order = $this->order(10);
        $this->pay($order, block: 1000); // เวลาบล็อก ≈ ตอนนี้ (ก่อนหมดอายุ)

        // ตัวสแกนช้าไป 2 ชั่วโมง — คำสั่งซื้อถูกปัดเป็น expired ไปแล้ว
        $this->travel(2)->hours();
        $this->verifyWallet($this->buyer);
        $this->finalizeAndScan();

        $this->assertSame(SaleCryptoOrder::STATUS_PAID, SaleCryptoOrder::sole()->status);
        $this->assertSame(1, SaleTransaction::count());
    }

    #[Test]
    public function each_order_gets_a_distinct_amount_and_reclicking_returns_the_same_order(): void
    {
        $a = $this->order(10);
        $again = $this->order(10);
        $this->assertSame($a['id'], $again['id']);

        $other = '0xcccc00000000000000000000000000000000cccc';
        $this->verifyWallet($other);
        $b = $this->order(10, wallet: $other);

        $this->assertNotSame($a['pay_amount_raw'], $b['pay_amount_raw']);
        $this->assertSame(2, SaleCryptoOrder::count());
    }

    // ── ด่านก่อนเงินออก = ด่านเดียวกับบัตร ────────────────────────────────

    #[Test]
    public function orders_are_refused_before_any_money_moves_when_a_card_gate_would_refuse(): void
    {
        $post = fn (array $override = []) => $this->postJson('/api/v1/token-sale/crypto-order', array_merge([
            'wallet_address' => $this->buyer,
            'phase_id' => $this->phase->id,
            'amount_usd' => 10,
            'chain_id' => 56,
        ], $override));

        // ต่ำกว่าขั้นต่ำ 100 TPIX ($5)
        $post(['amount_usd' => 2])->assertStatus(409)->assertJsonPath('error.code', 'NOT_PURCHASABLE');

        // เกินโควตาที่เหลือ
        $this->phase->update(['sold' => 999990]);
        $post()->assertStatus(409);
        $this->phase->update(['sold' => 0]);

        // เฟสหมดเวลา
        $this->phase->update(['ends_at' => now()->subMinute()]);
        Cache::flush();
        $this->verifyWallet($this->buyer);
        $post()->assertStatus(409);
        $this->phase->update(['ends_at' => now()->addDays(30)]);

        // เชนที่ไม่ได้เปิดรับชำระ (Polygon ไม่อยู่ใน sale_crypto.chains)
        $post(['chain_id' => 137])->assertStatus(409);

        // กระเป๋าไม่ได้ยืนยันเจ้าของ
        $this->postJson('/api/v1/token-sale/crypto-order', [
            'wallet_address' => '0xdddd00000000000000000000000000000000dddd',
            'phase_id' => $this->phase->id,
            'amount_usd' => 10,
            'chain_id' => 56,
        ])->assertStatus(403);

        $this->assertSame(0, SaleCryptoOrder::count());
    }

    #[Test]
    public function crypto_stays_closed_unless_the_sale_accepts_it_and_the_wallet_proved_its_key(): void
    {
        $post = fn () => $this->postJson('/api/v1/token-sale/crypto-order', [
            'wallet_address' => $this->buyer, 'phase_id' => $this->phase->id, 'amount_usd' => 10, 'chain_id' => 56,
        ]);

        // รอบขายไม่ได้ประกาศรับ CRYPTO (getActiveSale แคช 30 วิ — หลังบ้านล้างให้เองตอนแก้ ดู forgetSaleCache)
        $this->sale->update(['accept_currencies' => ['CARD']]);
        Cache::forget('token_sale:active');
        $post()->assertStatus(409);
        $this->assertFalse($this->getJson('/api/v1/token-sale')->json('data.payment_methods.crypto'));
        $this->sale->update(['accept_currencies' => ['CARD', 'CRYPTO']]);
        Cache::flush();
        $this->verifyWallet($this->buyer);

        // กระเป๋ารับเงินไม่เคยเซ็นธุรกรรมออก (nonce 0) — แบบเดียวกับ 0xF1CD… ที่ทำให้ทางเดิมถูกถอด
        $this->chainState[56]['nonce'][$this->saleWallet] = 0;
        $this->chainState[1]['nonce'][$this->saleWallet] = 0;
        $post()->assertStatus(409);
        $this->chainState[56]['nonce'][$this->saleWallet] = 1;
        Cache::flush();
        $this->verifyWallet($this->buyer);

        // ใช้ vault ของสะพานเป็นกระเป๋ารับเงินไม่ได้ (สะพานจะ mint USDT_TPIX ให้ทุกยอดซื้อ)
        $this->sale->update(['sale_wallet_address' => $this->vault]);
        $post()->assertStatus(409);
        $this->sale->update(['sale_wallet_address' => $this->saleWallet]);

        // ไม่ได้ติดตั้ง
        config(['sale_crypto.enabled' => false]);
        $post()->assertStatus(409);
        config(['sale_crypto.enabled' => true]);

        $this->assertSame(0, SaleCryptoOrder::count());
        Cache::forget('token_sale:active');
        $this->assertTrue($this->getJson('/api/v1/token-sale')->json('data.payment_methods.crypto'));
        $this->assertSame([56, 1], array_column($this->getJson('/api/v1/token-sale')->json('data.crypto_chains'), 'chain_id'));
    }

    #[Test]
    public function the_legacy_tx_hash_submission_stays_closed_when_crypto_orders_are_open(): void
    {
        $this->postJson('/api/v1/token-sale/purchase', [
            'wallet_address' => $this->buyer,
            'phase_id' => $this->phase->id,
            'currency' => 'USDT',
            'amount' => 10,
            'tx_hash' => '0x'.str_repeat('ab', 32),
        ])->assertStatus(422)->assertJsonPath('error.code', 'PURCHASE_REJECTED');

        $this->assertSame(0, SaleTransaction::count());
    }

    /** @return array<string, array{mixed, string, bool}> */
    public static function acceptedChannels(): array
    {
        return [
            'เปิด CRYPTO รับทางใหม่' => [['CARD', 'CRYPTO'], 'CRYPTO', true],
            'เปิด CRYPTO ยังรับบัตร' => [['CARD', 'CRYPTO'], 'USD', true],
            'เปิด CRYPTO แล้ว USDT ยังปิด' => [['CARD', 'CRYPTO'], 'USDT', false],
            'เปิด CRYPTO แล้ว BNB ยังปิด' => [['CARD', 'CRYPTO'], 'BNB', false],
            'รับบัตรอย่างเดียว ปิด CRYPTO' => [['CARD'], 'CRYPTO', false],
            'รับบัตรอย่างเดียว ปิด USDT' => [['CARD'], 'USDT', false],
            'ค่าว่างไม่เปิด CRYPTO' => [[], 'CRYPTO', false],
            'ค่าว่างไม่เปิด USDT' => [[], 'USDT', false],
            'null ไม่เปิด BNB' => [null, 'BNB', false],
            'ค่าว่างยังรับเงินสดของข้อมูลเก่า' => [[], 'USD', true],
            'ประกาศ USDT เองก็ไม่เปิด CRYPTO' => [['USDT'], 'CRYPTO', false],
        ];
    }

    #[Test]
    #[DataProvider('acceptedChannels')]
    public function the_sale_policy_decides_which_channels_are_accepted(mixed $accepted, string $channel, bool $allowed): void
    {
        $this->sale->update(['accept_currencies' => $accepted]);

        if (! $allowed) {
            $this->expectException(PurchaseException::class);
        }

        app(TokenSaleService::class)->assertCurrencyAccepted($this->sale->fresh(), $channel);

        $this->assertTrue($allowed);
    }

    #[Test]
    public function precheck_refuses_the_legacy_currencies_before_any_money_moves(): void
    {
        foreach (['USDT', 'BNB'] as $currency) {
            $this->postJson('/api/v1/token-sale/precheck', [
                'wallet_address' => $this->buyer,
                'phase_id' => $this->phase->id,
                'currency' => $currency,
                'amount' => 10,
            ])->assertStatus(409)->assertJsonPath('error.code', 'NOT_PURCHASABLE');
        }
    }

    #[Test]
    public function an_order_is_refused_when_the_sale_has_no_receive_wallet(): void
    {
        $this->sale->update(['sale_wallet_address' => null]);
        Cache::forget('token_sale:active');

        $this->postJson('/api/v1/token-sale/crypto-order', [
            'wallet_address' => $this->buyer, 'phase_id' => $this->phase->id, 'amount_usd' => 10, 'chain_id' => 56,
        ])->assertStatus(409);

        $this->assertSame(0, SaleCryptoOrder::count());
        $this->assertFalse($this->getJson('/api/v1/token-sale')->json('data.payment_methods.crypto'));
    }

    #[Test]
    public function a_card_only_sale_without_a_wallet_keeps_the_scanner_idle(): void
    {
        $this->sale->update(['accept_currencies' => ['CARD'], 'sale_wallet_address' => null]);
        Cache::forget('token_sale:active');

        $this->scan();

        $this->assertSame(0, SaleCryptoPayment::count());
        $this->assertSame([], app(CryptoPaymentScanner::class)->watchedAddresses(56));
    }

    /** @return array<string, array{string}> */
    public static function serverHeldConfig(): array
    {
        return [
            'กองทุนเสถียรราคา' => ['stabilizer.wallet.address'],
            'oracle มาสเตอร์โหนด' => ['masternode.uptime.oracle.address'],
        ];
    }

    #[Test]
    #[DataProvider('serverHeldConfig')]
    public function a_wallet_the_server_holds_the_key_for_cannot_receive_sale_money(string $configKey): void
    {
        config([$configKey => $this->saleWallet]);

        $this->assertSame('internal_address', app(SaleCryptoConfig::class)->receiveAddressProblem($this->sale));
    }

    #[Test]
    public function the_wtpix_bridge_relayer_cannot_receive_sale_money(): void
    {
        $keystore = tempnam(sys_get_temp_dir(), 'ks');
        file_put_contents($keystore, json_encode(['address' => substr($this->saleWallet, 2)]));
        config(['wtpix_bridge.signers.relayer.keystore' => $keystore]);

        try {
            $this->assertSame('internal_address', app(SaleCryptoConfig::class)->receiveAddressProblem($this->sale));
        } finally {
            unlink($keystore);
        }
    }

    #[Test]
    public function only_the_order_wallet_can_read_the_order(): void
    {
        $order = $this->order();
        $url = "/api/v1/token-sale/crypto-order/{$order['id']}";

        $this->getJson("{$url}?wallet_address={$this->buyer}")->assertOk()->assertJsonPath('data.reference', $order['reference']);
        $this->getJson($url)->assertNotFound();

        $this->getJson("{$url}?wallet_address={$this->exchange}")->assertForbidden();

        $this->verifyWallet($this->exchange);
        $this->getJson("{$url}?wallet_address={$this->exchange}")->assertNotFound();
    }

    #[Test]
    public function polling_the_order_does_not_use_up_the_purchase_rate_limit(): void
    {
        $order = $this->order();

        // หน้าซื้อถามทุก 6 วินาที — หนึ่งนาทีเกินเพดาน 10 ครั้งของกลุ่มซื้อ
        for ($i = 0; $i < 11; $i++) {
            $this->getJson("/api/v1/token-sale/crypto-order/{$order['id']}?wallet_address={$this->buyer}")->assertOk();
        }

        $this->assertNotSame(429, $this->postJson('/api/v1/token-sale/precheck', [
            'wallet_address' => $this->buyer,
            'phase_id' => $this->phase->id,
            'currency' => 'USD',
            'amount' => 10,
        ])->getStatusCode());
    }

    // ── เงินเข้าแต่ออกเหรียญเองไม่ได้ → needs_review เสมอ ──────────────────

    #[Test]
    public function under_and_overpayments_from_the_order_wallet_go_to_review_linked_to_the_order(): void
    {
        $order = $this->order(10);
        $this->pay($order, raw: '9000000000000000000');
        $this->finalizeAndScan();

        $p = SaleCryptoPayment::sole();
        $this->assertSame(SaleCryptoPayment::STATUS_NEEDS_REVIEW, $p->status);
        $this->assertSame(SaleCryptoPayment::REASON_UNDERPAID, $p->review_reason);
        $this->assertSame($order['id'], $p->order->uuid);
        $this->assertSame(SaleCryptoOrder::STATUS_NEEDS_REVIEW, $p->order->status);
        $this->assertSame(0, SaleTransaction::count());
        $this->assertEquals(0, (float) $this->phase->fresh()->sold);

        $other = '0xcccc00000000000000000000000000000000cccc';
        $this->verifyWallet($other);
        $order2 = $this->order(10, wallet: $other);
        $this->pay($order2, from: $other, raw: '11000000000000000000', block: 1101);
        $this->finalizeAndScan(1200);

        $this->assertSame(SaleCryptoPayment::REASON_OVERPAID, SaleCryptoPayment::latest('id')->first()->review_reason);
        $this->assertSame(0, SaleTransaction::count());
    }

    #[Test]
    public function a_payment_after_the_price_lock_expired_goes_to_review(): void
    {
        $order = $this->order(10);
        // บล็อก 2000 ≈ ตอนนี้ + 2000 วินาที (> 30 นาที)
        $this->pay($order, block: 2000);
        $this->travel(40)->minutes();
        $this->verifyWallet($this->buyer);
        $this->finalizeAndScan(2100);

        $p = SaleCryptoPayment::sole();
        $this->assertSame(SaleCryptoPayment::REASON_LATE, $p->review_reason);
        $this->assertSame(0, SaleTransaction::count());
    }

    #[Test]
    public function paying_the_same_order_twice_credits_once_and_flags_the_second(): void
    {
        $order = $this->order(10);
        $this->pay($order);
        $this->pay($order, block: 1001);
        $this->finalizeAndScan();

        $this->assertSame(1, SaleTransaction::count());
        $this->assertSame(1, SaleCryptoPayment::where('status', SaleCryptoPayment::STATUS_CREDITED)->count());
        $this->assertSame(SaleCryptoPayment::REASON_DUPLICATE, SaleCryptoPayment::where('status', SaleCryptoPayment::STATUS_NEEDS_REVIEW)->sole()->review_reason);
    }

    #[Test]
    public function wrong_tokens_and_unmatched_transfers_are_recorded_for_refund(): void
    {
        $order = $this->order(10);
        $this->pay($order, token: $this->usdcBsc);                                     // USDC แทน USDT
        $this->pay([], from: $this->exchange, raw: '77000000000000000000', block: 1001); // ไม่มีคำสั่งซื้อ
        $this->pay([], from: $this->exchange, raw: '1000000000000000', block: 1002);     // 0.001 = ฝุ่น ไม่บันทึก
        $this->finalizeAndScan();

        $rows = SaleCryptoPayment::orderBy('block_number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([SaleCryptoPayment::REASON_WRONG_TOKEN, SaleCryptoPayment::REASON_UNMATCHED], $rows->pluck('review_reason')->all());
        $this->assertSame('USDC', $rows[0]->token_symbol);
        $this->assertSame(0, SaleTransaction::count());
    }

    #[Test]
    public function allocation_that_ran_out_while_waiting_goes_to_review_instead_of_overselling(): void
    {
        $order = $this->order(10);
        $this->phase->update(['sold' => 999900]); // เหลือ 100 TPIX < 200 ที่สั่ง
        $this->pay($order);
        $this->finalizeAndScan();

        $this->assertSame(SaleCryptoPayment::REASON_ALLOCATION, SaleCryptoPayment::sole()->review_reason);
        $this->assertSame(0, SaleTransaction::count());
        $this->assertEquals(999900, (float) $this->phase->fresh()->sold);
    }

    // ── ตัวสแกน: กติกาเดียวกับสะพาน ────────────────────────────────────────

    #[Test]
    public function unfinalized_blocks_and_rpc_failures_never_lose_a_payment(): void
    {
        $order = $this->order(10);
        $this->pay($order, block: 1000);

        // ยังไม่นิ่ง (latest 1000 → หัวปลอดภัย 985)
        $this->scan();
        $this->assertSame(0, SaleCryptoPayment::count());

        // getLogs ล้ม → เคอร์เซอร์ไม่ขยับ
        $this->setLatest(56, 1100, 1090);
        $this->chainState[56]['fail_logs'] = true;
        $this->scan();
        $this->assertSame(0, SaleCryptoPayment::count());

        $this->chainState[56]['fail_logs'] = false;
        $this->scan();
        $this->assertSame(1, SaleTransaction::count());
    }

    #[Test]
    public function changing_the_receive_wallet_keeps_watching_the_address_of_open_orders(): void
    {
        $order = $this->order(10);

        $newWallet = '0x5a1e00000000000000000000000000000000beef';
        $this->chainState[56]['nonce'][$newWallet] = 1;
        $this->sale->update(['sale_wallet_address' => $newWallet]);
        Cache::forget('token_sale:active');

        // ผู้ซื้อยังโอนเข้าที่อยู่เดิมตามคำสั่งซื้อ
        $this->pay($order);
        $this->finalizeAndScan();

        $this->assertSame(1, SaleTransaction::count());
        $this->assertSame(strtolower($this->saleWallet), SaleCryptoPayment::sole()->to_address);
    }

    #[Test]
    public function a_stalled_scanner_closes_new_orders_on_that_chain(): void
    {
        $this->travel(15)->minutes(); // ไม่มีรอบสแกนผ่านเลย 15 นาที (> scan_stale_minutes 10)
        $this->verifyWallet($this->buyer);

        $this->postJson('/api/v1/token-sale/crypto-order', [
            'wallet_address' => $this->buyer, 'phase_id' => $this->phase->id, 'amount_usd' => 10, 'chain_id' => 56,
        ])->assertStatus(409);

        $this->scan();
        $this->order(10);
    }

    #[Test]
    public function crediting_the_same_payment_again_is_a_no_op(): void
    {
        $order = $this->order(10);
        $this->pay($order);
        $this->finalizeAndScan();

        $payment = SaleCryptoPayment::sole();
        $payment->update(['status' => SaleCryptoPayment::STATUS_DETECTED]); // จำลองรอบที่ค้างกลางคัน

        $status = app(CryptoPaymentCrediter::class)->process($payment);

        $this->assertSame(SaleCryptoPayment::STATUS_NEEDS_REVIEW, $status);
        $this->assertSame(SaleCryptoPayment::REASON_DUPLICATE, $payment->fresh()->review_reason);
        $this->assertSame(1, SaleTransaction::count());
        $this->assertEquals(200, (float) $this->phase->fresh()->sold);
    }

    // ── ทีมงานตัดสินรายการ needs_review ───────────────────────────────────

    #[Test]
    public function staff_can_credit_the_received_amount_or_record_a_refund(): void
    {
        $order = $this->order(10);
        $this->pay($order, raw: '9000000000000000000'); // โอนขาด 9 USDT
        $this->pay([], from: $this->exchange, raw: '5000000000000000000', block: 1001); // unmatched
        $this->finalizeAndScan();

        [$under, $unmatched] = SaleCryptoPayment::orderBy('id')->get()->all();
        $crediter = app(CryptoPaymentCrediter::class);

        $tx = $crediter->creditReceived($under, 'admin@test');
        $this->assertEquals(180, (float) $tx->tpix_amount); // 9 USDT ÷ $0.05 (ราคาที่ล็อกไว้)
        $this->assertEquals(9, (float) $tx->payment_usd_value);
        $this->assertSame(SaleCryptoPayment::STATUS_CREDITED, $under->fresh()->status);
        $this->assertSame(SaleCryptoOrder::STATUS_PAID, SaleCryptoOrder::sole()->status);

        // ไม่มีคำสั่งซื้อ → ออกเหรียญไม่ได้ ทำได้แค่บันทึกว่าคืนเงิน
        try {
            $crediter->creditReceived($unmatched, 'admin@test');
            $this->fail('unmatched payment must not be credited');
        } catch (PurchaseException) {
            // ตามคาด
        }

        $crediter->markResolved($unmatched, 'คืนเงินแล้ว tx 0xabc', 'admin@test');
        $this->assertSame(SaleCryptoPayment::STATUS_RESOLVED, $unmatched->fresh()->status);
        $this->assertSame(1, SaleTransaction::count());
    }
}
