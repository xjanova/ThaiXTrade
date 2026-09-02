<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\Web3BalanceService;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — API กระเป๋าบอท: สร้าง ดูยอด ถอนกลับหาตัวเอง ยกเลิก และด่านโหมดจริง.
 *
 * Developed by Xman Studio.
 */
class AiBotWalletApiTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x3333333333333333333333333333333333333333';

    private string $other = '0x4444444444444444444444444444444444444444';

    private float $usdt = 120.0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AiBotPlanSeeder::class);

        // throttle ของกลุ่มกับของ route ใช้คีย์ (IP) เดียวกัน — เทสต์ยิงหลายคำขอติดกันแล้วชน 429
        // กฎ throttle มีเทสต์ของตัวเองแยกต่างหาก ที่นี่ทดสอบพฤติกรรมของกระเป๋าบอท
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
            'aibot.live_enabled' => true,
        ]);

        foreach ([$this->wallet, $this->other] as $w) {
            Cache::put('wallet_verified:'.$w, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));
        }

        $this->partialMock(Web3BalanceService::class, function ($mock) {
            $mock->shouldReceive('getNativeBalance')->andReturn('0.05');
            $mock->shouldReceive('getTokenBalance')->andReturnUsing(fn () => (string) $this->usdt);
        });
    }

    #[Test]
    public function ยังไม่มีกระเป๋าตอบ_null_แล้วสร้างได้หนึ่งใบ(): void
    {
        $this->getJson('/api/v1/ai-bot/wallet?wallet_address='.$this->wallet)
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.wallet', null);

        $created = $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $address = $created->json('data.wallet.address');
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $address);
        $this->assertNull($created->json('data.wallet.key_ciphertext'), 'กุญแจต้องไม่ออกทาง API');

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet])
            ->assertStatus(200)
            ->assertJsonPath('data.wallet.address', $address);

        $this->assertSame(1, AiBotWallet::count());
    }

    #[Test]
    public function ปิดฟีเจอร์แล้วสร้างไม่ได้และบอกเหตุผล(): void
    {
        config(['aibot.bot_wallet.enabled' => false]);

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'BOT_WALLET_DISABLED');

        $this->getJson('/api/v1/ai-bot/wallet?wallet_address='.$this->wallet)
            ->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function ถอนได้เฉพาะกลับหาตัวเอง_และเห็นเฉพาะรายการของตัวเอง(): void
    {
        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet]);

        $this->postJson('/api/v1/ai-bot/wallet/withdraw', [
            'wallet_address' => $this->wallet, 'asset' => 'USDT', 'amount' => 50,
            'to_address' => $this->other,   // ต้องถูกเมิน — ไม่มีช่องนี้ให้ใช้
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.transfer.status', 'queued')
            ->assertJsonPath('data.transfer.to_address', $this->wallet)
            ->assertJsonPath('data.transfer.cancellable', true);

        $this->assertSame(1, AiBotWalletTransfer::where('to_address', $this->wallet)->count());

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->other]);
        $this->getJson('/api/v1/ai-bot/wallet?wallet_address='.$this->other)
            ->assertJsonCount(0, 'data.transfers');

        $this->getJson('/api/v1/ai-bot/wallet?wallet_address='.$this->wallet)
            ->assertJsonCount(1, 'data.transfers')
            ->assertJsonPath('data.wallet.has_pending_withdraw', true);
    }

    #[Test]
    public function ถอนเกินยอดหรือซ้ำซ้อนต้องได้ข้อความที่อ่านรู้เรื่อง(): void
    {
        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet]);

        $this->postJson('/api/v1/ai-bot/wallet/withdraw', ['wallet_address' => $this->wallet, 'asset' => 'USDT', 'amount' => 999])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOT_WALLET_INSUFFICIENT');

        $this->postJson('/api/v1/ai-bot/wallet/withdraw', ['wallet_address' => $this->wallet, 'asset' => 'USDT', 'amount' => 10])
            ->assertStatus(201);

        $this->postJson('/api/v1/ai-bot/wallet/withdraw', ['wallet_address' => $this->wallet, 'asset' => 'USDT', 'amount' => 10])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOT_WALLET_TRANSFER_IN_FLIGHT');

        $this->postJson('/api/v1/ai-bot/wallet/withdraw', ['wallet_address' => $this->wallet, 'asset' => 'USDT'])
            ->assertStatus(422);
    }

    #[Test]
    public function ยกเลิกรายการที่ยังไม่ส่งได้_ของคนอื่นไม่ได้(): void
    {
        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet]);
        $id = $this->postJson('/api/v1/ai-bot/wallet/withdraw', ['wallet_address' => $this->wallet, 'asset' => 'USDT', 'amount' => 10])
            ->json('data.transfer.id');

        $this->postJson("/api/v1/ai-bot/wallet/withdraw/{$id}/cancel", ['wallet_address' => $this->other])
            ->assertStatus(404);

        $this->postJson("/api/v1/ai-bot/wallet/withdraw/{$id}/cancel", ['wallet_address' => $this->wallet])
            ->assertStatus(200)
            ->assertJsonPath('data.transfer.status', 'cancelled');
    }

    #[Test]
    public function รีเฟรชยอดอ่านจากเชนใหม่(): void
    {
        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet]);
        $this->usdt = 777.0;

        $this->postJson('/api/v1/ai-bot/wallet/refresh', ['wallet_address' => $this->wallet])
            ->assertStatus(200)
            ->assertJsonPath('data.wallet.balances.USDT', 777);
    }

    #[Test]
    public function โหมดจริงต้องมีกระเป๋าบอทที่มีทุนก่อน(): void
    {
        $plan = AiBotPlan::where('tier', 'vip')->firstOrFail();
        AiBotSubscription::create([
            'wallet_address' => $this->wallet, 'ai_bot_plan_id' => $plan->id,
            'status' => 'active', 'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        $bot = AiBotConfig::create([
            'wallet_address' => $this->wallet, 'name' => 'b', 'pair' => 'BTC/USDT', 'strategy' => 'momentum',
            'timeframe' => '1h', 'status' => 'paused', 'mode' => 'demo',
        ]);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", ['wallet_address' => $this->wallet, 'mode' => 'live'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOT_WALLET_REQUIRED');

        $this->postJson('/api/v1/ai-bot/wallet', ['wallet_address' => $this->wallet]);

        // มีกระเป๋าแต่ยังไม่มี USDT (ยังไม่เคยอ่านยอด)
        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", ['wallet_address' => $this->wallet, 'mode' => 'live'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOT_WALLET_EMPTY');

        $this->postJson('/api/v1/ai-bot/wallet/refresh', ['wallet_address' => $this->wallet]);

        $this->postJson("/api/v1/ai-bot/bots/{$bot->id}/mode", ['wallet_address' => $this->wallet, 'mode' => 'live'])
            ->assertStatus(200)
            ->assertJsonPath('data.mode', 'live');
    }
}
