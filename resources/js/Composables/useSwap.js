/**
 * TPIX TRADE - useSwap Composable
 * Handles swap quotes, execution, and token approvals (BSC / PancakeSwap V2).
 *
 * Key invariants (see audit 2026-06-19):
 *  - Prices & balances are read from a dedicated BSC RPC, never the wallet's
 *    provider — so quotes work even while the wallet is on TPIX Chain (4289)
 *    and a quote is NEVER fabricated to a 1:1 fallback.
 *  - The ~0.3% platform fee is taken from the INPUT token and RESERVED: only
 *    (amount - fee) is routed through the swap, so the fee transfer always
 *    succeeds (even on MAX) and the displayed numbers match what lands on-chain.
 *  - minOut reflects slippage ONLY (the fee never reduces the router output).
 *
 * เพิ่มเติม (ออดิท 2026-10-21):
 *  - จำนวนเงินเป็น BigInt จากสตริงทศนิยมตลอดทาง (ไม่ผ่าน float) และไม่เกินยอดจริงบนเชน
 *  - /swap/quote ล้ม = ไม่มีราคา (fail-closed) — ไม่เดาอัตราค่าธรรมเนียมเอง
 *  - signer ถูกจับไว้ตั้งแต่ต้น ขั้นค่าธรรมเนียมต้องเป็นบัญชีเดียวกับที่สวอป
 *  - บันทึกไม้เข้าคิวในเครื่องทันทีที่สวอปลงเชน ก่อนขั้นค่าธรรมเนียม
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Contract, parseUnits, formatUnits, MaxUint256, id as eventTopic } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import {
    PANCAKE_ROUTER_ADDRESS,
    PANCAKE_ROUTER_ABI,
    WBNB_ADDRESS,
    ERC20_ABI,
    isNativeToken,
    getRoutingAddress,
    getTokenBalance,
    getAllowance,
    getAmountsOut,
    withBscReadFallback,
    isRpcUnreachable,
    getTxUrl,
} from '@/utils/web3';
import { txDeadlineSeconds } from '@/utils/tradeSettings';
import { t } from '@/Composables/useTranslation';
import axios from 'axios';

// Swaps run on BSC only.
const SWAP_CHAIN_ID = 56;

/*
 * เวลารอแต่ละขั้น — เดิม wait() ไม่มีเพดานเลย
 * RPC สะดุด / WalletConnect ทำคำขอหาย = ปุ่มค้าง "กำลังทำรายการ" ตลอดไป
 * และไม้ที่ลงเชนแล้วไม่ถูกบันทึกเพราะโค้ดไปไม่ถึงขั้นบันทึก
 */
const SWAP_WAIT_TIMEOUT_MS = 180000;     // รอสวอปลงบล็อก (BSC ~3 วินาที/บล็อก)
const APPROVE_WAIT_TIMEOUT_MS = 180000;  // รอ approve ลงบล็อก
const FEE_PROMPT_TIMEOUT_MS = 300000;    // รอผู้ใช้กดยืนยันค่าธรรมเนียมในกระเป๋า
const FEE_WAIT_TIMEOUT_MS = 120000;      // รอธุรกรรมค่าธรรมเนียมลงบล็อก
const FEE_COLLECTOR_TTL_MS = 60000;

/** ยอดที่เกินยอดจริงได้ไม่เกินเท่านี้ถือเป็นเศษจากการปัด → ตัดให้พอดียอด (1 ใน 10,000) */
const CLAMP_DUST_DIVISOR = 10000n;

/** ผลกระทบต่อราคาที่ยอมให้ส่ง (%) — เพดานเดียวกับกระดานเทรดและโหมด TPIX */
export const BSC_PRICE_IMPACT_BLOCK_PCT = 10;

const TRANSFER_TOPIC = eventTopic('Transfer(address,address,uint256)');
const WITHDRAWAL_TOPIC = eventTopic('Withdrawal(address,uint256)');

/**
 * ตัวเลขจากช่องกรอก/ตัวแปร → สตริงทศนิยมธรรมดา (ไม่มีจุลภาค ไม่มีรูป 1e-7)
 *
 * ⚠️ ห้ามแปลงผ่าน Number().toFixed() — float มีเลขนัยสำคัญแค่ ~16 หลัก
 *    ยอด 5,000,000,000.12345678 PEPE กลายเป็น …123456954956 (เกินยอดจริง)
 *    สวอปผ่านเพราะใช้แค่ 99.7% แต่ธุรกรรมค่าธรรมเนียม revert → ไม่ได้ค่าธรรมเนียมเลย
 *
 * @returns {string|null} null = ไม่ใช่จำนวนบวกที่อ่านได้
 */
export function toDecimalString(value) {
    if (value === null || value === undefined) return null;
    let text = typeof value === 'number'
        ? (Number.isFinite(value) ? String(value) : '')
        : String(value);
    text = text.replace(/,/g, '').trim();

    const m = /^(\d*)(?:\.(\d*))?(?:e([+-]?\d+))?$/i.exec(text);
    if (!m || (m[1] === '' && !m[2])) return null;

    let int = m[1] || '0';
    let frac = m[2] || '';
    const exp = m[3] ? parseInt(m[3], 10) : 0;
    if (exp > 0) {
        const moved = frac.padEnd(exp, '0');
        int += moved.slice(0, exp);
        frac = moved.slice(exp);
    } else if (exp < 0) {
        const padded = int.padStart(-exp + 1, '0');
        frac = padded.slice(padded.length + exp) + frac;
        int = padded.slice(0, padded.length + exp);
    }

    int = int.replace(/^0+(?=\d)/, '');
    frac = frac.replace(/0+$/, '');
    return frac ? `${int}.${frac}` : int;
}

/**
 * จำนวนเหรียญ → หน่วยเล็กสุดบนเชน (BigInt) ตัดทศนิยมที่เกิน decimals ทิ้ง (ไม่ปัดขึ้น)
 * @returns {bigint|null}
 */
export function toUnits(value, decimals) {
    const text = toDecimalString(value);
    if (text === null) return null;
    const [int, frac = ''] = text.split('.');
    const kept = frac.slice(0, decimals);
    return parseUnits(kept ? `${int}.${kept}` : int, decimals);
}

/**
 * แบ่งยอดที่ผู้ใช้จ่ายเป็น (ค่าธรรมเนียม, ส่วนที่สวอป) ด้วย BigInt ล้วน
 * ค่าธรรมเนียมปัดขึ้น 1 หน่วยเล็กสุด — อัตรา > 0 ต้องไม่ได้ค่าธรรมเนียม 0 เพราะการปัด
 * ผลรวมสองส่วนเท่ากับยอดที่จ่ายพอดีเสมอ
 *
 * @param {bigint} grossWei
 * @param {number} feeRatePct อัตรา % (เช่น 0.3)
 */
export function splitPlatformFee(grossWei, feeRatePct) {
    const ppm = BigInt(Math.max(0, Math.round(Number(feeRatePct || 0) * 10000)));
    if (ppm === 0n || grossWei <= 0n) return { feeWei: 0n, swapWei: grossWei };
    const feeWei = (grossWei * ppm + 999999n) / 1000000n;
    return { feeWei, swapWei: grossWei - feeWei };
}

/**
 * ผลกระทบต่อราคา (%) จากการเทียบอัตราของไม้จริงกับไม้จิ๋วบนเส้นทางเดียวกัน
 * ค่าธรรมเนียม LP ของสองไม้เท่ากัน จึงหักล้างกันเอง เหลือแค่ส่วนที่ไม้นี้ดันราคา
 * @returns {number|null} null = คำนวณไม่ได้ (ไม่ใช่ 0)
 */
export function priceImpactPct(amountIn, amountOut, refIn, refOut) {
    if (!(amountIn > 0n) || !(refIn > 0n) || !(refOut > 0n) || amountOut < 0n) return null;
    const ratioPpm = (amountOut * refIn * 1000000n) / (refOut * amountIn);
    const impact = (1000000 - Number(ratioPpm)) / 10000;
    return Math.max(0, Math.round(impact * 100) / 100);
}

/** ตัดคำสั่งที่ค้างนานเกิน (ป๊อปอัพกระเป๋าที่ไม่มีใครกด) — ไม่ทำให้ธุรกรรมหาย แค่เลิกรอ */
function withTimeout(promise, ms, code = 'TIMEOUT') {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => {
                const err = new Error('timed out');
                err.code = code;
                reject(err);
            }, ms);
        }),
    ]).finally(() => clearTimeout(timer));
}

/**
 * ยอดที่ได้รับจริงจาก log ของใบเสร็จ (ไม่ใช่ตัวเลขจาก quote)
 * โทเคน = ผลรวม Transfer ของเหรียญปลายทางที่เข้ากระเป๋าเรา
 * BNB = WBNB Withdrawal ที่ router ถอนออกมาโอนให้เรา
 * @returns {bigint|null} null = อ่านไม่ได้ (ผู้เรียกใช้ตัวเลขจาก quote แทน)
 */
export function receivedFromReceipt(receipt, toToken, owner) {
    const logs = Array.isArray(receipt?.logs) ? receipt.logs : [];
    const lower = s => String(s || '').toLowerCase();
    const topicAddress = topic => `0x${lower(topic).slice(-40)}`;
    let total = 0n;
    let found = false;

    try {
        for (const log of logs) {
            const topics = log?.topics || [];
            if (isNativeToken(toToken?.address)) {
                if (lower(log.address) !== lower(WBNB_ADDRESS) || lower(topics[0]) !== WITHDRAWAL_TOPIC) continue;
                if (topicAddress(topics[1]) !== lower(PANCAKE_ROUTER_ADDRESS)) continue;
            } else {
                if (lower(log.address) !== lower(toToken?.address) || lower(topics[0]) !== TRANSFER_TOPIC || topics.length < 3) continue;
                if (topicAddress(topics[2]) !== lower(owner)) continue;
            }
            total += BigInt(log.data);
            found = true;
        }
    } catch {
        return null;
    }

    return found ? total : null;
}

/**
 * คิวบันทึกไม้ที่ส่งไม่สำเร็จ — สวอปลงเชนไปแล้ว แต่ /swap/execute ล้ม
 * (ลายเซ็นกระเป๋าหมดอายุ 4 ชม. → 403, เน็ตหลุด, เซิร์ฟเวอร์ล่ม)
 *
 * เดิม console.warn แล้วจบ = ไม้จริงหายจากประวัติถาวร ทั้งที่ผู้ใช้เห็น "สำเร็จ"
 * เก็บไว้ในเครื่องแล้วลองใหม่ตอนโหลดประวัติครั้งถัดไป (useMyTrades เรียก flushPendingSwapRecords)
 * 422 ไม่เก็บ — ข้อมูลผิดรูป/ซ้ำ ลองกี่ครั้งก็ไม่ผ่าน
 */
export const PENDING_RECORDS_KEY = 'tpix.pendingSwapRecords';
const MAX_PENDING_RECORDS = 20;

function readPendingRecords() {
    try {
        const list = JSON.parse(localStorage.getItem(PENDING_RECORDS_KEY) || '[]');
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

function writePendingRecords(list) {
    try {
        if (list.length) localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify(list.slice(-MAX_PENDING_RECORDS)));
        else localStorage.removeItem(PENDING_RECORDS_KEY);
    } catch {
        // เก็บไม่ได้ (โหมดส่วนตัว) — ยอมเสียประวัติไม้นี้ ไม่กระทบตัวสวอป
    }
}

const sameHash = (a, b) => String(a || '').toLowerCase() === String(b || '').toLowerCase();

/** เขียน/แทนที่บันทึกของ tx นี้ในคิว (หนึ่ง tx = หนึ่งแถวเสมอ) */
function upsertPendingRecord(record) {
    writePendingRecords([...readPendingRecords().filter(r => !sameHash(r.tx_hash, record.tx_hash)), record]);
}

function removePendingRecord(txHash) {
    writePendingRecords(readPendingRecords().filter(r => !sameHash(r.tx_hash, txHash)));
}

/*
 * tx ที่หน้านี้กำลังทำขั้นค่าธรรมเนียมอยู่ — ตัว flush ต้องข้าม
 * ไม่งั้นส่งบันทึกชั่วคราว (fee_collected:false) ไปก่อน แล้วบันทึกจริงโดน 422 ซ้ำ
 * อยู่ในหน่วยความจำของหน้าเท่านั้น: ปิดแท็บแล้วเปิดใหม่ = ชุดว่าง = แถวนั้นถูกส่งตามปกติ
 */
const inflightHashes = new Set();

/** ส่งบันทึกหนึ่งรายการ — true = เซิร์ฟเวอร์รับแล้ว (หรือปฏิเสธถาวร ไม่ต้องลองอีก) */
async function postSwapRecord(payload) {
    try {
        await axios.post('/api/v1/swap/execute', payload);
        return true;
    } catch (err) {
        return err?.response?.status === 422;
    }
}

/**
 * ลองส่งบันทึกที่ค้างของกระเป๋านี้อีกรอบ
 * @returns {Promise<number>} จำนวนที่ส่งสำเร็จ
 */
export async function flushPendingSwapRecords(walletAddress) {
    const wallet = String(walletAddress || '').toLowerCase();
    const pending = readPendingRecords();
    if (!wallet || !pending.length) return 0;

    const keep = [];
    let sent = 0;
    for (const record of pending) {
        if (String(record.wallet_address).toLowerCase() !== wallet || inflightHashes.has(String(record.tx_hash).toLowerCase())) {
            keep.push(record);
            continue;
        }
        if (await postSwapRecord(record)) sent += 1;
        else keep.push(record);
    }
    writePendingRecords(keep);

    return sent;
}

/**
 * Map raw ethers / router errors to clean, user-facing messages.
 * Never surface raw exception strings (project rule).
 */
function friendlyError(err) {
    // ข้อความผ่าน i18n — เดิมเป็นอังกฤษล้วน ผู้ใช้ภาษาไทยเห็น "Swap failed" ในหน้าไทย
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') return t('swapError.rejected');
    const msg = (err?.reason || err?.shortMessage || err?.message || '').toString().toLowerCase();
    if (msg.includes('no liquidity')) return t('swapError.noLiquidity');
    if (msg.includes('insufficient_output_amount')) return t('swapError.priceMovedSlippage');
    if (msg.includes('insufficient_a_amount') || msg.includes('insufficient_b_amount')) return t('swapError.priceMoved');
    if (msg.includes('expired')) return t('swapError.expired');
    if (msg.includes('transfer_from_failed') || msg.includes('transferfrom')) return t('swapError.transferFailed');
    if (msg.includes('insufficient')) return t('swapError.insufficient');
    if (msg.includes('missing revert data') || msg.includes('call_exception')) return t('swapError.onchainFailed');
    if (msg.includes('user rejected') || msg.includes('user denied')) return t('swapError.rejected');
    if (msg.includes('timeout') || msg.includes('timed out')) return t('swapError.timeout');
    return t('swapError.failed');
}

function friendlyThrow(message) {
    const err = new Error(message);
    err.isFriendly = true;   // ข้อความนี้แสดงต่อ user ได้เลย — อย่าให้ friendlyError ทับ
    return err;
}

/*
 * ที่อยู่กระเป๋าค่าธรรมเนียม — ต้องรู้ "ก่อน" ให้ผู้ใช้เซ็นอะไรเลย (approve ด้วย)
 * เดิมเช็กตอนจะส่งสวอป หลังผู้ใช้จ่ายค่าแก๊ส approve ไปแล้ว
 */
let feeCollectorCache = { address: null, at: 0 };

/** @internal ล้างแคชในเทสต์ */
export function _resetFeeCollectorCacheForTests() {
    feeCollectorCache = { address: null, at: 0 };
}

async function resolveFeeCollector() {
    if (feeCollectorCache.address && Date.now() - feeCollectorCache.at < FEE_COLLECTOR_TTL_MS) {
        return feeCollectorCache.address;
    }

    let address = null;
    let reached = false;
    try {
        const { data } = await axios.get('/api/v1/trading/fee-info', { params: { chain_id: SWAP_CHAIN_ID } });
        reached = true;
        address = data?.data?.fee_collector || null;
    } catch {
        // ไปลองทางสำรองด้านล่าง
    }

    /*
     * ทางสำรอง: /api/v1/fees (กลุ่ม public คนละตัวนับโควตา)
     * fee-info ไม่มีเลขกระเป๋า จึงนับโควตาต่อ IP — คนใช้ IP ร่วม (มือถือ/ออฟฟิศ) โดน 429 ง่าย
     * เดิมตกไปเป็น "ระบบสวอปปิดปรับปรุง" เฉยๆ ทั้งที่ระบบปกติดี
     */
    if (!address) {
        try {
            const { data } = await axios.get('/api/v1/fees');
            reached = true;
            address = data?.swap?.feeWallet || null;
        } catch {
            // ติดต่อไม่ได้ทั้งสองทาง
        }
    }

    // พิมพ์เล็กเสมอ — ค่าในหลังบ้านมี checksum ผิดได้ แล้ว ethers โยน "bad address checksum"
    address = typeof address === 'string' ? address.trim().toLowerCase() : null;
    if (!address || !/^0x[0-9a-f]{40}$/.test(address)) {
        // ติดต่อได้แต่ไม่มีที่อยู่ = แอดมินยังไม่ตั้งค่า · ติดต่อไม่ได้เลย = ให้ลองใหม่
        throw friendlyThrow(t(reached ? 'swapError.unavailable' : 'swapError.feeInfoUnavailable'));
    }

    feeCollectorCache = { address, at: Date.now() };
    return address;
}

/** ยอดดิบ (หน่วยเล็กสุด) บน BSC — null = อ่านไม่ได้ */
async function readRawBalance(tokenAddress, owner) {
    try {
        return await withBscReadFallback((provider) => (isNativeToken(tokenAddress)
            ? provider.getBalance(owner)
            : new Contract(tokenAddress, ERC20_ABI, provider).balanceOf(owner)));
    } catch {
        return null;
    }
}

/**
 * คำตอบของ /swap/quote ล้ม → ข้อความที่ตรงกับเหตุ
 * 404 = เชน/เหรียญ/คู่ถูกปิด (ด่านฉุกเฉินของแอดมิน) · อื่นๆ = ตรวจค่าธรรมเนียมไม่ได้ชั่วคราว
 */
function backendQuoteError(err) {
    const status = err?.response?.status;
    const code = err?.response?.data?.error?.code;
    if (status === 404 || ['INVALID_CHAIN', 'INVALID_TOKEN', 'PAIR_DISABLED'].includes(code)) {
        return t('swapError.pairDisabled');
    }
    return t('swapError.feeConfigUnavailable');
}

export function useSwap() {
    const isLoadingQuote = ref(false);
    const isExecuting = ref(false);
    const isApproving = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const txStatus = ref(null); // 'pending', 'confirmed', 'failed'
    // ขั้นที่กำลังรอผู้ใช้ใน executeSwap — 'idle' | 'swapping' | 'fee' | 'recording'
    // (ขั้น 'fee' คือป๊อปอัพที่สองในกระเป๋า ผู้ใช้ต้องรู้ว่าคืออะไร ไม่งั้นกดปฏิเสธเพราะนึกว่าแปลก)
    const stage = ref('idle');

    /**
     * Get a swap quote: real on-chain price from PancakeSwap (read via a BSC RPC)
     * plus the platform fee taken from the input side. Returns null (no quote) on
     * any failure — never a fabricated rate.
     */
    async function getQuote(fromToken, toToken, amount, opts = {}) {
        error.value = null;

        const fromDecimals = fromToken.decimals || 18;
        const toDecimals = toToken.decimals || 18;

        // จำนวนจากสตริงทศนิยมตรงๆ (ไม่ผ่าน float) — ดู toDecimalString
        let grossWei;
        try {
            grossWei = toUnits(amount, fromDecimals);
        } catch {
            grossWei = null;
        }
        if (grossWei === null || grossWei <= 0n) {
            return null;
        }
        const grossAmount = Number(formatUnits(grossWei, fromDecimals));

        isLoadingQuote.value = true;

        try {
            /*
             * 1) อัตราค่าธรรมเนียม / slippage จาก backend — FAIL-CLOSED
             *
             * ⚠️ เดิมคำขอนี้ล้มแล้วเงียบ ใช้ 0.3% เดาเอง แล้วส่งสวอปต่อ:
             *    - แอดมินปิดเชน/คู่ฉุกเฉิน (404) แต่สวอปยังลงเชนจริง แล้วบันทึกไม่ได้ตลอดไป
             *    - อัตราจริงไม่ใช่ 0.3% → เก็บผิดอัตรา แล้วเซิร์ฟเวอร์ตีตก FEE_MISMATCH
             *      (422 = ปฏิเสธถาวร) → ไม้หายจากประวัติ
             *    ไม่รู้อัตราจริง = ไม่มีราคา = ไม่ส่งธุรกรรม
             */
            let backendQuote = null;
            try {
                const { data } = await axios.get('/api/v1/swap/quote', {
                    params: {
                        from_token: fromToken.address,
                        to_token: toToken.address,
                        amount: toDecimalString(formatUnits(grossWei, fromDecimals)),
                        chain_id: SWAP_CHAIN_ID,
                    },
                });
                if (data?.success) backendQuote = data.data?.quote ?? null;
            } catch (apiErr) {
                error.value = backendQuoteError(apiErr);
                return null;
            }
            const backendRate = Number(backendQuote?.fee_rate);
            if (!backendQuote || !Number.isFinite(backendRate) || backendRate < 0) {
                error.value = t('swapError.feeConfigUnavailable');
                return null;
            }

            /*
             * ค่าธรรมเนียมแพลตฟอร์มถูกยกเว้น (จ่ายด้วยเครดิต TPIX ผ่านใบอนุญาตวางไม้แล้ว)
             * ห้ามหัก 0.3% ซ้ำจากเหรียญที่สวอป — ผู้ใช้จะโดนเก็บสองต่อ
             */
            const waived = opts.waiveFee === true;
            const feeRate = waived ? 0 : backendRate;
            /*
             * slippage ที่ผู้ใช้เลือก (ถ้าส่งมา) ต้องชนะค่าจาก backend
             * เดิม "ได้รับอย่างน้อย" บนจอคิดจากค่า backend แต่ minOut ที่ส่งเข้า router
             * ใช้ค่าที่ผู้ใช้เลือก — เลือก 3% จอบอก ×0.995 แต่บนเชนยอมรับ ×0.97
             */
            const chosen = Number(opts.slippage);
            const slippage = opts.slippage != null && Number.isFinite(chosen) && chosen > 0
                ? chosen
                : (Number(backendQuote.slippage) > 0 ? Number(backendQuote.slippage) : 0.5);

            // 2) ค่าธรรมเนียมหักจากเหรียญขาเข้า (BigInt ล้วน) — สวอปเฉพาะส่วนที่เหลือ
            const { feeWei, swapWei: amountInSwapWei } = splitPlatformFee(grossWei, feeRate);
            if (amountInSwapWei <= 0n) {
                error.value = t(feeWei > 0n ? 'swapError.tooSmallAfterFee' : 'swapError.tooSmall');
                return null;
            }
            const feeAmount = Number(formatUnits(feeWei, fromDecimals));
            const swapInput = Number(formatUnits(amountInSwapWei, fromDecimals));

            /*
             * 3) เส้นทาง: ตรง กับ ผ่าน WBNB — ขอราคาทั้งสองแล้วเลือกทางที่ได้มากกว่า
             *
             * ⚠️ เดิมบังคับผ่าน WBNB เสมอ (ทางตรงใช้เฉพาะตอนทางนั้นล้ม) = จ่ายค่า LP 0.25% สองต่อ
             *    และโดนผลกระทบราคาสองพูล ทั้งที่ BTCB/ETH/… มีพูลตรงกับ USDT อยู่แล้ว
             */
            const fromAddr = getRoutingAddress(fromToken.address);
            const toAddr = getRoutingAddress(toToken.address);
            const viaWbnb = fromAddr.toLowerCase() === WBNB_ADDRESS.toLowerCase()
                || toAddr.toLowerCase() === WBNB_ADDRESS.toLowerCase();
            const candidates = viaWbnb
                ? [[fromAddr, toAddr]]
                : [[fromAddr, toAddr], [fromAddr, WBNB_ADDRESS, toAddr]];

            // 4) ราคาจริงบนเชนจาก BSC node (ไม่ขึ้นกับเชนที่กระเป๋าอยู่) — มีโหนดสำรอง
            const results = await Promise.allSettled(candidates.map(p =>
                withBscReadFallback(provider => getAmountsOut(amountInSwapWei, p, provider))));
            let path = null;
            let amountOut = null;
            results.forEach((r, i) => {
                if (r.status === 'fulfilled' && r.value != null && r.value > 0n && (amountOut === null || r.value > amountOut)) {
                    amountOut = r.value;
                    path = candidates[i];
                }
            });
            // Never proceed without a real on-chain amount (no 1:1 fabrication).
            if (amountOut === null) {
                // ทุกทางล้มเพราะติดต่อโหนดไม่ได้ ≠ ไม่มีสภาพคล่อง — บอกให้ตรงกับที่เกิดจริง
                const unreachable = results.some(r => r.status === 'rejected' && isRpcUnreachable(r.reason));
                throw friendlyThrow(t(unreachable ? 'swapError.rpcUnavailable' : 'swapError.noLiquidity'));
            }

            /*
             * 5) ผลกระทบต่อราคาจริง — เทียบกับไม้จิ๋ว (0.1%) บนเส้นทางเดียวกัน
             * เดิมใช้ตัวเลขจาก backend (จำนวน × 0.001) ซึ่งไม่เกี่ยวกับพูลเลย
             * อ่านไม่ได้ = null ("ไม่รู้") ไม่ใช่ 0 ("ไม่กระทบ")
             */
            let priceImpact = null;
            const refIn = amountInSwapWei / 1000n;
            if (refIn > 0n) {
                try {
                    const refOut = await withBscReadFallback(provider => getAmountsOut(refIn, path, provider));
                    priceImpact = priceImpactPct(amountInSwapWei, amountOut, refIn, refOut);
                } catch {
                    priceImpact = null;
                }
            }

            // Fee is already deducted from the input, so the router output IS what
            // the user receives — no second haircut on the output side.
            const rawOutputAmount = parseFloat(formatUnits(amountOut, toDecimals));
            const netOutput = rawOutputAmount;
            const minimumReceived = netOutput * (1 - slippage / 100);
            const exchangeRate = netOutput / grossAmount; // effective rate per total token paid

            return {
                amountIn: grossAmount,        // total the user pays (swap + fee)
                swapInput,                    // amount actually routed through PancakeSwap
                amountOut: rawOutputAmount,
                netOutput,
                exchangeRate,
                feeRate,
                feeAmount,
                priceImpact,
                slippage,
                minimumReceived: Math.max(minimumReceived, 0),
                path,
                route: path.length === 3 ? 'wbnb' : 'direct',
                waived,
                rawAmountOut: amountOut,       // BigInt — router output for the swapped amount
                grossWei,                     // BigInt — exact wei the user pays (swap + fee)
                amountInSwapWei,              // BigInt — exact wei routed through the swap
                feeWei,                       // BigInt — reserved platform fee (input token)
                decimals: { from: fromDecimals, to: toDecimals },
            };
        } catch (err) {
            error.value = err?.isFriendly ? err.message : friendlyError(err);
            return null;
        } finally {
            isLoadingQuote.value = false;
        }
    }

    /**
     * Check if the router has sufficient allowance to spend the token.
     * Read against the BSC RPC so it is correct regardless of the wallet's chain.
     */
    async function checkAllowance(tokenAddress, amount, decimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return false;
        if (isNativeToken(tokenAddress)) return true;

        let needed;
        try {
            needed = toUnits(amount, decimals) ?? 0n;
        } catch {
            needed = 0n;
        }
        const owner = walletStore.address;
        const allowance = await withBscReadFallback(provider => getAllowance(
            tokenAddress,
            owner,
            PANCAKE_ROUTER_ADDRESS,
            provider,
        ));
        return allowance >= needed;
    }

    /**
     * ที่อยู่กระเป๋าค่าธรรมเนียมพร้อมไหม — เรียกก่อนขอให้ผู้ใช้ approve
     * @throws Error (isFriendly) ข้อความพร้อมแสดง
     */
    async function ensureFeeCollector() {
        await resolveFeeCollector();
        return true;
    }

    /**
     * Approve the router to spend tokens (must be on BSC — caller switches first).
     * รอให้ลงบล็อกก่อนคืนค่า (มีเพดานเวลา) — ผู้เรียกไม่ต้อง wait() ซ้ำ
     */
    async function approveToken(tokenAddress) {
        const walletStore = useWalletStore();
        const signer = walletStore.signer;
        if (!signer) throw new Error('Wallet not connected');

        isApproving.value = true;
        error.value = null;

        try {
            const contract = new Contract(tokenAddress, ERC20_ABI, signer);
            const tx = await contract.approve(PANCAKE_ROUTER_ADDRESS, MaxUint256);
            try {
                await tx.wait(1, APPROVE_WAIT_TIMEOUT_MS);
            } catch (waitErr) {
                // กด "Speed up" = ธุรกรรมใหม่แทนตัวเดิม — ถ้าไม่ได้ยกเลิก การอนุมัติก็ลงเชนแล้ว
                if (!(waitErr?.code === 'TRANSACTION_REPLACED' && !waitErr.cancelled)) throw waitErr;
            }
            return tx;
        } catch (err) {
            // ethers v6 ส่ง ACTION_REJECTED ไม่ใช่ 4001 — เดิมกดปฏิเสธแล้วขึ้นว่า "อนุมัติล้มเหลว"
            const rejected = err?.code === 4001 || err?.code === 'ACTION_REJECTED';
            error.value = rejected ? t('swapError.approveRejected') : t('swapError.approveFailed');
            throw err;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * Execute the swap on PancakeSwap (BSC). The caller must already have switched
     * the wallet to BSC. Swaps (amount - fee), then transfers the reserved fee.
     *
     * @param {object} [meta] ไม้จากกระดานเทรด:
     *   { pair: 'BTC/USDT', side: 'buy'|'sell', price, feeTicket?: uuid, onStage?: (stage, info) => void }
     *   pair/side/price ส่งต่อไปบันทึก ให้ประวัติและป้ายบนกราฟรู้ว่าเป็นคู่/ฝั่งไหน (หน้า Swap ไม่ต้องส่ง)
     *   feeTicket = ใบอนุญาตวางไม้ที่จ่ายค่าบริการด้วยเครดิต TPIX แล้ว (quote ต้องขอแบบ waiveFee)
     *   onStage = บอกหน้าจอว่าอยู่ขั้นไหน ('swapping' | 'fee' | 'recording')
     */
    async function executeSwap(fromToken, toToken, amount, quote, slippage = 0.5, meta = {}) {
        const walletStore = useWalletStore();

        /*
         * ⚠️ จับ signer + บัญชีไว้ตั้งแต่ต้น แล้วใช้ตัวนี้ทุกขั้น
         *    เดิมขั้นโอนค่าธรรมเนียมอ่าน walletStore.signer ใหม่ — ผู้ใช้สลับบัญชีใน MetaMask
         *    ระหว่างรอสวอป (_onAccountsChanged เปลี่ยน signer ทันที) → ค่าธรรมเนียมถูกเก็บ
         *    จากอีกบัญชีหนึ่งที่ไม่ได้เทรด ขณะที่ประวัติบันทึกเป็นของบัญชีแรก
         */
        const signer = walletStore.signer;
        const owner = walletStore.address;

        if (!signer || !owner) {
            error.value = t('swapError.connectFirst');
            throw friendlyThrow(error.value);
        }
        // Safety: never execute against a missing/ fabricated quote.
        if (!quote || quote.rawAmountOut == null || quote.amountInSwapWei == null) {
            error.value = t('swapError.quoteUnavailable');
            throw friendlyThrow(error.value);
        }
        // Defensive: swaps must run on BSC (caller switches before calling).
        if (walletStore.chainId !== SWAP_CHAIN_ID) {
            error.value = t('swapError.switchBsc');
            throw friendlyThrow(error.value);
        }

        const notify = (next, info = {}) => {
            stage.value = next;
            try { meta?.onStage?.(next, info); } catch { /* หน้าจอพังต้องไม่ทำให้ธุรกรรมพัง */ }
        };
        const signerIsOwner = async () => {
            try {
                return String(await signer.getAddress()).toLowerCase() === String(owner).toLowerCase();
            } catch {
                return false;
            }
        };

        isExecuting.value = true;
        error.value = null;
        txHash.value = null;
        txStatus.value = 'pending';

        try {
            // signer ต้องเป็นบัญชีเดียวกับที่ประวัติจะบันทึก (ผู้ใช้สลับบัญชีก่อนกด = ให้เริ่มใหม่)
            if (!(await signerIsOwner())) {
                throw friendlyThrow(t('swapError.accountChanged'));
            }

            const fromDecimals = quote.decimals?.from ?? (fromToken.decimals || 18);
            const toDecimals = quote.decimals?.to ?? (toToken.decimals || 18);
            let amountInSwap = quote.amountInSwapWei;      // BigInt — (amount - fee)
            let feeWei = quote.feeWei || 0n;
            let grossWei = quote.grossWei ?? (amountInSwap + feeWei);
            let rawAmountOut = quote.rawAmountOut;

            // FAIL-CLOSED: ต้องรู้ที่อยู่ fee collector ก่อนส่ง swap — ถ้าแพลตฟอร์ม
            // ยังไม่ตั้งค่า (หรือ backend ล่ม) ห้าม swap เพื่อไม่ให้เกิดเคส
            // "swap สำเร็จแต่เก็บ fee ไม่ได้" ซึ่งตรวจย้อนหลังไม่ได้
            let feeCollectorAddress = null;
            if (feeWei > 0n) {
                feeCollectorAddress = await resolveFeeCollector();
            }

            /*
             * ยอดที่จะจ่ายต้องไม่เกินยอดจริงบนเชน
             *
             * ⚠️ เดิมไม่เช็ก — พิมพ์เกินยอดนิดเดียว (หรือเศษจากการปัด) สวอปยังผ่านเพราะใช้แค่ 99.7%
             *    แต่ธุรกรรมค่าธรรมเนียม revert เงียบๆ → ไม่ได้ค่าธรรมเนียม
             *    เกินด้วยเศษ (≤ 0.01%) = ตัดให้พอดียอด · เกินจริง = ไม่ส่ง
             *    BNB ไม่ตัด — ต้องเหลือไว้จ่ายค่าแก๊สอยู่แล้ว
             * อ่านยอดไม่ได้ (RPC สะดุด) = ไม่บล็อก — กระเป๋าจะปฏิเสธเองถ้ายอดไม่พอจริง
             */
            const balance = await readRawBalance(fromToken.address, owner);
            if (balance !== null && grossWei > balance) {
                const excess = grossWei - balance;
                if (isNativeToken(fromToken.address) || excess * CLAMP_DUST_DIVISOR > grossWei) {
                    throw friendlyThrow(t('swapError.insufficient'));
                }
                const clamped = splitPlatformFee(balance, feeWei > 0n ? quote.feeRate : 0);
                if (clamped.swapWei <= 0n) throw friendlyThrow(t('swapError.tooSmall'));
                rawAmountOut = (rawAmountOut * clamped.swapWei) / amountInSwap;
                grossWei = balance;
                feeWei = clamped.feeWei;
                amountInSwap = clamped.swapWei;
            }
            const amountIn = Number(formatUnits(grossWei, fromDecimals));
            const feeAmount = Number(formatUnits(feeWei, fromDecimals));

            // minOut from SLIPPAGE ONLY — the fee never reduces the router output.
            const slipFactorBps = BigInt(Math.max(0, Math.floor((1 - slippage / 100) * 10000)));
            const minOut = (rawAmountOut * slipFactorBps) / 10000n;
            if (minOut <= 0n) {
                error.value = t('swapError.slippageTooHigh');
                throw friendlyThrow(error.value);
            }

            const path = quote.path;
            // ระยะเวลาจำกัดจากหน้าตั้งค่า (ไม่เคยตั้ง = 20 นาที)
            const deadline = Math.floor(Date.now() / 1000) + txDeadlineSeconds();
            const router = new Contract(PANCAKE_ROUTER_ADDRESS, PANCAKE_ROUTER_ABI, signer);

            notify('swapping');
            let tx;
            if (isNativeToken(fromToken.address)) {
                // BNB → Token
                tx = await router.swapExactETHForTokens(
                    minOut, path, owner, deadline,
                    { value: amountInSwap },
                );
            } else if (isNativeToken(toToken.address)) {
                // Token → BNB
                tx = await router.swapExactTokensForETH(
                    amountInSwap, minOut, path, owner, deadline,
                );
            } else {
                // Token → Token
                tx = await router.swapExactTokensForTokens(
                    amountInSwap, minOut, path, owner, deadline,
                );
            }

            txHash.value = tx.hash;
            let finalHash = tx.hash;
            let receipt;
            try {
                receipt = await tx.wait(1, SWAP_WAIT_TIMEOUT_MS);
            } catch (waitErr) {
                /*
                 * ⚠️ กด "Speed up" ใน MetaMask = ธุรกรรมใหม่มาแทนตัวเดิม
                 *    ethers v6 โยน TRANSACTION_REPLACED แม้แค่เปลี่ยนค่าแก๊ส (repriced)
                 *    เดิมหน้าจอขึ้น "สวอปล้มเหลว" ทั้งที่สวอปลงเชนแล้ว → ไม่เก็บค่าธรรมเนียม
                 *    ไม่บันทึกประวัติ และผู้ใช้กดซ้ำจนเทรดสองรอบ
                 */
                if (waitErr?.code === 'TRANSACTION_REPLACED' && !waitErr.cancelled && waitErr.receipt) {
                    receipt = waitErr.receipt;
                    finalHash = waitErr.replacement?.hash || waitErr.receipt.hash || tx.hash;
                    txHash.value = finalHash;
                } else if (waitErr?.code === 'TRANSACTION_REPLACED' && waitErr.cancelled) {
                    throw friendlyThrow(t('swapError.cancelledInWallet'));
                } else if (waitErr?.code === 'CALL_EXCEPTION') {
                    throw waitErr;   // revert จริงบนเชน — friendlyError แปลให้
                } else {
                    /*
                     * ส่งธุรกรรมไปแล้วแต่รอผลไม่สำเร็จ (RPC สะดุด/เน็ตหลุด) — อาจสำเร็จอยู่ก็ได้
                     * ห้ามบอกว่า "ล้มเหลว ลองใหม่" เฉยๆ ผู้ใช้จะกดซ้ำแล้วเทรดสองรอบ
                     * ให้ไปดูผลที่ BscScan ก่อน (ผู้เรียกแนบลิงก์จาก txUrl)
                     */
                    const pendingErr = friendlyThrow(t('swapError.unconfirmed'));
                    pendingErr.txUrl = getTxUrl(tx.hash, SWAP_CHAIN_ID);
                    pendingErr.txHash = tx.hash;
                    pendingErr.txSent = true;
                    throw pendingErr;
                }
            }
            if (receipt?.status !== 1) {
                throw friendlyThrow(t('swapError.onchainFailed'));
            }
            txStatus.value = 'confirmed';

            // ยอดที่ได้รับจริงจาก log ของใบเสร็จ — ต่างจาก quote ได้ถึงเท่า slippage
            const received = receivedFromReceipt(receipt, toToken, owner);
            const amountOut = received !== null ? Number(formatUnits(received, toDecimals)) : quote.netOutput;

            const record = {
                from_token: fromToken.address,
                to_token: toToken.address,
                from_amount: amountIn,
                to_amount: amountOut,
                fee_amount: 0,
                // บอกตรงๆ ว่ายังไม่ได้โอนค่าธรรมเนียม — เดิมส่ง 0 เฉยๆ แล้วโดน FEE_MISMATCH ไม้หายจากประวัติ
                fee_collected: !(feeWei > 0n),
                tx_hash: finalHash,
                chain_id: SWAP_CHAIN_ID,
                wallet_address: owner,
            };
            if (meta?.pair) record.pair = String(meta.pair).replace('-', '/');
            if (meta?.side === 'buy' || meta?.side === 'sell') {
                record.side = meta.side;
                // ราคาที่ได้จริง (quote ต่อ base) จากยอดจริง — ให้ป้ายบนกราฟวางตรงจุดที่เกิดจริง
                const fill = meta.side === 'buy' ? amountIn / amountOut : amountOut / amountIn;
                if (Number.isFinite(fill) && fill > 0) record.price = fill;
            }
            if (!record.price && Number(meta?.price) > 0) record.price = Number(meta.price);
            if (meta?.feeTicket) record.fee_ticket = String(meta.feeTicket);

            /*
             * ⚠️ เก็บบันทึกลงคิวในเครื่อง "ทันที" ที่สวอปลงเชน — ก่อนขั้นค่าธรรมเนียม
             *    เดิมบันทึกหลังขั้นค่าธรรมเนียมจบเท่านั้น: ผู้ใช้ปิดแท็บ/รีเฟรชระหว่างป๊อปอัพ
             *    ค่าธรรมเนียมยังเปิดอยู่ (หรือ WalletConnect ทำคำขอหาย) = ไม้จริงหายจากประวัติถาวร
             *    ตอนนี้แถวชั่วคราว (fee_collected:false) รออยู่ แล้วถูกแทนที่ด้วยผลจริงด้านล่าง
             */
            const hashKey = String(finalHash).toLowerCase();
            inflightHashes.add(hashKey);
            upsertPendingRecord(record);

            let feeCollected = false;
            let recorded = false;
            try {
                // Collect the RESERVED platform fee — the user still holds it because
                // we only swapped (amount - fee), so this transfer cannot run dry.
                if (feeWei > 0n && feeCollectorAddress) {
                    feeCollected = await collectPlatformFee({
                        walletStore, signer, signerIsOwner, notify,
                        token: fromToken, feeWei, feeAmount, collector: feeCollectorAddress,
                    });
                }
                if (feeCollected) {
                    record.fee_amount = feeAmount;
                    record.fee_collected = true;
                    upsertPendingRecord(record);
                }

                // Record on backend — ล้มแล้วค้างในคิวไว้ลองใหม่ (ดู flushPendingSwapRecords)
                notify('recording');
                recorded = await postSwapRecord(record);
                if (recorded) removePendingRecord(finalHash);
            } finally {
                inflightHashes.delete(hashKey);
            }

            return {
                hash: finalHash,
                status: txStatus.value,
                url: getTxUrl(finalHash, SWAP_CHAIN_ID),
                recorded,
                amountIn,
                amountOut,
                feeCollected,
                feeAmount: feeCollected ? feeAmount : 0,
            };
        } catch (err) {
            txStatus.value = 'failed';
            // error ที่เรา throw เองมีข้อความ user-facing อยู่แล้ว — ไม่ต้อง map ซ้ำ
            error.value = err.isFriendly ? err.message : friendlyError(err);
            throw err;
        } finally {
            isExecuting.value = false;
            stage.value = 'idle';
        }
    }

    /**
     * โอนค่าธรรมเนียมที่กันไว้ — คืน true ถ้าโอนออกไปแล้ว (ไม่ throw เด็ดขาด: สวอปสำเร็จไปแล้ว)
     *
     * ส่งไปแล้วแต่รอผลไม่ได้ (RPC สะดุด/หมดเวลา/กด Speed up) = นับว่าเก็บแล้ว
     * เดิมนับเป็น "ไม่ได้เก็บ" ทั้งที่เงินออกจากกระเป๋าไปแล้ว แอดมินเห็นธงผิด
     */
    async function collectPlatformFee({ walletStore, signer, signerIsOwner, notify, token, feeWei, feeAmount, collector }) {
        // ผู้ใช้สลับเชนระหว่างรอสวอป — signer ผูกกับ BSC ส่งต่อไม่ได้ (ไม่ใช่เหตุให้เก็บจากเชนอื่น)
        if (Number(walletStore.chainId) !== SWAP_CHAIN_ID) return false;
        // ตรวจซ้ำก่อนเงินออก: ต้องเป็นบัญชีเดียวกับที่สวอป
        if (!(await signerIsOwner())) return false;

        notify('fee', { amount: feeAmount, symbol: token.symbol || '' });

        let feeTx;
        try {
            feeTx = await withTimeout(isNativeToken(token.address)
                ? signer.sendTransaction({ to: collector, value: feeWei })
                : new Contract(token.address, ERC20_ABI, signer).transfer(collector, feeWei), FEE_PROMPT_TIMEOUT_MS);
        } catch (feeErr) {
            // ผู้ใช้ปฏิเสธ / ยอดไม่พอ / ไม่มีใครกดจนหมดเวลา — สวอปสำเร็จไปแล้ว ไม่บล็อก
            console.warn('Fee collection skipped (swap still succeeded):', feeErr?.code || feeErr?.message);
            return false;
        }

        try {
            const feeReceipt = await feeTx.wait(1, FEE_WAIT_TIMEOUT_MS);
            return feeReceipt?.status === 1;
        } catch (waitErr) {
            if (waitErr?.code === 'TRANSACTION_REPLACED') return !waitErr.cancelled;
            if (waitErr?.code === 'CALL_EXCEPTION') return false;
            return true;
        }
    }

    /**
     * Get a token balance for the connected wallet (always read from BSC).
     * Returns the full-precision formatted string.
     */
    async function getBalance(tokenAddress) {
        const walletStore = useWalletStore();
        const owner = walletStore.address;
        if (!owner) return '0';

        try {
            return await withBscReadFallback(provider => getTokenBalance(tokenAddress, owner, provider));
        } catch {
            return '0';
        }
    }

    /**
     * Reset swap state.
     */
    function reset() {
        error.value = null;
        txHash.value = null;
        txStatus.value = null;
        stage.value = 'idle';
    }

    return {
        isLoadingQuote,
        isExecuting,
        isApproving,
        error,
        txHash,
        txStatus,
        stage,
        getQuote,
        checkAllowance,
        ensureFeeCollector,
        approveToken,
        executeSwap,
        getBalance,
        reset,
    };
}
