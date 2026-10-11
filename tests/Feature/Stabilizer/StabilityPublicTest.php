<?php

namespace Tests\Feature\Stabilizer;

use App\Models\StabilityDeposit;
use App\Models\StabilityReport;
use App\Models\StabilityTransaction;
use App\Support\Wei;
use Carbon\Carbon;
use Tests\Support\FakesStabilizerChain;
use Tests\TestCase;

/**
 * หน้า /stability + API สาธารณะ — โปร่งใสครบ แต่ต้องไม่มีความลับหลุดออกไป.
 */
class StabilityPublicTest extends TestCase
{
    use FakesStabilizerChain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', config('app.timezone')));
        $this->setUpStabilizer();
        $this->setParam('lookback_days', 1);
        $this->setPool('100000', '10000');
        $this->setWallet('5000', '800');
        $this->seedHistory('0.1');
    }

    protected function tearDown(): void
    {
        $this->tearDownStabilizerKeystore();
        parent::tearDown();
    }

    public function test_page_renders_for_anyone(): void
    {
        $this->get('/stability')->assertOk();
    }

    public function test_api_publishes_rules_wallet_and_balances_without_secrets(): void
    {
        StabilityDeposit::create(['token' => 'usdt', 'amount_wei' => Wei::toWei('800', 6), 'tx_hash' => '0x'.str_repeat('a', 64), 'from_address' => '0x'.str_repeat('9', 40)]);

        $response = $this->getJson('/api/v1/stability')->assertOk()->assertJsonPath('success', true);

        $response->assertJsonPath('data.rules.band_pct', 15)
            ->assertJsonPath('data.rules.max_impact_pct', 1)
            ->assertJsonPath('data.rules.daily_budget_usdt', 200)
            ->assertJsonPath('data.rules.opposite_cooldown_hours', 6)
            ->assertJsonPath('data.wallet.address', $this->sWallet)
            ->assertJsonPath('data.wallet.balances.tpix', '5000')
            ->assertJsonPath('data.wallet.balances.usdt', '800')
            ->assertJsonPath('data.usdt_backing.mode', 'deposits_only')
            ->assertJsonPath('data.usdt_backing.deposits_remaining', '800')
            ->assertJsonPath('data.market.position', 'inside')
            ->assertJsonPath('data.status.state', 'paused');

        $this->assertStringContainsString('/address/'.$this->sWallet, (string) $response->json('data.wallet.explorer_url'));

        // ห้ามมีอะไรเกี่ยวกับ keystore / passphrase / raw tx ในข้อมูลสาธารณะ
        $body = $response->getContent();
        $this->assertStringNotContainsString('test-only', $body);
        $this->assertStringNotContainsString('keystore', $body);
        $this->assertStringNotContainsString('raw_tx', $body);
        $this->assertStringNotContainsString('passphrase', $body);
    }

    public function test_api_lists_every_transaction_with_an_explorer_link(): void
    {
        StabilityTransaction::create([
            'purpose' => 'swap', 'side' => 'buy', 'token_in' => 'usdt',
            'amount_in_wei' => Wei::toWei('25', 6), 'expected_out_wei' => Wei::toWei('249', 18),
            'nonce' => 0, 'tx_hash' => '0x'.str_repeat('b', 64), 'raw_tx' => '0xdeadbeef',
            'status' => 'confirmed', 'block_number' => 10, 'signed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/stability')->assertOk();

        $response->assertJsonPath('data.transactions.0.side', 'buy')
            ->assertJsonPath('data.transactions.0.amount_in', '25')
            ->assertJsonPath('data.transactions.0.expected_out', '249')
            ->assertJsonPath('data.transactions.0.status', 'confirmed');
        $this->assertStringEndsWith('/tx/0x'.str_repeat('b', 64), (string) $response->json('data.transactions.0.tx_url'));
        $this->assertStringNotContainsString('deadbeef', $response->getContent());
    }

    public function test_api_marks_which_reports_were_written_by_ai(): void
    {
        $base = ['stats' => ['decisions' => 1], 'facts_th' => 'ตัวเลข', 'facts_en' => 'figures', 'generated_at' => now()];
        StabilityReport::create($base + ['report_date' => '2026-10-10', 'summary_th' => 'สรุป', 'summary_en' => 'summary', 'ai_provider' => 'openai']);
        StabilityReport::create($base + ['report_date' => '2026-10-09']);

        $this->getJson('/api/v1/stability')->assertOk()
            ->assertJsonPath('data.reports.0.date', '2026-10-10')
            ->assertJsonPath('data.reports.0.ai_written', true)
            ->assertJsonPath('data.reports.1.ai_written', false)
            ->assertJsonPath('data.reports.1.facts_en', 'figures');
    }

    public function test_api_says_not_live_when_the_server_switch_is_off(): void
    {
        config(['stabilizer.enabled' => false]);

        $this->getJson('/api/v1/stability')->assertOk()
            ->assertJsonPath('data.status.state', 'off')
            ->assertJsonPath('data.status.active', false);
    }

    public function test_api_still_answers_when_the_chain_is_down(): void
    {
        $this->chainDown = true;

        $this->getJson('/api/v1/stability')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.market', null);
    }

    public function test_api_is_throttled(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/stability');

        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }
}
