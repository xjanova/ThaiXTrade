<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — Wallet Connection Model
 * บันทึกประวัติการเชื่อมต่อ Wallet ของผู้ใช้
 * Developed by Xman Studio.
 */
class WalletConnection extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_address',
        'chain_id',
        'wallet_type',
        'is_primary',
        'connected_at',
        'disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'is_primary' => 'boolean',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
