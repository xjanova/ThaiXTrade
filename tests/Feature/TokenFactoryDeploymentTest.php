<?php

namespace Tests\Feature;

use App\Services\Web3DeploymentService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ตรวจชั้นที่ PHP ส่งต่อให้สคริปต์ deploy เหรียญ.
 *
 * บั๊กที่เทสต์ชุดนี้กันไม่ให้กลับมา (ทั้งหมดเคยอยู่บน production):
 *   1. สคริปต์ชื่อ .js ทั้งที่ package.json ตั้ง "type": "module"
 *      → node ตีความเป็น ESM แล้วตายที่ require() บรรทัดแรก
 *      การสร้างเหรียญจึงล้มตั้งแต่ยังไม่ทันต่อเชน
 *   2. NFT ถูกคูณ 10^decimals เหมือน ERC-20 → เพดานจำนวนใบพองเป็น 10^18 เท่า
 */
class TokenFactoryDeploymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'blockchain.deployer_private_key' => '0x'.str_repeat('1', 64),
            'blockchain.factory_v2_address' => '0x'.str_repeat('a', 40),
            'blockchain.nft_factory_address' => '0x'.str_repeat('b', 40),
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
        ]);
    }

    /** จับสิ่งที่ถูกส่งให้สคริปต์ node โดยไม่รันจริง */
    private function captureScriptInput(array $params): array
    {
        $command = null;
        $stdin = null;

        Process::fake(function ($process) use (&$command, &$stdin) {
            $command = $process->command;
            $stdin = $process->input;

            return Process::result(json_encode([
                'success' => true,
                'contractAddress' => '0x'.str_repeat('c', 40),
                'txHash' => '0x'.str_repeat('d', 64),
                'blockNumber' => 1,
            ]));
        });

        app(Web3DeploymentService::class)->deployToken($params);

        $this->assertNotNull($command, 'ต้องมีการเรียกสคริปต์');
        $this->assertNotNull($stdin, 'payload ต้องถูกส่งทาง stdin ไม่ใช่ argv');

        return [
            'command' => is_array($command) ? implode(' ', $command) : (string) $command,
            'input' => json_decode($stdin, true),
        ];
    }

    public function test_calls_the_cjs_script_not_the_esm_one(): void
    {
        // package.json ตั้ง "type": "module" → ไฟล์ .js ถูกมองเป็น ESM
        // สคริปต์เขียนแบบ CommonJS จึงต้องลงท้าย .cjs เท่านั้น
        $result = $this->captureScriptInput([
            'name' => 'Test', 'symbol' => 'TST', 'decimals' => 18,
            'total_supply' => '1000000', 'creator_address' => '0x'.str_repeat('e', 40),
            'token_type' => 'standard', 'token_category' => 'fungible', 'sub_options' => [],
        ]);

        $this->assertStringContainsString('create-token.cjs', $result['command']);
        $this->assertDoesNotMatchRegularExpression('/create-token\.js(?!on)/', $result['command']);
    }

    public function test_the_script_file_actually_exists_and_is_commonjs(): void
    {
        $path = base_path('scripts/blockchain/create-token.cjs');

        $this->assertFileExists($path, 'สคริปต์ที่ PHP เรียกต้องมีอยู่จริง');
        $this->assertStringContainsString(
            "require('ethers')",
            file_get_contents($path),
            'สคริปต์เป็น CommonJS จึงต้องนามสกุล .cjs'
        );
    }

    public function test_erc20_supply_is_scaled_by_decimals(): void
    {
        $result = $this->captureScriptInput([
            'name' => 'Coin', 'symbol' => 'CN', 'decimals' => 18,
            'total_supply' => '1000000', 'creator_address' => '0x'.str_repeat('e', 40),
            'token_type' => 'standard', 'token_category' => 'fungible', 'sub_options' => [],
        ]);

        $this->assertSame('1000000000000000000000000', $result['input']['totalSupply']);
    }

    public function test_erc20_with_six_decimals(): void
    {
        $result = $this->captureScriptInput([
            'name' => 'Stable', 'symbol' => 'STB', 'decimals' => 6,
            'total_supply' => '1000000', 'creator_address' => '0x'.str_repeat('e', 40),
            'token_type' => 'stablecoin', 'token_category' => 'special', 'sub_options' => [],
        ]);

        $this->assertSame('1000000000000', $result['input']['totalSupply']);
    }

    public function test_nft_count_is_never_scaled_by_decimals(): void
    {
        // NFT นับเป็นจำนวนใบ — ถ้าคูณทศนิยมไปด้วย เพดาน 100 ใบจะกลายเป็น 100 * 10^18
        // แล้วเพดานที่ผู้ใช้ตั้งไว้ก็ไม่มีความหมายอีกต่อไป
        foreach ([['nft', 'nft'], ['nft_collection', 'nft']] as [$type, $category]) {
            $result = $this->captureScriptInput([
                'name' => 'Art', 'symbol' => 'ART', 'decimals' => 18, // ตั้งใจใส่ 18 ให้ผิด
                'total_supply' => '100', 'creator_address' => '0x'.str_repeat('e', 40),
                'token_type' => $type, 'token_category' => $category, 'sub_options' => [],
            ]);

            $this->assertSame('100', $result['input']['totalSupply'], "ชนิด {$type} ต้องไม่ถูกคูณทศนิยม");
        }
    }

    public function test_refuses_when_no_factory_address_is_configured(): void
    {
        config([
            'blockchain.factory_address' => null,
            'blockchain.factory_v2_address' => null,
            'blockchain.nft_factory_address' => null,
        ]);

        $result = app(Web3DeploymentService::class)->deployToken([
            'name' => 'X', 'symbol' => 'X', 'decimals' => 18,
            'total_supply' => '1', 'creator_address' => '0x'.str_repeat('e', 40),
            'token_type' => 'standard', 'token_category' => 'fungible', 'sub_options' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('factory', strtolower($result['error']));
    }

    public function test_refuses_without_a_deployer_key(): void
    {
        config(['blockchain.deployer_private_key' => null]);

        $result = app(Web3DeploymentService::class)->deployToken([
            'name' => 'X', 'symbol' => 'X', 'decimals' => 18,
            'total_supply' => '1', 'creator_address' => '0x'.str_repeat('e', 40),
            'token_type' => 'standard', 'token_category' => 'fungible', 'sub_options' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DEPLOYER_PRIVATE_KEY', $result['error']);
    }

    public function test_sub_options_reach_the_script_untouched(): void
    {
        // PHP ไม่ควรตีความออฟชั่นเอง — ปล่อยให้สคริปต์แปลงที่เดียว จะได้ไม่มีสองแหล่งความจริง
        $subOptions = [
            'tax_enabled' => true, 'buy_tax_rate' => 3,
            'anti_bot_enabled' => true, 'anti_bot_duration' => 30,
            'blacklist_enabled' => true,
        ];

        $result = $this->captureScriptInput([
            'name' => 'U', 'symbol' => 'U', 'decimals' => 18,
            'total_supply' => '1000', 'creator_address' => '0x'.str_repeat('e', 40),
            'token_type' => 'utility', 'token_category' => 'fungible',
            'sub_options' => $subOptions,
        ]);

        $this->assertSame($subOptions, $result['input']['subOptions']);
    }
}
