<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ปลายทางที่อนุญาตให้คลังโอนไปได้.
 *
 * ที่อยู่เก็บเป็นตัวพิมพ์เล็กเสมอ (normalize ตอน set) เพราะ EVM address
 * เทียบแบบ case-insensitive แต่ `unique` ของฐานข้อมูลไม่รู้เรื่องนั้น
 * ถ้าไม่ normalize จะเพิ่มที่อยู่เดิมซ้ำได้ด้วยการเปลี่ยนตัวพิมพ์
 */
class TreasuryWhitelist extends Model
{
    use HasFactory;

    protected $table = 'treasury_whitelist';

    protected $fillable = [
        'address',
        'label',
        'note',
        'purpose',
        'max_per_tx_wei',
        'max_per_day_wei',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function setAddressAttribute(?string $value): void
    {
        $this->attributes['address'] = strtolower(trim((string) $value));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** หาปลายทางที่ active ตามที่อยู่ (ไม่สนตัวพิมพ์) */
    public static function findActiveByAddress(string $address): ?self
    {
        return static::active()
            ->where('address', strtolower(trim($address)))
            ->first();
    }
}
