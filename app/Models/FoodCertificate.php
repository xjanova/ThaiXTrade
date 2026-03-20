<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoodCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'food_product_id',
        'token_id',
        'owner_address',
        'contract_address',
        'token_uri',
        'tx_hash',
        'qr_code_url',
        'certificate_data',
        'status',
    ];

    protected $casts = [
        'certificate_data' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(FoodProduct::class, 'food_product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByOwner($query, string $address)
    {
        return $query->where('owner_address', strtolower($address));
    }
}
