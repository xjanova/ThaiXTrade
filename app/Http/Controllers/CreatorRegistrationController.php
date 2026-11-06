<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCreatorRegistrationRequest;
use App\Models\CreatorRegistration;
use App\Models\CreatorRegistrationDocument as Document;
use App\Models\User;
use App\Services\CreatorRegistration\CreatorAgreement;
use App\Services\CreatorRegistration\CreatorDocumentService;
use App\Services\CreatorRegistration\CreatorPublicProfile;
use App\Services\CreatorRegistration\CreatorRegistrationException;
use App\Services\CreatorRegistration\CreatorRegistrationPdf;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use App\Services\Kyc\KycGate;
use App\Services\Kyc\ThaipromptKycService;
use App\Services\WalletIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — ลงทะเบียนผู้สร้างเหรียญ (หน้าเว็บ).
 *
 *   GET  /token-creator                       หน้าลงทะเบียน (เปิดได้โดยไม่ล็อกอิน)
 *   POST /token-creator/challenge             ขอข้อความยืนยันความเป็นเจ้าของกระเป๋า
 *   POST /token-creator/start                 ส่งลายเซ็น → เข้าสู่ระบบด้วยกระเป๋า + เปิดใบ
 *   PUT  /token-creator/form                  บันทึกข้อมูลผู้สร้าง/โครงการ        (auth)
 *   POST /token-creator/agreement/challenge   ขอข้อความลงนามข้อตกลง               (auth)
 *   POST /token-creator/submit                ส่งลายเซ็นข้อตกลง → รอแอดมินตรวจ    (auth)
 *   GET  /token-creator/pdf                   ดาวน์โหลดข้อตกลงที่กรอก+เซ็นแล้ว   (auth)
 *   POST   /token-creator/documents           แนบเอกสารประกอบ                     (auth)
 *   PATCH  /token-creator/documents/{uuid}    เปลี่ยนความยินยอมเผยแพร่ของไฟล์     (auth)
 *   DELETE /token-creator/documents/{uuid}    ลบไฟล์                              (auth)
 *   GET    /token-creator/documents/{uuid}    เปิดไฟล์ต้นฉบับของตัวเอง             (auth)
 *
 * ใบลงทะเบียนผูกกับ "กระเป๋าของบัญชีที่ล็อกอิน" เสมอ — ไม่รับเลขกระเป๋าจากคำขอหลังขั้นเริ่ม
 * ไม่งั้นใครล็อกอินอยู่ก็แก้/ดาวน์โหลดใบของกระเป๋าคนอื่นได้ด้วยการพิมพ์เลขกระเป๋า
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationController extends Controller
{
    public function __construct(
        private readonly CreatorRegistrationService $registrations,
        private readonly CreatorAgreement $agreement,
        private readonly WalletIdentityService $identities,
        private readonly KycGate $kyc,
        private readonly ThaipromptKycService $thaiprompt,
        private readonly CreatorDocumentService $documents,
        private readonly CreatorPublicProfile $profiles,
    ) {}

    public function show(Request $request): InertiaResponse
    {
        $user = $request->user('web');
        $registration = $this->registrationFor($user);
        $version = $this->agreement->currentVersion();

        return Inertia::render('TokenCreator/Register', [
            'registration' => $registration ? $this->payload($registration) : null,
            'kyc' => [
                'approved' => $this->registrations->hasKyc($user),
                'level' => $this->kyc->approvedLevel($user),
                // KYC ทำในแอป Thaiprompt (เจ้าของสั่ง 2026-11-06) — หน้าเว็บพาไปโหลดแอป เปิดหน้า eKYC แล้วเชื่อมผลกลับ
                'thaiprompt' => $this->thaipromptPayload($user),
            ],
            'agreement' => [
                'version' => $version,
                'hash' => $this->agreement->hash($version),
                'document' => $this->agreement->document($version),
            ],
            'defaultStarterBps' => $this->registrations->defaultStarterBps(),
            'options' => [
                'categories' => SaveCreatorRegistrationRequest::CATEGORIES,
                'fund_sources' => SaveCreatorRegistrationRequest::FUND_SOURCES,
                'document_types' => Document::TYPES,
                'document_max_kb' => Document::MAX_KB,
                'document_max_files' => Document::MAX_FILES,
            ],
        ]);
    }

    /**
     * ลิงก์แอป Thaiprompt + สถานะการเชื่อมบัญชี — ผู้เยี่ยมชม (ยังไม่เข้าด้วยกระเป๋า) ได้แค่ลิงก์แอป.
     */
    private function thaipromptPayload(?User $user): array
    {
        if ($user) {
            return $this->thaiprompt->ownerPayload($user);
        }

        return [
            'available' => $this->thaiprompt->isConfigured(),
            'link' => null,
            'app_link' => (string) config('services.thaiprompt.app_ekyc_link'),
            'download_url' => (string) config('services.thaiprompt.app_download_url'),
        ];
    }

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->registrations->ownershipChallenge($validated['wallet_address']),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'nonce' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
        ]);

        $wallet = strtolower($validated['wallet_address']);

        try {
            $message = $this->registrations->verifyOwnership($wallet, $validated['nonce'], $validated['signature']);
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        /*
         * ลายเซ็นผ่าน = คนนี้คุมกระเป๋าจริง — จุดเดียวที่ผูกกระเป๋าเข้าบัญชีได้ (เหมือน WalletController)
         * ยังไม่ล็อกอิน → เข้าสู่ระบบด้วยกระเป๋า · ล็อกอินอยู่ → ผูกกระเป๋าใบนี้เข้าบัญชี (ถ้ายังว่าง)
         */
        $identity = $this->identities->linkVerified($request->user('web'), $wallet, 4289, 'metamask', $request->ip());

        if ($identity['error']) {
            $messages = [
                WalletIdentityService::ERR_WALLET_TAKEN => __('กระเป๋าใบนี้ผูกกับบัญชีอื่นอยู่แล้ว — เข้าสู่ระบบด้วยบัญชีนั้น หรือติดต่อทีมงานเพื่อรวมบัญชี'),
                WalletIdentityService::ERR_ALREADY_LINKED => __('บัญชีนี้ผูกกระเป๋าใบอื่นไว้แล้ว — ถอดใบเดิมออกในหน้าโปรไฟล์ก่อน'),
                WalletIdentityService::ERR_BANNED => __('บัญชีนี้ถูกระงับการใช้งาน'),
            ];

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $identity['error'],
                    'message' => $messages[$identity['error']] ?? __('ผูกกระเป๋ากับบัญชีไม่สำเร็จ'),
                ],
            ], 409);
        }

        /** @var User $user */
        $user = $identity['user'];

        if (! $request->user('web') || $request->user('web')->id !== $user->id) {
            Auth::guard('web')->login($user, remember: true);
            $request->session()->regenerate();   // กัน session fixation ตอนสิทธิ์เปลี่ยนมือ
        }

        $registration = $this->registrations->start($user, $wallet, $message, $validated['signature'], $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'registration' => $this->payload($registration),
                'kyc' => ['approved' => $this->registrations->hasKyc($user)],
            ],
        ]);
    }

    public function saveForm(SaveCreatorRegistrationRequest $request): JsonResponse
    {
        $registration = $this->registrationFor($request->user('web'));
        if ($registration === null) {
            return $this->noRegistration();
        }

        try {
            $registration = $this->registrations->saveForm($registration, $request->formData());
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => ['registration' => $this->payload($registration)]]);
    }

    public function agreementChallenge(Request $request): JsonResponse
    {
        $registration = $this->registrationFor($request->user('web'));
        if ($registration === null) {
            return $this->noRegistration();
        }

        try {
            $challenge = $this->registrations->agreementChallenge($registration, $request->user('web'));
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => $challenge]);
    }

    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nonce' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
        ]);

        $registration = $this->registrationFor($request->user('web'));
        if ($registration === null) {
            return $this->noRegistration();
        }

        try {
            $registration = $this->registrations->submit(
                $registration,
                $request->user('web'),
                $validated['nonce'],
                $validated['signature'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => ['registration' => $this->payload($registration)]]);
    }

    public function pdf(Request $request, CreatorRegistrationPdf $pdf): Response
    {
        $registration = $this->registrationFor($request->user('web'));

        abort_if($registration === null || ! is_array($registration->form_data), 404);

        return $pdf->download($registration);
    }

    // =========================================================================
    // เอกสารประกอบ
    // =========================================================================

    public function uploadDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(Document::TYPES))],
            'file' => ['required', 'file', 'max:'.Document::MAX_KB, 'mimetypes:'.implode(',', array_keys(Document::MIMES))],
            'publish' => ['nullable', 'boolean'],
        ], [
            'file.mimetypes' => __('รับเฉพาะไฟล์ JPG, PNG, WebP หรือ PDF'),
            'file.max' => __('ไฟล์ใหญ่เกิน 10 MB'),
            'type.in' => __('ไม่รู้จักประเภทเอกสารนี้'),
        ], [
            'file' => __('ไฟล์เอกสาร'),
            'type' => __('ประเภทเอกสาร'),
        ]);

        $registration = $this->registrationFor($request->user('web'));
        if ($registration === null) {
            return $this->noRegistration();
        }

        try {
            $this->documents->upload($registration, $request->file('file'), $validated['type'], $request->boolean('publish'));
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => ['registration' => $this->payload($registration->refresh())]]);
    }

    public function updateDocument(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate(['publish' => ['required', 'boolean']]);

        $registration = $this->registrationFor($request->user('web'));
        $doc = $this->ownDocument($registration, $document);
        if ($doc === null) {
            return $this->noDocument();
        }

        try {
            $this->documents->setPublish($registration, $doc, (bool) $validated['publish']);
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => ['registration' => $this->payload($registration->refresh())]]);
    }

    public function deleteDocument(Request $request, string $document): JsonResponse
    {
        $registration = $this->registrationFor($request->user('web'));
        $doc = $this->ownDocument($registration, $document);
        if ($doc === null) {
            return $this->noDocument();
        }

        try {
            $this->documents->delete($registration, $doc);
        } catch (CreatorRegistrationException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return response()->json(['success' => true, 'data' => ['registration' => $this->payload($registration->refresh())]]);
    }

    public function showDocument(Request $request, string $document): Response
    {
        $doc = $this->ownDocument($this->registrationFor($request->user('web')), $document);
        abort_if($doc === null, 404);

        return $this->documents->originalResponse($doc);
    }

    private function ownDocument(?CreatorRegistration $registration, string $uuid): ?Document
    {
        if ($registration === null || ! preg_match('/^[0-9a-f\-]{36}$/', $uuid)) {
            return null;
        }

        return $registration->documents()->where('uuid', $uuid)->first();
    }

    private function noDocument(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'NO_DOCUMENT', 'message' => __('ไม่พบเอกสารนี้')],
        ], 404);
    }

    // =========================================================================

    private function registrationFor(?User $user): ?CreatorRegistration
    {
        if (! $user || blank($user->wallet_address)) {
            return null;
        }

        return CreatorRegistration::where('wallet_address', strtolower((string) $user->wallet_address))->first();
    }

    private function noRegistration(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'NO_REGISTRATION',
                'message' => __('ยังไม่ได้เริ่มลงทะเบียน — เซ็นยืนยันกระเป๋าก่อน'),
            ],
        ], 404);
    }

    /**
     * ข้อมูลของใบให้เจ้าของใบเห็น — รวมข้อมูลที่กรอก (ของเขาเอง) เพื่อแก้ไขต่อ.
     */
    private function payload(CreatorRegistration $registration): array
    {
        return [
            'uuid' => $registration->uuid,
            'status' => $registration->status,
            'wallet_address' => $registration->wallet_address,
            'form' => $registration->form_data,
            'form_hash' => $registration->form_hash,
            'documents' => $registration->documents()->get()->map(fn (Document $d) => [
                'uuid' => $d->uuid,
                'type' => $d->type,
                'mime' => $d->mime,
                'size' => $d->size,
                'sha256' => $d->sha256,
                'original_name' => $d->original_name,
                'publish' => $d->publish,
                'publishable' => Document::typeIsPublic($d->type) && $d->isImage(),
            ])->values()->all(),
            'documents_hash' => $registration->documents_hash,
            'missing_documents' => $this->registrations->missingDocuments($registration),
            'public_url' => $this->profiles->isPublic($registration) ? route('token-creator.projects.show', $registration->uuid) : null,
            'ownership_signed_at' => $registration->ownership_signed_at?->toIso8601String(),
            'signed' => $registration->isSigned(),
            'agreement_version' => $registration->agreement_version,
            'agreement_signed_at' => $registration->agreement_signed_at?->toIso8601String(),
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
            'starter_allocation_bps' => $registration->starter_allocation_bps,
            'reviewed_at' => $registration->reviewed_at?->toIso8601String(),
            'rejection_reason' => $registration->rejection_reason,
        ];
    }
}
