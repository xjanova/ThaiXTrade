<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use App\Services\TokenFactoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ทะเบียนที่อยู่สัญญา — ทำให้หลัง deploy ไม่ต้องทำอะไรอีก.
 *
 * เดิมต้อง ssh เข้าเซิร์ฟไปแก้ .env แล้วรัน config:cache ทุกครั้งที่ deploy สัญญาใหม่
 * ซึ่งเป็นขั้นตอนที่ลืมง่ายที่สุด — deploy สำเร็จแต่เว็บยังไม่รู้จักสัญญา แล้วก็เงียบ
 *
 * ชุดนี้กันสองอย่าง:
 *   1. สคริปต์ deploy ลงทะเบียนที่อยู่เข้ามาเองได้จริง และปลอดภัยพอ
 *   2. หน้าเว็บรู้ตัวว่า "ยังรอ deploy" ไม่ใช่บอกว่าพร้อมแล้วปล่อยให้ผู้ใช้ไปเจอ error
 */
class ContractRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-registry-token-0123456789abcdef';

    private const ADDRESS = '0x1234567890AbcdEF1234567890aBcdef12345678';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.contract_registry.token' => self::TOKEN,
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'blockchain.masternode_registry' => '',
            'blockchain.factory_v2_address' => '',
            'blockchain.nft_factory_address' => '',
            'masternode.registry.address' => null,
        ]);
    }

    /** เชนตอบว่ามีโค้ดอยู่ที่ทุก address */
    private function fakeChainWithCode(): void
    {
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040']));
    }

    /** เชนตอบว่าไม่มีโค้ดเลย */
    private function fakeEmptyChain(): void
    {
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']));
    }

    private function register(array $contracts, ?string $token = self::TOKEN)
    {
        return $this->withHeaders($token ? ['Authorization' => "Bearer {$token}"] : [])
            ->postJson('/api/infra/contracts', ['contracts' => $contracts]);
    }

    // ═══════════════════════════════════════════════════════════
    //  ความปลอดภัย — ที่อยู่สัญญาคุมทางเดินของเงิน
    // ═══════════════════════════════════════════════════════════

    public function test_endpoint_is_closed_when_no_token_is_configured(): void
    {
        // ยังไม่ตั้ง token = ปิดระบบ ไม่ใช่เปิดโล่ง
        config(['services.contract_registry.token' => '']);

        $this->register(['masternode_registry' => self::ADDRESS])->assertStatus(503);
    }

    public function test_rejects_a_wrong_token(): void
    {
        $this->register(['masternode_registry' => self::ADDRESS], 'wrong-token')->assertStatus(401);
        $this->register(['masternode_registry' => self::ADDRESS], null)->assertStatus(401);
    }

    public function test_rejects_a_contract_name_it_does_not_know(): void
    {
        $this->fakeChainWithCode();

        $this->register(['some_random_contract' => self::ADDRESS])
            ->assertStatus(422);

        $this->assertNull(SiteSetting::get(ContractRegistry::GROUP, 'some_random_contract'));
    }

    public function test_rejects_a_malformed_address(): void
    {
        $this->fakeChainWithCode();

        $this->register(['masternode_registry' => 'not-an-address'])->assertStatus(422);
        $this->register(['masternode_registry' => '0x1234'])->assertStatus(422);
    }

    public function test_rejects_an_address_with_no_code_on_chain(): void
    {
        // กันทั้งการพิมพ์ผิด และกันคนชี้ระบบไปที่ address ที่ยังไม่มีสัญญา
        $this->fakeEmptyChain();

        $response = $this->register(['masternode_registry' => self::ADDRESS]);

        $response->assertStatus(422);
        $this->assertStringContainsString('eth_getCode', $response->json('rejected.masternode_registry'));
        $this->assertNull(app(ContractRegistry::class)->address('masternode_registry'));
    }

    // ═══════════════════════════════════════════════════════════
    //  ทางเดินหลัก
    // ═══════════════════════════════════════════════════════════

    public function test_registers_addresses_so_no_env_edit_is_needed(): void
    {
        $this->fakeChainWithCode();

        $factory = '0xAAAAaaaAAAAaaaAAAAaaaAAAAaaaAAAAaaaAAAAa';
        $nft = '0xBBBBbbbBBBBbbbBBBBbbbBBBBbbbBBBBbbbBBBBb';

        $this->register([
            'masternode_registry' => self::ADDRESS,
            'token_factory_v2' => $factory,
            'nft_factory' => $nft,
        ])->assertOk()->assertJsonPath('ok', true);

        $registry = app(ContractRegistry::class);

        $this->assertSame(self::ADDRESS, $registry->address('masternode_registry'));
        $this->assertSame($factory, $registry->address('token_factory_v2'));
        $this->assertSame($nft, $registry->address('nft_factory'));
    }

    public function test_reports_the_previous_address_when_replacing_one(): void
    {
        $this->fakeChainWithCode();
        $old = '0xCCCCcccCCCCcccCCCCcccCCCCcccCCCCcccCCCCc';

        $this->register(['masternode_registry' => $old])->assertOk();
        $response = $this->register(['masternode_registry' => self::ADDRESS])->assertOk();

        $this->assertSame($old, $response->json('applied.masternode_registry.previous'));
        $this->assertSame(self::ADDRESS, app(ContractRegistry::class)->address('masternode_registry'));
    }

    public function test_registry_value_wins_over_env(): void
    {
        // ค่าที่ลงทะเบียนใหม่ต้องชนะของเก่าใน .env — เป็นค่าที่ใหม่กว่าเสมอ
        $envAddress = '0xDDDDdddDDDDdddDDDDdddDDDDdddDDDDdddDDDDd';
        config(['blockchain.masternode_registry' => $envAddress]);

        $registry = app(ContractRegistry::class);
        $this->assertSame($envAddress, $registry->address('masternode_registry'));

        $this->fakeChainWithCode();
        $this->register(['masternode_registry' => self::ADDRESS])->assertOk();

        $this->assertSame(self::ADDRESS, $registry->address('masternode_registry'));
    }

    public function test_falls_back_to_env_when_nothing_is_registered(): void
    {
        $envAddress = '0xEEEEeeeEEEEeeeEEEEeeeEEEEeeeEEEEeeeEEEEe';
        config(['blockchain.factory_v2_address' => $envAddress]);

        $this->assertSame($envAddress, app(ContractRegistry::class)->address('token_factory_v2'));
    }

    public function test_status_says_where_each_address_came_from(): void
    {
        $this->fakeChainWithCode();
        config(['blockchain.nft_factory_address' => '0xFFFFfffFFFFfffFFFFfffFFFFfffFFFFfffFFFFf']);

        $this->register(['masternode_registry' => self::ADDRESS])->assertOk();

        $status = app(ContractRegistry::class)->status();

        $this->assertSame('registry', $status['masternode_registry']['source']);
        $this->assertSame('env', $status['nft_factory']['source']);
        $this->assertSame('none', $status['token_factory_v2']['source']);
    }

    // ═══════════════════════════════════════════════════════════
    //  หน้าเว็บต้องรู้ตัวว่ายังรอ deploy
    // ═══════════════════════════════════════════════════════════

    public function test_token_factory_reports_awaiting_deploy_before_anything_is_deployed(): void
    {
        // เดิมด่านนี้ไม่มีเลย — หน้าเว็บบอก "พร้อม" ทั้งที่ยังไม่มีแฟกทอรีบนเชนสักตัว
        // ผู้ใช้กรอกฟอร์ม จ่ายค่าธรรมเนียม แล้วงานไป fail ตอน deploy
        $this->fakeEmptyChain();
        config(['blockchain.deployer_private_key' => '0x'.str_repeat('1', 64)]);
        SiteSetting::set('factory', 'fee_wallet', '0x'.str_repeat('9', 40));

        $readiness = app(TokenFactoryService::class)->isFactoryReady();

        $this->assertFalse($readiness['ready']);
        $this->assertTrue($readiness['awaiting_deploy']);
        $this->assertContains('ยังไม่ได้ติดตั้งสัญญาแฟกทอรีบนเชน', $readiness['issues']);
    }

    public function test_token_factory_becomes_ready_once_the_factory_is_registered(): void
    {
        $this->fakeChainWithCode();
        config(['blockchain.deployer_private_key' => '0x'.str_repeat('1', 64)]);
        SiteSetting::set('factory', 'fee_wallet', '0x'.str_repeat('9', 40));

        $this->register([
            'token_factory_v2' => self::ADDRESS,
            'nft_factory' => '0xBBBBbbbBBBBbbbBBBBbbbBBBBbbbBBBBbbbBBBBb',
        ])->assertOk();

        $readiness = app(TokenFactoryService::class)->isFactoryReady();

        $this->assertTrue($readiness['ready'], implode(' · ', $readiness['issues']));
        $this->assertFalse($readiness['awaiting_deploy']);
        $this->assertSame([], $readiness['issues']);
    }

    public function test_token_factory_stays_blocked_without_a_deployer_key(): void
    {
        // มีแฟกทอรีบนเชนแล้วแต่เซิร์ฟเวอร์ไม่มีกระเป๋าส่ง tx = ยังสร้างไม่ได้อยู่ดี
        $this->fakeChainWithCode();
        config(['blockchain.deployer_private_key' => null]);
        SiteSetting::set('factory', 'fee_wallet', '0x'.str_repeat('9', 40));

        $this->register(['token_factory_v2' => self::ADDRESS])->assertOk();

        $readiness = app(TokenFactoryService::class)->isFactoryReady();

        $this->assertFalse($readiness['ready']);
        $this->assertFalse($readiness['awaiting_deploy'], 'สัญญาพร้อมแล้ว ปัญหาอยู่ที่คอนฟิก ไม่ใช่รอ deploy');
        $this->assertContains('ยังไม่ได้ตั้งกระเป๋าที่ใช้ส่งธุรกรรม (DEPLOYER_PRIVATE_KEY)', $readiness['issues']);
    }

    public function test_masternode_page_shows_the_waiting_state_before_deploy(): void
    {
        $this->fakeEmptyChain();

        $this->get('/masternode')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('MasterNode/Index')
                    ->where('registryLive', false)
            );
    }

    public function test_masternode_page_goes_live_once_registered(): void
    {
        $this->fakeChainWithCode();

        $this->register(['masternode_registry' => self::ADDRESS])->assertOk();

        $this->get('/masternode')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('MasterNode/Index')
                    ->where('registryLive', true)
                    ->where('registryAddress', self::ADDRESS)
            );
    }
}
