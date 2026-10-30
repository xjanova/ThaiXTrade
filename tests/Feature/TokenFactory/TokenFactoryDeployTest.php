<?php

namespace Tests\Feature\TokenFactory;

use App\Jobs\CheckFactoryDeployReceiptJob;
use App\Jobs\DeployTokenJob;
use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\AdminUser;
use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use App\Services\TokenFactoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * การ deploy ต้องส่งธุรกรรมไม่เกินหนึ่งครั้งต่อการอนุมัติ.
 *
 * เหรียญ supply ใหญ่ (เช่น IDTC 1.1 ล้านล้าน) ถ้าส่งซ้ำ = สัญญา supply เต็มหลายตัวบนเชน แก้คืนไม่ได้
 */
class TokenFactoryDeployTest extends TestCase
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

    /** แถวที่ผ่านการตรวจค่าธรรมเนียมแล้ว (มีใบเสนอราคาที่ใช้กับแถวนี้จริง) */
    private function token(array $attributes = []): FactoryToken
    {
        $token = FactoryToken::create(array_merge([
            'name' => 'Good Coin', 'symbol' => 'GOOD', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => $this->creator, 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'pending', 'metadata' => ['sub_options' => []],
        ], $attributes));

        if (! array_key_exists('fee_quote_id', $attributes)) {
            $quote = TokenFactoryFeeQuote::create([
                'creator_address' => $this->creator, 'chain_id' => 4289, 'token_type' => $token->token_type,
                'params_hash' => str_repeat('0', 64), 'amount' => '0', 'amount_wei' => '0',
                'expires_at' => now()->addMinutes(30), 'used_at' => now(), 'factory_token_id' => $token->id,
            ]);
            $token->update(['fee_quote_id' => $quote->id]);
        }

        return $token->fresh();
    }

    /** รัน handle() ตรง ๆ — dispatch_sync ถูก Queue::fake() ดักไว้ ไม่ได้รันจริง */
    private function runJob(object $job): void
    {
        app()->call([$job, 'handle']);
    }

    /** สคริปต์ deploy ปลอม — คืนบรรทัด JSON ตามลำดับ */
    private function fakeScript(array $lines, int $exitCode = 0): void
    {
        Process::fake(fn () => Process::describe()
            ->output(array_map(fn ($line) => json_encode($line), $lines))
            ->exitCode($exitCode));
    }

    #[Test]
    public function กดอนุมัติซ้ำ_สั่ง_deploy_แค่ครั้งเดียว(): void
    {
        Queue::fake();
        $token = $this->token();

        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$token->id}/approve")->assertSessionHas('success');
        $this->actingAs($this->admin, 'admin')->post("/admin/token-factory/{$token->id}/approve")->assertSessionHas('error');

        Queue::assertPushed(DeployTokenJob::class, 1);
        $this->assertSame('deploying', $token->fresh()->status);
    }

    #[Test]
    public function บริการอนุมัติแบบ_atomic_สองครั้งพร้อมกันได้งานเดียว(): void
    {
        Queue::fake();
        $token = $this->token();
        $stale = FactoryToken::find($token->id); // อ่านค้างไว้เหมือนอีก request ที่มาพร้อมกัน

        app(TokenFactoryService::class)->approveToken($token);

        try {
            app(TokenFactoryService::class)->approveToken($stale);
            $this->fail('การอนุมัติครั้งที่สองต้องถูกปฏิเสธ');
        } catch (\InvalidArgumentException) {
            // ถูกต้อง
        }

        Queue::assertPushed(DeployTokenJob::class, 1);
    }

    #[Test]
    public function moderator_อนุมัติไม่ได้(): void
    {
        Queue::fake();
        $moderator = AdminUser::create([
            'name' => 'mod', 'email' => 'mod@tpix.test', 'password' => bcrypt('x'), 'role' => 'moderator', 'is_active' => true,
        ]);
        $token = $this->token();

        $this->actingAs($moderator, 'admin')->post("/admin/token-factory/{$token->id}/approve")->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame('pending', $token->fresh()->status);
    }

    #[Test]
    public function แถวที่มี_tx_hash_แล้วไม่ส่งธุรกรรมใหม่_ตรวจผลอย่างเดียว(): void
    {
        Queue::fake();
        Process::fake();
        $token = $this->token(['status' => 'deploying', 'tx_hash' => '0x'.str_repeat('d', 64)]);

        $this->runJob(new DeployTokenJob($token->id));

        Process::assertNothingRan();
        Queue::assertPushed(CheckFactoryDeployReceiptJob::class);
    }

    #[Test]
    public function แถวที่ไม่ได้อยู่ในสถานะ_deploying_งานไม่ทำอะไร(): void
    {
        Process::fake();

        foreach (['pending', 'deployed', 'rejected', 'failed'] as $status) {
            $token = $this->token(['status' => $status, 'symbol' => 'S'.strtoupper(substr($status, 0, 3))]);
            $this->runJob(new DeployTokenJob($token->id));
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function บันทึก_hash_ทันทีที่เซ็น_และส่งต่อให้งานตรวจผลเมื่อยังไม่ขึ้นเชน(): void
    {
        Queue::fake();
        $hash = '0x'.str_repeat('e', 64);
        $this->fakeScript([
            ['event' => 'signed', 'txHash' => $hash, 'nonce' => 7, 'from' => $this->deployer(), 'rawTx' => '0x02f8'],
            ['event' => 'broadcast', 'txHash' => $hash],
            ['success' => false, 'pending' => true, 'txHash' => $hash, 'error' => 'timeout'],
        ], 3);

        $token = $this->token(['status' => 'deploying']);
        $this->runJob(new DeployTokenJob($token->id));

        $fresh = $token->fresh();
        $this->assertSame('deploying', $fresh->status, 'ยังไม่รู้ผล ห้าม mark failed');
        $this->assertSame($hash, $fresh->tx_hash);
        $this->assertSame(7, (int) $fresh->deploy_nonce);
        Queue::assertPushed(CheckFactoryDeployReceiptJob::class);

        // งานซ้ำ (เช่นคิวหยิบซ้ำ) ต้องไม่ส่งธุรกรรมใหม่
        $ranAgain = false;
        Process::fake(function () use (&$ranAgain) {
            $ranAgain = true;

            return Process::result('{"success":false,"error":"should not run"}');
        });
        $this->runJob(new DeployTokenJob($token->id));
        $this->assertFalse($ranAgain, 'แถวที่มี tx_hash แล้วต้องไม่รันสคริปต์ deploy อีก');
        $this->assertSame($hash, $token->fresh()->tx_hash);
    }

    #[Test]
    public function สำเร็จแล้วไม่ตั้ง_verified_เอง_และไม่ลิสต์เองตามค่าตั้งต้น(): void
    {
        Queue::fake();
        $hash = '0x'.str_repeat('f', 64);
        $this->fakeScript([
            ['event' => 'signed', 'txHash' => $hash, 'nonce' => 1, 'rawTx' => '0x02'],
            ['success' => true, 'contractAddress' => '0x'.str_repeat('C', 40), 'txHash' => $hash, 'blockNumber' => 123, 'factoryVersion' => 'v2'],
        ]);

        $token = $this->token(['status' => 'deploying']);
        $this->runJob(new DeployTokenJob($token->id));

        $fresh = $token->fresh();
        $this->assertSame('deployed', $fresh->status);
        $this->assertSame('0x'.str_repeat('c', 40), $fresh->contract_address);
        $this->assertFalse($fresh->is_verified, '"Verified" ต้องเป็นการตัดสินของแอดมิน');
        $this->assertFalse($fresh->is_listed);
        $this->assertNull($fresh->getRawOriginal('deploy_raw_tx'));
        // ไม่ได้จ่ายค่ายืนยันซอร์ส = ไม่ส่งให้ explorer (ซื้อทีหลังได้)
        Queue::assertNotPushed(VerifyFactoryTokenOnExplorerJob::class);
    }

    #[Test]
    public function จ่ายค่ายืนยันซอร์สมาตอนสร้าง_deploy_เสร็จส่งให้_explorer_เอง(): void
    {
        Queue::fake();
        $hash = '0x'.str_repeat('d', 64);
        $this->fakeScript([
            ['event' => 'signed', 'txHash' => $hash, 'nonce' => 1, 'rawTx' => '0x02'],
            ['success' => true, 'contractAddress' => '0x'.str_repeat('D', 40), 'txHash' => $hash, 'blockNumber' => 123, 'factoryVersion' => 'v2'],
        ]);

        $token = $this->token(['status' => 'deploying', 'metadata' => ['sub_options' => [], 'source_verify' => ['paid' => true, 'via' => 'create']]]);
        $this->runJob(new DeployTokenJob($token->id));

        $this->assertSame('deployed', $token->fresh()->status);
        Queue::assertPushed(VerifyFactoryTokenOnExplorerJob::class);
    }

    #[Test]
    public function ล้มก่อนเซ็น_mark_failed_และ_retry_ได้(): void
    {
        Queue::fake();
        $this->fakeScript([['success' => false, 'error' => 'execution reverted: Ownable: caller is not the owner']], 1);

        $token = $this->token(['status' => 'deploying']);
        $this->runJob(new DeployTokenJob($token->id));

        $fresh = $token->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNull($fresh->tx_hash);
        $this->assertNull($fresh->deploy_started_at);
        $this->assertStringContainsString('not the owner', $fresh->metadata['deploy_error']);
    }

    #[Test]
    public function failed_ไม่เขียนทับแถวที่_deployed_แล้ว(): void
    {
        $token = $this->token(['status' => 'deployed', 'contract_address' => '0x'.str_repeat('c', 40), 'tx_hash' => '0x'.str_repeat('a', 64)]);

        (new DeployTokenJob($token->id))->failed(new \RuntimeException('worker died'));

        $fresh = $token->fresh();
        $this->assertSame('deployed', $fresh->status);
        $this->assertArrayNotHasKey('deploy_error', $fresh->metadata ?? []);
    }

    #[Test]
    public function failed_ไม่_mark_failed_ถ้ามีธุรกรรมค้าง_ส่งไปตรวจผลแทน(): void
    {
        Queue::fake();
        $token = $this->token(['status' => 'deploying', 'tx_hash' => '0x'.str_repeat('b', 64)]);

        (new DeployTokenJob($token->id))->failed(new \RuntimeException('timeout'));

        $this->assertSame('deploying', $token->fresh()->status);
        Queue::assertPushed(CheckFactoryDeployReceiptJob::class);
    }

    #[Test]
    public function งานตรวจผล_revert_แล้ว_mark_failed_และเคลียร์ธุรกรรมให้_retry_ได้(): void
    {
        Queue::fake();
        $hash = '0x'.str_repeat('9', 64);
        $this->fakeScript([['success' => false, 'reverted' => true, 'txHash' => $hash, 'error' => 'reverted']], 1);

        $token = $this->token(['status' => 'deploying', 'tx_hash' => $hash, 'deploy_raw_tx' => '0x02']);
        $this->runJob(new CheckFactoryDeployReceiptJob($token->id));

        $fresh = $token->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNull($fresh->tx_hash);
        $this->assertSame($hash, $fresh->metadata['previous_deploy_txs'][0]['tx_hash']);
    }

    #[Test]
    public function งานตรวจผลส่ง_raw_tx_เดิม_ไม่ใช่เซ็นใหม่(): void
    {
        Queue::fake();
        $hash = '0x'.str_repeat('8', 64);
        $stdin = null;

        Process::fake(function ($process) use (&$stdin, $hash) {
            $stdin = $process->input;

            return Process::result(json_encode([
                'success' => true, 'contractAddress' => '0x'.str_repeat('7', 40), 'txHash' => $hash, 'blockNumber' => 5,
            ]));
        });

        $token = $this->token(['status' => 'deploying', 'tx_hash' => $hash, 'deploy_raw_tx' => '0x02abcdef']);
        $this->runJob(new CheckFactoryDeployReceiptJob($token->id));

        $payload = json_decode($stdin, true);
        $this->assertSame('receipt', $payload['mode']);
        $this->assertSame($hash, $payload['txHash']);
        $this->assertSame('0x02abcdef', $payload['rawTx']);
        $this->assertSame('deployed', $token->fresh()->status);
    }

    #[Test]
    public function ระหว่างมีเหรียญอื่นกำลังส่งด้วยคีย์เดียวกัน_งานต่อคิวใหม่ไม่ส่งซ้อน(): void
    {
        Queue::fake();
        Process::fake();
        $lock = Cache::lock('token-factory:deploy:'.$this->deployer(), 240);
        $this->assertTrue($lock->get());

        $token = $this->token(['status' => 'deploying']);
        $this->runJob(new DeployTokenJob($token->id));

        Process::assertNothingRan();
        Queue::assertPushed(DeployTokenJob::class, fn (DeployTokenJob $job) => $job->tokenId === $token->id);
        $this->assertNull($token->fresh()->deploy_started_at);
        $lock->release();
    }

    #[Test]
    public function อนุมัติไม่ได้ถ้ากระเป๋าเซิร์ฟเวอร์ไม่ใช่เจ้าของแฟกทอรี(): void
    {
        Queue::fake();
        $this->chain['owner'] = '0x'.str_repeat('5', 40);
        $token = $this->token();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/token-factory/{$token->id}/approve")
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame('pending', $token->fresh()->status);
    }

    #[Test]
    public function อนุมัติไม่ได้ถ้าตอนนี้นโยบายปิดตัวเลือกที่ขอไว้(): void
    {
        Queue::fake();
        $token = $this->token(['token_type' => 'mintable']); // mintable ปิดอยู่

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/token-factory/{$token->id}/approve")
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }
}
