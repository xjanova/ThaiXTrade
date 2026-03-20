<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CarbonProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'location',
        'country',
        'project_type',
        'standard',
        'registry_id',
        'image_url',
        'total_credits',
        'available_credits',
        'retired_credits',
        'price_per_credit_usd',
        'price_per_credit_tpix',
        'vintage_year',
        'status',
        'is_featured',
        'metadata',
    ];

    protected $casts = [
        'total_credits' => 'decimal:2',
        'available_credits' => 'decimal:2',
        'retired_credits' => 'decimal:2',
        'price_per_credit_usd' => 'decimal:2',
        'price_per_credit_tpix' => 'decimal:4',
        'is_featured' => 'boolean',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $project) {
            if (empty($project->slug)) {
                $project->slug = Str::slug($project->name);
            }
        });
    }

    public function credits()
    {
        return $this->hasMany(CarbonCredit::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }
}
