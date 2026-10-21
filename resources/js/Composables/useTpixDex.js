/**
 * TPIX TRADE - useTpixDex Composable
 * Swap + สภาพคล่อง on-chain จริงบน TPIX Chain (4289) ผ่าน TPIXDEXRouter02 (AMM แบบ UniV2)
 *
 * Invariants (แบบเดียวกับ useSwap.js ฝั่ง BSC):
 *  - quote อ่านจาก RPC ของ TPIX chain โดยตรง ไม่ใช่ provider ของ wallet
 *    → ได้ราคาแม้ wallet อยู่คนละเชน และไม่ fabricate rate 1:1 เด็ดขาด
 *  - fail-closed: ถ้า DEX ยังไม่ deploy (isDexConfigured() = false) ทุก action
 *    คืน error ชัดเจน ไม่เดา address
 *  - fee ของกระดาน = 0.3% ใน pool (LP + protocol feeTo) — ไม่มี fee transaction
 *    แยกให้ user ปฏิเสธได้เหมือนฝั่ง BSC swap
 *  - ที่อยู่สัญญาโหลดจากทะเบียนบนเซิร์ฟเวอร์ (loadDexConfig) — ห้าม hardcode
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import axios from 'axios';
import { Contract, JsonRpcProvider, parseUnits, formatUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import { t } from '@/Composables/useTranslation';
import {
    TPIX_DEX,
    TPIX_DEX_ROUTER_ABI,
    TPIX_DEX_FACTORY_ABI,
    TPIX_DEX_PAIR_ABI,
    TPIX_DEX_ERC20_ABI,
    isDexConfigured,
    loadDexConfig,
} from '@/Config/dexContracts';

const NATIVE = 'native'; // sentinel แทน native TPIX coin ใน path ที่ user เลือก
const ZERO = '0x0000000000000000000000000000000000000000';

/** ค่าธรรมเนียมของพูล (UniV2) — เข้า LP ทั้งก้อน โปรโตคอลได้ส่วนแบ่งผ่าน feeTo ไม่ได้หักเพิ่มจากผู้ใช้ */
export const POOL_FEE_PCT = 0.3;

/** ไม้ที่ดันราคาพูลเกินเท่านี้ (%) ห้ามส่ง — ใช้ร่วมกันทั้ง /swap และ /trade (utils/swapNetwork re-export) */
export const PRICE_IMPACT_BLOCK_PCT = 10;

/**
 * ไม้นี้ต้องถูกบล็อกเพราะผลกระทบราคาไหม — fail-closed
 * ไม่รู้ค่า (null/NaN) = บล็อก เพราะไม่รู้ว่าพูลรับไม้ขนาดนี้ได้หรือเปล่า
 * (เดิม Trade.vue เทียบ `> 10` ตรง ๆ — null > 10 เป็นเท็จ ไม้จึงผ่านตอนอ่านพูลไม่ได้)
 */
export function isPriceImpactBlocked(impact) {
    if (impact === null || impact === undefined || impact === '') return true;
    const n = Number(impact);
    if (!Number.isFinite(n)) return true;
    return n > PRICE_IMPACT_BLOCK_PCT;
}

/** kLast ของพูล — ใช้คิดส่วนแบ่งโปรโตคอลที่ถูก mint ก่อนถอน (ไม่มีใน ABI กลาง จึงประกาศเฉพาะที่นี่) */
const PAIR_KLAST_ABI = ['function kLast() view returns (uint256)'];

/*
 * ค่าแก๊สของ TPIX Chain — ตรวจกับ rpc1 แล้ว (2026-10-21): eth_gasPrice = 0x0 และบล็อกไม่มี baseFeePerGas
 * (เชนยังไม่เปิด EIP-1559) · ถ้าไม่บอกกระเป๋าตรง ๆ MetaMask/กระเป๋ามือถือบางตัวเติม priority fee เอง
 * → คนที่ถือ TPIX 0 (เช่นขาย USDT) ส่งธุรกรรมไม่ได้เพราะ "ยอดไม่พอจ่ายค่าแก๊ส"
 * อ่านจากเชนทุก 60 วิ — ถ้าวันหนึ่งเชนเปิด baseFee ตัวนี้จะไม่บังคับอะไร ปล่อยกระเป๋าตัดสินเอง
 */
const GAS_TTL_MS = 60_000;
let _gasOverrides = null;
let _gasCheckedAt = 0;

async function chainGasOverrides(provider) {
    if (_gasOverrides && Date.now() - _gasCheckedAt < GAS_TTL_MS) return _gasOverrides;
    try {
        const [gasPriceHex, block] = await Promise.all([
            provider.send('eth_gasPrice', []),
            provider.send('eth_getBlockByNumber', ['latest', false]),
        ]);
        const gasPrice = BigInt(gasPriceHex);
        const baseFee = block?.baseFeePerGas == null ? null : BigInt(block.baseFeePerGas);
        _gasOverrides = baseFee === null || baseFee === 0n ? { type: 0, gasPrice } : {};
        _gasCheckedAt = Date.now();
        return _gasOverrides;
    } catch {
        // อ่านไม่ได้ = ไม่บังคับ (ปล่อยกระเป๋าเลือกเอง) — ห้ามบล็อกการส่งธุรกรรมเพราะเรื่องนี้
        return {};
    }
}

/** ใช้ในเทสต์ — ล้างค่าแก๊สที่จำไว้ */
export function _resetGasCacheForTests() {
    _gasOverrides = null;
    _gasCheckedAt = 0;
}

/** overrides ท้ายอาร์กิวเมนต์ของ contract call — ว่าง = ไม่ส่งอะไรเพิ่ม */
function withOverrides(gas, extra = {}) {
    const merged = { ...gas, ...extra };
    return Object.keys(merged).length > 0 ? [merged] : [];
}

/** รากที่สองแบบปัดลง (BigInt) — แบบเดียวกับ Math.sqrt ในสัญญาพูล */
function sqrtBig(n) {
    if (n < 2n) return n;
    let x = n;
    let y = (x + 1n) / 2n;
    while (y < x) {
        x = y;
        y = (x + n / x) / 2n;
    }
    return x;
}

/**
 * LP ที่พูลจะ mint ให้ feeTo ตอนเริ่มถอน (_mintFee ของ UniV2: totalSupply·(√k−√kLast)/(5√k+√kLast))
 * ต้องบวกเข้า totalSupply ก่อนคิดยอดที่ถอนได้ — ไม่งั้นยอดขั้นต่ำสูงเกินจริงแล้ว revert หลังพูลมีปริมาณเยอะ
 */
export function protocolFeeMint(reserve0, reserve1, totalSupply, kLast) {
    if (!(kLast > 0n) || !(totalSupply > 0n)) return 0n;
    const rootK = sqrtBig(reserve0 * reserve1);
    const rootKLast = sqrtBig(kLast);
    if (rootK <= rootKLast) return 0n;
    return (totalSupply * (rootK - rootKLast)) / (rootK * 5n + rootKLast);
}

/**
 * แยกชนิดของความล้มเหลวหลังส่งธุรกรรมไปแล้ว (tx.wait() โยน)
 *  - reverted   : ลงบล็อกแล้วแต่ revert (ethers v6 โยน CALL_EXCEPTION พร้อม receipt.status 0)
 *  - repriced   : กระเป๋าเร่งแก๊ส/ส่งซ้ำด้วยข้อมูลเดิม และลงบล็อกสำเร็จ — ถือว่าไม้นี้สำเร็จ (hash ใหม่)
 *  - replaced   : ถูกแทนที่/ยกเลิก — ไม่มีอะไรเกิดขึ้น
 *  - unconfirmed: ส่งออกไปแล้วแต่ยืนยันผลไม่ได้ (RPC ล้ม/หมดเวลา) — อาจลงเชนแล้ว ห้ามบอกว่า "ล้มเหลว ลองใหม่"
 */
function classifyWaitError(err) {
    if (err?.code === 'TRANSACTION_REPLACED') {
        if (err.reason === 'repriced' && Number(err.receipt?.status) === 1) {
            return { kind: 'repriced', hash: err.receipt?.hash || err.replacement?.hash || null };
        }
        if (err.reason === 'repriced' && Number(err.receipt?.status) === 0) return { kind: 'reverted' };
        return { kind: 'replaced' };
    }
    if (err?.receipt && Number(err.receipt.status) === 0) return { kind: 'reverted' };
    return { kind: 'unconfirmed' };
}

let _readProvider = null;
let _readProviderRpc = null;
function getTpixReadProvider() {
    if (!_readProvider || _readProviderRpc !== TPIX_DEX.RPC) {
        _readProviderRpc = TPIX_DEX.RPC;
        _readProvider = new JsonRpcProvider(TPIX_DEX.RPC, TPIX_DEX.CHAIN_ID, { staticNetwork: true });
    }
    return _readProvider;
}

/** ที่อยู่ในฐานข้อมูล (0x0 = native) หรือ sentinel → ค่าที่ composable ใช้ */
export function toDexAddress(address) {
    const a = String(address || '').toLowerCase();
    if (a === '' || a === NATIVE || a === ZERO || a === '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee') return NATIVE;
    return a;
}

/** แปลง float เป็น wei — cap precision กัน float garbage (แบบเดียวกับ useSwap) */
function toWei(value, decimals) {
    const prec = Math.min(decimals, 12);
    return parseUnits(Number(value).toFixed(prec), decimals);
}

/**
 * map error ดิบเป็นข้อความ user-facing — ห้ามโชว์ raw exception (project rule)
 * ข้อความมาจากไฟล์แปลภาษา (tpixDexError.*) — เดิมฝังอังกฤษไว้ ผู้ใช้ไทยเห็นอังกฤษตอนกดยกเลิกในกระเป๋า
 */
function friendlyError(err) {
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') return t('tpixDexError.rejected');
    const msg = (err?.reason || err?.shortMessage || err?.message || '').toString().toLowerCase();
    if (msg.includes('pair_not_found') || msg.includes('insufficient_liquidity')) return t('tpixDexError.noLiquidity');
    if (msg.includes('insufficient_output_amount') || msg.includes('insufficient_a_amount') || msg.includes('insufficient_b_amount')) return t('tpixDexError.priceMoved');
    if (msg.includes('expired')) return t('tpixDexError.expired');
    if (msg.includes('transfer_from_failed') || msg.includes('transferfrom')) return t('tpixDexError.transferFailed');
    if (msg.includes('insufficient')) return t('tpixDexError.insufficient');
    if (msg.includes('user rejected') || msg.includes('user denied')) return t('tpixDexError.rejected');
    if (msg.includes('timeout') || msg.includes('timed out')) return t('tpixDexError.timeout');
    return t('tpixDexError.failed');
}

/** สร้าง path จริงบนเชน — NATIVE ถูกแทนด้วย WTPIX */
function buildPath(fromToken, toToken) {
    const from = fromToken === NATIVE ? TPIX_DEX.WTPIX : fromToken;
    const to = toToken === NATIVE ? TPIX_DEX.WTPIX : toToken;
    if (from.toLowerCase() === to.toLowerCase()) return null;
    // ถ้าไม่มีคู่ตรง router จะ revert PAIR_NOT_FOUND — caller ลอง route ผ่าน WTPIX ได้
    return [from, to];
}

export function explorerTxUrl(hash) {
    return `https://explorer.tpix.online/tx/${hash}`;
}

/** decimals บนเชนของโทเคนที่ตรวจแล้ว (ที่อยู่ → decimals) — ใช้ร่วมกันทุกหน้า ไม่ต้องอ่านซ้ำ */
const _chainDecimals = new Map();

/** ใช้ในเทสต์ — ล้าง decimals ที่จำไว้ */
export function _resetTokenCheckForTests() {
    _chainDecimals.clear();
}

/** เลข decimals ที่ใช้ได้ (0–36) หรือ null — ห้ามเดา 18 ให้เอง */
export function validDecimals(value) {
    if (value === null || value === undefined || value === '' || typeof value === 'boolean') return null;
    const n = Number(value);
    return Number.isInteger(n) && n >= 0 && n <= 36 ? n : null;
}

export function useTpixDex() {
    const isLoadingQuote = ref(false);
    const isExecuting = ref(false);
    const isApproving = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const txStatus = ref(null); // 'pending' | 'confirmed' | 'failed'

    /** ราคาล่าสุดที่ getTradeQuote คืน (คือราคาที่หน้าจอแสดงอยู่) — ผูก minOut ของไม้กับสิ่งที่ผู้ใช้เห็น */
    let lastQuote = null;

    /**
     * ให้แน่ใจว่าได้ที่อยู่จากทะเบียนแล้ว และพร้อมจริง
     * ⚠️ เดิมดูแค่ loaded — โหลดล้มครั้งเดียว (429/เน็ตสะดุด) = "ยังไม่ deploy" ไปตลอดหน้านั้น
     *    ตอนนี้ยังไม่พร้อมก็ถามใหม่ (loadDexConfig จำคำตอบที่สำเร็จไว้ 60 วิ จึงไม่ยิงถี่ ถ้าล้มจะถามจริง)
     */
    async function ensureConfig() {
        if (!TPIX_DEX.loaded || !isDexConfigured()) await loadDexConfig();
        return isDexConfigured();
    }

    /** ค่าแก๊สที่ต้องบอกกระเป๋าตรง ๆ บนเชนนี้ (ดู chainGasOverrides) */
    function gasOverrides() {
        return chainGasOverrides(getTpixReadProvider());
    }

    /** กระเป๋าฝังยังไม่ปลดล็อก → เปิดหน้าต่างปลดล็อกให้ แทนข้อความ "เชื่อมกระเป๋า" ที่ไม่มีปุ่มให้กด */
    function lockedWallet(walletStore) {
        if (walletStore?.isLocked !== true) return false;
        error.value = t('tpixDexError.unlockWallet');
        walletStore.openConnectModal?.();
        return true;
    }

    /**
     * ขอ quote จริงจาก router (RPC ของ TPIX chain)
     * @param fromToken 'native' หรือ ERC-20 address
     * @param toToken   'native' หรือ ERC-20 address
     * @param amount    จำนวน (human units)
     * @param decimals  { from, to } — decimals ของแต่ละฝั่ง (native = 18)
     * @returns {amountOut, path, amounts} หรือ null ถ้าไม่มีราคา (ไม่ fabricate)
     */
    async function getQuote(fromToken, toToken, amount, decimals = { from: 18, to: 18 }) {
        error.value = null;
        if (!(await ensureConfig())) {
            error.value = t('tpixDexError.notDeployed');
            return null;
        }
        if (!amount || Number(amount) <= 0) return null;

        isLoadingQuote.value = true;
        try {
            const path = buildPath(fromToken, toToken);
            if (!path) return null;

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, getTpixReadProvider());
            const amountIn = toWei(amount, decimals.from);

            let amounts;
            try {
                amounts = await router.getAmountsOut(amountIn, path);
            } catch (directErr) {
                // ไม่มีคู่ตรง → ลอง route ผ่าน WTPIX (token↔token เท่านั้น)
                if (fromToken !== NATIVE && toToken !== NATIVE) {
                    const hopPath = [path[0], TPIX_DEX.WTPIX, path[1]];
                    amounts = await router.getAmountsOut(amountIn, hopPath);
                    return {
                        amountOut: formatUnits(amounts[amounts.length - 1], decimals.to),
                        path: hopPath,
                        amounts,
                    };
                }
                throw directErr;
            }

            return {
                amountOut: formatUnits(amounts[amounts.length - 1], decimals.to),
                path,
                amounts,
            };
        } catch (err) {
            error.value = friendlyError(err);
            return null;
        } finally {
            isLoadingQuote.value = false;
        }
    }

    /**
     * Quote ในรูปแบบเดียวกับ useSwap.getQuote() ฝั่ง BSC — ให้หน้าเทรดใช้ตัวเดียวกันได้
     * ทั้งพรีวิวและตอนส่งจริง โดยไม่ต้องแยกโค้ดตามเชน
     *
     * @param fromTok {address|'native', decimals, symbol}
     * @param toTok   {address|'native', decimals, symbol}
     * @param amount  จำนวนฝั่ง input (human units)
     * @param slippagePct ค่าเริ่มต้น 0.5
     */
    async function getTradeQuote(fromTok, toTok, amount, slippagePct = 0.5) {
        const from = toDexAddress(fromTok.address);
        const to = toDexAddress(toTok.address);
        const decimals = { from: fromTok.decimals ?? 18, to: toTok.decimals ?? 18 };

        const q = await getQuote(from, to, amount, decimals);
        if (!q) return null;

        const grossAmount = Number(amount);
        const netOutput = parseFloat(q.amountOut);
        const slippage = Number.isFinite(Number(slippagePct)) ? Number(slippagePct) : 0.5;
        const rawAmountOut = q.amounts[q.amounts.length - 1];

        /*
         * price impact จาก reserve ของทุกฮอปในเส้นทาง — บอกผู้ใช้ก่อนว่าไม้นี้ดันราคาแค่ไหน
         * อ่านพูลไม่ได้ = null ("ไม่รู้") ไม่ใช่ 0 — ทั้ง /swap และ /trade บล็อกเมื่อไม่รู้ค่า (isPriceImpactBlocked)
         * ⚠️ เดิมใช้ reserve ของฮอปแรกเทียบกับผลลัพธ์ของฮอปสุดท้าย — เส้นทาง A→WTPIX→B หน่วยไม่ตรงกัน
         *    ได้ 0% (ผ่านด่านทั้งที่ดันราคาแรง) หรือ ~100% (บล็อกตลอดกาล)
         */
        let priceImpact = null;
        try {
            priceImpact = await pathPriceImpact(q.path, toWei(amount, decimals.from), rawAmountOut);
        } catch {
            priceImpact = null;
        }

        // minOut ที่จะส่งเข้า router ถ้าใช้ quote นี้ — สูตรเดียวกับ executeSwap (basis points ไม่ใช้ float)
        const bps = BigInt(Math.round(Math.min(Math.max(slippage, 0), 100) * 100));
        const minOutWei = (rawAmountOut * (10000n - bps)) / 10000n;

        const result = {
            amountIn: grossAmount,
            swapInput: grossAmount,
            amountOut: netOutput,
            netOutput,
            exchangeRate: grossAmount > 0 ? netOutput / grossAmount : 0,
            // ค่าธรรมเนียมอยู่ในพูล — ผู้ใช้ไม่ต้องจ่ายธุรกรรมที่สอง
            feeRate: POOL_FEE_PCT,
            feeAmount: grossAmount * (POOL_FEE_PCT / 100),
            feeModel: 'in_pool',
            priceImpact: priceImpact === null ? null : Number(priceImpact.toFixed(4)),
            slippage,
            minimumReceived: Math.max(netOutput * (1 - slippage / 100), 0),
            path: q.path,
            amounts: q.amounts,
            rawAmountOut,
            minOutWei,
            amountInSwapWei: toWei(amount, decimals.from),
            feeWei: 0n,
            decimals,
            from,
            to,
        };

        lastQuote = { key: quoteKey(from, to, amount, slippage), quote: result, at: Date.now() };
        return result;
    }

    function quoteKey(from, to, amount, slippage) {
        return [String(from).toLowerCase(), String(to).toLowerCase(), Number(amount), Number(slippage)].join('|');
    }

    /**
     * ราคาที่หน้าจอแสดงอยู่สำหรับไม้นี้ (คู่/ทิศ/จำนวน/slippage ตรงกัน) — null ถ้าไม่มีหรือเก่าเกิน
     * ใช้ตอนกดส่งจาก /trade: ราคาใหม่ที่ขอตอนส่งต้องไม่แย่กว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็น
     */
    function recentQuote(fromTok, toTok, amount, slippagePct, maxAgeMs = 10 * 60_000) {
        if (!lastQuote || !fromTok || !toTok) return null;
        const key = quoteKey(toDexAddress(fromTok.address), toDexAddress(toTok.address), amount, slippagePct);
        if (lastQuote.key !== key || Date.now() - lastQuote.at > maxAgeMs) return null;
        return lastQuote.quote;
    }

    /**
     * ผลกระทบราคา (%) ของทั้งเส้นทาง: เทียบผลลัพธ์จริงกับ "ราคากลาง" ที่คูณต่อกันทุกฮอป (หน่วย wei ล้วน)
     * @returns {Promise<number|null>} null = อ่านพูลใดพูลหนึ่งไม่ได้ / พูลว่าง
     */
    async function pathPriceImpact(path, amountInWei, amountOutWei) {
        if (!Array.isArray(path) || path.length < 2 || !(amountInWei > 0n)) return null;
        let spotOut = Number(amountInWei);
        for (let i = 0; i < path.length - 1; i++) {
            const pool = await getPoolInfo(path[i], path[i + 1]);
            if (!pool || !(pool.reserveIn > 0n) || !(pool.reserveOut > 0n)) return null;
            spotOut *= Number(pool.reserveOut) / Number(pool.reserveIn);
        }
        if (!(spotOut > 0) || !Number.isFinite(spotOut)) return null;
        return Math.max(0, (1 - Number(amountOutWei) / spotOut) * 100);
    }

    /** เช็ค allowance ของ ERC-20 ต่อ router (native ไม่ต้อง approve) */
    async function needsApproval(tokenAddress, amount, decimals = 18) {
        const token = toDexAddress(tokenAddress);
        if (token === NATIVE) return false;
        const walletStore = useWalletStore();
        if (!walletStore.address) return false;
        try {
            const erc20 = new Contract(token, TPIX_DEX_ERC20_ABI, getTpixReadProvider());
            const allowance = await erc20.allowance(walletStore.address, TPIX_DEX.ROUTER);
            return allowance < toWei(amount, decimals);
        } catch {
            return true; // อ่านไม่ได้ → บังคับ approve (fail-closed)
        }
    }

    /** approve token ให้ router */
    async function approveToken(tokenAddress, amount, decimals = 18) {
        const walletStore = useWalletStore();
        if (lockedWallet(walletStore)) return false;
        // ไม่มีผู้เซ็น = แจ้งเป็นข้อความพร้อมแสดง ไม่โยน Error ดิบ (หน้าเว็บเคยโชว์ 'Wallet not connected' ตรง ๆ)
        if (!walletStore.signer) {
            error.value = t('tpixDexError.connectFirst');
            return false;
        }

        isApproving.value = true;
        error.value = null;
        try {
            const gas = await gasOverrides();
            const erc20 = new Contract(toDexAddress(tokenAddress), TPIX_DEX_ERC20_ABI, walletStore.signer);
            const tx = await erc20.approve(TPIX_DEX.ROUTER, toWei(amount, decimals), ...withOverrides(gas));
            await tx.wait();
            return true;
        } catch (err) {
            error.value = friendlyError(err);
            return false;
        } finally {
            isApproving.value = false;
        }
    }

    /** ด่านก่อนส่งธุรกรรมทุกชนิด — กระเป๋า + เชน + DEX พร้อม */
    async function guardWrite() {
        const walletStore = useWalletStore();
        if (!(await ensureConfig())) {
            error.value = t('tpixDexError.notDeployed');
            return null;
        }
        if (lockedWallet(walletStore)) return null;
        if (!walletStore.signer || !walletStore.address) {
            error.value = t('tpixDexError.connectFirst');
            return null;
        }
        // Wrong-chain guard — ธุรกรรมนี้อยู่บน TPIX chain เท่านั้น
        if (Number(walletStore.chainId) !== TPIX_DEX.CHAIN_ID) {
            error.value = t('tpixDexError.switchTpix');
            return null;
        }
        return walletStore;
    }

    /**
     * Execute swap บน TPIX chain
     * @param fromToken 'native' | ERC-20 address
     * @param toToken   'native' | ERC-20 address
     * @param amount    จำนวนฝั่ง input (human units)
     * @param options   { slippagePct = 0.5, decimals = {from:18,to:18}, path = null, amounts = null, minOutFloor = null }
     *                  amounts = ผลของ getAmountsOut ตอนเสนอราคา (คู่กับ path) — ถ้าส่งมา
     *                  minOut คิดจากราคาที่ผู้ใช้เห็นบนจอ แบบเดียวกับ useSwap ฝั่ง BSC (quote.rawAmountOut)
     *                  minOutFloor = minOut ขั้นต่ำ (wei) ที่ผู้ใช้เห็นบนจอก่อนหน้า — minOut จริงไม่ต่ำกว่านี้
     * @returns {txHash, amountOut} หรือ null ถ้าพลาด (อ่าน error.value)
     *          ส่งออกไปแล้วแต่ยืนยันไม่ได้ = null + txStatus 'pending' + txHash (ห้ามบอกผู้ใช้ให้ลองใหม่)
     */
    async function executeSwap(fromToken, toToken, amount, options = {}) {
        const { slippagePct = 0.5, decimals = { from: 18, to: 18 } } = options;

        error.value = null;
        txHash.value = null;
        txStatus.value = null;

        const walletStore = await guardWrite();
        if (!walletStore) return null;

        isExecuting.value = true;
        try {
            /*
             * minOut ต้องผูกกับราคาที่ผู้ใช้กดยืนยัน — ส่ง amounts มากับ path ใช้ตัวนั้นเลย
             * (เดิมอ่านราคาใหม่ตอนส่ง minOut จึงเลื่อนตามราคาปัจจุบัน ไม่ใช่ "ได้รับอย่างน้อย" บนจอ)
             * ไม่มี path = ขอราคาสดก่อนยิง
             */
            const quote = options.path
                ? { path: options.path, amounts: options.amounts ?? null }
                : await getQuote(fromToken, toToken, amount, decimals);
            if (!quote) {
                if (!error.value) error.value = t('tpixDexError.noLiquidity');
                return null;
            }

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const amountIn = toWei(amount, decimals.from);
            const path = quote.path;
            const amounts = quote.amounts ?? (await router.getAmountsOut(amountIn, path));
            const quotedOut = amounts[amounts.length - 1];
            // minOut = quote × (1 - slippage) — คิดเป็น basis points กัน float precision
            const slip = Number(slippagePct);
            if (!Number.isFinite(slip) || slip < 0 || slip >= 100) {
                error.value = t('tpixDexError.slippageTooHigh');
                return null;
            }
            const bps = BigInt(Math.round(slip * 100));
            let minOut = (quotedOut * (10000n - bps)) / 10000n;
            // ขั้นต่ำที่ผู้ใช้เห็นบนจอ (ถ้ามี) — ราคาใหม่ต้องไม่ทำให้ "ได้รับอย่างน้อย" ต่ำกว่าที่เขากดยืนยัน
            if (typeof options.minOutFloor === 'bigint' && options.minOutFloor > minOut && options.minOutFloor <= quotedOut) {
                minOut = options.minOutFloor;
            }
            if (minOut <= 0n) {
                error.value = t('tpixDexError.slippageTooHigh');
                return null;
            }
            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;
            // เชน TPIX ค่าแก๊ส 0 — บอกกระเป๋าตรง ๆ (ดู chainGasOverrides)
            const gas = await gasOverrides();

            let tx;
            if (fromToken === NATIVE) {
                tx = await router.swapExactETHForTokens(
                    minOut, path, walletStore.address, deadline,
                    ...withOverrides(gas, { value: amountIn }),
                );
            } else if (toToken === NATIVE) {
                tx = await router.swapExactTokensForETH(
                    amountIn, minOut, path, walletStore.address, deadline,
                    ...withOverrides(gas),
                );
            } else {
                tx = await router.swapExactTokensForTokens(
                    amountIn, minOut, path, walletStore.address, deadline,
                    ...withOverrides(gas),
                );
            }

            txHash.value = tx.hash;
            txStatus.value = 'pending';

            let receipt;
            try {
                receipt = await tx.wait();
            } catch (waitErr) {
                return settleWaitError(waitErr, quotedOut, decimals);
            }
            txStatus.value = receipt?.status === 1 ? 'confirmed' : 'failed';

            if (receipt?.status !== 1) {
                error.value = t('tpixDexError.swapFailedOnchain');
                return null;
            }

            return {
                txHash: tx.hash,
                amountOut: formatUnits(quotedOut, decimals.to),
            };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /**
     * tx.wait() โยนหลังส่งธุรกรรมออกไปแล้ว — ตัดสินตามชนิด (classifyWaitError)
     * ⚠️ เดิมทุกกรณีกลายเป็น "ทำรายการไม่สำเร็จ ลองใหม่อีกครั้ง" ทั้งที่ไม้อาจลงเชนไปแล้ว
     *    ผู้ใช้กดซ้ำ = สวอปสองรอบ · ตอนนี้ "ยืนยันไม่ได้" ค้างสถานะ pending พร้อม hash ให้ไปตรวจ
     */
    function settleWaitError(waitErr, quotedOut, decimals) {
        const outcome = classifyWaitError(waitErr);
        if (outcome.kind === 'repriced' && outcome.hash) {
            txHash.value = outcome.hash;
            txStatus.value = 'confirmed';
            return { txHash: outcome.hash, amountOut: formatUnits(quotedOut, decimals.to) };
        }
        if (outcome.kind === 'reverted') {
            txStatus.value = 'failed';
            error.value = t('tpixDexError.swapFailedOnchain');
            return null;
        }
        if (outcome.kind === 'replaced') {
            txStatus.value = 'failed';
            error.value = t('tpixDexError.replaced');
            return null;
        }
        txStatus.value = 'pending';
        error.value = t('tpixDexError.unconfirmed');
        return null;
    }

    /**
     * ส่งไม้จากหน้าเทรด — สัญญาแบบเดียวกับ useSwap.executeSwap() ฝั่ง BSC
     * (คืน {hash, status, url} และ throw เมื่อล้มเหลว โดย error.value มีข้อความพร้อมแสดง)
     * บันทึกประวัติเข้าเซิร์ฟเวอร์ให้ด้วย (best-effort) เพื่อให้ป้ายบนกราฟ/ประวัติเห็นไม้นี้
     */
    async function executeTradeSwap(fromTok, toTok, amount, quote, slippage = 0.5, extra = {}) {
        const walletStore = useWalletStore();
        // ผู้ส่งจริงของไม้นี้ — จำไว้ก่อน await (สลับบัญชีกลางทางแล้วต้องบันทึกให้บัญชีที่เซ็น)
        const sender = walletStore.address;
        const from = toDexAddress(fromTok.address);
        const to = toDexAddress(toTok.address);
        const decimals = quote?.decimals ?? { from: fromTok.decimals ?? 18, to: toTok.decimals ?? 18 };

        const result = await executeSwap(from, to, amount, {
            slippagePct: slippage,
            decimals,
            path: quote?.path ?? null,
            // ใช้ amounts ของ quote เฉพาะเมื่อมาพร้อม path ของมันเอง (ฮอปตรงกัน)
            amounts: quote?.path && quote?.amounts ? quote.amounts : null,
            minOutFloor: extra.minOutFloor ?? null,
        });

        if (!result) {
            const err = new Error(error.value || t('tpixDexError.swapFailed'));
            err.isFriendly = true;
            // ส่งออกไปแล้วแต่ยืนยันไม่ได้ — ผู้เรียกต้องโชว์ลิงก์และห้ามคืนค่าบริการ/ชวนกดซ้ำ
            if (txStatus.value === 'pending' && txHash.value) {
                err.txSent = true;
                err.txHash = txHash.value;
                err.txUrl = explorerTxUrl(txHash.value);
            }
            throw err;
        }

        // บันทึกฝั่งเซิร์ฟเวอร์ — ล้มก็ไม่กระทบไม้ที่ลงไปแล้ว
        try {
            await axios.post('/api/v1/swap/execute', {
                from_token: from === NATIVE ? ZERO : from,
                to_token: to === NATIVE ? ZERO : to,
                from_amount: Number(amount),
                to_amount: parseFloat(result.amountOut),
                fee_amount: 0, // ค่าธรรมเนียมอยู่ในพูล
                tx_hash: result.txHash,
                chain_id: TPIX_DEX.CHAIN_ID,
                wallet_address: sender || walletStore.address,
            });
        } catch (apiErr) {
            console.warn('Failed to record TPIX DEX swap on backend:', apiErr?.message);
        }

        return {
            hash: result.txHash,
            status: 'confirmed',
            url: explorerTxUrl(result.txHash),
            amountOut: result.amountOut,
        };
    }

    /**
     * balance ของ token (หรือ native) บน TPIX chain — อ่านผ่าน read RPC
     * @returns {Promise<string|null>} null = อ่านไม่ได้ ("ไม่รู้ยอด") — ต่างจาก '0' ที่อ่านได้ว่าศูนย์
     *          (เดิมคืน '0' ตอน RPC ล้ม หน้า Swap จึงขึ้น "ยอดไม่พอ" ทั้งที่มีเหรียญ)
     */
    async function getBalance(tokenAddress) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return '0';
        try {
            const provider = getTpixReadProvider();
            const token = toDexAddress(tokenAddress);
            if (token === NATIVE) {
                return formatUnits(await provider.getBalance(walletStore.address), 18);
            }
            const erc20 = new Contract(token, TPIX_DEX_ERC20_ABI, provider);
            const [raw, dec] = await Promise.all([
                erc20.balanceOf(walletStore.address),
                erc20.decimals(),
            ]);
            return formatUnits(raw, dec);
        } catch {
            return null;
        }
    }

    /**
     * decimals ของโทเคนต้องตรงกับบนเชนก่อนส่งไม้ — ผิดหลักเดียว = จำนวนผิดเป็นล้านเท่า
     * (หน้า Swap ตรวจอยู่แล้ว · /trade เดิมเชื่อค่าในฐานข้อมูลและเดา 18 ให้เองเมื่อไม่มีค่า)
     * @returns {Promise<true|string>} true = ผ่าน · string = คีย์ข้อความ tpixDexError.*
     */
    async function verifyTokenDecimals(tok) {
        if (!tok) return 'tpixDexError.tokenCheckFailed';
        const expected = validDecimals(tok.decimals);
        if (expected === null) return 'tpixDexError.tokenMismatch';
        const address = toDexAddress(tok.address);
        if (address === NATIVE) return expected === 18 ? true : 'tpixDexError.tokenMismatch';

        if (!_chainDecimals.has(address)) {
            const meta = await readTokenMeta(address);
            const onchain = validDecimals(meta?.decimals);
            if (onchain === null) return 'tpixDexError.tokenCheckFailed';
            _chainDecimals.set(address, onchain);
        }
        return _chainDecimals.get(address) === expected ? true : 'tpixDexError.tokenMismatch';
    }

    // ── สภาพคล่อง ────────────────────────────────────────────────────────────

    /**
     * ข้อมูลพูลของคู่ (tokenIn, tokenOut) เรียง reserve ตามฝั่งที่ขอ
     * @returns {pair, reserveIn, reserveOut, totalSupply} (BigInt) หรือ null ถ้ายังไม่มีพูล
     */
    async function getPoolInfo(tokenA, tokenB) {
        if (!(await ensureConfig())) return null;
        const a = toDexAddress(tokenA) === NATIVE ? TPIX_DEX.WTPIX : toDexAddress(tokenA);
        const b = toDexAddress(tokenB) === NATIVE ? TPIX_DEX.WTPIX : toDexAddress(tokenB);
        const provider = getTpixReadProvider();
        const factory = new Contract(TPIX_DEX.FACTORY, TPIX_DEX_FACTORY_ABI, provider);
        const pairAddress = await factory.getPair(a, b);
        if (!pairAddress || pairAddress === ZERO) return null;

        const pair = new Contract(pairAddress, TPIX_DEX_PAIR_ABI, provider);
        const [token0, reserves, totalSupply] = await Promise.all([
            pair.token0(),
            pair.getReserves(),
            pair.totalSupply(),
        ]);
        const aIsToken0 = token0.toLowerCase() === a.toLowerCase();
        return {
            pair: pairAddress,
            reserveIn: aIsToken0 ? reserves[0] : reserves[1],
            reserveOut: aIsToken0 ? reserves[1] : reserves[0],
            totalSupply,
        };
    }

    /**
     * สัดส่วนของผู้ใช้ในพูล TOKEN/TPIX — จำนวน LP, ส่วนแบ่ง และมูลค่าที่ถอนได้ตอนนี้
     */
    async function getMyPosition(tokenAddress, tokenDecimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return null;
        const pool = await getPoolInfo(tokenAddress, NATIVE);
        if (!pool) return null;

        const pair = new Contract(pool.pair, TPIX_DEX_PAIR_ABI, getTpixReadProvider());
        const lp = await pair.balanceOf(walletStore.address);
        if (pool.totalSupply === 0n) return null;

        const tokenShare = (pool.reserveIn * lp) / pool.totalSupply;
        const tpixShare = (pool.reserveOut * lp) / pool.totalSupply;

        return {
            pair: pool.pair,
            lp,
            lpFormatted: formatUnits(lp, 18),
            sharePct: Number((lp * 1_000_000n) / pool.totalSupply) / 10_000,
            token: formatUnits(tokenShare, tokenDecimals),
            tpix: formatUnits(tpixShare, 18),
            reserveToken: formatUnits(pool.reserveIn, tokenDecimals),
            reserveTpix: formatUnits(pool.reserveOut, 18),
            // ค่าดิบ (wei) ให้คิดยอดถอนแบบ BigInt ได้ตรง ไม่ต้องแปลงกลับจากสตริงทศนิยม
            reserveTokenWei: pool.reserveIn,
            reserveTpixWei: pool.reserveOut,
            totalSupply: pool.totalSupply,
        };
    }

    /**
     * LP ที่พูลจะ mint ให้ feeTo ก่อนเผา LP ของผู้ใช้ (_mintFee) — อ่านไม่ได้ = 0 (เท่าพฤติกรรมเดิม)
     */
    async function pendingProtocolFee(position) {
        try {
            const provider = getTpixReadProvider();
            const factory = new Contract(TPIX_DEX.FACTORY, TPIX_DEX_FACTORY_ABI, provider);
            const feeTo = await factory.feeTo();
            if (!feeTo || String(feeTo).toLowerCase() === ZERO) return 0n;
            const kLast = await new Contract(position.pair, PAIR_KLAST_ABI, provider).kLast();
            return protocolFeeMint(position.reserveTokenWei, position.reserveTpixWei, position.totalSupply, BigInt(kLast));
        } catch {
            return 0n;
        }
    }

    /**
     * tx.wait() ของธุรกรรมสภาพคล่องโยน — แบบเดียวกับ settleWaitError ของสวอป
     * @returns {{hash, url}|null}
     */
    function settleLiquidityWait(waitErr, failKey) {
        const outcome = classifyWaitError(waitErr);
        if (outcome.kind === 'repriced' && outcome.hash) {
            txHash.value = outcome.hash;
            txStatus.value = 'confirmed';
            return { hash: outcome.hash, url: explorerTxUrl(outcome.hash) };
        }
        if (outcome.kind === 'unconfirmed') {
            txStatus.value = 'pending';
            error.value = t('tpixDexError.unconfirmed');
            return null;
        }
        txStatus.value = 'failed';
        error.value = t(failKey);
        return null;
    }

    /**
     * เติมสภาพคล่อง TOKEN + TPIX (พูลใหม่ = ตั้งราคาเปิดจากอัตราส่วนที่ใส่)
     * router จะปรับให้ตรงอัตราส่วนพูลเอง — ส่วนที่เกินส่งคืนกระเป๋า
     */
    async function addLiquidity(tokenAddress, tokenAmount, tpixAmount, options = {}) {
        const { slippagePct = 0.5, tokenDecimals = 18 } = options;
        error.value = null;
        txHash.value = null;

        const walletStore = await guardWrite();
        if (!walletStore) return null;

        const token = toDexAddress(tokenAddress);
        if (token === NATIVE) {
            error.value = t('tpixDexError.chooseToken');
            return null;
        }

        isExecuting.value = true;
        try {
            const tokenWei = toWei(tokenAmount, tokenDecimals);
            const tpixWei = toWei(tpixAmount, 18);
            const bps = BigInt(Math.round(slippagePct * 100));
            const tokenMin = (tokenWei * (10000n - bps)) / 10000n;
            const tpixMin = (tpixWei * (10000n - bps)) / 10000n;
            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;

            const gas = await gasOverrides();
            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const tx = await router.addLiquidityETH(
                token, tokenWei, tokenMin, tpixMin, walletStore.address, deadline,
                ...withOverrides(gas, { value: tpixWei }),
            );
            txHash.value = tx.hash;
            txStatus.value = 'pending';
            let receipt;
            try {
                receipt = await tx.wait();
            } catch (waitErr) {
                return settleLiquidityWait(waitErr, 'tpixDexError.addFailedOnchain');
            }
            txStatus.value = receipt?.status === 1 ? 'confirmed' : 'failed';
            if (receipt?.status !== 1) {
                error.value = t('tpixDexError.addFailedOnchain');
                return null;
            }
            return { hash: tx.hash, url: explorerTxUrl(tx.hash) };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /** approve LP token ให้ router ก่อนถอน */
    async function approveLp(pairAddress, lpAmountWei) {
        const walletStore = useWalletStore();
        if (lockedWallet(walletStore)) return false;
        // ไม่มีผู้เซ็น = แจ้งเป็นข้อความพร้อมแสดง ไม่โยน Error ดิบ (หน้าเว็บเคยโชว์ 'Wallet not connected' ตรง ๆ)
        if (!walletStore.signer) {
            error.value = t('tpixDexError.connectFirst');
            return false;
        }
        isApproving.value = true;
        try {
            const pair = new Contract(pairAddress, TPIX_DEX_PAIR_ABI, walletStore.signer);
            const allowance = await pair.allowance(walletStore.address, TPIX_DEX.ROUTER);
            if (allowance >= lpAmountWei) return true;
            const gas = await gasOverrides();
            const tx = await pair.approve(TPIX_DEX.ROUTER, lpAmountWei, ...withOverrides(gas));
            await tx.wait();
            return true;
        } catch (err) {
            error.value = friendlyError(err);
            return false;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * ถอนสภาพคล่องเป็นสัดส่วน (%) ของ LP ที่ถือ — ได้ TOKEN + TPIX คืน
     */
    async function removeLiquidity(tokenAddress, percent, options = {}) {
        const { slippagePct = 0.5, tokenDecimals = 18 } = options;
        error.value = null;
        txHash.value = null;

        const walletStore = await guardWrite();
        if (!walletStore) return null;

        const pct = Math.min(100, Math.max(0, Number(percent) || 0));
        if (pct <= 0) {
            error.value = t('tpixDexError.chooseAmount');
            return null;
        }

        isExecuting.value = true;
        try {
            const position = await getMyPosition(tokenAddress, tokenDecimals);
            if (!position || position.lp === 0n) {
                error.value = t('tpixDexError.noPosition');
                return null;
            }

            const lpToBurn = (position.lp * BigInt(Math.round(pct * 100))) / 10000n;
            /*
             * ยอดที่ถอนได้จริง = reserve × LP ที่เผา ÷ (totalSupply + LP ที่พูลจะ mint ให้ feeTo ก่อน)
             * ⚠️ เดิมหารด้วย totalSupply ตรง ๆ — พอพูลมีปริมาณซื้อขายสะสม ส่วนแบ่งโปรโตคอลทำให้ได้จริง
             *    น้อยกว่าที่คิดเกิน slippage 0.5% แล้วธุรกรรม revert (INSUFFICIENT_A_AMOUNT)
             */
            const supplyAtBurn = position.totalSupply + (await pendingProtocolFee(position));
            const tokenExpected = (position.reserveTokenWei * lpToBurn) / supplyAtBurn;
            const tpixExpected = (position.reserveTpixWei * lpToBurn) / supplyAtBurn;
            const bps = BigInt(Math.round(Math.min(Math.max(Number(slippagePct) || 0, 0), 99) * 100));
            const tokenMin = (tokenExpected * (10000n - bps)) / 10000n;
            const tpixMin = (tpixExpected * (10000n - bps)) / 10000n;

            if (!(await approveLp(position.pair, lpToBurn))) return null;

            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;
            const gas = await gasOverrides();
            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const tx = await router.removeLiquidityETH(
                toDexAddress(tokenAddress), lpToBurn, tokenMin, tpixMin, walletStore.address, deadline,
                ...withOverrides(gas),
            );
            txHash.value = tx.hash;
            txStatus.value = 'pending';
            let receipt;
            try {
                receipt = await tx.wait();
            } catch (waitErr) {
                return settleLiquidityWait(waitErr, 'tpixDexError.removeFailedOnchain');
            }
            txStatus.value = receipt?.status === 1 ? 'confirmed' : 'failed';
            if (receipt?.status !== 1) {
                error.value = t('tpixDexError.removeFailedOnchain');
                return null;
            }
            return { hash: tx.hash, url: explorerTxUrl(tx.hash) };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /** symbol/decimals ของ ERC-20 บนเชน TPIX — ใช้ตรวจที่อยู่ที่ผู้ใช้วางเอง */
    async function readTokenMeta(tokenAddress) {
        const token = toDexAddress(tokenAddress);
        if (token === NATIVE) return { symbol: 'TPIX', name: 'TPIX', decimals: 18, native: true };
        if (!/^0x[a-f0-9]{40}$/.test(token)) return null;
        try {
            const erc20 = new Contract(token, TPIX_DEX_ERC20_ABI, getTpixReadProvider());
            const [symbol, decimals, name] = await Promise.all([
                erc20.symbol(),
                erc20.decimals(),
                erc20.name().catch(() => ''),
            ]);
            return { symbol, name: name || symbol, decimals: Number(decimals), native: false, address: token };
        } catch {
            return null;
        }
    }

    return {
        // state
        isLoadingQuote,
        isExecuting,
        isApproving,
        error,
        txHash,
        txStatus,
        // constants
        NATIVE,
        POOL_FEE_PCT,
        PRICE_IMPACT_BLOCK_PCT,
        // pure helpers (ให้หน้าใช้โดยไม่ต้อง import เพิ่ม)
        isPriceImpactBlocked,
        explorerTxUrl,
        // actions
        getQuote,
        getTradeQuote,
        recentQuote,
        verifyTokenDecimals,
        needsApproval,
        approveToken,
        executeSwap,
        executeTradeSwap,
        getBalance,
        getPoolInfo,
        getMyPosition,
        addLiquidity,
        removeLiquidity,
        readTokenMeta,
        ensureConfig,
        isDexConfigured,
    };
}
