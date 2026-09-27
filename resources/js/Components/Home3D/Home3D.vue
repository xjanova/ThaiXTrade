<script setup>
/**
 * TPIX TRADE — หน้าแรกแบบ 3D เต็มจอ
 *
 * โลกกระดานเทรด 3 มิติ: เลื่อนหน้า = กล้องบินผ่าน 7 สถานี
 *   hero (กำแพงแท่งเทียนจริง + เหรียญ TPIX) → Master Node → ตลาด → รอบขาย → ระบบนิเวศ → จุดเด่น → เริ่มเทรด
 * แผงข้อความแต่ละสถานีเป็น HTML จริง (ลิงก์/ปุ่ม/อ่านด้วยโปรแกรมอ่านจอได้) ลอยทับฉาก
 * น้อง TPIX ยืนที่จุดยืนของสถานีที่ดูอยู่ แล้วบินไปสถานีถัดไปพร้อมพูดแนะนำ
 *
 * ถอยกลับหน้าเดิม (emit 'fallback') เมื่อ: บูตไม่ขึ้นใน 15 วิ · GPU หลุด · FPS ต่ำจนลดคุณภาพแล้วยังไม่ไหว
 *
 * Developed by Xman Studio
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import BannerAd from '@/Components/BannerAd.vue';
import TpixMascot from '@/Components/Home/TpixMascot.vue';
import { vTilt } from '@/Components/Home/directives';
import { STATIONS, journeyF, panelVisibility } from './stations';
import { createEngine } from './engine';
import { buildWorld } from './world/index';
import { useMarketData } from '@/Composables/useMarketData';
import { useTranslation } from '@/Composables/useTranslation';
import { useMascot } from '@/Composables/useMascot';

const emit = defineEmits(['fallback', 'lite']);

const { t } = useTranslation();
const mascot = useMascot();
const { tickers, topGainers, topVolume, isLoading, fetchTickers, startAutoRefresh } = useMarketData();

const canvasEl = ref(null);
const loading = ref(true);
const current = ref('hero');
const tip = reactive({ show: false, x: 0, y: 0, candle: null });

const sectionEls = {};
const panelEls = {};
const spotEls = {};
const setRef = (bag, key) => (el) => {
    if (el) bag[key] = el;
};

const stats = computed(() => [
    { label: t('home.supportedChains'), value: '9' },
    { label: t('home.tradingPairs'), value: '100+' },
    { label: t('home.dexProtocol'), value: 'PancakeSwap' },
    { label: t('home.network'), value: 'TPIX Chain + BSC' },
]);

// การ์ดระบบนิเวศ: ใช้ทั้งในฉาก 3D (คลิกได้) และในแผง HTML
const ECO = [
    { key: 'tokenSale', title: 'Token Sale', href: '/token-sale', image: '/images/art/card-tokensale.webp', color: '#22d3ee' },
    { key: 'whitepaper', title: 'Whitepaper', href: '/whitepaper', image: '/images/art/card-whitepaper.webp', color: '#a78bfa' },
    { key: 'explorer', title: 'Explorer', href: '/explorer', image: '/images/art/card-explorer.webp', color: '#00c853' },
    { key: 'masternode', title: 'Master Node', href: '/masternode', image: '/images/art/card-masternode.webp', color: '#f6bd35' },
];

// จุดเด่น: ไอคอน 3D เจนจาก ChatGPT (ไม่มีไฟล์ = ฉากใช้ทรงเรขาคณิตแทน)
const FEATURES = [
    { key: 'feature3', image: '/images/home3d/feature-shield.webp', color: '#22d3ee' },
    { key: 'feature4', image: '/images/home3d/feature-network.webp', color: '#a78bfa' },
    { key: 'feature2', image: '/images/home3d/feature-speed.webp', color: '#f6bd35' },
    { key: 'feature1', image: '/images/home3d/feature-gas.webp', color: '#00c853' },
];

const btcPrice = computed(() => {
    const row = tickers.value.find((r) => r.baseAsset === 'BTC');
    const p = row ? parseFloat(row.price) : NaN;
    return Number.isFinite(p) ? p : null;
});

let engine = null;
let world = null;
let alive = true;
let bootTimer = 0;
let klineTimer = 0;
let depthTimer = 0;
let centers = [];
let smooth = 0;
let f = 0;
let navBottom = 64;
const lastVis = new Map();

// ── วางผัง: ตำแหน่ง scroll ของกลางแต่ละสถานี ───────────────────────────
function layout() {
    const vh = window.innerHeight;
    const y = window.scrollY;
    centers = STATIONS.map((st) => {
        const el = sectionEls[st.key];
        if (!el) return 0;
        const top = el.getBoundingClientRect().top + y;
        return Math.max(0, top + (el.offsetHeight - vh) / 2);
    });
    // สถานีแรกอยู่ที่ scroll 0 พอดี — เปิดหน้ามาต้องเห็นฮีโร่เต็มๆ
    centers[0] = 0;
    world?.layout({ portrait: window.innerWidth / Math.max(1, vh) < 0.9 });
}

function goTo(i) {
    window.scrollTo({ top: centers[i] ?? 0, behavior: 'smooth' });
}

// ── ทุกเฟรม: scroll → กล้อง → แผง → จุดยืนน้อง ─────────────────────────
function tick(dt, time) {
    const y = window.scrollY;
    smooth += (y - smooth) * (1 - Math.exp(-7 * dt));
    if (Math.abs(y - smooth) > window.innerHeight * 4) smooth = y;
    f = journeyF(smooth, centers);
    world.update(dt, time, f);

    // แผงข้อความเริ่มใต้แถบนำทาง (แถบราคา/ป้ายโฆษณาด้านบนเลื่อนหายไปได้)
    const nav = document.querySelector('nav');
    const nb = nav ? Math.max(0, Math.round(nav.getBoundingClientRect().bottom)) : 64;
    if (nb !== navBottom) {
        navBottom = nb;
        document.documentElement.style.setProperty('--h3d-top', `${nb}px`);
    }

    STATIONS.forEach((st, i) => {
        const el = panelEls[st.key];
        if (!el) return;
        const v = panelVisibility(f, i);
        const prev = lastVis.get(i);
        if (prev !== undefined && Math.abs(prev - v) < 0.004) return;
        lastVis.set(i, v);
        el.style.opacity = v.toFixed(3);
        el.style.transform = `translate3d(0, ${((1 - v) * 32 * Math.sign(i - f || 1)).toFixed(1)}px, 0)`;
        el.style.visibility = v < 0.01 ? 'hidden' : 'visible';
        el.style.pointerEvents = v > 0.6 ? 'auto' : 'none';
    });

    // จุดยืนของน้อง = สถานีที่ใกล้ที่สุด · เลยสถานีสุดท้ายไปแล้ว = ไม่มีจุด (น้องจอดมุมจอ)
    const n = STATIONS.length;
    const near = Math.min(n - 1, Math.max(0, Math.round(f)));
    const key = f < n - 0.5 ? STATIONS[near].key : null;
    const activeKey = Object.keys(spotEls).find((k) => spotEls[k].classList.contains('is-active')) ?? null;
    if (key !== activeKey) {
        Object.entries(spotEls).forEach(([k, el]) => el.classList.toggle('is-active', k === key));
    }
    if (key && key !== current.value) current.value = key;
}

// ── ข้อมูลตลาดจริง ────────────────────────────────────────────────────────
async function loadCandles() {
    try {
        const { data } = await axios.get('/api/v1/market/klines/BTC-USDT', { params: { interval: '1h', limit: 60 }, timeout: 15000 });
        if (alive && world && data?.success) {
            world.wall.setCandles(data.data);
            if (btcPrice.value) world.wall.setLivePrice(btcPrice.value);
        }
    } catch {
        // ไม่มีกราฟ = กำแพงว่าง แต่ฉากที่เหลือยังใช้ได้ รอบหน้าลองใหม่
    }
}

async function loadDepth() {
    try {
        const { data } = await axios.get('/api/v1/market/orderbook/BTC-USDT', { params: { limit: 40 }, timeout: 15000 });
        if (alive && world && data?.success) world.wall.setDepth(data.data);
    } catch {
        // ของประกอบฉาก ไม่มีก็ได้
    }
}

watch([topGainers, topVolume], () => {
    world?.tickers.setTickers([...topGainers.value, ...topVolume.value]);
});

watch(btcPrice, (p) => {
    if (p) world?.wall.setLivePrice(p);
});

// ── เมาส์/แตะ ───────────────────────────────────────────────────────────
function onPointerMove(e) {
    if (!world) return;
    world.pointer(e.clientX / window.innerWidth, e.clientY / window.innerHeight, e.target === canvasEl.value);
    if (e.target !== canvasEl.value) tip.show = false;
}

function onCanvasClick() {
    const href = world?.clickHref();
    if (href) router.visit(href);
}

function onCandleHover(info) {
    tip.show = !!info;
    if (!info) return;
    tip.x = info.x;
    tip.y = info.y;
    tip.candle = info.candle;
}

const onResize = () => {
    layout();
    lastVis.clear();
};

/**
 * จับเวลาบูตเฉพาะตอนแท็บมองเห็นอยู่
 * เปิดหน้าในแท็บเบื้องหลัง = เบราว์เซอร์หยุดวาด การบูตจึงค้างเป็นปกติ ไม่ใช่เครื่องช้า
 * (ถ้านับ ผู้ใช้ที่กดเปิดลิงก์ในแท็บใหม่จะโดนโยนไปหน้าเดิมนาน 7 วัน)
 */
function armBootTimer() {
    clearTimeout(bootTimer);
    if (!loading.value) return;
    if (document.hidden) return;
    bootTimer = setTimeout(() => {
        if (loading.value && !document.hidden) emit('fallback', 'boot-timeout');
    }, 15000);
}
const onBootVisibility = () => (document.hidden ? clearTimeout(bootTimer) : armBootTimer());

onMounted(async () => {
    armBootTimer();
    document.addEventListener('visibilitychange', onBootVisibility);
    try {
        const phone = Math.min(window.innerWidth, window.innerHeight) < 700;
        engine = createEngine(canvasEl.value, {
            startLevel: phone ? 1 : 0,
            onSlow: () => emit('fallback', 'slow'),
            onLost: () => emit('fallback', 'context'),
        });
        world = buildWorld(engine, {
            ecosystem: ECO,
            features: FEATURES,
            onCandleHover,
            onCursor: (c) => {
                if (canvasEl.value && canvasEl.value.style.cursor !== c) canvasEl.value.style.cursor = c;
            },
        });
        layout();
        smooth = window.scrollY;
        f = journeyF(smooth, centers);
        engine.step(1 / 60);
        // คอมไพล์ล่วงหน้าช่วยกันกระตุก แต่ไม่ยอมรอเกิน 4 วิ (บางเครื่อง/แท็บเบื้องหลังรอไม่จบ)
        await Promise.race([engine.compile().catch(() => {}), new Promise((r) => setTimeout(r, 4000))]);
        if (!alive) return;
        engine.start(tick);
        loading.value = false;
        clearTimeout(bootTimer);
        document.removeEventListener('visibilitychange', onBootVisibility);
        // ช่องทางดีบักตอนพัฒนาเท่านั้น (build จริงตัดทิ้ง)
        if (import.meta.env.DEV) window.__tpixHome3d = { engine, world, get f() { return f; }, centers: () => centers };
    } catch {
        clearTimeout(bootTimer);
        document.removeEventListener('visibilitychange', onBootVisibility);
        emit('fallback', 'boot');
        return;
    }

    window.addEventListener('resize', onResize);
    window.addEventListener('pointermove', onPointerMove, { passive: true });

    await fetchTickers();
    startAutoRefresh();
    await Promise.all([loadCandles(), loadDepth()]);
    klineTimer = setInterval(() => !document.hidden && loadCandles(), 5 * 60 * 1000);
    depthTimer = setInterval(() => !document.hidden && loadDepth(), 30 * 1000);
});

onBeforeUnmount(() => {
    alive = false;
    clearTimeout(bootTimer);
    document.removeEventListener('visibilitychange', onBootVisibility);
    clearInterval(klineTimer);
    clearInterval(depthTimer);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('pointermove', onPointerMove);
    document.documentElement.style.removeProperty('--h3d-top');
    world?.dispose();
    engine?.dispose();
    world = null;
    engine = null;
});

function fmt(n) {
    if (!Number.isFinite(n)) return '-';
    if (n >= 1000) return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n >= 1 ? n.toFixed(2) : n.toFixed(6);
}

function fmtTime(ms) {
    const d = new Date(ms);
    return `${d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' })} ${d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false })}`;
}
</script>

<template>
    <Head title="Decentralized Trading Platform" />

    <AppLayout :hide-sidebar="true">
        <div class="h3d -mx-4 -mt-4 lg:-mx-6 lg:-mt-6">
            <canvas ref="canvasEl" class="h3d-canvas" :class="{ 'is-ready': !loading }" aria-hidden="true" @click="onCanvasClick" />
            <div class="h3d-shade" aria-hidden="true"></div>

            <Transition name="h3d-fade">
                <div v-if="loading" class="h3d-loader" role="status">
                    <span class="h3d-loader__ring" aria-hidden="true"></span>
                    <p class="text-sm text-dark-300">{{ t('home3d.loading') }}</p>
                </div>
            </Transition>

            <section
                v-for="st in STATIONS"
                :key="st.key"
                :ref="setRef(sectionEls, st.key)"
                class="h3d-st"
                :data-guide="st.key"
                :style="{ height: `${st.len * 100}vh` }"
            >
                <div class="h3d-layer" :class="`is-${st.panel}`">
                    <div :ref="setRef(panelEls, st.key)" class="h3d-panel thin-scrollbar" :class="`h3d-panel--${st.key}`">
                        <!-- ═══ 1. Hero ═══ -->
                        <template v-if="st.key === 'hero'">
                            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full glass-sm text-xs mb-4">
                                <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                                <span class="text-dark-300">{{ t('home.liveOnBSC') }}</span>
                            </div>
                            <h1 class="h3d-title text-4xl sm:text-5xl xl:text-6xl font-bold text-white mb-4">
                                {{ t('home.heroTitle1') }} <span class="text-gradient whitespace-nowrap">{{ t('home.heroTitle2') }}</span>
                                <br />
                                {{ t('home.heroTitle1') }} <span class="text-gradient-gold whitespace-nowrap">{{ t('home.heroTitle3') }}</span>
                            </h1>
                            <p class="text-base lg:text-lg text-dark-300 mb-6">{{ t('home.heroDesc') }}</p>
                            <div class="flex flex-col sm:flex-row gap-3 mb-6">
                                <Link href="/trade" class="btn-primary px-6 py-3 text-base justify-center">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                    {{ t('home.startTrading') }}
                                </Link>
                                <button type="button" class="btn-secondary px-6 py-3 text-base justify-center" @click="mascot.requestTour()">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                                    {{ t('home.tourWithMascot') }}
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div v-for="s in stats" :key="s.label" v-tilt="{ max: 10 }" class="h3d-chip">
                                    <p class="text-base xl:text-lg font-bold text-white leading-tight">{{ s.value }}</p>
                                    <p class="text-[11px] text-dark-400">{{ s.label }}</p>
                                </div>
                            </div>
                            <p class="h3d-hint mt-6 hidden sm:flex">
                                <span class="h3d-hint__mouse" aria-hidden="true"></span>{{ t('home3d.scrollHint') }}
                            </p>
                        </template>

                        <!-- ═══ 2. Master Node ═══ -->
                        <template v-else-if="st.key === 'node'">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-xs text-cyan-400 font-semibold mb-3">
                                <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse" />
                                {{ t('home.nodeNew') }}
                            </div>
                            <h2 class="text-3xl lg:text-4xl font-black text-white mb-3">{{ t('home.nodeTitle') }}</h2>
                            <p class="text-dark-300 mb-5">{{ t('home.nodeDesc') }}</p>
                            <div class="grid grid-cols-3 gap-2 mb-5">
                                <div v-tilt class="h3d-chip text-center">
                                    <div class="text-[11px] text-dark-400">Light</div>
                                    <div class="text-lg font-black text-cyan-400">10K</div>
                                    <div class="text-[11px] text-trading-green">4-6% APY</div>
                                </div>
                                <div v-tilt class="h3d-chip text-center !border-purple-500/30">
                                    <div class="text-[11px] text-dark-400">Sentinel</div>
                                    <div class="text-lg font-black text-purple-400">100K</div>
                                    <div class="text-[11px] text-trading-green">7-10% APY</div>
                                </div>
                                <div v-tilt class="h3d-chip text-center !border-red-500/30">
                                    <div class="text-[11px] text-dark-400">Validator</div>
                                    <div class="text-lg font-black text-red-400">10M</div>
                                    <div class="text-[11px] text-trading-green">15-20% APY</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link href="/masternode" class="btn-primary px-5 py-2.5 text-sm font-bold">⚡ {{ t('home.nodeStake') }}</Link>
                                <Link href="/masternode/guide" class="px-5 py-2.5 text-sm font-semibold border border-cyan-500/30 text-cyan-400 rounded-xl hover:bg-cyan-500/10 transition">📖 {{ t('home.nodeGuide') }}</Link>
                                <Link href="/download" class="px-5 py-2.5 text-sm font-semibold border border-white/10 text-dark-300 rounded-xl hover:bg-white/5 transition">📥 {{ t('home.nodeDownload') }}</Link>
                            </div>
                        </template>

                        <!-- ═══ 3. ตลาด ═══ -->
                        <template v-else-if="st.key === 'markets'">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-2xl lg:text-3xl font-bold text-white">{{ t('home.liveMarket') }}</h2>
                                <Link href="/markets" class="text-primary-400 hover:text-primary-300 text-sm">{{ t('home.viewAll') }}</Link>
                            </div>
                            <div v-if="isLoading" class="py-6 text-center text-dark-400 animate-pulse">{{ t('home.loadingLive') }}</div>
                            <div v-else class="grid sm:grid-cols-2 gap-4">
                                <div v-for="list in [{ title: t('home.topGainers'), rows: topGainers, up: true }, { title: t('home.topVolume'), rows: topVolume, up: false }]" :key="list.title">
                                    <p class="text-xs font-semibold text-dark-400 mb-2">{{ list.title }}</p>
                                    <Link
                                        v-for="c in list.rows"
                                        :key="c.symbol"
                                        :href="`/trade/${c.symbol}-USDT`"
                                        class="flex items-center justify-between gap-2 px-2.5 py-2 rounded-xl hover:bg-white/5 transition-colors"
                                    >
                                        <span class="flex items-center gap-2 min-w-0">
                                            <CoinIcon :symbol="c.symbol" size="sm" />
                                            <span class="font-semibold text-white text-sm truncate">{{ c.symbol }}</span>
                                        </span>
                                        <span class="text-right">
                                            <span class="block font-mono text-xs text-white">${{ c.price }}</span>
                                            <span :class="['block text-[11px] font-medium', list.up || c.isUp ? 'text-trading-green' : 'text-trading-red']">{{ c.change }}</span>
                                        </span>
                                    </Link>
                                </div>
                            </div>
                        </template>

                        <!-- ═══ 4. รอบขาย ═══ -->
                        <template v-else-if="st.key === 'sale'">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-trading-green/10 border border-trading-green/20 text-trading-green text-xs font-semibold mb-3">
                                <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                                {{ t('tokenSale.title') }}
                            </div>
                            <h2 class="text-3xl lg:text-4xl font-bold text-white mb-3">{{ t('home.saleTitle') }} <span class="text-gradient">TPIX</span></h2>
                            <p class="text-dark-300 mb-5">{{ t('home.saleDesc') }}</p>
                            <div class="grid grid-cols-3 gap-2 mb-5">
                                <div v-tilt class="h3d-chip text-center"><p class="text-[11px] text-dark-400">Private</p><p class="text-lg font-bold text-white">$0.05</p></div>
                                <div v-tilt class="h3d-chip text-center !border-primary-500/40"><p class="text-[11px] text-primary-400">Pre-Sale</p><p class="text-lg font-bold text-white">$0.08</p></div>
                                <div v-tilt class="h3d-chip text-center"><p class="text-[11px] text-dark-400">Public</p><p class="text-lg font-bold text-white">$0.10</p></div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link href="/token-sale" class="btn-primary px-5 py-2.5 text-sm">{{ t('home.saleCta') }}</Link>
                                <Link href="/whitepaper" class="btn-secondary px-5 py-2.5 text-sm">{{ t('home.viewWhitepaper') }}</Link>
                            </div>
                        </template>

                        <!-- ═══ 5. ระบบนิเวศ ═══ -->
                        <template v-else-if="st.key === 'ecosystem'">
                            <h2 class="text-3xl lg:text-4xl font-bold text-white mb-3">TPIX <span class="text-gradient">Ecosystem</span></h2>
                            <p class="text-dark-300 mb-4">{{ t('home.ecosystemDesc') }}</p>
                            <div class="space-y-1.5">
                                <Link v-for="card in ECO" :key="card.key" :href="card.href" class="flex items-center gap-3 p-2.5 rounded-xl hover:bg-white/5 transition-colors">
                                    <img :src="card.image" alt="" loading="lazy" class="w-12 h-9 rounded-lg object-cover shrink-0" />
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-white text-sm">{{ t(`home.eco.${card.key}`) }}</span>
                                        <span class="block text-xs text-dark-400 truncate">{{ t(`home.eco.${card.key}Desc`) }}</span>
                                    </span>
                                </Link>
                            </div>
                            <p class="text-[11px] text-dark-500 mt-3 hidden md:block">{{ t('home3d.ecoHint') }}</p>
                        </template>

                        <!-- ═══ 6. จุดเด่น ═══ -->
                        <template v-else-if="st.key === 'features'">
                            <h2 class="text-3xl lg:text-4xl font-bold text-white mb-3">{{ t('home.whyTpix') }}</h2>
                            <p class="text-dark-300 mb-4">{{ t('home.whyTpixDesc') }}</p>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div v-for="ft in FEATURES" :key="ft.key" v-tilt class="h3d-chip">
                                    <img :src="ft.image" alt="" loading="lazy" class="w-10 h-10 object-contain mb-1.5" @error="$event.target.style.display = 'none'" />
                                    <p class="font-semibold text-white text-sm">{{ t(`home.${ft.key}`) }}</p>
                                    <p class="text-[11px] text-dark-400 leading-snug">{{ t(`home.${ft.key}Desc`) }}</p>
                                </div>
                            </div>
                        </template>

                        <!-- ═══ 7. เริ่มเทรด ═══ -->
                        <template v-else-if="st.key === 'cta'">
                            <h2 class="text-3xl lg:text-5xl font-bold text-white mb-4">{{ t('home.ctaTitle') }}</h2>
                            <p class="text-dark-300 mb-6">{{ t('home.ctaDesc') }}</p>
                            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                <Link href="/trade" class="btn-primary px-8 py-3.5 text-lg justify-center">
                                    {{ t('home.launchApp') }}
                                    <svg class="w-5 h-5 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                </Link>
                                <Link href="/download" class="btn-secondary px-6 py-3.5 text-base justify-center">📥 {{ t('mascot.chips.download') }}</Link>
                            </div>
                        </template>
                    </div>

                    <!-- จุดยืนของน้อง TPIX (น้องวาดทับตรงนี้) · data-face = เนื้อหาอยู่ฝั่งไหนของน้อง -->
                    <div
                        :ref="setRef(spotEls, st.key)"
                        class="h3d-spot"
                        data-mascot-stage
                        :data-face="st.panel === 'right' ? 'right' : 'left'"
                        aria-hidden="true"
                    ></div>
                </div>
            </section>

            <div class="relative z-[3]">
                <BannerAd placement="home_bottom" class="py-4 max-w-6xl mx-auto" />
            </div>
        </div>

        <!-- ทางลัดไปแต่ละสถานี -->
        <nav class="h3d-dots" :aria-label="t('home3d.stationsLabel')">
            <button
                v-for="(st, i) in STATIONS"
                :key="st.key"
                type="button"
                class="h3d-dot"
                :class="{ 'is-on': current === st.key }"
                :aria-current="current === st.key ? 'step' : undefined"
                @click="goTo(i)"
            >
                <span class="h3d-dot__label">{{ t(`home3d.nav.${st.key}`) }}</span>
                <span class="h3d-dot__pip" aria-hidden="true"></span>
            </button>
        </nav>

        <button type="button" class="h3d-lite" @click="emit('lite')">
            <span aria-hidden="true">⚡</span> {{ t('home3d.switchToLite') }}
        </button>

        <!-- ค่า OHLC ของแท่งที่ชี้ -->
        <div
            v-if="tip.show && tip.candle"
            class="h3d-tip"
            :style="{ left: `${tip.x * 100}vw`, top: `${tip.y * 100}vh` }"
            aria-hidden="true"
        >
            <p class="text-dark-400 mb-1">BTC/USDT · {{ fmtTime(tip.candle.time) }}</p>
            <p class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-white">
                <span><span class="text-dark-500">O</span> {{ fmt(tip.candle.open) }}</span>
                <span><span class="text-dark-500">H</span> {{ fmt(tip.candle.high) }}</span>
                <span><span class="text-dark-500">L</span> {{ fmt(tip.candle.low) }}</span>
                <span :class="tip.candle.close >= tip.candle.open ? 'text-trading-green' : 'text-trading-red'"><span class="text-dark-500">C</span> {{ fmt(tip.candle.close) }}</span>
            </p>
        </div>

        <TpixMascot :section="current" />
    </AppLayout>
</template>

<style scoped>
.h3d {
    position: relative;
}

.h3d-canvas {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    z-index: 0;
    opacity: 0;
    transition: opacity 0.9s ease;
}

.h3d-canvas.is-ready {
    opacity: 1;
}

/* ขอบจอมืดลง ให้แผงข้อความอ่านง่าย */
.h3d-shade {
    position: fixed;
    inset: 0;
    z-index: 1;
    pointer-events: none;
    background:
        radial-gradient(ellipse 120% 90% at 50% 45%, transparent 55%, rgb(2 6 23 / 0.55) 100%),
        linear-gradient(to bottom, rgb(2 6 23 / 0.55), transparent 18%);
}

.h3d-loader {
    position: fixed;
    inset: 0;
    z-index: 30;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    background: #050b1a;
}

.h3d-loader__ring {
    width: 48px;
    height: 48px;
    border-radius: 999px;
    border: 3px solid rgb(34 211 238 / 0.2);
    border-top-color: #22d3ee;
    animation: h3d-spin 0.9s linear infinite;
}

@keyframes h3d-spin {
    to { transform: rotate(360deg); }
}

.h3d-fade-leave-active {
    transition: opacity 0.6s ease;
}

.h3d-fade-leave-to {
    opacity: 0;
}

.h3d-st {
    position: relative;
}

/* ชั้นแผงของแต่ละสถานี: ตรึงกับจอ ความชัดคุมด้วย JS ตามตำแหน่งกล้อง */
.h3d-layer {
    position: fixed;
    left: 0;
    right: 0;
    top: var(--h3d-top, 64px);
    bottom: 0;
    z-index: 2;
    pointer-events: none;
}

.h3d-panel {
    position: absolute;
    top: 50%;
    translate: 0 -50%;
    width: min(500px, 40vw);
    max-height: calc(100% - 40px);
    overflow-y: auto;
    padding: 26px 28px;
    border-radius: 22px;
    background: rgb(var(--c-dark-900) / 0.72);
    border: 1px solid rgb(255 255 255 / 0.08);
    box-shadow: 0 30px 80px rgb(0 0 0 / 0.45), inset 0 1px 0 rgb(255 255 255 / 0.06);
    backdrop-filter: blur(14px);
    opacity: 0;
    visibility: hidden;
    will-change: opacity, transform;
}

.h3d-panel--hero {
    width: min(560px, 44vw);
    background: rgb(var(--c-dark-900) / 0.55);
}

.is-left .h3d-panel {
    left: max(24px, 4vw);
}

.is-right .h3d-panel {
    right: max(24px, 5vw);
}

.is-center .h3d-panel {
    left: 50%;
    translate: -50% -50%;
    width: min(620px, 90vw);
    text-align: center;
}

.h3d-title {
    line-height: 1.12;
}

.h3d-chip {
    padding: 10px 12px;
    border-radius: 14px;
    background: rgb(255 255 255 / 0.05);
    border: 1px solid rgb(255 255 255 / 0.1);
}

.h3d-hint {
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: rgb(var(--c-dark-400));
}

.h3d-hint__mouse {
    position: relative;
    width: 18px;
    height: 28px;
    border-radius: 10px;
    border: 2px solid rgb(var(--c-dark-400));
}

.h3d-hint__mouse::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 5px;
    width: 3px;
    height: 6px;
    margin-left: -1.5px;
    border-radius: 3px;
    background: rgb(var(--c-primary-400));
    animation: h3d-wheel 1.6s ease-in-out infinite;
}

@keyframes h3d-wheel {
    0% { transform: translateY(0); opacity: 1; }
    70% { transform: translateY(8px); opacity: 0; }
    100% { opacity: 0; }
}

/* จุดยืนของน้อง: ริมจอฝั่งตรงข้ามแผงข้อความ */
.h3d-spot {
    position: absolute;
    bottom: 0;
    width: min(19vw, 290px);
    height: min(54vh, 440px);
}

.is-left .h3d-spot,
.is-center .h3d-spot {
    right: 52px;
}

.is-right .h3d-spot {
    left: 12px;
}

.h3d-dots {
    position: fixed;
    right: 14px;
    top: 50%;
    translate: 0 -50%;
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.h3d-dot {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.h3d-dot__pip {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: rgb(255 255 255 / 0.25);
    transition: all 0.25s ease;
}

.h3d-dot.is-on .h3d-dot__pip {
    height: 22px;
    background: linear-gradient(rgb(var(--c-primary-400)), rgb(var(--c-accent-500)));
}

.h3d-dot__label {
    font-size: 11px;
    color: #fff;
    opacity: 0;
    translate: 6px 0;
    transition: all 0.2s ease;
    white-space: nowrap;
    text-shadow: 0 2px 8px rgb(0 0 0 / 0.8);
}

.h3d-dot:hover .h3d-dot__label,
.h3d-dot:focus-visible .h3d-dot__label,
.h3d-dot.is-on .h3d-dot__label {
    opacity: 1;
    translate: 0 0;
}

.h3d-lite {
    position: fixed;
    left: 16px;
    bottom: 16px;
    z-index: 20;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--c-dark-300));
    background: rgb(var(--c-dark-900) / 0.8);
    border: 1px solid rgb(255 255 255 / 0.12);
    backdrop-filter: blur(10px);
    transition: color 0.2s ease, border-color 0.2s ease;
}

.h3d-lite:hover {
    color: #fff;
    border-color: rgb(var(--c-primary-500) / 0.5);
}

.h3d-tip {
    position: fixed;
    z-index: 25;
    transform: translate(-50%, -100%);
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-family: ui-monospace, Menlo, monospace;
    pointer-events: none;
    background: rgb(var(--c-dark-900) / 0.92);
    border: 1px solid rgb(255 255 255 / 0.1);
    box-shadow: 0 10px 30px rgb(0 0 0 / 0.5);
}

/* มือถือ/จอแนวตั้ง: แผงเป็นแผ่นด้านล่าง วัตถุ 3D อยู่ครึ่งบน น้องยืนมุมบน */
@media (max-width: 767px), (max-aspect-ratio: 9/10) {
    .h3d-panel,
    .h3d-panel--hero,
    .is-left .h3d-panel,
    .is-right .h3d-panel,
    .is-center .h3d-panel {
        left: 10px;
        right: 10px;
        top: auto;
        bottom: 10px;
        translate: none;
        width: auto;
        max-height: 58%;
        padding: 18px 18px;
        text-align: left;
    }

    .h3d-spot {
        top: 8px;
        bottom: auto;
        width: 30vw;
        height: 28vh;
    }

    .is-left .h3d-spot,
    .is-center .h3d-spot {
        right: 4px;
    }

    .is-right .h3d-spot {
        left: 4px;
    }

    .h3d-dots {
        display: none;
    }

    .h3d-lite {
        bottom: auto;
        top: calc(var(--h3d-top, 64px) + 8px);
        left: 10px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .h3d-canvas,
    .h3d-dot__pip,
    .h3d-dot__label {
        transition: none;
    }
    .h3d-hint__mouse::after,
    .h3d-loader__ring {
        animation: none;
    }
}
</style>

<style>
/* ลูกเล่นการ์ดเอียง (Components/Home/directives.js) — คลาสเติมตอนรันจึงต้องไม่ scoped */
.tilt-3d {
    position: relative;
    transform-style: preserve-3d;
    transition: transform 0.5s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.3s ease;
    will-change: transform;
}

.tilt-3d.is-tilting {
    transition: transform 0.08s linear, box-shadow 0.3s ease;
    box-shadow: 0 18px 40px -12px rgb(0 0 0 / 0.55), 0 0 0 1px rgb(var(--c-primary-500) / 0.2);
}

.tilt-3d__glare {
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    background: radial-gradient(circle at var(--gx, 50%) var(--gy, 50%), rgb(255 255 255 / 0.18), rgb(255 255 255 / 0) 55%);
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 5;
}

.tilt-3d.is-tilting .tilt-3d__glare {
    opacity: 1;
}
</style>
