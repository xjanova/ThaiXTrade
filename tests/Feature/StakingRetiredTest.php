<?php

namespace Tests\Feature;

use App\Models\StakingPool;
use App\Models\StakingPosition;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ระบบ staking เดิมไม่เคยรับเหรียญจริง — แถวตำแหน่งสร้างจากตัวเลขที่ผู้ใช้พิมพ์
 * จึงเปิดรับเพิ่มไม่ได้ ส่วนตำแหน่งเก่าต้องดูและถอนได้เหมือนเดิม.
 */
class StakingRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x5555555555555555555555555555555555555555';

    private StakingPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('wallet_verified:'.self::WALLET, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHours(4));

        $this->pool = StakingPool::create([
            'name' => 'Flexible',
            'name_th' => 'ยืดหยุ่น',
            'apy_percent' => 36.5,
            'lock_days' => 0,
            'min_stake' => 10,
            'max_stake' => 1000000,
            'max_pool_size' => 10000000,
            'total_staked' => 0,
            'is_active' => true,
        ]);
    }

    private function position(array $overrides = []): StakingPosition
    {
        return StakingPosition::create($overrides + [
            'staking_pool_id' => $this->pool->id,
            'wallet_address' => self::WALLET,
            'amount' => '1000',
            'staked_at' => now()->subDays(10),
            'last_reward_at' => now()->subDays(10),
            'status' => 'active',
        ]);
    }

    #[Test]
    public function new_stakes_are_refused_without_creating_a_position(): void
    {
        $this->postJson('/api/v1/staking/stake', [
            'wallet_address' => self::WALLET,
            'pool_id' => $this->pool->id,
            'amount' => 100,
        ])
            ->assertStatus(410)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'STAKING_RETIRED');

        $this->assertSame(0, StakingPosition::count());
        $this->assertSame(0.0, (float) $this->pool->fresh()->total_staked);
    }

    #[Test]
    public function an_existing_position_can_still_unstake(): void
    {
        $position = $this->position();

        $this->postJson("/api/v1/staking/unstake/{$position->id}", ['wallet_address' => self::WALLET])
            ->assertOk();

        $this->assertNotSame('active', $position->fresh()->status);
    }

    #[Test]
    public function pending_reward_is_a_plain_decimal_for_a_ten_day_position(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $position = $this->position(['last_reward_at' => now()->subDays(10)])->load('pool');

        // 1000 × 36.5% ÷ 365 × 10 วัน
        $this->assertSame(0, bccomp($position->pending_reward, '10', 12), $position->pending_reward);
    }

    #[Test]
    public function pending_reward_survives_a_tiny_fraction_of_a_day(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $position = $this->position(['last_reward_at' => now()->subSecond()])->load('pool');

        // หนึ่งวินาที = 0.0000115... วัน แปลงเป็นสตริงแล้วกลายเป็น 1.1574074074074E-5 ซึ่ง bcmath ปฏิเสธ
        $this->assertTrue(bccomp($position->pending_reward, '0', 18) > 0, $position->pending_reward);
        $this->assertLessThan(0.001, (float) $position->pending_reward);
    }

    #[Test]
    public function pending_reward_is_zero_when_nothing_has_elapsed(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $position = $this->position(['last_reward_at' => now()])->load('pool');

        $this->assertSame('0', $position->pending_reward);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
