<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarbonCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'carbon_project_id',
        'serial_number',
        'owner_address',
        'amount',
        'price_paid_usd',
        'payment_currency',
        'payment_amount',
        'tx_hash',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'price_paid_usd' => 'decimal:2',
        'payment_amount' => 'string',
    ];

    public function project()
    {
        return $this->belongsTo(CarbonProject::class, 'carbon_project_id');
    }

    public function retirements()
    {
        return $this->hasMany(CarbonRetirement::class);
    }

    public function scopeByOwner($query, string $address)
    {
        return $query->where('owner_address', strtolower($address));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
