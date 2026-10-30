<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

/**
 * GeoIpUpdate — โหลดฐาน GeoLite2-City ของ MaxMind มาไว้ในเครื่อง.
 *
 * ต้องสมัครบัญชี GeoLite ฟรีที่ maxmind.com แล้วตั้ง MAXMIND_ACCOUNT_ID + MAXMIND_LICENSE_KEY
 * MaxMind ปล่อยฐานใหม่สัปดาห์ละ 2 ครั้ง — schedule รายสัปดาห์พอ
 *
 * ขั้นตอนกันไฟล์เสีย: โหลด .tar.gz + .sha256 → เทียบ hash → แตกไฟล์ → เปิดอ่านลองค้น
 * ผ่านหมดแล้วค่อยเปลี่ยนชื่อทับของเดิม (rename) — ไฟล์เดิมใช้ได้ต่อจนวินาทีสุดท้าย
 *
 * ข้อตกลงของ GeoLite: ต้องอัปเดตฐานตามรอบ และลบฐานเก่าทิ้งเมื่อเลิกใช้
 *
 * Developed by Xman Studio
 */
class GeoIpUpdate extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download the MaxMind GeoLite2-City database used to show master-node country/city';

    private const URL = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download';

    public function handle(): int
    {
        $account = (string) config('masternode.geoip.account_id');
        $key = (string) config('masternode.geoip.license_key');
        if ($account === '' || $key === '') {
            $this->warn('ยังไม่ได้ตั้ง MAXMIND_ACCOUNT_ID / MAXMIND_LICENSE_KEY — ข้าม (แผนที่โหนดจะไม่มีประเทศ/เมือง)');

            return self::SUCCESS;
        }

        $target = (string) config('masternode.geoip.database_path');
        $dir = dirname($target);
        File::ensureDirectoryExists($dir, 0750);
        $work = $dir.'/.download-'.getmypid();
        File::ensureDirectoryExists($work, 0700);

        try {
            $sumResponse = Http::withBasicAuth($account, $key)->timeout(30)
                ->get(self::URL, ['suffix' => 'tar.gz.sha256']);
            if (! $sumResponse->successful() || ! preg_match('/^([a-f0-9]{64})\s/', $sumResponse->body(), $m)) {
                $this->error('ขอ sha256 ไม่สำเร็จ (HTTP '.$sumResponse->status().') — ตรวจ account id / license key');

                return self::FAILURE;
            }
            $expectedSha = $m[1];

            $archive = $work.'/GeoLite2-City.tar.gz';
            $response = Http::withBasicAuth($account, $key)->timeout(300)
                ->withOptions(['sink' => $archive])
                ->get(self::URL, ['suffix' => 'tar.gz']);
            if (! $response->successful()) {
                $this->error('ดาวน์โหลดไม่สำเร็จ (HTTP '.$response->status().')');

                return self::FAILURE;
            }

            if (! hash_equals($expectedSha, hash_file('sha256', $archive))) {
                $this->error('sha256 ไม่ตรง — ไม่ใช้ไฟล์นี้');

                return self::FAILURE;
            }

            $mmdb = $this->extractMmdb($archive, $work);
            if ($mmdb === null) {
                $this->error('ไม่พบ GeoLite2-City.mmdb ในไฟล์ที่โหลดมา');

                return self::FAILURE;
            }

            // เปิดอ่านจริงก่อนทับของเดิม — ไฟล์ที่เปิดไม่ได้ทำให้ทุก heartbeat ไม่มีประเทศ
            $reader = new Reader($mmdb);
            $meta = $reader->metadata();
            $reader->close();
            if (! str_contains((string) $meta->databaseType, 'City')) {
                $this->error('ไฟล์ที่ได้ไม่ใช่ฐานระดับเมือง ('.$meta->databaseType.')');

                return self::FAILURE;
            }

            rename($mmdb, $target);
            @chmod($target, 0640);
            $this->info(sprintf('อัปเดต %s แล้ว (build %s)', $target, date('Y-m-d', (int) $meta->buildEpoch)));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('อัปเดต GeoLite2 ไม่สำเร็จ: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** แตก .tar.gz แล้วคืนพาธของ .mmdb (อยู่ในโฟลเดอร์ย่อย GeoLite2-City_YYYYMMDD/) */
    private function extractMmdb(string $archive, string $work): ?string
    {
        $tar = new \PharData($archive);
        foreach (new \RecursiveIteratorIterator($tar) as $file) {
            if (basename($file->getPathname()) === 'GeoLite2-City.mmdb') {
                $out = $work.'/GeoLite2-City.mmdb';
                // อ่านจากใน archive แล้วเขียนเอง — ไม่ใช้ extractTo() ที่เขียนตามพาธในไฟล์ (path traversal)
                file_put_contents($out, file_get_contents($file->getPathname()));

                return $out;
            }
        }

        return null;
    }
}
