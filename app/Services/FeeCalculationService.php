<?php

namespace App\Services;

use App\Models\FeeConfig;
use App\Models\SiteSetting;
use App\Models\SwapConfig;
use App\Models\TradingPair;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FeeCalculationService.
 *
 * Handles fee calculations for swap and trade operations on the platform.
 * Implements a fee hierarchy: TradingPair override -> Chain-specific -> Global default.
 * Caches fee configurations for performance.
 */
class FeeCalculationService
{
    /**
     * Cache TTL for fee configs in seconds (10 minutes).
     */
    protected const CACHE_TTL = 600;

    /**
     * Cache key prefix for fee configurations.
     */
    protected const CACHE_PREFIX = 'fee_config';

    /**
     * Default slippage tolerance percentage.
     */
    protected const DEFAULT_SLIPPAGE = 0.5;

    /**
     * ตัวนับรุ่นของแคชค่าธรรมเนียม — ทุกคีย์แคชของบริการนี้ (และของ /api/v1/fees) ฝังเลขรุ่นไว้
     * แอดมินแก้ค่าธรรมเนียมที่ไหนก็ตาม → flush() เพิ่มเลขรุ่น → ค่าใหม่มีผลกับคำขอถัดไปทันที
     *
     * ⚠️ เดิมไม่มีใครล้างแคชพวกนี้เลย: แก้อัตราในหลังบ้านแล้วหน้าเว็บยังคิดอัตราเก่าต่ออีก 10 นาที
     *    (15 นาทีที่ /api/v1/fees) · ตั้งกระเป๋าค่าธรรมเนียมแล้วยังขึ้น "ยังไม่ได้ตั้ง" อีก 5 นาที
     */
    public const GENERATION_KEY = 'fee_config:generation';

    /** ที่อยู่ที่ใช้แทน "เหรียญหลักของเชน" (BNB / TPIX) — ในตาราง tokens เก็บเป็นที่อยู่ศูนย์ */
    private const NATIVE_ALIASES = [
        '0x0000000000000000000000000000000000000000',
        '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
    ];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * ทิ้งแคชค่าธรรมเนียมทั้งหมด — เรียกทุกครั้งที่แอดมินแก้ค่าที่บริการนี้อ่าน
     * (fee_configs · อัตราทับรายคู่ · swap_configs · ค่าตั้งกลุ่ม trading/revenue)
     */
    public static function flush(): void
    {
        Cache::forever(self::GENERATION_KEY, self::generation() + 1);
    }

    /** เลขรุ่นปัจจุบันของแคชค่าธรรมเนียม — ผู้เรียกภายนอกใช้ต่อท้ายคีย์แคชของตัวเอง */
    public static function generation(): int
    {
        return (int) Cache::get(self::GENERATION_KEY, 0);
    }

    /** คีย์แคชของบริการนี้ ผูกกับเลขรุ่นปัจจุบัน */
    private static function cacheKey(string $suffix): string
    {
        return self::CACHE_PREFIX.'.g'.self::generation().'.'.$suffix;
    }

    /**
     * Calculate the swap fee for a given amount on a specific chain.
     *
     * Uses the fee hierarchy:
     *   1. TradingPair-specific fee override (if tradingPairId provided)
     *   2. Chain-specific FeeConfig for 'swap' type
     *   3. Global default fee rate from SiteSetting
     *
     * @return array{fee_amount: float, net_amount: float, fee_rate: float, fee_type: string}
     */
    public function calculateSwapFee(float $amount, int $chainId, ?int $tradingPairId = null): array
    {
        $feeRate = $this->resolveSwapFeeRate($chainId, $tradingPairId);

        /*
         * Enforce max fee rate cap from site settings
         *
         * ⚠️ เพดานที่ว่าง/ไม่ใช่ตัวเลข/ไม่เป็นบวก = "ไม่ได้ตั้ง" ใช้ค่าปริยาย ไม่ใช่เพดาน 0
         *    เดิมแอดมินเผลอลบช่องนี้ทิ้งแล้วกดบันทึก → (float) '' = 0 → min(อัตรา, 0) = 0
         *    ค่าธรรมเนียมของทุกไม้กลายเป็นศูนย์เงียบๆ และถูกบันทึกว่า "ไม่ต้องเก็บ"
         */
        $feeRate = min($feeRate, self::positiveSetting('max_fee_rate', 5.0));

        // Use bcmath for precise fee calculation
        // ⚠️ ห้ามใช้ (string) กับ float — PHP เขียนเลขเล็กเป็น "1.0E-5" แล้ว bcmath โยน ValueError
        //    (ขาย BTC 0.00001 / ETH 0.01 → ค่าธรรมเนียมต่ำกว่า 0.0001 → 500 ทั้งตอนขอราคาและตอนบันทึกไม้)
        $feeAmount = (float) bcmul(self::decimal($amount), bcdiv(self::decimal($feeRate), '100', 12), 8);
        $netAmount = (float) bcsub(self::decimal($amount), self::decimal($feeAmount), 8);

        return [
            'fee_amount' => $feeAmount,
            'net_amount' => max($netAmount, 0.0),
            'fee_rate' => round($feeRate, 4),
            'fee_type' => 'swap',
        ];
    }

    /**
     * Get the effective fee rate for a given type and optional chain.
     *
     * Looks up the fee rate following the hierarchy:
     *   1. Chain-specific FeeConfig (if chainId provided)
     *   2. Global FeeConfig (chain_id IS NULL)
     *   3. Default from SiteSetting
     */
    public function getEffectiveFeeRate(string $type, ?int $chainId = null): float
    {
        $cacheKey = self::cacheKey("effective.{$type}.".($chainId ?? 'global'));

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($type, $chainId) {
                // 1. Try chain-specific config
                if ($chainId) {
                    $chainConfig = FeeConfig::active()
                        ->byType($type)
                        ->where('chain_id', $chainId)
                        ->first();

                    if ($chainConfig) {
                        return (float) $chainConfig->taker_fee;
                    }
                }

                // 2. Try global config (no chain)
                $globalConfig = FeeConfig::active()
                    ->byType($type)
                    ->whereNull('chain_id')
                    ->first();

                if ($globalConfig) {
                    return (float) $globalConfig->taker_fee;
                }

                // 3. Fall back to site setting default
                // ช่องว่าง/ไม่ใช่ตัวเลข = ไม่ได้ตั้ง → 0.3 · เลข 0 ที่ตั้งใจพิมพ์ = เทรดฟรีจริง
                $default = SiteSetting::get('trading', 'default_fee_rate', 0.3);

                return is_numeric($default) && (float) $default >= 0 ? (float) $default : 0.3;
            }
        );
    }

    /** ค่าตั้งกลุ่ม trading ที่ต้องเป็นจำนวนบวก — ว่าง/ผิดรูป/ไม่เป็นบวก ใช้ค่าปริยายแทน */
    private static function positiveSetting(string $key, float $default): float
    {
        $value = SiteSetting::get('trading', $key, $default);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }

    /**
     * Generate a swap quote with fee breakdown and estimated output.
     *
     * Returns comprehensive quote data including fee calculations,
     * price impact estimation, and minimum received amounts.
     *
     * @return array{
     *     from_amount: float,
     *     to_amount_estimate: float,
     *     fee_amount: float,
     *     fee_rate: float,
     *     price_impact: float,
     *     minimum_received: float,
     *     slippage: float
     * }
     */
    /**
     * ค่าธรรมเนียมสวอปของคู่โทเคนหนึ่ง — ใช้ลำดับอัตราเดียวกับ getSwapQuote ทุกประการ
     *
     * ตอนบันทึกไม้ (/swap/execute) ต้องคิดเหมือนตอนขอราคา ไม่งั้นคู่ที่แอดมินตั้ง
     * taker_fee_override ไว้จะโดน FEE_MISMATCH ทุกไม้ (ขอราคาคิดอัตราพิเศษ
     * แต่ตอนบันทึกคิดอัตรากลาง) แล้วไม้ทั้งคู่นั้นไม่มีประวัติให้ใครดูเลย
     *
     * @return array{fee_amount: float, net_amount: float, fee_rate: float, fee_type: string}
     */
    public function calculateSwapFeeForTokens(float $amount, string $fromToken, string $toToken, int $chainId): array
    {
        return $this->calculateSwapFee($amount, $chainId, $this->findTradingPairId($fromToken, $toToken, $chainId));
    }

    /**
     * เหรียญที่ถือว่า 1 หน่วย = 1 ดอลลาร์ — ใช้ตีมูลค่าค่าธรรมเนียมเป็น USD
     * (ชุดเดียวกับที่ประวัติเทรดใช้ตัดสินว่าฝั่งไหนคือ quote)
     */
    public const USD_STABLES = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'DAI', 'TUSD'];

    /**
     * มูลค่าค่าธรรมเนียมสวอปเป็นดอลลาร์ ณ ตอนที่บันทึกไม้.
     *
     * ⚠️ fee_amount เป็นหน่วยของ "เหรียญขาเข้า" — รวมกันตรงๆ ไม่ได้
     *    ขาย PEPE 1,000,000,000 เหรียญ = ค่าธรรมเนียม 3,000,000 PEPE (~$30)
     *    แต่หน้ารายได้ของแอดมินเคยบวกเป็น $3,000,000
     *
     *  - จ่ายด้วยสเตเบิล → ค่าธรรมเนียมคือดอลลาร์อยู่แล้ว
     *  - ได้สเตเบิล → ราคาโดยนัยของไม้นี้ = ยอดที่ได้ ÷ ส่วนที่สวอปจริง (ขาเข้า − ค่าธรรมเนียม)
     *  - ไม่มีฝั่งไหนเป็นสเตเบิล → ไม่รู้ (null) ดีกว่าเดา
     */
    public static function feeUsdFor(
        ?string $fromSymbol,
        ?string $toSymbol,
        float $feeAmount,
        float $fromAmount,
        float $toAmount,
    ): ?float {
        if ($feeAmount <= 0) {
            return 0.0;
        }

        $from = strtoupper((string) $fromSymbol);
        $to = strtoupper((string) $toSymbol);

        if (in_array($from, self::USD_STABLES, true)) {
            return round($feeAmount, 8);
        }

        $swapped = $fromAmount - $feeAmount;
        if (in_array($to, self::USD_STABLES, true) && $swapped > 0 && $toAmount > 0) {
            return round($feeAmount * ($toAmount / $swapped), 8);
        }

        return null;
    }

    /**
     * ตัวเลขเป็นสตริงทศนิยมธรรมดาที่ bcmath รับได้เสมอ (ไม่มีรูป 1.0E-5)
     */
    public static function decimal(float|int|string $value, int $scale = 18): string
    {
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        $formatted = number_format((float) $value, $scale, '.', '');

        if (! str_contains($formatted, '.')) {
            return $formatted;
        }

        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return ($trimmed === '' || $trimmed === '-0') ? '0' : $trimmed;
    }

    public function getSwapQuote(float $fromAmount, string $fromToken, string $toToken, int $chainId): array
    {
        // Find matching trading pair for potential fee override
        $tradingPairId = $this->findTradingPairId($fromToken, $toToken, $chainId);

        // Calculate fees
        $feeData = $this->calculateSwapFee($fromAmount, $chainId, $tradingPairId);

        // Get default slippage from swap config or use default
        $slippage = $this->getDefaultSlippage($chainId);

        // Estimate output amount (net amount after fee; price ratio is handled off-chain)
        $toAmountEstimate = $feeData['net_amount'];

        // Estimate price impact based on amount relative to min/max thresholds
        $priceImpact = $this->estimatePriceImpact($fromAmount, $chainId);

        // Calculate minimum received with slippage tolerance
        $minimumReceived = $toAmountEstimate * (1 - ($slippage / 100));

        return [
            'from_amount' => round($fromAmount, 8),
            'to_amount_estimate' => round($toAmountEstimate, 8),
            'fee_amount' => $feeData['fee_amount'],
            'fee_rate' => $feeData['fee_rate'],
            'price_impact' => round($priceImpact, 4),
            'minimum_received' => round(max($minimumReceived, 0), 8),
            'slippage' => round($slippage, 2),
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Resolve the swap fee rate using the fee hierarchy.
     */
    private function resolveSwapFeeRate(int $chainId, ?int $tradingPairId = null): float
    {
        // 1. Check TradingPair-specific override
        if ($tradingPairId) {
            $tradingPair = $this->getCachedTradingPair($tradingPairId);

            if ($tradingPair && ! is_null($tradingPair->taker_fee_override)) {
                return (float) $tradingPair->taker_fee_override;
            }
        }

        // 2. Chain-specific -> 3. Global default
        return $this->getEffectiveFeeRate('swap', $chainId);
    }

    /**
     * Get a cached TradingPair by ID.
     */
    private function getCachedTradingPair(int $tradingPairId): ?TradingPair
    {
        $cacheKey = self::cacheKey("trading_pair.{$tradingPairId}");

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($tradingPairId) {
                return TradingPair::find($tradingPairId);
            }
        );
    }

    /**
     * Find the trading pair ID for given tokens on a chain.
     *
     * Looks up by token contract addresses as base/quote in both directions.
     */
    private function findTradingPairId(string $fromToken, string $toToken, int $chainId): ?int
    {
        /*
         * เทียบที่อยู่แบบไม่สนตัวพิมพ์ และรับได้ทุกรูปของ "เหรียญหลักของเชน"
         *
         * ⚠️ เดิมเทียบสตริงตรงตัว — หน้าเว็บส่ง BNB เป็น 0xEeee…EEeE แต่ตาราง tokens เก็บ 0x0000…0000
         *    และส่งที่อยู่แบบ checksum ขณะที่บางแถวเก็บตัวพิมพ์เล็ก → หาคู่ไม่เจอ
         *    อัตราทับรายคู่ (taker_fee_override) ของคู่ที่มี BNB จึง "โชว์ในหน้าเทรดแต่ไม่ถูกคิดจริง"
         */
        $from = self::addressForms($fromToken);
        $to = self::addressForms($toToken);

        // Sort tokens to ensure consistent cache key regardless of swap direction
        $tokens = [$from[0], $to[0]];
        sort($tokens);
        $cacheKey = self::cacheKey("pair_lookup.{$chainId}.".md5(implode(':', $tokens)));

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($from, $to, $chainId) {
                $is = fn (array $forms) => fn ($t) => $t->whereIn(DB::raw('LOWER(contract_address)'), $forms);

                $pair = TradingPair::active()
                    ->where('chain_id', $chainId)
                    ->where(function ($query) use ($from, $to, $is) {
                        $query->where(function ($q) use ($from, $to, $is) {
                            $q->whereHas('baseToken', $is($from))
                                ->whereHas('quoteToken', $is($to));
                        })->orWhere(function ($q) use ($from, $to, $is) {
                            $q->whereHas('baseToken', $is($to))
                                ->whereHas('quoteToken', $is($from));
                        });
                    })
                    ->first();

                return $pair?->id;
            }
        );
    }

    /**
     * รูปของที่อยู่เหรียญที่ต้องถือว่าเป็นเหรียญเดียวกัน (ตัวพิมพ์เล็กทั้งหมด).
     *
     * @return array<int, string> ตัวแรกคือรูปหลัก ใช้ทำคีย์แคช
     */
    private static function addressForms(string $address): array
    {
        $address = strtolower(trim($address));

        return in_array($address, self::NATIVE_ALIASES, true) ? self::NATIVE_ALIASES : [$address];
    }

    /**
     * Get the default slippage tolerance for a chain.
     *
     * Reads from SwapConfig if available, otherwise uses class constant.
     */
    private function getDefaultSlippage(int $chainId): float
    {
        $cacheKey = self::cacheKey("slippage.{$chainId}");

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($chainId) {
                $swapConfig = SwapConfig::active()
                    ->where('chain_id', $chainId)
                    ->first();

                return $swapConfig ? (float) $swapConfig->slippage_tolerance : self::DEFAULT_SLIPPAGE;
            }
        );
    }

    /**
     * Estimate price impact based on the swap amount.
     *
     * Uses the FeeConfig min/max amount thresholds as reference points.
     * Larger amounts relative to the max threshold indicate higher price impact.
     *
     * ⚠️ ค่านี้ไม่ได้มาจากพูลจริง (ไม่รู้สภาพคล่องเลย) — หน้าเว็บไม่ใช้แล้ว
     *    useSwap วัดผลกระทบจริงจาก router บนเชนเอง คงไว้เพื่อความเข้ากันได้ของ API เดิม
     */
    private function estimatePriceImpact(float $amount, int $chainId): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $config = FeeConfig::active()
            ->byType('swap')
            ->where('chain_id', $chainId)
            ->first();

        if (! $config || ! $config->max_amount || (float) $config->max_amount <= 0) {
            // Default: minimal impact for small amounts, capped at 5%
            return min($amount * 0.001, 5.0);
        }

        // Price impact scales with amount relative to max threshold
        $ratio = $amount / (float) $config->max_amount;

        return min($ratio * 2.0, 10.0);
    }
}
