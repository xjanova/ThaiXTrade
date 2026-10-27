/**
 * TPIX TRADE — สคริปต์ deploy เหรียญ (scripts/blockchain/create-token.cjs) กับเชนจำลอง
 *
 * รันสคริปต์ตัวจริงด้วย node แล้วชี้ RPC ไปที่ JSON-RPC ปลอมในเครื่อง (ไม่แตะเชนจริงเด็ดขาด —
 * TPIX_RPC_FALLBACK_URLS ว่างเสมอ)
 *
 * กัน: ส่งธุรกรรมก่อนบอก hash · hash ที่บอกไม่ตรงกับที่ส่ง · ส่งซ้ำ · รอ receipt ไม่มีกำหนด
 *      · RPC ผิดเชนแล้วยังเซ็น · สร้างซ้ำทั้งที่บนเชนมีเหรียญของเจ้าของ+ชื่อ+สัญลักษณ์นี้แล้ว
 *      · โหมดตรวจผลเซ็นธุรกรรมใหม่ / ส่งซ้ำทั้งที่ nonce ถูกใช้ไปแล้ว
 */

import { describe, it, expect } from 'vitest';
import http from 'node:http';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(__dirname, '../../..');
const SCRIPT = path.join(ROOT, 'scripts/blockchain/create-token.cjs');
const { ethers } = require('ethers');

const FACTORY = '0x' + 'aa'.repeat(20);
const OWNER = '0x' + '11'.repeat(20);
const KEY = '0x' + '22'.repeat(32); // คีย์ทดสอบ ไม่ผูกกับอะไรจริง

function block() {
    return {
        number: '0x64', hash: '0x' + '01'.repeat(32), parentHash: '0x' + '02'.repeat(32), nonce: '0x0000000000000000',
        timestamp: '0x1', difficulty: '0x0', gasLimit: '0x1c9c380', gasUsed: '0x0', miner: '0x' + '00'.repeat(20),
        extraData: '0x', baseFeePerGas: '0x0', transactions: [],
    };
}

function receipt(hash, status, logs = []) {
    return {
        transactionHash: hash, status, to: FACTORY, from: '0x' + '33'.repeat(20), blockNumber: '0x50',
        blockHash: '0x' + 'ee'.repeat(32), transactionIndex: '0x0', logs, gasUsed: '0x1', cumulativeGasUsed: '0x1',
        logsBloom: '0x' + '00'.repeat(256), contractAddress: null, effectiveGasPrice: '0x0', type: '0x0',
    };
}

/** เชนจำลอง — overrides[method](params) แทนค่าตั้งต้นได้ */
async function withChain(overrides, fn) {
    const state = { calls: [], sent: [] };
    const handle = (method, params) => {
        state.calls.push(method);
        if (overrides[method]) return overrides[method](params);
        switch (method) {
            case 'eth_chainId': return '0x10c1';
            case 'eth_blockNumber': return '0x64';
            case 'eth_getCode': return '0x6080604052';
            case 'eth_getLogs': return [];
            case 'eth_getTransactionCount': return '0x5';
            case 'eth_estimateGas': return '0x5208';
            case 'eth_gasPrice': return '0x0';
            case 'eth_maxPriorityFeePerGas': return '0x0';
            case 'eth_getBlockByNumber': return block();
            case 'eth_sendRawTransaction': state.sent.push(params[0]); return ethers.keccak256(params[0]);
            default: return null;
        }
    };

    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            const msg = JSON.parse(body);
            const list = Array.isArray(msg) ? msg : [msg];
            // override ที่โยน = เชนตอบ JSON-RPC error (ไม่ใช่ทิ้ง request ค้าง)
            const out = list.map((m) => {
                try {
                    return { jsonrpc: '2.0', id: m.id, result: handle(m.method, m.params || []) };
                } catch (e) {
                    return { jsonrpc: '2.0', id: m.id, error: { code: -32000, message: e.message } };
                }
            });
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify(Array.isArray(msg) ? out : out[0]));
        });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    try {
        return await fn(`http://127.0.0.1:${server.address().port}`, state);
    } finally {
        server.close();
    }
}

function run(input, env) {
    return new Promise((resolve) => {
        const child = spawn(process.execPath, [SCRIPT], {
            env: { ...process.env, TPIX_RPC_FALLBACK_URLS: '', TOKEN_FACTORY_V2_ADDRESS: FACTORY, ...env },
        });
        let out = '';
        child.stdout.on('data', (d) => { out += d; });
        child.on('close', (code) => {
            const lines = out.trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
            resolve({ code, lines, last: lines[lines.length - 1] });
        });
        child.stdin.end(JSON.stringify(input));
    });
}

const deployInput = { mode: 'deploy', name: 'Good', symbol: 'GOOD', decimals: 18, totalSupply: '1000000000000000000000', tokenOwner: OWNER, tokenType: 'standard', subOptions: {} };

describe('create-token.cjs', () => {
    it('prints the signed hash before broadcasting, sends that exact tx once, and stops waiting after the limit', async () => {
        await withChain({}, async (url, state) => {
            const started = Date.now();
            const { code, lines, last } = await run(deployInput, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, DEPLOY_WAIT_SECONDS: '1' });

            const signedAt = lines.findIndex((l) => l.event === 'signed');
            const broadcastAt = lines.findIndex((l) => l.event === 'broadcast');
            const signed = lines[signedAt];

            expect(signedAt).toBeGreaterThanOrEqual(0);
            expect(signedAt).toBeLessThan(broadcastAt);
            expect(signed.txHash).toBe(ethers.keccak256(signed.rawTx));
            expect(state.sent).toEqual([signed.rawTx]);

            const tx = ethers.Transaction.from(signed.rawTx);
            expect(Number(tx.chainId)).toBe(4289);
            expect(tx.to.toLowerCase()).toBe(FACTORY);
            expect(signed.nonce).toBe(5);

            expect(last.pending).toBe(true);
            expect(code).toBe(3);
            expect(Date.now() - started).toBeLessThan(15000);
            // ด่านกันสร้างซ้ำบนเชนรันก่อนเซ็น
            expect(state.calls.indexOf('eth_getLogs')).toBeLessThan(state.calls.indexOf('eth_sendRawTransaction'));
        });
    }, 30000);

    it('refuses to sign when the RPC is a different chain', async () => {
        await withChain({ eth_chainId: () => '0x38' }, async (url, state) => {
            const { last, lines } = await run(deployInput, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, DEPLOY_WAIT_SECONDS: '1' });

            expect(last.success).toBe(false);
            expect(last.error).toContain('chainId');
            expect(lines.some((l) => l.event === 'signed')).toBe(false);
            expect(state.sent).toEqual([]);
        });
    }, 30000);

    it('reports the existing token instead of sending a new tx when the owner already has it on chain', async () => {
        const iface = new ethers.Interface(require(path.join(ROOT, 'scripts/blockchain/abi/TPIXTokenFactoryV2.json')).abi);
        const tokenAddress = '0x' + 'cc'.repeat(20);
        const txHash = '0x' + 'dd'.repeat(32);
        const encoded = iface.encodeEventLog(iface.getEvent('TokenCreated'), [tokenAddress, 'Good', 'GOOD', OWNER, 0]);
        const log = {
            address: FACTORY, topics: encoded.topics, data: encoded.data, blockNumber: '0x50', transactionHash: txHash,
            transactionIndex: '0x0', blockHash: '0x' + 'ee'.repeat(32), logIndex: '0x0', removed: false,
        };

        await withChain({
            eth_getLogs: () => [log],
            eth_getTransactionReceipt: () => receipt(txHash, '0x1', [log]),
        }, async (url, state) => {
            const { last, lines } = await run(deployInput, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, DEPLOY_WAIT_SECONDS: '1' });

            expect(last.success).toBe(true);
            expect(last.alreadyExisted).toBe(true);
            expect(last.contractAddress.toLowerCase()).toBe(tokenAddress);
            expect(lines.some((l) => l.event === 'signed')).toBe(false);
            expect(state.sent).toEqual([]);
        });
    }, 30000);

    it('receipt mode never signs: dropped when the nonce is already used, otherwise rebroadcasts the same raw tx', async () => {
        const wallet = new ethers.Wallet(KEY);

        const usedRaw = await wallet.signTransaction({ to: FACTORY, nonce: 3, gasLimit: 21000, gasPrice: 0, chainId: 4289, data: '0x' });
        await withChain({ eth_getTransactionCount: () => '0x5' }, async (url, state) => {
            const { last, code } = await run({ mode: 'receipt', txHash: ethers.keccak256(usedRaw), rawTx: usedRaw, waitSeconds: 1 }, { TPIX_RPC_URL: url });

            expect(last.dropped).toBe(true);
            expect(code).toBe(1);
            expect(state.sent).toEqual([]);
        });

        const freeRaw = await wallet.signTransaction({ to: FACTORY, nonce: 7, gasLimit: 21000, gasPrice: 0, chainId: 4289, data: '0x' });
        await withChain({ eth_getTransactionCount: () => '0x7' }, async (url, state) => {
            const { last, code } = await run({ mode: 'receipt', txHash: ethers.keccak256(freeRaw), rawTx: freeRaw, waitSeconds: 1 }, { TPIX_RPC_URL: url });

            expect(state.sent).toEqual([freeRaw]);
            expect(last.pending).toBe(true);
            expect(code).toBe(3);
        });
    }, 30000);

    it('receipt mode reports a reverted transaction as reverted', async () => {
        const hash = '0x' + 'ab'.repeat(32);
        await withChain({ eth_getTransactionReceipt: () => receipt(hash, '0x0') }, async (url) => {
            const { last } = await run({ mode: 'receipt', txHash: hash }, { TPIX_RPC_URL: url });
            expect(last.reverted).toBe(true);
        });
    }, 30000);

    it('refuses to sign when the on-chain duplicate check cannot run (fail closed)', async () => {
        await withChain({ eth_getLogs: () => { throw new Error('rpc down'); } }, async (url, state) => {
            const { last, lines } = await run(deployInput, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, DEPLOY_WAIT_SECONDS: '1' });

            expect(last.success).toBe(false);
            expect(last.code).toBe('DEDUPE_UNAVAILABLE');
            expect(lines.some((l) => l.event === 'signed')).toBe(false);
            expect(state.sent).toEqual([]);
        });
    }, 30000);

    it('refuses to sign when an existing token is found but its receipt cannot be read', async () => {
        const iface = new ethers.Interface(require(path.join(ROOT, 'scripts/blockchain/abi/TPIXTokenFactoryV2.json')).abi);
        const encoded = iface.encodeEventLog(iface.getEvent('TokenCreated'), ['0x' + 'cc'.repeat(20), 'Good', 'GOOD', OWNER, 0]);
        const log = {
            address: FACTORY, topics: encoded.topics, data: encoded.data, blockNumber: '0x50', transactionHash: '0x' + 'dd'.repeat(32),
            transactionIndex: '0x0', blockHash: '0x' + 'ee'.repeat(32), logIndex: '0x0', removed: false,
        };

        await withChain({ eth_getLogs: () => [log], eth_getTransactionReceipt: () => null }, async (url, state) => {
            const { last, lines } = await run(deployInput, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, DEPLOY_WAIT_SECONDS: '1' });

            expect(last.success).toBe(false);
            expect(last.code).toBe('DEDUPE_RECEIPT_UNREADABLE');
            expect(lines.some((l) => l.event === 'signed')).toBe(false);
            expect(state.sent).toEqual([]);
        });
    }, 30000);

    it('scans the whole lookback within the polygon-edge 1,000-block getLogs cap, and still finds an old token', async () => {
        const iface = new ethers.Interface(require(path.join(ROOT, 'scripts/blockchain/abi/TPIXTokenFactoryV2.json')).abi);
        const tokenAddress = '0x' + 'ab'.repeat(20);
        const txHash = '0x' + '9a'.repeat(32);
        const encoded = iface.encodeEventLog(iface.getEvent('TokenCreated'), [tokenAddress, 'Good', 'GOOD', OWNER, 0]);
        const log = {
            address: FACTORY, topics: encoded.topics, data: encoded.data, blockNumber: '0xbb8', transactionHash: txHash,
            transactionIndex: '0x0', blockHash: '0x' + 'ee'.repeat(32), logIndex: '0x0', removed: false,
        };
        const ranges = [];

        await withChain({
            eth_blockNumber: () => '0x4e20', // 20000 — เหรียญเดิมอยู่บล็อก 3000 เก่ากว่า 1,000 บล็อกล่าสุดมาก
            // เพดานจริงของ polygon-edge บนเชน TPIX: to - from > 1000 → "block range too high"
            // (เดิมเทสต์จำลองเพดาน 5,000 ซึ่งไม่ตรงของจริง สคริปต์จึงผ่านเทสต์แต่ล้มบนเชน)
            eth_getLogs: ([filter]) => {
                const from = Number(BigInt(filter.fromBlock));
                const to = Number(BigInt(filter.toBlock));
                ranges.push(to - from);
                if (to - from > 1000) throw new Error('block range too high');
                return from <= 3000 && 3000 <= to ? [log] : [];
            },
            eth_getTransactionReceipt: () => receipt(txHash, '0x1', [log]),
        }, async (url, state) => {
            const { last, lines } = await run(deployInput, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, DEPLOY_WAIT_SECONDS: '1' });

            expect(last.success).toBe(true);
            expect(last.alreadyExisted).toBe(true);
            expect(last.contractAddress.toLowerCase()).toBe(tokenAddress);
            expect(lines.some((l) => l.event === 'signed')).toBe(false);
            expect(state.sent).toEqual([]);
            expect(Math.max(...ranges)).toBeLessThanOrEqual(1000);
        });
    }, 30000);

    it('encodes a tiny mint price exactly instead of failing on "1e-7"', async () => {
        const nftIface = new ethers.Interface(require(path.join(ROOT, 'scripts/blockchain/abi/TPIXNFTFactory.json')).abi);

        await withChain({}, async (url) => {
            const { lines } = await run({
                mode: 'deploy', name: 'Art', symbol: 'ART', decimals: 0, totalSupply: '100', tokenOwner: OWNER,
                tokenType: 'nft_collection',
                subOptions: { mint_type: 'public', mint_price: '0.0000001', max_per_wallet: 5, max_per_tx: 2, reserve_count: 0 },
            }, { TPIX_RPC_URL: url, DEPLOYER_PRIVATE_KEY: KEY, NFT_FACTORY_ADDRESS: FACTORY, TOKEN_FACTORY_V2_ADDRESS: '', DEPLOY_WAIT_SECONDS: '1' });

            const signed = lines.find((l) => l.event === 'signed');
            expect(signed).toBeTruthy();

            const call = nftIface.parseTransaction({ data: ethers.Transaction.from(signed.rawTx).data });
            expect(call.name).toBe('createNFTCollection');
            expect(call.args[5]).toBe(100000000000n); // 0.0000001 TPIX = 1e11 wei
        });
    }, 30000);
});
