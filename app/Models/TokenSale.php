<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TokenSale Model — รอบการขายเหรียญ TPIX (ICO/IDO).
 *
 * แต่ละรอบมีหลาย phase (Private, Pre-Sale, Public)
 * รับชำระบน BSC ด้วย BNB/USDT แล้ว allocate TPIX ให้ผู้ซื้อ
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $total_supply_for_sale
 * @property string $total_sold
 * @property string $total_raised_usd
 * @property array|null $accept_currencies
 * @property int $accept_chain_id
 * @property string|null $sale_wallet_address
 * @property string|null $sale_contract_address
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TokenSale extends Model
{
    use HasFactory, SoftDeletes;

    /** คอลัมน์ slug ยาวได้ 255 ตัว — เหลือที่ไว้ให้เลขต่อท้ายเมื่อชื่อซ้ำ */
    private const SLUG_BASE_LENGTH = 240;

    protected $table = 'token_sales';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'total_supply_for_sale',
        'total_sold',
        'total_raised_usd',
        'accept_currencies',
        'accept_chain_id',
        'sale_wallet_address',
        'sale_contract_address',
        'status',
        'starts_at',
        'ends_at',
        'launched_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_supply_for_sale' => 'decimal:18',
            'total_sold' => 'decimal:18',
            'total_raised_usd' => 'decimal:2',
            'accept_currencies' => 'array',
            'metadata' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'launched_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Boot
    // =========================================================================

    /**
     * ตั้ง slug ตอนสร้างเท่านั้น — รอบที่มีอยู่แล้วต้องคงเดิมแม้เปลี่ยนชื่อ เพราะ URL สาธารณะอาจอ้างถึง.
     */
    protected static function booted(): void
    {
        static::creating(function (TokenSale $sale) {
            if (empty($sale->slug)) {
                $sale->slug = static::generateUniqueSlug($sale->name);
            }
        });
    }

    /**
     * สร้าง slug ไม่ซ้ำจากชื่อ.
     *
     * นับรวมรอบที่ลบแบบ soft delete เพราะ unique index ของคอลัมน์ยังเห็นแถวเหล่านั้น
     */
    protected static function generateUniqueSlug(string $name): string
    {
        // ชื่อภาษาไทยล้วนอาจแปลงแล้วว่าง และ slug ยาวกว่าชื่อได้ ('@' กลายเป็น 'at')
        $base = rtrim(substr(Str::slug($name), 0, self::SLUG_BASE_LENGTH), '-') ?: 'token-sale';
        $slug = $base;
        $counter = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Phase ทั้งหมดในรอบขายนี้.
     */
    public function phases(): HasMany
    {
        return $this->hasMany(SalePhase::class)->orderBy('phase_order');
    }

    /**
     * รายการซื้อทั้งหมดในรอบขายนี้.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SaleTransaction::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * เฉพาะรอบขายที่กำลัง active.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * เฉพาะรอบขายที่กำลังจะมา.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'upcoming');
    }

    // =========================================================================
    // Computed
    // =========================================================================

    /**
     * เปอร์เซ็นต์ที่ขายไปแล้ว.
     */
    public function getPercentSoldAttribute(): float
    {
        if ((float) $this->total_supply_for_sale <= 0) {
            return 0;
        }

        return min(100, round(((float) $this->total_sold / (float) $this->total_supply_for_sale) * 100, 2));
    }

    /**
     * จำนวนที่เหลือให้ซื้อ.
     */
    public function getRemainingAttribute(): float
    {
        return max(0, (float) $this->total_supply_for_sale - (float) $this->total_sold);
    }
}
