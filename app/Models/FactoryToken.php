<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FactoryToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'symbol',
        'decimals',
        'total_supply',
        'creator_address',
        'contract_address',
        'tx_hash',
        'chain_id',
        'logo_url',
        'description',
        'website',
        'token_type',
        'token_category',
        'status',
        'reject_reason',
        'is_verified',
        'is_listed',
        'metadata',
    ];

    protected $casts = [
        'total_supply' => 'string',
        'is_verified' => 'boolean',
        'is_listed' => 'boolean',
        'metadata' => 'array',
    ];

    public function chain()
    {
        return $this->belongsTo(Chain::class, 'chain_id', 'chain_id');
    }

    public function scopeDeployed($query)
    {
        return $query->where('status', 'deployed');
    }

    public function scopeByCreator($query, string $address)
    {
        return $query->where('creator_address', strtolower($address));
    }
}
