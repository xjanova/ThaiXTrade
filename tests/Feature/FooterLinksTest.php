<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TPIX TRADE — ทุกลิงก์ใน footer ต้องเปิดได้จริง.
 *
 * footer เป็นทางเข้าหลักของหน้าที่ไม่ได้อยู่ใน nav — ลิงก์ตายที่นั่นจะไม่มีใครเจอ
 * จนกว่าผู้ใช้จะกด เทสต์นี้อ่าน href จาก AppLayout.vue ตรงๆ แล้วยิงทุกเส้นทาง
 * จึงกันได้ทั้ง "ลิงก์พิมพ์ผิด" และ "ลบ route ทิ้งแต่ลืมลบลิงก์"
 *
 * เคสจริงที่เทสต์นี้เกิดมาเพราะมัน: ปุ่ม "Download Whitepaper" ชี้ /whitepaper
 * (หน้าอ่าน) แทน /whitepaper/download (ตัวไฟล์) — กดแล้วไม่ได้ไฟล์ที่โฆษณาไว้
 *
 * Developed by Xman Studio.
 */
class FooterLinksTest extends TestCase
{
    /** @return list<string> เส้นทางภายในทั้งหมดที่ footer ชี้ไป */
    private function footerPaths(): array
    {
        $source = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

        $start = strpos($source, 'const footerColumns');
        $this->assertNotFalse($start, 'ไม่พบตาราง footerColumns ใน AppLayout.vue');

        $block = substr($source, $start, strpos($source, '];', $start) - $start);

        preg_match_all("/href:\s*'(\/[^']*)'/", $block, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_footer_actually_declares_links(): void
    {
        $paths = $this->footerPaths();

        $this->assertGreaterThanOrEqual(15, count($paths), 'footer มีลิงก์น้อยผิดปกติ — regex อาจพัง');
    }

    public function test_every_footer_link_resolves(): void
    {
        foreach ($this->footerPaths() as $path) {
            $response = $this->get($path);

            $this->assertContains(
                $response->getStatusCode(),
                [200, 302],
                "ลิงก์ใน footer เสีย: {$path} คืนสถานะ {$response->getStatusCode()}"
            );
        }
    }

    public function test_download_whitepaper_points_at_the_file_not_the_reader(): void
    {
        $paths = $this->footerPaths();

        $this->assertContains('/whitepaper/download', $paths, 'ปุ่มดาวน์โหลดไวต์เปเปอร์ต้องชี้ไปที่ไฟล์');
        $this->assertContains('/whitepaper', $paths, 'ลิงก์อ่านไวต์เปเปอร์ต้องยังอยู่');
    }

    public function test_footer_covers_the_pages_that_have_no_other_entry_point(): void
    {
        $paths = $this->footerPaths();

        // หน้าที่ไม่มีอยู่ใน nav bar — ถ้า footer ไม่ลิงก์ ผู้ใช้จะหาไม่เจอเลย
        foreach (['/launch', '/validators', '/food-passport', '/download', '/blog', '/ai-trade'] as $path) {
            $this->assertContains($path, $paths, "footer ยังไม่ได้ลิงก์ไปหน้า {$path}");
        }
    }

    public function test_footer_labels_are_translation_keys_not_hardcoded_text(): void
    {
        $source = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        $start = strpos($source, 'const footerColumns');
        $block = substr($source, $start, strpos($source, '];', $start) - $start);

        preg_match_all("/labelKey:\s*'([^']+)'/", $block, $matches);
        $keys = $matches[1];

        $this->assertNotEmpty($keys);

        $th = json_decode(file_get_contents(resource_path('js/i18n/th.json')), true);
        $en = json_decode(file_get_contents(resource_path('js/i18n/en.json')), true);

        foreach ($keys as $key) {
            $this->assertNotNull(data_get($th, $key), "คีย์ {$key} ไม่มีคำแปลภาษาไทย");
            $this->assertNotNull(data_get($en, $key), "คีย์ {$key} ไม่มีคำแปลภาษาอังกฤษ");
        }
    }
}
