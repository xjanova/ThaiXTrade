<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoodTrace extends Model
{
    use HasFactory;

    protected $fillable = [
        'food_product_id',
        'chain_trace_id',
        'iot_device_id',
        'recorder_address',
        'stage',
        'location',
        'temperature',
        'humidity',
        'weight_kg',
        'ph_level',
        'sensor_data',
        'image_url',
        'notes',
        'tx_hash',
        'recorded_at',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
        'humidity' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'ph_level' => 'decimal:2',
        'sensor_data' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(FoodProduct::class, 'food_product_id');
    }

    public function device()
    {
        return $this->belongsTo(IoTDevice::class, 'iot_device_id');
    }

    public function scopeByStage($query, string $stage)
    {
        return $query->where('stage', $stage);
    }
}
