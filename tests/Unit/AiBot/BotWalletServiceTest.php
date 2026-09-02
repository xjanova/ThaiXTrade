<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\Web3BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — กฎของกระเป๋าบอท: หนึ่งใบต่อเจ้าของ ถอนได้แค่กลับหาเจ้าของ และด่านยอด/แก๊ส/เพดาน.
 *
 * Developed by Xman Studio.
 */
class BotWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private float $bnb = 0.05;

    private float $usdt = 250.0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
            'aibot.bot_wallet.chain_id' => 56,
            'aibot.bot_wallet.gas_reserve_bnb' => 0.002,
            'aibot.bot_wallet.withdraw_daily_cap' => 1000,
        ]);

        $this->partialMock(Web3BalanceService::class, function ($mock) {
            $mock->shouldReceive('getNativeBalance')->andReturnUsing(fn () => (string) $this->bnb);
            $mock->shouldReceive('getTokenBalance')->andReturnUsing(fn () => (string) $this->usdt);
        });
    }

    private function service(): BotWalletService
    {
        return app(BotWalletService::class);
    }

    #[Test]
    public function สร้างครั้งเดียวต่อเจ้าของ_และไม่เก็บกุญแจดิบ(): void
    {
        $wallet = $this->service()->ensure(self::OWNER);
        $again = $this->service()->ensure(strtoupper(self::OWNER));

        $this->assertSame($wallet->id, $again->id);
        $this->assertSame(1, AiBotWallet::count());
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $wallet->address);
        $this->assertNotSame(self::OWNER, $wallet->address, 'กระเป๋าบอทต้องไม่ใช่กระเป๋าของเจ้าของ');
        $this->assertArrayNotHasKey('key_ciphertext', $wallet->toArray(), 'ciphertext ต้องไม่หลุดออกทาง toArray');
    }

    #[Test]
    public function ปิดฟีเจอร์แล้วสร้างไม่ได้(): void
    {
        config(['aibot.bot_wallet.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(BotWalletService::ERR_DISABLED);
        $this->service()->ensure(self::OWNER);
    }

    #[Test]
    public function อ่านยอดจากเชนแล้วเก็บไว้ทุกสินทรัพย์(): void
    {
        $wallet = $this->service()->refreshBalances($this->service()->ensure(self::OWNER));

        $this->assertSame(0.05, $wallet->balanceOf('BNB'));
        $this->assertSame(250.0, $wallet->balanceOf('usdt'));
        $this->assertNotNull($wallet->balances_at);

        $presented = $this->service()->present($wallet);
        $this->assertSame('BNB', $presented['native_symbol']);
        $this->assertStringContainsString($wallet->address, (string) $presented['explorer_url']);
    }

    #[Test]
    public function ถอนได้ปลายทางเดียวคือเจ้าของ_และเข้าคิวโดยยังไม่แตะกุญแจ(): void
    {
        $this->service()->ensure(self::OWNER);

        $transfer = $this->service()->requestWithdraw(self::OWNER, 'usdt', 100.123456789, '10.0.0.1');

        $this->assertSame(self::OWNER, $transfer->to_address);
        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $transfer->status);
        $this->assertSame('USDT', $transfer->asset);
        $this->assertSame(100.12345679, (float) $transfer->amount, 'ปัดเหลือ 8 ตำแหน่ง');
        $this->assertSame('100123456790000000000', $transfer->amount_wei);
        $this->assertSame('0x55d398326f99059ff775485246999027b3197955', $transfer->token_address);
        $this->assertNull($transfer->tx_hash);
    }

    #[Test]
    public function ยอดไม่พอ_แก๊สไม่พอ_ต่ำกว่าขั้นต่ำ_สินทรัพย์ไม่รู้จัก_ต้องปฏิเสธ(): void
    {
        $this->service()->ensure(self::OWNER);

        foreach ([
            [BotWalletService::ERR_BALANCE, 'USDT', 300.0],
            [BotWalletService::ERR_AMOUNT, 'USDT', 0.5],
            [BotWalletService::ERR_ASSET, 'DOGE', 1.0],
            [BotWalletService::ERR_GAS, 'BNB', 0.049],   // เหลือ 0.001 < กัน 0.002
        ] as [$code, $asset, $amount]) {
            try {
                $this->service()->requestWithdraw(self::OWNER, $asset, $amount);
                $this->fail("ต้องปฏิเสธ {$code}");
            } catch (RuntimeException $e) {
                $this->assertSame($code, $e->getMessage());
            }
        }

        $this->assertSame(0, AiBotWalletTransfer::count());
    }

    #[Test]
    public function ถอนโทเคนโดยไม่มี_bnb_จ่ายแก๊สต้องปฏิเสธ(): void
    {
        $this->bnb = 0.0;
        $this->service()->ensure(self::OWNER);

        $this->expectExceptionMessage(BotWalletService::ERR_GAS);
        $this->service()->requestWithdraw(self::OWNER, 'USDT', 10.0);
    }

    #[Test]
    public function มีรายการค้างอยู่ห้ามขอถอนซ้ำ_ยกเลิกแล้วค่อยขอใหม่ได้(): void
    {
        $this->service()->ensure(self::OWNER);
        $first = $this->service()->requestWithdraw(self::OWNER, 'USDT', 10.0);

        try {
            $this->service()->requestWithdraw(self::OWNER, 'BNB', 0.01);
            $this->fail('ต้องปฏิเสธเพราะมีรายการค้าง');
        } catch (RuntimeException $e) {
            $this->assertSame(BotWalletService::ERR_IN_FLIGHT, $e->getMessage());
        }

        $cancelled = $this->service()->cancelWithdraw(self::OWNER, $first->id);
        $this->assertSame(AiBotWalletTransfer::STATUS_CANCELLED, $cancelled->status);

        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $this->service()->requestWithdraw(self::OWNER, 'BNB', 0.01)->status);
    }

    #[Test]
    public function รายการที่ส่งไปแล้วยกเลิกไม่ได้_และคนอื่นยกเลิกของเราไม่ได้(): void
    {
        $this->service()->ensure(self::OWNER);
        $transfer = $this->service()->requestWithdraw(self::OWNER, 'USDT', 10.0);
        $transfer->update(['status' => AiBotWalletTransfer::STATUS_BROADCASTING, 'tx_hash' => '0x'.str_repeat('1', 64)]);

        try {
            $this->service()->cancelWithdraw(self::OWNER, $transfer->id);
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertSame(BotWalletService::ERR_NOT_CANCELLABLE, $e->getMessage());
        }

        $this->expectExceptionMessage(BotWalletService::ERR_NOT_FOUND);
        $this->service()->cancelWithdraw('0x2222222222222222222222222222222222222222', $transfer->id);
    }

    #[Test]
    public function เพดานถอนต่อวันนับรวมรายการที่สำเร็จแล้ววันนี้(): void
    {
        $this->usdt = 5000.0;
        $wallet = $this->service()->ensure(self::OWNER);

        $wallet->transfers()->create([
            'owner_address' => self::OWNER, 'direction' => 'withdraw', 'asset' => 'USDT',
            'amount' => 950, 'amount_wei' => '950000000000000000000', 'to_address' => self::OWNER,
            'status' => AiBotWalletTransfer::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);

        $this->expectExceptionMessage(BotWalletService::ERR_DAILY_CAP);
        $this->service()->requestWithdraw(self::OWNER, 'USDT', 100.0);
    }

    #[Test]
    public function แปลงจำนวนเป็น_wei_แบบสตริงล้วนไม่เสียความละเอียด(): void
    {
        $this->assertSame('1000000000000000000', BotWalletService::toWei(1.0, 18));
        $this->assertSame('123456780000000000', BotWalletService::toWei(0.12345678, 18));
        $this->assertSame('1500000', BotWalletService::toWei(1.5, 6));
        $this->assertSame('0', BotWalletService::toWei(0.0, 18));
        $this->assertSame('250000000000000000000', BotWalletService::toWei(250, 18));
    }
}
