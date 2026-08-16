<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TPIX TRADE - Chain Status Tests
 * ตรวจว่า /api/v1/chains ส่ง status ครบทุกเชน:
 * BSC ต้อง live (เชนเดียวที่เปิดเทรดจริง) ส่วนเชนอื่นเป็น coming_soon
 * (เห็นใน selector ได้แต่กดเลือกไม่ได้)
 * Developed by Xman Studio.
 */
class ChainStatusTest extends TestCase
{
    /**
     * ทุกเชนใน API ต้องมี field status เสมอ — frontend ใช้ตัดสินใจเปิด/ปิดปุ่ม
     */
    public function test_every_chain_has_status_field(): void
    {
        $response = $this->getJson('/api/v1/chains');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $chains = $response->json('data');
        $this->assertNotEmpty($chains);

        foreach ($chains as $chain) {
            $this->assertArrayHasKey('status', $chain, "Chain {$chain['name']} missing status field");
            $this->assertContains($chain['status'], ['live', 'coming_soon']);
        }
    }

    /**
     * BSC (56) เป็นเชนเดียวที่เปิดเทรดจริงตอนนี้.
     */
    public function test_bsc_is_the_only_live_chain(): void
    {
        $response = $this->getJson('/api/v1/chains');
        $chains = collect($response->json('data'));

        $bsc = $chains->firstWhere('chainId', 56);
        $this->assertNotNull($bsc);
        $this->assertSame('live', $bsc['status']);

        // เชนอื่นทั้งหมดต้องยังไม่เปิด (เห็นไว้ก่อน กดไม่ได้)
        $liveChains = $chains->where('status', 'live');
        $this->assertCount(1, $liveChains);
    }

    /**
     * TPIX Chain (4289) โชว์ในลิสต์แต่สถานะ coming_soon จนกว่า DEX บนเชนพร้อม
     */
    public function test_tpix_chain_is_visible_but_coming_soon(): void
    {
        $response = $this->getJson('/api/v1/chains');
        $chains = collect($response->json('data'));

        $tpix = $chains->firstWhere('chainId', 4289);
        $this->assertNotNull($tpix, 'TPIX Chain must stay visible in the chain list');
        $this->assertSame('coming_soon', $tpix['status']);
        $this->assertTrue($tpix['enabled']);
    }
}
