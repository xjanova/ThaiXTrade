<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * SiteSetting Model
 *
 * Stores key-value configuration settings organized by group.
 * Provides static helper methods with caching for efficient retrieval.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SiteSetting extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'site_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
    ];

    /**
     * The cache TTL in seconds (1 hour).
     *
     * @var int
     */
    protected static int $cacheTtl = 3600;

    /**
     * The cache key prefix.
     *
     * @var string
     */
    protected static string $cachePrefix = 'site_settings';

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Get a single setting value by group and key.
     *
     * @param string $group
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $cacheKey = static::$cachePrefix . ".{$group}.{$key}";

        return Cache::remember($cacheKey, static::$cacheTtl, function () use ($group, $key, $default) {
            $setting = static::where('group', $group)
                ->where('key', $key)
                ->first();

            if (! $setting) {
                return $default;
            }

            return static::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value by group and key.
     * Creates the setting if it doesn't exist, updates if it does.
     *
     * @param string $group
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @return static
     */
    public static function set(string $group, string $key, mixed $value, string $type = 'string'): static
    {
        $setting = static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => (string) $value, 'type' => $type]
        );

        // Clear individual cache
        Cache::forget(static::$cachePrefix . ".{$group}.{$key}");

        // Clear group cache
        Cache::forget(static::$cachePrefix . ".group.{$group}");

        return $setting;
    }

    /**
     * Get all settings for a specific group.
     *
     * @param string $group
     * @return \Illuminate\Support\Collection
     */
    public static function getGroup(string $group): \Illuminate\Support\Collection
    {
        $cacheKey = static::$cachePrefix . ".group.{$group}";

        return Cache::remember($cacheKey, static::$cacheTtl, function () use ($group) {
            return static::where('group', $group)
                ->get()
                ->mapWithKeys(function ($setting) {
                    return [$setting->key => static::castValue($setting->value, $setting->type)];
                });
        });
    }

    /**
     * Clear all cached settings.
     */
    public static function clearCache(): void
    {
        $settings = static::all();

        foreach ($settings as $setting) {
            Cache::forget(static::$cachePrefix . ".{$setting->group}.{$setting->key}");
        }

        $groups = $settings->pluck('group')->unique();
        foreach ($groups as $group) {
            Cache::forget(static::$cachePrefix . ".group.{$group}");
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Cast a setting value to its proper type.
     *
     * @param string|null $value
     * @param string $type
     * @return mixed
     */
    protected static function castValue(?string $value, string $type): mixed
    {
        if (is_null($value)) {
            return null;
        }

        return match ($type) {
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'int' => (int) $value,
            'float', 'double' => (float) $value,
            'array', 'json' => json_decode($value, true),
            default => $value,
        };
    }
}
