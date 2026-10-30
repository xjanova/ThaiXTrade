<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Models\TreasuryPayout;
use App\Support\Wei;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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

    /** คำนำหน้า idempotency key ของคิวจ่าย ต่อด้วย uuid ของออเดอร์ แล้วตามด้วย -tge-รอบที่ */
    public const PAYOUT_KEY_PREFIX = 'sale-bank-';

    /** ออเดอร์ล็อกราคาได้นานเท่านี้ — ไม่จองโควตาจึงไม่มีอะไรมาบังคับให้จบเอง */
    private const ORDER_TTL_HOURS = 48;

    public function __construct(
        private readonly TokenSaleService $saleService,
        private readonly TreasuryService $treasury,
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
        $expiresAt = now()->addHours(self::ORDER_TTL_HOURS)->toIso8601String();

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
                'expires_at' => $expiresAt,
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
            'expires_at' => $expiresAt,
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
     * ออเดอร์ที่หมดอายุแล้วและราคาเปลี่ยนไปจะยืนยันไม่ได้ เว้นแต่ทีมงานเห็นว่าเงินเข้าทันเวลาจริง
     * แล้วส่ง $honorExpired ซึ่งถูกบันทึกชื่อคนกดไว้ในออเดอร์
     *
     * @throws PurchaseException
     */
    public function confirm(SaleTransaction $tx, ?string $confirmedBy = null, bool $honorExpired = false): SaleTransaction
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
        $outcome = DB::transaction(function () use ($tx, $confirmedBy, $honorExpired) {
            // ล็อกทั้งรายการและเฟส — กันกดยืนยันซ้อนกันสองหน้าต่าง
            $locked = SaleTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new PurchaseException('This order is no longer pending.');
            }

            $phase = SalePhase::whereKey($locked->sale_phase_id)->lockForUpdate()->firstOrFail();
            $tpixAmount = (float) $locked->tpix_amount;

            if (! $honorExpired && $this->priceLapsed($locked, $phase)) {
                return ['ok' => false, 'reason' => 'price_lapsed'];
            }

            /*
             * โควตาอาจหมดไประหว่างรอโอนเงิน (เพราะเราไม่จองไว้ล่วงหน้าโดยตั้งใจ)
             * กรณีนี้ห้ามออกเหรียญ ต้องคืนเงิน — ทำเครื่องหมายไว้ให้ชัด
             * เพดานต่อกระเป๋าก็เหมือนกัน: ออเดอร์ที่ค้างอยู่ผ่านด่านตอนสั่งซื้อได้ทุกใบ
             */
            $refusal = match (true) {
                $tpixAmount > $phase->remaining_allocation => 'insufficient_allocation',
                $this->saleService->exceedsWalletCap($phase, $locked->wallet_address, $tpixAmount) => 'wallet_cap_exceeded',
                default => null,
            };

            if ($refusal !== null) {
                $locked->update([
                    'status' => 'failed',
                    'metadata' => array_merge($locked->metadata ?? [], [
                        'needs_refund' => true,
                        'reason' => $refusal,
                        'failed_at' => now()->toIso8601String(),
                    ]),
                ]);

                // คืนค่าออกไปเฉยๆ เพื่อให้เครื่องหมายด้านบน commit จริง
                return ['ok' => false, 'reason' => $refusal, 'remaining' => (float) $phase->remaining_allocation];
            }

            $sale = TokenSale::find($locked->token_sale_id);

            $locked->update([
                'status' => 'confirmed',
                'vesting_start_at' => $phase->ends_at ?? $sale?->ends_at ?? now(),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'confirmed_by' => $confirmedBy,
                    'confirmed_at' => now()->toIso8601String(),
                ], $honorExpired ? ['honored_expired_by' => $confirmedBy] : []),
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
            throw new PurchaseException(match ($outcome['reason']) {
                'price_lapsed' => 'ออเดอร์นี้หมดอายุแล้วและราคาเปลี่ยนไปแล้ว — ถ้าเงินเข้าทันเวลาจริงให้กดยืนยันแบบรับราคาเดิม ไม่อย่างนั้นปฏิเสธแล้วให้ผู้ซื้อสั่งใหม่',
                'wallet_cap_exceeded' => 'ยอดนี้เกินเพดานของกระเป๋าในเฟสนี้ — รายการถูกทำเครื่องหมายว่าต้องคืนเงินแล้ว',
                default => 'โควตาของเฟสนี้ไม่พอแล้ว (เหลือ '.number_format($outcome['remaining'], 2)
                    .' TPIX) — รายการถูกทำเครื่องหมายว่าต้องคืนเงินแล้ว',
            });
        }

        // ขายหมดขั้นนี้แล้ว → เปิดขั้นราคาถัดไปทันที (หลัง commit เท่านั้น — ดู rollOverIfExhausted)
        if ($outcome['tx']->phase) {
            $this->saleService->rollOverIfExhausted($outcome['tx']->phase);
        }

        return $outcome['tx'];
    }

    /**
     * ออเดอร์หมดอายุและราคาที่ล็อกไว้ไม่ใช่ราคาปัจจุบันแล้ว.
     *
     * ออเดอร์เปิดฟรีและไม่จองโควตา ถ้าไม่มีวันหมดอายุ ใครก็เปิดทิ้งไว้ตอนราคาต่ำ
     * แล้วค่อยโอนหลังราคาขึ้น หมดอายุแล้วแต่ราคายังเท่าเดิมยังยืนยันได้เพราะไม่มีใครได้เปรียบ
     */
    private function priceLapsed(SaleTransaction $order, SalePhase $phase): bool
    {
        $expiresAt = isset($order->metadata['expires_at'])
            ? Carbon::parse($order->metadata['expires_at'])
            : $order->created_at->copy()->addHours(self::ORDER_TTL_HOURS);

        if ($expiresAt->isFuture()) {
            return false;
        }

        return $phase->status !== 'active'
            || ($phase->ends_at !== null && $phase->ends_at->isPast())
            || bccomp((string) $phase->price_usd, (string) $order->price_per_tpix, 8) !== 0;
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
     *
     * ═══ ออเดอร์หนึ่งมีรายการจ่ายครั้งแรกที่ยังไม่จบได้แถวเดียว (ออดิท 2026-10-29 C6) ═══
     * เดิมคีย์กันซ้ำมียอด claimable ปนอยู่ และไม่แตะ claimed_amount ผลคือ
     *   - vesting ปลดเพิ่มหรือผู้ซื้อ claim → ยอดเปลี่ยน → คีย์เปลี่ยน → ได้แถวที่สองจากเหรียญก้อนเดิม
     *   - ยอดที่เข้าคิวแล้วยังนับเป็น claimable → /token-sale/claim จ่ายยอดเดิมอีกรอบ
     *
     * ตอนนี้ยอดที่เข้าคิวถูกบวก claimed_amount ในทรานแซกชันเดียวกับการสร้างแถว
     * ส่วนที่ปลดเพิ่มทีหลังเป็นสิทธิ์ claim ของผู้ซื้อ ไม่ใช่ของทางนี้
     *
     * กดซ้ำหลังคิวล้มกลางทาง: ทรานแซกชันม้วนกลับหมด เริ่มใหม่ได้เลย
     * กดซ้ำหลังแถวเดิมจบโดยเงินไม่ออก: ได้แถวใหม่ยอดเดิม เพราะยอดนั้นยังกันอยู่ใน claimed_amount
     */
    public function queueInitialPayout(SaleTransaction $tx, ?int $requestedBy = null): ?TreasuryPayout
    {
        return DB::transaction(function () use ($tx, $requestedBy) {
            // ล็อกแถวเดียวกับ /token-sale/claim — สองทางหักสิทธิ์จากยอดเดียวกัน ต้องเข้าทีละทาง
            $locked = SaleTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            if (! $this->isPayableOrder($locked)) {
                return null;
            }

            $payouts = $this->initialPayouts($locked);

            $open = $this->openPayout($payouts);
            if ($open) {
                return $open;
            }

            $amountWei = $locked->metadata['initial_payout_wei'] ?? null;

            if ($amountWei === null) {
                $amountWei = $this->initialPayoutWei($locked);

                if (bccomp($amountWei, '0', 0) <= 0) {
                    return null;
                }

                $locked->update([
                    'claimed_amount' => bcadd((string) $locked->claimed_amount, Wei::format($amountWei), 18),
                    'metadata' => array_merge($locked->metadata ?? [], ['initial_payout_wei' => $amountWei]),
                ]);
            }

            return TreasuryPayout::create([
                'idempotency_key' => self::PAYOUT_KEY_PREFIX.$locked->uuid.'-tge-'.($payouts->count() + 1),
                'to_address' => strtolower($locked->wallet_address),
                'amount_wei' => $amountWei,
                'purpose' => 'token_sale',
                'origin' => TreasuryPayout::ORIGIN_SALE_BANK,
                'memo' => 'จ่ายส่วนที่ปลดล็อกแล้วจากการโอนเงิน '.($locked->metadata['reference'] ?? $locked->uuid),
                'status' => TreasuryPayout::STATUS_PENDING,
                'requested_by' => $requestedBy,
            ]);
        });
    }

    /**
     * ยอดที่จะเข้าคิวถ้าแอดมินกดเข้าคิวจ่ายใหม่ตอนนี้ (wei) — null = ไม่มีอะไรให้กด.
     *
     * หน้าแอดมินใช้แสดงปุ่มและบอกยอดก่อนกดเท่านั้น การเข้าคิวจริงตัดสินใหม่ใต้ล็อกใน queueInitialPayout
     */
    public function requeueableAmountWei(SaleTransaction $tx): ?string
    {
        if (! $this->isPayableOrder($tx) || $this->openPayout($this->initialPayouts($tx))) {
            return null;
        }

        $amountWei = $tx->metadata['initial_payout_wei'] ?? $this->initialPayoutWei($tx);

        return bccomp($amountWei, '0', 0) > 0 ? $amountWei : null;
    }

    /** route เข้าคิวจ่ายใหม่รับ id ใดก็ได้ — ออเดอร์บัตรที่ยืนยันแล้วต้องไม่ผ่านด่านนี้ */
    private function isPayableOrder(SaleTransaction $tx): bool
    {
        return $tx->status === 'confirmed' && ($tx->metadata['method'] ?? null) === 'bank_transfer';
    }

    /** @return Collection<int, TreasuryPayout> */
    private function initialPayouts(SaleTransaction $tx): Collection
    {
        return TreasuryPayout::where('idempotency_key', 'like', self::PAYOUT_KEY_PREFIX.$tx->uuid.'-%')
            ->orderBy('id')
            ->get();
    }

    /**
     * แถวที่อาจจ่ายออกอยู่ — นับว่าจบเฉพาะเมื่อพิสูจน์ได้ว่าเงินไม่ออก.
     *
     * failed ที่มี hash แต่ไม่มีใบเสร็จอาจโอนไปแล้ว จึงยังนับว่าไม่จบ
     *
     * @param  Collection<int, TreasuryPayout>  $payouts
     */
    private function openPayout(Collection $payouts): ?TreasuryPayout
    {
        return $payouts->first(fn (TreasuryPayout $p) => ! $p->endedWithoutPayment());
    }

    /**
     * ยอดจ่ายครั้งแรกเป็น wei — ส่วนที่ปลดล็อกแล้ว แต่ไม่เกินวงเงินต่อครั้งของปลายทางนี้.
     *
     * ยอดเกินวงเงินคือรายการที่ validatePayout ไม่มีวันผ่าน และเมื่อหักสิทธิ์ claim ไปแล้ว
     * ผู้ซื้อจะติดอยู่ตรงนั้น จึงจ่ายเต็มวงเงิน ส่วนที่เหลือคงเป็นสิทธิ์ให้แบ่ง claim เอง
     */
    private function initialPayoutWei(SaleTransaction $tx): string
    {
        $claimableWei = Wei::toWei(number_format((float) $tx->claimable_amount, 18, '.', ''));
        $maxPerTxWei = $this->treasury->maxPerTxWei($tx->wallet_address);

        return bccomp($maxPerTxWei, '0', 0) > 0 && bccomp($claimableWei, $maxPerTxWei, 0) > 0
            ? $maxPerTxWei
            : $claimableWei;
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
