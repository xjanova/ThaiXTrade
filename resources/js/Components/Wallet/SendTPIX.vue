<script setup>
/**
 * TPIX TRADE — Send TPIX Component
 * ส่ง TPIX ผ่าน embedded wallet — gasless!
 * Developed by Xman Studio.
 */
import { ref, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const emit = defineEmits(['close']);
const walletStore = useWalletStore();

const toAddress = ref('');
const amount = ref('');
const step = ref('form'); // 'form', 'confirm', 'sending', 'done', 'error'
const txHash = ref('');
const errorMsg = ref('');
// เก็บคีย์ไว้แทนข้อความ — สลับภาษาแล้วข้อความ error ที่ค้างบนจอเปลี่ยนตาม
const errorKey = ref('');
const errorText = computed(() => (errorKey.value ? t(errorKey.value) : errorMsg.value));

const maxAmount = computed(() => parseFloat(walletStore.tpixBalance || '0'));

function setMax() {
    amount.value = maxAmount.value.toString();
}

function preview() {
    errorMsg.value = '';
    errorKey.value = '';
    if (!toAddress.value.match(/^0x[a-fA-F0-9]{40}$/)) {
        errorKey.value = 'ui.walletSendTPIX.errors.invalidAddress';
        return;
    }
    if (!amount.value || parseFloat(amount.value) <= 0) {
        errorKey.value = 'ui.walletSendTPIX.errors.enterAmount';
        return;
    }
    if (parseFloat(amount.value) > maxAmount.value) {
        errorKey.value = 'ui.walletSendTPIX.errors.exceedsBalance';
        return;
    }
    step.value = 'confirm';
}

async function send() {
    step.value = 'sending';
    try {
        const tx = await walletStore.sendTPIX(toAddress.value, amount.value);
        txHash.value = tx.hash;
        step.value = 'done';
    } catch (err) {
        errorKey.value = '';
        errorMsg.value = err?.message || '';
        if (!errorMsg.value) errorKey.value = 'ui.walletSendTPIX.errors.sendFailed';
        step.value = 'error';
    }
}

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors text-sm';
</script>

<template>
    <div class="space-y-4">
        <!-- Form -->
        <template v-if="step === 'form'">
            <h3 class="text-lg font-bold text-white">{{ t('ui.walletSendTPIX.form.title') }}</h3>

            <div>
                <label class="text-dark-400 text-sm mb-1 block">{{ t('ui.walletSendTPIX.form.toAddress') }}</label>
                <input v-model="toAddress" :class="inputClass" placeholder="0x..." />
            </div>

            <div>
                <label class="text-dark-400 text-sm mb-1 block">{{ t('ui.walletSendTPIX.form.amount') }}</label>
                <div class="relative">
                    <input v-model="amount" type="number" :class="inputClass" placeholder="0.00" step="any" />
                    <button @click="setMax" class="absolute right-3 top-1/2 -translate-y-1/2 text-primary-400 text-xs font-medium hover:text-primary-300">{{ t('ui.walletSendTPIX.form.max') }}</button>
                </div>
                <p class="text-dark-500 text-xs mt-1">{{ t('ui.walletSendTPIX.form.balance', { amount: parseFloat(walletStore.tpixBalance || 0).toLocaleString() }) }}</p>
            </div>

            <div class="bg-trading-green/10 border border-trading-green/30 rounded-lg px-3 py-2">
                <p class="text-trading-green text-xs font-medium">{{ t('ui.walletSendTPIX.form.gasFree') }}</p>
            </div>

            <p v-if="errorText" class="text-trading-red text-sm">{{ errorText }}</p>

            <div class="flex gap-3">
                <button @click="emit('close')" class="flex-1 py-2 text-dark-400 hover:text-white transition-colors">{{ t('ui.walletSendTPIX.form.cancel') }}</button>
                <button @click="preview" class="flex-1 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">{{ t('ui.walletSendTPIX.form.review') }}</button>
            </div>
        </template>

        <!-- Confirm -->
        <template v-if="step === 'confirm'">
            <h3 class="text-lg font-bold text-white">{{ t('ui.walletSendTPIX.confirm.title') }}</h3>
            <div class="bg-dark-800 rounded-xl p-4 space-y-3">
                <div class="flex justify-between"><span class="text-dark-400 text-sm">{{ t('ui.walletSendTPIX.confirm.sendTo') }}</span><span class="text-white text-sm font-mono break-all">{{ toAddress }}</span></div>
                <div class="flex justify-between"><span class="text-dark-400 text-sm">{{ t('ui.walletSendTPIX.confirm.amount') }}</span><span class="text-white text-lg font-bold">{{ parseFloat(amount).toLocaleString() }} TPIX</span></div>
                <div class="flex justify-between"><span class="text-dark-400 text-sm">{{ t('ui.walletSendTPIX.confirm.gas') }}</span><span class="text-trading-green text-sm font-medium">{{ t('ui.walletSendTPIX.confirm.free') }}</span></div>
            </div>
            <div class="flex gap-3">
                <button @click="step = 'form'" class="flex-1 py-2 text-dark-400 hover:text-white transition-colors">{{ t('ui.walletSendTPIX.confirm.edit') }}</button>
                <button @click="send" class="flex-1 py-3 bg-trading-green hover:bg-green-600 text-white rounded-xl font-medium transition-colors">{{ t('ui.walletSendTPIX.confirm.sendNow') }}</button>
            </div>
        </template>

        <!-- Sending -->
        <template v-if="step === 'sending'">
            <div class="text-center py-8 space-y-4">
                <div class="w-12 h-12 mx-auto border-4 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
                <p class="text-white font-medium">{{ t('ui.walletSendTPIX.sending') }}</p>
            </div>
        </template>

        <!-- Done -->
        <template v-if="step === 'done'">
            <div class="text-center space-y-4">
                <div class="w-16 h-16 mx-auto rounded-full bg-trading-green/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                </div>
                <h3 class="text-xl font-bold text-white">{{ t('ui.walletSendTPIX.done.title') }}</h3>
                <p class="text-dark-400 text-sm">{{ parseFloat(amount).toLocaleString() }} TPIX</p>
                <a :href="`https://explorer.tpix.online/tx/${txHash}`" target="_blank" class="text-primary-400 hover:text-primary-300 text-sm underline block">{{ t('ui.walletSendTPIX.done.viewExplorer') }}</a>
                <button @click="emit('close')" class="w-full py-3 bg-dark-700 hover:bg-dark-600 text-white rounded-xl font-medium transition-colors">{{ t('ui.walletSendTPIX.done.close') }}</button>
            </div>
        </template>

        <!-- Error -->
        <template v-if="step === 'error'">
            <div class="text-center space-y-4">
                <div class="w-16 h-16 mx-auto rounded-full bg-trading-red/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-trading-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </div>
                <h3 class="text-lg font-bold text-white">{{ t('ui.walletSendTPIX.error.title') }}</h3>
                <p class="text-dark-400 text-sm">{{ errorText }}</p>
                <button @click="step = 'form'" class="w-full py-3 bg-dark-700 hover:bg-dark-600 text-white rounded-xl font-medium transition-colors">{{ t('ui.walletSendTPIX.error.retry') }}</button>
            </div>
        </template>
    </div>
</template>
