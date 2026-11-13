<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBotConfig;
use App\Models\AiCopyFee;
use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\AiCopyReview;
use App\Services\AiBot\Copy\CopyTradeService;
use App\Services\AiBot\Copy\MediaService;
use App\Services\AiBot\Copy\RankService;
use App\Services\AiBot\Copy\ReviewService;
use App\Services\AiBot\Live\LiveBroker;
use App\Services\AiBotService;
use App\Services\Kyc\KycGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — Master / Copy Trade API.
 *
 *   GET  /api/v1/ai-bot/copy/masters              กระดานมาสเตอร์ (สาธารณะ — ดูผลงานได้ก่อนเชื่อมกระเป๋า)
 *   GET  /api/v1/ai-bot/copy/me                   ของฉัน: โปรไฟล์มาสเตอร์ · คำชวน · คนที่ฉันตาม · ค่าบริการ
 *   POST /api/v1/ai-bot/copy/master               เปิดตัวเป็นมาสเตอร์
 *   PUT  /api/v1/ai-bot/copy/master               แก้ชื่อ/อัตรา/พักรับผู้ตาม
 *   POST /api/v1/ai-bot/copy/master/close         ปิดโปรไฟล์
 *   POST /api/v1/ai-bot/copy/follow               เริ่มก๊อปปี้มาสเตอร์
 *   POST /api/v1/ai-bot/copy/follow/{id}/stop     เลิกตาม
 *   POST /api/v1/ai-bot/copy/follow/{id}/accept   ยอมรับอัตราใหม่ของมาสเตอร์
 *
 * ทุกด่านอยู่ที่เซิร์ฟเวอร์ (แอพมือถือกับคนที่ยิง API ตรงไม่เห็นหน้าเว็บ) และไม่มี endpoint ไหน
 * ส่งกฎหรือพารามิเตอร์ของบอทมาสเตอร์ออกไป — ผู้ตามเห็นผลงาน ไม่เห็นกฎ
 *
 * Developed by Xman Studio.
 */
class AiCopyTradeController extends Controller
{
    private const ERROR_MESSAGES = [
        CopyTradeService::ERR_DISABLED => 'Copy Trade ยังไม่เปิดใช้งาน',
        CopyTradeService::ERR_NEEDS_LIVE => 'Copy Trade ใช้ได้เมื่อเปิดเทรดเงินจริงแล้วเท่านั้น — ผลงานและการก๊อปปี้นับเฉพาะเงินจริง',
        CopyTradeService::ERR_NOT_ELIGIBLE => 'บอทตัวนี้ยังไม่ผ่านเกณฑ์เปิดตัวเป็นมาสเตอร์ (นับเฉพาะไม้เงินจริงที่ปิดแล้ว)',
        CopyTradeService::ERR_KYC_REQUIRED => 'ต้องยืนยันตัวตน (KYC) ให้ผ่านก่อนเปิดตัวเป็นมาสเตอร์',
        CopyTradeService::ERR_ALREADY_MASTER => 'กระเป๋านี้เป็นมาสเตอร์อยู่แล้ว',
        CopyTradeService::ERR_MASTER_NOT_FOUND => 'ไม่พบมาสเตอร์คนนี้',
        CopyTradeService::ERR_MASTER_CLOSED => 'มาสเตอร์คนนี้ไม่ได้เปิดรับผู้ตามใหม่ในตอนนี้',
        CopyTradeService::ERR_SELF => 'ก๊อปปี้ตัวเองไม่ได้',
        CopyTradeService::ERR_ALREADY_FOLLOWING => 'คุณตามมาสเตอร์คนนี้อยู่แล้ว',
        CopyTradeService::ERR_PAIR_UNSUPPORTED => 'คู่เหรียญของมาสเตอร์คนนี้ยังเทรดเงินจริงไม่ได้',
        CopyTradeService::ERR_FOLLOW_NOT_FOUND => 'ไม่พบการติดตามนี้',
        ReviewService::ERR_NOT_ELIGIBLE => 'รีวิวได้เมื่อคุณก๊อปปี้มาสเตอร์คนนี้ด้วยเงินจริงครบระยะขั้นต่ำ และปิดไม้ที่ก๊อปปี้ไปแล้วอย่างน้อยหนึ่งไม้',
        ReviewService::ERR_NOT_FOUND => 'ไม่พบรีวิวนี้',
        ReviewService::ERR_OWN_REVIEW => 'รายงานรีวิวของตัวเองไม่ได้',
        MediaService::ERR_LOCKED => 'ยังแปะไลฟ์/คลิปไม่ได้ — ปลดล็อกเมื่อระดับและยอดที่ทำให้แพลตฟอร์มถึงเกณฑ์',
        MediaService::ERR_INVALID_LINK => 'ลิงก์นี้ใช้ไม่ได้ — รับเฉพาะลิงก์วิดีโอแบบเต็ม (https) ของ YouTube, Facebook หรือ TikTok',
        MediaService::ERR_LIMIT => 'แปะลิงก์ครบจำนวนสูงสุดแล้ว — ลบรายการเดิมก่อน',
        MediaService::ERR_DUPLICATE => 'ลิงก์นี้แปะไว้แล้ว',
        MediaService::ERR_NOT_FOUND => 'ไม่พบลิงก์นี้',
        AiBotService::ERR_NO_SUBSCRIPTION => 'ยังไม่มีการเช่าที่ใช้งานอยู่ กรุณาเช่าบอทก่อน',
        AiBotService::ERR_BOT_LIMIT => 'จำนวนบอทเต็มโควตาของแพลนนี้แล้ว',
        AiBotService::ERR_STRATEGY_LOCKED => 'กลยุทธ์นี้ต้องใช้แพลนระดับสูงกว่า',
    ];

    public function __construct(
        private readonly CopyTradeService $copy,
        private readonly AiBotService $bots,
        private readonly LiveBroker $live,
        private readonly KycGate $kyc,
        private readonly RankService $ranks,
        private readonly ReviewService $reviews,
        private readonly MediaService $media,
    ) {}

    /** กระดานมาสเตอร์ — สาธารณะ: มีแต่ผลงานเงินจริงที่ปิดแล้ว ไม่มีกฎ ไม่มีที่อยู่กระเป๋าเต็ม */
    public function masters(): JsonResponse
    {
        if (! $this->copy->enabled()) {
            return response()->json(['success' => true, 'data' => $this->settings() + ['masters' => [], 'promoted' => []]]);
        }

        $board = $this->copy->leaderboard();

        return response()->json([
            'success' => true,
            'data' => $this->settings() + [
                'masters' => array_map(fn (AiCopyMaster $master) => $this->presentMaster($master, in_array($master->id, $board['promoted'], true)), $board['masters']),
                'promoted' => $board['promoted'],
            ],
        ]);
    }

    /** ของกระเป๋านี้ — โปรไฟล์มาสเตอร์ · บอทที่ผ่านเกณฑ์ (คำชวนเปิดตัว) · มาสเตอร์ที่ตามอยู่ · ค่าบริการ */
    public function me(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);

        if (! $this->copy->enabled()) {
            return response()->json(['success' => true, 'data' => $this->settings() + ['master' => null, 'invite' => false, 'candidates' => [], 'follows' => [], 'kyc_approved' => false]]);
        }

        $master = $this->copy->masterOf($wallet);
        $candidates = $this->copy->candidates($wallet);
        $follows = AiCopyFollow::where('follower_wallet', $wallet)->with('master', 'bot')->orderByDesc('id')->get();

        // ลงบัญชีไม้ที่เพิ่งปิดก่อนตอบ — ตัวเลขค่าบริการที่ผู้ตามเห็นต้องเป็นของล่าสุด
        $follows->each(function (AiCopyFollow $follow) {
            try {
                $this->copy->settleFees($follow);
            } catch (\Throwable $e) {
                Log::warning('copy trade: ลงบัญชีค่าบริการตอนอ่านสถานะไม่สำเร็จ', ['follow' => $follow->id, 'error' => $e->getMessage()]);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $this->settings() + [
                'master' => $master ? $this->presentMaster($master->isOpen() ? $this->copy->refreshStats($master) : $master, false, own: true) : null,
                /*
                 * คำชวนเปิดตัวเป็นมาสเตอร์ — "ถ้าเขาทำกำไรได้เยอะ ระบบจะแจ้งให้เขาสร้างตัวเป็น master"
                 * ขึ้นเมื่อมีบอทที่ผ่านเกณฑ์จากผลเงินจริง และยังไม่ได้เป็นมาสเตอร์อยู่
                 */
                'invite' => (! $master || ! $master->isOpen()) && collect($candidates)->contains(fn (array $c) => $c['eligible']),
                'candidates' => $candidates,
                'kyc_approved' => $this->kycApproved($request),
                'follows' => $follows->map(fn (AiCopyFollow $follow) => $this->presentFollow($follow->fresh(['master', 'bot'])) + $this->reviewState($wallet, $follow))->all(),
            ],
        ]);
    }

    public function registerMaster(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);
        $validated = $request->validate(['bot_id' => ['required', 'integer']] + $this->profileRules(required: true));

        $bot = AiBotConfig::forWallet($wallet)->where('id', $validated['bot_id'])->first();

        if (! $bot) {
            return $this->failure('BOT_NOT_FOUND', __('ไม่พบบอทตัวนี้'), 404);
        }

        try {
            $master = $this->copy->register($wallet, $bot, $validated, $this->kycApproved($request));
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        return response()->json(['success' => true, 'data' => $this->presentMaster($master, false, own: true)], 201);
    }

    public function updateMaster(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);
        $master = $this->copy->masterOf($wallet);

        if (! $master || ! $master->isOpen()) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        $validated = $request->validate($this->profileRules(required: false) + [
            'status' => ['sometimes', 'string', 'in:'.AiCopyMaster::STATUS_ACTIVE.','.AiCopyMaster::STATUS_PAUSED],
        ]);

        try {
            $master = $this->copy->updateMaster($master, $validated);
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        return response()->json(['success' => true, 'data' => $this->presentMaster($master, false, own: true)]);
    }

    public function closeMaster(Request $request): JsonResponse
    {
        $master = $this->copy->masterOf($this->wallet($request));

        if (! $master || ! $master->isOpen()) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        return response()->json(['success' => true, 'data' => $this->presentMaster($this->copy->closeMaster($master), false, own: true)]);
    }

    public function follow(Request $request): JsonResponse
    {
        $wallet = $this->wallet($request);

        $validated = $request->validate([
            'master_id' => ['required', 'integer'],
            'risk' => ['sometimes', 'array'],
            // ผู้ตามต้องเห็นและยอมรับอัตราตอนกดตาม — อัตราที่ส่งมาไม่ตรงกับของมาสเตอร์ตอนนี้ = ยังไม่ได้ยอมรับ
            'accept_fee_rate_pct' => ['required', 'numeric'],
        ]);

        $master = AiCopyMaster::find($validated['master_id']);

        if (! $master) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        if (abs((float) $validated['accept_fee_rate_pct'] - $master->fee_rate_pct) > 0.0001) {
            return $this->failure('COPY_RATE_CHANGED', __('มาสเตอร์เปลี่ยนอัตราค่าบริการเป็น :rate% แล้ว — ตรวจอัตราใหม่ก่อนกดตาม', ['rate' => $master->fee_rate_pct]));
        }

        try {
            $follow = $this->copy->follow($wallet, $master, (array) ($validated['risk'] ?? []));
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        return response()->json(['success' => true, 'data' => $this->presentFollow($follow->fresh(['master', 'bot']))], 201);
    }

    public function stopFollow(Request $request, int $id): JsonResponse
    {
        $follow = $this->findFollow($this->wallet($request), $id);

        if (! $follow) {
            return $this->failure(CopyTradeService::ERR_FOLLOW_NOT_FOUND, null, 404);
        }

        // เลิกตามไม่มีด่านใดทั้งสิ้น (ธงปิด · แพลนหมด · KYC) — ผู้ตามต้องออกได้เสมอ
        return response()->json(['success' => true, 'data' => $this->presentFollow($this->copy->stopFollow($follow)->fresh(['master', 'bot']))]);
    }

    public function acceptRate(Request $request, int $id): JsonResponse
    {
        $follow = $this->findFollow($this->wallet($request), $id);

        if (! $follow || ! $follow->isActive() || ! $follow->master?->isOpen()) {
            return $this->failure(CopyTradeService::ERR_FOLLOW_NOT_FOUND, null, 404);
        }

        return response()->json(['success' => true, 'data' => $this->presentFollow($this->copy->acceptCurrentRate($follow)->fresh(['master', 'bot']))]);
    }

    // =========================================================================
    // ไลฟ์ / คลิป
    // =========================================================================

    /** ไลฟ์/คลิปของมาสเตอร์คนหนึ่ง — สาธารณะ: โชว์เฉพาะตอนที่มาสเตอร์ยังมีสิทธิ์ และเฉพาะรายการที่ไม่ถูกซ่อน */
    public function mediaOf(int $id): JsonResponse
    {
        $master = $this->copy->enabled() ? AiCopyMaster::open()->find($id) : null;

        if (! $master) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        return response()->json(['success' => true, 'data' => [
            'embed' => (bool) config('aibot.copy_trade.media.embed', false),
            'items' => $this->media->items($master),
        ]]);
    }

    public function addMedia(Request $request): JsonResponse
    {
        $master = $this->copy->enabled() ? $this->copy->masterOf($this->wallet($request)) : null;

        if (! $master || ! $master->isOpen()) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:live,clip'],
            // ตรวจแค่ว่าเป็นข้อความสั้น — การตัดสินว่า "เป็นลิงก์ที่รับ" อยู่ที่ MediaLink ที่เดียว
            'url' => ['required', 'string', 'max:300'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $this->media->add($master, $validated['kind'], $validated['url'], $validated['title'] ?? null);
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        return response()->json(['success' => true, 'data' => $this->mediaState($master)], 201);
    }

    public function removeMedia(Request $request, int $id): JsonResponse
    {
        $master = $this->copy->masterOf($this->wallet($request));

        if (! $master) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        try {
            // ลบของตัวเองได้เสมอ แม้ถูกถอนสิทธิ์หรือระบบปิดอยู่ — เอาของออกต้องไม่มีด่าน
            $this->media->remove($master, $id);
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        return response()->json(['success' => true, 'data' => $this->mediaState($master)]);
    }

    /** สิทธิ์แปะลิงก์ + รายการทั้งหมดของเจ้าของ (รวมที่ถูกซ่อน) */
    private function mediaState(AiCopyMaster $master): array
    {
        return $this->media->access($master) + [
            'max_items' => (int) config('aibot.copy_trade.media.max_items', 10),
            'items' => $this->media->items($master, forOwner: true),
        ];
    }

    // =========================================================================
    // ดาวรีวิว
    // =========================================================================

    /** รีวิวของมาสเตอร์คนหนึ่ง — สาธารณะ: ดาว ข้อความ (ข้อความล้วน) และกระเป๋าแบบย่อ */
    public function reviewsOf(int $id): JsonResponse
    {
        $master = $this->copy->enabled() ? AiCopyMaster::open()->find($id) : null;

        if (! $master) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        $rows = AiCopyReview::where('ai_copy_master_id', $master->id)->visible()->orderByDesc('updated_at')->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'rating' => ['avg' => $master->rating_avg, 'count' => $master->rating_count],
                'reviews' => $rows->map(fn (AiCopyReview $review) => $this->presentReview($review))->all(),
            ],
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $wallet = $this->wallet($request);

        $validated = $request->validate([
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('aibot.copy_trade.reviews.max_comment_length', 500)],
        ]);

        $master = $this->copy->enabled() ? AiCopyMaster::find($id) : null;

        if (! $master) {
            return $this->failure(CopyTradeService::ERR_MASTER_NOT_FOUND, null, 404);
        }

        try {
            $review = $this->reviews->submit($wallet, $master, (int) $validated['stars'], $validated['comment'] ?? null);
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        return response()->json(['success' => true, 'data' => $this->presentReview($review)]);
    }

    public function reportReview(Request $request, int $id): JsonResponse
    {
        $wallet = $this->wallet($request);
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:200']]);

        $review = AiCopyReview::visible()->find($id);

        if (! $review) {
            return $this->failure(ReviewService::ERR_NOT_FOUND, null, 404);
        }

        try {
            $this->reviews->report($wallet, $review, $validated['reason'] ?? null);
        } catch (RuntimeException $e) {
            return $this->serviceFailure($e);
        }

        // ไม่บอกจำนวนรายงาน — คนรายงานไม่ควรรู้ว่าต้องรุมอีกกี่คน
        return response()->json(['success' => true, 'data' => ['reported' => true]]);
    }

    /** สิทธิ์รีวิวและรีวิวที่เคยให้ไว้ของการติดตามนี้ */
    private function reviewState(string $wallet, AiCopyFollow $follow): array
    {
        $master = $follow->master;
        $mine = $master ? $this->reviews->reviewBy($wallet, $master) : null;

        return [
            'can_review' => $master !== null && $this->reviews->canReview($wallet, $master),
            'my_review' => $mine ? ['stars' => $mine->stars, 'comment' => $mine->comment] : null,
        ];
    }

    private function presentReview(AiCopyReview $review): array
    {
        return [
            'id' => $review->id,
            'stars' => $review->stars,
            'comment' => $review->comment,
            'reviewer' => substr($review->reviewer_wallet, 0, 6).'…'.substr($review->reviewer_wallet, -4),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** กติกาที่หน้าจอต้องใช้วาด (ช่วงอัตรา · เกณฑ์ · ธง) — ส่งจากเซิร์ฟเวอร์ที่เดียว ไม่ให้หน้าเว็บ/แอพฮาร์ดโค้ด */
    private function settings(): array
    {
        return [
            'enabled' => $this->copy->enabled(),
            // ก๊อปปี้ได้จริงตอนนี้ไหม — ยังไม่เปิดเทรดเงินจริง = ดูกระดานได้ กดตามไม่ได้
            'live_ready' => $this->live->operational(),
            'fees' => [
                'collect' => (bool) config('aibot.copy_trade.fees.collect', false),
                'currency' => (string) config('aibot.copy_trade.fees.currency', 'TPIX'),
                'rate_pct' => config('aibot.copy_trade.fees.rate_pct'),
                'platform_share_pct' => config('aibot.copy_trade.fees.platform_share_pct'),
            ],
            'requirements' => array_intersect_key((array) config('aibot.copy_trade.master', []), array_flip([
                'requires_kyc', 'min_days', 'min_closed_trades', 'min_return_pct', 'max_drawdown_pct', 'max_name_length', 'max_bio_length',
            ])),
        ];
    }

    private function profileRules(bool $required): array
    {
        $rate = (array) config('aibot.copy_trade.fees.rate_pct');
        $share = (array) config('aibot.copy_trade.fees.platform_share_pct');
        $presence = $required ? 'required' : 'sometimes';

        return [
            // ชื่อที่โชว์สาธารณะ — ตัวอักษร ตัวเลข เว้นวรรค และ . _ - เท่านั้น (ไม่มีแท็ก ไม่มีลิงก์)
            'display_name' => [$presence, 'string', 'min:3', 'max:'.(int) config('aibot.copy_trade.master.max_name_length', 40), 'regex:/^[\p{L}\p{M}\p{N} ._\-]+$/u'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('aibot.copy_trade.master.max_bio_length', 280)],
            'fee_rate_pct' => [$presence, 'numeric', 'min:'.$rate['min'], 'max:'.$rate['max']],
            'platform_share_pct' => [$presence, 'numeric', 'min:'.$share['min'], 'max:'.$share['max']],
        ];
    }

    /**
     * KYC ของคนที่ถืออยู่จริง — ถาม KycGate ตัวเดียวกับด่านฟังก์ชันขั้นสูงอื่น ไม่มีระบบ KYC ชุดที่สอง.
     *
     * ต่างจากด่าน kyc:<feature> ตรงที่ไม่ขึ้นกับสวิตช์รวมของระบบ: มาสเตอร์รับเงินจากคนอื่น
     * ต้องมีใบที่อนุมัติแล้วเสมอ แม้ตอนนั้นระบบจะยังไม่ได้เปิดบังคับ KYC กับฟีเจอร์อื่น
     */
    private function kycApproved(Request $request): bool
    {
        $user = $this->kyc->resolveUser($request);

        return $user !== null && $this->kyc->satisfiesLevel($user, (string) config('aibot.copy_trade.master.kyc_level', 'basic'));
    }

    private function findFollow(string $wallet, int $id): ?AiCopyFollow
    {
        return AiCopyFollow::where('follower_wallet', $wallet)->where('id', $id)->with('master', 'bot')->first();
    }

    /**
     * @param  bool  $own  เจ้าของโปรไฟล์ดูเอง — เห็นบอทที่ผูกไว้และรายได้ค่าบริการ
     */
    private function presentMaster(AiCopyMaster $master, bool $promoted, bool $own = false): array
    {
        $stats = $master->stats ?? [];

        $data = [
            'id' => $master->id,
            'display_name' => $master->display_name,
            'bio' => $master->bio,
            // ย่อที่อยู่กระเป๋า — พอให้แยกคนชื่อซ้ำได้ ไม่ต้องเปิดทั้งใบบนกระดานสาธารณะ
            'wallet_short' => substr($master->wallet_address, 0, 6).'…'.substr($master->wallet_address, -4),
            'fee_rate_pct' => $master->fee_rate_pct,
            'platform_share_pct' => $master->platform_share_pct,
            'status' => $master->status,
            'accepting' => $master->status === AiCopyMaster::STATUS_ACTIVE,
            'promoted' => $promoted,
            'pair' => $stats['pair'] ?? null,
            'timeframe' => $stats['timeframe'] ?? null,
            // ผลเงินจริงจากไม้ที่ปิดแล้วเท่านั้น (mode = live เสมอ) — หน้าจอต้องติดป้ายตามนี้
            'record' => $stats['record'] ?? null,
            'meets_floor' => (bool) ($stats['meets_floor'] ?? false),
            'followers' => (int) ($stats['followers'] ?? 0),
            // ระดับจากผลงานเงินจริง (null = ยังไม่มีระดับ) · ดาวจากผู้ตามที่ก๊อปปี้จริง — สองอย่างนี้ซื้อไม่ได้
            'rank' => $this->ranks->present((int) $master->rank_tier),
            'rank_score' => $master->rank_score,
            'rating' => ['avg' => $master->rating_avg, 'count' => (int) $master->rating_count],
            // จำนวนไลฟ์/คลิปที่คนทั่วไปเห็นตอนนี้ (0 เมื่อยังไม่ปลดล็อก/ถูกถอนสิทธิ์) — รายการจริงโหลดแยกเมื่อกดดู
            'media_count' => count($this->media->items($master)),
            'stats_at' => $master->stats_at?->toIso8601String(),
            'registered_at' => $master->registered_at?->toIso8601String(),
        ];

        if ($own) {
            $data += [
                'bot_id' => $master->ai_bot_config_id,
                'media' => $this->mediaState($master),
                'earnings' => [
                    'master_usd' => round((float) AiCopyFee::where('ai_copy_master_id', $master->id)->where('status', '!=', AiCopyFee::STATUS_VOID)->sum('master_usd'), 2),
                    'collect' => (bool) config('aibot.copy_trade.fees.collect', false),
                ],
            ];
        }

        return $data;
    }

    private function presentFollow(AiCopyFollow $follow): array
    {
        $master = $follow->master;

        return [
            'id' => $follow->id,
            'status' => $follow->status,
            'bot_id' => $follow->ai_bot_config_id,
            'bot_status' => $follow->bot?->status,
            'master' => $master ? [
                'id' => $master->id,
                'display_name' => $master->display_name,
                'status' => $master->status,
                'fee_rate_pct' => $master->fee_rate_pct,
            ] : null,
            'fee_rate_pct' => $follow->fee_rate_pct,
            // มาสเตอร์แก้อัตราหลังเริ่มตาม — ผู้ตามยังอยู่ที่อัตราเดิมจนกว่าจะกดยอมรับ
            'rate_changed' => $follow->isActive() && $master !== null && abs($master->fee_rate_pct - $follow->fee_rate_pct) > 0.0001,
            'realized_pnl' => round($follow->realized_pnl, 2),
            'fees_usd' => round($follow->fees_usd, 2),
            'fees_tpix' => round((float) $follow->fees()->sum('fee_tpix'), 4),
            // recorded = ลงบัญชีให้เห็นเฉยๆ ยังไม่ถูกเก็บ · accrued = ค้างเก็บ
            'fees_collect' => (bool) config('aibot.copy_trade.fees.collect', false),
            'started_at' => $follow->started_at?->toIso8601String(),
            'stopped_at' => $follow->stopped_at?->toIso8601String(),
        ];
    }

    private function wallet(Request $request): string
    {
        $wallet = $this->bots->normalize(
            (string) ($request->input('wallet_address') ?? $request->query('wallet_address', ''))
        );

        abort_unless(
            preg_match('/^0x[a-f0-9]{40}$/', $wallet) === 1,
            response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_WALLET', 'message' => 'Invalid wallet address format.'],
            ], 422)
        );

        return $wallet;
    }

    /** ส่งออกได้เฉพาะรหัสที่ตั้งใจไว้ — ข้อความของ QueryException/RPC เก็บไว้ใน log ฝั่งเซิร์ฟเวอร์ */
    private function serviceFailure(RuntimeException $e): JsonResponse
    {
        if (array_key_exists($e->getMessage(), self::ERROR_MESSAGES)) {
            $status = match ($e->getMessage()) {
                CopyTradeService::ERR_DISABLED, CopyTradeService::ERR_NEEDS_LIVE => 409,
                CopyTradeService::ERR_KYC_REQUIRED, AiBotService::ERR_STRATEGY_LOCKED, AiBotService::ERR_BOT_LIMIT, AiBotService::ERR_NO_SUBSCRIPTION => 403,
                default => 422,
            };

            return $this->failure($e->getMessage(), null, $status);
        }

        Log::error('copy trade: ทำรายการล้มเหลวจากสาเหตุที่ไม่ได้ตั้งใจ', ['error' => $e->getMessage(), 'exception' => $e::class, 'path' => request()->path()]);

        return $this->failure('REQUEST_FAILED', __('ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'), 500);
    }

    private function failure(string $code, ?string $message = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message ?? __(self::ERROR_MESSAGES[$code] ?? 'ทำรายการไม่สำเร็จ')],
        ], $status);
    }
}
