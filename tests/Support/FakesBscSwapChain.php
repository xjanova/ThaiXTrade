<?php

namespace Tests\Support;

use App\Services\FeeCalculationService;
use App\Support\Wei;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * BSC จำลองสำหรับเทสต์การบันทึกไม้ — ตอบ eth_getTransactionReceipt / eth_getTransactionByHash / decimals()
 * ด้วยใบเสร็จรูปเดียวกับของจริง (log Transfer / WBNB Deposit / Withdrawal) ให้ BscSwapVerifier ถอดจริง.
 *
 * ⚠️ TestCase ไม่มี preventStrayRequests — เทสต์ที่บันทึกไม้ BSC โดยไม่เรียก fakeBscChain() จะยิง RPC จริง
 */
trait FakesBscSwapChain
{
    protected string $bscRouter = '0x10ed43c718714eb63d5aa57b78b54704e256024e';

    protected string $bscWbnb = '0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c';

    /** พูลที่ router ดึงเหรียญเข้า/ส่งเหรียญออก — ตัวตรวจดูแค่ฝั่งกระเป๋า */
    protected string $bscPool = '0x9999999999999999999999999999999999999999';

    /** @var array<string, array> hash => ['from','to','state','logs','value','block'] */
    private array $bscTxs = [];

    /** @var array<string, int> */
    private array $bscDecimals = [];

    private bool $bscIsDown = false;

    private bool $bscFakeInstalled = false;

    protected function fakeBscChain(): void
    {
        config(['chains.chains.56.rpc' => ['https://bsc-rpc.test/']]);

        if ($this->bscFakeInstalled) {
            return;
        }
        $this->bscFakeInstalled = true;

        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'bsc-rpc.test')) {
                return null;
            }
            if ($this->bscIsDown) {
                return Http::response('down', 503);
            }

            $body = $request->data();
            $params = $body['params'] ?? [];
            $ok = fn ($result) => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);

            return match ($body['method'] ?? '') {
                'eth_getTransactionReceipt' => $ok($this->bscReceipt(strtolower($params[0] ?? ''))),
                'eth_getTransactionByHash' => $ok($this->bscTransaction(strtolower($params[0] ?? ''))),
                'eth_call' => $ok($this->bscCall($params[0] ?? [])),
                default => $ok(null),
            };
        });
    }

    protected function bscDown(bool $down = true): void
    {
        $this->bscIsDown = $down;
    }

    protected function bscTokenDecimals(string $token, int $decimals): void
    {
        $this->bscDecimals[strtolower($token)] = $decimals;
    }

    /**
     * ไม้สวอปบนเชน: กระเป๋าจ่าย $inWei ของ $from ได้ $outWei ของ $to.
     *
     * $state: success | failed (revert) | pending (อยู่ใน mempool ยังไม่มีใบเสร็จ)
     * $target: ที่อยู่ที่ธุรกรรมส่งเข้า (ปริยาย = PancakeSwap router)
     */
    protected function bscSwap(
        string $hash,
        string $wallet,
        string $from,
        string $to,
        string $inWei,
        string $outWei,
        string $state = 'success',
        ?string $target = null,
    ): void {
        $wallet = strtolower($wallet);
        $logs = [];

        $logs[] = $this->isBscNative($from)
            ? $this->bscLog($this->bscWbnb, [self::BSC_DEPOSIT, $this->bscRouter], $inWei)
            : $this->bscLog($from, [self::BSC_TRANSFER, $wallet, $this->bscPool], $inWei);
        $logs[] = $this->isBscNative($to)
            ? $this->bscLog($this->bscWbnb, [self::BSC_WITHDRAWAL, $this->bscRouter], $outWei)
            : $this->bscLog($to, [self::BSC_TRANSFER, $this->bscPool, $wallet], $outWei);

        $this->bscTxs[strtolower($hash)] = [
            'from' => $wallet,
            'to' => strtolower($target ?? $this->bscRouter),
            'state' => $state,
            'logs' => $logs,
            'value' => $this->isBscNative($from) ? $inWei : '0',
            'block' => 40000000 + count($this->bscTxs),
        ];
    }

    /** ธุรกรรมโอนค่าธรรมเนียม: กระเป๋าโอน $amountWei ของ $token เข้า $collector */
    protected function bscFeeTransfer(string $hash, string $wallet, string $collector, string $token, string $amountWei, string $state = 'success'): void
    {
        $wallet = strtolower($wallet);
        $native = $this->isBscNative($token);

        $this->bscTxs[strtolower($hash)] = [
            'from' => $wallet,
            'to' => strtolower($native ? $collector : $token),
            'state' => $state,
            'logs' => $native ? [] : [$this->bscLog($token, [self::BSC_TRANSFER, $wallet, strtolower($collector)], $amountWei)],
            'value' => $native ? $amountWei : '0',
            'block' => 40000000 + count($this->bscTxs),
        ];
    }

    /** ธุรกรรมที่ค้างอยู่ลงบล็อกแล้ว */
    protected function bscMine(string $hash, string $state = 'success'): void
    {
        $this->bscTxs[strtolower($hash)]['state'] = $state;
    }

    /** ธุรกรรมหายจากเชน (หลุดจาก mempool / ถูกแทนที่) */
    protected function bscForget(string $hash): void
    {
        unset($this->bscTxs[strtolower($hash)]);
    }

    /** จำนวนทศนิยม → หน่วยเล็กสุด */
    protected static function wei(float|int|string $amount, int $decimals): string
    {
        return Wei::toWei(FeeCalculationService::decimal($amount), $decimals);
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    private const BSC_TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const BSC_DEPOSIT = '0xe1fffcc4923d04b559f4d29a8bfc6cda04eb5b0d3c460751c2402c5c5cc9109c';

    private const BSC_WITHDRAWAL = '0x7fcf532c15f0a6db0bd6d0e038bea71d30d808c7d98cb3bf7268a95bf5081b65';

    private function bscReceipt(string $hash): ?array
    {
        $tx = $this->bscTxs[$hash] ?? null;
        if ($tx === null || $tx['state'] === 'pending') {
            return null;
        }

        return [
            'transactionHash' => $hash,
            'status' => $tx['state'] === 'success' ? '0x1' : '0x0',
            'from' => $tx['from'],
            'to' => $tx['to'],
            'blockNumber' => '0x'.dechex($tx['block']),
            'logs' => $tx['state'] === 'success' ? $tx['logs'] : [],
        ];
    }

    private function bscTransaction(string $hash): ?array
    {
        $tx = $this->bscTxs[$hash] ?? null;

        return $tx === null ? null : [
            'hash' => $hash,
            'from' => $tx['from'],
            'to' => $tx['to'],
            'value' => '0x'.self::bscHex($tx['value']),
            'blockNumber' => $tx['state'] === 'pending' ? null : '0x'.dechex($tx['block']),
        ];
    }

    private function bscCall(array $call): ?string
    {
        $token = strtolower($call['to'] ?? '');
        // decimals() = 0x313ce567
        if (str_starts_with(strtolower($call['data'] ?? ''), '0x313ce567') && isset($this->bscDecimals[$token])) {
            return '0x'.str_pad(dechex($this->bscDecimals[$token]), 64, '0', STR_PAD_LEFT);
        }

        return '0x';
    }

    /** @param array<int, string> $topics ตัวแรกคือ topic ของ event ที่เหลือเป็นที่อยู่ */
    private function bscLog(string $address, array $topics, string $amountWei): array
    {
        return [
            'address' => strtolower($address),
            'topics' => array_map(
                fn (string $t, int $i) => $i === 0 ? $t : '0x'.str_pad(substr(strtolower($t), 2), 64, '0', STR_PAD_LEFT),
                $topics,
                array_keys($topics),
            ),
            'data' => '0x'.str_pad(self::bscHex($amountWei), 64, '0', STR_PAD_LEFT),
        ];
    }

    private function isBscNative(string $token): bool
    {
        return in_array(strtolower($token), ['0x0000000000000000000000000000000000000000', '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'], true);
    }

    private static function bscHex(string $decimal): string
    {
        $hex = '';
        while (bccomp($decimal, '0', 0) > 0) {
            $hex = dechex((int) bcmod($decimal, '16')).$hex;
            $decimal = bcdiv($decimal, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }
}
