#!/usr/bin/env node
/**
 * bridge-signer.cjs — เซ็น/ส่งธุรกรรมของสะพาน USDT (relayer · payout · guardian)
 *
 * เรียกจาก App\Services\Bridge\BridgeSigner (ฝั่ง CLI เท่านั้น — php-fpm ปิด proc_open)
 * ความลับส่งผ่าน **environment variable ไม่ใช่ argv** เพราะ argv โผล่ใน `ps aux`
 *
 * คีย์อยู่ในไฟล์ keystore V3 (ethers ถอด scrypt ได้ในเสี้ยววินาที ส่วน PHP ทำไม่ได้เร็วพอ)
 * PHP ไม่เคยเห็นคีย์ — เห็นแค่ raw tx ที่เซ็นแล้วซึ่งเป็นข้อมูลสาธารณะอยู่แล้ว
 *
 * env:
 *   BRIDGE_ACTION          sign | send
 *   BRIDGE_RPC_URLS        RPC คั่นด้วย , (ตัวแรกที่ตอบได้ถูกใช้)
 *   BRIDGE_CHAIN_ID        chain id (EIP-155)
 *   BRIDGE_USER_AGENT      Cloudflare หน้า rpc.tpix.online ต้องมี UA
 *   ── sign ──
 *   BRIDGE_KEYSTORE_PATH   ไฟล์ keystore V3
 *   BRIDGE_KEYSTORE_PASS   passphrase
 *   BRIDGE_EXPECT_ADDRESS  ที่อยู่ที่ keystore ต้องถอดได้ (ไม่ตรง = ไม่เซ็น)
 *   BRIDGE_TO              สัญญาที่จะเรียก
 *   BRIDGE_DATA            calldata (hex)
 *   BRIDGE_NONCE           ว่าง = ถามเชน (pending)
 *   BRIDGE_GAS_PRICE_WEI   ว่าง = ถามเชนแล้วคูณ BRIDGE_GAS_MULTIPLIER_BPS (10000 = 1 เท่า)
 *   BRIDGE_MAX_GAS_PRICE_WEI  เพดาน — แพงกว่านี้ไม่เซ็น (รอรอบหน้า)
 *   BRIDGE_GAS_LIMIT       ว่าง = estimateGas × 1.3
 *   ── send ──
 *   BRIDGE_RAW_TX          raw ที่เซ็นไว้แล้ว
 *
 * ผลลัพธ์: JSON บรรทัดเดียวทาง stdout
 *   { ok: true, address, nonce, txHash, raw, gasLimit, gasPrice }   (sign)
 *   { ok: true, txHash, alreadyKnown }                              (send)
 *   { ok: false, error, code }
 *
 * code ที่ฝั่ง PHP ใช้ตัดสินใจ:
 *   estimate_failed   — เชนบอกว่าธุรกรรมนี้จะ revert (ไม่ได้เซ็น ไม่มีอะไรขึ้นเชน)
 *   insufficient_gas  — เงินค่าแก๊สในกระเป๋าไม่พอ (ไม่ได้เซ็น)
 *   gas_price_too_high — ค่าแก๊สแพงเกินเพดาน (ไม่ได้เซ็น)
 *   nonce_used        — (send) nonce ถูกใช้ไปแล้ว ห้ามเซ็นใหม่ ให้ไปตรวจ receipt
 *   rpc_unavailable   — ไม่มี RPC ไหนตอบได้
 *
 * **ไม่พิมพ์ private key หรือ passphrase ออกทาง stdout/stderr ไม่ว่ากรณีใด**
 */

const fs = require('fs');
const { Wallet, JsonRpcProvider, FetchRequest, Transaction, getAddress } = require('ethers');

function out(obj) {
    process.stdout.write(JSON.stringify(obj));
}

/** ตัดข้อความ error ให้สั้น — ตอน Cloudflare บล็อก ethers แนบ HTML ทั้งหน้ามาด้วย */
function shorten(error) {
    const text = String(error?.shortMessage || error?.message || error).replace(/\s+/g, ' ').trim();

    return text.length > 280 ? text.slice(0, 280) + '…' : text;
}

function fail(error, code = 'error', extra = {}) {
    out({ ok: false, error: shorten(error), code, ...extra });
    process.exit(1);
}

function providers(urls, chainId, userAgent) {
    return urls.map((url) => {
        const req = new FetchRequest(url);
        req.setHeader('User-Agent', userAgent);
        req.setHeader('Content-Type', 'application/json');
        req.timeout = 15000;

        return new JsonRpcProvider(req, chainId, { staticNetwork: true });
    });
}

/** ลองทีละ RPC จนกว่าจะมีตัวตอบได้ — error ที่เป็นคำตอบของเชน (revert) โยนออกทันที ไม่ลองตัวอื่น */
async function firstWorking(list, fn) {
    let lastError = null;

    for (const provider of list) {
        try {
            return await fn(provider);
        } catch (e) {
            const code = e?.code;
            if (code === 'CALL_EXCEPTION' || code === 'INSUFFICIENT_FUNDS' || code === 'NONCE_EXPIRED' || code === 'REPLACEMENT_UNDERPRICED') {
                throw e;
            }
            lastError = e;
        }
    }

    const err = new Error(shorten(lastError || 'ไม่มี RPC ที่ตอบได้'));
    err.code = 'RPC_UNAVAILABLE';
    throw err;
}

async function main() {
    const action = process.env.BRIDGE_ACTION || 'sign';
    const chainId = Number(process.env.BRIDGE_CHAIN_ID || 0);
    const urls = String(process.env.BRIDGE_RPC_URLS || '').split(',').map((u) => u.trim()).filter(Boolean);
    const userAgent = process.env.BRIDGE_USER_AGENT || 'TPIX-Bridge/1.0';

    if (!chainId) fail('ไม่ได้ตั้ง BRIDGE_CHAIN_ID', 'config');
    if (urls.length === 0) fail('ไม่ได้ตั้ง BRIDGE_RPC_URLS', 'config');

    const list = providers(urls, chainId, userAgent);

    // ── send ──────────────────────────────────────────────────────────────
    if (action === 'send') {
        const raw = process.env.BRIDGE_RAW_TX;
        if (!raw) fail('ไม่ได้ส่ง BRIDGE_RAW_TX มา', 'config');

        const parsed = Transaction.from(raw);
        if (Number(parsed.chainId) !== chainId) fail(`raw tx เป็นของเชน ${parsed.chainId} ไม่ใช่ ${chainId}`, 'config');

        let sawNonceLow = false;
        let lastError = null;

        // ยิง raw เดิมเข้าได้หลาย RPC อย่างปลอดภัย — nonce เดียวกัน เชนรับได้ครั้งเดียว
        for (const provider of list) {
            try {
                await provider.broadcastTransaction(raw);

                return out({ ok: true, txHash: parsed.hash, alreadyKnown: false });
            } catch (e) {
                const msg = String(e?.message || e).toLowerCase();

                // เชนรู้จักอยู่แล้ว = เคยส่งไปแล้ว ไม่ใช่ความล้มเหลว
                if (msg.includes('already known') || msg.includes('already exists') || msg.includes('already imported') || msg.includes('duplicate')) {
                    return out({ ok: true, txHash: parsed.hash, alreadyKnown: true });
                }

                // nonce ถูกใช้ไปแล้ว — อาจเป็นธุรกรรมของเราเองที่ลงบล็อกไปแล้ว ห้ามเซ็นใหม่
                if (msg.includes('nonce too low') || msg.includes('nonce is too low') || e?.code === 'NONCE_EXPIRED') {
                    sawNonceLow = true;
                    continue;
                }

                lastError = e;
            }
        }

        if (sawNonceLow) {
            return fail('nonce ถูกใช้ไปแล้ว', 'nonce_used', { txHash: parsed.hash });
        }

        return fail(lastError || 'broadcast ไม่สำเร็จ', 'broadcast_failed', { txHash: parsed.hash });
    }

    // ── sign ──────────────────────────────────────────────────────────────
    const ksPath = process.env.BRIDGE_KEYSTORE_PATH;
    const ksPass = process.env.BRIDGE_KEYSTORE_PASS;
    const to = process.env.BRIDGE_TO;
    const data = process.env.BRIDGE_DATA;

    if (!ksPath || !ksPass) fail('ไม่ได้ตั้ง keystore หรือ passphrase', 'config');
    if (!to || !/^0x[0-9a-fA-F]{40}$/.test(to)) fail('BRIDGE_TO ไม่ใช่ที่อยู่', 'config');
    if (!data || !/^0x[0-9a-fA-F]*$/.test(data)) fail('BRIDGE_DATA ไม่ใช่ hex', 'config');
    if (!fs.existsSync(ksPath)) fail('ไม่พบไฟล์ keystore', 'keystore_missing');

    let wallet;
    try {
        wallet = await Wallet.fromEncryptedJson(fs.readFileSync(ksPath, 'utf8'), ksPass);
    } catch (e) {
        // ไม่แนบข้อความดิบจาก ethers เพราะบางกรณีมีเนื้อ keystore ปนมา
        fail('ถอดรหัส keystore ไม่สำเร็จ (passphrase ผิดหรือไฟล์เสีย)', 'keystore_decrypt_failed');
    }

    // ด่านกันใช้ keystore ผิดใบ — ไม่ตรงกับที่ระบบคาด ห้ามเซ็นเด็ดขาด
    const expect = process.env.BRIDGE_EXPECT_ADDRESS;
    if (!expect || getAddress(wallet.address) !== getAddress(expect.toLowerCase())) {
        fail('keystore ถอดออกมาได้คนละที่อยู่กับที่ระบบคาด', 'address_mismatch');
    }

    const request = { from: wallet.address, to: getAddress(to.toLowerCase()), data, value: 0n };

    try {
        const nonce = process.env.BRIDGE_NONCE !== undefined && process.env.BRIDGE_NONCE !== ''
            ? Number(process.env.BRIDGE_NONCE)
            : await firstWorking(list, (p) => p.getTransactionCount(wallet.address, 'pending'));

        let gasPrice;
        if (process.env.BRIDGE_GAS_PRICE_WEI !== undefined && process.env.BRIDGE_GAS_PRICE_WEI !== '') {
            gasPrice = BigInt(process.env.BRIDGE_GAS_PRICE_WEI);
        } else {
            const fee = await firstWorking(list, (p) => p.send('eth_gasPrice', []));
            const bps = BigInt(process.env.BRIDGE_GAS_MULTIPLIER_BPS || '10000');
            gasPrice = (BigInt(fee) * bps) / 10000n;
        }

        const cap = process.env.BRIDGE_MAX_GAS_PRICE_WEI;
        if (cap && gasPrice > BigInt(cap)) {
            fail(`ค่าแก๊ส ${gasPrice} wei แพงกว่าเพดาน ${cap}`, 'gas_price_too_high');
        }

        let gasLimit;
        if (process.env.BRIDGE_GAS_LIMIT) {
            gasLimit = BigInt(process.env.BRIDGE_GAS_LIMIT);
        } else {
            try {
                const estimated = await firstWorking(list, (p) => p.estimateGas(request));
                gasLimit = (estimated * 130n) / 100n;
            } catch (e) {
                if (e?.code === 'RPC_UNAVAILABLE') throw e;
                // เชนบอกว่าจะ revert — ไม่เซ็น ไม่มีอะไรขึ้นเชน (ฝั่ง PHP ไปตรวจสาเหตุเอง)
                fail(e, 'estimate_failed');
            }
        }

        // แก๊สไม่พอ = ส่งไปก็ค้าง — บอกก่อนเซ็น ไม่ใช่ปล่อยให้ไปเจอตอน broadcast
        const balance = await firstWorking(list, (p) => p.getBalance(wallet.address, 'latest'));
        if (balance < gasLimit * gasPrice) {
            fail(`ค่าแก๊สในกระเป๋าไม่พอ: มี ${balance} ต้องใช้ ${gasLimit * gasPrice} wei`, 'insufficient_gas');
        }

        const tx = { type: 0, chainId, nonce, to: request.to, data, value: 0n, gasLimit, gasPrice };
        const raw = await wallet.signTransaction(tx);
        const parsed = Transaction.from(raw);

        out({
            ok: true,
            address: wallet.address,
            nonce,
            txHash: parsed.hash,
            raw,
            gasLimit: gasLimit.toString(),
            gasPrice: gasPrice.toString(),
        });
    } catch (e) {
        if (e?.code === 'RPC_UNAVAILABLE') fail(e, 'rpc_unavailable');
        fail(e, 'unexpected');
    }
}

main().catch((e) => fail(e, 'unexpected'));
