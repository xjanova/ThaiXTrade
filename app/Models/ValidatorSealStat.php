<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ValidatorSealStat — ผลการเฝ้าดูการปิดบล็อกของ validator หนึ่งตัว (validators:seal-monitor).
 *
 * @property int $id
 * @property string $address
 * @property string|null $bls_public_key
 * @property bool $in_set
 * @property int|null $set_index
 * @property int|null $first_seen_block
 * @property int|null $last_proposed_block
 * @property int|null $last_signed_block
 * @property int $window_blocks
 * @property int $window_signed
 * @property int $window_proposed
 * @property int $window_missed_turns
 * @property int|null $scanned_to_block
 */
class ValidatorSealStat extends Model
{
    protected $fillable = [
        'address',
        'bls_public_key',
        'in_set',
        'set_index',
        'first_seen_block',
        'last_proposed_block',
        'last_signed_block',
        'window_blocks',
        'window_signed',
        'window_proposed',
        'window_missed_turns',
        'scanned_to_block',
    ];

    protected function casts(): array
    {
        return [
            'in_set' => 'boolean',
            'set_index' => 'integer',
            'first_seen_block' => 'integer',
            'last_proposed_block' => 'integer',
            'last_signed_block' => 'integer',
            'window_blocks' => 'integer',
            'window_signed' => 'integer',
            'window_proposed' => 'integer',
            'window_missed_turns' => 'integer',
            'scanned_to_block' => 'integer',
        ];
    }

    /** % ของบล็อกในหน้าต่างที่ตัวนี้เซ็นรับรอง — null ถ้ายังไม่มีข้อมูล */
    public function signRate(): ?float
    {
        return $this->window_blocks > 0 ? round($this->window_signed * 100 / $this->window_blocks, 1) : null;
    }
}
