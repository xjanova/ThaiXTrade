<?php

namespace App\Services\Validator;

use Illuminate\Support\Facades\Cache;

/**
 * IbftValidatorSet — ชุด validator ที่ปิดบล็อกอยู่จริง ณ บล็อกล่าสุด.
 *
 * อ่านจาก extraData ไม่ใช่ RPC `ibft_*` เพราะ polygon-edge 0.9 ตอบ -32601 ทุกเมธอดตระกูลนั้น
 *
 * Developed by Xman Studio
 */
class IbftValidatorSet
{
    private const CACHE_KEY = 'validators:ibft-set';

    private const CACHE_TTL = 15;

    public function __construct(
        private readonly TpixRpc $rpc,
        private readonly IbftExtraDecoder $decoder,
    ) {}

    /**
     * @return array{block:int,validators:list<array{address:string,bls:?string}>}|null null = อ่านเชนไม่ได้
     */
    public function current(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $block = $this->rpc->block('latest');
        $summary = $block === null ? null : $this->decoder->summarize($block);
        if ($summary === null) {
            return null;
        }

        $set = ['block' => $summary['number'], 'validators' => $summary['validators']];
        Cache::put(self::CACHE_KEY, $set, self::CACHE_TTL);

        return $set;
    }

    /** @return list<string> ที่อยู่ตัวพิมพ์เล็ก */
    public function addresses(): ?array
    {
        $set = $this->current();

        return $set === null ? null : array_column($set['validators'], 'address');
    }

    /** null = ไม่ทราบ (อ่านเชนไม่ได้) */
    public function contains(string $address): ?bool
    {
        $addresses = $this->addresses();

        return $addresses === null ? null : in_array(strtolower($address), $addresses, true);
    }

    /**
     * ต้องได้กี่เสียงถึงจะเพิ่ม/ถอด (polygon-edge: เกินครึ่งของชุดปัจจุบัน).
     */
    public static function votesNeeded(int $setSize): int
    {
        return intdiv($setSize, 2) + 1;
    }

    /**
     * จำนวนที่ต้องเห็นพ้องเพื่อปิดบล็อก — polygon-edge 0.9 OptimalQuorumSize:
     * ต่ำกว่า 4 ตัวต้องครบทุกตัว ไม่งั้น ceil(2N/3)
     */
    public static function quorum(int $setSize): int
    {
        return $setSize < 4 ? $setSize : (int) ceil(2 * $setSize / 3);
    }

    /** ดับพร้อมกันได้กี่ตัวโดยเชนยังเดิน */
    public static function faultTolerance(int $setSize): int
    {
        return max(0, $setSize - self::quorum($setSize));
    }
}
