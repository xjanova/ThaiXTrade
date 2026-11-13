<?php

namespace App\Services\AiBot\Copy;

/**
 * TPIX TRADE — ลิงก์ไลฟ์/คลิปของมาสเตอร์: รับเฉพาะผู้ให้บริการที่อนุญาต แล้ว "สร้างลิงก์ใหม่เอง" จากรหัสวิดีโอ.
 *
 * เจ้าของสั่ง 2026-11-13: มาสเตอร์ที่ผลงานเด่น "ไลฟ์ สอน แชร์คลิปมาจากช่องตัวเองได้" — เราไม่โฮสต์วิดีโอ
 *
 * ⚠️ ลิงก์จากผู้ใช้เป็นของอันตราย (แอปนี้เคยมีเหตุ stored JS ผ่านไฟล์ SVG) กติกาของไฟล์นี้:
 *   - รับเฉพาะ https บนโฮสต์ที่ระบุไว้ตรงตัว (ไม่มีพอร์ต ไม่มี user:pass@ ไม่มีโฮสต์ที่ "ลงท้ายด้วย" ชื่อที่อนุญาต)
 *   - ดึงออกมาแค่ "รหัสวิดีโอ" ที่ตรงรูปแบบของผู้ให้บริการนั้น แล้วประกอบลิงก์ดูและลิงก์ฝังขึ้นมาใหม่เอง
 *     ข้อความที่ผู้ใช้พิมพ์มาไม่ถูกเก็บและไม่ถูกส่งกลับออกไปเลย (query แปลกปลอม · fragment · javascript: ไปไม่ถึงฐานข้อมูล)
 *   - รูปแบบที่ต้อง "ตามลิงก์ไปดู" ถึงจะรู้ปลายทาง (ลิงก์ย่อ vm.tiktok.com) ไม่รับ — เซิร์ฟเวอร์ไม่ออกไปเปิดลิงก์ของผู้ใช้
 *
 * pure ทั้งไฟล์
 *
 * Developed by Xman Studio.
 */
final class MediaLink
{
    public const PROVIDERS = ['youtube', 'facebook', 'tiktok'];

    /**
     * @return array{provider: string, external_id: string, url: string, embed_url: string}|null null = ไม่ใช่ลิงก์ที่รับ
     */
    public static function parse(mixed $input): ?array
    {
        if (! is_string($input) || strlen($input) > 300 || preg_match('/[\x00-\x20\x7F"\'<>\\\\`]/', trim($input)) === 1) {
            return null;
        }

        $parts = parse_url(trim($input));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        return match (true) {
            in_array($host, ['www.youtube.com', 'youtube.com', 'm.youtube.com', 'youtu.be'], true) => self::youtube($host, $path, $query),
            in_array($host, ['www.facebook.com', 'facebook.com', 'm.facebook.com'], true) => self::facebook($path, $query),
            in_array($host, ['www.tiktok.com', 'tiktok.com'], true) => self::tiktok($path),
            default => null,
        };
    }

    /** ประกอบลิงก์ดูและลิงก์ฝังจากค่าที่เก็บไว้ — null ถ้าค่าในฐานข้อมูลไม่ตรงรูปแบบ (ไม่เชื่อแม้แต่ของที่เก็บเอง) */
    public static function build(string $provider, string $externalId): ?array
    {
        return match ($provider) {
            'youtube' => preg_match('/^[A-Za-z0-9_-]{11}$/', $externalId) === 1 ? [
                'provider' => 'youtube',
                'external_id' => $externalId,
                'url' => 'https://www.youtube.com/watch?v='.$externalId,
                'embed_url' => 'https://www.youtube-nocookie.com/embed/'.$externalId,
            ] : null,
            'facebook' => preg_match('/^[0-9]{5,25}$/', $externalId) === 1 ? [
                'provider' => 'facebook',
                'external_id' => $externalId,
                'url' => 'https://www.facebook.com/watch/?v='.$externalId,
                'embed_url' => 'https://www.facebook.com/plugins/video.php?href='.rawurlencode('https://www.facebook.com/watch/?v='.$externalId).'&show_text=false',
            ] : null,
            'tiktok' => preg_match('/^[0-9]{10,25}$/', $externalId) === 1 ? [
                'provider' => 'tiktok',
                'external_id' => $externalId,
                'url' => 'https://www.tiktok.com/embed/v2/'.$externalId,
                'embed_url' => 'https://www.tiktok.com/embed/v2/'.$externalId,
            ] : null,
            default => null,
        };
    }

    private static function youtube(string $host, string $path, array $query): ?array
    {
        $id = match (true) {
            $host === 'youtu.be' => ltrim($path, '/'),
            $path === '/watch' => is_string($query['v'] ?? null) ? $query['v'] : '',
            preg_match('#^/(?:live|shorts|embed)/([^/]+)/?$#', $path, $m) === 1 => $m[1],
            default => '',
        };

        return self::build('youtube', $id);
    }

    private static function facebook(string $path, array $query): ?array
    {
        $id = match (true) {
            preg_match('#^/watch/?$#', $path) === 1 => is_string($query['v'] ?? null) ? $query['v'] : '',
            preg_match('#^/[A-Za-z0-9.\-]{1,80}/videos/(?:[^/]+/)?([0-9]+)/?$#', $path, $m) === 1 => $m[1],
            preg_match('#^/reel/([0-9]+)/?$#', $path, $m) === 1 => $m[1],
            default => '',
        };

        return self::build('facebook', $id);
    }

    private static function tiktok(string $path): ?array
    {
        $id = preg_match('#^/@[A-Za-z0-9._]{1,30}/video/([0-9]+)/?$#', $path, $m) === 1 ? $m[1] : '';

        return self::build('tiktok', $id);
    }
}
