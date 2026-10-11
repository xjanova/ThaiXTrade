<?php

namespace App\Services\Bridge;

use App\Support\Wei;
use kornrunner\Keccak;

/**
 * ตัวเข้ารหัส/ถอด ABI เท่าที่สะพานใช้ — selector คำนวณจากลายเซ็นด้วย keccak เสมอ
 * (เคยมีคน hardcode selector ผิดทั้งไฟล์มาแล้ว ดู NodeRegistryContract).
 *
 * ทุกจำนวนเป็นสตริงทศนิยม คำนวณด้วย bcmath — ไม่ผ่าน int/float
 */
final class Abi
{
    public const TRANSFER_EVENT = 'Transfer(address,address,uint256)';

    public const BURNED_EVENT = 'Burned(address,uint256,uint256,string)';

    public const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

    /** @var array<string, string> */
    private static array $cache = [];

    public static function keccak(string $data): string
    {
        return Keccak::hash($data, 256);
    }

    /** '0x' + 4 ไบต์แรกของ keccak(ลายเซ็น) */
    public static function selector(string $signature): string
    {
        return self::$cache['sel:'.$signature] ??= '0x'.substr(self::keccak($signature), 0, 8);
    }

    /** topic0 ของ event */
    public static function topic(string $signature): string
    {
        return self::$cache['topic:'.$signature] ??= '0x'.self::keccak($signature);
    }

    public static function encAddress(string $address): string
    {
        return str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    /** จำนวนเต็มทศนิยม (สตริง) → 32 ไบต์ hex ไม่มี 0x */
    public static function encUint(string $decimal): string
    {
        if (! ctype_digit($decimal)) {
            throw new \InvalidArgumentException('encUint ต้องการจำนวนเต็มไม่ติดลบ');
        }

        $hex = '';
        $value = ltrim($decimal, '0');
        if ($value === '') {
            return str_repeat('0', 64);
        }

        while (bccomp($value, '0', 0) > 0) {
            $hex = dechex((int) bcmod($value, '16')).$hex;
            $value = bcdiv($value, '16', 0);
        }

        if (strlen($hex) > 64) {
            throw new \InvalidArgumentException('encUint เกิน uint256');
        }

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    public static function encBytes32(string $hex): string
    {
        $body = strtolower(substr($hex, 2));

        if (strlen($body) !== 64 || ! ctype_xdigit($body)) {
            throw new \InvalidArgumentException('encBytes32 ต้องการ 32 ไบต์');
        }

        return $body;
    }

    /** calldata ของ TPIXBridgeMinter.mint(address,uint256,bytes32) */
    public static function mintCalldata(string $to, string $units, string $depositId): string
    {
        return self::selector('mint(address,uint256,bytes32)')
            .self::encAddress($to).self::encUint($units).self::encBytes32($depositId);
    }

    /** calldata ของ ERC-20 transfer(address,uint256) */
    public static function transferCalldata(string $to, string $amountRaw): string
    {
        return self::selector('transfer(address,uint256)').self::encAddress($to).self::encUint($amountRaw);
    }

    /**
     * depositId = keccak256(abi.encode(uint256 chainId, bytes32 txHash, uint256 logIndex)).
     *
     * ผูกกับ (เชน, ธุรกรรม, ตำแหน่ง log) ซึ่งไม่ซ้ำกันทั้งจักรวาล — USDT_TPIX จดค่านี้ไว้ใน
     * processedTxHashes จึง mint ซ้ำจาก deposit เดิมไม่ได้แม้เซิร์ฟเวอร์จะพลาดเอง
     */
    public static function depositId(int $chainId, string $txHash, int $logIndex): string
    {
        $encoded = self::encUint((string) $chainId).self::encBytes32($txHash).self::encUint((string) $logIndex);

        return '0x'.self::keccak((string) hex2bin($encoded));
    }

    /** แยก payload เป็นคำละ 32 ไบต์ */
    public static function words(?string $raw): array
    {
        if (! is_string($raw) || strlen($raw) < 66 || ! str_starts_with($raw, '0x')) {
            return [];
        }

        return str_split(substr($raw, 2), 64);
    }

    public static function decUint(?string $raw): ?string
    {
        $words = self::words($raw);

        return $words === [] ? null : Wei::hexToInt('0x'.$words[0]);
    }

    public static function decBool(?string $raw): ?bool
    {
        $value = self::decUint($raw);

        return $value === null ? null : $value !== '0';
    }

    public static function decAddress(?string $raw): ?string
    {
        $words = self::words($raw);

        return $words === [] ? null : '0x'.substr($words[0], 24);
    }

    /** topic (32 ไบต์) → ที่อยู่ตัวพิมพ์เล็ก */
    public static function topicAddress(?string $topic): ?string
    {
        if (! is_string($topic) || strlen($topic) !== 66) {
            return null;
        }

        return '0x'.strtolower(substr($topic, 26));
    }

    /**
     * ถอด data ของ Burned(address indexed from, uint256 amount, uint256 indexed targetChainId, string targetAddress)
     * data = abi.encode(uint256 amount, string targetAddress).
     *
     * @return array{amount: string, target: string}|null
     */
    public static function decodeBurnData(?string $data): ?array
    {
        $words = self::words($data);
        if (count($words) < 3) {
            return null;
        }

        $amount = Wei::hexToInt('0x'.$words[0]);
        $offset = Wei::hexToInt('0x'.$words[1]);
        if (bccomp($offset, '0', 0) < 0 || bcmod($offset, '32') !== '0') {
            return null;
        }

        $index = (int) bcdiv($offset, '32', 0);
        if (! isset($words[$index])) {
            return null;
        }

        $length = Wei::hexToInt('0x'.$words[$index]);
        // ที่อยู่ปลายทางยาวเกินนี้ไม่มีทางเป็นที่อยู่ EVM — ตัดไว้เก็บเป็นหลักฐานพอ ไม่ต้องอ่านทั้งก้อน
        $take = bccomp($length, '4096', 0) > 0 ? 4096 : (int) $length;
        $hex = implode('', array_slice($words, $index + 1));
        $bytes = @hex2bin(substr($hex, 0, $take * 2));

        if ($bytes === false) {
            return null;
        }

        return ['amount' => $amount, 'target' => $bytes];
    }

    /** ที่อยู่ EVM ที่ใช้ได้จริง (ไม่ใช่ศูนย์ และ checksum ถูกถ้าพิมพ์ตัวพิมพ์ผสมมา) */
    public static function isValidAddress(string $address): bool
    {
        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return false;
        }

        if (strtolower($address) === self::ZERO_ADDRESS) {
            return false;
        }

        $body = substr($address, 2);

        // ตัวเล็กล้วน/ตัวใหญ่ล้วน = ไม่ได้ใส่ checksum มา ยอมรับ
        if ($body === strtolower($body) || $body === strtoupper($body)) {
            return true;
        }

        return self::checksum($address) === $address;
    }

    /** EIP-55 */
    public static function checksum(string $address): string
    {
        $lower = strtolower(substr($address, 2));
        $hash = self::keccak($lower);
        $out = '0x';

        for ($i = 0; $i < 40; $i++) {
            $char = $lower[$i];
            $out .= ctype_alpha($char) && hexdec($hash[$i]) >= 8 ? strtoupper($char) : $char;
        }

        return $out;
    }

    /**
     * หน่วยดิบของเหรียญ → หน่วย 6 ทศนิยม (ตัดเศษทิ้ง) + เศษที่ตัด.
     *
     * @return array{units: string, dust: string}
     */
    public static function toUnits6(string $raw, int $decimals): array
    {
        if ($decimals === 6) {
            return ['units' => $raw, 'dust' => '0'];
        }

        if ($decimals > 6) {
            $div = bcpow('10', (string) ($decimals - 6), 0);

            return ['units' => bcdiv($raw, $div, 0), 'dust' => bcmod($raw, $div)];
        }

        return ['units' => bcmul($raw, bcpow('10', (string) (6 - $decimals), 0), 0), 'dust' => '0'];
    }

    /** หน่วย 6 ทศนิยม → หน่วยดิบของเหรียญปลายทาง (ทศนิยมน้อยกว่า 6 = ตัดเศษ ปัดลงเสมอ ไม่สร้างเงินเพิ่ม) */
    public static function fromUnits6(string $units, int $decimals): string
    {
        if ($decimals >= 6) {
            return bcmul($units, bcpow('10', (string) ($decimals - 6), 0), 0);
        }

        return bcdiv($units, bcpow('10', (string) (6 - $decimals), 0), 0);
    }
}
