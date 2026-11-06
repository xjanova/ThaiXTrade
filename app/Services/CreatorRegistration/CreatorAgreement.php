<?php

namespace App\Services\CreatorRegistration;

use RuntimeException;

/**
 * ข้อตกลงผู้สร้างเหรียญ — ฉบับ เนื้อหา และค่าแฮชที่ผู้สร้างเซ็น.
 *
 * เนื้อหาแต่ละฉบับอยู่ใน resources/legal/token-creator-agreement/<version>.php
 * ค่าแฮชถูกล็อกไว้ในค่าคงที่ VERSIONS — ถ้ามีคนแก้ไฟล์ฉบับเก่า hash() จะโยน exception
 * แทนที่จะคืนแฮชใหม่เงียบๆ (ลายเซ็นเก่าจะชี้ไปหาเนื้อหาที่ไม่มีใครเคยเซ็น)
 *
 * แฮช = SHA-256 ของ JSON (UTF-8 ไม่ escape) ของอาร์เรย์ทั้งไฟล์ — คำนวณซ้ำได้จากไฟล์ใน git
 *
 * Developed by Xman Studio.
 */
class CreatorAgreement
{
    public const CURRENT = '2026-11-06.2';

    /** ฉบับ => SHA-256 ที่ล็อกไว้ (เพิ่มฉบับใหม่ได้ ห้ามแก้ของเดิม) */
    public const VERSIONS = [
        '2026-11-06' => '452b395d5dc9ca9814e27670b6de06bcac942cff002c53fbff98723077fe2958',
        // หน้าที่ 10 หมวด + กติกากันลักพูลชั้น 1/2 + เอกสารและการเผยแพร่ (ตัวเลขจาก App\Support\LaunchpadRules)
        '2026-11-06.2' => '1c19f25b02dbd766413398258a185ebc6ffb90ccb78463202f219b3256af6b0c',
    ];

    /** @var array<string, array> */
    private array $loaded = [];

    public function currentVersion(): string
    {
        return self::CURRENT;
    }

    public function exists(string $version): bool
    {
        return array_key_exists($version, self::VERSIONS);
    }

    /**
     * เนื้อหาเต็มของฉบับ — โยน exception ถ้าเนื้อหาไม่ตรงแฮชที่ล็อกไว้.
     */
    public function document(?string $version = null): array
    {
        $version ??= self::CURRENT;

        if (! $this->exists($version)) {
            throw new RuntimeException("Unknown creator agreement version {$version}");
        }

        if (! isset($this->loaded[$version])) {
            $doc = require resource_path("legal/token-creator-agreement/{$version}.php");

            if (! is_array($doc) || ($doc['version'] ?? null) !== $version) {
                throw new RuntimeException("Creator agreement file {$version} is malformed");
            }

            $actual = self::computeHash($doc);
            if (! hash_equals(self::VERSIONS[$version], $actual)) {
                throw new RuntimeException("Creator agreement {$version} was edited after publishing (hash {$actual})");
            }

            $this->loaded[$version] = $doc;
        }

        return $this->loaded[$version];
    }

    public function hash(?string $version = null): string
    {
        $version ??= self::CURRENT;
        $this->document($version);

        return self::VERSIONS[$version];
    }

    public static function computeHash(array $document): string
    {
        return hash('sha256', json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
