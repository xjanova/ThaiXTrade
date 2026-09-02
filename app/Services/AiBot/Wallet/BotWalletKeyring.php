<?php

namespace App\Services\AiBot\Wallet;

use App\Models\AiBotWallet;
use Elliptic\EC;
use Illuminate\Support\Facades\Crypt;
use kornrunner\Keccak;
use RuntimeException;

/**
 * TPIX TRADE — พวงกุญแจของกระเป๋าบอท: สร้าง ห่อ และถอดกุญแจส่วนตัว.
 *
 * ห่อสองชั้นโดยตั้งใจ:
 *  1. AES-256-GCM ด้วยคีย์จาก env AIBOT_BOT_WALLET_KEY โดยผูก AAD = ที่อยู่เจ้าของ
 *     → ก๊อป ciphertext ไปใส่แถวของคนอื่นแล้วถอดไม่ได้ (tag ไม่ผ่าน)
 *  2. Crypt ของแอป (APP_KEY) ทับอีกชั้น
 *  ฐานข้อมูลรั่ว + APP_KEY รั่ว ยังไม่พอ ต้องได้ env ของเซิร์ฟเวอร์ด้วย และกลับกัน
 *
 * ถอดได้เฉพาะ CLI (PHP_SAPI) — เหมือน HotWalletSigner: php-fpm ไม่มีทางได้กุญแจ
 * ต่อให้มีช่องโหว่ในเว็บ กุญแจก็อยู่นอกมือของ request
 *
 * ทุกครั้งที่ถอด ต้องคำนวณที่อยู่จากกุญแจแล้วเทียบกับที่บันทึกไว้ — ไม่ตรงคือปฏิเสธ
 * (ด่านเดียวกับ TPIX_EXPECT_ADDRESS ของกระเป๋าร้อน)
 *
 * Developed by Xman Studio.
 */
class BotWalletKeyring
{
    public const KEY_VERSION = 1;

    /** ลำดับของกลุ่ม secp256k1 (n) — กุญแจส่วนตัวต้องอยู่ใน (0, n) */
    private const CURVE_ORDER = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    /** ระบบพร้อมสร้างกระเป๋าไหม — ต้องเปิดฟีเจอร์และมีคีย์ห่อที่ยาวพอ */
    public function available(): bool
    {
        return (bool) config('aibot.bot_wallet.enabled')
            && strlen((string) config('aibot.bot_wallet.encryption_key')) >= 32;
    }

    /** ถอดกุญแจได้เฉพาะฝั่ง CLI เท่านั้น */
    public function canOpen(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * สร้างคู่กุญแจใหม่จาก CSPRNG ของระบบ.
     *
     * @return array{address: string, private_key: string} private_key เป็น hex 64 ตัว (ไม่มี 0x)
     */
    public function generate(): array
    {
        do {
            $hex = bin2hex(random_bytes(32));
        } while (! $this->validScalar($hex));

        return ['address' => $this->addressOf($hex), 'private_key' => $hex];
    }

    /** ที่อยู่ EVM จากกุญแจส่วนตัว — keccak256 ของ public key (ไม่รวม 04) เอา 20 ไบต์ท้าย */
    public function addressOf(string $privateKeyHex): string
    {
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKeyHex, 'hex');
        $public = $key->getPublic(false, 'hex');   // '04' . x . y

        $hash = Keccak::hash(hex2bin(substr($public, 2)), 256);

        return '0x'.substr($hash, -40);
    }

    /** ห่อกุญแจสำหรับเก็บลงฐานข้อมูล — ผูกกับเจ้าของผ่าน AAD */
    public function seal(string $privateKeyHex, string $ownerAddress): string
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $privateKeyHex)) {
            throw new RuntimeException('BOT_WALLET_KEY_FORMAT');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $cipher = openssl_encrypt(
            hex2bin($privateKeyHex),
            'aes-256-gcm',
            $this->envelopeKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            strtolower($ownerAddress),
            self::TAG_BYTES,
        );

        if ($cipher === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('BOT_WALLET_SEAL_FAILED');
        }

        return Crypt::encryptString(base64_encode($iv.$tag.$cipher));
    }

    /**
     * ถอดกุญแจของกระเป๋า — CLI เท่านั้น และที่อยู่ต้องตรงกับที่บันทึกไว้.
     *
     * @return string hex 64 ตัว (ไม่มี 0x)
     */
    public function open(AiBotWallet $wallet): string
    {
        if (! $this->canOpen()) {
            throw new RuntimeException('BOT_WALLET_KEY_CLI_ONLY');
        }

        $blob = base64_decode(Crypt::decryptString($wallet->key_ciphertext), true);

        if ($blob === false || strlen($blob) <= self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('BOT_WALLET_KEY_UNREADABLE');
        }

        $iv = substr($blob, 0, self::IV_BYTES);
        $tag = substr($blob, self::IV_BYTES, self::TAG_BYTES);
        $cipher = substr($blob, self::IV_BYTES + self::TAG_BYTES);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $this->envelopeKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            strtolower($wallet->owner_address),
        );

        if ($plain === false || strlen($plain) !== 32) {
            throw new RuntimeException('BOT_WALLET_KEY_UNREADABLE');
        }

        $hex = bin2hex($plain);

        if (strcasecmp($this->addressOf($hex), $wallet->address) !== 0) {
            throw new RuntimeException('BOT_WALLET_ADDRESS_MISMATCH');
        }

        return $hex;
    }

    private function envelopeKey(): string
    {
        $raw = (string) config('aibot.bot_wallet.encryption_key');

        if (strlen($raw) < 32) {
            throw new RuntimeException('BOT_WALLET_KEY_MISSING');
        }

        return hash('sha256', $raw, true);
    }

    private function validScalar(string $hex): bool
    {
        // hex ตัวพิมพ์เล็กยาวเท่ากัน → เทียบเป็นสตริงได้ผลเท่ากับเทียบตัวเลข
        return $hex !== str_repeat('0', 64) && strcmp($hex, self::CURVE_ORDER) < 0;
    }
}
