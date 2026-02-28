<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Language Model
 *
 * Represents a supported language in the platform.
 * Provides static helpers for retrieving default and active languages.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $native_name
 * @property string|null $flag_emoji
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Translation> $translations
 */
class Language extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'languages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'flag_emoji',
        'is_active',
        'is_default',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the translations for this language.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active languages.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Get the default language.
     *
     * @return static|null
     */
    public static function getDefault(): ?static
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Get all active languages, ordered by sort_order.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActive(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->orderBy('sort_order')->get();
    }
}
