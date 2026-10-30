<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * TPIX TRADE — workflow ต้องไม่ชนเพดาน expression ของ GitHub Actions.
 *
 * สตริงที่มี ${{ }} อยู่ข้างในถูกนับทั้งก้อนเป็น expression เดียว เพดาน 21,000 ไบต์
 * เกินเมื่อไร GitHub ปฏิเสธไฟล์ทั้งไฟล์ ("Exceeded max expression length 21000")
 * — ไม่มี job ไหนได้รัน และไม่มีอะไรขึ้นในเช็กของ PR เพราะ workflow ไม่ได้ผูกกับ pull_request
 *
 * เกิดจริง 2026-10-30: merge #186 แล้ว script ของ auto-deploy.yml ยาว 23,479 ไบต์
 * (คอมเมนต์ภาษาไทย 3 ไบต์ต่อตัวอักษร) → v1.1.266 ไม่ได้ deploy เลย
 *
 * Developed by Xman Studio.
 */
class DeployWorkflowLimitsTest extends TestCase
{
    /** เพดานจริงของ GitHub คือ 21000 — เผื่อไว้ให้รู้ตัวก่อนชน */
    private const MAX_EXPRESSION_BYTES = 20000;

    #[Test]
    public function สคริปต์_deploy_ส่งค่าผ่าน_env_ไม่มี_expression_ข้างใน(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/auto-deploy.yml'));
        $step = collect($workflow['jobs']['deploy']['steps'])
            ->first(fn (array $s) => str_starts_with($s['uses'] ?? '', 'appleboy/ssh-action'));

        $this->assertNotNull($step, 'ไม่เจอ step ssh-action ใน auto-deploy.yml');
        $this->assertStringNotContainsString('${{', $step['with']['script']);

        // ทุกชื่อใน envs ต้องมีค่าใน env ของ step — ไม่งั้นฝั่งเซิร์ฟเวอร์ได้ค่าว่างเงียบๆ
        $names = array_map('trim', explode(',', $step['with']['envs']));
        $this->assertSame([], array_diff($names, array_keys($step['env'] ?? [])));
    }

    #[Test]
    public function สตริงที่มี_expression_ทุกตัวในทุก_workflow_สั้นกว่าเพดาน(): void
    {
        $tooLong = [];

        foreach (File::glob(base_path('.github/workflows/*.yml')) as $file) {
            $this->walk(Yaml::parseFile($file), basename($file), $tooLong);
        }

        $this->assertSame([], $tooLong, 'สตริงเหล่านี้มี ${{ }} และยาวใกล้เพดาน 21000 ไบต์ของ GitHub — ย้ายค่าไปส่งทาง env');
    }

    /**
     * @param  array<string, int>  $tooLong
     */
    private function walk(mixed $node, string $path, array &$tooLong): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $this->walk($child, "{$path}.{$key}", $tooLong);
            }

            return;
        }

        if (is_string($node) && str_contains($node, '${{') && strlen($node) > self::MAX_EXPRESSION_BYTES) {
            $tooLong[$path] = strlen($node);
        }
    }
}
