<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AdminNotification Model.
 *
 * Internal notification system for the admin panel.
 *
 * @property int $id
 * @property int $admin_user_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property array|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminNotification extends Model
{
    protected $fillable = [
        'admin_user_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForAdmin($query, int $adminId)
    {
        return $query->where('admin_user_id', $adminId);
    }

    // =========================================================================
    // Methods
    // =========================================================================

    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }

    public static function markAllAsRead(int $adminId): int
    {
        return static::forAdmin($adminId)
            ->unread()
            ->update(['read_at' => now()]);
    }
}
