#!/usr/bin/env node
/**
 * bot-wallet-swap.cjs — ไม้เงินจริงของบอท AI: หาเส้นทาง / เซ็น approve / เซ็นสวอป บน PancakeSwap (BSC)
 *
 * เรียกจาก App\Services\AiBot\Wallet\BotWalletSigner (ฝั่ง CLI เท่านั้น)
 * ความลับส่งผ่าน environment variable ไม่ใช่ argv (argv โผล่ใน `ps aux`)
 * การ "ส่ง" ธุรกรรมที่เซ็นแล้วใช้ bot-wallet-transfer.cjs (action send) ตัวเดิม
 *
 * กฎที่สคริปต์นี้บังคับเอง ไม่เชื่อผู้เรียก:
 *   - ผู้รับเหรียญของสวอป = กระเป๋าที่เซ็น เสมอ (ไม่มี env ให้ระบุผู้รับ)
 *   - router / spender ต้องเป็นตัวที่ส่งมาใน BW_V2_ROUTER / BW_V3_ROUTER เท่านั้น
 *   - เหรียญกลางทางของเส้นทางเป็นได้แค่ WBNB
 *   - ประเมินแก๊สไม่ผ่าน = ไม่เซ็น (ไม่มีค่าแก๊สสำรองสำหรับการเรียกสัญญา)
 *
 * env ร่วม:
 *   BW_ACTION        plan | sign-approve | sign-swap
 *   BW_RPC_URL / BW_CHAIN_ID / BW_USER_AGENT
 *   BW_WBNB / BW_V2_ROUTER / BW_V3_ROUTER / BW_V3_QUOTER / BW_MULTICALL3
 *
 * plan (ไม่ใช้กุญแจ):
 *   BW_OWNER         ที่อยู่กระเป๋าบอท
 *   BW_TOKEN_IN / BW_TOKEN_OUT / BW_AMOUNT_IN (หน่วยเล็กสุด)
 *   BW_ROUTER_PIN    '' | v2 | v3 — บังคับชนิด router (ใช้หลัง approve ไปแล้ว)
 *   BW_QUOTE_TOKEN   stablecoin ที่ใช้ตีราคา BNB (ค่าแก๊สเป็นดอลลาร์)
 *   → { ok, balanceIn, nativeBalance, decimalsIn, decimalsOut, allowance:{v2,v3},
 *       best:{kind, router, tokens, fees, amountOut} | null, bnbQuote, gasPrice }
 *
 * sign-approve:
 *   BW_PRIVATE_KEY / BW_EXPECT_ADDRESS / BW_TOKEN / BW_SPENDER / BW_AMOUNT
 * sign-swap:
 *   BW_PRIVATE_KEY / BW_EXPECT_ADDRESS / BW_TOKEN_IN / BW_TOKEN_OUT / BW_AMOUNT_IN
 *   BW_MIN_OUT / BW_DEADLINE (unix วินาที) / BW_ROUTE (JSON {kind, tokens, fees})
 *   → { ok, address, nonce, txHash, raw, gasLimit, gasPrice }
 *
 * **ไม่พิมพ์ private key ออกทาง stdout/stderr ไม่ว่ากรณีใด**
 */

const { Wallet, JsonRpcProvider, FetchRequest, Transaction, Interface, getAddress, solidityPacked } = require('ethers');

const V3_FEE_TIERS = [100, 500, 2500, 10000];
const MIN_SQRT_RATIO_LIMIT = 4295128739n + 1n;
const MAX_SQRT_RATIO_LIMIT = 1461446703485210103287273052203988822378723970342n - 1n;

const ERC20 = new Interface([
    'function balanceOf(address owner) view returns (uint256)',
    'function decimals() view returns (uint8)',
    'function allowance(address owner, address spender) view returns (uint256)',
    'function approve(address spender, uint256 value) returns (bool)',
]);
const V2_ROUTER = new Interface([
    'function getAmountsOut(uint256 amountIn, address[] path) view returns (uint256[] amounts)',
    'function swapExactTokensForTokens(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
]);
const V3_QUOTER = new Interface([
    'function quoteExactInputSingle((address tokenIn, address tokenOut, uint256 amountIn, uint24 fee, uint160 sqrtPriceLimitX96) params) returns (uint256 amountOut, uint160 sqrtPriceX96After, uint32 initializedTicksCrossed, uint256 gasEstimate)',
]);
const V3_ROUTER = new Interface([
    'function exactInput((bytes path, address recipient, uint256 amountIn, uint256 amountOutMinimum) params) payable returns (uint256 amountOut)',
    'function multicall(uint256 deadline, bytes[] data) payable returns (bytes[] results)',
]);
const MULTICALL3 = new Interface([
    'function aggregate3((address target, bool allowFailure, bytes callData)[] calls) payable returns ((bool success, bytes returnData)[] returnData)',
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

function address(name) {
    const raw = (process.env[name] || '').trim();
    if (!raw) fail(`ไม่ได้ตั้ง ${name}`, 'config');
    try {
        return getAddress(raw.toLowerCase());
    } catch (_) {
        return fail(`${name} ไม่ใช่ที่อยู่ที่ถูกต้อง`, 'config');
    }
}

function amount(name) {
    const raw = (process.env[name] || '').trim();
    if (!/^[0-9]+$/.test(raw)) fail(`${name} ต้องเป็นจำนวนเต็มหน่วยเล็กสุด`, 'config');

    return BigInt(raw);
}

const same = (a, b) => String(a).toLowerCase() === String(b).toLowerCase();

function hitPriceLimit(sqrtPriceX96After) {
    const p = BigInt(sqrtPriceX96After);

    return p <= MIN_SQRT_RATIO_LIMIT || p >= MAX_SQRT_RATIO_LIMIT;
}

function encodeV3Path(tokens, fees) {
    const types = [];
    const values = [];
    tokens.forEach((token, i) => {
        types.push('address');
        values.push(getAddress(String(token).toLowerCase()));
        if (i < fees.length) {
            types.push('uint24');
            values.push(Number(fees[i]));
        }
    });

    return solidityPacked(types, values);
}

async function aggregate(provider, multicall, calls) {
    const raw = await provider.call({
        to: multicall,
        data: MULTICALL3.encodeFunctionData('aggregate3', [calls.map((c) => ({ target: c.target, allowFailure: true, callData: c.callData }))]),
    });
    const [results] = MULTICALL3.decodeFunctionResult('aggregate3', raw);

    return calls.map((c, i) => {
        const r = results[i];
        if (!r || !r.success) return null;
        try {
            return c.decode(r.returnData);
        } catch (_) {
            return null;
        }
    });
}

const v2Quote = (router, amountIn, path) => ({
    target: router,
    callData: V2_ROUTER.encodeFunctionData('getAmountsOut', [amountIn, path]),
    decode: (data) => {
        const [amounts] = V2_ROUTER.decodeFunctionResult('getAmountsOut', data);
        const last = amounts[amounts.length - 1];

        return last > 0n ? last : null;
    },
});

const v3Quote = (quoter, tokenIn, tokenOut, fee, amountIn) => ({
    target: quoter,
    callData: V3_QUOTER.encodeFunctionData('quoteExactInputSingle', [{ tokenIn, tokenOut, amountIn, fee, sqrtPriceLimitX96: 0n }]),
    decode: (data) => {
        const [amountOut, sqrtPriceX96After] = V3_QUOTER.decodeFunctionResult('quoteExactInputSingle', data);

        return amountOut > 0n && !hitPriceLimit(sqrtPriceX96After) ? amountOut : null;
    },
});

function bestIndex(amounts) {
    let best = -1;
    amounts.forEach((a, i) => {
        if (a !== null && a !== undefined && (best < 0 || a > amounts[best])) best = i;
    });

    return best;
}

function contracts() {
    return {
        wbnb: address('BW_WBNB'),
        v2Router: address('BW_V2_ROUTER'),
        v3Router: address('BW_V3_ROUTER'),
        v3Quoter: address('BW_V3_QUOTER'),
        multicall: address('BW_MULTICALL3'),
    };
}

async function plan(provider) {
    const c = contracts();
    const owner = address('BW_OWNER');
    const tokenIn = address('BW_TOKEN_IN');
    const tokenOut = address('BW_TOKEN_OUT');
    const amountIn = amount('BW_AMOUNT_IN');
    const pin = (process.env.BW_ROUTER_PIN || '').trim();
    const quoteToken = (process.env.BW_QUOTE_TOKEN || '').trim();

    if (amountIn <= 0n) fail('จำนวนต้องมากกว่าศูนย์', 'config');
    if (same(tokenIn, tokenOut)) fail('เหรียญขาเข้าและขาออกเป็นตัวเดียวกัน', 'config');

    const touchesWbnb = same(tokenIn, c.wbnb) || same(tokenOut, c.wbnb);
    const one = (iface, fn) => (data) => iface.decodeFunctionResult(fn, data)[0];

    const calls = [
        { target: tokenIn, callData: ERC20.encodeFunctionData('balanceOf', [owner]), decode: one(ERC20, 'balanceOf') },
        { target: tokenIn, callData: ERC20.encodeFunctionData('decimals', []), decode: one(ERC20, 'decimals') },
        { target: tokenOut, callData: ERC20.encodeFunctionData('decimals', []), decode: one(ERC20, 'decimals') },
        { target: tokenIn, callData: ERC20.encodeFunctionData('allowance', [owner, c.v2Router]), decode: one(ERC20, 'allowance') },
        { target: tokenIn, callData: ERC20.encodeFunctionData('allowance', [owner, c.v3Router]), decode: one(ERC20, 'allowance') },
    ];
    const base = calls.length;

    // ราคา BNB เป็นดอลลาร์ (ตีค่าแก๊ส) — 1 WBNB → stablecoin ผ่าน V2 · อ่านไม่ได้ = null ไม่ใช่ความล้มเหลว
    const bnbQuoteIndex = quoteToken && !same(quoteToken, c.wbnb)
        ? calls.push(v2Quote(c.v2Router, 10n ** 18n, [c.wbnb, getAddress(quoteToken.toLowerCase())])) - 1
        : -1;

    const v2Routes = [{ tokens: [tokenIn, tokenOut] }];
    if (!touchesWbnb) v2Routes.push({ tokens: [tokenIn, c.wbnb, tokenOut] });
    const v2Start = calls.length;
    if (pin !== 'v3') v2Routes.forEach((r) => calls.push(v2Quote(c.v2Router, amountIn, r.tokens)));

    const v3DirectStart = calls.length;
    const v3FirstStart = v3DirectStart + V3_FEE_TIERS.length;
    if (pin !== 'v2') {
        V3_FEE_TIERS.forEach((fee) => calls.push(v3Quote(c.v3Quoter, tokenIn, tokenOut, fee, amountIn)));
        if (!touchesWbnb) V3_FEE_TIERS.forEach((fee) => calls.push(v3Quote(c.v3Quoter, tokenIn, c.wbnb, fee, amountIn)));
    }

    const [results, nativeBalance, feeData] = await Promise.all([
        aggregate(provider, c.multicall, calls),
        provider.getBalance(owner),
        provider.getFeeData().catch(() => null),
    ]);

    const [balanceIn, decimalsIn, decimalsOut, allowanceV2, allowanceV3] = results.slice(0, base);
    if (balanceIn === null || decimalsIn === null || decimalsOut === null) {
        fail('อ่านยอด/ทศนิยมของเหรียญไม่ได้', 'rpc');
    }

    const candidates = [];

    if (pin !== 'v3') {
        v2Routes.forEach((r, i) => {
            const amountOut = results[v2Start + i];
            if (amountOut) candidates.push({ kind: 'v2', router: c.v2Router, tokens: r.tokens, fees: [], amountOut });
        });
    }

    if (pin !== 'v2') {
        const direct = results.slice(v3DirectStart, v3DirectStart + V3_FEE_TIERS.length);
        const d = bestIndex(direct);
        if (d >= 0) candidates.push({ kind: 'v3', router: c.v3Router, tokens: [tokenIn, tokenOut], fees: [V3_FEE_TIERS[d]], amountOut: direct[d] });

        if (!touchesWbnb) {
            const first = results.slice(v3FirstStart, v3FirstStart + V3_FEE_TIERS.length);
            const f = bestIndex(first);
            if (f >= 0) {
                const second = await aggregate(provider, c.multicall, V3_FEE_TIERS.map((fee) => v3Quote(c.v3Quoter, c.wbnb, tokenOut, fee, first[f])));
                const s = bestIndex(second);
                if (s >= 0) {
                    candidates.push({ kind: 'v3', router: c.v3Router, tokens: [tokenIn, c.wbnb, tokenOut], fees: [V3_FEE_TIERS[f], V3_FEE_TIERS[s]], amountOut: second[s] });
                }
            }
        }
    }

    const best = candidates.reduce((b, x) => (b === null || x.amountOut > b.amountOut ? x : b), null);

    out({
        ok: true,
        balanceIn: balanceIn.toString(),
        nativeBalance: nativeBalance.toString(),
        decimalsIn: Number(decimalsIn),
        decimalsOut: Number(decimalsOut),
        allowance: { v2: (allowanceV2 ?? 0n).toString(), v3: (allowanceV3 ?? 0n).toString() },
        best: best ? { ...best, amountOut: best.amountOut.toString() } : null,
        bnbQuote: bnbQuoteIndex >= 0 && results[bnbQuoteIndex] ? results[bnbQuoteIndex].toString() : null,
        gasPrice: feeData?.gasPrice ? feeData.gasPrice.toString() : null,
    });
}

function openWallet(provider) {
    const privateKey = process.env.BW_PRIVATE_KEY;
    if (!privateKey || !/^[0-9a-fA-F]{64}$/.test(privateKey)) fail('กุญแจไม่ถูกต้อง', 'config');

    let wallet;
    try {
        wallet = new Wallet('0x' + privateKey, provider);
    } catch (_) {
        fail('สร้างกระเป๋าจากกุญแจไม่สำเร็จ', 'key_invalid');
    }

    const expect = process.env.BW_EXPECT_ADDRESS;
    if (!expect || getAddress(wallet.address) !== getAddress(expect.toLowerCase())) {
        fail('กุญแจถอดออกมาไม่ตรงกับกระเป๋าที่ระบบคาดไว้', 'address_mismatch');
    }

    return wallet;
}

async function signCall(provider, wallet, request) {
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
        gasLimit = (estimated * 130n) / 100n;
    } catch (e) {
        // การเรียกสัญญาที่ประเมินไม่ผ่าน = จะ revert บนเชน (ยอด/สิทธิ์ไม่พอ · ราคาเลื่อนเกิน minOut) — ไม่เซ็น ไม่เสียแก๊ส
        return fail('ประเมินแก๊สไม่ผ่าน: ' + shorten(e), 'estimate_failed');
    }

    const chainId = Number(process.env.BW_CHAIN_ID);
    const nonce = await provider.getTransactionCount(wallet.address, 'pending');
    const raw = await wallet.signTransaction({ type: 0, chainId, nonce, gasLimit, gasPrice, value: 0n, ...request });

    out({
        ok: true,
        address: wallet.address,
        nonce,
        txHash: Transaction.from(raw).hash,
        raw,
        gasLimit: gasLimit.toString(),
        gasPrice: gasPrice.toString(),
    });
}

async function signApprove(provider) {
    const c = contracts();
    const token = address('BW_TOKEN');
    const spender = address('BW_SPENDER');
    const value = amount('BW_AMOUNT');

    if (!same(spender, c.v2Router) && !same(spender, c.v3Router)) {
        fail('ผู้รับสิทธิ์ไม่ใช่ router ของ PancakeSwap ที่ระบบรู้จัก', 'spender_not_allowed');
    }

    const wallet = openWallet(provider);

    await signCall(provider, wallet, { to: token, data: ERC20.encodeFunctionData('approve', [spender, value]) });
}

async function signSwap(provider) {
    const c = contracts();
    const tokenIn = address('BW_TOKEN_IN');
    const tokenOut = address('BW_TOKEN_OUT');
    const amountIn = amount('BW_AMOUNT_IN');
    const minOut = amount('BW_MIN_OUT');
    const deadline = amount('BW_DEADLINE');

    let route;
    try {
        route = JSON.parse(process.env.BW_ROUTE || '');
    } catch (_) {
        fail('BW_ROUTE อ่านไม่ออก', 'config');
    }

    const tokens = Array.isArray(route?.tokens) ? route.tokens.map((t) => getAddress(String(t).toLowerCase())) : [];
    const fees = Array.isArray(route?.fees) ? route.fees.map(Number) : [];

    if (amountIn <= 0n || minOut <= 0n) fail('จำนวนขาเข้าและขั้นต่ำขาออกต้องมากกว่าศูนย์', 'config');
    if (tokens.length < 2 || tokens.length > 3) fail('เส้นทางต้องมี 2–3 เหรียญ', 'route_invalid');
    if (!same(tokens[0], tokenIn) || !same(tokens[tokens.length - 1], tokenOut)) fail('เส้นทางไม่ตรงกับเหรียญของคำสั่ง', 'route_invalid');
    if (tokens.length === 3 && !same(tokens[1], c.wbnb)) fail('เหรียญกลางทางต้องเป็น WBNB', 'route_invalid');

    const wallet = openWallet(provider);

    if (route.kind === 'v2') {
        return signCall(provider, wallet, {
            to: c.v2Router,
            data: V2_ROUTER.encodeFunctionData('swapExactTokensForTokens', [amountIn, minOut, tokens, wallet.address, deadline]),
        });
    }

    if (route.kind === 'v3') {
        if (fees.length !== tokens.length - 1 || fees.some((fee) => !V3_FEE_TIERS.includes(fee))) {
            fail('ค่าธรรมเนียมพูลของเส้นทาง V3 ไม่ถูกต้อง', 'route_invalid');
        }

        const swap = V3_ROUTER.encodeFunctionData('exactInput', [{
            path: encodeV3Path(tokens, fees),
            recipient: wallet.address,
            amountIn,
            amountOutMinimum: minOut,
        }]);

        return signCall(provider, wallet, { to: c.v3Router, data: V3_ROUTER.encodeFunctionData('multicall', [deadline, [swap]]) });
    }

    return fail('ไม่รู้จักชนิดเส้นทาง', 'route_invalid');
}

async function main() {
    const action = process.env.BW_ACTION || '';
    const rpcUrl = process.env.BW_RPC_URL;
    const chainId = Number(process.env.BW_CHAIN_ID || 0);

    if (!rpcUrl) fail('ไม่ได้ตั้ง BW_RPC_URL', 'config');
    if (!chainId) fail('ไม่ได้ตั้ง BW_CHAIN_ID', 'config');

    const fetchReq = new FetchRequest(rpcUrl);
    fetchReq.setHeader('User-Agent', process.env.BW_USER_AGENT || 'TPIX-BotWallet/1.0');
    fetchReq.setHeader('Content-Type', 'application/json');

    const provider = new JsonRpcProvider(fetchReq, chainId, { staticNetwork: true });

    if (action === 'plan') return plan(provider);
    if (action === 'sign-approve') return signApprove(provider);
    if (action === 'sign-swap') return signSwap(provider);

    return fail('ไม่รู้จัก BW_ACTION', 'config');
}

main().catch((e) => fail(e?.message || e, 'unexpected'));
