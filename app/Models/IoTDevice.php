<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IoTDevice extends Model
{
    use HasFactory;

    protected $table = 'iot_devices';

    protected $fillable = [
        'device_id',
        'name',
        'type',
        'wallet_address',
        'owner_address',
        'location',
        'firmware_version',
        'status',
        'config',
        'last_ping_at',
    ];

    protected $casts = [
        'config' => 'array',
        'last_ping_at' => 'datetime',
    ];

    public function traces()
    {
        return $this->hasMany(FoodTrace::class, 'iot_device_id');
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
