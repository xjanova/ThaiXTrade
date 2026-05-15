<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Masternode Allowlist — operator ที่ผ่านการ verify แล้วได้ allowlist IP บน Cloudflare
 *
 * Lifecycle:
 *   1. masternode-ui ส่ง POST /api/v1/node/heartbeat → controller verify → insert/update row + add CF rule
 *   2. Operator renew ทุก 30 นาที (heartbeat_count++)
 *   3. Cron `masternode:cleanup` ทำงานทุก 5 นาที → entry ที่ allowed_until < now() → delete CF rule + mark expired
 *
 * Developed by Xman Studio
 */
class MasternodeAllowlist extends Model
{
    use HasFactory;

    protected $table = 'masternode_allowlist';

    protected $fillable = [
        'wallet_address',
        'delegate_address',
        'delegation_signature',
        'delegation_expires_at',
        'ip_address',
        'tier',
        'cf_rule_id',
        'allowed_until',
        'last_heartbeat',
        'last_signed_timestamp',
        'heartbeat_count',
        'status',
        'notes',
    ];

    protected $casts = [
        'delegation_expires_at' => 'datetime',
        'allowed_until' => 'datetime',
        'last_heartbeat' => 'datetime',
        'last_signed_timestamp' => 'integer',
        'heartbeat_count' => 'integer',
    ];

    protected $hidden = [
        'delegation_signature', // ไม่ leak ออก JSON response
    ];

    /**
     * Scope: เฉพาะ entry ที่ยัง active
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('allowed_until', '>', now());
    }

    /**
     * Scope: เฉพาะ entry ที่หมดอายุแล้ว (รอ cleanup)
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'active')
            ->where('allowed_until', '<=', now());
    }

    /**
     * Helper: เช็คว่า delegation ยังไม่หมดอายุ
     */
    public function delegationValid(): bool
    {
        return $this->delegation_expires_at > now();
    }

    /**
     * Mask sensitive fields for admin UI display
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'wallet' => $this->wallet_address,
            'delegate' => $this->delegate_address,
            'ip' => $this->ip_address,
            'tier' => $this->tier,
            'allowed_until' => $this->allowed_until,
            'last_heartbeat' => $this->last_heartbeat,
            'heartbeat_count' => $this->heartbeat_count,
            'status' => $this->status,
            'cf_rule_id' => $this->cf_rule_id ? substr($this->cf_rule_id, 0, 8).'…' : null,
            'notes' => $this->notes,
        ];
    }
}
