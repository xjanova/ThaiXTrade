<?php

namespace App\Services\Trading;

use App\Models\AiBotSubscription;
use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Services\FeeCalculationService;

/**
 * TPIX TRADE — ค่าบริการวางไม้ของไม้นี้เท่าไร และจ่ายทางไหนได้บ้าง.
 *
 * เจ้าของกำหนดสองเส้นทาง:
 *   มี TPIX ในคลัง → จ่ายเป็น TPIX จำนวนคงที่ตามขั้นบันได (ถูกกว่า)
 *   ไม่มี TPIX     → จ่าย % จากเหรียญที่เทรดแบบเดิม (แพงกว่า)
 *
 * ⚠️ ค่าบริการเป็น "จำนวน TPIX คงที่" ไม่ใช่เปอร์เซ็นต์ของมูลค่าไม้
 *    ถ้าคิดเป็น % ไม้ใหญ่จะจ่ายแพงจนไม่มีใครใช้ และคนเทรดเหรียญราคาสูง
 *    เสียเปรียบ — เจ้าของสั่งชัดว่าให้เป็นขั้นบันไดจำนวนคงที่
 *
 * ⚠️ ทั้งสองทางเก็บ "ก่อนวางไม้" เพื่อกันเบี้ยว
 *    ต่างกันที่ตอนคืน: จ่ายด้วยคลัง TPIX คืนเต็ม เพราะเป็นบัญชีในระบบเรา
 *    ส่วนจ่ายเป็นเหรียญบนเชน ต้องหักค่าแก๊สของธุรกรรมคืนออกก่อน
 *
 * Developed by Xman Studio.
 */
class TradingFeeQuoteService
{
    public function __construct(
        private readonly TradingCreditService $credits,
        private readonly FeeCalculationService $fees,
    ) {}

    /** ระบบค่าบริการ TPIX เปิดใช้อยู่ไหม — ปิดแล้วทุกอย่างกลับไปเป็นแบบเดิม */
    public function enabled(): bool
    {
        $value = SiteSetting::get('trading', 'tpix_fee_enabled', false);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * ค่าบริการของไม้ขนาดนี้ พร้อมสองทางเลือกให้ผู้ใช้เปรียบเทียบ.
     *
     * @return array{
     *   enabled: bool,
     *   order_value_usd: float,
     *   balance: float,
     *   tpix: array,
     *   onchain: array,
     *   recommended: string,
     *   can_place: bool,
     *   reason: ?string
     * }
     */
    public function quote(?string $wallet, float $orderValueUsd, int $chainId, ?int $tradingPairId = null): array
    {
        $orderValueUsd = max(0.0, $orderValueUsd);

        /*
         * เช่าบอทอยู่ = ไม่เก็บค่าวางไม้เลย (เจ้าของสั่ง 28 ส.ค. 2026)
         *
         * "เหมาไปเลย ไม่ต้องเอามาคิดอีก เพื่อจะได้คิดกำไรได้เต็มที่ ง่ายกว่าเดิม
         *  ไม่ซับซ้อน ตรงไปตรงมา" — ค่าวางไม้ถูกรวมอยู่ในค่าเช่าแล้ว
         *
         * ครอบคลุมทุกไม้ของกระเป๋านั้น ไม่ใช่เฉพาะไม้ที่บอทสั่ง — เพราะคำว่า "เหมา"
         * แปลว่าจ่ายครั้งเดียวจบ ถ้ายังเก็บไม้ที่ผู้ใช้กดเองอยู่ เขาจะต้องมานั่งแยก
         * ว่ากำไรก้อนไหนโดนหักค่าอะไรไปบ้าง ซึ่งตรงข้ามกับที่สั่งมา
         *
         * ⚠️ นับเฉพาะแพลนที่ "เซิร์ฟเวอร์รันบอทให้" (`runsInCloud`) ไม่ใช่ดูที่ราคา
         *
         *    ตอนแรกเช็คว่าแพลนเสียเงินไหม แต่พลาดกรณีจริงบน prod: แพลน `admin`
         *    ของเจ้าของเป็น tier vip ทำงานบนคลาวด์ แต่ราคา 0 → ถูกมองเป็นแพลนฟรี
         *    แล้วยังโดนเก็บค่าวางไม้ ทั้งที่เป็นบอทคลาวด์เต็มรูปแบบ
         *
         *    `execution` เป็นเส้นแบ่งที่ระบบใช้อยู่แล้ว: ฟรี = เบราว์เซอร์ (ปิดจอแล้วหยุด)
         *    ส่วนการเช่าจริง = คลาวด์ ซึ่งตรงกับคำว่า "เช่าบอท" พอดี และเป็นตัวเดียวกับ
         *    ที่ aibot:tick ใช้กรองว่าบอทตัวไหนได้รันบนเซิร์ฟเวอร์
         */
        if ($wallet && $this->hasCloudBotRental($wallet)) {
            return $this->waived($orderValueUsd);
        }

        // อัตราเดิมที่หักจากเหรียญที่เทรด — คำนวณด้วยตัวเดียวกับที่หักจริงเสมอ
        $onchainFee = $this->fees->calculateSwapFee($orderValueUsd, $chainId, $tradingPairId);

        $onchain = [
            'available' => true,
            'fee_rate' => $onchainFee['fee_rate'],
            'fee_usd' => round($onchainFee['fee_amount'], 8),
            // เก็บก่อนวางไม้เหมือนกัน แต่คืนแล้วโดนหักค่าแก๊ส
            'refund_full' => false,
            'refund_note' => 'ยกเลิกไม้แล้วคืนให้ โดยหักค่าแก๊สของธุรกรรมคืนออกก่อน',
        ];

        if (! $this->enabled()) {
            return [
                'enabled' => false,
                'order_value_usd' => $orderValueUsd,
                'balance' => 0.0,
                'tpix' => ['available' => false, 'reason' => 'ยังไม่เปิดใช้ค่าบริการแบบ TPIX'],
                'onchain' => $onchain,
                'recommended' => 'onchain',
                'can_place' => true,
                'reason' => null,
            ];
        }

        $tier = $this->tierFor($orderValueUsd);
        $balance = $wallet ? $this->credits->balanceFor($wallet) : 0.0;

        if (! $tier) {
            // ไม่มีขั้นครอบคลุมมูลค่านี้ = แอดมินตั้งขั้นบันไดไม่ครบ
            // ไม่ปิดกั้นผู้ใช้เพราะเป็นความผิดพลาดฝั่งเรา ให้ตกไปทางเดิมแทน
            return [
                'enabled' => true,
                'order_value_usd' => $orderValueUsd,
                'balance' => $balance,
                'tpix' => ['available' => false, 'reason' => 'ยังไม่ได้ตั้งค่าบริการสำหรับไม้ขนาดนี้'],
                'onchain' => $onchain,
                'recommended' => 'onchain',
                'can_place' => true,
                'reason' => null,
            ];
        }

        $feeTpix = (float) $tier->fee_tpix;
        $hasEnough = $balance >= $feeTpix;

        $tpix = [
            'available' => true,
            'fee_tpix' => $feeTpix,
            'tier_id' => $tier->id,
            'tier_label' => $tier->label ?: $tier->rangeLabel(),
            'tier_range' => $tier->rangeLabel(),
            'has_enough' => $hasEnough,
            'shortfall' => $hasEnough ? 0.0 : round($feeTpix - $balance, 8),
            // คลังเป็นบัญชีในระบบเรา คืนได้ทันทีเต็มจำนวน ไม่มีค่าแก๊ส
            'refund_full' => true,
            'refund_note' => 'ยกเลิกไม้แล้วคืนเข้าคลังเต็มจำนวน ไม่มีค่าแก๊ส',
        ];

        return [
            'enabled' => true,
            'order_value_usd' => $orderValueUsd,
            'balance' => $balance,
            'tpix' => $tpix,
            'onchain' => $onchain,
            'recommended' => $hasEnough ? 'tpix_credit' : 'onchain',
            // ไม่มี TPIX ก็ยังวางไม้ได้ แค่จ่ายแพงกว่า — ไม่ปิดประตูใส่ผู้ใช้ใหม่
            'can_place' => true,
            'reason' => null,
        ];
    }

    /**
     * กระเป๋านี้เช่าบอทคลาวด์อยู่ไหม.
     *
     * ถามฐานข้อมูลตรงๆ ไม่ผ่าน AiBotService เพราะบริการนั้นดึงของอีกหลายชั้นตามมา
     * ขณะที่ตรงนี้ต้องตอบเร็ว — ถูกเรียกทุกครั้งที่ผู้ใช้พิมพ์จำนวนในฟอร์มวางไม้
     *
     * อ่านไม่ได้ = ถือว่าไม่ได้เช่า (เก็บค่าบริการตามปกติ) ไม่ใช่ยกเว้นให้ฟรี —
     * ฐานข้อมูลสะดุดครั้งเดียวต้องไม่กลายเป็นการเปิดให้เทรดฟรีทั้งเว็บ
     */
    public function hasCloudBotRental(string $wallet): bool
    {
        try {
            $subscription = AiBotSubscription::with('plan')
                ->where('wallet_address', strtolower(trim($wallet)))
                ->live()
                ->latest('expires_at')
                ->first();
        } catch (\Throwable) {
            return false;
        }

        return (bool) $subscription?->plan?->runsInCloud();
    }

    /** ค่าบริการเป็นศูนย์ทุกทาง — รูปร่างเหมือน quote() ปกติเพื่อให้หน้าเว็บไม่ต้องแยกเคส */
    private function waived(float $orderValueUsd): array
    {
        $note = 'เช่าบอท AI อยู่ — ค่าวางไม้รวมอยู่ในค่าเช่าแล้ว';

        return [
            'enabled' => true,
            'waived' => true,
            'waived_reason' => $note,
            'order_value_usd' => $orderValueUsd,
            'balance' => 0.0,
            'tpix' => [
                'available' => true,
                'fee_tpix' => 0.0,
                'tier_id' => null,
                'tier_label' => 'ฟรี (เช่าบอทอยู่)',
                'tier_range' => '—',
                'has_enough' => true,
                'shortfall' => 0.0,
                'refund_full' => true,
                'refund_note' => 'ไม่ได้เก็บค่าบริการ จึงไม่มีอะไรต้องคืน',
            ],
            'onchain' => [
                'available' => true,
                'fee_rate' => 0.0,
                'fee_usd' => 0.0,
                'refund_full' => true,
                'refund_note' => 'ไม่ได้เก็บค่าบริการ จึงไม่มีอะไรต้องคืน',
            ],
            'recommended' => 'tpix_credit',
            'can_place' => true,
            'reason' => $note,
        ];
    }

    /**
     * ขั้นบันไดที่ครอบคลุมมูลค่าไม้นี้.
     *
     * เรียงจากขั้นต่ำสุดขึ้นไป แล้วคืนขั้นแรกที่ครอบคลุม — ถ้าแอดมินตั้งช่วงทับกัน
     * จะได้ขั้นที่ถูกที่สุดเสมอ ซึ่งเป็นฝั่งที่ควรผิดพลาดไปทาง (ไม่เก็บผู้ใช้เกิน)
     */
    public function tierFor(float $orderValueUsd): ?TradingFeeTier
    {
        return TradingFeeTier::active()
            ->orderBy('min_order_usd')
            ->orderBy('sort_order')
            ->get()
            ->first(fn (TradingFeeTier $tier) => $tier->covers($orderValueUsd));
    }

    /** ขั้นบันไดทั้งหมดที่เปิดใช้ — หน้าเว็บเอาไปโชว์ตารางราคา */
    public function tiers(): array
    {
        return TradingFeeTier::active()
            ->orderBy('min_order_usd')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TradingFeeTier $tier) => [
                'id' => $tier->id,
                'label' => $tier->label,
                'range' => $tier->rangeLabel(),
                'min_order_usd' => (float) $tier->min_order_usd,
                'max_order_usd' => $tier->max_order_usd === null ? null : (float) $tier->max_order_usd,
                'fee_tpix' => (float) $tier->fee_tpix,
            ])
            ->all();
    }
}
