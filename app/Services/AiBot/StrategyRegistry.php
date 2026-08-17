<?php

namespace App\Services\AiBot;

use App\Services\AiBot\Strategies\AiSignalStrategy;
use App\Services\AiBot\Strategies\ArbitrageStrategy;
use App\Services\AiBot\Strategies\BreakoutStrategy;
use App\Services\AiBot\Strategies\DcaStrategy;
use App\Services\AiBot\Strategies\GridStrategy;
use App\Services\AiBot\Strategies\MeanReversionStrategy;
use App\Services\AiBot\Strategies\MomentumStrategy;
use App\Services\AiBot\Strategies\ScalpingStrategy;
use App\Services\AiBot\Strategies\Strategy;

/**
 * TPIX TRADE — ทะเบียนกลยุทธ์ code → คลาสที่รันจริง.
 *
 * ต้องมีครบทุก code ที่อยู่ใน config/aibot.php — มีเทสต์บังคับไว้
 * (ถ้าเพิ่มกลยุทธ์ใน config แล้วลืมเขียนคลาส บอทของผู้ใช้จะตั้งได้แต่ไม่มีอะไรทำงาน)
 *
 * Developed by Xman Studio.
 */
class StrategyRegistry
{
    /** @var array<string, Strategy>|null */
    private ?array $strategies = null;

    /** @return array<string, Strategy> */
    public function all(): array
    {
        if ($this->strategies === null) {
            $this->strategies = collect([
                new GridStrategy(),
                new DcaStrategy(),
                new MomentumStrategy(),
                new MeanReversionStrategy(),
                new BreakoutStrategy(),
                new ScalpingStrategy(),
                new ArbitrageStrategy(),
                new AiSignalStrategy(),
            ])->keyBy(fn (Strategy $s) => $s->code())->all();
        }

        return $this->strategies;
    }

    public function find(string $code): ?Strategy
    {
        return $this->all()[$code] ?? null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }
}
