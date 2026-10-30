<?php

namespace App\Services\MasterNode;

use Closure;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * GeoIpService — IP ของ heartbeat → ประเทศ/เมือง จากฐาน GeoLite2-City ในเครื่อง.
 *
 * ทำบนเซิร์ฟเวอร์เท่านั้น ไม่ส่ง IP ไปถามบริการภายนอก
 * ผลที่ออกไปสาธารณะมีแค่ประเทศ เมือง และพิกัดของ "เมือง" ปัดเหลือทศนิยม 1 ตำแหน่ง (~11 กม.)
 * ห้ามคืน IP หรือพิกัดละเอียดกว่านี้ — คนนอกจะเล็งเครื่องของผู้รันโหนดได้
 *
 * ไม่มีไฟล์ฐานข้อมูล = คืน null เงียบ ๆ (ระบบ uptime ยังทำงานได้ แค่แผนที่ไม่มีประเทศ)
 * โหลดไฟล์ด้วย `php artisan geoip:update`
 *
 * Developed by Xman Studio
 */
class GeoIpService
{
    /** @var (Closure(string): ?array)|null */
    private ?Closure $lookup = null;

    private bool $readerFailed = false;

    /**
     * @param  (Closure(string): ?array)|null  $lookup  ให้เทสต์ส่งตัวค้นปลอมเข้ามาได้ — ค่าเริ่มต้นอ่านไฟล์ .mmdb
     */
    public function __construct(?Closure $lookup = null)
    {
        $this->lookup = $lookup;
    }

    /**
     * @return array{country_code:string, country_name:?string, city:?string, latitude:?float, longitude:?float}|null
     */
    public function locate(string $ip): ?array
    {
        // IP ภายใน/สงวน ไม่มีในฐานอยู่แล้ว และไม่ควรโผล่บนแผนที่สาธารณะ
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $lookup = $this->resolveLookup();
        if ($lookup === null) {
            return null;
        }

        try {
            $record = $lookup($ip);
        } catch (\Throwable $e) {
            Log::warning('GeoIP lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        return is_array($record) ? self::present($record) : null;
    }

    /**
     * record ดิบของ GeoLite2-City → ช่องที่เปิดเผยได้.
     *
     * @return array{country_code:string, country_name:?string, city:?string, latitude:?float, longitude:?float}|null
     */
    public static function present(array $record): ?array
    {
        // บางช่วง IP ไม่มี country แต่มี registered_country (ประเทศที่จด IP) — ใช้แทนได้
        $country = $record['country'] ?? $record['registered_country'] ?? null;
        $code = is_array($country) ? strtoupper((string) ($country['iso_code'] ?? '')) : '';
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        $city = $record['city']['names']['en'] ?? null;
        $lat = $record['location']['latitude'] ?? null;
        $lng = $record['location']['longitude'] ?? null;
        $hasCity = is_string($city) && $city !== '';

        return [
            'country_code' => $code,
            'country_name' => isset($country['names']['en']) ? mb_substr((string) $country['names']['en'], 0, 64) : null,
            'city' => $hasCity ? mb_substr($city, 0, 100) : null,
            // ไม่รู้เมือง = พิกัดที่มีคือ "กลางประเทศ" ซึ่งปักหมุดแล้วทำให้เข้าใจผิด — ไม่ส่งพิกัด
            'latitude' => $hasCity && is_numeric($lat) ? round((float) $lat, 1) : null,
            'longitude' => $hasCity && is_numeric($lng) ? round((float) $lng, 1) : null,
        ];
    }

    /** มีฐานข้อมูลให้ค้นไหม — ไม่มี ผู้เรียกไม่ควรจำว่า "ค้น IP นี้แล้ว" */
    public function available(): bool
    {
        return $this->resolveLookup() !== null;
    }

    public function databasePath(): string
    {
        return (string) config('masternode.geoip.database_path');
    }

    private function resolveLookup(): ?Closure
    {
        if ($this->lookup !== null) {
            return $this->lookup;
        }
        if ($this->readerFailed) {
            return null;
        }

        $path = $this->databasePath();
        if ($path === '' || ! is_readable($path)) {
            $this->readerFailed = true;

            return null;
        }

        try {
            $reader = new Reader($path);
        } catch (\Throwable $e) {
            $this->readerFailed = true;
            Log::warning('GeoIP database unreadable', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return $this->lookup = fn (string $ip): ?array => $reader->get($ip);
    }
}
