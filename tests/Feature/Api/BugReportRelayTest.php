<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ทางผ่านรายงานบั๊กของหน้าเว็บไปยังระบบกลาง xman studio.
 *
 * Developed by Xman Studio.
 */
class BugReportRelayTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_name' => 'tpix-web',
            'product_version' => '1.1.157',
            'report_type' => 'crash',
            'title' => 'TypeError: x is undefined',
            'description' => 'TypeError: x is undefined at app.js:1:1',
            'stack_trace' => "TypeError\n at foo",
            'metadata' => ['url' => 'https://tpix.online/trade', 'breadcrumbs' => ['navigate /trade']],
            'severity' => 'major',
        ], $overrides);
    }

    #[Test]
    public function ส่งต่อไประบบกลางพร้อมข้อมูล_relay_และคืน_id(): void
    {
        Http::fake(['xman4289.com/*' => Http::response(['success' => true, 'data' => ['id' => 4242, 'status' => 'new']], 201)]);

        $this->postJson('/api/v1/bug-reports', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.id', 4242)
            ->assertJsonPath('data.forwarded', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'xman4289.com/api/v1/bug-reports')
                && $request['product_name'] === 'tpix-web'
                && $request['metadata']['breadcrumbs'] === ['navigate /trade']
                && $request['metadata']['relay']['ip'] === '127.0.0.1'
                && $request->hasHeader('User-Agent')
                && ! str_starts_with($request->header('User-Agent')[0], 'GuzzleHttp');
        });
    }

    #[Test]
    public function ผลิตภัณฑ์หรือชนิดที่ไม่รู้จักถูกปฏิเสธก่อนส่งต่อ(): void
    {
        Http::fake();

        $this->postJson('/api/v1/bug-reports', $this->payload(['product_name' => 'giggok']))->assertStatus(422);
        $this->postJson('/api/v1/bug-reports', $this->payload(['report_type' => 'diagnostic']))->assertStatus(422);
        $this->postJson('/api/v1/bug-reports', $this->payload(['title' => str_repeat('x', 300)]))->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    public function ระบบกลางล่มต้องไม่ทิ้งรายงาน_เก็บลง_log_แล้วตอบ_202(): void
    {
        Http::fake(['xman4289.com/*' => Http::response('boom', 503)]);
        Log::shouldReceive('warning')->once()->withArgs(fn ($msg, $ctx) => str_contains($msg, 'bug-report') && $ctx['payload']['title'] === 'TypeError: x is undefined');

        $this->postJson('/api/v1/bug-reports', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.forwarded', false);
    }
}
