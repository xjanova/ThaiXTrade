#!/usr/bin/env node
/**
 * uptime-oracle.cjs — เซ็น/ส่งธุรกรรม updateUptimeBatch ด้วยกุญแจ uptime oracle
 *
 * เรียกจาก App\Services\MasterNode\UptimeOracleSigner (ฝั่ง CLI เท่านั้น)
 * โครงเดียวกับ treasury-payout.cjs: ความลับส่งผ่าน env ไม่ใช่ argv (argv โผล่ใน `ps aux`)
 *
 * กุญแจนี้ตั้งได้แค่ uptime ของโหนด (ตัวคูณรางวัลอนาคต) — ห้ามเอาไปใช้กับงานอื่น
 * และห้ามใช้กุญแจ owner/deployer/treasury มาเป็น oracle
 *
 * env:
 *   TPIX_ACTION          address = ถอด keystore แล้วบอกที่อยู่ (ไม่เซ็นอะไร)
 *                        sign    = เซ็นธุรกรรมเรียกสัญญา คืน raw + hash (ยังไม่ส่ง)
 *                        send    = ส่ง raw ที่ให้มาขึ้นเชน
 *   TPIX_KEYSTORE_PATH   keystore V3 ของกุญแจ oracle
 *   TPIX_KEYSTORE_PASS   passphrase
 *   TPIX_EXPECT_ADDRESS  ที่อยู่ที่คาดว่าจะถอดได้ (ไม่ตรง = ไม่เซ็น)
 *   TPIX_RPC_URL / TPIX_CHAIN_ID / TPIX_USER_AGENT
 *   TPIX_TO              ที่อยู่สัญญา NodeRegistryV2
 *   TPIX_DATA            calldata (0x…) — สคริปต์รับเฉพาะ selector ของ updateUptimeBatch
 *   TPIX_RAW_TX          ใช้กับ action=send
 *
 * ผลลัพธ์: JSON บรรทัดเดียวทาง stdout · ไม่พิมพ์ private key ไม่ว่ากรณีใด
 */

const fs = require('fs');
const { Wallet, JsonRpcProvider, FetchRequest, Transaction, getAddress } = require('ethers');

// updateUptimeBatch(address[],uint256[]) — กันสคริปต์ถูกใช้เซ็นอย่างอื่นด้วยกุญแจนี้
const UPDATE_UPTIME_BATCH = '0xb949fb14';
// เพดานบล็อกของเชน 10M — เผื่อครึ่งหนึ่งไว้ให้ธุรกรรมอื่น
const MAX_GAS = 9000000n;

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
    const rpcUrl = process.env.TPIX_RPC_URL;
    const chainId = Number(process.env.TPIX_CHAIN_ID || 0);
    if (!rpcUrl) fail('ไม่ได้ตั้ง TPIX_RPC_URL', 'config');
    if (!chainId) fail('ไม่ได้ตั้ง TPIX_CHAIN_ID', 'config');

    // Cloudflare ตอบ 403 ให้ request ที่ไม่มี UA — ต้องตั้งที่ FetchRequest
    const req = new FetchRequest(rpcUrl);
    req.setHeader('User-Agent', process.env.TPIX_USER_AGENT || 'TPIX-UptimeOracle/1.0');
    req.setHeader('Content-Type', 'application/json');

    return { chainId, rpc: new JsonRpcProvider(req, chainId, { staticNetwork: true }) };
}

async function loadWallet() {
    const ksPath = process.env.TPIX_KEYSTORE_PATH;
    const ksPass = process.env.TPIX_KEYSTORE_PASS;
    if (!ksPath || !ksPass) fail('ไม่ได้ตั้ง keystore หรือ passphrase ของกุญแจ oracle', 'config');
    if (!fs.existsSync(ksPath)) fail(`ไม่พบไฟล์ keystore ที่ ${ksPath}`, 'keystore_missing');

    let wallet;
    try {
        wallet = await Wallet.fromEncryptedJson(fs.readFileSync(ksPath, 'utf8'), ksPass);
    } catch {
        // ไม่แนบข้อความดิบจาก ethers — บางกรณีมีเนื้อ keystore ปนมา
        fail('ถอดรหัส keystore ไม่สำเร็จ (passphrase ผิดหรือไฟล์เสีย)', 'keystore_decrypt_failed');
    }

    const expect = process.env.TPIX_EXPECT_ADDRESS;
    if (expect && getAddress(wallet.address) !== getAddress(expect)) {
        fail(`keystore ถอดออกมาได้ ${wallet.address} แต่ระบบคาดว่าเป็น ${expect}`, 'address_mismatch');
    }

    return wallet;
}

async function main() {
    const action = process.env.TPIX_ACTION || 'sign';

    if (action === 'address') {
        const wallet = await loadWallet();
        return out({ ok: true, address: wallet.address });
    }

    const { chainId, rpc } = provider();

    if (action === 'send') {
        const raw = process.env.TPIX_RAW_TX;
        if (!raw) fail('ไม่ได้ส่ง TPIX_RAW_TX มา', 'config');
        try {
            const sent = await rpc.broadcastTransaction(raw);
            return out({ ok: true, txHash: sent.hash, alreadyKnown: false });
        } catch (e) {
            const msg = String(e?.message || e).toLowerCase();
            // เคยส่งไปแล้วรอบก่อน — ไม่ใช่ความล้มเหลว
            if (msg.includes('already known') || msg.includes('already exists') || msg.includes('duplicate')) {
                return out({ ok: true, txHash: Transaction.from(raw).hash, alreadyKnown: true });
            }
            return fail(e?.message || e, 'broadcast_failed');
        }
    }

    if (action !== 'sign') fail(`ไม่รู้จัก action ${action}`, 'config');

    const to = process.env.TPIX_TO;
    const data = String(process.env.TPIX_DATA || '');
    if (!to) fail('ไม่ได้ระบุที่อยู่สัญญา', 'config');
    if (!/^0x[0-9a-fA-F]+$/.test(data) || !data.toLowerCase().startsWith(UPDATE_UPTIME_BATCH)) {
        fail('calldata ไม่ใช่ updateUptimeBatch — กุญแจ oracle เซ็นอย่างอื่นไม่ได้', 'bad_calldata');
    }

    const wallet = await loadWallet();
    const nonce = await rpc.getTransactionCount(wallet.address, 'pending');

    // ประมาณแก๊สจากเชนจริง — revert ตรงนี้ (เช่น "Not owner or uptime oracle") ดีกว่าไปเสีย nonce
    let gasLimit;
    try {
        const est = await rpc.estimateGas({ from: wallet.address, to: getAddress(to), data });
        gasLimit = (est * 12n) / 10n;
    } catch (e) {
        return fail(e?.shortMessage || e?.message || e, 'estimate_failed');
    }
    if (gasLimit > MAX_GAS) fail(`ชุดใหญ่เกินไป (แก๊ส ${gasLimit}) — ลด MASTERNODE_UPTIME_BATCH_SIZE`, 'gas_too_high');

    // เชน TPIX แก๊สเป็นศูนย์ (--price-limit 0)
    const raw = await wallet.signTransaction({
        type: 0,
        chainId,
        nonce,
        to: getAddress(to),
        value: 0n,
        gasLimit,
        gasPrice: 0n,
        data,
    });

    out({ ok: true, address: wallet.address, nonce, gasLimit: gasLimit.toString(), txHash: Transaction.from(raw).hash, raw });
}

main().catch((e) => fail(e?.message || e, 'unexpected'));
