#!/usr/bin/env node
/**
 * stabilizer-sign.cjs — เซ็น/ส่งธุรกรรมของกระเป๋า TPIX Stability Reserve
 *
 * เรียกจาก App\Services\Stabilizer\NodeStabilizerSigner (ฝั่ง CLI เท่านั้น)
 * ความลับส่งผ่าน **environment variable ไม่ใช่ argv** เพราะ argv โผล่ใน `ps aux`
 *
 * env:
 *   STB_ACTION           sign | send
 *   STB_KEYSTORE_PATH    keystore V3 (sign)
 *   STB_KEYSTORE_PASS    passphrase (sign)
 *   STB_EXPECT_ADDRESS   ที่อยู่กระเป๋าที่ประกาศไว้ — keystore ต้องถอดได้ที่อยู่นี้เท่านั้น
 *   STB_ALLOWED_TO       ปลายทางที่อนุญาต (คั่นด้วย ,) = router + USDT_TPIX
 *   STB_TO / STB_DATA / STB_VALUE_WEI / STB_GAS_LIMIT / STB_NONCE
 *   STB_RAW_TX           (send)
 *   STB_RPC_URL / STB_CHAIN_ID / STB_USER_AGENT
 *
 * ผลลัพธ์: JSON บรรทัดเดียวทาง stdout
 *   { ok: true, address, nonce, txHash, raw }   (sign)
 *   { ok: true, txHash, alreadyKnown }          (send)
 *   { ok: false, error, code }
 *
 * ด่านในสคริปต์นี้ซ้อนกับฝั่ง PHP โดยตั้งใจ — ถ้า PHP มีบั๊กสร้าง calldata ผิด
 * สคริปต์ต้องปฏิเสธ ไม่ใช่เซ็นตามที่ได้รับมา:
 *   - ปลายทางต้องอยู่ใน STB_ALLOWED_TO
 *   - ฟังก์ชันต้องเป็น approve / swapExactTokensForETH / swapExactETHForTokens เท่านั้น
 *   - approve ได้เฉพาะให้ router (ไม่ใช่ใครก็ได้)
 *   - ผู้รับเงินจากสวอป (to) ต้องเป็นกระเป๋าตัวเอง
 *   - ส่ง value ได้เฉพาะ swapExactETHForTokens
 *
 * **ไม่พิมพ์ private key หรือเนื้อ keystore ออกทาง stdout/stderr ไม่ว่ากรณีใด**
 */

const fs = require('fs');
const { Wallet, JsonRpcProvider, FetchRequest, Transaction, Interface, getAddress } = require('ethers');

const ABI = new Interface([
    'function approve(address spender, uint256 amount)',
    'function swapExactTokensForETH(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline)',
    'function swapExactETHForTokens(uint256 amountOutMin, address[] path, address to, uint256 deadline)',
]);

function out(obj) {
    process.stdout.write(JSON.stringify(obj));
}

function shorten(error) {
    const text = String(error?.message || error).replace(/\s+/g, ' ').trim();

    return text.length > 300 ? text.slice(0, 300) + '…' : text;
}

function fail(error, code = 'error') {
    out({ ok: false, error: shorten(error), code });
    process.exit(1);
}

function provider() {
    const rpcUrl = process.env.STB_RPC_URL;
    const chainId = Number(process.env.STB_CHAIN_ID || 0);
    if (!rpcUrl || !chainId) fail('STB_RPC_URL / STB_CHAIN_ID not set', 'config');

    // Cloudflare WAF ตอบ 403 ให้ request ที่ไม่มี User-Agent
    const req = new FetchRequest(rpcUrl);
    req.setHeader('User-Agent', process.env.STB_USER_AGENT || 'TPIX-Stabilizer/1.0');
    req.setHeader('Content-Type', 'application/json');

    return new JsonRpcProvider(req, chainId, { staticNetwork: true });
}

async function send() {
    const raw = process.env.STB_RAW_TX;
    if (!raw) fail('STB_RAW_TX not set', 'config');

    try {
        const sent = await provider().broadcastTransaction(raw);
        return out({ ok: true, txHash: sent.hash, alreadyKnown: false });
    } catch (e) {
        const msg = String(e?.message || e).toLowerCase();

        // โหนดรู้จัก tx นี้แล้ว = เคยส่งไปแล้ว ไม่ใช่ความล้มเหลว (ห้ามเซ็นใหม่)
        if (msg.includes('already known') || msg.includes('already exists') || msg.includes('duplicate')) {
            return out({ ok: true, txHash: Transaction.from(raw).hash, alreadyKnown: true });
        }

        // nonce ถูกใช้แล้ว — อาจเป็น tx ของเราเองที่ลงบล็อกไปแล้ว ให้ PHP ไปตรวจ receipt
        if (msg.includes('nonce too low') || msg.includes('nonce is too low')) {
            return out({ ok: false, error: 'nonce already used', code: 'nonce_used', txHash: Transaction.from(raw).hash });
        }

        return fail(e?.message || e, 'broadcast_failed');
    }
}

function guardCall(to, data, value, self, allowed, router) {
    if (!allowed.includes(to)) fail(`destination ${to} is not on the allowlist`, 'to_not_allowed');

    let parsed;
    try {
        parsed = ABI.parseTransaction({ data, value });
    } catch {
        parsed = null;
    }
    if (!parsed) fail('calldata is not an allowed function', 'function_not_allowed');

    if (parsed.name === 'approve') {
        if (value !== 0n) fail('approve must not carry value', 'value_not_allowed');
        if (getAddress(parsed.args.spender) !== router) fail('approve spender must be the router', 'spender_not_allowed');
        if (to === router) fail('approve must target the token, not the router', 'to_not_allowed');
        return;
    }

    if (to !== router) fail('swaps must target the router', 'to_not_allowed');
    if (getAddress(parsed.args.to) !== self) fail('swap recipient must be the reserve wallet itself', 'recipient_not_allowed');
    if (parsed.name === 'swapExactTokensForETH' && value !== 0n) fail('token-in swap must not carry value', 'value_not_allowed');
    if (parsed.name === 'swapExactETHForTokens' && value === 0n) fail('TPIX-in swap needs value', 'value_missing');
}

async function sign() {
    const ksPath = process.env.STB_KEYSTORE_PATH;
    const ksPass = process.env.STB_KEYSTORE_PASS;
    const chainId = Number(process.env.STB_CHAIN_ID || 0);
    const nonceText = process.env.STB_NONCE;

    if (!ksPath || !ksPass) fail('keystore path or passphrase not set', 'config');
    if (!chainId) fail('STB_CHAIN_ID not set', 'config');
    // PHP เป็นคนกำหนด nonce เสมอ (จากสถานะที่ลงบล็อกแล้ว) — สคริปต์ห้ามเดาเอง
    if (nonceText === undefined || nonceText === '' || !/^\d+$/.test(nonceText)) fail('STB_NONCE is required', 'config');
    if (!process.env.STB_EXPECT_ADDRESS) fail('STB_EXPECT_ADDRESS not set', 'config');
    if (!/^0x([0-9a-fA-F]{2})+$/.test(process.env.STB_DATA || '')) fail('STB_DATA must be hex', 'config');
    if (!fs.existsSync(ksPath)) fail('keystore file not found', 'keystore_missing');

    const self = getAddress(process.env.STB_EXPECT_ADDRESS);
    const allowed = String(process.env.STB_ALLOWED_TO || '').split(',').filter(Boolean).map((a) => getAddress(a));
    if (allowed.length < 2) fail('STB_ALLOWED_TO must list router and USDT', 'config');
    // ตัวแรกในรายการคือ router (ฝั่ง PHP ส่ง ROUTER มาก่อน USDT เสมอ)
    const router = allowed[0];

    const to = getAddress(process.env.STB_TO || '');
    const value = BigInt(process.env.STB_VALUE_WEI || '0');
    const data = process.env.STB_DATA;
    guardCall(to, data, value, self, allowed, router);

    let wallet;
    try {
        wallet = await Wallet.fromEncryptedJson(fs.readFileSync(ksPath, 'utf8'), ksPass);
    } catch {
        // ไม่แนบข้อความดิบจาก ethers เพราะบางกรณีมีเนื้อ keystore ปนมา
        fail('could not decrypt keystore (wrong passphrase or corrupt file)', 'keystore_decrypt_failed');
    }

    if (getAddress(wallet.address) !== self) {
        fail(`keystore belongs to ${wallet.address}, expected ${self}`, 'address_mismatch');
    }

    // เชน TPIX: แก๊สเป็นศูนย์ ใช้ธุรกรรม legacy (type 0) + EIP-155 chainId
    const tx = {
        type: 0,
        chainId,
        nonce: Number(nonceText),
        to,
        value,
        gasLimit: BigInt(process.env.STB_GAS_LIMIT || '400000'),
        gasPrice: 0n,
        data,
    };

    const raw = await wallet.signTransaction(tx);
    out({ ok: true, address: wallet.address, nonce: tx.nonce, txHash: Transaction.from(raw).hash, raw });
}

const action = process.env.STB_ACTION || '';
(action === 'send' ? send() : action === 'sign' ? sign() : Promise.reject(new Error('unknown STB_ACTION')))
    .catch((e) => fail(e?.message || e, 'unexpected'));
