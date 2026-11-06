<?php

namespace App\Http\Controllers;

use App\Models\CreatorRegistration;
use App\Services\CreatorRegistration\CreatorDocumentService;
use App\Services\CreatorRegistration\CreatorPublicProfile;
use App\Services\CreatorRegistration\CreatorRegistrationException;
use App\Support\BuyerRiskWarnings;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — หน้าโครงการสาธารณะของผู้สร้างเหรียญ (ผู้ซื้อเปิดดูได้โดยไม่ล็อกอิน).
 *
 *   GET /token-creator/projects                            รวมโครงการที่ผ่านการตรวจและยอมเผยแพร่ + เหรียญของแต่ละโครงการ
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

    private const PER_PAGE = 24;

    /**
     * ผู้ซื้อดูว่าเหรียญไหนเป็นของโครงการ/บริษัทไหน — เฉพาะใบที่อนุมัติและยอมเผยแพร่.
     * ค้นด้วยชื่อโครงการ ชื่อที่เผยแพร่ สัญลักษณ์ หรือที่อยู่สัญญาเหรียญ (ไม่ค้นด้วยข้อมูลที่ไม่เผยแพร่).
     */
    public function index(Request $request): InertiaResponse
    {
        $raw = $request->query('q');   // ?q[]=x ต้องไม่กลายเป็น 500
        $q = Str::limit(trim(is_string($raw) ? $raw : ''), 64, '');
        $needle = Str::lower($q);

        $rows = $this->profiles->publishedRegistrations();
        $tokens = $this->profiles->tokensByWallet($rows->pluck('wallet_address')->all());

        $projects = $rows
            ->map(fn (CreatorRegistration $r) => $this->profiles->summary($r) + ['tokens' => $tokens[strtolower($r->wallet_address)] ?? []])
            ->filter(fn (array $p) => $needle === '' || $this->matches($p, $needle))
            ->values();

        // หน้าเกินจำนวนจริง → หน้าสุดท้าย ไม่ใช่หน้าว่างที่บอกว่า "ยังไม่มีโครงการ"
        $lastPage = max(1, (int) ceil($projects->count() / self::PER_PAGE));
        $page = min($lastPage, max(1, (int) $request->query('page', 1)));
        $paginator = new LengthAwarePaginator(
            $projects->forPage($page, self::PER_PAGE)->values(),
            $projects->count(),
            self::PER_PAGE,
            $page,
            ['path' => route('token-creator.projects.index'), 'query' => array_filter(['q' => $q])],
        );

        return Inertia::render('TokenCreator/Projects', [
            'projects' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'prev_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
            ],
            'q' => $q,
        ]);
    }

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

    private function matches(array $project, string $needle): bool
    {
        $hay = [$project['project_name'], $project['display_name'], $project['display_name_en'], $project['token_symbol']];
        foreach ($project['tokens'] as $t) {
            array_push($hay, $t['name'], $t['symbol'], $t['contract_address']);
        }

        foreach ($hay as $value) {
            if (is_string($value) && str_contains(Str::lower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function publicRegistration(string $uuid): CreatorRegistration
    {
        $registration = CreatorRegistration::with(['documents', 'user'])->where('uuid', $uuid)->first();

        abort_unless($this->profiles->isPublic($registration), 404);

        return $registration;
    }
}
