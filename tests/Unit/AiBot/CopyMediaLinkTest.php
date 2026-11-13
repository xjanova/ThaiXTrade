<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\Copy\MediaLink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TPIX TRADE — ลิงก์ไลฟ์/คลิปของมาสเตอร์ต้องผ่านรายการอนุญาตและถูกประกอบใหม่จากรหัสวิดีโอ.
 *
 * ลิงก์จากผู้ใช้ถูกแสดงให้ผู้ใช้คนอื่นเห็น (และอาจถูกฝังเป็นกรอบวิดีโอ) — ชุดนี้คุมว่าไม่มีข้อความ
 * ที่ผู้ใช้พิมพ์หลุดออกไปในลิงก์ที่ส่งออก และโฮสต์/รูปแบบที่ไม่อยู่ในรายการถูกปฏิเสธทั้งหมด
 *
 * Developed by Xman Studio.
 */
class CopyMediaLinkTest extends TestCase
{
    #[Test]
    public function it_accepts_the_supported_video_link_shapes(): void
    {
        $cases = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://youtu.be/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://www.youtube.com/live/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://m.youtube.com/shorts/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ'],
            'https://www.facebook.com/watch/?v=1234567890123' => ['facebook', '1234567890123'],
            'https://www.facebook.com/tpix.trade/videos/1234567890123/' => ['facebook', '1234567890123'],
            'https://www.facebook.com/reel/1234567890123' => ['facebook', '1234567890123'],
            'https://www.tiktok.com/@tpix.trade/video/7300000000000000000' => ['tiktok', '7300000000000000000'],
        ];

        foreach ($cases as $url => [$provider, $id]) {
            $link = MediaLink::parse($url);

            $this->assertNotNull($link, $url);
            $this->assertSame([$provider, $id], [$link['provider'], $link['external_id']], $url);
        }
    }

    #[Test]
    public function the_exported_links_are_rebuilt_and_carry_nothing_the_user_typed(): void
    {
        $link = MediaLink::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLx&t=42s&utm_source=evil#frag');

        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $link['url']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $link['embed_url']);

        $facebook = MediaLink::parse('https://www.facebook.com/some.page/videos/1234567890123/?ref=x');
        $this->assertSame('https://www.facebook.com/watch/?v=1234567890123', $facebook['url']);
        $this->assertSame('https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Fwatch%2F%3Fv%3D1234567890123&show_text=false', $facebook['embed_url']);

        $tiktok = MediaLink::parse('https://www.tiktok.com/@someone/video/7300000000000000000?lang=th');
        $this->assertSame('https://www.tiktok.com/embed/v2/7300000000000000000', $tiktok['embed_url']);
        $this->assertStringNotContainsString('someone', $tiktok['url'].$tiktok['embed_url']);
    }

    #[Test]
    public function it_rejects_every_link_that_is_not_a_plain_https_video_on_an_allowed_host(): void
    {
        $bad = [
            // ไม่ใช่ https / ไม่ใช่ลิงก์
            'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            '//www.youtube.com/watch?v=dQw4w9WgXcQ',
            'www.youtube.com/watch?v=dQw4w9WgXcQ',
            'ftp://youtu.be/dQw4w9WgXcQ',
            // โฮสต์ที่ "ดูเหมือน" ของที่อนุญาต
            'https://www.youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://evil.example/www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://notyoutube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com@evil.example/watch?v=dQw4w9WgXcQ',
            'https://user:pass@www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ',
            'https://xn--youtub-9ua.com/watch?v=dQw4w9WgXcQ',
            // รหัสวิดีโอไม่ตรงรูปแบบ / ไม่ใช่หน้าวิดีโอ
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ"onload="x',
            'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
            'https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx',
            'https://www.youtube.com/redirect?q=https://evil.example',
            'https://www.facebook.com/l.php?u=https://evil.example',
            'https://www.facebook.com/watch/?v=abc',
            'https://www.tiktok.com/@someone',
            'https://www.tiktok.com/@someone/video/abc',
            // ลิงก์ย่อ — ต้องตามไปเปิดถึงจะรู้ปลายทาง เซิร์ฟเวอร์ไม่ออกไปเปิดลิงก์ของผู้ใช้
            'https://vm.tiktok.com/ZSabcdef/',
            'https://fb.watch/abcdEFGH/',
            'https://bit.ly/3abcdef',
            // อักขระแปลกปลอม / ยาวเกิน / ไม่ใช่ข้อความ
            "https://www.youtube.com/watch?v=dQw4w9WgXcQ\n<script>",
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ <b>',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ\\',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&x='.str_repeat('a', 300),
            '',
            null,
            ['https://youtu.be/dQw4w9WgXcQ'],
            12345,
        ];

        foreach ($bad as $input) {
            $this->assertNull(MediaLink::parse($input), 'ต้องปฏิเสธ: '.var_export($input, true));
        }
    }

    #[Test]
    public function stored_values_are_checked_again_before_a_link_is_built(): void
    {
        $this->assertNotNull(MediaLink::build('youtube', 'dQw4w9WgXcQ'));

        // แถวที่ถูกแก้ในฐานข้อมูลให้ผิดรูปแบบต้องไม่กลายเป็นลิงก์
        foreach ([['youtube', 'x"><script>'], ['youtube', ''], ['facebook', 'abc'], ['tiktok', '12'], ['vimeo', '123456789'], ['', 'dQw4w9WgXcQ']] as [$provider, $id]) {
            $this->assertNull(MediaLink::build($provider, $id), "{$provider}/{$id}");
        }
    }

    #[Test]
    public function every_exported_link_points_at_a_known_provider_origin(): void
    {
        $allowed = ['https://www.youtube.com/', 'https://www.youtube-nocookie.com/', 'https://www.facebook.com/', 'https://www.tiktok.com/'];

        foreach ([['youtube', 'dQw4w9WgXcQ'], ['facebook', '1234567890123'], ['tiktok', '7300000000000000000']] as [$provider, $id]) {
            $link = MediaLink::build($provider, $id);

            foreach ([$link['url'], $link['embed_url']] as $url) {
                $this->assertTrue(collect($allowed)->contains(fn (string $origin) => str_starts_with($url, $origin)), $url);
            }
        }

        $this->assertSame(['youtube', 'facebook', 'tiktok'], MediaLink::PROVIDERS);
    }
}
