<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — Article Model
 * บทความ AI-generated หรือ manual สำหรับ content marketing.
 */
class Article extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'slug', 'summary', 'content', 'cover_image',
        'language', 'category', 'tags', 'status', 'published_at',
        'scheduled_at', 'is_ai_generated', 'ai_model', 'ai_tokens_used',
        'ai_image_prompt', 'views', 'likes', 'seo_title', 'seo_description',
        'author_name',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_ai_generated' => 'boolean',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $article) {
            if (empty($article->slug)) {
                $article->slug = Str::slug($article->title).'-'.Str::random(6);
            }
        });
    }

    // === Scopes ===

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where('published_at', '<=', now());
    }

    public function scopeByLanguage($query, string $lang)
    {
        return $query->where('language', $lang);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeScheduledReady($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now());
    }

    // === Helpers ===

    public function incrementViews(): void
    {
        $this->increment('views');
    }

    public function getReadTimeAttribute(): int
    {
        $wordCount = str_word_count(strip_tags($this->content));

        return max(1, (int) ceil($wordCount / 200));
    }
}
