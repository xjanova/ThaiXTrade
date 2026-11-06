<?php

namespace App\Support;

/**
 * กติกากันลักพูลชั้นที่ 1 (บนเชน) — สำเนาค่าคงที่ของสัญญา LaunchpadFactory / PoolFeeder.
 *
 * ที่มา: xjanova/TPIX-Coin PR #19 (branch feat/launchpad-pool-feeder, commit 1b51665)
 *   contracts/src/launch/LaunchpadFactory.sol — MIN_LP_LOCK = 180 days,
 *     MIN_POOL_SHARE_BPS = 5000, MIN_LOCKED_SUPPLY_BPS = 8000, CATEGORY_ERC20V2 = 0
 *   contracts/src/launch/PoolFeeder.sol — MAX_BAND_BPS = 2000 (LaunchpadFactory DEFAULT_BAND_BPS = 300)
 *
 * ⚠️ ข้อตกลงผู้สร้างเหรียญ (resources/legal/token-creator-agreement/*.php) พิมพ์ตัวเลขจากค่าคงที่ชุดนี้
 *    และข้อตกลงแต่ละฉบับตรึง SHA-256 ไว้ — แก้ค่าตรงนี้แล้วฉบับที่อ้างค่าเหล่านี้จะโหลดไม่ผ่าน (CreatorAgreementTest แดง)
 *    นั่นคือตั้งใจ: กติกาบนเชนเปลี่ยน = ต้องออกข้อตกลงฉบับใหม่ให้ผู้สร้างเซ็นใหม่ ไม่ใช่แก้ตัวเลขเงียบๆ
 *    แก้ค่าที่นี่ต้องแก้สัญญาใน TPIX-Coin ก่อน และอัปเดต CONTRACT_REF ให้ชี้ commit ใหม่
 *
 * Developed by Xman Studio.
 */
final class LaunchpadRules
{
    public const CONTRACT_REF = 'xjanova/TPIX-Coin PR #19 @ 1b51665 (LaunchpadFactory.sol, PoolFeeder.sol, FixedPriceSale.sol, TrancheVesting.sol)';

    /** LP ล็อกอย่างน้อยกี่วันหลังปิดการขาย (MIN_LP_LOCK = 180 days) */
    public const MIN_LP_LOCK_DAYS = 180;

    /** ยอดซื้อแต่ละครั้งต้องเข้าพูลอย่างน้อย (MIN_POOL_SHARE_BPS) */
    public const MIN_POOL_SHARE_BPS = 5000;

    /** ณ วันปลด LP ซัพพลายที่ต้องอยู่ในการขาย ทุนสำรองพูล หรือ vesting ที่ยังล็อก (MIN_LOCKED_SUPPLY_BPS) */
    public const MIN_LOCKED_SUPPLY_BPS = 8000;

    /** กรอบราคาเริ่มต้นที่ feeder ยอมเติมพูล เทียบราคาขาย (DEFAULT_BAND_BPS) */
    public const DEFAULT_BAND_BPS = 300;

    /** กรอบราคากว้างสุดที่ตั้งได้ (PoolFeeder.MAX_BAND_BPS) */
    public const MAX_BAND_BPS = 2000;

    public static function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }

    /** ผู้สร้างถือแบบไม่ล็อกได้ไม่เกิน (100% − MIN_LOCKED_SUPPLY) */
    public static function maxUnlockedPercent(): string
    {
        return self::percent(10000 - self::MIN_LOCKED_SUPPLY_BPS);
    }
}
