<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RPC ของเชน TPIX สะดุด ≠ สัญญา DEX หาย
 *
 * ⚠️ เดิม isLive() แคช "ถามไม่ได้" (timeout/403/429) ว่า false 5 นาที
 *    → dexConfig().ready = false → dex:sync เขียนเชน 4289 เป็น coming_soon
 *    → ทั้งเว็บปิด DEX (เชนปริยายกลับเป็น BSC, ตัวเลือกเชนขึ้น "Soon") แล้วเปิดเองใน 5 นาที — flap
 *    ส่วน "ไม่มีโค้ดจริง" (เชน regenesis ตอบ 0x) ยังต้องปิดทันทีเหมือนเดิม (DexSyncTest)
 */
class DexRpcResilienceTest extends TestCase
{
    private const ADDR = [
        'wtpix' => '0x1111111111111111111111111111111111111111',
        'usdt_tpix' => '0x2222222222222222222222222222222222222222',
        'dex_factory' => '0x3333333333333333333333333333333333333333',
        'dex_router' => '0x4444444444444444444444444444444444444444',
    ];

    /** 'code' = มีโค้ด · 'empty' = เชนตอบ 0x · 'down' = เชนตอบไม่ได้ */
    private string $chainMode = 'code';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'blockchain.dex.wtpix' => '',
            'blockchain.dex.usdt' => '',
            'blockchain.dex.factory' => '',
            'blockchain.dex.router' => '',
        ]);

        foreach (self::ADDR as $key => $address) {
            SiteSetting::set(ContractRegistry::GROUP, $key, $address);
        }
        Cache::flush();

        Http::fake(function () {
            return match ($this->chainMode) {
                'down' => Http::response('<html>429 Too Many Requests</html>', 429),
                'empty' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']),
                default => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x6080604052']),
            };
        });
    }

    private function registry(): ContractRegistry
    {
        return app(ContractRegistry::class);
    }

    /** ลืมคำตอบระยะสั้น (5 นาที) แต่เก็บ "คำตอบล่าสุดที่เชนยืนยัน" ไว้ — เหมือนเวลาผ่านไปเกินแคช */
    private function expireShortCache(): void
    {
        foreach (self::ADDR as $key => $address) {
            Cache::forget("contracts:live:{$key}:".strtolower($address));
        }
        Cache::forget('dex:config:public');
    }

    private function tpixChain(): Chain
    {
        return Chain::where('chain_id', 4289)->firstOrFail();
    }

    public function test_an_rpc_outage_keeps_the_last_confirmed_answer(): void
    {
        $this->assertTrue($this->registry()->dexConfig()['ready']);

        $this->expireShortCache();
        $this->chainMode = 'down';

        $cfg = $this->registry()->dexConfig();
        $this->assertTrue($cfg['ready'], 'RPC สะดุดต้องไม่ทำให้ DEX ที่เพิ่งยืนยันว่ามีโค้ดกลายเป็น "ไม่พร้อม"');
        $this->assertSame([], $cfg['missing']);
        $this->assertTrue($this->registry()->isLive('dex_router'));
    }

    public function test_an_unanswered_check_is_unverified_not_missing(): void
    {
        $this->chainMode = 'down';

        $cfg = $this->registry()->dexConfig();

        $this->assertFalse($cfg['ready']);
        $this->assertSame([], $cfg['missing']);
        $this->assertEqualsCanonicalizing(array_keys(self::ADDR), $cfg['unverified']);
        $this->assertNull($this->registry()->liveState('dex_router'));
    }

    public function test_an_rpc_failure_is_not_cached_as_dead(): void
    {
        $this->chainMode = 'down';
        $this->assertFalse($this->registry()->dexConfig()['ready']);

        // เชนกลับมาแล้ว — ต้องตอบตามจริงทันที ไม่ใช่รอแคช "ตาย" หมดอายุ 5 นาที
        $this->chainMode = 'code';
        $this->assertTrue($this->registry()->dexConfig()['ready']);
    }

    public function test_dex_sync_never_closes_a_live_chain_because_the_rpc_did_not_answer(): void
    {
        $this->tpixChain()->forceFill(['status' => Chain::STATUS_LIVE])->save();
        $this->chainMode = 'down';

        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(Chain::STATUS_LIVE, $this->tpixChain()->fresh()->status);
    }

    public function test_dex_sync_still_closes_the_chain_when_the_code_is_really_gone(): void
    {
        $this->tpixChain()->forceFill(['status' => Chain::STATUS_LIVE])->save();
        $this->chainMode = 'empty';

        $this->artisan('dex:sync')->assertSuccessful();

        $this->assertSame(Chain::STATUS_COMING_SOON, $this->tpixChain()->fresh()->status);
    }

    public function test_a_not_ready_config_is_cached_only_briefly(): void
    {
        $this->chainMode = 'down';
        $this->getJson('/api/v1/dex/config')->assertOk()->assertJsonPath('data.ready', false);

        $this->chainMode = 'code';
        // คำตอบ "ไม่พร้อม" จำแค่ 10 วิ — เดิม 60 วิ ทุกหน้าที่เปิดในนาทีนั้นปิด DEX ทั้งที่เชนกลับมาแล้ว
        $this->travel(11)->seconds();
        $this->getJson('/api/v1/dex/config')->assertOk()->assertJsonPath('data.ready', true);
    }
}
