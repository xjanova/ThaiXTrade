<script setup>
/**
 * TPIX TRADE - Trade Form Component
 * ฟอร์มซื้อ/ขาย ผูกกับยอดคงเหลือจริงของ wallet
 * - คลิกราคาในสมุดคำสั่ง/เทรดล่าสุด → เด้งเข้าช่อง Price ทันที
 * - ช่อง Total พิมพ์เองได้ (ซื้อด้วยงบที่ต้องการ)
 * - จัดคอลัมน์ตาม "ความกว้างของการ์ด" ไม่ใช่ความกว้างจอ — ผู้ใช้ย้ายการ์ดไป
 *   คอลัมน์ไหนก็ยังอ่านง่าย (ในคอลัมน์แคบเรียงลง, ใต้กราฟกว้างๆ เรียงเป็น 3 ช่อง)
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted, onUnmounted, useTemplateRef } from 'vue';
import { useElementSize } from '@vueuse/core';
import { playClickSound } from '@/Composables/useSounds';
import { useTranslation } from '@/Composables/useTranslation';
import axios from 'axios';
import OrderFeeNotice from '@/Components/Trading/OrderFeeNotice.vue';
import TpixTopupModal from '@/Components/Trading/TpixTopupModal.vue';
import { useTradingFee } from '@/Composables/useTradingFee';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    tickerPrice: { type: Number, default: 0 },
    /**
     * ราคาที่ผู้ใช้คลิกมาจากสมุดคำสั่ง/เทรดล่าสุด
     * รับได้ทั้งตัวเลขล้วน และอ็อบเจ็กต์ { price, amount, nonce }
     * ต้องมี nonce เพราะคลิก "ราคาเดิมซ้ำ" จะไม่ทำให้ watcher ทำงานถ้าเทียบแค่ค่าราคา
     */
    selectedPrice: { type: [Number, Object, null], default: null },
    isWalletConnected: { type: Boolean, default: false },
    isSubmitting: { type: Boolean, default: false },
    balances: { type: Array, default: () => [] },
    // โหมดของฟอร์ม:
    //  'onchain'  = market order เทรดจริงบน BSC ผ่าน PancakeSwap (limit ยังไม่เปิด)
    //  'disabled' = คู่เทรดยังไม่เปิด (เช่น TPIX รอเชน TPIX พร้อม) — โชว์ Coming soon
    //  'internal' = order book ภายใน (พฤติกรรมเดิม — ใช้เมื่อ TPIX Chain เปิด)
    mode: { type: String, default: 'internal' },
    // เชนที่ไม้จะลงจริง — ใช้ขอใบเสนอราคาค่าบริการให้ตรงเชน (56 = BSC, 4289 = TPIX Chain)
    chainId: { type: Number, default: 56 },
    // ตัวเลข preview จาก quote จริงของ router (parent format มาให้พร้อมแสดง)
    marketPreview: { type: Object, default: null },
    /**
     * เลขกระเป๋าที่เชื่อมอยู่ — ใช้ถามค่าบริการวางไม้และยอดเครดิต
     *
     * รับผ่าน prop ไม่ดึงจาก store เอง: คอมโพเนนต์นี้ตั้งใจให้ mount ทดสอบได้
     * โดยไม่ต้องตั้ง Pinia และรับสถานะกระเป๋าทาง prop อยู่แล้ว (isWalletConnected)
     */
    walletAddress: { type: String, default: null },
});

const emit = defineEmits(['submit-order', 'connect-wallet', 'form-change']);

const { t } = useTranslation();

const root = useTemplateRef('root');
const { width } = useElementSize(root);
/**
 * ResizeObserver อาจยังไม่ยิงในเฟรมแรก (และไม่ยิงเลยถ้าแท็บไม่ได้ compositing)
 * จึงวัดเองหนึ่งครั้งตอน mount ไว้ใช้ "ระหว่างรอ" — กันฟอร์มเรนเดอร์ผังแคบ
 * ทั้งที่วางอยู่ใต้กราฟกว้างๆ
 *
 * ⚠️ ห้ามใช้ Math.max(width, initialWidth)
 *    เคยใช้แบบนั้นแล้วฟอร์ม "โตได้อย่างเดียว ไม่ยอมหดกลับ" — พอย่อหน้าต่างลง
 *    (หรือคอลัมน์กลางแคบลงตอนเป็นผัง 4 คอลัมน์) ฟอร์มยังคิดว่าตัวเองกว้างอยู่
 *    เลยใช้ผัง 3 ช่องในกล่อง 394px จนช่องเหลือ 123px และเนื้อหาล้นออกนอกการ์ด
 *    วัดได้จริงที่ความกว้างหน้าต่าง 1280px
 *
 *    ค่าจาก ResizeObserver เป็นตัวจริงเสมอเมื่อมีค่าแล้ว
 */
const initialWidth = ref(0);
const cardWidth = computed(() => (width.value > 0 ? width.value : initialWidth.value));
/** การ์ดกว้างพอจะวางช่องกรอกเรียงข้างกันไหม (เช่น ตอนอยู่ใต้กราฟ) */
const isWide = computed(() => cardWidth.value >= 520);

const isConnected = computed(() => props.isWalletConnected);

const activeTab = ref('buy');
const orderType = ref(props.mode === 'onchain' ? 'market' : 'limit');
const price = ref('');
const amount = ref('');
const total = ref('');
const sliderValue = ref(0);
const priceFlash = ref(false);

/** slippage ที่ผู้ใช้เลือกเอง สำหรับ market order บน BSC (null = ใช้ค่าจาก backend) */
const slippageOptions = [0.5, 1, 2, 5];
const slippage = ref(null);

let flashTimer = null;

// โหมด onchain: limit โชว์ไว้แต่กดไม่ได้ (AMM ทำ limit order ไม่ได้จนกว่า
// order book บน TPIX Chain จะเปิด) — market เท่านั้นที่ execute จริง
const orderTypes = computed(() => {
    if (props.mode === 'onchain') {
        return [
            { value: 'limit', labelKey: 'trade.form.limit', disabled: true },
            { value: 'market', labelKey: 'trade.form.marketType' },
        ];
    }
    return [
        { value: 'limit', labelKey: 'trade.form.limit' },
        { value: 'market', labelKey: 'trade.form.marketType' },
    ];
});

const limitAvailable = computed(() => !orderTypes.value.find(o => o.value === 'limit')?.disabled);

// เปลี่ยนคู่เทรด/โหมด → บังคับ market ในโหมด onchain (limit เลือกไม่ได้)
watch(() => props.mode, (m) => {
    if (m === 'onchain') orderType.value = 'market';
}, { immediate: true });

// แจ้ง parent เมื่อค่าในฟอร์มเปลี่ยน — ใช้ขอ quote จริงจาก router (parent debounce เอง)
watch([activeTab, orderType, amount, total], () => {
    emit('form-change', {
        side: activeTab.value,
        type: orderType.value,
        amount: amount.value,
        total: total.value,
    });
});

const sliderPercentages = [0, 25, 50, 75, 100];

const baseSymbol = computed(() => props.symbol.split('/')[0] || 'BTC');
const quoteSymbol = computed(() => props.symbol.split('/')[1] || 'USDT');

const availableBalance = computed(() => {
    if (!props.balances || props.balances.length === 0) return '0';
    const tokenSymbol = activeTab.value === 'buy' ? quoteSymbol.value : baseSymbol.value;
    const found = props.balances.find(b => b.symbol?.toUpperCase() === tokenSymbol.toUpperCase());
    return found ? parseFloat(found.balance).toFixed(6) : '0';
});

const toNumber = (v) => parseFloat(String(v ?? '').replace(/,/g, '')) || 0;

/** ราคาที่ใช้คำนวณ — โหมด market ใช้ราคาตลาดถ้าผู้ใช้ยังไม่ได้เลือกราคาเอง */
const effectivePrice = computed(() => toNumber(price.value) || props.tickerPrice || 0);

/** เตือนเมื่อยอดที่จะใช้เกินยอดคงเหลือ (เช็คฝั่งที่ต้องจ่ายจริง) */
const exceedsBalance = computed(() => {
    if (!isConnected.value) return false;
    const balance = parseFloat(availableBalance.value) || 0;
    if (balance <= 0) return false;
    const spending = activeTab.value === 'buy' ? toNumber(total.value) : toNumber(amount.value);
    return spending > balance;
});

// ── เติมราคาจากการคลิกในสมุดคำสั่ง / เทรดล่าสุด ──────────────────────────────
/**
 * เดิม watcher เช็ค `orderType === 'limit'` ก่อนเติมราคา
 * แต่โหมด onchain ถูกบังคับเป็น market เสมอ → คลิกราคาแล้วไม่มีอะไรเกิดขึ้นเลย
 * ตอนนี้เติมราคาทุกกรณี และสลับไป Limit ให้อัตโนมัติเมื่อคู่นั้นทำ limit ได้
 */
watch(() => props.selectedPrice, (val) => {
    if (val == null) return;

    const picked = typeof val === 'object' ? val : { price: val };
    const pickedPrice = Number(picked.price);
    if (!Number.isFinite(pickedPrice) || pickedPrice <= 0) return;

    if (orderType.value === 'market' && limitAvailable.value) {
        orderType.value = 'limit';
    }

    price.value = formatPriceInput(pickedPrice);

    const pickedAmount = Number(picked.amount);
    if (Number.isFinite(pickedAmount) && pickedAmount > 0) {
        amount.value = pickedAmount.toFixed(6);
    }

    calculateTotal();
    flashPrice();
}, { deep: true });

function flashPrice() {
    priceFlash.value = true;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { priceFlash.value = false; }, 700);
}

function formatPriceInput(value) {
    return value >= 1
        ? value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : value.toFixed(8);
}

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * ค่าธรรมเนียม — ตัวเลขนี้ต้องตรงกับที่ระบบหักจริงเสมอ
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมดึงจาก /api/v1/swap/routes ซึ่งเป็นค่าของ "การสลับเหรียญ" คนละอย่างกับ
 * ค่าธรรมเนียมเทรด และตกมาที่ 0.1% ทั้งที่ค่าปริยายจริงคือ 0.3% — ผู้ใช้คำนวณ
 * กำไรผิดทุกไม้โดยไม่มีทางรู้ จนกว่าจะมานั่งเทียบยอดย้อนหลัง
 *
 * ตอนนี้อ่านจาก /api/v1/market/pairs ซึ่งคำนวณด้วย FeeCalculationService
 * ตัวเดียวกับที่หักเงินจริง (รวมค่าตั้งทับรายคู่ + เพดานของแอดมินแล้ว)
 */
const feeRate = ref(null);

/** ยังไม่รู้อัตราจริง = ยังไม่โชว์ตัวเลข ดีกว่าโชว์ค่าเดาที่ผู้ใช้เอาไปคิดกำไร */
const feeKnown = computed(() => feeRate.value !== null);

/** ฐานคิดค่าธรรมเนียม — ตรงกับฝั่งเซิร์ฟเวอร์: ใช้ total ถ้ามี ไม่งั้นใช้ amount */
const feeBase = computed(() => {
    const totalNum = toNumber(total.value);
    return totalNum > 0 ? totalNum : toNumber(amount.value) * effectivePrice.value;
});

const feeAmount = computed(() => (feeKnown.value ? feeBase.value * (feeRate.value / 100) : 0));

/**
 * ยอดที่ต้องจ่ายจริง / ที่จะได้รับจริง — ตัวเลขที่ผู้ใช้ต้องใช้ตัดสินใจ
 *
 * ซื้อ: จ่ายมูลค่ารวม + ค่าธรรมเนียม · ขาย: ได้มูลค่ารวม − ค่าธรรมเนียม
 * โชว์แค่ค่าธรรมเนียมเฉยๆ ยังต้องให้ผู้ใช้บวกลบเองในหัวทุกครั้ง
 */
const netTotal = computed(() => {
    const gross = toNumber(total.value);
    if (gross <= 0) return 0;

    return activeTab.value === 'buy' ? gross + feeAmount.value : gross - feeAmount.value;
});

/**
 * ทศนิยมตามขนาดของตัวเลข ไม่ใช่ 2 ตำแหน่งตายตัว
 *
 * ⚠️ ของเดิมใช้ toFixed(2) ทุกกรณี — คู่ที่เทียบด้วย BTC จะได้ค่าธรรมเนียม
 *    0.00005 BTC แล้วแสดงเป็น "0.00" ผู้ใช้อ่านว่าเทรดฟรี ทั้งที่ถูกหักจริง
 */
function formatQuote(value) {
    const n = Number(value) || 0;
    if (n === 0) return '0';
    if (Math.abs(n) >= 1) return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (Math.abs(n) >= 0.01) return n.toFixed(4);
    if (Math.abs(n) >= 0.0001) return n.toFixed(6);

    return n.toFixed(8);
}

const feeAmountText = computed(() => formatQuote(feeAmount.value));
const netTotalText = computed(() => formatQuote(netTotal.value));

onMounted(async () => {
    if (root.value) initialWidth.value = root.value.getBoundingClientRect().width;

    await loadFeeRate();
});

/** อัตราของคู่ที่กำลังดูอยู่ — เปลี่ยนคู่แล้วต้องโหลดใหม่ อัตราตั้งทับรายคู่ได้ */
async function loadFeeRate() {
    feeRate.value = null;

    try {
        const { data } = await axios.get('/api/v1/market/pairs');
        if (!data?.success) return;

        const wanted = props.symbol.replace('/', '-').toUpperCase();
        const pair = data.data.find(p => `${p.base_asset}-${p.quote_asset}`.toUpperCase() === wanted);

        // ไม่เจอคู่นี้ในทะเบียน = ยังไม่รู้อัตราจริง ปล่อยเป็น null ไม่เดา
        if (pair && typeof pair.fee_rate === 'number') feeRate.value = pair.fee_rate;
    } catch {
        // อ่านไม่ได้ = ไม่รู้ ไม่ใช่ 0
    }
}

onUnmounted(() => {
    clearTimeout(flashTimer);
});

// Update price field when ticker changes (only if user hasn't typed yet)
watch(() => props.tickerPrice, (newPrice) => {
    if (newPrice > 0 && !price.value) {
        price.value = formatPriceInput(newPrice);
    }
}, { immediate: true });

// เปลี่ยนคู่เทรด → ราคา/จำนวนของคู่เดิมใช้ไม่ได้แล้ว ล้างทิ้งแล้วรับราคาใหม่จาก ticker
watch(() => props.symbol, () => {
    price.value = '';
    amount.value = '';
    total.value = '';
    sliderValue.value = 0;
    // อัตราค่าธรรมเนียมตั้งทับรายคู่ได้ — ค้างค่าของคู่เดิมไว้คือโชว์เลขผิด
    loadFeeRate();
});

const calculateTotal = () => {
    const priceNum = effectivePrice.value;
    const amountNum = toNumber(amount.value);
    total.value = amountNum > 0
        ? (priceNum * amountNum).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '';
};

/** พิมพ์ Total เอง → ถอยกลับไปหาจำนวนเหรียญ (ซื้อด้วยงบที่ตั้งไว้) */
const calculateAmountFromTotal = () => {
    const priceNum = effectivePrice.value;
    const totalNum = toNumber(total.value);
    if (priceNum > 0 && totalNum > 0) {
        amount.value = (totalNum / priceNum).toFixed(6);
    } else if (totalNum === 0) {
        amount.value = '';
    }
};

const setSliderValue = (percent) => {
    sliderValue.value = percent;
    playClickSound();

    if (percent === 0) {
        amount.value = '';
        total.value = '';
        return;
    }

    const balance = parseFloat(availableBalance.value) || 0;
    if (balance <= 0) return;

    const priceNum = effectivePrice.value;
    if (priceNum <= 0) return;

    amount.value = activeTab.value === 'buy'
        ? ((balance * (percent / 100)) / priceNum).toFixed(6)
        : (balance * (percent / 100)).toFixed(6);

    calculateTotal();
};

const setMarketPrice = () => {
    if (props.tickerPrice > 0) {
        price.value = formatPriceInput(props.tickerPrice);
        calculateTotal();
        flashPrice();
    }
};

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * ค่าบริการวางไม้ (เครดิต TPIX) — เก็บก่อนวางไม้ ไม่ใช่หลังปิดไม้
 * ═══════════════════════════════════════════════════════════════════════════
 * เจ้าของกำหนด: ต้องมี TPIX ในคลังก่อนถึงวางไม้ได้ · ไม่มีก็เทรดได้แต่จ่ายแพงกว่า
 * และคืนเงินโดนหักค่าแก๊ส · ความต่างนี้ต้องเห็นก่อนกดยืนยันทุกครั้ง
 */
const tradingFee = useTradingFee();
const feeMethod = ref('tpix_credit');
const showTopup = ref(false);

/** มูลค่าไม้เป็น USD — คู่ที่เทียบด้วย USDT ถือว่าเท่ากับดอลลาร์ */
const orderValueUsd = computed(() => {
    const gross = toNumber(total.value);
    return gross > 0 ? gross : toNumber(amount.value) * effectivePrice.value;
});

/** ขอใบเสนอราคาใหม่เมื่อขนาดไม้เปลี่ยน — หน่วงไว้ไม่ให้ยิงทุกตัวอักษรที่พิมพ์ */
let feeQuoteTimer = null;
watch([orderValueUsd, () => props.symbol, () => props.walletAddress], () => {
    clearTimeout(feeQuoteTimer);
    feeQuoteTimer = setTimeout(() => {
        tradingFee.quote({
            wallet: props.walletAddress,
            orderValueUsd: orderValueUsd.value,
            chainId: props.chainId,
            pair: props.symbol,
        });
    }, 400);
});

/*
 * เครดิตไม่พอ + เลือกจ่ายด้วย TPIX = วางไม้ไม่ได้
 *
 * ⚠️ ไม่ปิดปุ่มถ้าเขาเลือกจ่ายเป็นเหรียญ — เจ้าของสั่งว่าไม่มี TPIX ก็ยังเทรดได้
 *    แค่จ่ายแพงกว่า การปิดประตูใส่ผู้ใช้ใหม่ทั้งหมดไม่ใช่สิ่งที่ต้องการ
 */
const feeBlocked = computed(() => {
    if (tradingFee.currentQuote.value?.enabled !== true) return false;
    if (feeMethod.value !== 'tpix_credit') return false;

    return tradingFee.canPayWithCredit.value !== true;
});

const feeBlockedReason = computed(() => {
    if (!feeBlocked.value) return '';
    const q = tradingFee.currentQuote.value?.tpix;
    if (q?.available !== true) return q?.reason || 'ยังใช้ค่าบริการแบบ TPIX ไม่ได้';

    return `เครดิตไม่พอ — ขาดอีก ${tradingFee.creditShortfall.value} TPIX`;
});

/** ระบบแนะนำทางไหน ก็ตั้งให้ตรงนั้นเป็นค่าเริ่มต้น (ผู้ใช้เปลี่ยนเองได้) */
watch(() => tradingFee.recommended.value, (rec) => {
    if (rec) feeMethod.value = rec;
});

const submitOrder = () => {
    if (props.isSubmitting) return;

    if (!isConnected.value) {
        emit('connect-wallet');
        return;
    }

    // เครดิตไม่พอสำหรับทางที่เลือก — พาไปเติมแทนที่จะปล่อยให้กดแล้วพัง
    if (feeBlocked.value) {
        showTopup.value = true;
        return;
    }

    playClickSound();

    emit('submit-order', {
        side: activeTab.value,
        type: orderType.value,
        price: price.value,
        amount: amount.value,
        total: total.value,
        slippage: slippage.value,
        // ทางจ่ายค่าบริการ — parent เอาไปขอใบอนุญาตก่อนเซ็นธุรกรรมของไม้
        feeMethod: feeMethod.value,
        orderValueUsd: orderValueUsd.value,
    });
};
</script>

<template>
    <div ref="root" class="p-3">
        <!-- คู่เทรดยังไม่เปิด (รอ TPIX Chain) — โชว์ไว้ก่อน กดไม่ได้ -->
        <div v-if="mode === 'disabled'" class="py-8 px-4 text-center">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-primary-500/10 flex items-center justify-center">
                <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <p class="text-white font-semibold text-sm mb-1">{{ t('trade.form.comingSoon', { pair: symbol }) }}</p>
            <p class="text-dark-400 text-xs leading-relaxed">{{ t('trade.form.comingSoonBody') }}</p>
            <span class="inline-block mt-3 text-[10px] px-2 py-1 rounded bg-amber-500/15 text-amber-400 font-medium">
                {{ t('trade.form.tpixChainSoon') }}
            </span>
        </div>

        <template v-else>
            <!-- แถวบน: ซื้อ/ขาย + ชนิดคำสั่ง + ยอดคงเหลือ -->
            <div :class="['mb-3', isWide ? 'flex items-center gap-3' : '']">
                <div :class="['flex gap-2', isWide ? 'flex-1 max-w-[280px]' : 'mb-3']">
                    <button
                        type="button"
                        :class="[
                            'flex-1 py-2.5 rounded-xl font-semibold text-sm transition-all',
                            activeTab === 'buy' ? 'bg-trading-green text-white shadow-green-glow' : 'bg-dark-800 text-dark-400 hover:text-white'
                        ]"
                        @click="activeTab = 'buy'; playClickSound()"
                    >
                        {{ t('trade.form.buy') }}
                    </button>
                    <button
                        type="button"
                        :class="[
                            'flex-1 py-2.5 rounded-xl font-semibold text-sm transition-all',
                            activeTab === 'sell' ? 'bg-trading-red text-white shadow-red-glow' : 'bg-dark-800 text-dark-400 hover:text-white'
                        ]"
                        @click="activeTab = 'sell'; playClickSound()"
                    >
                        {{ t('trade.form.sell') }}
                    </button>
                </div>

                <!-- ชนิดคำสั่ง — limit ในโหมด onchain โชว์ไว้แต่กดไม่ได้ (รอ TPIX Chain) -->
                <div :class="['flex gap-1 p-0.5 bg-dark-800 rounded-lg', isWide ? 'w-[200px]' : 'mb-3']">
                    <button
                        v-for="type in orderTypes"
                        :key="type.value"
                        type="button"
                        :disabled="type.disabled"
                        :title="type.disabled ? t('trade.form.limitSoonTitle') : ''"
                        :class="[
                            'flex-1 py-1.5 text-xs font-medium rounded-md transition-all',
                            orderType === type.value ? 'bg-dark-600 text-white' : 'text-dark-400',
                            type.disabled ? 'opacity-40 cursor-not-allowed' : 'hover:text-white'
                        ]"
                        @click="!type.disabled && (orderType = type.value)"
                    >
                        {{ t(type.labelKey) }}
                        <span v-if="type.disabled" class="ml-0.5 text-[9px] text-amber-400">{{ t('trade.form.soon') }}</span>
                    </button>
                </div>

                <div v-if="isConnected" :class="['flex items-center justify-between gap-2 text-xs text-dark-400', isWide ? 'ml-auto' : 'mb-2']">
                    <span>{{ t('trade.form.available') }}</span>
                    <span class="font-mono text-dark-200">
                        {{ availableBalance }} {{ activeTab === 'buy' ? quoteSymbol : baseSymbol }}
                    </span>
                </div>
            </div>

            <!-- ช่องกรอก: แคบ = เรียงลง, กว้าง = 3 ช่องเรียงข้างกัน -->
            <div :class="isWide ? 'grid grid-cols-3 gap-3 mb-3' : ''">
                <!-- ราคา -->
                <div :class="isWide ? '' : 'mb-2'">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs text-dark-400">
                            {{ t('trade.form.price') }}
                            <span v-if="orderType === 'market'" class="text-dark-500">{{ t('trade.form.priceRef') }}</span>
                        </label>
                        <!-- โหมด market ก็ต้องมีปุ่มนี้ — คลิกราคาจากสมุดคำสั่งแล้วอยากกลับไปราคาตลาด -->
                        <button type="button" class="text-xs text-primary-400 hover:text-primary-300" @click="setMarketPrice">
                            {{ t('trade.form.useMarket') }}
                        </button>
                    </div>
                    <div class="trading-input-group">
                        <input
                            v-model="price"
                            type="text"
                            inputmode="decimal"
                            :disabled="orderType === 'market'"
                            :placeholder="orderType === 'market' ? 'Market Price' : '0.00'"
                            class="trading-input pr-14 font-mono text-sm transition-shadow"
                            :class="[
                                orderType === 'market' && 'opacity-60',
                                priceFlash && 'ring-2 ring-primary-400 border-primary-500',
                            ]"
                            @input="calculateTotal"
                        >
                        <span class="input-suffix text-xs quote-tint">{{ quoteSymbol }}</span>
                    </div>
                </div>

                <!-- จำนวน -->
                <div :class="isWide ? '' : 'mb-2'">
                    <label class="block text-xs text-dark-400 mb-1 leading-[18px]">{{ t('trade.form.amount') }}</label>
                    <div class="trading-input-group">
                        <input
                            v-model="amount"
                            type="text"
                            inputmode="decimal"
                            class="trading-input pr-14 font-mono text-sm"
                            placeholder="0.00"
                            @input="calculateTotal"
                        >
                        <span class="input-suffix text-xs base-tint">{{ baseSymbol }}</span>
                    </div>
                </div>

                <!-- มูลค่ารวม — พิมพ์เองได้ เพื่อซื้อด้วยงบที่ต้องการตรงๆ -->
                <div :class="isWide ? '' : 'mb-3'">
                    <label class="block text-xs text-dark-400 mb-1 leading-[18px]">{{ t('trade.form.total') }}</label>
                    <div class="trading-input-group">
                        <input
                            v-model="total"
                            type="text"
                            inputmode="decimal"
                            class="trading-input pr-14 font-mono text-sm"
                            placeholder="0.00"
                            @input="calculateAmountFromTotal"
                        >
                        <span class="input-suffix text-xs quote-tint">{{ quoteSymbol }}</span>
                    </div>
                </div>
            </div>

            <p v-if="exceedsBalance" class="-mt-1 mb-2 text-[11px] text-trading-red">
                {{ t('trade.form.exceeds', { balance: availableBalance, symbol: activeTab === 'buy' ? quoteSymbol : baseSymbol }) }}
            </p>

            <!-- เปอร์เซ็นต์ของยอดคงเหลือ -->
            <div class="mb-3 flex justify-between gap-1">
                <button
                    v-for="percent in sliderPercentages"
                    :key="percent"
                    type="button"
                    :class="[
                        'flex-1 py-1 text-xs font-medium rounded-md transition-all',
                        sliderValue === percent
                            ? activeTab === 'buy' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red'
                            : 'bg-dark-800 text-dark-400 hover:text-white'
                    ]"
                    @click="setSliderValue(percent)"
                >
                    {{ percent }}%
                </button>
            </div>

            <div :class="isWide ? 'grid grid-cols-2 gap-3 items-start' : ''">
                <!-- ค่าคลาดเคลื่อน — มีผลจริงกับ minOut ที่ส่งเข้า router -->
                <div v-if="mode === 'onchain' && orderType === 'market'" class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs text-dark-400">{{ t('trade.form.slippage') }}</label>
                        <span class="text-[10px] text-dark-500">{{ slippage === null ? t('trade.form.auto') : slippage + '%' }}</span>
                    </div>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            :class="['flex-1 py-1 text-[11px] rounded-md transition-all',
                                slippage === null ? 'bg-primary-500/20 text-primary-300' : 'bg-dark-800 text-dark-400 hover:text-white']"
                            @click="slippage = null"
                        >
                            {{ t('trade.form.auto') }}
                        </button>
                        <button
                            v-for="s in slippageOptions"
                            :key="s"
                            type="button"
                            :class="['flex-1 py-1 text-[11px] rounded-md font-mono transition-all',
                                slippage === s ? 'bg-primary-500/20 text-primary-300' : 'bg-dark-800 text-dark-400 hover:text-white']"
                            @click="slippage = s"
                        >
                            {{ s }}%
                        </button>
                    </div>
                </div>

                <!-- ตัวเลขจริงจาก PancakeSwap router (เฉพาะโหมด onchain) -->
                <div v-if="mode === 'onchain' && orderType === 'market' && marketPreview" class="mb-3 px-3 py-2 rounded-lg bg-dark-800/60 border border-white/5 space-y-1">
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="text-dark-400">{{ t('trade.form.youReceive') }}</span>
                        <span v-if="marketPreview.loading" class="skeleton w-20 h-3"></span>
                        <span v-else class="font-mono text-white truncate">{{ marketPreview.receiveText || '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="text-dark-400">{{ t('trade.form.minReceived') }}</span>
                        <span v-if="marketPreview.loading" class="skeleton w-16 h-3"></span>
                        <span v-else class="font-mono text-dark-300 truncate">{{ marketPreview.minReceivedText || '—' }}</span>
                    </div>
                    <div class="text-[10px] text-dark-500">{{ t('trade.form.executedVia') }}</div>
                </div>
            </div>

            <!--
                ค่าธรรมเนียม + ยอดสุทธิ — ตัวเลขที่ผู้ใช้ใช้ตัดสินใจเรื่องเงิน

                โหมด onchain ใช้ค่าจริงจาก quote ของ router ส่วนโหมดอื่นคำนวณจาก
                อัตราที่เซิร์ฟเวอร์บอก (ตัวเดียวกับที่หักจริง) และอัปเดตทุกครั้งที่พิมพ์
            -->
            <div class="mb-3 rounded-lg bg-black/20 border border-white/5 px-2.5 py-2 space-y-1">
                <template v-if="mode === 'onchain' && marketPreview && marketPreview.feeText">
                    <div class="flex items-center justify-between gap-2 text-xs text-dark-400">
                        <span>{{ t('trade.form.fee') }}</span>
                        <span class="font-mono truncate text-dark-200">{{ marketPreview.feeText }}</span>
                    </div>
                </template>

                <template v-else>
                    <div class="flex items-center justify-between gap-2 text-xs text-dark-400">
                        <span>
                            {{ t('trade.form.fee') }}
                            <span v-if="feeKnown" class="font-mono">({{ feeRate }}%)</span>
                        </span>
                        <!-- ยังไม่รู้อัตราจริง = ขีดกลาง ไม่ใช่ 0 ที่อ่านว่า "ไม่เสียค่าธรรมเนียม" -->
                        <span v-if="!feeKnown" class="font-mono text-dark-500">—</span>
                        <span v-else class="font-mono text-dark-200">
                            {{ feeAmountText }} <span class="quote-tint">{{ quoteSymbol }}</span>
                        </span>
                    </div>

                    <div v-if="feeKnown && netTotal > 0"
                        class="flex items-center justify-between gap-2 text-xs pt-1 border-t border-white/5">
                        <span class="text-dark-300">
                            {{ activeTab === 'buy' ? t('trade.form.netPay') : t('trade.form.netReceive') }}
                        </span>
                        <span :class="['font-mono font-semibold', activeTab === 'buy' ? 'text-trading-red' : 'text-trading-green']">
                            {{ netTotalText }} <span class="quote-tint">{{ quoteSymbol }}</span>
                        </span>
                    </div>
                </template>
            </div>

            <!--
                ค่าบริการวางไม้ — ต้องเห็นก่อนกดยืนยันเสมอ
                เจ้าของสั่งว่า "ต้องชี้แจงรายละเอียดให้ครบ" และ "แนะนำให้ใช้ TPIX"
            -->
            <OrderFeeNotice
                v-if="isConnected"
                class="mb-3"
                :quote="tradingFee.currentQuote.value"
                :method="feeMethod"
                :quote-symbol="quoteSymbol"
                @select-method="feeMethod = $event"
                @topup="showTopup = true"
            />

            <button
                type="button"
                :disabled="isSubmitting"
                :class="[
                    'w-full py-3 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2',
                    isSubmitting ? 'opacity-60 cursor-not-allowed' : '',
                    !isConnected
                        ? 'bg-primary-500 hover:bg-primary-600 text-white'
                        : feeBlocked
                            ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 hover:bg-amber-500/30'
                            : activeTab === 'buy'
                                ? 'btn-success'
                                : 'btn-danger'
                ]"
                @click="submitOrder"
            >
                <div v-if="isSubmitting" class="spinner !w-4 !h-4 !border-white/30 !border-t-white"></div>
                {{
                    isSubmitting ? t('trade.form.processing')
                    : !isConnected ? t('trade.form.connectWallet')
                    : feeBlocked ? 'เติมเครดิต TPIX เพื่อวางไม้'
                    : activeTab === 'buy' ? t('trade.form.buySymbol', { symbol: baseSymbol })
                    : t('trade.form.sellSymbol', { symbol: baseSymbol })
                }}
            </button>

            <!-- ปุ่มไม่ได้ตายเฉยๆ — บอกว่าขาดอะไรและกดแล้วไปไหนต่อ -->
            <p v-if="feeBlocked" class="mt-1.5 text-[11px] text-amber-300/90 text-center leading-relaxed">
                {{ feeBlockedReason }}
            </p>

            <TpixTopupModal
                v-if="showTopup"
                :show="showTopup"
                @close="showTopup = false"
                @credited="tradingFee.quote({ wallet: walletAddress, orderValueUsd, chainId: chainId, pair: symbol })"
            />

            <!-- TP/SL — ยังไม่มีจริงในโหมด onchain (AMM) จึงซ่อนไว้ -->
            <div v-if="mode !== 'onchain'" class="mt-3 flex items-center justify-between text-xs">
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3 h-3">
                    <span class="text-dark-400">{{ t('trade.form.tpsl') }}</span>
                </label>
                <button type="button" class="text-primary-400 hover:text-primary-300 transition-colors">
                    {{ t('trade.form.advanced') }}
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
/*
 * สีของสัญลักษณ์สกุลเงินในช่องกรอก
 *
 * ช่องราคา/จำนวน/มูลค่ารวมหน้าตาเหมือนกันหมด ต่างกันแค่ตัวอักษรจางๆ ที่มุมขวา
 * ซึ่งเป็นตัวบอกว่าเลขที่พิมพ์ลงไปหมายถึงอะไร — กรอกจำนวนเหรียญลงช่องมูลค่ารวม
 * แล้วสั่งซื้อ คือสั่งผิดขนาดหลายเท่าตัวโดยที่ทุกอย่างดูปกติ
 *
 * ให้สองสกุลคนละสีจึงแยกออกได้ด้วยการชำเลืองมอง ไม่ต้องอ่านตัวอักษร
 * เขียวอมฟ้า = สกุลหลักที่จ่าย/ได้รับ · ส้ม = เหรียญที่กำลังซื้อขาย
 */
.quote-tint {
    @apply text-primary-300 font-semibold;
}

.base-tint {
    @apply text-warm-300 font-semibold;
}
</style>
