<?php

namespace App\Services\Stabilizer;

use App\Services\TpixDexService;
use kornrunner\Keccak;

/**
 * ประกอบ calldata ของ 3 ฟังก์ชันที่ Stability Reserve เรียกได้ — มีแค่นี้ ไม่มีอย่างอื่น.
 *
 *   approve(router, amount)                                  บน USDT_TPIX
 *   swapExactTokensForETH(amountIn, minOut, [USDT, WTPIX], wallet, deadline)   ซื้อ TPIX
 *   swapExactETHForTokens(minOut, [WTPIX, USDT], wallet, deadline) + value      ขาย TPIX
 *
 * ผู้รับเงินจากการสวอป (to) คือกระเป๋าของกองทุนเองเสมอ — สคริปต์เซ็นตรวจซ้ำอีกชั้น
 * selector คำนวณจากลายเซ็นด้วย keccak ไม่ hardcode (เคยมีคน hardcode ผิดทั้งไฟล์มาแล้ว)
 *
 * Developed by Xman Studio
 */
final class SwapCalldata
{
    public const SIG_APPROVE = 'approve(address,uint256)';

    public const SIG_BUY = 'swapExactTokensForETH(uint256,uint256,address[],address,uint256)';

    public const SIG_SELL = 'swapExactETHForTokens(uint256,address[],address,uint256)';

    public static function selector(string $signature): string
    {
        return '0x'.substr(Keccak::hash($signature, 256), 0, 8);
    }

    public static function approve(string $spender, string $amountWei): string
    {
        return self::selector(self::SIG_APPROVE)
            .TpixDexService::encAddress($spender)
            .TpixDexService::encUint($amountWei);
    }

    /** ซื้อ TPIX ด้วย USDT — path [USDT, WTPIX] router ถอด WTPIX เป็น TPIX native ให้ */
    public static function buy(string $usdtIn, string $minTpixOut, string $usdt, string $wtpix, string $recipient, int $deadline): string
    {
        // head 5 ช่อง: amountIn, amountOutMin, offset ของ path (5×32 = 0xa0), to, deadline
        return self::selector(self::SIG_BUY)
            .TpixDexService::encUint($usdtIn)
            .TpixDexService::encUint($minTpixOut)
            .TpixDexService::encUint('160')
            .TpixDexService::encAddress($recipient)
            .TpixDexService::encUint((string) $deadline)
            .self::path([$usdt, $wtpix]);
    }

    /** ขาย TPIX native ได้ USDT — จำนวน TPIX ส่งไปเป็น value ของธุรกรรม */
    public static function sell(string $minUsdtOut, string $wtpix, string $usdt, string $recipient, int $deadline): string
    {
        // head 4 ช่อง: amountOutMin, offset ของ path (4×32 = 0x80), to, deadline
        return self::selector(self::SIG_SELL)
            .TpixDexService::encUint($minUsdtOut)
            .TpixDexService::encUint('128')
            .TpixDexService::encAddress($recipient)
            .TpixDexService::encUint((string) $deadline)
            .self::path([$wtpix, $usdt]);
    }

    /** @param  string[]  $addresses */
    private static function path(array $addresses): string
    {
        $out = TpixDexService::encUint((string) count($addresses));
        foreach ($addresses as $address) {
            $out .= TpixDexService::encAddress($address);
        }

        return $out;
    }
}
