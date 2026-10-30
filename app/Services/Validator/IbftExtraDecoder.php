<?php

namespace App\Services\Validator;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * IbftExtraDecoder — อ่าน extraData ของบล็อก polygon-edge IBFT (เชน TPIX ใช้ validator_type: bls).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  รูปแบบที่ยืนยันกับบล็อกจริงของเชน 4289 (2026-10-22)
 * ═══════════════════════════════════════════════════════════════════════════
 *   [0:32]  vanity (ศูนย์ล้วน)
 *   [32:]   RLP([
 *             validators       — [[address 20B, blsPubKey 48B], ...]
 *             proposerSeal     — ลายเซ็น ECDSA 65 ไบต์ ของผู้เสนอบล็อก
 *             committedSeals   — [bitmap, aggregatedBlsSig 96B]  ← ใครเซ็นรับรองบล็อกนี้
 *             parentCommitted  — [bitmap, sig]                   ← ใครเซ็นรับรองบล็อกก่อนหน้า
 *             roundNumber      — uint64 8 ไบต์ (0 = ผู้เสนอคิวแรกทำหน้าที่ทัน)
 *           ])
 *
 *  ผู้เสนอบล็อก = ecrecover(keccak256(blockHash), proposerSeal)
 *  (polygon-edge ให้ blockHash คือแฮชของหัวบล็อกที่ตัด seal ออกแล้ว และเซ็นที่ keccak ของมันอีกชั้น
 *   ลองกับ 41 บล็อกจริงได้ลำดับผู้เสนอ 3-0-1-2 วนครบทุกบล็อก)
 *
 *  bitmap: บิตที่ i = validator ลำดับที่ i ของชุดในบล็อกเดียวกันเซ็นแล้ว
 *          ต้องการแค่ครบ quorum จึงปกติที่บางบล็อกไม่มีบางตัว — ตัวที่ "ไม่เคยมี" เลยคือตัวที่ดับ
 *
 *  RPC ฟิลด์ miner เป็น 0x0 เสมอบนเชนนี้ — ห้ามใช้หาคนปิดบล็อก
 *
 * Developed by Xman Studio
 */
class IbftExtraDecoder
{
    private const VANITY_BYTES = 32;

    /**
     * @return array{
     *     validators: list<array{address:string,bls:?string}>,
     *     proposer_seal: string,
     *     signers: list<int>,
     *     parent_signers: list<int>,
     *     round: int
     * }|null null = ไม่ใช่ extraData ของ IBFT หรือข้อมูลเพี้ยน
     */
    public function decode(?string $extraData): ?array
    {
        if (! is_string($extraData) || ! preg_match('/^0x([0-9a-fA-F]{2})+$/', $extraData)) {
            return null;
        }

        $bin = hex2bin(substr($extraData, 2));
        if ($bin === false || strlen($bin) <= self::VANITY_BYTES) {
            return null;
        }

        try {
            $items = Rlp::decode(substr($bin, self::VANITY_BYTES));
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (! is_array($items) || count($items) < 3 || ! is_array($items[0])) {
            return null;
        }

        $validators = [];
        foreach ($items[0] as $entry) {
            $validator = $this->validatorEntry($entry);
            if ($validator === null) {
                return null;
            }
            $validators[] = $validator;
        }

        return [
            'validators' => $validators,
            'proposer_seal' => is_string($items[1]) ? $items[1] : '',
            'signers' => $this->bitmapIndexes($items[2] ?? null, count($validators)),
            'parent_signers' => $this->bitmapIndexes($items[3] ?? null, null),
            'round' => isset($items[4]) && is_string($items[4]) ? $this->uint($items[4]) : 0,
        ];
    }

    /**
     * ที่อยู่ของผู้เสนอบล็อก (ตัวพิมพ์เล็ก) — null ถ้ากู้ลายเซ็นไม่ได้.
     *
     * @param  string  $blockHash  hash ของบล็อกจาก RPC (0x + 64 hex)
     * @param  string  $proposerSeal  ไบต์ดิบ 65 ไบต์จาก decode()['proposer_seal']
     */
    public function proposer(string $blockHash, string $proposerSeal): ?string
    {
        if (! preg_match('/^0x[0-9a-fA-F]{64}$/', $blockHash) || strlen($proposerSeal) !== 65) {
            return null;
        }

        try {
            $digest = Keccak::hash(hex2bin(substr($blockHash, 2)), 256);
            $sealHex = bin2hex($proposerSeal);
            $v = ord($proposerSeal[64]);
            $recovery = $v >= 27 ? $v - 27 : $v;
            if ($recovery > 1) {
                return null;
            }

            $ec = new EC('secp256k1');
            $pub = $ec->recoverPubKey($digest, [
                'r' => substr($sealHex, 0, 64),
                's' => substr($sealHex, 64, 64),
            ], $recovery);

            $pubHex = $pub->encode('hex');

            return '0x'.substr(Keccak::hash(hex2bin(substr($pubHex, 2)), 256), -40);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ผู้เสนอตามกติกาหมุนคิวของ polygon-edge (CalcProposer) — ไม่ต้องกู้ลายเซ็น.
     *
     *   seed = (ลำดับของผู้เสนอบล็อกก่อนในชุดปัจจุบัน หรือ 0 ถ้าไม่อยู่ในชุด) + round + 1
     *   ผู้เสนอ = validators[seed % จำนวน]
     *
     * ecrecover ด้วย bcmath ใช้ ~190ms ต่อบล็อก (เซิร์ฟเวอร์ไม่มี ext-gmp) — ใช้สูตรนี้เป็นหลัก
     * แล้วกู้ลายเซ็นจริงเป็นจุดยึด/จุดตรวจ (ดู SealMonitor)
     *
     * @param  list<string>  $validators
     */
    public static function expectedProposer(array $validators, ?string $lastProposer, int $round): ?string
    {
        $count = count($validators);
        if ($count === 0 || $lastProposer === null) {
            return null;
        }
        $index = array_search($lastProposer, $validators, true);

        return $validators[(($index === false ? 0 : $index) + $round + 1) % $count];
    }

    /**
     * ถอดบล็อกจาก RPC (eth_getBlockByNumber) เป็นสรุปเดียว — ใช้โดย SealMonitor และหน้าแอดมิน.
     *
     * @param  array<string,mixed>  $block
     * @param  bool  $recoverProposer  false = ไม่กู้ลายเซ็น (proposer เป็น null ให้ผู้เรียกคำนวณเอง)
     * @return array{number:int,hash:string,timestamp:int,validators:list<array{address:string,bls:?string}>,proposer:?string,proposer_seal:string,signers:list<string>,round:int}|null
     */
    public function summarize(array $block, bool $recoverProposer = true): ?array
    {
        $extra = $this->decode($block['extraData'] ?? null);
        $hash = (string) ($block['hash'] ?? '');
        if ($extra === null || ! preg_match('/^0x[0-9a-fA-F]{64}$/', $hash)) {
            return null;
        }

        $signers = [];
        foreach ($extra['signers'] as $i) {
            if (isset($extra['validators'][$i])) {
                $signers[] = $extra['validators'][$i]['address'];
            }
        }

        return [
            'number' => (int) hexdec(substr((string) ($block['number'] ?? '0x0'), 2)),
            'hash' => strtolower($hash),
            'timestamp' => (int) hexdec(substr((string) ($block['timestamp'] ?? '0x0'), 2)),
            'validators' => $extra['validators'],
            'proposer' => $recoverProposer ? $this->proposer($hash, $extra['proposer_seal']) : null,
            'proposer_seal' => $extra['proposer_seal'],
            'signers' => $signers,
            'round' => $extra['round'],
        ];
    }

    /**
     * @return array{address:string,bls:?string}|null
     */
    private function validatorEntry(mixed $entry): ?array
    {
        // validator_type: ecdsa — ที่อยู่เปล่า
        if (is_string($entry) && strlen($entry) === 20) {
            return ['address' => '0x'.bin2hex($entry), 'bls' => null];
        }

        // validator_type: bls — [address, blsPubKey 48 ไบต์]
        if (is_array($entry) && count($entry) === 2
            && is_string($entry[0]) && strlen($entry[0]) === 20
            && is_string($entry[1]) && strlen($entry[1]) === 48) {
            return ['address' => '0x'.bin2hex($entry[0]), 'bls' => '0x'.bin2hex($entry[1])];
        }

        return null;
    }

    /**
     * ดัชนี validator ที่บิตถูกตั้งใน AggregatedSeal — ลิสต์ว่างถ้าไม่ใช่ seal แบบ BLS.
     *
     * @return list<int>
     */
    private function bitmapIndexes(mixed $seal, ?int $limit): array
    {
        if (! is_array($seal) || count($seal) !== 2 || ! is_string($seal[0])) {
            return [];
        }

        $bitmap = $seal[0];
        $indexes = [];
        $bytes = strlen($bitmap);
        for ($k = 0; $k < $bytes; $k++) {
            $byte = ord($bitmap[$bytes - 1 - $k]);
            for ($bit = 0; $bit < 8; $bit++) {
                if (($byte >> $bit) & 1) {
                    $indexes[] = $k * 8 + $bit;
                }
            }
        }

        if ($limit !== null) {
            $indexes = array_values(array_filter($indexes, fn (int $i) => $i < $limit));
        }

        return $indexes;
    }

    private function uint(string $bytes): int
    {
        $n = 0;
        foreach (str_split($bytes) as $ch) {
            $n = ($n << 8) | ord($ch);
        }

        return $n;
    }
}
