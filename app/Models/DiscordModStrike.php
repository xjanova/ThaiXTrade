<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — ความผิดหนึ่งครั้งที่ AutoMod ของ Discord บล็อกไว้ (อ่านจาก audit log).
 *
 * Developed by Xman Studio.
 */
class DiscordModStrike extends Model
{
    protected $fillable = ['audit_id', 'user_id', 'rule_name', 'weight', 'channel_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'weight' => 'integer'];
    }
}
