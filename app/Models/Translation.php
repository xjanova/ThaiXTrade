<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Translation Model
 *
 * Stores key-value translation strings organized by language and group.
 * Provides a static helper with caching for efficient retrieval.
 *
 * @property int $id
 * @property int $language_id
 * @property string $group
 * @property string $key
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Language $language
 */
class Translation extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'translations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'language_id',
        'group',
        'key',
        'value',
    ];

    /**
     * The cache TTL in seconds (1 hour).
     *
     * @var int
     */
    protected static int $cacheTtl = 3600;

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the language this translation belongs to.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Get a translation value by language code, group, and key.
     *
     * @param string $languageCode
     * @param string $group
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    public static function get(string $languageCode, string $group, string $key, ?string $default = null): ?string
    {
        $cacheKey = "translations.{$languageCode}.{$group}.{$key}";

        return Cache::remember($cacheKey, static::$cacheTtl, function () use ($languageCode, $group, $key, $default) {
            $translation = static::whereHas('language', function ($query) use ($languageCode) {
                $query->where('code', $languageCode);
            })
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            return $translation?->value ?? $default;
        });
    }
}
