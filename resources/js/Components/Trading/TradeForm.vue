<script setup>
/**
 * TPIX TRADE - Trade Form Component
 * Buy/Sell order form with real wallet integration
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    tickerPrice: { type: Number, default: 0 },
    isWalletConnected: { type: Boolean, default: false },
});

const emit = defineEmits(['submit-order', 'connect-wallet']);

const isConnected = computed(() => props.isWalletConnected);

const activeTab = ref('buy');
const orderType = ref('limit');
const price = ref('');
const amount = ref('');
const total = ref('');
const sliderValue = ref(0);

const orderTypes = [
    { value: 'limit', label: 'Limit' },
    { value: 'market', label: 'Market' },
    { value: 'stop-limit', label: 'Stop Limit' },
];

const sliderPercentages = [0, 25, 50, 75, 100];

const baseSymbol = computed(() => props.symbol.split('/')[0] || 'BTC');
const quoteSymbol = computed(() => props.symbol.split('/')[1] || 'USDT');

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
    // Placeholder: in production, calculate based on actual wallet balance
    if (percent === 0) {
        amount.value = '';
        total.value = '';
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
    if (!isConnected.value) {
        emit('connect-wallet');
        return;
    }
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
        <!-- Buy/Sell Tabs -->
        <div class="flex gap-2 mb-3">
            <button
                @click="activeTab = 'buy'"
                :class="[
                    'flex-1 py-2.5 rounded-xl font-semibold text-sm transition-all',
                    activeTab === 'buy'
                        ? 'bg-trading-green text-white'
                        : 'bg-dark-800 text-dark-400 hover:text-white'
                ]"
            >
                Buy
            </button>
            <button
                @click="activeTab = 'sell'"
                :class="[
                    'flex-1 py-2.5 rounded-xl font-semibold text-sm transition-all',
                    activeTab === 'sell'
                        ? 'bg-trading-red text-white'
                        : 'bg-dark-800 text-dark-400 hover:text-white'
                ]"
            >
                Sell
            </button>
        </div>

        <!-- Order Type -->
        <div class="flex gap-1 mb-3 p-0.5 bg-dark-800 rounded-lg">
            <button
                v-for="type in orderTypes"
                :key="type.value"
                @click="orderType = type.value"
                :class="[
                    'flex-1 py-1.5 text-xs font-medium rounded-md transition-all',
                    orderType === type.value ? 'bg-dark-600 text-white' : 'text-dark-400 hover:text-white'
                ]"
            >
                {{ type.label }}
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

        <!-- Fee Info -->
        <div class="flex items-center justify-between mb-3 text-xs text-dark-400">
            <span>Fee (0.1%)</span>
            <span class="font-mono">~$0.00</span>
        </div>

        <!-- Submit Button -->
        <button
            @click="submitOrder"
            :class="[
                'w-full py-3 rounded-xl font-bold text-sm transition-all',
                !isConnected
                    ? 'bg-primary-500 hover:bg-primary-600 text-white'
                    : activeTab === 'buy'
                        ? 'btn-success'
                        : 'btn-danger'
            ]"
        >
            {{ !isConnected ? 'Connect Wallet' : activeTab === 'buy' ? `Buy ${baseSymbol}` : `Sell ${baseSymbol}` }}
        </button>

        <!-- TP/SL Options -->
        <div class="mt-3 flex items-center justify-between text-xs">
            <label class="flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3 h-3">
                <span class="text-dark-400">TP/SL</span>
            </label>
            <button class="text-primary-400 hover:text-primary-300 transition-colors">
                Advanced Options
            </button>
        </div>
    </div>
</template>
