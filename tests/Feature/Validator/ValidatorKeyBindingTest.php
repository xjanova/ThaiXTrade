<?php

namespace Tests\Feature\Validator;

use App\Models\ValidatorKeyBinding;
use App\Services\MasterNode\Web3SignatureService;
use App\Services\Validator\ValidatorKeyBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesValidatorChain;
use Tests\Support\SignsWithTestKeys;
use Tests\TestCase;

/**
 * ผูกกุญแจ validator (ที่อยู่ IBFT + BLS + node ID) กับกระเป๋าที่ stake ชั้น Validator.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * สภาพเดิมที่เทสต์ชุดนี้กันไม่ให้กลับมา
 * ═══════════════════════════════════════════════════════════════════════════
 * - สัญญาไม่มีช่องเก็บกุญแจที่ปิดบล็อก แอปสร้าง validator.key แล้วไม่เคยบอกใคร
 *   → เจ้าของเชนไม่รู้ว่าจะ `ibft propose --addr ? --bls ?` ให้ใคร
 * - ถ้าเชื่อลายเซ็นกระเป๋าใบเดียว ใครก็เอาที่อยู่ validator หลักมาผูกกับ stake ตัวเอง
 *   วันถอน stake → ขั้นตอนถอดออกจากชุดจะถอด validator หลักทิ้ง
 */
class ValidatorKeyBindingTest extends TestCase
{
    use FakesValidatorChain;
    use RefreshDatabase;
    use SignsWithTestKeys;

    private const BLS = '0x8f5c6db7b7f32fb48d9f3b1e86eec5b3821ada3ca35e110aa258c1aa2e55c3fc11f7fd1b48027240667e9c8e806c18d5';

    private const NODE_ID = '16Uiu2HAmSmFLK2z9HhZifQ3xaZmbZkZbT3hcEodRvVdX9tNnB8xv';

    private string $walletKey;

    private string $validatorKey;

    private string $wallet;

    private string $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletKey = $this->testKey(1);
        $this->validatorKey = $this->testKey(2);
        $this->wallet = $this->addressOf($this->walletKey);
        $this->validator = $this->addressOf($this->validatorKey);

        $this->registerValidatorContracts();
        $this->registryNode($this->wallet);
        // ชุดปัจจุบัน = validator หลักอื่น 4 ตัว (ไม่ใช่กุญแจของผู้สมัคร)
        $this->chainWithValidators([$this->testKey(10), $this->testKey(11), $this->testKey(12), $this->testKey(13)]);
        $this->fakeValidatorChain();
    }

    /** payload แบบที่แอปส่ง — เซ็นข้อความเดียวกันด้วยสองกุญแจ */
    private function payload(array $override = [], ?string $walletKey = null, ?string $validatorKey = null): array
    {
        $walletKey ??= $this->walletKey;
        $validatorKey ??= $this->validatorKey;
        $fields = array_merge([
            'chain_id' => 4289,
            'staking_wallet' => $this->addressOf($walletKey),
            'validator_address' => $this->addressOf($validatorKey),
            'bls_public_key' => self::BLS,
            'node_id' => self::NODE_ID,
            'signed_at' => time(),
        ], $override);
        $message = ValidatorKeyBindingService::message($fields);

        return $fields + [
            'staking_signature' => $this->personalSign($walletKey, $message),
            'validator_signature' => $this->personalSign($validatorKey, $message),
        ];
    }

    private function bind(array $payload)
    {
        return $this->postJson('/api/v1/validators/key-binding', $payload);
    }

    public function test_the_message_is_exactly_what_the_desktop_app_signs(): void
    {
        // ต้องตรงกับ TPIX-Masternode test/validator-binding.test.js ทุกตัวอักษร
        $this->assertSame(
            "TPIX Chain validator key binding\n"
            ."chain_id: 4289\n"
            ."staking_wallet: 0xabcdef0000000000000000000000000000000001\n"
            ."validator_address: 0x00affa6379fbd969cbe5906cdeee793960a500da\n"
            .'bls_public_key: '.self::BLS."\n"
            .'node_id: '.self::NODE_ID."\n"
            .'signed_at: 1761123456',
            ValidatorKeyBindingService::message([
                'chain_id' => 4289,
                'staking_wallet' => '0xABCDEF0000000000000000000000000000000001',
                'validator_address' => '0x00AFfa6379fBd969CBe5906cDeee793960a500DA',
                'bls_public_key' => strtoupper(self::BLS),
                'node_id' => self::NODE_ID,
                'signed_at' => 1761123456,
            ]),
        );
    }

    public function test_accepts_the_signature_made_by_the_server_script_and_the_app(): void
    {
        // TPIX-Coin validator_sign_binding.py และ ethers ในแอปให้ลายเซ็นนี้ตรงทุกไบต์
        // (กุญแจทดสอบสาธารณะ 0x4c08…2318 → 0x2c75…5c23) — เว็บต้องตรวจผ่าน
        $message = ValidatorKeyBindingService::message([
            'chain_id' => 4289,
            'staking_wallet' => '0xabcdef0000000000000000000000000000000001',
            'validator_address' => '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23',
            'bls_public_key' => '0x'.str_repeat('ab', 48),
            'node_id' => self::NODE_ID,
            'signed_at' => 1761123456,
        ]);

        $this->assertTrue(app(Web3SignatureService::class)->verify(
            $message,
            '0x60d005529293b7c4a129e0f5fbd7e3f7fda1bf0aa75ffb0b4f5dceda70fbe0160facf59bca2d2dae0f6340aa5114400c153c2fc90355af644eb8171828036d181b',
            '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23',
        ));
    }

    public function test_binds_and_reports_awaiting_vote(): void
    {
        $this->bind($this->payload())
            ->assertOk()
            ->assertJsonPath('data.stage', 'awaiting_vote')
            ->assertJsonPath('data.binding.validator_address', $this->validator)
            ->assertJsonPath('data.binding.bls_public_key', self::BLS)
            ->assertJsonPath('data.stake_active', true)
            ->assertJsonPath('data.in_set', false);

        $row = ValidatorKeyBinding::sole();
        $this->assertSame($this->wallet, $row->staking_wallet);
        $this->assertStringContainsString('node_id: '.self::NODE_ID, $row->message);

        $this->getJson('/api/v1/validators/key-binding/'.$this->wallet)
            ->assertOk()->assertJsonPath('data.stage', 'awaiting_vote');
    }

    public function test_status_of_an_unbound_wallet(): void
    {
        $this->getJson('/api/v1/validators/key-binding/0x'.str_repeat('1', 40))
            ->assertOk()->assertJsonPath('data.stage', 'not_bound')->assertJsonPath('data.binding', null);
    }

    public function test_both_signatures_must_be_genuine(): void
    {
        // กระเป๋าเซ็นแต่กุญแจ validator ไม่ได้เซ็น (เอาลายเซ็นกระเป๋ามาใส่แทน)
        $p = $this->payload();
        $p['validator_signature'] = $p['staking_signature'];
        $this->bind($p)->assertStatus(422)->assertJsonPath('error.code', 'BAD_VALIDATOR_SIGNATURE');

        // คนอื่นเซ็นแทนกระเป๋า
        $p = $this->payload();
        $p['staking_signature'] = $this->personalSign($this->testKey(99), ValidatorKeyBindingService::message($p));
        $this->bind($p)->assertJsonPath('error.code', 'BAD_STAKING_SIGNATURE');

        // แก้ BLS หลังเซ็น
        $p = $this->payload();
        $p['bls_public_key'] = '0x'.str_repeat('ab', 48);
        $this->bind($p)->assertJsonPath('error.code', 'BAD_STAKING_SIGNATURE');

        $this->assertSame(0, ValidatorKeyBinding::count());
    }

    public function test_old_or_foreign_chain_signatures_are_refused(): void
    {
        $this->bind($this->payload(['signed_at' => time() - 3600]))->assertJsonPath('error.code', 'STALE_SIGNATURE');
        $this->bind($this->payload(['chain_id' => 1]))->assertJsonPath('error.code', 'CHAIN_MISMATCH');
    }

    public function test_the_staking_wallet_cannot_double_as_the_sealing_key(): void
    {
        $this->bind($this->payload([], $this->walletKey, $this->walletKey))->assertJsonPath('error.code', 'SAME_KEY');
    }

    public function test_needs_an_active_validator_tier_stake(): void
    {
        $this->registryNode($this->wallet, tier: 0); // Guardian
        $this->bind($this->payload())->assertJsonPath('error.code', 'NOT_VALIDATOR_STAKE');

        $this->registryNode($this->wallet, tier: 3, status: 2); // Slashed
        $this->bind($this->payload())->assertJsonPath('error.code', 'NOT_VALIDATOR_STAKE');
    }

    public function test_cannot_claim_a_key_that_already_seals(): void
    {
        // ผู้สมัครถือกุญแจของ validator ตัวหนึ่งในชุด (เช่นเครื่องหลักถูกยึด) — ห้ามผูกกับ stake
        $this->chainWithValidators([$this->validatorKey, $this->testKey(11), $this->testKey(12), $this->testKey(13)]);

        $this->bind($this->payload())->assertJsonPath('error.code', 'ADDRESS_IN_SET');
    }

    public function test_cannot_reuse_the_bls_key_of_a_sealing_validator(): void
    {
        // makeBlock ให้ BLS ของตัวที่ 0 = 0x80 ซ้ำ 48 ไบต์
        $this->bind($this->payload(['bls_public_key' => '0x'.str_repeat('80', 48)]))->assertJsonPath('error.code', 'BLS_IN_SET');
    }

    public function test_one_key_one_wallet(): void
    {
        $this->bind($this->payload())->assertOk();

        $otherWalletKey = $this->testKey(3);
        $this->registryNode($this->addressOf($otherWalletKey));
        $this->bind($this->payload([], $otherWalletKey, $this->validatorKey))->assertJsonPath('error.code', 'KEY_TAKEN');
    }

    public function test_a_replayed_older_signature_does_not_undo_a_newer_binding(): void
    {
        $old = $this->payload(['signed_at' => time() - 60]);
        $this->bind($this->payload())->assertOk();

        $this->bind($old)->assertJsonPath('error.code', 'REPLAYED');
        $this->assertSame(1, ValidatorKeyBinding::count());
    }

    public function test_rebinding_to_a_new_key_supersedes_the_old_one(): void
    {
        $this->bind($this->payload(['signed_at' => time() - 30]))->assertOk();

        $newKey = $this->testKey(4);
        $this->bind($this->payload([], null, $newKey))
            ->assertOk()->assertJsonPath('data.binding.validator_address', $this->addressOf($newKey));

        $this->assertSame(1, ValidatorKeyBinding::active()->count());
        $this->assertSame(ValidatorKeyBinding::STATUS_SUPERSEDED, ValidatorKeyBinding::where('validator_address', $this->validator)->sole()->status);
    }

    public function test_cannot_swap_keys_while_the_old_key_still_seals(): void
    {
        $this->bind($this->payload(['signed_at' => time() - 30]))->assertOk();
        // เจ้าของเชนโหวตกุญแจเดิมเข้าไปแล้ว
        $this->chainWithValidators([$this->validatorKey, $this->testKey(11), $this->testKey(12), $this->testKey(13)], 1001);

        $this->getJson('/api/v1/validators/key-binding/'.$this->wallet)->assertJsonPath('data.stage', 'sealing');
        $this->bind($this->payload([], null, $this->testKey(4)))->assertJsonPath('error.code', 'OLD_KEY_STILL_SEALING');

        // re-sign กุญแจเดิม (เช่นเปลี่ยน node ID) ยังทำได้ ไม่ติดด่าน "อยู่ในชุดแล้ว"
        $this->bind($this->payload(['node_id' => '16Uiu2HAmFZFHnseVxL4Qhp4m1SqjEH7Gck9NLE94MzwfWnY47mLk']))->assertOk();
    }

    public function test_stake_gone_while_sealing_means_drop_needed(): void
    {
        $this->bind($this->payload())->assertOk();
        $this->chainWithValidators([$this->validatorKey, $this->testKey(11), $this->testKey(12), $this->testKey(13)], 1001);
        $this->registryNode($this->wallet, tier: 3, status: 3); // Exited

        $this->getJson('/api/v1/validators/key-binding/'.$this->wallet)->assertJsonPath('data.stage', 'drop_needed');
    }

    public function test_refuses_when_the_validator_set_cannot_be_read(): void
    {
        $this->chainBlocks = [];
        $this->chainHead = null;
        Cache::forget('validators:ibft-set');

        $this->bind($this->payload())->assertStatus(503)->assertJsonPath('error.code', 'CHAIN_UNREACHABLE');
    }

    public function test_rejects_malformed_input_before_touching_the_chain(): void
    {
        $p = $this->payload();
        $p['bls_public_key'] = '0x1234';
        $p['node_id'] = 'not-a-peer-id';
        $this->bind($p)->assertStatus(422)->assertJsonValidationErrors(['bls_public_key', 'node_id']);
    }
}
