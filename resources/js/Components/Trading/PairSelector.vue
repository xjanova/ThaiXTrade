<script setup>
/**
 * TPIX TRADE — Pair Selector
 * เลือกคู่เทรดจาก dropdown — แสดงราคา + % change
 * Developed by Xman Studio.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { getCoinLogo } from '@/utils/cryptoLogos';
import axios from 'axios';

const props = defineProps({
    currentPair: { type: String, default: 'BTC/USDT' },
});

const isOpen = ref(false);
const search = ref('');
const tickers = ref([]);

// ดึง tickers จาก API
async function fetchTickers() {
    try {
        const { data } = await axios.get('/api/v1/market/tickers');
        if (data.success) {
            tickers.value = data.data.map(t => ({
                symbol: `${t.baseAsset}/${t.quoteAsset}`,
                pair: `${t.baseAsset}-${t.quoteAsset}`,
                base: t.baseAsset,
                price: parseFloat(t.price),
                change: parseFloat(t.priceChangePercent),
                volume: parseFloat(t.quoteVolume || 0),
            }));
        }
        // เพิ่ม TPIX/USDT ที่หัว (ถ้ายังไม่มี)
        if (!tickers.value.find(t => t.base === 'TPIX')) {
            tickers.value.unshift({
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX',
                price: 0.10, change: 0, volume: 0, isTpix: true,
            });
        }
    } catch {
        // Fallback — แค่ TPIX
        tickers.value = [{ symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX', price: 0.10, change: 0, volume: 0, isTpix: true }];
    }
}

const filtered = computed(() => {
    if (!search.value) return tickers.value.slice(0, 30);
    const q = search.value.toUpperCase();
    return tickers.value.filter(t => t.symbol.includes(q) || t.base.includes(q)).slice(0, 30);
});

function selectPair(t) {
    isOpen.value = false;
    search.value = '';
    router.visit(`/trade/${t.pair}`);
}

function formatPrice(p) {
    if (p >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (p >= 1) return p.toFixed(2);
    if (p >= 0.01) return p.toFixed(4);
    return p.toFixed(8);
}

// ปิด dropdown เมื่อ click ข้างนอก
function handleClickOutside(e) {
    if (!e.target.closest('.pair-selector')) isOpen.value = false;
}
onMounted(() => { fetchTickers(); document.addEventListener('click', handleClickOutside); });
onUnmounted(() => { document.removeEventListener('click', handleClickOutside); });
</script>

<template>
    <div class="pair-selector relative">
        <!-- ปุ่มเปิด -->
        <button @click="isOpen = !isOpen"
            class="flex items-center gap-2 px-4 py-2 rounded-xl bg-dark-800/80 border border-white/10 hover:border-primary-500/50 transition-all">
            <span class="text-white font-bold text-lg">{{ currentPair }}</span>
            <svg :class="['w-4 h-4 text-dark-400 transition-transform', isOpen && 'rotate-180']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div v-if="isOpen" class="absolute top-full left-0 mt-2 w-80 bg-dark-800 border border-white/10 rounded-xl shadow-2xl z-50 overflow-hidden">
            <!-- ค้นหา -->
            <div class="p-3 border-b border-white/5">
                <input v-model="search" type="text" placeholder="ค้นหาคู่เทรด..." autofocus
                    class="w-full bg-dark-700 border border-dark-600 rounded-lg px-3 py-2 text-white text-sm placeholder-dark-500 focus:border-primary-500 outline-none" />
            </div>

            <!-- รายการ -->
            <div class="max-h-80 overflow-y-auto">
                <button v-for="t in filtered" :key="t.pair" @click="selectPair(t)"
                    :class="['w-full flex items-center justify-between px-4 py-2.5 hover:bg-white/5 transition-colors',
                        t.symbol === currentPair && 'bg-primary-500/10']">
                    <div class="flex items-center gap-2">
                        <img v-if="t.isTpix" src="/logo.png" class="w-5 h-5 rounded-full" alt="TPIX" />
                        <img v-else-if="getCoinLogo(t.base)" :src="getCoinLogo(t.base, 'thumb')" class="w-5 h-5 rounded-full" :alt="t.base" />
                        <div class="w-5 h-5 rounded-full bg-dark-600 flex items-center justify-center" v-else>
                            <span class="text-[8px] text-dark-400">{{ t.base.slice(0,2) }}</span>
                        </div>
                        <span class="text-white text-sm font-medium">{{ t.symbol }}</span>
                    </div>
                    <div class="text-right">
                        <p class="text-white text-sm font-mono">${{ formatPrice(t.price) }}</p>
                        <p :class="['text-xs', t.change >= 0 ? 'text-trading-green' : 'text-trading-red']">
                            {{ t.change >= 0 ? '+' : '' }}{{ t.change.toFixed(2) }}%
                        </p>
                    </div>
                </button>
                <p v-if="filtered.length === 0" class="text-dark-500 text-sm text-center py-6">ไม่พบคู่เทรด</p>
            </div>
        </div>
    </div>
</template>
