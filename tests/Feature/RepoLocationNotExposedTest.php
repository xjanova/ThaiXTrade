<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TPIX TRADE — ทุกที่ที่คนนอกเข้าถึงได้ ห้ามบอกว่า repo อยู่ไหน.
 *
 * 2026-09-06 เก็บกวาดครั้งใหญ่ ตั้งต้นจากลิงก์ GitHub ที่โผล่ในกล่องอัปเดตของแอปเทรด
 * แล้วไล่ต่อจนเจออีก 9 ที่: ปุ่มดาวน์โหลดโหนดบนหน้าแรก · ปุ่ม "GitHub Source Code"
 * ใน whitepaper · ท้ายไฟล์ PDF · คู่มือมาสเตอร์โหนด · ไอคอนที่ footer ·
 * `/api/v1/tpix/info` ที่เว็บจัดอันดับเหรียญมาดึง · หน้าคงที่ masternode-preview.html
 * ที่เสิร์ฟอยู่จริง · เมนูในโปรแกรมมาสเตอร์โหนด · และตัวอัปเดตของโปรแกรมนั้น
 * ที่ยิงหา GitHub ตรง ๆ ทั้งที่เซิร์ฟเวอร์มีฟีดให้อยู่แล้ว
 *
 * ด่านนี้เป็นตัวกันของกลับมาใหม่ — ลิงก์พวกนี้ถูกใส่กลับได้ง่ายมากเวลาเพิ่มปุ่ม
 * ดาวน์โหลดใหม่ แล้วไม่มีใครทันสังเกตจนกว่าจะไปโผล่หน้าผู้ใช้จริง
 *
 * หน้า Admin ไม่นับ — อยู่หลังล็อกอิน และตัวจัดการ release ของแอดมินต้องพูดถึง
 * GitHub ตรง ๆ อยู่แล้วเพราะมันคือที่ที่ไฟล์อยู่จริง
 *
 * Developed by Xman Studio.
 */
class RepoLocationNotExposedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * สิ่งที่ห้ามโผล่ในซอร์สที่ผู้ใช้เห็น.
     *
     * 'github.com' ครอบทั้ง github.com/<owner>/<repo> และ api.github.com
     * (ไม่ชน 'githubusercontent' ซึ่งเป็นคนละโดเมนและเป็นของบุคคลที่สาม)
     */
    private const FORBIDDEN = [
        'github.com',
        'xjanova',
    ];

    /**
     * ข้อยกเว้นเดียวที่เปิดเผยได้ — โฟลเดอร์สัญญาอัจฉริยะของ TPIX (เจ้าของกำหนด 2026-09-06).
     *
     * โครงการเชนต้องให้คนตรวจสัญญาเองได้ ไม่งั้นไม่มีใครกล้าเอาเงินมาวาง
     * แต่เปิดได้แค่ path นี้ — ลิงก์ที่ชี้ระดับ repo (`/xjanova/TPIX-Coin` เปล่า ๆ)
     * ยังต้องโดนจับ เพราะมันพาไปดูซอร์สทั้งโปรเจกต์ ไม่ใช่แค่สัญญา
     */
    private const ALLOWED_CONTRACTS_URL = 'https://github.com/xjanova/TPIX-Coin/tree/main/contracts/src';

    /** ตัดข้อยกเว้นออกก่อน แล้วที่เหลือคือของที่ไม่ควรมี */
    private function withoutAllowedLinks(string $text): string
    {
        return str_replace(self::ALLOWED_CONTRACTS_URL, '', $text);
    }

    /**
     * ทุกพื้นผิวที่คนนอกเข้าถึงได้.
     *
     * ไม่ใช่แค่หน้าเว็บ — โปรแกรมมาสเตอร์โหนดเป็นไฟล์ .exe ที่แจกให้คนนอกติดตั้ง
     * ใครก็แกะ app.asar ออกมาอ่านได้ ส่วน public_html เสิร์ฟไฟล์คงที่ตรง ๆ
     * (masternode-preview.html เคยตอบ 200 พร้อมลิงก์ repo สองอันเต็ม ๆ)
     */
    private const USER_FACING_DIRS = [
        'resources/js',
        'resources/views',
        'public_html',
        'masternode-ui/src',
        'masternode-ui/electron',
    ];

    /**
     * ของที่ไม่ได้เขียนเอง / ไม่ได้แจก — บิลด์ใหม่ทับทุกครั้งอยู่แล้ว.
     */
    private const SKIP_PATHS = [
        'public_html/build',        // บันเดิลที่ vite เจน (มีโค้ดไลบรารีคนอื่นปนอยู่)
        'masternode-ui/dist',       // ผลบิลด์ ไม่ได้ commit
        'node_modules',
    ];

    /** หลังล็อกอินแอดมิน — ไม่ใช่หน้าที่ผู้ใช้ทั่วไปเห็น */
    private const ADMIN_PREFIX = 'resources/js/Pages/Admin';

    /**
     * @return list<string> path ของไฟล์ที่ต้องตรวจ (relative จาก base path)
     */
    private function userFacingFiles(): array
    {
        $files = [];

        foreach (self::USER_FACING_DIRS as $dir) {
            $absolute = base_path($dir);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if (! in_array(strtolower($file->getExtension()), ['vue', 'js', 'php', 'html', 'json'], true)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if (str_starts_with($relative, self::ADMIN_PREFIX)) {
                    continue;
                }

                foreach (self::SKIP_PATHS as $skip) {
                    if (str_contains($relative, $skip)) {
                        continue 2;
                    }
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_scan_actually_looks_at_something(): void
    {
        // กันด่านนี้ผ่านฟรีเพราะหาไฟล์ไม่เจอ (path เพี้ยน / ย้ายโฟลเดอร์)
        $files = $this->userFacingFiles();

        $this->assertGreaterThan(100, count($files), 'สแกนไฟล์ได้น้อยผิดปกติ — ด่านนี้กำลังผ่านฟรี');
        $this->assertContains('resources/js/Pages/Home.vue', $files);
        $this->assertContains('resources/views/whitepaper/pdf.blade.php', $files);
        $this->assertContains('public_html/masternode-preview.html', $files);
        $this->assertContains('masternode-ui/electron/auto-updater.js', $files);

        // ผลบิลด์ต้องไม่ถูกลากเข้ามา ไม่งั้นด่านนี้จะแดงจากโค้ดไลบรารีคนอื่น
        $this->assertNotContains('masternode-ui/dist', $files);
    }

    public function test_no_user_facing_page_reveals_where_the_repo_lives(): void
    {
        $offenders = [];

        foreach ($this->userFacingFiles() as $relative) {
            $contents = $this->withoutAllowedLinks(file_get_contents(base_path($relative)));

            foreach (self::FORBIDDEN as $needle) {
                if (stripos($contents, $needle) === false) {
                    continue;
                }

                // เก็บเลขบรรทัดไว้ด้วย ไม่งั้นคนที่ทำด่านนี้แดงต้องไปไล่หาเอง
                foreach (preg_split('/\R/', $contents) as $i => $line) {
                    if (stripos($line, $needle) !== false) {
                        $offenders[] = $relative.':'.($i + 1).' → '.trim($line);
                    }
                }
            }
        }

        // บรรทัดเดียวอาจโดนหลายคำต้องห้าม — รายงานครั้งเดียวพอ
        $offenders = array_values(array_unique($offenders));

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['เจอที่อยู่ repo ในหน้าที่ผู้ใช้เห็น — ใช้ /download หรือ /masternode/guide แทน:'],
            $offenders
        )));
    }

    /**
     * ไฟล์ PDF ของ whitepaper เจนฝั่งเซิร์ฟเวอร์ ไม่ได้ผ่าน bundler
     * คอมเมนต์กับข้อความในนั้นจึงไปถึงผู้อ่านตรง ๆ ทั้งดุ้น.
     */
    public function test_whitepaper_pdf_footer_points_at_our_own_site(): void
    {
        $blade = file_get_contents(base_path('resources/views/whitepaper/pdf.blade.php'));

        $this->assertStringContainsString('https://tpix.online/download', $blade);
        $this->assertStringNotContainsString('github', strtolower($blade));
    }

    /**
     * ข้อยกเว้นต้องแคบจริง — ลิงก์ระดับ repo ยังต้องโดนจับ ไม่ใช่ผ่านเพราะขึ้นต้นเหมือนกัน.
     */
    public function test_the_exception_is_only_the_contracts_folder(): void
    {
        // ตัดข้อยกเว้นออกแล้ว ลิงก์ระดับ repo ต้องยังเหลือให้จับได้
        $repoLevel = 'https://github.com/xjanova/TPIX-Coin';
        $this->assertStringContainsString('github.com', $this->withoutAllowedLinks($repoLevel));

        // ส่วนลิงก์สัญญาเต็ม ๆ ต้องถูกตัดจนไม่เหลืออะไรให้จับ
        $this->assertSame('', $this->withoutAllowedLinks(self::ALLOWED_CONTRACTS_URL));
    }

    /**
     * ข้อมูลเหรียญสาธารณะ — เว็บจัดอันดับเหรียญมาดึงไปแสดง เคยแจก
     * social.github = ที่อยู่ repo ไปพร้อมกับข้อมูลราคา.
     */
    public function test_public_coin_info_does_not_hand_out_the_repo(): void
    {
        $response = $this->getJson('/api/v1/tpix/info')->assertOk();

        // ตรวจ "ที่อยู่" ไม่ใช่คำว่า github เฉย ๆ — ชื่อช่องที่ว่างอยู่ไม่ได้บอกอะไรใคร
        // แต่ค่าข้างในต้องไม่มีทั้ง github.com และชื่อเจ้าของ
        // เข้ารหัสใหม่แบบไม่ escape slash ก่อน — ไม่งั้น JSON เขียน https:\/\/... แล้ว
        // การตัดข้อยกเว้นออกจะไม่แมตช์ กลายเป็นด่านแดงทั้งที่ลิงก์ถูกต้อง
        $body = $this->withoutAllowedLinks(
            json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                strtolower($body),
                "ข้อมูลเหรียญสาธารณะยังแจก '{$needle}' ออกไป"
            );
        }

        // ชี้ที่โฟลเดอร์สัญญาเท่านั้น เว็บจัดอันดับเหรียญเอาไปให้คนตรวจสัญญาได้
        $response->assertJsonPath('data.social.github', self::ALLOWED_CONTRACTS_URL);

        // ช่องอื่นต้องยังอยู่ครบ — ตัดแค่ที่อยู่ repo ไม่ใช่ตัดทั้งบล็อก social
        $response->assertJsonPath('data.social.website', 'https://tpix.online');
        $response->assertJsonPath('data.social.whitepaper', 'https://tpix.online/whitepaper');
    }
}
