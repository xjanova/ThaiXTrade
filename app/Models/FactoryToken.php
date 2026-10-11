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
        'fee_tx_hash',
        'fee_quote_id',
        'deploy_nonce',
        'deploy_raw_tx',
        'deploy_started_at',
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

    /**
     * ธุรกรรมดิบที่เซ็นแล้ว — ไม่ใช่ความลับ (สุดท้ายก็ขึ้นเชน) แต่ไม่มีใครต้องเห็นนอกงาน deploy
     * active_symbol เป็นคอลัมน์ generated ที่มีไว้ให้ unique index เท่านั้น
     */
    protected $hidden = [
        'deploy_raw_tx',
        'active_symbol',
    ];

    protected $casts = [
        'total_supply' => 'string',
        'is_verified' => 'boolean',
        'is_listed' => 'boolean',
        'metadata' => 'array',
        'deploy_started_at' => 'datetime',
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

    /**
     * ข้อมูลที่เปิดให้คนทั่วไปเห็น.
     *
     * ⚠️ ห้ามคืนทั้งแถวให้คนนอก — metadata มี deploy_error (stderr ของสคริปต์),
     *    ตัวเลือกย่อยทั้งหมด, เหตุผลที่ถูกปฏิเสธ และข้อมูลค่าธรรมเนียม
     *    ส่วนคำขอที่ยังไม่ deploy ไม่ต้องบอกว่าใครเป็นคนขอ
     */
    public function toPublicArray(): array
    {
        $deployed = $this->status === 'deployed';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'total_supply' => $this->total_supply,
            'chain_id' => $this->chain_id,
            'logo_url' => $this->logo_url,
            'description' => $this->description,
            'website' => $this->website,
            'token_type' => $this->token_type,
            'token_category' => $this->token_category,
            'status' => $this->status,
            'is_verified' => (bool) $this->is_verified,
            'is_listed' => (bool) $this->is_listed,
            'contract_address' => $deployed ? $this->contract_address : null,
            'creator_address' => $deployed ? $this->creator_address : null,
            'tx_hash' => $deployed ? $this->tx_hash : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
