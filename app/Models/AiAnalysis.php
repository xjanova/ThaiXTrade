<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AiAnalysis Model.
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include completed analyses.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to get recent analyses (last N days).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
