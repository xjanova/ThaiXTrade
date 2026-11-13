/**
 * bot-signer/policy.cjs — กติกาที่ตัวเซ็นบังคับเอง โดยไม่เชื่อเครื่องเว็บ
 *
 * สมมติฐานของการออกแบบ: เครื่องเว็บ (ที่ใช้ผู้ใช้ระบบร่วมกับเว็บอื่น) ถูกยึดได้ทั้งเครื่อง
 * คนร้ายจึงมีทั้งฐานข้อมูล ทั้ง secret ที่ใช้เรียกตัวเซ็น และส่งคำขอได้ทุกรูปแบบ
 * สิ่งที่ต้องยังจริงอยู่ในสถานการณ์นั้น:
 *
 *   1. ถอนได้ทางเดียว คือกลับไปกระเป๋าเจ้าของ (เจ้าของ = AAD ที่ถอดกุญแจได้ ไม่ใช่ค่าที่ส่งมา)
 *   2. สวอปได้เฉพาะ USDT ⇄ เหรียญในรายการ ผ่าน router ในรายการ เหรียญที่ได้กลับเข้ากระเป๋าเดิม
 *   3. ขั้นต่ำขาออก (minOut) ต้องไม่ต่ำกว่าราคาที่ตัวเซ็นถามเชนเองเกินที่กำหนด — ตั้ง minOut = 1 แล้วดักกินส่วนต่างไม่ได้
 *   4. ไม้เข้าใหญ่สุดมีเพดาน และจำนวนลายเซ็นต่อกระเป๋าต่อวันมีเพดาน — วนสวอปเผาค่าธรรมเนียมได้จำกัด
 *
 * ไฟล์นี้เป็นฟังก์ชันล้วน (ไม่แตะเครือข่าย ไม่แตะกุญแจ) เพื่อให้ทดสอบได้ครบทุกกิ่ง
 */

const fs = require('node:fs');
const path = require('node:path');

const ADDRESS_RE = /^0x[0-9a-fA-F]{40}$/;
const UINT_RE = /^[0-9]{1,78}$/;
const V3_FEE_TIERS = [100, 500, 2500, 10000];

class PolicyError extends Error {
    constructor(code, message) {
        super(message || code);
        this.code = code;
    }
}

function lower(address) {
    return String(address || '').toLowerCase();
}

function requireAddress(value, code) {
    if (typeof value !== 'string' || !ADDRESS_RE.test(value)) {
        throw new PolicyError(code, 'ที่อยู่ไม่ถูกต้อง');
    }

    return lower(value);
}

function requireUint(value, code, { positive = true } = {}) {
    if (typeof value !== 'string' || !UINT_RE.test(value)) {
        throw new PolicyError(code, 'จำนวนต้องเป็นเลขจำนวนเต็มในรูปสตริง');
    }

    const n = BigInt(value);

    if (positive && n <= 0n) {
        throw new PolicyError(code, 'จำนวนต้องมากกว่าศูนย์');
    }

    return n;
}

function addressList(raw) {
    return String(raw || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean)
        .map((a) => requireAddress(a, 'config_address'));
}

function intEnv(env, name, fallback, { min, max }) {
    const raw = env[name];
    const value = raw === undefined || raw === '' ? fallback : Number(raw);

    if (!Number.isInteger(value) || value < min || value > max) {
        throw new PolicyError('config', `${name} ต้องเป็นจำนวนเต็มระหว่าง ${min} ถึง ${max}`);
    }

    return value;
}

/**
 * อ่านค่าตั้งจาก env ของเครื่องตัวเซ็น — ขาดตัวไหน = ไม่เริ่มทำงาน (fail-closed)
 * รายการเหรียญและ router อยู่ที่นี่ที่เดียว คำขอจากเครื่องเว็บเพิ่มรายการเองไม่ได้
 */
function loadConfig(env) {
    const need = (name) => {
        const value = env[name];

        if (typeof value !== 'string' || value.trim() === '') {
            throw new PolicyError('config', `ไม่ได้ตั้ง ${name}`);
        }

        return value.trim();
    };

    const quoteToken = requireAddress(need('SIGNER_QUOTE_TOKEN'), 'config_address');
    const tradeTokens = addressList(need('SIGNER_TRADE_TOKENS'));

    if (tradeTokens.length === 0 || tradeTokens.includes(quoteToken)) {
        throw new PolicyError('config', 'SIGNER_TRADE_TOKENS ต้องมีอย่างน้อยหนึ่งเหรียญ และต้องไม่มีเหรียญอ้างอิง (USDT) อยู่ในนั้น');
    }

    const config = {
        chainId: intEnv(env, 'SIGNER_CHAIN_ID', 56, { min: 1, max: 2 ** 31 }),
        rpcUrl: need('SIGNER_RPC_URL'),
        userAgent: (env.SIGNER_USER_AGENT || 'TPIX-BotSigner/1.0').trim(),
        contracts: {
            wbnb: requireAddress(need('SIGNER_WBNB'), 'config_address'),
            v2Router: requireAddress(need('SIGNER_V2_ROUTER'), 'config_address'),
            v3Router: requireAddress(need('SIGNER_V3_ROUTER'), 'config_address'),
            v3Quoter: requireAddress(need('SIGNER_V3_QUOTER'), 'config_address'),
            multicall3: requireAddress(need('SIGNER_MULTICALL3'), 'config_address'),
        },
        quoteToken,
        tradeTokens,
        // ถอนได้: เหรียญหลักของเชน (ไว้จ่ายแก๊ส) + เหรียญอ้างอิง + รายการเพิ่ม (ถ้าตั้ง)
        withdrawTokens: [quoteToken, ...addressList(env.SIGNER_WITHDRAW_TOKENS || '')],
        // ไม้เข้า (จ่าย USDT) ใหญ่สุดกี่หน่วยเล็กสุด — ค่าปริยาย 500 USDT (18 ทศนิยมบน BSC)
        maxEntryQuoteWei: requireUint(env.SIGNER_MAX_ENTRY_QUOTE_WEI || '500000000000000000000', 'config'),
        // minOut ต่ำกว่าราคาที่ตัวเซ็นถามเชนเองได้ไม่เกินนี้
        // เครื่องเว็บตั้ง slippage ได้ถึง 500 bps และราคาขยับระหว่างสองคำถามได้ จึงเผื่อไว้มากกว่านั้น
        maxMinOutGapBps: intEnv(env, 'SIGNER_MAX_MINOUT_GAP_BPS', 600, { min: 10, max: 2000 }),
        maxDeadlineSeconds: intEnv(env, 'SIGNER_MAX_DEADLINE_SECONDS', 900, { min: 60, max: 3600 }),
        dailySignsPerWallet: intEnv(env, 'SIGNER_DAILY_SIGNS_PER_WALLET', 120, { min: 1, max: 10000 }),
        dailyWalletCreations: intEnv(env, 'SIGNER_DAILY_WALLET_CREATIONS', 500, { min: 1, max: 100000 }),
    };

    return config;
}

function tradeable(config, token) {
    return config.tradeTokens.includes(token);
}

/** ฟิลด์ร่วมของทุกคำขอที่ใช้กุญแจ */
function checkKeyFields(body) {
    if (typeof body.sealed !== 'string' || body.sealed.length < 60 || body.sealed.length > 200) {
        throw new PolicyError('request', 'ไม่มีกุญแจที่ห่อไว้ในคำขอ');
    }

    return {
        sealed: body.sealed,
        owner: requireAddress(body.owner, 'request'),
        address: requireAddress(body.address, 'request'),
    };
}

/**
 * ถอน — ปลายทางไม่รับจากคำขอ ผู้เรียกใช้ owner (ที่ถอดกุญแจได้) เป็นปลายทางเสมอ
 */
function checkWithdraw(body, config) {
    const key = checkKeyFields(body);
    const token = body.token === null || body.token === undefined || body.token === '' ? null : requireAddress(body.token, 'request');

    if (token !== null && !config.withdrawTokens.includes(token)) {
        throw new PolicyError('policy_token', 'เหรียญนี้ไม่อยู่ในรายการที่ถอนได้');
    }

    if (body.to !== undefined && body.to !== null && lower(body.to) !== key.owner) {
        // ไม่ใช่ข้อผิดพลาดที่ควรเงียบ: ผู้เรียกตั้งใจส่งไปที่อื่น
        throw new PolicyError('policy_destination', 'ถอนได้เฉพาะกลับไปกระเป๋าเจ้าของ');
    }

    let nonce = null;

    if (body.nonce !== undefined && body.nonce !== null) {
        if (!Number.isInteger(body.nonce) || body.nonce < 0) {
            throw new PolicyError('request', 'nonce ไม่ถูกต้อง');
        }

        nonce = body.nonce;
    }

    return { ...key, token, to: key.owner, amountWei: requireUint(body.amountWei, 'request').toString(), nonce };
}

function checkApprove(body, config) {
    const key = checkKeyFields(body);
    const token = requireAddress(body.token, 'request');

    if (token !== config.quoteToken && !tradeable(config, token)) {
        throw new PolicyError('policy_token', 'เหรียญนี้ไม่อยู่ในรายการที่บอทเทรดได้');
    }

    if (body.router !== 'v2' && body.router !== 'v3') {
        throw new PolicyError('policy_router', 'ไม่รู้จักชนิด router');
    }

    return {
        ...key,
        token,
        spender: body.router === 'v3' ? config.contracts.v3Router : config.contracts.v2Router,
        amountWei: requireUint(body.amountWei, 'request').toString(),
    };
}

function checkSwap(body, config, nowSeconds) {
    const key = checkKeyFields(body);
    const tokenIn = requireAddress(body.tokenIn, 'request');
    const tokenOut = requireAddress(body.tokenOut, 'request');
    const amountIn = requireUint(body.amountInWei, 'request');
    const minOut = requireUint(body.minOutWei, 'request');

    // ไม้ของบอทคือ USDT ⇄ เหรียญในรายการ เท่านั้น — ฝั่งหนึ่งต้องเป็นเหรียญอ้างอิงเสมอ
    const entry = tokenIn === config.quoteToken && tradeable(config, tokenOut);
    const exit = tokenOut === config.quoteToken && tradeable(config, tokenIn);

    if (!entry && !exit) {
        throw new PolicyError('policy_token', 'คู่นี้ไม่อยู่ในรายการที่บอทเทรดได้');
    }

    if (entry && amountIn > config.maxEntryQuoteWei) {
        throw new PolicyError('policy_size', 'ไม้เข้าใหญ่เกินเพดานของตัวเซ็น');
    }

    if (!Number.isInteger(body.deadline) || body.deadline <= nowSeconds || body.deadline > nowSeconds + config.maxDeadlineSeconds) {
        throw new PolicyError('policy_deadline', 'อายุของธุรกรรมอยู่นอกช่วงที่ยอมให้');
    }

    const route = body.route;

    if (!route || (route.kind !== 'v2' && route.kind !== 'v3') || !Array.isArray(route.tokens)) {
        throw new PolicyError('policy_route', 'เส้นทางไม่ถูกต้อง');
    }

    const tokens = route.tokens.map((t) => requireAddress(t, 'policy_route'));

    if (tokens.length < 2 || tokens.length > 3 || tokens[0] !== tokenIn || tokens[tokens.length - 1] !== tokenOut) {
        throw new PolicyError('policy_route', 'เส้นทางไม่ตรงกับเหรียญของคำสั่ง');
    }

    if (tokens.length === 3 && tokens[1] !== config.contracts.wbnb) {
        throw new PolicyError('policy_route', 'เหรียญกลางทางต้องเป็น WBNB');
    }

    const fees = Array.isArray(route.fees) ? route.fees : [];

    if (route.kind === 'v3' && (fees.length !== tokens.length - 1 || fees.some((fee) => !V3_FEE_TIERS.includes(fee)))) {
        throw new PolicyError('policy_route', 'ค่าธรรมเนียมพูลของเส้นทาง V3 ไม่ถูกต้อง');
    }

    return {
        ...key,
        tokenIn,
        tokenOut,
        amountInWei: amountIn.toString(),
        minOutWei: minOut.toString(),
        deadline: body.deadline,
        route: { kind: route.kind, tokens, fees: route.kind === 'v3' ? fees : [] },
        side: entry ? 'entry' : 'exit',
    };
}

/**
 * minOut ที่เครื่องเว็บขอมา เทียบกับราคาที่ตัวเซ็นถามเชนเอง "ตอนนี้"
 *
 * ไม่มีราคาอิสระ = ไม่เซ็น (ตัวเซ็นถามเชนไม่ได้ ไม่ใช่เหตุให้เชื่อเครื่องเว็บ)
 */
function checkMinOut(minOutWei, independentBestOutWei, config) {
    if (typeof independentBestOutWei !== 'string' || !UINT_RE.test(independentBestOutWei) || BigInt(independentBestOutWei) <= 0n) {
        throw new PolicyError('policy_no_quote', 'ตัวเซ็นอ่านราคาจากเชนเองไม่ได้ จึงไม่เซ็น');
    }

    const floor = (BigInt(independentBestOutWei) * BigInt(10000 - config.maxMinOutGapBps)) / 10000n;

    if (BigInt(minOutWei) < floor) {
        throw new PolicyError('policy_min_out', 'ขั้นต่ำขาออกต่ำกว่าราคาบนเชนเกินที่ตัวเซ็นยอมให้');
    }
}

/**
 * ตัวนับรายวัน (UTC) — เก็บลงไฟล์ให้รอดการรีสตาร์ต เขียนแบบ tmp + rename
 * อ่านไฟล์ไม่ได้/ไฟล์พัง = เริ่มนับใหม่ของวันนั้น (เข้มน้อยลงชั่วคราว) แต่เขียนไม่ได้ = โยน error (ไม่เซ็น)
 */
class DailyCounter {
    constructor(file, clock = () => new Date()) {
        this.file = file;
        this.clock = clock;
        this.state = { date: '', counts: {} };

        try {
            const loaded = JSON.parse(fs.readFileSync(file, 'utf8'));

            if (loaded && typeof loaded.date === 'string' && loaded.counts && typeof loaded.counts === 'object') {
                this.state = loaded;
            }
        } catch (_) {
            // ยังไม่มีไฟล์ หรืออ่านไม่ออก
        }
    }

    today() {
        return this.clock().toISOString().slice(0, 10);
    }

    roll() {
        const today = this.today();

        if (this.state.date !== today) {
            this.state = { date: today, counts: {} };
        }
    }

    used(key) {
        this.roll();

        return this.state.counts[key] || 0;
    }

    /** @returns {boolean} false = ถึงเพดานแล้ว (ไม่นับเพิ่ม) */
    take(key, limit) {
        this.roll();

        const used = this.state.counts[key] || 0;

        if (used >= limit) {
            return false;
        }

        this.state.counts[key] = used + 1;
        this.persist();

        return true;
    }

    persist() {
        const tmp = this.file + '.tmp';

        fs.mkdirSync(path.dirname(this.file), { recursive: true });
        fs.writeFileSync(tmp, JSON.stringify(this.state), { mode: 0o600 });
        fs.renameSync(tmp, this.file);
    }
}

module.exports = {
    PolicyError,
    loadConfig,
    checkWithdraw,
    checkApprove,
    checkSwap,
    checkMinOut,
    DailyCounter,
    V3_FEE_TIERS,
};
