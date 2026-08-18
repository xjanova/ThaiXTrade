<?php

namespace Tests\Feature\Kyc;

use App\Models\AdminUser;
use App\Models\KycDeletionRequest;
use App\Models\KycDocument;
use App\Models\KycDocumentView;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\Kyc\KycPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ความเป็นส่วนตัวของเอกสารยืนยันตัวตน + สิทธิตาม PDPA.
 *
 * ไฟล์นี้คุมสามเรื่องที่พลาดแล้วเป็นข่าว:
 *   1. รูปบัตรประชาชนต้องเปิดดูได้เฉพาะเจ้าตัวกับทีมงาน ไม่ใช่ใครก็ได้ที่เดา id ถูก
 *   2. "ลบ" ต้องลบไฟล์จริง ไม่ใช่ตั้งธงว่าลบแล้วโดยไฟล์ยังอยู่
 *   3. ต้องตอบได้ว่าใครเปิดดูข้อมูลของใครเมื่อไหร่
 *
 * Developed by Xman Studio.
 */
class KycPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private AdminUser $admin;

    private KycSubmission $submission;

    private KycDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('kyc');

        $this->owner = User::create([
            'email' => 'owner@tpix.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@tpix.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->admin = AdminUser::create([
            'name' => 'ทีมงานตรวจเอกสาร',
            'email' => 'reviewer@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->submission = KycSubmission::create([
            'user_id' => $this->owner->id,
            'level' => KycSubmission::LEVEL_BASIC,
            'status' => KycSubmission::STATUS_PENDING,
            'full_name' => 'สมหญิง รักษ์ดี',
            'national_id' => '9876543210987',
            'national_id_hash' => KycSubmission::hashNationalId('9876543210987'),
            'address' => '99 ถนนพระราม 4 กรุงเทพฯ',
            'phone' => '0812345678',
            'consent_version' => '1.0',
            'consented_at' => now(),
            'consent_ip' => '203.0.113.5',
            'submitted_at' => now(),
            'purge_after' => now()->addDays(1825),
        ]);

        Storage::disk('kyc')->put('demo/front.jpg', 'fake-image-bytes');

        $this->document = KycDocument::create([
            'kyc_submission_id' => $this->submission->id,
            'type' => KycDocument::TYPE_ID_FRONT,
            'disk' => 'kyc',
            'path' => 'demo/front.jpg',
            'original_name' => 'front.jpg',
            'mime' => 'image/jpeg',
            'size' => 16,
            'sha256' => hash('sha256', 'fake-image-bytes'),
        ]);
    }

    // =========================================================================
    // ใครเปิดดูเอกสารได้บ้าง
    // =========================================================================

    #[Test]
    public function คนที่ไม่ได้ล็อกอินเปิดเอกสารไม่ได้(): void
    {
        $this->get("/kyc/documents/{$this->document->id}")->assertRedirect();
        $this->get("/admin/kyc/documents/{$this->document->id}")->assertRedirect();
    }

    #[Test]
    public function ผู้ใช้คนอื่นเปิดเอกสารของเราไม่ได้(): void
    {
        /*
         * id ของเอกสารเป็นเลขเรียง เดาง่ายมาก
         * ถ้าด่านนี้พัง = ไล่ดูบัตรประชาชนทุกคนในเว็บได้ด้วยการนับ 1, 2, 3
         */
        $this->actingAs($this->stranger)
            ->get("/kyc/documents/{$this->document->id}")
            ->assertForbidden();
    }

    #[Test]
    public function เจ้าของเปิดเอกสารตัวเองได้(): void
    {
        $this->actingAs($this->owner)
            ->get("/kyc/documents/{$this->document->id}")
            ->assertOk();
    }

    #[Test]
    public function ผู้ใช้ทั่วไปเข้าประตูฝั่งแอดมินไม่ได้(): void
    {
        $this->actingAs($this->owner)
            ->get("/admin/kyc/documents/{$this->document->id}")
            ->assertRedirect();

        $this->assertSame(0, KycDocumentView::count());
    }

    #[Test]
    public function แอดมินเปิดเอกสารแล้วถูกบันทึกไว้ทุกครั้ง(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get("/admin/kyc/documents/{$this->document->id}")
            ->assertOk();

        $view = KycDocumentView::first();

        $this->assertNotNull($view);
        $this->assertSame($this->admin->id, $view->admin_user_id);
        $this->assertSame($this->document->id, $view->kyc_document_id);

        // เปิดซ้ำต้องนับใหม่ ไม่ใช่ทับของเดิม
        $this->actingAs($this->admin, 'admin')->get("/admin/kyc/documents/{$this->document->id}");
        $this->assertSame(2, KycDocumentView::count());
    }

    #[Test]
    public function เอกสารที่ล้างแล้วเปิดไม่ได้อีก(): void
    {
        $this->document->purgeFile();

        $this->actingAs($this->admin, 'admin')
            ->get("/admin/kyc/documents/{$this->document->id}")
            ->assertStatus(410);
    }

    // =========================================================================
    // การล้างข้อมูล
    // =========================================================================

    #[Test]
    public function ล้างใบแล้วไฟล์หายจากดิสก์จริง(): void
    {
        Storage::disk('kyc')->assertExists('demo/front.jpg');

        $deleted = app(KycPurgeService::class)->purgeSubmission($this->submission, 'retention');

        $this->assertSame(1, $deleted);
        Storage::disk('kyc')->assertMissing('demo/front.jpg');
        $this->assertNotNull($this->document->fresh()->purged_at);
    }

    #[Test]
    public function ล้างใบแล้วข้อมูลส่วนบุคคลหายจากฐานข้อมูลด้วย(): void
    {
        app(KycPurgeService::class)->purgeSubmission($this->submission, 'retention');

        $raw = DB::table('kyc_submissions')->where('id', $this->submission->id)->first();

        $this->assertNull($raw->full_name);
        $this->assertNull($raw->national_id);
        $this->assertNull($raw->address);
        $this->assertNull($raw->phone);
        $this->assertNull($raw->consent_ip);

        /*
         * ลายนิ้วมือเลขบัตรต้องหายไปด้วย
         *
         * เลขบัตรไทยมี 13 หลักและรูปแบบตายตัว เก็บ HMAC ไว้เท่ากับยังตอบได้ว่า
         * "ใบนี้ใช่ของคนที่ถือเลขนี้ไหม" เมื่อมีเลขมาเทียบ ซึ่งยังเป็นข้อมูลส่วนบุคคล
         */
        $this->assertNull($raw->national_id_hash);

        // แต่โครงว่าเคยตรวจต้องยังอยู่ ไม่งั้นตอบหน่วยงานกำกับไม่ได้
        $this->assertNotNull($raw->submitted_at);
        $this->assertNotNull($raw->purged_at);
        $this->assertSame('retention', $raw->purge_reason);
    }

    #[Test]
    public function ล้างซ้ำไม่พังและไม่นับไฟล์ซ้ำ(): void
    {
        $service = app(KycPurgeService::class);

        $this->assertSame(1, $service->purgeSubmission($this->submission, 'retention'));
        $this->assertSame(0, $service->purgeSubmission($this->submission->fresh(), 'retention'));
    }

    // =========================================================================
    // ระยะเก็บข้อมูล
    // =========================================================================

    #[Test]
    public function คำสั่งล้างเก็บเฉพาะใบที่ครบกำหนด(): void
    {
        // ใบนี้ครบกำหนดแล้วและตรวจเสร็จแล้ว
        $this->submission->forceFill([
            'status' => KycSubmission::STATUS_APPROVED,
            'purge_after' => now()->subDay(),
        ])->save();

        // ใบที่ยังไม่ครบกำหนด
        $future = KycSubmission::create([
            'user_id' => $this->stranger->id,
            'level' => 'basic',
            'status' => KycSubmission::STATUS_APPROVED,
            'full_name' => 'ยังไม่ครบกำหนด',
            'consent_version' => '1.0',
            'consented_at' => now(),
            'submitted_at' => now(),
            'purge_after' => now()->addYear(),
        ]);

        $this->artisan('kyc:purge')->assertSuccessful();

        $this->assertNotNull($this->submission->fresh()->purged_at);
        $this->assertNull($future->fresh()->purged_at);
    }

    #[Test]
    public function ใบที่ยังรอตรวจไม่ถูกล้างแม้ครบกำหนด(): void
    {
        /*
         * ใบที่รอตรวจจนครบระยะเก็บ แปลว่าคิวตรวจค้างนานผิดปกติ
         * ลบทิ้งเงียบๆ = ผู้ใช้รอผลที่ไม่มีวันมา ต้องให้คนเห็นปัญหาก่อน
         */
        $this->submission->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('kyc:purge')->assertSuccessful();

        $this->assertNull($this->submission->fresh()->purged_at);
    }

    // =========================================================================
    // คำขอลบข้อมูล
    // =========================================================================

    #[Test]
    public function ขอลบข้อมูลแล้วแอดมินกดลบให้จริง(): void
    {
        $this->submission->forceFill(['status' => KycSubmission::STATUS_APPROVED])->save();
        $this->owner->forceFill(['kyc_status' => 'approved'])->save();

        $this->actingAs($this->owner)
            ->post('/kyc/deletion-request', ['reason' => 'ไม่ประสงค์ใช้บริการต่อ'])
            ->assertRedirect();

        $request = KycDeletionRequest::first();
        $this->assertNotNull($request);
        $this->assertTrue($request->isPending());

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/deletions/{$request->id}/complete", ['note' => 'ลบตามคำขอ'])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(KycDeletionRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(1, $request->files_deleted);
        Storage::disk('kyc')->assertMissing('demo/front.jpg');

        // สิทธิที่ได้จากการยืนยันตัวตนต้องหายไปด้วย
        $this->assertSame('none', $this->owner->fresh()->kyc_status);
        $this->assertSame('user_request', $this->submission->fresh()->purge_reason);
    }

    #[Test]
    public function กดขอลบซ้ำไม่สร้างคิวซ้ำ(): void
    {
        $this->actingAs($this->owner)->post('/kyc/deletion-request');
        $this->actingAs($this->owner)->post('/kyc/deletion-request');

        $this->assertSame(1, KycDeletionRequest::count());
    }

    #[Test]
    public function ปฏิเสธคำขอลบต้องมีเหตุผลเสมอ(): void
    {
        $this->actingAs($this->owner)->post('/kyc/deletion-request');
        $request = KycDeletionRequest::first();

        // ไม่กรอกเหตุผล → ปฏิเสธไม่ได้
        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/deletions/{$request->id}/reject", [])
            ->assertSessionHasErrors('note');

        $this->assertTrue($request->fresh()->isPending());

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/deletions/{$request->id}/reject", [
                'note' => 'ต้องเก็บตามกฎหมายป้องกันการฟอกเงินอีก 2 ปี',
            ])
            ->assertRedirect();

        $this->assertSame(KycDeletionRequest::STATUS_REJECTED, $request->fresh()->status);

        // ปฏิเสธแล้วไฟล์ต้องยังอยู่
        Storage::disk('kyc')->assertExists('demo/front.jpg');
    }

    #[Test]
    public function ดำเนินการคำขอเดิมซ้ำไม่ได้(): void
    {
        $this->actingAs($this->owner)->post('/kyc/deletion-request');
        $request = KycDeletionRequest::first();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/deletions/{$request->id}/complete");

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/deletions/{$request->id}/complete")
            ->assertStatus(409);
    }

    // =========================================================================
    // สิทธิขอสำเนาข้อมูล
    // =========================================================================

    #[Test]
    public function ขอสำเนาข้อมูลได้พร้อมรายชื่อคนที่เคยเปิดดู(): void
    {
        // ให้แอดมินเปิดดูก่อน จะได้มีรายการให้ตรวจ
        $this->actingAs($this->admin, 'admin')->get("/admin/kyc/documents/{$this->document->id}");

        $response = $this->actingAs($this->owner)->get('/kyc/export');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');

        $data = $response->json();

        $this->assertSame('owner@tpix.test', $data['account']['email']);
        $this->assertSame('สมหญิง รักษ์ดี', $data['submissions'][0]['full_name']);
        $this->assertSame('9876543210987', $data['submissions'][0]['national_id']);

        // PDPA: ต้องบอกได้ว่าใครเข้าถึงข้อมูลเขาบ้าง
        $accessed = $data['submissions'][0]['documents'][0]['accessed_by'];
        $this->assertCount(1, $accessed);
        $this->assertSame('ทีมงานตรวจเอกสาร', $accessed[0]['admin']);
    }

    #[Test]
    public function สำเนาข้อมูลได้เฉพาะของตัวเอง(): void
    {
        $data = $this->actingAs($this->stranger)->get('/kyc/export')->json();

        $this->assertSame('stranger@tpix.test', $data['account']['email']);
        $this->assertSame([], $data['submissions']);
    }

    // =========================================================================
    // การตรวจของแอดมิน
    // =========================================================================

    #[Test]
    public function แอดมินอนุมัติแล้วสถานะตามไปทุกที่(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/{$this->submission->uuid}/approve", ['note' => 'ตรงทุกช่อง'])
            ->assertRedirect();

        $this->submission->refresh();

        $this->assertSame(KycSubmission::STATUS_APPROVED, $this->submission->status);
        $this->assertSame($this->admin->id, $this->submission->reviewed_by);
        $this->assertSame('approved', $this->owner->fresh()->kyc_status);

        // นับระยะเก็บใหม่จากวันอนุมัติ ไม่ใช่วันยื่น
        $this->assertTrue($this->submission->purge_after->isFuture());
    }

    #[Test]
    public function ปฏิเสธต้องบอกเหตุผลที่ผู้ใช้เห็น(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/{$this->submission->uuid}/reject", [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/{$this->submission->uuid}/reject", [
                'reason' => 'รูปบัตรไม่ชัด อ่านเลขบัตรไม่ออก',
                'note' => 'ภายใน: ภาพเบลอทั้งใบ',
            ])
            ->assertRedirect();

        $this->submission->refresh();

        $this->assertSame(KycSubmission::STATUS_REJECTED, $this->submission->status);
        $this->assertSame('รูปบัตรไม่ชัด อ่านเลขบัตรไม่ออก', $this->submission->reject_reason);
        $this->assertSame('rejected', $this->owner->fresh()->kyc_status);
    }

    #[Test]
    public function ใบที่ล้างข้อมูลแล้วตรวจไม่ได้(): void
    {
        app(KycPurgeService::class)->purgeSubmission($this->submission, 'user_request');

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/kyc/{$this->submission->uuid}/approve")
            ->assertStatus(410);
    }

    #[Test]
    public function บันทึกภายในไม่หลุดไปหน้าผู้ใช้(): void
    {
        $this->submission->forceFill([
            'status' => KycSubmission::STATUS_REJECTED,
            'review_note' => 'ภายใน: สงสัยเป็นบัญชีม้า',
            'reject_reason' => 'รูปบัตรไม่ชัด',
        ])->save();

        $payload = $this->submission->fresh()->toOwnerArray();

        $this->assertArrayNotHasKey('review_note', $payload);
        $this->assertSame('รูปบัตรไม่ชัด', $payload['reject_reason']);

        // และเลขบัตรที่ส่งกลับไปต้องถูกปิดบัง
        $this->assertStringNotContainsString('9876543210987', (string) $payload['national_id_masked']);
        $this->assertStringEndsWith('0987', (string) $payload['national_id_masked']);
    }
}
