<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotWallet;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — พวงกุญแจกระเป๋าบอท: ที่อยู่ถูกต้อง ห่อแล้วถอดได้ และถอดผิดคนไม่ได้.
 *
 * Developed by Xman Studio.
 */
class BotWalletKeyringTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private BotWalletKeyring $keyring;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
        ]);
        $this->keyring = new BotWalletKeyring;
    }

    private function wallet(string $address, string $sealed, string $owner = self::OWNER): AiBotWallet
    {
        return AiBotWallet::create([
            'owner_address' => $owner, 'chain_id' => 56, 'address' => $address,
            'key_ciphertext' => $sealed, 'key_version' => 1, 'status' => 'active',
        ]);
    }

    /** เวกเตอร์มาตรฐาน: กุญแจ 0x…01 → 0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf */
    #[Test]
    public function ที่อยู่คำนวณจากกุญแจได้ตรงเวกเตอร์มาตรฐาน(): void
    {
        $address = $this->keyring->addressOf(str_repeat('0', 63).'1');

        $this->assertSame('0x7e5f4552091a69125d5dfcb7b8c2659029395bdf', strtolower($address));
    }

    #[Test]
    public function สร้างกุญแจใหม่ได้ที่อยู่ที่ถูกรูปแบบและไม่ซ้ำกัน(): void
    {
        $a = $this->keyring->generate();
        $b = $this->keyring->generate();

        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $a['address']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['private_key']);
        $this->assertNotSame($a['address'], $b['address']);
        $this->assertSame($a['address'], $this->keyring->addressOf($a['private_key']));
    }

    #[Test]
    public function ห่อแล้วถอดกลับได้กุญแจเดิม_และไม่มีกุญแจโผล่ในสิ่งที่เก็บ(): void
    {
        $pair = $this->keyring->generate();
        $sealed = $this->keyring->seal($pair['private_key'], self::OWNER);

        $this->assertStringNotContainsString($pair['private_key'], $sealed);
        $this->assertStringNotContainsString($pair['private_key'], base64_decode($sealed) ?: '');

        $wallet = $this->wallet($pair['address'], $sealed);

        $this->assertSame($pair['private_key'], $this->keyring->open($wallet));
    }

    #[Test]
    public function ก๊อป_ciphertext_ไปใส่แถวของเจ้าของคนอื่นแล้วถอดไม่ได้(): void
    {
        $pair = $this->keyring->generate();
        $sealed = $this->keyring->seal($pair['private_key'], self::OWNER);

        $stolen = $this->wallet($pair['address'], $sealed, '0x2222222222222222222222222222222222222222');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOT_WALLET_KEY_UNREADABLE');
        $this->keyring->open($stolen);
    }

    #[Test]
    public function ที่อยู่ที่บันทึกไม่ตรงกับกุญแจต้องปฏิเสธ(): void
    {
        $pair = $this->keyring->generate();
        $sealed = $this->keyring->seal($pair['private_key'], self::OWNER);

        $wallet = $this->wallet('0x'.str_repeat('a', 40), $sealed);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOT_WALLET_ADDRESS_MISMATCH');
        $this->keyring->open($wallet);
    }

    #[Test]
    public function เปลี่ยนคีย์ห่อแล้วกุญแจเดิมอ่านไม่ออก(): void
    {
        $pair = $this->keyring->generate();
        $sealed = $this->keyring->seal($pair['private_key'], self::OWNER);
        $wallet = $this->wallet($pair['address'], $sealed);

        config(['aibot.bot_wallet.encryption_key' => str_repeat('z', 40)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOT_WALLET_KEY_UNREADABLE');
        (new BotWalletKeyring)->open($wallet);
    }

    #[Test]
    public function ไม่มีคีย์ห่อหรือปิดฟีเจอร์ต้องไม่พร้อมใช้(): void
    {
        $this->assertTrue($this->keyring->available());

        config(['aibot.bot_wallet.encryption_key' => 'short']);
        $this->assertFalse($this->keyring->available());

        config(['aibot.bot_wallet.encryption_key' => str_repeat('k', 40), 'aibot.bot_wallet.enabled' => false]);
        $this->assertFalse($this->keyring->available());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOT_WALLET_KEY_MISSING');
        config(['aibot.bot_wallet.encryption_key' => null]);
        $this->keyring->seal(str_repeat('1', 64), self::OWNER);
    }
}
