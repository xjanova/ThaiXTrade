<?php

namespace Tests\Support;

use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use App\Services\Validator\ValidatorKycContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use kornrunner\Keccak;

/**
 * เชน TPIX จำลองสำหรับงาน validator — ตอบ eth_call ตาม selector จริง (keccak)
 * ตัวถอด ABI ของเว็บจึงถูกทดสอบจริง ไม่ได้ mock ผลลัพธ์ทิ้ง.
 *
 * บล็อกมาจาก fixture เชนจริง (tests/Fixtures/ibft) — ชุด validator 4 ตัวของจริง
 */
trait FakesValidatorChain
{
    protected string $kycAddress = '0x443e487ca031fae41a285cf35bd89e90be7340e7';

    protected string $registryAddress = '0x9c36000000000000000000000000000000000001';

    protected string $kycOwner = '0xd2ea000000000000000000000000000000000001';

    /** @var array<string,array<string,mixed>> applicant => record */
    protected array $kycRecords = [];

    /** @var array<string,array{tier:int,status:int}> operator => node */
    protected array $registryNodes = [];

    /** @var array<string,array<string,mixed>> txHash => tx */
    protected array $chainTxs = [];

    /** @var array<int,array<string,mixed>> number => block */
    protected array $chainBlocks = [];

    protected ?int $chainHead = null;

    protected bool $chainDown = false;

    private bool $validatorChainInstalled = false;

    /** @var array<string,string> */
    private array $addressCache = [];

    protected function registerValidatorContracts(): void
    {
        SiteSetting::set(ContractRegistry::GROUP, 'validator_kyc', $this->kycAddress);
        SiteSetting::set(ContractRegistry::GROUP, 'masternode_registry', $this->registryAddress);
        Cache::flush();
    }

    /** บล็อกจริงจาก fixture — คืนรายการตามลำดับ */
    protected function fixtureBlocks(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ibft/tpix-blocks-3207988.json')), true);
    }

    protected function useFixtureBlocks(): void
    {
        foreach ($this->fixtureBlocks() as $b) {
            $this->chainBlocks[(int) hexdec(substr($b['number'], 2))] = $b;
        }
        $this->chainHead = max(array_keys($this->chainBlocks));
    }

    /**
     * บล็อกสังเคราะห์ที่ถอดได้เหมือนของจริง — ผู้เสนอเซ็น proposerSeal ด้วยกุญแจจริง
     * (ต้องใช้ trait SignsWithTestKeys ด้วย).
     *
     * @param  list<string>  $keys  กุญแจส่วนตัวของ validator ตามลำดับในชุด
     * @param  list<int>  $signers  ดัชนีที่เซ็นรับรองบล็อกนี้
     */
    protected function makeBlock(int $number, array $keys, int $proposer, array $signers, int $round = 0): array
    {
        $validators = [];
        foreach ($keys as $i => $key) {
            // ที่อยู่จากกุญแจคำนวณครั้งเดียว — bcmath ช้า และเชนสังเคราะห์มีหลายร้อยบล็อก
            $this->addressCache[$key] ??= $this->addressOf($key);
            $validators[] = [hex2bin(substr($this->addressCache[$key], 2)), str_repeat(chr(0x80 + $i), 48)];
        }
        $bitmap = 0;
        foreach ($signers as $i) {
            $bitmap |= 1 << $i;
        }
        $hash = '0x'.hash('sha256', 'block-'.$number);
        $seal = hex2bin(substr($this->signDigest($keys[$proposer], Keccak::hash(hex2bin(substr($hash, 2)), 256)), 2));
        $aggregated = [$bitmap === 0 ? '' : chr($bitmap), str_repeat("\x01", 96)];
        $extra = str_repeat("\x00", 32).$this->rlp([$validators, $seal, $aggregated, $aggregated, pack('J', $round)]);

        return [
            'number' => '0x'.dechex($number),
            'hash' => $hash,
            'timestamp' => '0x'.dechex(1761100000 + $number * 2),
            'extraData' => '0x'.bin2hex($extra),
        ];
    }

    /** ชุด validator ของ "บล็อกล่าสุด" = กุญแจที่ให้มา */
    protected function chainWithValidators(array $keys, int $head = 1000): void
    {
        $this->chainBlocks[$head] = $this->makeBlock($head, $keys, 0, range(0, count($keys) - 1));
        $this->chainHead = $head;
        Cache::forget('validators:ibft-set');
    }

    protected function rlp(string|array $value): string
    {
        if (is_string($value)) {
            if (strlen($value) === 1 && ord($value) < 0x80) {
                return $value;
            }

            return $this->rlpLength(strlen($value), 0x80).$value;
        }
        $payload = implode('', array_map(fn ($v) => $this->rlp($v), $value));

        return $this->rlpLength(strlen($payload), 0xC0).$payload;
    }

    private function rlpLength(int $len, int $offset): string
    {
        if ($len < 56) {
            return chr($offset + $len);
        }
        $bin = ltrim(pack('J', $len), "\x00");

        return chr($offset + 55 + strlen($bin)).$bin;
    }

    protected function kycApplicant(string $address, string $status, array $extra = []): void
    {
        $this->kycRecords[strtolower($address)] = array_merge([
            'status' => array_search($status, ValidatorKycContract::STATUSES, true),
            'kyc_hash' => '0x'.str_repeat('0', 64),
            'consent_at' => 1761000000,
            'submitted_at' => 0,
            'reviewed_at' => 0,
            'reviewer' => '0x'.str_repeat('0', 40),
            'reason' => '',
        ], $extra);
    }

    /** tier 3 = Validator · status 1 = Active */
    protected function registryNode(string $operator, int $tier = 3, int $status = 1): void
    {
        $this->registryNodes[strtolower($operator)] = ['tier' => $tier, 'status' => $status];
    }

    protected function chainTx(string $hash, string $from, string $to, string $input, bool $success = true): void
    {
        $this->chainTxs[strtolower($hash)] = [
            'hash' => strtolower($hash),
            'from' => strtolower($from),
            'to' => strtolower($to),
            'input' => $input,
            'status' => $success ? '0x1' : '0x0',
            'blockNumber' => '0x30f350',
        ];
    }

    protected function fakeValidatorChain(): void
    {
        if ($this->validatorChainInstalled) {
            return;
        }
        $this->validatorChainInstalled = true;

        Http::fake(function (Request $request) {
            if ($this->chainDown) {
                return Http::response('bad gateway', 502);
            }
            $body = $request->data();
            $result = $this->answerRpc((string) ($body['method'] ?? ''), $body['params'] ?? []);

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    private function answerRpc(string $method, array $params): mixed
    {
        return match ($method) {
            'eth_blockNumber' => $this->chainHead === null ? null : '0x'.dechex($this->chainHead),
            'eth_getBlockByNumber' => $this->answerBlock($params[0] ?? 'latest'),
            'eth_getTransactionByHash' => isset($this->chainTxs[strtolower($params[0] ?? '')])
                ? $this->chainTxs[strtolower($params[0])] : null,
            'eth_getTransactionReceipt' => isset($this->chainTxs[strtolower($params[0] ?? '')])
                ? ['status' => $this->chainTxs[strtolower($params[0])]['status'], 'blockNumber' => '0x30f350']
                : null,
            'eth_call' => $this->answerCall(strtolower($params[0]['to'] ?? ''), strtolower($params[0]['data'] ?? '')),
            default => null,
        };
    }

    private function answerBlock(string $tag): ?array
    {
        $n = $tag === 'latest' ? $this->chainHead : (int) hexdec(substr($tag, 2));

        return $n === null ? null : ($this->chainBlocks[$n] ?? null);
    }

    private function answerCall(string $to, string $data): string
    {
        $sel = substr($data, 0, 10);
        $arg = substr($data, 10);
        $s = fn (string $sig) => ValidatorKycContract::selector($sig);

        if ($to === $this->kycAddress) {
            $applicants = array_keys($this->kycRecords);

            return match ($sel) {
                $s('owner()') => '0x'.$this->word($this->kycOwner),
                $s('getApplicantCount()') => '0x'.$this->word(count($applicants)),
                $s('applicants(uint256)') => '0x'.$this->word($applicants[(int) hexdec($arg)] ?? 0),
                $s('getRecord(address)') => $this->encodeRecord('0x'.substr($arg, 24)),
                default => '0x',
            };
        }

        if ($to === $this->registryAddress) {
            $operator = '0x'.substr($arg, 24);
            $node = $this->registryNodes[$operator] ?? null;

            return match ($sel) {
                '0xfacd743b' => '0x'.$this->word($node !== null && $node['tier'] === 3 && $node['status'] === 1 ? 1 : 0),
                '0x582115fb' => $this->encodeNode($operator, $node),
                default => '0x',
            };
        }

        return '0x';
    }

    private function encodeRecord(string $applicant): string
    {
        $r = $this->kycRecords[$applicant] ?? null;
        $reason = $r['reason'] ?? '';
        $head = [
            $this->word($r === null ? 0 : $applicant),
            $this->word($r['status'] ?? 0),
            str_pad(substr($r['kyc_hash'] ?? '0x', 2), 64, '0', STR_PAD_LEFT),
            $this->word($r['consent_at'] ?? 0),
            $this->word($r['submitted_at'] ?? 0),
            $this->word($r['reviewed_at'] ?? 0),
            $this->word($r['reviewer'] ?? 0),
            $this->word(8 * 32),
        ];

        return '0x'.$this->word(32).implode('', $head).$this->encodeString($reason);
    }

    private function encodeNode(string $operator, ?array $node): string
    {
        $head = [
            $this->word($node === null ? 0 : $operator),
            $this->word($node['tier'] ?? 0),
            $this->word($node['status'] ?? 0),
            $this->word($node === null ? 0 : '0x84595161401484a000000'), // 10M TPIX
            $this->word(1761000000), $this->word(1776552000), $this->word(1761000000), $this->word(0),
            $this->word(10000), str_repeat('a', 64), $this->word(13 * 32), $this->word(0), $this->word(0),
        ];

        return '0x'.$this->word(32).implode('', $head).$this->encodeString('203.0.113.10:8545');
    }

    private function encodeString(string $value): string
    {
        $hex = bin2hex($value);

        return $this->word(strlen($value)).($hex === '' ? '' : str_pad($hex, (int) ceil(strlen($hex) / 64) * 64, '0'));
    }

    private function word(int|string $value): string
    {
        if (is_int($value)) {
            return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
        }

        return str_pad(strtolower(substr($value, 2)), 64, '0', STR_PAD_LEFT);
    }
}
