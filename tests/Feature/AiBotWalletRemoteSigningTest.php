<?php

namespace Tests\Feature;

use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletRemoteSigner;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\Wallet\BotWalletSigner;
use App\Services\Web3BalanceService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — กระเป๋าบอทที่กุญแจอยู่กับตัวเซ็นแยกเครื่อง: สร้างผ่าน API และถอนผ่านคำสั่งส่งคิว.
 *
 * ตั้งสภาพแวดล้อมเป็น production ทั้งไฟล์ เพราะนี่คือทางเดียวที่ production ใช้ได้
 * สิ่งที่พิสูจน์: เครื่องเว็บไม่ถอดกุญแจเลยสักจังหวะ และตัวเซ็นล่ม = ไม่มีกระเป๋า/ไม่มีธุรกรรม (ไม่ถอยไปแบบเดิม)
 *
 * Developed by Xman Studio.
 */
class AiBotWalletRemoteSigningTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const BOT_ADDRESS = '0x2222222222222222222222222222222222222222';

    private const HASH = '0x'.'ab'.'cd'.'ef'.'00'.'11'.'22'.'33'.'44'.'55'.'66'.'77'.'88'.'99'.'aa'.'bb'.'cc'.'dd'.'ee'.'ff'.'01'.'02'.'03'.'04'.'05'.'06'.'07'.'08'.'09'.'10'.'20'.'30'.'40';

    private string $sealed;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AiBotPlanSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->app['env'] = 'production';
        $this->sealed = base64_encode(random_bytes(60));

        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.confirmations' => 2,
            // production จริงไม่มีคีย์ห่อบนเครื่องเว็บ
            'aibot.bot_wallet.encryption_key' => null,
            'aibot.bot_wallet.signer.driver' => 'remote',
            'aibot.bot_wallet.signer.url' => 'http://10.8.0.2:8787',
            'aibot.bot_wallet.signer.secret' => str_repeat('s', 40),
        ]);

        Cache::put('wallet_verified:'.self::OWNER, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));

        $this->partialMock(Web3BalanceService::class, function ($mock) {
            $mock->shouldReceive('getNativeBalance')->andReturn('0.05');
            $mock->shouldReceive('getTokenBalance')->andReturn('100');
        });
    }

    private function signerCreatesWallet(): void
    {
        Http::fake(['*/v1/wallets' => Http::response(['ok' => true, 'address' => self::BOT_ADDRESS, 'sealed' => $this->sealed, 'keyVersion' => 2])]);
    }

    #[Test]
    public function สร้างกระเป๋าผ่าน_api_ได้กระเป๋ารุ่น_remote_และกุญแจไม่ออกทางคำตอบ(): void
    {
        $this->signerCreatesWallet();

        $created = $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => self::OWNER])
            ->assertStatus(201)
            ->assertJsonPath('data.wallet.address', self::BOT_ADDRESS);

        $this->assertStringNotContainsString($this->sealed, $created->getContent());

        $wallet = AiBotWallet::sole();
        $this->assertSame(BotWalletKeyring::KEY_VERSION_REMOTE, $wallet->key_version);
        $this->assertSame($this->sealed, Crypt::decryptString($wallet->key_ciphertext));

        Http::assertSent(fn (Request $request) => $request->data() === ['owner' => self::OWNER]);
    }

    #[Test]
    public function ตัวเซ็นไม่ตอบแล้วไม่มีกระเป๋าเกิดขึ้น_และผู้ใช้ได้เหตุผลที่อ่านรู้เรื่อง(): void
    {
        Http::fake(['*' => Http::response('bad gateway', 502)]);

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => self::OWNER])
            ->assertStatus(503)
            ->assertJsonPath('error.code', BotWalletRemoteSigner::ERR_UNAVAILABLE)
            ->assertJsonMissing(['message' => 'ทำรายการไม่สำเร็จ']);

        $this->assertSame(0, AiBotWallet::count());
    }

    #[Test]
    public function ยังไม่ตั้งค่าตัวเซ็นบน_production_ระบบถือว่ากระเป๋าบอทยังไม่เปิด(): void
    {
        config(['aibot.bot_wallet.signer.url' => null, 'aibot.bot_wallet.encryption_key' => str_repeat('k', 40)]);
        Http::fake();

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => self::OWNER])
            ->assertStatus(503)
            ->assertJsonPath('error.code', BotWalletService::ERR_DISABLED);

        // ต่อให้มีคนตั้ง AIBOT_BOT_WALLET_KEY ไว้ตามเอกสารเก่า ก็ไม่สร้างกุญแจบนเครื่องเว็บ
        config(['aibot.bot_wallet.signer.driver' => 'local']);

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => self::OWNER])
            ->assertStatus(503)
            ->assertJsonPath('error.code', BotWalletService::ERR_DISABLED);

        $this->assertSame(0, AiBotWallet::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function ถอนจากกระเป๋ารุ่น_remote_เซ็นที่ตัวเซ็น_เครื่องเว็บไม่แตะกุญแจ(): void
    {
        $this->signerCreatesWallet();

        $service = app(BotWalletService::class);
        $wallet = $service->ensure(self::OWNER);
        $transfer = $service->requestWithdraw(self::OWNER, 'USDT', 25.0);
        $seen = [];

        $this->mock(BotWalletRemoteSigner::class, function (MockInterface $mock) use ($wallet, $transfer) {
            $mock->shouldReceive('signWithdraw')->once()->withArgs(function ($forWallet, $sealed, $wei, $token) use ($wallet, $transfer) {
                return $forWallet->is($wallet)
                    && $sealed === $this->sealed
                    && $wei === '25000000000000000000'
                    && $token === $transfer->token_address;
            })->andReturn(['ok' => true, 'address' => self::BOT_ADDRESS, 'nonce' => 4, 'txHash' => self::HASH, 'raw' => '0xraw']);
        });

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) use (&$seen, $transfer) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            // ตัวเซ็นบนเครื่องเว็บ (ที่ต้องรับกุญแจส่วนตัว) ต้องไม่ถูกเรียกเลย
            $mock->shouldNotReceive('sign');
            $mock->shouldReceive('send')->once()->with('0xraw')->andReturnUsing(function () use (&$seen, $transfer) {
                $seen[] = $transfer->fresh()->tx_hash;

                return ['ok' => true, 'txHash' => self::HASH, 'alreadyKnown' => false];
            });
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame([self::HASH], $seen, 'บันทึก hash ก่อนส่งเสมอ เหมือนกระเป๋ารุ่นเดิม');

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->status);
        $this->assertSame(4, $transfer->nonce);
    }

    #[Test]
    public function ติดต่อตัวเซ็นไม่ได้แล้วรายการกลับเข้าคิว_ไม่ถูกปิดเป็นล้มเหลว(): void
    {
        $this->signerCreatesWallet();

        $service = app(BotWalletService::class);
        $service->ensure(self::OWNER);
        $transfer = $service->requestWithdraw(self::OWNER, 'USDT', 25.0);

        $this->mock(BotWalletRemoteSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('signWithdraw')->twice()->andReturn(
                ['ok' => false, 'code' => 'signer_unreachable', 'error' => 'ติดต่อไม่ได้'],
                ['ok' => true, 'address' => self::BOT_ADDRESS, 'nonce' => 1, 'txHash' => self::HASH, 'raw' => '0xraw'],
            );
        });

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldNotReceive('sign');
            $mock->shouldReceive('send')->once()->with('0xraw')->andReturn(['ok' => true, 'txHash' => self::HASH, 'alreadyKnown' => false]);
        });

        $this->artisan('aibot:wallet-transfers')->expectsOutputToContain('คืนเข้าคิว')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_QUEUED, $transfer->status);
        $this->assertNull($transfer->signing_at);
        $this->assertNull($transfer->failure_code);

        // ตัวเซ็นกลับมาแล้ว รอบถัดไปส่งได้ตามปกติ
        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $this->assertSame(AiBotWalletTransfer::STATUS_BROADCASTING, $transfer->refresh()->status);
    }

    #[Test]
    public function ตัวเซ็นปฏิเสธแล้วรายการล้มโดยไม่มีอะไรถูกส่ง(): void
    {
        $this->signerCreatesWallet();

        $service = app(BotWalletService::class);
        $service->ensure(self::OWNER);
        $transfer = $service->requestWithdraw(self::OWNER, 'USDT', 25.0);

        $this->mock(BotWalletRemoteSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('signWithdraw')->once()->andReturn(['ok' => false, 'code' => 'policy_rate', 'error' => 'ครบเพดาน']);
        });

        $this->mock(BotWalletSigner::class, function (MockInterface $mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('blockNumber')->andReturn(null);
            $mock->shouldNotReceive('sign');
            $mock->shouldNotReceive('send');
        });

        $this->artisan('aibot:wallet-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame(AiBotWalletTransfer::STATUS_FAILED, $transfer->status);
        $this->assertSame(AiBotWalletTransfer::FAIL_SIGN, $transfer->failure_code);
        $this->assertNull($transfer->tx_hash);
    }
}
