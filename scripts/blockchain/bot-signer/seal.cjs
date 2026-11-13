/**
 * bot-signer/seal.cjs — สร้าง ห่อ และถอดกุญแจของกระเป๋าบอท "บนเครื่องตัวเซ็นเท่านั้น"
 *
 * รูปแบบเดียวกับชั้นในของ App\Services\AiBot\Wallet\BotWalletKeyring:
 *   base64( iv[12] | tag[16] | ciphertext )  ·  AES-256-GCM
 *   คีย์ = sha256(ค่า SIGNER_ENVELOPE_KEY)   ·  AAD = ที่อยู่เจ้าของ (ตัวพิมพ์เล็ก)
 *
 * AAD ผูกกุญแจกับเจ้าของ: เอา ciphertext ไปใส่แถวของคนอื่น หรือแก้ owner_address ในฐานข้อมูล
 * แล้วส่งมาให้เซ็น = ถอดไม่ออก ตัวเซ็นจึงใช้ "เจ้าของที่ถอดกุญแจได้" เป็นปลายทางถอนได้อย่างปลอดภัย
 *
 * คีย์ห่อ (SIGNER_ENVELOPE_KEY) อยู่บนเครื่องตัวเซ็นที่เดียว เครื่องเว็บเก็บได้แค่ ciphertext
 */

const crypto = require('node:crypto');
const { Wallet } = require('ethers');

const IV_BYTES = 12;
const TAG_BYTES = 16;
const OWNER_RE = /^0x[0-9a-f]{40}$/;

function envelopeKey(raw) {
    if (typeof raw !== 'string' || raw.length < 32) {
        throw new Error('ENVELOPE_KEY_MISSING');
    }

    return crypto.createHash('sha256').update(raw, 'utf8').digest();
}

function normalizeOwner(owner) {
    const value = String(owner || '').toLowerCase();

    if (!OWNER_RE.test(value)) {
        throw new Error('OWNER_FORMAT');
    }

    return value;
}

/** คู่กุญแจใหม่จาก CSPRNG ของระบบ — privateKey เป็น hex 64 ตัว ไม่มี 0x */
function generate() {
    for (;;) {
        const hex = crypto.randomBytes(32).toString('hex');

        try {
            // ethers ปฏิเสธสเกลาร์ที่อยู่นอก (0, n) เอง
            return { address: new Wallet('0x' + hex).address.toLowerCase(), privateKey: hex };
        } catch (_) {
            // โอกาส ~2^-128 — สุ่มใหม่
        }
    }
}

function addressOf(privateKeyHex) {
    return new Wallet('0x' + privateKeyHex).address.toLowerCase();
}

function seal(privateKeyHex, owner, rawEnvelopeKey) {
    if (!/^[0-9a-f]{64}$/.test(String(privateKeyHex))) {
        throw new Error('KEY_FORMAT');
    }

    const iv = crypto.randomBytes(IV_BYTES);
    const cipher = crypto.createCipheriv('aes-256-gcm', envelopeKey(rawEnvelopeKey), iv, { authTagLength: TAG_BYTES });
    cipher.setAAD(Buffer.from(normalizeOwner(owner), 'utf8'));

    const body = Buffer.concat([cipher.update(Buffer.from(privateKeyHex, 'hex')), cipher.final()]);

    return Buffer.concat([iv, cipher.getAuthTag(), body]).toString('base64');
}

/** @returns {string} hex 64 ตัว — โยน KEY_UNREADABLE ถ้าถอดไม่ออก (คีย์ห่อผิด / เจ้าของไม่ตรง / ถูกแก้) */
function open(sealed, owner, rawEnvelopeKey) {
    const blob = Buffer.from(String(sealed || ''), 'base64');

    if (blob.length !== IV_BYTES + TAG_BYTES + 32) {
        throw new Error('KEY_UNREADABLE');
    }

    const key = envelopeKey(rawEnvelopeKey);
    const aad = Buffer.from(normalizeOwner(owner), 'utf8');

    try {
        const decipher = crypto.createDecipheriv('aes-256-gcm', key, blob.subarray(0, IV_BYTES), { authTagLength: TAG_BYTES });
        decipher.setAAD(aad);
        decipher.setAuthTag(blob.subarray(IV_BYTES, IV_BYTES + TAG_BYTES));

        const plain = Buffer.concat([decipher.update(blob.subarray(IV_BYTES + TAG_BYTES)), decipher.final()]);

        if (plain.length !== 32) {
            throw new Error('length');
        }

        return plain.toString('hex');
    } catch (_) {
        throw new Error('KEY_UNREADABLE');
    }
}

module.exports = { generate, addressOf, seal, open, normalizeOwner, IV_BYTES, TAG_BYTES };
