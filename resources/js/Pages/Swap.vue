<script setup>
/**
 * TPIX TRADE - Swap Page
 * DEX token swap with real Web3 integration
 *
 * สองเครือข่าย (ผู้ใช้เลือกได้ที่หัวการ์ด):
 *  - TPIX Chain → พูล TPIX DEX ผ่าน useTpixDex (ทางเดียวกับ /trade) ค่าธรรมเนียม 0.3% อยู่ในพูล
 *  - BSC        → PancakeSwap ผ่าน useSwap — ทางเดิมทุกอย่าง ผูก chain id 56 ตรง ๆ
 * เปิดหน้ามาเป็น TPIX ก็ต่อเมื่อเซิร์ฟเวอร์ยืนยันว่า DEX พร้อม (fail-closed) ไม่งั้นเป็น BSC เหมือนเดิม
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useSwap } from '@/Composables/useSwap';
import { useTpixDex } from '@/Composables/useTpixDex';
import { usePlatformReadiness } from '@/Composables/usePlatformReadiness';
import { TPIX_DEX, loadDexConfig, isDexConfigured } from '@/Config/dexContracts';
import { getTxUrl, BSC_CHAIN_ID } from '@/utils/web3';
import { preferredSlippage, parseSlippage, SLIPPAGE_MIN, SLIPPAGE_MAX } from '@/utils/tradeSettings';
import {
    SWAP_NETWORKS,
    TPIX_CHAIN_ID,
    PRICE_IMPACT_BLOCK_PCT,
    chainIdForNetwork,
    resolveSwapNetwork,
    buildTpixTokenList,
    defaultTokenPair,
    sameToken,
    isPriceImpactBlocked,
    exceedsBalance,
} from '@/utils/swapNetwork';
import { useTranslation } from '@/Composables/useTranslation';
import WalletModal from '@/Components/Wallet/WalletModal.vue';

const walletStore = useWalletStore();
const swap = useSwap();
const dex = useTpixDex();
const { t } = useTranslation();

// swap บน BSC ต้องมี fee_collector_wallet ที่แอดมินตั้งไว้ ไม่งั้น API ตอบ 503
// เช็กตั้งแต่ตอนเข้าหน้า ดีกว่าปล่อยให้กรอกจนจบแล้วค่อยเจอ error
const readiness = usePlatformReadiness();

// Token lists (BSC mainnet addresses)
const popularTokens = ref([
    { symbol: 'BNB', name: 'BNB', address: '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE', decimals: 18, balance: '0.00' },
    { symbol: 'USDT', name: 'Tether', address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18, balance: '0.00' },
    { symbol: 'USDC', name: 'USD Coin', address: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', decimals: 18, balance: '0.00' },
    { symbol: 'ETH', name: 'Ethereum', address: '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', decimals: 18, balance: '0.00' },
    { symbol: 'BTC', name: 'Bitcoin', address: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', decimals: 18, balance: '0.00' },
    { symbol: 'SOL', name: 'Solana', address: '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF', decimals: 18, balance: '0.00' },
    { symbol: 'DOGE', name: 'Dogecoin', address: '0xbA2aE424d960c26247Dd6c32edC70B295c744C43', decimals: 8, balance: '0.00' },
    { symbol: 'CAKE', name: 'PancakeSwap', address: '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', decimals: 18, balance: '0.00' },
]);

// ── เครือข่าย (BSC / TPIX Chain) ────────────────────────────────────────────
/*
 * DEX บนเชน TPIX ใช้ได้ก็ต่อเมื่อเซิร์ฟเวอร์ยืนยันว่าสัญญามีโค้ดบนเชนจริง (isDexConfigured)
 * ระหว่างรอคำตอบ / ไม่พร้อม = BSC เสมอ หน้านี้ทำงานแบบเดิมทุกอย่าง
 */
const dexChecked = computed(() => TPIX_DEX.loaded);
const dexReady = computed(() => isDexConfigured());

/** เหรียญของโหมด TPIX — สร้างจากคู่บน DEX ที่เปิดอยู่ (ที่อยู่ + decimals จากเซิร์ฟเวอร์ ตรวจซ้ำบนเชน) */
const tpixTokens = ref([]);
const tpixPairsLoading = ref(false);
const tpixPairsFailed = ref(false);

/** เครือข่ายที่ผู้ใช้เลือกเอง — null = ยังไม่เลือก ให้ระบบเลือกตามความพร้อมของ DEX */
const requestedNetwork = ref(null);

const network = computed(() => resolveSwapNetwork({
    requested: requestedNetwork.value,
    dexReady: dexReady.value,
    tpixTokenCount: tpixTokens.value.length,
}));
const isTpixMode = computed(() => network.value === SWAP_NETWORKS.TPIX);
// BSC = 56 ตรง ๆ เสมอ (chainIdForNetwork) — ห้ามใช้เชนปริยายของระบบแทน
const activeChainId = computed(() => chainIdForNetwork(network.value));
const activeTokens = computed(() => (isTpixMode.value ? tpixTokens.value : popularTokens.value));
/** ตัวทำงานของเครือข่ายที่เลือก — ถือ error / txHash / สถานะกำลังทำงานของตัวเอง */
const engine = computed(() => (isTpixMode.value ? dex : swap));
/** เลือก TPIX แล้วแต่ยังไม่มีคู่ให้สวอป (หรือโหลดไม่สำเร็จ) — แสดงหน้าว่างแทนฟอร์ม */
const tpixEmpty = computed(() => isTpixMode.value && tpixTokens.value.length < 2);

/*
 * ด่านกระเป๋าค่าธรรมเนียม (fee_collector_wallet) เป็นของ BSC เท่านั้น
 * TPIX: ค่าธรรมเนียมอยู่ในพูล เซิร์ฟเวอร์บันทึกไม้จากใบเสร็จบนเชนโดยไม่ต้องใช้กระเป๋านี้ — ด่านของโหมดนี้คือ dexReady
 */
const swapBlockedReason = computed(() => (isTpixMode.value ? null : readiness.reasonFor('swap')));

// Swap state
const fromToken = ref(popularTokens.value[0]);
const toToken = ref(popularTokens.value[1]);
const fromAmount = ref('');
const toAmount = ref('');
// ค่าเริ่มต้นจากหน้าตั้งค่า (ถ้าเคยตั้ง) — เดิมตายตัว 0.5% ไม่ว่าผู้ใช้ตั้งไว้เท่าไร
const slippage = ref(preferredSlippage() ?? 0.5);
const showSlippageSettings = ref(false);
const showTokenSelector = ref(false);
const tokenSelectorMode = ref('from');
const isLoading = ref(false);
const isSwapInFlight = ref(false); // guards the whole swap incl. the chain-switch await
const isApproveInFlight = ref(false); // เหมือนกันสำหรับปุ่มอนุมัติ — ครอบช่วงรอสลับเชนด้วย
const showWalletModal = ref(false);
/** ข้อความจากการกระทำล่าสุดที่ไม่ได้มาจาก useSwap/useTpixDex (สลับเชนไม่สำเร็จ / ราคาอัปเดต) */
const actionNotice = ref(null); // { type: 'error' | 'info', text }

// Quote data
const currentQuote = ref(null);
/** เลขลำดับรอบอ่านยอดคงเหลือ (ดู refreshBalances) */
let balanceSeq = 0;

// Computed
const isWalletConnected = computed(() => walletStore.isConnected);

// ด่านยืนยันตัวตนของ "เทรด/สวอป" (แอดมินเปิด/ปิดได้) — สถานะมากับทุกหน้า (ใช้กับทั้งสองเครือข่าย)
const page = usePage();
const kycBlocked = computed(() => {
    const gate = page.props.kyc?.features?.trading;
    return !!gate?.required && !gate?.passed;
});
const isOnBSC = computed(() => walletStore.isBSC);
const isOnTpix = computed(() => Number(walletStore.chainId) === TPIX_CHAIN_ID);
const isOnActiveNetwork = computed(() => (isTpixMode.value ? isOnTpix.value : isOnBSC.value));

/** ระหว่างอนุมัติ/สวอป ห้ามสลับเครือข่าย — ธุรกรรมที่ค้างอยู่เป็นของเครือข่ายเดิม */
const networkLocked = computed(() => isSwapInFlight.value || isApproveInFlight.value
    || swap.isExecuting.value || swap.isApproving.value
    || dex.isExecuting.value || dex.isApproving.value);

// ── Slippage ────────────────────────────────────────────────────────────────
/*
 * เดิมช่องนี้รับอะไรก็ได้: 99% ผ่าน (เปิดให้บอทประกบ sandwich กินส่วนต่างเกือบหมด)
 * และช่องว่างกลายเป็น 0 → minOut = ราคาเต็ม → ธุรกรรม revert เสียค่าแก๊สฟรี
 * ใช้ parseSlippage ตัวเดียวกับหน้าตั้งค่า: นอกช่วง 0.01–50 = ใช้ไม่ได้ ห้ามส่งธุรกรรม
 */
const SLIPPAGE_WARN_ABOVE = 5;
/** slippage ที่ใช้ได้จริง (%) หรือ null ถ้าช่องว่าง/นอกช่วง */
const effectiveSlippage = computed(() => parseSlippage(slippage.value));
const slippageInvalid = computed(() => effectiveSlippage.value === null);
const slippageHigh = computed(() => (effectiveSlippage.value ?? 0) > SLIPPAGE_WARN_ABOVE);

let lastValidSlippage = effectiveSlippage.value ?? 0.5;
watch(effectiveSlippage, (value) => {
    if (value !== null) lastValidSlippage = value;
});

/**
 * ออกจากช่องกรอกแล้วค่ายังใช้ไม่ได้ → แก้ให้ทันที ไม่ปล่อยค่าเสียค้างไว้
 * เกินเพดาน = ตัดที่ 50 (ยังขึ้นคำเตือนสีเหลือง) / ว่าง-ศูนย์-ติดลบ = กลับไปค่าล่าสุดที่ใช้ได้
 * (ปัดขึ้นเป็น 0.01 แทบรับประกันว่า revert — ไม่ใช่สิ่งที่ผู้ใช้ตั้งใจ)
 */
function normalizeSlippageInput() {
    if (!slippageInvalid.value) return;
    const raw = Number(slippage.value);
    slippage.value = slippage.value !== '' && slippage.value !== null && Number.isFinite(raw) && raw > SLIPPAGE_MAX
        ? SLIPPAGE_MAX
        : lastValidSlippage;
}

const feeRate = computed(() => currentQuote.value?.feeRate ?? 0.3);
const feeAmount = computed(() => {
    if (currentQuote.value) return currentQuote.value.feeAmount.toFixed(6);
    const amount = parseFloat(fromAmount.value) || 0;
    return (amount * feeRate.value / 100).toFixed(6);
});
const exchangeRate = computed(() => currentQuote.value?.exchangeRate ?? null);

/*
 * ผลกระทบต่อราคา — BSC แสดงแบบเดิม · TPIX ไม่รู้ค่า (อ่านพูลไม่ได้) แสดง "—" และถือว่าบล็อก
 */
const priceImpactKnown = computed(() => {
    const v = currentQuote.value?.priceImpact;
    return typeof v === 'number' && Number.isFinite(v);
});
const priceImpact = computed(() => {
    if (isTpixMode.value && currentQuote.value && !priceImpactKnown.value) return '—';
    return currentQuote.value?.priceImpact?.toFixed(2) ?? '0';
});
const priceImpactClass = computed(() => {
    const v = parseFloat(priceImpact.value);
    if (!Number.isFinite(v)) return 'text-trading-red';
    return v > 3 ? 'text-trading-red' : v > 1 ? 'text-yellow-400' : 'text-trading-green';
});
/** TPIX: ไม้ที่ดันราคาพูลเกิน 10% (หรือไม่รู้ค่า) ห้ามส่ง — เพดานเดียวกับ /trade */
const priceImpactBlocked = computed(() => isTpixMode.value
    && !!currentQuote.value
    && isPriceImpactBlocked(currentQuote.value.priceImpact));

/** TPIX: จำนวนเกินยอดที่อ่านได้จากเชน — ปิดปุ่มก่อนเปิดป๊อปอัพกระเป๋าที่รู้อยู่แล้วว่าจะล้ม */
const insufficientBalance = computed(() => isTpixMode.value
    && isWalletConnected.value
    && exceedsBalance(fromAmount.value, fromToken.value?.balanceRaw));

/*
 * "ได้รับอย่างน้อย" คิดจาก slippage ที่ใช้จริงบนจอ — สูตรเดียวกับ minOut ที่ executeSwap ส่งเข้า router
 * (เดิมใช้ quote.minimumReceived ที่คิดจากค่าปริยายของ backend: เลือก 3% แต่จอบอก ×0.995)
 * TPIX: minOut ผูกกับ amounts ของ quote บนจอเช่นกัน (useTpixDex.executeTradeSwap)
 */
const minimumReceived = computed(() => {
    const slip = effectiveSlippage.value;
    if (slip === null) return '—';
    const out = currentQuote.value ? currentQuote.value.netOutput : (parseFloat(toAmount.value) || 0);
    return Math.max(out * (1 - slip / 100), 0).toFixed(6);
});

const needsApproval = ref(false);

const swapDisabled = computed(() => !fromAmount.value || !toAmount.value || slippageInvalid.value
    || isLoading.value || isSwapInFlight.value
    || engine.value.isExecuting.value || engine.value.isLoadingQuote.value
    || priceImpactBlocked.value || insufficientBalance.value);

const swapButtonLabel = computed(() => {
    if (engine.value.isExecuting.value || isSwapInFlight.value) return t('swapPage.swapping');
    if (isLoading.value || engine.value.isLoadingQuote.value) return t('swapPage.fetchingQuote');
    if (slippageInvalid.value) return t('swapPage.slippageInvalidShort');
    if (insufficientBalance.value) return t('swapPage.insufficientBalance', { symbol: fromToken.value.symbol });
    if (priceImpactBlocked.value) return t('swapPage.priceImpactTooHigh');
    return t('swapPage.swap');
});

// ── ตรวจเหรียญบนเชน TPIX ────────────────────────────────────────────────────
/*
 * decimals จากเซิร์ฟเวอร์ต้องตรงกับสัญญาบนเชน — ผิดหลักเดียว = จำนวนผิดเป็นล้านเท่า
 * ตรวจครั้งแรกตอนขอราคา แล้วจำไว้ (ที่อยู่ → decimals บนเชน) ตอนอนุมัติ/ส่งจึงไม่ต้องอ่านซ้ำ
 * อ่านไม่ได้ = ไม่จำ (ลองใหม่ได้) และไม่ให้เดินต่อ
 */
const chainDecimals = new Map();

/** @returns {Promise<true|string>} true = ผ่าน · string = คีย์ข้อความที่จะแสดง */
async function verifyTpixToken(token) {
    if (!token) return 'swapPage.tokenCheckFailed';
    if (token.native) return true;

    if (!chainDecimals.has(token.address)) {
        const meta = await dex.readTokenMeta(token.address);
        if (!meta || !Number.isInteger(Number(meta.decimals))) return 'swapPage.tokenCheckFailed';
        chainDecimals.set(token.address, Number(meta.decimals));
    }
    return chainDecimals.get(token.address) === Number(token.decimals) ? true : 'swapPage.tokenMismatch';
}

async function verifyTpixTokens(from, to) {
    const [a, b] = await Promise.all([verifyTpixToken(from), verifyTpixToken(to)]);
    return a !== true ? a : b;
}

// ── Quote ───────────────────────────────────────────────────────────────────
/*
 * ทุกคำขอราคามีเลขลำดับ (แบบ previewSeq ใน Trade.vue) — ผลที่กลับมาช้ากว่าคำขอใหม่ถูกทิ้ง
 * เดิมคำขอเก่าที่ตอบช้าทับผลของคำขอใหม่ได้ แล้ว executeSwap ใช้ quote.amountInSwapWei
 * → ผู้ใช้พิมพ์ 1 แต่ธุรกรรมส่ง 10 (หรือคนละเส้นทาง) จากราคาที่ไม่ได้อยู่บนจอ
 *
 * quote ทุกอันผูก key = เครือข่าย|เหรียญต้นทาง|ปลายทาง|จำนวน ไว้ด้วย ก่อนส่งธุรกรรมต้องตรงกับจอปัจจุบัน
 * (ใส่เครือข่ายใน key — quote ของ BSC ต้องไม่มีวันถูกส่งเข้า router ของ TPIX และกลับกัน)
 */
const QUOTE_DEBOUNCE_MS = 600;
/** ราคาเก่ากว่านี้ขอใหม่ก่อนส่งธุรกรรม — ราคาบน DEX ขยับเร็ว minOut จากราคาเก่าป้องกันได้น้อยลง */
const QUOTE_MAX_AGE_MS = 30000;
let quoteTimeout = null;
let quoteSeq = 0;

function quoteKeyFor(from, to, amount, net = network.value) {
    return [
        net,
        String(from?.address || '').toLowerCase(),
        String(to?.address || '').toLowerCase(),
        String(amount ?? '').trim(),
    ].join('|');
}

/** quote ที่ถืออยู่เป็นของเครือข่าย/คู่/จำนวนที่อยู่บนจอตอนนี้จริงไหม */
function quoteMatchesScreen() {
    return !!currentQuote.value
        && currentQuote.value.key === quoteKeyFor(fromToken.value, toToken.value, fromAmount.value);
}

function resetQuote() {
    toAmount.value = '';
    currentQuote.value = null;
    needsApproval.value = false;
}

/** ยกเลิกคำขอที่รออยู่ และทำให้ผลของคำขอที่กำลังวิ่งถูกทิ้งเมื่อกลับมา */
function invalidateQuotes() {
    if (quoteTimeout) {
        clearTimeout(quoteTimeout);
        quoteTimeout = null;
    }
    quoteSeq++;
    isLoading.value = false;
}

/** เก็บยอดคงเหลือ (เต็มความละเอียดสำหรับ MAX + ตัวเลขแสดงผล) */
function setBalance(token, balance) {
    token.balanceRaw = balance;                     // full precision — used by MAX
    token.balance = parseFloat(balance).toFixed(4); // display
}

async function runQuote(seq) {
    // จับเครือข่ายไว้ตั้งแต่ต้น — ผลที่กลับมาหลังผู้ใช้สลับเครือข่ายถูกทิ้งด้วยเลขลำดับ
    const net = network.value;
    const tpix = net === SWAP_NETWORKS.TPIX;
    const eng = tpix ? dex : swap;
    const from = fromToken.value;
    const to = toToken.value;
    const amountText = fromAmount.value;
    const key = quoteKeyFor(from, to, amountText, net);

    try {
        let quote;
        if (tpix) {
            // decimals ต้องตรงกับบนเชนก่อนจะเชื่อตัวเลขใด ๆ
            const check = await verifyTpixTokens(from, to);
            if (seq !== quoteSeq) return;
            if (check !== true) {
                actionNotice.value = { type: 'error', text: t(check) };
                toAmount.value = '';
                currentQuote.value = null;
                return;
            }

            // ยอดของเหรียญต้นทางอ่านพร้อมราคา (RPC เดียวกัน) — ยอดที่ใช้ตัดสิน "ไม่พอ" สดเท่ากับราคาเสมอ
            const [q, balance] = await Promise.all([
                dex.getTradeQuote(from, to, parseFloat(amountText), effectiveSlippage.value ?? lastValidSlippage),
                isWalletConnected.value ? dex.getBalance(from.address) : Promise.resolve(null),
            ]);
            quote = q;
            if (seq === quoteSeq && balance !== null) setBalance(from, balance);
        } else {
            // ส่ง slippage ของหน้านี้ไปด้วย — ไม่งั้น quote คิดจากค่าปริยายของ backend
            quote = await swap.getQuote(from, to, parseFloat(amountText), {
                slippage: effectiveSlippage.value ?? undefined,
            });
        }

        if (seq !== quoteSeq) {
            // ผลเก่าที่ล้มเหลวอาจเขียน error ทับหลังคำขอใหม่สำเร็จไปแล้ว — ล้างให้ตรงกับจอ
            if (!quote && quoteMatchesScreen()) eng.error.value = null;
            return;
        }

        if (!quote) {
            toAmount.value = '';
            currentQuote.value = null;
            return;
        }

        eng.error.value = null;
        currentQuote.value = { ...quote, key, quotedAt: Date.now() };
        toAmount.value = quote.netOutput.toFixed(6);

        // Check if approval is needed (ของเหรียญ/จำนวนเดียวกับ quote นี้)
        if (isWalletConnected.value) {
            const needs = tpix
                ? await dex.needsApproval(from.address, amountText, from.decimals)
                : !(await swap.checkAllowance(from.address, amountText, from.decimals));
            if (seq !== quoteSeq) return;
            needsApproval.value = needs;
        } else {
            needsApproval.value = false;
        }
    } catch (err) {
        if (seq !== quoteSeq) return;
        console.warn('Quote error:', err?.message);
        toAmount.value = '';
        currentQuote.value = null;
    } finally {
        if (seq === quoteSeq) isLoading.value = false;
    }
}

/** ขอราคาใหม่แบบหน่วงเวลา (พิมพ์จำนวน / เปลี่ยน slippage / เชื่อมกระเป๋า) */
function scheduleQuote(delay = QUOTE_DEBOUNCE_MS) {
    invalidateQuotes();
    actionNotice.value = null;

    const amount = parseFloat(fromAmount.value);
    if (!fromAmount.value || !(amount > 0) || tpixEmpty.value) {
        resetQuote();
        return;
    }

    const seq = quoteSeq;
    isLoading.value = true;
    quoteTimeout = setTimeout(() => {
        quoteTimeout = null;
        runQuote(seq);
    }, delay);
}

/** ขอราคาใหม่ทันที (ตอนกดสวอปแล้วราคาที่ถืออยู่เก่า/ไม่ตรงจอ) — คืน quote ที่ตรงจอ หรือ null */
async function requoteNow() {
    invalidateQuotes();
    const seq = quoteSeq;
    isLoading.value = true;
    await runQuote(seq);
    return seq === quoteSeq && quoteMatchesScreen() ? currentQuote.value : null;
}

/**
 * ผู้ใช้เริ่มกรอก/เลือกเหรียญแล้ว — ตรึงเครือข่ายที่เห็นอยู่
 * กันฟอร์มถูกล้างกลางทางเพราะคำตอบ DEX config มาถึงทีหลังแล้วสลับเครือข่ายให้เอง
 */
function pinNetwork() {
    if (requestedNetwork.value === null) requestedNetwork.value = network.value;
}

watch(fromAmount, (value) => {
    if (value) pinNetwork();
    scheduleQuote();
});

// เปลี่ยน slippage → ขอราคาใหม่ (หน่วงเวลา) ให้ตัวเลขบนจอมาจากค่าเดียวกับที่จะส่งจริง
watch(effectiveSlippage, (value, previous) => {
    if (value === previous || value === null) return;
    if (fromAmount.value && parseFloat(fromAmount.value) > 0) scheduleQuote();
});

// Cleanup debounce timeout on unmount to prevent memory leak
onUnmounted(() => {
    invalidateQuotes();
    balanceSeq++;
});

// Watch wallet connection to refresh balances
watch(() => walletStore.isConnected, async (connected) => {
    if (connected) {
        await refreshBalances();
    } else {
        balanceSeq++;   // ยอดที่กำลังอ่านอยู่เป็นของกระเป๋าที่เพิ่งตัดการเชื่อม — ทิ้ง
        [...popularTokens.value, ...tpixTokens.value].forEach((token) => {
            token.balance = '0.00';
            token.balanceRaw = '0';
        });
    }
});

// สลับกระเป๋า → allowance/ยอดที่เช็กไว้เป็นของใบเก่า ต้องเช็กใหม่ (ไม่งั้นข้ามขั้นอนุมัติแล้ว revert)
watch(() => walletStore.address, (address, previous) => {
    if (!address || address === previous) return;
    if (previous) refreshBalances();
    if (fromAmount.value && parseFloat(fromAmount.value) > 0) scheduleQuote(0);
});

// Watch token changes to refresh quote
watch([fromToken, toToken], () => {
    invalidateQuotes();
    fromAmount.value = '';
    resetQuote();
});

/**
 * เปลี่ยนเครือข่าย (ผู้ใช้กดเอง / DEX พร้อม / รายการเหรียญ TPIX โหลดใหม่)
 * ล้างทุกอย่างของเครือข่ายเก่า: ราคาที่รออยู่ · จำนวน · ข้อความ error · ลิงก์ธุรกรรม
 * แล้วตั้งคู่เริ่มต้นของเครือข่ายใหม่ (TPIX: TPIX → USDT · BSC: BNB → USDT เหมือนเดิม)
 */
function applyNetworkDefaults() {
    invalidateQuotes();
    actionNotice.value = null;
    for (const eng of [swap, dex]) {
        eng.error.value = null;
        eng.txHash.value = null;
        eng.txStatus.value = null;
    }

    if (isTpixMode.value) {
        const pair = defaultTokenPair(tpixTokens.value);
        if (pair) {
            fromToken.value = pair.from;
            toToken.value = pair.to;
        }
    } else {
        fromToken.value = popularTokens.value[0];
        toToken.value = popularTokens.value[1];
    }

    fromAmount.value = '';
    resetQuote();
    refreshBalances();
}

watch([network, tpixTokens], ([net], [previousNet]) => {
    // รายการเหรียญ TPIX เปลี่ยนขณะอยู่บน BSC ไม่ต้องทำอะไร
    if (net !== previousNet || net === SWAP_NETWORKS.TPIX) applyNetworkDefaults();
});

/** ผู้ใช้กดเลือกเครือข่าย — TPIX กดได้เมื่อ DEX พร้อมเท่านั้น */
function selectNetwork(net) {
    if (networkLocked.value) return;
    if (net === SWAP_NETWORKS.TPIX && !dexReady.value) return;
    requestedNetwork.value = net;
    if (net === SWAP_NETWORKS.TPIX && tpixTokens.value.length < 2 && !tpixPairsLoading.value) {
        loadTpixPairs();
    }
}

/** คู่บน TPIX DEX → รายการเหรียญ (เรียกซ้ำได้จากปุ่มลองใหม่) */
async function loadTpixPairs() {
    if (!dexReady.value || tpixPairsLoading.value) return;
    tpixPairsLoading.value = true;
    tpixPairsFailed.value = false;
    try {
        const { data } = await axios.get('/api/v1/dex/pairs');
        if (data?.success) {
            tpixTokens.value = buildTpixTokenList(data.data);
        } else {
            tpixPairsFailed.value = true;
        }
    } catch {
        tpixPairsFailed.value = true;
    } finally {
        tpixPairsLoading.value = false;
    }
}

const swapTokens = () => {
    pinNetwork();
    const temp = fromToken.value;
    fromToken.value = toToken.value;
    toToken.value = temp;
    invalidateQuotes();
    fromAmount.value = '';
    resetQuote();
};

const openTokenSelector = (mode) => {
    tokenSelectorMode.value = mode;
    showTokenSelector.value = true;
};

// เทียบด้วยที่อยู่ ไม่ใช่สัญลักษณ์ — บนเชน TPIX สัญลักษณ์ซ้ำกันได้
const selectToken = (token) => {
    pinNetwork();
    if (tokenSelectorMode.value === 'from') {
        if (sameToken(token, toToken.value)) swapTokens();
        else fromToken.value = token;
    } else {
        if (sameToken(token, fromToken.value)) swapTokens();
        else toToken.value = token;
    }
    showTokenSelector.value = false;
    invalidateQuotes();
    fromAmount.value = '';
    resetQuote();
};

const slippageOptions = [0.1, 0.5, 1.0, 3.0];

/*
 * ยอดคงเหลือของรายการเหรียญที่อยู่บนจอ
 * BSC อ่านจาก BSC RPC (useSwap) · TPIX อ่านจาก RPC ของเชน TPIX (useTpixDex) — ถูกต้องไม่ว่ากระเป๋าอยู่เชนไหน
 * balanceSeq กันผลของรอบเก่า (เครือข่าย/กระเป๋าเดิม) มาเขียนทับรอบใหม่
 */
async function refreshBalances() {
    if (!walletStore.isConnected) return;
    const seq = ++balanceSeq;
    const tpix = isTpixMode.value;
    const tokens = [...(tpix ? tpixTokens.value : popularTokens.value)];
    for (const token of tokens) {
        try {
            const balance = await (tpix ? dex.getBalance(token.address) : swap.getBalance(token.address));
            if (seq !== balanceSeq) return;
            setBalance(token, balance);
        } catch {
            if (seq !== balanceSeq) return;
            token.balanceRaw = '0';
            token.balance = '0.00';
        }
    }
}

/**
 * สลับกระเป๋าไป BSC ถ้ายังไม่อยู่ — ใช้ทั้งปุ่มอนุมัติและปุ่มสวอป
 *
 * ระบุ 56 ตรง ๆ — สวอปหน้านี้วิ่งบน PancakeSwap ซึ่งอยู่บน BSC เท่านั้น
 * (เชนปริยายของระบบเป็น TPIX แล้ว การเรียกแบบไม่ใส่พารามิเตอร์จะพาไปผิดเชน)
 *
 * @returns {Promise<boolean>} false = สลับไม่สำเร็จ (ข้อความขึ้นใน actionNotice แล้ว)
 */
async function ensureOnBsc() {
    if (isOnBSC.value) return true;

    try {
        await walletStore.switchChain(BSC_CHAIN_ID);
    } catch (err) {
        let key = 'swapPage.switchFailed';
        if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') key = 'swapPage.switchCancelled';
        else if (err?.message === 'EMBEDDED_WALLET_SINGLE_CHAIN') key = 'swapPage.embeddedNoBsc';
        actionNotice.value = { type: 'error', text: t(key) };
        return false;
    }

    // กันกระเป๋าที่ตอบว่าสำเร็จแต่ยังอยู่เชนเดิม — ห้ามเดินต่อบนเชนที่ผิด
    if (!isOnBSC.value) {
        actionNotice.value = { type: 'error', text: t('swapPage.switchFailed') };
        return false;
    }
    return true;
}

/**
 * สลับกระเป๋าไป TPIX Chain (4289) ถ้ายังไม่อยู่ — คู่กับ ensureOnBsc
 * กระเป๋าฝัง (TPIX Wallet) อยู่บน TPIX อยู่แล้ว ไม่มีป๊อปอัพ
 */
async function ensureOnTpix() {
    if (isOnTpix.value) return true;

    try {
        await walletStore.switchChain(TPIX_CHAIN_ID);
    } catch (err) {
        const key = err?.code === 4001 || err?.code === 'ACTION_REJECTED'
            ? 'swapPage.tpixSwitchCancelled'
            : 'swapPage.tpixSwitchFailed';
        actionNotice.value = { type: 'error', text: t(key) };
        return false;
    }

    if (!isOnTpix.value) {
        actionNotice.value = { type: 'error', text: t('swapPage.tpixSwitchFailed') };
        return false;
    }
    return true;
}

/** สลับไปเครือข่ายที่เลือกบนจอ (ปุ่ม "สลับ" ในแถบเตือน) */
function ensureOnActiveNetwork() {
    return isTpixMode.value ? ensureOnTpix() : ensureOnBsc();
}

// Handle approval
async function handleApprove() {
    // กันกดรัวระหว่างรอสลับเชน/เซ็น
    if (isApproveInFlight.value || swap.isApproving.value || dex.isApproving.value) return;

    if (!isWalletConnected.value) {
        showWalletModal.value = true;
        return;
    }

    const tpix = isTpixMode.value;
    isApproveInFlight.value = true;
    actionNotice.value = null;
    try {
        /*
         * ต้องอยู่บนเชนของเครือข่ายที่เลือกก่อนอนุมัติ — เดิมปุ่มนี้ไม่สลับเชน (ปุ่มสวอปสลับ)
         * อนุมัติเลยไปเกิดบนเชนที่กระเป๋าอยู่ → allowance บนเชนที่จะสวอปยังเป็นศูนย์
         * แล้วสวอป revert ทั้งที่ผู้ใช้เพิ่งกดอนุมัติไป
         */
        if (!(await (tpix ? ensureOnTpix() : ensureOnBsc()))) return;

        const token = fromToken.value;
        const approvedAddress = token.address;

        if (tpix) {
            const check = await verifyTpixToken(token);
            if (check !== true) {
                actionNotice.value = { type: 'error', text: t(check) };
                return;
            }

            // อนุมัติเท่าจำนวนที่จะสวอปพอดี (แบบเดียวกับ /trade) — ไม่เปิดวงเงินไม่จำกัดให้ router
            const amountText = fromAmount.value;
            const ok = await dex.approveToken(approvedAddress, amountText, token.decimals);
            if (!ok) return;   // dex.error มีข้อความพร้อมแสดงแล้ว (รวมกรณีกดปฏิเสธในกระเป๋า)

            // ผู้ใช้เปลี่ยนเหรียญ/จำนวนระหว่างรอเซ็น — วงเงินที่อนุมัติอาจไม่พอ ต้องเช็กใหม่
            if (sameToken(fromToken.value, token) && fromAmount.value === amountText) {
                needsApproval.value = false;
            } else if (fromAmount.value && parseFloat(fromAmount.value) > 0) {
                scheduleQuote(0);
            }
            return;
        }

        await swap.approveToken(approvedAddress);

        // ผู้ใช้เปลี่ยนเหรียญระหว่างรอเซ็น/รอบล็อก — ผลนี้เป็นของเหรียญเก่า ห้ามไปปิดสถานะของเหรียญใหม่
        if (fromToken.value.address === approvedAddress) {
            needsApproval.value = false;
        } else if (fromAmount.value && parseFloat(fromAmount.value) > 0) {
            scheduleQuote(0);   // เช็ก allowance ของเหรียญปัจจุบันใหม่
        }
    } catch (err) {
        // Error is already set in swap composable
    } finally {
        isApproveInFlight.value = false;
    }
}

// Execute the swap
async function executeSwap() {
    // Re-entrancy / double-tap guard — covers the chain-switch await window too,
    // so a second tap during switchChain() can't fire a second transaction.
    if (isSwapInFlight.value || engine.value.isExecuting.value || isLoading.value || engine.value.isLoadingQuote.value) return;
    if (!fromAmount.value || !toAmount.value || !currentQuote.value) return;

    if (!isWalletConnected.value) {
        showWalletModal.value = true;
        return;
    }

    // ยังไม่ผ่านด่านยืนยันตัวตนของการเทรด — ห้ามปล่อยให้เงินออกแล้วบันทึกไม่ได้ (ปุ่มเปลี่ยนแล้ว — กันอีกชั้น)
    if (kycBlocked.value) {
        router.visit('/kyc');
        return;
    }

    // slippage เสีย = ห้ามส่งธุรกรรมเด็ดขาด (ปุ่มถูกปิดอยู่แล้ว — กันอีกชั้น)
    if (slippageInvalid.value) {
        showSlippageSettings.value = true;
        return;
    }

    // TPIX: ยอดไม่พอ / ราคาขยับแรงเกิน (ปุ่มถูกปิดอยู่แล้ว — กันอีกชั้น)
    if (insufficientBalance.value || priceImpactBlocked.value) return;

    const net = network.value;
    const tpix = net === SWAP_NETWORKS.TPIX;

    isSwapInFlight.value = true;
    actionNotice.value = null;
    try {
        // สลับเชนก่อนเสมอ — BSC = 56 · TPIX = 4289
        if (!(await (tpix ? ensureOnTpix() : ensureOnBsc()))) return;

        /*
         * ราคาที่จะส่งเข้า router ต้องเป็นของจำนวน/คู่ที่อยู่บนจอ "ตอนนี้"
         * ระหว่างรอสลับเชนผู้ใช้ยังพิมพ์จำนวนใหม่ได้ — ถ้าไม่ตรงจอ ขอราคาใหม่แล้วให้ดูก่อนกดอีกครั้ง
         */
        if (!quoteMatchesScreen()) {
            await requoteNow();
            actionNotice.value = { type: 'info', text: t('swapPage.quoteRefreshed') };
            return;
        }

        // ราคาเก่าเกินไป → ขอใหม่ ถ้าได้น้อยกว่า "ได้รับอย่างน้อย" ที่ผู้ใช้เห็นตอนกด ให้ดูก่อน ไม่ส่งเลย
        if (Date.now() - currentQuote.value.quotedAt > QUOTE_MAX_AGE_MS) {
            const minSeen = currentQuote.value.netOutput * (1 - effectiveSlippage.value / 100);
            const fresh = await requoteNow();
            if (!fresh || needsApproval.value) return;   // ไม่มีราคา (error ขึ้นแล้ว) / ต้องอนุมัติก่อน
            if (fresh.netOutput < minSeen) {
                actionNotice.value = { type: 'info', text: t('swapPage.quoteRefreshed') };
                return;
            }
        }

        if (tpix) {
            // decimals ตรวจไว้แล้วตอนขอราคา (จำไว้) — เรียกซ้ำกันกรณีเหรียญถูกเปลี่ยนระหว่างทาง
            const check = await verifyTpixTokens(fromToken.value, toToken.value);
            if (check !== true) {
                actionNotice.value = { type: 'error', text: t(check) };
                return;
            }
            if (needsApproval.value) return;   // ปุ่มเปลี่ยนเป็น "อนุมัติ" แล้ว
        }

        // เช็กรอบสุดท้ายแบบไม่มี await คั่น — สิ่งที่ส่งต้องเป็นสิ่งที่อยู่บนจอเป๊ะ
        const slip = effectiveSlippage.value;
        if (slip === null || network.value !== net || !quoteMatchesScreen()) return;

        if (tpix) {
            const quote = currentQuote.value;
            // ราคาใหม่ที่เพิ่งขอมาอาจดันพูลแรงขึ้น / ยอดอาจลดลง — ตัดสินจากตัวที่จะส่งจริง
            if (isPriceImpactBlocked(quote.priceImpact)) return;
            if (exceedsBalance(fromAmount.value, fromToken.value.balanceRaw)) return;

            // minOut ผูกกับ amounts ของ quote บนจอ · บันทึกประวัติ (fee_amount 0 — ค่าธรรมเนียมอยู่ในพูล) ให้แล้ว
            await dex.executeTradeSwap(
                fromToken.value,
                toToken.value,
                parseFloat(fromAmount.value),
                quote,
                slip,
            );
        } else {
            await swap.executeSwap(
                fromToken.value,
                toToken.value,
                parseFloat(fromAmount.value),
                currentQuote.value,
                slip,
            );
        }

        // Refresh balances after swap, then reset the form
        await refreshBalances();
        invalidateQuotes();
        fromAmount.value = '';
        resetQuote();
    } catch (err) {
        // Error is already set in the swap composable (useSwap / useTpixDex)
    } finally {
        isSwapInFlight.value = false;
    }
}

// Set max amount from balance
function setMaxAmount() {
    const raw = String(fromToken.value.balanceRaw ?? fromToken.value.balance ?? '0');
    if (parseFloat(raw) <= 0) return;

    // TRUNCATE (never round) the full-precision balance string so MAX is always
    // <= the real on-chain balance — avoids both TRANSFER_FROM_FAILED and the
    // fee-leg reverting by a rounding wei, even for balances ending in many 9s.
    const dec = Math.min(8, fromToken.value.decimals || 18);
    const [intPart, fracPart = ''] = raw.split('.');
    const truncated = dec > 0 && fracPart ? `${intPart}.${fracPart.slice(0, dec)}` : intPart;

    // กันค่าแก๊สเฉพาะ BNB บน BSC — เชน TPIX ค่าแก๊สเป็นศูนย์ (gasPrice 0) ใช้ได้เต็มยอด
    if (!isTpixMode.value && fromToken.value.symbol === 'BNB') {
        // Reserve a little BNB for gas (covers both the swap and the fee transfer).
        fromAmount.value = Math.max(parseFloat(truncated) - 0.005, 0).toString();
    } else {
        fromAmount.value = truncated;
    }
}

/** DEX config → (ถ้าพร้อม) รายการเหรียญ TPIX — ผลของสองขั้นนี้ตัดสินว่าเปิดหน้ามาเป็นเครือข่ายไหน */
async function initTpixNetwork() {
    await loadDexConfig();
    if (dexReady.value) await loadTpixPairs();
}

// Load balances on mount if wallet is connected
onMounted(async () => {
    // ถามก่อนว่าบริการเปิดจริงไหม — ไม่รอผลนี้ก่อนโหลดยอด เพราะเป็นคนละเรื่องกัน
    readiness.load();
    initTpixNetwork();

    if (walletStore.isConnected) {
        await refreshBalances();
    }
});
</script>

<template>
    <Head :title="t('swapPage.title')" />

    <AppLayout :hide-sidebar="true">
        <div class="flex items-center justify-center min-h-[calc(100vh-160px)] px-4 py-6">
            <div class="w-full max-w-md">

                <!-- Network Warning -->
                <div v-if="isWalletConnected && !isOnActiveNetwork && !tpixEmpty" data-test="network-warning" class="mb-4 p-3 rounded-xl bg-yellow-500/10 border border-yellow-500/30 text-yellow-400 text-sm flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <span>{{ isTpixMode ? t('swapPage.switchPromptTpix') : t('swapPage.switchPrompt') }}</span>
                    <!-- ผ่าน ensureOnBsc/ensureOnTpix — ผู้ใช้กดยกเลิกในกระเป๋าต้องได้ข้อความ ไม่ใช่ promise reject เงียบๆ -->
                    <button type="button" @click="ensureOnActiveNetwork" class="ml-auto px-3 py-1 rounded-lg bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 text-xs font-medium transition-colors">
                        {{ t('swapPage.switch') }}
                    </button>
                </div>

                <!-- Main Swap Card -->
                <div class="relative">
                    <!-- Glow effect behind card -->
                    <div class="absolute -inset-1 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-3xl blur-xl opacity-60"></div>

                    <div class="relative glass-brand p-5 rounded-2xl">
                        <!-- Header -->
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-lg font-bold text-white">{{ t('swapPage.title') }}</h2>
                            <button
                                type="button"
                                :aria-label="t('swapPage.settings')"
                                :title="t('swapPage.settings')"
                                @click="showSlippageSettings = !showSlippageSettings"
                                class="p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                                :class="{ 'text-primary-400 bg-primary-500/10': showSlippageSettings }"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </div>

                        <!-- Network selector — TPIX กดได้เมื่อ DEX พร้อมเท่านั้น · ล็อกระหว่างอนุมัติ/สวอป -->
                        <div class="mb-4" data-test="network-selector">
                            <div class="flex items-center gap-1 p-1 rounded-xl bg-dark-800/60 border border-white/5" role="group" :aria-label="t('swapPage.network')">
                                <button
                                    type="button"
                                    data-test="network-tpix"
                                    :aria-pressed="isTpixMode"
                                    :disabled="!dexReady || networkLocked"
                                    @click="selectNetwork(SWAP_NETWORKS.TPIX)"
                                    class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-semibold border transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="isTpixMode ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'text-dark-400 hover:text-white border-transparent'"
                                >
                                    <CoinIcon symbol="TPIX" size="xs" />
                                    {{ t('swapPage.networkTpix') }}
                                </button>
                                <button
                                    type="button"
                                    data-test="network-bsc"
                                    :aria-pressed="!isTpixMode"
                                    :disabled="networkLocked"
                                    @click="selectNetwork(SWAP_NETWORKS.BSC)"
                                    class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-semibold border transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="!isTpixMode ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'text-dark-400 hover:text-white border-transparent'"
                                >
                                    <CoinIcon symbol="BNB" size="xs" />
                                    {{ t('swapPage.networkBsc') }}
                                </button>
                            </div>
                            <p v-if="!dexChecked" class="mt-1.5 text-[11px] text-dark-500" data-test="tpix-checking">{{ t('swapPage.tpixChecking') }}</p>
                            <p v-else-if="!dexReady" class="mt-1.5 text-[11px] text-dark-400" data-test="tpix-coming-soon">{{ t('swapPage.tpixComingSoon') }}</p>
                        </div>

                        <!-- Slippage Settings -->
                        <Transition
                            enter-active-class="transition ease-out duration-200"
                            enter-from-class="opacity-0 -translate-y-2"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-active-class="transition ease-in duration-150"
                            leave-from-class="opacity-100 translate-y-0"
                            leave-to-class="opacity-0 -translate-y-2"
                        >
                            <div v-if="showSlippageSettings" class="mb-4 p-3 rounded-xl bg-dark-800/60 border border-white/5">
                                <p class="text-xs font-medium text-dark-400 mb-2">{{ t('swap.slippage') }}</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        v-for="opt in slippageOptions"
                                        :key="opt"
                                        type="button"
                                        @click="slippage = opt"
                                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                                        :class="slippage === opt ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30' : 'bg-dark-700/50 text-dark-400 hover:text-white border border-transparent'"
                                    >
                                        {{ opt }}%
                                    </button>
                                    <div class="flex items-center gap-1 ml-auto">
                                        <input
                                            v-model.number="slippage"
                                            type="number"
                                            step="0.1"
                                            :min="SLIPPAGE_MIN"
                                            :max="SLIPPAGE_MAX"
                                            :aria-invalid="slippageInvalid"
                                            data-test="slippage-input"
                                            class="w-14 bg-dark-700/50 border rounded-lg px-2 py-1.5 text-xs text-white text-center focus:outline-none"
                                            :class="slippageInvalid ? 'border-trading-red focus:border-trading-red' : 'border-dark-600 focus:border-primary-500'"
                                            @blur="normalizeSlippageInput"
                                            @keydown.enter="normalizeSlippageInput"
                                        />
                                        <span class="text-xs text-dark-400">%</span>
                                    </div>
                                </div>
                                <p v-if="slippageInvalid" class="mt-2 text-[11px] text-trading-red leading-relaxed">
                                    {{ t('swapPage.slippageInvalid', { min: SLIPPAGE_MIN, max: SLIPPAGE_MAX }) }}
                                </p>
                                <p v-else-if="slippageHigh" class="mt-2 text-[11px] text-amber-300 leading-relaxed">
                                    {{ t('swapPage.slippageHigh') }}
                                </p>
                            </div>
                        </Transition>

                        <!-- TPIX เลือกแล้วแต่ยังไม่มีคู่ให้สวอป — บอกตรง ๆ + ลองโหลดใหม่ (ไม่โชว์ฟอร์มที่ใช้ไม่ได้) -->
                        <div v-if="tpixEmpty" data-test="tpix-empty" class="rounded-xl bg-dark-800/40 border border-white/5 p-5 text-center">
                            <p v-if="tpixPairsLoading" class="text-sm text-dark-400 animate-pulse">{{ t('common.loading') }}</p>
                            <template v-else>
                                <p class="text-sm text-dark-300">{{ tpixPairsFailed ? t('swapPage.tpixPairsFailed') : t('swapPage.tpixNoPairs') }}</p>
                                <button
                                    type="button"
                                    data-test="tpix-retry"
                                    @click="loadTpixPairs"
                                    class="mt-3 px-3 py-1.5 text-xs rounded-lg bg-white/5 hover:bg-white/10 text-dark-200 transition-colors"
                                >
                                    {{ t('swapPage.retry') }}
                                </button>
                            </template>
                        </div>

                        <template v-else>
                        <!-- From Token -->
                        <div class="rounded-xl bg-dark-800/40 border border-white/5 p-4 hover:border-white/10 transition-colors">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-dark-400">{{ t('swap.youPay') }}</span>
                                <button
                                    v-if="isWalletConnected && parseFloat(fromToken.balance) > 0"
                                    type="button"
                                    @click="setMaxAmount"
                                    class="text-xs text-primary-400 hover:text-primary-300 transition-colors"
                                >
                                    {{ t('swapPage.balance', { amount: fromToken.balance }) }} <span class="font-semibold">{{ t('swapPage.max') }}</span>
                                </button>
                                <span v-else class="text-xs text-dark-500">{{ t('swapPage.balance', { amount: fromToken.balance }) }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="fromAmount"
                                    type="number"
                                    placeholder="0.0"
                                    step="any"
                                    min="0"
                                    class="flex-1 bg-transparent text-2xl font-semibold text-white placeholder-dark-600 focus:outline-none min-w-0"
                                />
                                <button
                                    type="button"
                                    data-test="from-token"
                                    @click="openTokenSelector('from')"
                                    class="flex items-center gap-2 px-3 py-2 rounded-xl bg-dark-700/60 hover:bg-dark-600/60 border border-white/5 hover:border-white/10 transition-all flex-shrink-0"
                                >
                                    <CoinIcon :symbol="fromToken.symbol" :src="fromToken.logo || null" size="sm" />
                                    <span class="font-semibold text-white text-sm">{{ fromToken.symbol }}</span>
                                    <svg class="w-3.5 h-3.5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Swap Direction Button -->
                        <div class="flex justify-center -my-2.5 relative z-10">
                            <button
                                type="button"
                                data-test="flip-tokens"
                                @click="swapTokens"
                                class="w-9 h-9 rounded-full bg-dark-800 border-4 border-dark-950/80 flex items-center justify-center text-dark-400 hover:text-primary-400 hover:border-primary-500/30 transition-all hover:rotate-180 duration-300"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                </svg>
                            </button>
                        </div>

                        <!-- To Token -->
                        <div class="rounded-xl bg-dark-800/40 border border-white/5 p-4 hover:border-white/10 transition-colors">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-dark-400">{{ t('swap.youReceive') }}</span>
                                <span class="text-xs text-dark-500">{{ t('swapPage.balance', { amount: toToken.balance }) }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex-1 text-2xl font-semibold min-w-0 truncate" :class="isLoading || engine.isLoadingQuote.value ? 'text-dark-500 animate-pulse' : toAmount ? 'text-white' : 'text-dark-600'">
                                    {{ (isLoading || engine.isLoadingQuote.value) ? t('common.loading') : (toAmount || '0.0') }}
                                </div>
                                <button
                                    type="button"
                                    data-test="to-token"
                                    @click="openTokenSelector('to')"
                                    class="flex items-center gap-2 px-3 py-2 rounded-xl bg-dark-700/60 hover:bg-dark-600/60 border border-white/5 hover:border-white/10 transition-all flex-shrink-0"
                                >
                                    <CoinIcon :symbol="toToken.symbol" :src="toToken.logo || null" size="sm" />
                                    <span class="font-semibold text-white text-sm">{{ toToken.symbol }}</span>
                                    <svg class="w-3.5 h-3.5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Exchange Rate (inline) -->
                        <div v-if="exchangeRate" class="mt-3 flex items-center justify-center text-xs text-dark-400">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            1 {{ fromToken.symbol }} = {{ exchangeRate.toFixed(4) }} {{ toToken.symbol }}
                        </div>

                        <!-- Error (จาก useSwap หรือ useTpixDex ตามเครือข่าย — ข้อความแปลแล้ว ไม่ใช่ error ดิบ) -->
                        <div v-if="engine.error.value" data-test="swap-error" class="mt-3 p-3 rounded-xl bg-trading-red/10 border border-trading-red/20 text-trading-red text-sm flex items-center gap-2">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ engine.error.value }}
                        </div>

                        <!-- สลับเชนไม่สำเร็จ / ราคาอัปเดตก่อนส่ง — ไม่ได้มาจาก composable จึงแยกช่อง -->
                        <div
                            v-if="actionNotice"
                            data-test="swap-action-notice"
                            :class="['mt-3 p-3 rounded-xl text-sm flex items-center gap-2 border',
                                actionNotice.type === 'error'
                                    ? 'bg-trading-red/10 border-trading-red/20 text-trading-red'
                                    : 'bg-amber-500/10 border-amber-500/25 text-amber-300']"
                        >
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ actionNotice.text }}
                        </div>

                        <!-- Success — ลิงก์ explorer ของเชนที่สวอปจริง -->
                        <div v-if="engine.txHash.value && engine.txStatus.value === 'confirmed'" data-test="swap-success" class="mt-3 p-3 rounded-xl bg-trading-green/10 border border-trading-green/20 text-trading-green text-sm">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span class="font-medium">{{ t('swapPage.confirmed') }}</span>
                            </div>
                            <a :href="getTxUrl(engine.txHash.value, activeChainId)" data-test="swap-tx-link" target="_blank" rel="noopener" class="text-xs underline mt-1 block text-trading-green/70 hover:text-trading-green">
                                {{ isTpixMode ? t('swapPage.viewOnTpixExplorer') : t('swapPage.viewOnBscScan') }}
                            </a>
                        </div>

                        <!-- Details Accordion -->
                        <div v-if="fromAmount && parseFloat(fromAmount) > 0 && currentQuote" class="mt-3 rounded-xl bg-dark-800/30 border border-white/5 overflow-hidden">
                            <div class="p-3 space-y-2 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-dark-400">{{ isTpixMode ? t('swapPage.poolFee') : t('swapPage.platformFee') }} <span class="text-accent-400">({{ feeRate }}%)</span></span>
                                    <span class="text-dark-300 font-mono">{{ feeAmount }} {{ fromToken.symbol }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-dark-400">{{ t('swap.priceImpact') }}</span>
                                    <span :class="priceImpactClass" class="font-mono" data-test="price-impact">
                                        {{ priceImpact }}%
                                    </span>
                                </div>
                                <!-- TPIX: ไม้ใหญ่เกินพูล / ตรวจผลกระทบไม่ได้ — ปุ่มสวอปถูกปิด บอกเหตุผลตรงนี้ -->
                                <p v-if="priceImpactBlocked" class="text-[11px] text-trading-red leading-relaxed" data-test="price-impact-blocked">
                                    {{ priceImpactKnown
                                        ? t('swapPage.priceImpactBlocked', { impact: currentQuote.priceImpact.toFixed(2), limit: PRICE_IMPACT_BLOCK_PCT })
                                        : t('swapPage.priceImpactUnknown') }}
                                </p>
                                <div class="flex items-center justify-between">
                                    <span class="text-dark-400">{{ t('swapPage.slippage') }}</span>
                                    <span
                                        class="font-mono"
                                        :class="slippageInvalid ? 'text-trading-red' : slippageHigh ? 'text-amber-300' : 'text-dark-300'"
                                    >
                                        {{ effectiveSlippage ?? '—' }}%
                                    </span>
                                </div>
                                <!-- เตือนซ้ำตรงนี้ด้วย — แผงตั้งค่าอาจปิดอยู่ ผู้ใช้ต้องเห็นก่อนกดสวอป -->
                                <p v-if="slippageHigh" class="text-[11px] text-amber-300 leading-relaxed" data-test="slippage-high-warning">
                                    {{ t('swapPage.slippageHigh') }}
                                </p>
                                <div class="border-t border-white/5 pt-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-dark-300">{{ t('swapPage.minReceived') }}</span>
                                        <span class="text-white font-semibold font-mono" data-test="min-received">{{ minimumReceived }} {{ toToken.symbol }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ยังตั้งค่าไม่ครบ — บอกให้ชัดว่าไม่ใช่เว็บพัง แล้วปิดปุ่มทั้งชุด -->
                        <div v-if="swapBlockedReason" class="mt-4 p-4 rounded-xl bg-amber-500/10 border border-amber-500/25 text-amber-300 text-sm">
                            <div class="flex items-start gap-2.5">
                                <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                </svg>
                                <div class="min-w-0">
                                    <p class="font-semibold mb-0.5">{{ t('swapPage.blockedTitle') }}</p>
                                    <p class="text-amber-300/80 text-xs leading-relaxed">{{ swapBlockedReason }}</p>
                                    <button v-if="readiness.loadFailed.value" type="button" @click="readiness.reload()"
                                        class="mt-2 px-3 py-1.5 text-xs rounded-lg bg-amber-500/15 hover:bg-amber-500/25 transition-colors">
                                        {{ t('swapPage.retry') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Action Button -->
                        <button
                            v-else-if="!isWalletConnected"
                            class="w-full mt-4 btn-brand py-3.5 text-base"
                            @click="showWalletModal = true"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            {{ t('wallet.connect') }}
                        </button>

                        <!-- ด่านยืนยันตัวตนของการเทรด/สวอปเปิดอยู่และยังไม่ผ่าน — พาไปยืนยันก่อน
                             (สวอปออกจากกระเป๋าตรง ด่านฝั่งเซิร์ฟเวอร์ไปโผล่ตอนบันทึก ซึ่งเงินออกไปแล้ว) -->
                        <template v-else-if="kycBlocked">
                            <button
                                type="button"
                                data-test="kyc-button"
                                class="w-full mt-4 py-3.5 rounded-xl text-base font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/40 hover:bg-amber-500/30 transition-colors"
                                @click="router.visit('/kyc')"
                            >
                                {{ t('trade.form.verifyIdentity') }}
                            </button>
                            <p class="mt-2 text-xs text-amber-300/90 text-center">{{ t('trade.form.kycHint') }}</p>
                        </template>

                        <!-- ยอดไม่พอ / ราคาขยับเกินเพดาน (TPIX) — ไม่ชวนให้อนุมัติไม้ที่ส่งไม่ได้อยู่แล้ว ปุ่มสวอปบอกเหตุผลแทน -->
                        <template v-else-if="needsApproval && fromAmount && currentQuote && !insufficientBalance && !priceImpactBlocked">
                            <button
                                type="button"
                                data-test="approve-button"
                                :disabled="engine.isApproving.value || isApproveInFlight"
                                class="w-full mt-4 py-3.5 text-base rounded-xl font-semibold transition-all disabled:opacity-40 bg-accent-500/20 text-accent-400 border border-accent-500/30 hover:bg-accent-500/30"
                                @click="handleApprove"
                            >
                                {{ (engine.isApproving.value || isApproveInFlight) ? t('swapPage.approving') : t('swapPage.approve', { symbol: fromToken.symbol }) }}
                            </button>
                            <p v-if="isTpixMode" class="mt-2 text-[11px] text-dark-400 text-center">{{ t('swapPage.approveExactHint') }}</p>
                        </template>

                        <button
                            v-else
                            type="button"
                            data-test="swap-button"
                            :disabled="swapDisabled"
                            class="w-full mt-4 btn-primary py-3.5 text-base disabled:opacity-40"
                            @click="executeSwap"
                        >
                            {{ swapButtonLabel }}
                        </button>
                        </template>

                        <!-- Powered by -->
                        <div class="mt-3 flex items-center justify-center gap-1.5 text-[10px] text-dark-500">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            {{ isTpixMode ? t('swapPage.poweredByTpix') : t('swapPage.poweredBy') }}
                        </div>
                    </div>
                </div>

                <!-- Route Info -->
                <div v-if="!tpixEmpty" class="mt-4 p-3 rounded-xl bg-dark-900/60 border border-white/5">
                    <div class="flex items-center gap-3 text-xs">
                        <div class="flex items-center gap-1.5">
                            <CoinIcon :symbol="fromToken.symbol" :src="fromToken.logo || null" size="xs" />
                            <span class="text-dark-300 font-medium">{{ fromToken.symbol }}</span>
                        </div>
                        <div class="flex-1 border-t border-dashed border-dark-700 relative">
                            <div class="absolute inset-x-0 -top-2 flex justify-center">
                                <span class="px-1.5 bg-dark-950 text-[10px] text-primary-400/70">{{ isTpixMode ? 'TPIX DEX' : 'PancakeSwap V2' }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <CoinIcon :symbol="toToken.symbol" :src="toToken.logo || null" size="xs" />
                            <span class="text-dark-300 font-medium">{{ toToken.symbol }}</span>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center gap-3 text-[10px] text-dark-500">
                        <span class="flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full" :class="isWalletConnected ? 'bg-trading-green animate-pulse' : 'bg-dark-600'"></span>
                            {{ isWalletConnected ? t('swapPage.connected') : t('swapPage.notConnected') }}
                        </span>
                        <span>{{ isTpixMode ? 'TPIX Chain' : 'BSC Mainnet' }}</span>
                        <span>{{ t('swapPage.feeShort', { rate: feeRate }) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Token Selector Modal -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showTokenSelector" class="fixed inset-0 bg-dark-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTokenSelector = false">
                <div class="glass w-full max-w-sm p-5 animate-scale-in">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-white">{{ t('swapPage.selectToken') }}</h3>
                        <button type="button" :aria-label="t('common.close')" @click="showTokenSelector = false" class="p-1 rounded-lg hover:bg-white/10 text-dark-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Popular tags -->
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        <button
                            v-for="token in activeTokens.slice(0, 5)"
                            :key="'tag-' + token.address"
                            type="button"
                            @click="selectToken(token)"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-dark-800/50 border border-white/5 hover:border-white/10 text-xs text-dark-300 hover:text-white transition-all"
                        >
                            <CoinIcon :symbol="token.symbol" :src="token.logo || null" size="xs" />
                            {{ token.symbol }}
                        </button>
                    </div>

                    <div class="border-t border-white/5 pt-3">
                        <div class="space-y-0.5 max-h-[320px] overflow-y-auto" data-test="token-list">
                            <button
                                v-for="token in activeTokens"
                                :key="token.address"
                                type="button"
                                @click="selectToken(token)"
                                class="w-full flex items-center gap-3 p-2.5 rounded-xl hover:bg-white/5 transition-colors text-left"
                                :class="{
                                    'opacity-30 pointer-events-none': (tokenSelectorMode === 'from' && sameToken(token, fromToken)) || (tokenSelectorMode === 'to' && sameToken(token, toToken)),
                                }"
                            >
                                <CoinIcon :symbol="token.symbol" :src="token.logo || null" size="md" class="flex-shrink-0" />
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-white text-sm">{{ token.symbol }}</p>
                                    <p class="text-xs text-dark-500 truncate">{{ token.name }}</p>
                                </div>
                                <span class="text-xs text-dark-400 font-mono">{{ token.balance }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Wallet Modal -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <WalletModal
                v-if="showWalletModal && !isWalletConnected"
                @close="showWalletModal = false"
                @connected="showWalletModal = false"
            />
        </Transition>
    </AppLayout>
</template>
