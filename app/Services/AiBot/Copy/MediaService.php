<?php

namespace App\Services\AiBot\Copy;

use App\Models\AiCopyFee;
use App\Models\AiCopyMaster;
use App\Models\AiCopyMedia;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TPIX TRADE — ไลฟ์/คลิปของมาสเตอร์: ใครแปะได้ แปะอะไรได้ และใครเห็น.
 *
 * เจ้าของสั่ง 2026-11-13: "ทำระบบรองรับการไลฟ์ สอน แชร์คลิปมาจากช่องตัวเองได้ เมื่อมีผลงานเด่น ทำยอดให้แพลตฟอร์มดี"
 *
 *  - สิทธิ์แปะลิงก์ปลดล็อกเมื่อ "ระดับถึงเกณฑ์" และ "ทำยอดให้แพลตฟอร์มถึงเกณฑ์" ทั้งสองข้อ (config)
 *    และทีมงานถอนสิทธิ์รายคนได้ (media_revoked_at · คำสั่ง aibot:copy-media)
 *  - สิทธิ์เป็นของ "ตอนนี้": ระดับตก/ถูกถอนสิทธิ์ ลิงก์เดิมหายจากหน้าสาธารณะทันที (ไม่ถูกลบ กลับมาโชว์เมื่อสิทธิ์กลับ)
 *  - คลิปแนะนำ (featured) เป็นสิทธิ์พื้นฐานของมาสเตอร์ทุกคน: ตั้งได้ไม่เกิน featured_max คลิปโดยไม่ต้องรอปลดล็อก
 *    โชว์บนหน้าโปรไฟล์ — ยังถูกทีมงานถอนสิทธิ์/ซ่อนได้เหมือนลิงก์อื่น
 *  - ลิงก์ผ่าน MediaLink เท่านั้น — เก็บผู้ให้บริการกับรหัสวิดีโอ ไม่เก็บสิ่งที่ผู้ใช้พิมพ์ · ชื่อเรื่องเป็นข้อความล้วน
 *  - ยอดที่ทำให้แพลตฟอร์ม = ส่วนของแพลตฟอร์มในบัญชีค่าบริการ (ไม่นับรายการที่ถูกยกเลิก)
 *
 * Developed by Xman Studio.
 */
class MediaService
{
    public const ERR_LOCKED = 'COPY_MEDIA_LOCKED';

    public const ERR_INVALID_LINK = 'COPY_MEDIA_INVALID_LINK';

    public const ERR_LIMIT = 'COPY_MEDIA_LIMIT';

    public const ERR_DUPLICATE = 'COPY_MEDIA_DUPLICATE';

    public const ERR_NOT_FOUND = 'COPY_MEDIA_NOT_FOUND';

    public const ERR_REVOKED = 'COPY_MEDIA_REVOKED';

    public const ERR_FEATURED_LIMIT = 'COPY_MEDIA_FEATURED_LIMIT';

    public const ERR_FEATURED_KIND = 'COPY_MEDIA_FEATURED_KIND';

    /**
     * สิทธิ์แปะลิงก์ของมาสเตอร์ตอนนี้ พร้อมตัวเลขที่ใช้ตัดสิน (ให้หน้าจอบอกได้ว่ายังขาดอะไร).
     *
     * @return array{unlocked: bool, revoked: bool, tier: array{need: int, have: int}, revenue_usd: array{need: float, have: float}}
     */
    public function access(AiCopyMaster $master): array
    {
        $needTier = (int) config('aibot.copy_trade.media.min_tier', 3);
        $needRevenue = (float) config('aibot.copy_trade.media.min_platform_revenue_usd', 50);

        $revenue = round((float) AiCopyFee::where('ai_copy_master_id', $master->id)
            ->where('status', '!=', AiCopyFee::STATUS_VOID)
            ->sum('platform_usd'), 2);

        $revoked = $master->media_revoked_at !== null;

        return [
            'unlocked' => $master->isOpen() && ! $revoked && (int) $master->rank_tier >= $needTier && $revenue >= $needRevenue,
            'revoked' => $revoked,
            'tier' => ['need' => $needTier, 'have' => (int) $master->rank_tier],
            'revenue_usd' => ['need' => $needRevenue, 'have' => $revenue],
        ];
    }

    public function featuredMax(): int
    {
        return max(0, (int) config('aibot.copy_trade.media.featured_max', 3));
    }

    /**
     * แปะลิงก์ — ข้อความที่ผู้ใช้ส่งมาไม่ถูกเก็บ เก็บเฉพาะผู้ให้บริการกับรหัสวิดีโอที่ตรวจแล้ว.
     *
     * @param  bool  $featured  ตั้งเป็นคลิปแนะนำบนหน้าโปรไฟล์ — ไม่ต้องรอปลดล็อก แต่มีเพดานของตัวเอง และเป็นคลิปเสมอ
     */
    public function add(AiCopyMaster $master, string $kind, mixed $url, ?string $title, bool $featured = false): AiCopyMedia
    {
        if ($featured) {
            $kind = 'clip';
        } elseif (! $this->access($master)['unlocked']) {
            throw new RuntimeException(self::ERR_LOCKED);
        }

        $link = MediaLink::parse($url);

        if ($link === null || ! in_array($kind, AiCopyMedia::KINDS, true)) {
            throw new RuntimeException(self::ERR_INVALID_LINK);
        }

        // ล็อกแถวมาสเตอร์ก่อนนับ — สองคำขอพร้อมกันต้องไม่ทะลุเพดานจำนวน
        return DB::transaction(function () use ($master, $kind, $link, $title, $featured) {
            AiCopyMaster::whereKey($master->id)->lockForUpdate()->first();

            if ($featured) {
                $this->assertCanFeature($master);
            } elseif (AiCopyMedia::where('ai_copy_master_id', $master->id)->whereNull('featured_at')->count() >= (int) config('aibot.copy_trade.media.max_items', 10)) {
                throw new RuntimeException(self::ERR_LIMIT);
            }

            if (AiCopyMedia::where('ai_copy_master_id', $master->id)->where('provider', $link['provider'])->where('external_id', $link['external_id'])->exists()) {
                throw new RuntimeException(self::ERR_DUPLICATE);
            }

            try {
                return AiCopyMedia::create([
                    'ai_copy_master_id' => $master->id,
                    'kind' => $kind,
                    'provider' => $link['provider'],
                    'external_id' => $link['external_id'],
                    'title' => ReviewService::plainText($title, 120) ?? ucfirst($link['provider']),
                    'status' => AiCopyMedia::STATUS_VISIBLE,
                    'featured_at' => $featured ? now() : null,
                ]);
            } catch (QueryException) {
                // unique (มาสเตอร์ · ผู้ให้บริการ · รหัสวิดีโอ) เป็นด่านสุดท้าย
                throw new RuntimeException(self::ERR_DUPLICATE);
            }
        });
    }

    /** ตั้ง/ถอดคลิปแนะนำจากลิงก์ที่แปะไว้แล้ว — ถอดได้เสมอ · ตั้งได้เฉพาะคลิป ไม่เกินเพดาน และเมื่อไม่ถูกถอนสิทธิ์ */
    public function setFeatured(AiCopyMaster $master, int $id, bool $featured): AiCopyMedia
    {
        return DB::transaction(function () use ($master, $id, $featured) {
            AiCopyMaster::whereKey($master->id)->lockForUpdate()->first();

            $media = AiCopyMedia::where('ai_copy_master_id', $master->id)->where('id', $id)->first();

            if (! $media) {
                throw new RuntimeException(self::ERR_NOT_FOUND);
            }

            if (! $featured) {
                $media->update(['featured_at' => null]);

                return $media;
            }

            if ($media->featured_at !== null) {
                return $media;
            }

            if ($media->kind !== 'clip') {
                throw new RuntimeException(self::ERR_FEATURED_KIND);
            }

            $this->assertCanFeature($master);
            $media->update(['featured_at' => now()]);

            return $media;
        });
    }

    private function assertCanFeature(AiCopyMaster $master): void
    {
        if (! $master->isOpen()) {
            throw new RuntimeException(self::ERR_LOCKED);
        }

        if ($master->media_revoked_at !== null) {
            throw new RuntimeException(self::ERR_REVOKED);
        }

        if (AiCopyMedia::where('ai_copy_master_id', $master->id)->whereNotNull('featured_at')->count() >= $this->featuredMax()) {
            throw new RuntimeException(self::ERR_FEATURED_LIMIT);
        }
    }

    public function remove(AiCopyMaster $master, int $id): void
    {
        $deleted = AiCopyMedia::where('ai_copy_master_id', $master->id)->where('id', $id)->delete();

        if ($deleted === 0) {
            throw new RuntimeException(self::ERR_NOT_FOUND);
        }
    }

    /**
     * รายการที่ส่งออกได้ — สาธารณะเห็นเฉพาะรายการที่ไม่ถูกซ่อน ของมาสเตอร์ที่ยังเปิดโปรไฟล์และไม่ถูกถอนสิทธิ์:
     * ปลดล็อกแล้วเห็นทั้งหมด · ยังไม่ปลดล็อกเห็นเฉพาะคลิปแนะนำ.
     *
     * @return list<array{id: int, kind: string, provider: string, title: string, url: string, embed_url: string, status: string, featured: bool}>
     */
    public function items(AiCopyMaster $master, bool $forOwner = false): array
    {
        $query = AiCopyMedia::where('ai_copy_master_id', $master->id);

        if (! $forOwner) {
            if (! $master->isOpen() || $master->media_revoked_at !== null) {
                return [];
            }

            $query->visible();

            if (! $this->access($master)['unlocked']) {
                $query->whereNotNull('featured_at');
            }
        }

        // คลิปแนะนำขึ้นก่อน ตามลำดับที่ตั้ง · ที่เหลือใหม่สุดก่อน
        return $query
            ->orderByRaw('featured_at is null')
            ->orderBy('featured_at')
            // ตั้งในวินาทีเดียวกัน = เรียงตามลำดับที่แปะ (id น้อยก่อน) · รายการธรรมดา id มากก่อน
            ->orderByRaw('case when featured_at is null then -id else id end')
            ->get()
            ->map(function (AiCopyMedia $media) {
                // ประกอบลิงก์ใหม่จากรหัสที่เก็บไว้ทุกครั้ง — แถวที่รูปแบบเพี้ยน (ถูกแก้ในฐานข้อมูล) ไม่ถูกส่งออก
                $link = MediaLink::build($media->provider, $media->external_id);

                return $link === null ? null : [
                    'id' => $media->id,
                    'kind' => $media->kind,
                    'provider' => $media->provider,
                    'title' => $media->title,
                    'url' => $link['url'],
                    'embed_url' => $link['embed_url'],
                    'status' => $media->status,
                    'featured' => $media->featured_at !== null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** คลิปแนะนำที่คนทั่วไปเห็นบนหน้าโปรไฟล์ตอนนี้ */
    public function featured(AiCopyMaster $master): array
    {
        return array_values(array_filter($this->items($master), fn (array $item) => $item['featured']));
    }

    /** ทีมงาน: ถอน/คืนสิทธิ์แปะลิงก์ของมาสเตอร์คนนี้ */
    public function setRevoked(AiCopyMaster $master, bool $revoked): AiCopyMaster
    {
        $master->update(['media_revoked_at' => $revoked ? now() : null]);

        return $master->fresh();
    }

    /** ทีมงาน: ซ่อน/คืนลิงก์หนึ่งรายการ */
    public function setVisible(AiCopyMedia $media, bool $visible): AiCopyMedia
    {
        $media->update(['status' => $visible ? AiCopyMedia::STATUS_VISIBLE : AiCopyMedia::STATUS_HIDDEN]);

        return $media->fresh();
    }
}
