<?php

namespace Tests\Feature;

use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletKeyring;
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

    private const HASH = '0x'.'ab'.'cd'.'ef'.'00'.'11'.'22'.'33'.'44'.'55'.'66'.'77'.'88'.'99'.'aa'.'bb'.'cc'.'dd'.'ee'.'ff'.'01'.'02'.'03'.'04'.'05'.'06'.'07'.'08'.'09'.'10'.'20'.'30'.'40';

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
                $derived = app(BotWalletKeyring::class)->addressOf($key);

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

    /** รายการที่ส่งไปแล้ว (มี hash + raw) เมื่อ $minutesAgo นาทีก่อน */
    private function broadcasting(int $minutesAgo = 2, array $extra = []): AiBotWalletTransfer
    {
        $transfer = $this->queued();
        $transfer->forceFill(array_merge([
            'status' => AiBotWalletTransfer::STATUS_BROADCASTING,
            'tx_hash' => self::HASH,
            'nonce' => 5,
            'raw_tx' => '0xraw',
            'broadcast_at' => now()->subMinutes($minutesAgo),
        ], $extra))->save();

        return $transfer->fresh();
    }

    #[Test]
    public function ส่งขึ้นเชนไม่ผ่านต้องคงสถานะรอผล_ไม่ใช่ล้มเหลว_และเก็บ_raw_ไว้ส่งซ้ำ(): void
    {
        // RPC หมดเวลาหลังโหนดรับไปแล้ว — ถ้าตีว่าล้ม รายการหลุดจากโควตาและล็อกต่อกระเป๋าทั้งที่เงินออกไปแล้ว
        $transfer = $this->queued();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->once()->andReturn(['ok' => true, 'nonce' => 3, 'txHash' => self::HASH, 'raw' => '0xsigned-raw']);
            $mock->shouldReceive('send')->once()->andReturn(['ok' => false, 'code' => 'process_failed', 'error' => 'The process exceeded the timeout of 120 seconds.']);
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->status);
        $this->assertSame('0xsigned-raw', $transfer->raw_tx);
        $this->assertNull($transfer->failure_code);

        // ยังกินโควตา/ล็อกกระเป๋าอยู่ — ขอถอนซ้อนไม่ได้
        $this->expectExceptionMessage(BotWalletService::ERR_IN_FLIGHT);
        app(BotWalletService::class)->requestWithdraw(self::OWNER, 'USDT', 1.0);
    }

    #[Test]
    public function เชนไม่รู้จัก_tx_ส่ง_raw_เดิมซ้ำโดยไม่เซ็นใหม่(): void
    {
        $transfer = $this->broadcasting(minutesAgo: 3);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(200);
            $mock->shouldReceive('receipt')->andReturn(null);
            $mock->shouldReceive('transactionKnown')->with(self::HASH)->andReturn(false);
            $mock->shouldReceive('nonceOf')->andReturn(5);   // nonce ของเรายังว่าง
            $mock->shouldReceive('send')->once()->with('0xraw')->andReturn(['ok' => true, 'txHash' => self::HASH]);
            $mock->shouldReceive('sign')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);
    }

    #[Test]
    public function nonce_ถูกธุรกรรมอื่นใช้แล้วนานพอ_ปิดเป็นไม่ได้โอน_แล้วขอถอนใหม่ได้(): void
    {
        config(['aibot.bot_wallet.stuck_after_minutes' => 30]);
        $transfer = $this->broadcasting(minutesAgo: 40);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(200);
            // ถามซ้ำก่อนตัดสินเสมอ (และรอบเดียวกันยังตามใบเสร็จของรายการที่เพิ่งปิดต่อ)
            $mock->shouldReceive('receipt')->atLeast()->times(2)->andReturn(null);
            $mock->shouldReceive('transactionKnown')->andReturn(false);
            $mock->shouldReceive('nonceOf')->andReturn(6);
            $mock->shouldReceive('send')->never();
            $mock->shouldReceive('sign')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->status);
        $this->assertSame(AiBotWalletTransfer::FAIL_NONCE_CONSUMED, $transfer->failure_code);

        // ไม่ค้างตลอดกาลอีกแล้ว — ผู้ใช้ขอถอนใหม่ได้
        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, app(BotWalletService::class)->requestWithdraw(self::OWNER, 'USDT', 1.0)->status);
    }

    #[Test]
    public function nonce_ถูกใช้แต่เป็นของเราเองที่เพิ่งลงบล็อก_ต้องปิดตามใบเสร็จไม่ใช่ล้มเหลว(): void
    {
        $transfer = $this->broadcasting(minutesAgo: 40);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(110);
            // RPC ตัวแรกยังไม่เห็นใบเสร็จ ตัวที่ถามซ้ำเห็นแล้ว
            $mock->shouldReceive('receipt')->andReturn(null, ['status' => '0x1', 'blockNumber' => '0x64']);
            $mock->shouldReceive('transactionKnown')->andReturn(false);
            $mock->shouldReceive('nonceOf')->andReturn(6);
            $mock->shouldReceive('sign')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_CONFIRMED, $transfer->fresh()->status);
    }

    #[Test]
    public function ไม่ลงบล็อกและ_nonce_ยังว่างนานเกินเวลา_ปิดเป็น_dropped(): void
    {
        config(['aibot.bot_wallet.stuck_after_minutes' => 30]);
        // แถวเก่าก่อนมี raw — ส่งซ้ำไม่ได้ แต่ต้องไม่ค้างตลอดกาล
        $transfer = $this->broadcasting(minutesAgo: 45, extra: ['raw_tx' => null]);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(200);
            $mock->shouldReceive('receipt')->andReturn(null);
            $mock->shouldReceive('transactionKnown')->andReturn(false);
            $mock->shouldReceive('nonceOf')->andReturn(5);
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->status);
        $this->assertSame(AiBotWalletTransfer::FAIL_DROPPED, $transfer->failure_code);
    }

    #[Test]
    public function ค้างใน_mempool_ยังลงบล็อกได้_ห้ามตัดสินเอง(): void
    {
        $transfer = $this->broadcasting(minutesAgo: 120);

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(200);
            $mock->shouldReceive('receipt')->andReturn(null);
            $mock->shouldReceive('transactionKnown')->andReturn(true);
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);
    }

    #[Test]
    public function รายการที่ปิดเป็น_dropped_แล้วลงบล็อกทีหลัง_แก้กลับเป็นสำเร็จ(): void
    {
        $transfer = $this->broadcasting();
        $transfer->forceFill([
            'status' => AiBotWalletTransfer::STATUS_FAILED,
            'failure_code' => AiBotWalletTransfer::FAIL_DROPPED,
            'failure_reason' => AiBotWalletTransfer::FAILURE_MESSAGES[AiBotWalletTransfer::FAIL_DROPPED],
        ])->save();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(105);
            $mock->shouldReceive('receipt')->with(self::HASH)->andReturn(['status' => '0x1', 'blockNumber' => '0x64']);
            $mock->shouldReceive('sign')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_CONFIRMED, $transfer->status);
        $this->assertNull($transfer->failure_code);
        $this->assertSame(100, $transfer->block_number);
    }

    #[Test]
    public function ค้างกำลังเซ็นโดยยังไม่มี_hash_คืนเข้าคิวแล้วเซ็นใหม่ได้(): void
    {
        config(['aibot.bot_wallet.signing_stale_minutes' => 10]);
        $transfer = $this->queued();
        $transfer->forceFill(['status' => AiBotWalletTransfer::STATUS_SIGNING, 'signing_at' => now()->subMinutes(20)])->save();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->once()->andReturn(['ok' => true, 'nonce' => 2, 'txHash' => self::HASH, 'raw' => '0xraw']);
            $mock->shouldReceive('send')->once()->andReturn(['ok' => true, 'txHash' => self::HASH]);
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);
    }

    #[Test]
    public function กำลังเซ็นอยู่จริง_ยังไม่นาน_ต้องไม่ถูกหยิบไปเซ็นซ้ำ(): void
    {
        $transfer = $this->queued();
        $transfer->forceFill(['status' => AiBotWalletTransfer::STATUS_SIGNING, 'signing_at' => now()->subMinute()])->save();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('sign')->never();
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_SIGNING, $transfer->fresh()->status);
    }

    #[Test]
    public function สถานะเปลี่ยนระหว่างเซ็น_ทิ้งธุรกรรมที่เซ็นไว้โดยไม่ส่ง(): void
    {
        // อีกรอบของ cron / ทีมงานปิดรายการไปแล้วระหว่างที่รอบนี้กำลังเซ็น — ตัวที่แพ้ต้องไม่ส่งอะไรออกไป
        $transfer = $this->queued();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) use ($transfer) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->once()->andReturnUsing(function () use ($transfer) {
                AiBotWalletTransfer::whereKey($transfer->id)->update([
                    'status' => AiBotWalletTransfer::STATUS_FAILED,
                    'failure_code' => AiBotWalletTransfer::FAIL_RESOLVED_BY_ADMIN,
                ]);

                return ['ok' => true, 'nonce' => 2, 'txHash' => self::HASH, 'raw' => '0xraw'];
            });
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->status);
        $this->assertNull($transfer->tx_hash);
    }

    #[Test]
    public function ข้อความล้มเหลวที่ผู้ใช้เห็นเป็นข้อความตามรหัส_ไม่มีข้อความดิบจากระบบ(): void
    {
        $transfer = $this->queued();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->once()->andReturn(['ok' => false, 'code' => 'unexpected', 'error' => 'connect ECONNREFUSED 10.0.0.5:8545']);
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $shown = app(BotWalletService::class)->presentTransfer($transfer->fresh());
        $this->assertSame(AiBotWalletTransfer::FAIL_SIGN, $shown['failure_code']);
        $this->assertSame(AiBotWalletTransfer::FAILURE_MESSAGES[AiBotWalletTransfer::FAIL_SIGN], $shown['failure_reason']);
        $this->assertStringNotContainsString('10.0.0.5', (string) $transfer->fresh()->failure_reason);
    }

    #[Test]
    public function ถอดกุญแจไม่ได้_ไม่ส่งอะไร_และไม่เปิดเผยข้อความของตัวถอดรหัส(): void
    {
        $transfer = $this->queued();
        $transfer->wallet->forceFill(['key_ciphertext' => 'not-a-valid-payload'])->save();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldReceive('sign')->never();
            $mock->shouldReceive('send')->never();
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::FAIL_KEY_UNREADABLE, $transfer->failure_code);
        $this->assertStringNotContainsStringIgnoringCase('payload', (string) $transfer->failure_reason);
    }

    // ── aibot:wallet-transfer-resolve ──────────────────────────────────────

    #[Test]
    public function คำสั่งปิดรายการด้วยมือปฏิเสธเมื่อมีใบเสร็จบนเชนแล้ว(): void
    {
        $transfer = $this->broadcasting();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('receipt')->andReturn(['status' => '0x1', 'blockNumber' => '0x64']);
            $mock->shouldReceive('transactionKnown')->andReturn(true);
            $mock->shouldReceive('nonceOf')->andReturn(6);
        });

        $this->artisan('aibot:wallet-transfer-resolve', ['id' => $transfer->id, '--fail' => true])->assertFailed();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);
    }

    #[Test]
    public function คำสั่งปิดรายการด้วยมือ_tx_ยังใน_mempool_ต้องใช้_force(): void
    {
        $transfer = $this->broadcasting();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('receipt')->andReturn(null);
            $mock->shouldReceive('transactionKnown')->andReturn(true);
            $mock->shouldReceive('nonceOf')->andReturn(5);
        });

        $this->artisan('aibot:wallet-transfer-resolve', ['id' => $transfer->id, '--fail' => true])->assertFailed();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->fresh()->status);

        $this->artisan('aibot:wallet-transfer-resolve', ['id' => $transfer->id, '--fail' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(AiBotWalletTransfer::FAIL_RESOLVED_BY_ADMIN, $transfer->fresh()->failure_code);
    }

    #[Test]
    public function คำสั่งคืนเข้าคิวด้วยมือใช้ได้เฉพาะรายการที่ยังไม่มี_hash(): void
    {
        $sent = $this->broadcasting();

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('receipt')->andReturn(null);
            $mock->shouldReceive('transactionKnown')->andReturn(false);
            $mock->shouldReceive('nonceOf')->andReturn(5);
        });

        // มี hash แล้ว = อาจออกไปแล้ว — คืนเข้าคิวคือเซ็นใหม่ด้วย nonce ใหม่ = เสี่ยงโอนซ้ำ
        $this->artisan('aibot:wallet-transfer-resolve', ['id' => $sent->id, '--requeue' => true])->assertFailed();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $sent->fresh()->status);

        $sent->forceFill(['status' => AiBotWalletTransfer::STATUS_SIGNING, 'tx_hash' => null, 'raw_tx' => null])->save();
        $this->artisan('aibot:wallet-transfer-resolve', ['id' => $sent->id, '--requeue' => true])->assertSuccessful();
        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $sent->fresh()->status);
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
