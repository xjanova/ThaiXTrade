<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * TPIX TRADE — ข้อความฝั่งเซิร์ฟเวอร์ต้องมีคำแปลอังกฤษครบ.
 *
 * โค้ดเขียนข้อความเป็นไทยแล้วห่อ __('ข้อความ :name') — ภาษาไทยได้ข้อความเดิมเสมอ
 * ส่วนภาษาอังกฤษหาจาก lang/en.json ด้วยข้อความไทยเป็นคีย์ ถ้าคีย์ไม่มีผู้ใช้ EN จะเห็นไทยเงียบๆ
 * เทสต์นี้กันสองอาการ:
 *  1. เพิ่ม __('ข้อความไทยใหม่') แต่ลืมใส่ใน lang/en.json
 *  2. คำแปลมีตัวแปร :name ไม่ตรงกับต้นฉบับ → ตัวเลข/ชื่อหายจากข้อความอังกฤษ
 *
 * Developed by Xman Studio.
 */
class ServerTranslationsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function english(): array
    {
        $en = json_decode((string) file_get_contents(self::root().'/lang/en.json'), true);
        self::assertIsArray($en, 'lang/en.json must be valid JSON');

        return $en;
    }

    private static function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /** @return array<int, array{string, string}> [ข้อความ, file:line] ของทุก __('...') ที่เป็นสตริงตายตัวและมีอักษรไทย */
    private static function thaiLiterals(): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/app'));
        $paths = array_map(fn ($f) => $f->getPathname(), iterator_to_array($files));
        foreach (['web', 'api', 'admin'] as $route) {
            $paths[] = self::root()."/routes/{$route}.php";
        }

        foreach ($paths as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($path)),
                fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));
            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== '__' || ($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }
                // รวมสตริงที่ต่อกันด้วย . ให้เป็นคีย์เดียว ('ก' . 'ข') — ถ้ามีตัวแปรปนอยู่ถือว่าไม่ใช่คีย์ตายตัว
                $text = '';
                $j = $i + 2;
                while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $text .= eval('return '.$tokens[$j][1].';');
                    $line = $tokens[$j][2];
                    if (($tokens[$j + 1] ?? null) !== '.') {
                        break;
                    }
                    $j += 2;
                }
                if ($text === '' || ! in_array($tokens[$j + 1] ?? null, [',', ')'], true) || ! preg_match('/\p{Thai}/u', $text)) {
                    continue;
                }
                $found[] = [$text, substr($path, strlen(self::root()) + 1).':'.$line];
            }
        }

        return $found;
    }

    public function test_every_thai_server_message_has_an_english_translation(): void
    {
        $en = self::english();
        $literals = self::thaiLiterals();
        $this->assertNotEmpty($literals);

        $missing = array_map(
            fn ($l) => "{$l[1]}  {$l[0]}",
            array_filter($literals, fn ($l) => ! array_key_exists($l[0], $en)),
        );

        $this->assertSame([], array_values($missing), "Add these keys to lang/en.json:\n".implode("\n", $missing));
    }

    public function test_english_keeps_the_same_placeholders(): void
    {
        $bad = [];
        foreach (self::english() as $thai => $english) {
            // ชื่อคำสั่งบอท Discord (/ถาม /ราคา ...) เป็นภาษาไทยจริงในทุกภาษา — ไม่นับเป็นลืมแปล
            if (! is_string($english) || $english === '' || preg_match('/\p{Thai}/u', (string) preg_replace('#/\S+#u', '', $english))) {
                $bad[] = "not translated: {$thai}";
            } elseif (self::placeholders($thai) !== self::placeholders($english)) {
                $bad[] = "{$thai} => {$english}";
            }
        }

        $this->assertSame([], $bad);
    }
}
