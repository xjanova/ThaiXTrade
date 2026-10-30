<?php

namespace Tests\Feature\Validator;

use App\Models\AdminUser;
use App\Models\ValidatorKeyBinding;
use App\Services\Validator\SealMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakesValidatorChain;
use Tests\TestCase;

/**
 * /admin/validators — ชุดจริง + ตัวเลขรายตัวจริง + คำสั่งโหวตที่ถูกต้อง.
 *
 * สภาพเดิม:
 *  - ตัวถอด extraData อ่านชุดแบบ bls ไม่ถูก (คาดว่าทุกช่องเป็นที่อยู่เปล่า 0x94…)
 *  - "Blocks Sealed 0 / Last Sealed —" ตายตัวทุกแถว
 *  - คำแนะนำโหวตใช้เมธอด RPC ที่ไม่มีจริง พอร์ตผิด (9632) และไม่มี --bls → รันแล้วล้มทุกครั้ง
 */
class ValidatorAdminDashboardTest extends TestCase
{
    use FakesValidatorChain;
    use RefreshDatabase;

    private const NEW_VALIDATOR = '0x1111111111111111111111111111111111111111';

    private const BLS = '0x8f5c6db7b7f32fb48d9f3b1e86eec5b3821ada3ca35e110aa258c1aa2e55c3fc11f7fd1b48027240667e9c8e806c18d5';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->actingAs(AdminUser::create([
            'name' => 'ผู้ดูแลระบบ', 'email' => 'admin@tpix.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'is_active' => true,
        ]), 'admin');

        $this->registerValidatorContracts();
        $this->useFixtureBlocks();
        $this->fakeValidatorChain();
    }

    public function test_dashboard_shows_the_real_bls_set_with_monitor_numbers(): void
    {
        app(SealMonitor::class)->run(12);

        $this->get('/admin/validators')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Admin/Validators/Index')
                    ->has('validators', 4)
                    ->where('validators.0.address', '0x00affa6379fbd969cbe5906cdeee793960a500da')
                    ->where('validators.0.bls', '0x8f5c6db7b7f32fb48d9f3b1e86eec5b3821ada3ca35e110aa258c1aa2e55c3fc11f7fd1b48027240667e9c8e806c18d5')
                    ->where('validators.0.blocks_sealed', 3)
                    ->where('validators.2.last_sealed_block', 3207999)
                    ->where('validators.0.silent', false)
                    ->where('stats.active_validators', 4)
                    ->where('stats.quorum', 3)
                    ->where('stats.fault_tolerance', 1)
                    ->where('stats.votes_needed', 3)
                    ->where('stats.monitor_block', 3207999)
                    ->where('stats.block_height', 3207999)
            );
    }

    public function test_onboarding_lists_bindings_with_the_right_command(): void
    {
        ValidatorKeyBinding::create([
            'staking_wallet' => '0x'.str_repeat('5', 40),
            'validator_address' => self::NEW_VALIDATOR,
            'bls_public_key' => '0x'.str_repeat('ab', 48),
            'node_id' => '16Uiu2HAmSmFLK2z9HhZifQ3xaZmbZkZbT3hcEodRvVdX9tNnB8xv',
            'message' => 'm', 'staking_signature' => '0x', 'validator_signature' => '0x',
            'signed_at' => time(), 'status' => 'active',
        ]);
        $this->registryNode('0x'.str_repeat('5', 40));

        $this->getJson('/admin/validators/stats')
            ->assertOk()
            ->assertJsonPath('data.stats.awaiting_vote', 1)
            ->assertJsonPath('data.onboarding.0.stage', 'awaiting_vote')
            ->assertJsonPath(
                'data.onboarding.0.commands.script',
                'sudo bash ~/TPIX-Coin/infrastructure/single-host/validator-vote.sh auth --addr '.self::NEW_VALIDATOR
                .' --bls 0x'.str_repeat('ab', 48).' --node-id 16Uiu2HAmSmFLK2z9HhZifQ3xaZmbZkZbT3hcEodRvVdX9tNnB8xv',
            );
    }

    public function test_propose_vote_gives_commands_that_actually_run(): void
    {
        $data = $this->postJson('/admin/validators/propose-vote', [
            'address' => self::NEW_VALIDATOR,
            'action' => 'add',
            'bls' => self::BLS,
        ])->assertOk()->json('data');

        $all = implode("\n", $data['cli_instructions']);
        $this->assertStringNotContainsString('ibft_proposeValidatorVote', $all, 'เมธอด RPC นี้ไม่มีใน polygon-edge 0.9');
        $this->assertStringNotContainsString('9632', $all, 'gRPC จริงคือ 10000 ในคอนเทนเนอร์');
        $this->assertStringContainsString(
            'sudo docker exec tpix-validator-1 polygon-edge ibft propose --addr '.self::NEW_VALIDATOR.' --bls '.self::BLS.' --vote auth --grpc-address 127.0.0.1:10000',
            $all,
        );
        $this->assertStringContainsString('--yes', $all);
        $this->assertSame(3, $data['votes_needed']);
        $this->assertSame(4, $data['total_validators']);
        $this->assertStringContainsString('ทนดับได้ 1 ตัวเท่าเดิม', $data['warnings'][0]);
    }

    public function test_adding_requires_a_bls_key_but_dropping_does_not(): void
    {
        $this->postJson('/admin/validators/propose-vote', ['address' => self::NEW_VALIDATOR, 'action' => 'add'])
            ->assertStatus(422)->assertJsonValidationErrors('bls');

        $data = $this->postJson('/admin/validators/propose-vote', ['address' => self::NEW_VALIDATOR, 'action' => 'remove'])
            ->assertOk()->json('data');
        $this->assertStringContainsString('validator-vote.sh drop --addr '.self::NEW_VALIDATOR, $data['cli_command']);
        $this->assertStringNotContainsString('--bls', implode("\n", $data['cli_instructions']));
        $this->assertSame([], $data['warnings']);
    }

    public function test_dashboard_survives_a_chain_outage(): void
    {
        $this->chainDown = true;

        $this->getJson('/admin/validators/stats')
            ->assertOk()
            ->assertJsonPath('data.validators', [])
            ->assertJsonPath('data.stats.rpc_connected', false);
    }
}
