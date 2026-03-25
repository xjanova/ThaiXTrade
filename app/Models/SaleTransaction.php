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
     * จำนวน TPIX ที่ปลดล็อคแล้ว (claimable) ตาม vesting schedule.
     */
    public function getClaimableAmountAttribute(): float
    {
        $phase = $this->phase;
        if (! $phase) {
            return (float) $this->tpix_amount;
        }

        // ถ้าไม่มี vesting (TGE 100%) → ได้ทั้งหมดทันที
        if ((float) $phase->vesting_tge_percent >= 100 || $phase->vesting_duration_days <= 0) {
            return (float) $this->tpix_amount;
        }

        $totalTpix = (float) $this->tpix_amount;
        $tgeAmount = $totalTpix * ((float) $phase->vesting_tge_percent / 100);
        $vestingAmount = $totalTpix - $tgeAmount;

        // ถ้ายังไม่ถึง vesting start
        $vestingStart = $this->vesting_start_at ?? $this->created_at;
        if (! $vestingStart) {
            return $tgeAmount;
        }

        $now = now();
        $cliffEnd = $vestingStart->copy()->addDays($phase->vesting_cliff_days);

        // ยังอยู่ใน cliff period → ได้แค่ TGE
        if ($now->lt($cliffEnd)) {
            return $tgeAmount;
        }

        // คำนวณสัดส่วน vesting ที่ปลดล็อคแล้ว
        // diffInDays ต้องเรียกจาก cliffEnd เพื่อให้ได้จำนวนวันที่ผ่านไปหลัง cliff
        $daysSinceCliff = $cliffEnd->diffInDays($now);
        // vesting_duration_days คือระยะเวลา linear vesting หลังจาก cliff (ไม่รวม cliff)
        $vestingDays = max(1, $phase->vesting_duration_days);
        $vestedRatio = min(1.0, $daysSinceCliff / $vestingDays);

        $totalClaimable = $tgeAmount + ($vestingAmount * $vestedRatio);

        return max(0, $totalClaimable - (float) $this->claimed_amount);
    }
}
