<?php

namespace App\Services\AiBot\Copy;

use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\AiCopyReview;
use App\Models\AiCopyReviewReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TPIX TRADE — ดาวรีวิวของมาสเตอร์ จากผู้ตามที่ก๊อปปี้ด้วยเงินจริงมาแล้วเท่านั้น.
 *
 * เจ้าของสั่ง 2026-11-13: "ระบบดาว ให้ผู้ตามรีวิวได้"
 *
 *  - รีวิวได้เฉพาะคนที่ตามมาสเตอร์คนนั้นครบระยะขั้นต่ำ และปิดไม้เงินจริงที่ก๊อปปี้ไปแล้วอย่างน้อยหนึ่งไม้
 *    (เปิดกระเป๋าเปล่ามาให้ห้าดาวตัวเอง หรือมาถล่มหนึ่งดาวคู่แข่ง ต้องทำไม่ได้)
 *  - หนึ่งคนต่อมาสเตอร์หนึ่งรีวิว แก้ได้ (ผลเปลี่ยน ความเห็นก็เปลี่ยนได้)
 *  - รายงานรีวิวได้ แต่การรายงานไม่ซ่อนรีวิวเอง — ทีมงานตัดสิน (aibot:copy-review)
 *  - ดาวไม่มีผลกับ rank และลำดับบนกระดาน (RankService ไม่อ่านค่านี้)
 *
 * Developed by Xman Studio.
 */
class ReviewService
{
    public const ERR_NOT_ELIGIBLE = 'COPY_REVIEW_NOT_ELIGIBLE';

    public const ERR_NOT_FOUND = 'COPY_REVIEW_NOT_FOUND';

    public const ERR_OWN_REVIEW = 'COPY_REVIEW_OWN';

    /** กระเป๋านี้รีวิวมาสเตอร์คนนี้ได้ไหม — ตามด้วยเงินจริงครบระยะ และปิดไม้ที่ก๊อปปี้ไปแล้ว */
    public function canReview(string $wallet, AiCopyMaster $master): bool
    {
        if ($master->wallet_address === $wallet) {
            return false;
        }

        $minDays = max(0, (int) config('aibot.copy_trade.reviews.min_follow_days', 14));

        return AiCopyFollow::where('ai_copy_master_id', $master->id)
            ->where('follower_wallet', $wallet)
            ->where('last_settled_trade_id', '>', 0)
            ->where('started_at', '<=', now()->subDays($minDays))
            ->exists();
    }

    public function reviewBy(string $wallet, AiCopyMaster $master): ?AiCopyReview
    {
        return AiCopyReview::where('ai_copy_master_id', $master->id)->where('reviewer_wallet', $wallet)->first();
    }

    /** ให้ดาว/แก้รีวิว — ข้อความถูกทำให้เป็นข้อความล้วนก่อนบันทึก */
    public function submit(string $wallet, AiCopyMaster $master, int $stars, ?string $comment): AiCopyReview
    {
        if (! $this->canReview($wallet, $master)) {
            throw new RuntimeException(self::ERR_NOT_ELIGIBLE);
        }

        return DB::transaction(function () use ($wallet, $master, $stars, $comment) {
            $review = AiCopyReview::updateOrCreate(
                ['ai_copy_master_id' => $master->id, 'reviewer_wallet' => $wallet],
                ['stars' => max(1, min(5, $stars)), 'comment' => self::plainText($comment)],
            );

            $this->refreshRating($master);

            return $review->fresh();
        });
    }

    /** รายงานรีวิว — หนึ่งกระเป๋าต่อรีวิวหนึ่งครั้ง รายงานรีวิวของตัวเองไม่ได้ */
    public function report(string $wallet, AiCopyReview $review, ?string $reason): AiCopyReview
    {
        if ($review->reviewer_wallet === $wallet) {
            throw new RuntimeException(self::ERR_OWN_REVIEW);
        }

        DB::transaction(function () use ($wallet, $review, $reason) {
            $report = AiCopyReviewReport::firstOrCreate(
                ['ai_copy_review_id' => $review->id, 'reporter_wallet' => $wallet],
                ['reason' => self::plainText($reason, 200)],
            );

            if ($report->wasRecentlyCreated) {
                $review->increment('reports');
            }
        });

        return $review->fresh();
    }

    /** ทีมงานซ่อน/คืนรีวิว — ค่าเฉลี่ยคิดใหม่จากรีวิวที่ยังโชว์อยู่เท่านั้น */
    public function setVisible(AiCopyReview $review, bool $visible): AiCopyReview
    {
        $review->update(['status' => $visible ? AiCopyReview::STATUS_VISIBLE : AiCopyReview::STATUS_HIDDEN]);

        if ($review->master) {
            $this->refreshRating($review->master);
        }

        return $review->fresh();
    }

    public function refreshRating(AiCopyMaster $master): void
    {
        $visible = AiCopyReview::where('ai_copy_master_id', $master->id)->visible();
        $count = (clone $visible)->count();

        $master->update([
            'rating_avg' => $count > 0 ? round((float) (clone $visible)->avg('stars'), 2) : null,
            'rating_count' => $count,
        ]);
    }

    /**
     * ข้อความจากผู้ใช้ → ข้อความล้วน: ตัดแท็ก อักขระควบคุม และช่องว่างซ้ำ (ว่าง = null).
     *
     * ตัดที่เซิร์ฟเวอร์แม้หน้าเว็บจะ escape ตอนแสดงอยู่แล้ว — แอพมือถือและหน้าหลังบ้านอ่านค่าเดียวกันนี้
     */
    public static function plainText(?string $text, int $max = 0): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = strip_tags($text);
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
        $clean = trim((string) preg_replace('/[ \t]+/u', ' ', $clean));

        if ($max <= 0) {
            $max = (int) config('aibot.copy_trade.reviews.max_comment_length', 500);
        }

        $clean = mb_substr($clean, 0, $max);

        return $clean === '' ? null : $clean;
    }
}
