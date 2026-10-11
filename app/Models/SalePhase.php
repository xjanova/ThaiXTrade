<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * SalePhase Model — phase ของการขายเหรียญ.
 *
 * เช่น Private Sale ($0.05), Pre-Sale ($0.08), Public Sale ($0.10)
 * แต่ละ phase มี allocation, ราคา, และ vesting schedule แยกกัน
 *
 * @property int $id
 * @property int $token_sale_id
 * @property string $name
 * @property string $slug
 * @property int $phase_order
 * @property int|null $duration_days
 * @property string $price_usd
 * @property string $allocation
 * @property string $sold
 * @property string $min_purchase
 * @property string $max_purchase
 * @property int $vesting_cliff_days
 * @property int $vesting_duration_days
 * @property string $vesting_tge_percent
 * @property bool $whitelist_only
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class SalePhase extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sale_phases';

    protected $fillable = [
        'token_sale_id',
        'name',
        'slug',
        'phase_order',
        'duration_days',
        'price_usd',
        'allocation',
        'sold',
        'min_purchase',
        'max_purchase',
        'vesting_cliff_days',
        'vesting_duration_days',
        'vesting_tge_percent',
        'whitelist_only',
        'status',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:8',
            'allocation' => 'decimal:18',
            'sold' => 'decimal:18',
            'min_purchase' => 'decimal:18',
            'max_purchase' => 'decimal:18',
            'vesting_tge_percent' => 'decimal:2',
            'whitelist_only' => 'boolean',
            'metadata' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * รอบขายที่ phase นี้สังกัดอยู่.
     */
    public function tokenSale(): BelongsTo
    {
        return $this->belongsTo(TokenSale::class);
    }

    /**
     * รายการซื้อทั้งหมดใน phase นี้.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SaleTransaction::class);
    }

    /**
     * รายชื่อ whitelist ใน phase นี้.
     */
    public function whitelistEntries(): HasMany
    {
        return $this->hasMany(WhitelistEntry::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // =========================================================================
    // Computed
    // =========================================================================

    /**
     * เปอร์เซ็นต์ที่ขายไปแล้วใน phase นี้.
     */
    public function getPercentSoldAttribute(): float
    {
        if ((float) $this->allocation <= 0) {
            return 0;
        }

        return min(100, round(((float) $this->sold / (float) $this->allocation) * 100, 2));
    }

    /**
     * จำนวนที่เหลือใน phase นี้.
     */
    public function getRemainingAllocationAttribute(): float
    {
        return max(0, (float) $this->allocation - (float) $this->sold);
    }

    /**
     * ตรวจสอบว่า phase นี้ขายหมดแล้วหรือยัง.
     */
    public function getIsSoldOutAttribute(): bool
    {
        return $this->remaining_allocation <= 0;
    }

    /**
     * โควตาที่เหลือ "ขายต่อไม่ได้แล้ว" หรือยัง.
     *
     * ต่างจาก is_sold_out ตรงที่นับเศษที่ต่ำกว่ายอดซื้อขั้นต่ำว่าหมดด้วย
     * เพราะในรอบขายแบบขั้นบันได ขั้นถัดไปจะเปิดก็ต่อเมื่อขั้นนี้หมด —
     * ถ้าเหลือ 30 TPIX แต่ซื้อขั้นต่ำ 100 TPIX จะไม่มีใครซื้อเศษนั้นได้เลย
     * แล้วรอบขายจะค้างอยู่ที่ขั้นนี้ไปจนวันจบรอบ
     */
    public function isExhausted(): bool
    {
        $remaining = $this->remaining_allocation;

        return $remaining <= 0
            || ((float) $this->min_purchase > 0 && $remaining < (float) $this->min_purchase);
    }
}
