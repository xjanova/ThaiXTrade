<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Models\WtpixBridgeFeeQuote;
use App\Models\WtpixBridgeTransfer;
use App\Services\PriceFeedService;
use App\Services\Stabilizer\StabilizerMarket;
use App\Support\Wei;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ค่าธรรมเนียมขาออก (ล็อก TPIX → mint wTPIX บน BSC) แบบลอยตัว.
 *
 *   ค่าธรรมเนียม (TPIX) = แก๊สกลางของ mint × ราคาแก๊ส BSC × ราคา BNB ÷ ราคา TPIX × (1 + ส่วนเพิ่ม)
 *   แล้วหนีบด้วย lock_fee (ขั้นต่ำ) และ lock_fee_max (เพดาน)
 *
 * เราไม่เสียเปรียบ:
 *   - ราคา TPIX = ค่าที่ต่ำกว่าระหว่าง TWAP สั้น/ยาว (ราคาต่ำ = ค่าธรรมเนียมเป็น TPIX สูง) — ปั่นราคาขึ้น
 *     ชั่วครู่ไม่ทำให้ค่าธรรมเนียมถูกลง ต้องยืนราคาไว้ทั้งวัน และขั้นต่ำยังกันอยู่ชั้นสุดท้าย
 *   - ราคาแก๊สคูณตัวคูณเดียวกับที่ relayer ใช้เซ็นจริง · ปัดเศษขึ้นเสมอ
 *   - ข้อมูลไหนอ่านไม่ได้/ไม่พอ = ใช้เพดาน ไม่เดา
 * ผู้ใช้ไม่เสียเปรียบ:
 *   - เพดานกันราคาเพี้ยน (เช่นมีคนทุบพูลให้ TWAP ต่ำ) — ค่าจริงเกินเพดาน = เราจ่ายส่วนต่าง + แจ้งแอดมิน
 *   - คิดจากราคาที่ประกาศ ณ เวลาบล็อกที่ล็อก และเอาค่าต่ำสุดในช่วง grace ก่อนหน้า (ดู feeForLockAt)
 *
 * ⚠️ ห้ามใช้ราคาสดของพูลหรือราคาแก๊ส ณ ตอนสแกนคิดค่าธรรมเนียมของรายการที่ล็อกไปแล้ว —
 *    ผู้ใช้ตัดสินใจจากราคาที่เห็นบนหน้าเว็บ การเปลี่ยนราคาหลังเขาล็อกคือการเก็บเงินเกินที่ประกาศ
 */
class WtpixBridgeFeeQuoter
{
    public const ALERT_CAPPED = 'wtpix_bridge_fee_capped';

    public const ALERT_FALLBACK = 'wtpix_bridge_fee_fallback';

    public function __construct(
        private WtpixBridgeConfig $config,
        private PriceFeedService $prices,
        private StabilizerMarket $market,
    ) {}

    // =========================================================================
    // ประกาศราคา
    // =========================================================================

    /** คำนวณ + บันทึกราคาใหม่ (ตัวตั้งเวลาทุก 5 นาที และทุกครั้งที่แอดมินแก้ค่าตั้ง) */
    public function refresh(): WtpixBridgeFeeQuote
    {
        $quote = WtpixBridgeFeeQuote::create($this->compute());
        Cache::forget(WtpixBridgeConfig::PUBLIC_CACHE_KEY);

        if ($quote->source === WtpixBridgeFeeQuote::SOURCE_CAPPED) {
            SystemAlert::raise(
                WtpixBridgeGuard::ALERT_NODE,
                self::ALERT_CAPPED,
                'warning',
                'ค่าแก๊ส mint wTPIX จริง ('.Wei::format((string) $quote->raw_fee_wei, 18).' TPIX) เกินเพดานค่าธรรมเนียม ('
                .Wei::format((string) $quote->fee_wei, 18).' TPIX) — สะพานจ่ายส่วนต่างเอง พิจารณาขยับเพดานที่ /admin/bridge/wtpix',
                ['raw_fee_wei' => $quote->raw_fee_wei, 'fee_wei' => $quote->fee_wei]
            );
        }

        if ($quote->source === WtpixBridgeFeeQuote::SOURCE_FALLBACK) {
            SystemAlert::raise(
                WtpixBridgeGuard::ALERT_NODE,
                self::ALERT_FALLBACK,
                'warning',
                'คิดค่าธรรมเนียมสะพาน wTPIX จากตลาดไม่ได้ ('.$quote->note.') — ผู้ใช้ถูกเก็บเพดาน '
                .Wei::format((string) $quote->fee_wei, 18).' TPIX จนกว่าข้อมูลกลับมา',
                ['note' => $quote->note]
            );
        }

        $keep = max(1, $this->config->feeSetting('keep_days', 30));
        WtpixBridgeFeeQuote::where('created_at', '<', now()->subDays($keep))->delete();

        return $quote;
    }

    /**
     * @return array{fee_wei: string, raw_fee_wei: ?string, gas_price_wei: ?string, gas_units: int, bnb_usd: ?string, tpix_usd: ?string, markup_bps: int, source: string, note: ?string}
     */
    public function compute(): array
    {
        $floor = $this->floorWei();
        $ceiling = $this->ceilingWei();
        $markup = $this->config->feeMarkupBps();
        $gasUnits = $this->gasUnits();

        $row = [
            'fee_wei' => $ceiling, 'raw_fee_wei' => null, 'gas_price_wei' => null, 'gas_units' => $gasUnits,
            'bnb_usd' => null, 'tpix_usd' => null, 'markup_bps' => $markup,
            'source' => WtpixBridgeFeeQuote::SOURCE_FALLBACK, 'note' => null,
        ];

        $gasPrice = $this->gasPriceWei();
        if ($gasPrice === null) {
            return ['note' => 'อ่านราคาแก๊ส BSC ไม่ได้'] + $row;
        }
        $row['gas_price_wei'] = $gasPrice;

        $bnb = $this->bnbUsd();
        if ($bnb === null) {
            return ['note' => 'ไม่มีราคา BNB ที่เชื่อถือได้'] + $row;
        }
        $row['bnb_usd'] = $bnb;

        [$tpix, $why] = $this->tpixUsd();
        if ($tpix === null) {
            return ['note' => $why] + $row;
        }
        $row['tpix_usd'] = $tpix;

        // fee(wei TPIX) = gasUnits × gasPrice(wei BNB) × bnbUsd × (10000 + markup) ÷ (tpixUsd × 10000)
        $numerator = bcmul(bcmul(bcmul((string) $gasUnits, $gasPrice, 0), $bnb, 18), (string) (10000 + $markup), 18);
        $denominator = bcmul($tpix, '10000', 18);
        $raw = $this->ceilDiv($numerator, $denominator);

        $row['raw_fee_wei'] = $raw;

        if (bccomp($raw, $ceiling, 0) > 0) {
            return ['fee_wei' => $ceiling, 'source' => WtpixBridgeFeeQuote::SOURCE_CAPPED, 'note' => 'ค่าแก๊สจริงเกินเพดาน'] + $row;
        }

        return ['fee_wei' => bccomp($raw, $floor, 0) < 0 ? $floor : $raw, 'source' => WtpixBridgeFeeQuote::SOURCE_MARKET] + $row;
    }

    // =========================================================================
    // อ่านราคาที่ประกาศ
    // =========================================================================

    /**
     * ราคาที่ประกาศอยู่ตอนนี้ — หน้าเว็บโชว์ค่านี้ให้ผู้ใช้ก่อนกดล็อก.
     *
     * @return array{fee_wei: string, fresh: bool, quote: ?WtpixBridgeFeeQuote}
     */
    public function current(): array
    {
        $latest = WtpixBridgeFeeQuote::latest('created_at')->latest('id')->first();
        $fresh = $latest !== null && $latest->created_at !== null
            && $latest->created_at->diffInSeconds(now(), true) <= $this->staleMinutes() * 60;

        return ['fee_wei' => $fresh ? (string) $latest->fee_wei : $this->ceilingWei(), 'fresh' => $fresh, 'quote' => $latest];
    }

    /**
     * ค่าธรรมเนียมของการล็อกที่ลงบล็อก ณ เวลา $timestamp (เวลาของบล็อกบนเชน 4289).
     *
     * = ค่าต่ำสุดในบรรดาราคาที่ "ประกาศอยู่" ช่วง [บล็อก − grace, บล็อก]:
     *   ราคาที่ออกในช่วงนั้น + ราคาที่ประกาศอยู่ตอนต้นช่วง (ถ้ายังไม่เก่าเกิน stale)
     * ผู้ใช้ที่เห็นราคาแล้วกดภายใน grace จึงไม่มีทางจ่ายเกินที่เห็น ต่อให้ราคาขึ้นระหว่างรอลงบล็อก
     * ไม่มีราคาที่ใช้ได้เลย = เพดาน (ตรงกับที่หน้าเว็บโชว์ตอนไม่มีราคาสด)
     */
    public function feeForLockAt(int $timestamp): string
    {
        // ⚠️ ต้องอยู่ในโซนเวลาของแอป — created_at เก็บเป็นเวลาท้องถิ่นของแอป (Carbon 3 สร้างจาก timestamp เป็น UTC)
        $at = Carbon::createFromTimestamp($timestamp)->setTimezone(config('app.timezone'));
        $windowStart = $at->copy()->subMinutes(max(0, $this->config->feeSetting('grace_minutes', 15)));

        $candidates = WtpixBridgeFeeQuote::whereBetween('created_at', [$windowStart, $at])->pluck('fee_wei')->map(fn ($v) => (string) $v)->all();

        $before = WtpixBridgeFeeQuote::where('created_at', '<', $windowStart)->latest('created_at')->latest('id')->first();
        if ($before !== null && $before->created_at->diffInSeconds($windowStart, true) <= $this->staleMinutes() * 60) {
            $candidates[] = (string) $before->fee_wei;
        }

        if ($candidates === []) {
            return $this->ceilingWei();
        }

        return array_reduce($candidates, fn (?string $min, string $fee) => $min === null || bccomp($fee, $min, 0) < 0 ? $fee : $min);
    }

    public function floorWei(): string
    {
        return $this->config->limitWei('lock_fee');
    }

    /** เพดาน — ตั้งต่ำกว่าขั้นต่ำมาโดยพลาด ถือว่าเท่าขั้นต่ำ (ค่าคงที่) */
    public function ceilingWei(): string
    {
        $floor = $this->floorWei();
        $ceiling = $this->config->limitWei('lock_fee_max');

        return bccomp($ceiling, $floor, 0) < 0 ? $floor : $ceiling;
    }

    /**
     * ค่าธรรมเนียมที่เก็บได้ vs BNB ที่ relayer จ่ายจริง ของ mint ที่สำเร็จแล้ว — หลังบ้านใช้ดูว่าขาดทุนไหม.
     *
     * @return array{mints: int, fees_tpix: string, gas_bnb: string, unmetered: int}
     */
    public function ledger(): array
    {
        $fees = '0';
        $gas = '0';
        $mints = 0;
        $unmetered = 0;

        WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_BSC)
            ->whereNotNull('dest_tx_hash')
            ->whereIn('status', [WtpixBridgeTransfer::STATUS_COMPLETED, WtpixBridgeTransfer::STATUS_NEEDS_REVIEW])
            ->select(['id', 'status', 'fee_wei', 'dest_gas_used', 'dest_gas_price_wei'])
            ->chunkById(500, function ($rows) use (&$fees, &$gas, &$mints, &$unmetered) {
                foreach ($rows as $row) {
                    if ($row->status === WtpixBridgeTransfer::STATUS_COMPLETED) {
                        $fees = bcadd($fees, (string) $row->fee_wei, 0);
                        $mints++;
                    }
                    if ($row->dest_gas_used === null || $row->dest_gas_price_wei === null) {
                        $unmetered++;

                        continue;
                    }
                    $gas = bcadd($gas, bcmul((string) $row->dest_gas_used, (string) $row->dest_gas_price_wei, 0), 0);
                }
            });

        return ['mints' => $mints, 'fees_tpix' => Wei::format($fees, 18), 'gas_bnb' => Wei::format($gas, 18), 'unmetered' => $unmetered];
    }

    // =========================================================================
    // ส่วนประกอบ
    // =========================================================================

    /** แก๊สกลาง (median) ของ mint ล่าสุดจากใบเสร็จจริง — ตัวอย่างไม่พอ = ค่าเผื่อจาก config */
    public function gasUnits(): int
    {
        $samples = WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_BSC)
            ->whereNotNull('dest_gas_used')
            ->latest('id')
            ->limit(max(1, $this->config->feeSetting('gas_samples', 20)))
            ->pluck('dest_gas_used')
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->sort()
            ->values()
            ->all();

        $fallback = max(21000, $this->config->feeSetting('mint_gas_units', 150000));

        if (count($samples) < max(1, $this->config->feeSetting('min_gas_samples', 5))) {
            return $fallback;
        }

        $mid = intdiv(count($samples), 2);

        // จำนวนคู่ = เอาตัวที่สูงกว่าของคู่กลาง (ไม่เฉลี่ยลง)
        return $samples[$mid];
    }

    /** ราคาแก๊สที่ relayer จะจ่ายจริง = eth_gasPrice × ตัวคูณเดียวกับตอนเซ็น หนีบเพดานเดียวกัน */
    private function gasPriceWei(): ?string
    {
        $hex = $this->config->bscRpc()->result('eth_gasPrice');
        if (! is_string($hex) || ! str_starts_with($hex, '0x')) {
            return null;
        }

        $bsc = $this->config->bsc();
        $price = bcdiv(bcmul(Wei::hexToInt($hex), (string) max(10000, (int) ($bsc['gas_price_multiplier_bps'] ?? 12000)), 0), '10000', 0);

        $max = (string) ($bsc['max_gas_price_gwei'] ?? '');
        if ($max !== '') {
            $maxWei = Wei::toWei($max, 9);
            if (bccomp($price, $maxWei, 0) > 0) {
                $price = $maxWei;
            }
        }

        return bccomp($price, '0', 0) > 0 ? $price : null;
    }

    private function bnbUsd(): ?string
    {
        try {
            $price = $this->prices->getPrice('BNB');
        } catch (\Throwable $e) {
            Log::warning('wtpix-bridge: ไม่มีราคา BNB สำหรับคิดค่าธรรมเนียม', ['error' => $e->getMessage()]);

            return null;
        }

        // ⚠️ ทศนิยมคงที่ — ห้ามพึ่ง (string) ของ float (ได้รูป 1.0E-5 / ปัดต่างกันตามเครื่อง)
        return is_finite($price) && $price > 0 ? number_format($price, 8, '.', '') : null;
    }

    /**
     * ราคา TPIX (USDT) = min(TWAP สั้น, TWAP ยาว) — ทั้งสองช่วงต้องมีแท่งครอบคลุมพอ.
     *
     * @return array{0: ?string, 1: ?string} [ราคา, เหตุผลที่ไม่มีราคา]
     */
    private function tpixUsd(): array
    {
        try {
            $contracts = $this->market->contracts();
        } catch (\Throwable) {
            $contracts = null;
        }

        if ($contracts === null) {
            return [null, 'หาพูล TPIX-USDT ไม่เจอ'];
        }

        $minCoverage = (float) $this->config->feeSetting('min_twap_coverage_pct', 50);
        $prices = [];

        foreach (['twap_short_minutes' => 60, 'twap_long_minutes' => 1440] as $key => $default) {
            $twap = $this->market->twap($contracts['pair'], $this->config->feeSetting($key, $default));
            if ($twap['price'] === null || $twap['coverage_pct'] < $minCoverage) {
                return [null, "TWAP {$twap['minutes']} นาทีมีข้อมูลไม่พอ ({$twap['coverage_pct']}%)"];
            }
            $prices[] = $twap['price'];
        }

        $price = bccomp($prices[0], $prices[1], 18) <= 0 ? $prices[0] : $prices[1];

        return bccomp($price, '0', 18) > 0 ? [$price, null] : [null, 'ราคา TPIX เป็นศูนย์'];
    }

    private function staleMinutes(): int
    {
        return max(1, $this->config->feeSetting('stale_minutes', 30));
    }

    /** หารปัดขึ้น (จำนวนเต็ม wei) — ปัดลงทีละ wei สะสมเป็นส่วนที่สะพานออกเอง */
    private function ceilDiv(string $numerator, string $denominator): string
    {
        $quotient = bcdiv($numerator, $denominator, 0);

        return bccomp(bcmul($quotient, $denominator, 18), $numerator, 18) < 0 ? bcadd($quotient, '1', 0) : $quotient;
    }
}
