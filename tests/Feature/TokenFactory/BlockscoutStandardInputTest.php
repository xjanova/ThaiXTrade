<?php

namespace Tests\Feature\TokenFactory;

use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\FactoryToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ยืนยันซอร์สบน Blockscout ด้วย standard JSON input (ไม่ใช่ flattened + cancun แบบเดิมที่ไม่มีวันผ่าน).
 */
class BlockscoutStandardInputTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $address = '0xcccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/verify-'.uniqid());
        File::ensureDirectoryExists($this->dir);

        config([
            'blockchain.explorer_api_url' => 'https://explorer.tpix.test/api',
            'blockchain.explorer_verify_enabled' => true,
            'blockchain.explorer_standard_input_dir' => $this->dir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function deployedToken(string $type = 'standard'): FactoryToken
    {
        return FactoryToken::create([
            'name' => 'Good', 'symbol' => 'GOOD', 'total_supply' => '1000', 'creator_address' => '0x'.str_repeat('1', 40),
            'chain_id' => 4289, 'token_type' => $type, 'status' => 'deployed', 'contract_address' => $this->address,
            'metadata' => ['factory_version' => 'v2'],
        ]);
    }

    #[Test]
    public function ส่งแบบ_standard_input_multipart_พร้อมฟิลด์ที่_blockscout_ต้องการ(): void
    {
        File::put($this->dir.'/FactoryERC20V2.json', json_encode([
            'compiler_version' => 'v0.8.24+commit.e11b9ed9',
            'contract_name' => 'src/token-factory/FactoryERC20V2.sol:FactoryERC20V2',
            'input' => ['language' => 'Solidity', 'sources' => ['src/token-factory/FactoryERC20V2.sol' => ['content' => '// x']], 'settings' => ['evmVersion' => 'london', 'viaIR' => true]],
        ]));

        Http::fake([
            "explorer.tpix.test/api/v2/smart-contracts/{$this->address}/verification/via/standard-input" => Http::response(['message' => 'Smart-contract verification started']),
            "explorer.tpix.test/api/v2/smart-contracts/{$this->address}" => Http::response(['is_verified' => false]),
        ]);

        $token = $this->deployedToken();
        app()->call([new VerifyFactoryTokenOnExplorerJob($token->id), 'handle']);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/verification/via/standard-input')) {
                return false;
            }

            $parts = collect($request->data())->keyBy('name');

            return $request->isMultipart()
                && $request->method() === 'POST'
                && $parts['compiler_version']['contents'] === 'v0.8.24+commit.e11b9ed9'
                && $parts['contract_name']['contents'] === 'src/token-factory/FactoryERC20V2.sol:FactoryERC20V2'
                && $parts['autodetect_constructor_args']['contents'] === 'true'
                && $parts['license_type']['contents'] === 'mit'
                && str_contains((string) $parts['files[0]']['contents'], '"evmVersion":"london"');
        });

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'flattened-code'));

        $this->assertSame('submitted', $token->fresh()->metadata['explorer_verification']['status']);
        // ป้าย Verified ของเว็บไม่เกี่ยวกับการยืนยันซอร์ส — แอดมินตัดสินเอง
        $this->assertFalse($token->fresh()->is_verified);
    }

    #[Test]
    public function ไม่มีไฟล์อินพุต_บันทึกว่าข้าม_ไม่ทำให้เหรียญล้ม(): void
    {
        Http::fake();

        $token = $this->deployedToken(); // ไม่มี FactoryERC20V2.json ในโฟลเดอร์
        app()->call([new VerifyFactoryTokenOnExplorerJob($token->id), 'handle']);

        $fresh = $token->fresh();
        $this->assertSame('deployed', $fresh->status);
        $this->assertSame('skipped', $fresh->metadata['explorer_verification']['status']);
        $this->assertSame('skipped: missing standard input', $fresh->metadata['explorer_verification']['message']);
        Http::assertNothingSent();
    }

    #[Test]
    public function รอบถัดไปถามผลจนยืนยันแล้ว(): void
    {
        Http::fake([
            "explorer.tpix.test/api/v2/smart-contracts/{$this->address}" => Http::response(['is_verified' => true]),
        ]);

        $token = $this->deployedToken();
        $token->update(['metadata' => ['explorer_verification' => ['status' => 'submitted']]]);

        app()->call([new VerifyFactoryTokenOnExplorerJob($token->id), 'handle']);

        $this->assertSame('verified', $token->fresh()->metadata['explorer_verification']['status']);
    }
}
