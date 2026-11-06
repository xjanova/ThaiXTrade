<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\CreatorRegistration;
use App\Services\CreatorRegistration\CreatorAgreement;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ลงทะเบียนผู้สร้างเหรียญฝั่งผู้สร้าง — เข้าด้วยกระเป๋า → กรอกฟอร์ม → KYC → เซ็นข้อตกลง.
 *
 * ทุกลายเซ็นในเทสต์เป็นลายเซ็นจริง ตัวตรวจ ecrecover ของเว็บจึงถูกทดสอบตรงๆ
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationFlowTest extends TestCase
{
    use CreatorRegistrationHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // =========================================================================
    // เข้าด้วยกระเป๋า
    // =========================================================================

    #[Test]
    public function เปิดหน้าลงทะเบียนได้โดยไม่ต้องล็อกอิน_และมีข้อตกลงฉบับปัจจุบัน(): void
    {
        $this->get('/token-creator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TokenCreator/Register')
                ->where('registration', null)
                ->where('agreement.version', CreatorAgreement::CURRENT)
                ->where('agreement.hash', app(CreatorAgreement::class)->hash())
                ->where('kyc.approved', false)
                ->has('kyc.thaiprompt.download_url')
                ->has('kyc.thaiprompt.app_link')
                ->where('kyc.thaiprompt.link', null));
    }

    #[Test]
    public function เซ็นยืนยันกระเป๋าแล้วได้ใบร่าง_และเข้าสู่ระบบด้วยกระเป๋านั้น(): void
    {
        [, $wallet] = $this->startRegistration();

        $registration = $this->registrationOf($wallet);
        $this->assertSame(CreatorRegistration::STATUS_DRAFT, $registration->status);
        $this->assertSame(strtolower($wallet), $registration->wallet_address);
        $this->assertNotNull($registration->ownership_signature);
        $this->assertStringContainsString('Wallet: '.strtolower($wallet), $registration->ownership_message);

        $this->assertAuthenticatedAs($this->userOf($wallet), 'web');
        $this->assertSame($registration->user_id, $this->userOf($wallet)->id);
    }

    #[Test]
    public function ลายเซ็นจากกระเป๋าอื่นถูกปฏิเสธ_และไม่สร้างใบ(): void
    {
        $wallet = $this->addressOf($this->testKey(1));
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])->json('data');

        $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($this->testKey(2), $challenge['message']),
        ])->assertStatus(403)->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertSame(0, CreatorRegistration::count());
        $this->assertGuest('web');
    }

    #[Test]
    public function nonce_ใช้ได้ครั้งเดียว_ส่งซ้ำถูกปฏิเสธ(): void
    {
        $priv = $this->testKey(1);
        $wallet = $this->addressOf($priv);
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])->json('data');
        $payload = [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ];

        $this->postJson('/token-creator/start', $payload)->assertOk();
        $this->postJson('/token-creator/start', $payload)->assertStatus(422)->assertJsonPath('error.code', 'NONCE_EXPIRED');
    }

    #[Test]
    public function nonce_ที่ลายเซ็นผิดก็ถูกเผาทิ้ง_เดาซ้ำไม่ได้(): void
    {
        $priv = $this->testKey(1);
        $wallet = $this->addressOf($priv);
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])->json('data');

        $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($this->testKey(9), $challenge['message']),
        ])->assertStatus(403);

        // ลายเซ็นถูกแล้วแต่ nonce ใช้ไปแล้ว
        $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ])->assertStatus(422)->assertJsonPath('error.code', 'NONCE_EXPIRED');
    }

    #[Test]
    public function nonce_ของกระเป๋าหนึ่งเอาไปใช้กับอีกกระเป๋าไม่ได้(): void
    {
        $walletA = $this->addressOf($this->testKey(1));
        $privB = $this->testKey(2);
        $walletB = $this->addressOf($privB);
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $walletA])->json('data');

        $this->postJson('/token-creator/start', [
            'wallet_address' => $walletB,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($privB, $challenge['message']),
        ])->assertStatus(422)->assertJsonPath('error.code', 'NONCE_EXPIRED');
    }

    #[Test]
    public function เริ่มซ้ำด้วยกระเป๋าเดิม_ได้ใบเดิมไม่สร้างใหม่(): void
    {
        [, $wallet] = $this->startRegistration();
        $uuid = $this->registrationOf($wallet)->uuid;

        $this->startRegistration();

        $this->assertSame(1, CreatorRegistration::count());
        $this->assertSame($uuid, $this->registrationOf($wallet)->uuid);
    }

    #[Test]
    public function ไม่มีช่องรับ_private_key_ที่ไหนเลย(): void
    {
        $priv = $this->testKey(1);
        $wallet = $this->addressOf($priv);
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])->json('data');

        $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
            'private_key' => $priv,
        ])->assertOk();

        foreach ((array) DB::table('creator_registrations')->first() as $value) {
            $this->assertStringNotContainsString($priv, (string) $value);
        }
    }

    // =========================================================================
    // ฟอร์ม
    // =========================================================================

    #[Test]
    public function บันทึกฟอร์มได้_ข้อมูลส่วนตัวเข้ารหัสในฐานข้อมูล(): void
    {
        [, $wallet] = $this->startRegistration();

        $this->saveForm()->assertOk()->assertJsonPath('data.registration.form.token_symbol', 'SFARM');

        $registration = $this->registrationOf($wallet);
        $this->assertSame('Siam Farm', $registration->project_name);
        $this->assertSame('SFARM', $registration->token_symbol);
        $this->assertSame(64, strlen($registration->form_hash));
        $this->assertSame('สมชาย ใจดี', $registration->form_data['full_name']);

        $raw = DB::table('creator_registrations')->value('form_data');
        $this->assertStringNotContainsString('somchai@example.com', $raw);
        $this->assertStringNotContainsString('สมชาย', $raw);
    }

    #[Test]
    public function ฟอร์มนิติบุคคลไม่เก็บช่องของบุคคลธรรมดา(): void
    {
        [, $wallet] = $this->startRegistration();

        $this->saveForm([
            'applicant_type' => 'company',
            'company_name' => 'บริษัท สยามฟาร์ม จำกัด',
            'registration_number' => '0105566012345',
            'registration_country' => 'th',
        ])->assertOk();

        $form = $this->registrationOf($wallet)->form_data;
        $this->assertSame('บริษัท สยามฟาร์ม จำกัด', $form['company_name']);
        $this->assertSame('TH', $form['registration_country']);
        $this->assertArrayNotHasKey('full_name', $form);
        $this->assertArrayNotHasKey('date_of_birth', $form);
    }

    #[Test]
    public function ฟอร์มผิดได้ข้อความภาษาไทย(): void
    {
        $this->startRegistration();

        $this->saveForm([
            'token_symbol' => 'SF-1',
            'date_of_birth' => now()->subYears(18)->toDateString(),
            'website' => 'http://insecure.example',
            'declare_true' => false,
        ])->assertStatus(422)
            ->assertJsonPath('errors.token_symbol.0', 'สัญลักษณ์เหรียญต้องเป็นตัวอักษรอังกฤษพิมพ์ใหญ่หรือตัวเลข 2–11 ตัว')
            ->assertJsonPath('errors.date_of_birth.0', 'ผู้สร้างต้องมีอายุ 20 ปีบริบูรณ์ขึ้นไป')
            ->assertJsonValidationErrors(['website', 'declare_true']);
    }

    #[Test]
    public function ฟอร์มผิดได้ข้อความภาษาอังกฤษเมื่อเลือกภาษาอังกฤษ(): void
    {
        $this->startRegistration();

        $response = $this->putJson('/token-creator/form', $this->validForm(['token_symbol' => 'SF-1']), ['X-Locale' => 'en'])
            ->assertStatus(422);

        $message = $response->json('errors.token_symbol.0');
        $this->assertDoesNotMatchRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $message);
    }

    #[Test]
    public function ผู้ที่ประกาศว่าเป็น_pe_p_ต้องให้รายละเอียด(): void
    {
        $this->startRegistration();

        $this->saveForm(['is_pep' => true])->assertStatus(422)->assertJsonValidationErrors('pep_details');
        $this->saveForm(['is_pep' => true, 'pep_details' => 'อดีตสมาชิกสภาท้องถิ่น'])->assertOk();
    }

    #[Test]
    public function ยังไม่เริ่มลงทะเบียน_บันทึกฟอร์มไม่ได้(): void
    {
        $this->putJson('/token-creator/form', $this->validForm())->assertUnauthorized();
    }

    // =========================================================================
    // KYC + เซ็นข้อตกลง
    // =========================================================================

    #[Test]
    public function ยังไม่ผ่าน_ky_c_ขอข้อความข้อตกลงไม่ได้(): void
    {
        $this->startRegistration();
        $this->saveForm()->assertOk();

        $this->postJson('/token-creator/agreement/challenge')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'KYC_REQUIRED');
    }

    #[Test]
    public function ยังไม่กรอกฟอร์ม_ขอข้อความข้อตกลงไม่ได้(): void
    {
        [, $wallet] = $this->startRegistration();
        $this->approveKyc($this->userOf($wallet));

        $this->postJson('/token-creator/agreement/challenge')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FORM_INCOMPLETE');
    }

    #[Test]
    public function ข้อความข้อตกลงผูกฉบับ_แฮชเอกสาร_และแฮชฟอร์ม(): void
    {
        [, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));

        $data = $this->postJson('/token-creator/agreement/challenge')->assertOk()->json('data');
        $registration = $this->registrationOf($wallet);

        $this->assertSame(CreatorAgreement::CURRENT, $data['agreement_version']);
        $this->assertStringContainsString('Agreement SHA-256: '.app(CreatorAgreement::class)->hash(), $data['message']);
        $this->assertStringContainsString('Application SHA-256: '.$registration->form_hash, $data['message']);
        $this->assertStringContainsString('Registration: '.$registration->uuid, $data['message']);
        $this->assertTrue(mb_check_encoding($data['message'], 'ASCII'), 'ข้อความที่เซ็นต้องเป็น ASCII ล้วน');
    }

    #[Test]
    public function เซ็นข้อตกลงแล้วส่งตรวจ_เก็บหลักฐานครบ(): void
    {
        [, $wallet] = $this->submittedRegistration();

        $registration = $this->registrationOf($wallet);
        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, $registration->status);
        $this->assertSame(CreatorAgreement::CURRENT, $registration->agreement_version);
        $this->assertSame(app(CreatorAgreement::class)->hash(), $registration->agreement_hash);
        $this->assertNotNull($registration->agreement_signed_at);
        $this->assertNotNull($registration->submitted_at);
        $this->assertSame('127.0.0.1', $registration->agreement_ip);

        $evidence = app(CreatorRegistrationService::class)->verifyEvidence($registration);
        $this->assertSame([true, true, true, true, true], array_values($evidence));
    }

    #[Test]
    public function ลายเซ็นข้อตกลงจากกระเป๋าอื่นถูกปฏิเสธ(): void
    {
        [, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));

        $challenge = $this->postJson('/token-creator/agreement/challenge')->json('data');
        $this->postJson('/token-creator/submit', [
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($this->testKey(7), $challenge['message']),
        ])->assertStatus(403)->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertSame(CreatorRegistration::STATUS_DRAFT, $this->registrationOf($wallet)->status);
    }

    #[Test]
    public function แก้ฟอร์มระหว่างเซ็น_ลายเซ็นเก่าใช้ไม่ได้(): void
    {
        [$priv, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));

        $challenge = $this->postJson('/token-creator/agreement/challenge')->json('data');
        $this->saveForm(['project_name' => 'Siam Farm 2'])->assertOk();

        $this->postJson('/token-creator/submit', [
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ])->assertStatus(409)->assertJsonPath('error.code', 'FORM_CHANGED');
    }

    #[Test]
    public function ส่งตรวจซ้ำด้วยลายเซ็นเดิมไม่ได้_และส่งแล้วแก้ฟอร์มไม่ได้(): void
    {
        [$priv] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($this->addressOf($priv)));

        $challenge = $this->postJson('/token-creator/agreement/challenge')->json('data');
        $payload = ['nonce' => $challenge['nonce'], 'signature' => $this->personalSign($priv, $challenge['message'])];

        $this->postJson('/token-creator/submit', $payload)->assertOk();
        $this->postJson('/token-creator/submit', $payload)->assertStatus(422)->assertJsonPath('error.code', 'NONCE_EXPIRED');

        $this->saveForm(['project_name' => 'แก้หลังส่ง'])->assertStatus(409)->assertJsonPath('error.code', 'NOT_EDITABLE');
        $this->postJson('/token-creator/agreement/challenge')->assertStatus(409)->assertJsonPath('error.code', 'NOT_EDITABLE');
    }

    // =========================================================================
    // PDF + การเข้าถึง
    // =========================================================================

    #[Test]
    public function ดาวน์โหลด_pd_f_ข้อตกลงที่เซ็นแล้วได้(): void
    {
        $this->submittedRegistration();

        $response = $this->get('/token-creator/pdf')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('TPIX-Creator-Agreement-SFARM-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function ยังไม่กรอกฟอร์ม_ไม่มี_pdf(): void
    {
        $this->startRegistration();

        $this->get('/token-creator/pdf')->assertNotFound();
    }

    #[Test]
    public function ผู้ใช้อื่นเห็นแค่ใบของตัวเอง(): void
    {
        [, $walletA] = $this->submittedRegistration(1);

        Auth::guard('web')->logout();
        $this->flushSession();
        [, $walletB] = $this->startRegistration(2);

        $this->get('/token-creator')->assertInertia(fn ($page) => $page
            ->where('registration.wallet_address', strtolower($walletB))
            ->where('registration.status', 'draft'));

        // ใบของ B ยังไม่มีฟอร์ม → ไม่มี PDF (ไม่ได้ PDF ของ A)
        $this->get('/token-creator/pdf')->assertNotFound();
        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, $this->registrationOf($walletA)->status);
    }

    #[Test]
    public function เส้นที่ต้องล็อกอินปฏิเสธผู้เยี่ยมชม(): void
    {
        $this->postJson('/token-creator/agreement/challenge')->assertUnauthorized();
        $this->postJson('/token-creator/submit', ['nonce' => str_repeat('a', 32), 'signature' => '0x'.str_repeat('1', 130)])->assertUnauthorized();
        $this->get('/token-creator/pdf')->assertRedirect();
    }
}
