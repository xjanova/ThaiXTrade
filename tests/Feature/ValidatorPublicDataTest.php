<?php

namespace Tests\Feature;

use App\Models\ValidatorApplication;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * หน้า /validators และ /api/v1/validators/* ต้องไม่แต่งตัวเลขเอง.
 *
 * บั๊กที่เทสต์ชุดนี้กัน (ตรวจพบ 2026-10-22):
 *   1. validator ทุกตัวได้เมืองไทยตามลำดับ (กรุงเทพ เชียงใหม่ ภูเก็ต ...) online=true uptime=99.5
 *   2. total_staked = จำนวน validator × 10M = 40M ทั้งที่ NodeRegistryV2 มีโหนด active 0 ตัว
 *   3. ใบสมัครที่อนุมัติแล้ว (กรอกประเทศเอง ไม่ต้องเซ็น) ไปทับตำแหน่งของ validator ตัวจริง
 *   4. /rewards เอายอดเงินในกระเป๋ามาเรียกว่า "รางวัล" และเอาราคาชั้นมาเรียกว่า "เงินวางค้ำ"
 */
class ValidatorPublicDataTest extends TestCase
{
    private const REGISTRY = '0x1234567890AbcdEF1234567890aBcdef12345678';

    /** validator 4 ตัวจริงของ TPIX Chain (extraData บล็อก 0x30f2f7) */
    private const VALIDATORS = [
        '0x00affa6379fbd969cbe5906cdeee793960a500da',
        '0xfe0dd72399145f54b5882ea3620f106bafdd5ad5',
        '0xa7d8ea159a59c82effcef88ccd92cf47048b2fde',
        '0xdeff2d109b52ddc574f6edc374e7e081e4198e20',
    ];

    /** extraData จริงของบล็อก 0x30f2f7 ที่ดึงจาก rpc1.tpix.online — committed seal bitmap = 0x0b */
    private const REAL_EXTRA = '0x0000000000000000000000000000000000000000000000000000000000000000f90239f90120f8469400affa6379fbd969cbe5906cdeee793960a500dab08f5c6db7b7f32fb48d9f3b1e86eec5b3821ada3ca35e110aa258c1aa2e55c3fc11f7fd1b48027240667e9c8e806c18d5f84694fe0dd72399145f54b5882ea3620f106bafdd5ad5b0ada53442c5b10b5be4b9e76ebac7f9ee141da61dac85245bfb4c945bfdd0549ddad8dcc2c6d1963b05a3f54660f0829cf84694a7d8ea159a59c82effcef88ccd92cf47048b2fdeb088394ef0a79f2444be8241128ff3053f0a2217e0341babfaa883fcdae931e44f0080ea93bbf7556db5b856c5031af752f84694deff2d109b52ddc574f6edc374e7e081e4198e20b0a2c4b0c1f97cc8f58867acd165d12a2aae4079c489bbb592baf93f48b51328238548ead0e33da040a59a75164c2c75f9b841c396448c3b576fa54096a76dd71d185f0ce9ab31a77afa8f9559bf678eafe1e764d6f681c3a8c7924d37154e106103a7df35eb003f44fdece57d1863e277cee701f8630bb86095fa7badd4345ed362c38b66db6168271e34a00233dc7fb070e07e07a7a903e89b343477497ed169c486fab33d280a0607f20c05d23850a8572fa52c6c07c4705947590e3872a62ecce256c65e413ac4ee206a883e2382c8cc4b908e6cba900df8630db860ac95eea3e0c7b8d55a7735ef97acf1cdf9b270b793419e55728868cbc9ae8a8d18a1c0a180c7b3b959eff5b15bc637f609e88544c326e5b895e03015492675e17e8e4e94a669c5d71041f2a85cb8b409ab839f18f020a73915a2cbec5b4fa1b7880000000000000000';

    /** เลขบล็อก => ['extra' => hex] ของเชนจำลอง */
    private array $blocks = [];

    /** 'missing' = ไม่มีโค้ดที่ที่อยู่สัญญา, 'live' = มีสัญญา */
    private string $registryState = 'missing';

    /** ที่อยู่ (ตัวเล็ก) => ผล getNodeInfo ที่ ABI-encode แล้ว */
    private array $nodeInfo = [];

    private array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'masternode.registry.address' => self::REGISTRY,
            'masternode.registry.rpc_url' => 'https://rpc.tpix.test',
            'blockchain.masternode_registry' => self::REGISTRY,
        ]);

        $this->fixtures = json_decode(
            file_get_contents(base_path('tests/Fixtures/node-registry-abi.json')),
            true
        );

        Http::fake(function (Request $request) {
            $body = json_decode($request->body(), true);

            // JSON-RPC batch = array ของคำขอ
            if (array_is_list($body)) {
                return Http::response(array_map(fn ($call) => $this->answer($call), $body));
            }

            return Http::response($this->answer($body));
        });
    }

    // =========================================================================
    //  Chain validators: ตำแหน่ง / ออนไลน์ / uptime / เงินวางค้ำ
    // =========================================================================

    public function test_reads_who_signed_from_a_real_tpix_block(): void
    {
        // บล็อกจริงบล็อกเดียว (บล็อกก่อนหน้าอ่านไม่ได้) — bitmap 0x0b = ลำดับ 0, 1, 3 เซ็น
        $this->blocks = [0x30F2F7 => ['extra' => self::REAL_EXTRA, 'unreadable_parents' => true]];

        $list = $this->getJson('/api/v1/validators/list')->assertOk()->json('data');

        $this->assertSame(self::VALIDATORS, array_column($list, 'address'));
        $this->assertSame([true, true, false, true], array_column($list, 'online'));
        $this->assertSame([1, 1, 0, 1], array_column($list, 'seals_signed'));
        $this->assertSame([1, 1, 1, 1], array_column($list, 'seal_window'));
        $this->assertSame(0x30F2F7, $list[0]['last_signed_block']);
        $this->assertNull($list[2]['last_signed_block']);
    }

    public function test_list_never_invents_location_uptime_or_stake(): void
    {
        $this->chainWithSigners(0b0111); // ลำดับ 3 ไม่เคยเซ็นใน 20 บล็อกล่าสุด

        $response = $this->getJson('/api/v1/validators/list')->assertOk();
        $list = $response->json('data');

        $this->assertCount(4, $list);
        foreach ($list as $v) {
            $this->assertSame('validator', $v['tier']);
            $this->assertNull($v['country_code']);
            $this->assertNull($v['country_name']);
            $this->assertNull($v['latitude']);
            $this->assertNull($v['longitude']);
            $this->assertFalse($v['location_known']);
            $this->assertNull($v['uptime'], 'ยังไม่มีการวัด uptime — ห้ามใส่ตัวเลข');
            $this->assertNull($v['stake_amount'], 'IBFT validator ไม่ได้วางค้ำในสัญญา');
            $this->assertSame(20, $v['seal_window']);
        }

        $this->assertSame([true, true, true, false], array_column($list, 'online'));
        $this->assertSame([20, 20, 20, 0], array_column($list, 'seals_signed'));
        $this->assertSame('1', $list[0]['balance']);

        // พิกัดเมืองที่เคยแจกให้ (กรุงเทพ / เชียงใหม่) ต้องไม่โผล่ที่ไหนอีก
        $this->assertStringNotContainsString('13.7563', $response->getContent());
        $this->assertStringNotContainsString('18.7883', $response->getContent());
        $this->assertStringNotContainsString('99.5', $response->getContent());
    }

    public function test_stats_count_only_validators_that_actually_sign(): void
    {
        $this->chainWithSigners(0b1011);

        $this->getJson('/api/v1/validators/stats')
            ->assertOk()
            ->assertJsonPath('data.chain_reachable', true)
            ->assertJsonPath('data.total_validators', 4)
            ->assertJsonPath('data.active_validators', 3)
            ->assertJsonPath('data.seal_window', 20)
            ->assertJsonPath('data.block_height', 1000)
            ->assertJsonPath('data.block_time', 2)
            ->assertJsonPath('data.gas_price', '1000000000');
    }

    public function test_total_staked_comes_from_node_registry_not_validator_count(): void
    {
        $this->chainWithSigners(0b1111);
        $this->registryState = 'live';

        $data = $this->getJson('/api/v1/validators/stats')->assertOk()->json('data');

        // fixture netStats: วางค้ำรวม 10,000 TPIX ปีที่ index 0
        $this->assertSame('10000', $data['total_staked']);
        $this->assertSame(1, $data['current_year']);
        $this->assertNotEquals(40000000, $data['total_staked']);
    }

    public function test_total_staked_is_unknown_without_the_registry(): void
    {
        $this->chainWithSigners(0b1111);
        $this->registryState = 'missing';

        $this->getJson('/api/v1/validators/stats')
            ->assertOk()
            ->assertJsonPath('data.total_staked', null)
            ->assertJsonPath('data.current_year', null);
    }

    public function test_approved_application_cannot_overwrite_a_chain_validator(): void
    {
        $this->chainWithSigners(0b1111);

        ValidatorApplication::create([
            'wallet_address' => self::VALIDATORS[0],
            'tier' => 'light',
            'endpoint' => 'https://evil.example',
            'country_code' => 'US',
            'country_name' => 'United States',
            'latitude' => 37.0902,
            'longitude' => -95.7129,
            'status' => 'approved',
        ]);

        $first = $this->getJson('/api/v1/validators/list')->assertOk()->json('data.0');

        $this->assertSame(self::VALIDATORS[0], $first['address']);
        $this->assertSame('validator', $first['tier']);
        $this->assertNull($first['country_code']);
        $this->assertNull($first['latitude']);
        $this->assertNull($first['endpoint']);
    }

    public function test_unreachable_chain_is_unknown_not_zero(): void
    {
        Http::fake(['*' => Http::response('upstream down', 502)]);

        $this->getJson('/api/v1/validators/stats')
            ->assertOk()
            ->assertJsonPath('data.chain_reachable', false)
            ->assertJsonPath('data.total_validators', null)
            ->assertJsonPath('data.active_validators', null)
            ->assertJsonPath('data.block_height', null)
            ->assertJsonPath('data.total_staked', null);

        $this->getJson('/api/v1/validators/list')->assertOk()->assertJsonPath('data', []);
    }

    // =========================================================================
    //  /rewards
    // =========================================================================

    public function test_rewards_do_not_invent_stake_for_an_unregistered_validator(): void
    {
        $this->chainWithSigners(0b1111);
        $this->registryState = 'live';  // สัญญามี แต่ validator ไม่ได้ลงทะเบียน (getNodeInfo = struct ศูนย์)

        $this->getJson('/api/v1/validators/rewards?address='.self::VALIDATORS[0])
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.in_validator_set', true)
            ->assertJsonPath('data.tier', 'validator')
            ->assertJsonPath('data.stake_amount', null)
            ->assertJsonPath('data.pending_rewards', null)
            ->assertJsonPath('data.total_earned', null)
            ->assertJsonPath('data.balance', '1');
    }

    public function test_rewards_read_stake_and_rewards_from_the_registry(): void
    {
        $this->chainWithSigners(0b1111);
        $this->registryState = 'live';
        $operator = '0x1111111111111111111111111111111111111111';
        $this->nodeInfo[$operator] = $this->fixtures['nodeInfo'];   // Light, active, 10,000 TPIX

        $this->getJson('/api/v1/validators/rewards?address='.$operator)
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.in_validator_set', false)
            ->assertJsonPath('data.tier', 'light')
            ->assertJsonPath('data.registry_status', 'active')
            ->assertJsonPath('data.stake_amount', '10000')
            ->assertJsonPath('data.pending_rewards', '2.5');
    }

    // =========================================================================
    //  หน้าเว็บ
    // =========================================================================

    public function test_validators_page_props_carry_no_fabricated_data(): void
    {
        $this->withoutVite();
        $this->chainWithSigners(0b0111);

        $this->get('/validators')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Validators/Index', false)
                    ->has('validators', 4)
                    ->where('validators.0.country_code', null)
                    ->where('validators.0.latitude', null)
                    ->where('validators.0.uptime', null)
                    ->where('validators.3.online', false)
                    ->where('stats.active_validators', 3)
                    ->where('stats.total_staked', null)
                    ->where('tiers', [])
            );
    }

    // =========================================================================
    //  เชนจำลอง
    // =========================================================================

    /** บล็อก 981-1000 ที่ทุกบล็อกมี validator ตาม bitmap ที่ให้เซ็น commit */
    private function chainWithSigners(int $bitmap): void
    {
        $this->blocks = [];
        for ($n = 981; $n <= 1000; $n++) {
            $this->blocks[$n] = ['extra' => $this->extraData(self::VALIDATORS, $bitmap)];
        }
    }

    private function answer(array $call): array
    {
        $result = match ($call['method']) {
            'eth_getBlockByNumber' => $this->block($call['params'][0]),
            'eth_gasPrice' => '0x3b9aca00',
            'eth_getBalance' => '0xde0b6b3a7640000',   // 1 TPIX
            'eth_getCode' => $this->registryState === 'live' ? '0x60806040' : '0x',
            'eth_call' => $this->contractCall($call['params'][0]['data'] ?? ''),
            default => null,
        };

        return ['jsonrpc' => '2.0', 'id' => $call['id'] ?? 1, 'result' => $result];
    }

    private function block(string $tag): ?array
    {
        if ($this->blocks === []) {
            return null;
        }

        $latest = max(array_keys($this->blocks));
        $number = $tag === 'latest' ? $latest : (int) hexdec($tag);
        $block = $this->blocks[$number] ?? null;

        if ($block === null || ($tag !== 'latest' && ($this->blocks[$latest]['unreadable_parents'] ?? false))) {
            return null;
        }

        return [
            'number' => '0x'.dechex($number),
            // บล็อกละ 2 วินาทีพอดี
            'timestamp' => '0x'.dechex(1792600000 + $number * 2),
            'extraData' => $block['extra'],
        ];
    }

    private function contractCall(string $data): string
    {
        if ($this->registryState !== 'live') {
            return '0x';
        }

        $selector = substr($data, 0, 10);

        return match ($selector) {
            '0x4a662f48' => $this->fixtures['netStats'],
            '0x582115fb' => $this->nodeInfo['0x'.substr($data, -40)] ?? '0x',
            // pendingReward = 2.5 TPIX
            '0xf40f0f52' => '0x'.str_pad('22b1c8c1227a0000', 64, '0', STR_PAD_LEFT),
            default => '0x',
        };
    }

    /**
     * extraData แบบ polygon-edge BLS:
     * vanity 32 bytes + RLP([validators[[addr, blsPub]], proposerSeal, [bitmap, aggSig], [bitmap, aggSig], round]).
     */
    private function extraData(array $validators, int $bitmap): string
    {
        $entries = array_map(
            fn ($addr) => $this->rlpList([$this->rlpBytes(hex2bin(substr($addr, 2))), $this->rlpBytes(str_repeat("\xab", 48))]),
            $validators
        );
        $seal = $this->rlpList([
            $this->rlpBytes($bitmap === 0 ? '' : ltrim(pack('J', $bitmap), "\0")),
            $this->rlpBytes(str_repeat("\xcd", 96)),
        ]);

        $rlp = $this->rlpList([
            $this->rlpList($entries),
            $this->rlpBytes(str_repeat("\x11", 65)),
            $seal,
            $seal,
            $this->rlpBytes(str_repeat("\0", 8)),
        ]);

        return '0x'.str_repeat('00', 32).bin2hex($rlp);
    }

    private function rlpBytes(string $bin): string
    {
        if (strlen($bin) === 1 && ord($bin) < 0x80) {
            return $bin;
        }

        return $this->rlpLength(strlen($bin), 0x80).$bin;
    }

    private function rlpList(array $items): string
    {
        $payload = implode('', $items);

        return $this->rlpLength(strlen($payload), 0xC0).$payload;
    }

    private function rlpLength(int $length, int $offset): string
    {
        if ($length < 56) {
            return chr($offset + $length);
        }

        $bytes = ltrim(pack('J', $length), "\0");

        return chr($offset + 55 + strlen($bytes)).$bytes;
    }
}
