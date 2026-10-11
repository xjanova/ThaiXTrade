<?php

namespace App\Services\News;

use App\Models\Article;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\ContractRegistry;
use App\Services\Discord\DiscordLiveData;
use App\Services\SiteKnowledgeService;
use App\Services\TpixDexService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — เหตุการณ์จริงที่ควรเป็นข่าว + ข้อเท็จจริงสดที่ป้อนให้นักเขียน AI.
 *
 * ทำไมต้องมี: เดิมบอทเขียนบทความสุ่มหัวข้อจากรายการตายตัว ~28 หัวข้อ และ AI ไม่รู้เลยว่าเกิดอะไรขึ้นบ้าง
 * วันที่ DEX บนเชน TPIX เปิดจริง (2026-10-10) บอทจึงไม่มีทางเขียนถึง — เจ้าของ: "ดีพลอยเหรียญแล้วก็ควร
 * เขียนให้น่าตื่นเต้น บอทไม่เขียนเหรอ"
 *
 * แหล่งเหตุการณ์ = ของที่ระบบรู้แน่ ๆ ไม่ใช่การเดา:
 *   - ทะเบียนสัญญา (ContractRegistry) — สคริปต์ deploy ลงทะเบียนเอง + ต้องมีโค้ดอยู่บนเชนจริง
 *   - คู่เทรด DEX ที่มีสภาพคล่องและเหรียญผ่านการตรวจจากทีมงาน (ชุดเดียวกับที่บอท Discord ประกาศ)
 *
 * ข่าวต้องสด: เหตุการณ์เก่ากว่า LOOKBACK_HOURS ไม่เขียน (ไม่ขุดของเก่าทั้งหมดมาเขียนตอนเปิดระบบครั้งแรก)
 *
 * Developed by Xman Studio.
 */
class NewsEventCollector
{
    /** เหตุการณ์ที่เก่ากว่านี้ไม่นับเป็นข่าวแล้ว */
    public const LOOKBACK_HOURS = 72;

    /** คู่เทรดที่เห็นแล้ว — symbol => เวลาที่เห็นครั้งแรก (null = มีอยู่ก่อนเปิดระบบข่าว ไม่ใช่ของใหม่) */
    public const PAIRS_SEEN_KEY = 'news_pairs_seen';

    /**
     * สัญญาเดี่ยวที่ deploy แล้วเป็นข่าวได้ — ชุด DEX แยกเป็นข่าวเดียว (dexLaunch) ไม่ออก 4 ข่าว.
     * ValidatorKYC / factory ตัวเก่า ไม่ใช่สิ่งที่ผู้ใช้ทั่วไปจับต้องได้ จึงไม่อยู่ในรายการ.
     *
     * @var array<string, array{headline: string, facts: list<string>, label: string, path: string, link: string}>
     */
    private const CONTRACT_NEWS = [
        'token_factory_v2' => [
            'headline' => 'Token Factory is live on TPIX Chain — anyone can create their own token',
            'facts' => [
                'The TPIX Token Factory smart contract (TPIXTokenFactoryV2) has just been deployed on TPIX Chain and is live.',
                'Anyone can now create their own ERC-20 token on TPIX Chain from the Token Factory page, without writing code.',
                'Tokens created by users are not automatically trusted — only tokens verified by the TPIX team are listed as official trading pairs.',
            ],
            'label' => 'Token Factory',
            'path' => '/token-factory',
            'link' => 'Create a token',
        ],
        'nft_factory' => [
            'headline' => 'NFT Factory is live on TPIX Chain',
            'facts' => [
                'The TPIX NFT Factory smart contract has just been deployed on TPIX Chain and is live.',
                'Users can now create NFT collections on TPIX Chain.',
            ],
            'label' => 'NFT Factory',
            'path' => '/nft',
            'link' => 'NFT on TPIX',
        ],
        'masternode_registry' => [
            'headline' => 'Master node registry is live on TPIX Chain',
            'facts' => [
                'The master node registry smart contract (NodeRegistryV2) has just been deployed on TPIX Chain and is live.',
                'Master node operators can now register their node on-chain from the Master Node page.',
            ],
            'label' => 'Master node registry',
            'path' => '/masternode',
            'link' => 'Run a master node',
        ],
    ];

    public function __construct(
        private readonly ContractRegistry $contracts,
        private readonly DiscordLiveData $live,
        private readonly SiteKnowledgeService $knowledge,
    ) {}

    /**
     * เหตุการณ์ที่ยังไม่มีบทความครบทุกภาษาที่ขอ เรียงเก่า → ใหม่.
     *
     * บทความที่แอดมินลบทิ้ง (soft delete) นับว่าเขียนแล้ว — ลบเพราะไม่ชอบ ต้องไม่งอกกลับมาเอง
     *
     * @param  list<string>  $languages
     * @return list<array{key: string, occurred_at: CarbonInterface, headline: string, facts: list<string>, addresses: array<string, string>, links: array<string, string>, image: string, missing: list<string>}>
     */
    public function pending(array $languages): array
    {
        $events = array_merge($this->dexLaunch(), $this->contractLaunches(), $this->newPairs());
        $cutoff = now()->subHours(self::LOOKBACK_HOURS);

        $out = [];
        foreach ($events as $event) {
            if ($event['occurred_at']->lt($cutoff)) {
                continue;
            }

            $written = Article::withTrashed()->where('source_key', $event['key'])->pluck('language')->all();
            $missing = array_values(array_diff($languages, $written));
            if ($missing !== []) {
                $out[] = $event + ['missing' => $missing];
            }
        }

        usort($out, fn ($a, $b) => $a['occurred_at'] <=> $b['occurred_at']);

        return $out;
    }

    /**
     * ข้อเท็จจริงสดสำหรับทุกบทความ (ข่าวและบทความความรู้) — กัน AI เขียนว่า "เร็ว ๆ นี้จะเปิด DEX"
     * ทั้งที่เปิดไปแล้ว หรือเขียนว่าเปิดขายเหรียญอยู่ทั้งที่ยังไม่เปิด.
     */
    public function liveFacts(): string
    {
        $lines = ['## LIVE STATUS (read from the system just now — trust this over anything else)'];

        try {
            $network = $this->live->network();
            $lines[] = "- Add TPIX Chain to a wallet: chain ID {$network['chain_id']}, RPC {$network['rpc']}, explorer {$network['explorer']}";

            if ($this->contracts->dexReady()) {
                $pairs = $this->pairSymbols();
                $lines[] = '- TPIX DEX (our own AMM exchange on TPIX Chain) is LIVE — users can swap now at '.$this->url('/swap')
                    .($pairs !== [] ? '. Official pairs: '.implode(', ', $pairs) : '');
            } else {
                $lines[] = '- TPIX DEX on TPIX Chain is NOT live yet — do not tell readers they can trade on it.';
            }

            if ($this->contracts->isLive('token_factory_v2')) {
                $lines[] = '- Token Factory is live: anyone can create an ERC-20 token at '.$this->url('/token-factory');
            }
        } catch (\Throwable $e) {
            Log::warning('News: อ่านสถานะสดไม่สำเร็จ', ['error' => $e->getMessage()]);
            $lines[] = '- Live contract status unavailable right now — do not claim anything is live or not live.';
        }

        // การขายเหรียญ + บทความล่าสุด — ชุดเดียวกับผู้ช่วย AI บนเว็บ/Discord (ไม่ throw)
        $lines[] = $this->knowledge->liveFacts();

        return implode("\n", $lines);
    }

    // ── แหล่งเหตุการณ์ ──────────────────────────────────────────────────────────

    /** DEX ทั้งชุด (WTPIX · USDT · Factory · Router) ครบและมีโค้ดบนเชน = ข่าวใหญ่ข่าวเดียว */
    private function dexLaunch(): array
    {
        if (! $this->contracts->dexReady()) {
            return [];
        }

        $at = $this->registeredAt(ContractRegistry::DEX_KEYS);
        $router = $this->contracts->address('dex_router');
        if ($at === null || $router === null) {
            return []; // ที่อยู่มาจาก .env ล้วน = ตั้งไว้นานแล้ว ไม่รู้วันเปิด ไม่ใช่ข่าวสด
        }

        $pairs = $this->pairSymbols();
        $first = $this->live->dexPairs()->first()?->symbol;

        $facts = [
            'BREAKING: TPIX DEX — TPIX Chain\'s own decentralized exchange (an automated market maker in the Uniswap V2 style) — is now live on TPIX Chain mainnet (chain ID 4289).',
            'Four smart contracts were deployed and verified live on-chain: WTPIX (wrapped TPIX, the ERC-20 form of the native coin used inside the DEX), USDT on TPIX Chain, the DEX Factory (registry of liquidity pools) and the DEX Router (swaps and adding/removing liquidity).',
            'For the first time, TPIX can be traded on-chain on its own network instead of only through the token sale or other chains.',
            'Trading on TPIX Chain has zero gas fees for users.',
            'It is non-custodial: users trade from their own wallet (TPIX Wallet or another EVM wallet), funds never sit with the exchange.',
        ];
        $facts[] = $pairs !== []
            ? 'Trading pairs open right now: '.implode(', ', $pairs).'.'
            : 'Liquidity pools are being added — trading pairs will appear on the swap page as they open.';
        $facts[] = 'How to start: add TPIX Chain to your wallet (or use TPIX Wallet), connect it on tpix.online, open Swap or the trading page.';

        $links = ['Swap on TPIX DEX' => $this->url('/swap')];
        if ($first !== null) {
            $links['Trade '.str_replace('-', '/', $first)] = $this->url('/trade/'.rawurlencode($first));
        }

        return [[
            'key' => 'event:dex_live:'.strtolower($router),
            'occurred_at' => $at,
            'headline' => 'TPIX DEX is live on TPIX Chain — on-chain trading of TPIX has begun',
            'facts' => $facts,
            'addresses' => array_filter([
                'WTPIX (wrapped TPIX)' => $this->contracts->address('wtpix'),
                'USDT on TPIX Chain' => $this->contracts->address('usdt_tpix'),
                'DEX Factory' => $this->contracts->address('dex_factory'),
                'DEX Router' => $router,
            ]),
            'links' => $links,
            'image' => 'Grand launch of a decentralized crypto exchange, glowing trading pairs and token coins flowing between liquidity pools, fireworks of light, celebration, dark theme, cyan and gold accents, futuristic, cinematic, no text',
        ]];
    }

    /** สัญญาเดี่ยวที่เพิ่ง deploy (key ผูกกับที่อยู่ — deploy ตัวใหม่แทนตัวเดิม = ข่าวใหม่) */
    private function contractLaunches(): array
    {
        $out = [];
        foreach (self::CONTRACT_NEWS as $key => $news) {
            $address = $this->contracts->address($key);
            $at = $this->registeredAt([$key]);
            if ($address === null || $at === null || ! $this->contracts->isLive($key)) {
                continue;
            }

            $out[] = [
                'key' => "event:contract:{$key}:".strtolower($address),
                'occurred_at' => $at,
                'headline' => $news['headline'],
                'facts' => $news['facts'],
                'addresses' => [$news['label'] => $address],
                'links' => [$news['link'] => $this->url($news['path'])],
                'image' => "Launch of a new blockchain feature: {$news['label']}, glowing smart contract blocks, dark theme, cyan blue accent, futuristic, celebratory, no text",
            ];
        }

        return $out;
    }

    /**
     * คู่เทรดใหม่ที่ทีมงานตรวจแล้ว — รอบแรกจดชุดที่มีอยู่เป็น "ของเดิม" (ไม่ประกาศย้อนหลัง).
     * จดเวลาที่เห็นครั้งแรกไว้ เพื่อให้ข่าวหมดอายุตาม LOOKBACK_HOURS เหมือนเหตุการณ์อื่น.
     */
    private function newPairs(): array
    {
        $current = $this->live->dexPairs();
        $raw = SiteSetting::get('content', self::PAIRS_SEEN_KEY);
        $seen = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($seen)) {
            SiteSetting::set('content', self::PAIRS_SEEN_KEY, json_encode(
                $current->mapWithKeys(fn ($p) => [(string) $p->symbol => null])->all() ?: new \stdClass()
            ), 'json');

            return [];
        }

        $changed = false;
        $out = [];
        foreach ($current as $pair) {
            $symbol = (string) $pair->symbol;
            if (! array_key_exists($symbol, $seen)) {
                $seen[$symbol] = now()->toIso8601String();
                $changed = true;
            }
            if ($seen[$symbol] === null) {
                continue;
            }

            [$base, $quote] = explode('-', $symbol, 2);
            $token = Token::find($pair->base_token_id);
            // ชื่อเหรียญมาจากคนสร้าง — ตัดเหลือตัวอักษร/ตัวเลข/ช่องว่าง กันข้อความแทรกคำสั่งเข้า prompt
            $name = trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string) $token?->name) ?? '');
            $name = mb_substr($name, 0, 40);

            $out[] = [
                'key' => "event:pair:{$symbol}",
                'occurred_at' => Carbon::parse($seen[$symbol]),
                'headline' => "New listing on TPIX DEX: {$base}/{$quote}",
                'facts' => array_values(array_filter([
                    "A new trading pair has just opened on TPIX DEX (TPIX Chain): {$base}/{$quote}.",
                    $name !== '' && $name !== $base ? "{$base} is the token \"{$name}\"." : null,
                    "The {$base} token has been verified by the TPIX team and the pool has liquidity, so it can be swapped now.",
                    'A listing is not a recommendation to buy. Readers should always check the token contract address on the trading page before swapping.',
                ])),
                'addresses' => array_filter(["{$base} token" => $this->contractAddress((string) $token?->contract_address)]),
                'links' => ["Trade {$base}/{$quote}" => $this->url('/trade/'.rawurlencode($symbol))],
                'image' => 'New token listing on a decentralized exchange, a glowing new coin entering a liquidity pool, dark theme, cyan blue accent, futuristic, no text',
            ];
        }

        if ($changed) {
            SiteSetting::set('content', self::PAIRS_SEEN_KEY, json_encode($seen), 'json');
        }

        return $out;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * เวลาที่สคริปต์ deploy ลงทะเบียนสัญญาล่าสุดในชุดนี้ — null ถ้าไม่มีแถวในทะเบียน (ค่ามาจาก .env).
     *
     * @param  list<string>  $keys
     */
    private function registeredAt(array $keys): ?CarbonInterface
    {
        $at = SiteSetting::where('group', ContractRegistry::GROUP)->whereIn('key', $keys)->max('updated_at');

        return $at ? Carbon::parse($at) : null;
    }

    /** ที่อยู่สัญญาจริง — TPIX native (0x0) ไม่มีสัญญาให้ตรวจ จึงไม่ใส่ในรายการที่อยู่ทางการ */
    private function contractAddress(string $address): ?string
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) && strtolower($address) !== TpixDexService::ZERO
            ? $address
            : null;
    }

    /** @return list<string> เช่น ["TPIX/USDT"] */
    private function pairSymbols(): array
    {
        return $this->live->dexPairs()->map(fn (TradingPair $p) => str_replace('-', '/', (string) $p->symbol))->values()->all();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
