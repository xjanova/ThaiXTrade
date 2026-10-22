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

    /*
     * hash ของ API key ห้ามออกไปไหน — อุปกรณ์ถูกโหลดมากับ trace ในหน้า /verify สาธารณะด้วย
     * ไม่อยู่ใน $fillable เช่นกัน ตั้งได้ทางเดียวคือ IoTService::issueApiKey()
     */
    protected $hidden = [
        'api_key_hash',
    ];

    /** ให้หน้าเว็บรู้ว่าเครื่องไหนยังไม่มีกุญแจ (ลงทะเบียนก่อนมีระบบกุญแจ) โดยไม่ต้องเห็น hash */
    protected $appends = [
        'has_api_key',
    ];

    public function getHasApiKeyAttribute(): bool
    {
        return $this->api_key_hash !== null;
    }

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
