<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog Model
 *
 * Records administrative actions for accountability and auditing.
 * Automatically captures admin ID, IP address, and user agent from the request.
 *
 * @property int $id
 * @property int|null $admin_id
 * @property string $action
 * @property string|null $model_type
 * @property int|null $model_id
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read AdminUser|null $admin
 */
class AuditLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'admin_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the admin user who performed this action.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Log an administrative action.
     *
     * Automatically captures the authenticated admin's ID,
     * the client IP address, and user agent from the current request.
     *
     * @param string $action Description of the action performed
     * @param \Illuminate\Database\Eloquent\Model|null $model The model that was acted upon
     * @param array|null $oldValues The original values before the change
     * @param array|null $newValues The new values after the change
     * @return static
     */
    public static function log(
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): static {
        return static::create([
            'admin_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
