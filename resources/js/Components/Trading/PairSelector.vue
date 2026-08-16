<script setup>
/**
 * TPIX TRADE — Pair Selector
 * เลือกคู่เทรดจาก dropdown — ค้นหา, ตัวกรอง, จัดเรียง, เส้นกราฟย่อ 24 ชม. และแบ่งหน้า
 * Developed by Xman Studio.
 */
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CoinIcon from '@/Components/CoinIcon.vue';
import Sparkline from '@/Components/Trading/Sparkline.vue';
import { useSparklines } from '@/Composables/useSparklines';
import axios from 'axios';

const props = defineProps({
    currentPair: { type: String, default: 'BTC/USDT' },
});

const PAGE_SIZE = 8;
const FAV_KEY = 'tpix.favPairs';

const isOpen = ref(false);
const search = ref('');
const searchInput = ref(null);
const tickers = ref([]);
const isLoading = ref(true);
const activeFilter = ref('all'); // all | favorites | gainers | losers
const sortKey = ref('volume');   // volume | change | name
const page = ref(1);
const favorites = ref([]);

const { series, load: loadSparklines } = useSparklines();

const filters = [
    { id: 'all', label: 'ทั้งหมด' },
    { id: 'favorites', label: '★ รายการโปรด' },
    { id: 'gainers', label: 'ขึ้น' },
    { id: 'losers', label: 'ลง' },
];

const sorts = [
    { id: 'volume', label: 'วอลุ่ม' },
    { id: 'change', label: '% เปลี่ยน' },
    { id: 'name', label: 'ชื่อ' },
];

// ── รายการโปรด (เก็บใน localStorage ของเครื่องผู้ใช้) ────────────────────────
function readFavorites() {
    try {
        const raw = JSON.parse(localStorage.getItem(FAV_KEY) || '[]');
        favorites.value = Array.isArray(raw) ? raw.filter(v => typeof v === 'string') : [];
    } catch {
        favorites.value = [];
    }
}

function toggleFavorite(pair) {
    favorites.value = favorites.value.includes(pair)
        ? favorites.value.filter(p => p !== pair)
        : [...favorites.value, pair];
    try {
        localStorage.setItem(FAV_KEY, JSON.stringify(favorites.value));
    } catch {
        // โหมดส่วนตัว/โควตาเต็ม — ยังใช้งานต่อได้ในหน้านี้ แค่ไม่ถูกจำไว้
    }
}

// ── ข้อมูลตลาด ──────────────────────────────────────────────────────────────
async function fetchTickers() {
    try {
        const [tickerRes, pairRes] = await Promise.all([
            axios.get('/api/v1/market/tickers'),
            axios.get('/api/v1/market/pairs'),
        ]);

        // map symbol → base_logo จาก pairs DB (admin-managed)
        const logoMap = {};
        if (pairRes.data?.success) {
            pairRes.data.data.forEach(p => {
                logoMap[p.base_asset] = p.base_logo;
            });
        }

        if (tickerRes.data.success) {
            tickers.value = tickerRes.data.data.map(t => ({
                symbol: `${t.baseAsset}/${t.quoteAsset}`,
                pair: `${t.baseAsset}-${t.quoteAsset}`,
                base: t.baseAsset,
                logo: logoMap[t.baseAsset] || null,
                price: parseFloat(t.price),
                change: parseFloat(t.priceChangePercent),
                volume: parseFloat(t.quoteVolume || 0),
            }));
        }
        // เพิ่ม TPIX/USDT ที่หัว (ถ้ายังไม่มี)
        if (!tickers.value.find(t => t.base === 'TPIX')) {
            tickers.value.unshift({
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX',
                logo: logoMap['TPIX'] || '/tpixlogo.webp',
                price: 0.10, change: 0, volume: 0, isTpix: true,
            });
        }
    } catch {
        // Fallback — แค่ TPIX
        tickers.value = [{ symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX', logo: '/tpixlogo.webp', price: 0.10, change: 0, volume: 0, isTpix: true }];
    } finally {
        isLoading.value = false;
    }
}

// ── กรอง + จัดเรียง + แบ่งหน้า ───────────────────────────────────────────────
const filtered = computed(() => {
    const q = search.value.trim().toUpperCase();

    let list = tickers.value.filter(t => {
        if (q && !t.symbol.includes(q) && !t.base.includes(q)) return false;
        if (activeFilter.value === 'favorites') return favorites.value.includes(t.pair);
        if (activeFilter.value === 'gainers') return t.change > 0;
        if (activeFilter.value === 'losers') return t.change < 0;
        return true;
    });

    list = [...list].sort((a, b) => {
        if (sortKey.value === 'name') return a.symbol.localeCompare(b.symbol);
        if (sortKey.value === 'change') return b.change - a.change;
        return b.volume - a.volume;
    });

    // TPIX ปักหมุดบนสุดเสมอ (เหรียญของแพลตฟอร์ม) ยกเว้นตอนค้นหาเจาะจง
    if (!q) {
        const tpixIndex = list.findIndex(t => t.base === 'TPIX');
        if (tpixIndex > 0) list.unshift(list.splice(tpixIndex, 1)[0]);
    }

    return list;
});

const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PAGE_SIZE)));

const paged = computed(() => {
    const start = (page.value - 1) * PAGE_SIZE;
    return filtered.value.slice(start, start + PAGE_SIZE);
});

// เปลี่ยนตัวกรอง/ค้นหา/เรียง → กลับไปหน้า 1 ไม่งั้นค้างบนหน้าที่ไม่มีข้อมูลแล้ว
watch([search, activeFilter, sortKey], () => { page.value = 1; });

// กันหน้าเกินขอบเมื่อจำนวนผลลัพธ์หดลง (เช่น เอาออกจากรายการโปรด)
watch(totalPages, (n) => { if (page.value > n) page.value = n; });

// โหลดเส้นกราฟเฉพาะแถวที่มองเห็นจริงในหน้านั้น — ไม่ยิงทั้งลิสต์
watch([paged, isOpen], () => {
    if (!isOpen.value) return;
    const symbols = paged.value.filter(t => !t.isTpix).map(t => t.pair);
    if (symbols.length) loadSparklines(symbols);
}, { immediate: true });

function goToPage(n) {
    page.value = Math.min(Math.max(1, n), totalPages.value);
}

// ── การเลือกคู่เทรด ─────────────────────────────────────────────────────────
function selectPair(t) {
    isOpen.value = false;
    search.value = '';
    router.visit(`/trade/${t.pair}`);
}

function toggleOpen() {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        page.value = 1;
        nextTick(() => searchInput.value?.focus());
    }
}

function formatPrice(p) {
    if (p >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (p >= 1) return p.toFixed(2);
    if (p >= 0.01) return p.toFixed(4);
    return p.toFixed(8);
}

function formatVolume(v) {
    if (v >= 1e9) return (v / 1e9).toFixed(1) + 'B';
    if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + 'K';
    return v.toFixed(0);
}

// ปิด dropdown เมื่อ click ข้างนอก
function handleClickOutside(e) {
    if (!e.target.closest('.pair-selector')) isOpen.value = false;
}

function handleEsc(e) {
    if (e.key === 'Escape') isOpen.value = false;
}

onMounted(() => {
    readFavorites();
    fetchTickers();
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleEsc);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleEsc);
});
</script>

<template>
    <div class="pair-selector relative">
        <!-- ปุ่มเปิด -->
        <button @click="toggleOpen"
            class="flex items-center gap-2 px-4 py-2 rounded-xl bg-dark-800/80 border border-white/10 hover:border-primary-500/50 transition-all">
            <span class="text-white font-bold text-lg">{{ currentPair }}</span>
            <svg :class="['w-4 h-4 text-dark-400 transition-transform', isOpen && 'rotate-180']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div v-if="isOpen"
            class="absolute top-full left-0 mt-2 w-[400px] max-w-[92vw] bg-dark-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl z-50 overflow-hidden">

            <!-- ค้นหา -->
            <div class="p-3 pb-2">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input ref="searchInput" v-model="search" type="text" placeholder="ค้นหาคู่เทรด..."
                        class="w-full bg-dark-700/70 border border-dark-600 rounded-lg pl-9 pr-3 py-2 text-white text-sm placeholder-dark-500 focus:border-primary-500 outline-none" />
                </div>
            </div>

            <!-- ตัวกรอง -->
            <div class="px-3 pb-2 flex items-center gap-1.5 overflow-x-auto scrollbar-none">
                <button v-for="f in filters" :key="f.id" @click="activeFilter = f.id"
                    :class="['px-2.5 py-1 rounded-lg text-[11px] font-medium whitespace-nowrap transition-colors',
                        activeFilter === f.id ? 'bg-primary-500/20 text-primary-300 border border-primary-500/30'
                                              : 'bg-white/5 text-dark-400 border border-transparent hover:text-white']">
                    {{ f.label }}
                </button>
            </div>

            <!-- จัดเรียง -->
            <div class="px-3 pb-2 flex items-center gap-2">
                <span class="text-[10px] text-dark-500">เรียงตาม</span>
                <button v-for="s in sorts" :key="s.id" @click="sortKey = s.id"
                    :class="['text-[11px] px-1.5 py-0.5 rounded transition-colors',
                        sortKey === s.id ? 'text-primary-400 font-semibold' : 'text-dark-500 hover:text-white']">
                    {{ s.label }}
                </button>
            </div>

            <!-- รายการ -->
            <div class="border-t border-white/5">
                <div v-if="isLoading" class="py-8 text-center text-dark-500 text-sm animate-pulse">กำลังโหลดคู่เทรด...</div>

                <template v-else>
                    <div v-for="t in paged" :key="t.pair"
                        :class="['group w-full flex items-center gap-2 px-3 py-2.5 hover:bg-white/5 transition-colors cursor-pointer',
                            t.symbol === currentPair && 'bg-primary-500/10']"
                        @click="selectPair(t)">

                        <!-- ดาวรายการโปรด — หยุด event ไม่ให้ทะลุไปเปลี่ยนหน้า -->
                        <button @click.stop="toggleFavorite(t.pair)"
                            :class="['shrink-0 w-5 text-sm transition-colors',
                                favorites.includes(t.pair) ? 'text-amber-400' : 'text-dark-600 hover:text-amber-400']"
                            :aria-label="favorites.includes(t.pair) ? 'เอาออกจากรายการโปรด' : 'เพิ่มในรายการโปรด'">
                            {{ favorites.includes(t.pair) ? '★' : '☆' }}
                        </button>

                        <CoinIcon :symbol="t.base" size="sm" :src="t.logo || (t.isTpix ? '/tpixlogo.webp' : undefined)" />

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="text-white text-sm font-medium truncate">{{ t.symbol }}</span>
                                <!-- คู่ TPIX เปิดเทรดเมื่อเชน TPIX พร้อม — โชว์ไว้ก่อน -->
                                <span v-if="t.base === 'TPIX'" class="text-[9px] px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 font-medium">
                                    Soon
                                </span>
                            </div>
                            <p class="text-[10px] text-dark-500">Vol {{ formatVolume(t.volume) }}</p>
                        </div>

                        <!-- เส้นกราฟย่อ 24 ชม. -->
                        <!-- loading = ยังไม่เคยดึง (undefined) — ถ้าดึงแล้วไม่มีข้อมูลจะได้ [] แล้วหยุด skeleton -->
                        <Sparkline :points="series[t.pair] || []" :is-up="t.change >= 0" :width="60" :height="24"
                            :loading="!t.isTpix && series[t.pair] === undefined" />

                        <div class="text-right w-[88px] shrink-0">
                            <p class="text-white text-sm font-mono">${{ formatPrice(t.price) }}</p>
                            <p :class="['text-xs', t.change >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                {{ t.change >= 0 ? '+' : '' }}{{ t.change.toFixed(2) }}%
                            </p>
                        </div>
                    </div>

                    <p v-if="paged.length === 0" class="text-dark-500 text-sm text-center py-8">
                        {{ activeFilter === 'favorites' ? 'ยังไม่มีคู่เทรดในรายการโปรด' : 'ไม่พบคู่เทรด' }}
                    </p>
                </template>
            </div>

            <!-- แบ่งหน้า -->
            <div v-if="!isLoading && filtered.length > PAGE_SIZE"
                class="flex items-center justify-between px-3 py-2 border-t border-white/5 bg-dark-900/40">
                <span class="text-[11px] text-dark-500">{{ filtered.length }} คู่</span>
                <div class="flex items-center gap-1">
                    <button @click.stop="goToPage(page - 1)" :disabled="page === 1"
                        class="px-2 py-1 rounded text-xs text-dark-400 hover:text-white hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors">
                        ‹
                    </button>
                    <span class="text-[11px] text-dark-400 font-mono px-1">{{ page }} / {{ totalPages }}</span>
                    <button @click.stop="goToPage(page + 1)" :disabled="page === totalPages"
                        class="px-2 py-1 rounded text-xs text-dark-400 hover:text-white hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors">
                        ›
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scrollbar-none::-webkit-scrollbar { display: none; }
.scrollbar-none { scrollbar-width: none; }
</style>
