<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * AiNews Model.
 *
 * Represents an AI-generated news article for the TPIX TRADE platform.
 * Supports multi-language content, publishing workflow, and soft deletion.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $content
 * @property string|null $summary
 * @property string $category
 * @property string $language_code
 * @property string|null $source_prompt
 * @property string|null $ai_model
 * @property string|null $featured_image
 * @property array|null $tags
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int $views
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read AdminUser|null $creator
 */
class AiNews extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_news';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'slug',
        'content',
        'summary',
        'category',
        'language_code',
        'source_prompt',
        'ai_model',
        'featured_image',
        'tags',
        'status',
        'published_at',
        'views',
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
            'tags' => 'array',
            'published_at' => 'datetime',
            'views' => 'integer',
        ];
    }

    // =========================================================================
    // Boot
    // =========================================================================

    /**
     * The "booted" method of the model.
     * Auto-generates a unique slug from the title when creating a new news article.
     */
    protected static function booted(): void
    {
        static::creating(function (AiNews $news) {
            if (empty($news->slug)) {
                $news->slug = static::generateUniqueSlug($news->title);
            }
        });
    }

    /**
     * Generate a unique slug from the given title.
     */
    protected static function generateUniqueSlug(string $title): string
    {
        $slug = Str::slug($title);

        // If slug is empty (e.g. Thai-only title), use a timestamp-based slug
        if (empty($slug)) {
            $slug = 'news-'.now()->format('Ymd-His');
        }

        $originalSlug = $slug;
        $counter = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the admin user who created this news article.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include published articles.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')->whereNotNull('published_at');
    }

    /**
     * Scope a query to filter by category.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to filter by language code.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLanguage($query, string $languageCode)
    {
        return $query->where('language_code', $languageCode);
    }
}
