<?php

namespace App\Services;

use App\Models\Chain;
use App\Models\Kline;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Support\Wei;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ไม้สวอปบนเชน TPIX — บันทึกจาก event Swap ของพูลบนเชนจริง ไม่ใช่จากตัวเลขที่หน้าเว็บส่งมา
 *
 * ⚠️ เดิม (ถึง 2026-10-11) รายการ "เทรดล่าสุด" ของคู่ DEX มาจาก POST /api/v1/swap/execute
 *    ที่หน้าเว็บยิงหลังสวอป และเซิร์ฟเวอร์บันทึก status=confirmed ตามที่ถูกบอกมาเลย
 *    → ใครที่เซ็นยืนยันกระเป๋าแล้ว ยิงไม้ปลอม (จำนวน/ราคาอะไรก็ได้ + tx hash มั่ว) เข้ารายการสาธารณะได้
 *    → สวอปที่ทำนอกเว็บ (แอปมือถือ, เรียกสัญญาตรง) ไม่เคยขึ้นรายการ
 *
 * ตอนนี้: dex:sync ไล่ event Swap ของทุกพูลทุกนาที (syncAll) และการแจ้ง tx hash จากหน้าเว็บ
 * แค่ "เร่ง" ให้บันทึกทันที (recordTransaction) — กระเป๋า จำนวน ราคา เวลา อ่านจากเชนทั้งหมด
 * log ต้องมาจากที่อยู่พูลที่ dex:sync อ่านจาก factory เท่านั้น สัญญาอื่นยิง event หน้าตาเหมือนกันก็ไม่ถูกนับ
 *
 * Developed by Xman Studio
 */
class DexSwapIndexer
{
    public const SWAP_EVENT = 'Swap(address,uint256,uint256,uint256,uint256,address)';

    /** polygon-edge ปฏิเสธ eth_getLogs ช่วงเกิน 1000 บล็อก ("block range too high") — เผื่อไว้ */
    public const CHUNK_BLOCKS = 900;

    /**
     * ไล่ย้อนหลังได้ไม่เกินเท่านี้ต่อพูลต่อรอบ (dex:sync รันทุกนาที)
     * รอบแรกหลัง deploy ต้องไล่ ~2 หมื่นบล็อก — ไม่หั่นจะกินเวลาจนรอบถัดไปซ้อน
     */
    public const MAX_BLOCKS_PER_RUN = 18000;

    /** ตำแหน่งที่ไล่ถึงแล้ว เก็บในฐานข้อมูล (แคชถูกล้างตอน deploy แล้วต้องไล่ใหม่ตั้งแต่ต้นทุกครั้ง) */
    private const CURSOR_GROUP = 'dex_indexer';

    /**
     * ผลของ recordLog เมื่อเชนตอบไม่ได้ระหว่างอ่านข้อมูลประกอบ (ผู้ส่ง/เวลาบล็อก/token0)
     * ต่างจาก null ที่แปลว่า "ไม่ต้องบันทึก" (ซ้ำ / ไม่ใช่ไม้ซื้อขาย) — อันนี้ต้องกลับมาอ่านใหม่
     */
    private const RETRY = 'retry';

    /** @var array<string, string> tx hash → กระเป๋าผู้สวอป (จำในรอบเดียว) */
    private array $senders = [];

    /** @var array<int, int> เลขบล็อก → เวลา (จำในรอบเดียว) */
    private array $blockTimes = [];

    public function __construct(
        private TpixDexService $dex,
        private ChainResolver $chains,
    ) {}

    /**
     * ไล่ event Swap ของทุกพูลตั้งแต่บล็อกที่อ่านถึงล่าสุด
     *
     * @return int จำนวนไม้ที่บันทึกใหม่ในรอบนี้
     */
    public function syncAll(): int
    {
        $chain = $this->tpixChain();
        if (! $chain) {
            return 0;
        }

        $latest = $this->dex->blockNumber();
        if ($latest === null) {
            return 0;
        }

        $topic = $this->dex->eventTopic(self::SWAP_EVENT);
        $recorded = 0;

        foreach ($this->onchainPairs($chain) as $pair) {
            $from = $this->cursor($pair);
            $end = min($latest, $from + self::MAX_BLOCKS_PER_RUN - 1);

            while ($from <= $end) {
                $to = min($from + self::CHUNK_BLOCKS - 1, $end);
                $logs = $this->dex->logs($pair->dex_pair_address, [$topic], $from, $to);

                // เชนตอบไม่ได้ — หยุดพูลนี้ไว้ที่เดิม รอบหน้าเริ่มจากตรงนี้ (ห้ามขยับเคอร์เซอร์ข้ามช่วงที่ยังไม่ได้อ่าน)
                if ($logs === null) {
                    break;
                }

                $retryAt = null;
                foreach ($logs as $log) {
                    $row = $this->recordLog($chain, $pair, $log);
                    if ($row === self::RETRY) {
                        /*
                         * ⚠️ เดิมข้าม log นี้แล้วขยับเคอร์เซอร์ผ่านไป = ไม้หายถาวร (ประวัติ/ปริมาณไม่ครบ)
                         * ตอนนี้หยุดพูลนี้ไว้ที่บล็อกของ log นี้ รอบหน้าอ่านบล็อกนี้ใหม่
                         * log ก่อนหน้าในบล็อกเดียวกันที่บันทึกไปแล้วไม่ซ้ำ (ด่าน exists ใน recordLog)
                         */
                        $retryAt = max($from, (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0')));
                        break;
                    }
                    if ($row instanceof Transaction) {
                        $recorded++;
                    }
                }

                if ($retryAt !== null) {
                    $this->setCursor($pair, $retryAt);
                    break;
                }

                $this->setCursor($pair, $to + 1);
                $from = $to + 1;
            }
        }

        return $recorded;
    }

    /**
     * บันทึกไม้จาก tx hash เดียว — หน้าเว็บแจ้งเข้ามาหลังสวอปเสร็จ ให้ขึ้นรายการทันทีไม่ต้องรอรอบนาที
     *
     * @return array{status: string, transactions: Transaction[]}
     *   status: recorded | exists | pending (ยังไม่ลงบล็อก/เชนตอบไม่ได้) | failed (tx revert)
     *           wallet_mismatch (คนแจ้งไม่ใช่คนทำ) | not_swap (ไม่มี Swap จากพูลของเรา) | unavailable
     */
    public function recordTransaction(string $txHash, ?string $expectedWallet = null): array
    {
        $txHash = strtolower($txHash);
        $chain = $this->tpixChain();
        if (! $chain) {
            return ['status' => 'unavailable', 'transactions' => []];
        }

        $existing = Transaction::where('chain_id', $chain->id)
            ->where('type', 'swap')
            ->where('tx_hash', $txHash)
            ->get();
        if ($existing->isNotEmpty()) {
            return ['status' => 'exists', 'transactions' => $existing->all()];
        }

        $receipt = $this->dex->receipt($txHash);
        if ($receipt === null) {
            return ['status' => 'pending', 'transactions' => []];
        }

        if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            return ['status' => 'failed', 'transactions' => []];
        }

        $sender = strtolower((string) ($receipt['from'] ?? ''));
        if ($expectedWallet !== null && $sender !== strtolower($expectedWallet)) {
            return ['status' => 'wallet_mismatch', 'transactions' => []];
        }
        $this->senders[$txHash] = $sender;

        $pairs = $this->onchainPairs($chain);
        $topic = $this->dex->eventTopic(self::SWAP_EVENT);
        $rows = [];
        $retry = false;

        foreach ($receipt['logs'] ?? [] as $log) {
            $pair = $pairs->get(strtolower((string) ($log['address'] ?? '')));
            if (! $pair || strtolower((string) ($log['topics'][0] ?? '')) !== $topic) {
                continue;
            }

            // receipt ของ edge ไม่มี txHash ในแต่ละ log ทุกเวอร์ชัน — เติมให้ recordLog ใช้คีย์เดียวกัน
            $log['transactionHash'] = $log['transactionHash'] ?? $txHash;
            $log['blockNumber'] = $log['blockNumber'] ?? ($receipt['blockNumber'] ?? null);

            $row = $this->recordLog($chain, $pair, $log);
            if ($row === self::RETRY) {
                $retry = true;
            } elseif ($row instanceof Transaction) {
                $rows[] = $row;
            }
        }

        // อ่านข้อมูลประกอบไม่ได้ (เชนสะดุด) — ไม่ใช่ "ไม่ใช่สวอป" · dex:sync จะบันทึกให้เองในรอบนาที
        if ($rows === [] && $retry) {
            return ['status' => 'pending', 'transactions' => []];
        }

        if ($rows === []) {
            // ไม่มี Swap จากพูลของเรา หรือมีแต่ถูกบันทึกไปพร้อมกันจากอีกทาง — ดูซ้ำอีกที
            $existing = Transaction::where('chain_id', $chain->id)->where('type', 'swap')->where('tx_hash', $txHash)->get();

            return $existing->isNotEmpty()
                ? ['status' => 'exists', 'transactions' => $existing->all()]
                : ['status' => 'not_swap', 'transactions' => []];
        }

        return ['status' => 'recorded', 'transactions' => $rows];
    }

    /**
     * log Swap หนึ่งอัน → แถว transactions หนึ่งแถว (+ ปริมาณในแท่ง 1 นาที)
     * ซ้ำได้ปลอดภัย: คีย์คือ tx hash + เหรียญขาเข้า/ขาออก (สวอปหลายฮอปในธุรกรรมเดียวได้หลายแถว คนละคู่)
     */
    private function recordLog(Chain $chain, TradingPair $pair, array $log): Transaction|string|null
    {
        $txHash = strtolower((string) ($log['transactionHash'] ?? ''));
        $blockNumber = (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0'));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash) || $blockNumber <= 0) {
            return null;
        }

        $trade = $this->decodeTradeOrRetry($pair, (string) ($log['data'] ?? ''));
        if ($trade === self::RETRY) {
            return $this->retryOrGiveUp($txHash, $log);
        }
        if ($trade === null) {
            return null;
        }

        $result = Cache::lock("dex:swap-index:{$txHash}", 15)->block(10, function () use ($chain, $pair, $log, $txHash, $blockNumber, $trade) {
            $exists = Transaction::where('chain_id', $chain->id)
                ->where('type', 'swap')
                ->where('tx_hash', $txHash)
                ->where('from_token', $trade['from_token'])
                ->where('to_token', $trade['to_token'])
                ->exists();
            if ($exists) {
                return null;
            }

            $sender = $this->senderOf($txHash);
            $timestamp = $this->timeOf($blockNumber);
            if ($sender === null || $timestamp === null) {
                // ข้อมูลไม่ครบ — ไม่บันทึกครึ่งๆ กลางๆ และให้ผู้เรียกกลับมาอ่าน log นี้ใหม่ (เดิมข้ามทิ้งถาวร)
                return self::RETRY;
            }

            $time = Carbon::createFromTimestamp($timestamp, config('app.timezone'));

            $tx = new Transaction([
                'type' => 'swap',
                'wallet_address' => $sender,
                'chain_id' => $chain->id,
                'from_token' => $trade['from_token'],
                'to_token' => $trade['to_token'],
                'from_amount' => $trade['from_amount'],
                'to_amount' => $trade['to_amount'],
                'fee_amount' => 0,
                'tx_hash' => $txHash,
                'status' => 'confirmed',
                'metadata' => [
                    'source' => 'chain',
                    'fee_model' => 'in_pool',
                    'fee_rate' => 0.3,
                    'fee_collected' => true,
                    'pair' => strtoupper(str_replace('-', '/', $pair->symbol)),
                    'side' => $trade['side'],
                    'price' => $trade['price'],
                    'block_number' => $blockNumber,
                    'log_index' => (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0')),
                    'pair_address' => strtolower((string) $pair->dex_pair_address),
                ],
            ]);
            $tx->created_at = $time;
            $tx->save();

            $this->addToCandle($pair, $time, $trade);

            return $tx;
        });

        return $result === self::RETRY ? $this->retryOrGiveUp($txHash, $log) : $result;
    }

    /**
     * นับรอบที่อ่าน log นี้ไม่สำเร็จ — เกินเพดาน (~30 นาทีของ dex:sync) ค่อยยอมข้าม
     * กัน log ที่เสียถาวร (เช่นเชนไม่มีธุรกรรมนั้นแล้ว) ตรึงเคอร์เซอร์ของพูลไว้ตลอดกาล
     */
    private function retryOrGiveUp(string $txHash, array $log): ?string
    {
        $key = "dex:swap-index:retry:{$txHash}:".strtolower((string) ($log['logIndex'] ?? '0x0'));
        $attempts = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $attempts, now()->addDay());

        if ($attempts > self::MAX_LOG_RETRIES) {
            Log::warning('DexSwapIndexer: อ่านข้อมูลประกอบของไม้นี้ไม่ได้ซ้ำหลายรอบ ข้ามไม้นี้', ['tx' => $txHash, 'attempts' => $attempts]);

            return null;
        }

        return self::RETRY;
    }

    /** เพดานรอบที่ยอมอ่าน log เดิมซ้ำเมื่อเชนตอบไม่ได้ (dex:sync รันทุกนาที) */
    private const MAX_LOG_RETRIES = 30;

    /**
     * ถอดจำนวนจาก data ของ Swap (amount0In, amount1In, amount0Out, amount1Out) แล้วจัดเป็นฝั่ง base/quote ของคู่
     *
     * @return array{side: string, from_token: string, to_token: string, from_amount: string, to_amount: string, price: string, base_amount: string, quote_amount: string}|null
     */
    public function decodeTrade(TradingPair $pair, string $data): ?array
    {
        $trade = $this->decodeTradeOrRetry($pair, $data);

        return is_array($trade) ? $trade : null;
    }

    /**
     * เหมือน decodeTrade แต่แยก "เชนตอบไม่ได้" (RETRY) ออกจาก "ไม่ใช่ไม้ซื้อขาย" (null)
     *
     * ใช้แค่ token0 ของพูล (แคช 1 ชม.) — เดิมเรียก pairInfo ซึ่งต้องอ่าน getReserves สดด้วย
     * อ่าน reserve ไม่ได้ครั้งเดียว = ไม้นั้นหายถาวร ทั้งที่การถอด event ไม่ได้ใช้ reserve เลย
     *
     * @return array<string,string>|string|null
     */
    private function decodeTradeOrRetry(TradingPair $pair, string $data): array|string|null
    {
        $words = TpixDexService::words($data);
        if (count($words) < 4) {
            return null;
        }
        [$a0In, $a1In, $a0Out, $a1Out] = array_map(fn ($w) => Wei::hexToInt('0x'.$w), array_slice($words, 0, 4));

        $base = $pair->baseToken;
        $quote = $pair->quoteToken;
        if (! $base || ! $quote) {
            return null;
        }

        $info = $this->dex->pairTokens((string) $pair->dex_pair_address);
        if (! $info) {
            return self::RETRY;
        }

        $baseOnchain = $this->dex->onchainAddress((string) $base->contract_address);
        $baseIsToken0 = $baseOnchain !== null && $baseOnchain === strtolower($info['token0']);

        $baseIn = $baseIsToken0 ? $a0In : $a1In;
        $baseOut = $baseIsToken0 ? $a0Out : $a1Out;
        $quoteIn = $baseIsToken0 ? $a1In : $a0In;
        $quoteOut = $baseIsToken0 ? $a1Out : $a0Out;

        $baseDec = (int) $base->decimals;
        $quoteDec = (int) $quote->decimals;
        $baseAddr = strtolower((string) $base->contract_address);
        $quoteAddr = strtolower((string) $quote->contract_address);

        if (bccomp($baseIn, '0', 0) > 0 && bccomp($quoteOut, '0', 0) > 0) {
            $side = 'sell';
            $baseAmount = Wei::format($baseIn, $baseDec);
            $quoteAmount = Wei::format($quoteOut, $quoteDec);
            [$fromToken, $toToken, $fromAmount, $toAmount] = [$baseAddr, $quoteAddr, $baseAmount, $quoteAmount];
        } elseif (bccomp($quoteIn, '0', 0) > 0 && bccomp($baseOut, '0', 0) > 0) {
            $side = 'buy';
            $baseAmount = Wei::format($baseOut, $baseDec);
            $quoteAmount = Wei::format($quoteIn, $quoteDec);
            [$fromToken, $toToken, $fromAmount, $toAmount] = [$quoteAddr, $baseAddr, $quoteAmount, $baseAmount];
        } else {
            // flash swap / เข้าออกทั้งสองฝั่ง — ไม่ใช่ไม้ซื้อขายธรรมดา ไม่ใส่ในรายการเทรด
            return null;
        }

        if (bccomp($baseAmount, '0', 18) <= 0) {
            return null;
        }

        return [
            'side' => $side,
            'from_token' => $fromToken,
            'to_token' => $toToken,
            'from_amount' => $fromAmount,
            'to_amount' => $toAmount,
            'price' => bcdiv($quoteAmount, $baseAmount, 18),
            'base_amount' => $baseAmount,
            'quote_amount' => $quoteAmount,
        ];
    }

    /**
     * ปริมาณซื้อขายจริงลงแท่ง 1 นาทีของเวลาไม้ — เดิมแท่งของคู่ DEX มีแต่ราคาจาก reserve ปริมาณเป็น 0 ตลอด
     * ราคาปิดไม่แตะ (dex:sync ตั้งจากราคากลางของพูลหลังไม้) แค่ขยายสูง/ต่ำให้ครอบราคาที่ไม้นี้ได้จริง
     */
    private function addToCandle(TradingPair $pair, Carbon $time, array $trade): void
    {
        $candle = Kline::firstOrNew([
            'trading_pair_id' => $pair->id,
            'interval' => '1m',
            'open_time' => $time->copy()->startOfMinute(),
        ]);

        $price = $trade['price'];
        if (! $candle->exists) {
            $candle->fill([
                'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price,
                'volume' => '0', 'quote_volume' => '0', 'trade_count' => 0,
            ]);
        } else {
            if (bccomp($price, (string) $candle->high, 18) > 0) {
                $candle->high = $price;
            }
            if (bccomp($price, (string) $candle->low, 18) < 0) {
                $candle->low = $price;
            }
        }

        $candle->volume = bcadd((string) ($candle->volume ?? '0'), $trade['base_amount'], 18);
        $candle->quote_volume = bcadd((string) ($candle->quote_volume ?? '0'), $trade['quote_amount'], 18);
        $candle->trade_count = (int) ($candle->trade_count ?? 0) + 1;
        $candle->save();

        // แท่ง/ตัวเลข 24 ชม. ถูกแคชไว้สั้นๆ — ล้างให้ไม้ใหม่โผล่ทันที
        Cache::forget("dex:ticker:{$pair->id}");
    }

    /** ผู้ทำธุรกรรมจริง (tx.from) — ผ่าน router แล้ว `to` ใน event อาจเป็น router ไม่ใช่ผู้ใช้ */
    private function senderOf(string $txHash): ?string
    {
        if (! isset($this->senders[$txHash])) {
            $from = $this->dex->transaction($txHash)['from'] ?? null;
            if (! is_string($from) || ! preg_match('/^0x[a-fA-F0-9]{40}$/', $from)) {
                return null;
            }
            $this->senders[$txHash] = strtolower($from);
        }

        return $this->senders[$txHash];
    }

    private function timeOf(int $block): ?int
    {
        if (! isset($this->blockTimes[$block])) {
            $ts = $this->dex->blockTimestamp($block);
            if ($ts === null) {
                return null;
            }
            $this->blockTimes[$block] = $ts;
        }

        return $this->blockTimes[$block];
    }

    /** @return Collection<string, TradingPair> คีย์ = ที่อยู่พูลตัวพิมพ์เล็ก */
    private function onchainPairs(Chain $chain): Collection
    {
        return TradingPair::with(['baseToken', 'quoteToken'])
            ->where('chain_id', $chain->id)
            ->where('execution_mode', 'onchain')
            ->whereNotNull('dex_pair_address')
            ->get()
            ->keyBy(fn (TradingPair $p) => strtolower((string) $p->dex_pair_address));
    }

    private function cursor(TradingPair $pair): int
    {
        $saved = (int) SiteSetting::get(self::CURSOR_GROUP, "swap_block_{$pair->id}", 0);

        return max($saved, (int) config('blockchain.tpix_dex_index_from_block', 2732000));
    }

    private function setCursor(TradingPair $pair, int $nextBlock): void
    {
        // ⚠️ คอลัมน์ type เป็น enum (string/text/boolean/number/json/image) — 'integer' ถูก DB ปฏิเสธ
        SiteSetting::set(self::CURSOR_GROUP, "swap_block_{$pair->id}", (string) $nextBlock, 'number');
    }

    private function tpixChain(): ?Chain
    {
        return $this->chains->resolve((int) config('blockchain.tpix_chain_id', 4289));
    }
}
