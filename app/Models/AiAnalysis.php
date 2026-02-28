<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiAnalysis Model
 *
 * Represents an AI-powered market analysis performed via Groq API.
 * Stores analysis results including prompts, responses, and performance metrics.
 *
 * @property int $id
 * @property string $type
 * @property string|null $symbol
 * @property string|null $chain
 * @property string $prompt
 * @property string $response
 * @property string $model
 * @property int|null $tokens_used
 * @property int|null $processing_time_ms
 * @property string $status
 * @property array|null $metadata
 * @property string|null $error_message
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read AdminUser|null $creator
 */
class AiAnalysis extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_analyses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'symbol',
        'chain',
        'prompt',
        'response',
        'model',
        'tokens_used',
        'processing_time_ms',
        'status',
        'metadata',
        'error_message',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'tokens_used' => 'integer',
            'processing_time_ms' => 'integer',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the admin user who created this analysis.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to filter by analysis type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include completed analyses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to get recent analyses (last N days).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
