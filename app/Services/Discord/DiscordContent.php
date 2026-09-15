<?php

namespace App\Services\Discord;

use App\Models\Article;
use App\Services\SaleStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TPIX TRADE — หน้าตาข้อความที่บอทโพสต์ลง Discord.
 *
 * ทุกข้อความปิด allowed_mentions — เนื้อหามาจากฐานข้อมูล/AI ห้ามปิง @everyone ใส่สมาชิกทั้งเซิร์ฟเวอร์
 * payload ต้อง "เหมือนเดิมทุกครั้งถ้าข้อมูลไม่เปลี่ยน" (ไม่มีเวลาปัจจุบันปนใน payload)
 * เพราะผู้โพสต์เทียบ hash ของ payload เพื่อตัดสินว่าต้องแก้ข้อความเดิมไหม
 *
 * Developed by Xman Studio.
 */
class DiscordContent
{
    private const COLOR_BRAND = 0x3B82F6;

    private const COLOR_OPEN = 0x00C853;

    private const COLOR_PREPARING = 0xF5A623;

    private const COLOR_CLOSED = 0x8A8F98;

    private const COLOR_REPORT = 0xFF1744;

    /**
     * ข้อความในห้องสมาชิก: ภาษาอังกฤษเป็นหลัก ตามด้วยภาษาไทย (เจ้าของ 2026-09-15: "ให้มีภาษาอังกฤษด้วย เป็นภาษาหลัก")
     * การ์ดรายงาน/ห้องแอดมินยังเป็นไทย — คนอ่านคือทีมงาน.
     */
    private const THAI = "\n\n🇹🇭 ";

    /** สถานะเฟสภาษาอังกฤษ (คู่กับ status_label ภาษาไทยของ SaleStatusService) */
    private const PHASE_STATUS_EN = [
        'open' => '🟢 Open now',
        'sold_out' => 'Sold out',
        'ended' => 'Phase ended',
        'waiting' => 'Up next',
        'upcoming' => 'Not open yet',
    ];

    /** ปุ่มบนการ์ดรายงาน: action => [ป้าย, สไตล์ปุ่ม] (1 น้ำเงิน · 2 เทา · 3 เขียว · 4 แดง) */
    public const REPORT_ACTIONS = [
        'timeout' => ['🔇 ปิดเสียง 24 ชม.', 2],
        'kick' => ['👢 เตะ', 4],
        'ban' => ['🔨 แบน + ลบข้อความ 24 ชม.', 4],
        'delete' => ['🗑️ ลบข้อความนี้', 2],
        'dismiss' => ['✅ ไม่ผิด ปิดเรื่อง', 3],
    ];

    public function __construct(
        private readonly SaleStatusService $sale,
        private readonly DiscordSettings $settings,
        private readonly DiscordLiveData $live,
    ) {}

    /**
     * สถานะการขาย — ข้อความไทยมาจาก SaleStatusService (ตัวเดียวกับหน้าเว็บ/ผู้ช่วย AI)
     * ภาษาอังกฤษประกอบจากข้อมูลดิบของชุดเดียวกัน จึงบอกสถานะตรงกันเสมอ.
     */
    public function saleStatus(): array
    {
        $s = $this->sale->snapshot();
        [$enHeadline, $enDetail] = $this->saleEnglish($s);

        $fields = [];
        foreach ($s['phases'] as $phase) {
            $fields[] = [
                'name' => $phase['name'].' · '.$phase['price'],
                'value' => implode("\n", array_filter([
                    trim(self::PHASE_STATUS_EN[$phase['status'] ?? ''] ?? '').' · '.$phase['status_label'],
                    $this->phaseWindowEnglish($phase),
                    $phase['window'],
                    'Sold · ขายแล้ว '.$phase['sold_text'],
                ])),
                'inline' => false,
            ];
        }

        if ($s['state'] === SaleStatusService::OPEN) {
            $fields[] = ['name' => 'Payment · ช่องทางชำระ', 'value' => $this->paymentMethods($s['payment_methods']), 'inline' => false];
        }

        return $this->message(embed: [
            'title' => '💰 TPIX Token Sale · การขายเหรียญ TPIX',
            'url' => $s['url'],
            'description' => "**{$enHeadline}**\n{$enDetail}".self::THAI."**{$s['headline']}**\n{$s['detail']}",
            'color' => match ($s['state']) {
                SaleStatusService::OPEN => self::COLOR_OPEN,
                SaleStatusService::CLOSED => self::COLOR_CLOSED,
                default => self::COLOR_PREPARING,
            },
            'fields' => array_slice($fields, 0, 25),
            'footer' => ['text' => 'Auto-updated from tpix.online · Crypto is high-risk, do your own research · คริปโตมีความเสี่ยง ศึกษาข้อมูลก่อนตัดสินใจ'],
        ], button: $s['state'] === SaleStatusService::OPEN ? ['Buy on the website · ซื้อที่หน้าเว็บ', $s['url']] : ['Sale details · รายละเอียดการขาย', $s['url']]);
    }

    /**
     * บทความจากเว็บ — เนื้อหาเป็นภาษาของบทความนั้น (เว็บเขียนแยกภาษา)
     * บทความอังกฤษที่มีคู่ภาษาไทย (สร้างจากหัวข้อเดียวกัน) โพสต์เป็นข้อความเดียว มีปุ่มอ่านภาษาไทยให้.
     */
    public function article(Article $article, ?Article $thai = null): array
    {
        $url = $this->url('/blog/'.$article->slug);
        $summary = trim((string) ($article->summary ?: Str::limit(strip_tags((string) $article->content), 280)));
        $image = $this->absolute((string) $article->cover_image);
        $isThai = $article->language === 'th';

        $buttons = [[$isThai ? 'Read (Thai) · อ่านต่อ' : 'Read · อ่าน', $url]];
        if ($thai !== null) {
            $buttons[] = ['อ่านภาษาไทย', $this->url('/blog/'.$thai->slug)];
        }

        return $this->message(embed: array_filter([
            'title' => Str::limit((string) $article->title, 250),
            'url' => $url,
            'description' => ($isThai ? "📰 *New article (in Thai) · บทความใหม่*\n\n" : '').Str::limit($summary, 600),
            'color' => self::COLOR_BRAND,
            'image' => $image !== null ? ['url' => $image] : null,
            'footer' => ['text' => '📰 Article from tpix.online · บทความ'.($article->category ? ' · '.$article->category : '')],
            'timestamp' => $article->published_at?->toIso8601String(),
        ]), buttons: $buttons);
    }

    /** @param  array{code: string, file: string, title: string, part: string}  $video */
    public function video(array $video): array
    {
        $file = $this->url('/videos/whitepaper/'.rawurlencode($video['file']));

        // ลิงก์ mp4 ตรง ๆ = Discord เล่นวิดีโอในห้องได้เอง (ไฟล์ใหญ่เกินเพดานแนบไฟล์)
        return $this->message(
            content: "🎬 **{$video['part']}**\n_{$video['title']}_ — TPIX Whitepaper video series · ซีรีส์วิดีโอ Whitepaper\n{$file}",
            button: ['Watch with notes · ดูพร้อมเนื้อหาประกอบ', $this->url('/whitepaper')],
        );
    }

    public function rules(): array
    {
        return $this->message(embed: [
            'title' => '📜 กฎของชุมชน TPIX',
            'description' => Str::limit($this->settings->rulesText(), 4000),
            'color' => self::COLOR_BRAND,
            'footer' => ['text' => 'อยู่ในเซิร์ฟเวอร์นี้ = ยอมรับกฎทั้งหมด'],
        ]);
    }

    public function whitepaper(): array
    {
        return $this->message(embed: [
            'title' => '📘 Whitepaper — TPIX Chain & TPIX TRADE',
            'url' => $this->url('/whitepaper'),
            'description' => implode("\n", [
                'The full project document — TPIX Chain, tokenomics, the token sale, master nodes, AI TRADE and the roadmap.',
                '',
                '• Read online (with 9 video episodes): '.$this->url('/whitepaper'),
                '• Download PDF (English): '.$this->url('/whitepaper/download?lang=en'),
                '• Download PDF (Thai · ภาษาไทย): '.$this->url('/whitepaper/download?lang=th'),
            ]).self::THAI.'เอกสารฉบับเต็มของโปรเจกต์ — เชน TPIX, โทเคโนมิกส์, การขายเหรียญ, มาสเตอร์โหนด, AI TRADE และแผนงาน · อ่านออนไลน์หรือดาวน์โหลด PDF ภาษาไทยได้จากลิงก์ด้านบน',
            'color' => self::COLOR_BRAND,
        ], button: ['Open Whitepaper · เปิด Whitepaper', $this->url('/whitepaper')]);
    }

    public function askHint(): array
    {
        return $this->message(embed: [
            'title' => '🤖 Ask the TPIX AI assistant · ถามผู้ช่วย AI',
            'description' => implode("\n", [
                'Type **/ask** followed by your question, e.g. `/ask Is the token sale open?`',
                'Answers come from tpix.online — the same assistant as on the website.',
                'Live data: **/sale** · **/price** · **/chain** · **/links**',
            ]).self::THAI.implode("\n", [
                'พิมพ์ **/ถาม** ตามด้วยคำถาม เช่น `/ถาม ตอนนี้เปิดขายเหรียญหรือยัง`',
                'ข้อมูลสด: **/ขายเหรียญ** · **/ราคา** · **/เชน** · **/ลิงก์**',
                '',
                '_AI answers can be wrong — check important details on the website. Staff will never DM you for your seed phrase. · คำตอบของ AI อาจผิดพลาดได้ ทีมงานไม่มีวัน DM ขอ seed phrase_',
            ]),
            'color' => self::COLOR_BRAND,
        ]);
    }

    /**
     * ข้อความตอบ /ถาม
     *
     * ⚠️ คำตอบโพสต์ในห้องให้ทุกคนเห็น ในนามบอททางการ — ลิงก์ที่ไม่ใช่ของเราถูกซ่อนทั้งในคำถามที่พิมพ์ซ้ำ
     *    และในคำตอบ ไม่งั้นใครก็ใส่ลิงก์ฟิชชิงในคำถาม (หรือหลอก AI ให้ตอบลิงก์ปลอม) แล้วให้บอทกระจายให้
     */
    public function answer(string $question, string $answer, ?string $navigation): array
    {
        $question = $this->onlyOurLinks(str_replace(["\n", '*'], [' ', ''], $question));
        $head = '**❓ '.Str::limit($question, 200).'**';
        $body = Str::limit($this->onlyOurLinks(trim($answer)), 1900 - mb_strlen($head));

        $button = null;
        if ($navigation !== null && preg_match('#^/[a-z0-9\-/]*$#i', $navigation)) {
            $button = ['Open related page · เปิดหน้าที่เกี่ยวข้อง', $this->url($navigation)];
        }

        return $this->message(content: $head."\n\n".$body, button: $button);
    }

    // ── ข้อมูลสด (การ์ดประจำห้อง + คำสั่ง) ────────────────────────────────────

    /**
     * การ์ดราคาประจำห้อง — null = อ่านราคาไม่ได้ตอนนี้ ปล่อยการ์ดเดิมไว้ (ไม่ทับด้วยข้อความ error)
     * ตัวเลขปัดก่อนใส่ payload: ราคาขยับเศษเล็กน้อยไม่ต้องแก้ข้อความทุกรอบ.
     */
    public function priceCard(): ?array
    {
        $price = $this->live->price();

        return $price === null ? null : $this->message(embed: $this->priceEmbed($price), button: ['TPIX/USDT chart · เปิดกราฟ', $this->url('/trade/TPIX-USDT')]);
    }

    /** คำตอบ /ราคา — มีเวลาของข้อมูลกำกับ (ไม่ต้องคงที่แบบการ์ดประจำห้อง) */
    public function priceReply(): array
    {
        $price = $this->live->price();

        if ($price === null) {
            return $this->message(content: "⚠️ Can't read the TPIX price right now — try again or check ".$this->url('/trade/TPIX-USDT')."\nตอนนี้อ่านราคา TPIX ไม่ได้ ลองใหม่อีกครั้ง");
        }

        return $this->message(embed: $this->priceEmbed($price) + ['timestamp' => now()->toIso8601String()], button: ['TPIX/USDT chart · เปิดกราฟ', $this->url('/trade/TPIX-USDT')]);
    }

    public function chainStatus(): array
    {
        $chain = $this->live->chain();

        if (! $chain['connected']) {
            return $this->message(embed: [
                'title' => '⛓️ TPIX Chain status · สถานะเครือข่าย',
                'url' => $chain['explorer'],
                'description' => "⚠️ Can't read the chain right now — try again or check the Explorer.\nตอนนี้อ่านข้อมูลจากเชนไม่ได้ — ลองใหม่อีกครั้ง หรือดูที่ Explorer",
                'color' => self::COLOR_PREPARING,
            ], button: ['Open Explorer · เปิด Explorer', $chain['explorer']]);
        }

        // แคชของหน้าเว็บเก็บบล็อกล่าสุดได้ถึง 1 นาที — เกิน 3 นาทีค่อยถือว่าเชนช้าผิดปกติ
        $age = $chain['last_block_at'] !== null ? now()->timestamp - $chain['last_block_at'] : null;
        $headline = $age !== null && $age > 180
            ? "🟠 Last block <t:{$chain['last_block_at']}:R> — the chain may be delayed · เชนอาจล่าช้า"
            : '🟢 Network is running · เชนทำงานปกติ'.($chain['last_block_at'] !== null ? " · last block <t:{$chain['last_block_at']}:R>" : '');

        $fields = [
            ['name' => 'Latest block · บล็อกล่าสุด', 'value' => number_format($chain['block_height']), 'inline' => true],
            ['name' => 'Active validators', 'value' => (string) $chain['validators'], 'inline' => true],
            ['name' => 'Chain ID', 'value' => (string) $chain['chain_id'], 'inline' => true],
        ];

        if ($chain['masternodes'] !== null) {
            $n = $chain['masternodes'];
            $tiers = array_filter([
                'Validator' => (int) ($n['validator_nodes'] ?? 0),
                'Guardian' => (int) ($n['guardian_nodes'] ?? 0),
                'Sentinel' => (int) ($n['sentinel_nodes'] ?? 0),
                'Light' => (int) ($n['light_nodes'] ?? 0),
            ]);
            $breakdown = collect($tiers)->map(fn ($count, $tier) => "{$tier} {$count}")->implode(' · ');
            $fields[] = ['name' => 'Active master nodes · มาสเตอร์โหนด', 'value' => number_format((int) ($n['total_nodes'] ?? 0)).($breakdown !== '' ? " ({$breakdown})" : ''), 'inline' => false];
        }

        $fields[] = ['name' => 'RPC', 'value' => "`{$chain['rpc']}`", 'inline' => false];

        return $this->message(embed: [
            'title' => '⛓️ TPIX Chain status · สถานะเครือข่าย',
            'url' => $chain['explorer'],
            'description' => $headline,
            'color' => self::COLOR_OPEN,
            'fields' => $fields,
            'footer' => ['text' => 'Live on-chain data, same as the website · ข้อมูลจากเชนจริง ชุดเดียวกับหน้าเว็บ'],
            'timestamp' => now()->toIso8601String(),
        ], button: ['Open Explorer · เปิด Explorer', $chain['explorer']]);
    }

    public function officialLinks(): array
    {
        $links = $this->live->links();

        $fields = [[
            'name' => 'TPIX Chain network (add to your wallet) · เพิ่มเครือข่ายในวอลเล็ต',
            'value' => "Chain ID `{$links['chain_id']}` · Symbol `TPIX`\nRPC `{$links['rpc']}`\nExplorer {$links['explorer']}",
            'inline' => false,
        ]];

        if ($links['contracts'] !== []) {
            $fields[] = [
                'name' => 'Contracts on TPIX Chain (verified on-chain) · ที่อยู่สัญญาที่มีอยู่จริง',
                'value' => collect($links['contracts'])->map(fn ($c) => "{$c[0]}\n`{$c[1]}`")->implode("\n"),
                'inline' => false,
            ];
        }

        if ($links['bsc_wtpix'] !== null) {
            $fields[] = ['name' => 'wTPIX on BNB Smart Chain · wTPIX บน BSC', 'value' => "`{$links['bsc_wtpix']}`", 'inline' => false];
        }

        $fields[] = [
            'name' => '🚨 Stay safe · กันมิจฉาชีพ',
            'value' => "Any other link is fake. Staff never DM you first and never ask for your seed phrase, private key or an upfront payment.\nลิงก์อื่นนอกจากนี้ถือว่าปลอม · ทีมงานไม่ทัก DM ไปก่อน · ไม่มีวันขอ seed phrase / private key / ให้โอนเงินก่อน",
            'inline' => false,
        ];

        return $this->message(embed: [
            'title' => '🔗 Official TPIX links · ลิงก์ทางการ',
            'description' => collect($links['pages'])->map(fn ($p) => "{$p[0]} — {$p[1]}")->implode("\n"),
            'color' => self::COLOR_BRAND,
            'fields' => $fields,
            'footer' => ['text' => 'Type /links anytime · พิมพ์ /ลิงก์ ได้ทุกเมื่อ — pulled live from the real system'],
        ], button: ['Open tpix.online · เปิดเว็บไซต์', $this->url('/')]);
    }

    /** @param  array{product: string, label: string, label_th?: string, version: string, name: string, notes: string, published_at: ?string}  $release */
    public function release(array $release): array
    {
        $notes = trim($this->onlyOurLinks($this->releaseNotes($release['notes'])));
        $thai = ($release['label_th'] ?? $release['label'])." เวอร์ชัน {$release['version']} ออกแล้ว — ดาวน์โหลดได้ที่ปุ่มด้านล่าง";

        return $this->message(embed: array_filter([
            'title' => Str::limit("🚀 {$release['label']} — version {$release['version']}", 250),
            'url' => $this->url('/download'),
            'description' => Str::limit($notes !== '' ? $notes : 'A new version is ready to download.', 1500).self::THAI.$thai,
            'color' => self::COLOR_BRAND,
            'footer' => ['text' => 'Download only from the official website — files from elsewhere may carry wallet-stealing malware · ดาวน์โหลดจากเว็บทางการเท่านั้น'],
            'timestamp' => $release['published_at'],
        ]), button: ['Download · ดาวน์โหลด', $this->url('/download')]);
    }

    public function newPair(string $symbol): array
    {
        $url = $this->url('/trade/'.rawurlencode($symbol));

        return $this->message(embed: [
            'title' => '🆕 New pair on TPIX DEX · คู่เทรดใหม่: '.str_replace('-', '/', $symbol),
            'url' => $url,
            'description' => implode("\n", [
                'The token is team-verified and now has liquidity — you can swap it on TPIX Chain.',
                '⚠️ A listing is not a recommendation to buy. Check the contract address on the trading page before every swap. Crypto is high-risk.',
            ]).self::THAI.implode("\n", [
                'เหรียญผ่านการตรวจจากทีมงานและมีสภาพคล่องแล้ว — สวอปบนเชน TPIX ได้',
                '⚠️ การมีคู่เทรดไม่ใช่คำแนะนำให้ซื้อ ตรวจที่อยู่สัญญาในหน้าเทรดก่อนสวอปทุกครั้ง · คริปโตมีความเสี่ยงสูง',
            ]),
            'color' => self::COLOR_OPEN,
        ], button: ['Open trading page · เปิดหน้าเทรด', $url]);
    }

    /** รายการคู่เทรดประจำห้อง — ไม่มีราคาในการ์ด (ราคาขยับทุกนาที การ์ดนี้แก้เฉพาะตอนคู่เปลี่ยน) */
    public function pairsCard(): array
    {
        $pairs = $this->live->dexPairs();

        $lines = $pairs->map(fn ($p) => '• ['.str_replace('-', '/', (string) $p->symbol).']('.$this->url('/trade/'.rawurlencode((string) $p->symbol)).')')->implode("\n");

        return $this->message(embed: [
            'title' => '📋 Trading pairs on TPIX DEX · คู่เทรดบน TPIX DEX',
            'url' => $this->url('/swap'),
            'description' => $lines !== ''
                ? Str::limit($lines, 3200)."\n\n_Only team-verified tokens are listed — anyone can create a token on the chain, so be extra careful with anything not on this list. · แสดงเฉพาะเหรียญที่ทีมงานตรวจแล้ว เหรียญที่ไม่อยู่ในรายการนี้ให้ระวังเป็นพิเศษ_"
                : "No team-verified pairs yet — new pairs will show up here automatically.\nยังไม่มีคู่เทรดของเหรียญที่ทีมงานตรวจแล้ว — คู่ใหม่จะขึ้นในห้องนี้อัตโนมัติ",
            'color' => self::COLOR_BRAND,
            'footer' => ['text' => 'Auto-updated from on-chain pools · อัปเดตอัตโนมัติจากพูลบนเชน · '.$pairs->count().' pairs'],
        ], button: ['Open swap · เปิดหน้าสวอป', $this->url('/swap')]);
    }

    // ── คู่มือประจำห้อง ────────────────────────────────────────────────────────

    public function guideHelp(): array
    {
        $explorer = $this->live->network()['explorer'];

        return $this->message(embed: [
            'title' => '🆘 Need help? Read this first · ขอความช่วยเหลือ',
            'description' => implode("\n", [
                '**Get answers 24/7**',
                '• `/ask` — AI assistant answering from the website',
                '• `/price` · `/chain` · `/sale` · `/links` — live data from the real system',
                '',
                '**Reporting a problem here? Include:**',
                '1) What you use — website / TPIX TRADE app / TPIX Wallet / master node — and its version',
                '2) What you did and what happened (screenshots welcome)',
                "3) For transfers, the transaction hash (tx hash) — look it up on {$explorer}",
                '',
                '🚨 **Never post:** seed phrase / private key / passwords / OTP codes',
                'Staff will never ask for them and never DM you first — anyone "helping" you in DMs is a scammer.',
                '🚩 Suspicious message? **Right-click it → Apps → Report to admins**',
            ]).self::THAI.implode("\n", [
                '• ถามได้ 24 ชม. ด้วย `/ถาม` · ข้อมูลสด `/ราคา` `/เชน` `/ขายเหรียญ` `/ลิงก์`',
                '• แจ้งปัญหา: บอกว่าใช้อะไร (เว็บ/แอป/วอลเล็ต/มาสเตอร์โหนด) + เวอร์ชัน · ทำอะไรแล้วเกิดอะไรขึ้น · ถ้าเกี่ยวกับการโอนใส่ tx hash',
                '• 🚨 ห้ามโพสต์ seed phrase / private key / รหัสผ่าน / OTP — ทีมงานไม่มีวันขอ และไม่ทัก DM ไปก่อน',
                '• 🚩 เจอข้อความน่าสงสัย: คลิกขวาที่ข้อความ → Apps → รายงานให้แอดมิน',
            ]),
            'color' => self::COLOR_BRAND,
        ]);
    }

    public function guideDex(): array
    {
        $links = $this->live->network();

        return $this->message(embed: [
            'title' => '🔄 Using TPIX DEX · วิธีสวอปบนเชน TPIX',
            'description' => implode("\n", [
                '**Get started in 3 steps**',
                '1) Install a wallet (TPIX Wallet or MetaMask) — '.$this->url('/download'),
                "2) Add TPIX Chain: Chain ID `{$links['chain_id']}` · RPC `{$links['rpc']}` · Symbol `TPIX`",
                '3) Open '.$this->url('/swap').', connect your wallet, pick the tokens and swap',
                '',
                '**Stay safe**',
                '• Always check the token contract address — type `/links` for the official ones',
                '• Anyone can create a token on the chain; copycat names do exist',
                "• Swap failing? Raise slippage a little at a time — don't set it higher than you need",
                '',
                '**Stuck?** Post here with the transaction hash (tx hash) — never post your seed phrase or private key.',
            ]).self::THAI.implode("\n", [
                '1) ติดตั้งวอลเล็ต (TPIX Wallet หรือ MetaMask) · 2) เพิ่มเครือข่าย TPIX Chain ตามค่าด้านบน · 3) เปิดหน้าสวอป เชื่อมวอลเล็ต แล้วกดสวอป',
                '• ตรวจที่อยู่สัญญาเหรียญทุกครั้ง (พิมพ์ `/ลิงก์`) · ใครก็สร้างเหรียญได้ เหรียญชื่อเลียนแบบมีจริง',
                '• สวอปไม่ผ่าน ค่อย ๆ เพิ่ม slippage · ติดปัญหาโพสต์ tx hash ในห้องนี้ ห้ามโพสต์ seed phrase',
            ]),
            'color' => self::COLOR_BRAND,
        ], button: ['Open swap · เปิดหน้าสวอป', $this->url('/swap')]);
    }

    public function guideBugs(): array
    {
        return $this->message(embed: [
            'title' => '🐞 Bug reports — copy this form · แบบฟอร์มแจ้งบั๊ก',
            'description' => implode("\n", [
                '```',
                'App / page (แอป/หน้าเว็บ):  website trading page / TPIX TRADE app / TPIX Wallet / master node',
                'Version / device (เวอร์ชัน/อุปกรณ์):  e.g. 1.2.3 · Android 14 · Chrome',
                'Steps (ขั้นตอน):  1) ... 2) ... 3) ...',
                'Expected (ผลที่คาดไว้):',
                'Actual (ผลที่เกิดจริง):  screenshots / video welcome',
                '```',
                '⚠️ Before attaching screenshots, make sure no seed phrase, private key or account details are visible.',
                '🔒 Found a security hole? Do not post it publicly — message a server admin directly.',
            ]).self::THAI.implode("\n", [
                '⚠️ ก่อนแนบภาพ ตรวจว่าไม่มี seed phrase / private key / ข้อมูลบัญชี',
                '🔒 เจอช่องโหว่ด้านความปลอดภัย อย่าโพสต์ในห้องสาธารณะ — ทักแอดมินของเซิร์ฟเวอร์โดยตรง',
            ]),
            'color' => self::COLOR_BRAND,
        ]);
    }

    public function guideIdeas(): array
    {
        return $this->message(embed: [
            'title' => '💡 Ideas & feature requests · เสนอไอเดีย',
            'description' => implode("\n", [
                '```',
                'Idea (ไอเดีย):  one-line summary',
                'Problem it solves (แก้ปัญหาอะไร):',
                'Who benefits (ใครได้ประโยชน์):',
                'Example / mockup (ตัวอย่าง):  optional',
                '```',
                'React 👍 on the ideas you want — it shows the team what people need most.',
                'One idea per post keeps the discussion easy to follow.',
            ]).self::THAI.'กด 👍 ใต้ไอเดียที่อยากได้ ช่วยให้ทีมงานเห็นว่าไอเดียไหนคนต้องการมากที่สุด · หนึ่งโพสต์ต่อหนึ่งไอเดีย',
            'color' => self::COLOR_BRAND,
        ]);
    }

    public function guideIntro(): array
    {
        return $this->message(embed: [
            'title' => '👋 Introduce yourself · แนะนำตัวกันหน่อย',
            'description' => implode("\n", [
                '```',
                'Nickname (ชื่อเล่น):',
                'Where you are (อยู่ที่ไหน):  city / country',
                'Interested in (สนใจเรื่องไหน):  trading · master nodes · DEX · token factory · other',
                'How you found TPIX (รู้จัก TPIX จากที่ไหน):',
                '```',
                "⚠️ Don't share your phone number, address, wallet details or seed phrase — scammers harvest intro channels.",
            ]).self::THAI.'⚠️ อย่าใส่เบอร์โทร ที่อยู่ ข้อมูลกระเป๋า หรือ seed phrase — มิจฉาชีพชอบเก็บข้อมูลจากห้องแนะนำตัว',
            'color' => self::COLOR_BRAND,
        ]);
    }

    // ── รายงานข้อความ (คลิกขวา → Apps → รายงานให้แอดมิน) ──────────────────────

    /**
     * การ์ดรายงานในห้องแอดมิน — เนื้อหาอยู่ในกล่องโค้ด ลิงก์ฟิชชิงในข้อความจึงกดไม่ได้.
     *
     * @param  array{guild_id: string, channel_id: string, message_id: string, author_id: string, author_name: string, content: string, attachments: int, reporter_id: string, score: int}  $r
     */
    public function report(array $r): array
    {
        $body = trim(str_replace('```', 'ˋˋˋ', $r['content']));
        $body = $body !== '' ? '```'."\n".Str::limit($body, 1500)."\n".'```' : '_(ไม่มีข้อความ)_';
        if ($r['attachments'] > 0) {
            $body .= "\n📎 มีไฟล์แนบ {$r['attachments']} ไฟล์ — เปิดข้อความเพื่อดู";
        }

        $payload = $this->message(embed: [
            'title' => '🚩 สมาชิกรายงานข้อความ',
            'url' => "https://discord.com/channels/{$r['guild_id']}/{$r['channel_id']}/{$r['message_id']}",
            'description' => $body,
            'color' => self::COLOR_REPORT,
            'fields' => [
                // ชื่อที่แสดงตั้งเองได้ทุกตัวอักษร — ตัดสัญลักษณ์ markdown ออก ไม่งั้นตั้งชื่อเป็น [ยืนยัน](ลิงก์ปลอม) แล้วกดได้ในห้องแอดมิน
                ['name' => 'ผู้เขียน', 'value' => "<@{$r['author_id']}> · ".Str::limit(preg_replace('/[\[\]()<>*_`~|\\\\#@]/u', '', $r['author_name']) ?? '', 60)."\n`{$r['author_id']}`", 'inline' => true],
                ['name' => 'ห้อง', 'value' => "<#{$r['channel_id']}>", 'inline' => true],
                ['name' => 'ผู้รายงาน', 'value' => "<@{$r['reporter_id']}>", 'inline' => true],
                ['name' => 'คะแนนความผิด 7 วัน', 'value' => (string) $r['score'], 'inline' => true],
            ],
            'footer' => ['text' => 'กดปุ่มด้านล่างเพื่อตัดสิน — บอทไม่ลงโทษทีมงาน/เจ้าของเซิร์ฟเวอร์'],
        ]);

        $payload['components'] = self::reportButtons(array_keys(self::REPORT_ACTIONS), $r['author_id'], $r['channel_id'], $r['message_id']);

        return $payload;
    }

    /**
     * แถวปุ่มบนการ์ดรายงาน — custom_id = mod:{action}:{ผู้เขียน}:{ห้อง}:{ข้อความ} (ยาวสุด ~75 ตัว ไม่เกิน 100).
     *
     * @param  list<string>  $actions
     * @return list<array<string, mixed>>
     */
    public static function reportButtons(array $actions, string $authorId, string $channelId, string $messageId): array
    {
        $buttons = [];
        foreach ($actions as $action) {
            if (! isset(self::REPORT_ACTIONS[$action])) {
                continue;
            }
            [$label, $style] = self::REPORT_ACTIONS[$action];
            $buttons[] = ['type' => 2, 'style' => $style, 'label' => $label, 'custom_id' => "mod:{$action}:{$authorId}:{$channelId}:{$messageId}"];
        }

        // แถวละไม่เกิน 5 ปุ่ม (ข้อจำกัดของ Discord)
        return array_map(fn ($row) => ['type' => 1, 'components' => $row], array_chunk($buttons, 3));
    }

    /** ถามเป็นภาษาไทย = ตอบไทย ไม่งั้นดูภาษาของแอป Discord ผู้ถาม (/ถาม และหน้าทดสอบในหลังบ้านใช้ร่วมกัน) */
    public static function languageOf(string $question, string $locale = ''): string
    {
        if (preg_match('/\p{Thai}/u', $question)) {
            return 'th';
        }

        return str_starts_with(strtolower($locale), 'th') ? 'th' : 'en';
    }

    /** hash ของ payload — ใช้ตัดสินว่าต้องแก้ข้อความเดิมไหม */
    public static function hash(array $payload): string
    {
        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * การ์ดราคา — บอกตรง ๆ ว่าราคามาจากไหน
     * source=admin คือยังไม่มีตลาดจริง: สูง/ต่ำ 24 ชม. ของ API เป็นแค่ ±2% ที่คำนวณขึ้น จึงไม่แสดง.
     *
     * @param  array<string, mixed>  $p
     */
    private function priceEmbed(array $p): array
    {
        $market = in_array($p['source'] ?? '', ['dex', 'trades'], true);
        $fields = [];

        if ($market) {
            $change = round((float) ($p['change_24h'] ?? 0), 2);
            $fields[] = ['name' => '24h change · 24 ชม.', 'value' => ($change > 0 ? '🟢 +' : ($change < 0 ? '🔴 ' : '⚪ ')).number_format($change, 2).'%', 'inline' => true];
            $fields[] = ['name' => '24h high / low · สูง/ต่ำ', 'value' => $this->usd((float) ($p['high_24h'] ?? 0)).' / '.$this->usd((float) ($p['low_24h'] ?? 0)), 'inline' => true];
        }

        // อ่านอุปทานจากเชนไม่ได้ API ให้ 0 มา — "มูลค่า $0.00" ในนามบอททางการชวนเข้าใจผิด ไม่แสดงดีกว่า (เจอจริง 2026-09-14)
        if ((float) ($p['market_cap'] ?? 0) > 0) {
            $fields[] = ['name' => 'Market cap · มูลค่าตามราคาตลาด', 'value' => '$'.$this->compact((float) $p['market_cap']), 'inline' => true];
        }
        if ((float) ($p['circulating_supply'] ?? 0) > 0) {
            $fields[] = ['name' => 'Circulating supply · อุปทานหมุนเวียน', 'value' => $this->compact((float) $p['circulating_supply']).' TPIX', 'inline' => true];
        }

        return [
            'title' => '📈 TPIX Price · ราคา TPIX',
            'url' => $this->url('/trade/TPIX-USDT'),
            'description' => '## '.$this->usd((float) ($p['price'] ?? 0)),
            'color' => self::COLOR_BRAND,
            'fields' => $fields,
            'footer' => ['text' => match ($p['source'] ?? '') {
                'dex' => 'Price from the TPIX DEX pool (swappable) · ราคาจากพูลบน TPIX DEX',
                'trades' => 'Last trade on TPIX TRADE · ราคาซื้อขายล่าสุด',
                default => 'Reference price — no live market yet · ราคาอ้างอิง ยังไม่มีการซื้อขายจริงบนตลาด',
            }.' · Not financial advice · ไม่ใช่คำแนะนำการลงทุน'],
        ];
    }

    /**
     * หัวข้อ/รายละเอียดภาษาอังกฤษของสถานะการขาย — ประกอบจากสถานะเดียวกับข้อความไทย.
     *
     * @param  array<string, mixed>  $s
     * @return array{0: string, 1: string}
     */
    private function saleEnglish(array $s): array
    {
        if ($s['sale'] === null) {
            return ['No token sale round right now', 'Follow this channel for the next announcement.'];
        }

        $open = collect($s['phases'])->firstWhere('status', 'open');

        return match ($s['state']) {
            SaleStatusService::OPEN => [
                'Sale is open — '.($open['name'] ?? (string) $s['current_phase']).' at '.($open['price'] ?? '').' per TPIX',
                'Buy on the website. Payment: '.$this->paymentMethods($s['payment_methods'], thai: false),
            ],
            SaleStatusService::CLOSED => ['Sale closed', 'Thank you to everyone who joined this round — watch this channel for the next one.'],
            default => [
                'Preparing to launch — not open for purchase yet',
                'The team is getting token delivery ready before taking payments. This message updates itself the moment the sale opens.',
            ],
        };
    }

    /** @param  array<string, mixed>  $phase */
    private function phaseWindowEnglish(array $phase): string
    {
        if (! empty($phase['starts_at']) && ! empty($phase['ends_at'])) {
            $format = fn (string $iso) => Carbon::parse($iso)->timezone('Asia/Bangkok')->format('M j, Y');

            return $format($phase['starts_at']).' – '.$format($phase['ends_at']);
        }

        return (int) ($phase['duration_days'] ?? 0).' days · the countdown starts at launch';
    }

    /** @param  array{card: bool, bank: bool}  $methods */
    private function paymentMethods(array $methods, bool $thai = true): string
    {
        $names = array_keys(array_filter([
            $thai ? 'Credit/debit card · บัตรเครดิต/เดบิต' : 'credit/debit card' => $methods['card'] ?? false,
            $thai ? 'Bank transfer · โอนผ่านธนาคาร' : 'bank transfer' => $methods['bank'] ?? false,
        ]));

        return $names === [] ? '—' : implode($thai ? "\n" : ', ', $names);
    }

    /**
     * บันทึกรุ่นจาก GitHub เขียนไว้ให้คนเปิดหน้า release — ใน Discord ตาราง markdown ขึ้นเป็นขีด | ดิบ ๆ
     * และ "ดาวน์โหลดไฟล์ด้านล่าง" ไม่มีไฟล์ให้กด → ตัดสองอย่างนี้ทิ้ง ที่เหลือคือรายการฟีเจอร์.
     */
    private function releaseNotes(string $notes): string
    {
        $lines = array_filter(
            preg_split('/\R/u', $notes) ?: [],
            fn (string $line) => ! preg_match('/^\s*\|/u', $line) && ! preg_match('/\b(?:below|attached|assets?)\b|ด้านล่าง|\.(?:apk|exe)\b/iu', $line),
        );

        return trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)) ?? '');
    }

    /** $0.1800 · $0.000123 · $1,234.56 — ปัดให้คงที่ (กันการ์ดถูกแก้ทุกรอบเพราะเศษทศนิยม) */
    private function usd(float $value): string
    {
        if ($value >= 1) {
            return '$'.number_format($value, 2);
        }

        $decimals = $value > 0 ? max(4, min(8, (int) ceil(-log10($value)) + 3)) : 4;

        return '$'.number_format($value, $decimals);
    }

    /** 1.26B · 350.00M · 12.50K */
    private function compact(float $value): string
    {
        return match (true) {
            $value >= 1e9 => number_format($value / 1e9, 2).'B',
            $value >= 1e6 => number_format($value / 1e6, 2).'M',
            $value >= 1e3 => number_format($value / 1e3, 2).'K',
            default => number_format($value, 2),
        };
    }

    /**
     * @param  array{0: string, 1: string}|null  $button  [ข้อความ, ลิงก์]
     * @param  list<array{0: string, 1: string}>  $buttons  หลายปุ่มลิงก์ในแถวเดียว (สูงสุด 5)
     */
    private function message(?string $content = null, ?array $embed = null, ?array $button = null, array $buttons = []): array
    {
        $payload = ['allowed_mentions' => ['parse' => []]];

        $payload['content'] = $content ?? '';

        // ⚠️ ข้อความที่ไม่มีการ์ด ห้ามส่ง embeds: [] — Discord ถือว่า "ไม่เอา embed" แล้วไม่สร้างตัวเล่น/พรีวิวให้ลิงก์
        //    (เจอจริง 2026-09-14: ลิงก์วิดีโอ mp4 ขึ้นเป็นลิงก์เปล่า พอตัดช่องนี้ออก Discord ทำตัวเล่นให้ในไม่กี่วินาที)
        if ($embed !== null) {
            $payload['embeds'] = [$embed];
        }

        $links = array_slice($button !== null ? [$button, ...$buttons] : $buttons, 0, 5);
        $payload['components'] = $links !== []
            ? [['type' => 1, 'components' => array_map(fn ($b) => ['type' => 2, 'style' => 5, 'label' => Str::limit($b[0], 80, ''), 'url' => $b[1]], $links)]]
            : [];

        return $payload;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * เหลือไว้เฉพาะลิงก์โดเมนของเรา (โดเมนของ APP_URL และซับโดเมน) — ลิงก์อื่นแทนด้วยข้อความ
     * จับทั้งลิงก์ปกติ ลิงก์ในวงเล็บแบบ markdown และแบบไม่มี scheme ที่ Discord ทำเป็นลิงก์ให้ (www.).
     */
    private function onlyOurLinks(string $text): string
    {
        $ours = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return preg_replace_callback('#\b(?:https?://|www\.)[^\s<>()\[\]]+#iu', function (array $m) use ($ours) {
            $candidate = str_starts_with(strtolower($m[0]), 'www.') ? 'https://'.$m[0] : $m[0];
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));

            $trusted = $ours !== '' && ($host === $ours || str_ends_with($host, '.'.$ours));

            return $trusted ? $m[0] : '[external link hidden · ลิงก์ภายนอกถูกซ่อน]';
        }, $text) ?? $text;
    }

    private function absolute(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'https://')) {
            return $path;
        }

        return str_starts_with($path, '/') ? $this->url($path) : null;
    }
}
