<?php

namespace Tests\Feature\Api;

use App\Services\WalletSessionService;
use Database\Seeders\AiBotPlanSeeder;
use Elliptic\EC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use kornrunner\Keccak;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เซสชันกระเป๋าแบบยาวของแอปมือถือ.
 *
 * เจ้าของ: "เชื่อมแล้ว เวลาเปิดแอพมาใหม่ก็ต้องมาเชื่อมใหม่อีก ทั้งที่ควรเชื่อมค้างไว้ได้เลย"
 * ต้นเหตุคือแคชยืนยัน 4 ชั่วโมง + ผูก IP — ชุดนี้ตรึงว่าโทเคนเซสชันแทนได้จริง
 * ทั้งอ่านและเขียน ข้าม IP และเป็นของกระเป๋าใบเดียวเท่านั้น
 *
 * Developed by Xman Studio.
 */
class WalletSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AiBotPlanSeeder::class);
    }

    #[Test]
    public function โทเคนแทนการยืนยันได้ทั้งอ่านและเขียนแม้_ip_เปลี่ยน(): void
    {
        $issued = app(WalletSessionService::class)->issue($this->wallet, 56, '10.0.0.1');

        // ไม่มีแคช wallet_verified เลย — ต้อง 403 ตามเดิม
        $this->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)->assertStatus(403);

        // มีโทเคน → ผ่าน (อ่าน)
        $this->withHeader(WalletSessionService::HEADER, $issued['token'])
            ->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)
            ->assertStatus(200);

        // เขียนจาก IP อื่น — แคชเดิมจะปฏิเสธด้วย WALLET_IP_MISMATCH แต่โทเคนไม่สน IP
        $this->withHeader(WalletSessionService::HEADER, $issued['token'])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/ai-bot/cancel', ['wallet_address' => $this->wallet])
            ->assertStatus(200);
    }

    #[Test]
    public function โทเคนของกระเป๋าอื่นหรือโทเคนมั่วใช้ไม่ได้(): void
    {
        $other = app(WalletSessionService::class)->issue('0x2222222222222222222222222222222222222222', 56);

        $this->withHeader(WalletSessionService::HEADER, $other['token'])
            ->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)
            ->assertStatus(403);

        $this->withHeader(WalletSessionService::HEADER, str_repeat('ab', 32))
            ->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)
            ->assertStatus(403);

        $this->withHeader(WalletSessionService::HEADER, 'not-a-token')
            ->getJson('/api/v1/ai-bot/bots?wallet_address='.$this->wallet)
            ->assertStatus(403);
    }

    #[Test]
    public function เพิกถอนแล้วใช้ต่อไม่ได้_และแคชเก็บแค่_hash(): void
    {
        $service = app(WalletSessionService::class);
        $issued = $service->issue($this->wallet, 56);

        $this->assertSame($this->wallet, $service->resolve($issued['token']));
        $this->assertNull(Cache::get('wallet_session:'.$issued['token']), 'ห้ามเก็บโทเคนดิบเป็นคีย์');

        $service->revoke($issued['token']);

        $this->assertNull($service->resolve($issued['token']));
    }

    /**
     * เซ็นจริงด้วยกุญแจทดสอบ → verify-signature ต้องออกโทเคนให้เฉพาะ client=mobile.
     */
    #[Test]
    public function เซ็นผ่านแล้วแอปได้โทเคน_แต่เว็บไม่ได้(): void
    {
        $privateKey = str_repeat('0', 63).'1';
        $address = '0x7e5f4552091a69125d5dfcb7b8c2659029395bdf';

        foreach (['mobile' => true, 'web' => false] as $client => $expectToken) {
            $sign = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])->assertStatus(200)->json('data');

            $response = $this->postJson('/api/v1/wallet/verify-signature', [
                'wallet_address' => $address,
                'signature' => $this->personalSign($sign['message'], $privateKey),
                'nonce' => $sign['nonce'],
                'client' => $client,
            ])->assertStatus(200)->assertJsonPath('data.verified', true);

            $token = $response->json('data.session_token');

            if ($expectToken) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $token);
                $this->assertSame($address, app(WalletSessionService::class)->resolve($token));
            } else {
                $this->assertNull($token);
            }
        }
    }

    /** EIP-191 personal_sign ด้วย elliptic-php — แบบเดียวกับที่กระเป๋ามือถือทำ */
    private function personalSign(string $message, string $privateKeyHex): string
    {
        $prefixed = "\x19Ethereum Signed Message:\n".strlen($message).$message;
        $hash = Keccak::hash($prefixed, 256);

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKeyHex, 'hex');
        $sig = $key->sign($hash, ['canonical' => true]);

        $r = str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = dechex(27 + (int) $sig->recoveryParam);

        return '0x'.$r.$s.$v;
    }
}
