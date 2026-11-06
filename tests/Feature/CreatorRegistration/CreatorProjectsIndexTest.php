<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\CreatorRegistration;
use App\Models\FactoryToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * หน้ารวมโครงการผู้สร้างเหรียญ /token-creator/projects — "เหรียญนี้เป็นของใคร".
 *
 * - แสดงเฉพาะใบที่อนุมัติและติ๊กยินยอมเผยแพร่โครงการ (draft / submitted / rejected / ไม่ยินยอม ไม่โผล่)
 * - จับคู่เหรียญด้วยกระเป๋าผู้สร้างแบบไม่สนตัวพิมพ์ เฉพาะเหรียญที่ deploy แล้ว
 * - ไม่หลุดข้อมูลส่วนตัว (PDPA): อีเมล เบอร์ ที่อยู่ วันเกิด ชื่อที่ไม่ยินยอม กระเป๋า เอกสาร
 *
 * Developed by Xman Studio.
 */
class CreatorProjectsIndexTest extends TestCase
{
    use CreatorRegistrationHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function registration(string $wallet, string $status, array $form = [], ?string $reviewedAt = '2026-11-06 10:00:00'): CreatorRegistration
    {
        $data = $this->validForm($form);

        return CreatorRegistration::create([
            'wallet_address' => $wallet,
            'status' => $status,
            'applicant_type' => $data['applicant_type'],
            'project_name' => $data['project_name'],
            'token_symbol' => strtoupper($data['token_symbol']),
            'form_data' => $data,
            'agreement_version' => '2026-11-06.2',
            'agreement_hash' => str_repeat('a', 64),
            'starter_allocation_bps' => 1000,
            'reviewed_at' => $status === CreatorRegistration::STATUS_APPROVED ? $reviewedAt : null,
        ]);
    }

    private function token(string $creator, string $symbol, string $status = 'deployed', ?string $contract = null): FactoryToken
    {
        return FactoryToken::create([
            'name' => "{$symbol} Token", 'symbol' => $symbol, 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => $creator,
            'contract_address' => $contract ?? ($status === 'deployed' ? '0x'.substr(hash('sha256', $symbol), 0, 40) : null),
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => $status,
        ]);
    }

    private function wallet(int $n): string
    {
        return '0x'.str_pad(dechex($n), 40, '0', STR_PAD_LEFT);
    }

    #[Test]
    public function ยังไม่มีโครงการเผยแพร่_แสดงหน้าว่าง(): void
    {
        $this->get('/token-creator/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TokenCreator/Projects')
                ->has('projects', 0)
                ->where('meta.total', 0)
                ->where('q', ''));
    }

    #[Test]
    public function ซ่อนใบร่าง_รอตรวจ_ถูกปฏิเสธ_และอนุมัติแต่ไม่ยินยอม(): void
    {
        $this->registration($this->wallet(1), CreatorRegistration::STATUS_DRAFT, ['project_name' => 'Draft Farm']);
        $this->registration($this->wallet(2), CreatorRegistration::STATUS_SUBMITTED, ['project_name' => 'Pending Farm']);
        $this->registration($this->wallet(3), CreatorRegistration::STATUS_REJECTED, ['project_name' => 'Rejected Farm']);
        $this->registration($this->wallet(4), CreatorRegistration::STATUS_APPROVED, ['project_name' => 'Private Farm', 'consent_publish_project' => false]);
        $this->registration($this->wallet(5), CreatorRegistration::STATUS_APPROVED, ['project_name' => 'Public Farm']);

        $res = $this->get('/token-creator/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('projects', 1)
                ->where('projects.0.project_name', 'Public Farm')
                ->where('meta.total', 1));

        $json = json_encode($res->viewData('page'), JSON_UNESCAPED_UNICODE);
        foreach (['Draft Farm', 'Pending Farm', 'Rejected Farm', 'Private Farm'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json, $hidden);
        }
    }

    #[Test]
    public function โครงการที่เผยแพร่แสดงพร้อมเหรียญของกระเป๋าตัวเอง_ไม่สนตัวพิมพ์(): void
    {
        $wallet = '0x'.str_repeat('ab', 20);
        $r = $this->registration($wallet, CreatorRegistration::STATUS_APPROVED);

        $mine = $this->token('0x'.str_repeat('AB', 20), 'SFARM');            // ตัวใหญ่ในฐานข้อมูล ต้องยังจับคู่ได้
        $this->token($wallet, 'WAIT', 'pending');                              // ยังไม่ deploy — ไม่แสดง
        $this->token($this->wallet(9), 'OTHER');                               // ของคนอื่น — ไม่แสดง

        $this->get('/token-creator/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('projects', 1)
                ->where('projects.0.uuid', $r->uuid)
                ->where('projects.0.url', route('token-creator.projects.show', $r->uuid))
                ->where('projects.0.project_name', 'Siam Farm')
                ->where('projects.0.token_symbol', 'sfarm')
                ->where('projects.0.applicant_type', 'individual')
                ->where('projects.0.display_name', null)   // ไม่ยินยอมเผยแพร่ชื่อ
                ->where('projects.0.approved_at', fn ($v) => str_starts_with((string) $v, '2026-11-06'))
                ->has('projects.0.tokens', 1)
                ->where('projects.0.tokens.0.id', $mine->id)
                ->where('projects.0.tokens.0.symbol', 'SFARM')
                ->where('projects.0.tokens.0.contract_address', $mine->contract_address)
                ->where('projects.0.tokens.0.whitepaper_url', route('token-factory.whitepaper', $mine->id))
                ->where('projects.0.tokens.0.explorer_url', rtrim((string) config('blockchain.explorer_url', 'https://explorer.tpix.online'), '/').'/address/'.$mine->contract_address));
    }

    #[Test]
    public function ไม่หลุดข้อมูลส่วนตัว_กระเป๋า_หรือเอกสาร(): void
    {
        $wallet = $this->wallet(7);
        $this->registration($wallet, CreatorRegistration::STATUS_APPROVED, ['line_id' => 'somchai_line', 'telegram' => '@somchai']);

        $res = $this->get('/token-creator/projects')->assertOk();
        $json = json_encode($res->viewData('page'), JSON_UNESCAPED_UNICODE);

        foreach ([
            'somchai@example.com', '+66812345678', 'ถนนสุขุมวิท', '1990-01-15', 'สมชาย', 'Somchai Jaidee',
            'own_funds', 'นักพัฒนาซอฟต์แวร์', 'somchai_line', '@somchai', $wallet, 'agreement_hash', 'documents', 'form_data',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    #[Test]
    public function บริษัทแสดงชื่อนิติบุคคลและเลขทะเบียน_ไม่แสดงผู้ลงนามในหน้ารวม(): void
    {
        $this->registration($this->wallet(8), CreatorRegistration::STATUS_APPROVED, [
            'applicant_type' => 'company',
            'company_name' => 'บริษัท สยามฟาร์ม จำกัด',
            'company_name_en' => 'Siam Farm Co., Ltd.',
            'registration_number' => '0105566000001',
            'registration_country' => 'TH',
            'signatory_name' => 'สมชาย ใจดี',
            'consent_publish_name' => true,
        ]);

        $res = $this->get('/token-creator/projects')
            ->assertInertia(fn ($page) => $page
                ->where('projects.0.applicant_type', 'company')
                ->where('projects.0.display_name', 'บริษัท สยามฟาร์ม จำกัด')
                ->where('projects.0.display_name_en', 'Siam Farm Co., Ltd.')
                ->where('projects.0.registration_number', '0105566000001'));

        $this->assertStringNotContainsString('สมชาย', json_encode($res->viewData('page'), JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function บุคคลธรรมดาที่ยินยอมเผยแพร่ชื่อ_แสดงชื่อ(): void
    {
        $this->registration($this->wallet(10), CreatorRegistration::STATUS_APPROVED, ['consent_publish_name' => true]);

        $this->get('/token-creator/projects')
            ->assertInertia(fn ($page) => $page
                ->where('projects.0.display_name', 'สมชาย ใจดี')
                ->where('projects.0.display_name_en', 'Somchai Jaidee'));
    }

    #[Test]
    public function ค้นด้วยที่อยู่สัญญาเหรียญ_ชื่อโครงการ_และไม่พบ(): void
    {
        $a = $this->wallet(11);
        $b = $this->wallet(12);
        $this->registration($a, CreatorRegistration::STATUS_APPROVED, ['project_name' => 'Siam Farm'], '2026-11-05 10:00:00');
        $this->registration($b, CreatorRegistration::STATUS_APPROVED, ['project_name' => 'Chiang Mai Coffee', 'token_symbol' => 'cmcf'], '2026-11-06 10:00:00');
        $coffee = $this->token($b, 'CMCF');

        // ใหม่สุดก่อน
        $this->get('/token-creator/projects')
            ->assertInertia(fn ($page) => $page
                ->has('projects', 2)
                ->where('projects.0.project_name', 'Chiang Mai Coffee')
                ->where('projects.1.project_name', 'Siam Farm'));

        $this->get('/token-creator/projects?q='.strtoupper($coffee->contract_address))
            ->assertInertia(fn ($page) => $page
                ->has('projects', 1)
                ->where('projects.0.project_name', 'Chiang Mai Coffee')
                ->where('q', strtoupper($coffee->contract_address)));

        $this->get('/token-creator/projects?q=siam')
            ->assertInertia(fn ($page) => $page->has('projects', 1)->where('projects.0.project_name', 'Siam Farm'));

        $this->get('/token-creator/projects?q=nothing-here')
            ->assertInertia(fn ($page) => $page->has('projects', 0)->where('meta.total', 0));

        // ค้นด้วยข้อมูลที่ไม่เผยแพร่ (อีเมล) ต้องไม่เจอ — ห้ามใช้หน้านี้เดาข้อมูลส่วนตัว
        $this->get('/token-creator/projects?q=somchai@example.com')
            ->assertInertia(fn ($page) => $page->has('projects', 0));
    }

    #[Test]
    public function แบ่งหน้าเมื่อเกิน_24_โครงการ(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->registration($this->wallet(100 + $i), CreatorRegistration::STATUS_APPROVED, ['project_name' => "Farm {$i}"]);
        }

        $this->get('/token-creator/projects')
            ->assertInertia(fn ($page) => $page
                ->has('projects', 24)
                ->where('meta.total', 25)
                ->where('meta.last_page', 2)
                ->where('meta.prev_url', null)
                ->where('meta.next_url', fn ($url) => str_contains((string) $url, 'page=2')));

        $this->get('/token-creator/projects?page=2')
            ->assertInertia(fn ($page) => $page->has('projects', 1)->where('meta.page', 2));

        // หน้าเกิน → หน้าสุดท้าย, ค่าแปลก ๆ ไม่ทำให้ 500
        $this->get('/token-creator/projects?page=99')
            ->assertInertia(fn ($page) => $page->has('projects', 1)->where('meta.page', 2));
        $this->get('/token-creator/projects?page=abc&q[]=x')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects', 24)->where('meta.page', 1)->where('q', ''));
    }
}
