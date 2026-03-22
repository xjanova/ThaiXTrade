<script setup>
/**
 * TPIX TRADE - CoinIcon Component
 * แสดงโลโก้เหรียญพร้อม multi-source fallback
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { getCoinLogo, getCoinLogoFallback } from '@/utils/cryptoLogos';

const props = defineProps({
    symbol: { type: String, required: true },
    size: { type: String, default: 'md' },
    src: { type: String, default: null },
});

const imgFailed = ref(false);
const fallbackFailed = ref(false);

const primarySrc = computed(() => props.src || getCoinLogo(props.symbol));
const fallbackSrc = computed(() => getCoinLogoFallback(props.symbol));

const sizeClass = computed(() => {
    const sizes = {
        xs: 'w-4 h-4',
        sm: 'w-6 h-6',
        md: 'w-8 h-8',
        lg: 'w-10 h-10',
        xl: 'w-12 h-12',
    };
    return sizes[props.size] || sizes.md;
});

const initial = computed(() => {
    if (!props.symbol) return '?';
    return props.symbol.charAt(0).toUpperCase();
});

function onPrimaryError() {
    imgFailed.value = true;
}

function onFallbackError() {
    fallbackFailed.value = true;
}
</script>

<template>
    <div :class="['rounded-full overflow-hidden bg-dark-700 flex-shrink-0', sizeClass]">
        <!-- Primary source -->
        <img
            v-if="!imgFailed && primarySrc"
            :src="primarySrc"
            :alt="symbol"
            class="w-full h-full object-cover"
            loading="lazy"
            @error="onPrimaryError"
        />
        <!-- Fallback source (CryptoLogos.cc / Trust Wallet) -->
        <img
            v-else-if="!fallbackFailed && fallbackSrc"
            :src="fallbackSrc"
            :alt="symbol"
            class="w-full h-full object-cover"
            loading="lazy"
            @error="onFallbackError"
        />
        <!-- Letter placeholder -->
        <span v-else class="flex items-center justify-center w-full h-full text-xs text-white font-bold bg-gradient-to-br from-primary-500/50 to-accent-500/50">
            {{ initial }}
        </span>
    </div>
</template>
