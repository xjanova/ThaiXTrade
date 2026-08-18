<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KycDeletionRequest;
use App\Models\KycSubmission;
use App\Models\SiteSetting;
use App\Services\Kyc\KycGate;
use App\Services\Kyc\KycPurgeService;
use App\Services\Kyc\KycService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TPIX TRADE — หลังบ้านยืนยันตัวตน.
 *
 * ทีมงานตรวจเอกสารเอง (เจ้าของเลือกแบบนี้ ไม่ใช้ผู้ให้บริการภายนอก)
 * หน้านี้จึงต้องทำให้คนตรวจเห็นทุกอย่างที่ต้องใช้ตัดสินในจอเดียว
 * และต้องบันทึกว่าใครตัดสินอะไรไว้เสมอ
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
     * คิวตรวจ.
     */
    public function index(Request $request): InertiaResponse
    {
        $status = (string) $request->query('status', KycSubmission::STATUS_PENDING);

        $allowed = [
            KycSubmission::STATUS_PENDING,
            KycSubmission::STATUS_APPROVED,
            KycSubmission::STATUS_REJECTED,
            KycSubmission::STATUS_CANCELLED,
            'all',
        ];

        if (! in_array($status, $allowed, true)) {
            $status = KycSubmission::STATUS_PENDING;
        }

        $query = KycSubmission::query()
            ->with('user:id,email,name,wallet_address')
            ->latest('submitted_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $submissions = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Kyc/Index', [
            'submissions' => $submissions->through(fn (KycSubmission $s) => [
                'uuid' => $s->uuid,
                'level' => $s->level,
                'status' => $s->status,
                // ชื่ออยู่ในคอลัมน์ที่เข้ารหัส — ถอดตรงนี้เพราะทีมงานต้องเห็นเพื่อคัดคิว
                'full_name' => $s->purged_at ? null : $s->full_name,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'reviewed_at' => $s->reviewed_at?->toIso8601String(),
                'purged_at' => $s->purged_at?->toIso8601String(),
                'user' => [
                    'id' => $s->user?->id,
                    'email' => $s->user?->email,
                    'name' => $s->user?->name,
                    'wallet_address' => $s->user?->wallet_address,
                ],
            ]),
            'filters' => ['status' => $status],
            'counts' => [
                'pending' => KycSubmission::where('status', KycSubmission::STATUS_PENDING)->count(),
                'approved' => KycSubmission::where('status', KycSubmission::STATUS_APPROVED)->count(),
                'rejected' => KycSubmission::where('status', KycSubmission::STATUS_REJECTED)->count(),
                'deletion_pending' => KycDeletionRequest::where('status', KycDeletionRequest::STATUS_PENDING)->count(),
            ],
            'settings' => $this->settingsPayload(),
        ]);
    }

    /**
     * หน้าตรวจใบเดียว.
     */
    public function show(string $uuid): InertiaResponse
    {
        $submission = KycSubmission::query()
            ->with(['user:id,email,name,wallet_address,kyc_status,created_at', 'documents.views.admin:id,name', 'reviewer:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $purged = $submission->isPurged();

        return Inertia::render('Admin/Kyc/Show', [
            'submission' => [
                'uuid' => $submission->uuid,
                'level' => $submission->level,
                'status' => $submission->status,
                'purged_at' => $submission->purged_at?->toIso8601String(),
                'purge_after' => $submission->purge_after?->toIso8601String(),
                'purge_reason' => $submission->purge_reason,

                // ── ข้อมูลที่ถอดรหัสแล้ว ────────────────────────────────────
                //
                // ส่งข้ามไปฝั่งเบราว์เซอร์เท่าที่คนตรวจต้องใช้เทียบกับรูปบัตรจริงๆ
                // ใบที่ล้างแล้วส่ง null ทั้งชุด — ไม่มีอะไรให้ดูอีกแล้ว
                'full_name' => $purged ? null : $submission->full_name,
                'full_name_en' => $purged ? null : $submission->full_name_en,
                'id_type' => $submission->id_type,
                'national_id' => $purged ? null : $submission->national_id,
                'date_of_birth' => $purged ? null : $submission->date_of_birth,
                'nationality' => $purged ? null : $submission->nationality,
                'address' => $purged ? null : $submission->address,
                'occupation' => $purged ? null : $submission->occupation,
                'phone' => $purged ? null : $submission->phone,

                'consent_version' => $submission->consent_version,
                'consented_at' => $submission->consented_at?->toIso8601String(),
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
                'reviewed_by' => $submission->reviewer?->name,
                'review_note' => $submission->review_note,
                'reject_reason' => $submission->reject_reason,

                'documents' => $submission->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'type' => $d->type,
                    'original_name' => $d->original_name,
                    'size' => $d->size,
                    'mime' => $d->mime,
                    'purged' => $d->isPurged(),
                    'views' => $d->views->map(fn ($v) => [
                        'admin' => $v->admin?->name ?? 'ไม่ทราบ',
                        'viewed_at' => $v->viewed_at?->toIso8601String(),
                    ])->all(),
                ])->all(),
            ],
            'user' => [
                'id' => $submission->user?->id,
                'email' => $submission->user?->email,
                'name' => $submission->user?->name,
                'wallet_address' => $submission->user?->wallet_address,
                'kyc_status' => $submission->user?->kyc_status,
                'joined_at' => $submission->user?->created_at?->toIso8601String(),
            ],
            // บัตรใบเดียวกันเคยยื่นในบัญชีอื่นไหม — ธงเตือน ไม่ใช่คำตัดสิน
            'duplicateUserIds' => $this->kyc->duplicateAccountIds($submission),
            'history' => KycSubmission::query()
                ->where('user_id', $submission->user_id)
                ->where('id', '!=', $submission->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (KycSubmission $s) => [
                    'uuid' => $s->uuid,
                    'status' => $s->status,
                    'level' => $s->level,
                    'submitted_at' => $s->submitted_at?->toIso8601String(),
                    'reject_reason' => $s->reject_reason,
                ])
                ->all(),
        ]);
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $submission = KycSubmission::where('uuid', $uuid)->firstOrFail();

        abort_if($submission->isPurged(), 410, 'ใบนี้ถูกล้างข้อมูลแล้ว ตรวจไม่ได้');

        $this->kyc->approve($submission, Auth::guard('admin')->user(), $validated['note'] ?? null);

        $this->audit('kyc.approve', $submission);

        return back()->with('success', 'อนุมัติแล้ว');
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            // บังคับกรอกเหตุผล — ผู้ใช้ต้องรู้ว่าต้องแก้อะไรถึงจะยื่นใหม่ให้ผ่าน
            'reason' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $submission = KycSubmission::where('uuid', $uuid)->firstOrFail();

        abort_if($submission->isPurged(), 410, 'ใบนี้ถูกล้างข้อมูลแล้ว ตรวจไม่ได้');

        $this->kyc->reject(
            $submission,
            Auth::guard('admin')->user(),
            $validated['reason'],
            $validated['note'] ?? null,
        );

        $this->audit('kyc.reject', $submission);

        return back()->with('success', 'ปฏิเสธแล้ว');
    }

    // =========================================================================
    // คำขอลบข้อมูล (PDPA)
    // =========================================================================

    public function deletionRequests(): InertiaResponse
    {
        $requests = KycDeletionRequest::query()
            ->with(['user:id,email,name,wallet_address', 'handler:id,name'])
            ->latest('requested_at')
            ->paginate(20);

        return Inertia::render('Admin/Kyc/Deletions', [
            'requests' => $requests->through(fn (KycDeletionRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'reason' => $r->reason,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'handled_at' => $r->handled_at?->toIso8601String(),
                'handler' => $r->handler?->name,
                'handler_note' => $r->handler_note,
                'files_deleted' => $r->files_deleted,
                'user' => [
                    'id' => $r->user?->id,
                    'email' => $r->user?->email,
                    'name' => $r->user?->name,
                    'wallet_address' => $r->user?->wallet_address,
                ],
            ]),
        ]);
    }

    /**
     * ลบข้อมูลตามคำขอ — ลบไฟล์จริง เอากลับมาไม่ได้.
     */
    public function completeDeletion(Request $request, KycDeletionRequest $deletionRequest): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if(! $deletionRequest->isPending(), 409, 'คำขอนี้ถูกดำเนินการไปแล้ว');

        $result = $this->purge->completeDeletion(
            $deletionRequest,
            Auth::guard('admin')->user(),
            $validated['note'] ?? null,
        );

        $this->audit('kyc.deletion.complete', null, [
            'request_id' => $deletionRequest->id,
            'user_id' => $deletionRequest->user_id,
            'files_deleted' => $result->files_deleted,
        ]);

        return back()->with('success', "ลบข้อมูลแล้ว ({$result->files_deleted} ไฟล์)");
    }

    public function rejectDeletion(Request $request, KycDeletionRequest $deletionRequest): RedirectResponse
    {
        $validated = $request->validate([
            // ปฏิเสธคำขอลบข้อมูลต้องอ้างฐานทางกฎหมายได้ ไม่ใช่แค่ไม่อยากลบ
            'note' => ['required', 'string', 'max:1000'],
        ]);

        abort_if(! $deletionRequest->isPending(), 409, 'คำขอนี้ถูกดำเนินการไปแล้ว');

        $this->purge->rejectDeletion(
            $deletionRequest,
            Auth::guard('admin')->user(),
            $validated['note'],
        );

        $this->audit('kyc.deletion.reject', null, ['request_id' => $deletionRequest->id]);

        return back()->with('success', 'บันทึกการปฏิเสธแล้ว');
    }

    // =========================================================================
    // ตั้งค่าด่าน
    // =========================================================================

    /**
     * เปิด/ปิดด่านรายฟีเจอร์.
     *
     * เจ้าของสั่งว่าต้องกดเปิดปิดเองได้ที่หลังบ้าน ไม่ต้องแก้โค้ด
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $featureKeys = array_keys((array) config('kyc.features', []));

        $rules = [
            'enabled' => ['required', 'boolean'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:36500'],
            'consent_version' => ['required', 'string', 'max:20'],
        ];

        foreach ($featureKeys as $key) {
            $rules["gates.{$key}.enabled"] = ['required', 'boolean'];
            $rules["gates.{$key}.level"] = ['required', 'in:basic,enhanced'];
        }

        $validated = $request->validate($rules);

        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_ENABLED, $validated['enabled'] ? '1' : '0', 'boolean');
        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_RETENTION, (string) $validated['retention_days'], 'number');
        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_CONSENT_VERSION, $validated['consent_version'], 'string');

        foreach ($featureKeys as $key) {
            $gate = $validated['gates'][$key];

            SiteSetting::set(
                KycGate::SETTING_GROUP,
                KycGate::KEY_GATE_PREFIX.$key,
                $gate['enabled'] ? '1' : '0',
                'boolean'
            );

            SiteSetting::set(
                KycGate::SETTING_GROUP,
                KycGate::KEY_LEVEL_PREFIX.$key,
                $gate['level'],
                'string'
            );
        }

        $this->audit('kyc.settings.update', null, ['enabled' => $validated['enabled']]);

        return back()->with('success', 'บันทึกการตั้งค่าแล้ว');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function settingsPayload(): array
    {
        $gates = [];

        foreach ($this->gate->features() as $feature) {
            $gates[$feature['key']] = [
                'enabled' => $feature['enabled'],
                'level' => $feature['level'],
                'label_th' => $feature['label_th'],
                'label_en' => $feature['label_en'],
                'desc_th' => $feature['desc_th'],
            ];
        }

        return [
            'enabled' => $this->gate->isEnabled(),
            'retention_days' => $this->kyc->retentionDays(),
            'consent_version' => $this->kyc->consentVersion(),
            'gates' => $gates,
        ];
    }

    /**
     * บันทึกว่าใครตัดสินอะไร.
     *
     * ⚠️ ห้ามใส่ชื่อ เลขบัตร หรือข้อมูลส่วนบุคคลลง audit log
     *    audit log ไม่ได้ถูกล้างตามคำขอลบข้อมูล ใส่ไปแล้วลบไม่ออก
     *    เก็บแค่ uuid ของใบ ซึ่งชี้กลับไปหาแถวที่ล้างได้
     */
    private function audit(string $action, ?KycSubmission $submission = null, array $extra = []): void
    {
        try {
            AuditLog::create([
                'admin_id' => Auth::guard('admin')->id(),
                'action' => $action,
                'model_type' => $submission ? KycSubmission::class : null,
                'model_id' => $submission?->id,
                'new_values' => array_merge(
                    $submission ? ['submission_uuid' => $submission->uuid] : [],
                    $extra
                ),
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // บันทึกไม่ได้ไม่ควรทำให้การตรวจล้ม แต่ต้องรู้ว่าบันทึกหาย
            report($e);
        }
    }
}
