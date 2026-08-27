<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ด่านอัปโหลดโลโก้ของ Token Factory.
 *
 * สองช่องที่ปิดในเทสต์ชุดนี้พังเงียบทั้งคู่ — ไม่มี error ไม่มีอะไรฟ้อง
 * ถ้าใครเผลอเปิดกลับ จะรู้ตัวอีกทีตอนโดนใช้แล้ว:
 *
 *   1. รับไฟล์ svg — SVG คือ XML ที่ฝัง <script> ได้ พอเสิร์ฟจาก /storage
 *      สคริปต์จะรันด้วยสิทธิ์ origin เดียวกับหน้าเทรด (อ่าน session ของแอดมิน,
 *      สลับที่อยู่ปลายทางในหน้าต่างที่ผู้ใช้กำลังจะเซ็น)
 *
 *   2. ไม่ผูกไฟล์กับกระเป๋า — อัปกี่ไฟล์ก็ค้างถาวร ไม่มีใครลบ ถมดิสก์
 *      ของเว็บเซิร์ฟเวอร์ที่มีเว็บอื่นอยู่ด้วยได้
 *
 * Developed by Xman Studio.
 */
class TokenFactoryLogoUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');

        $this->verifyWallet($this->wallet);
    }

    private function verifyWallet(string $wallet): void
    {
        Cache::put('wallet_verified:'.strtolower($wallet), [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    /** @param  array<string, mixed>  $extra */
    private function upload(UploadedFile $logo, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/token-factory/upload-logo', array_merge([
            'logo' => $logo,
            'wallet_address' => $this->wallet,
        ], $extra));
    }

    #[Test]
    public function svg_ถูกปฏิเสธและไม่มีไฟล์ตกค้างบนดิสก์(): void
    {
        $evil = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->upload($evil)
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function ไม่ส่งกระเป๋ามาก็อัปไม่ได้(): void
    {
        // เดิม VerifyWalletOwnership ปล่อยผ่านเมื่อหาที่อยู่กระเป๋าไม่เจอ
        // และหน้าเว็บไม่เคยส่งฟิลด์นี้มา → route นี้จึงเปิดโล่งมาตลอด
        $this->postJson('/api/v1/token-factory/upload-logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_address');
    }

    #[Test]
    public function กระเป๋าที่ยังไม่พิสูจน์ตัวตนอัปไม่ได้(): void
    {
        Cache::flush();

        $this->upload(UploadedFile::fake()->image('logo.png'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
    }

    #[Test]
    public function png_ของกระเป๋าที่พิสูจน์แล้วอัปได้และเก็บแยกตามกระเป๋า(): void
    {
        $res = $this->upload(UploadedFile::fake()->image('logo.png'))
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $path = $res->json('data.path');

        $this->assertStringStartsWith('token-logos/'.strtolower($this->wallet).'/', $path);
        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function เก็บย้อนหลังแค่_3_ไฟล์ต่อกระเป๋า_ไม่ให้ถมดิสก์(): void
    {
        // ปิด throttle เฉพาะเทสต์นี้ — เทสต์นี้วัด "เพดานไฟล์บนดิสก์"
        // ถ้าปล่อยไว้จะไปชน 429 ก่อน แล้ววัดสิ่งที่ตั้งใจวัดไม่ได้
        // (ตัว throttle เองมีด่านของมันที่ route อยู่แล้ว: throttle:10,60)
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 1; $i <= 6; $i++) {
            $this->upload(UploadedFile::fake()->image("logo{$i}.png"))->assertStatus(200);
        }

        $files = Storage::disk('public')->files('token-logos/'.strtolower($this->wallet));

        $this->assertCount(3, $files, 'ต้องเหลือ 3 ไฟล์ล่าสุดเท่านั้น');
    }

    #[Test]
    public function กระเป๋าคนอื่นถูกปฏิเสธ_แม้ของเราจะพิสูจน์แล้ว(): void
    {
        $other = '0x2222222222222222222222222222222222222222';

        $this->upload(UploadedFile::fake()->image('logo.png'), ['wallet_address' => $other])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
    }
}
