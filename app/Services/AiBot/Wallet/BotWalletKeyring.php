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
 * ── กุญแจสองรุ่น ──
 *  รุ่น 1 (local)   ห่อด้วย AIBOT_BOT_WALLET_KEY บนเครื่องนี้ ตามที่อธิบายข้างบน
 *                   ทุกอย่างที่ใช้ถอดอยู่ใน .env ของเครื่องเว็บ — เครื่องที่ใช้ผู้ใช้ระบบร่วมกับเว็บอื่น
 *                   ถูกเจาะเว็บเดียวก็ถอดได้ทุกใบ จึงยอมให้ใช้เฉพาะนอก production (localKeysAllowed())
 *  รุ่น 2 (remote)  สร้าง ห่อ และเซ็นบนเครื่องตัวเซ็นแยก (BotWalletRemoteSigner) เครื่องนี้เก็บแค่ ciphertext
 *                   open() ปฏิเสธรุ่นนี้เสมอ — ไม่มีทางได้กุญแจรุ่น 2 บนเครื่องเว็บ ต่อให้เป็น CLI
 *
 * Developed by Xman Studio.
 */
class BotWalletKeyring
{
    public const KEY_VERSION = 1;

    /** กุญแจที่ตัวเซ็นแยกเครื่องเป็นผู้สร้างและถือคีย์ห่อ */
    public const KEY_VERSION_REMOTE = 2;

    public const DRIVER_LOCAL = 'local';

    public const DRIVER_REMOTE = 'remote';

    /** ลำดับของกลุ่ม secp256k1 (n) — กุญแจส่วนตัวต้องอยู่ใน (0, n) */
    private const CURVE_ORDER = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    /** ระบบพร้อมสร้างกระเป๋าไหม — ต้องเปิดฟีเจอร์ และที่เก็บกุญแจที่เลือกไว้ต้องพร้อม */
    public function available(): bool
    {
        if (! config('aibot.bot_wallet.enabled')) {
            return false;
        }

        if ($this->driver() === self::DRIVER_REMOTE) {
            return app(BotWalletRemoteSigner::class)->configured();
        }

        return $this->localKeysAllowed()
            && strlen((string) config('aibot.bot_wallet.encryption_key')) >= 32;
    }

    /** ที่เก็บกุญแจของกระเป๋าที่จะสร้างใหม่ — ค่าที่ไม่รู้จักถือเป็น local (ซึ่ง production ปฏิเสธ = fail-closed) */
    public function driver(): string
    {
        return config('aibot.bot_wallet.signer.driver') === self::DRIVER_REMOTE
            ? self::DRIVER_REMOTE
            : self::DRIVER_LOCAL;
    }

    /**
     * ยอมให้คีย์ห่ออยู่บนเครื่องนี้ไหม.
     *
     * production = ไม่ยอม: เครื่องเว็บใช้ผู้ใช้ระบบร่วมกับเว็บอื่น ใครอ่าน .env ได้ก็ถอดกุญแจได้ทุกใบ
     * เปิดได้ด้วย AIBOT_BOT_WALLET_ALLOW_LOCAL_KEYS=true เท่านั้น — ต้องเป็นการตัดสินใจที่ตั้งใจ ไม่ใช่ค่าปริยาย
     */
    public function localKeysAllowed(): bool
    {
        return ! app()->environment('production')
            || (bool) config('aibot.bot_wallet.signer.allow_local_in_production', false);
    }

    /** กุญแจของกระเป๋าใบนี้อยู่กับตัวเซ็นแยกเครื่องไหม */
    public function isRemote(AiBotWallet $wallet): bool
    {
        return (int) $wallet->key_version === self::KEY_VERSION_REMOTE;
    }

    /**
     * สร้างกระเป๋าใหม่ตามที่เก็บกุญแจที่ตั้งไว้ — คืนของที่พร้อมบันทึกลงแถว ไม่คืนกุญแจ.
     *
     * @return array{address: string, key_ciphertext: string, key_version: int}
     *
     * @throws RuntimeException สร้างไม่ได้ = ไม่มีกระเป๋า (ไม่ถอยไปใช้ที่เก็บอีกแบบเอง)
     */
    public function create(string $ownerAddress): array
    {
        if ($this->driver() === self::DRIVER_REMOTE) {
            $made = app(BotWalletRemoteSigner::class)->createWallet($ownerAddress);

            return [
                'address' => strtolower($made['address']),
                // ชั้นนอกยังเป็น Crypt ของแอปเหมือนเดิม — ฐานข้อมูลรั่วอย่างเดียวไม่เห็นแม้แต่รูปร่างของ ciphertext
                'key_ciphertext' => Crypt::encryptString($made['sealed']),
                'key_version' => self::KEY_VERSION_REMOTE,
            ];
        }

        if (! $this->localKeysAllowed()) {
            throw new RuntimeException('BOT_WALLET_LOCAL_KEYS_FORBIDDEN');
        }

        $pair = $this->generate();
        $sealed = $this->seal($pair['private_key'], $ownerAddress);
        unset($pair['private_key']);

        return [
            'address' => strtolower($pair['address']),
            'key_ciphertext' => $sealed,
            'key_version' => self::KEY_VERSION,
        ];
    }

    /**
     * ciphertext ชั้นในของกระเป๋ารุ่น remote สำหรับส่งให้ตัวเซ็น — ถอดไม่ได้ถ้าไม่มีคีย์ห่อของตัวเซ็น.
     */
    public function sealedForSigner(AiBotWallet $wallet): string
    {
        if (! $this->isRemote($wallet)) {
            throw new RuntimeException('BOT_WALLET_KEY_NOT_REMOTE');
        }

        return Crypt::decryptString($wallet->key_ciphertext);
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

        // กุญแจรุ่น remote ถอดได้บนเครื่องตัวเซ็นเท่านั้น — เครื่องนี้ไม่มีคีย์ห่อของมัน
        if ($this->isRemote($wallet)) {
            throw new RuntimeException('BOT_WALLET_KEY_REMOTE');
        }

        if (! $this->localKeysAllowed()) {
            throw new RuntimeException('BOT_WALLET_LOCAL_KEYS_FORBIDDEN');
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
