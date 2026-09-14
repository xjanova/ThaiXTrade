<?php

namespace App\Services\Discord;

/**
 * TPIX TRADE — เดาว่าห้องไหนในเซิร์ฟเวอร์ควรได้เนื้อหาอะไร.
 *
 * เจ้าของสั่ง: "มีห้องเยอะแล้ว ควรใส่อะไรไว้ห้องไหน บอทรู้ว่าจะโพสต์อะไรในห้องไหนได้เอง"
 *
 * ใช้ชื่อห้องเป็นหลัก + ชื่อหมวดเป็นตัวช่วย (ห้อง "ทั่วไป" ใต้หมวด "📢 ข่าวสาร" ก็นับว่าเป็นห้องข่าว)
 * ผลเป็นแค่ข้อเสนอ — แอดมินเปลี่ยนเองได้ที่หลังบ้านเมื่อเดาผิด
 *
 * Developed by Xman Studio.
 */
class DiscordChannelPlanner
{
    /** ห้องข้อความ (0) และห้องประกาศ (5) — ฟอรัม/เสียง/หมวด บอทโพสต์ลงไม่ได้ */
    public const POSTABLE_TYPES = [0, 5];

    private const CATEGORY_TYPE = 4;

    private const NAME_WEIGHT = 3;

    private const CATEGORY_WEIGHT = 1;

    /** ห้องประกาศของ Discord (type 5) คือห้องที่ออกแบบมาให้ทีมงานโพสต์ — ได้แต้มเพิ่มเล็กน้อย */
    private const ANNOUNCEMENT_BONUS = 1;

    /**
     * @param  list<array<string, mixed>>  $channels  ผลจาก GET /guilds/{id}/channels
     * @return array{map: array<string, string>, matches: array<string, list<string>>, postable: list<array{id: string, name: string, category: ?string}>}
     */
    public function plan(array $channels): array
    {
        $categories = [];
        foreach ($channels as $channel) {
            if ((int) ($channel['type'] ?? -1) === self::CATEGORY_TYPE) {
                $categories[(string) $channel['id']] = (string) ($channel['name'] ?? '');
            }
        }

        $postable = collect($channels)
            ->filter(fn ($c) => in_array((int) ($c['type'] ?? -1), self::POSTABLE_TYPES, true) && preg_match(DiscordSettings::SNOWFLAKE, (string) ($c['id'] ?? '')))
            ->sortBy(fn ($c) => [(int) ($c['position'] ?? 0), (string) $c['id']])
            ->values();

        $roles = (array) config('discord.roles', []);
        $best = [];
        $matches = [];

        foreach ($postable as $channel) {
            $name = $this->normalize((string) ($channel['name'] ?? ''));
            $category = $this->normalize($categories[(string) ($channel['parent_id'] ?? '')] ?? '');

            foreach ($roles as $role => $definition) {
                $score = 0;
                foreach ((array) ($definition['keywords'] ?? []) as $keyword) {
                    $keyword = $this->normalize((string) $keyword);
                    if ($keyword === '') {
                        continue;
                    }
                    if (str_contains($name, $keyword)) {
                        // คำยาว/เจาะจง ("ขายเหรียญ") ชนะคำสั้นที่กว้าง ("ขาย" ในห้องซื้อขายของทั่วไป)
                        $score = max($score, self::NAME_WEIGHT + (mb_strlen($keyword) >= 5 ? 1 : 0));
                    } elseif ($category !== '' && str_contains($category, $keyword)) {
                        $score = max($score, self::CATEGORY_WEIGHT);
                    }
                }

                if ($score === 0) {
                    continue;
                }

                if ((int) ($channel['type'] ?? 0) === 5) {
                    $score += self::ANNOUNCEMENT_BONUS;
                }

                $matches[(string) $channel['id']][] = $role;

                // แต้มเท่ากัน = ห้องที่อยู่บนกว่าชนะ (วนตามลำดับตำแหน่งอยู่แล้ว จึงแทนเฉพาะเมื่อมากกว่า)
                if (! isset($best[$role]) || $score > $best[$role]['score']) {
                    $best[$role] = ['id' => (string) $channel['id'], 'score' => $score];
                }
            }
        }

        $map = array_map(fn ($hit) => $hit['id'], $best);

        foreach ((array) config('discord.fallbacks', []) as $role => $fallbacks) {
            if (isset($map[$role])) {
                continue;
            }
            foreach ((array) $fallbacks as $fallback) {
                if (isset($map[$fallback])) {
                    $map[$role] = $map[$fallback];
                    break;
                }
            }
        }

        return [
            'map' => $map,
            'matches' => $matches,
            'postable' => $postable->map(fn ($c) => [
                'id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? ''),
                'category' => $categories[(string) ($c['parent_id'] ?? '')] ?? null,
            ])->all(),
        ];
    }

    /**
     * ตัวพิมพ์เล็ก + ตัดอีโมจิ/สัญลักษณ์ออก เหลือตัวอักษร (รวมไทยพร้อมสระ/วรรณยุกต์) ตัวเลข และขีด.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\-]+/u', '-', $text) ?? $text;

        return trim(preg_replace('/-+/', '-', $text) ?? $text, '-');
    }
}
