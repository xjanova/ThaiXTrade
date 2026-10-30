<?php

namespace App\Services\Bridge\Wtpix;

use App\Services\Bridge\Abi;

/**
 * ลายเซ็น event/ฟังก์ชันของ TPIXBridgeVault · WTPIXBridgeMinter · wTPIX (TPIX-Coin main 36a4431)
 * — selector/topic คำนวณจากลายเซ็นด้วย keccak เสมอ (ผ่าน Abi) ไม่ hardcode hex.
 */
final class WtpixAbi
{
    /** TPIXBridgeVault: event Locked(address indexed from, uint256 amount, uint256 indexed nonce) */
    public const LOCKED_EVENT = 'Locked(address,uint256,uint256)';

    /** TPIXBridgeVault: event Released(address indexed relayer, address indexed to, uint256 amount, bytes32 indexed depositId, uint256 day, uint256 releasedThatDay) */
    public const RELEASED_EVENT = 'Released(address,address,uint256,bytes32,uint256,uint256)';

    /** WTPIXBridgeMinter: event BridgeMinted(address indexed relayer, address indexed to, uint256 amount, bytes32 indexed depositId, uint256 day, uint256 mintedThatDay) */
    public const BRIDGE_MINTED_EVENT = 'BridgeMinted(address,address,uint256,bytes32,uint256,uint256)';

    /** wTPIX: event MinterQueued(address indexed minter, uint256 executeAfter) */
    public const MINTER_QUEUED_EVENT = 'MinterQueued(address,uint256)';

    /** wTPIX: event MinterSet(address indexed minter, bool status) */
    public const MINTER_SET_EVENT = 'MinterSet(address,bool)';

    /** topic ของที่อยู่ศูนย์ — Transfer(x, 0x0) = เผา · Transfer(0x0, x) = mint */
    public static function zeroTopic(): string
    {
        return '0x'.str_repeat('0', 64);
    }

    /** calldata ของ WTPIXBridgeMinter.mint(address,uint256,bytes32) — ลายเซ็นเดียวกับ minter ของสะพาน USDT */
    public static function mintCalldata(string $to, string $wei, string $depositId): string
    {
        return Abi::mintCalldata($to, $wei, $depositId);
    }

    /** calldata ของ TPIXBridgeVault.release(address,uint256,bytes32) */
    public static function releaseCalldata(string $to, string $wei, string $depositId): string
    {
        return Abi::selector('release(address,uint256,bytes32)')
            .Abi::encAddress($to).Abi::encUint($wei).Abi::encBytes32($depositId);
    }
}
