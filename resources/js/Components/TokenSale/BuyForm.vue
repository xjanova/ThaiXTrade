<script setup>
/**
 * BuyForm — ฟอร์มซื้อเหรียญ TPIX
 * เลือกสกุลเงิน → กรอกจำนวน → ดู preview → ซื้อ
 * ส่ง transaction บน BSC แล้ว submit tx_hash ไป backend
 * Developed by Xman Studio
 */
import { ref, watch } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenSale } from '@/Composables/useTokenSale';

const walletStore = useWalletStore();
const {
    selectedCurrency,
    paymentAmount,
    preview,
    isLoadingPreview,
    isSendingTx,
    isSubmitting,
    error,
    txHash,
    purchaseResult,
    acceptCurrencies,
    currentPhase,
    currentPrice,
    canPurchase,
    calculatePreview,
    executePurchase,
    resetForm,
} = useTokenSale();

const emit = defineEmits(['purchase-complete']);

// สถานะ UI
const showSuccess = ref(false);

// คำนวณ preview เมื่อเปลี่ยน currency หรือ amount (debounce 500ms)
let debounceTimer = null;
watch([selectedCurrency, paymentAmount], () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(calculatePreview, 500);
});

// ไอคอนสกุลเงิน
const currencyIcons = {
    BNB: 'https://cryptologos.cc/logos/bnb-bnb-logo.svg',
    USDT: 'https://cryptologos.cc/logos/tether-usdt-logo.svg',
    BUSD: 'https://cryptologos.cc/logos/binance-usd-busd-logo.svg',
};

/**
 * ดำเนินการซื้อ
 */
async function handlePurchase() {
    try {
        await executePurchase();
        showSuccess.value = true;
        emit('purchase-complete');

        // ซ่อน success หลัง 10 วินาที
        setTimeout(() => {
            showSuccess.value = false;
        }, 10000);
    } catch {
        // error ถูกจัดการใน composable แล้ว
    }
}

function formatNumber(n) {
    if (!n) return '0';
    return Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 });
}
</script>

<template>
    <div class="buy-form glass-dark p-6 rounded-xl border border-white/10">
        <h3 class="text-xl font-bold text-white mb-1">Buy TPIX Tokens</h3>
        <p class="text-sm text-gray-400 mb-6">
            Current Price: <span class="text-primary-400 font-semibold">${{ currentPrice }}</span> per TPIX
        </p>

        <!-- ถ้ายังไม่เชื่อมต่อ wallet -->
        <div v-if="!walletStore.isConnected" class="text-center py-8">
            <div class="text-gray-400 mb-4">
                <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3" />
                </svg>
                Please connect your wallet to buy TPIX
            </div>
        </div>

        <!-- ถ้าไม่ได้อยู่บน BSC -->
        <div v-else-if="!walletStore.isBSC" class="text-center py-8">
            <div class="text-yellow-400 mb-4">
                <svg class="w-12 h-12 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
                Please switch to BSC Network
            </div>
            <button class="btn-primary px-6 py-2" @click="walletStore.switchChain(56)">
                Switch to BSC
            </button>
        </div>

        <!-- ถ้าไม่มี active phase -->
        <div v-else-if="!currentPhase" class="text-center py-8 text-gray-400">
            No active sale phase at the moment.
        </div>

        <!-- ฟอร์มซื้อ -->
        <div v-else>
            <!-- เลือกสกุลเงิน -->
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Pay With</label>
                <div class="flex gap-2">
                    <button
                        v-for="currency in acceptCurrencies"
                        :key="currency"
                        class="flex items-center gap-2 px-4 py-2.5 rounded-lg border transition-all text-sm font-medium"
                        :class="selectedCurrency === currency
                            ? 'border-primary-500 bg-primary-500/10 text-primary-400'
                            : 'border-white/10 bg-white/5 text-gray-300 hover:border-white/20'"
                        @click="selectedCurrency = currency"
                    >
                        <img :src="currencyIcons[currency]" :alt="currency" class="w-5 h-5" />
                        {{ currency }}
                    </button>
                </div>
            </div>

            <!-- กรอกจำนวนเงิน -->
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Amount ({{ selectedCurrency }})</label>
                <div class="relative">
                    <input
                        v-model="paymentAmount"
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0.00"
                        class="trading-input w-full pr-16"
                    />
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">
                        {{ selectedCurrency }}
                    </span>
                </div>
            </div>

            <!-- Preview — จำนวน TPIX ที่จะได้ -->
            <div v-if="preview" class="mb-4 p-4 rounded-lg bg-white/5 border border-white/10">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm text-gray-400">You Will Receive</span>
                    <span class="text-xl font-bold text-trading-green">
                        {{ formatNumber(preview.tpix_amount) }} TPIX
                    </span>
                </div>
                <div class="space-y-1 text-xs text-gray-400">
                    <div class="flex justify-between">
                        <span>Payment Value</span>
                        <span class="text-white">${{ formatNumber(preview.payment_usd_value) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Price per TPIX</span>
                        <span class="text-white">${{ preview.price_per_tpix }}</span>
                    </div>
                    <div v-if="preview.bonus_percent > 0" class="flex justify-between">
                        <span>Bonus</span>
                        <span class="text-trading-green">+{{ preview.bonus_percent }}%</span>
                    </div>
                </div>
            </div>

            <!-- Loading preview -->
            <div v-else-if="isLoadingPreview" class="mb-4 text-center py-3">
                <span class="text-sm text-gray-400">Calculating...</span>
            </div>

            <!-- Error -->
            <div v-if="error" class="mb-4 p-3 rounded-lg bg-trading-red/10 border border-trading-red/20">
                <p class="text-sm text-trading-red">{{ error }}</p>
            </div>

            <!-- Success -->
            <div v-if="showSuccess && purchaseResult" class="mb-4 p-4 rounded-lg bg-trading-green/10 border border-trading-green/20">
                <p class="text-sm text-trading-green font-semibold mb-1">Purchase Successful!</p>
                <p class="text-xs text-gray-300">
                    You purchased {{ formatNumber(purchaseResult.tpix_amount) }} TPIX
                </p>
            </div>

            <!-- Transaction progress -->
            <div v-if="isSendingTx || isSubmitting" class="mb-4 p-3 rounded-lg bg-primary-500/10 border border-primary-500/20">
                <div class="flex items-center gap-2">
                    <!-- Spinner -->
                    <svg class="animate-spin h-4 w-4 text-primary-400" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    <span class="text-sm text-primary-400">
                        {{ isSendingTx ? 'Sending transaction on BSC...' : 'Verifying purchase...' }}
                    </span>
                </div>
                <p v-if="txHash" class="text-xs text-gray-400 mt-1 font-mono truncate">
                    Tx: {{ txHash }}
                </p>
            </div>

            <!-- ปุ่มซื้อ -->
            <button
                class="w-full btn-success py-3 text-lg font-bold"
                :disabled="!canPurchase"
                :class="{ 'opacity-50 cursor-not-allowed': !canPurchase }"
                @click="handlePurchase"
            >
                {{ isSendingTx ? 'Confirming...' : isSubmitting ? 'Processing...' : 'Buy TPIX' }}
            </button>

            <!-- คำเตือน -->
            <p class="text-xs text-gray-500 mt-3 text-center">
                Payment is processed on BSC (BNB Smart Chain). Please ensure you have sufficient balance.
            </p>
        </div>
    </div>
</template>
