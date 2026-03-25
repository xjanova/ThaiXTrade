<?php

namespace App\Services;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\WhitelistEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TokenSaleService — ระบบจัดการการขายเหรียญ TPIX.
 *
 * จัดการ purchase flow:
 * 1. ผู้ใช้ส่ง BNB/USDT ไปยัง sale wallet บน BSC
 * 2. ส่ง tx_hash มาที่ API
 * 3. Backend verify tx on-chain → บันทึก allocation
 * 4. ผู้ใช้ claim TPIX เมื่อ vesting ปลดล็อค
 */
class TokenSaleService
{
    public function __construct(
        private PriceFeedService $priceFeed,
    ) {}

    // =========================================================================
    // Public Methods — ดึงข้อมูลรอบขาย
    // =========================================================================

    /**
     * ดึงรอบขายที่กำลัง active พร้อม phases.
     */
    public function getActiveSale(): ?TokenSale
    {
        return Cache::remember('token_sale:active', 30, function () {
            return TokenSale::active()
                ->with(['phases' => fn ($q) => $q->orderBy('phase_order')])
                ->first();
        });
    }

    /**
     * ดึง phase ที่กำลัง active (เปิดขายอยู่).
     */
    public function getActivePhase(?TokenSale $sale = null): ?SalePhase
    {
        $sale = $sale ?? $this->getActiveSale();
        if (! $sale) {
            return null;
        }

        return $sale->phases()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->first();
    }

    /**
     * ดึงสถิติรอบขาย (สำหรับแสดงบนหน้าเว็บ).
     */
    public function getSaleStats(?TokenSale $sale = null): array
    {
        $sale = $sale ?? $this->getActiveSale();
        if (! $sale) {
            return [
                'total_supply' => 0,
                'total_sold' => 0,
                'total_raised_usd' => 0,
                'percent_sold' => 0,
                'buyers_count' => 0,
                'phases' => [],
            ];
        }

        $buyersCount = SaleTransaction::where('token_sale_id', $sale->id)
            ->where('status', 'confirmed')
            ->distinct('wallet_address')
            ->count('wallet_address');

        return [
            'total_supply' => (float) $sale->total_supply_for_sale,
            'total_sold' => (float) $sale->total_sold,
            'total_raised_usd' => (float) $sale->total_raised_usd,
            'percent_sold' => $sale->percent_sold,
            'buyers_count' => $buyersCount,
            'phases' => $sale->phases->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price_usd' => (float) $p->price_usd,
                'allocation' => (float) $p->allocation,
                'sold' => (float) $p->sold,
                'percent_sold' => $p->percent_sold,
                'remaining' => $p->remaining_allocation,
                'status' => $p->status,
                'starts_at' => $p->starts_at?->toIso8601String(),
                'ends_at' => $p->ends_at?->toIso8601String(),
                'vesting_tge_percent' => (float) $p->vesting_tge_percent,
                'vesting_cliff_days' => $p->vesting_cliff_days,
                'vesting_duration_days' => $p->vesting_duration_days,
            ])->all(),
        ];
    }

    // =========================================================================
    // Public Methods — การซื้อ
    // =========================================================================

    /**
     * คำนวณ preview ก่อนซื้อ (จำนวน TPIX ที่จะได้).
     */
    public function calculatePurchasePreview(int $phaseId, string $currency, float $amount): array
    {
        $phase = SalePhase::findOrFail($phaseId);
        $conversion = $this->priceFeed->convertToTpix($amount, $currency, (float) $phase->price_usd);

        return [
            'phase_id' => $phase->id,
            'phase' => $phase->name,
            'price_per_tpix' => (float) $phase->price_usd,
            'payment_amount' => $amount,
            'payment_currency' => strtoupper($currency),
            'payment_usd_value' => $conversion['usd_value'],
            'tpix_amount' => $conversion['tpix_amount'],
            'currency_rate' => $conversion['rate'],
            'remaining_in_phase' => $phase->remaining_allocation,
        ];
    }

    /**
     * ดำเนินการซื้อเหรียญ TPIX.
     *
     * Flow: ตรวจสอบ → verify on-chain → บันทึก → อัปเดตยอด
     *
     * @throws \Exception
     */
    public function processPurchase(
        string $walletAddress,
        int $phaseId,
        string $currency,
        float $amount,
        string $txHash
    ): SaleTransaction {
        $phase = SalePhase::with('tokenSale')->findOrFail($phaseId);
        $sale = $phase->tokenSale;

        // ตรวจสอบว่า sale และ phase ยัง active
        if ($sale->status !== 'active') {
            throw new \Exception('Token sale is not active.');
        }
        if ($phase->status !== 'active') {
            throw new \Exception('This phase is not active.');
        }

        // คำนวณ TPIX ที่จะได้
        $conversion = $this->priceFeed->convertToTpix($amount, $currency, (float) $phase->price_usd);
        $tpixAmount = $conversion['tpix_amount'];

        // ตรวจสอบว่ายังมี allocation เหลือ (ใช้ pessimistic lock)
        DB::transaction(function () use ($phase, $tpixAmount) {
            $lockedPhase = SalePhase::lockForUpdate()->find($phase->id);
            if ($tpixAmount > $lockedPhase->remaining_allocation) {
                throw new \Exception('Insufficient allocation in this phase.');
            }
        });

        // ตรวจสอบ min/max purchase
        if ($tpixAmount < (float) $phase->min_purchase) {
            throw new \Exception("Minimum purchase is {$phase->min_purchase} TPIX.");
        }
        if ((float) $phase->max_purchase > 0 && $tpixAmount > (float) $phase->max_purchase) {
            throw new \Exception("Maximum purchase is {$phase->max_purchase} TPIX.");
        }

        // ตรวจสอบ whitelist (ถ้า phase ต้อง whitelist)
        if ($phase->whitelist_only) {
            $isWhitelisted = WhitelistEntry::where('sale_phase_id', $phase->id)
                ->where('wallet_address', strtolower($walletAddress))
                ->exists();
            if (! $isWhitelisted) {
                throw new \Exception('Your wallet is not whitelisted for this phase.');
            }
        }

        // ตรวจสอบ tx_hash ซ้ำ
        $exists = SaleTransaction::where('tx_hash', $txHash)->exists();
        if ($exists) {
            throw new \Exception('This transaction has already been processed.');
        }

        // ตรวจสอบ transaction บน BSC on-chain (รวมตรวจจำนวนเงิน)
        $txVerification = $this->verifyBscTransaction($txHash, $walletAddress, $sale->sale_wallet_address, $currency, $amount);
        $txStatus = $txVerification['verified'] ? 'confirmed' : 'pending';

        if (! $txVerification['verified']) {
            Log::warning('BSC tx verification failed — saving as pending', [
                'tx_hash' => $txHash,
                'reason' => $txVerification['reason'],
                'wallet' => $walletAddress,
                'claimed_amount' => $amount,
                'actual_amount' => $txVerification['value'] ?? null,
            ]);
        }

        // บันทึกรายการซื้อใน DB transaction
        return DB::transaction(function () use ($sale, $phase, $walletAddress, $currency, $amount, $conversion, $tpixAmount, $txHash, $txStatus, $txVerification) {
            $transaction = SaleTransaction::create([
                'token_sale_id' => $sale->id,
                'sale_phase_id' => $phase->id,
                'wallet_address' => strtolower($walletAddress),
                'payment_currency' => strtoupper($currency),
                'payment_amount' => $amount,
                'payment_usd_value' => $conversion['usd_value'],
                'tpix_amount' => $tpixAmount,
                'price_per_tpix' => $phase->price_usd,
                'tx_hash' => $txHash,
                'status' => $txStatus,
                'vesting_start_at' => $phase->ends_at ?? $sale->ends_at ?? now(),
                'metadata' => [
                    'bsc_verified' => $txVerification['verified'],
                    'bsc_block' => $txVerification['block_number'] ?? null,
                    'bsc_from' => $txVerification['from'] ?? null,
                    'bsc_to' => $txVerification['to'] ?? null,
                    'bsc_value' => $txVerification['value'] ?? null,
                ],
            ]);

            // อัปเดตยอดขายใน phase
            $phase->increment('sold', $tpixAmount);

            // อัปเดตยอดขายรวมใน sale
            $sale->increment('total_sold', $tpixAmount);
            $sale->increment('total_raised_usd', $conversion['usd_value']);

            // เคลียร์ cache
            Cache::forget('token_sale:active');

            return $transaction;
        });
    }

    // =========================================================================
    // Public Methods — Vesting & Claims
    // =========================================================================

    /**
     * ดึงรายการซื้อของ wallet.
     */
    public function getPurchases(string $walletAddress): array
    {
        return SaleTransaction::byWallet($walletAddress)
            ->with('phase')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->uuid,
                'phase' => $tx->phase?->name,
                'tpix_amount' => (float) $tx->tpix_amount,
                'payment_amount' => (float) $tx->payment_amount,
                'payment_currency' => $tx->payment_currency,
                'payment_usd_value' => (float) $tx->payment_usd_value,
                'status' => $tx->status,
                'claimable' => $tx->claimable_amount,
                'claimed' => (float) $tx->claimed_amount,
                'tx_hash' => $tx->tx_hash,
                'created_at' => $tx->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * ดึง vesting schedule ของ wallet.
     */
    public function getVestingSchedule(string $walletAddress): array
    {
        $purchases = SaleTransaction::byWallet($walletAddress)
            ->confirmed()
            ->with('phase')
            ->get();

        $totalPurchased = $purchases->sum(fn ($tx) => (float) $tx->tpix_amount);
        $totalClaimable = $purchases->sum(fn ($tx) => $tx->claimable_amount);
        $totalClaimed = $purchases->sum(fn ($tx) => (float) $tx->claimed_amount);

        return [
            'total_purchased' => round($totalPurchased, 8),
            'total_claimable' => round($totalClaimable, 8),
            'total_claimed' => round($totalClaimed, 8),
            'total_locked' => round($totalPurchased - $totalClaimed - $totalClaimable, 8),
        ];
    }

    // =========================================================================
    // BSC On-Chain Verification
    // =========================================================================

    /**
     * ตรวจสอบ transaction บน BSC ว่ามีจริง + ส่งไปถูก wallet + จำนวนเงินถูกต้อง.
     *
     * @param  string  $txHash  Transaction hash บน BSC
     * @param  string  $fromWallet  Wallet address ของผู้ซื้อ
     * @param  string|null  $saleWallet  Wallet ที่รับเงิน
     * @param  string  $claimedCurrency  สกุลเงินที่ผู้ใช้อ้าง (BNB, USDT, BUSD)
     * @param  float  $claimedAmount  จำนวนเงินที่ผู้ใช้อ้าง
     */
    private function verifyBscTransaction(
        string $txHash,
        string $fromWallet,
        ?string $saleWallet,
        string $claimedCurrency = 'BNB',
        float $claimedAmount = 0
    ): array {
        $result = ['verified' => false, 'reason' => 'not_checked'];

        // ถ้าไม่มี sale wallet ข้ามการ verify
        if (empty($saleWallet)) {
            $result['reason'] = 'no_sale_wallet_configured';

            return $result;
        }

        $rpcUrl = config('services.bsc.rpc_url', 'https://bsc-dataseed.binance.org');

        // Tolerance สำหรับ amount check (5% — เผื่อ gas/slippage)
        $amountTolerance = 0.05;

        try {
            // eth_getTransactionReceipt — เช็คว่า tx สำเร็จ
            $response = Http::timeout(10)
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_getTransactionReceipt',
                    'params' => [$txHash],
                ]);

            if (! $response->successful()) {
                $result['reason'] = 'rpc_request_failed';

                return $result;
            }

            $receipt = $response->json('result');

            if (! $receipt) {
                $result['reason'] = 'tx_not_found_or_pending';

                return $result;
            }

            // เช็คว่า tx สำเร็จ (status = 0x1)
            if (($receipt['status'] ?? '') !== '0x1') {
                $result['reason'] = 'tx_reverted';

                return $result;
            }

            $result['block_number'] = hexdec($receipt['blockNumber'] ?? '0');

            // ดึง tx detail เพื่อเช็ค from/to/value
            $txResponse = Http::timeout(10)
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'method' => 'eth_getTransactionByHash',
                    'params' => [$txHash],
                ]);

            $tx = $txResponse->json('result');
            if (! $tx) {
                $result['reason'] = 'tx_detail_not_found';

                return $result;
            }

            $result['from'] = strtolower($tx['from'] ?? '');
            $result['to'] = strtolower($tx['to'] ?? '');

            // ใช้ gmp เพื่อป้องกัน overflow สำหรับจำนวนเงินสูง
            $valueWei = gmp_init($tx['value'] ?? '0x0', 16);
            $result['value'] = (float) bcdiv(gmp_strval($valueWei), '1000000000000000000', 18);

            // เช็ค: tx ต้องส่งมาจาก wallet ที่อ้าง
            if ($result['from'] !== strtolower($fromWallet)) {
                $result['reason'] = 'from_address_mismatch';

                return $result;
            }

            $saleWalletLower = strtolower($saleWallet);

            // === BNB Native Transfer ===
            if ($result['to'] === $saleWalletLower) {
                // ตรวจจำนวนเงิน — ต้องตรงกับที่อ้าง (tolerance 5%)
                if (strtoupper($claimedCurrency) === 'BNB' && $claimedAmount > 0) {
                    $minExpected = $claimedAmount * (1 - $amountTolerance);
                    if ($result['value'] < $minExpected) {
                        $result['reason'] = 'amount_mismatch_native';
                        $result['expected'] = $claimedAmount;
                        $result['actual'] = $result['value'];

                        return $result;
                    }
                }

                $result['verified'] = true;
                $result['reason'] = 'verified_native_transfer';

                return $result;
            }

            // === ERC-20 Transfer (USDT/BUSD) ===
            $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
            foreach ($receipt['logs'] ?? [] as $log) {
                if (($log['topics'][0] ?? '') === $transferTopic) {
                    // topics[1] = from address, topics[2] = to address (padded to 32 bytes)
                    $logTo = '0x'.substr($log['topics'][2] ?? '', 26);

                    if (strtolower($logTo) === $saleWalletLower) {
                        // ตรวจจำนวนเงินจาก data field (ERC-20 amount)
                        if (in_array(strtoupper($claimedCurrency), ['USDT', 'BUSD']) && $claimedAmount > 0) {
                            $dataHex = $log['data'] ?? '0x0';
                            $tokenWei = gmp_init($dataHex, 16);
                            // USDT/BUSD บน BSC ใช้ 18 decimals
                            $actualTokenAmount = (float) bcdiv(gmp_strval($tokenWei), '1000000000000000000', 8);
                            $minExpected = $claimedAmount * (1 - $amountTolerance);

                            if ($actualTokenAmount < $minExpected) {
                                $result['reason'] = 'amount_mismatch_erc20';
                                $result['expected'] = $claimedAmount;
                                $result['actual'] = $actualTokenAmount;

                                return $result;
                            }
                            $result['value'] = $actualTokenAmount;
                        }

                        $result['verified'] = true;
                        $result['reason'] = 'verified_erc20_transfer';

                        return $result;
                    }
                }
            }

            $result['reason'] = 'recipient_mismatch';

            return $result;
        } catch (\Exception $e) {
            Log::warning('BSC verification error', ['tx' => $txHash, 'error' => $e->getMessage()]);
            $result['reason'] = 'verification_error: '.$e->getMessage();

            return $result;
        }
    }
}
