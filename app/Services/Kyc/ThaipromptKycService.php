<?php

namespace App\Services\Kyc;

use App\Models\KycSubmission;
use App\Models\ThaipromptKycLink;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TPIX TRADE — ยืนยันตัวตนด้วยผล KYC ของ Thaiprompt.
 *
 * เจ้าของสั่ง: "ให้ไปยืนยันใน thaiprompt app ถ้าผ่านก็บันทึกว่าผ่านแล้ว
 *              ถ้าเคยยืนยันแล้วก็ผ่านเลย ไม่ต้องยืนยันอีก"
 *
 * ทางเดิน (OAuth2 authorization code + PKCE ผ่าน Passport ของ Thaiprompt):
 *   1. ลูกค้ากด "ยืนยันด้วย Thaiprompt" → ไปหน้าอนุญาตของ Thaiprompt (ล็อกอินที่นั่น)
 *   2. กดอนุญาต → กลับมา callback พร้อม code → แลกเป็น token (ฝั่งเซิร์ฟเวอร์)
 *   3. ถาม GET /api/oauth/kyc → ผ่านแล้ว = ออกใบ KYC ที่อนุมัติแล้วให้ทันที (ไม่ต้องส่งเอกสาร)
 *                              → ยังไม่ผ่าน = เก็บ token ไว้ ให้ลูกค้าไปทำ eKYC ในแอป
 *                                แล้วถามซ้ำเป็นระยะ (หน้าเว็บ + คำสั่ง kyc:thaiprompt-sync)
 *
 * ด่านจริง (KycGate) ไม่ต้องรู้จัก Thaiprompt เลย — มันอ่าน "ใบที่อนุมัติ" อยู่แล้ว
 *
 * 🔒 PDPA: Thaiprompt ส่งมาแค่ผ่าน/ไม่ผ่าน + วันที่ + วิธี — ไม่มีเลขบัตร ชื่อ หรือรูป
 *    ใบที่ออกจากทางนี้จึงไม่มีข้อมูลส่วนบุคคลให้เก็บเลย
 *
 * ⚠️ หนึ่งบัญชี Thaiprompt ปลดล็อกได้บัญชีเดียว (unique thaiprompt_user_id)
 *    Thaiprompt กันบัตรใบเดียวเปิดหลายบัญชีอยู่แล้ว เราจึงได้ "หนึ่งคน หนึ่งบัญชี" ต่อมาด้วย
 *
 * Developed by Xman Studio.
 */
class ThaipromptKycService
{
    public const SESSION_KEY = 'thaiprompt_kyc_oauth';

    /** ลิงก์อนุญาตที่เริ่มไว้ใช้ได้นานเท่านี้ — กันคนเก็บ state เก่ามายิงซ้ำ */
    public const STATE_TTL_MINUTES = 15;

    /** ถามซ้ำได้ไม่ถี่กว่านี้ (กันหน้าเว็บหลายแท็บยิงรัวไปที่ Thaiprompt) */
    public const MIN_RECHECK_SECONDS = 10;

    public const ERR_NOT_CONFIGURED = 'ยังไม่ได้เปิดใช้การยืนยันตัวตนผ่าน Thaiprompt';

    public const ERR_STATE = 'ลิงก์ยืนยันหมดอายุหรือไม่ถูกต้อง กรุณากดปุ่มเชื่อมกับ Thaiprompt ใหม่อีกครั้ง';

    public const ERR_DENIED = 'คุณยังไม่ได้อนุญาตให้ Thaiprompt ส่งผลยืนยันตัวตนมาให้ TPIX TRADE';

    public const ERR_TAKEN = 'บัญชี Thaiprompt นี้ใช้ยืนยันตัวตนกับบัญชี TPIX TRADE อื่นไปแล้ว';

    public const ERR_UNAVAILABLE = 'ติดต่อ Thaiprompt ไม่ได้ชั่วคราว ลองใหม่อีกครั้งในอีกสักครู่';

    public const ERR_RECONNECT = 'สิทธิ์เชื่อมกับ Thaiprompt หมดอายุแล้ว กรุณากดเชื่อมใหม่อีกครั้ง';

    private const STATUSES = ['none', 'pending', 'approved', 'rejected'];

    public function __construct(
        private readonly KycService $kyc,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.thaiprompt.oauth_client_id'))
            && filled(config('services.thaiprompt.oauth_client_secret'));
    }

    /**
     * สิ่งที่หน้า /kyc ต้องใช้วาดการ์ด "ยืนยันด้วย Thaiprompt".
     */
    public function ownerPayload(User $user): array
    {
        return [
            'available' => $this->isConfigured(),
            'link' => $user->thaipromptKycLink?->toOwnerArray(),
            'app_link' => (string) config('services.thaiprompt.app_ekyc_link'),
            'download_url' => (string) config('services.thaiprompt.app_download_url'),
        ];
    }

    // =========================================================================
    // เริ่มเชื่อม → หน้าอนุญาตของ Thaiprompt
    // =========================================================================

    /**
     * สร้าง state + PKCE แล้วคืน URL หน้าอนุญาตของ Thaiprompt.
     *
     * state ผูกกับ "บัญชีที่กด" ไว้ใน session — callback ที่กลับมาในบัญชีอื่น (ล็อกอินสลับระหว่างทาง)
     * จะถูกปฏิเสธ ไม่ใช่เอาผล KYC ของคนหนึ่งไปติดอีกบัญชี
     *
     * @throws RuntimeException
     */
    public function beginAuthorization(User $user, Session $session, ?string $ip = null): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(self::ERR_NOT_CONFIGURED);
        }

        $state = Str::random(40);
        $verifier = Str::random(96);

        $session->put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'user_id' => $user->id,
            'ip' => $ip,
            'consented_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(self::STATE_TTL_MINUTES)->getTimestamp(),
        ]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $this->baseUrl().'/oauth/authorize?'.http_build_query([
            'client_id' => (string) config('services.thaiprompt.oauth_client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            // ขอแค่ผล KYC — ไม่ขออีเมล/ชื่อ (เก็บเท่าที่จำเป็น)
            'scope' => 'kyc',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * กลับมาจากหน้าอนุญาต → แลก token → ถามสถานะ → ผูกบัญชี.
     *
     * @param  array<string, mixed>  $query  query string ของ callback
     *
     * @throws RuntimeException ข้อความพร้อมแสดงผู้ใช้
     */
    public function completeAuthorization(User $user, Session $session, array $query): ThaipromptKycLink
    {
        // ใช้ครั้งเดียว — ดึงออกจาก session ก่อนทำอะไรทั้งนั้น กดย้อนกลับมายิงซ้ำไม่ได้
        $pending = $session->pull(self::SESSION_KEY);

        if (($query['error'] ?? null) === 'access_denied') {
            throw new RuntimeException(self::ERR_DENIED);
        }

        $state = $query['state'] ?? null;
        $code = $query['code'] ?? null;

        if (! is_array($pending)
            || ! is_string($state) || ! is_string($code) || $code === ''
            || ! hash_equals((string) ($pending['state'] ?? ''), $state)
            || (int) ($pending['user_id'] ?? 0) !== (int) $user->id
            || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()
        ) {
            throw new RuntimeException(self::ERR_STATE);
        }

        $tokens = $this->exchangeCode($code, (string) $pending['verifier']);

        try {
            $status = $this->fetchStatus($tokens['access_token']);
        } catch (ThaipromptUnauthorized) {
            // token เพิ่งได้มาแต่อ่านผลไม่ได้ = ไม่ได้รับสิทธิ์ kyc (กดอนุญาตไม่ครบ) — ให้เริ่มใหม่
            throw new RuntimeException(self::ERR_STATE);
        }

        $link = $this->bind($user, $status['sub'], $tokens);

        return $this->apply($link, $status, $pending['ip'] ?? null);
    }

    // =========================================================================
    // ถามสถานะซ้ำ (ลูกค้ายังทำ eKYC ในแอปไม่เสร็จ / ตรวจทวนเป็นระยะ)
    // =========================================================================

    /**
     * @param  bool  $force  ข้ามการกันถามถี่ (ใช้จากคำสั่งตั้งเวลา)
     *
     * @throws RuntimeException
     */
    public function refresh(ThaipromptKycLink $link, bool $force = false): ThaipromptKycLink
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(self::ERR_NOT_CONFIGURED);
        }

        if ($link->needs_reconnect) {
            throw new RuntimeException(self::ERR_RECONNECT);
        }

        if (! $force && $link->last_checked_at?->gt(now()->subSeconds(self::MIN_RECHECK_SECONDS))) {
            return $link;
        }

        try {
            // token จะหมดใน 1 นาที → ต่ออายุก่อนเลย ไม่ต้องรอโดน 401
            if ($link->token_expires_at === null || $link->token_expires_at->lt(now()->addMinute())) {
                $this->renewTokens($link);
            }

            try {
                $status = $this->fetchStatus((string) $link->access_token);
            } catch (ThaipromptUnauthorized) {
                // token ถูกเพิกถอน/หมดอายุก่อนกำหนด — ต่ออายุหนึ่งครั้งแล้วถามใหม่
                $this->renewTokens($link);
                $status = $this->fetchStatus((string) $link->access_token);
            }
        } catch (ThaipromptUnauthorized) {
            $this->markReconnect($link, 'unauthorized');

            throw new RuntimeException(self::ERR_RECONNECT);
        }

        if ($status['sub'] !== $link->thaiprompt_user_id) {
            // ไม่ควรเกิด — token ของบัญชีหนึ่งต้องตอบเป็นบัญชีเดิมเสมอ ถ้าเกิดคือมีอะไรผิดปกติ ห้ามเชื่อ
            Log::warning('Thaiprompt KYC: sub mismatch on refresh', ['link_id' => $link->id]);
            $this->markReconnect($link, 'sub_mismatch');

            throw new RuntimeException(self::ERR_RECONNECT);
        }

        return $this->apply($link, $status);
    }

    // =========================================================================
    // ภายใน
    // =========================================================================

    /**
     * ผูกบัญชีเรากับบัญชี Thaiprompt.
     *
     * @param  array{access_token: string, refresh_token: ?string, expires_at: Carbon}  $tokens
     *
     * @throws RuntimeException
     */
    private function bind(User $user, string $sub, array $tokens): ThaipromptKycLink
    {
        $taken = ThaipromptKycLink::query()
            ->where('thaiprompt_user_id', $sub)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($taken) {
            throw new RuntimeException(self::ERR_TAKEN);
        }

        try {
            return DB::transaction(function () use ($user, $sub, $tokens) {
                $link = ThaipromptKycLink::query()->where('user_id', $user->id)->lockForUpdate()->first();

                // เปลี่ยนไปผูกบัญชี Thaiprompt อื่น → ผลที่ได้จากบัญชีเก่าใช้ไม่ได้แล้ว
                if ($link && $link->thaiprompt_user_id !== $sub) {
                    $this->revokeDerived($user, $link->thaiprompt_user_id, 'เปลี่ยนไปผูกบัญชี Thaiprompt อื่น');
                }

                $link ??= new ThaipromptKycLink(['user_id' => $user->id]);

                $link->fill([
                    'thaiprompt_user_id' => $sub,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_expires_at' => $tokens['expires_at'],
                    'linked_at' => now(),
                    'needs_reconnect' => false,
                    'last_error' => null,
                ])->save();

                return $link;
            });
        } catch (QueryException $e) {
            // สองบัญชีกดผูกบัญชี Thaiprompt เดียวกันพร้อมกัน — unique index ตัดสินให้
            if (str_contains(strtolower($e->getMessage()), 'unique')
                || str_contains($e->getMessage(), '1062')
                || str_contains($e->getMessage(), '23000')) {
                throw new RuntimeException(self::ERR_TAKEN);
            }

            throw $e;
        }
    }

    /**
     * ลงผลที่ Thaiprompt ตอบ → ออก/ถอนใบ KYC ฝั่งเรา.
     *
     * @param  array{sub: string, kyc_status: string, verified: bool, verified_at: ?Carbon, method: ?string}  $status
     */
    private function apply(ThaipromptKycLink $link, array $status, ?string $consentIp = null): ThaipromptKycLink
    {
        return DB::transaction(function () use ($link, $status, $consentIp) {
            $link->fill([
                'kyc_status' => $status['verified'] ? ThaipromptKycLink::STATUS_APPROVED : $status['kyc_status'],
                'verified_at' => $status['verified'] ? ($status['verified_at'] ?? now()) : null,
                'method' => $status['verified'] ? $status['method'] : null,
                'last_checked_at' => now(),
                'last_error' => null,
            ])->save();

            $user = $link->user;

            if ($status['verified']) {
                $this->grantDerived($user, $link, $consentIp);
            } else {
                // เคยผ่านแล้ว Thaiprompt ยกเลิก (ตรวจเจอปลอม/แอดมินรีเซ็ต) → สิทธิ์ฝั่งเราต้องหายตาม
                $this->revokeDerived($user, $link->thaiprompt_user_id, 'Thaiprompt แจ้งว่ายังไม่ผ่านการยืนยันตัวตน');
            }

            return $link->fresh();
        });
    }

    /** ออกใบ "อนุมัติแล้ว" จากผลของ Thaiprompt — ทำครั้งเดียวต่อบัญชี Thaiprompt (ไม่ซ้ำ) */
    private function grantDerived(User $user, ThaipromptKycLink $link, ?string $consentIp): void
    {
        $exists = KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('source', KycSubmission::SOURCE_THAIPROMPT)
            ->where('external_ref', $link->thaiprompt_user_id)
            ->where('status', KycSubmission::STATUS_APPROVED)
            ->whereNull('purged_at')
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            return;
        }

        // ใบที่ส่งเอกสารค้างรอตรวจอยู่ไม่ต้องตรวจแล้ว — ทีมงานจะได้ไม่เสียเวลากับใบที่ไม่มีผลอะไร
        KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('status', KycSubmission::STATUS_PENDING)
            ->update(['status' => KycSubmission::STATUS_CANCELLED]);

        $verifiedAt = $link->verified_at ?? now();

        KycSubmission::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            // eKYC ของ Thaiprompt = บัตรประชาชน + ใบหน้าตรงกับบัตร ≈ ระดับปกติของเรา
            // ระดับเพิ่มเติม (หลักฐานที่อยู่) Thaiprompt ไม่ได้ตรวจ — ต้องส่งเอกสารกับเราเหมือนเดิม
            'level' => KycSubmission::LEVEL_BASIC,
            'source' => KycSubmission::SOURCE_THAIPROMPT,
            'external_ref' => $link->thaiprompt_user_id,
            'status' => KycSubmission::STATUS_APPROVED,
            'id_type' => 'national_id',
            'consent_version' => 'thaiprompt-'.$this->kyc->consentVersion(),
            'consented_at' => $link->linked_at ?? now(),
            'consent_ip' => $consentIp,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'review_note' => sprintf(
                'ยืนยันตัวตนผ่าน Thaiprompt (%s) เมื่อ %s',
                $link->method === 'ekyc' ? 'eKYC ในแอป' : 'ทีมงาน Thaiprompt ตรวจ',
                $verifiedAt->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            ),
            'purge_after' => now()->addDays($this->kyc->retentionDays()),
        ]);

        $this->kyc->syncUserStatus($user);
    }

    /** ถอนใบที่ได้จาก Thaiprompt (บัญชีนี้) — ใบที่ส่งเอกสารกับเราเองไม่แตะ */
    private function revokeDerived(User $user, string $sub, string $reason): void
    {
        $affected = KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('source', KycSubmission::SOURCE_THAIPROMPT)
            ->where('external_ref', $sub)
            ->where('status', KycSubmission::STATUS_APPROVED)
            ->update([
                'status' => KycSubmission::STATUS_EXPIRED,
                'review_note' => $reason,
            ]);

        if ($affected > 0) {
            Log::info('Thaiprompt KYC: derived approval revoked', ['user_id' => $user->id, 'reason' => $reason]);
            $this->kyc->syncUserStatus($user);
        }
    }

    private function markReconnect(ThaipromptKycLink $link, string $error): void
    {
        $link->forceFill([
            'needs_reconnect' => true,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'last_error' => $error,
            'last_checked_at' => now(),
        ])->save();
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: Carbon}
     *
     * @throws RuntimeException
     */
    private function exchangeCode(string $code, string $verifier): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            // code ใช้ไปแล้ว/หมดอายุ/redirect ไม่ตรง — ลูกค้าแก้ได้ด้วยการกดเชื่อมใหม่
            Log::warning('Thaiprompt KYC: code exchange failed', ['status' => $response->status(), 'error' => $response->json('error')]);

            throw new RuntimeException($response->serverError() ? self::ERR_UNAVAILABLE : self::ERR_STATE);
        }

        return $this->tokensFrom($response);
    }

    /**
     * @throws ThaipromptUnauthorized refresh token ใช้ไม่ได้แล้ว
     * @throws RuntimeException
     */
    private function renewTokens(ThaipromptKycLink $link): void
    {
        if (! $link->refresh_token) {
            throw new ThaipromptUnauthorized();
        }

        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $link->refresh_token,
            'scope' => 'kyc',
        ]);

        if (in_array($response->status(), [400, 401], true)) {
            throw new ThaipromptUnauthorized();
        }

        if (! $response->successful()) {
            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }

        $tokens = $this->tokensFrom($response);

        // Passport หมุน refresh token ทุกครั้ง — ต้องเก็บตัวใหม่ทันที ตัวเก่าใช้ไม่ได้แล้ว
        $link->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $link->refresh_token,
            'token_expires_at' => $tokens['expires_at'],
        ])->save();
    }

    /**
     * @return array{sub: string, kyc_status: string, verified: bool, verified_at: ?Carbon, method: ?string}
     *
     * @throws ThaipromptUnauthorized
     * @throws RuntimeException
     */
    private function fetchStatus(string $accessToken): array
    {
        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withToken($accessToken)
                ->get($this->baseUrl().'/api/oauth/kyc');
        } catch (ConnectionException) {
            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new ThaipromptUnauthorized();
        }

        if (! $response->successful()) {
            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }

        $sub = $response->json('sub');
        $status = $response->json('kyc_status');

        // ไม่เชื่อคำตอบที่ผิดรูป — ห้ามตีความ "อะไรก็ไม่รู้" ว่าผ่าน
        if (! is_scalar($sub) || (string) $sub === '' || ! in_array($status, self::STATUSES, true)) {
            Log::warning('Thaiprompt KYC: malformed status payload');

            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }

        $verified = $response->json('verified') === true && $status === 'approved';

        $verifiedAt = null;
        if ($verified && is_string($response->json('verified_at'))) {
            try {
                $verifiedAt = Carbon::parse($response->json('verified_at'));
            } catch (\Throwable) {
                $verifiedAt = null;
            }
        }

        $method = $response->json('method');

        return [
            'sub' => (string) $sub,
            'kyc_status' => $status,
            'verified' => $verified,
            'verified_at' => $verifiedAt,
            'method' => in_array($method, ['ekyc', 'manual'], true) ? $method : null,
        ];
    }

    /** @throws RuntimeException */
    private function tokenRequest(array $params): Response
    {
        try {
            return Http::timeout($this->timeout())
                ->acceptJson()
                ->asForm()
                ->post($this->baseUrl().'/oauth/token', $params + [
                    'client_id' => (string) config('services.thaiprompt.oauth_client_id'),
                    'client_secret' => (string) config('services.thaiprompt.oauth_client_secret'),
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: Carbon}
     *
     * @throws RuntimeException
     */
    private function tokensFrom(Response $response): array
    {
        $access = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }

        $refresh = $response->json('refresh_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 0);

        return [
            'access_token' => $access,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            // เผื่อเวลาคลาดเคลื่อน 5 นาที — ต่ออายุก่อนหมดจริง
            'expires_at' => now()->addSeconds(max(60, $expiresIn - 300)),
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.thaiprompt.base_url'), '/');
    }

    /**
     * ต้องตรงกับที่ลงทะเบียนไว้ที่ Thaiprompt ทุกตัวอักษร (Passport เทียบแบบ byte-for-byte).
     */
    public function redirectUri(): string
    {
        return (string) (config('services.thaiprompt.oauth_redirect') ?: route('kyc.thaiprompt.callback'));
    }

    private function timeout(): int
    {
        return max(3, (int) config('services.thaiprompt.timeout', 10));
    }
}
