<?php

namespace Tests\Feature\TokenFactory;

use App\Jobs\CheckFactoryDeployReceiptJob;
use App\Jobs\DeployTokenJob;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Models\TokenFactoryFeeQuote;
use App\Services\TokenFactory\FactoryDeploymentFinalizer;
use App\Services\TokenFactory\TokenFactoryPolicy;
use App\Services\TokenFactoryService;
use App\Services\Web3DeploymentService;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * Regression ของผลรีวิวความปลอดภัยรอบสอง (branch feat/token-factory-hardening).
 *
 * แต่ละเทสต์ผูกกับข้อในรีวิว — ถ้าใครเผลอเปิดช่องกลับ จะพังที่ข้อนั้นตรง ๆ
 */
class TokenFactorySecurityReviewTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();

        $this->admin = AdminUser::create([
            'name' => 'แอดมิน', 'email' => 'admin@tpix.test', 'password' => bcrypt('x'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function runJob(object $job): void
    {
        app()->call([$job, 'handle']);
    }

    /** แถวที่ผ่านการตรวจค่าธรรมเนียม (ใบเสนอราคาฟรีที่ใช้กับแถวนี้) */
    private function verifiedToken(array $attributes = []): FactoryToken
    {
        $token = FactoryToken::create(array_merge([
            'name' => 'Good Coin', 'symbol' => 'GOOD', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => $this->creator, 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'pending', 'metadata' => ['sub_options' => []],
        ], $attributes));

        $quote = TokenFactoryFeeQuote::create([
            'creator_address' => $this->creator, 'chain_id' => 4289, 'token_type' => $token->token_type,
            'params_hash' => str_repeat('0', 64), 'amount' => '0', 'amount_wei' => '0',
            'expires_at' => now()->addMinutes(30), 'used_at' => now(), 'factory_token_id' => $token->id,
        ]);
        $token->update(['fee_quote_id' => $quote->id]);

        return $token->fresh();
    }

    // ── ข้อ 1: แอดมินทุกระดับเขียนนโยบายผ่านปลายทางรวมไม่ได้ ───────────────────

    #[Test]
    public function ข้อ1_ชื่อกลุ่มตัวพิมพ์ใหญ่หรือมีช่องว่างก็เขียนกลุ่มต้องห้ามไม่ได้(): void
    {
        $payload = json_encode(['fee_wallet' => '0x'.str_repeat('e', 40), 'options' => ['mintable' => ['state' => 'on']]]);

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/settings', ['settings' => [
                'TOKEN_FACTORY_POLICY.policy' => $payload,
                'token_factory_policy .policy' => $payload,
                'Token_Factory_Policy.POLICY' => $payload,
                'contracts.token_factory_v2' => '0x'.str_repeat('d', 40),
                'kyc.enabled' => '0',
                'general.site_name' => 'TPIX',
            ]])
            ->assertRedirect();

        // ไม่มีแถวสะกดแปลก ๆ ที่ collation ของ MySQL จะจับคู่กับกลุ่มต้องห้ามได้ (SQLite เก็บแยกแถว จึงตรวจได้ตรง ๆ)
        $variants = SiteSetting::query()
            ->whereRaw('LOWER(TRIM("group")) IN (?, ?, ?)', ['token_factory_policy', 'contracts', 'kyc'])
            ->get()
            ->filter(fn (SiteSetting $row) => $row->group !== strtolower(trim($row->group)));
        $this->assertCount(0, $variants, 'ห้ามสร้างแถวกลุ่มต้องห้ามที่สะกดต่างจากของจริง');
        $this->assertSame(1, SiteSetting::where('group', TokenFactoryPolicy::GROUP)->count());
        $this->assertFalse(SiteSetting::where('group', 'kyc')->where('key', 'enabled')->exists());

        $this->assertSame($this->feeWallet, app(TokenFactoryPolicy::class)->feeWallet());
        $this->assertSame('off', app(TokenFactoryPolicy::class)->effective()['options']['mintable']['state']);
        $this->assertSame(strtolower($this->erc20Factory), strtolower((string) SiteSetting::get('contracts', 'token_factory_v2')));
        // กลุ่มข้อความหน้าเว็บยังเขียนได้ตามเดิม
        $this->assertSame('TPIX', SiteSetting::get('general', 'site_name'));
    }

    #[Test]
    public function ข้อ1_กระเป๋ารับเงินและสวิตช์ใหญ่ไม่ตกไปใช้คีย์ที่แอดมินทุกระดับแก้ได้(): void
    {
        $this->setPolicy(['fee_wallet' => '']);
        SiteSetting::set('factory', 'fee_wallet', '0x'.str_repeat('7', 40));
        SiteSetting::set('revenue', 'tpix_wallet', '0x'.str_repeat('8', 40));
        SiteSetting::set('factory', 'creation_enabled', '0', 'boolean');

        $policy = app(TokenFactoryPolicy::class);
        $this->assertSame('', $policy->feeWallet());
        $this->assertTrue($policy->creationEnabled(), 'factory.creation_enabled ต้องไม่มีผลแล้ว');

        $state = app(TokenFactoryService::class)->isFactoryReady();
        $this->assertFalse($state['ready']);
        $this->assertContains('ยังไม่ได้ตั้งกระเป๋ารับค่าธรรมเนียม (ตั้งที่หน้า ตั้งค่า Token Factory)', $state['issues']);

        $this->setPolicy(['creation_enabled' => false]);
        $this->assertFalse(app(TokenFactoryPolicy::class)->creationEnabled());
    }

    // ── ข้อ 2: คำขอระบบเก่าห้ามหลุดไป deploy ฟรี ────────────────────────────

    #[Test]
    public function ข้อ2_คำขอที่ไม่มีใบเสนอราคา_หรือเป็นเชน4290_อนุมัติและretryไม่ได้(): void
    {
        Queue::fake();

        $legacy = FactoryToken::create([
            'name' => 'Old', 'symbol' => 'OLD', 'total_supply' => '1000', 'creator_address' => $this->creator,
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => 'pending', 'metadata' => ['fee_tx_hash' => '0x'.str_repeat('a', 64)],
        ]);
        $testnet = $this->verifiedToken(['symbol' => 'TNET', 'chain_id' => 4290, 'status' => 'failed']);

        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$legacy->id}/approve")->assertSessionHas('error');
        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$testnet->id}/retry")->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame('pending', $legacy->fresh()->status);
        $this->assertSame('failed', $testnet->fresh()->status);
    }

    #[Test]
    public function ข้อ2_migration_ปฏิเสธคำขอเก่าที่ยังไม่ได้ตรวจค่าธรรมเนียม(): void
    {
        $legacy = FactoryToken::create(['name' => 'A', 'symbol' => 'AAA', 'total_supply' => '1', 'creator_address' => $this->creator, 'chain_id' => 4289, 'status' => 'pending']);
        $testnet = $this->verifiedToken(['symbol' => 'BBB', 'chain_id' => 4290, 'status' => 'failed']);
        $good = $this->verifiedToken(['symbol' => 'CCC']);
        $deployed = FactoryToken::create(['name' => 'D', 'symbol' => 'DDD', 'total_supply' => '1', 'creator_address' => $this->creator, 'chain_id' => 4289, 'status' => 'deployed', 'contract_address' => '0x'.str_repeat('c', 40)]);

        (require database_path('migrations/2026_10_11_020000_reject_unverified_factory_requests.php'))->up();

        $this->assertSame('rejected', $legacy->fresh()->status);
        $this->assertSame('rejected', $testnet->fresh()->status);
        $this->assertSame('pending', $good->fresh()->status);
        $this->assertSame('deployed', $deployed->fresh()->status, 'แถวที่ deploy แล้วห้ามแตะ');
    }

    // ── ข้อ 3: แถวค้าง deploying กู้ได้ · งานรุ่นเก่าไม่ระเบิด ───────────────────

    #[Test]
    public function ข้อ3_รีเซ็ตแถวค้างได้เฉพาะเมื่อไม่มีธุรกรรมและค้างนานพอ(): void
    {
        $stuck = $this->verifiedToken(['status' => 'deploying']);
        DB::table('factory_tokens')->where('id', $stuck->id)->update(['deploy_started_at' => now()->subMinutes(20)]);

        $recent = $this->verifiedToken(['symbol' => 'NEW', 'status' => 'deploying']);
        DB::table('factory_tokens')->where('id', $recent->id)->update(['deploy_started_at' => now()->subMinutes(3)]);

        $withTx = $this->verifiedToken(['symbol' => 'WTX', 'status' => 'deploying', 'tx_hash' => '0x'.str_repeat('b', 64)]);
        DB::table('factory_tokens')->where('id', $withTx->id)->update(['deploy_started_at' => now()->subHour()]);

        // ไม่เคยมีงานรับ (คิวล่มหลังเปลี่ยนสถานะ) — ดูจาก updated_at
        $neverStarted = $this->verifiedToken(['symbol' => 'NOJOB', 'status' => 'deploying']);
        DB::table('factory_tokens')->where('id', $neverStarted->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$stuck->id}/reset-stuck")->assertSessionHas('success');
        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$neverStarted->id}/reset-stuck")->assertSessionHas('success');
        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$recent->id}/reset-stuck")->assertSessionHas('error');
        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$withTx->id}/reset-stuck")->assertSessionHas('error');

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('failed', $neverStarted->fresh()->status);
        $this->assertSame('deploying', $recent->fresh()->status);
        $this->assertSame('deploying', $withTx->fresh()->status);
        $this->assertSame(2, AuditLog::where('action', 'token_factory.reset_stuck')->count());
        $this->assertNotNull(AuditLog::where('action', 'token_factory.reset_stuck')->first()->admin_id);
    }

    #[Test]
    public function ข้อ3_ส่งเข้าคิวไม่ได้_คืนสถานะเดิมไม่ค้าง_deploying(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('redis down'));
        $token = $this->verifiedToken();

        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$token->id}/approve")->assertSessionHas('error');

        $this->assertSame('pending', $token->fresh()->status);
    }

    #[Test]
    public function ข้อ3_งาน_deploy_รุ่นเก่าที่ค้างในคิวไม่ระเบิด_และไม่ส่งคำขอที่ไม่ได้ตรวจค่าธรรมเนียม(): void
    {
        Process::fake();

        $legacy = FactoryToken::create([
            'name' => 'Old', 'symbol' => 'OLD', 'total_supply' => '1000', 'creator_address' => $this->creator,
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => 'deploying',
        ]);

        // รูปร่าง payload ของงานรุ่นก่อน: มีแต่ 'token' (โมเดล) ไม่มี tokenId
        $job = (new \ReflectionClass(DeployTokenJob::class))->newInstanceWithoutConstructor();
        $job->__unserialize(['token' => new ModelIdentifier(FactoryToken::class, $legacy->id, [], null)]);

        $this->runJob($job);

        Process::assertNothingRan();
        $fresh = $legacy->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('ระบบเก่า', $fresh->metadata['deploy_error']);

        // payload เสียจนไม่มีรหัส — ทั้ง handle() และ failed() ต้องไม่โยน Error
        $broken = (new \ReflectionClass(DeployTokenJob::class))->newInstanceWithoutConstructor();
        $broken->__unserialize([]);
        $this->runJob($broken);
        $broken->failed(new \RuntimeException('x'));
        $this->assertTrue(true);
    }

    // ── ข้อ 4: ผลของธุรกรรมเก่าห้ามปิดงานของธุรกรรมใหม่ ─────────────────────

    #[Test]
    public function ข้อ4_ผล_dropped_ของ_T1_ไม่เคลียร์_T2(): void
    {
        $t1 = '0x'.str_repeat('1', 64);
        $t2 = '0x'.str_repeat('2', 64);
        $token = $this->verifiedToken(['status' => 'deploying', 'tx_hash' => $t2]);

        $finalizer = app(FactoryDeploymentFinalizer::class);
        $this->assertSame('ignored', $finalizer->apply($token->id, ['success' => false, 'dropped' => true, 'error' => 'old'], 'receipt', $t1));
        $this->assertSame('ignored', $finalizer->apply($token->id, ['success' => true, 'contractAddress' => '0x'.str_repeat('c', 40), 'txHash' => $t1], 'receipt', $t1));

        $finalizer->markFailed($token->id, 'stale', clearTx: true, expectedTx: $t1);

        $fresh = $token->fresh();
        $this->assertSame('deploying', $fresh->status);
        $this->assertSame($t2, $fresh->tx_hash);
    }

    #[Test]
    public function ข้อ4_งานตรวจผลที่แถวถูก_retry_ระหว่างรอ_ไม่เขียนทับธุรกรรมใหม่(): void
    {
        $t1 = '0x'.str_repeat('3', 64);
        $t2 = '0x'.str_repeat('4', 64);
        $token = $this->verifiedToken(['status' => 'deploying', 'tx_hash' => $t1, 'deploy_raw_tx' => '0x02']);

        // ระหว่างสคริปต์ถามผล T1 แอดมิน retry จนแถวมี T2 แล้ว
        Process::fake(function () use ($token, $t2) {
            DB::table('factory_tokens')->where('id', $token->id)->update(['tx_hash' => $t2]);

            return Process::result(json_encode(['success' => false, 'dropped' => true, 'txHash' => '0x'.str_repeat('3', 64), 'error' => 'nonce used']));
        });

        $this->runJob(new CheckFactoryDeployReceiptJob($token->id));

        $fresh = $token->fresh();
        $this->assertSame('deploying', $fresh->status);
        $this->assertSame($t2, $fresh->tx_hash);
    }

    // ── ข้อ 6: นโยบายถูกปิดระหว่างรอคิว → ไม่ส่ง ─────────────────────────────

    #[Test]
    public function ข้อ6_งาน_deploy_ตรวจนโยบายซ้ำก่อนส่ง(): void
    {
        Process::fake();
        $this->setPolicy(['options' => ['pausable' => ['state' => 'on']]]);
        $token = $this->verifiedToken(['status' => 'deploying', 'metadata' => ['sub_options' => ['pausable' => true]]]);

        // แอดมินปิด pausable หลังอนุมัติ แต่ก่อนงานรัน
        $this->setPolicy(['options' => ['pausable' => ['state' => 'off']]]);
        $this->runJob(new DeployTokenJob($token->id));

        Process::assertNothingRan();
        $fresh = $token->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('นโยบายตอนนี้ไม่อนุญาต', $fresh->metadata['deploy_error']);
    }

    // ── ข้อ 7: ราคา mint ทศนิยมเล็ก ๆ ────────────────────────────────────────

    #[Test]
    public function ข้อ7_ราคา_mint_เล็กมากถูกส่งเป็นทศนิยมปกติ_และเกิน18ตำแหน่งถูกปฏิเสธ(): void
    {
        $this->setPolicy(['types' => ['nft_collection' => ['state' => 'on']]]);
        $policy = app(TokenFactoryPolicy::class);
        $base = [
            'token_type' => 'nft_collection', 'total_supply' => '100', 'symbol' => 'ART', 'name' => 'Art',
            'creator' => $this->creator, 'risk_ack' => ['nft_collection'],
        ];

        $ok = $policy->evaluate($base + ['sub_options' => ['mint_type' => 'public', 'mint_price' => 1e-7]]);
        $this->assertSame([], $ok['errors']);
        $this->assertSame('0.0000001', $ok['sub_options']['mint_price']);

        $tooPrecise = $policy->evaluate($base + ['sub_options' => ['mint_price' => '0.0000000000000000001']]);
        $this->assertArrayHasKey('sub_options.mint_price', $tooPrecise['errors']);

        // เลขใหญ่จาก JSON (float) ต้องไม่กลายเป็น "1.0E+21"
        $big = $policy->evaluate($base + ['sub_options' => ['mint_type' => 'public', 'mint_price' => 1e21]]);
        $this->assertSame('1000000000000000000000', $big['sub_options']['mint_price']);
    }

    // ── ข้อ 8 / 9: เวลาโหมดตรวจผล และ RPC สำรองจาก config ──────────────────────

    #[Test]
    public function ข้อ8_ข้อ9_โหมดตรวจผลใช้เวลาสั้นกว่างาน_และส่ง_RPC_สำรองจาก_config(): void
    {
        config(['blockchain.tpix_rpc_fallback_urls' => '']);
        $timeout = null;
        $env = null;

        Process::fake(function ($process) use (&$timeout, &$env) {
            $timeout = $process->timeout;
            $env = $process->environment;

            return Process::result(json_encode(['success' => false, 'pending' => true]));
        });

        app(Web3DeploymentService::class)->checkDeployment('0x'.str_repeat('5', 64), null, 'standard');

        $this->assertSame(Web3DeploymentService::RECEIPT_PROCESS_TIMEOUT, $timeout);
        $this->assertLessThan((new CheckFactoryDeployReceiptJob(1))->timeout, $timeout);
        $this->assertArrayHasKey('TPIX_RPC_FALLBACK_URLS', $env);
        $this->assertSame('', $env['TPIX_RPC_FALLBACK_URLS']);
    }
}
