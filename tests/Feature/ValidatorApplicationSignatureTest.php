<?php

namespace Tests\Feature;

use App\Models\ValidatorApplication;
use Elliptic\EC;
use kornrunner\Keccak;
use Tests\TestCase;

/**
 * ใบสมัคร validator ต้องเซ็นด้วยกระเป๋าที่สมัคร.
 *
 * เดิม POST /api/v1/validators/apply รับแค่ wallet_address เป็นข้อความ ใครก็สมัครในนาม
 * กระเป๋าของ validator ตัวจริงได้ แล้วพอแอดมินกดอนุมัติ ประเทศที่กรอกเองก็ไปทับตำแหน่งบนแผนที่
 */
class ValidatorApplicationSignatureTest extends TestCase
{
    /** บัญชีทดสอบมาตรฐานของ ganache/hardhat — ไม่มีเงินจริง */
    private const PRIV_A = '0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d';

    private const ADDR_A = '0x90f8bf6a479f320ead074411a4b0e7944ea8c9c1';

    private const PRIV_B = '0x6cbed15c793ce57650b9877cf6fa156fbef513c4e6134f022a85b1ffdd59b2a1';

    public function test_challenge_message_names_the_wallet_tier_and_endpoint(): void
    {
        // กระเป๋าส่ง checksum (ตัวพิมพ์ปนกัน) มาได้ — ข้อความต้องใช้ตัวเล็กเสมอ
        $challenge = $this->challenge('0x'.strtoupper(substr(self::ADDR_A, 2)), 'guardian', 'https://node.example:8545');

        $this->assertStringContainsString('Wallet: '.self::ADDR_A, $challenge['message']);
        $this->assertStringContainsString('Tier: guardian', $challenge['message']);
        $this->assertStringContainsString('Endpoint: https://node.example:8545', $challenge['message']);
        $this->assertStringContainsString('Nonce: '.$challenge['nonce'], $challenge['message']);
    }

    public function test_application_without_a_signature_is_rejected(): void
    {
        $payload = $this->application();
        unset($payload['nonce'], $payload['signature']);

        $this->postJson('/api/v1/validators/apply', $payload)->assertStatus(422);

        $this->assertDatabaseCount('validator_applications', 0);
    }

    public function test_application_signed_by_another_wallet_is_rejected(): void
    {
        // ผู้โจมตีขอข้อความในนามกระเป๋า A แต่เซ็นด้วยกุญแจของตัวเอง (B)
        $challenge = $this->challenge(self::ADDR_A, 'validator');

        $this->postJson('/api/v1/validators/apply', $this->application([
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_B),
        ]))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertDatabaseCount('validator_applications', 0);
    }

    public function test_signed_application_is_stored_without_coordinates(): void
    {
        $challenge = $this->challenge(self::ADDR_A, 'validator');

        $this->postJson('/api/v1/validators/apply', $this->application([
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_A),
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $app = ValidatorApplication::firstOrFail();
        $this->assertSame(self::ADDR_A, $app->wallet_address);
        $this->assertSame('TH', $app->country_code);
        $this->assertNull($app->latitude);
        $this->assertNull($app->longitude);
    }

    public function test_a_signature_cannot_be_replayed(): void
    {
        $challenge = $this->challenge(self::ADDR_A, 'validator');
        $payload = $this->application([
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_A),
        ]);

        $this->postJson('/api/v1/validators/apply', $payload)->assertStatus(201);
        ValidatorApplication::query()->delete();

        $this->postJson('/api/v1/validators/apply', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_NONCE');

        $this->assertDatabaseCount('validator_applications', 0);
    }

    public function test_signature_is_bound_to_the_tier_it_was_signed_for(): void
    {
        $challenge = $this->challenge(self::ADDR_A, 'light');

        $this->postJson('/api/v1/validators/apply', $this->application([
            'tier' => 'validator',
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_A),
        ]))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $challenge = $this->challenge(self::ADDR_A, 'validator');
        $this->travel(11)->minutes();

        $this->postJson('/api/v1/validators/apply', $this->application([
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_A),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_NONCE');
    }

    public function test_browsing_the_public_api_does_not_use_up_the_application_quota(): void
    {
        // เดิม throttle:5,60 แบบไม่มีชื่อนับรวมกับ API สาธารณะทุกตัว — เปิดหน้าอื่นมาไม่กี่ครั้งก็ 429
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/validators/map-config')->assertOk();
        }

        $challenge = $this->challenge(self::ADDR_A, 'validator');

        $this->postJson('/api/v1/validators/apply', $this->application([
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_A),
        ]))->assertStatus(201);
    }

    public function test_previously_rejected_wallet_gets_a_clear_conflict_not_a_server_error(): void
    {
        ValidatorApplication::create([
            'wallet_address' => self::ADDR_A,
            'tier' => 'light',
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'status' => 'rejected',
        ]);

        $challenge = $this->challenge(self::ADDR_A, 'validator');

        $this->postJson('/api/v1/validators/apply', $this->application([
            'nonce' => $challenge['nonce'],
            'signature' => $this->sign($challenge['message'], self::PRIV_A),
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPLICATION_REJECTED');
    }

    // =========================================================================

    private function challenge(string $wallet, string $tier, ?string $endpoint = null): array
    {
        return $this->postJson('/api/v1/validators/apply/challenge', [
            'wallet_address' => $wallet,
            'tier' => $tier,
            'endpoint' => $endpoint,
        ])->assertOk()->json('data');
    }

    private function application(array $overrides = []): array
    {
        return array_merge([
            'wallet_address' => self::ADDR_A,
            'tier' => 'validator',
            'endpoint' => null,
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'contact_email' => 'ops@example.com',
            'nonce' => str_repeat('a', 32),
            'signature' => '0x'.str_repeat('1', 130),
        ], $overrides);
    }

    /** EIP-191 personal_sign — แบบเดียวกับที่ MetaMask / ethers signMessage ทำ */
    private function sign(string $message, string $privateKey): string
    {
        $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);

        $key = (new EC('secp256k1'))->keyFromPrivate(substr($privateKey, 2));
        $sig = $key->sign($hash, ['canonical' => true]);

        $r = str_pad($sig->r->toString('hex'), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString('hex'), 64, '0', STR_PAD_LEFT);

        return '0x'.$r.$s.sprintf('%02x', 27 + ($sig->recoveryParam ?? 0));
    }
}
