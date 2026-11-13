#!/usr/bin/env node
/**
 * bot-signer/server.cjs — ตัวเซ็นของกระเป๋าบอท รันบนเครื่องแยกจากเครื่องเว็บ
 *
 * ทำไมต้องมี
 * ──────────
 * เครื่องเว็บ tpix.online ใช้ผู้ใช้ระบบตัวเดียวร่วมกับเว็บอื่นหลายสิบเว็บ และผู้ใช้นั้นมีสิทธิ์
 * เทียบเท่า root (docker + sudo) เว็บไหนถูกเจาะ คนร้ายอ่าน .env ของ tpix.online ได้ทันที
 * ถ้าคีย์ห่อ (AIBOT_BOT_WALLET_KEY) อยู่ที่นั่น = ถอดกุญแจกระเป๋าบอทได้ทุกใบ = เงินลูกค้าหายทั้งหมด
 *
 * ตัวเซ็นนี้ย้ายสองอย่างออกจากเครื่องเว็บ:
 *   1. คีย์ห่อ — อยู่ใน env ของเครื่องนี้ที่เดียว เครื่องเว็บเก็บได้แค่ ciphertext
 *   2. การตัดสินว่า "เซ็นอะไรได้" — policy.cjs บังคับเอง ไม่เชื่อคำขอ
 *
 * การเซ็นจริงใช้สคริปต์ตัวเดิม (bot-wallet-transfer.cjs / bot-wallet-swap.cjs) ไม่มีโค้ดเซ็นชุดที่สอง
 *
 * เส้นทาง (POST, JSON, ต้องมีลายเซ็น HMAC — ดู auth.cjs):
 *   /v1/wallets         { owner }                                   → { address, sealed, keyVersion }
 *   /v1/sign/withdraw   { sealed, owner, address, token, amountWei, nonce? }
 *   /v1/sign/approve    { sealed, owner, address, token, router, amountWei }
 *   /v1/sign/swap       { sealed, owner, address, tokenIn, tokenOut, amountInWei, minOutWei, deadline, route }
 *   GET /health         ไม่ต้องมีลายเซ็น ตอบแค่ว่ายังอยู่
 *
 * คำตอบของ /v1/sign/* เป็นรูปเดียวกับที่สคริปต์เซ็นคืน: { ok, address, nonce, txHash, raw, ... }
 *
 * **ไม่เขียน private key ลง log / stdout / ไฟล์ ไม่ว่ากรณีใด** (บันทึกของกระเป๋าเก็บเฉพาะ ciphertext)
 */

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { execFile } = require('node:child_process');

const seal = require('./seal.cjs');
const auth = require('./auth.cjs');
const policy = require('./policy.cjs');

const KEY_VERSION = 2;
const MAX_BODY_BYTES = 16 * 1024;
const SCRIPT_TIMEOUT_MS = 120_000;
const SCRIPTS_DIR = path.resolve(__dirname, '..');

/**
 * env ของโปรเซสลูก (สคริปต์เซ็น) — มีเฉพาะที่จำเป็น ไม่ส่ง SIGNER_ENVELOPE_KEY / SIGNER_HMAC_SECRET ลงไป
 *
 * NODE_PATH ต้องส่งต่อ: ในอิมเมจ สคริปต์เซ็นอยู่ที่ /app แต่ ethers อยู่ที่ /app/bot-signer/node_modules
 * ไม่ส่ง = สคริปต์ require('ethers') ไม่เจอ = เซ็นไม่ได้เลยสักรายการ (ในรีโปไม่เห็นอาการนี้ เพราะ node หา
 * node_modules ของโฟลเดอร์แม่เจอเอง)
 */
function childEnv(env, parent = process.env) {
    const base = { PATH: parent.PATH || '' };

    if (parent.NODE_PATH) {
        base.NODE_PATH = parent.NODE_PATH;
    }

    return { ...base, ...env };
}

/** รันสคริปต์เซ็นตัวเดิมด้วย env ที่กำหนด — คืน JSON ที่สคริปต์พิมพ์ */
function runScript(scriptName, env) {
    return new Promise((resolve) => {
        execFile(
            process.execPath,
            [path.join(SCRIPTS_DIR, scriptName)],
            { env: childEnv(env), timeout: SCRIPT_TIMEOUT_MS, maxBuffer: 1024 * 1024 },
            (error, stdout) => {
                try {
                    resolve(JSON.parse(String(stdout).trim()));
                } catch (_) {
                    resolve({ ok: false, code: error && error.killed ? 'timeout' : 'bad_output', error: 'สคริปต์เซ็นคืนผลที่อ่านไม่ออก' });
                }
            },
        );
    });
}

function scriptEnv(config) {
    return {
        BW_RPC_URL: config.rpcUrl,
        BW_CHAIN_ID: String(config.chainId),
        BW_USER_AGENT: config.userAgent,
        BW_WBNB: config.contracts.wbnb,
        BW_V2_ROUTER: config.contracts.v2Router,
        BW_V3_ROUTER: config.contracts.v3Router,
        BW_V3_QUOTER: config.contracts.v3Quoter,
        BW_MULTICALL3: config.contracts.multicall3,
    };
}

/**
 * @param {object} deps
 * @param {object} deps.config       จาก policy.loadConfig()
 * @param {string} deps.envelopeKey  SIGNER_ENVELOPE_KEY
 * @param {string} deps.hmacSecret   SIGNER_HMAC_SECRET
 * @param {string} deps.stateDir     ที่เก็บตัวนับรายวัน + บันทึกกระเป๋าที่สร้าง
 * @param {Function} [deps.run]      ตัวรันสคริปต์ (ใส่ตัวปลอมได้ตอนทดสอบ)
 * @param {Function} [deps.now]      () => วินาที unix
 */
function createHandler(deps) {
    const { config, envelopeKey, hmacSecret, stateDir } = deps;
    const run = deps.run || runScript;
    const now = deps.now || (() => Math.floor(Date.now() / 1000));
    const seen = new Map();
    const counter = new policy.DailyCounter(path.join(stateDir, 'counters.json'), () => new Date(now() * 1000));
    const journal = path.join(stateDir, 'wallets.jsonl');

    /** ถอดกุญแจ + ยืนยันว่ากุญแจนี้เป็นของที่อยู่ที่ขอมา — เจ้าของผิด/ถูกแก้ = ถอดไม่ออก */
    function unlock(request) {
        let privateKey;

        try {
            privateKey = seal.open(request.sealed, request.owner, envelopeKey);
        } catch (_) {
            throw new policy.PolicyError('key_unreadable', 'ถอดกุญแจไม่ได้ (กุญแจไม่ใช่ของเจ้าของคนนี้ หรือถูกแก้ไข)');
        }

        if (seal.addressOf(privateKey) !== request.address) {
            throw new policy.PolicyError('address_mismatch', 'กุญแจไม่ตรงกับที่อยู่ของกระเป๋า');
        }

        return privateKey;
    }

    function takeSign(address) {
        if (!counter.take('sign:' + address, config.dailySignsPerWallet)) {
            throw new policy.PolicyError('policy_rate', 'กระเป๋านี้ขอลายเซ็นครบเพดานของวันนี้แล้ว');
        }
    }

    const routes = {
        '/v1/wallets': async (body) => {
            const owner = seal.normalizeOwner(body.owner);

            if (!counter.take('create', config.dailyWalletCreations)) {
                throw new policy.PolicyError('policy_rate', 'สร้างกระเป๋าครบเพดานของวันนี้แล้ว');
            }

            const pair = seal.generate();
            const sealed = seal.seal(pair.privateKey, owner, envelopeKey);

            // สำเนากู้คืน: ถ้าฐานข้อมูลของเครื่องเว็บถูกลบ/ถูกแก้ ciphertext ยังอยู่ที่นี่ (ถอดได้ด้วยคีย์ห่อเท่านั้น)
            // เขียนไม่สำเร็จ = ไม่คืนกระเป๋า — ไม่ยอมให้มีกระเป๋าที่รับเงินได้แต่ไม่มีสำเนากุญแจ
            fs.mkdirSync(stateDir, { recursive: true });
            fs.appendFileSync(
                journal,
                JSON.stringify({ at: new Date(now() * 1000).toISOString(), address: pair.address, owner, sealed, keyVersion: KEY_VERSION }) + '\n',
                { mode: 0o600 },
            );

            return { ok: true, address: pair.address, sealed, keyVersion: KEY_VERSION };
        },

        '/v1/sign/withdraw': async (body) => {
            const request = policy.checkWithdraw(body, config);
            const privateKey = unlock(request);
            takeSign(request.address);

            return run('bot-wallet-transfer.cjs', {
                ...scriptEnv(config),
                BW_ACTION: 'sign',
                BW_PRIVATE_KEY: privateKey,
                BW_EXPECT_ADDRESS: request.address,
                BW_TO: request.to,
                BW_VALUE_WEI: request.amountWei,
                BW_TOKEN: request.token || '',
                BW_NONCE: request.nonce === null ? '' : String(request.nonce),
            });
        },

        '/v1/sign/approve': async (body) => {
            const request = policy.checkApprove(body, config);
            const privateKey = unlock(request);
            takeSign(request.address);

            return run('bot-wallet-swap.cjs', {
                ...scriptEnv(config),
                BW_ACTION: 'sign-approve',
                BW_PRIVATE_KEY: privateKey,
                BW_EXPECT_ADDRESS: request.address,
                BW_TOKEN: request.token,
                BW_SPENDER: request.spender,
                BW_AMOUNT: request.amountWei,
            });
        },

        '/v1/sign/swap': async (body) => {
            const request = policy.checkSwap(body, config, now());
            const privateKey = unlock(request);

            // ถามเชนเองก่อนเซ็น — ไม่ใช้กุญแจ และไม่เชื่อ minOut ที่เครื่องเว็บคำนวณมา
            const plan = await run('bot-wallet-swap.cjs', {
                ...scriptEnv(config),
                BW_ACTION: 'plan',
                BW_OWNER: request.address,
                BW_TOKEN_IN: request.tokenIn,
                BW_TOKEN_OUT: request.tokenOut,
                BW_AMOUNT_IN: request.amountInWei,
                BW_ROUTER_PIN: '',
                BW_QUOTE_TOKEN: config.quoteToken,
            });

            policy.checkMinOut(request.minOutWei, plan && plan.ok && plan.best ? plan.best.amountOut : null, config);
            takeSign(request.address);

            return run('bot-wallet-swap.cjs', {
                ...scriptEnv(config),
                BW_ACTION: 'sign-swap',
                BW_PRIVATE_KEY: privateKey,
                BW_EXPECT_ADDRESS: request.address,
                BW_TOKEN_IN: request.tokenIn,
                BW_TOKEN_OUT: request.tokenOut,
                BW_AMOUNT_IN: request.amountInWei,
                BW_MIN_OUT: request.minOutWei,
                BW_DEADLINE: String(request.deadline),
                BW_ROUTE: JSON.stringify(request.route),
            });
        },
    };

    /** @returns {Promise<{status: number, body: object}>} */
    return async function handle({ method, url, headers, body }) {
        if (method === 'GET' && url === '/health') {
            return { status: 200, body: { ok: true, service: 'tpix-bot-signer', keyVersion: KEY_VERSION, chainId: config.chainId } };
        }

        const route = routes[url];

        if (method !== 'POST' || !route) {
            return { status: 404, body: { ok: false, code: 'not_found', error: 'ไม่มีเส้นทางนี้' } };
        }

        const verdict = auth.verify({ headers, path: url, body, secret: hmacSecret, now: now(), seen });

        if (!verdict.ok) {
            return { status: 401, body: { ok: false, code: verdict.code, error: 'คำขอไม่ผ่านการยืนยันตัวตน' } };
        }

        let parsed;

        try {
            parsed = JSON.parse(body.toString('utf8'));
        } catch (_) {
            parsed = null;
        }

        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return { status: 400, body: { ok: false, code: 'request', error: 'เนื้อคำขอต้องเป็น JSON object' } };
        }

        try {
            const result = await route(parsed);

            // สคริปต์เซ็นปฏิเสธเอง (ประเมินแก๊สไม่ผ่าน ฯลฯ) = 200 พร้อม ok:false — เป็นคำตอบ ไม่ใช่ความผิดของตัวเซ็น
            return { status: 200, body: result };
        } catch (error) {
            if (error instanceof policy.PolicyError) {
                return { status: 422, body: { ok: false, code: error.code, error: error.message } };
            }

            if (error && (error.message === 'OWNER_FORMAT' || error.message === 'KEY_FORMAT')) {
                return { status: 422, body: { ok: false, code: 'request', error: 'รูปแบบข้อมูลไม่ถูกต้อง' } };
            }

            // ไม่ส่งข้อความของ error ภายในออกไป — อาจมี path หรือรายละเอียดของเครื่อง
            process.stderr.write(`[bot-signer] ${url} failed: ${String(error && error.message).slice(0, 200)}\n`);

            return { status: 500, body: { ok: false, code: 'signer_error', error: 'ตัวเซ็นทำงานไม่สำเร็จ' } };
        }
    };
}

function startServer(env = process.env) {
    const config = policy.loadConfig(env);
    const envelopeKey = env.SIGNER_ENVELOPE_KEY || '';
    const hmacSecret = env.SIGNER_HMAC_SECRET || '';
    const stateDir = env.SIGNER_STATE_DIR || path.join(__dirname, 'state');

    if (envelopeKey.length < 32) throw new Error('SIGNER_ENVELOPE_KEY ต้องยาวอย่างน้อย 32 ตัว');
    if (hmacSecret.length < 32) throw new Error('SIGNER_HMAC_SECRET ต้องยาวอย่างน้อย 32 ตัว');
    if (envelopeKey === hmacSecret) throw new Error('SIGNER_ENVELOPE_KEY กับ SIGNER_HMAC_SECRET ต้องไม่ใช่ค่าเดียวกัน');

    const handle = createHandler({ config, envelopeKey, hmacSecret, stateDir });

    const server = http.createServer((req, res) => {
        const chunks = [];
        let size = 0;
        let tooLarge = false;

        req.on('data', (chunk) => {
            size += chunk.length;

            if (size > MAX_BODY_BYTES) {
                tooLarge = true;
                req.destroy();
            } else {
                chunks.push(chunk);
            }
        });

        req.on('end', async () => {
            if (tooLarge) return;

            let answer;

            try {
                answer = await handle({ method: req.method, url: req.url, headers: req.headers, body: Buffer.concat(chunks) });
            } catch (_) {
                answer = { status: 500, body: { ok: false, code: 'signer_error', error: 'ตัวเซ็นทำงานไม่สำเร็จ' } };
            }

            res.writeHead(answer.status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
            res.end(JSON.stringify(answer.body));
        });
    });

    // ค่าปริยายผูก 127.0.0.1 — จะรับจากเครื่องเว็บต้องตั้ง SIGNER_BIND เป็น IP ของลิงก์ส่วนตัว (เช่น WireGuard) เอง
    const [host, port] = (env.SIGNER_BIND || '127.0.0.1:8787').split(':');

    server.listen(Number(port), host, () => {
        process.stdout.write(`[bot-signer] listening on ${host}:${port} chain=${config.chainId} tokens=${config.tradeTokens.length}\n`);
    });

    return server;
}

if (require.main === module) {
    try {
        startServer();
    } catch (error) {
        process.stderr.write(`[bot-signer] ไม่เริ่มทำงาน: ${error.message}\n`);
        process.exit(1);
    }
}

module.exports = { createHandler, startServer, runScript, childEnv, KEY_VERSION };
