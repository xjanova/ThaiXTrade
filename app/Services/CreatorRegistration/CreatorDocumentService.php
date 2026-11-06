<?php

namespace App\Services\CreatorRegistration;

use App\Models\CreatorRegistration;
use App\Models\CreatorRegistrationDocument as Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * เอกสารประกอบใบลงทะเบียนผู้สร้างเหรียญ — รับไฟล์ เก็บนอก public และวาดภาพลายน้ำให้ผู้ซื้อดู.
 *
 * กติกา:
 *   - แก้เอกสารได้เฉพาะใบที่ยังแก้ได้ (ร่าง/ถูกปฏิเสธ) และต้องบันทึกข้อมูลผู้สร้างก่อน (รู้ว่าเป็นบุคคลหรือบริษัท)
 *   - ประเภท "บริษัท" อัปโหลดได้เฉพาะนิติบุคคล — บุคคลธรรมดาไม่ต้องมีและไม่ถูกบังคับ
 *   - MIME ตรวจจากเนื้อไฟล์ (finfo) ไม่เชื่อนามสกุล · ภาพต้องถอดรหัสได้จริง · PDF ต้องขึ้นต้น %PDF-
 *   - ยินยอมเผยแพร่ได้เฉพาะประเภทสาธารณะที่เป็นภาพ (เซิร์ฟเวอร์วาดลายน้ำได้) — บัตรไม่มีวันเผยแพร่
 *   - ทุกการเปลี่ยนแปลงล้างลายเซ็นข้อตกลงเดิม และอัปเดต documents_hash (แฮชรายการเอกสารที่ผูกในข้อความที่เซ็น)
 *
 * Developed by Xman Studio.
 */
class CreatorDocumentService
{
    /** ภาพใหญ่เกินนี้ไม่รับ — GD ถอดภาพ 10k×10k กินแรมหลายร้อย MB ต่อคำขอ */
    public const MAX_PIXELS = 40_000_000;

    /** ความกว้างสูงสุดของภาพลายน้ำที่ส่งให้ผู้ซื้อ — พออ่านเอกสารได้ ไม่ใช่ไฟล์คุณภาพเต็ม */
    public const PUBLIC_MAX_EDGE = 1400;

    /** ภาพลายน้ำเก็บแคชไว้กี่วินาที (วันที่บนภาพเปลี่ยนก็เป็นคีย์ใหม่) */
    public const WATERMARK_CACHE_TTL = 3600;

    public function __construct(
        private readonly CreatorRegistrationService $registrations,
    ) {}

    /**
     * @throws CreatorRegistrationException
     */
    public function upload(CreatorRegistration $registration, UploadedFile $file, string $type, bool $publish): Document
    {
        $this->assertEditable($registration);
        $this->assertTypeAllowed($registration, $type);

        if ($registration->documents()->count() >= Document::MAX_FILES) {
            throw new CreatorRegistrationException('TOO_MANY_DOCUMENTS', __('แนบเอกสารได้สูงสุด :max ไฟล์ — ลบไฟล์ที่ไม่ใช้ก่อน', ['max' => Document::MAX_FILES]));
        }

        if ($registration->documents()->where('type', $type)->count() >= Document::TYPES[$type]['max']) {
            throw new CreatorRegistrationException('TOO_MANY_OF_TYPE', __('เอกสารประเภทนี้แนบได้สูงสุด :max ไฟล์', ['max' => Document::TYPES[$type]['max']]));
        }

        $path = $file->getRealPath();
        $mime = (string) $file->getMimeType();   // finfo จากเนื้อไฟล์
        $ext = Document::MIMES[$mime] ?? null;

        if ($ext === null || $path === false) {
            throw new CreatorRegistrationException('INVALID_FILE', __('รับเฉพาะไฟล์ JPG, PNG, WebP หรือ PDF'));
        }

        $width = $height = null;
        if ($ext === 'pdf') {
            $head = (string) @file_get_contents($path, false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw new CreatorRegistrationException('INVALID_FILE', __('ไฟล์ PDF เสียหายหรือไม่ใช่ PDF จริง'));
            }
        } else {
            $info = @getimagesize($path);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                throw new CreatorRegistrationException('INVALID_FILE', __('เปิดไฟล์ภาพไม่ได้ — ลองถ่ายหรือสแกนใหม่'));
            }
            if ($info[0] * $info[1] > self::MAX_PIXELS) {
                throw new CreatorRegistrationException('INVALID_FILE', __('ภาพใหญ่เกินไป — ย่อให้ไม่เกิน 40 ล้านพิกเซลก่อนแนบ'));
            }
            [$width, $height] = [min($info[0], 65535), min($info[1], 65535)];
        }

        $uuid = (string) Str::uuid();
        $stored = "{$registration->uuid}/{$uuid}.{$ext}";
        Storage::disk(Document::DISK)->putFileAs($registration->uuid, $file, "{$uuid}.{$ext}");

        try {
            $document = DB::transaction(function () use ($registration, $uuid, $type, $stored, $mime, $file, $path, $publish, $ext, $width, $height) {
                $doc = Document::create([
                    'uuid' => $uuid,
                    'creator_registration_id' => $registration->id,
                    'type' => $type,
                    'path' => $stored,
                    'mime' => $mime,
                    'size' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', $path),
                    'original_name' => $this->cleanName($file->getClientOriginalName()),
                    'publish' => $publish && Document::typeIsPublic($type) && $ext !== 'pdf',
                    'width' => $width,
                    'height' => $height,
                ]);
                $this->changed($registration);

                return $doc;
            });
        } catch (Throwable $e) {
            Storage::disk(Document::DISK)->delete($stored);

            throw $e;
        }

        return $document;
    }

    /**
     * @throws CreatorRegistrationException
     */
    public function setPublish(CreatorRegistration $registration, Document $document, bool $publish): Document
    {
        $this->assertEditable($registration);

        if ($publish && (! Document::typeIsPublic($document->type) || ! $document->isImage())) {
            throw new CreatorRegistrationException('NOT_PUBLISHABLE', __('เอกสารนี้เผยแพร่ไม่ได้ — เผยแพร่ได้เฉพาะเอกสารบริษัทหรือรูปโครงการที่เป็นไฟล์ภาพ'));
        }

        DB::transaction(function () use ($registration, $document, $publish) {
            $document->update(['publish' => $publish]);
            $this->changed($registration);
        });

        return $document->refresh();
    }

    /**
     * @throws CreatorRegistrationException
     */
    public function delete(CreatorRegistration $registration, Document $document): void
    {
        $this->assertEditable($registration);

        DB::transaction(function () use ($registration, $document) {
            $document->delete();
            $this->changed($registration);
        });

        Storage::disk(Document::DISK)->delete($document->path);
    }

    /**
     * แฮชรายการเอกสาร (ประเภท + SHA-256 + ยินยอมเผยแพร่) — null เมื่อไม่มีเอกสาร.
     *
     * ไม่รวมชื่อไฟล์/เวลา — สิ่งที่ผู้สร้างรับรองคือเนื้อไฟล์และสิ่งที่ยอมให้เปิดเผย
     */
    public static function manifestHash(iterable $documents): ?string
    {
        $rows = [];
        foreach ($documents as $doc) {
            $rows[] = $doc->type.'|'.$doc->sha256.'|'.($doc->publish ? '1' : '0');
        }

        if ($rows === []) {
            return null;
        }

        sort($rows, SORT_STRING);

        return hash('sha256', implode("\n", $rows));
    }

    /**
     * ภาพลายน้ำสำหรับผู้ซื้อ (JPEG) — วาดใหม่จากต้นฉบับทุกครั้ง ข้อมูล EXIF/GPS ไม่ติดออกไป.
     *
     * @throws CreatorRegistrationException
     */
    public function watermarkedJpeg(Document $document, string $dateLabel): string
    {
        // แคชผลต่อไฟล์ต่อวัน — ภาพใหญ่ถอดรหัสครั้งละ ~160 MB ห้ามให้ใครยิงซ้ำจนเครื่องล่ม
        // เก็บเป็น base64 เพราะ cache store แบบฐานข้อมูลรับไบต์ดิบที่ไม่ใช่ UTF-8 ไม่ได้
        $key = 'creator_doc_wm:'.$document->uuid.':'.$document->sha256.':'.md5($dateLabel);
        $cached = Cache::get($key);
        if (is_string($cached) && ($jpeg = base64_decode($cached, true)) !== false && $jpeg !== '') {
            return $jpeg;
        }

        $jpeg = $this->renderWatermark($document, $dateLabel);
        Cache::put($key, base64_encode($jpeg), self::WATERMARK_CACHE_TTL);

        return $jpeg;
    }

    /**
     * @throws CreatorRegistrationException
     */
    private function renderWatermark(Document $document, string $dateLabel): string
    {
        $this->ensureMemoryFor($document);

        try {
            $raw = Storage::disk(Document::DISK)->get($document->path);   // disk ตั้ง throw — ไฟล์หายโยนมา
        } catch (Throwable) {
            $raw = null;
        }
        $src = ($raw !== null && $raw !== '') ? @imagecreatefromstring($raw) : false;

        if ($src === false) {
            throw new CreatorRegistrationException('DOCUMENT_UNREADABLE', __('เปิดเอกสารนี้ไม่ได้'), 404);
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::PUBLIC_MAX_EDGE / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $img = imagecreatetruecolor($nw, $nh);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));   // PNG โปร่งใสต้องไม่กลายเป็นพื้นดำ
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $th = 'ใช้ตรวจสอบโครงการบน tpix.online เท่านั้น · '.$dateLabel;
        $en = 'For project verification on tpix.online only · '.$dateLabel;
        $font = storage_path('fonts/Sarabun-Bold.ttf');
        $hasFont = is_file($font) && function_exists('imagettftext');

        // ลายน้ำเฉียงซ้ำทั้งภาพ — ครอปมุมไหนก็ยังติด
        $tint = imagecolorallocatealpha($img, 200, 30, 30, 88);
        $size = max(12, (int) round(min($nw, $nh) / 26));
        $stepY = $size * 7;
        $stepX = $size * 22;
        for ($y = -$nh; $y < $nh * 2; $y += $stepY) {
            for ($x = -$nw; $x < $nw * 2; $x += $stepX) {
                if ($hasFont) {
                    imagettftext($img, $size, 28, $x, $y, $tint, $font, $th);
                    imagettftext($img, (int) round($size * 0.7), 28, $x + (int) ($size * 1.2), $y + (int) ($size * 2.2), $tint, $font, $en);
                } else {
                    imagestring($img, 5, $x, $y, $en, $tint);
                }
            }
        }

        // แถบล่างทึบ บอกที่มาและวัน
        $band = max(22, (int) round($size * 1.9));
        imagefilledrectangle($img, 0, $nh - $band, $nw, $nh, imagecolorallocatealpha($img, 15, 23, 42, 20));
        $white = imagecolorallocate($img, 255, 255, 255);
        $line = ($hasFont ? $th : $en).' · '.substr($document->uuid, 0, 8);
        if ($hasFont) {
            imagettftext($img, max(9, (int) round($band * 0.42)), 0, 8, $nh - (int) round($band * 0.3), $white, $font, $line);
        } else {
            imagestring($img, 3, 6, $nh - $band + 4, $line, $white);
        }

        ob_start();
        imagejpeg($img, null, 78);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        return $jpeg;
    }

    /**
     * ไฟล์ต้นฉบับ — เฉพาะเจ้าของใบและแอดมิน (ผู้เรียกตรวจสิทธิ์แล้ว).
     *
     * เปิดในแท็บ (inline) แต่ sandbox + nosniff — PDF/ภาพที่ฝังสคริปต์ทำงานในโดเมนเราไม่ได้
     * ชื่อไฟล์ใน header เป็น ASCII ที่สร้างเอง ไม่ใช้ชื่อที่ผู้ใช้ตั้ง (กัน header injection)
     */
    public function originalResponse(Document $document): Response
    {
        try {
            $bytes = Storage::disk(Document::DISK)->get($document->path);
        } catch (Throwable $e) {
            Log::warning('Creator document missing on disk', ['document' => $document->uuid, 'error' => class_basename($e)]);
            $bytes = null;
        }

        abort_if($bytes === null, 404);

        $ext = Document::MIMES[$document->mime] ?? 'bin';

        return response($bytes, 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => sprintf('inline; filename="%s-%s.%s"', $document->type, substr($document->uuid, 0, 8), $ext),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // =========================================================================

    /**
     * GD ถอดภาพเป็น ~4 ไบต์ต่อพิกเซล (+ ภาพปลายทาง) — ยก memory_limit ชั่วคราวถ้าโฮสต์ตั้งไว้ต่ำกว่าที่ภาพนี้ต้องใช้.
     * ภาพถูกจำกัดที่ MAX_PIXELS ตั้งแต่ตอนอัปโหลด เพดานนี้จึงมีขอบเขต.
     */
    private function ensureMemoryFor(Document $document): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return;
        }

        $pixels = (int) $document->width * (int) $document->height;
        $needMb = max(128, (int) ceil($pixels * 5 / 1048576) + 96);

        $value = (int) $limit;
        $currentMb = match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => intdiv($value, 1024),
            default => intdiv($value, 1048576),
        };

        if ($currentMb < $needMb) {
            @ini_set('memory_limit', $needMb.'M');
        }
    }

    /**
     * @throws CreatorRegistrationException
     */
    private function assertEditable(CreatorRegistration $registration): void
    {
        if (! $registration->isEditable()) {
            throw new CreatorRegistrationException('NOT_EDITABLE', __('ใบลงทะเบียนนี้ส่งตรวจแล้ว แก้ไขไม่ได้'), 409);
        }

        if (blank($registration->applicant_type)) {
            throw new CreatorRegistrationException('FORM_INCOMPLETE', __('บันทึกข้อมูลผู้สร้างก่อนแนบเอกสาร'));
        }
    }

    /**
     * @throws CreatorRegistrationException
     */
    private function assertTypeAllowed(CreatorRegistration $registration, string $type): void
    {
        $spec = Document::TYPES[$type] ?? null;

        if ($spec === null) {
            throw new CreatorRegistrationException('INVALID_TYPE', __('ไม่รู้จักประเภทเอกสารนี้'));
        }

        if ($spec['company'] && ! $registration->isCompany()) {
            throw new CreatorRegistrationException('COMPANY_ONLY', __('เอกสารประเภทนี้สำหรับนิติบุคคลเท่านั้น — บุคคลธรรมดาไม่ต้องแนบ'));
        }
    }

    /** เอกสารเปลี่ยน = ลายเซ็นเดิมไม่ครอบแล้ว + แฮชรายการใหม่ */
    private function changed(CreatorRegistration $registration): void
    {
        $this->registrations->clearSignature($registration, [
            'documents_hash' => self::manifestHash($registration->documents()->get()),
        ]);
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? '';

        return mb_substr(trim($name), 0, 150) ?: 'document';
    }
}
