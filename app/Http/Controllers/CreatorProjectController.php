<?php

namespace App\Http\Controllers;

use App\Models\CreatorRegistration;
use App\Services\CreatorRegistration\CreatorDocumentService;
use App\Services\CreatorRegistration\CreatorPublicProfile;
use App\Services\CreatorRegistration\CreatorRegistrationException;
use App\Support\BuyerRiskWarnings;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — หน้าโครงการสาธารณะของผู้สร้างเหรียญ (ผู้ซื้อเปิดดูได้โดยไม่ล็อกอิน).
 *
 *   GET /token-creator/projects/{uuid}                     ข้อมูลโครงการที่ผู้สร้างยินยอมเผยแพร่
 *   GET /token-creator/projects/{uuid}/documents/{doc}     ภาพเอกสารพร้อมลายน้ำ PDPA (จำกัดอัตรา)
 *
 * เปิดได้เฉพาะใบที่อนุมัติแล้วและผู้สร้างติ๊กยินยอมเผยแพร่ — อย่างอื่นตอบ 404 (ไม่บอกว่ามีใบอยู่)
 * ผู้ซื้อไม่เคยได้ไฟล์ต้นฉบับ: ทุกภาพวาดใหม่ ย่อขนาด ตัด EXIF และประทับ "ใช้ตรวจสอบโครงการบน tpix.online เท่านั้น" + วันที่
 *
 * Developed by Xman Studio.
 */
class CreatorProjectController extends Controller
{
    public function __construct(
        private readonly CreatorPublicProfile $profiles,
        private readonly CreatorDocumentService $documents,
    ) {}

    public function show(string $uuid): InertiaResponse
    {
        $registration = $this->publicRegistration($uuid);

        return Inertia::render('TokenCreator/Project', [
            'project' => $this->profiles->build($registration),
            'risks' => BuyerRiskWarnings::all(),
        ]);
    }

    public function document(string $uuid, string $document): Response
    {
        $registration = $this->publicRegistration($uuid);

        $doc = $registration->documents->firstWhere('uuid', $document);
        abort_unless($doc !== null && $doc->isPubliclyViewable(), 404);

        $date = now()->timezone('Asia/Bangkok')->format('d/m/Y');

        try {
            $jpeg = $this->documents->watermarkedJpeg($doc, $date);
        } catch (CreatorRegistrationException) {
            abort(404);   // ไฟล์หาย/เสีย — ไม่บอกรายละเอียดภายใน
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => sprintf('inline; filename="tpix-verify-%s.jpg"', substr($doc->uuid, 0, 8)),
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, noimageindex',
        ]);
    }

    private function publicRegistration(string $uuid): CreatorRegistration
    {
        $registration = CreatorRegistration::with(['documents', 'user'])->where('uuid', $uuid)->first();

        abort_unless($this->profiles->isPublic($registration), 404);

        return $registration;
    }
}
