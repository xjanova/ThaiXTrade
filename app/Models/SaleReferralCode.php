<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReferralCode extends Model
{
    protected $fillable = [
        'code',
        'owner_address',
        'bonus_percent',
        'referrer_reward_percent',
        'uses',
        'max_uses',
        'is_active',
    ];

    protected $casts = [
        'bonus_percent' => 'decimal:2',
        'referrer_reward_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->max_uses && $this->uses >= $this->max_uses) {
            return false;
        }

        return true;
    }
}
