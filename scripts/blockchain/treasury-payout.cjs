#!/usr/bin/env node
/**
 * treasury-payout.js — เซ็นและส่งธุรกรรมจ่ายเงินจากกระเป๋าร้อน TPIX
 *
 * เรียกจาก App\Services\HotWalletSigner (ฝั่ง CLI เท่านั้น)
 * ความลับส่งผ่าน **environment variable ไม่ใช่ argv** เพราะ argv โผล่ใน
 * `ps aux` ให้ผู้ใช้อื่นบนเครื่องเดียวกันเห็นได้
 *
 * env ที่ต้องมี:
 *   TPIX_KEYSTORE_PATH   path ของ keystore V3
 *   TPIX_KEYSTORE_PASS   passphrase
 *   TPIX_RPC_URL         RPC
 *   TPIX_CHAIN_ID        chain id (4289)
 *   TPIX_EXPECT_ADDRESS  ที่อยู่ที่คาดว่า keystore นี้จะถอดออกมาได้
 *   TPIX_TO              ปลายทาง
 *   TPIX_VALUE_WEI       จำนวน (wei, decimal string)
 *   TPIX_NONCE           nonce ที่จะใช้ (ว่าง = ให้สคริปต์ถามเชนเอง)
 *   TPIX_ACTION          sign  = เซ็นอย่างเดียว คืน raw + hash
 *                        send  = ส่ง raw ที่ให้มาขึ้นเชน
 *   TPIX_RAW_TX          ใช้กับ action=send
 *
 * ผลลัพธ์: JSON บรรทัดเดียวทาง stdout
 *   { ok: true, address, nonce, txHash, raw }        (sign)
 *   { ok: true, txHash, alreadyKnown }               (send)
 *   { ok: false, error, code }
 *
 * **ไม่พิมพ์ private key ออกทาง stdout/stderr ไม่ว่ากรณีใด**
 */

const fs = require('fs');
const { Wallet, JsonRpcProvider, FetchRequest, Transaction, getAddress } = require('ethers');

function out(obj) {
    process.stdout.write(JSON.stringify(obj));
}

/**
 * ตัดข้อความ error ให้สั้น — ตอน Cloudflare บล็อก ethers จะแนบ HTML ทั้งหน้า
 * มาในข้อความ (หลายพันตัวอักษร) ซึ่งจะไหลลง log และคอลัมน์ failure_reason
 */
function shorten(error) {
    const text = String(error?.message || error).replace(/\s+/g, ' ').trim();

    return text.length > 300 ? text.slice(0, 300) + '…' : text;
}

function fail(error, code = 'error') {
    out({ ok: false, error: shorten(error), code });
    process.exit(1);
}

async function main() {
    const action = process.env.TPIX_ACTION || 'sign';
    const rpcUrl = process.env.TPIX_RPC_URL;
    const chainId = Number(process.env.TPIX_CHAIN_ID || 0);

    if (!rpcUrl) fail('ไม่ได้ตั้ง TPIX_RPC_URL', 'config');
    if (!chainId) fail('ไม่ได้ตั้ง TPIX_CHAIN_ID', 'config');

    // ตั้ง User-Agent ผ่าน FetchRequest — Cloudflare WAF ตอบ 403 (เป็น HTML)
    // ให้ request ที่ UA ไม่ผ่าน และ ethers ไม่มี option `fetchOptions`
    // การส่ง header ต้องทำที่ FetchRequest แล้วส่งตัวนั้นเข้า provider
    const fetchReq = new FetchRequest(rpcUrl);
    fetchReq.setHeader('User-Agent', process.env.TPIX_USER_AGENT || 'TPIX-Treasury/1.0');
    fetchReq.setHeader('Content-Type', 'application/json');

    const provider = new JsonRpcProvider(fetchReq, chainId, { staticNetwork: true });

    if (action === 'send') {
        const raw = process.env.TPIX_RAW_TX;
        if (!raw) fail('ไม่ได้ส่ง TPIX_RAW_TX มา', 'config');

        try {
            const sent = await provider.broadcastTransaction(raw);
            return out({ ok: true, txHash: sent.hash, alreadyKnown: false });
        } catch (e) {
            const msg = String(e?.message || e).toLowerCase();

            // เชนบอกว่ารู้จัก tx นี้อยู่แล้ว = เคยส่งไปแล้วรอบก่อน ไม่ใช่ความล้มเหลว
            // ถ้าถือว่าล้มเหลวแล้วเซ็นใหม่ด้วย nonce ใหม่ = จ่ายเงินซ้ำสองรอบ
            if (msg.includes('already known') || msg.includes('already exists') || msg.includes('duplicate')) {
                const parsed = Transaction.from(raw);
                return out({ ok: true, txHash: parsed.hash, alreadyKnown: true });
            }

            // nonce ต่ำไป = nonce นี้ถูกใช้ไปแล้ว อาจเป็น tx ของเราเองที่ผ่านไปแล้ว
            // ต้องให้ฝั่ง PHP ไปตรวจ receipt ของ hash ที่บันทึกไว้ ห้ามเซ็นใหม่
            if (msg.includes('nonce too low') || msg.includes('nonce is too low')) {
                const parsed = Transaction.from(raw);
                return out({ ok: false, error: 'nonce ถูกใช้ไปแล้ว', code: 'nonce_used', txHash: parsed.hash });
            }

            return fail(e?.message || e, 'broadcast_failed');
        }
    }

    // ── action = sign ────────────────────────────────────────────────────
    const ksPath = process.env.TPIX_KEYSTORE_PATH;
    const ksPass = process.env.TPIX_KEYSTORE_PASS;
    const to = process.env.TPIX_TO;
    const valueWei = process.env.TPIX_VALUE_WEI;

    if (!ksPath || !ksPass) fail('ไม่ได้ตั้ง keystore หรือ passphrase', 'config');
    if (!to || !valueWei) fail('ไม่ได้ระบุปลายทางหรือจำนวนเงิน', 'config');
    if (!fs.existsSync(ksPath)) fail(`ไม่พบไฟล์ keystore ที่ ${ksPath}`, 'keystore_missing');

    let wallet;
    try {
        wallet = await Wallet.fromEncryptedJson(fs.readFileSync(ksPath, 'utf8'), ksPass);
    } catch (e) {
        // ไม่แนบข้อความดิบจาก ethers เพราะบางกรณีมีเนื้อ keystore ปนมา
        fail('ถอดรหัส keystore ไม่สำเร็จ (passphrase ผิดหรือไฟล์เสีย)', 'keystore_decrypt_failed');
    }

    // ด่านกันใช้ keystore ผิดใบ — ถ้าที่อยู่ไม่ตรงกับที่ระบบคาดไว้ ห้ามเซ็นเด็ดขาด
    const expect = process.env.TPIX_EXPECT_ADDRESS;
    if (expect && getAddress(wallet.address) !== getAddress(expect)) {
        fail(`keystore ถอดออกมาได้ ${wallet.address} แต่ระบบคาดว่าเป็น ${expect}`, 'address_mismatch');
    }

    const nonce = process.env.TPIX_NONCE !== undefined && process.env.TPIX_NONCE !== ''
        ? Number(process.env.TPIX_NONCE)
        : await provider.getTransactionCount(wallet.address, 'pending');

    // เชน TPIX แก๊สเป็นศูนย์ (--price-limit 0) แต่ยังต้องใส่ gasLimit ให้ถูก
    // โอน native ธรรมดาใช้ 21000 พอดี ไม่ต้อง estimate ให้เสียเวลาและเสี่ยง RPC ล่ม
    const tx = {
        type: 0,
        chainId,
        nonce,
        to: getAddress(to),
        value: BigInt(valueWei),
        gasLimit: 21000n,
        gasPrice: 0n,
        data: '0x',
    };

    const raw = await wallet.signTransaction(tx);
    const parsed = Transaction.from(raw);

    out({ ok: true, address: wallet.address, nonce, txHash: parsed.hash, raw });
}

main().catch((e) => fail(e?.message || e, 'unexpected'));
