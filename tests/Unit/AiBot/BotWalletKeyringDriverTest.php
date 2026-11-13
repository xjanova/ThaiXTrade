<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotWallet;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletRemoteSigner;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — ที่เก็บกุญแจของกระเป๋าบอท: ตัวเซ็นแยกเครื่อง (remote) กับคีย์ห่อบนเครื่องเว็บ (local).
 *
 * สิ่งที่ต้องจริงเสมอ: บน production กุญแจที่ถอดได้ด้วยของใน .env ของเครื่องเว็บต้องไม่เกิดขึ้น
 * และต้องถอดไม่ได้ เว้นแต่เจ้าของตั้งธงยอมเองโดยตั้งใจ
 *
 * Developed by Xman Studio.
 */
class BotWalletKeyringDriverTest extends TestCase
{
    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const ADDRESS = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aibot.bot_wallet.enabled' => true,
            'aibot.bot_wallet.encryption_key' => str_repeat('k', 40),
            'aibot.bot_wallet.signer.url' => 'http://10.8.0.2:8787',
            'aibot.bot_wallet.signer.secret' => str_repeat('s', 40),
        ]);
    }

    private function keyring(): BotWalletKeyring
    {
        return app(BotWalletKeyring::class);
    }

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
    }

    #[Test]
    public function ค่าปริยายคือ_local_และค่าที่ไม่รู้จักก็ถือเป็น_local(): void
    {
        $this->assertSame('local', $this->keyring()->driver());

        config(['aibot.bot_wallet.signer.driver' => 'REMOTE ']);
        $this->assertSame('local', $this->keyring()->driver());

        config(['aibot.bot_wallet.signer.driver' => 'remote']);
        $this->assertSame('remote', $this->keyring()->driver());
    }

    #[Test]
    public function บน_production_คีย์ห่อบนเครื่องเว็บใช้สร้างและถอดกุญแจไม่ได้(): void
    {
        $keyring = $this->keyring();
        $made = $keyring->create(self::OWNER);
        $wallet = new AiBotWallet(['owner_address' => self::OWNER] + $made);

        $this->assertSame(BotWalletKeyring::KEY_VERSION, $made['key_version']);
        $this->assertSame(64, strlen($keyring->open($wallet)), 'นอก production ถอดได้ตามเดิม');

        $this->asProduction();

        $this->assertFalse($keyring->localKeysAllowed());
        $this->assertFalse($keyring->available(), 'ตั้ง AIBOT_BOT_WALLET_KEY บน production แล้วก็ยังไม่พร้อม');

        foreach ([fn () => $keyring->create(self::OWNER), fn () => $keyring->open($wallet)] as $attempt) {
            try {
                $attempt();
                $this->fail('ต้องถูกปฏิเสธบน production');
            } catch (RuntimeException $e) {
                $this->assertSame('BOT_WALLET_LOCAL_KEYS_FORBIDDEN', $e->getMessage());
            }
        }
    }

    #[Test]
    public function เจ้าของตั้งธงยอมเองแล้ว_local_บน_production_จึงใช้ได้(): void
    {
        $this->asProduction();
        config(['aibot.bot_wallet.signer.allow_local_in_production' => true]);

        $keyring = $this->keyring();

        $this->assertTrue($keyring->available());
        $this->assertSame(BotWalletKeyring::KEY_VERSION, $keyring->create(self::OWNER)['key_version']);
    }

    #[Test]
    public function แบบ_remote_พร้อมเมื่อตั้งค่าตัวเซ็นครบ_ไม่ขึ้นกับคีย์ห่อบนเครื่องเว็บ(): void
    {
        $this->asProduction();
        config(['aibot.bot_wallet.signer.driver' => 'remote', 'aibot.bot_wallet.encryption_key' => null]);

        $this->assertTrue($this->keyring()->available());

        config(['aibot.bot_wallet.signer.secret' => null]);
        $this->assertFalse($this->keyring()->available());

        config(['aibot.bot_wallet.signer.secret' => str_repeat('s', 40), 'aibot.bot_wallet.enabled' => false]);
        $this->assertFalse($this->keyring()->available());
    }

    #[Test]
    public function แบบ_remote_เก็บแค่_ciphertext_ที่ตัวเซ็นให้มา_และถอดบนเครื่องเว็บไม่ได้(): void
    {
        config(['aibot.bot_wallet.signer.driver' => 'remote']);
        $sealed = base64_encode(random_bytes(60));
        Http::fake(['*' => Http::response(['ok' => true, 'address' => strtoupper(self::ADDRESS), 'sealed' => $sealed])]);

        // ตัวเซ็นคืนที่อยู่ตัวพิมพ์เล็กเสมอ — ตัวพิมพ์ใหญ่จึงถือว่าผิดรูป
        try {
            $this->keyring()->create(self::OWNER);
            $this->fail('ที่อยู่ผิดรูปต้องถูกปฏิเสธ');
        } catch (RuntimeException $e) {
            $this->assertSame(BotWalletRemoteSigner::ERR_BAD_REPLY, $e->getMessage());
        }

        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['ok' => true, 'address' => self::ADDRESS, 'sealed' => $sealed])]);

        $keyring = $this->keyring();
        $made = $keyring->create(self::OWNER);
        $wallet = new AiBotWallet(['owner_address' => self::OWNER] + $made);

        $this->assertSame(self::ADDRESS, $made['address']);
        $this->assertSame(BotWalletKeyring::KEY_VERSION_REMOTE, $made['key_version']);
        $this->assertNotSame($sealed, $made['key_ciphertext'], 'ชั้นนอกยังห่อด้วย Crypt ของแอป');
        $this->assertSame($sealed, Crypt::decryptString($made['key_ciphertext']));
        $this->assertTrue($keyring->isRemote($wallet));
        $this->assertSame($sealed, $keyring->sealedForSigner($wallet));

        try {
            $keyring->open($wallet);
            $this->fail('กุญแจรุ่น remote ต้องถอดบนเครื่องเว็บไม่ได้');
        } catch (RuntimeException $e) {
            $this->assertSame('BOT_WALLET_KEY_REMOTE', $e->getMessage());
        }
    }

    #[Test]
    public function ตัวเซ็นล่มแล้วไม่ถอยไปสร้างกุญแจบนเครื่องเว็บเอง(): void
    {
        config(['aibot.bot_wallet.signer.driver' => 'remote']);
        Http::fake(['*' => Http::response(['ok' => false, 'code' => 'signer_error'], 500)]);

        try {
            $this->keyring()->create(self::OWNER);
            $this->fail('ต้องโยน error');
        } catch (RuntimeException $e) {
            $this->assertSame(BotWalletRemoteSigner::ERR_UNAVAILABLE, $e->getMessage());
        }
    }

    #[Test]
    public function กระเป๋ารุ่น_local_ขอ_ciphertext_สำหรับตัวเซ็นไม่ได้(): void
    {
        $wallet = new AiBotWallet(['owner_address' => self::OWNER] + $this->keyring()->create(self::OWNER));

        $this->assertFalse($this->keyring()->isRemote($wallet));

        $this->expectExceptionMessage('BOT_WALLET_KEY_NOT_REMOTE');
        $this->keyring()->sealedForSigner($wallet);
    }
}
