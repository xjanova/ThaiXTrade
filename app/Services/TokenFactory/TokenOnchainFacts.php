<?php

namespace App\Services\TokenFactory;

use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use kornrunner\Keccak;
use RuntimeException;

/**
 * ข้อเท็จจริงของเหรียญที่อ่านจากสัญญาบนเชนโดยตรง — ใช้ทำไวท์เปเปอร์มาตรฐานและป้ายความเสี่ยง.
 *
 * อ่าน name/symbol/decimals/totalSupply/owner และสวิตช์ของ FactoryERC20V2
 * (isMintable/isPausable/isBlacklistEnabled/isAutoBurnEnabled — ค่า immutable ในสัญญา)
 * ค่าที่อ่านไม่ได้ (สัญญาไม่มีฟังก์ชันนั้น / RPC ล่ม) เป็น null — หน้าเว็บต้องบอกว่า "อ่านจากเชนไม่ได้"
 * ไม่เดาจากฐานข้อมูลแล้วติดป้ายว่ามาจากเชน
 *
 * Developed by Xman Studio.
 */
class TokenOnchainFacts
{
    public const CACHE_TTL = 600;

    private const FLAGS = [
        'mintable' => 'isMintable()',
        'pausable' => 'isPausable()',
        'blacklist' => 'isBlacklistEnabled()',
        'auto_burn' => 'isAutoBurnEnabled()',
    ];

    public function __construct(private readonly TpixRpcClient $rpc) {}

    /**
     * @return array{read: bool, name: ?string, symbol: ?string, decimals: ?int, total_supply: ?string,
     *               owner: ?string, renounced: ?bool, flags: array<string, ?bool>, read_at: string}
     */
    public function for(string $contract): array
    {
        $contract = strtolower($contract);

        return Cache::remember("token_onchain_facts:{$contract}", self::CACHE_TTL, fn () => $this->read($contract));
    }

    private function read(string $contract): array
    {
        $decimals = $this->uint($contract, 'decimals()');
        $supplyHex = $this->raw($contract, 'totalSupply()');
        $owner = $this->address($contract, 'owner()');

        $flags = [];
        foreach (self::FLAGS as $key => $signature) {
            $flags[$key] = $this->bool($contract, $signature);
        }

        $name = $this->string($contract, 'name()');

        return [
            'read' => $name !== null || $supplyHex !== null,
            'name' => $name,
            'symbol' => $this->string($contract, 'symbol()'),
            'decimals' => $decimals,
            'total_supply' => $supplyHex !== null && $decimals !== null && $decimals <= 36
                ? Wei::hexToDecimal($supplyHex, $decimals)
                : null,
            'owner' => $owner,
            'renounced' => $owner === null ? null : $owner === '0x'.str_repeat('0', 40),
            'flags' => $flags,
            'read_at' => now()->toIso8601String(),
        ];
    }

    public static function selector(string $signature): string
    {
        return '0x'.substr(Keccak::hash($signature, 256), 0, 8);
    }

    /** ผลดิบ 32 ไบต์แรกขึ้นไป — null เมื่ออ่านไม่ได้ */
    private function raw(string $contract, string $signature): ?string
    {
        try {
            $result = $this->rpc->call('eth_call', [['to' => $contract, 'data' => self::selector($signature)], 'latest']);
        } catch (RuntimeException) {
            return null;
        }

        return is_string($result) && preg_match('/^0x[0-9a-fA-F]{64,}$/', $result) ? $result : null;
    }

    private function uint(string $contract, string $signature): ?int
    {
        $raw = $this->raw($contract, $signature);
        if ($raw === null) {
            return null;
        }
        $word = ltrim(substr($raw, 2, 64), '0');

        return strlen($word) > 8 ? null : (int) hexdec($word === '' ? '0' : $word);
    }

    private function bool(string $contract, string $signature): ?bool
    {
        $raw = $this->raw($contract, $signature);
        if ($raw === null) {
            return null;
        }
        $word = substr($raw, 2, 64);

        return match (ltrim($word, '0')) {
            '' => false,
            '1' => true,
            default => null,
        };
    }

    private function address(string $contract, string $signature): ?string
    {
        $raw = $this->raw($contract, $signature);

        return $raw === null ? null : '0x'.strtolower(substr($raw, 26, 40));
    }

    /** string แบบ ABI (offset → length → bytes) — ข้อความเพี้ยน/ยาวเกินคืน null */
    private function string(string $contract, string $signature): ?string
    {
        $raw = $this->raw($contract, $signature);
        if ($raw === null || strlen($raw) < 2 + 128) {
            return null;
        }

        $hex = substr($raw, 2);
        $offset = (int) hexdec(substr($hex, 0, 64)) * 2;
        $length = (int) hexdec(substr($hex, $offset, 64));
        if ($length < 1 || $length > 200) {
            return null;
        }

        $bytes = hex2bin(substr($hex, $offset + 64, $length * 2));
        if ($bytes === false || ! mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        return preg_replace('/[\x00-\x1F\x7F]/', '', $bytes);
    }
}
