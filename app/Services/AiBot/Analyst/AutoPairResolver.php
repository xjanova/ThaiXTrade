<?php

namespace App\Services\AiBot\Analyst;

use App\Models\AiBotConfig;
use App\Models\AiBotPlan;
use App\Models\TradingPair;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — ให้ AI เลือกเหรียญที่บอทจะเทรด.
 *
 * บอทที่เปิดโหมดนี้ (`params.auto_pair = true`) จะย้ายไปเทรดคู่ที่ AI จัดอันดับ
 * ไว้สูงสุดในรอบนั้น แทนที่จะยึดคู่เดียวตลอดอายุการใช้งาน
 *
 * ═══ กฎสามข้อที่ห้ามข้าม ═══
 *
 * 1. **ห้ามย้ายตอนถือของอยู่** — ย้ายแล้วไม้เดิมจะค้างอยู่บนคู่ที่ไม่มีใครดูแล
 *    ต่อไป ไม่มีกลยุทธ์ไหนคอยขายให้ และ stop loss ก็ไม่ถูกตรวจอีกเลย
 *    เงินของผู้ใช้จะลอยอยู่ตรงนั้นจนกว่าจะมีคนไปกดขายเอง
 *
 * 2. **ห้ามย้ายถี่กว่า min_hold_minutes** — ทุกครั้งที่ย้ายแล้วเข้าไม้ใหม่มีต้นทุน
 *    จริง 0.36% ถ้าอันดับสลับทุกรอบ 15 นาที ค่าเข้าออกจะกินกำไรหมดก่อนที่
 *    การเลือกเหรียญจะได้พิสูจน์ตัวเองว่าดีกว่าการอยู่เฉยๆ หรือเปล่า
 *
 * 3. **คู่ปลายทางต้องเปิดเทรดอยู่จริง** — AI คัดจากรายการที่เราส่งไป แต่แอดมิน
 *    ปิดคู่ได้ตลอดเวลาระหว่างรอบ ย้ายไปคู่ที่ปิดแล้ว = บอทตายเงียบ
 *
 * Developed by Xman Studio.
 */
class AutoPairResolver
{
    /** ที่เก็บเวลาย้ายล่าสุด — อยู่ใน stats เพื่อไม่ต้องเพิ่มคอลัมน์ */
    private const SWITCHED_AT = 'auto_pair_switched_at';

    public function __construct(private readonly AiViewGate $gate) {}

    /** บอทตัวนี้เปิดโหมดให้ AI เลือกเหรียญไว้ไหม */
    public function isAuto(AiBotConfig $bot): bool
    {
        return (bool) config('aibot_analyst.auto_pair.enabled', true)
            && (($bot->params['auto_pair'] ?? false) === true);
    }

    /**
     * ย้ายบอทไปคู่ที่ AI คัดไว้ ถ้าเงื่อนไขครบ.
     *
     * @param  bool  $hasPosition  ถือของอยู่ไหม — ถือแล้วห้ามย้าย (กฎข้อ 1)
     * @return array{switched: bool, pair: string, reason: string}
     */
    public function resolve(AiBotConfig $bot, ?AiBotPlan $plan, bool $hasPosition): array
    {
        $stay = fn (string $reason) => ['switched' => false, 'pair' => (string) $bot->pair, 'reason' => $reason];

        if (! $this->isAuto($bot)) {
            return $stay('บอทตัวนี้ไม่ได้เปิดโหมดให้ AI เลือกเหรียญ');
        }

        if ($hasPosition) {
            return $stay('ยังถือของอยู่ — รอปิดไม้ก่อนถึงจะย้ายเหรียญได้');
        }

        $cooldown = (int) config('aibot_analyst.auto_pair.min_hold_minutes', 240);
        $switchedAt = $this->switchedAt($bot);

        if ($switchedAt && $switchedAt->diffInMinutes(now()) < $cooldown) {
            $wait = $cooldown - (int) $switchedAt->diffInMinutes(now());

            return $stay("เพิ่งย้ายเหรียญไปเมื่อไม่นาน — รออีก {$wait} นาที");
        }

        $view = $this->gate->viewFor($plan);

        if (! $view) {
            return $stay('ยังไม่มีมุมมองตลาดของ AI ที่ใช้ได้ — คงคู่เดิมไว้ก่อน');
        }

        $target = $this->firstTradable($view->shortlistPairs());

        if ($target === null) {
            return $stay('AI ยังไม่ได้คัดคู่ไหนไว้ หรือคู่ที่คัดไม่ได้เปิดเทรด');
        }

        if (strcasecmp($target, (string) $bot->pair) === 0) {
            return $stay('AI ยังคัดคู่เดิมเป็นอันดับหนึ่ง');
        }

        $from = (string) $bot->pair;

        $bot->update([
            'pair' => $target,
            'stats' => array_merge($bot->stats ?? [], [
                self::SWITCHED_AT => now()->toDateTimeString(),
                'auto_pair_from' => $from,
            ]),
        ]);

        return [
            'switched' => true,
            'pair' => $target,
            'reason' => "AI ย้ายเหรียญจาก {$from} ไป {$target}",
        ];
    }

    /**
     * คู่แรกในรายการที่เปิดเทรดอยู่จริง (กฎข้อ 3).
     *
     * @param  list<string>  $shortlist
     */
    private function firstTradable(array $shortlist): ?string
    {
        foreach ($shortlist as $pair) {
            $exists = TradingPair::query()
                ->where('is_active', true)
                ->where('symbol', $pair)
                ->exists();

            if ($exists) {
                return $pair;
            }
        }

        return null;
    }

    private function switchedAt(AiBotConfig $bot): ?Carbon
    {
        $raw = $bot->stats[self::SWITCHED_AT] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            // ค่าเสียในฐานข้อมูลต้องไม่ทำให้บอทหยุด — ถือว่ายังไม่เคยย้าย
            return null;
        }
    }
}
