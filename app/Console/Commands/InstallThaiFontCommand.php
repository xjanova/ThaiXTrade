<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * ติดตั้งฟอนต์ Sarabun (Thai) สำหรับ DomPDF
 *
 * ดาวน์โหลดจาก Google Fonts GitHub repository และบันทึกลง storage/fonts/
 * ใช้สำหรับสร้าง Whitepaper PDF ภาษาไทย
 *
 * Usage: php artisan tpix:install-thai-font
 */
class InstallThaiFontCommand extends Command
{
    protected $signature = 'tpix:install-thai-font';
    protected $description = 'ดาวน์โหลดและติดตั้งฟอนต์ Sarabun (Thai) สำหรับ DomPDF Whitepaper';

    /**
     * Google Fonts GitHub — Sarabun TTF files
     */
    private const FONT_BASE_URL = 'https://raw.githubusercontent.com/google/fonts/main/ofl/sarabun/';

    private const FONT_FILES = [
        'Sarabun-Regular.ttf',
        'Sarabun-Bold.ttf',
        'Sarabun-Italic.ttf',
        'Sarabun-BoldItalic.ttf',
        'Sarabun-Light.ttf',
        'Sarabun-Medium.ttf',
        'Sarabun-SemiBold.ttf',
        'Sarabun-ExtraBold.ttf',
    ];

    public function handle(): int
    {
        $fontDir = storage_path('fonts');

        // สร้างโฟลเดอร์ถ้ายังไม่มี
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
            $this->info("Created directory: {$fontDir}");
        }

        $this->info('Downloading Sarabun font family from Google Fonts...');
        $this->newLine();

        $bar = $this->output->createProgressBar(count(self::FONT_FILES));
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach (self::FONT_FILES as $file) {
            $url = self::FONT_BASE_URL . $file;
            $dest = $fontDir . '/' . $file;

            // ข้ามถ้ามีอยู่แล้ว
            if (file_exists($dest) && filesize($dest) > 10000) {
                $bar->advance();
                $success++;
                continue;
            }

            try {
                $response = Http::timeout(30)->get($url);

                if ($response->successful()) {
                    file_put_contents($dest, $response->body());
                    $success++;
                } else {
                    $this->newLine();
                    $this->warn("  Failed to download {$file}: HTTP {$response->status()}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("  Error downloading {$file}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($success > 0) {
            $this->info("Successfully installed {$success} font file(s) to: {$fontDir}");
        }

        if ($failed > 0) {
            $this->warn("{$failed} font file(s) failed to download.");
        }

        // ตรวจสอบว่ามีไฟล์หลักครบ
        $required = ['Sarabun-Regular.ttf', 'Sarabun-Bold.ttf'];
        $missing = array_filter($required, fn ($f) => ! file_exists($fontDir . '/' . $f));

        if (empty($missing)) {
            $this->info('Thai font is ready! You can now generate Thai PDF:');
            $this->comment('  /whitepaper/download?lang=th');
            return self::SUCCESS;
        }

        $this->error('Missing required font files: ' . implode(', ', $missing));
        $this->comment('Try running the command again or download manually from:');
        $this->comment('  ' . self::FONT_BASE_URL);

        return self::FAILURE;
    }
}
