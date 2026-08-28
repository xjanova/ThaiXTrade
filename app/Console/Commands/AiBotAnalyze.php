<?php

namespace App\Console\Commands;

use App\Models\AiMarketView;
use App\Services\AiBot\Analyst\MarketAnalyst;
use App\Services\AiBot\Analyst\MarketContext;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — รอบวิเคราะห์ตลาดด้วย AI.
 *
 *   php artisan aibot:analyze --scope=strategic   (ทุก 4 ชม.)
 *   php artisan aibot:analyze --scope=tactical    (ทุก 15 นาที · แพลนสูง)
 *   php artisan aibot:analyze --coverage          (ตรวจว่าเหรียญไหนยังไม่มีข่าว)
 *   php artisan aibot:analyze --dry-run           (ดูบริบทที่จะส่งไป ไม่ยิงจริง)
 *
 * `--dry-run` มีไว้เพราะบริบทที่ส่งออกไปคือสิ่งที่กำหนดคุณภาพคำตอบทั้งหมด —
 * ต้องดูได้ว่าส่งอะไรไปบ้างโดยไม่ต้องเสียค่า API และไม่ต้องรอรอบจริง
 *
 * Developed by Xman Studio.
 */
class AiBotAnalyze extends Command
{
    protected $signature = 'aibot:analyze
        {--scope=strategic : รอบวิเคราะห์ strategic หรือ tactical}
        {--coverage : รายงานความครอบคลุมของข่าวต่อเหรียญ แล้วจบ}
        {--dry-run : ประกอบบริบทให้ดูโดยไม่เรียก AI}';

    protected $description = 'ให้ AI ประเมินภาพตลาดและจัดอันดับเหรียญเป็นรอบ';

    public function handle(MarketAnalyst $analyst, MarketContext $context): int
    {
        if ($this->option('coverage')) {
            return $this->reportCoverage($context);
        }

        $scope = (string) $this->option('scope');

        if ($this->option('dry-run')) {
            return $this->dryRun($context, $scope);
        }

        $result = $analyst->run($scope);

        if (! ($result['ok'] ?? false)) {
            $this->error($result['reason'] ?? 'วิเคราะห์ไม่สำเร็จ');

            return self::FAILURE;
        }

        /** @var AiMarketView $view */
        $view = $result['view'];

        $this->components->info("รอบ {$scope} เสร็จแล้ว — {$view->model}");
        $this->line("ท่าทีตลาด: {$view->regime} · มั่นใจ {$view->confidence} · ตัวคูณขนาดไม้ {$view->size_multiplier}");
        $this->line('เหรียญที่ให้ความเห็น: '.count((array) $view->coins));
        $this->line('คู่ที่คัดไว้: '.(implode(' ', $view->shortlistPairs()) ?: '—'));
        $this->line("โทเคนที่ใช้ {$view->tokens_used} · ใช้เวลา {$view->latency_ms} ms");
        $this->newLine();
        $this->line($view->summary ?: '(ไม่มีสรุป)');

        return self::SUCCESS;
    }

    /**
     * รายงานว่าเหรียญไหนยังไม่มีข่าว — ช่องว่างที่ทำให้ AI เลือกเหรียญไม่ได้จริง.
     *
     * มีคำสั่งนี้เพราะความครอบคลุมของข่าวเสื่อมได้เงียบๆ: แอดมินเปิดคู่เทรดใหม่
     * ที่ยังไม่มีในพจนานุกรม แล้วบอทจะเทรดเหรียญที่ด่านข่าวมองไม่เห็นเลย
     */
    private function reportCoverage(MarketContext $context): int
    {
        $built = $context->build(AiMarketView::SCOPE_STRATEGIC);
        $unknown = $context->unknownCoins();

        $silent = array_values(array_filter(
            $built['coins'],
            fn (array $coin) => $coin['news_count'] === 0,
        ));

        $this->components->info('ความครอบคลุมของข่าว (24 ชม. ล่าสุด)');
        $this->line('เหรียญใน universe: '.count($built['coins']));
        $this->line('มีข่าว: '.(count($built['coins']) - count($silent)).' · ไม่มีข่าวเลย: '.count($silent));

        if ($silent !== []) {
            $this->newLine();
            $this->warn('เหรียญที่ยังไม่มีข่าวใน 24 ชม.:');
            $this->line(implode(' ', array_column($silent, 'symbol')));
            $this->line('→ ปกติถ้าเพิ่งเริ่มหมุนคิว รอให้ครบรอบ (~90 นาที) แล้วดูใหม่');
        }

        if ($unknown !== []) {
            $this->newLine();
            $this->error('เหรียญที่เปิดเทรดอยู่แต่ยังไม่มีในพจนานุกรม:');
            $this->line(implode(' ', $unknown));
            $this->line('→ เติมใน config/aibot_coins.php ไม่งั้น AI จะเลือกเหรียญพวกนี้ไม่ได้เลย');
        }

        return $unknown === [] ? self::SUCCESS : self::FAILURE;
    }

    private function dryRun(MarketContext $context, string $scope): int
    {
        $built = $context->build($scope);

        $this->components->info("บริบทของรอบ {$scope} (ไม่ได้เรียก AI)");
        $this->line('เหรียญ: '.count($built['coins']).' · พาดหัวข่าว: '.count($built['headlines']));
        $this->line('ของที่ถืออยู่: '.count($built['holdings']).' เหรียญ');
        $this->line("ต้นทุนเข้า-ออกหนึ่งรอบ: {$built['cost_bps']} bps");
        $this->newLine();

        $this->table(
            ['เหรียญ', 'ราคา', '24ชม.%', 'ข่าว', 'ตื่นตระหนก', 'ถืออยู่'],
            array_map(fn (array $c) => [
                $c['symbol'],
                $c['price'] ?? '—',
                $c['change_24h_pct'] ?? '—',
                $c['news_count'],
                $c['worst_panic'],
                $c['held'] ? '✓' : '',
            ], array_slice($built['coins'], 0, 25)),
        );

        return self::SUCCESS;
    }
}
