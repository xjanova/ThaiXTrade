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
    // ตัวเลข preview จาก quote จริงของ router (parent format มาให้พร้อมแสดง)
    marketPreview: { type: Object, default: null },
});

const emit = defineEmits(['submit-order', 'connect-wallet', 'form-change']);

const { t } = useTranslation();

const root = useTemplateRef('root');
const { width } = useElementSize(root);
/**
 * ResizeObserver อาจยังไม่ยิงในเฟรมแรก (และไม่ยิงเลยถ้าแท็บไม่ได้ compositing)
 * จึงวัดเองหนึ่งครั้งตอน mount แล้วใช้ค่าที่มากกว่า — กันฟอร์มเรนเดอร์ผังแคบ
 * ทั้งที่วางอยู่ใต้กราฟกว้างๆ
 */
const initialWidth = ref(0);
const cardWidth = computed(() => Math.max(width.value, initialWidth.value));
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

// Fee rate from backend (default 0.1%, fetched on mount)
const feeRate = ref(0.1);
const feeAmount = computed(() => (toNumber(total.value) * (feeRate.value / 100)).toFixed(2));

onMounted(async () => {
    if (root.value) initialWidth.value = root.value.getBoundingClientRect().width;

    try {
        const { data } = await axios.get('/api/v1/swap/routes');
        if (data.success && data.data?.length > 0) {
            feeRate.value = data.data[0].fee_rate ?? 0.1;
        }
    } catch { /* keep default */ }
});

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

const submitOrder = () => {
    if (props.isSubmitting) return;

    if (!isConnected.value) {
        emit('connect-wallet');
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
                        <span class="input-suffix text-xs">{{ quoteSymbol }}</span>
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
                        <span class="input-suffix text-xs">{{ baseSymbol }}</span>
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
                        <span class="input-suffix text-xs">{{ quoteSymbol }}</span>
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

            <!-- ค่าธรรมเนียม — โหมด onchain ใช้ค่าจริงจาก quote, โหมดอื่นใช้ rate จาก backend -->
            <div class="flex items-center justify-between gap-2 mb-3 text-xs text-dark-400">
                <template v-if="mode === 'onchain' && marketPreview && marketPreview.feeText">
                    <span>{{ t('trade.form.fee') }}</span>
                    <span class="font-mono truncate">{{ marketPreview.feeText }}</span>
                </template>
                <template v-else>
                    <span>{{ t('trade.form.fee') }} ({{ feeRate }}%)</span>
                    <span class="font-mono">~${{ feeAmount }}</span>
                </template>
            </div>

            <button
                type="button"
                :disabled="isSubmitting"
                :class="[
                    'w-full py-3 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2',
                    isSubmitting ? 'opacity-60 cursor-not-allowed' : '',
                    !isConnected
                        ? 'bg-primary-500 hover:bg-primary-600 text-white'
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
                    : activeTab === 'buy' ? t('trade.form.buySymbol', { symbol: baseSymbol })
                    : t('trade.form.sellSymbol', { symbol: baseSymbol })
                }}
            </button>

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
