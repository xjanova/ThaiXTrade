<?php

namespace Tests\Feature;

use App\Services\MasterNode\NodeRegistryContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NodeRegistryV2 รุ่นแก้เพดาน APR + สวิตช์ปิดรับลงทะเบียน + endpoint ค้นหาสัญญาของแอป.
 *
 * ทำไมต้องมีชุดนี้:
 *   1. ก่อนมีเพดาน APR โหนด Light ตัวแรกตัวเดียวจะได้ ~900,000% APR แล้วดูดพูลหมด —
 *      หน้าเว็บต้องโชว์ "เพดาน" กับ "คาดการณ์ถ้าเข้าตอนนี้" จากเชนจริง ไม่ใช่ช่วงตัวเลขที่เขียนมือ
 *   2. selector ผิดแล้วเงียบคือบั๊กที่เคยหลุดขึ้น production (ดู MasterNodeRegistryTest) —
 *      ทุกเทสต์ที่นี่ตอบ eth_call ตาม selector เป๊ะ ๆ ถ้ายิงผิดตัวจะได้ revert ไม่ใช่ค่าที่ถูก
 *   3. GET /api/v1/masternode/config เป็นสัญญากับโปรแกรม PC + TPIX Wallet ใน repo อื่น
 *      ชื่อคีย์ห้ามเปลี่ยน และ "ไม่ทราบ" ต้องเป็น null ไม่ใช่ false/0
 */
class MasterNodeStakingConfigTest extends TestCase
{
    private const REGISTRY = '0x1234567890AbcdEF1234567890aBcdef12345678';

    private const KYC = '0x9999999999999999999999999999999999999999';

    // selector คำนวณจาก keccak256 ของ signature (ethers id()) — ห้ามเดา
    private const SEL_PAUSED = '0x9abbbd3b';    // registrationPaused()

    private const SEL_APR_CAP = '0xf8bc005d';   // tierAprCapBps(uint8)

    private const SEL_RATE = '0x4dedaa7a';      // nodeRewardRate(uint8)

    private const SEL_TIER_INFO = '0x7ed85ff3'; // getTierInfo(uint8)

    private const SEL_KYC = '0x6d3123eb';       // kycContract()

    // bootnodes + delegation_max_lifetime_seconds: เพิ่มพร้อมกับแอปมาสเตอร์โหนด (TPIX-Masternode#1)
    // ที่อ่านสองคีย์นี้ — TPIX Wallet อ่านแค่คีย์เดิม คีย์ที่เพิ่มไม่กระทบ
    private const CONFIG_KEYS = [
        'bootnodes', 'chain_id', 'delegation_max_lifetime_seconds', 'explorer_url', 'kyc_contract',
        'registration_paused', 'registry_address', 'registry_live', 'rpc_url',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'masternode.registry.address' => self::REGISTRY,
            'masternode.registry.rpc_url' => 'https://rpc.tpix.test',
            'blockchain.masternode_registry' => self::REGISTRY,
        ]);
    }

    private function uint(string|int $value): string
    {
        return str_pad(is_int($value) ? dechex($value) : $value, 64, '0', STR_PAD_LEFT);
    }

    /**
     * เชนจำลองที่ตอบ eth_call ตาม calldata — calldata ที่ไม่รู้จัก = revert
     * (เหมือนสัญญาจริงที่ไม่มีฟังก์ชันนั้น) ไม่ใช่คืนค่าอะไรก็ได้.
     *
     * @param  array<string,string>  $calls  calldata (lowercase) => ผลลัพธ์ hex
     */
    private function fakeChain(array $calls, string $code = '0x60806040'): void
    {
        Http::fake(function ($request) use ($calls, $code) {
            $body = json_decode($request->body(), true);

            if ($body['method'] === 'eth_getCode') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $code]);
            }
            if ($body['method'] === 'eth_blockNumber') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x2a0120']);
            }

            $data = strtolower($body['params'][0]['data'] ?? '');
            if (array_key_exists($data, $calls)) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $calls[$data]]);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32000, 'message' => 'execution reverted'],
            ]);
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  ตัวอ่านสัญญา
    // ═══════════════════════════════════════════════════════════

    public function test_reads_registration_paused_with_its_own_selector(): void
    {
        $this->fakeChain([self::SEL_PAUSED => '0x'.$this->uint(1)]);

        $this->assertTrue(app(NodeRegistryContract::class)->registrationPaused());
    }

    public function test_reads_registration_open(): void
    {
        $this->fakeChain([self::SEL_PAUSED => '0x'.$this->uint(0)]);

        $this->assertFalse(app(NodeRegistryContract::class)->registrationPaused());
    }

    public function test_registration_paused_is_unknown_on_an_old_contract(): void
    {
        // สัญญารุ่นก่อนไม่มีฟังก์ชันนี้ → revert → ต้องเป็น null ไม่ใช่ false ("เปิดรับอยู่")
        $this->fakeChain([]);

        $this->assertNull(app(NodeRegistryContract::class)->registrationPaused());
    }

    public function test_registration_paused_rejects_a_non_boolean_word(): void
    {
        // bool ที่ encode ถูกต้องมีแค่ 0/1 — ค่าอื่นแปลว่าถอดผิดสัญญา
        $this->fakeChain([self::SEL_PAUSED => '0x'.$this->uint(7)]);

        $this->assertNull(app(NodeRegistryContract::class)->registrationPaused());
    }

    public function test_reads_tier_apr_cap_for_the_contract_tier_index(): void
    {
        // Light = enum 2 — ต้องถาม tier 2 ไม่ใช่ลำดับการ์ดบนหน้าจอ
        $this->fakeChain([self::SEL_APR_CAP.$this->uint(2) => '0x'.$this->uint(600)]);

        $registry = app(NodeRegistryContract::class);
        $this->assertSame(600, $registry->tierAprCapBps(2));
        $this->assertNull($registry->tierAprCapBps(0), 'ไม่ได้ตอบ tier 0 ไว้ = revert = ไม่ทราบ');
        $this->assertNull($registry->tierAprCapBps(9), 'tier นอก enum ไม่ต้องยิง RPC');
    }

    public function test_apr_cap_outside_the_contract_bounds_is_rejected(): void
    {
        // setTierAprCap() รับแค่ 1..5000 — 0 หรือเกินนั้นแปลว่าไม่ได้คุยกับสัญญาที่คิด
        $this->fakeChain([
            self::SEL_APR_CAP.$this->uint(0) => '0x'.$this->uint(0),
            self::SEL_APR_CAP.$this->uint(1) => '0x'.$this->uint(5001),
        ]);

        $registry = app(NodeRegistryContract::class);
        $this->assertNull($registry->tierAprCapBps(0));
        $this->assertNull($registry->tierAprCapBps(1));
    }

    public function test_reads_node_reward_rate_as_two_words(): void
    {
        $this->fakeChain([
            self::SEL_RATE.$this->uint(3) => '0x'.$this->uint('e14fc68e3008d1').$this->uint(1),
        ]);

        $rate = app(NodeRegistryContract::class)->nodeRewardRate(3);

        $this->assertSame([
            'current_per_node_wei' => '63419583967529169',
            'if_one_more_joins_wei' => '1',
        ], $rate);
    }

    public function test_tier_info_carries_apr_cap_and_projected_apr_computed_without_floats(): void
    {
        // Validator: minStake 10,000,000 TPIX = 1e25 wei (0x84595161401484a000000)
        $minStake = '84595161401484a000000';
        $tierInfo = '0x'.$this->uint($minStake).$this->uint(21).$this->uint(4)
            .$this->uint(180).$this->uint(1500).$this->uint(2000);

        // อัตราที่ห่างจาก 20% พอดีไม่ถึง 1 wei/วินาที (20% เป๊ะ = ...169.9) → ต้องปัดลงเป็น 1999
        // ห้ามโฆษณาผลตอบแทนเกินจริงแม้แต่ 0.01% · 63419583967529169 > 2^53 จึงต้องคิดด้วย bcmath
        $current = 'e14fc68e3008d1';     // 63,419,583,967,529,169 wei/s
        // ถ้ามีคนเข้าเพิ่ม: 1 TPIX/วินาที = 31,536,000 TPIX/ปี จาก 10M = 315.36% → ถูกเพดาน 20%
        // แต่สัญญาเป็นคนตัดเพดานเอง ตัวอ่านแค่แปลงหน่วยตามจริง
        $ifJoin = 'de0b6b3a7640000';     // 1e18 wei/s

        $this->fakeChain([
            self::SEL_TIER_INFO.$this->uint(3) => $tierInfo,
            self::SEL_APR_CAP.$this->uint(3) => '0x'.$this->uint(2000),
            self::SEL_RATE.$this->uint(3) => '0x'.$this->uint($current).$this->uint($ifJoin),
        ]);

        $tier = app(NodeRegistryContract::class)->tierInfo(3);

        $this->assertSame('Validator', $tier['name']);
        $this->assertSame('10000000000000000000000000', $tier['min_stake_wei'], 'ต้องส่ง wei เต็มให้หน้าเว็บส่งค่าตรงเป๊ะ');
        $this->assertSame(2000, $tier['apr_cap_bps']);
        $this->assertSame(1999, $tier['current_apr_bps'], 'ปัดลงเสมอ ห้ามปัดขึ้น');
        $this->assertSame(31536, $tier['projected_apr_bps']);
        $this->assertSame('63419583967529169', $tier['reward_rate_per_node_wei']);
        $this->assertSame('1000000000000000000', $tier['reward_rate_if_join_wei']);
    }

    public function test_tier_info_still_returns_core_config_on_a_contract_without_apr_functions(): void
    {
        $tierInfo = '0x'.$this->uint('21e19e0c9bab2400000').$this->uint(0).$this->uint(7)
            .$this->uint(7).$this->uint(0).$this->uint(1500);

        // สัญญารุ่นเก่า: มี getTierInfo แต่ไม่มี tierAprCapBps / nodeRewardRate
        $this->fakeChain([self::SEL_TIER_INFO.$this->uint(2) => $tierInfo]);

        $tier = app(NodeRegistryContract::class)->tierInfo(2);

        $this->assertSame('10000', $tier['min_stake']);
        $this->assertNull($tier['apr_cap_bps'], 'อ่านไม่ได้ = ไม่ทราบ ไม่ใช่ 0%');
        $this->assertNull($tier['projected_apr_bps']);
        $this->assertNull($tier['current_apr_bps']);
    }

    // ═══════════════════════════════════════════════════════════
    //  GET /api/v1/masternode/config — สัญญากับแอปใน repo อื่น
    // ═══════════════════════════════════════════════════════════

    public function test_config_endpoint_shape_when_the_registry_is_live(): void
    {
        $this->fakeChain([
            self::SEL_KYC => '0x'.str_pad(substr(self::KYC, 2), 64, '0', STR_PAD_LEFT),
            self::SEL_PAUSED => '0x'.$this->uint(0),
        ]);

        $response = $this->getJson('/api/v1/masternode/config')->assertOk();

        $data = $response->json('data');
        $keys = array_keys($data);
        sort($keys);

        $this->assertTrue($response->json('success'));
        $this->assertSame(self::CONFIG_KEYS, $keys, 'แอปใน repo อื่นอ่านคีย์ชุดนี้ — ห้ามเพิ่ม/ลบ/เปลี่ยนชื่อโดยไม่ประสาน');
        $this->assertSame(self::REGISTRY, $data['registry_address']);
        $this->assertTrue($data['registry_live']);
        $this->assertSame(strtolower(self::KYC), strtolower($data['kyc_contract']));
        $this->assertFalse($data['registration_paused']);
        $this->assertSame(4289, $data['chain_id']);
        $this->assertSame('https://rpc1.tpix.online', $data['rpc_url'], 'แอปนอกเบราว์เซอร์ต้องได้ RPC ที่ไม่บังคับ User-Agent');
        $this->assertStringStartsWith('https://', $data['explorer_url']);
    }

    public function test_config_endpoint_reports_paused_registration(): void
    {
        $this->fakeChain([self::SEL_PAUSED => '0x'.$this->uint(1)]);

        $this->getJson('/api/v1/masternode/config')
            ->assertOk()
            ->assertJsonPath('data.registration_paused', true)
            ->assertJsonPath('data.kyc_contract', null);
    }

    public function test_config_endpoint_before_any_address_is_registered(): void
    {
        config(['masternode.registry.address' => null, 'blockchain.masternode_registry' => '']);
        Http::fake();

        $response = $this->getJson('/api/v1/masternode/config')->assertOk();

        $keys = array_keys($response->json('data'));
        sort($keys);
        $this->assertSame(self::CONFIG_KEYS, $keys, 'สถานะ "ยังไม่ deploy" ต้องมีคีย์ครบเหมือนกัน แอปจะได้ไม่ต้องเดา');

        $response->assertJsonPath('data.registry_address', null)
            ->assertJsonPath('data.registry_live', false)
            ->assertJsonPath('data.kyc_contract', null)
            ->assertJsonPath('data.registration_paused', null)
            ->assertJsonPath('data.chain_id', 4289);

        // ไม่มีที่อยู่ = ไม่ต้องถามเชนเลย
        Http::assertNothingSent();
    }

    public function test_config_endpoint_when_the_address_has_no_code(): void
    {
        // เชน regenesis แล้วที่อยู่เก่ายังค้าง — มีที่อยู่แต่ไม่มีสัญญา
        $this->fakeChain([self::SEL_PAUSED => '0x'.$this->uint(1)], code: '0x');

        $this->getJson('/api/v1/masternode/config')
            ->assertOk()
            ->assertJsonPath('data.registry_address', self::REGISTRY)
            ->assertJsonPath('data.registry_live', false)
            ->assertJsonPath('data.registration_paused', null);
    }

    public function test_config_endpoint_is_public_and_needs_no_wallet_signature(): void
    {
        config(['masternode.registry.address' => null, 'blockchain.masternode_registry' => '']);
        Http::fake();

        // ไม่มี header ลายเซ็นกระเป๋าใด ๆ — โปรแกรม PC เรียกก่อนผู้ใช้จะผูกกระเป๋าด้วยซ้ำ
        $this->get('/api/v1/masternode/config')->assertOk();
    }

    // ═══════════════════════════════════════════════════════════
    //  ค่าที่ส่งให้หน้าเว็บ
    // ═══════════════════════════════════════════════════════════

    public function test_fallback_tiers_carry_default_apr_caps_but_no_fake_projection(): void
    {
        config(['masternode.registry.address' => null, 'blockchain.masternode_registry' => '']);
        Http::fake();

        $tiers = collect($this->getJson('/api/v1/masternode/tiers')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(600, $tiers['Light']['apr_cap_bps']);
        $this->assertSame(900, $tiers['Sentinel']['apr_cap_bps']);
        $this->assertSame(1200, $tiers['Guardian']['apr_cap_bps']);
        $this->assertSame(2000, $tiers['Validator']['apr_cap_bps']);
        $this->assertSame('config', $tiers['Light']['source']);
        $this->assertNull($tiers['Light']['projected_apr_bps'], 'ยังไม่มีสัญญา = ไม่มีอัตราจริง ห้ามเดาเลข');
        $this->assertSame('10000000000000000000000', $tiers['Light']['min_stake_wei']);
    }

    public function test_page_receives_registration_paused_from_chain(): void
    {
        $this->fakeChain([self::SEL_PAUSED => '0x'.$this->uint(1)]);

        $this->get('/masternode')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MasterNode/Index')
                ->where('registryLive', true)
                ->where('registrationPaused', true));
    }

    public function test_page_reports_registration_paused_as_unknown_before_deploy(): void
    {
        config(['masternode.registry.address' => null, 'blockchain.masternode_registry' => '']);
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']));

        $this->get('/masternode')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MasterNode/Index')
                ->where('registryLive', false)
                ->where('registrationPaused', null));
    }
}
