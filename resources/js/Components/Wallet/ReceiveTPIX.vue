<script setup>
/**
 * TPIX TRADE — Receive TPIX Component
 * แสดง QR Code + address สำหรับรับ TPIX
 * Developed by Xman Studio.
 */
import { ref, onMounted } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import QRCode from 'qrcode';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const emit = defineEmits(['close']);
const walletStore = useWalletStore();

const qrDataUrl = ref('');
const copied = ref(false);

onMounted(async () => {
    if (walletStore.address) {
        qrDataUrl.value = await QRCode.toDataURL(walletStore.address, {
            width: 200,
            margin: 2,
            color: { dark: '#06B6D4', light: '#0f1117' },
        });
    }
});

function copyAddress() {
    navigator.clipboard.writeText(walletStore.address);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
}
</script>

<template>
    <div class="text-center space-y-5">
        <h3 class="text-lg font-bold text-white">{{ t('ui.walletReceiveTPIX.title') }}</h3>
        <p class="text-dark-400 text-sm">{{ t('ui.walletReceiveTPIX.subtitle') }}</p>

        <!-- QR Code -->
        <div class="flex justify-center">
            <div class="bg-dark-900 rounded-2xl p-4 border border-dark-600">
                <img v-if="qrDataUrl" :src="qrDataUrl" :alt="t('ui.walletReceiveTPIX.qrAlt')" class="w-48 h-48" />
                <div v-else class="w-48 h-48 flex items-center justify-center text-dark-500">{{ t('ui.walletReceiveTPIX.loading') }}</div>
            </div>
        </div>

        <!-- Address -->
        <div class="bg-dark-800 rounded-xl p-3">
            <p class="text-white text-xs font-mono break-all">{{ walletStore.address }}</p>
        </div>

        <!-- Copy button -->
        <button @click="copyAddress"
            :class="copied ? 'bg-trading-green text-white' : 'bg-dark-700 hover:bg-dark-600 text-dark-300'"
            class="w-full py-3 rounded-xl font-medium transition-colors text-sm">
            {{ copied ? t('ui.walletReceiveTPIX.copied') : t('ui.walletReceiveTPIX.copyAddress') }}
        </button>

        <div class="bg-primary-500/10 border border-primary-500/30 rounded-lg px-3 py-2">
            <p class="text-primary-300 text-xs">{{ t('ui.walletReceiveTPIX.chainOnly', { id: 4289 }) }}</p>
            <p class="text-primary-300/60 text-xs mt-1">{{ t('ui.walletReceiveTPIX.warning') }}</p>
        </div>

        <button @click="emit('close')" class="w-full py-2 text-dark-400 hover:text-white transition-colors text-sm">{{ t('ui.walletReceiveTPIX.close') }}</button>
    </div>
</template>
