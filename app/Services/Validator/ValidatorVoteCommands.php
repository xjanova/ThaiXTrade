<?php

namespace App\Services\Validator;

/**
 * ValidatorVoteCommands — คำสั่งโหวตเพิ่ม/ถอด validator ที่ต้องรันบนเครื่องเชน (123.253.62.252).
 *
 * ทางหลักคือสคริปต์ TPIX-Coin infrastructure/single-host/validator-vote.sh ซึ่งตรวจก่อนโหวต
 * (ซิงก์/เพียร์/ชุดปัจจุบัน) แล้วโหวตบน validator ทุกตัวในเครื่องนั้น และรอจนเห็นผลใน snapshot
 * คำสั่งมือเป็นทางสำรอง — ยืนยันธงกับ `polygon-edge ibft propose --help` ของเครื่องจริงแล้ว (2026-10-22):
 *   --addr  --bls (ต้องมีตอน auth เพราะ genesis เป็น validator_type: bls)  --vote auth|drop
 *   gRPC อยู่ที่ 127.0.0.1:10000 ในคอนเทนเนอร์ (ค่าเริ่มต้นของคำสั่งคือ 9632 ซึ่งผิด ต้องระบุเสมอ)
 *
 * Developed by Xman Studio
 */
final class ValidatorVoteCommands
{
    public const SCRIPT = 'sudo bash ~/TPIX-Coin/infrastructure/single-host/validator-vote.sh';

    /** validator ทั้งหมดของเชนอยู่บนเครื่องเดียว — ชื่อคอนเทนเนอร์ตาม single-host/docker-compose.yml */
    public const CONTAINERS = ['tpix-validator-1', 'tpix-validator-2', 'tpix-validator-3', 'tpix-validator-4'];

    private const GRPC = '127.0.0.1:10000';

    /**
     * @return array{script:string,lines:list<string>}
     */
    public static function auth(string $address, string $bls, ?string $nodeId = null): array
    {
        $args = '--addr '.$address.' --bls '.$bls.($nodeId ? ' --node-id '.$nodeId : '');

        return self::build('auth', $args, '--addr '.$address.' --bls '.$bls.' --vote auth');
    }

    /**
     * ถอดไม่ต้องใช้ BLS (polygon-edge รับเฉพาะที่อยู่ตอน drop).
     *
     * @return array{script:string,lines:list<string>}
     */
    public static function drop(string $address): array
    {
        return self::build('drop', '--addr '.$address, '--addr '.$address.' --vote drop');
    }

    /**
     * สิ่งที่เจ้าของต้องรู้ก่อนเพิ่มตัวใหม่ — ภาษาคน.
     *
     * @return list<string>
     */
    public static function addWarnings(int $setSize): array
    {
        $after = $setSize + 1;
        $warnings = [];
        if (IbftValidatorSet::faultTolerance($after) <= IbftValidatorSet::faultTolerance($setSize)) {
            $warnings[] = sprintf(
                'เพิ่มเป็น %d ตัว: ต้องเห็นพ้อง %d ตัว ทนดับได้ %d ตัวเท่าเดิม — ถ้าตัวใหม่ดับ validator เดิมต้องอยู่ครบทุกตัว ไม่งั้นเชนหยุด',
                $after,
                IbftValidatorSet::quorum($after),
                IbftValidatorSet::faultTolerance($after),
            );
        }
        $warnings[] = 'โหวตเข้าเมื่อโหนดใหม่ซิงก์ถึงบล็อกล่าสุดและเป็นเพียร์กับ validator เดิมแล้วเท่านั้น (สคริปต์ตรวจให้เมื่อใส่ --node-id)';
        $warnings[] = 'คะแนนโหวตที่ยังไม่ครบถูกล้างทุก 100,000 บล็อก (~55 ชม.) — ไม่ครบในรอบนั้นต้องโหวตใหม่';

        return $warnings;
    }

    /**
     * @return array{script:string,lines:list<string>}
     */
    private static function build(string $vote, string $scriptArgs, string $proposeArgs): array
    {
        $script = self::SCRIPT.' '.$vote.' '.$scriptArgs;
        $lines = [
            '# 1) ซ้อม (ตรวจอย่างเดียว ไม่ส่งอะไร) — บนเครื่องเชน: ssh tpixadmin@123.253.62.252',
            $script,
            '# 2) ทำจริง',
            $script.' --yes',
            '# สำรอง: สั่งเองทีละตัว (ต้องได้เกินครึ่งของชุดปัจจุบัน) แล้วตรวจด้วย ibft snapshot',
        ];
        foreach (self::CONTAINERS as $container) {
            $lines[] = 'sudo docker exec '.$container.' polygon-edge ibft propose '.$proposeArgs.' --grpc-address '.self::GRPC;
        }
        $lines[] = 'sudo docker exec '.self::CONTAINERS[0].' polygon-edge ibft snapshot --grpc-address '.self::GRPC;

        return ['script' => $script, 'lines' => $lines];
    }
}
