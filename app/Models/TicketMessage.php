<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TicketMessage Model.
 *
 * Represents a message within a support ticket conversation.
 * Supports both user and admin messages, with optional internal notes.
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $sender_type
 * @property int|null $admin_id
 * @property string $message
 * @property array|null $attachments
 * @property bool $is_internal
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read SupportTicket $ticket
 * @property-read AdminUser|null $admin
 */
class TicketMessage extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ticket_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_id',
        'sender_type',
        'admin_id',
        'message',
        'attachments',
        'is_internal',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_internal' => 'boolean',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the support ticket this message belongs to.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * Get the admin who sent this message (if sent by an admin).
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
