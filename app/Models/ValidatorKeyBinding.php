<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ValidatorKeyBinding — กระเป๋าที่ stake ชั้น Validator ↔ กุญแจที่ใช้ปิดบล็อก IBFT.
 *
 * แถว active มีได้หนึ่งแถวต่อกระเป๋า และหนึ่งแถวต่อที่อยู่ validator
 * ผูกใหม่ = แถวเดิมกลายเป็น superseded (เก็บไว้เป็นประวัติ ไม่ลบ)
 *
 * @property int $id
 * @property string $staking_wallet
 * @property string $validator_address
 * @property string $bls_public_key
 * @property string $node_id
 * @property string $message
 * @property string $staking_signature
 * @property string $validator_signature
 * @property int $signed_at
 * @property string $status
 * @property Carbon|null $voted_in_at
 * @property Carbon|null $left_set_at
 */
class ValidatorKeyBinding extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'staking_wallet',
        'validator_address',
        'bls_public_key',
        'node_id',
        'message',
        'staking_signature',
        'validator_signature',
        'signed_at',
        'status',
        'voted_in_at',
        'left_set_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'integer',
            'voted_in_at' => 'datetime',
            'left_set_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
