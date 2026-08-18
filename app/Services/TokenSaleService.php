<?php

namespace App\Services;

use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\WhitelistEntry;
use App\Support\Wei;
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

        // ★ ด่านช่วงเวลา — ต้องตรงกับ getActivePhase() ไม่งั้นเฟสที่ปิดไปแล้ว
        // ยังถูกซื้อได้ด้วยการยิง API ตรงพร้อม phase_id เก่า (หน้าเว็บซ่อนไปแล้ว
        // แต่ backend ยังรับ) = รับเงินเข้ารอบที่ประกาศปิดไปแล้ว
        $now = now();
        if ($phase->starts_at !== null && $phase->starts_at->gt($now)) {
            throw new \Exception('This phase has not started yet.');
        }
        if ($phase->ends_at !== null && $phase->ends_at->lt($now)) {
            throw new \Exception('This phase has already ended.');
        }

        // ตรวจสอบ whitelist (ถ้า phase ต้อง whitelist)
        if ($phase->whitelist_only) {
            $entry = WhitelistEntry::where('sale_phase_id', $phase->id)
                ->where('wallet_address', strtolower($walletAddress))
                ->first();
            if (! $entry) {
                throw new \Exception('Your wallet is not whitelisted for this phase.');
            }
        }

        // ตรวจ tx_hash ซ้ำแบบเร็วก่อน (ด่านจริงคือ unique index ในฐานข้อมูล)
        if (SaleTransaction::where('tx_hash', $txHash)->exists()) {
            throw new \Exception('This transaction has already been processed.');
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * ยืนยันการชำระเงินก่อนทุกอย่าง แล้วใช้ "ยอดที่อ่านจากเชน" เท่านั้น
         * ═══════════════════════════════════════════════════════════════════
         * เดิมคำนวณเหรียญจากตัวเลขที่ผู้ซื้อกรอกมา แล้วค่อยไป verify ทีหลัง
         * และต่อให้ verify ไม่ผ่านก็ยังบันทึกแถวเป็น pending พร้อมบวก sold
         * ผลคือ: ยิง tx_hash มั่วปิดรอบขายได้ฟรี และอ้างยอดเกินจริงได้ตามใจ
         *
         * ตอนนี้: verify ไม่ผ่าน = ไม่บันทึกอะไรเลย ไม่แตะ sold และผู้ซื้อยื่นซ้ำได้
         */
        $verification = $this->verifyBscTransaction(
            $txHash,
            $walletAddress,
            $sale->sale_wallet_address,
            $currency,
            $amount
        );

        if (! $verification['verified']) {
            Log::warning('token-sale: ยืนยันการชำระเงินไม่ผ่าน', [
                'tx_hash' => $txHash,
                'reason' => $verification['reason'],
                'retryable' => $verification['retryable'] ?? false,
                'wallet' => $walletAddress,
                'claimed_currency' => $currency,
                'claimed_amount' => $amount,
            ]);

            throw new \Exception($this->purchaseErrorMessage($verification));
        }

        // ยอดและสกุลที่ใช้ออกเหรียญ = ของจริงบนเชน ไม่ใช่ของที่ผู้ซื้อกรอก
        $paidCurrency = $verification['currency'];
        $paidAmount = (float) $verification['amount'];

        // เตือนไว้ในบันทึกถ้าต่างจากที่กรอกมาเกิน 1% (ปกติคือกรอกผิด ไม่ใช่โจมตี)
        if ($amount > 0 && abs($paidAmount - $amount) / $amount > 0.01) {
            Log::info('token-sale: ยอดที่จ่ายจริงต่างจากที่แจ้ง — ใช้ยอดจากเชน', [
                'tx_hash' => $txHash,
                'claimed' => $amount,
                'actual' => $paidAmount,
            ]);
        }

        // คำนวณ TPIX จากยอดที่จ่ายจริง
        $conversion = $this->priceFeed->convertToTpix($paidAmount, $paidCurrency, (float) $phase->price_usd);
        $tpixAmount = $conversion['tpix_amount'];

        if ($tpixAmount <= 0) {
            throw new \Exception('Unable to price this payment right now. Please contact support.');
        }

        // ตรวจสอบ min/max purchase (ก่อน lock เพื่อลด contention)
        if ($tpixAmount < (float) $phase->min_purchase) {
            throw new \Exception("Minimum purchase is {$phase->min_purchase} TPIX.");
        }
        if ((float) $phase->max_purchase > 0 && $tpixAmount > (float) $phase->max_purchase) {
            throw new \Exception("Maximum purchase is {$phase->max_purchase} TPIX.");
        }

        // ★ ATOMIC: ตรวจ allocation + บันทึก + อัปเดต counters ใน transaction เดียว
        // ป้องกัน race condition: สองคนซื้อพร้อมกันจะไม่ขายเกิน
        try {
            return DB::transaction(function () use ($sale, $phase, $walletAddress, $paidCurrency, $paidAmount, $conversion, $tpixAmount, $txHash, $verification) {
                // Pessimistic lock — ตรวจ allocation ภายใต้ lock
                $lockedPhase = SalePhase::lockForUpdate()->find($phase->id);
                if ($tpixAmount > $lockedPhase->remaining_allocation) {
                    throw new \Exception('Insufficient allocation in this phase. Only '.number_format($lockedPhase->remaining_allocation, 2).' TPIX remaining.');
                }

                // เพดานสะสมต่อกระเป๋าในเฟส whitelist — เดิมตั้งค่าไว้แต่ไม่เคยบังคับใช้
                if ($lockedPhase->whitelist_only) {
                    $entry = WhitelistEntry::where('sale_phase_id', $lockedPhase->id)
                        ->where('wallet_address', strtolower($walletAddress))
                        ->lockForUpdate()
                        ->first();

                    $cap = (float) ($entry->max_allocation ?? 0);
                    if ($cap > 0) {
                        $already = (float) SaleTransaction::where('sale_phase_id', $lockedPhase->id)
                            ->where('wallet_address', strtolower($walletAddress))
                            ->sum('tpix_amount');

                        if ($already + $tpixAmount > $cap) {
                            throw new \Exception('This purchase exceeds your allocation for this phase.');
                        }
                    }
                }

                $transaction = SaleTransaction::create([
                    'token_sale_id' => $sale->id,
                    'sale_phase_id' => $phase->id,
                    'wallet_address' => strtolower($walletAddress),
                    'payment_currency' => $paidCurrency,
                    'payment_amount' => $paidAmount,
                    'payment_usd_value' => $conversion['usd_value'],
                    'tpix_amount' => $tpixAmount,
                    'price_per_tpix' => $phase->price_usd,
                    'tx_hash' => $txHash,
                    // ยืนยันแล้วเท่านั้นถึงมาถึงบรรทัดนี้ได้ จึงไม่มีสถานะ pending อีกต่อไป
                    'status' => 'confirmed',
                    'vesting_start_at' => $lockedPhase->ends_at ?? $sale->ends_at ?? now(),
                    'metadata' => [
                        'bsc_verified' => true,
                        'bsc_reason' => $verification['reason'],
                        'bsc_block' => $verification['block_number'] ?? null,
                        'bsc_confirmations' => $verification['confirmations'] ?? null,
                        'bsc_from' => $verification['from'] ?? null,
                        'bsc_to' => $verification['to'] ?? null,
                        'bsc_amount' => $paidAmount,
                    ],
                ]);

                // อัปเดตยอดขายใน phase (ใช้ locked instance)
                $lockedPhase->increment('sold', $tpixAmount);

                // Auto-close phase ถ้าขายหมดแล้ว
                if ($lockedPhase->fresh()->remaining_allocation <= 0) {
                    $lockedPhase->update(['status' => 'completed']);
                    Log::info('Phase sold out — auto-closed', ['phase_id' => $lockedPhase->id, 'name' => $lockedPhase->name]);
                }

                // อัปเดตยอดขายรวมใน sale
                $sale->increment('total_sold', $tpixAmount);
                $sale->increment('total_raised_usd', $conversion['usd_value']);

                // Auto-close sale ถ้าทุก phase ปิดหมดแล้ว
                $activePhases = SalePhase::where('token_sale_id', $sale->id)->where('status', 'active')->count();
                if ($activePhases === 0) {
                    $sale->update(['status' => 'completed']);
                    Log::info('Token sale completed — all phases sold out', ['sale_id' => $sale->id]);
                }

                // เคลียร์ cache
                Cache::forget('token_sale:active');

                return $transaction;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // ยิงขนานมาหลายคำขอด้วย tx เดียว — ใบแรกชนะ ที่เหลือถูกฐานข้อมูลปัด
            throw new \Exception('This transaction has already been processed.');
        }
    }

    /**
     * ข้อความบอกผู้ซื้อว่าทำไมยืนยันการชำระเงินไม่ผ่าน.
     *
     * แยก "รอสักครู่แล้วลองใหม่" (ชั่วคราว) ออกจาก "ยื่นซ้ำไปก็ไม่ผ่าน" (ถาวร)
     * เพราะสองอย่างนี้ผู้ซื้อต้องทำคนละอย่างกัน
     */
    private function purchaseErrorMessage(array $verification): string
    {
        return match ($verification['reason']) {
            'insufficient_confirmations' => 'Payment found but not confirmed yet. Please wait about a minute and submit again.',
            'tx_not_found_or_pending', 'tx_detail_not_found' => 'Transaction not found on BSC yet. Please wait for it to confirm and submit again.',
            'rpc_request_failed', 'verification_error' => 'Could not reach BSC right now. Please try again shortly — your payment is safe.',
            'from_address_mismatch' => 'This transaction was not sent from your connected wallet.',
            'currency_mismatch_native' => 'This transaction paid in BNB. Please select BNB as the payment currency.',
            'currency_mismatch_erc20' => 'The token in this transaction does not match the currency you selected.',
            'unsupported_token' => 'That token is not accepted. Please pay with BNB, USDT or BUSD on BNB Smart Chain.',
            'recipient_mismatch' => 'This transaction was not sent to the official sale wallet.',
            'tx_reverted' => 'That transaction failed on-chain. No payment was received.',
            'tx_too_old' => 'This transaction is too old to be accepted. Please contact support.',
            'zero_value_transfer' => 'That transaction did not transfer any funds.',
            'no_sale_wallet_configured' => 'The sale is not accepting payments right now. Please try again later.',
            default => 'We could not verify this payment. Please contact support with your transaction hash.',
        };
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
        /*
         * ผลลัพธ์ต้องบอก "ของจริงบนเชน" เสมอ:
         *   currency/amount = สิ่งที่จ่ายมาจริง ไม่ใช่สิ่งที่ผู้ซื้อกรอกมา
         *   retryable       = ล้มเหลวชั่วคราว (RPC ล่ม/บล็อกยังไม่นิ่ง) ยื่นซ้ำได้
         *                     ต่างจากล้มเหลวถาวร (ผิดกระเป๋า/เหรียญปลอม) ที่ยื่นซ้ำไปก็เท่านั้น
         *
         * $claimedAmount ไม่ถูกใช้ตัดสินอะไรอีกแล้ว — เก็บพารามิเตอร์ไว้เพื่อให้
         * ผู้เรียกยังส่งมาเทียบเป็นด่านกันกรอกผิดได้ แต่ยอดที่ออกเหรียญมาจากเชนเท่านั้น
         */
        $result = [
            'verified' => false,
            'reason' => 'not_checked',
            'retryable' => false,
            'currency' => null,
            'amount' => 0.0,
            'block_number' => 0,
            'confirmations' => 0,
        ];

        if (empty($saleWallet)) {
            $result['reason'] = 'no_sale_wallet_configured';

            return $result;
        }

        $rpcUrl = config('services.bsc.rpc_url', 'https://bsc-dataseed.binance.org');
        $minConfirmations = (int) config('services.bsc.min_confirmations', 15);
        $maxAgeMinutes = (int) config('services.bsc.max_tx_age_minutes', 60);

        // เหรียญที่รับชำระ: address (ตัวพิมพ์เล็ก) => [symbol, decimals]
        $tokenByAddress = [];
        foreach ((array) config('services.bsc.payment_tokens', []) as $symbol => $meta) {
            $addr = strtolower((string) ($meta['address'] ?? ''));
            if ($addr !== '') {
                $tokenByAddress[$addr] = [
                    'symbol' => strtoupper((string) $symbol),
                    'decimals' => (int) ($meta['decimals'] ?? 18),
                ];
            }
        }

        $claimedCurrency = strtoupper($claimedCurrency);

        try {
            $receipt = $this->rpcCall($rpcUrl, 'eth_getTransactionReceipt', [$txHash]);
            if ($receipt === false) {
                $result['reason'] = 'rpc_request_failed';
                $result['retryable'] = true;

                return $result;
            }

            if (! $receipt) {
                // ยังไม่ขึ้นบล็อก หรือไม่มี hash นี้จริง — ให้ยื่นซ้ำได้
                $result['reason'] = 'tx_not_found_or_pending';
                $result['retryable'] = true;

                return $result;
            }

            if (($receipt['status'] ?? '') !== '0x1') {
                $result['reason'] = 'tx_reverted';

                return $result;
            }

            $blockNumber = (int) hexdec($receipt['blockNumber'] ?? '0');
            $result['block_number'] = $blockNumber;

            $tx = $this->rpcCall($rpcUrl, 'eth_getTransactionByHash', [$txHash]);
            if ($tx === false) {
                $result['reason'] = 'rpc_request_failed';
                $result['retryable'] = true;

                return $result;
            }
            if (! $tx) {
                $result['reason'] = 'tx_detail_not_found';
                $result['retryable'] = true;

                return $result;
            }

            $from = strtolower($tx['from'] ?? '');
            $to = strtolower($tx['to'] ?? '');
            $result['from'] = $from;
            $result['to'] = $to;

            // ผู้จ่ายต้องเป็นกระเป๋าที่ผูกกับคำสั่งซื้อ — กันเอา tx ของคนอื่นมาเคลม
            if ($from !== strtolower($fromWallet)) {
                $result['reason'] = 'from_address_mismatch';

                return $result;
            }

            /*
             * บล็อกต้องนิ่งพอก่อนถือว่าเงินเข้าจริง
             * ยืนยันที่ 0 confirmation แล้วเจอ reorg = ออกเหรียญทั้งที่เงินไม่เคยเข้า
             */
            $headHex = $this->rpcCall($rpcUrl, 'eth_blockNumber', []);
            if ($headHex === false || ! is_string($headHex)) {
                $result['reason'] = 'rpc_request_failed';
                $result['retryable'] = true;

                return $result;
            }
            $confirmations = max(0, ((int) hexdec($headHex)) - $blockNumber + 1);
            $result['confirmations'] = $confirmations;
            if ($confirmations < $minConfirmations) {
                $result['reason'] = 'insufficient_confirmations';
                $result['retryable'] = true;

                return $result;
            }

            /*
             * ธุรกรรมต้องไม่เก่าเกินกำหนด
             * เราตีราคา ณ เวลาที่ยื่น ถ้ารับ tx เก่าได้ ผู้ยื่นจะรอจังหวะที่ราคาเหรียญ
             * ที่จ่ายพุ่งขึ้น แล้วเอา tx ตอนราคาถูกมาขึ้นเงิน ส่วนต่างตกเป็นของผู้ยื่นฟรี
             */
            $block = $this->rpcCall($rpcUrl, 'eth_getBlockByNumber', ['0x'.dechex($blockNumber), false]);
            if (is_array($block) && isset($block['timestamp'])) {
                $blockTime = (int) hexdec($block['timestamp']);
                $result['block_timestamp'] = $blockTime;
                if ($maxAgeMinutes > 0 && (time() - $blockTime) / 60 > $maxAgeMinutes) {
                    $result['reason'] = 'tx_too_old';

                    return $result;
                }
            }

            $saleWalletLower = strtolower($saleWallet);

            // ── ช่องทางที่ 1: โอน BNB ตรงเข้ากระเป๋าขาย ────────────────────────
            if ($to === $saleWalletLower) {
                // tx โอน native พิสูจน์ได้แค่ว่าจ่าย BNB — อ้างว่าเป็น USDT ไม่ได้
                if ($claimedCurrency !== 'BNB') {
                    $result['reason'] = 'currency_mismatch_native';

                    return $result;
                }

                $paid = (float) bcdiv(Wei::hexToInt($tx['value'] ?? '0x0'), '1000000000000000000', 18);
                if ($paid <= 0) {
                    $result['reason'] = 'zero_value_transfer';

                    return $result;
                }

                $result['currency'] = 'BNB';
                $result['amount'] = $paid;
                $result['value'] = $paid;
                $result['verified'] = true;
                $result['reason'] = 'verified_native_transfer';

                return $result;
            }

            /*
             * ── ช่องทางที่ 2: โอนเหรียญ ERC-20 ────────────────────────────────
             * นับเฉพาะ event ที่ปล่อยจาก "สัญญาในรายการที่รับ" เท่านั้น
             * ดูแค่ลายเซ็น Transfer ไม่พอ เพราะเหรียญที่ใครก็ deploy เองได้
             * ก็ปล่อย event หน้าตาเดียวกันเป๊ะ ด้วยต้นทุนหลักสิบบาท
             */
            $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
            $paidBySymbol = [];
            $sawForeignToken = false;

            foreach ($receipt['logs'] ?? [] as $log) {
                if (($log['topics'][0] ?? '') !== $transferTopic) {
                    continue;
                }

                $logAddress = strtolower($log['address'] ?? '');
                $logFrom = strtolower('0x'.substr($log['topics'][1] ?? '', 26));
                $logTo = strtolower('0x'.substr($log['topics'][2] ?? '', 26));

                if ($logTo !== $saleWalletLower) {
                    continue;
                }

                // เข้ากระเป๋าขายจริง แต่เป็นเหรียญที่ไม่รับ → จำไว้เพื่อบอกเหตุผลให้ชัด
                if (! isset($tokenByAddress[$logAddress])) {
                    $sawForeignToken = true;

                    continue;
                }

                // ผู้โอนต้องเป็นกระเป๋าเดียวกับที่ผูกคำสั่งซื้อ
                if ($logFrom !== strtolower($fromWallet)) {
                    continue;
                }

                $meta = $tokenByAddress[$logAddress];
                $divisor = bcpow('10', (string) $meta['decimals']);
                $amount = (float) bcdiv(Wei::hexToInt($log['data'] ?? '0x0'), $divisor, 18);

                $paidBySymbol[$meta['symbol']] = ($paidBySymbol[$meta['symbol']] ?? 0.0) + $amount;
            }

            if (empty($paidBySymbol)) {
                $result['reason'] = $sawForeignToken ? 'unsupported_token' : 'recipient_mismatch';

                return $result;
            }

            // สกุลที่อ้างต้องตรงกับเหรียญที่โอนเข้ามาจริง
            if (($paidBySymbol[$claimedCurrency] ?? 0) <= 0) {
                $result['reason'] = 'currency_mismatch_erc20';

                return $result;
            }

            $result['currency'] = $claimedCurrency;
            $result['amount'] = $paidBySymbol[$claimedCurrency];
            $result['value'] = $result['amount'];
            $result['verified'] = true;
            $result['reason'] = 'verified_erc20_transfer';

            return $result;
        } catch (\Exception $e) {
            Log::warning('BSC verification error', ['tx' => $txHash, 'error' => $e->getMessage()]);
            $result['reason'] = 'verification_error';
            $result['retryable'] = true;

            return $result;
        }
    }

    /**
     * เรียก JSON-RPC หนึ่งครั้ง.
     *
     * คืน false เมื่อ "ต่อไม่ติด/ตอบไม่สำเร็จ" ซึ่งเป็นความล้มเหลวชั่วคราว
     * แยกจาก null ที่แปลว่าเชนตอบมาแล้วว่าไม่มีข้อมูลนั้นจริง
     *
     * @return mixed|false
     */
    private function rpcCall(string $rpcUrl, string $method, array $params)
    {
        $response = Http::timeout(10)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);

        if (! $response->successful()) {
            return false;
        }

        return $response->json('result');
    }
}
