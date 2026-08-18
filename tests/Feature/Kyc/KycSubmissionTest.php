<?php

namespace Tests\Feature\Kyc;

use App\Models\KycDocument;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — การยื่นเอกสารยืนยันตัวตน.
 *
 * เทสต์ชุดนี้เน้นสองเรื่องที่พลาดแล้วเสียหายหนัก:
 *   1. เอกสารต้องไม่หลุดออกเว็บ และไฟล์ที่ไม่ใช่รูปต้องเข้ามาไม่ได้
 *   2. ข้อมูลส่วนบุคคลต้องเข้ารหัสจริงในฐานข้อมูล ไม่ใช่แค่เขียนว่าเข้ารหัส
 *
 * Developed by Xman Studio.
 */
class KycSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('kyc');

        $this->user = User::create([
            'email' => 'trader@tpix.test',
            'password' => bcrypt('secret-password'),
            'name' => 'ผู้ใช้ทดสอบ',
        ]);
    }

    /** รูปจริงที่ GD อ่านออก — ไฟล์ปลอมผ่านด่านตรวจไม่ได้ */
    private function image(string $name = 'id.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 640, 400);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'level' => 'basic',
            'full_name' => 'สมชาย ใจดี',
            'id_type' => 'national_id',
            'national_id' => '1234567890123',
            'date_of_birth' => '1990-05-20',
            'nationality' => 'TH',
            'address' => '123 ถนนสุขุมวิท กรุงเทพฯ 10110',
            'consent' => true,
            'documents' => [
                'id_card_front' => $this->image('front.jpg'),
                'selfie_with_id' => $this->image('selfie.jpg'),
            ],
        ], $overrides);
    }

    // =========================================================================
    // ยื่นได้จริง
    // =========================================================================

    #[Test]
    public function ยื่นเอกสารครบแล้วได้ใบรอตรวจ(): void
    {
        $this->actingAs($this->user)
            ->post('/kyc', $this->payload())
            ->assertRedirect();

        $submission = KycSubmission::first();

        $this->assertNotNull($submission);
        $this->assertSame(KycSubmission::STATUS_PENDING, $submission->status);
        $this->assertSame('สมชาย ใจดี', $submission->full_name);
        $this->assertCount(2, $submission->documents);

        // สถานะเงาที่หน้าสมาชิกเดิมใช้ต้องตามมาด้วย
        $this->assertSame('pending', $this->user->fresh()->kyc_status);
    }

    #[Test]
    public function ไฟล์ถูกเก็บบน_disk_ส่วนตัวไม่ใช่ที่เปิดสาธารณะ(): void
    {
        $this->actingAs($this->user)->post('/kyc', $this->payload());

        $document = KycDocument::first();

        $this->assertSame('kyc', $document->disk);
        Storage::disk('kyc')->assertExists($document->path);

        /*
         * ข้อนี้สำคัญที่สุดในไฟล์
         *
         * disk 'public' มี symlink ออกเว็บ ใครเดา URL ถูกก็เปิดบัตรประชาชนคนอื่นได้
         * โดยไม่ต้องล็อกอิน — ถ้าวันหนึ่งมีใครเปลี่ยน disk เป็น public เทสต์นี้ต้องแดง
         */
        $this->assertNotSame('public', $document->disk);
    }

    #[Test]
    public function เอกสารไม่ครบตามระดับยื่นไม่ผ่าน(): void
    {
        $payload = $this->payload([
            'documents' => ['id_card_front' => $this->image()],
        ]);

        $this->actingAs($this->user)
            ->post('/kyc', $payload)
            ->assertSessionHasErrors('kyc');

        $this->assertSame(0, KycSubmission::count());
    }

    #[Test]
    public function ระดับเพิ่มเติมต้องมีหลังบัตรกับหลักฐานที่อยู่ด้วย(): void
    {
        $payload = $this->payload([
            'level' => 'enhanced',
            'documents' => [
                'id_card_front' => $this->image('front.jpg'),
                'selfie_with_id' => $this->image('selfie.jpg'),
            ],
        ]);

        $this->actingAs($this->user)
            ->post('/kyc', $payload)
            ->assertSessionHasErrors('kyc');
    }

    #[Test]
    public function ไม่ติ๊กยินยอมยื่นไม่ได้(): void
    {
        $this->actingAs($this->user)
            ->post('/kyc', $this->payload(['consent' => false]))
            ->assertSessionHasErrors('consent');

        $this->assertSame(0, KycSubmission::count());
    }

    // =========================================================================
    // ไฟล์ที่ไม่ใช่รูป
    // =========================================================================

    #[Test]
    public function ไฟล์ที่ไม่ใช่รูปถูกปฏิเสธแม้ตั้งนามสกุลเป็น_jpg(): void
    {
        /*
         * นี่คือท่ามาตรฐานของคนที่อยากยัดไฟล์อื่นเข้ามา — ตั้งชื่อ .jpg แล้วส่ง
         * ด่านต้องดูจากเนื้อไฟล์ ไม่ใช่จากนามสกุลหรือ Content-Type ที่ผู้ส่งกรอกเอง
         */
        $fake = UploadedFile::fake()->createWithContent(
            'evil.jpg',
            "<?php echo 'pwned'; ?>"
        );

        $payload = $this->payload([
            'documents' => [
                'id_card_front' => $fake,
                'selfie_with_id' => $this->image('selfie.jpg'),
            ],
        ]);

        $response = $this->actingAs($this->user)->post('/kyc', $payload);

        $response->assertSessionHasErrors();
        $this->assertSame(0, KycSubmission::count());
        $this->assertSame(0, KycDocument::count());
    }

    #[Test]
    public function ยื่นล้มแล้วต้องไม่มีไฟล์ค้างบนดิสก์(): void
    {
        $fake = UploadedFile::fake()->createWithContent('evil.jpg', 'not-an-image');

        $this->actingAs($this->user)->post('/kyc', $this->payload([
            'documents' => [
                'id_card_front' => $this->image('front.jpg'),
                'selfie_with_id' => $fake,
            ],
        ]));

        /*
         * ไฟล์กำพร้าคือข้อมูลส่วนบุคคลที่ไม่มีแถวไหนอ้างถึง
         * แปลว่าไม่มีวันถูกล้างตามระยะเก็บ และไม่มีใครรู้ว่ามันคือของใคร
         */
        $this->assertEmpty(Storage::disk('kyc')->allFiles());
    }

    // =========================================================================
    // การเข้ารหัส
    // =========================================================================

    #[Test]
    public function เลขบัตรถูกเข้ารหัสจริงในฐานข้อมูล(): void
    {
        $this->actingAs($this->user)->post('/kyc', $this->payload());

        $raw = DB::table('kyc_submissions')->first();

        // อ่านผ่าน Eloquent ได้ค่าเดิม
        $this->assertSame('1234567890123', KycSubmission::first()->national_id);

        // แต่ค่าดิบในตารางต้องไม่ใช่ข้อความธรรมดา
        $this->assertNotSame('1234567890123', $raw->national_id);
        $this->assertStringNotContainsString('1234567890123', (string) $raw->national_id);
        $this->assertStringNotContainsString('สมชาย', (string) $raw->full_name);
    }

    #[Test]
    public function ลายนิ้วมือเลขบัตรหาคนยื่นซ้ำได้โดยไม่ต้องถอดรหัส(): void
    {
        $this->actingAs($this->user)->post('/kyc', $this->payload());

        $hash = KycSubmission::hashNationalId('1234567890123');

        $this->assertSame($hash, KycSubmission::first()->national_id_hash);

        // เว้นวรรค/ขีดคั่นไม่ควรทำให้กลายเป็นคนละคน
        $this->assertSame($hash, KycSubmission::hashNationalId('1-2345-67890-12-3'));

        // และต้องไม่ใช่ sha256 เปล่าที่ไล่ครบ 13 หลักได้ด้วยเครื่องธรรมดา
        $this->assertNotSame(hash('sha256', '1234567890123'), $hash);
    }

    // =========================================================================
    // กฎการยื่นซ้ำ
    // =========================================================================

    #[Test]
    public function มีใบรอตรวจอยู่แล้วยื่นซ้ำไม่ได้(): void
    {
        $this->actingAs($this->user)->post('/kyc', $this->payload());

        $this->actingAs($this->user)
            ->post('/kyc', $this->payload())
            ->assertSessionHasErrors('kyc');

        $this->assertSame(1, KycSubmission::count());
    }

    #[Test]
    public function ถูกปฏิเสธแล้วยื่นใหม่ได้(): void
    {
        $this->actingAs($this->user)->post('/kyc', $this->payload());

        KycSubmission::first()->update(['status' => KycSubmission::STATUS_REJECTED]);

        $this->actingAs($this->user)
            ->post('/kyc', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, KycSubmission::count());
    }

    #[Test]
    public function ยกเลิกใบของตัวเองได้แต่ของคนอื่นไม่ได้(): void
    {
        $this->actingAs($this->user)->post('/kyc', $this->payload());
        $submission = KycSubmission::first();

        $other = User::create([
            'email' => 'other@tpix.test',
            'password' => bcrypt('secret-password'),
        ]);

        // คนอื่นยกเลิกใบเราไม่ได้ แม้จะรู้ uuid
        $this->actingAs($other)
            ->post("/kyc/{$submission->uuid}/cancel")
            ->assertNotFound();

        $this->assertSame(KycSubmission::STATUS_PENDING, $submission->fresh()->status);

        // เจ้าของยกเลิกได้
        $this->actingAs($this->user)
            ->post("/kyc/{$submission->uuid}/cancel")
            ->assertRedirect();

        $this->assertSame(KycSubmission::STATUS_CANCELLED, $submission->fresh()->status);
        $this->assertSame('none', $this->user->fresh()->kyc_status);
    }

    #[Test]
    public function ยังไม่ล็อกอินเปิดหน้ายืนยันตัวตนไม่ได้(): void
    {
        $this->get('/kyc')->assertRedirect();
        $this->post('/kyc', $this->payload())->assertRedirect();
    }
}
