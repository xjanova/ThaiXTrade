<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SaleTransaction Model — รายการซื้อเหรียญ TPIX.
 *
 * บันทึกทุกรายการซื้อ พร้อม tx_hash จาก BSC เพื่อ verify on-chain
 * รองรับ vesting schedule สำหรับ phase ที่มีการ lock
 *
 * @property int $id
 * @property string $uuid
 * @property int $token_sale_id
 * @property int $sale_phase_id
 * @property string $wallet_address
 * @property string $payment_currency
 * @property string $payment_amount
 * @property string $payment_usd_value
 * @property string $tpix_amount
 * @property string $price_per_tpix
 * @property string|null $tx_hash
 * @property string|null $claim_tx_hash
 * @property string $status
 * @property Carbon|null $vesting_start_at
 * @property string $claimed_amount
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class SaleTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sale_transactions';

    protected $fillable = [
        'uuid',
        'token_sale_id',
        'sale_phase_id',
        'wallet_address',
        'payment_currency',
        'payment_amount',
        'payment_usd_value',
        'tpix_amount',
        'price_per_tpix',
        'tx_hash',
        'claim_tx_hash',
        'status',
        'vesting_start_at',
        'claimed_amount',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:18',
            'payment_usd_value' => 'decimal:2',
            'tpix_amount' => 'decimal:18',
            'price_per_tpix' => 'decimal:8',
            'claimed_amount' => 'decimal:18',
            'metadata' => 'array',
            'vesting_start_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Boot — สร้าง UUID อัตโนมัติเมื่อสร้างรายการใหม่
    // =========================================================================

    protected static function booted(): void
    {
        static::creating(function (SaleTransaction $tx) {
            if (empty($tx->uuid)) {
                $tx->uuid = (string) Str::uuid();
            }
        });
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * บังคับเก็บที่อยู่กระเป๋าเป็นตัวพิมพ์เล็กเสมอ.
     *
     * ทุกที่ที่ "อ่าน" ค้นด้วย strtolower อยู่แล้ว (scopeByWallet, claim)
     * แต่ฝั่ง "เขียน" เคยมีเส้นทางที่ลืมแปลง — เส้นทาง Stripe บันทึก address
     * แบบ checksum (มีพิมพ์ใหญ่ปน ซึ่งเป็นค่าเริ่มต้นของ MetaMask) ทำให้
     * ลูกค้าจ่ายเงินจริงแล้วหารายการของตัวเองไม่เจอและเคลมไม่ได้ตลอดกาล
     *
     * บังคับที่โมเดลจุดเดียว ทุกเส้นทางจึงถูกต้องเหมือนกันหมด
     */
    public function setWalletAddressAttribute(?string $value): void
    {
        $this->attributes['wallet_address'] = $value !== null ? strtolower($value) : null;
    }

    public function tokenSale(): BelongsTo
    {
        return $this->belongsTo(TokenSale::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(SalePhase::class, 'sale_phase_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeByWallet(Builder $query, string $walletAddress): Builder
    {
        return $query->where('wallet_address', strtolower($walletAddress));
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    // =========================================================================
    // Computed — คำนวณ vesting
    // =========================================================================

    /**
     * จำนวน TPIX ที่ยังเคลมได้ตอนนี้ (ปลดล็อคแล้ว − ที่เคลมไปแล้ว).
     *
     * ⚠️ กับดักที่เคยเกิดจริง: เดิมมีทางออก 4 ทางในเมธอดนี้ แต่มีแค่ทางสุดท้าย
     *    ทางเดียวที่หัก claimed_amount อีกสามทาง (ไม่มี phase / TGE 100% /
     *    ยังไม่ถึง vesting start / อยู่ในช่วง cliff) คืนยอดเต็มทุกครั้งที่เรียก
     *    → กด claim ซ้ำได้ไม่จำกัดจากการซื้อครั้งเดียว
     *
     *    วิธีกันไม่ให้พลาดซ้ำ: คำนวณ "ยอดที่ปลดล็อคแล้ว" ให้เสร็จก่อน
     *    แล้วหัก claimed_amount ที่จุดเดียวตอน return — ห้ามมี return กลางทาง
     */
    public function getClaimableAmountAttribute(): float
    {
        $totalTpix = (float) $this->tpix_amount;
        $phase = $this->phase;

        $unlocked = $this->unlockedAmount($phase, $totalTpix);

        return max(0, min($unlocked, $totalTpix) - (float) $this->claimed_amount);
    }

    /**
     * ยอดที่ปลดล็อคแล้วตามตาราง vesting (ยังไม่หักส่วนที่เคลมไปแล้ว).
     */
    private function unlockedAmount(?SalePhase $phase, float $totalTpix): float
    {
        // ไม่มีข้อมูลเฟส → ถือว่าปลดล็อคหมด (ข้อมูลเก่าก่อนมีระบบ vesting)
        if (! $phase) {
            return $totalTpix;
        }

        // ไม่มี vesting (TGE 100% หรือไม่ได้ตั้งระยะเวลา) → ปลดล็อคหมดทันที
        if ((float) $phase->vesting_tge_percent >= 100 || $phase->vesting_duration_days <= 0) {
            return $totalTpix;
        }

        $tgeAmount = $totalTpix * ((float) $phase->vesting_tge_percent / 100);
        $vestingAmount = $totalTpix - $tgeAmount;

        $vestingStart = $this->vesting_start_at ?? $this->created_at;
        if (! $vestingStart) {
            return $tgeAmount;
        }

        $cliffEnd = $vestingStart->copy()->addDays($phase->vesting_cliff_days);

        // ยังอยู่ในช่วง cliff → ปลดล็อคแค่ส่วน TGE
        if (now()->lt($cliffEnd)) {
            return $tgeAmount;
        }

        // หลังพ้น cliff → ทยอยปลดแบบเส้นตรงตามจำนวนวันที่ผ่านไป
        // diffInDays เรียกจาก cliffEnd เพื่อให้ได้จำนวนวันหลัง cliff
        $daysSinceCliff = $cliffEnd->diffInDays(now());
        $vestingDays = max(1, $phase->vesting_duration_days);
        $vestedRatio = min(1.0, $daysSinceCliff / $vestingDays);

        return $tgeAmount + ($vestingAmount * $vestedRatio);
    }
}
