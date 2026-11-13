/**
 * ตัวเซ็นกระเป๋าบอท (scripts/blockchain/bot-signer) — ห่อกุญแจ ยืนยันคำขอ กติกา และตัวจัดการคำขอ
 *
 * รันใน environment ปริยายของโปรเจกต์ (jsdom) เพราะ tests/js/setup.js ใช้ localStorage
 * โมดูลของตัวเซ็นเป็น CommonJS ที่ใช้แต่ของ node (crypto, fs) ซึ่งยังเรียกได้ตามปกติ
 *
 * สคริปต์เซ็นจริง (ที่ต่อเชน) ถูกแทนด้วยตัวปลอม — เทสต์นี้พิสูจน์ว่า "อะไรถูกส่งไปให้เซ็น และอะไรถูกปฏิเสธ"
 * ภายใต้สมมติฐานว่าเครื่องเว็บถูกยึดและส่งคำขอได้ทุกรูปแบบ
 */
import { describe, it, expect, beforeEach } from 'vitest';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';

import seal from '../../../scripts/blockchain/bot-signer/seal.cjs';
import auth from '../../../scripts/blockchain/bot-signer/auth.cjs';
import policy from '../../../scripts/blockchain/bot-signer/policy.cjs';
import server from '../../../scripts/blockchain/bot-signer/server.cjs';

const ENVELOPE = 'envelope-key-that-lives-only-on-the-signer-host';
const SECRET = 'hmac-secret-shared-with-the-web-host-1234567890';
const OWNER = '0x1111111111111111111111111111111111111111';
const OTHER = '0x2222222222222222222222222222222222222222';

const USDT = '0x55d398326f99059ff775485246999027b3197955';
const BTCB = '0x7130d2a12b9bcbfae4f2634d864a1ee1ce3ead9c';
const ETH = '0x2170ed0880ac9a755fd29b2688956bd959f933f8';
const WBNB = '0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c';
const SHIB = '0x2859e4544c4bb03966803b044a93563bd2d0dd4d';

const ENV = {
    SIGNER_RPC_URL: 'http://rpc.invalid',
    SIGNER_WBNB: WBNB,
    SIGNER_V2_ROUTER: '0x10ED43C718714eb63d5aA57B78B54704E256024E',
    SIGNER_V3_ROUTER: '0x13f4EA83D0bd40E75C8222255bc855a974568Dd4',
    SIGNER_V3_QUOTER: '0xB048Bbc1Ee6b733FFfCFb9e9CeF7375518e25997',
    SIGNER_MULTICALL3: '0xcA11bde05977b3631167028862bE2a173976CA11',
    SIGNER_QUOTE_TOKEN: USDT,
    SIGNER_TRADE_TOKENS: `${BTCB},${ETH}`,
    SIGNER_DAILY_SIGNS_PER_WALLET: '3',
};

const NOW = 1_800_000_000;
const usdt = (n) => (BigInt(n) * 10n ** 18n).toString();

describe('ห่อกุญแจ', () => {
    it('สร้าง ห่อ แล้วถอดกลับได้กุญแจเดิมและที่อยู่เดิม', () => {
        const pair = seal.generate();
        const sealed = seal.seal(pair.privateKey, OWNER, ENVELOPE);

        expect(pair.privateKey).toMatch(/^[0-9a-f]{64}$/);
        expect(pair.address).toMatch(/^0x[0-9a-f]{40}$/);
        expect(sealed).not.toContain(pair.privateKey);
        expect(Buffer.from(sealed, 'base64')).toHaveLength(12 + 16 + 32);

        const opened = seal.open(sealed, OWNER.toUpperCase().replace('0X', '0x'), ENVELOPE);
        expect(opened).toBe(pair.privateKey);
        expect(seal.addressOf(opened)).toBe(pair.address);
    });

    it('เจ้าของไม่ตรง คีย์ห่อผิด หรือ ciphertext ถูกแก้ = ถอดไม่ออก', () => {
        const pair = seal.generate();
        const sealed = seal.seal(pair.privateKey, OWNER, ENVELOPE);
        const tampered = Buffer.from(sealed, 'base64');
        tampered[40] ^= 0x01;

        expect(() => seal.open(sealed, OTHER, ENVELOPE)).toThrow('KEY_UNREADABLE');
        expect(() => seal.open(sealed, OWNER, ENVELOPE + 'x')).toThrow('KEY_UNREADABLE');
        expect(() => seal.open(tampered.toString('base64'), OWNER, ENVELOPE)).toThrow('KEY_UNREADABLE');
        expect(() => seal.open('AAAA', OWNER, ENVELOPE)).toThrow('KEY_UNREADABLE');
    });

    it('คีย์ห่อสั้นเกินไปถูกปฏิเสธ', () => {
        expect(() => seal.seal('a'.repeat(64), OWNER, 'short')).toThrow('ENVELOPE_KEY_MISSING');
    });
});

describe('ยืนยันคำขอ', () => {
    const body = Buffer.from('{"owner":"x"}');
    const headers = (overrides = {}) => {
        const ts = String(overrides.ts ?? NOW);
        const nonce = overrides.nonce ?? crypto.randomBytes(16).toString('hex');

        return {
            'x-signer-timestamp': ts,
            'x-signer-nonce': nonce,
            'x-signer-signature': overrides.signature ?? auth.signature(overrides.secret ?? SECRET, ts, nonce, overrides.path ?? '/v1/wallets', overrides.body ?? body),
        };
    };
    const verify = (h, seen = new Map()) => auth.verify({ headers: h, path: '/v1/wallets', body, secret: SECRET, now: NOW, seen });

    it('ลายเซ็นถูกต้องผ่าน และใช้ซ้ำไม่ได้', () => {
        const seen = new Map();
        const h = headers();

        expect(verify(h, seen)).toEqual({ ok: true });
        expect(verify(h, seen)).toEqual({ ok: false, code: 'auth_replay' });
    });

    it('secret ผิด เส้นทางต่าง เนื้อคำขอต่าง หรือหมดเวลา ถูกปฏิเสธ', () => {
        expect(verify(headers({ secret: SECRET + 'x' })).code).toBe('auth_bad_signature');
        expect(verify(headers({ path: '/v1/sign/swap' })).code).toBe('auth_bad_signature');
        expect(verify(headers({ body: Buffer.from('{"owner":"y"}') })).code).toBe('auth_bad_signature');
        expect(verify(headers({ ts: NOW - 91 })).code).toBe('auth_expired');
        expect(verify(headers({ ts: NOW + 91 })).code).toBe('auth_expired');
        expect(verify({}).code).toBe('auth_malformed');
        expect(verify(headers({ signature: 'zz' })).code).toBe('auth_malformed');
    });

    it('ลายเซ็นของค่าคงที่ชุดนี้ต้องตรงกับที่ฝั่ง PHP คำนวณ (เวกเตอร์เดียวกับ BotWalletRemoteSignerTest)', () => {
        const fixed = Buffer.from('{"owner":"0x1111111111111111111111111111111111111111"}');

        expect(auth.signature('s'.repeat(40), '1800000000', 'abcdef0123456789abcdef0123456789', '/v1/wallets', fixed))
            .toBe('021948bc1c61062b7837417b17514ec0a624dad32497aa9c55ed05649ce2c5d4');
    });

    it('ไม่ได้ตั้ง secret = ปฏิเสธทุกคำขอ', () => {
        expect(auth.verify({ headers: headers(), path: '/v1/wallets', body, secret: '', now: NOW, seen: new Map() }).code).toBe('auth_not_configured');
    });
});

describe('กติกาของตัวเซ็น', () => {
    const config = policy.loadConfig(ENV);
    const key = { sealed: 'A'.repeat(80), owner: OWNER, address: OTHER };
    const swap = (overrides = {}) => ({
        ...key,
        tokenIn: USDT,
        tokenOut: BTCB,
        amountInWei: usdt(100),
        minOutWei: '1000',
        deadline: NOW + 300,
        route: { kind: 'v2', tokens: [USDT, BTCB], fees: [] },
        ...overrides,
    });
    const code = (fn) => {
        try {
            fn();
        } catch (e) {
            return e.code;
        }

        return null;
    };

    it('ค่าตั้งไม่ครบ = ไม่เริ่มทำงาน', () => {
        for (const name of ['SIGNER_RPC_URL', 'SIGNER_QUOTE_TOKEN', 'SIGNER_TRADE_TOKENS', 'SIGNER_V2_ROUTER']) {
            expect(code(() => policy.loadConfig({ ...ENV, [name]: '' })), name).toBe('config');
        }

        // เหรียญอ้างอิงห้ามอยู่ในรายการเหรียญที่เทรด
        expect(code(() => policy.loadConfig({ ...ENV, SIGNER_TRADE_TOKENS: USDT }))).toBe('config');
        expect(code(() => policy.loadConfig({ ...ENV, SIGNER_TRADE_TOKENS: 'not-an-address' }))).toBe('config_address');
    });

    it('ถอน: ปลายทางคือเจ้าของเสมอ และรับเฉพาะเหรียญในรายการ', () => {
        expect(policy.checkWithdraw({ ...key, token: null, amountWei: '5' }, config).to).toBe(OWNER);
        expect(policy.checkWithdraw({ ...key, token: USDT.toUpperCase().replace('0X', '0x'), amountWei: '5' }, config).token).toBe(USDT);

        expect(code(() => policy.checkWithdraw({ ...key, token: null, amountWei: '5', to: OTHER }, config))).toBe('policy_destination');
        expect(code(() => policy.checkWithdraw({ ...key, token: BTCB, amountWei: '5' }, config))).toBe('policy_token');
        expect(code(() => policy.checkWithdraw({ ...key, token: null, amountWei: '0' }, config))).toBe('request');
        expect(code(() => policy.checkWithdraw({ ...key, token: null, amountWei: 5 }, config))).toBe('request');
        expect(code(() => policy.checkWithdraw({ ...key, token: null, amountWei: '5', nonce: -1 }, config))).toBe('request');
    });

    it('approve: ผู้รับสิทธิ์มาจากค่าตั้งของตัวเซ็น ไม่ใช่จากคำขอ', () => {
        const ok = policy.checkApprove({ ...key, token: USDT, router: 'v3', amountWei: '9', spender: OTHER }, config);

        expect(ok.spender).toBe(ENV.SIGNER_V3_ROUTER.toLowerCase());
        expect(code(() => policy.checkApprove({ ...key, token: SHIB, router: 'v2', amountWei: '9' }, config))).toBe('policy_token');
        expect(code(() => policy.checkApprove({ ...key, token: USDT, router: OTHER, amountWei: '9' }, config))).toBe('policy_router');
    });

    it('สวอป: ต้องเป็น USDT ⇄ เหรียญในรายการเท่านั้น', () => {
        expect(policy.checkSwap(swap(), config, NOW).side).toBe('entry');
        expect(policy.checkSwap(swap({ tokenIn: BTCB, tokenOut: USDT, route: { kind: 'v2', tokens: [BTCB, USDT] } }), config, NOW).side).toBe('exit');

        // เหรียญนอกรายการ / สองฝั่งไม่มี USDT / USDT ทั้งสองฝั่ง
        expect(code(() => policy.checkSwap(swap({ tokenOut: SHIB, route: { kind: 'v2', tokens: [USDT, SHIB] } }), config, NOW))).toBe('policy_token');
        expect(code(() => policy.checkSwap(swap({ tokenIn: BTCB, tokenOut: ETH, route: { kind: 'v2', tokens: [BTCB, ETH] } }), config, NOW))).toBe('policy_token');
        expect(code(() => policy.checkSwap(swap({ tokenOut: USDT, route: { kind: 'v2', tokens: [USDT, USDT] } }), config, NOW))).toBe('policy_token');
    });

    it('สวอป: ไม้เข้ามีเพดาน แต่ไม้ออก (ปิดไม้) ไม่ถูกจำกัดขนาด', () => {
        expect(code(() => policy.checkSwap(swap({ amountInWei: usdt(500) }), config, NOW))).toBe(null);
        expect(code(() => policy.checkSwap(swap({ amountInWei: (BigInt(usdt(500)) + 1n).toString() }), config, NOW))).toBe('policy_size');

        const bigExit = swap({ tokenIn: BTCB, tokenOut: USDT, amountInWei: usdt(999999), route: { kind: 'v2', tokens: [BTCB, USDT] } });
        expect(code(() => policy.checkSwap(bigExit, config, NOW))).toBe(null);
    });

    it('สวอป: อายุธุรกรรมและเส้นทางต้องอยู่ในกรอบ', () => {
        expect(code(() => policy.checkSwap(swap({ deadline: NOW }), config, NOW))).toBe('policy_deadline');
        expect(code(() => policy.checkSwap(swap({ deadline: NOW + 901 }), config, NOW))).toBe('policy_deadline');
        expect(code(() => policy.checkSwap(swap({ deadline: '1800000300' }), config, NOW))).toBe('policy_deadline');

        expect(code(() => policy.checkSwap(swap({ route: { kind: 'v2', tokens: [USDT, ETH] } }), config, NOW))).toBe('policy_route');
        expect(code(() => policy.checkSwap(swap({ route: { kind: 'v2', tokens: [USDT, ETH, BTCB] } }), config, NOW))).toBe('policy_route');
        expect(code(() => policy.checkSwap(swap({ route: { kind: 'v2', tokens: [USDT, WBNB, BTCB] } }), config, NOW))).toBe(null);
        expect(code(() => policy.checkSwap(swap({ route: { kind: 'v4', tokens: [USDT, BTCB] } }), config, NOW))).toBe('policy_route');
        expect(code(() => policy.checkSwap(swap({ route: { kind: 'v3', tokens: [USDT, BTCB], fees: [123] } }), config, NOW))).toBe('policy_route');
        expect(code(() => policy.checkSwap(swap({ route: { kind: 'v3', tokens: [USDT, BTCB], fees: [500] } }), config, NOW))).toBe(null);
    });

    it('minOut ต้องไม่ต่ำกว่าราคาที่ตัวเซ็นถามเชนเองเกิน 6% และไม่มีราคา = ไม่เซ็น', () => {
        expect(code(() => policy.checkMinOut('9400', '10000', config))).toBe(null);
        expect(code(() => policy.checkMinOut('9399', '10000', config))).toBe('policy_min_out');
        expect(code(() => policy.checkMinOut('1', '10000', config))).toBe('policy_min_out');
        expect(code(() => policy.checkMinOut('9400', null, config))).toBe('policy_no_quote');
        expect(code(() => policy.checkMinOut('9400', '0', config))).toBe('policy_no_quote');
    });

    it('ตัวนับรายวันตัดที่เพดาน รอดการรีสตาร์ต และเริ่มใหม่เมื่อขึ้นวันใหม่', () => {
        const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'signer-count-')), 'counters.json');
        let day = new Date('2026-11-13T10:00:00Z');
        const counter = new policy.DailyCounter(file, () => day);

        expect(counter.take('a', 2)).toBe(true);
        expect(counter.take('a', 2)).toBe(true);
        expect(counter.take('a', 2)).toBe(false);
        expect(counter.take('b', 2)).toBe(true);

        const reloaded = new policy.DailyCounter(file, () => day);
        expect(reloaded.take('a', 2)).toBe(false);

        day = new Date('2026-11-14T00:00:01Z');
        expect(reloaded.take('a', 2)).toBe(true);

        fs.rmSync(path.dirname(file), { recursive: true, force: true });
    });
});

describe('ตัวจัดการคำขอ', () => {
    let stateDir;
    let calls;
    let handle;
    let bestOut;

    const signedRequest = (url, payload, secret = SECRET) => {
        const body = Buffer.from(JSON.stringify(payload));
        const nonce = crypto.randomBytes(16).toString('hex');

        return {
            method: 'POST',
            url,
            body,
            headers: {
                'x-signer-timestamp': String(NOW),
                'x-signer-nonce': nonce,
                'x-signer-signature': auth.signature(secret, String(NOW), nonce, url, body),
            },
        };
    };

    const post = (url, payload) => handle(signedRequest(url, payload));

    const createWallet = async (owner = OWNER) => {
        const answer = await post('/v1/wallets', { owner });

        return { sealed: answer.body.sealed, owner, address: answer.body.address };
    };

    beforeEach(() => {
        stateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'signer-state-'));
        calls = [];
        bestOut = '10000';

        handle = server.createHandler({
            config: policy.loadConfig(ENV),
            envelopeKey: ENVELOPE,
            hmacSecret: SECRET,
            stateDir,
            now: () => NOW,
            run: async (script, env) => {
                calls.push({ script, env });

                if (env.BW_ACTION === 'plan') {
                    return { ok: true, best: bestOut === null ? null : { kind: 'v2', amountOut: bestOut } };
                }

                return { ok: true, address: env.BW_EXPECT_ADDRESS, nonce: 0, txHash: '0x' + 'ab'.repeat(32), raw: '0xraw' };
            },
        });
    });

    const swapOf = (wallet, overrides = {}) => ({
        ...wallet,
        tokenIn: USDT,
        tokenOut: BTCB,
        amountInWei: usdt(100),
        minOutWei: '9900',
        deadline: NOW + 300,
        route: { kind: 'v2', tokens: [USDT, BTCB], fees: [] },
        ...overrides,
    });

    it('ไม่มีลายเซ็น หรือ secret ผิด = 401 และไม่มีอะไรถูกรัน', async () => {
        const unsigned = await handle({ method: 'POST', url: '/v1/wallets', headers: {}, body: Buffer.from('{}') });
        const wrong = await handle(signedRequest('/v1/wallets', { owner: OWNER }, SECRET + 'x'));

        expect(unsigned.status).toBe(401);
        expect(wrong.status).toBe(401);
        expect(calls).toHaveLength(0);
        expect(fs.existsSync(path.join(stateDir, 'wallets.jsonl'))).toBe(false);
    });

    it('สร้างกระเป๋า: คืนแค่ที่อยู่กับ ciphertext และจดสำเนากู้คืนไว้บนเครื่องตัวเซ็น', async () => {
        const answer = await post('/v1/wallets', { owner: OWNER.toUpperCase().replace('0X', '0x') });

        expect(answer.status).toBe(200);
        expect(Object.keys(answer.body).sort()).toEqual(['address', 'keyVersion', 'ok', 'sealed']);
        expect(answer.body.keyVersion).toBe(2);

        const privateKey = seal.open(answer.body.sealed, OWNER, ENVELOPE);
        expect(seal.addressOf(privateKey)).toBe(answer.body.address);
        expect(JSON.stringify(answer.body)).not.toContain(privateKey);

        const journal = fs.readFileSync(path.join(stateDir, 'wallets.jsonl'), 'utf8').trim().split('\n').map((l) => JSON.parse(l));
        expect(journal).toHaveLength(1);
        expect(journal[0]).toMatchObject({ address: answer.body.address, owner: OWNER, sealed: answer.body.sealed });
        expect(JSON.stringify(journal)).not.toContain(privateKey);
    });

    it('ถอน: สคริปต์เซ็นได้ปลายทางเป็นเจ้าของเสมอ และ env ของลูกไม่มีคีย์ห่อ', async () => {
        const wallet = await createWallet();
        const answer = await post('/v1/sign/withdraw', { ...wallet, token: USDT, amountWei: usdt(25) });

        expect(answer.status).toBe(200);
        expect(answer.body).toMatchObject({ ok: true, address: wallet.address, raw: '0xraw' });

        const { script, env } = calls[0];
        expect(script).toBe('bot-wallet-transfer.cjs');
        expect(env.BW_TO).toBe(OWNER);
        expect(env.BW_TOKEN).toBe(USDT);
        expect(env.BW_VALUE_WEI).toBe(usdt(25));
        expect(seal.addressOf(env.BW_PRIVATE_KEY)).toBe(wallet.address);
        expect(Object.keys(env).some((k) => k.startsWith('SIGNER_'))).toBe(false);
        expect(JSON.stringify(env)).not.toContain(ENVELOPE);
        expect(JSON.stringify(answer.body)).not.toContain(env.BW_PRIVATE_KEY);
    });

    it('ถอนไปที่อื่น หรือแก้เจ้าของในคำขอ = ไม่เซ็น', async () => {
        const wallet = await createWallet();

        const elsewhere = await post('/v1/sign/withdraw', { ...wallet, token: null, amountWei: '5', to: OTHER });
        // คนร้ายแก้ owner_address ในฐานข้อมูลเป็นกระเป๋าตัวเองแล้วขอถอน — กุญแจผูกกับเจ้าของเดิม จึงถอดไม่ออก
        const hijacked = await post('/v1/sign/withdraw', { ...wallet, owner: OTHER, token: null, amountWei: '5' });
        // เอา ciphertext ของคนอื่นมาใส่กระเป๋าตัวเอง
        const mine = await createWallet(OTHER);
        const swapped = await post('/v1/sign/withdraw', { ...mine, sealed: wallet.sealed, token: null, amountWei: '5' });

        expect([elsewhere.status, elsewhere.body.code]).toEqual([422, 'policy_destination']);
        expect([hijacked.status, hijacked.body.code]).toEqual([422, 'key_unreadable']);
        expect([swapped.status, swapped.body.code]).toEqual([422, 'key_unreadable']);
        expect(calls).toHaveLength(0);
    });

    it('ที่อยู่ในคำขอไม่ใช่ของกุญแจใบนั้น = ไม่เซ็น', async () => {
        const wallet = await createWallet();
        const answer = await post('/v1/sign/withdraw', { ...wallet, address: OTHER, token: null, amountWei: '5' });

        expect([answer.status, answer.body.code]).toEqual([422, 'address_mismatch']);
        expect(calls).toHaveLength(0);
    });

    it('สวอป: ถามราคาจากเชนเองก่อน แล้วเซ็นด้วยค่าที่ผ่านกติกา', async () => {
        const wallet = await createWallet();
        const answer = await post('/v1/sign/swap', swapOf(wallet));

        expect(answer.status).toBe(200);
        expect(answer.body.ok).toBe(true);
        expect(calls.map((c) => c.env.BW_ACTION)).toEqual(['plan', 'sign-swap']);
        expect(calls[0].env.BW_PRIVATE_KEY).toBeUndefined();
        expect(calls[0].env.BW_OWNER).toBe(wallet.address);
        expect(calls[1].env.BW_MIN_OUT).toBe('9900');
        expect(JSON.parse(calls[1].env.BW_ROUTE)).toEqual({ kind: 'v2', tokens: [USDT, BTCB], fees: [] });
        expect(calls[1].env.BW_V2_ROUTER).toBe(ENV.SIGNER_V2_ROUTER.toLowerCase());
    });

    it('สวอป: minOut ต่ำกว่าราคาบนเชนมาก หรือตัวเซ็นอ่านราคาเองไม่ได้ = ไม่เซ็น', async () => {
        const wallet = await createWallet();

        const lowball = await post('/v1/sign/swap', swapOf(wallet, { minOutWei: '1' }));
        bestOut = null;
        const blind = await post('/v1/sign/swap', swapOf(wallet));

        expect([lowball.status, lowball.body.code]).toEqual([422, 'policy_min_out']);
        expect([blind.status, blind.body.code]).toEqual([422, 'policy_no_quote']);
        expect(calls.filter((c) => c.env.BW_ACTION === 'sign-swap')).toHaveLength(0);
    });

    it('สวอปเหรียญนอกรายการถูกปฏิเสธก่อนแตะกุญแจหรือเชน', async () => {
        const wallet = await createWallet();
        const answer = await post('/v1/sign/swap', swapOf(wallet, { tokenOut: SHIB, route: { kind: 'v2', tokens: [USDT, SHIB] } }));

        expect([answer.status, answer.body.code]).toEqual([422, 'policy_token']);
        expect(calls).toHaveLength(0);
    });

    it('approve: ผู้รับสิทธิ์ที่ส่งให้สคริปต์คือ router จากค่าตั้งของตัวเซ็น', async () => {
        const wallet = await createWallet();
        const answer = await post('/v1/sign/approve', { ...wallet, token: USDT, router: 'v2', amountWei: usdt(100), spender: OTHER });

        expect(answer.body.ok).toBe(true);
        expect(calls[0].env.BW_ACTION).toBe('sign-approve');
        expect(calls[0].env.BW_SPENDER).toBe(ENV.SIGNER_V2_ROUTER.toLowerCase());
    });

    it('ขอลายเซ็นเกินเพดานต่อวันของกระเป๋า = ไม่เซ็นเพิ่ม', async () => {
        const wallet = await createWallet();
        const codes = [];

        for (let i = 0; i < 4; i++) {
            const answer = await post('/v1/sign/withdraw', { ...wallet, token: null, amountWei: '5' });
            codes.push(answer.body.ok ? 'ok' : answer.body.code);
        }

        expect(codes).toEqual(['ok', 'ok', 'ok', 'policy_rate']);
        expect(calls).toHaveLength(3);
    });

    it('คำขอเดิมส่งซ้ำไม่ได้ และเนื้อคำขอที่ไม่ใช่ JSON object ถูกปฏิเสธ', async () => {
        const request = signedRequest('/v1/wallets', { owner: OWNER });

        expect((await handle(request)).status).toBe(200);
        expect((await handle(request)).body.code).toBe('auth_replay');
        expect((await post('/v1/wallets', [1, 2])).status).toBe(400);
        expect((await post('/v1/wallets', { owner: 'nope' })).status).toBe(422);
        expect((await post('/v1/unknown', {})).status).toBe(404);
    });

    it('env ของสคริปต์เซ็นได้ NODE_PATH ต่อจากตัวเซ็น แต่ไม่ได้ความลับของตัวเซ็น', () => {
        const parent = { PATH: '/usr/bin', NODE_PATH: '/app/bot-signer/node_modules', SIGNER_ENVELOPE_KEY: ENVELOPE, SIGNER_HMAC_SECRET: SECRET, HOME: '/home/node' };

        expect(server.childEnv({ BW_ACTION: 'plan' }, parent)).toEqual({
            PATH: '/usr/bin',
            NODE_PATH: '/app/bot-signer/node_modules',
            BW_ACTION: 'plan',
        });
        expect(server.childEnv({ BW_ACTION: 'plan' }, { PATH: '/usr/bin' })).toEqual({ PATH: '/usr/bin', BW_ACTION: 'plan' });
    });

    it('/health ตอบได้โดยไม่ต้องมีลายเซ็น และไม่บอกอะไรเกี่ยวกับกุญแจ', async () => {
        const answer = await handle({ method: 'GET', url: '/health', headers: {}, body: Buffer.alloc(0) });

        expect(answer.status).toBe(200);
        expect(answer.body).toEqual({ ok: true, service: 'tpix-bot-signer', keyVersion: 2, chainId: 56 });
    });
});
