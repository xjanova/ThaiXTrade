<?php

namespace App\Services\AiBot;

use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use App\Models\AiBotPosition;
use App\Services\AiBot\Analyst\AiViewGate;
use App\Services\AiBot\Analyst\AutoPairResolver;
use App\Services\AiBotService;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ตัวรันบอทหนึ่งรอบ.
 *
 * ลำดับการทำงานต่อบอทหนึ่งตัว (ห้ามสลับลำดับ):
 *   1. เจ้าของยังเช่าอยู่ไหม        — หมดอายุแล้วต้องหยุด ไม่ใช่เทรดฟรีต่อ
 *   2. ดึงแท่งเทียนจริง
 *   3. **ด่านความเสี่ยง**            — ตลาดพัง/ข่าวร้าย = เทออกก่อน ไม่ต้องถามกลยุทธ์
 *   4. **กรอบความเสี่ยงของผู้ใช้**   — stop loss / take profit / ขาดทุนสูงสุดต่อวัน
 *   5. ถามกลยุทธ์
 *   6. ลงมือผ่านโบรกเกอร์จำลอง
 *
 * ที่ให้ด่านความเสี่ยงมาก่อนกลยุทธ์เพราะกลยุทธ์ส่วนใหญ่ "ซื้อตอนราคาถูก" —
 * ในวันที่ตลาดพังจริงมันจะเห็นเป็นของถูกแล้วรับมีดตกตลอดทาง
 *
 * โหมด live ยังไม่ลงมือส่งธุรกรรมเอง — ระบบไม่ถือกุญแจของผู้ใช้ (non-custodial)
 * จึงบันทึกสัญญาณไว้ให้ผู้ใช้กดยืนยันเองในหน้าเว็บ
 *
 * Developed by Xman Studio.
 */
class BotRunner
{
    /**
     * หนึ่งแท่งกินเวลากี่นาที — ใช้แปลง "รอบเป็นชั่วโมง" ที่ผู้ใช้ตั้ง เป็นจำนวนแท่ง.
     *
     * เก็บเป็นนาทีต่อแท่ง (ไม่ใช่แท่งต่อชั่วโมง) เพราะเป็นจำนวนเต็มทุกค่า —
     * แบบเดิม 1d = 0.0417 แท่ง/ชม. ซึ่งปัดเศษแล้วคลาดเคลื่อนสะสม
     */
    private const MINUTES_PER_BAR = ['1m' => 1, '5m' => 5, '15m' => 15, '1h' => 60, '4h' => 240, '1d' => 1440];

    public function __construct(
        private readonly MarketDataService $market,
        private readonly MarketRiskService $risk,
        private readonly StrategyRegistry $registry,
        private readonly PaperBroker $broker,
        private readonly AiBotService $bots,
        private readonly AiViewGate $aiGate,
        private readonly AutoPairResolver $autoPair,
    ) {}

    /**
     * รันบอทหนึ่งตัวหนึ่งรอบ.
     *
     * @return array{action: string, reason: string, risk?: string}
     */
    public function tick(AiBotConfig $bot): array
    {
        // 1) การเช่ายังไม่หมดอายุ
        $subscription = $this->bots->activeSubscription($bot->wallet_address);

        if (! $subscription) {
            $bot->update(['status' => 'paused', 'last_reason' => 'การเช่าหมดอายุ — บอทถูกพักอัตโนมัติ']);

            return $this->record($bot, 'stopped', 'การเช่าหมดอายุ — บอทถูกพักอัตโนมัติ');
        }

        $strategy = $this->registry->find($bot->strategy);

        if (! $strategy) {
            return $this->record($bot, 'error', "ไม่รู้จักกลยุทธ์ {$bot->strategy}");
        }

        /*
         * 1.05) กลยุทธ์ที่ถูกถอดออกจากการขายแล้ว (ธง retired ใน config/aibot.php)
         *
         * API กันไม่ให้สร้าง/เริ่มตัวใหม่แล้ว แต่บอทที่ "กำลังเดินอยู่" ตอนที่ถอด
         * ต้องถูกจับพักที่นี่ — ไม่งั้นมันจะเทรดต่อด้วยกลยุทธ์ที่เราเพิ่งพิสูจน์ว่า
         * ขาดทุนโดยโครงสร้าง จนกว่าเจ้าของจะบังเอิญเปิดหน้าเว็บมาเห็น
         */
        if ($this->bots->isRetired($bot->strategy)) {
            $why = (string) ($this->bots->strategy($bot->strategy)['retired_reason'] ?? '');
            $reason = 'กลยุทธ์นี้ถูกถอดออกจากการขายแล้ว — บอทถูกพักไว้'.($why !== '' ? ': '.$why : '');
            $bot->update(['status' => 'paused', 'last_reason' => $reason]);

            return $this->record($bot, 'stopped', $reason);
        }

        /*
         * 1.1) กลยุทธ์ต้องอยู่ในสิทธิ์ของแพลน "ปัจจุบัน" ไม่ใช่แพลนตอนที่สร้างบอท
         *
         * เดิมเช็คแค่ว่า "มีการเช่าอยู่ไหม" ซึ่งผ่านเสมอ เพราะพอแพลนเสียเงินหมดอายุ
         * ระบบลงแพลนฟรีให้ทันที → บอทที่ปลดล็อกด้วย VIP เดินต่อได้ฟรีตลอดกาล
         * (ยืนยันด้วยการรันจริง: แพลนกลายเป็นฟรี แต่บอท ai_signal ยังเดินผ่าน)
         *
         * ต้องเช็คที่นี่ด้วย ไม่ใช่แค่ที่ปุ่มหรือที่ controller — เส้นทางคลาวด์
         * ไม่ได้ผ่าน controller เลย
         *
         * วางไว้ "หลัง" การหากลยุทธ์ เพราะกลยุทธ์ที่ระบบไม่รู้จักเป็นคนละปัญหา
         * (ข้อมูลเสีย ไม่ใช่สิทธิ์ไม่พอ) และต้องรายงานคนละแบบ
         */
        $unlocked = $subscription->plan?->unlockedStrategies() ?? [];

        if (! in_array($bot->strategy, $unlocked, true)) {
            $reason = 'แพลนปัจจุบันไม่รวมกลยุทธ์นี้แล้ว — บอทถูกพักไว้ ต่ออายุแพลนเพื่อใช้ต่อ';
            $bot->update(['status' => 'paused', 'last_reason' => $reason]);

            return $this->record($bot, 'stopped', $reason);
        }

        /*
         * 1.2) ให้ AI เลือกเหรียญ (เฉพาะบอทที่เปิดโหมดนี้)
         *
         * ต้องมาก่อนการดึงแท่งเทียน เพราะการย้ายเหรียญเปลี่ยนว่าจะดึงข้อมูลของคู่ไหน
         * ถ้าย้ายทีหลังจะได้แท่งเทียนของคู่เก่ามาตัดสินใจให้คู่ใหม่
         *
         * ถามสถานะการถือครองก่อนตรงนี้เพราะตัวเลือกเหรียญต้องรู้ว่าถือของอยู่ไหม
         * (ห้ามย้ายตอนถือของ ไม่งั้นไม้เดิมจะค้างอยู่บนคู่ที่ไม่มีใครดูแลต่อ)
         */
        $holding = AiBotPosition::where('ai_bot_config_id', $bot->id)
            ->where('mode', $bot->mode)
            ->where('quantity', '>', 0)
            ->exists();

        $autoPair = $this->autoPair->resolve($bot, $subscription->plan, $holding);

        if ($autoPair['switched']) {
            $bot->refresh();
        }

        // 2) ข้อมูลตลาดจริง — ดึงให้พอกับที่กลยุทธ์นั้นต้องใช้จริงๆ
        $candles = $this->candles($bot, $strategy->minCandles($bot->params ?? []));

        if (count($candles) < 30) {
            return $this->record($bot, 'hold', 'ยังดึงแท่งเทียนของคู่นี้ไม่ได้');
        }

        $price = (float) $candles[count($candles) - 1]['close'];
        $position = AiBotPosition::where('ai_bot_config_id', $bot->id)->where('mode', $bot->mode)->first();

        /*
         * 2.5) แท่งปิดเดิม + ไม่มีของ = ไม่มีอะไรใหม่ให้คิด
         *
         * ตัวจับเวลาปลุกบอท VIP ทุกนาที แต่บอทส่วนใหญ่ตัดสินใจจากแท่ง 1 ชั่วโมง
         * ที่ปิดแล้ว (ตัดแท่งสดทิ้งใน candles()) — ข้อมูลที่กลยุทธ์เห็นจึงเหมือนเดิม
         * เป๊ะ 60 รอบต่อแท่ง วัดบน prod: 81,105 รอบใน 13 วัน โดย 99% เป็นคำตอบเดิม
         *
         * ข้ามได้เฉพาะตอน "ไม่มีของ": ถือของอยู่ต้องประเมินทุกรอบ เพราะด่านข่าว
         * และมุมมอง AI เปลี่ยนได้ระหว่างแท่ง แล้วอาจต้องสั่งปิดไม้ทันที
         * (ราคาที่ใช้ยังเป็นราคาปิดของแท่งเดิม — แต่เหตุให้ออกไม่ได้มาจากราคาอย่างเดียว)
         *
         * ไม่มีของแล้วข้าม = อย่างแย่เข้าไม้ช้าไปหนึ่งแท่ง ซึ่งเท่ากับความละเอียด
         * ของกลยุทธ์นั้นอยู่แล้ว
         */
        $lastCandleTime = (int) $candles[count($candles) - 1]['time'];
        $seenCandleTime = (int) (($bot->stats ?? [])['last_candle_time'] ?? 0);

        if (! $position && $seenCandleTime === $lastCandleTime) {
            // ยังต้องขยับ last_run_at ให้ isDue() ของตัวจับเวลานับรอบถูก
            $bot->update(['last_run_at' => now()]);

            return [
                'action' => 'hold',
                'reason' => 'ยังไม่มีแท่งปิดใหม่ตั้งแต่รอบก่อน',
                'risk' => (string) (($bot->stats ?? [])['last_risk'] ?? 'calm'),
                'skipped' => true,
            ];
        }

        // ให้ record() บันทึกเวลาแท่งนี้ลง stats พร้อมผลรอบนี้ (ไม่ save แยกเพื่อไม่เขียนสองรอบ)
        $bot->stats = array_merge($bot->stats ?? [], ['last_candle_time' => $lastCandleTime]);
        /*
         * `entry` = ต้นทุนจริงต่อหน่วย (รวมค่าธรรมเนียม + slippage ขาซื้อแล้ว)
         *           ถูกต้องสำหรับคิดกำไร/ขาดทุน เพราะต้องชนะต้นทุนจริงถึงจะได้กำไร
         *
         * `entry_market` = ราคาตลาดตอนเข้าไม้ (ถอดต้นทุนออกแล้ว)
         *           ต้องใช้ตัวนี้เวลาวาง stop ที่อ้างอิงระยะทางของราคา เช่น ATR
         *
         * ⚠️ ใช้ต้นทุนจริงไปวาง stop = วัดจากจุดที่ลอยเหนือตลาดอยู่ 18 bps
         *    ไม้ที่เพิ่งซื้อจะ "หลุด stop" ทันทีทั้งที่ราคาไม่ขยับเลย ถ้าตัวคูณ ATR
         *    ต่ำกว่า ~1 (ฟอร์มยอมให้ตั้งถึง 0.5 ซึ่งดูสมเหตุสมผลว่า "เสี่ยงน้อย")
         *    วัดจริง: ราคาไม่ขยับเลย → ขายทันที ขาดทุน 35.9 bps พร้อมป้ายที่อ่าน
         *    เหมือนบอททำงานถูกต้อง
         */
        $entryCostFactor = 1 + ($this->bots->roundTripCostBps() / 2) / 10000;

        $positionArray = $position ? [
            'qty' => (float) $position->quantity,
            'entry' => (float) $position->entry_price,
            'entry_market' => (float) $position->entry_price / $entryCostFactor,
        ] : null;

        /*
         * 3) ด่านความเสี่ยงจากตลาด + ข่าว
         *
         * ส่ง timeframe ไปด้วยเพราะด่านนี้พูดเป็น "ชั่วโมง" แต่คิดจากจำนวนแท่ง —
         * ไม่บอกว่าหนึ่งแท่งกินเวลาเท่าไหร่ บอท 1d จะอ่าน "24 ชั่วโมง" เป็น 24 วัน
         *
         * news_filter เป็นสวิตช์ที่ผู้ใช้ตั้งได้จริงในฟอร์ม (ไม่ใช่ป้ายลอยๆ) —
         * ปิดแล้วต้องข้ามด่านข่าวจริง ไม่ใช่เก็บค่าไว้เฉยๆ
         */
        $risk = $this->risk->assess(
            $bot->pair,
            $candles,
            $bot->timeframe,
            ($bot->params['news_filter'] ?? true) !== false,
        );

        if ($risk['force_exit'] && $position) {
            $reason = 'ตลาดเข้าภาวะตื่นตระหนก — เทออกทั้งหมด: '.implode(' · ', array_slice($risk['reasons'], 0, 2));
            $this->execute($bot, 'sell', $price, 0, $reason, $risk);

            // ราคาต้องติดไปด้วย — ไม่งั้น analyst-report/harvest ให้คะแนนไม้ที่ด่านนี้สั่งไม่ได้
            return $this->record($bot, 'sell', $reason, $risk['level'], [
                'price' => $price,
                'has_position' => true,
                'meta' => ['risk' => $risk['reasons'] ?? []],
            ]);
        }

        if ($risk['size_multiplier'] <= 0 && ! $position) {
            $reason = 'หยุดเข้าไม้ใหม่ชั่วคราว: '.(implode(' · ', array_slice($risk['reasons'], 0, 2)) ?: 'ความเสี่ยงสูง');

            return $this->record($bot, 'hold', $reason, $risk['level'], [
                'price' => $price,
                'has_position' => false,
                'meta' => ['risk' => $risk['reasons'] ?? []],
            ]);
        }

        /*
         * 3.5) มุมมองตลาดของ AI (รอบ 4 ชม. / 15 นาที ตามแพลน)
         *
         * วางไว้ "หลัง" ด่านความเสี่ยงแบบกฎ ไม่ใช่แทนที่ — สองชั้นนี้มองคนละมุม
         * และต้องผ่านทั้งคู่ ด่านกฎจับเหตุการณ์เฉียบพลันที่วัดจากราคาและคำสำคัญ
         * ส่วน AI อ่านบริบทกว้างที่กฎจับไม่ได้ (นโยบายการเงิน ท่าทีหน่วยงานกำกับ
         * ข่าวที่ต้องตีความ) มุมไหนบอกให้เบา ต้องเบาตามมุมนั้น
         *
         * ⚠️ ไม่มีมุมมอง = เดินต่อด้วยกฎล้วน ไม่ใช่หยุดเทรด
         *    OpenAI ล่ม / โควตาหมด / cron ตาย ต้องไม่ทำให้บอททุกตัวหยุดพร้อมกัน
         */
        /*
         * `ai_gate` = false คือบอทกลุ่มควบคุม — ได้กฎล้วน ไม่ต่างจากตอนที่ยังไม่มี AI
         * ต้องคืนโครงสร้างเดียวกับ evaluate() เป๊ะ (idle) เพราะโค้ดด้านล่างอ่านทุกคีย์
         */
        $ai = (($bot->params ?? [])['ai_gate'] ?? true) === false
            ? AiViewGate::idle()
            : $this->aiGate->evaluate($bot, $subscription->plan, (bool) $position);

        if ($ai['force_exit'] && $position) {
            $reason = 'AI สั่งปิดไม้: '.(implode(' · ', array_slice($ai['reasons'], 0, 2)) ?: 'ความเสี่ยงสูงขึ้น');
            $this->execute($bot, 'sell', $price, 0, $reason, $risk);

            return $this->record($bot, 'sell', $reason, $risk['level'], [
                'price' => $price,
                'has_position' => true,
                'meta' => ['ai' => $ai],
            ]);
        }

        if ($ai['block_entry'] && ! $position) {
            $reason = implode(' · ', array_slice($ai['reasons'], 0, 2)) ?: 'AI ไม่แนะนำเหรียญนี้รอบนี้';

            return $this->record($bot, 'hold', $reason, $risk['level'], [
                'price' => $price,
                'has_position' => false,
                'meta' => ['ai' => $ai],
            ]);
        }

        /*
         * ตัวคูณสองชั้นคูณกัน ไม่ใช่เลือกอันใดอันหนึ่ง — ตลาดผันผวน (กฎลดเหลือ 0.5)
         * บวกกับ AI มองขาลง (ลดเหลือ 0.6) ต้องได้ 0.3 ไม่ใช่ 0.5 หรือ 0.6
         */
        $sizeMultiplier = (float) $risk['size_multiplier'] * $ai['size_multiplier'];

        // 4) กรอบความเสี่ยงของผู้ใช้เอง (มาก่อนกลยุทธ์ — stop ต้องชนะสัญญาณเสมอ)
        if ($position) {
            $guard = $this->checkUserRiskLimits($bot, $position, $price);

            if ($guard !== null) {
                $this->execute($bot, 'sell', $price, 0, $guard, $risk);

                return $this->record($bot, 'sell', $guard, $risk['level'], [
                    'price' => $price,
                    'has_position' => true,
                ]);
            }
        }

        /*
         * 4.1) ทะลุเพดานขาดทุนของวันแล้ว = ห้ามเปิดไม้ใหม่ ไม่ใช่แค่ห้ามถือต่อ
         *
         * เพดานนี้คือ "วันนี้ยอมเสียได้เท่าไหร่" ถ้ากันแค่ตอนถือของ พอไม้ถูกตัด
         * ขาดทุนจนหมดโควตาแล้ว บอทก็เปิดไม้ใหม่ทันทีในรอบถัดไป เพดานจึงไม่เคย
         * ทำหน้าที่ของมันเลยสักครั้ง
         */
        if (! $position && $this->dailyLossHit($bot)) {
            $reason = 'ขาดทุนสะสมวันนี้ถึงเพดานแล้ว — พักบอทไว้จนถึงวันถัดไป';
            $bot->update(['status' => 'paused']);

            return $this->record($bot, 'stopped', $reason, $risk['level'], ['price' => $price]);
        }

        // 5) ถามกลยุทธ์
        $params = $this->paramsFor($bot, $candles, $position);

        /*
         * AI ผ่อนเกณฑ์ความมั่นใจได้ แต่ "สร้างสัญญาณเองไม่ได้"
         *
         * ผ่อนได้สูงสุด 8 จุดจาก 100 (config aibot_analyst.limits) — พอให้สัญญาณ
         * ที่เกือบผ่านได้ผ่าน แต่ไม่พอให้สัญญาณอ่อนๆ ทะลุ ถ้าให้ AI สั่งซื้อตรงๆ
         * เราจะย้อนตรวจไม่ได้เลยว่าทำไมถึงเสียเงิน เพราะคำตอบมันไม่คงที่
         *
         * บีบพื้นไว้ที่ 50 กันกรณีผู้ใช้ตั้งเกณฑ์ต่ำอยู่แล้ว แล้วถูกผ่อนจนกลายเป็น
         * "ซื้อทุกครั้งที่คะแนนไม่ติดลบ" ซึ่งไม่ใช่สิ่งที่การผ่อนเกณฑ์ควรทำได้
         */
        if ($ai['confidence_relief'] > 0 && isset($params['confidence_min'])) {
            $params['confidence_min'] = max(50.0, (float) $params['confidence_min'] - $ai['confidence_relief']);
        }

        $signal = $strategy->decide($candles, $params, $positionArray);

        $logContext = [
            'price' => $price,
            'has_position' => (bool) $position,
            'params' => $params,
            'ai' => $ai,
        ];

        if (! $signal->isActionable()) {
            return $this->record($bot, 'hold', $signal->reason, $risk['level'], $logContext + ['meta' => $signal->meta]);
        }

        if ($signal->action === Signal::BUY && $position && ! $strategy->allowsPyramiding()) {
            return $this->record($bot, 'hold', 'ถือของอยู่แล้ว กลยุทธ์นี้ไม่เติมไม้', $risk['level'], $logContext);
        }

        // 6) ลงมือ
        $budget = $this->budgetFor($bot, $signal->strength, $sizeMultiplier, $params);
        $result = $this->execute($bot, $signal->action, $price, $budget, $signal->reason, $risk, $signal->meta);

        if (! ($result['ok'] ?? false)) {
            // แยก "มีสัญญาณแต่รอผู้ใช้ยืนยัน" ออกจาก "ตัดสินใจไม่ทำอะไร" ให้ชัด
            // ไม่งั้นหน้าเว็บจะแสดงเหมือนกันหมด แล้วผู้ใช้โหมดจริงจะไม่รู้เลยว่า
            // ต้องไปกดยืนยันอะไร — ซึ่งทำให้โหมดจริงไร้ประโยชน์
            $action = ($result['pending'] ?? false) ? 'signal' : 'hold';

            return $this->record(
                $bot,
                $action,
                $result['reason'] ?? 'ลงมือไม่สำเร็จ',
                $risk['level'],
                $logContext + ['budget' => $budget, 'meta' => $signal->meta]
            );
        }

        return $this->record(
            $bot,
            $signal->action,
            $signal->reason,
            $risk['level'],
            $logContext + ['budget' => $budget, 'meta' => $signal->meta]
        );
    }

    /**
     * ปิด/เปิดไม้ — โหมด live ยังไม่ส่งธุรกรรมเอง (ไม่ถือกุญแจผู้ใช้).
     */
    private function execute(AiBotConfig $bot, string $action, float $price, float $budget, string $reason, array $risk, array $meta = []): array
    {
        if ($bot->mode !== 'demo') {
            // บันทึกเป็นสัญญาณรอผู้ใช้ยืนยัน — ไม่แกล้งทำเป็นว่าเทรดไปแล้ว
            $bot->update(['last_signal_at' => now()]);

            // ส่งเหตุผลของกลยุทธ์กลับไปพร้อมป้ายกำกับ ไม่ใช่ข้อความระบบลอยๆ
            // ผู้ใช้ต้องเห็นว่า "บอทอยากให้ยืนยันอะไร" ถึงจะตัดสินใจได้
            $side = $action === Signal::BUY ? 'ซื้อ' : 'ขาย';

            return [
                'ok' => false,
                'pending' => true,
                'reason' => "[รอยืนยัน] บอทเสนอให้{$side} — {$reason}",
            ];
        }

        $context = ['reason' => $reason, 'meta' => $meta, 'risk_level' => $risk['level']];

        return $action === Signal::BUY
            ? $this->broker->buy($bot, $price, $budget, $context)
            : $this->broker->sell($bot, $price, $context);
    }

    /**
     * ตรวจกรอบความเสี่ยงที่ผู้ใช้ตั้งเอง.
     *
     * @return string|null เหตุผลที่ต้องปิดไม้ (null = ยังไม่ต้องปิด)
     */
    private function checkUserRiskLimits(AiBotConfig $bot, AiBotPosition $position, float $price): ?string
    {
        $risk = $bot->risk ?? [];
        $entry = (float) $position->entry_price;

        if ($entry <= 0) {
            return null;
        }

        $changePct = (($price - $entry) / $entry) * 100;

        $stopLoss = (float) ($risk['stop_loss_pct'] ?? 0);
        if ($stopLoss > 0 && $changePct <= -$stopLoss) {
            return sprintf('ถึงจุดตัดขาดทุนที่ตั้งไว้ (%.2f%%)', $changePct);
        }

        $takeProfit = (float) ($risk['take_profit_pct'] ?? 0);
        if ($takeProfit > 0 && $changePct >= $takeProfit) {
            return sprintf('ถึงเป้าทำกำไรที่ตั้งไว้ (+%.2f%%)', $changePct);
        }

        // ขาดทุนสะสมของวันนี้เกินเพดาน (รวมกำไร/ขาดทุนลอยของไม้ที่ถืออยู่) → ปิดไม้
        if ($this->dailyLossHit($bot, $position->unrealizedPnl($price))) {
            $bot->update(['status' => 'paused']);

            return sprintf(
                'ขาดทุนสะสมวันนี้ถึงเพดาน $%.2f — ปิดไม้และพักบอท',
                (float) ($risk['max_daily_loss_usd'] ?? 0)
            );
        }

        return null;
    }

    /**
     * ขาดทุนสะสมของวันนี้ถึงเพดานที่ผู้ใช้ตั้งไว้หรือยัง.
     *
     * ⚠️ ต้องเรียกได้ทั้งตอน "ถือของอยู่" และตอน "ไม่มีของ"
     *
     * เดิมด่านนี้อยู่ใน checkUserRiskLimits() ซึ่งถูกเรียกใต้ `if ($position)` เท่านั้น
     * ตอนไม่มีของจึงถูกข้ามทั้งก้อน — บอทที่เพิ่งโดนตัดขาดทุนจนทะลุเพดานไปแล้ว
     * ยังเปิดไม้ใหม่เต็มขนาดในรอบถัดไปได้ทันที วนขาดทุนได้ไม่จำกัดรอบในวันเดียว
     * (พิสูจน์ด้วยการรัน: ตั้งเพดาน $50 → ขาดทุนจริง -102 แต่บอทยัง running)
     *
     * ซ้ำร้าย สาขา stop loss ด้านบน return ออกก่อนถึงด่านนี้เสมอ ไม้ที่ถูกตัด
     * ขาดทุนจึง realize แล้วไม่เคยพักบอทเลยสักครั้ง
     *
     * @param  float  $openPnl  กำไร/ขาดทุนลอยของไม้ที่ถืออยู่ (0 เมื่อไม่มีของ)
     */
    private function dailyLossHit(AiBotConfig $bot, float $openPnl = 0.0): bool
    {
        $maxDailyLoss = (float) (($bot->risk ?? [])['max_daily_loss_usd'] ?? 0);

        if ($maxDailyLoss <= 0) {
            return false;
        }

        $todayPnl = (float) $bot->trades()
            ->whereDate('created_at', today())
            ->where('mode', $bot->mode)
            ->sum('realized_pnl');

        return ($todayPnl + $openPnl) <= -$maxDailyLoss;
    }

    /**
     * งบของไม้นี้ = ขนาดไม้ที่ตั้งไว้ × ความแรงสัญญาณ × ตัวคูณความเสี่ยง.
     *
     * สูตรอยู่ใน PositionSizer (pure) เพื่อให้ backtester คิดขนาดไม้ "เป๊ะ" เท่าของจริง
     * — เหตุผลของแต่ละบรรทัดอยู่ที่นั่น
     *
     * @param  array  $params  พารามิเตอร์ที่ผ่าน sanitizeParams แล้ว
     *                         ⚠️ ห้ามอ่าน $bot->params ดิบ — บอทที่สร้างไว้ก่อนกติกา
     *                         เปลี่ยน (หรือสร้างด้วย params ว่าง) จะไม่มีค่าปริยายอยู่เลย
     *                         แล้วเงื่อนไข "ผู้ใช้ระบุจำนวนเงินไหม" จะเป็นเท็จเสมอ
     *                         → ตกไปใช้เพดานทุนแทนงบที่ตั้งไว้ (เจอจริงตอนรันกับตลาดจริง:
     *                         ตั้งงบ 25 แต่ลง 30 เพราะไปคิดจากเพดาน 100)
     */
    private function budgetFor(AiBotConfig $bot, float $strength, float $riskMultiplier, array $params = []): float
    {
        return PositionSizer::budget(
            $bot->strategy,
            $bot->risk ?? [],
            $params,
            $strength,
            $riskMultiplier,
            $bot->params ?? [],
        );
    }

    /** พารามิเตอร์ที่กลยุทธ์ต้องใช้ + ตัวช่วยที่ engine เป็นคนรู้ (เช่นรอบของ DCA) */
    private function paramsFor(AiBotConfig $bot, array $candles, ?AiBotPosition $position): array
    {
        /*
         * ล้างค่าพารามิเตอร์ตามกติกาปัจจุบันทุกครั้งที่รัน ไม่ใช่เชื่อค่าที่บันทึกไว้
         *
         * sanitizeParams() ถูกเรียกเฉพาะตอนสร้าง/แก้บอท บอทที่บันทึกไว้ก่อนกติกา
         * เปลี่ยนจึงยังใช้ค่าเดิมตลอดไป — เช่นเป้ากำไรสแกลป์ที่ต่ำกว่าจุดคุ้มทุน
         * ปิดไม้พร้อมป้าย "ถึงเป้ากำไร" แต่ยอดติดลบ ผู้ใช้ไม่มีทางรู้ว่าต้องไปแก้เอง
         */
        $params = $this->bots->sanitizeParams($bot->strategy, $bot->params ?? []);
        $minutesPerBar = self::MINUTES_PER_BAR[$bot->timeframe] ?? 60;

        if ($bot->strategy === 'dca') {
            $params['_interval_bars'] = max(1, (int) round(((float) ($params['interval_hours'] ?? 24)) * 60 / $minutesPerBar));

            $lastBuy = $bot->trades()->where('mode', $bot->mode)->where('side', 'buy')->latest('created_at')->first();

            /*
             * นับจากเวลาจริงของไม้ล่าสุด ไม่ใช่นับแท่งที่อยู่ในหน้าต่างที่ดึงมา
             *
             * เดิมนับแท่งย้อนหลังในชุดที่ดึงมา ซึ่งมีเพดานตามขนาดหน้าต่าง —
             * รอบ 720 ชั่วโมงบน 1h ต้องการ 720 แท่ง แต่หน้าต่างให้ได้มากสุด 500
             * ตัวนับจึงไม่มีวันถึงเกณฑ์ บอทซื้อครั้งแรกครั้งเดียวแล้วเงียบตลอดกาล
             */
            $params['_bars_since_entry'] = $lastBuy
                ? (int) floor($lastBuy->created_at->diffInMinutes(now()) / $minutesPerBar)
                : PHP_INT_MAX;
        }

        if ($bot->strategy === 'scalping') {
            /*
             * "พักระหว่างไม้" ที่ฟอร์มให้ตั้ง — engine เป็นคนรู้เวลา ไม่ใช่กลยุทธ์
             * (กลยุทธ์เห็นแค่แท่งเทียน ไม่รู้ว่าไม้ล่าสุดปิดไปกี่วินาทีแล้ว)
             */
            $lastTrade = $bot->trades()->where('mode', $bot->mode)->latest('created_at')->first();

            $params['_seconds_since_trade'] = $lastTrade
                ? (int) $lastTrade->created_at->diffInSeconds(now())
                : PHP_INT_MAX;
        }

        return $params;
    }

    /**
     * @param  int  $needed  จำนวนแท่งที่กลยุทธ์ต้องใช้อย่างน้อย
     * @return list<array> แท่งเทียนจริงในรูปแบบตัวเลข
     */
    private function candles(AiBotConfig $bot, int $needed = 0): array
    {
        /*
         * เดิมฮาร์ดโค้ด 150 แท่งเสมอ ทั้งที่ฟอร์มให้ตั้งค่าที่ต้องใช้มากกว่านั้น —
         * momentum ตั้ง slow_ema ได้ถึง 400 (ต้องการ 422 แท่ง) และ breakout ตั้ง
         * channel_period ได้ถึง 200 (ต้องการ 216) ผู้ใช้ที่ตั้งเกิน ~129/135
         * จะได้ "ข้อมูลแท่งเทียนยังไม่พอ" ตลอดกาลโดยไม่มีอะไรบอกว่าเพราะอะไร
         *
         * 500 คือเพดานของแหล่งข้อมูล (getKlines บีบไว้อยู่แล้ว) — ขอเกินไปก็ไม่ได้เพิ่ม
         */
        // +1 ชดเชยแท่งที่กำลังวิ่งอยู่ซึ่งจะถูกตัดทิ้งด้านล่าง
        $limit = max(150, min(500, $needed + 31));

        try {
            $raw = $this->market->getKlines($bot->pair, $bot->timeframe, $limit);
        } catch (\Throwable $e) {
            Log::warning('AI bot klines failed', ['bot' => $bot->id, 'error' => $e->getMessage()]);

            return [];
        }

        /*
         * ⚠️ ตัดแท่งสุดท้ายทิ้ง เพราะมันยังปิดไม่จบ
         *
         * ตลาดคืนแท่งที่กำลังวิ่งอยู่มาด้วยเสมอ ราคาปิดกับวอลุ่มของมันจึงเปลี่ยน
         * ทุกวินาที ตัวจับเวลาถามทุก 1-5 นาที แต่ timeframe เป็น 15m/1h/4h
         * แท่งเดียวจึงถูกถามซ้ำ 3-240 ครั้งด้วย "ราคาปิด" ที่ไม่เหมือนกันสักครั้ง
         *
         * สองอย่างที่พังจากตรงนี้ (พิสูจน์ด้วยการรันจริง):
         * 1. กลยุทธ์ที่ดูการ "ตัดกัน" ของเส้น (momentum) เข้าไม้จากการตัดชั่วคราว
         *    ที่ยังไม่ยืนยัน พอแท่งปิดกลับหัว เงื่อนไขขาย (แท่งก่อนหน้าต้องอยู่
         *    เหนือเส้นช้า) กลายเป็นเท็จถาวร → ถือค้างจนกว่า stop loss จะทำงาน
         *    ทั้งที่โฆษณาว่า "ออกเมื่อโมเมนตัมหมด" (วัดจริง: ขาย 0 ครั้งใน 100 รอบ)
         * 2. คำตอบไม่คงที่ตามจังหวะ cron — แท่งชุดเดิม วอลุ่มสะสมต่างกัน
         *    ให้ผลคนละอย่าง (vol 95 = hold · vol 100 = buy)
         *
         * ราคาปัจจุบันที่ใช้คำนวณกำไร/ขาดทุนก็จะเป็นราคาปิดของแท่งที่ปิดแล้ว
         * ซึ่งช้ากว่าตลาดจริงไม่เกินหนึ่งแท่ง — แลกกับสัญญาณที่เชื่อถือได้ คุ้มกว่ามาก
         */
        $closed = count($raw) > 1 ? array_slice($raw, 0, -1) : $raw;

        return array_map(fn ($c) => [
            'time' => (int) $c['time'],
            'open' => (float) $c['open'],
            'high' => (float) $c['high'],
            'low' => (float) $c['low'],
            'close' => (float) $c['close'],
            'volume' => (float) $c['volume'],
        ], $closed);
    }

    /**
     * บันทึกผลรอบนี้ — ทั้งที่บอท (ให้ผู้ใช้เห็น) และลงตารางประวัติ (ให้เอาไปวิเคราะห์).
     *
     * ⚠️ `last_reason` เก็บได้แค่รอบล่าสุดรอบเดียว เขียนทับทุกครั้งที่บอทคิด
     *    ข้อมูลที่ใช้ปรับปรุงกลยุทธ์ได้จริงคือ "ทำไมถึงไม่ทำอะไร" ซึ่งเกิดบ่อยกว่า
     *    การเข้าไม้หลายสิบเท่า และเดิมหายไปหมดทุกรอบ
     *
     * เก็บลง ai_bot_decisions แยกต่างหาก ไม่ยัดลงตาราง trades เพราะ trades
     * เป็นบัญชีเงิน — ปนรายการที่ไม่มีเงินเปลี่ยนมือเข้าไปแล้วยอดรวมจะผิดทันที
     */
    private function record(
        AiBotConfig $bot,
        string $action,
        string $reason,
        string $riskLevel = 'calm',
        array $context = [],
    ): array {
        $bot->update([
            'last_run_at' => now(),
            'last_reason' => $reason,
            'stats' => array_merge($bot->stats ?? [], ['last_action' => $action, 'last_risk' => $riskLevel]),
        ]);

        try {
            $price = isset($context['price']) ? (float) $context['price'] : null;
            $hasPosition = (bool) ($context['has_position'] ?? false);

            /*
             * สภาพเดิมเป๊ะจากรอบก่อน = นับซ้ำในแถวเดิม ไม่แทรกแถวใหม่
             *
             * ดูเหตุผลที่ AiBotDecision::isSameSituation() — 81k แถวเหมือนกันใน 13 วัน
             * จำกัดไว้ที่ 15 นาทีย้อนหลัง: บอทที่ถูกพักแล้วกลับมาเจอสภาพเดิมในอีกสัปดาห์
             * ต้องได้แถวใหม่ ไม่ใช่ไปต่อตัวนับของแถวเก่าจนอ่านเหมือนว่าคิดต่อเนื่องมาตลอด
             */
            $latest = AiBotDecision::where('ai_bot_config_id', $bot->id)->latest('id')->first();

            if ($latest
                && ($latest->last_seen_at ?? $latest->created_at)?->gt(now()->subMinutes(15))
                && $latest->isSameSituation($action, $reason, $riskLevel, $price, $hasPosition)) {
                $latest->update([
                    'repeat_count' => (int) $latest->repeat_count + 1,
                    'last_seen_at' => now(),
                ]);
            } else {
                AiBotDecision::create([
                    'ai_bot_config_id' => $bot->id,
                    'wallet_address' => $bot->wallet_address,
                    'strategy' => $bot->strategy,
                    'pair' => $bot->pair,
                    'timeframe' => $bot->timeframe,
                    'mode' => $bot->mode,
                    'action' => $action,
                    'reason' => $reason,
                    'risk_level' => $riskLevel,
                    'price' => $price,
                    'budget' => $context['budget'] ?? null,
                    'has_position' => $hasPosition,
                    'signal_meta' => $context['meta'] ?? null,
                    'params' => $context['params'] ?? null,
                    'repeat_count' => 1,
                    'last_seen_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            /*
             * บันทึกไม่ลงต้องไม่ทำให้บอทหยุดเทรด
             *
             * ตารางนี้มีไว้เก็บข้อมูลไปวิเคราะห์ ไม่ใช่ส่วนหนึ่งของการตัดสินใจ
             * ถ้ามันล่มแล้วลาก tick ล้มไปด้วย เท่ากับเอาของสำคัญน้อยกว่ามาคุมของสำคัญกว่า
             */
            Log::warning('บันทึกการตัดสินใจของบอทไม่สำเร็จ', ['bot' => $bot->id, 'error' => $e->getMessage()]);
        }

        return ['action' => $action, 'reason' => $reason, 'risk' => $riskLevel];
    }
}
