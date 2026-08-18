<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Services\TokenSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — Sale Payment Verification Tests
 *
 * ชุดนี้คือด่านกันช่องโหว่ที่เคยทำให้ "ซื้อเหรียญได้โดยไม่ต้องจ่ายเงินจริง"
 * ทุกเคสจำลองคำตอบของ BSC RPC เพื่อพิสูจน์ว่าระบบตัดสินจากข้อมูลบนเชนจริง
 * ไม่ใช่จากตัวเลขที่ผู้ซื้อกรอกเข้ามา
 *
 * Developed by Xman Studio.
 */
class SalePaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_WALLET = '0xF1CD82550E1145664a86f238AcC8AC67D0d68B4f';

    private const BUYER = '0x1111111111111111111111111111111111111111';

    private const USDT = '0x55d398326f99059fF775485246999027B3197955';

    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const TX = '0xaaaa000000000000000000000000000000000000000000000000000000000001';

    private SalePhase $phase;

    protected function setUp(): void
    {
        parent::setUp();

        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'sale_wallet_address' => self::SALE_WALLET,
        ]);

        $this->phase = SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Private Sale',
            'slug' => 'private-sale',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 200000000,
            'min_purchase' => 1,
            'max_purchase' => 10000000,
            'vesting_tge_percent' => 20,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(59),
        ]);
    }

    /**
     * แปลงจำนวนเหรียญ (หน่วยปกติ) เป็น hex ของ wei 18 หลัก
     *
     * ใช้ bcmath ไม่ใช่ gmp เพราะเซิร์ฟเวอร์จริงไม่มี ext-gmp (โค้ดหลักก็เลี่ยงไว้แล้ว)
     */
    private function toHexWei(string $amount): string
    {
        $dec = bcmul($amount, bcpow('10', '18'), 0);

        if (bccomp($dec, '0') === 0) {
            return '0x0';
        }

        $hex = '';
        while (bccomp($dec, '0') > 0) {
            $hex = dechex((int) bcmod($dec, '16')).$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return '0x'.$hex;
    }

    /** address 20 ไบต์ → topic 32 ไบต์ */
    private function pad(string $address): string
    {
        return '0x'.str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    /**
     * จำลอง BSC RPC — ระบุ receipt/tx ที่จะตอบกลับ
     *
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $tx
     */
    private function fakeRpc(array $receipt, array $tx, int $confirmations = 30, ?int $blockAgeSeconds = 0): void
    {
        $block = 1000;
        $receipt['blockNumber'] = '0x'.dechex($block);
        $receipt['status'] ??= '0x1';

        Http::fake(function ($request) use ($receipt, $tx, $block, $confirmations, $blockAgeSeconds) {
            $method = $request->data()['method'] ?? '';

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => match ($method) {
                    'eth_getTransactionReceipt' => $receipt,
                    'eth_getTransactionByHash' => $tx,
                    'eth_blockNumber' => '0x'.dechex($block + $confirmations - 1),
                    'eth_getBlockByNumber' => ['timestamp' => '0x'.dechex(time() - $blockAgeSeconds)],
                    default => null,
                },
            ], 200);
        });
    }

    /** receipt ของการโอน ERC-20 หนึ่งรายการ */
    private function erc20Receipt(string $tokenAddress, string $amount, ?string $from = null): array
    {
        return [
            'logs' => [[
                'address' => $tokenAddress,
                'topics' => [
                    self::TRANSFER_TOPIC,
                    $this->pad($from ?? self::BUYER),
                    $this->pad(self::SALE_WALLET),
                ],
                'data' => $this->toHexWei($amount),
            ]],
        ];
    }

    private function buy(string $currency, float $amount, string $txHash = self::TX): SaleTransaction
    {
        return app(TokenSaleService::class)->processPurchase(
            self::BUYER,
            $this->phase->id,
            $currency,
            $amount,
            $txHash,
        );
    }

    // ── ช่องโหว่ที่ 1: โอน BNB เศษสตางค์แล้วอ้างว่าเป็น USDT หลักล้าน ────────

    public function test_bnb_transfer_cannot_be_claimed_as_usdt(): void
    {
        $this->fakeRpc(
            ['logs' => []],
            ['from' => self::BUYER, 'to' => self::SALE_WALLET, 'value' => $this->toHexWei('1')],
        );

        $this->expectExceptionMessage('This transaction paid in BNB. Please select BNB as the payment currency.');
        $this->buy('USDT', 10000000);
    }

    public function test_unverified_purchase_does_not_touch_sold_counter(): void
    {
        $this->fakeRpc(
            ['logs' => []],
            ['from' => self::BUYER, 'to' => self::SALE_WALLET, 'value' => $this->toHexWei('1')],
        );

        try {
            $this->buy('USDT', 10000000);
        } catch (\Throwable) {
            // คาดว่าโดนปฏิเสธ
        }

        $this->assertSame(0.0, (float) $this->phase->fresh()->sold, 'ยอดขายต้องไม่ขยับเมื่อยืนยันไม่ผ่าน');
        $this->assertSame(0, SaleTransaction::count(), 'ต้องไม่บันทึกรายการค้างไว้เลย');
    }

    // ── ช่องโหว่ที่ 2: เหรียญปลอมที่ตั้งชื่อว่า USDT ─────────────────────────

    public function test_fake_token_contract_is_rejected(): void
    {
        $fakeToken = '0xdead00000000000000000000000000000000beef';
        $this->fakeRpc(
            $this->erc20Receipt($fakeToken, '1000000'),
            ['from' => self::BUYER, 'to' => $fakeToken, 'value' => '0x0'],
        );

        $this->expectExceptionMessage('That token is not accepted.');
        $this->buy('USDT', 1000000);
    }

    // ── ช่องโหว่ที่ 7: อ้างยอดเกินจริง ──────────────────────────────────────

    public function test_tokens_are_issued_from_onchain_amount_not_claimed_amount(): void
    {
        // จ่ายจริง 100 USDT แต่แจ้งมา 1,000
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '100'),
            ['from' => self::BUYER, 'to' => self::USDT, 'value' => '0x0'],
        );

        $tx = $this->buy('USDT', 1000);

        // ราคา $0.05 → 100 USD ได้ 2,000 TPIX (ไม่ใช่ 20,000 ตามที่แจ้ง)
        $this->assertSame(100.0, (float) $tx->payment_amount);
        $this->assertSame(2000.0, (float) $tx->tpix_amount);
        $this->assertSame('confirmed', $tx->status);
    }

    public function test_valid_usdt_payment_is_accepted(): void
    {
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '500'),
            ['from' => self::BUYER, 'to' => self::USDT, 'value' => '0x0'],
        );

        $tx = $this->buy('USDT', 500);

        $this->assertSame('USDT', $tx->payment_currency);
        $this->assertSame(10000.0, (float) $tx->tpix_amount);
        $this->assertSame(10000.0, (float) $this->phase->fresh()->sold);
    }

    // ── การโอนของคนอื่น ────────────────────────────────────────────────────

    public function test_transfer_from_another_wallet_is_rejected(): void
    {
        $someoneElse = '0x9999999999999999999999999999999999999999';
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '500', $someoneElse),
            ['from' => $someoneElse, 'to' => self::USDT, 'value' => '0x0'],
        );

        $this->expectExceptionMessage('This transaction was not sent from your connected wallet.');
        $this->buy('USDT', 500);
    }

    // ── ยังไม่นิ่งพอ (reorg) ───────────────────────────────────────────────

    public function test_payment_with_too_few_confirmations_is_rejected_but_retryable(): void
    {
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '500'),
            ['from' => self::BUYER, 'to' => self::USDT, 'value' => '0x0'],
            confirmations: 2,
        );

        $this->expectExceptionMessage('Payment found but not confirmed yet.');
        $this->buy('USDT', 500);
    }

    // ── ธุรกรรมเก่าเกินไป ──────────────────────────────────────────────────

    public function test_old_transaction_is_rejected(): void
    {
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '500'),
            ['from' => self::BUYER, 'to' => self::USDT, 'value' => '0x0'],
            blockAgeSeconds: 60 * 60 * 24 * 30, // 30 วันก่อน
        );

        $this->expectExceptionMessage('This transaction is too old');
        $this->buy('USDT', 500);
    }

    // ── ธุรกรรมที่ revert ──────────────────────────────────────────────────

    public function test_reverted_transaction_is_rejected(): void
    {
        $this->fakeRpc(
            ['logs' => [], 'status' => '0x0'],
            ['from' => self::BUYER, 'to' => self::SALE_WALLET, 'value' => $this->toHexWei('1')],
        );

        $this->expectExceptionMessage('That transaction failed on-chain.');
        $this->buy('BNB', 1);
    }

    // ── ใช้ tx เดิมซ้ำ ─────────────────────────────────────────────────────

    public function test_same_transaction_cannot_be_used_twice(): void
    {
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '500'),
            ['from' => self::BUYER, 'to' => self::USDT, 'value' => '0x0'],
        );

        $this->buy('USDT', 500);

        $this->expectExceptionMessage('This transaction has already been processed.');
        $this->buy('USDT', 500);
    }

    /**
     * แม้ด่านเช็คซ้ำในโค้ดจะถูกข้าม (จำลองการยิงขนาน) ฐานข้อมูลต้องกันได้เอง
     */
    public function test_database_rejects_duplicate_tx_hash(): void
    {
        $this->fakeRpc(
            $this->erc20Receipt(self::USDT, '500'),
            ['from' => self::BUYER, 'to' => self::USDT, 'value' => '0x0'],
        );

        $first = $this->buy('USDT', 500);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        SaleTransaction::create([
            'token_sale_id' => $first->token_sale_id,
            'sale_phase_id' => $first->sale_phase_id,
            'wallet_address' => self::BUYER,
            'payment_currency' => 'USDT',
            'payment_amount' => 500,
            'payment_usd_value' => 500,
            'tpix_amount' => 10000,
            'price_per_tpix' => 0.05,
            'tx_hash' => self::TX,
            'status' => 'confirmed',
        ]);
    }
}
