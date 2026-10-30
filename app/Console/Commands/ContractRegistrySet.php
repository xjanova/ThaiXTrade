<?php

namespace App\Console\Commands;

use App\Services\ContractRegistry;
use Illuminate\Console\Command;

/**
 * ตั้งที่อยู่สัญญาจากเซิร์ฟเวอร์ — ทางเดียวที่ข้ามลายนิ้วมือ bytecode ได้.
 *
 * API ใช้ token ที่อยู่ในสคริปต์ deploy ถ้ารั่วต้องชี้สัญญาไปตัวปลอมไม่ได้
 * การย้ายไปสัญญาคนละตัวจึงต้องเข้าถึงเซิร์ฟเวอร์ และมีคนกดยืนยันเองเท่านั้น
 */
class ContractRegistrySet extends Command
{
    protected $signature = 'tpix:contract-registry-set
                            {key : ชื่อสัญญาใน ContractRegistry::CONTRACTS}
                            {address : ที่อยู่สัญญา}
                            {--force : ย้ายไปสัญญาที่ bytecode ต่างจากตัวที่ลงทะเบียนไว้}';

    protected $description = 'ตั้งที่อยู่สัญญาในทะเบียน (ใช้ --force เมื่ออัปเกรดเป็นสัญญาคนละตัวจริง ๆ)';

    public function handle(ContractRegistry $registry): int
    {
        $key = (string) $this->argument('key');
        $address = (string) $this->argument('address');
        $force = (bool) $this->option('force');

        if (! $registry->hasCode($address)) {
            $this->error("eth_getCode คืน 0x — ไม่มีสัญญาอยู่ที่ {$address}");

            return self::FAILURE;
        }

        // ไม่มีคนตอบ (--no-interaction) ถือว่าไม่ยืนยัน
        if ($force && ! $this->confirm("ย้าย {$key} ไป {$address} โดยข้ามลายนิ้วมือ bytecode?")) {
            $this->warn('ยกเลิก ไม่ได้เปลี่ยนอะไร');

            return self::FAILURE;
        }

        $result = $registry->set($key, $address, $force);

        if (! $result['ok']) {
            $this->error((string) $result['message']);

            return self::FAILURE;
        }

        $this->info("{$key}: ".($result['previous'] ?? '(ว่าง)')." -> {$address}");

        return self::SUCCESS;
    }
}
