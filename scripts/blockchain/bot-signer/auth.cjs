/**
 * bot-signer/auth.cjs — ลายเซ็นคำขอระหว่างเครื่องเว็บกับตัวเซ็น (HMAC-SHA256)
 *
 *   X-Signer-Timestamp   วินาที (unix)
 *   X-Signer-Nonce       สุ่ม 16-64 ตัว [A-Za-z0-9_-]
 *   X-Signer-Signature   hex( hmac_sha256( secret, ts \n nonce \n path \n sha256hex(body) ) )
 *
 * ต้องตรงกับ App\Services\AiBot\Wallet\BotWalletRemoteSigner ทุกตัวอักษร
 *
 * ⚠️ ลายเซ็นนี้พิสูจน์แค่ว่า "คำขอมาจากเครื่องที่ถือ secret" — ถ้าเครื่องเว็บถูกยึด คนร้ายก็ได้ secret ไปด้วย
 *    สิ่งที่กันเงินคือกติกาใน policy.cjs ไม่ใช่ลายเซ็นนี้
 */

const crypto = require('node:crypto');

const WINDOW_SECONDS = 90;
const NONCE_RE = /^[A-Za-z0-9_-]{16,64}$/;

function bodyHash(body) {
    return crypto.createHash('sha256').update(body).digest('hex');
}

function signature(secret, timestamp, nonce, path, body) {
    return crypto
        .createHmac('sha256', secret)
        .update(`${timestamp}\n${nonce}\n${path}\n${bodyHash(body)}`)
        .digest('hex');
}

/**
 * @param {Map<string, number>} seen  nonce → เวลาหมดอายุ (วินาที) — กันเล่นซ้ำคำขอเดิมภายในหน้าต่างเวลา
 * @returns {{ok: true} | {ok: false, code: string}}
 */
function verify({ headers, path, body, secret, now, seen }) {
    if (typeof secret !== 'string' || secret.length < 32) {
        return { ok: false, code: 'auth_not_configured' };
    }

    const timestamp = String(headers['x-signer-timestamp'] || '');
    const nonce = String(headers['x-signer-nonce'] || '');
    const given = String(headers['x-signer-signature'] || '');

    if (!/^\d{9,11}$/.test(timestamp) || !NONCE_RE.test(nonce) || !/^[0-9a-f]{64}$/.test(given)) {
        return { ok: false, code: 'auth_malformed' };
    }

    if (Math.abs(now - Number(timestamp)) > WINDOW_SECONDS) {
        return { ok: false, code: 'auth_expired' };
    }

    const expected = signature(secret, timestamp, nonce, path, body);

    if (!crypto.timingSafeEqual(Buffer.from(given, 'hex'), Buffer.from(expected, 'hex'))) {
        return { ok: false, code: 'auth_bad_signature' };
    }

    for (const [key, expires] of seen) {
        if (expires < now) seen.delete(key);
    }

    if (seen.has(nonce)) {
        return { ok: false, code: 'auth_replay' };
    }

    seen.set(nonce, now + WINDOW_SECONDS * 2);

    return { ok: true };
}

module.exports = { signature, verify, bodyHash, WINDOW_SECONDS };
