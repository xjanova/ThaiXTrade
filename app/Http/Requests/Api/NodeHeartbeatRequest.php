<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * NodeHeartbeatRequest — validate payload สำหรับ POST /api/v1/node/heartbeat.
 *
 * Payload format:
 * {
 *   "wallet": "0xABC...",
 *   "delegate_address": "0x123...",
 *   "delegation_signature": "0x...",
 *   "delegation_expires_at": 1781200000,
 *   "timestamp": 1778500000,
 *   "signature": "0x...",            // signed by delegate-key
 *   "tier": "Validator"               // optional — server จะ verify กับ NodeRegistry
 * }
 *
 * รุ่น 2 (แอปมาสเตอร์โหนดที่รันโหนดจริง — ใช้วัด uptime) เพิ่ม:
 *   "peer_id": "16Uiu2HAm...",        // libp2p peer ID ของโหนด — delegate key คือกุญแจของ peer ID นี้
 *   "block_number": 2752952,          // บล็อกล่าสุดที่โหนดในเครื่องเห็น
 *   "block_hash": "0x...",            // แฮชของบล็อกนั้น
 *   "app_version": "1.15.0",          // optional
 *   "peer_count": 5                   // optional
 * ไม่มี peer_id = รุ่น 1 (allowlist อย่างเดียว ไม่นับ uptime)
 */
class NodeHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    public function rules(): array
    {
        // Upper bound กัน operator ตั้ง delegation ถึงปี 2099 → bypass max_lifetime_seconds config
        $maxLifetime = (int) config('masternode.delegation.max_lifetime_seconds', 30 * 24 * 3600);
        $maxExpiry = time() + $maxLifetime;

        return [
            'wallet' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'delegate_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'delegation_signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
            'delegation_expires_at' => ['required', 'integer', 'min:'.time(), 'max:'.$maxExpiry],
            'timestamp' => ['required', 'integer'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
            'tier' => ['nullable', 'string', 'in:Validator,Guardian,Sentinel,Light'],
            'peer_id' => ['nullable', 'string', 'regex:/^16Uiu2[1-9A-HJ-NP-Za-km-z]{40,60}$/'],
            'block_number' => ['required_with:peer_id', 'integer', 'min:0'],
            'block_hash' => ['required_with:peer_id', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            'app_version' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z.+-]+$/'],
            'peer_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'wallet.regex' => 'wallet must be a valid 0x-prefixed Ethereum address',
            'delegate_address.regex' => 'delegate_address must be a valid 0x-prefixed Ethereum address',
            'delegation_signature.regex' => 'delegation_signature must be 0x + 130 hex chars',
            'signature.regex' => 'signature must be 0x + 130 hex chars',
            'peer_id.regex' => 'peer_id must be a secp256k1 libp2p peer ID (16Uiu2...)',
            'block_hash.regex' => 'block_hash must be 0x + 64 hex chars',
            'delegation_expires_at.min' => 'delegation already expired',
            'delegation_expires_at.max' => 'delegation lifetime exceeds maximum allowed',
        ];
    }
}
