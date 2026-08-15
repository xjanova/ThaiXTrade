<script setup>
/**
 * TPIX TRADE - Trade Form Component
 * Buy/Sell order form with real wallet balance integration
 * ป้องกันกดซ้ำ + loading state + validation
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import { playClickSound } from '@/Composables/useSounds';
import axios from 'axios';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    tickerPrice: { type: Number, default: 0 },
    selectedPrice: { type: [Number, null], default: null },
    isWalletConnected: { type: Boolean, default: false },
    isSubmitting: { type: Boolean, default: false },
    balances: { type: Array, default: () => [] },
    // โหมดของฟอร์ม:
    //  'onchain'  = market order เทรดจริงบน BSC ผ่าน PancakeSwap (limit ยังไม่เปิด)
    //  'disabled' = คู่เทรดยังไม่เปิด (เช่น TPIX รอเชน TPIX พร้อม) — โชว์ Coming soon
    //  'internal' = order book ภายใน (พฤติกรรมเดิม — ใช้เมื่อ TPIX Chain เปิด)
    mode: { type: String, default: 'internal' },
    // ตัวเลข preview จาก quote จริงของ router (parent format มาให้พร้อมแสดง)
    // { receiveText, minReceivedText, feeText, loading }
    marketPreview: { type: Object, default: null },
});

const emit = defineEmits(['submit-order', 'connect-wallet', 'form-change']);

const isConnected = computed(() => props.isWalletConnected);

const activeTab = ref('buy');
const orderType = ref(props.mode === 'onchain' ? 'market' : 'limit');
const price = ref('');
const amount = ref('');
const total = ref('');
const sliderValue = ref(0);

// หมายเหตุ: stop-limit ยังไม่รองรับใน launch นี้ (ต้องมี trigger job ใน backend)
// เปิดกลับคืนเมื่อมี OrderTriggerJob + trigger price input field พร้อม
// โหมด onchain: limit โชว์ไว้แต่กดไม่ได้ (AMM ทำ limit order ไม่ได้จนกว่า
// order book บน TPIX Chain จะเปิด) — market เท่านั้นที่ execute จริง
const orderTypes = computed(() => {
    if (props.mode === 'onchain') {
        return [
            { value: 'limit', label: 'Limit', disabled: true },
            { value: 'market', label: 'Market' },
        ];
    }
    return [
        { value: 'limit', label: 'Limit' },
        { value: 'market', label: 'Market' },
    ];
});

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

// Get available balance for the relevant token
const availableBalance = computed(() => {
    if (!props.balances || props.balances.length === 0) return '0';
    const tokenSymbol = activeTab.value === 'buy' ? quoteSymbol.value : baseSymbol.value;
    const found = props.balances.find(b =>
        b.symbol?.toUpperCase() === tokenSymbol.toUpperCase()
    );
    return found ? parseFloat(found.balance).toFixed(6) : '0';
});

// Fill price from OrderBook click
watch(() => props.selectedPrice, (val) => {
    if (val && orderType.value === 'limit') {
        price.value = String(val);
        calculateTotal();
    }
});

// Fee rate from backend (default 0.1%, fetched on mount)
const feeRate = ref(0.1);

const feeAmount = computed(() => {
    const totalNum = parseFloat(String(total.value).replace(/,/g, '')) || 0;
    return (totalNum * (feeRate.value / 100)).toFixed(2);
});

onMounted(async () => {
    try {
        const { data } = await axios.get('/api/v1/swap/routes');
        if (data.success && data.data?.length > 0) {
            feeRate.value = data.data[0].fee_rate ?? 0.1;
        }
    } catch { /* keep default */ }
});

// Update price field when ticker changes (only if user hasn't typed yet)
watch(() => props.tickerPrice, (newPrice) => {
    if (newPrice > 0 && !price.value) {
        price.value = newPrice >= 1
            ? newPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : newPrice.toFixed(8);
    }
}, { immediate: true });

const calculateTotal = () => {
    const priceNum = parseFloat(String(price.value).replace(/,/g, '')) || 0;
    const amountNum = parseFloat(amount.value) || 0;
    total.value = (priceNum * amountNum).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
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

    const priceNum = parseFloat(String(price.value).replace(/,/g, '')) || 0;
    if (priceNum <= 0) return;

    if (activeTab.value === 'buy') {
        const spendAmount = balance * (percent / 100);
        amount.value = (spendAmount / priceNum).toFixed(6);
    } else {
        amount.value = (balance * (percent / 100)).toFixed(6);
    }

    calculateTotal();
};

const setMarketPrice = () => {
    if (props.tickerPrice > 0) {
        price.value = props.tickerPrice >= 1
            ? props.tickerPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : props.tickerPrice.toFixed(8);
        calculateTotal();
    }
};

const submitOrder = () => {
    // ป้องกันกดซ้ำ
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
    });
};
</script>

<template>
    <div class="glass-dark rounded-2xl p-3">
        <!-- คู่เทรดยังไม่เปิด (รอ TPIX Chain) — โชว์ไว้ก่อน กดไม่ได้ -->
        <div v-if="mode === 'disabled'" class="py-10 px-4 text-center">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-primary-500/10 flex items-center justify-center">
                <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <p class="text-white font-semibold text-sm mb-1">{{ symbol }} — Coming Soon</p>
            <p class="text-dark-400 text-xs leading-relaxed">
                Trading opens with TPIX Chain launch.<br>
                Meanwhile, trade major pairs live on BSC.
            </p>
            <span class="inline-block mt-3 text-[10px] px-2 py-1 rounded bg-amber-500/15 text-amber-400 font-medium">
                TPIX Chain — Coming Soon
            </span>
        </div>

        <template v-else>
        <!-- Buy/Sell Tabs -->
        <div class="flex gap-2 mb-3">
            <button
                @click="activeTab = 'buy'; playClickSound()"
                :class="[
                    'flex-1 py-2.5 rounded-xl font-semibold text-sm transition-all',
                    activeTab === 'buy'
                        ? 'bg-trading-green text-white shadow-green-glow'
                        : 'bg-dark-800 text-dark-400 hover:text-white'
                ]"
            >
                Buy
            </button>
            <button
                @click="activeTab = 'sell'; playClickSound()"
                :class="[
                    'flex-1 py-2.5 rounded-xl font-semibold text-sm transition-all',
                    activeTab === 'sell'
                        ? 'bg-trading-red text-white shadow-red-glow'
                        : 'bg-dark-800 text-dark-400 hover:text-white'
                ]"
            >
                Sell
            </button>
        </div>

        <!-- Available Balance -->
        <div v-if="isConnected" class="flex items-center justify-between mb-2 text-xs text-dark-400">
            <span>Available</span>
            <span class="font-mono">
                {{ availableBalance }} {{ activeTab === 'buy' ? quoteSymbol : baseSymbol }}
            </span>
        </div>

        <!-- Order Type — limit ในโหมด onchain โชว์ไว้แต่กดไม่ได้ (รอ TPIX Chain) -->
        <div class="flex gap-1 mb-3 p-0.5 bg-dark-800 rounded-lg">
            <button
                v-for="type in orderTypes"
                :key="type.value"
                @click="!type.disabled && (orderType = type.value)"
                :disabled="type.disabled"
                :title="type.disabled ? 'Limit orders open with TPIX Chain launch' : ''"
                :class="[
                    'flex-1 py-1.5 text-xs font-medium rounded-md transition-all',
                    orderType === type.value ? 'bg-dark-600 text-white' : 'text-dark-400',
                    type.disabled ? 'opacity-40 cursor-not-allowed' : 'hover:text-white'
                ]"
            >
                {{ type.label }}
                <span v-if="type.disabled" class="ml-0.5 text-[9px] text-amber-400">Soon</span>
            </button>
        </div>

        <!-- Price Input -->
        <div class="mb-2">
            <div class="flex items-center justify-between mb-1">
                <label class="text-xs text-dark-400">Price</label>
                <button
                    v-if="orderType !== 'market'"
                    @click="setMarketPrice"
                    class="text-xs text-primary-400 hover:text-primary-300"
                >
                    Market
                </button>
            </div>
            <div class="trading-input-group">
                <input
                    v-model="price"
                    type="text"
                    :disabled="orderType === 'market'"
                    :placeholder="orderType === 'market' ? 'Market Price' : '0.00'"
                    class="trading-input pr-14 font-mono text-sm"
                    :class="{ 'opacity-50': orderType === 'market' }"
                    @input="calculateTotal"
                >
                <span class="input-suffix text-xs">{{ quoteSymbol }}</span>
            </div>
        </div>

        <!-- Amount Input -->
        <div class="mb-2">
            <label class="block text-xs text-dark-400 mb-1">Amount</label>
            <div class="trading-input-group">
                <input
                    v-model="amount"
                    type="text"
                    class="trading-input pr-14 font-mono text-sm"
                    placeholder="0.00"
                    @input="calculateTotal"
                >
                <span class="input-suffix text-xs">{{ baseSymbol }}</span>
            </div>
        </div>

        <!-- Percentage Slider -->
        <div class="mb-3">
            <div class="flex justify-between gap-1">
                <button
                    v-for="percent in sliderPercentages"
                    :key="percent"
                    @click="setSliderValue(percent)"
                    :class="[
                        'flex-1 py-1 text-xs font-medium rounded-md transition-all',
                        sliderValue === percent
                            ? activeTab === 'buy' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red'
                            : 'bg-dark-800 text-dark-400 hover:text-white'
                    ]"
                >
                    {{ percent }}%
                </button>
            </div>
        </div>

        <!-- Total -->
        <div class="mb-3">
            <label class="block text-xs text-dark-400 mb-1">Total</label>
            <div class="trading-input-group">
                <input
                    v-model="total"
                    type="text"
                    class="trading-input pr-14 font-mono text-sm"
                    placeholder="0.00"
                    readonly
                >
                <span class="input-suffix text-xs">{{ quoteSymbol }}</span>
            </div>
        </div>

        <!-- Market preview — ตัวเลขจริงจาก PancakeSwap router (เฉพาะโหมด onchain) -->
        <div v-if="mode === 'onchain' && orderType === 'market' && marketPreview" class="mb-3 px-3 py-2 rounded-lg bg-dark-800/60 border border-white/5 space-y-1">
            <div class="flex items-center justify-between text-xs">
                <span class="text-dark-400">You receive ≈</span>
                <span v-if="marketPreview.loading" class="skeleton w-20 h-3"></span>
                <span v-else class="font-mono text-white">{{ marketPreview.receiveText || '—' }}</span>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="text-dark-400">Min received</span>
                <span v-if="marketPreview.loading" class="skeleton w-16 h-3"></span>
                <span v-else class="font-mono text-dark-300">{{ marketPreview.minReceivedText || '—' }}</span>
            </div>
            <div class="flex items-center justify-between text-[11px] text-dark-500">
                <span>Executed on BSC via PancakeSwap</span>
            </div>
        </div>

        <!-- Fee Info — โหมด onchain ใช้ค่าจริงจาก quote, โหมดอื่นใช้ rate จาก backend -->
        <div class="flex items-center justify-between mb-3 text-xs text-dark-400">
            <template v-if="mode === 'onchain' && marketPreview && marketPreview.feeText">
                <span>Fee</span>
                <span class="font-mono">{{ marketPreview.feeText }}</span>
            </template>
            <template v-else>
                <span>Fee ({{ feeRate }}%)</span>
                <span class="font-mono">~${{ feeAmount }}</span>
            </template>
        </div>

        <!-- Submit Button -->
        <button
            @click="submitOrder"
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
        >
            <div v-if="isSubmitting" class="spinner !w-4 !h-4 !border-white/30 !border-t-white"></div>
            {{ isSubmitting ? 'Processing...' : !isConnected ? 'Connect Wallet' : activeTab === 'buy' ? `Buy ${baseSymbol}` : `Sell ${baseSymbol}` }}
        </button>

        <!-- TP/SL Options — ยังไม่มีจริงในโหมด onchain (AMM) จึงซ่อนไว้ -->
        <div v-if="mode !== 'onchain'" class="mt-3 flex items-center justify-between text-xs">
            <label class="flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3 h-3">
                <span class="text-dark-400">TP/SL</span>
            </label>
            <button class="text-primary-400 hover:text-primary-300 transition-colors">
                Advanced Options
            </button>
        </div>
        </template>
    </div>
</template>
