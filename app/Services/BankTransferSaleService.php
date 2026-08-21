<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Support\Wei;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ขายเหรียญด้วยการโอนเงินเข้าบัญชีธนาคาร แล้วทีมงานยืนยันเอง.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมต้องมีทางนี้
 * ═══════════════════════════════════════════════════════════════════════════
 * เจ้าของกำหนดให้รับเงินเป็น "บัตร/โอนเงิน" แล้วส่งมอบ TPIX บนเชน 4289 เท่านั้น
 * ผลพลอยได้ที่สำคัญ: ไม่ต้องมีกระเป๋ารับคริปโตอีกต่อไป ปัญหา "ยังพิสูจน์ไม่ได้ว่า
 * ถือคีย์กระเป๋ารับเงิน BSC" จึงหายไปทั้งข้อ — เงินเข้าบัญชีธนาคารตรง
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ลำดับที่กันไม่ให้ออกเหรียญฟรี
 * ═══════════════════════════════════════════════════════════════════════════
 *   1. ผู้ซื้อกดสั่งซื้อ → ได้ "รหัสอ้างอิง" + เลขบัญชี → รายการเป็น pending
 *      ยังไม่แตะยอดขาย ยังไม่จองโควตา ยังไม่ออกเหรียญ
 *   2. ผู้ซื้อโอนเงินจริงพร้อมใส่รหัสอ้างอิง
 *   3. **ทีมงานเปิดดูรายการเดินบัญชีเองแล้วกดยืนยัน** ← เงินถึงจะนับ
 *      ตรงนี้เท่านั้นที่ sold เพิ่ม และคิวจ่ายเหรียญถูกสร้าง
 *
 * ที่ไม่จองโควตาตั้งแต่ขั้นที่ 1 เพราะใครก็กดสั่งซื้อได้ไม่จำกัดโดยไม่ต้องจ่ายเงิน
 * ถ้าจองไว้ก่อน คนเดียวสามารถกดจนโควตาเต็มแล้วไม่โอนเงินเลย = ปิดรอบขายได้ฟรี
 * ผลข้างเคียงที่ยอมรับ: ถ้าโควตาหมดระหว่างรอโอน ทีมงานต้องคืนเงิน (มีปุ่มปฏิเสธให้)
 *
 * Developed by Xman Studio.
 */
class BankTransferSaleService
{
    /** คำนำหน้า tx_hash — ใช้เป็นกุญแจกันบันทึกซ้ำเหมือนที่ Stripe ใช้ 'stripe_' */
    private const HASH_PREFIX = 'bank_';

    public function __construct(
        private readonly TokenSaleService $saleService,
    ) {}

    // =========================================================================
    // ข้อมูลบัญชีรับเงิน (ตั้งที่ /admin/settings)
    // =========================================================================

    /**
     * รายละเอียดบัญชีที่ให้ผู้ซื้อโอนเข้า.
     *
     * @return array<string, mixed>
     */
    public function bankDetails(): array
    {
        $accountNo = trim((string) SiteSetting::get('sale', 'bank_account_no', ''));

        return [
            'configured' => $accountNo !== '',
            'bank_name' => (string) SiteSetting::get('sale', 'bank_name', ''),
            'account_name' => (string) SiteSetting::get('sale', 'bank_account_name', ''),
            'account_no' => $accountNo,
            'note' => (string) SiteSetting::get('sale', 'bank_note', ''),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->bankDetails()['configured'];
    }

    // =========================================================================
    // ฝั่งผู้ซื้อ
    // =========================================================================

    /**
     * สร้างคำสั่งซื้อรอโอนเงิน — ยังไม่นับเป็นยอดขาย.
     *
     * @throws PurchaseException
     */
    public function createOrder(string $walletAddress, int $phaseId, float $amountUsd): array
    {
        if (! $this->isConfigured()) {
            throw new PurchaseException('Bank transfer is not available right now. Please try again later.');
        }

        // ด่านเดียวกับทุกทางที่รับเงิน (เฟสเปิด · whitelist · ขั้นต่ำ/เพดาน · โควตา)
        $check = $this->saleService->assertPurchasable($walletAddress, $phaseId, 'USD', $amountUsd);

        $phase = SalePhase::with('tokenSale')->findOrFail($phaseId);

        /*
         * ★ รอบขายนี้ประกาศรับการโอนเงินหรือเปล่า
         *
         * แยกจาก isConfigured() คนละเรื่อง — ตัวนั้นถามว่า "ตั้งค่าครบไหม"
         * ตัวนี้ถามว่า "ตั้งใจเปิดไหม" ถ้าไม่แยกกัน วันที่มีคนเผลอกรอกเลขบัญชี
         * ทางโอนเงินจะเปิดเองเงียบๆ ทั้งที่เจ้าของสั่งให้ใช้ Stripe อย่างเดียว
         */
        $this->saleService->assertCurrencyAccepted($phase->tokenSale, 'BANK');

        /*
         * รหัสอ้างอิงที่ผู้ซื้อต้องใส่ตอนโอน — ต้องอ่านออกและพิมพ์ตามได้ง่าย
         * ใช้ตัวพิมพ์ใหญ่ล้วนและตัดอักษรที่สับสนกับตัวเลขออก (I, O)
         */
        $reference = $this->generateReference();

        $tx = SaleTransaction::create([
            'token_sale_id' => $phase->token_sale_id,
            'sale_phase_id' => $phase->id,
            'wallet_address' => strtolower($walletAddress),
            'payment_currency' => 'USD_BANK',
            'payment_amount' => $amountUsd,
            'payment_usd_value' => $amountUsd,
            'tpix_amount' => $check['tpix_amount'],
            'price_per_tpix' => (float) $phase->price_usd,
            'tx_hash' => self::HASH_PREFIX.$reference,
            'status' => 'pending',
            'metadata' => [
                'method' => 'bank_transfer',
                'reference' => $reference,
                'requested_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('token-sale: สร้างคำสั่งซื้อรอโอนเงิน', [
            'uuid' => $tx->uuid,
            'reference' => $reference,
            'wallet' => $walletAddress,
            'amount_usd' => $amountUsd,
        ]);

        return [
            'transaction_id' => $tx->uuid,
            'reference' => $reference,
            'amount_usd' => $amountUsd,
            'tpix_amount' => (float) $tx->tpix_amount,
            'bank' => $this->bankDetails(),
            'status' => 'pending',
        ];
    }

    // =========================================================================
    // ฝั่งทีมงาน
    // =========================================================================

    /**
     * ยืนยันว่าเงินเข้าบัญชีจริง → นับเป็นยอดขาย + เข้าคิวจ่ายเหรียญ.
     *
     * ⚠️ จุดเดียวในระบบที่ทำให้เหรียญออกจากคำสั่งซื้อทางโอนเงิน
     *    ทีมงานต้องเปิดดูรายการเดินบัญชีจริงก่อนกดเสมอ ระบบตรวจแทนไม่ได้
     *
     * @throws PurchaseException
     */
    public function confirm(SaleTransaction $tx, ?string $confirmedBy = null): SaleTransaction
    {
        if ($tx->status !== 'pending') {
            throw new PurchaseException('This order is no longer pending.');
        }

        if (($tx->metadata['method'] ?? null) !== 'bank_transfer') {
            throw new PurchaseException('This is not a bank transfer order.');
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * ★ ห้ามโยน exception ออกจากใน DB::transaction() เมื่อยังต้องการให้
         *   สิ่งที่เพิ่งเขียนลงไปคงอยู่
         * ═══════════════════════════════════════════════════════════════════
         * เดิมกรณีโควตาไม่พอ เราทำเครื่องหมาย needs_refund แล้วโยน exception ทันที
         * ผลคือ Laravel ม้วนทรานแซกชันกลับ → เครื่องหมายนั้นหายไปด้วย
         * รายการกลับไปเป็น pending เหมือนไม่มีอะไรเกิดขึ้น ทั้งที่ลูกค้าโอนเงินมาแล้ว
         * และไม่มีร่องรอยว่าต้องคืนเงิน — เงินค้างอยู่กับเราโดยไม่มีใครรู้
         *
         * จึงคืนค่าออกมาแบบปกติให้ทรานแซกชัน commit ก่อน แล้วค่อยโยนข้างนอก
         */
        $outcome = DB::transaction(function () use ($tx, $confirmedBy) {
            // ล็อกทั้งรายการและเฟส — กันกดยืนยันซ้อนกันสองหน้าต่าง
            $locked = SaleTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new PurchaseException('This order is no longer pending.');
            }

            $phase = SalePhase::whereKey($locked->sale_phase_id)->lockForUpdate()->firstOrFail();
            $tpixAmount = (float) $locked->tpix_amount;

            /*
             * โควตาอาจหมดไประหว่างรอโอนเงิน (เพราะเราไม่จองไว้ล่วงหน้าโดยตั้งใจ)
             * กรณีนี้ห้ามออกเหรียญ ต้องคืนเงิน — ทำเครื่องหมายไว้ให้ชัด
             */
            if ($tpixAmount > $phase->remaining_allocation) {
                $locked->update([
                    'status' => 'failed',
                    'metadata' => array_merge($locked->metadata ?? [], [
                        'needs_refund' => true,
                        'reason' => 'insufficient_allocation',
                        'failed_at' => now()->toIso8601String(),
                    ]),
                ]);

                // คืนค่าออกไปเฉยๆ เพื่อให้เครื่องหมายด้านบน commit จริง
                return ['ok' => false, 'remaining' => (float) $phase->remaining_allocation];
            }

            $sale = TokenSale::find($locked->token_sale_id);

            $locked->update([
                'status' => 'confirmed',
                'vesting_start_at' => $phase->ends_at ?? $sale?->ends_at ?? now(),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'confirmed_by' => $confirmedBy,
                    'confirmed_at' => now()->toIso8601String(),
                ]),
            ]);

            $phase->increment('sold', $tpixAmount);

            if ($sale) {
                $sale->increment('total_sold', $tpixAmount);
                $sale->increment('total_raised_usd', (float) $locked->payment_usd_value);
            }

            Log::info('token-sale: ยืนยันการโอนเงินแล้ว', [
                'uuid' => $locked->uuid,
                'reference' => $locked->metadata['reference'] ?? null,
                'confirmed_by' => $confirmedBy,
                'tpix' => $tpixAmount,
            ]);

            return ['ok' => true, 'tx' => $locked->refresh()];
        });

        if (! $outcome['ok']) {
            throw new PurchaseException(
                'โควตาของเฟสนี้ไม่พอแล้ว (เหลือ '.number_format($outcome['remaining'], 2)
                .' TPIX) — รายการถูกทำเครื่องหมายว่าต้องคืนเงินแล้ว'
            );
        }

        return $outcome['tx'];
    }

    /**
     * ปฏิเสธคำสั่งซื้อที่ไม่มีเงินเข้า (หรือผู้ซื้อยกเลิก).
     *
     * ใช้สถานะ `failed` ไม่ใช่ `cancelled` — คอลัมน์ status เป็น ENUM ที่มีแค่
     * pending/confirmed/claimed/refunded/failed การเขียนค่านอกชุดจะผ่านบน SQLite
     * ตอนเทสต์แต่ทำให้ MySQL strict ตายกลางคัน (เคยเกิดจริงมาแล้ว)
     */
    public function reject(SaleTransaction $tx, string $reason, ?string $rejectedBy = null): SaleTransaction
    {
        if ($tx->status !== 'pending') {
            throw new PurchaseException('This order is no longer pending.');
        }

        $tx->update([
            'status' => 'failed',
            'metadata' => array_merge($tx->metadata ?? [], [
                'rejected_by' => $rejectedBy,
                'rejected_at' => now()->toIso8601String(),
                'reject_reason' => $reason,
            ]),
        ]);

        return $tx->refresh();
    }

    /**
     * เข้าคิวจ่ายเหรียญส่วนที่ปลดล็อกแล้วให้ผู้ซื้อทันทีหลังยืนยัน.
     *
     * แยกจาก confirm() เพื่อให้การยืนยันยอดขายไม่ล้มเพราะคิวจ่ายมีปัญหา
     * — ยอดขายที่ยืนยันแล้วต้องนิ่ง ส่วนการจ่ายเหรียญกดซ้ำได้ภายหลัง
     */
    public function queueInitialPayout(SaleTransaction $tx): ?TreasuryPayout
    {
        $claimable = (float) $tx->claimable_amount;

        if ($claimable <= 0) {
            return null;
        }

        return TreasuryPayout::firstOrCreate(
            ['idempotency_key' => 'sale-bank-'.$tx->uuid.'-'.$claimable],
            [
                'to_address' => strtolower($tx->wallet_address),
                'amount_wei' => Wei::toWei(number_format($claimable, 18, '.', '')),
                'purpose' => 'token_sale',
                'memo' => 'จ่ายส่วนที่ปลดล็อกแล้วจากการโอนเงิน '.($tx->metadata['reference'] ?? $tx->uuid),
                'status' => TreasuryPayout::STATUS_PENDING,
            ],
        );
    }

    /**
     * รหัสอ้างอิงที่ไม่ซ้ำกับรายการที่ยังมีอยู่.
     */
    private function generateReference(): string
    {
        // ตัด I กับ O ออกเพราะพิมพ์สับสนกับเลข 1 และ 0 ตอนกรอกในแอปธนาคาร
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'TPIX-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (! SaleTransaction::where('tx_hash', self::HASH_PREFIX.$code)->exists()) {
                return $code;
            }
        }

        // แทบเป็นไปไม่ได้ที่จะมาถึงตรงนี้ แต่ต้องไม่คืนรหัสซ้ำเด็ดขาด
        return 'TPIX-'.strtoupper(Str::random(10));
    }
}
