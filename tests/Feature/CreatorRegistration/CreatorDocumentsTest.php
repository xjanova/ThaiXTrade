<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\AuditLog;
use App\Models\CreatorRegistration;
use App\Models\CreatorRegistrationDocument as Document;
use App\Models\FactoryToken;
use App\Services\TokenFactory\TpixRpcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * เอกสารประกอบใบลงทะเบียนผู้สร้างเหรียญ + หน้าโครงการสาธารณะ (ภาพลายน้ำ PDPA).
 *
 * - นิติบุคคลต้องมีหนังสือรับรองบริษัทก่อนเซ็น บุคคลธรรมดาไม่ต้อง
 * - ลายเซ็นผูกรายการเอกสาร: แก้เอกสาร = ลายเซ็นเดิมใช้ไม่ได้
 * - ผู้ซื้อเห็นเฉพาะภาพที่ผู้สร้างเลือกเผยแพร่ เป็น JPEG ลายน้ำ ไม่เคยได้ไฟล์ต้นฉบับ บัตรเห็นเฉพาะแอดมิน
 *
 * Developed by Xman Studio.
 */
class CreatorDocumentsTest extends TestCase
{
    use CreatorRegistrationHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake(Document::DISK);
    }

    private function companyForm(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => 'company',
            'company_name' => 'บริษัท สยามฟาร์ม จำกัด',
            'company_name_en' => 'Siam Farm Co., Ltd.',
            'registration_number' => '0105566000001',
            'registration_country' => 'TH',
            'signatory_name' => 'สมชาย ใจดี',
            'signatory_position' => 'กรรมการผู้จัดการ',
        ], $overrides);
    }

    private function upload(string $type, ?UploadedFile $file = null, bool $publish = false): TestResponse
    {
        return $this->post('/token-creator/documents', [
            'type' => $type,
            'file' => $file ?? UploadedFile::fake()->image('photo.jpg', 640, 480),
            'publish' => $publish ? '1' : '0',
        ], ['Accept' => 'application/json']);
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cert.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** ใบที่อนุมัติแล้ว มีรูปโครงการเผยแพร่ 1 + ไม่เผยแพร่ 1 + บัตร 1 — คืน registration */
    private function approvedWithDocuments(array $form = []): CreatorRegistration
    {
        [$priv, $wallet] = $this->startRegistration();
        $this->saveForm($form)->assertOk();
        $this->upload('project_photo', publish: true)->assertOk();
        $this->upload('project_photo', UploadedFile::fake()->image('private.png', 300, 300))->assertOk();
        $this->upload('signatory_id', publish: true)->assertOk();
        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk()->assertJsonPath('data.registration.status', 'submitted');
        Auth::guard('web')->logout();

        $uuid = $this->registrationOf($wallet)->uuid;
        $this->asAdmin($this->makeAdmin())
            ->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])
            ->assertSessionHasNoErrors();

        return $this->registrationOf($wallet)->load('documents');
    }

    // ─── อัปโหลด ────────────────────────────────────────────────────────────

    #[Test]
    public function บุคคลธรรมดาแนบรูปโครงการได้_เก็บในดิสก์ส่วนตัวและผูกแฮชรายการ(): void
    {
        [, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();

        $res = $this->upload('project_photo', publish: true)
            ->assertOk()
            ->assertJsonPath('data.registration.documents.0.type', 'project_photo')
            ->assertJsonPath('data.registration.documents.0.publish', true)
            ->assertJsonPath('data.registration.documents.0.publishable', true)
            ->assertJsonPath('data.registration.missing_documents', [])
            ->assertJsonMissingPath('data.registration.documents.0.path');

        $doc = Document::firstOrFail();
        Storage::disk(Document::DISK)->assertExists($doc->path);
        $this->assertSame(64, strlen($doc->sha256));
        $this->assertSame(
            $res->json('data.registration.documents_hash'),
            $this->registrationOf($wallet)->documents_hash,
        );
        $this->assertNotNull($this->registrationOf($wallet)->documents_hash);
    }

    #[Test]
    public function บุคคลธรรมดาแนบเอกสารบริษัทไม่ได้(): void
    {
        $this->startRegistration();
        $this->saveForm()->assertOk();

        $this->upload('company_certificate', $this->fakePdf())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPANY_ONLY');
        $this->assertSame(0, Document::count());
    }

    #[Test]
    public function ไฟล์ที่ไม่ใช่ภาพหรือ_pd_f_ถูกปฏิเสธ_และประเภทที่ไม่รู้จักถูกปฏิเสธ(): void
    {
        $this->startRegistration();
        $this->saveForm()->assertOk();

        // นามสกุล .jpg แต่เนื้อเป็นสคริปต์ — ชั้นบริการเปิดภาพไม่ได้ก็ปฏิเสธ (ไม่เชื่อนามสกุล)
        $this->upload('project_photo', UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1; ?>'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_FILE');
        $this->upload('company_other', UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf'))
            ->assertStatus(422);
        $this->upload('project_photo', UploadedFile::fake()->createWithContent('notes.txt', 'hello'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
        $this->upload('passport_scan')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
        $this->upload('project_photo', UploadedFile::fake()->image('huge.jpg')->size(Document::MAX_KB + 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
    }

    #[Test]
    public function ต้องบันทึกฟอร์มก่อนแนบเอกสาร(): void
    {
        $this->startRegistration();

        $this->upload('project_photo')->assertStatus(422)->assertJsonPath('error.code', 'FORM_INCOMPLETE');
    }

    #[Test]
    public function แนบเกินจำนวนต่อประเภทไม่ได้(): void
    {
        $this->startRegistration();
        $this->saveForm($this->companyForm())->assertOk();

        $max = Document::TYPES['vat_certificate']['max'];
        for ($i = 0; $i < $max; $i++) {
            $this->upload('vat_certificate', $this->fakePdf())->assertOk();
        }
        $this->upload('vat_certificate', $this->fakePdf())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOO_MANY_OF_TYPE');
    }

    #[Test]
    public function pdf_เผยแพร่ไม่ได้_บัตรเผยแพร่ไม่ได้(): void
    {
        $this->startRegistration();
        $this->saveForm($this->companyForm())->assertOk();

        $this->upload('company_certificate', $this->fakePdf(), publish: true)
            ->assertOk()
            ->assertJsonPath('data.registration.documents.0.publish', false)
            ->assertJsonPath('data.registration.documents.0.publishable', false);
        $pdf = Document::firstOrFail();
        $this->patchJson("/token-creator/documents/{$pdf->uuid}", ['publish' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOT_PUBLISHABLE');

        $this->upload('signatory_id', publish: true)->assertOk();
        $id = Document::where('type', 'signatory_id')->firstOrFail();
        $this->assertFalse($id->publish);
        $this->patchJson("/token-creator/documents/{$id->uuid}", ['publish' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOT_PUBLISHABLE');
    }

    #[Test]
    public function สลับเผยแพร่และลบได้_แฮชรายการเปลี่ยนตาม(): void
    {
        [, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->upload('project_photo')->assertOk();
        $doc = Document::firstOrFail();
        $before = $this->registrationOf($wallet)->documents_hash;

        $this->patchJson("/token-creator/documents/{$doc->uuid}", ['publish' => true])
            ->assertOk()
            ->assertJsonPath('data.registration.documents.0.publish', true);
        $after = $this->registrationOf($wallet)->documents_hash;
        $this->assertNotSame($before, $after);

        $this->deleteJson("/token-creator/documents/{$doc->uuid}")->assertOk()->assertJsonPath('data.registration.documents', []);
        $this->assertNull($this->registrationOf($wallet)->documents_hash);
        Storage::disk(Document::DISK)->assertMissing($doc->path);
    }

    #[Test]
    public function เปิดหรือแก้เอกสารของคนอื่นไม่ได้(): void
    {
        $this->startRegistration(1);
        $this->saveForm()->assertOk();
        $this->upload('project_photo')->assertOk();
        $doc = Document::firstOrFail();
        Auth::guard('web')->logout();

        $this->startRegistration(2);
        $this->saveForm()->assertOk();

        $this->get("/token-creator/documents/{$doc->uuid}")->assertNotFound();
        $this->patchJson("/token-creator/documents/{$doc->uuid}", ['publish' => true])->assertNotFound();
        $this->deleteJson("/token-creator/documents/{$doc->uuid}")->assertNotFound();
        $this->assertDatabaseHas('creator_registration_documents', ['uuid' => $doc->uuid, 'publish' => false]);
    }

    #[Test]
    public function เจ้าของเปิดไฟล์ต้นฉบับได้_พร้อม_header_กันสคริปต์(): void
    {
        $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->upload('project_photo')->assertOk();
        $doc = Document::firstOrFail();

        $res = $this->get("/token-creator/documents/{$doc->uuid}")->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('photo.jpg', (string) $res->headers->get('Content-Disposition'));
    }

    // ─── ลายเซ็นผูกเอกสาร ────────────────────────────────────────────────────

    #[Test]
    public function นิติบุคคลต้องแนบหนังสือรับรองก่อนเซ็น_และข้อความที่เซ็นผูกแฮชเอกสาร(): void
    {
        [$priv, $wallet] = $this->startRegistration();
        $this->saveForm($this->companyForm())
            ->assertOk()
            ->assertJsonPath('data.registration.missing_documents', ['company_certificate']);
        $this->approveKyc($this->userOf($wallet));

        $this->postJson('/token-creator/agreement/challenge')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENTS_REQUIRED');

        $this->upload('company_certificate', $this->fakePdf())->assertOk()->assertJsonPath('data.registration.missing_documents', []);
        $hash = $this->registrationOf($wallet)->documents_hash;

        $challenge = $this->postJson('/token-creator/agreement/challenge')->assertOk()->json('data');
        $this->assertStringContainsString("Documents SHA-256: {$hash}", $challenge['message']);

        $this->postJson('/token-creator/submit', [
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ])->assertOk()->assertJsonPath('data.registration.status', 'submitted');
    }

    #[Test]
    public function แก้เอกสารระหว่างขอข้อความกับส่ง_ส่งไม่ผ่าน(): void
    {
        [$priv, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));

        $challenge = $this->postJson('/token-creator/agreement/challenge')->assertOk()->json('data');
        $this->upload('project_photo')->assertOk();   // แท็บอื่นแนบไฟล์เพิ่มหลังขอข้อความ

        $this->postJson('/token-creator/submit', [
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ])->assertStatus(409)->assertJsonPath('error.code', 'FORM_CHANGED');

        $this->assertSame(CreatorRegistration::STATUS_DRAFT, $this->registrationOf($wallet)->status);
    }

    #[Test]
    public function ส่งตรวจแล้วแก้เอกสารไม่ได้(): void
    {
        $this->submittedRegistration();

        $this->upload('project_photo')->assertStatus(409)->assertJsonPath('error.code', 'NOT_EDITABLE');
    }

    #[Test]
    public function ใบที่ถูกปฏิเสธ_แก้เอกสารแล้วลายเซ็นเดิมถูกล้าง(): void
    {
        [, $wallet] = $this->submittedRegistration();
        $this->registrationOf($wallet)->update(['status' => CreatorRegistration::STATUS_REJECTED, 'rejection_reason' => 'ขอรูปโครงการเพิ่ม']);

        $this->upload('project_photo')->assertOk()->assertJsonPath('data.registration.signed', false);

        $r = $this->registrationOf($wallet);
        $this->assertSame(CreatorRegistration::STATUS_DRAFT, $r->status);
        $this->assertNull($r->agreement_signature);
    }

    #[Test]
    public function เปลี่ยนเป็นบุคคลธรรมดาขณะมีเอกสารบริษัท_ระบบไม่ลบให้เงียบๆ(): void
    {
        $this->startRegistration();
        $this->saveForm($this->companyForm())->assertOk();
        $this->upload('company_certificate', $this->fakePdf())->assertOk();

        $this->saveForm(['applicant_type' => 'individual'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'COMPANY_DOCUMENTS_PRESENT');
        $this->assertSame(1, Document::count());
    }

    #[Test]
    public function เอกสารถูกสลับหลังเซ็น_หลักฐานไม่ผ่านและอนุมัติไม่ได้(): void
    {
        [$priv, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->upload('project_photo')->assertOk();
        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk();
        Auth::guard('web')->logout();

        // มีคนแก้ฐานข้อมูลตรงๆ หลังเซ็น (เปิดเผยเอกสารที่ผู้สร้างไม่ได้ยินยอม)
        Document::query()->update(['publish' => true]);
        $uuid = $this->registrationOf($wallet)->uuid;
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->get("/admin/creator-registrations/{$uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('evidence.documents_hash_matches', false));
        $this->asAdmin($admin)
            ->post("/admin/creator-registrations/{$uuid}/approve", ['starter_allocation_bps' => 1000])
            ->assertSessionHasErrors('registration');

        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, $this->registrationOf($wallet)->status);
    }

    // ─── หลังบ้าน ────────────────────────────────────────────────────────────

    #[Test]
    public function แอดมินเปิดไฟล์ต้นฉบับได้_และบันทึก_audit(): void
    {
        [$priv, $wallet] = $this->startRegistration();
        $this->saveForm()->assertOk();
        $this->upload('signatory_id')->assertOk();
        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk();
        Auth::guard('web')->logout();

        $r = $this->registrationOf($wallet);
        $doc = Document::firstOrFail();

        $this->get("/admin/creator-registrations/{$r->uuid}/documents/{$doc->uuid}")->assertRedirect();
        $this->assertFalse(AuditLog::where('action', 'creator_registration.document_view')->exists());

        $this->asAdmin($this->makeAdmin())
            ->get("/admin/creator-registrations/{$r->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('registration.documents.0.type', 'signatory_id')
                ->where('registration.documents.0.public', false)
                ->where('evidence.documents_hash_matches', true));
        $this->asAdmin($this->makeAdmin('admin', 'ops@tpix.test'))
            ->get("/admin/creator-registrations/{$r->uuid}/documents/{$doc->uuid}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertTrue(AuditLog::where('action', 'creator_registration.document_view')->exists());
    }

    // ─── หน้าโครงการสาธารณะ ─────────────────────────────────────────────────

    #[Test]
    public function หน้าโครงการเปิดได้เมื่ออนุมัติและยินยอม_ไม่มีข้อมูลติดต่อหรือบัตร(): void
    {
        $r = $this->approvedWithDocuments();
        $published = $r->documents->first(fn ($d) => $d->type === 'project_photo' && $d->publish);

        $res = $this->get("/token-creator/projects/{$r->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TokenCreator/Project')
                ->where('project.project_name', 'Siam Farm')
                ->where('project.kyc_verified', true)
                ->where('project.entity', [])   // ไม่ยินยอมเผยแพร่ชื่อ
                ->has('project.documents', 1)
                ->where('project.documents.0.uuid', $published->uuid)
                ->has('risks', 6));

        $json = json_encode($res->viewData('page'), JSON_UNESCAPED_UNICODE);
        foreach (['somchai@example.com', '+66812345678', 'ถนนสุขุมวิท', '1990-01-15', 'สมชาย', 'signatory_id', 'own_funds'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    #[Test]
    public function ยินยอมเผยแพร่ชื่อ_หน้าโครงการแสดงชื่อ(): void
    {
        $r = $this->approvedWithDocuments(['consent_publish_name' => true]);

        $this->get("/token-creator/projects/{$r->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('project.entity.full_name', 'สมชาย ใจดี'));
    }

    #[Test]
    public function หน้าโครงการตอบ404เมื่อยังไม่อนุมัติหรือไม่ยินยอม(): void
    {
        [, $wallet] = $this->submittedRegistration();
        $r = $this->registrationOf($wallet);
        Auth::guard('web')->logout();

        $this->get("/token-creator/projects/{$r->uuid}")->assertNotFound();

        // อนุมัติแล้วแต่ใบรอบแรกไม่มีความยินยอม
        $r->update([
            'status' => CreatorRegistration::STATUS_APPROVED,
            'form_data' => array_merge($r->form_data, ['consent_publish_project' => false]),
        ]);
        $this->get("/token-creator/projects/{$r->uuid}")->assertNotFound();
        $this->get('/token-creator/projects/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    #[Test]
    public function ภาพเอกสารสาธารณะเป็น_jpeg_ลายน้ำ_ไม่ใช่ไฟล์ต้นฉบับ(): void
    {
        $r = $this->approvedWithDocuments();
        $published = $r->documents->first(fn ($d) => $d->type === 'project_photo' && $d->publish);

        $res = $this->get("/token-creator/projects/{$r->uuid}/documents/{$published->uuid}")->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));

        $body = $res->getContent();
        $original = Storage::disk(Document::DISK)->get($published->path);
        $this->assertNotSame(hash('sha256', $original), hash('sha256', $body));
        $info = getimagesizefromstring($body);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    #[Test]
    public function ภาพที่ไม่เผยแพร่และบัตรเปิดจากหน้าสาธารณะไม่ได้(): void
    {
        $r = $this->approvedWithDocuments();

        foreach ($r->documents->reject(fn ($d) => $d->type === 'project_photo' && $d->publish) as $doc) {
            $this->get("/token-creator/projects/{$r->uuid}/documents/{$doc->uuid}")->assertNotFound();
        }
    }

    #[Test]
    public function เหรียญของผู้สร้างที่อนุมัติแล้ว_ไวท์เปเปอร์แสดงผู้สร้างและหน้าโครงการลิงก์ไวท์เปเปอร์(): void
    {
        $r = $this->approvedWithDocuments();
        $this->mock(TpixRpcClient::class, fn ($m) => $m->shouldReceive('call')->andThrow(new RuntimeException('down')));
        $token = FactoryToken::create([
            'name' => 'Siam Farm Token', 'symbol' => 'SFARM', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => $r->wallet_address, 'contract_address' => '0x'.str_repeat('12', 20),
            'chain_id' => 4289, 'token_type' => 'standard', 'status' => 'deployed',
        ]);

        $this->get("/token-creator/projects/{$r->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('project.tokens.0.symbol', 'SFARM')
                ->where('project.tokens.0.whitepaper_url', route('token-factory.whitepaper', $token->id)));

        $this->get("/token-factory/{$token->id}/whitepaper")
            ->assertOk()
            ->assertSee('ยืนยันตัวตนผ่าน Thaiprompt แล้ว')
            ->assertSee('Siam Farm')
            ->assertDontSee('somchai@example.com')
            ->assertDontSee('+66812345678');

        $pdf = $this->get("/token-factory/{$token->id}/whitepaper/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    #[Test]
    public function ภาพลายน้ำถูกแคช_ยิงซ้ำไม่ถอดรหัสภาพใหม่(): void
    {
        $r = $this->approvedWithDocuments();
        $published = $r->documents->first(fn ($d) => $d->type === 'project_photo' && $d->publish);
        $url = "/token-creator/projects/{$r->uuid}/documents/{$published->uuid}";

        $first = $this->get($url)->assertOk()->getContent();
        Storage::disk(Document::DISK)->delete($published->path);   // ถ้ายังถอดรหัสใหม่ จะได้ 404

        $this->assertSame($first, $this->get($url)->assertOk()->getContent());
    }

    #[Test]
    public function ไฟล์ต้นฉบับหายจากดิสก์_หน้าสาธารณะตอบ404ไม่ใช่500(): void
    {
        $r = $this->approvedWithDocuments();
        $published = $r->documents->first(fn ($d) => $d->type === 'project_photo' && $d->publish);
        Storage::disk(Document::DISK)->delete($published->path);

        $this->get("/token-creator/projects/{$r->uuid}/documents/{$published->uuid}")->assertNotFound();
    }
}
