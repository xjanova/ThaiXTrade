<?php

namespace Tests\Feature\Discord;

use App\Services\Discord\DiscordChannelPlanner;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทเดาห้องจากชื่อได้ถูก (เจ้าของ: "บอทรู้ว่าจะโพสต์อะไรในห้องไหนได้เอง").
 *
 * Developed by Xman Studio.
 */
class DiscordChannelPlannerTest extends TestCase
{
    private function channel(string $id, string $name, int $type = 0, ?string $parent = null, int $position = 0): array
    {
        return ['id' => $id, 'name' => $name, 'type' => $type, 'parent_id' => $parent, 'position' => $position];
    }

    public function test_thai_and_english_channel_names_are_mapped_to_the_right_content(): void
    {
        $plan = app(DiscordChannelPlanner::class)->plan([
            $this->channel('100000000000000001', '📜กฎ-กติกา', position: 1),
            $this->channel('100000000000000002', '📢ประกาศ-ทางการ', 5, position: 2),
            $this->channel('100000000000000003', 'ข่าวสาร-tpix', position: 3),
            $this->channel('100000000000000004', '🎬วิดีโอ', position: 4),
            $this->channel('100000000000000005', 'whitepaper', position: 5),
            $this->channel('100000000000000006', '💰ซื้อเหรียญ-tpix', position: 6),
            $this->channel('100000000000000007', 'ถาม-ตอบ', position: 7),
            $this->channel('100000000000000008', 'พูดคุยทั่วไป', position: 8),
            $this->channel('100000000000000009', 'ห้องเสียง', 2, position: 9),
        ]);

        $expected = [
            'rules' => '100000000000000001',
            'announcements' => '100000000000000002',
            'news' => '100000000000000003',
            'videos' => '100000000000000004',
            'whitepaper' => '100000000000000005',
            'sale' => '100000000000000006',
            'ask' => '100000000000000007',
        ];
        $map = $plan['map'];
        ksort($expected);
        ksort($map);

        $this->assertSame($expected, $map);

        // ห้องเสียงโพสต์ไม่ได้ — ต้องไม่อยู่ในรายการให้เลือก
        $this->assertNotContains('100000000000000009', array_column($plan['postable'], 'id'));
    }

    public function test_the_category_name_counts_when_the_channel_name_is_generic(): void
    {
        $plan = app(DiscordChannelPlanner::class)->plan([
            $this->channel('200000000000000001', '📰 ข่าวสารและอัปเดต', 4),
            $this->channel('200000000000000002', 'ทั่วไป', 0, '200000000000000001'),
        ]);

        $this->assertSame('200000000000000002', $plan['map']['news']);
        $this->assertSame('📰 ข่าวสารและอัปเดต', $plan['postable'][0]['category']);
    }

    public function test_a_specific_name_beats_a_broad_one(): void
    {
        // "ซื้อขาย-แลกเปลี่ยน" มีคำว่า "ขาย" แต่ห้องขายเหรียญจริงต้องชนะ
        $plan = app(DiscordChannelPlanner::class)->plan([
            $this->channel('300000000000000001', 'ซื้อขาย-แลกเปลี่ยน', position: 1),
            $this->channel('300000000000000002', 'ขายเหรียญ-tpix', position: 2),
        ]);

        $this->assertSame('300000000000000002', $plan['map']['sale']);
    }

    public function test_missing_rooms_fall_back_to_announcements_but_never_to_general_chat(): void
    {
        $plan = app(DiscordChannelPlanner::class)->plan([
            $this->channel('400000000000000001', 'ประกาศ', 5),
            $this->channel('400000000000000002', 'general'),
        ]);

        $this->assertSame('400000000000000001', $plan['map']['news']);
        $this->assertSame('400000000000000001', $plan['map']['sale']);
        $this->assertSame('400000000000000001', $plan['map']['videos']);
        $this->assertArrayNotHasKey('rules', $plan['map']);
        $this->assertNotContains('400000000000000002', $plan['map']);
    }
}
