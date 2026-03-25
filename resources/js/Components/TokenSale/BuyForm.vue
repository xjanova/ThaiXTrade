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
import { isMobile, downloadTpixApp } from '@/utils/mobileWallet';

const walletStore = useWalletStore();
const mobile = isMobile();
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

/**
 * ชำระเงินผ่าน Stripe (credit card)
 * ต้องมี preview ก่อนเพื่อให้ได้ amount_usd และ phase_id ที่ถูกต้อง
 */
async function handleStripeCheckout() {
    if (!walletStore.isConnected || !paymentAmount.value) return;

    // ต้องมี preview เพื่อให้ได้ phase_id และ USD value ที่ถูกต้อง
    if (!preview.value || !preview.value.phase_id) {
        error.value = 'กรุณารอระบบคำนวณราคาก่อน (Please wait for price calculation)';
        await calculatePreview();
        return;
    }

    // ตรวจสอบว่า phase_id มีค่าจริง
    if (!currentPhase.value?.id) {
        error.value = 'No active sale phase available.';
        return;
    }

    try {
        const res = await fetch('/api/v1/token-sale/stripe/checkout', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Wallet-Address': walletStore.address,
            },
            body: JSON.stringify({
                wallet_address: walletStore.address,
                amount_usd: preview.value.payment_usd_value,
                phase_id: currentPhase.value.id,
            }),
        });
        const data = await res.json();
        if (data.success && data.data?.url) {
            window.location.href = data.data.url;
        } else {
            error.value = data.error?.message || 'Failed to create checkout session';
        }
    } catch (e) {
        error.value = e.message || 'Stripe checkout failed';
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
        <div v-if="!walletStore.isConnected" class="text-center py-6 space-y-4">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/20 flex items-center justify-center mx-auto">
                <svg class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3" />
                </svg>
            </div>
            <div>
                <p class="text-white font-semibold mb-1">เชื่อมต่อ Wallet เพื่อซื้อ TPIX</p>
                <p class="text-gray-400 text-xs">ยังไม่มีแอปกระเป๋า? สร้าง TPIX Wallet ได้เลย ไม่ต้องติดตั้ง</p>
            </div>

            <!-- ปุ่ม Connect Wallet (เปิด WalletModal ซึ่งมี TPIX Wallet ให้สร้างได้) -->
            <button
                @click="walletStore.openConnectModal()"
                class="w-full py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-semibold hover:shadow-lg hover:shadow-primary-500/20 transition-all"
            >
                Connect Wallet / สร้างกระเป๋า
            </button>

            <!-- Mobile: ปุ่มดาวน์โหลดแอป TPIX -->
            <button
                v-if="mobile"
                @click="downloadTpixApp"
                class="w-full py-2.5 rounded-xl font-medium text-sm bg-accent-500/10 border border-accent-500/20 text-accent-300 hover:bg-accent-500/20 transition-all"
            >
                Download TPIX App
            </button>
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

            <!-- Separator -->
            <div class="flex items-center gap-3 my-4">
                <div class="flex-1 border-t border-white/10"></div>
                <span class="text-xs text-gray-500">or pay with card</span>
                <div class="flex-1 border-t border-white/10"></div>
            </div>

            <!-- Stripe Button -->
            <button
                class="w-full py-3 rounded-xl font-semibold text-sm flex items-center justify-center gap-2 bg-[#635BFF]/20 border border-[#635BFF]/30 text-[#A8A3FF] hover:bg-[#635BFF]/30 transition-all"
                :disabled="!canPurchase || !paymentAmount"
                :class="{ 'opacity-50 cursor-not-allowed': !canPurchase || !paymentAmount }"
                @click="handleStripeCheckout"
            >
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-7.076-2.19L3.28 21.873C5.153 23.017 8.005 24 11.2 24c2.612 0 4.77-.593 6.312-1.758 1.678-1.27 2.53-3.155 2.53-5.565 0-4.124-2.505-5.765-6.066-7.527z"/>
                </svg>
                Pay with Credit Card (Stripe)
            </button>

            <!-- คำเตือน -->
            <p class="text-xs text-gray-500 mt-3 text-center">
                Crypto payments are processed on BSC. Card payments via Stripe.
            </p>
        </div>
    </div>
</template>
