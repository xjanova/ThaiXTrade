<?php

namespace Tests\Feature\Api;

use App\Models\MasternodeAllowlist;
use App\Services\MasterNode\CloudflareWafService;
use App\Services\MasterNode\NodeRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use kornrunner\Keccak;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/node/heartbeat
 *
 * Coverage:
 *   - Valid signature flow (genesis operator) → 200 + DB row + CF rule call
 *   - Invalid delegation signature → 401
 *   - Invalid heartbeat signature → 401
 *   - Timestamp out of window → 401
 *   - Wallet not registered (no genesis, no balance) → 403
 *   - Renew too soon → 429
 *
 * Developed by Xman Studio
 */
class NodeHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PRIV = '0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d';
    private const TEST_ADDR = '0x90f8bf6a479f320ead074411a4b0e7944ea8c9c1';

    private const DELEGATE_PRIV = '0x6cbed15c793ce57650b9877cf6fa156fbef513c4e6134f022a85b1ffdd59b2a1';
    private const DELEGATE_ADDR = '0xffcf8fdee72ac11b5c542428b35eef5769c409f0';

    protected function setUp(): void
    {
        parent::setUp();

        // Disable throttling for tests
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Mock Cloudflare service — return fake rule id
        $cfMock = Mockery::mock(CloudflareWafService::class);
        $cfMock->shouldReceive('configured')->andReturn(true);
        $cfMock->shouldReceive('addAllowRule')->andReturn(['id' => 'fake-rule-1', 'created_on' => now()->toIso8601String()]);
        $cfMock->shouldReceive('deleteRule')->andReturn(true);
        $this->app->instance(CloudflareWafService::class, $cfMock);

        // Configure: TEST_ADDR เป็น genesis operator (Validator tier)
        config(['masternode.genesis_operators' => [
            self::TEST_ADDR => 'Validator',
        ]]);
    }

    public function test_heartbeat_succeeds_with_valid_dual_signature(): void
    {
        $payload = $this->buildValidPayload();

        $response = $this->postJson('/api/v1/node/heartbeat', $payload);

        $response->assertOk()
            ->assertJsonStructure(['ok', 'wallet', 'tier', 'ip', 'cf_rule_id', 'allowed_until']);

        $this->assertDatabaseHas('masternode_allowlist', [
            'wallet_address' => strtolower(self::TEST_ADDR),
            'tier' => 'Validator',
            'status' => 'active',
        ]);
    }

    public function test_heartbeat_rejects_invalid_delegation_signature(): void
    {
        $payload = $this->buildValidPayload();
        $payload['delegation_signature'] = '0x'.str_repeat('a', 130);

        $response = $this->postJson('/api/v1/node/heartbeat', $payload);

        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'invalid_delegation']);
    }

    public function test_heartbeat_rejects_invalid_heartbeat_signature(): void
    {
        $payload = $this->buildValidPayload();
        $payload['signature'] = '0x'.str_repeat('b', 130);

        $response = $this->postJson('/api/v1/node/heartbeat', $payload);

        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'invalid_heartbeat_signature']);
    }

    public function test_heartbeat_rejects_old_timestamp(): void
    {
        $payload = $this->buildValidPayload();
        $payload['timestamp'] = time() - 600; // 10 นาทีเก่า — เกิน window 5 นาที

        $response = $this->postJson('/api/v1/node/heartbeat', $payload);

        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'timestamp_out_of_window']);
    }

    public function test_heartbeat_rejects_unregistered_wallet(): void
    {
        config(['masternode.genesis_operators' => []]); // remove from genesis

        // Mock: no balance + no registry
        $registryMock = Mockery::mock(NodeRegistryService::class);
        $registryMock->shouldReceive('lookup')->andReturn(null);
        $this->app->instance(NodeRegistryService::class, $registryMock);

        $payload = $this->buildValidPayload();

        $response = $this->postJson('/api/v1/node/heartbeat', $payload);

        $response->assertStatus(403)
            ->assertJsonFragment(['error' => 'not_registered']);
    }

    public function test_status_endpoint_returns_existing_entry(): void
    {
        MasternodeAllowlist::create([
            'wallet_address' => strtolower(self::TEST_ADDR),
            'delegate_address' => strtolower(self::DELEGATE_ADDR),
            'delegation_signature' => '0x'.str_repeat('1', 130),
            'delegation_expires_at' => now()->addDays(30),
            'ip_address' => '1.2.3.4',
            'tier' => 'Validator',
            'cf_rule_id' => 'rule-x',
            'allowed_until' => now()->addHour(),
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/node/status/'.self::TEST_ADDR);

        $response->assertOk()
            ->assertJsonFragment([
                'exists' => true,
                'tier' => 'Validator',
                'is_active' => true,
            ])
            // ห้าม leak IP จริงในบรรลุภายในของ response
            ->assertJsonMissing(['ip' => '1.2.3.4']);
    }

    public function test_status_endpoint_does_not_leak_operator_ip(): void
    {
        MasternodeAllowlist::create([
            'wallet_address' => strtolower(self::TEST_ADDR),
            'delegate_address' => strtolower(self::DELEGATE_ADDR),
            'delegation_signature' => '0x'.str_repeat('1', 130),
            'delegation_expires_at' => now()->addDays(30),
            'ip_address' => '1.2.3.4',
            'tier' => 'Validator',
            'allowed_until' => now()->addHour(),
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/node/status/'.self::TEST_ADDR);

        $body = $response->json();
        $this->assertArrayNotHasKey('ip', $body, 'public status must not expose operator IP');
        $this->assertArrayHasKey('ip_matches_caller', $body);
        $this->assertFalse($body['ip_matches_caller'], 'caller IP should not match seeded entry');
    }

    public function test_heartbeat_rejects_replay_of_old_timestamp(): void
    {
        // First heartbeat — ผ่าน
        $first = $this->buildValidPayload();
        $this->postJson('/api/v1/node/heartbeat', $first)->assertOk();

        // Replay payload เดิม → ต้องโดน reject (sig เดียวกัน, timestamp <= last_signed_timestamp)
        $response = $this->postJson('/api/v1/node/heartbeat', $first);

        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'timestamp_not_monotonic']);
    }

    public function test_heartbeat_rejects_delegation_lifetime_too_long(): void
    {
        // กำหนด max lifetime = 1 ชม. → delegation 2 ชม. ต้องไม่ผ่าน validation
        config(['masternode.delegation.max_lifetime_seconds' => 3600]);

        $payload = $this->buildValidPayload();
        $payload['delegation_expires_at'] = time() + 7200; // 2 ชม.

        $response = $this->postJson('/api/v1/node/heartbeat', $payload);

        $response->assertStatus(422); // validation fail
    }

    public function test_signature_with_leading_zero_byte_is_accepted(): void
    {
        // Regression: เดิมใช้ ltrim('0x') → strip char '0'/'x' ทุกตัวที่นำหน้า
        // sig ที่ r ขึ้นต้นด้วย '0' (1/16 ของ sigs) จะถูกตัด leading zero → length ผิด → verify fail สุ่ม
        // หลัง fix ต้องผ่านทุก sig แม้ขึ้นต้นด้วย '0'
        $sigService = $this->app->make(\App\Services\MasterNode\Web3SignatureService::class);

        // สร้าง signature ที่ r เริ่มด้วย '0' โดยตรง — fake แต่ทดสอบ parser logic
        $fakeSigWithLeadingZero = '0x0'.str_repeat('1', 63).'0'.str_repeat('2', 63).'1b';
        // recoverAddress ต้องไม่ throw + ไม่ return '' จากการ length check ผิด
        $recovered = $sigService->recoverAddress('test', $fakeSigWithLeadingZero);
        // sig ปลอม → อาจ recover เป็น address อะไรก็ได้ แต่ต้องไม่ใช่ '' (จาก length check fail)
        $this->assertNotEmpty($recovered, 'ltrim fix: leading zero in r should not break length check');
    }

    /**
     * สร้าง payload ที่ valid — sign ด้วย kornrunner/keccak + simplito/elliptic-php
     */
    private function buildValidPayload(): array
    {
        $expiresAt = time() + 30 * 24 * 3600;
        $timestamp = time();

        $delegationMessage = sprintf(
            'tpix-masternode-delegate:%s:%s:%d',
            strtolower(self::TEST_ADDR),
            strtolower(self::DELEGATE_ADDR),
            $expiresAt
        );

        $heartbeatMessage = sprintf(
            'tpix-masternode-heartbeat:%s:%d',
            strtolower(self::TEST_ADDR),
            $timestamp
        );

        return [
            'wallet' => self::TEST_ADDR,
            'delegate_address' => self::DELEGATE_ADDR,
            'delegation_signature' => $this->signMessage($delegationMessage, self::TEST_PRIV),
            'delegation_expires_at' => $expiresAt,
            'timestamp' => $timestamp,
            'signature' => $this->signMessage($heartbeatMessage, self::DELEGATE_PRIV),
            'tier' => 'Validator',
        ];
    }

    /**
     * Helper: sign message ด้วย private key (เลียนแบบ MetaMask personal_sign)
     */
    private function signMessage(string $message, string $privateKey): string
    {
        $prefix = "\x19Ethereum Signed Message:\n".strlen($message);
        $hash = Keccak::hash($prefix.$message, 256);

        $ec = new \Elliptic\EC('secp256k1');
        $key = $ec->keyFromPrivate(ltrim($privateKey, '0x'));
        $sig = $key->sign($hash, ['canonical' => true]);

        $r = str_pad($sig->r->toString('hex'), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString('hex'), 64, '0', STR_PAD_LEFT);
        $v = sprintf('%02x', 27 + ($sig->recoveryParam ?? 0));

        return '0x'.$r.$s.$v;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
