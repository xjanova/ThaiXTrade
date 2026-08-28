<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * TPIX TRADE — ดึงคีย์ AI จากพูลของ Thaiprompt มาใช้.
 *
 *   php artisan ai:pull-pool-key --list          ดูว่าพูลมีคีย์อะไรบ้าง (ไม่โชว์ค่าคีย์)
 *   php artisan ai:pull-pool-key --id=39         ดึงใบนั้นมาตั้งเป็นคีย์ OpenAI ของเว็บนี้
 *   php artisan ai:pull-pool-key --id=39 --dry-run   ทดสอบว่าใช้ได้ไหม โดยไม่บันทึก
 *
 * ═══ ทำไมต้องมีคำสั่งนี้ แทนที่จะก๊อปคีย์มาแปะเอง ═══
 *
 * สองแอปอยู่เครื่องเดียวกันแต่ **APP_KEY คนละใบ** — พูลเก็บคีย์ด้วย
 * `Crypt::encryptString` ของ Thaiprompt ก๊อป ciphertext มาตรงๆ จึงถอดไม่ออก
 * ต้องถอดด้วยกุญแจฝั่งโน้นก่อน แล้วค่อยเก็บฝั่งนี้
 *
 * เคยทำมือมาแล้วตอนย้ายคีย์ให้ xmanstudio (22 ส.ค. 2026) ด้วย `php -r` สองท่อน
 * กับไฟล์ชั่วคราวที่มีคีย์ล้วนวางอยู่บนดิสก์ — ทำซ้ำทุกครั้งที่หมุนคีย์ไม่ไหว
 * และไฟล์ชั่วคราวคือความเสี่ยงที่ไม่จำเป็น คำสั่งนี้ทำในหน่วยความจำล้วน
 *
 * ⚠️ คีย์ในพูลอยู่ OpenAI org เดียวกันทั้งหมด → **บิลรวมกัน**
 *    เลือกใบที่ Thaiprompt ไม่ได้ใช้อยู่ เพื่อไม่ให้แย่ง rate limit กัน
 *    (แย่ง rate limit แยกกันได้ แต่บิลแยกไม่ได้)
 *
 * ⚠️ คำสั่งนี้อ่าน `.env` ของอีกแอปหนึ่ง — ตั้งใจ และจำกัดอยู่แค่ APP_KEY กับ
 *    ค่าเชื่อมฐานข้อมูล ไม่แตะอย่างอื่น และไม่พิมพ์ค่าคีย์ออกหน้าจอเลยสักครั้ง
 *
 * Developed by Xman Studio.
 */
class AiPullPoolKey extends Command
{
    protected $signature = 'ai:pull-pool-key
        {--list : แสดงคีย์ในพูลแล้วจบ}
        {--id= : รหัสคีย์ในพูลที่จะดึงมาใช้}
        {--dry-run : ทดสอบว่าคีย์ใช้ได้ไหม โดยไม่บันทึก}';

    protected $description = 'ดึงคีย์ OpenAI จากพูลของ Thaiprompt มาตั้งให้ TPIX TRADE';

    public function handle(): int
    {
        $path = rtrim((string) config('ai_text.pool.path'), '/');

        if (! is_file("{$path}/.env")) {
            $this->error("ไม่พบ .env ของ Thaiprompt ที่ {$path}");
            $this->line('ตั้งที่อยู่ให้ถูกด้วย THAIPROMPT_PATH ใน .env ของเว็บนี้');

            return self::FAILURE;
        }

        $env = $this->readEnv("{$path}/.env");

        try {
            $rows = $this->poolRows($env);
        } catch (\Throwable $e) {
            $this->error('ต่อฐานข้อมูลของ Thaiprompt ไม่ได้: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('list') || ! $this->option('id')) {
            return $this->listPool($rows);
        }

        $id = (int) $this->option('id');
        $row = collect($rows)->firstWhere('id', $id);

        if (! $row) {
            $this->error("ไม่พบคีย์รหัส {$id} ในพูล");

            return self::FAILURE;
        }

        if ($row->provider !== 'openai') {
            $this->error("คีย์ใบนี้เป็นของ {$row->provider} — คำสั่งนี้รองรับเฉพาะ openai");

            return self::FAILURE;
        }

        try {
            $plain = $this->decrypt($env, (string) $row->api_key);
        } catch (\Throwable $e) {
            $this->error('ถอดรหัสคีย์ไม่สำเร็จ: '.$e->getMessage());
            $this->line('มักเกิดจาก APP_KEY ของ Thaiprompt ถูกเปลี่ยนหลังจากบันทึกคีย์ใบนี้');

            return self::FAILURE;
        }

        /*
         * ทดสอบก่อนบันทึกเสมอ
         *
         * บันทึกคีย์ตายลงไปแล้วอาการจะเหมือน "ผู้ช่วย AI พัง" ซึ่งเป็นอาการเดียวกับ
         * อีกหลายสาเหตุ แยกไม่ออกจนกว่าจะไปอ่าน log — เสียเวลาโดยไม่จำเป็น
         */
        $check = $this->verify($plain);

        if (! $check['ok']) {
            $this->error('คีย์ใบนี้ใช้ไม่ได้: '.$check['reason']);

            return self::FAILURE;
        }

        $this->components->info("คีย์ใช้ได้ — ผู้ให้บริการมีโมเดล {$check['models']} ตัว");

        if ($this->option('dry-run')) {
            $this->line('โหมดทดสอบ — ไม่ได้บันทึกอะไร');

            return self::SUCCESS;
        }

        SiteSetting::set('ai', 'openai_api_key', $plain);

        // ตั้งโมเดลปริยายให้ด้วยถ้ายังไม่เคยตั้ง — ไม่งั้นตกไปใช้ค่าใน config เฉยๆ
        if (! SiteSetting::get('ai', 'openai_default_model')) {
            SiteSetting::set('ai', 'openai_default_model', (string) config('ai_text.providers.openai.default_model'));
        }

        $this->components->info("บันทึกแล้ว — คีย์รหัส {$id} ({$row->purpose}) เป็นคีย์ OpenAI ของเว็บนี้");
        $this->newLine();
        $this->line('ใช้กับ: ผู้ช่วย AI · วิเคราะห์ตลาด · เขียนข่าว · รอบวิเคราะห์ของบอทเทรด');
        $this->warn('อย่าลืม: php artisan cache:clear (SiteSetting แคชไว้)');

        return self::SUCCESS;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /** แสดงพูลโดยไม่เปิดเผยค่าคีย์ */
    private function listPool(array $rows): int
    {
        $this->components->info('คีย์ในพูลของ Thaiprompt');

        $this->table(
            ['id', 'ชื่อ', 'ผู้ให้บริการ', 'จุดประสงค์', 'โมเดล', 'เปิด', 'โทเคน/เดือน', 'ใช้ล่าสุด'],
            array_map(fn ($r) => [
                $r->id,
                mb_substr((string) $r->name, 0, 12),
                $r->provider,
                $r->purpose ?? '—',
                mb_substr((string) $r->model, 0, 16) ?: '—',
                $r->is_active ? '✓' : '',
                number_format((int) $r->tokens_used_month),
                $r->last_used_at ?? '—',
            ], $rows),
        );

        $this->newLine();
        $this->line('เลือกใบที่ Thaiprompt "ไม่ได้เปิดใช้" เพื่อไม่แย่ง rate limit กัน');
        $this->warn('⚠️ ทุกใบอยู่ OpenAI org เดียวกัน — บิลรวมกัน แยกได้แค่ rate limit');

        return self::SUCCESS;
    }

    /**
     * อ่าน .env ของอีกแอปแบบง่ายๆ.
     *
     * ไม่ใช้ Dotenv เพราะมันจะไปเขียนทับ environment ของ process นี้ — เว็บเรา
     * กำลังรันอยู่ด้วยค่าของตัวเอง การให้ค่าของอีกแอปมาทับคือหายนะเงียบ
     *
     * @return array<string, string>
     */
    private function readEnv(string $file): array
    {
        $out = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim(trim($v), "\"'");
        }

        return $out;
    }

    /** อ่านตารางพูลผ่านการเชื่อมต่อชั่วคราว (ไม่แตะ config ถาวรของแอป) */
    private function poolRows(array $env): array
    {
        $name = 'thaiprompt_pool_tmp';

        config(["database.connections.{$name}" => [
            'driver' => 'mysql',
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? '3306',
            'database' => $env['DB_DATABASE'] ?? '',
            'username' => $env['DB_USERNAME'] ?? '',
            'password' => $env['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
        ]]);

        DB::purge($name);

        return DB::connection($name)
            ->table((string) config('ai_text.pool.table'))
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** ถอดรหัสด้วย APP_KEY ของ Thaiprompt (ไม่ใช่ของเรา) */
    private function decrypt(array $env, string $ciphertext): string
    {
        $appKey = (string) ($env['APP_KEY'] ?? '');

        if ($appKey === '') {
            throw new \RuntimeException('ไม่พบ APP_KEY ใน .env ของ Thaiprompt');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        $cipher = $env['APP_CIPHER'] ?? 'AES-256-CBC';

        // decryptString ไม่ใช่ decrypt — decrypt คาด payload ที่ serialize มา แล้วจะพัง
        return (new Encrypter($appKey, $cipher))->decryptString($ciphertext);
    }

    /**
     * คีย์ใช้ได้จริงไหม.
     *
     * @return array{ok: bool, reason?: string, models?: int}
     */
    private function verify(string $key): array
    {
        try {
            $response = Http::timeout(20)->withToken($key)->get('https://api.openai.com/v1/models');
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'reason' => 'คีย์ถูกปฏิเสธ (401) — อาจถูกยกเลิกไปแล้ว'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'reason' => 'HTTP '.$response->status()];
        }

        return ['ok' => true, 'models' => count((array) $response->json('data', []))];
    }
}
