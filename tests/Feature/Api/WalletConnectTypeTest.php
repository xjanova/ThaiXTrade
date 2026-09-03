<?php

namespace Tests\Feature\Api;

use Elliptic\EC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use kornrunner\Keccak;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ชนิดกระเป๋าที่แอปมือถือส่งมาต้องผ่านด่านตรวจ.
 *
 * ⚠️ อาการจริง 2026-09-03 (รายงานบั๊ก 3536 จากแอป Trade v1.1.158):
 *    แอปส่ง wallet_type=tpix_embedded มาที่ /wallet/connect แล้วโดน 422 ทุกครั้ง
 *    การเชื่อมต่อจากแอปจึงไม่เคยถูกบันทึกเลย และตอนยืนยันลายเซ็นแอปไม่ได้ส่งเชน/ชนิด
 *    กระเป๋ามา → ประวัติการเชื่อมต่อของผู้ใช้แอปกลายเป็น BSC/metamask ทั้งหมด
 *
 * ชุดนี้ยิง endpoint จริงพร้อมลายเซ็นจริง เหมือนที่แอปทำ
 *
 * Developed by Xman Studio.
 */
class WalletConnectTypeTest extends TestCase
{
    use RefreshDatabase;

    /** กุญแจสำหรับทดสอบเท่านั้น — ไม่เคยถือเงินและไม่มีอยู่บนเชนไหน */
    private const PRIVATE_KEY = '4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    /** กระเป๋าสมมติสำหรับเทสต์ที่ไม่ต้องเซ็น */
    private const WALLET = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        if (! class_exists(Keccak::class)) {
            $this->markTestSkipped('ต้องมี kornrunner/keccak ถึงจะเซ็นข้อความทดสอบได้');
        }
    }

    #[Test]
    public function กระเป๋าในแอปเทรดส่ง_tpix_embedded_แล้วต้องผ่าน(): void
    {
        $this->connect('tpix_embedded')
            ->assertOk()
            ->assertJsonPath('data.wallet_address', self::WALLET);

        $this->assertDatabaseHas('wallet_connections', [
            'wallet_address' => self::WALLET,
            'chain_id' => 4289,
            'wallet_type' => 'tpix_embedded',
        ]);
    }

    #[Test]
    public function เชื่อมจากแอปกระเป๋าส่ง_tpix_wallet_แล้วต้องผ่าน(): void
    {
        $this->connect('tpix_wallet')->assertOk();

        $this->assertDatabaseHas('wallet_connections', [
            'wallet_address' => self::WALLET,
            'wallet_type' => 'tpix_wallet',
        ]);
    }

    /** รายการที่รู้จักยังเป็นด่านอยู่ — ค่ามั่วต้องไม่หลุดลงฐานข้อมูล */
    #[Test]
    public function ชนิดกระเป๋าที่ไม่รู้จักยังโดน_422(): void
    {
        $this->connect('bogus_wallet')->assertStatus(422);

        $this->assertDatabaseMissing('wallet_connections', ['wallet_address' => self::WALLET]);
    }

    /** แอปยืนยันลายเซ็นพร้อมเชน+ชนิดกระเป๋าจริง → ประวัติต้องตรงกับที่ส่งมา ไม่ใช่ BSC/metamask */
    #[Test]
    public function ยืนยันลายเซ็นจากแอปบันทึกเชนและชนิดกระเป๋าตามที่ส่งมา(): void
    {
        $response = $this->verify([
            'chain_id' => 4289,
            'wallet_type' => 'tpix_wallet',
            'client' => 'mobile',
        ]);

        $response->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotEmpty($response->json('data.session_token'));

        $this->assertDatabaseHas('wallet_connections', [
            'wallet_address' => $this->address(),
            'chain_id' => 4289,
            'wallet_type' => 'tpix_wallet',
        ]);
    }

    /** ลายเซ็นถูกใช้ไปแล้ว — ค่าชนิดกระเป๋าประหลาดต้องไม่ทำให้การยืนยันล้ม (ลดเป็นค่าเริ่มต้นแทน) */
    #[Test]
    public function ชนิดกระเป๋าประหลาดตอนยืนยันลายเซ็นไม่ทำให้ล้ม(): void
    {
        $this->verify(['wallet_type' => str_repeat('x', 40)])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertDatabaseHas('wallet_connections', [
            'wallet_address' => $this->address(),
            'wallet_type' => 'metamask',
        ]);
    }

    // ── helpers ──

    private function connect(string $walletType, int $chainId = 4289): TestResponse
    {
        return $this->postJson('/api/v1/wallet/connect', [
            'wallet_address' => self::WALLET,
            'chain_id' => $chainId,
            'wallet_type' => $walletType,
        ]);
    }

    /** ขอ nonce → เซ็น → ส่งกลับไปยืนยัน พร้อมฟิลด์เพิ่มเติมแบบที่แอปส่ง */
    private function verify(array $extra): TestResponse
    {
        $address = $this->address();

        $message = $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $address])
            ->assertOk()
            ->json('data');

        return $this->postJson('/api/v1/wallet/verify-signature', [
            'wallet_address' => $address,
            'signature' => $this->sign($message['message']),
            'nonce' => $message['nonce'],
            ...$extra,
        ]);
    }

    /** ที่อยู่ที่ได้จากกุญแจทดสอบด้านบน */
    private function address(): string
    {
        $publicKey = (new EC('secp256k1'))->keyFromPrivate(self::PRIVATE_KEY)
            ->getPublic(false, 'hex');

        // ตัด prefix 04 ออกก่อน hash ตามมาตรฐาน Ethereum แล้วเอา 20 ไบต์ท้าย
        return '0x'.substr(Keccak::hash(hex2bin(substr($publicKey, 2)), 256), -40);
    }

    /** เซ็นข้อความแบบเดียวกับที่กระเป๋าทำ (EIP-191 personal_sign) */
    private function sign(string $message): string
    {
        $prefixed = "\x19Ethereum Signed Message:\n".strlen($message).$message;
        $hash = Keccak::hash($prefixed, 256);

        $signature = (new EC('secp256k1'))
            ->keyFromPrivate(self::PRIVATE_KEY)
            ->sign($hash, ['canonical' => true]);

        return '0x'
            .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
            .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
            .dechex($signature->recoveryParam + 27);
    }
}
