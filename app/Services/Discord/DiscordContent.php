<?php

namespace App\Services\Discord;

use App\Models\Article;
use App\Services\SaleStatusService;
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

    public function saleStatus(): array
    {
        $s = $this->sale->snapshot();

        $fields = [];
        foreach ($s['phases'] as $phase) {
            $fields[] = [
                'name' => $phase['name'].' · '.$phase['price'],
                'value' => "{$phase['status_label']}\n{$phase['window']}\nขายแล้ว {$phase['sold_text']}",
                'inline' => false,
            ];
        }

        if ($s['state'] === SaleStatusService::OPEN) {
            $methods = array_keys(array_filter(['บัตรเครดิต/เดบิต' => $s['payment_methods']['card'], 'โอนผ่านธนาคาร' => $s['payment_methods']['bank']]));
            $fields[] = ['name' => 'ช่องทางชำระ', 'value' => implode(' · ', $methods), 'inline' => false];
        }

        return $this->message(embed: [
            'title' => '💰 การขายเหรียญ TPIX',
            'url' => $s['url'],
            'description' => "**{$s['headline']}**\n{$s['detail']}",
            'color' => match ($s['state']) {
                SaleStatusService::OPEN => self::COLOR_OPEN,
                SaleStatusService::CLOSED => self::COLOR_CLOSED,
                default => self::COLOR_PREPARING,
            },
            'fields' => array_slice($fields, 0, 25),
            'footer' => ['text' => 'อัปเดตอัตโนมัติจาก tpix.online · คริปโตมีความเสี่ยง ศึกษาข้อมูลก่อนตัดสินใจ'],
        ], button: $s['state'] === SaleStatusService::OPEN ? ['ซื้อเหรียญที่หน้าเว็บ', $s['url']] : ['ดูรายละเอียดการขาย', $s['url']]);
    }

    public function article(Article $article): array
    {
        $url = $this->url('/blog/'.$article->slug);
        $summary = trim((string) ($article->summary ?: Str::limit(strip_tags((string) $article->content), 280)));
        $image = $this->absolute((string) $article->cover_image);

        return $this->message(embed: array_filter([
            'title' => Str::limit((string) $article->title, 250),
            'url' => $url,
            'description' => Str::limit($summary, 600),
            'color' => self::COLOR_BRAND,
            'image' => $image !== null ? ['url' => $image] : null,
            'footer' => ['text' => '📰 บทความจาก tpix.online'.($article->category ? ' · '.$article->category : '')],
            'timestamp' => $article->published_at?->toIso8601String(),
        ]), button: ['อ่านต่อ', $url]);
    }

    /** @param  array{code: string, file: string, title: string, part: string}  $video */
    public function video(array $video): array
    {
        $file = $this->url('/videos/whitepaper/'.rawurlencode($video['file']));

        // ลิงก์ mp4 ตรง ๆ = Discord เล่นวิดีโอในห้องได้เอง (ไฟล์ใหญ่เกินเพดานแนบไฟล์)
        return $this->message(
            content: "🎬 **{$video['title']}**\n_{$video['part']}_ — ซีรีส์วิดีโอ Whitepaper ของ TPIX\n{$file}",
            button: ['ดูพร้อมเนื้อหาประกอบ', $this->url('/whitepaper')],
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
                'เอกสารฉบับเต็มของโปรเจกต์ — เชน TPIX, โทเคโนมิกส์, การขายเหรียญ, มาสเตอร์โหนด, AI TRADE และแผนงาน',
                '',
                '• อ่านออนไลน์ (มีวิดีโอประกอบ 9 ตอน): '.$this->url('/whitepaper'),
                '• ดาวน์โหลด PDF ภาษาไทย: '.$this->url('/whitepaper/download?lang=th'),
                '• Download PDF (English): '.$this->url('/whitepaper/download?lang=en'),
            ]),
            'color' => self::COLOR_BRAND,
        ], button: ['เปิด Whitepaper', $this->url('/whitepaper')]);
    }

    public function askHint(): array
    {
        return $this->message(embed: [
            'title' => '🤖 ถามผู้ช่วย AI ของ TPIX',
            'description' => implode("\n", [
                'พิมพ์ **/ถาม** ตามด้วยคำถาม เช่น `/ถาม ตอนนี้เปิดขายเหรียญหรือยัง`',
                'ผู้ช่วยตอบจากข้อมูลบนเว็บ tpix.online ตัวเดียวกับผู้ช่วยบนหน้าเว็บ',
                'ดูสถานะการขายเหรียญล่าสุดได้ด้วย **/ขายเหรียญ**',
                '',
                '_คำตอบของ AI อาจผิดพลาดได้ ข้อมูลสำคัญให้ตรวจที่หน้าเว็บอีกครั้ง · ทีมงานไม่มีวัน DM ขอ seed phrase_',
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
            $button = ['เปิดหน้าที่เกี่ยวข้อง', $this->url($navigation)];
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

        return $price === null ? null : $this->message(embed: $this->priceEmbed($price), button: ['เปิดกราฟ TPIX/USDT', $this->url('/trade/TPIX-USDT')]);
    }

    /** คำตอบ /ราคา — มีเวลาของข้อมูลกำกับ (ไม่ต้องคงที่แบบการ์ดประจำห้อง) */
    public function priceReply(): array
    {
        $price = $this->live->price();

        if ($price === null) {
            return $this->message(content: '⚠️ ตอนนี้อ่านราคา TPIX ไม่ได้ ลองใหม่อีกครั้ง หรือดูที่ '.$this->url('/trade/TPIX-USDT'));
        }

        return $this->message(embed: $this->priceEmbed($price) + ['timestamp' => now()->toIso8601String()], button: ['เปิดกราฟ TPIX/USDT', $this->url('/trade/TPIX-USDT')]);
    }

    public function chainStatus(): array
    {
        $chain = $this->live->chain();

        if (! $chain['connected']) {
            return $this->message(embed: [
                'title' => '⛓️ สถานะ TPIX Chain',
                'url' => $chain['explorer'],
                'description' => '⚠️ ตอนนี้อ่านข้อมูลจากเชนไม่ได้ — ลองใหม่อีกครั้ง หรือดูที่ Explorer',
                'color' => self::COLOR_PREPARING,
            ], button: ['เปิด Explorer', $chain['explorer']]);
        }

        // แคชของหน้าเว็บเก็บบล็อกล่าสุดได้ถึง 1 นาที — เกิน 3 นาทีค่อยถือว่าเชนช้าผิดปกติ
        $age = $chain['last_block_at'] !== null ? now()->timestamp - $chain['last_block_at'] : null;
        $headline = $age !== null && $age > 180
            ? "🟠 บล็อกล่าสุดเมื่อ <t:{$chain['last_block_at']}:R> — เชนอาจล่าช้า"
            : '🟢 เชนทำงานปกติ'.($chain['last_block_at'] !== null ? " · บล็อกล่าสุด <t:{$chain['last_block_at']}:R>" : '');

        $fields = [
            ['name' => 'บล็อกล่าสุด', 'value' => number_format($chain['block_height']), 'inline' => true],
            ['name' => 'Validator ที่ทำงาน', 'value' => (string) $chain['validators'], 'inline' => true],
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
            $fields[] = ['name' => 'มาสเตอร์โหนดที่ทำงาน', 'value' => number_format((int) ($n['total_nodes'] ?? 0)).($breakdown !== '' ? " ({$breakdown})" : ''), 'inline' => false];
        }

        $fields[] = ['name' => 'RPC', 'value' => "`{$chain['rpc']}`", 'inline' => false];

        return $this->message(embed: [
            'title' => '⛓️ สถานะ TPIX Chain',
            'url' => $chain['explorer'],
            'description' => $headline,
            'color' => self::COLOR_OPEN,
            'fields' => $fields,
            'footer' => ['text' => 'ข้อมูลจากเชนจริง ชุดเดียวกับหน้าเว็บ'],
            'timestamp' => now()->toIso8601String(),
        ], button: ['เปิด Explorer', $chain['explorer']]);
    }

    public function officialLinks(): array
    {
        $links = $this->live->links();

        $fields = [[
            'name' => 'เครือข่าย TPIX Chain (เพิ่มในวอลเล็ต)',
            'value' => "Chain ID `{$links['chain_id']}` · สกุล `TPIX`\nRPC `{$links['rpc']}`\nExplorer {$links['explorer']}",
            'inline' => false,
        ]];

        if ($links['contracts'] !== []) {
            $fields[] = [
                'name' => 'ที่อยู่สัญญาบนเชน TPIX (ตรวจแล้วว่ามีอยู่จริง)',
                'value' => collect($links['contracts'])->map(fn ($c) => "{$c[0]}\n`{$c[1]}`")->implode("\n"),
                'inline' => false,
            ];
        }

        if ($links['bsc_wtpix'] !== null) {
            $fields[] = ['name' => 'wTPIX บน BNB Smart Chain', 'value' => "`{$links['bsc_wtpix']}`", 'inline' => false];
        }

        $fields[] = [
            'name' => '🚨 กันมิจฉาชีพ',
            'value' => 'ลิงก์อื่นนอกจากนี้ถือว่าปลอม · ทีมงานไม่ทัก DM ไปก่อน · ไม่มีวันขอ seed phrase / private key / ให้โอนเงินก่อน',
            'inline' => false,
        ];

        return $this->message(embed: [
            'title' => '🔗 ลิงก์ทางการของ TPIX',
            'description' => collect($links['pages'])->map(fn ($p) => "{$p[0]} — {$p[1]}")->implode("\n"),
            'color' => self::COLOR_BRAND,
            'fields' => $fields,
            'footer' => ['text' => 'พิมพ์ /ลิงก์ ได้ทุกเมื่อ — บอททางการดึงจากระบบจริง'],
        ], button: ['เปิดเว็บไซต์ tpix.online', $this->url('/')]);
    }

    /** @param  array{product: string, label: string, version: string, name: string, notes: string, published_at: ?string}  $release */
    public function release(array $release): array
    {
        $notes = trim($this->onlyOurLinks($release['notes']));

        return $this->message(embed: array_filter([
            'title' => Str::limit("🚀 {$release['label']} เวอร์ชัน {$release['version']}", 250),
            'url' => $this->url('/download'),
            'description' => $notes !== '' ? Str::limit($notes, 1500) : 'มีเวอร์ชันใหม่ให้ดาวน์โหลดแล้ว',
            'color' => self::COLOR_BRAND,
            'footer' => ['text' => 'ดาวน์โหลดจากหน้าเว็บทางการเท่านั้น — ไฟล์จากที่อื่นอาจฝังมัลแวร์ขโมยกระเป๋า'],
            'timestamp' => $release['published_at'],
        ]), button: ['ดาวน์โหลด', $this->url('/download')]);
    }

    public function newPair(string $symbol): array
    {
        $url = $this->url('/trade/'.rawurlencode($symbol));

        return $this->message(embed: [
            'title' => '🆕 คู่เทรดใหม่บน TPIX DEX: '.str_replace('-', '/', $symbol),
            'url' => $url,
            'description' => "เหรียญผ่านการตรวจจากทีมงานและมีสภาพคล่องแล้ว — สวอปบนเชน TPIX ได้\n\n⚠️ การมีคู่เทรดไม่ใช่คำแนะนำให้ซื้อ ตรวจที่อยู่สัญญาในหน้าเทรดก่อนสวอปทุกครั้ง · คริปโตมีความเสี่ยงสูง",
            'color' => self::COLOR_OPEN,
        ], button: ['เปิดหน้าเทรด', $url]);
    }

    /** รายการคู่เทรดประจำห้อง — ไม่มีราคาในการ์ด (ราคาขยับทุกนาที การ์ดนี้แก้เฉพาะตอนคู่เปลี่ยน) */
    public function pairsCard(): array
    {
        $pairs = $this->live->dexPairs();

        $lines = $pairs->map(fn ($p) => '• ['.str_replace('-', '/', (string) $p->symbol).']('.$this->url('/trade/'.rawurlencode((string) $p->symbol)).')')->implode("\n");

        return $this->message(embed: [
            'title' => '📋 คู่เทรดบน TPIX DEX',
            'url' => $this->url('/swap'),
            'description' => $lines !== ''
                ? Str::limit($lines, 3500)."\n\n_แสดงเฉพาะเหรียญที่ทีมงานตรวจแล้ว — ใครก็สร้างเหรียญบนเชนได้ เหรียญที่ไม่อยู่ในรายการนี้ให้ระวังเป็นพิเศษ_"
                : 'ยังไม่มีคู่เทรดของเหรียญที่ทีมงานตรวจแล้ว — คู่ใหม่จะขึ้นในห้องนี้อัตโนมัติ',
            'color' => self::COLOR_BRAND,
            'footer' => ['text' => 'อัปเดตอัตโนมัติจากพูลบนเชน · '.$pairs->count().' คู่'],
        ], button: ['เปิดหน้าสวอป', $this->url('/swap')]);
    }

    // ── คู่มือประจำห้อง ────────────────────────────────────────────────────────

    public function guideHelp(): array
    {
        return $this->message(embed: [
            'title' => '🆘 ขอความช่วยเหลือ — อ่านก่อนโพสต์',
            'description' => implode("\n", [
                '**ถามได้ทันที 24 ชั่วโมง**',
                '• `/ถาม` ผู้ช่วย AI ตอบจากข้อมูลบนเว็บ',
                '• `/ราคา` · `/เชน` · `/ขายเหรียญ` · `/ลิงก์` ดูข้อมูลสดจากระบบจริง',
                '',
                '**แจ้งปัญหาในห้องนี้ บอกให้ครบจะได้ช่วยเร็ว**',
                '1) ใช้อะไร — เว็บ / แอป TPIX TRADE / TPIX Wallet / มาสเตอร์โหนด และเวอร์ชัน',
                '2) ทำอะไรแล้วเกิดอะไรขึ้น (แนบภาพหน้าจอได้)',
                '3) ถ้าเกี่ยวกับการโอน ใส่เลขธุรกรรม (tx hash) — ดูได้ที่ '.$this->live->network()['explorer'],
                '',
                '🚨 **ห้ามโพสต์เด็ดขาด:** seed phrase / private key / รหัสผ่าน / รหัส OTP',
                'ทีมงานไม่มีวันขอ และไม่ทัก DM ไปหาก่อน — ใครทักมาช่วยแก้ปัญหาทาง DM คือมิจฉาชีพ',
                '',
                '🚩 เจอข้อความน่าสงสัย: **คลิกขวาที่ข้อความ → Apps → รายงานให้แอดมิน**',
            ]),
            'color' => self::COLOR_BRAND,
        ]);
    }

    public function guideDex(): array
    {
        $links = $this->live->network();

        return $this->message(embed: [
            'title' => '🔄 ใช้ TPIX DEX — สวอปเหรียญบนเชน TPIX',
            'description' => implode("\n", [
                '**เริ่มต้น 3 ขั้น**',
                '1) ติดตั้งวอลเล็ต (TPIX Wallet หรือ MetaMask) — '.$this->url('/download'),
                "2) เพิ่มเครือข่าย TPIX Chain: Chain ID `{$links['chain_id']}` · RPC `{$links['rpc']}` · สกุล `TPIX`",
                '3) เปิด '.$this->url('/swap').' เชื่อมวอลเล็ต เลือกเหรียญ แล้วกดสวอป',
                '',
                '**ปลอดภัยไว้ก่อน**',
                '• ตรวจที่อยู่สัญญาเหรียญทุกครั้ง — พิมพ์ `/ลิงก์` ดูที่อยู่ทางการ',
                '• ใครก็สร้างเหรียญบนเชนได้ เหรียญชื่อซ้ำ/เลียนแบบมีจริง',
                '• สวอปไม่ผ่าน ลองเพิ่ม slippage ทีละน้อย อย่าตั้งสูงเกินจำเป็น',
                '',
                '**ติดปัญหา** โพสต์ในห้องนี้พร้อมเลขธุรกรรม (tx hash) — ห้ามโพสต์ seed phrase / private key',
            ]),
            'color' => self::COLOR_BRAND,
        ], button: ['เปิดหน้าสวอป', $this->url('/swap')]);
    }

    public function guideBugs(): array
    {
        return $this->message(embed: [
            'title' => '🐞 แจ้งบั๊ก — ก๊อปแบบฟอร์มนี้ไปกรอก',
            'description' => implode("\n", [
                '```',
                'แอป/หน้าเว็บ: (เว็บหน้าเทรด / แอป TPIX TRADE / TPIX Wallet / มาสเตอร์โหนด)',
                'เวอร์ชัน / อุปกรณ์: (เช่น 1.2.3 · Android 14 · Chrome)',
                'ขั้นตอนที่ทำ: 1) ... 2) ... 3) ...',
                'ผลที่คาดไว้:',
                'ผลที่เกิดจริง: (แนบภาพหน้าจอ/วิดีโอได้)',
                '```',
                '⚠️ ก่อนแนบภาพ ตรวจว่าไม่มี seed phrase / private key / ข้อมูลบัญชี',
                '🔒 เจอช่องโหว่ด้านความปลอดภัย อย่าโพสต์ในห้องสาธารณะ — ทักแอดมินของเซิร์ฟเวอร์โดยตรง',
            ]),
            'color' => self::COLOR_BRAND,
        ]);
    }

    public function guideIdeas(): array
    {
        return $this->message(embed: [
            'title' => '💡 เสนอไอเดีย / ฟีเจอร์',
            'description' => implode("\n", [
                '```',
                'ไอเดีย: (สรุปสั้น ๆ 1 บรรทัด)',
                'ช่วยแก้ปัญหาอะไร:',
                'ใครได้ประโยชน์:',
                'ตัวอย่าง/ภาพประกอบ: (ถ้ามี)',
                '```',
                'กด 👍 ใต้ไอเดียที่อยากได้ — ช่วยให้ทีมงานเห็นว่าไอเดียไหนคนต้องการมากที่สุด',
                'หนึ่งโพสต์ต่อหนึ่งไอเดีย จะคุยต่อง่ายและไม่ปนกัน',
            ]),
            'color' => self::COLOR_BRAND,
        ]);
    }

    public function guideIntro(): array
    {
        return $this->message(embed: [
            'title' => '👋 แนะนำตัวกันหน่อย',
            'description' => implode("\n", [
                '```',
                'ชื่อเล่น:',
                'อยู่ที่ไหน: (จังหวัด / ประเทศ)',
                'สนใจเรื่องไหนของ TPIX: (เทรด · มาสเตอร์โหนด · DEX · สร้างเหรียญ · อื่น ๆ)',
                'รู้จัก TPIX จากที่ไหน:',
                '```',
                '⚠️ อย่าใส่เบอร์โทร ที่อยู่ ข้อมูลกระเป๋า หรือ seed phrase — มิจฉาชีพชอบเก็บข้อมูลจากห้องแนะนำตัว',
            ]),
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
            $fields[] = ['name' => 'เปลี่ยนแปลง 24 ชม.', 'value' => ($change > 0 ? '🟢 +' : ($change < 0 ? '🔴 ' : '⚪ ')).number_format($change, 2).'%', 'inline' => true];
            $fields[] = ['name' => 'สูง / ต่ำ 24 ชม.', 'value' => $this->usd((float) ($p['high_24h'] ?? 0)).' / '.$this->usd((float) ($p['low_24h'] ?? 0)), 'inline' => true];
        }

        $fields[] = ['name' => 'มูลค่าตามราคาตลาด', 'value' => '$'.$this->compact((float) ($p['market_cap'] ?? 0)), 'inline' => true];
        $fields[] = ['name' => 'อุปทานหมุนเวียน', 'value' => $this->compact((float) ($p['circulating_supply'] ?? 0)).' TPIX', 'inline' => true];

        return [
            'title' => '📈 ราคา TPIX',
            'url' => $this->url('/trade/TPIX-USDT'),
            'description' => '## '.$this->usd((float) ($p['price'] ?? 0)),
            'color' => self::COLOR_BRAND,
            'fields' => $fields,
            'footer' => ['text' => match ($p['source'] ?? '') {
                'dex' => 'ราคาจากพูลบน TPIX DEX (สวอปได้จริง)',
                'trades' => 'ราคาซื้อขายล่าสุดบนกระดาน TPIX TRADE',
                default => 'ราคาอ้างอิง — ยังไม่มีการซื้อขายจริงบนตลาด',
            }.' · ไม่ใช่คำแนะนำการลงทุน'],
        ];
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
     */
    private function message(?string $content = null, ?array $embed = null, ?array $button = null): array
    {
        $payload = ['allowed_mentions' => ['parse' => []]];

        $payload['content'] = $content ?? '';

        // ⚠️ ข้อความที่ไม่มีการ์ด ห้ามส่ง embeds: [] — Discord ถือว่า "ไม่เอา embed" แล้วไม่สร้างตัวเล่น/พรีวิวให้ลิงก์
        //    (เจอจริง 2026-09-14: ลิงก์วิดีโอ mp4 ขึ้นเป็นลิงก์เปล่า พอตัดช่องนี้ออก Discord ทำตัวเล่นให้ในไม่กี่วินาที)
        if ($embed !== null) {
            $payload['embeds'] = [$embed];
        }
        $payload['components'] = $button !== null
            ? [['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => $button[0], 'url' => $button[1]]]]]
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

            return $trusted ? $m[0] : '[ลิงก์ภายนอกถูกซ่อน]';
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
