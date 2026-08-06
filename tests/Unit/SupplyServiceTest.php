<?php

namespace Tests\Unit;

use App\Services\SupplyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE - SupplyService Tests
 * ทดสอบการคำนวณ circulating supply ที่เผยแพร่ให้ CoinGecko / CMC / DeFiLlama
 * Developed by Xman Studio.
 */
class SupplyServiceTest extends TestCase
{
    private const POOL_A = '0xf54c0deE404ec728a03b467cba7bBA171CC77dad';

    private const POOL_B = '0x24CD5d5A6B5EcC6520c76f5427DB06F81BcC61C5';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'supply.total_supply' => '1000',
            'supply.max_supply' => '1000',
            'supply.decimals' => 18,
            'supply.strategy' => 'onchain',
            'supply.rpc_url' => 'https://rpc.tpix.test',
            'supply.cache_ttl' => 60,
            'supply.locked_addresses' => [
                ['address' => self::POOL_A, 'label' => 'Pool A', 'initial' => '600', 'category' => 'rewards'],
                ['address' => self::POOL_B, 'label' => 'Pool B', 'initial' => '300', 'category' => 'validator'],
            ],
        ]);
    }

    /** ตอบยอดเป็น hex wei ให้ตรงกับจำนวน TPIX ที่ต้องการ */
    private function weiHex(string $tpix): string
    {
        return '0x'.base_convert(bcmul($tpix, bcpow('10', '18', 0), 0), 10, 16);
    }

    // =========================================================================
    // happy path
    // =========================================================================

    public function test_circulating_is_total_minus_onchain_locked(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->weiHex('500')])
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->weiHex('200')]),
        ]);

        $snapshot = (new SupplyService())->snapshot();

        $this->assertSame('700', $snapshot['locked']);
        $this->assertSame('300', $snapshot['circulating']);
        $this->assertFalse($snapshot['degraded']);
    }

    public function test_sends_explicit_user_agent(): void
    {
        // Cloudflare หน้า rpc.tpix.online ตอบ 403 ให้ request ที่ไม่มี User-Agent
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0'])]);

        (new SupplyService())->snapshot();

        Http::assertSent(fn ($request) => str_contains(
            $request->header('User-Agent')[0] ?? '',
            'ThaiXTrade-SupplyService'
        ));
    }

    // =========================================================================
    // RPC ล้มเหลว — ห้ามดัน circulating สูงเกินจริง
    // =========================================================================

    public function test_rpc_failure_falls_back_to_genesis_amount_not_zero(): void
    {
        // นี่คือบั๊กเดิม: ดึงยอดไม่ได้ → นับเป็น 0 → locked = 0 →
        // circulating = total ทั้งก้อน ถูกเสิร์ฟให้ผู้รวบรวมราคา
        Http::fake(['*' => Http::response(null, 500)]);

        $snapshot = (new SupplyService())->snapshot();

        $this->assertTrue($snapshot['degraded']);
        $this->assertSame('900', $snapshot['locked'], 'ต้องถอยไปใช้ยอด genesis (600+300) ไม่ใช่ 0');
        $this->assertSame('100', $snapshot['circulating']);
        $this->assertNotSame('1000', $snapshot['circulating']);
    }

    public function test_cloudflare_403_does_not_inflate_circulating(): void
    {
        Http::fake(['*' => Http::response('<html>403 Forbidden</html>', 403)]);

        $snapshot = (new SupplyService())->snapshot();

        $this->assertTrue($snapshot['degraded']);
        $this->assertSame('100', $snapshot['circulating']);
    }

    public function test_jsonrpc_error_object_is_not_treated_as_zero_balance(): void
    {
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32000, 'message' => 'unknown block'],
        ], 200)]);

        $snapshot = (new SupplyService())->snapshot();

        $this->assertTrue($snapshot['degraded']);
        $this->assertSame('900', $snapshot['locked']);
    }

    public function test_partial_failure_marks_degraded(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->weiHex('500')])
                ->push(null, 500),
        ]);

        $snapshot = (new SupplyService())->snapshot();

        $this->assertTrue($snapshot['degraded']);
        // ใบแรกได้ยอดจริง 500, ใบที่สองถอยไปใช้ genesis 300
        $this->assertSame('800', $snapshot['locked']);
    }

    // =========================================================================
    // cache
    // =========================================================================

    public function test_degraded_snapshot_is_cached_briefly_so_it_self_heals(): void
    {
        // รอบแรกล้มทั้งสองใบ รอบสองสำเร็จทั้งสองใบ — ใช้ sequence เดียว เพราะ
        // เรียก Http::fake() ซ้ำจะไม่ทับ stub เดิม (ตัวที่ match ก่อนชนะ)
        Http::fake([
            '*' => Http::sequence()
                ->push(null, 500)
                ->push(null, 500)
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->weiHex('500')])
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->weiHex('200')]),
        ]);

        $service = new SupplyService();
        $this->assertTrue($service->snapshot()['degraded']);
        Http::assertSentCount(2);

        // TTL ของผลที่ไม่สมบูรณ์ (15s) ต้องสั้นกว่า cache_ttl ปกติ (60s)
        $this->travel(20)->seconds();

        $fresh = $service->snapshot();

        $this->assertFalse($fresh['degraded'], 'ผลที่ไม่สมบูรณ์ต้องหมดอายุเร็วและถามใหม่');
        $this->assertSame('300', $fresh['circulating']);
        Http::assertSentCount(4);
    }

    public function test_healthy_snapshot_is_cached_for_full_ttl(): void
    {
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->weiHex('500')])]);

        $service = new SupplyService();
        $first = $service->snapshot();

        $this->travel(20)->seconds();
        $second = $service->snapshot();

        $this->assertSame($first['updated_at'], $second['updated_at'], 'ยังอยู่ใน TTL ต้องไม่ถาม RPC ซ้ำ');
    }

    // =========================================================================
    // manual strategy
    // =========================================================================

    public function test_manual_strategy_never_touches_rpc(): void
    {
        Http::fake();
        config(['supply.strategy' => 'manual', 'supply.circulating_override' => '250']);

        $snapshot = (new SupplyService())->snapshot();

        $this->assertSame('250', $snapshot['circulating']);
        $this->assertSame('750', $snapshot['locked']);
        $this->assertFalse($snapshot['degraded']);
        Http::assertNothingSent();
    }
}
