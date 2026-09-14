<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TPIX TRADE — ข้อความที่บอท Discord โพสต์ไปแล้ว (ดู migration create_discord_posts_table).
 *
 * @property int $id
 * @property string $kind
 * @property string $ref_key
 * @property string $channel_id
 * @property string|null $message_id
 * @property string|null $content_hash
 * @property string|null $last_error
 * @property Carbon|null $posted_at
 *
 * Developed by Xman Studio.
 */
class DiscordPost extends Model
{
    protected $fillable = [
        'kind',
        'ref_key',
        'channel_id',
        'message_id',
        'content_hash',
        'last_error',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }
}
