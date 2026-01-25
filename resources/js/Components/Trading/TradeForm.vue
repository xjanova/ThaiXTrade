<script setup>
/**
 * ThaiXTrade - Trade Form Component
 * Buy/Sell order form
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';

const props = defineProps({
    symbol: {
        type: String,
        default: 'BTC/USDT'
    },
    balance: {
        type: Object,
        default: () => ({
            base: { symbol: 'BTC', amount: '0.5678' },
            quote: { symbol: 'USDT', amount: '15,234.50' }
        })
    }
});

const emit = defineEmits(['submit-order']);

const activeTab = ref('buy');
const orderType = ref('limit');
const price = ref('67,234.50');
const amount = ref('');
const total = ref('');
const sliderValue = ref(0);

const orderTypes = [
    { value: 'limit', label: 'Limit' },
    { value: 'market', label: 'Market' },
    { value: 'stop-limit', label: 'Stop Limit' },
];

const sliderPercentages = [0, 25, 50, 75, 100];

const calculateTotal = () => {
    const priceNum = parseFloat(price.value.replace(',', '')) || 0;
    const amountNum = parseFloat(amount.value) || 0;
    total.value = (priceNum * amountNum).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
};

const setSliderValue = (percent) => {
    sliderValue.value = percent;
    const balance = parseFloat(props.balance.quote.amount.replace(',', '')) || 0;
    const priceNum = parseFloat(price.value.replace(',', '')) || 0;

    if (activeTab.value === 'buy' && priceNum > 0) {
        const maxAmount = (balance * percent / 100) / priceNum;
        amount.value = maxAmount.toFixed(6);
    } else {
        const baseBalance = parseFloat(props.balance.base.amount) || 0;
        amount.value = (baseBalance * percent / 100).toFixed(6);
    }
    calculateTotal();
};

const submitOrder = () => {
    emit('submit-order', {
        side: activeTab.value,
        type: orderType.value,
        price: price.value,
        amount: amount.value,
        total: total.value
    });
};
</script>

<template>
    <div class="glass-dark rounded-2xl p-4">
        <!-- Buy/Sell Tabs -->
        <div class="flex gap-2 mb-4">
            <button
                @click="activeTab = 'buy'"
                :class="[
                    'flex-1 py-3 rounded-xl font-semibold transition-all',
                    activeTab === 'buy'
                        ? 'bg-trading-green text-white shadow-green-glow/50'
                        : 'bg-dark-800 text-dark-400 hover:text-white'
                ]"
            >
                Buy
            </button>
            <button
                @click="activeTab = 'sell'"
                :class="[
                    'flex-1 py-3 rounded-xl font-semibold transition-all',
                    activeTab === 'sell'
                        ? 'bg-trading-red text-white shadow-red-glow/50'
                        : 'bg-dark-800 text-dark-400 hover:text-white'
                ]"
            >
                Sell
            </button>
        </div>

        <!-- Order Type Selector -->
        <div class="flex gap-1 mb-4 p-1 bg-dark-800 rounded-xl">
            <button
                v-for="type in orderTypes"
                :key="type.value"
                @click="orderType = type.value"
                :class="[
                    'flex-1 py-2 text-sm font-medium rounded-lg transition-all',
                    orderType === type.value
                        ? 'bg-dark-600 text-white'
                        : 'text-dark-400 hover:text-white'
                ]"
            >
                {{ type.label }}
            </button>
        </div>

        <!-- Balance Display -->
        <div class="flex items-center justify-between mb-4 text-sm">
            <span class="text-dark-400">Available</span>
            <span class="font-mono text-white">
                {{ activeTab === 'buy' ? balance.quote.amount : balance.base.amount }}
                <span class="text-dark-400">{{ activeTab === 'buy' ? balance.quote.symbol : balance.base.symbol }}</span>
            </span>
        </div>

        <!-- Price Input -->
        <div class="mb-3">
            <label class="block text-sm text-dark-400 mb-2">Price</label>
            <div class="trading-input-group">
                <input
                    v-model="price"
                    type="text"
                    :disabled="orderType === 'market'"
                    class="trading-input pr-16 font-mono"
                    :class="{ 'opacity-50': orderType === 'market' }"
                    placeholder="0.00"
                    @input="calculateTotal"
                >
                <span class="input-suffix">USDT</span>
            </div>
        </div>

        <!-- Amount Input -->
        <div class="mb-3">
            <label class="block text-sm text-dark-400 mb-2">Amount</label>
            <div class="trading-input-group">
                <input
                    v-model="amount"
                    type="text"
                    class="trading-input pr-16 font-mono"
                    placeholder="0.00"
                    @input="calculateTotal"
                >
                <span class="input-suffix">BTC</span>
            </div>
        </div>

        <!-- Percentage Slider -->
        <div class="mb-4">
            <div class="flex justify-between gap-2">
                <button
                    v-for="percent in sliderPercentages"
                    :key="percent"
                    @click="setSliderValue(percent)"
                    :class="[
                        'flex-1 py-1.5 text-xs font-medium rounded-lg transition-all',
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
        <div class="mb-4">
            <label class="block text-sm text-dark-400 mb-2">Total</label>
            <div class="trading-input-group">
                <input
                    v-model="total"
                    type="text"
                    class="trading-input pr-16 font-mono"
                    placeholder="0.00"
                    readonly
                >
                <span class="input-suffix">USDT</span>
            </div>
        </div>

        <!-- Fee Info -->
        <div class="flex items-center justify-between mb-4 text-xs text-dark-400">
            <span>Fee (0.1%)</span>
            <span class="font-mono">~$0.00</span>
        </div>

        <!-- Submit Button -->
        <button
            @click="submitOrder"
            :class="[
                'w-full py-4 rounded-xl font-bold text-lg transition-all',
                activeTab === 'buy'
                    ? 'btn-success'
                    : 'btn-danger'
            ]"
        >
            {{ activeTab === 'buy' ? 'Buy BTC' : 'Sell BTC' }}
        </button>

        <!-- Additional Options -->
        <div class="mt-4 flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500">
                <span class="text-dark-400">TP/SL</span>
            </label>
            <button class="text-primary-400 hover:text-primary-300 transition-colors">
                Advanced Options
            </button>
        </div>
    </div>
</template>
