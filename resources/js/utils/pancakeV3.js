/**
 * TPIX TRADE - PancakeSwap V3 บน BSC (ขอราคา + เข้ารหัสเส้นทาง)
 *
 * ทำไมต้องมี (2026-11-03): สภาพคล่องของเหรียญยอดนิยมหลายตัวย้ายไป V3 แล้ว
 * บน V2 ไม้ $100 ได้ราคาห่าง Binance — PEPE 48% · POL 7.9% · SOL 5.6% · ATOM 4% · TRX 2.4%
 * ด่าน 10% / ด่าน price impact ในหน้าเทรดจึงบล็อกไม้ทั้งที่ตลาดจริงมีสภาพคล่อง
 *
 * หลักการ:
 *  - ขอราคาจาก QuoterV2 ทุกระดับค่าธรรมเนียมพูล (0.01/0.05/0.25/1%) ทั้งทางตรงและผ่าน WBNB
 *    รวมเป็นคำขอเดียวต่อรอบผ่าน Multicall3 (ไม่ยิง RPC ทีละพูล)
 *  - ผ่าน WBNB เลือกพูลขาแรกที่ได้ WBNB มากสุด แล้วค่อยเลือกพูลขาสอง
 *    ขาสองให้ผลเพิ่มตามยอดเข้าเสมอ → วิธีนี้ได้คู่พูลที่ดีที่สุดเท่ากับลองครบ 16 แบบ
 *  - พูลที่สภาพคล่องไม่พอรับทั้งไม้ (ราคาวิ่งไปชนขอบ) = ไม่นับ — ไม่ใช่ราคาจริงของยอดนี้
 *  - ส่งไม้ผ่าน SmartRouter (ตัวเดียวกับหน้าเว็บ PancakeSwap) — ตัวตรวจไม้ฝั่งเซิร์ฟเวอร์
 *    (BscSwapVerifier) รับธุรกรรมที่ส่งเข้า router นี้ด้วย (config chains.56.swap_routers)
 *
 * Developed by Xman Studio
 */

import { Interface, solidityPacked, getAddress } from 'ethers';

// PancakeSwap V3 บน BSC — ตรวจบนเชนแล้ว 2026-11-03: SmartRouter.WETH9() = WBNB,
// factoryV2() = PancakeSwap V2 factory, QuoterV2 ใช้ factory/deployer ชุดเดียวกับ SmartRouter
export const PANCAKE_V3_SMART_ROUTER_ADDRESS = '0x13f4EA83D0bd40E75C8222255bc855a974568Dd4';
export const PANCAKE_V3_QUOTER_ADDRESS = '0xB048Bbc1Ee6b733FFfCFb9e9CeF7375518e25997';
export const MULTICALL3_ADDRESS = '0xcA11bde05977b3631167028862bE2a173976CA11';

/** ระดับค่าธรรมเนียมพูล V3 (หน่วย 1/1,000,000) */
export const V3_FEE_TIERS = [100, 500, 2500, 10000];

/** ผู้รับพิเศษของ SmartRouter: ให้ router ถือเหรียญไว้ก่อน (ใช้ตอนถอน WBNB เป็น BNB) */
export const V3_ADDRESS_THIS = '0x0000000000000000000000000000000000000002';

// ขอบราคาที่ QuoterV2 ใช้เมื่อไม่กำหนดเพดาน — ราคาหลังไม้ไปหยุดที่นี่ = พูลรับยอดไม่หมด
const MIN_SQRT_RATIO_LIMIT = 4295128739n + 1n;
const MAX_SQRT_RATIO_LIMIT = 1461446703485210103287273052203988822378723970342n - 1n;

export const PANCAKE_V3_QUOTER_ABI = [
    'function quoteExactInputSingle((address tokenIn, address tokenOut, uint256 amountIn, uint24 fee, uint160 sqrtPriceLimitX96) params) returns (uint256 amountOut, uint160 sqrtPriceX96After, uint32 initializedTicksCrossed, uint256 gasEstimate)',
    'function quoteExactInput(bytes path, uint256 amountIn) returns (uint256 amountOut, uint160[] sqrtPriceX96AfterList, uint32[] initializedTicksCrossedList, uint256 gasEstimate)',
];

export const PANCAKE_V3_SMART_ROUTER_ABI = [
    'function exactInput((bytes path, address recipient, uint256 amountIn, uint256 amountOutMinimum) params) payable returns (uint256 amountOut)',
    'function unwrapWETH9(uint256 amountMinimum, address recipient) payable',
    'function refundETH() payable',
    'function multicall(uint256 deadline, bytes[] data) payable returns (bytes[] results)',
];

const MULTICALL3_ABI = [
    'function aggregate3((address target, bool allowFailure, bytes callData)[] calls) payable returns ((bool success, bytes returnData)[] returnData)',
];

const quoterIface = new Interface(PANCAKE_V3_QUOTER_ABI);
const multicallIface = new Interface(MULTICALL3_ABI);

const lower = s => String(s || '').toLowerCase();

/** ราคาหลังไม้ชนขอบ = พูลมีสภาพคล่องไม่พอรับยอดนี้ทั้งหมด */
function hitPriceLimit(sqrtPriceX96After) {
    const p = BigInt(sqrtPriceX96After);
    return p <= MIN_SQRT_RATIO_LIMIT || p >= MAX_SQRT_RATIO_LIMIT;
}

/**
 * เส้นทาง V3 แบบ packed: token(20) fee(3) token(20) [fee(3) token(20)]
 * @param {string[]} tokens ที่อยู่เหรียญตามลำดับ (2 หรือ 3 ตัว)
 * @param {number[]} fees ระดับค่าธรรมเนียมของแต่ละพูล (น้อยกว่า tokens หนึ่งตัว)
 */
export function encodeV3Path(tokens, fees) {
    if (!Array.isArray(tokens) || !Array.isArray(fees) || tokens.length < 2 || fees.length !== tokens.length - 1) {
        throw new Error('invalid V3 path');
    }
    for (const fee of fees) {
        if (!V3_FEE_TIERS.includes(Number(fee))) throw new Error('invalid V3 fee tier');
    }
    const types = [];
    const values = [];
    tokens.forEach((token, i) => {
        types.push('address');
        values.push(getAddress(lower(token)));
        if (i < fees.length) {
            types.push('uint24');
            values.push(Number(fees[i]));
        }
    });
    return solidityPacked(types, values);
}

/**
 * ราคาของพูลเดี่ยวหลายตัวในคำขอเดียว (Multicall3 · eth_call)
 * @param {{tokenIn: string, tokenOut: string, fee: number, amountIn: bigint}[]} legs
 * @returns {Promise<(bigint|null)[]>} ตามลำดับ legs · null = ไม่มีพูล / พูลรับยอดไม่หมด / ได้ 0
 * @throws เมื่อโหนดไม่ตอบ (ผู้เรียกแยก "ติดต่อไม่ได้" ออกจาก "ไม่มีสภาพคล่อง")
 */
export async function quoteV3Singles(provider, legs) {
    if (!legs.length) return [];
    const calls = legs.map(leg => ({
        target: PANCAKE_V3_QUOTER_ADDRESS,
        allowFailure: true,
        callData: quoterIface.encodeFunctionData('quoteExactInputSingle', [{
            tokenIn: leg.tokenIn,
            tokenOut: leg.tokenOut,
            amountIn: leg.amountIn,
            fee: leg.fee,
            sqrtPriceLimitX96: 0n,
        }]),
    }));
    const raw = await provider.call({ to: MULTICALL3_ADDRESS, data: multicallIface.encodeFunctionData('aggregate3', [calls]) });
    const [results] = multicallIface.decodeFunctionResult('aggregate3', raw);

    return legs.map((_, i) => {
        const r = results[i];
        if (!r || !r.success) return null;
        try {
            const [amountOut, sqrtPriceX96After] = quoterIface.decodeFunctionResult('quoteExactInputSingle', r.returnData);
            if (!(amountOut > 0n) || hitPriceLimit(sqrtPriceX96After)) return null;
            return amountOut;
        } catch {
            return null;
        }
    });
}

/** ตัวที่ได้มากสุดในรายการ (null ข้าม) → { index, amountOut } หรือ null */
function pickBest(amounts) {
    let best = null;
    amounts.forEach((amountOut, index) => {
        if (amountOut !== null && (best === null || amountOut > best.amountOut)) best = { index, amountOut };
    });
    return best;
}

/**
 * เส้นทาง V3 ที่ได้มากสุด: ตรง (ทุกระดับค่าธรรมเนียม) หรือผ่าน WBNB
 * @param {bigint} amountIn ยอดที่สวอปจริง (หักค่าธรรมเนียมแพลตฟอร์มแล้ว)
 * @param {string} from ที่อยู่สำหรับ routing (BNB = WBNB แล้ว)
 * @param {string} to   ที่อยู่สำหรับ routing
 * @returns {Promise<{tokens: string[], fees: number[], amountOut: bigint}|null>}
 */
export async function bestV3Route(provider, amountIn, from, to, wbnb) {
    const touchesWbnb = lower(from) === lower(wbnb) || lower(to) === lower(wbnb);

    const direct = V3_FEE_TIERS.map(fee => ({ tokenIn: from, tokenOut: to, fee, amountIn }));
    const firstLeg = touchesWbnb ? [] : V3_FEE_TIERS.map(fee => ({ tokenIn: from, tokenOut: wbnb, fee, amountIn }));
    const round1 = await quoteV3Singles(provider, [...direct, ...firstLeg]);

    const candidates = [];
    const bestDirect = pickBest(round1.slice(0, direct.length));
    if (bestDirect) {
        candidates.push({ tokens: [from, to], fees: [direct[bestDirect.index].fee], amountOut: bestDirect.amountOut });
    }

    const bestFirst = pickBest(round1.slice(direct.length));
    if (bestFirst) {
        const midAmount = bestFirst.amountOut;
        const secondLeg = V3_FEE_TIERS.map(fee => ({ tokenIn: wbnb, tokenOut: to, fee, amountIn: midAmount }));
        const bestSecond = pickBest(await quoteV3Singles(provider, secondLeg));
        if (bestSecond) {
            candidates.push({
                tokens: [from, wbnb, to],
                fees: [firstLeg[bestFirst.index].fee, secondLeg[bestSecond.index].fee],
                amountOut: bestSecond.amountOut,
            });
        }
    }

    return candidates.reduce((best, c) => (best === null || c.amountOut > best.amountOut ? c : best), null);
}

/**
 * ราคาของเส้นทาง V3 ทั้งเส้น (QuoterV2.quoteExactInput) — ใช้กับไม้อ้างอิงเพื่อวัด price impact
 * @returns {Promise<bigint|null>} null = พูลใดพูลหนึ่งรับยอดไม่หมด / ได้ 0
 */
export async function quoteV3Path(provider, tokens, fees, amountIn) {
    const raw = await provider.call({
        to: PANCAKE_V3_QUOTER_ADDRESS,
        data: quoterIface.encodeFunctionData('quoteExactInput', [encodeV3Path(tokens, fees), amountIn]),
    });
    const [amountOut, sqrtPriceX96AfterList] = quoterIface.decodeFunctionResult('quoteExactInput', raw);
    if (!(amountOut > 0n) || [...sqrtPriceX96AfterList].some(hitPriceLimit)) return null;
    return amountOut;
}
