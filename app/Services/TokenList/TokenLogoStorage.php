<?php

namespace App\Services\TokenList;

use App\Models\FactoryToken;
use App\Models\Token;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * ไฟล์โลโก้เหรียญที่แอดมินอัปโหลด — ใช้ทั้งเหรียญหลัก (tokens) และเหรียญจาก Token Factory.
 *
 * กติกาไฟล์ (สิ่งที่ explorer / wallet / CoinGecko ต้องการ):
 *   - PNG หรือ WebP เท่านั้น — ไม่รับ SVG (XML ฝัง <script> ได้ ดู TokenFactoryApiController::uploadLogo)
 *   - สี่เหลี่ยมจัตุรัส อย่างน้อย 128×128 (CoinGecko อยากได้ ≥ 200×200) · ไม่เกิน 512 KB
 *
 * ชื่อไฟล์ผูกกับเนื้อไฟล์: token-logos/{chainId}/{address}-{sha256 16 ตัว}.{ext}
 *   - ไฟล์เดิม = URL เดิมตลอดไป (Cloudflare/เบราว์เซอร์แคชรูป 1 ปีได้โดยไม่ค้างของเก่า)
 *   - เปลี่ยนรูป = URL ใหม่ → tokenlist.json เปลี่ยน → cron ฝั่ง explorer รู้ว่าต้องดึงใหม่
 *   - ไม่ได้อยู่ในโฟลเดอร์รายกระเป๋า (token-logos/0x…) ที่ถูกหมุนเก็บแค่ 3 ไฟล์ล่าสุด
 *
 * Developed by Xman Studio.
 */
class TokenLogoStorage
{
    public const DISK = 'public';

    public const DIR = 'token-logos';

    public const MAX_KB = 512;

    public const MIN_PX = 128;

    /**
     * @return array<int, string>
     */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            // ทั้งนามสกุลที่เดาจากเนื้อไฟล์ และ MIME จริง — กันไฟล์เปลี่ยนชื่อมาหลอก
            'mimes:png,webp',
            'mimetypes:image/png,image/webp',
            'max:'.self::MAX_KB,
            'dimensions:min_width='.self::MIN_PX.',min_height='.self::MIN_PX.',ratio=1/1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [
            "{$field}.mimes" => 'โลโก้ต้องเป็นไฟล์ PNG หรือ WebP เท่านั้น',
            "{$field}.mimetypes" => 'โลโก้ต้องเป็นไฟล์ PNG หรือ WebP เท่านั้น',
            "{$field}.max" => 'โลโก้ต้องมีขนาดไม่เกิน '.self::MAX_KB.' KB',
            "{$field}.dimensions" => 'โลโก้ต้องเป็นสี่เหลี่ยมจัตุรัส ขนาดอย่างน้อย '.self::MIN_PX.'×'.self::MIN_PX.' พิกเซล (แนะนำ 256×256 ขึ้นไป)',
            "{$field}.required" => 'กรุณาเลือกไฟล์โลโก้',
            "{$field}.file" => 'อัปโหลดไฟล์ไม่สำเร็จ',
        ];
    }

    /**
     * เก็บไฟล์ (ผ่าน validate มาแล้ว) แล้วคืน path บนดิสก์ public เช่น "token-logos/4289/0xab…-1a2b3c4d5e6f7a8b.png".
     */
    public function store(UploadedFile $file, int $chainId, string $address): string
    {
        $ext = $file->guessExtension() === 'webp' ? 'webp' : 'png';
        $hash = substr((string) hash_file('sha256', $file->getRealPath()), 0, 16);

        $owner = preg_match('/^0x[0-9a-fA-F]{40}$/', $address) ? strtolower($address) : 'token';
        $dir = self::DIR.'/'.max(0, $chainId);
        $name = "{$owner}-{$hash}.{$ext}";

        Storage::disk(self::DISK)->putFileAs($dir, $file, $name);

        return "{$dir}/{$name}";
    }

    /**
     * ลบไฟล์โลโก้เก่าเมื่อไม่มีแถวไหนใช้แล้ว.
     *
     * ลบได้เฉพาะไฟล์ที่ระบบหลังบ้านเป็นคนสร้าง (tokens/… รุ่นเก่า และ token-logos/{chainId}/…)
     * เดิมลบทุกไฟล์บนดิสก์ public ที่ชื่อตรง — แอดมินที่ใส่ URL /storage/settings/logo.png
     * เป็นโลโก้เหรียญ แล้วเปลี่ยนรูปทีหลัง = โลโก้เว็บหาย
     * และไฟล์เดียวกันอาจถูกใช้ทั้งแถว tokens และ factory_tokens (ชื่อไฟล์ผูกกับเนื้อไฟล์)
     */
    public function deleteIfUnused(?string $logo, ?Token $exceptToken = null, ?FactoryToken $exceptFactory = null): void
    {
        $path = self::diskPath($logo);
        if ($path === null || ! preg_match('#^(tokens/[^/]+|'.self::DIR.'/\d+/[^/]+)$#', $path)) {
            return;
        }

        $variants = [$path, '/storage/'.$path, 'storage/'.$path];

        $usedByToken = Token::query()
            ->whereIn('logo', $variants)
            ->when($exceptToken?->exists, fn ($q) => $q->whereKeyNot($exceptToken->getKey()))
            ->exists();

        $usedByFactory = FactoryToken::query()
            ->whereIn('logo_url', $variants)
            ->when($exceptFactory?->exists, fn ($q) => $q->whereKeyNot($exceptFactory->getKey()))
            ->exists();

        if ($usedByToken || $usedByFactory) {
            return;
        }

        $disk = Storage::disk(self::DISK);
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * ค่าโลโก้ที่เก็บในฐานข้อมูล → path บนดิสก์ public (null = ไม่ใช่ไฟล์บนดิสก์ public เช่น URL ภายนอก).
     */
    public static function diskPath(?string $logo): ?string
    {
        if ($logo === null || $logo === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $logo) || str_starts_with($logo, '//')) {
            return null;
        }

        $path = ltrim($logo, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        } elseif (str_starts_with($logo, '/')) {
            // "/images/…" = ไฟล์ใน public_html ไม่ใช่ดิสก์ public
            return null;
        }

        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }
}
