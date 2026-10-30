<?php

namespace Tests\Feature\Validator;

use App\Models\AdminUser;
use App\Models\KycDocument;
use App\Models\KycSubmission;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\ValidatorKycAction;
use App\Services\Validator\ValidatorKycContract;
use App\Services\Validator\ValidatorKycHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakesValidatorChain;
use Tests\TestCase;

/**
 * หน้าแอดมินตรวจ KYC ของผู้สมัคร Validator (/admin/validators/kyc).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * สภาพเดิมที่เทสต์ชุดนี้กันไม่ให้กลับมา
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. submit/approve KYC มีแค่สคริปต์ CLI ของ TPIX-Coin — หลังบ้านไม่มีทางทำเลย
 * 2. KYC ของเว็บไม่เคยโยงกับสัญญา ValidatorKYC — hash บนเชนย้อนหาใบไหนไม่ได้
 * 3. ประวัติต้องมาจาก tx จริงบนเชนเท่านั้น — เบราว์เซอร์ "บอกว่าทำแล้ว" ไม่พอ
 */
class ValidatorKycAdminTest extends TestCase
{
    use FakesValidatorChain;
    use RefreshDatabase;

    private const APPLICANT = '0xabc0000000000000000000000000000000000001';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin, 'admin');

        $this->registerValidatorContracts();
        $this->useFixtureBlocks();
        $this->fakeValidatorChain();
    }

    private function approvedSiteKyc(string $wallet = self::APPLICANT): KycSubmission
    {
        $user = User::create(['email' => 'operator@tpix.test', 'password' => bcrypt('x'), 'wallet_address' => strtolower($wallet)]);
        $submission = KycSubmission::create([
            'user_id' => $user->id,
            'level' => KycSubmission::LEVEL_ENHANCED,
            'status' => KycSubmission::STATUS_APPROVED,
            'full_name' => 'ผู้ให้บริการโหนด',
            'consent_version' => '1.0',
            'consented_at' => now(),
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'purge_after' => now()->addYear(),
        ]);
        KycDocument::create([
            'kyc_submission_id' => $submission->id,
            'type' => KycDocument::TYPE_ID_FRONT,
            'disk' => 'kyc',
            'path' => 'demo/front.jpg',
            'original_name' => 'front.jpg',
            'mime' => 'image/jpeg',
            'size' => 16,
            'sha256' => hash('sha256', 'front-bytes'),
        ]);

        return $submission;
    }

    private function submitInput(string $hash, string $applicant = self::APPLICANT): string
    {
        return ValidatorKycContract::selector('submitKYC(address,bytes32)')
            .str_pad(substr($applicant, 2), 64, '0', STR_PAD_LEFT)
            .substr($hash, 2);
    }

    private function approveInput(string $applicant = self::APPLICANT): string
    {
        return ValidatorKycContract::selector('approveKYC(address)').str_pad(substr($applicant, 2), 64, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // หน้า
    // =========================================================================

    public function test_page_lists_applicants_from_the_contract_with_site_kyc_and_stake(): void
    {
        $submission = $this->approvedSiteKyc();
        $this->kycApplicant(self::APPLICANT, 'ConsentGiven');
        $this->registryNode(self::APPLICANT);

        $hash = app(ValidatorKycHasher::class)->hash($submission);

        $this->get('/admin/validators/kyc')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Admin/Validators/Kyc')
                    ->where('contract', $this->kycAddress)
                    ->where('owner', $this->kycOwner)
                    ->where('loadError', null)
                    ->has('applicants', 1)
                    ->where('applicants.0.applicant', self::APPLICANT)
                    ->where('applicants.0.status', 'ConsentGiven')
                    ->where('applicants.0.site_kyc.status', 'approved')
                    ->where('applicants.0.site_kyc.uuid', $submission->uuid)
                    ->where('applicants.0.site_kyc.doc_hash', $hash)
                    ->where('applicants.0.node.tier', 'Validator')
                    ->where('applicants.0.node.status', 'active')
            );
    }

    public function test_page_compares_the_on_chain_hash_with_the_site_submission(): void
    {
        $submission = $this->approvedSiteKyc();
        $this->kycApplicant(self::APPLICANT, 'Submitted', ['kyc_hash' => app(ValidatorKycHasher::class)->hash($submission)]);

        $this->getJson('/admin/validators/kyc/applicants')
            ->assertOk()
            ->assertJsonPath('data.applicants.0.hash_matches_site', true)
            ->assertJsonPath('data.applicants.0.node', null);

        $this->kycRecords[self::APPLICANT]['kyc_hash'] = '0x'.str_repeat('ab', 32);
        $this->getJson('/admin/validators/kyc/applicants')->assertJsonPath('data.applicants.0.hash_matches_site', false);
    }

    public function test_page_says_why_the_list_is_empty(): void
    {
        $this->chainDown = true;
        $this->getJson('/admin/validators/kyc/applicants')->assertJsonPath('data.loadError', 'CHAIN_UNREACHABLE');

        SiteSetting::where('key', 'validator_kyc')->delete();
        Cache::flush();
        $this->getJson('/admin/validators/kyc/applicants')->assertJsonPath('data.loadError', 'KYC_NOT_CONFIGURED');
    }

    public function test_reject_reason_string_is_decoded(): void
    {
        $this->kycApplicant(self::APPLICANT, 'Rejected', ['reason' => 'เอกสารไม่ชัด กรุณาส่งใหม่ทั้งชุด']);

        $this->getJson('/admin/validators/kyc/applicants')
            ->assertJsonPath('data.applicants.0.reject_reason', 'เอกสารไม่ชัด กรุณาส่งใหม่ทั้งชุด')
            ->assertJsonPath('data.applicants.0.site_kyc.status', 'no_account');
    }

    public function test_only_admin_roles_reach_the_page(): void
    {
        $support = AdminUser::create([
            'name' => 'ซัพพอร์ต', 'email' => 'support@tpix.test', 'password' => bcrypt('x'),
            'role' => 'support', 'is_active' => true,
        ]);

        $this->actingAs($support, 'admin')->get('/admin/validators/kyc')->assertForbidden();
    }

    // =========================================================================
    // ยืนยันรายการ — ต้องตรวจกับเชนจริง
    // =========================================================================

    public function test_confirms_a_submit_that_used_the_site_kyc_hash(): void
    {
        $submission = $this->approvedSiteKyc();
        $hash = app(ValidatorKycHasher::class)->hash($submission);
        $this->kycApplicant(self::APPLICANT, 'Submitted', ['kyc_hash' => $hash]);
        $tx = '0x'.str_repeat('1', 64);
        $this->chainTx($tx, $this->kycOwner, $this->kycAddress, $this->submitInput($hash));

        $this->postJson('/admin/validators/kyc/'.self::APPLICANT.'/confirm', [
            'action' => 'submit',
            'tx_hash' => $tx,
            'kyc_submission_uuid' => $submission->uuid,
        ])->assertOk()->assertJsonPath('data.record.status', 'Submitted');

        $row = ValidatorKycAction::sole();
        $this->assertSame('submit', $row->action);
        $this->assertSame($hash, $row->kyc_hash);
        $this->assertSame($submission->id, $row->kyc_submission_id);
        $this->assertSame($this->kycOwner, $row->signer);
        $this->assertSame($this->admin->id, $row->admin_user_id);

        // กดซ้ำ = แถวเดิม ไม่สร้างใหม่
        $this->postJson('/admin/validators/kyc/'.self::APPLICANT.'/confirm', [
            'action' => 'submit', 'tx_hash' => $tx, 'kyc_submission_uuid' => $submission->uuid,
        ])->assertOk();
        $this->assertSame(1, ValidatorKycAction::count());
    }

    public function test_manual_hash_needs_a_note(): void
    {
        $hash = '0x'.str_repeat('cd', 32);
        $this->kycApplicant(self::APPLICANT, 'Submitted', ['kyc_hash' => $hash]);
        $tx = '0x'.str_repeat('2', 64);
        $this->chainTx($tx, $this->kycOwner, $this->kycAddress, $this->submitInput($hash));

        $this->postJson('/admin/validators/kyc/'.self::APPLICANT.'/confirm', ['action' => 'submit', 'tx_hash' => $tx])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOTE_REQUIRED');

        $this->postJson('/admin/validators/kyc/'.self::APPLICANT.'/confirm', [
            'action' => 'submit', 'tx_hash' => $tx, 'note' => 'ตรวจเอกสารตัวจริงที่สำนักงาน 21 ต.ค.',
        ])->assertOk();
        $this->assertNull(ValidatorKycAction::sole()->kyc_submission_id);
    }

    public function test_a_hash_that_does_not_come_from_the_claimed_submission_is_refused(): void
    {
        $submission = $this->approvedSiteKyc();
        $hash = '0x'.str_repeat('ee', 32);
        $this->kycApplicant(self::APPLICANT, 'Submitted', ['kyc_hash' => $hash]);
        $tx = '0x'.str_repeat('3', 64);
        $this->chainTx($tx, $this->kycOwner, $this->kycAddress, $this->submitInput($hash));

        $this->postJson('/admin/validators/kyc/'.self::APPLICANT.'/confirm', [
            'action' => 'submit', 'tx_hash' => $tx, 'kyc_submission_uuid' => $submission->uuid,
        ])->assertStatus(422)->assertJsonPath('error.code', 'HASH_NOT_FROM_SUBMISSION');
        $this->assertSame(0, ValidatorKycAction::count());
    }

    public function test_refuses_anything_the_chain_does_not_back(): void
    {
        $this->kycApplicant(self::APPLICANT, 'Approved');
        $url = '/admin/validators/kyc/'.self::APPLICANT.'/confirm';

        // ไม่มี tx บนเชน
        $this->postJson($url, ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('4', 64)])
            ->assertStatus(409)->assertJsonPath('error.code', 'TX_NOT_FOUND');

        // revert
        $this->chainTx('0x'.str_repeat('5', 64), $this->kycOwner, $this->kycAddress, $this->approveInput(), false);
        $this->postJson($url, ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('5', 64)])
            ->assertJsonPath('error.code', 'TX_FAILED');

        // คนละสัญญา
        $this->chainTx('0x'.str_repeat('6', 64), $this->kycOwner, '0x'.str_repeat('9', 40), $this->approveInput());
        $this->postJson($url, ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('6', 64)])
            ->assertJsonPath('error.code', 'TX_WRONG_CONTRACT');

        // คนละผู้สมัคร
        $this->chainTx('0x'.str_repeat('7', 64), $this->kycOwner, $this->kycAddress, $this->approveInput('0x'.str_repeat('8', 40)));
        $this->postJson($url, ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('7', 64)])
            ->assertJsonPath('error.code', 'TX_MISMATCH');

        // บอกว่า reject แต่ tx คือ approve
        $this->chainTx('0x'.str_repeat('a', 64), $this->kycOwner, $this->kycAddress, $this->approveInput());
        $this->postJson($url, ['action' => 'reject', 'tx_hash' => '0x'.str_repeat('a', 64)])
            ->assertJsonPath('error.code', 'TX_MISMATCH');

        // ผู้เซ็นไม่ใช่ owner
        $this->chainTx('0x'.str_repeat('b', 64), '0x'.str_repeat('c', 40), $this->kycAddress, $this->approveInput());
        $this->postJson($url, ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('b', 64)])
            ->assertJsonPath('error.code', 'TX_NOT_OWNER');

        $this->assertSame(0, ValidatorKycAction::count());

        // ตรงทุกข้อ
        $this->chainTx('0x'.str_repeat('d', 64), $this->kycOwner, $this->kycAddress, $this->approveInput());
        $this->postJson($url, ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('d', 64)])->assertOk();
        $this->assertSame('Approved', ValidatorKycAction::sole()->chain_status);
    }

    public function test_chain_state_must_have_moved(): void
    {
        // tx ถูกต้องแต่สถานะบนสัญญายังเป็น Submitted (RPC ตามไม่ทัน) — ห้ามบันทึกว่าอนุมัติแล้ว
        $this->kycApplicant(self::APPLICANT, 'Submitted');
        $this->chainTx('0x'.str_repeat('e', 64), $this->kycOwner, $this->kycAddress, $this->approveInput());

        $this->postJson('/admin/validators/kyc/'.self::APPLICANT.'/confirm', ['action' => 'approve', 'tx_hash' => '0x'.str_repeat('e', 64)])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHAIN_STATE_MISMATCH');
    }

    // =========================================================================
    // hash ของใบ KYC
    // =========================================================================

    public function test_hash_tracks_the_documents_and_skips_purged_submissions(): void
    {
        $submission = $this->approvedSiteKyc();
        $hasher = app(ValidatorKycHasher::class);
        $before = $hasher->hash($submission);

        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $before);
        $this->assertSame($before, $hasher->hash($submission->fresh()), 'คำนวณซ้ำต้องได้ค่าเดิม');

        KycDocument::create([
            'kyc_submission_id' => $submission->id, 'type' => KycDocument::TYPE_SELFIE, 'disk' => 'kyc',
            'path' => 'demo/selfie.jpg', 'original_name' => 'selfie.jpg', 'mime' => 'image/jpeg', 'size' => 8,
            'sha256' => hash('sha256', 'selfie-bytes'),
        ]);
        $this->assertNotSame($before, $hasher->hash($submission->fresh()));

        $submission->update(['purged_at' => now()]);
        $this->assertNull($hasher->approvedSubmissionFor(self::APPLICANT));
        $summary = $hasher->siteKycSummary(self::APPLICANT);
        $this->assertSame('purged', $summary['status'], 'ใบที่ล้างแล้วห้ามโชว์ว่า "ผ่าน" ทั้งที่พิสูจน์ไม่ได้แล้ว');
        $this->assertNull($summary['doc_hash']);
    }
}
