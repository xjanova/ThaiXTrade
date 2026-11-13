<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — ประวัติการเปลี่ยนระดับของมาสเตอร์ (หนึ่งแถวต่อการเปลี่ยนหนึ่งครั้ง).
 *
 * Developed by Xman Studio.
 */
class AiCopyRankHistory extends Model
{
    protected $table = 'ai_copy_rank_history';

    protected $fillable = ['ai_copy_master_id', 'from_tier', 'to_tier', 'score', 'components', 'reason', 'changed_at'];

    protected function casts(): array
    {
        return [
            'from_tier' => 'integer',
            'to_tier' => 'integer',
            'score' => 'float',
            'components' => 'array',
            'changed_at' => 'datetime',
        ];
    }
}
