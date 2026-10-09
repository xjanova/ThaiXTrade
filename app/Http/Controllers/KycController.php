<?php

namespace App\Http\Controllers;

use App\Models\KycDeletionRequest;
use App\Models\KycSubmission;
use App\Services\Kyc\KycGate;
use App\Services\Kyc\KycPurgeService;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;

/**
 * TPIX TRADE — หน้ายืนยันตัวตนของผู้ใช้.
 *
 * ผู้ใช้เห็นได้เฉพาะใบของตัวเอง — ทุกจุดที่รับ id มาจาก request ต้องกรองด้วย user_id
 * ไม่ใช่หาแล้วเชื่อ ไม่งั้นเปลี่ยนเลขใน URL ก็เปิดใบคนอื่นได้
 *
 * Developed by Xman Studio.
 */
class KycController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly KycGate $gate,
        private readonly KycPurgeService $purge,
    ) {}

    /**
     * หน้าสถานะ + ขั้นตอนยืนยันตัวตนในแอป.
     *
     * เปิดได้โดยไม่ต้องล็อกอิน — ยืนยันตัวตนทำในแอป TPIX TRADE เท่านั้น หน้านี้จึงต้องอธิบาย
     * ให้ทุกคนได้ เดิมอยู่หลัง 'auth' ผู้ใช้กระเป๋าล้วน (ไม่มีรหัสผ่าน) กดปุ่ม "ยืนยันตัวตนก่อนเทรด"
     * แล้วเด้งไปหน้าเข้าสู่ระบบที่เขาเข้าไม่ได้ ไปต่อไม่ได้เลย
     * ไม่รู้ว่าเป็นใคร = ไม่มีใบ ไม่มีประวัติ — ข้อมูลส่วนตัวยังออกเฉพาะเจ้าของเหมือนเดิม
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $latest = $user?->kycSubmissions()
            ->with('documents')
            ->latest('id')
            ->first();

        $pendingDeletion = $user
            ? KycDeletionRequest::query()
                ->where('user_id', $user->id)
                ->where('status', KycDeletionRequest::STATUS_PENDING)
                ->first()
            : null;

        return Inertia::render('Kyc/Index', [
            'submission' => $latest?->toOwnerArray(),
            'history' => $user
                ? $user->kycSubmissions()
                    ->latest('id')
                    ->limit(10)
                    ->get()
                    ->map(fn (KycSubmission $s) => [
                        'uuid' => $s->uuid,
                        'level' => $s->level,
                        'status' => $s->status,
                        'submitted_at' => $s->submitted_at?->toIso8601String(),
                        'reviewed_at' => $s->reviewed_at?->toIso8601String(),
                        'reject_reason' => $s->reject_reason,
                    ])
                    ->all()
                : [],
            'gate' => $this->gate->statusFor($user),
            'features' => $this->gate->features(),
            'deletionRequest' => $pendingDeletion?->toOwnerArray(),
            // ยืนยันตัวตนทำในแอป — ปุ่มเปิดแอป (มือถือ) และ QR ไปหน้าดาวน์โหลด (คอมพิวเตอร์)
            'app' => [
                'deep_link' => 'tpixtrade://kyc',
                'download_url' => route('download'),
            ],
        ]);
    }

    /**
     * ยื่นคำขอ.
     */
    public function store(Request $request): RedirectResponse
    {
        $maxKb = (int) config('kyc.uploads.max_size_kb', 8192);
        $extensions = implode(',', (array) config('kyc.uploads.extensions', ['jpg', 'png']));

        $fileRule = ['nullable', 'file', "mimes:{$extensions}", "max:{$maxKb}"];

        $validated = $request->validate([
            'level' => ['required', 'in:basic,enhanced'],
            'full_name' => ['required', 'string', 'max:150'],
            'full_name_en' => ['nullable', 'string', 'max:150'],
            'id_type' => ['required', 'in:national_id,passport'],
            // ไม่บังคับรูปแบบเลขบัตรตายตัว เพราะรับพาสปอร์ตต่างชาติด้วย
            // ความถูกต้องจริงมาจากคนตรวจที่เทียบกับรูปบัตร ไม่ใช่จาก regex
            'national_id' => ['required', 'string', 'max:40'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'nationality' => ['required', 'string', 'size:2'],
            'address' => ['required', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'consent' => ['accepted'],
            'documents.id_card_front' => $fileRule,
            'documents.id_card_back' => $fileRule,
            'documents.selfie_with_id' => $fileRule,
            'documents.address_proof' => $fileRule,
        ]);

        $files = array_filter((array) $request->file('documents', []));

        try {
            $this->kyc->submit(
                $request->user(),
                $validated,
                $files,
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['kyc' => $e->getMessage()]);
        }

        return back()->with('success', 'ส่งเอกสารเรียบร้อย ทีมงานจะตรวจภายใน 1–3 วันทำการ');
    }

    /**
     * ยกเลิกใบของตัวเองที่ยังรอตรวจ.
     */
    public function cancel(Request $request, string $uuid): RedirectResponse
    {
        $submission = $this->ownedSubmission($request, $uuid);

        $this->kyc->cancel($submission);

        return back()->with('success', 'ยกเลิกคำขอแล้ว');
    }

    // =========================================================================
    // PDPA
    // =========================================================================

    /**
     * ขอสำเนาข้อมูลของตัวเอง.
     *
     * ส่งเป็นไฟล์ JSON ให้ดาวน์โหลด ไม่แสดงบนหน้าเว็บ — ข้างในมีเลขบัตรเต็ม
     * ไม่ควรค้างอยู่ในแคชเบราว์เซอร์หรือประวัติหน้าเว็บ
     */
    public function export(Request $request): JsonResponse
    {
        $data = $this->purge->exportForUser($request->user());

        $filename = 'tpix-kyc-'.$request->user()->id.'-'.now()->format('Ymd-His').'.json';

        return response()
            ->json($data, 200, [
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-store, private',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * ขอลบข้อมูล.
     */
    public function requestDeletion(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->purge->requestDeletion(
            $request->user(),
            $validated['reason'] ?? null,
            $request->ip(),
        );

        return back()->with(
            'success',
            'รับคำขอลบข้อมูลแล้ว ทีมงานจะดำเนินการและแจ้งผลกลับ'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * หาใบของ "ผู้ใช้คนนี้" เท่านั้น.
     *
     * ⚠️ where user_id อยู่ใน query ไม่ใช่เช็คทีหลัง — ลืมเช็คทีหลังเมื่อไหร่
     *    ก็กลายเป็นช่องให้เปิดใบคนอื่นด้วยการเดา uuid
     */
    private function ownedSubmission(Request $request, string $uuid): KycSubmission
    {
        return KycSubmission::query()
            ->where('user_id', Auth::id())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
