<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Services\Learn\LearnCatalog;
use Illuminate\Database\Seeder;

/**
 * บทความหมวด "เรียนรู้" จาก config/learn.php — รันทุกรอบ deploy (auto-deploy.yml).
 *
 * - ยังไม่มี → สร้างและเผยแพร่ (ไทย + อังกฤษ เวลาเดียวกัน = บอทรู้ว่าเป็นคู่ภาษา)
 * - มีแล้ว → อัปเดตเนื้อหาตามต้นฉบับ ไม่แตะสถานะ/วันเผยแพร่/ยอดวิว (แอดมินซ่อนเป็นร่างไว้ = ยังเป็นร่าง)
 * - แอดมินลบทิ้งแล้ว (soft delete) → ไม่ปลุกกลับมา
 *
 * Developed by Xman Studio.
 */
class LearnLessonsSeeder extends Seeder
{
    public function run(LearnCatalog $catalog): void
    {
        $now = now();

        foreach ($catalog->all() as $i => $lesson) {
            foreach (LearnCatalog::LANGUAGES as $language) {
                $attributes = $catalog->articleAttributes($lesson, $language);
                $article = Article::withTrashed()
                    ->where('source_key', $catalog->sourceKey($lesson))
                    ->where('language', $language)
                    ->first();

                if ($article?->trashed()) {
                    continue;
                }

                if ($article !== null) {
                    $article->fill($attributes)->save();

                    continue;
                }

                // บทเรียนแรกในไฟล์ขึ้นบนสุดของหน้าบล็อก (เรียงใหม่ → เก่า)
                Article::create($attributes + [
                    'language' => $language,
                    'status' => 'published',
                    'published_at' => $now->copy()->subMinutes($i),
                    'source_key' => $catalog->sourceKey($lesson),
                ]);
            }
        }
    }
}
