<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — การลงโทษของบอทดูแลห้อง (หรือที่จะลงโทษ ในโหมดแจ้งเตือนอย่างเดียว).
 *
 * Developed by Xman Studio.
 */
class DiscordModAction extends Model
{
    protected $fillable = ['user_id', 'action', 'mode', 'score', 'status', 'reason', 'error'];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }
}
