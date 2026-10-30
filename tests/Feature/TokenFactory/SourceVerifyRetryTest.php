<?php

namespace Tests\Feature\TokenFactory;

use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\FactoryToken;
use App\Models\SystemAlert;
use App\Services\BlockscoutVerifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * จ่ายค่ายืนยันซอร์สแล้วต้องได้บริการ — explorer ล่มชั่วคราวหรือคำขอหายต้องลองใหม่เองโดยไม่คิดเงินซ้ำ.
 */
class SourceVerifyRetryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function paidToken(?array $verification, array $sourceVerify = ['paid' => true], string $touched = '-2 hours'): FactoryToken
    {
        $this->sequence++;
        $metadata = ['factory_version' => 'v2', 'source_verify' => $sourceVerify + ['via' => 'create', 'paid_at' => now()->subHours(2)->toIso8601String()]];
        if ($verification !== null) {
            $metadata['explorer_verification'] = $verification;
        }

        $token = FactoryToken::create([
            'name' => 'Good Coin', 'symbol' => "GD{$this->sequence}", 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => '0x'.str_repeat('1', 40), 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'deployed',
            'contract_address' => '0x'.str_repeat((string) $this->sequence, 40), 'metadata' => $metadata,
        ]);

        // เขียนตรงเพื่อคุมเวลาแถวโดยไม่ให้ Eloquent ตั้ง updated_at ทับ
        DB::table('factory_tokens')->where('id', $token->id)->update(['updated_at' => now()->modify($touched)]);

        return $token->fresh();
    }

    private function retry(): void
    {
        $this->artisan('tpix:token-factory-source-verify')->assertSuccessful();
    }

    private function runJob(FactoryToken $token, BlockscoutVerifyService $explorer): void
    {
        (new VerifyFactoryTokenOnExplorerJob($token))->handle($explorer);
    }

    #[Test]
    public function paid_tokens_without_delivery_get_another_job(): void
    {
        $failed = $this->paidToken(['status' => 'error', 'message' => 'explorer 502']);
        $skipped = $this->paidToken(['status' => 'skipped', 'message' => 'skipped: verification disabled']);
        $submitted = $this->paidToken(['status' => 'submitted']);
        $never = $this->paidToken(null);

        $this->retry();

        foreach ([$failed, $skipped, $submitted, $never] as $token) {
            Queue::assertPushed(VerifyFactoryTokenOnExplorerJob::class, fn ($job) => $job->tokenId === $token->id);
        }
    }

    #[Test]
    public function delivered_or_unpaid_tokens_stay_out_of_the_retry(): void
    {
        $this->paidToken(['status' => 'verified']);
        $this->paidToken(['status' => 'error'], ['paid' => false]);
        $this->paidToken(['status' => 'error'], touched: '-5 minutes');

        $this->retry();

        Queue::assertNotPushed(VerifyFactoryTokenOnExplorerJob::class);
    }

    #[Test]
    public function verified_rows_do_not_crowd_out_a_token_still_waiting(): void
    {
        foreach (range(1, 3) as $i) {
            $this->paidToken(['status' => 'verified'], touched: '-3 days');
        }
        $waiting = $this->paidToken(['status' => 'error']);

        $this->retry();

        Queue::assertPushed(VerifyFactoryTokenOnExplorerJob::class, fn ($job) => $job->tokenId === $waiting->id);
    }

    #[Test]
    public function the_job_tries_again_after_a_skip_once_the_explorer_can_serve(): void
    {
        $token = $this->paidToken(['status' => 'skipped', 'message' => 'skipped: verification disabled']);

        $explorer = Mockery::mock(BlockscoutVerifyService::class);
        $explorer->shouldReceive('submit')->once()->andReturn(['status' => 'submitted', 'message' => null, 'contract_name' => 'FactoryERC20V2']);

        $this->runJob($token, $explorer);

        $this->assertSame('submitted', $token->fresh()->metadata['explorer_verification']['status']);
    }

    #[Test]
    public function the_job_resubmits_when_an_old_submission_never_landed(): void
    {
        $token = $this->paidToken(['status' => 'submitted', 'updated_at' => now()->subHours(2)->toIso8601String()]);

        $explorer = Mockery::mock(BlockscoutVerifyService::class);
        $explorer->shouldReceive('submit')->once()->andReturn(['status' => 'already_verified', 'message' => null, 'contract_name' => 'FactoryERC20V2']);

        $this->runJob($token, $explorer);

        $this->assertSame('verified', $token->fresh()->metadata['explorer_verification']['status']);
    }

    #[Test]
    public function the_job_keeps_polling_a_fresh_submission(): void
    {
        $token = $this->paidToken(['status' => 'submitted', 'updated_at' => now()->subMinute()->toIso8601String()]);

        $explorer = Mockery::mock(BlockscoutVerifyService::class);
        $explorer->shouldNotReceive('submit');
        $explorer->shouldReceive('isVerified')->once()->andReturn(true);

        $this->runJob($token, $explorer);

        $this->assertSame('verified', $token->fresh()->metadata['explorer_verification']['status']);
    }

    #[Test]
    public function a_day_without_delivery_raises_an_alert(): void
    {
        $late = $this->paidToken(['status' => 'error'], ['paid' => true, 'paid_at' => now()->subHours(25)->toIso8601String()]);
        $recent = $this->paidToken(['status' => 'error']);

        $this->retry();

        $this->assertSame(1, SystemAlert::where('node', 'token-factory')->where('status', 'active')->count());
        $this->assertTrue(SystemAlert::where('alert_key', "source_verify_undelivered_{$late->id}")->exists());
        $this->assertFalse(SystemAlert::where('alert_key', "source_verify_undelivered_{$recent->id}")->exists());
    }
}
