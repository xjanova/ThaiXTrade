<?php

namespace Tests\Feature\TokenFactory;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Services\TokenFactory\TokenFactoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * นโยบาย Token Factory — "ปิดทุกอย่างที่เสี่ยงไว้ก่อน แอดมินเปิด/ปิดเองได้".
 *
 * กัน:
 *   - ค่าตั้งต้นเปิดของเสี่ยงโดยไม่ตั้งใจ
 *   - แอดมิน (หรือคนแก้ JSON ในฐานข้อมูล) ปลดล็อกของที่สัญญามีบั๊กทำร้ายผู้ซื้อ
 *   - เปลี่ยนนโยบายแล้วไม่มีร่องรอยว่าใครเปลี่ยนอะไร
 */
class TokenFactoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->superAdmin = AdminUser::create([
            'name' => 'เจ้าของ',
            'email' => 'owner@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function policy(): TokenFactoryPolicy
    {
        return app(TokenFactoryPolicy::class);
    }

    #[Test]
    public function ค่าตั้งต้นเปิดเฉพาะเหรียญมาตรฐานและการเผาของผู้ถือ(): void
    {
        $policy = $this->policy()->effective();

        $this->assertSame('on', $policy['types']['erc20']['state']);
        $this->assertSame('on', $policy['options']['burnable']['state']);

        foreach (['mintable', 'mint_cap', 'pausable', 'blacklist'] as $option) {
            $this->assertSame('off', $policy['options'][$option]['state'], "{$option} ต้องปิดไว้ก่อน");
        }
        foreach (['governance', 'nft', 'nft_collection'] as $type) {
            $this->assertSame('off', $policy['types'][$type]['state'], "{$type} ต้องปิดไว้ก่อน");
        }

        foreach (['utility', 'reward', 'stablecoin'] as $type) {
            $this->assertSame('locked', $policy['types'][$type]['state'], "{$type} ต้องถูกล็อก");
            $this->assertNotEmpty($policy['types'][$type]['locked_reason']);
        }
        $this->assertSame('locked', $policy['options']['auto_burn']['state']);
        $this->assertStringContainsString('honeypot', $policy['options']['auto_burn']['locked_reason']);

        // ทศนิยมตั้งต้นรับ 18 อย่างเดียว · อนุมัติอัตโนมัติปิด
        $this->assertSame(18, $policy['types']['erc20']['decimals_min']);
        $this->assertSame(18, $policy['types']['erc20']['decimals_max']);
        $this->assertFalse($policy['types']['erc20']['auto_approve']);

        foreach (['TPIX', 'WTPIX', 'USDT', 'USDC', 'BTC', 'ETH', 'BNB', 'IDTC'] as $symbol) {
            $this->assertArrayHasKey($symbol, $policy['reserved_symbols']);
        }
    }

    #[Test]
    public function แอดมินเปิดตัวเลือกได้และบันทึกลง_audit_log_ว่าใครเปลี่ยนอะไร(): void
    {
        $this->actingAs($this->superAdmin, 'admin')
            ->put('/admin/token-factory/policy', [
                'options' => ['pausable' => ['state' => 'on', 'fee' => 7]],
                'types' => ['erc20' => ['decimals_min' => 6, 'decimals_max' => 18]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $policy = $this->policy()->effective();
        $this->assertSame('on', $policy['options']['pausable']['state']);
        $this->assertEquals(7, $policy['options']['pausable']['fee']);
        $this->assertSame(6, $policy['types']['erc20']['decimals_min']);

        $log = AuditLog::where('action', 'token_factory.policy.update')->latest('id')->first();
        $this->assertNotNull($log, 'ต้องมีบันทึกการเปลี่ยนนโยบาย');
        $this->assertSame($this->superAdmin->id, $log->admin_id);
        $this->assertSame('off', $log->old_values['options.pausable.state']);
        $this->assertSame('on', $log->new_values['options.pausable.state']);
        $this->assertSame(18, $log->old_values['types.erc20.decimals_min']);
        $this->assertSame(6, $log->new_values['types.erc20.decimals_min']);
        // บันทึกเฉพาะช่องที่เปลี่ยน
        $this->assertArrayNotHasKey('options.blacklist.state', $log->new_values);
    }

    #[Test]
    public function ของที่ล็อกในโค้ดแอดมินเปิดไม่ได้(): void
    {
        foreach ([
            ['types' => ['utility' => ['state' => 'on']]],
            ['types' => ['reward' => ['state' => 'on']]],
            ['types' => ['stablecoin' => ['state' => 'on']]],
            ['options' => ['auto_burn' => ['state' => 'on']]],
        ] as $payload) {
            $this->actingAs($this->superAdmin, 'admin')
                ->put('/admin/token-factory/policy', $payload)
                ->assertRedirect()
                ->assertSessionHasErrors();
        }

        $policy = $this->policy()->effective();
        $this->assertSame('locked', $policy['types']['utility']['state']);
        $this->assertSame('locked', $policy['options']['auto_burn']['state']);
        $this->assertSame(0, AuditLog::where('action', 'token_factory.policy.update')->count());
    }

    #[Test]
    public function แก้_json_ในฐานข้อมูลเองก็ปลดล็อกไม่ได้(): void
    {
        SiteSetting::set(TokenFactoryPolicy::GROUP, TokenFactoryPolicy::KEY, json_encode([
            'types' => ['utility' => ['state' => 'on'], 'stablecoin' => ['state' => 'on']],
            'options' => ['auto_burn' => ['state' => 'on']],
        ]), 'json');

        $policy = $this->policy()->effective();

        $this->assertSame('locked', $policy['types']['utility']['state']);
        $this->assertSame('locked', $policy['types']['stablecoin']['state']);
        $this->assertSame('locked', $policy['options']['auto_burn']['state']);
    }

    #[Test]
    public function แอดมินทั่วไปเข้าหน้านโยบายไม่ได้(): void
    {
        $admin = AdminUser::create([
            'name' => 'แอดมิน', 'email' => 'admin@tpix.test', 'password' => bcrypt('x'),
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')->get('/admin/token-factory/policy')->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->put('/admin/token-factory/policy', ['options' => ['mintable' => ['state' => 'on']]])
            ->assertForbidden();

        $this->assertSame('off', $this->policy()->effective()['options']['mintable']['state']);
    }

    #[Test]
    public function หน้านโยบายแสดงของที่ล็อกพร้อมเหตุผล(): void
    {
        $this->actingAs($this->superAdmin, 'admin')
            ->get('/admin/token-factory/policy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/TokenFactory/Policy')
                ->where('policy.types.utility.state', 'locked')
                ->where('policy.options.auto_burn.state', 'locked')
                ->where('policy.options.pausable.state', 'off')
                ->has('policy.types.reward.locked_reason')
            );
    }

    #[Test]
    public function กระเป๋าใน_allowlist_ใช้สัญลักษณ์สงวนได้_คนอื่นไม่ได้(): void
    {
        $owner = '0x'.str_repeat('a', 40);

        $this->actingAs($this->superAdmin, 'admin')
            ->put('/admin/token-factory/policy', [
                'reserved_symbols' => [
                    ['symbol' => 'IDTC', 'wallets' => $owner],
                    ['symbol' => 'TPIX', 'wallets' => ''],
                ],
            ])
            ->assertSessionHasNoErrors();

        $base = ['token_type' => 'standard', 'decimals' => 18, 'total_supply' => '1000', 'name' => 'Official'];

        $ok = $this->policy()->evaluate($base + ['symbol' => 'IDTC', 'creator' => $owner]);
        $this->assertArrayNotHasKey('symbol', $ok['errors']);

        $denied = $this->policy()->evaluate($base + ['symbol' => 'idtc', 'creator' => '0x'.str_repeat('b', 40)]);
        $this->assertArrayHasKey('symbol', $denied['errors']);

        $tpix = $this->policy()->evaluate($base + ['symbol' => 'TPIX', 'creator' => $owner]);
        $this->assertArrayHasKey('symbol', $tpix['errors']);
    }

    #[Test]
    public function ตัวเลือกเสี่ยงที่เปิดอยู่ต้องติ๊กยอมรับ_และค่าธรรมเนียมมาจากนโยบายที่เดียว(): void
    {
        $this->policy()->save(array_replace_recursive(
            $this->snapshot(),
            ['options' => ['pausable' => ['state' => 'on', 'fee' => 5], 'blacklist' => ['state' => 'on', 'fee' => 5]]]
        ));

        $request = [
            'token_type' => 'burnable', 'decimals' => 18, 'total_supply' => '1000', 'symbol' => 'ABC',
            'name' => 'Abc', 'creator' => '0x'.str_repeat('c', 40),
            // ชื่อคีย์จากฟอร์มจริง (blacklist_enabled) ต้องคิดเงินด้วย — เดิมคิดเฉพาะชื่อ blacklist
            'sub_options' => ['pausable' => true, 'blacklist_enabled' => true],
        ];

        $missing = app(TokenFactoryPolicy::class)->evaluate($request);
        $this->assertSame(['pausable', 'blacklist'], $missing['required_acks']);
        $this->assertArrayHasKey('risk_ack', $missing['errors']);
        $this->assertEquals(60, $missing['fee']['total']); // 50 + 0 (burnable) + 5 + 5

        $acked = app(TokenFactoryPolicy::class)->evaluate($request + ['risk_ack' => ['pausable', 'blacklist']]);
        $this->assertSame([], $acked['errors']);
        $this->assertFalse($acked['auto_approve'], 'มีของเสี่ยงต้องรอแอดมินอนุมัติเสมอ');
    }

    private function snapshot(): array
    {
        $effective = app(TokenFactoryPolicy::class)->effective();
        $snap = ['types' => [], 'options' => []] + array_intersect_key($effective, array_flip([
            'creation_enabled', 'fee_wallet', 'required_confirmations', 'quote_ttl_minutes', 'daily_limit_per_wallet',
            'name_max_length', 'symbol_max_length', 'auto_list_on_deploy', 'require_kyc', 'reserved_symbols',
        ]));
        foreach ($effective['types'] as $k => $t) {
            $snap['types'][$k] = array_intersect_key($t, array_flip(['state', 'fee', 'max_supply', 'decimals_min', 'decimals_max', 'auto_approve', 'require_kyc']));
            $snap['types'][$k]['state'] = $t['state'] === 'on' ? 'on' : 'off';
        }
        foreach ($effective['options'] as $k => $o) {
            $snap['options'][$k] = ['state' => $o['state'] === 'on' ? 'on' : 'off', 'fee' => $o['fee']];
        }

        return $snap;
    }
}
