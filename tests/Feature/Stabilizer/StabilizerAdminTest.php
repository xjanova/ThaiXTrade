<?php

namespace Tests\Feature\Stabilizer;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\StabilityDeposit;
use App\Models\StabilityTransaction;
use App\Services\TpixDexService;
use App\Support\Wei;
use Tests\Support\FakesStabilizerChain;
use Tests\TestCase;

/**
 * หลังบ้าน /admin/stabilizer — เฉพาะ super_admin · เปิดต้องยืนยันและพร้อมครบ · ทุกอย่างลง audit log.
 */
class StabilizerAdminTest extends TestCase
{
    use FakesStabilizerChain;

    private AdminUser $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpStabilizer();
        $this->setPool('100000', '10000');
        $this->setWallet('5000', '800');

        $this->owner = AdminUser::create([
            'name' => 'เจ้าของ', 'email' => 'owner@tpix.test', 'password' => bcrypt('secret-password'),
            'role' => 'super_admin', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownStabilizerKeystore();
        parent::tearDown();
    }

    public function test_super_admin_sees_the_page(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->get('/admin/stabilizer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Stabilizer/Index')
                ->where('readiness.ready', true)
                ->where('params.band_pct', 15)
                ->has('bounds.max_impact_pct'));
    }

    public function test_other_admin_roles_are_refused(): void
    {
        $staff = AdminUser::create([
            'name' => 'staff', 'email' => 'staff@tpix.test', 'password' => bcrypt('secret-password'),
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->actingAs($staff, 'admin')->get('/admin/stabilizer')->assertForbidden();
        $this->actingAs($staff, 'admin')->post('/admin/stabilizer/switch', ['enabled' => true, 'confirm' => 'ENABLE'])->assertForbidden();
    }

    public function test_enabling_requires_typed_confirmation(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/switch', ['enabled' => true])
            ->assertSessionHasErrors('confirm');

        $this->assertFalse((bool) SiteSetting::get('stabilizer', 'stabilizer_enabled', false));
    }

    public function test_enabling_is_refused_until_ready(): void
    {
        config(['stabilizer.wallet.passphrase' => '']);

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/switch', ['enabled' => true, 'confirm' => 'ENABLE'])
            ->assertSessionHasErrors('enabled');

        $this->assertFalse((bool) SiteSetting::get('stabilizer', 'stabilizer_enabled', false));
    }

    public function test_keystore_for_a_different_wallet_blocks_enabling(): void
    {
        config(['stabilizer.wallet.address' => '0x'.str_repeat('8', 40)]);

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/switch', ['enabled' => true, 'confirm' => 'ENABLE'])
            ->assertSessionHasErrors('enabled');
    }

    public function test_enable_and_pause_are_audited(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/switch', ['enabled' => true, 'confirm' => 'ENABLE'])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) SiteSetting::get('stabilizer', 'stabilizer_enabled'));

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/switch', ['enabled' => false, 'reason' => 'manual_pause'])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) SiteSetting::get('stabilizer', 'stabilizer_enabled'));
        $this->assertSame('manual_pause', SiteSetting::get('stabilizer', 'stabilizer_pause_reason'));

        $this->assertSame(['stabilizer.enable', 'stabilizer.pause'], AuditLog::orderBy('id')->where('action', 'like', 'stabilizer.%')->pluck('action')->all());
    }

    public function test_params_are_validated_against_hard_bounds(): void
    {
        $params = config('stabilizer.params');
        $payload = array_map(fn ($b) => $b['default'], $params);

        // เพดานผลกระทบต่อราคา 5% เกินกรอบแข็ง 2% → ปฏิเสธ
        $this->actingAs($this->owner, 'admin')
            ->put('/admin/stabilizer/params', ['max_impact_pct' => 5] + $payload)
            ->assertSessionHasErrors('max_impact_pct');

        $this->actingAs($this->owner, 'admin')
            ->put('/admin/stabilizer/params', ['band_pct' => 10, 'daily_budget_usdt' => 100] + $payload)
            ->assertSessionHasNoErrors();

        $this->getJson('/api/v1/stability')
            ->assertJsonPath('data.rules.band_pct', 10)
            ->assertJsonPath('data.rules.daily_budget_usdt', 100);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stabilizer.params']);
    }

    public function test_deposit_amount_is_read_from_the_chain_not_the_form(): void
    {
        $hash = '0x'.str_repeat('c', 64);
        $dex = app(TpixDexService::class);
        $this->receiptFor($hash);
        $this->chainReceipts[$hash]['logs'] = [[
            'address' => $this->sUsdt,
            'topics' => [
                $dex->eventTopic('Transfer(address,address,uint256)'),
                '0x'.TpixDexService::encAddress('0x'.str_repeat('9', 40)),
                '0x'.TpixDexService::encAddress($this->sWallet),
            ],
            'data' => '0x'.TpixDexService::encUint(Wei::toWei('750', 6)),
        ]];

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/deposits', ['token' => 'usdt', 'tx_hash' => $hash, 'note' => 'initial funding'])
            ->assertSessionHasNoErrors();

        $deposit = StabilityDeposit::firstOrFail();
        $this->assertSame(Wei::toWei('750', 6), $deposit->amount_wei);
        $this->assertSame('0x'.str_repeat('9', 40), $deposit->from_address);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stabilizer.deposit']);

        // ซ้ำ = ปฏิเสธ
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/deposits', ['token' => 'usdt', 'tx_hash' => $hash])
            ->assertSessionHasErrors('tx_hash');
        $this->assertSame(1, StabilityDeposit::count());
    }

    public function test_deposit_without_a_transfer_into_the_reserve_wallet_is_refused(): void
    {
        $hash = '0x'.str_repeat('e', 64);
        $this->receiptFor($hash); // สำเร็จ แต่ไม่มี Transfer เข้ากระเป๋ากองทุน

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/deposits', ['token' => 'usdt', 'tx_hash' => $hash])
            ->assertSessionHasErrors('tx_hash');

        $this->assertSame(0, StabilityDeposit::count());
    }

    /** ไม้ขายของกองทุนเองมี USDT จากพูลเข้ากระเป๋า — ต้องไม่กลายเป็น "เงินฝากที่มีที่มาชัด" */
    public function test_a_transaction_signed_by_the_stabilizer_itself_is_not_a_deposit(): void
    {
        $hash = '0x'.str_repeat('d', 64);
        $this->receiptFor($hash);
        $this->chainReceipts[$hash]['logs'] = [$this->usdtTransferLog($this->sPair, $this->sWallet, '300')];

        StabilityTransaction::create([
            'purpose' => 'swap', 'side' => 'sell', 'token_in' => 'tpix', 'amount_in_wei' => Wei::toWei('3000', 18),
            'nonce' => 0, 'tx_hash' => $hash, 'raw_tx' => '0xraw', 'status' => 'confirmed', 'signed_at' => now(),
        ]);

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/deposits', ['token' => 'usdt', 'tx_hash' => $hash])
            ->assertSessionHasErrors('tx_hash');

        $this->assertSame(0, StabilityDeposit::count());
    }

    /** USDT จากพูล/router/กระเป๋ากองทุนเองไม่ใช่เงินใหม่ — นับเฉพาะผู้ส่งจากข้างนอก */
    public function test_transfers_from_the_pool_router_or_the_reserve_wallet_itself_are_not_counted(): void
    {
        $only = '0x'.str_repeat('f', 64);
        $this->receiptFor($only);
        $this->chainReceipts[$only]['logs'] = [
            $this->usdtTransferLog($this->sPair, $this->sWallet, '300'),
            $this->usdtTransferLog($this->sRouter, $this->sWallet, '20'),
            $this->usdtTransferLog($this->sWallet, $this->sWallet, '50'),
        ];

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/deposits', ['token' => 'usdt', 'tx_hash' => $only])
            ->assertSessionHasErrors('tx_hash');
        $this->assertSame(0, StabilityDeposit::count());

        // ธุรกรรมเดียวกันที่มีเงินจากข้างนอกปนอยู่ด้วย — นับเฉพาะส่วนของข้างนอก
        $mixed = '0x'.str_repeat('a', 63).'b';
        $outsider = '0x'.str_repeat('9', 40);
        $this->receiptFor($mixed);
        $this->chainReceipts[$mixed]['logs'] = [
            $this->usdtTransferLog($this->sPair, $this->sWallet, '300'),
            $this->usdtTransferLog($outsider, $this->sWallet, '125'),
        ];

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/stabilizer/deposits', ['token' => 'usdt', 'tx_hash' => $mixed])
            ->assertSessionHasNoErrors();

        $deposit = StabilityDeposit::firstOrFail();
        $this->assertSame(Wei::toWei('125', 6), $deposit->amount_wei);
        $this->assertSame($outsider, $deposit->from_address);
    }

    private function usdtTransferLog(string $from, string $to, string $amount): array
    {
        return [
            'address' => $this->sUsdt,
            'topics' => [
                app(TpixDexService::class)->eventTopic('Transfer(address,address,uint256)'),
                '0x'.TpixDexService::encAddress($from),
                '0x'.TpixDexService::encAddress($to),
            ],
            'data' => '0x'.TpixDexService::encUint(Wei::toWei($amount, 6)),
        ];
    }

    public function test_preview_evaluates_without_recording(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->getJson('/admin/stabilizer/preview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.order');

        $this->assertDatabaseCount('stability_decisions', 0);
        $this->assertSame([], $this->signer->signed);
    }
}
