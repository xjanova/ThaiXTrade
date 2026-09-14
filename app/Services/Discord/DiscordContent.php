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

    public function __construct(
        private readonly SaleStatusService $sale,
        private readonly DiscordSettings $settings,
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
