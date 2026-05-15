<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * NodeHeartbeatRequest — validate payload สำหรับ POST /api/v1/node/heartbeat
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
        ];
    }

    public function messages(): array
    {
        return [
            'wallet.regex' => 'wallet must be a valid 0x-prefixed Ethereum address',
            'delegate_address.regex' => 'delegate_address must be a valid 0x-prefixed Ethereum address',
            'delegation_signature.regex' => 'delegation_signature must be 0x + 130 hex chars',
            'signature.regex' => 'signature must be 0x + 130 hex chars',
            'delegation_expires_at.min' => 'delegation already expired',
            'delegation_expires_at.max' => 'delegation lifetime exceeds maximum allowed',
        ];
    }
}
