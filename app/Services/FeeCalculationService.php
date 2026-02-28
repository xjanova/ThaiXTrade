<?php

namespace App\Services;

use App\Models\FeeConfig;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * FeeCalculationService
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

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Calculate the swap fee for a given amount on a specific chain.
     *
     * Uses the fee hierarchy:
     *   1. TradingPair-specific fee override (if tradingPairId provided)
     *   2. Chain-specific FeeConfig for 'swap' type
     *   3. Global default fee rate from SiteSetting
     *
     * @param  float  $amount       The swap amount (before fees)
     * @param  int    $chainId      The blockchain chain ID
     * @param  int|null  $tradingPairId  Optional trading pair for pair-specific overrides
     * @return array{fee_amount: float, net_amount: float, fee_rate: float, fee_type: string}
     */
    public function calculateSwapFee(float $amount, int $chainId, ?int $tradingPairId = null): array
    {
        $feeRate = $this->resolveSwapFeeRate($chainId, $tradingPairId);

        // Enforce max fee rate cap from site settings
        $maxFeeRate = (float) SiteSetting::get('trading', 'max_fee_rate', 5.0);
        $feeRate = min($feeRate, $maxFeeRate);

        $feeAmount = $amount * ($feeRate / 100);
        $netAmount = $amount - $feeAmount;

        return [
            'fee_amount' => round($feeAmount, 8),
            'net_amount' => round(max($netAmount, 0), 8),
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
     *
     * @param  string  $type     The fee type (e.g., 'swap', 'trade', 'withdrawal')
     * @param  int|null  $chainId  Optional chain ID for chain-specific rates
     * @return float  The effective fee rate as a percentage
     */
    public function getEffectiveFeeRate(string $type, ?int $chainId = null): float
    {
        $cacheKey = self::CACHE_PREFIX.".effective.{$type}.".($chainId ?? 'global');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($type, $chainId) {
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
            return (float) SiteSetting::get('trading', 'default_fee_rate', 0.3);
        });
    }

    /**
     * Generate a swap quote with fee breakdown and estimated output.
     *
     * Returns comprehensive quote data including fee calculations,
     * price impact estimation, and minimum received amounts.
     *
     * @param  float   $fromAmount  The input token amount
     * @param  string  $fromToken   The source token contract address
     * @param  string  $toToken     The destination token contract address
     * @param  int     $chainId     The blockchain chain ID
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
     *
     * @param  int       $chainId
     * @param  int|null  $tradingPairId
     * @return float
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
     *
     * @param  int  $tradingPairId
     * @return TradingPair|null
     */
    private function getCachedTradingPair(int $tradingPairId): ?TradingPair
    {
        $cacheKey = self::CACHE_PREFIX.".trading_pair.{$tradingPairId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tradingPairId) {
            return TradingPair::find($tradingPairId);
        });
    }

    /**
     * Find the trading pair ID for given tokens on a chain.
     *
     * Looks up by token contract addresses as base/quote in both directions.
     *
     * @param  string  $fromToken
     * @param  string  $toToken
     * @param  int     $chainId
     * @return int|null
     */
    private function findTradingPairId(string $fromToken, string $toToken, int $chainId): ?int
    {
        $cacheKey = self::CACHE_PREFIX.".pair_lookup.{$chainId}.".md5($fromToken.$toToken);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($fromToken, $toToken, $chainId) {
            $pair = TradingPair::active()
                ->where('chain_id', $chainId)
                ->where(function ($query) use ($fromToken, $toToken) {
                    $query->where(function ($q) use ($fromToken, $toToken) {
                        $q->whereHas('baseToken', fn ($t) => $t->where('contract_address', $fromToken))
                          ->whereHas('quoteToken', fn ($t) => $t->where('contract_address', $toToken));
                    })->orWhere(function ($q) use ($fromToken, $toToken) {
                        $q->whereHas('baseToken', fn ($t) => $t->where('contract_address', $toToken))
                          ->whereHas('quoteToken', fn ($t) => $t->where('contract_address', $fromToken));
                    });
                })
                ->first();

            return $pair?->id;
        });
    }

    /**
     * Get the default slippage tolerance for a chain.
     *
     * Reads from SwapConfig if available, otherwise uses class constant.
     *
     * @param  int  $chainId
     * @return float
     */
    private function getDefaultSlippage(int $chainId): float
    {
        $cacheKey = self::CACHE_PREFIX.".slippage.{$chainId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($chainId) {
            $swapConfig = \App\Models\SwapConfig::active()
                ->where('chain_id', $chainId)
                ->first();

            return $swapConfig ? (float) $swapConfig->slippage_tolerance : self::DEFAULT_SLIPPAGE;
        });
    }

    /**
     * Estimate price impact based on the swap amount.
     *
     * Uses the FeeConfig min/max amount thresholds as reference points.
     * Larger amounts relative to the max threshold indicate higher price impact.
     *
     * @param  float  $amount
     * @param  int    $chainId
     * @return float  Estimated price impact as a percentage
     */
    private function estimatePriceImpact(float $amount, int $chainId): float
    {
        $config = FeeConfig::active()
            ->byType('swap')
            ->where('chain_id', $chainId)
            ->first();

        if (! $config || ! $config->max_amount || $config->max_amount == 0) {
            // Default: minimal impact for small amounts
            return min($amount * 0.001, 5.0);
        }

        // Price impact scales with amount relative to max threshold
        $ratio = $amount / (float) $config->max_amount;

        return min($ratio * 2.0, 10.0);
    }
}
