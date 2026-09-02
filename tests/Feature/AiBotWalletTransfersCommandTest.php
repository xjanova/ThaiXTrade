<?php

namespace Tests\Feature;

use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\Wallet\BotWalletSigner;
use App\Services\Web3BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — คำสั่งส่งคิวถอนของกระเป๋าบอท: ลำดับ เซ็น → บันทึก hash → ส่ง → ตามผล.
 *
 * ตัวเซ็นถูก mock (ไม่เรียก node จริง) แต่พวงกุญแจใช้ของจริง — เทสต์นี้จึงพิสูจน์ด้วยว่า
 * กุญแจที่ถอดออกมาตรงกับที่อยู่ของกระเป๋า
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransfersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const HASH = '0x'.'ab' . 'cd' . 'ef' . '00' . '11' . '22' . '33' . '44' . '55' . '66' . '77' . '88' . '99' . 'aa' . 'bb' . 'cc' . 'dd' . 'ee' . 'ff' . '01' . '02' . '03' . '04' . '05' . '06' . '07' . '08' . '09' . '10' . '20' . '30' . '40';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
            'aibot.bot_wallet.confirmations' => 2,
        ]);

        $this->partialMock(Web3BalanceService::class, function ($mock) {
            $mock->shouldReceive('getNativeBalance')->andReturn('0.05');
            $mock->shouldReceive('getTokenBalance')->andReturn('100');
        });
    }

    private function queued(): AiBotWalletTransfer
    {
        $service = app(BotWalletService::class);
        $service->ensure(self::OWNER);

        return $service->requestWithdraw(self::OWNER, 'USDT', 25.0);
    }

    #[Test]
    public function ปิดฟีเจอร์แล้วไม่ทำอะไร(): void
    {
        config(['aibot.bot_wallet.enabled' => false]);

        $this->artisan('aibot:wallet-transfers')->expectsOutputToContain('ยังไม่เปิดใช้')->assertSuccessful();
    }

    #[Test]
    public function เซ็นแล้วบันทึก_hash_ก่อนส่ง_และส่งสำเร็จค้างสถานะรอผล(): void
    {
        $transfer = $this->queued();
        $wallet = $transfer->wallet;
        $seen = [];

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) use ($wallet, $transfer, &$seen) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->once()->withArgs(function ($key, $expect, $to, $wei, $token) use ($wallet, $transfer) {
                // กุญแจที่ถอดออกมาต้องถอดกลับเป็นที่อยู่ของกระเป๋าบอทจริง
                $derived = app(\App\Services\AiBot\Wallet\BotWalletKeyring::class)->addressOf($key);

                return strcasecmp($derived, $wallet->address) === 0
                    && strcasecmp($expect, $wallet->address) === 0
                    && strcasecmp($to, self::OWNER) === 0
                    && $wei === '25000000000000000000'
                    && $token === $transfer->token_address;
            })->andReturn(['ok' => true, 'address' => $wallet->address, 'nonce' => 7, 'txHash' => self::HASH, 'raw' => '0xraw']);
            $mock->shouldReceive('send')->once()->with('0xraw')->andReturnUsing(function () use (&$seen, $transfer) {
                // ตอนที่ส่ง hash ต้องอยู่ในฐานข้อมูลแล้ว
                $seen[] = $transfer->fresh()->tx_hash;

                return ['ok' => true, 'txHash' => self::HASH, 'alreadyKnown' => false];
            });
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame([self::HASH], $seen, 'บันทึก hash ก่อนส่งเสมอ');

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->status);
        $this->assertSame(7, $transfer->nonce);
        $this->assertNotNull($transfer->broadcast_at);
    }

    #[Test]
    public function เซ็นไม่ผ่านต้องล้มเหลวโดยไม่ส่งอะไรและเงินยังอยู่(): void
    {
        $transfer = $this->queued();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->once()->andReturn(['ok' => false, 'code' => 'estimate_failed', 'error' => 'ยอดไม่พอ']);
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->status);
        $this->assertStringContainsString('ยอดไม่พอ', $transfer->failure_reason);
        $this->assertNull($transfer->tx_hash);
    }

    #[Test]
    public function nonce_ถูกใช้แล้วต้องไม่ล้มเหลวและไม่เซ็นซ้ำ(): void
    {
        $transfer = $this->queued();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('receipt')->andReturn(null);
            $mock->shouldReceive('sign')->once()->andReturn(['ok' => true, 'nonce' => 1, 'txHash' => self::HASH, 'raw' => '0xraw']);
            $mock->shouldReceive('send')->once()->andReturn(['ok' => false, 'code' => 'nonce_used', 'txHash' => self::HASH]);
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);

        // รอบถัดไป: ยังรอ receipt อยู่ ต้องไม่หยิบมาเซ็นใหม่ (sign once() ด้านบนคุมไว้)
        $this->artisan('aibot:wallet-transfers')->assertSuccessful();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);
    }

    #[Test]
    public function ตาม_receipt_จนครบยืนยันแล้วปิดรายการและอ่านยอดใหม่(): void
    {
        $transfer = $this->queued();
        $transfer->update(['status' => AiBotWalletTransfer::STATUS_BROADCASTING, 'tx_hash' => self::HASH, 'broadcast_at' => now()]);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(100, 101);
            $mock->shouldReceive('receipt')->with(self::HASH)->andReturn(['status' => '0x1', 'blockNumber' => '0x64']);
            $mock->shouldReceive('sign')->never();
        });

        // รอบแรก: 1 ยืนยัน (ต้องการ 2) → ยังรอ
        $this->artisan('aibot:wallet-transfers')->assertSuccessful();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);
        $this->assertSame(1, $transfer->fresh()->confirmations);

        // รอบสอง: 2 ยืนยัน → สำเร็จ + ยอดถูกอ่านใหม่
        $this->artisan('aibot:wallet-transfers')->assertSuccessful();
        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_CONFIRMED, $transfer->status);
        $this->assertSame(100, $transfer->block_number);
        $this->assertNotNull($transfer->confirmed_at);
        $this->assertNotNull($transfer->wallet->balances_at);
    }

    #[Test]
    public function เชนปฏิเสธธุรกรรมต้องเป็นล้มเหลวพร้อมเหตุผล(): void
    {
        $transfer = $this->queued();
        $transfer->update(['status' => AiBotWalletTransfer::STATUS_BROADCASTING, 'tx_hash' => self::HASH]);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(100);
            $mock->shouldReceive('receipt')->andReturn(['status' => '0x0', 'blockNumber' => '0x64']);
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->fresh()->status);
        $this->assertStringContainsString('ปฏิเสธ', $transfer->fresh()->failure_reason);
    }

    #[Test]
    public function ปลายทางที่ถูกแก้ในฐานข้อมูลให้ไม่ใช่เจ้าของต้องถูกปฏิเสธตอนส่งจริง(): void
    {
        $transfer = $this->queued();
        $transfer->forceFill(['to_address' => '0x9999999999999999999999999999999999999999'])->save();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->never();
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->fresh()->status);
    }
}
