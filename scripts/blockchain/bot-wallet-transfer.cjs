#!/usr/bin/env node
/**
 * bot-wallet-transfer.cjs — เซ็น/ส่งธุรกรรมโอนจากกระเป๋าบอท (BSC หรือเชน EVM ทั่วไป)
 *
 * เรียกจาก App\Services\AiBot\Wallet\BotWalletSigner (ฝั่ง CLI เท่านั้น)
 * ความลับส่งผ่าน environment variable ไม่ใช่ argv (argv โผล่ใน `ps aux`)
 *
 * env:
 *   BW_ACTION          sign | send
 *   BW_PRIVATE_KEY     hex 64 ตัว (ไม่มี 0x) — ใช้กับ sign
 *   BW_EXPECT_ADDRESS  ที่อยู่ที่กุญแจนี้ต้องถอดออกมาได้ (fail-closed)
 *   BW_RPC_URL / BW_CHAIN_ID / BW_USER_AGENT
 *   BW_TO              ปลายทาง
 *   BW_VALUE_WEI       จำนวน (หน่วยเล็กสุด, decimal string)
 *   BW_TOKEN           ที่อยู่ ERC-20 (ว่าง = เหรียญหลักของเชน)
 *   BW_NONCE           ว่าง = ถามเชนเอง
 *   BW_RAW_TX          ใช้กับ send
 *
 * ผลลัพธ์: JSON บรรทัดเดียวทาง stdout
 *   { ok: true, address, nonce, txHash, raw, gasLimit, gasPrice }   (sign)
 *   { ok: true, txHash, alreadyKnown }                              (send)
 *   { ok: false, error, code }
 *
 * **ไม่พิมพ์ private key ออกทาง stdout/stderr ไม่ว่ากรณีใด**
 */

const { Wallet, JsonRpcProvider, FetchRequest, Transaction, Interface, getAddress } = require('ethers');

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

const ERC20 = new Interface(['function transfer(address to, uint256 value) returns (bool)']);

async function main() {
    const action = process.env.BW_ACTION || 'sign';
    const rpcUrl = process.env.BW_RPC_URL;
    const chainId = Number(process.env.BW_CHAIN_ID || 0);

    if (!rpcUrl) fail('ไม่ได้ตั้ง BW_RPC_URL', 'config');
    if (!chainId) fail('ไม่ได้ตั้ง BW_CHAIN_ID', 'config');

    const fetchReq = new FetchRequest(rpcUrl);
    fetchReq.setHeader('User-Agent', process.env.BW_USER_AGENT || 'TPIX-BotWallet/1.0');
    fetchReq.setHeader('Content-Type', 'application/json');

    const provider = new JsonRpcProvider(fetchReq, chainId, { staticNetwork: true });

    if (action === 'send') {
        const raw = process.env.BW_RAW_TX;
        if (!raw) fail('ไม่ได้ส่ง BW_RAW_TX มา', 'config');

        try {
            const sent = await provider.broadcastTransaction(raw);
            return out({ ok: true, txHash: sent.hash, alreadyKnown: false });
        } catch (e) {
            const msg = String(e?.message || e).toLowerCase();

            // เชนรู้จัก tx นี้แล้ว = ส่งไปรอบก่อนแล้ว ไม่ใช่ความล้มเหลว (ห้ามเซ็นใหม่)
            if (msg.includes('already known') || msg.includes('already exists') || msg.includes('duplicate')) {
                return out({ ok: true, txHash: Transaction.from(raw).hash, alreadyKnown: true });
            }

            if (msg.includes('nonce too low') || msg.includes('nonce is too low')) {
                return out({ ok: false, error: 'nonce ถูกใช้ไปแล้ว', code: 'nonce_used', txHash: Transaction.from(raw).hash });
            }

            return fail(e?.message || e, 'broadcast_failed');
        }
    }

    // ── action = sign ────────────────────────────────────────────────────
    const privateKey = process.env.BW_PRIVATE_KEY;
    const to = process.env.BW_TO;
    const valueWei = process.env.BW_VALUE_WEI;
    const token = (process.env.BW_TOKEN || '').trim();

    if (!privateKey || !/^[0-9a-fA-F]{64}$/.test(privateKey)) fail('กุญแจไม่ถูกต้อง', 'config');
    if (!to || !valueWei) fail('ไม่ได้ระบุปลายทางหรือจำนวนเงิน', 'config');

    let wallet;
    try {
        wallet = new Wallet('0x' + privateKey, provider);
    } catch (e) {
        fail('สร้างกระเป๋าจากกุญแจไม่สำเร็จ', 'key_invalid');
    }

    const expect = process.env.BW_EXPECT_ADDRESS;
    if (expect && getAddress(wallet.address) !== getAddress(expect)) {
        fail(`กุญแจถอดออกมาได้ ${wallet.address} แต่ระบบคาดว่าเป็น ${expect}`, 'address_mismatch');
    }

    const nonce = process.env.BW_NONCE !== undefined && process.env.BW_NONCE !== ''
        ? Number(process.env.BW_NONCE)
        : await provider.getTransactionCount(wallet.address, 'pending');

    const value = BigInt(valueWei);
    const isToken = token !== '';

    const request = isToken
        ? { to: getAddress(token), value: 0n, data: ERC20.encodeFunctionData('transfer', [getAddress(to), value]) }
        : { to: getAddress(to), value, data: '0x' };

    // แก๊ส: BSC ใช้ legacy gasPrice · ประเมินแล้วเผื่อ 20% · ถ้าประเมินไม่ได้ใช้ค่าปลอดภัย
    let gasPrice;
    try {
        const fee = await provider.getFeeData();
        gasPrice = fee.gasPrice ?? 3_000_000_000n;
    } catch (_) {
        gasPrice = 3_000_000_000n;
    }

    let gasLimit;
    try {
        const estimated = await provider.estimateGas({ from: wallet.address, ...request });
        gasLimit = (estimated * 120n) / 100n;
    } catch (e) {
        // โอนโทเคนที่ประเมินไม่ผ่านมักแปลว่ายอดไม่พอ — บอกให้ชัดแทนที่จะส่งไปให้ revert
        if (isToken) fail('ประเมินแก๊สไม่ผ่าน (ยอดโทเคนอาจไม่พอ): ' + shorten(e), 'estimate_failed');
        gasLimit = 21000n;
    }

    const tx = { type: 0, chainId, nonce, gasLimit, gasPrice, ...request };

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
}

main().catch((e) => fail(e?.message || e, 'unexpected'));
