<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\CreatorRegistration;
use App\Models\CreatorRegistrationDocument as Document;
use App\Models\SiteSetting;
use App\Services\CreatorRegistration\CreatorDocumentService;
use App\Services\CreatorRegistration\CreatorPublicProfile;
use App\Services\CreatorRegistration\CreatorRegistrationException;
use App\Services\CreatorRegistration\CreatorRegistrationPdf;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use App\Services\Kyc\KycGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — หลังบ้านทะเบียนผู้สร้างเหรียญ.
 *
 * คิวตรวจ → เปิดดูใบ (ข้อมูลที่กรอก + ผล KYC + ผลตรวจลายเซ็นซ้ำ) → อนุมัติ (กำหนดส่วนเริ่มโครงการ)
 * หรือปฏิเสธพร้อมเหตุผล ทุกการตัดสินลง audit log
 *
 * ⚠️ อ่านแอดมินจาก Auth::guard('admin') ตรงๆ เสมอ — ห้ามพึ่ง $request->user()
 *    (actingAs ในเทสต์สลับ guard ให้ ทำให้เทสต์เขียวทั้งที่ production อ่าน guard ผิด)
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationController extends Controller
{
    public function __construct(
        private readonly CreatorRegistrationService $registrations,
        private readonly KycGate $kyc,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $status = (string) $request->query('status', CreatorRegistration::STATUS_SUBMITTED);
        if (! in_array($status, [...CreatorRegistration::STATUSES, 'all'], true)) {
            $status = CreatorRegistration::STATUS_SUBMITTED;
        }

        $query = CreatorRegistration::query()->latest('updated_at');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $rows = $query->paginate(20)->withQueryString();

        $counts = CreatorRegistration::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return Inertia::render('Admin/CreatorRegistrations/Index', [
            'registrations' => $rows->through(fn (CreatorRegistration $r) => [
                'uuid' => $r->uuid,
                'status' => $r->status,
                'applicant_type' => $r->applicant_type,
                'project_name' => $r->project_name,
                'token_symbol' => $r->token_symbol,
                'wallet_address' => $r->wallet_address,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'starter_allocation_bps' => $r->starter_allocation_bps,
            ]),
            'filters' => ['status' => $status],
            'counts' => collect(CreatorRegistration::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'settings' => [
                'required' => $this->registrations->isRequired(),
                'default_starter_bps' => $this->registrations->defaultStarterBps(),
                'max_starter_bps' => CreatorRegistration::MAX_STARTER_BPS,
            ],
            'canEditSettings' => $this->admin()?->isSuperAdmin() ?? false,
        ]);
    }

    public function show(string $uuid): InertiaResponse
    {
        $r = $this->find($uuid);
        $user = $r->user;

        return Inertia::render('Admin/CreatorRegistrations/Show', [
            'registration' => [
                'uuid' => $r->uuid,
                'status' => $r->status,
                'wallet_address' => $r->wallet_address,
                'applicant_type' => $r->applicant_type,
                'project_name' => $r->project_name,
                'token_symbol' => $r->token_symbol,
                // ข้อมูลส่วนตัวถอดรหัสเฉพาะหน้านี้ — ทีมงานต้องเห็นเพื่อตัดสิน
                'form' => $r->form_data,
                'form_hash' => $r->form_hash,
                'ownership_signed_at' => $r->ownership_signed_at?->toIso8601String(),
                'ownership_ip' => $r->ownership_ip,
                'agreement_version' => $r->agreement_version,
                'agreement_hash' => $r->agreement_hash,
                'agreement_message' => $r->agreement_message,
                'agreement_signature' => $r->agreement_signature,
                'agreement_signed_at' => $r->agreement_signed_at?->toIso8601String(),
                'agreement_ip' => $r->agreement_ip,
                'agreement_user_agent' => $r->agreement_user_agent,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'starter_allocation_bps' => $r->starter_allocation_bps,
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'reviewer' => $r->reviewer?->name,
                'rejection_reason' => $r->rejection_reason,
                'documents_hash' => $r->documents_hash,
                'documents' => $r->documents->map(fn (Document $d) => [
                    'uuid' => $d->uuid,
                    'type' => $d->type,
                    'mime' => $d->mime,
                    'size' => $d->size,
                    'sha256' => $d->sha256,
                    'original_name' => $d->original_name,
                    'publish' => $d->publish,
                    'public' => $d->isPubliclyViewable(),
                ])->values()->all(),
                'missing_documents' => $this->registrations->missingDocuments($r),
                'public_url' => app(CreatorPublicProfile::class)->isPublic($r) ? route('token-creator.projects.show', $r->uuid) : null,
            ],
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'kyc' => [
                'approved' => $this->registrations->hasKyc($user),
                'level' => $this->kyc->approvedLevel($user),
            ],
            'evidence' => $this->registrations->verifyEvidence($r),
            'defaultStarterBps' => $this->registrations->defaultStarterBps(),
            'maxStarterBps' => CreatorRegistration::MAX_STARTER_BPS,
            // route อนุมัติ/ปฏิเสธล็อก super_admin — ระดับอื่นเห็นข้อมูลได้แต่ไม่เห็นปุ่ม
            'canDecide' => $this->admin()?->isSuperAdmin() ?? false,
        ]);
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            'starter_allocation_bps' => ['required', 'integer', 'min:0', 'max:'.CreatorRegistration::MAX_STARTER_BPS],
        ], [], [
            'starter_allocation_bps' => __('ส่วนเริ่มโครงการ'),
        ]);

        $admin = $this->admin();
        abort_if($admin === null, 403);

        try {
            $this->registrations->approve($this->find($uuid), $admin, (int) $validated['starter_allocation_bps']);
        } catch (CreatorRegistrationException $e) {
            return back()->withErrors(['registration' => $e->getMessage()]);
        }

        return back()->with('success', __('อนุมัติผู้สร้างเหรียญแล้ว'));
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], [
            'reason' => __('เหตุผลที่ไม่อนุมัติ'),
        ]);

        $admin = $this->admin();
        abort_if($admin === null, 403);

        try {
            $this->registrations->reject($this->find($uuid), $admin, trim($validated['reason']));
        } catch (CreatorRegistrationException $e) {
            return back()->withErrors(['registration' => $e->getMessage()]);
        }

        return back()->with('success', __('ปฏิเสธใบลงทะเบียนแล้ว'));
    }

    /**
     * เปิดไฟล์ต้นฉบับ (รวมบัตรผู้มีอำนาจลงนาม) — แอดมินทุกระดับที่เข้าหน้านี้ได้ บันทึกว่าใครเปิดไฟล์ไหน.
     */
    public function document(string $uuid, string $document, CreatorDocumentService $documents): Response
    {
        $r = $this->find($uuid);
        $doc = $r->documents->firstWhere('uuid', $document);
        abort_if($doc === null, 404);

        AuditLog::log('creator_registration.document_view', $r, null, [
            'uuid' => $r->uuid,
            'document' => $doc->uuid,
            'type' => $doc->type,
        ]);

        return $documents->originalResponse($doc);
    }

    public function pdf(string $uuid, CreatorRegistrationPdf $pdf): Response
    {
        $r = $this->find($uuid);
        abort_if(! is_array($r->form_data), 404);

        return $pdf->download($r);
    }

    /**
     * สวิตช์บังคับลงทะเบียนก่อนสร้างเหรียญ + ส่วนเริ่มโครงการตั้งต้น (super_admin — route ล็อกไว้).
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'required' => ['required', 'boolean'],
            'default_starter_bps' => ['required', 'integer', 'min:0', 'max:'.CreatorRegistration::MAX_STARTER_BPS],
        ], [], [
            'default_starter_bps' => __('ส่วนเริ่มโครงการตั้งต้น'),
        ]);

        SiteSetting::set(CreatorRegistrationService::SETTING_GROUP, CreatorRegistrationService::KEY_REQUIRED, $validated['required'] ? '1' : '0', 'boolean');
        SiteSetting::set(CreatorRegistrationService::SETTING_GROUP, CreatorRegistrationService::KEY_DEFAULT_BPS, (string) $validated['default_starter_bps'], 'number');

        AuditLog::log('creator_registration.settings', null, null, [
            'required' => (bool) $validated['required'],
            'default_starter_bps' => (int) $validated['default_starter_bps'],
        ]);

        return back()->with('success', __('บันทึกการตั้งค่าแล้ว'));
    }

    private function find(string $uuid): CreatorRegistration
    {
        return CreatorRegistration::query()->with(['user', 'reviewer', 'documents'])->where('uuid', $uuid)->firstOrFail();
    }

    private function admin(): ?AdminUser
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser ? $admin : null;
    }
}
