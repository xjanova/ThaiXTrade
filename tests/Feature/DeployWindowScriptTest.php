<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * TPIX TRADE — dry-run สคริปต์ SSH ของ auto-deploy.yml: หน้าต่าง deploy ต้องเปิดก่อน git reset
 * และปิดเสมอ แม้ deploy ล้มกลางทาง.
 *
 * อ่านสคริปต์จาก workflow จริง แล้วรันกับโฟลเดอร์แอปปลอม — git/composer/npm/php/crontab
 * เป็นสคริปต์จำลองที่จดว่าถูกเรียกตอนธงยังอยู่หรือไม่ ไม่มีอะไรแตะเซิร์ฟเวอร์จริง
 *
 * ต้องใช้ Linux (/proc + pgrep) แบบเดียวกับเครื่อง prod — บนเครื่องอื่นข้ามไป CI รันให้
 *
 * Developed by Xman Studio.
 */
class DeployWindowScriptTest extends TestCase
{
    private string $root;

    /** โฟลเดอร์แอปปลอม (DEPLOY_PATH) — ห้ามชื่อ $app เพราะชนกับแอป Laravel ของ TestCase */
    private string $appDir;

    /** @var array<int, Process> */
    private array $background = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux' || ! is_dir('/proc') || trim((string) shell_exec('command -v pgrep')) === '') {
            $this->markTestSkipped('ต้องใช้ Linux ที่มี /proc และ pgrep แบบเครื่อง prod');
        }

        $this->root = sys_get_temp_dir().'/deploy-window-'.bin2hex(random_bytes(4));
        $this->appDir = $this->root.'/app';

        foreach (['storage/framework', 'bootstrap/cache', 'public_html', 'scripts'] as $dir) {
            File::ensureDirectoryExists("{$this->appDir}/{$dir}");
        }
        File::ensureDirectoryExists("{$this->root}/bin");
        File::ensureDirectoryExists("{$this->root}/other-app");

        File::put("{$this->appDir}/.env", "APP_ENV=testing\n");
        File::put("{$this->appDir}/scripts/build-assets-atomic.sh", "exit 0\n");
        // health check ท้ายสคริปต์ต้องเห็น version ตรงกับไฟล์นี้ (curl ปลอมข้างล่างตอบค่าเดียวกัน)
        File::put("{$this->appDir}/version.json", '{"version":"9.9.9-test"}');
        // artisan ปลอมสำหรับโปรเซส "งานตามตารางที่ยังวิ่งอยู่" — รันด้วย PHP จริงให้ชื่อโปรเซสเหมือน prod
        // จดไฟล์ตอนจบแทนการถาม kill -0 เพราะโปรเซสที่จบแล้วยังเป็น zombie จนกว่า PHPUnit จะเก็บ
        $busyArtisan = '<?php sleep((int) ($argv[2] ?? 1)); touch(getcwd()."/busy-done");';
        File::put("{$this->appDir}/artisan", $busyArtisan);
        File::put("{$this->root}/other-app/artisan", $busyArtisan);

        $flagState = 'flag=down; [ -e "$DEPLOY_FLAG_UNDER_TEST" ] && flag=up';

        $this->stub('git', <<<SH
            {$flagState}
            busy=none; [ -n "\$BUSY_MARKER" ] && { [ -e "\$BUSY_MARKER" ] && busy=done || busy=alive; }
            echo "git \$* \$flag busy=\$busy" >> "\$CALLS_LOG"
            SH);
        $this->stub('composer', <<<'SH'
            echo "composer $*" >> "$CALLS_LOG"
            exit "${FAIL_COMPOSER:-0}"
            SH);
        $this->stub('npm', 'echo "npm $*" >> "$CALLS_LOG"');
        $this->stub('php', <<<SH
            # php -r ของ health check (อ่าน version.json / แยก JSON) ให้ PHP จริงทำ
            [ "\$1" = "-r" ] && exec "\$REAL_PHP" "\$@"
            {$flagState}
            echo "php \$* \$flag" >> "\$CALLS_LOG"
            # งานรายวันที่ PHP จดไว้ตอนถูกข้าม (DeployWindow::holdsBack)
            if [ "\$2" = "migrate" ] && [ -n "\$SIMULATE_SKIPPED" ]; then
              echo "\$SIMULATE_SKIPPED" >> "\$DEPLOY_FLAG_UNDER_TEST.skipped"
            fi
            exit 0
            SH);
        $this->stub('curl', <<<'SH'
            # /health ของแอปที่เพิ่ง deploy — เขียน body ลงไฟล์ -o แล้วพิมพ์ HTTP code เหมือน -w
            out=/dev/null
            while [ $# -gt 0 ]; do [ "$1" = "-o" ] && { out="$2"; shift; }; shift; done
            printf '{"status":"healthy","version":"9.9.9-test"}' > "$out"
            printf 200
            SH);
        $this->stub('crontab', <<<'SH'
            [ "$1" = "-l" ] && { cat "$CRONTAB_FILE" 2>/dev/null; exit 0; }
            [ "$1" = "-" ] && cat > "$CRONTAB_FILE"
            exit 0
            SH);
    }

    protected function tearDown(): void
    {
        foreach ($this->background as $process) {
            $process->stop(0);
        }

        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    private function stub(string $name, string $body): void
    {
        $path = "{$this->root}/bin/{$name}";
        File::put($path, "#!/bin/bash\n{$body}\n");
        chmod($path, 0755);
    }

    private function flagPath(): string
    {
        return "{$this->appDir}/storage/framework/deploy-in-progress";
    }

    private function busyTask(string $cwd, int $seconds): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'busy', (string) $seconds], $cwd);
        $process->start();
        $this->background[] = $process;

        // ให้โปรเซสขึ้นใน /proc ก่อนสคริปต์ deploy เริ่มนับ
        usleep(300_000);
    }

    /**
     * @param  array<string, string>  $env
     * @return array{exit: int, output: string, calls: string, seconds: float}
     */
    private function deploy(array $env = [], int $drainMaxSeconds = 90): array
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/auto-deploy.yml'));
        $step = collect($workflow['jobs']['deploy']['steps'])
            ->first(fn (array $s) => str_starts_with($s['uses'] ?? '', 'appleboy/ssh-action'));

        $script = strtr($step['with']['script'], [
            '${{ secrets.DEPLOY_PATH }}' => $this->appDir,
            '${{ secrets.GH_PAT }}' => '',
            '${{ secrets.APP_URL }}' => '',
            '${{ github.sha }}' => 'test-sha',
            'DRAIN_MAX_SECONDS=90' => "DRAIN_MAX_SECONDS={$drainMaxSeconds}",
        ]);
        $this->assertStringNotContainsString('${{', $script, 'มี expression ของ Actions ที่ dry-run ยังไม่แทนค่า');

        File::put("{$this->root}/deploy.sh", $script);

        $process = new Process(['bash', "{$this->root}/deploy.sh"], $this->root, $env + [
            'PATH' => "{$this->root}/bin:".getenv('PATH'),
            'CALLS_LOG' => "{$this->root}/calls.log",
            'CRONTAB_FILE' => "{$this->root}/crontab",
            'DEPLOY_FLAG_UNDER_TEST' => $this->flagPath(),
            'REAL_PHP' => PHP_BINARY,
        ]);
        $process->setTimeout(120);

        $started = microtime(true);
        $process->run();

        return [
            'exit' => $process->getExitCode(),
            'output' => $process->getOutput().$process->getErrorOutput(),
            'calls' => (string) @file_get_contents("{$this->root}/calls.log"),
            'seconds' => microtime(true) - $started,
        ];
    }

    #[Test]
    public function ธงอยู่ตลอดช่วง_git_reset_ถึงแคช_แล้วถูกลบเมื่อจบ(): void
    {
        $run = $this->deploy();

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('git reset --hard origin/main flag=up', $run['calls']);
        $this->assertStringContainsString('php artisan migrate --force flag=up', $run['calls']);
        $this->assertStringContainsString('php artisan config:cache flag=up', $run['calls']);
        $this->assertStringContainsString('php artisan queue:restart flag=up', $run['calls']);
        $this->assertFileDoesNotExist($this->flagPath());
        $this->assertStringContainsString('Scheduler released', $run['output']);
        $this->assertStringContainsString('Health check ผ่าน', $run['output']);
    }

    #[Test]
    public function deploy_ล้มกลางทาง_ธงก็ต้องถูกลบ(): void
    {
        $run = $this->deploy(['FAIL_COMPOSER' => '1']);

        $this->assertNotSame(0, $run['exit'], 'composer ล้มสองรอบ = set -e ต้องหยุด deploy');
        $this->assertStringContainsString('git reset --hard origin/main flag=up', $run['calls']);
        $this->assertStringNotContainsString('artisan migrate', $run['calls']);
        $this->assertFileDoesNotExist($this->flagPath(), 'ธงค้าง = สะพานและการจ่ายเงินหยุดไปอีก 15 นาที');
        $this->assertFileDoesNotExist($this->flagPath().'.skipped');
    }

    #[Test]
    public function งานรายวันที่ถูกข้ามขึ้นเป็นคำเตือนใน_actions(): void
    {
        $run = $this->deploy(['SIMULATE_SKIPPED' => 'php artisan stabilizer:report']);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('::warning::', $run['output']);
        $this->assertStringContainsString('php artisan stabilizer:report', $run['output']);
        $this->assertFileDoesNotExist($this->flagPath().'.skipped');
    }

    #[Test]
    public function รองานของแอปนี้ที่ยังวิ่งอยู่ให้จบก่อน_git_reset_แต่ไม่รองานของแอปอื่น(): void
    {
        $this->busyTask($this->appDir, 4);
        $this->busyTask("{$this->root}/other-app", 60);

        $run = $this->deploy(['BUSY_MARKER' => "{$this->appDir}/busy-done"]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('git reset --hard origin/main flag=up busy=done', $run['calls']);
        $this->assertLessThan(30, $run['seconds'], 'ต้องไม่รอโปรเซส artisan ของแอปอื่นบนเครื่องเดียวกัน');
    }

    #[Test]
    public function งานค้างนานเกินเพดาน_deploy_ไปต่อพร้อมคำเตือน(): void
    {
        $this->busyTask($this->appDir, 60);

        $run = $this->deploy(['BUSY_MARKER' => "{$this->appDir}/busy-done"], drainMaxSeconds: 3);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('git reset --hard origin/main flag=up busy=alive', $run['calls']);
        $this->assertStringContainsString('งานตามตารางยังไม่จบ', $run['output']);
        $this->assertLessThan(30, $run['seconds']);
        $this->assertFileDoesNotExist($this->flagPath());
    }
}
