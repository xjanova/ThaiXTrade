<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoodProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'chain_product_id',
        'name',
        'category',
        'origin',
        'producer_address',
        'producer_name',
        'batch_number',
        'description',
        'image_url',
        'weight_kg',
        'harvest_date',
        'expiry_date',
        'tx_hash',
        'status',
        'metadata',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
        'harvest_date' => 'date',
        'expiry_date' => 'date',
        'metadata' => 'array',
    ];

    public function traces()
    {
        return $this->hasMany(FoodTrace::class)->orderBy('recorded_at');
    }

    public function certificate()
    {
        return $this->hasOne(FoodCertificate::class);
    }

    public function scopeByProducer($query, string $address)
    {
        return $query->where('producer_address', strtolower($address));
    }

    public function scopeCertified($query)
    {
        return $query->where('status', 'certified');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function getLatestStageAttribute(): ?string
    {
        return $this->traces()->latest('recorded_at')->value('stage');
    }

    public function getTraceCountAttribute(): int
    {
        return $this->traces()->count();
    }
}
