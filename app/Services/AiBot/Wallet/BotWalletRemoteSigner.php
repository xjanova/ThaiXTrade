<?php

namespace App\Services\AiBot\Wallet;

use App\Models\AiBotWallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — ลูกข่ายของตัวเซ็นกระเป๋าบอทที่รันบนเครื่องแยก (scripts/blockchain/bot-signer).
 *
 * เครื่องเว็บไม่มีคีย์ห่อและไม่เคยเห็นกุญแจส่วนตัวของกระเป๋ารุ่นนี้ สิ่งที่ส่งไปคือ
 *   - ciphertext ของกุญแจ (ถอดได้ด้วยคีย์ห่อบนเครื่องตัวเซ็นเท่านั้น)
 *   - "เจตนา" ของธุรกรรม (ถอนเท่าไร / สวอปอะไรเป็นอะไร) — ตัวเซ็นตัดสินตามกติกาของมันเอง
 * สิ่งที่ได้กลับคือธุรกรรมที่เซ็นแล้ว รูปเดียวกับที่ BotWalletSigner คืน
 *
 * ลายเซ็นคำขอ (HMAC) ต้องตรงกับ scripts/blockchain/bot-signer/auth.cjs ทุกตัวอักษร:
 *   hmac_sha256(secret, "{ts}\n{nonce}\n{path}\n{sha256(body)}")
 *
 * ⚠️ secret ตัวนี้อยู่บนเครื่องเว็บ จึงถือว่ารั่วได้ — มันไม่ได้กันเงิน กติกาบนตัวเซ็นต่างหากที่กัน
 *    ห้ามใช้ secret นี้แทนคีย์ห่อ และห้ามตั้งให้เป็นค่าเดียวกัน
 *
 * Developed by Xman Studio.
 */
class BotWalletRemoteSigner
{
    public const ERR_UNAVAILABLE = 'BOT_WALLET_SIGNER_UNAVAILABLE';

    public const ERR_BAD_REPLY = 'BOT_WALLET_SIGNER_BAD_REPLY';

    /** ตั้งค่าครบพอจะเรียกตัวเซ็นได้ไหม (ไม่ได้ยืนยันว่าตัวเซ็นตอบ) */
    public function configured(): bool
    {
        return preg_match('#^https?://[^\s/]+#i', $this->baseUrl()) === 1
            && strlen($this->secret()) >= 32;
    }

    /**
     * ให้ตัวเซ็นสร้างคู่กุญแจใหม่และห่อไว้ — กุญแจไม่เคยเดินทางมาที่เครื่องนี้.
     *
     * @return array{address: string, sealed: string} sealed = ciphertext ชั้นใน (base64)
     *
     * @throws RuntimeException สร้างไม่ได้ = ไม่มีกระเป๋า (fail-closed)
     */
    public function createWallet(string $owner): array
    {
        $reply = $this->call('/v1/wallets', ['owner' => strtolower($owner)]);

        if (! ($reply['ok'] ?? false)) {
            throw new RuntimeException(self::ERR_UNAVAILABLE);
        }

        $address = (string) ($reply['address'] ?? '');
        $sealed = (string) ($reply['sealed'] ?? '');

        if (preg_match('/^0x[0-9a-f]{40}$/', $address) !== 1 || base64_decode($sealed, true) === false || strlen($sealed) < 60) {
            throw new RuntimeException(self::ERR_BAD_REPLY);
        }

        return ['address' => $address, 'sealed' => $sealed];
    }

    /**
     * เซ็นถอน — ไม่มีพารามิเตอร์ปลายทาง: ตัวเซ็นส่งกลับไปที่กระเป๋าเจ้าของเสมอ.
     *
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, error?: string, code?: string}
     */
    public function signWithdraw(AiBotWallet $wallet, string $sealed, string $amountWei, ?string $tokenAddress = null, ?int $nonce = null): array
    {
        return $this->sign($wallet, '/v1/sign/withdraw', $sealed, [
            'token' => $tokenAddress === null || $tokenAddress === '' ? null : strtolower($tokenAddress),
            'amountWei' => $amountWei,
            'nonce' => $nonce,
        ]);
    }

    /**
     * เซ็น approve — ระบุได้แค่ชนิด router ('v2' | 'v3') ที่อยู่จริงมาจากค่าตั้งของตัวเซ็น.
     *
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, error?: string, code?: string}
     */
    public function signApprove(AiBotWallet $wallet, string $sealed, string $token, string $routerKind, string $amountWei): array
    {
        return $this->sign($wallet, '/v1/sign/approve', $sealed, [
            'token' => strtolower($token),
            'router' => $routerKind,
            'amountWei' => $amountWei,
        ]);
    }

    /**
     * เซ็นสวอป — ตัวเซ็นถามราคาจากเชนเองก่อน และปฏิเสธถ้า minOut ต่ำกว่าราคานั้นเกินที่มันยอม.
     *
     * @param  array{kind: string, tokens: array<int, string>, fees?: array<int, int>}  $route
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, error?: string, code?: string}
     */
    public function signSwap(
        AiBotWallet $wallet,
        string $sealed,
        string $tokenIn,
        string $tokenOut,
        string $amountInWei,
        string $minOutWei,
        int $deadline,
        array $route,
    ): array {
        return $this->sign($wallet, '/v1/sign/swap', $sealed, [
            'tokenIn' => strtolower($tokenIn),
            'tokenOut' => strtolower($tokenOut),
            'amountInWei' => $amountInWei,
            'minOutWei' => $minOutWei,
            'deadline' => $deadline,
            'route' => [
                'kind' => (string) ($route['kind'] ?? ''),
                'tokens' => array_values(array_map(fn ($t) => strtolower((string) $t), (array) ($route['tokens'] ?? []))),
                'fees' => array_values(array_map('intval', (array) ($route['fees'] ?? []))),
            ],
        ]);
    }

    /** ตัวเซ็นตอบอยู่ไหม — ใช้กับหน้าสถานะและคำสั่งตรวจ ไม่ได้พิสูจน์ว่าเซ็นได้ */
    public function healthy(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get($this->baseUrl().'/health');

            return $response->successful() && ($response->json('ok') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function sign(AiBotWallet $wallet, string $path, string $sealed, array $intent): array
    {
        $reply = $this->call($path, [
            'sealed' => $sealed,
            'owner' => strtolower((string) $wallet->owner_address),
            'address' => strtolower((string) $wallet->address),
        ] + $intent);

        if (! ($reply['ok'] ?? false)) {
            return $reply;
        }

        // ธุรกรรมที่ได้กลับมาต้องเป็นของกระเป๋าใบนี้ — ตัวเซ็นตอบของกระเป๋าอื่น = ไม่บันทึก ไม่ส่ง
        if (strcasecmp((string) ($reply['address'] ?? ''), (string) $wallet->address) !== 0) {
            Log::error('bot-wallet: ตัวเซ็นคืนธุรกรรมของกระเป๋าอื่น', ['path' => $path, 'wallet_id' => $wallet->id]);

            return ['ok' => false, 'code' => 'address_mismatch', 'error' => 'ตัวเซ็นคืนธุรกรรมที่ไม่ใช่ของกระเป๋านี้'];
        }

        return $reply;
    }

    /**
     * @return array{ok: bool, code?: string, error?: string} คำตอบของตัวเซ็น หรือ ok=false พร้อมรหัสเมื่อติดต่อไม่ได้
     */
    private function call(string $path, array $payload): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'code' => 'signer_not_configured', 'error' => 'ยังไม่ได้ตั้งค่าตัวเซ็นของกระเป๋าบอท'];
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$path."\n".hash('sha256', $body), $this->secret());

        try {
            $response = Http::timeout(max(10, (int) config('aibot.bot_wallet.signer.timeout', 260)))
                ->withHeaders([
                    'X-Signer-Timestamp' => $timestamp,
                    'X-Signer-Nonce' => $nonce,
                    'X-Signer-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($this->baseUrl().$path);
        } catch (\Throwable $e) {
            // ห้าม log payload (มี ciphertext ของกุญแจ) — log แค่เส้นทางกับชนิดของความล้มเหลว
            Log::error('bot-wallet: ติดต่อตัวเซ็นไม่ได้', ['path' => $path, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return ['ok' => false, 'code' => 'signer_unreachable', 'error' => 'ติดต่อตัวเซ็นของกระเป๋าบอทไม่ได้'];
        }

        $data = $response->json();

        if (! is_array($data) || ! array_key_exists('ok', $data)) {
            Log::error('bot-wallet: ตัวเซ็นตอบรูปแบบที่อ่านไม่ออก', ['path' => $path, 'status' => $response->status()]);

            return ['ok' => false, 'code' => 'signer_bad_reply', 'error' => 'ตัวเซ็นของกระเป๋าบอทตอบรูปแบบที่อ่านไม่ออก'];
        }

        if (! $data['ok']) {
            Log::warning('bot-wallet: ตัวเซ็นปฏิเสธคำขอ', [
                'path' => $path,
                'status' => $response->status(),
                'code' => $data['code'] ?? null,
                'error' => isset($data['error']) ? mb_substr((string) $data['error'], 0, 200) : null,
            ]);
        }

        return $data;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('aibot.bot_wallet.signer.url', ''), '/');
    }

    private function secret(): string
    {
        return (string) config('aibot.bot_wallet.signer.secret', '');
    }
}
