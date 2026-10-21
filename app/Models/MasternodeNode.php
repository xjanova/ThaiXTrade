<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MasternodeNode — สถานะล่าสุดของโหนดที่ส่ง heartbeat รุ่น 2 (1 แถวต่อกระเป๋า).
 *
 * ไม่มีคอลัมน์ IP โดยตั้งใจ — มีแค่ hash ไว้รู้ว่า IP เปลี่ยน (ต้องค้น GeoIP ใหม่ไหม)
 * ข้อมูลที่แสดงสาธารณะได้คือประเทศ/เมือง/พิกัดระดับเมืองเท่านั้น (ดู toPublicArray)
 *
 * Developed by Xman Studio
 */
class MasternodeNode extends Model
{
    protected $table = 'masternode_nodes';

    protected $fillable = [
        'wallet_address',
        'peer_id',
        'node_address',
        'delegation_expires_at',
        'tier',
        'app_version',
        'peer_count',
        'first_seen_at',
        'last_seen_at',
        'last_block_number',
        'last_block_lag',
        'last_check',
        'country_code',
        'country_name',
        'city',
        'latitude',
        'longitude',
        'geo_ip_hash',
    ];

    protected $casts = [
        'delegation_expires_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_block_number' => 'integer',
        'last_block_lag' => 'integer',
        'peer_count' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected $hidden = [
        'geo_ip_hash',
    ];

    /**
     * ช่องที่เปิดเผยสาธารณะได้ — ไม่มี IP, ไม่มี hash ของ IP, ไม่มี peer ID, ไม่มีกระเป๋า
     *   - peer ID + IP คู่กันคือที่อยู่สำหรับยิงเครื่องผู้ใช้ตรง ๆ
     *   - กระเป๋า + เมือง = รู้ว่าคนที่วางค้ำ 10M TPIX อยู่เมืองไหน
     * id เป็น HMAC ของกระเป๋าด้วย app key — คงที่ให้หน้าจอใช้อ้างอิง แต่ย้อนกลับเป็นกระเป๋าไม่ได้.
     */
    public function toPublicArray(?int $uptime24hBps): array
    {
        return [
            'id' => substr(hash_hmac('sha256', (string) $this->wallet_address, (string) config('app.key')), 0, 16),
            'tier' => $this->tier,
            'country_code' => $this->country_code,
            'country' => $this->country_name,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'last_seen_at' => $this->last_seen_at?->timestamp,
            'synced' => $this->last_check === 'ok',
            'uptime_24h_bps' => $uptime24hBps,
        ];
    }
}
