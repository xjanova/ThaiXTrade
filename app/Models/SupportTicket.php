<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SupportTicket Model
 *
 * Represents a customer support ticket submitted by a wallet user.
 * Auto-generates a unique ticket number in TIX-YYYYMMDD-XXXX format.
 *
 * @property int $id
 * @property string $ticket_number
 * @property string $wallet_address
 * @property string|null $email
 * @property string $subject
 * @property string $category
 * @property string $priority
 * @property string $status
 * @property int|null $assigned_to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TicketMessage> $messages
 * @property-read AdminUser|null $assignedAdmin
 */
class SupportTicket extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'support_tickets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_number',
        'wallet_address',
        'email',
        'subject',
        'category',
        'priority',
        'status',
        'assigned_to',
    ];

    // =========================================================================
    // Boot
    // =========================================================================

    /**
     * The "booted" method of the model.
     * Auto-generates a ticket number when creating a new support ticket.
     * Format: TIX-YYYYMMDD-XXXX (e.g., TIX-20260228-0001)
     */
    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
        });
    }

    /**
     * Generate a unique ticket number.
     *
     * @return string
     */
    protected static function generateTicketNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = "TIX-{$date}-";

        $lastTicket = static::withTrashed()
            ->where('ticket_number', 'like', "{$prefix}%")
            ->orderByDesc('ticket_number')
            ->first();

        if ($lastTicket) {
            $lastNumber = (int) substr($lastTicket->ticket_number, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the messages associated with this ticket.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    /**
     * Get the admin user assigned to this ticket.
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_to');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include open tickets (not closed or resolved).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['closed', 'resolved']);
    }

    /**
     * Scope a query to filter by ticket status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by ticket priority.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $priority
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }
}
