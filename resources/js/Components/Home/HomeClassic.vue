<script setup>
/**
 * TPIX TRADE - Home Page (Classic / Lite)
 *
 * หน้าแรกแบบธรรมดา — ใช้กับเครื่องที่แสดงหน้าแรก 3D ไม่ไหว / ผู้ใช้เลือกเอง (Pages/Home.vue เลือกให้)
 * ห้ามใส่ของหนักเพิ่มในไฟล์นี้: ภาพทุกใบเป็น WebP ขนาดเบา (ภาพนอกจอโหลดแบบ lazy) ไม่มีวิดีโอ ไม่มี WebGL
 *
 * ภาพประกอบทั้งหมดเจนจาก ChatGPT (ต้นฉบับ + คำสั่งเก็บนอก repo ที่ D:\Code\TPIX\home-art\)
 * ตัวเลขทุกตัวบนหน้ามาจาก API จริงผ่าน useHomeLiveStats — ห้ามพิมพ์ราคา/สถิติตายตัวลงในหน้า
 *
 * แผงที่มีภาพพื้นหลังโทนมืด (.hc-island) ตรึงสีของธีมมืดไว้เสมอ
 * ผู้ใช้ธีมสว่าง (xp-silver) จะเห็นแผงเหล่านี้เป็น "เกาะมืด" ที่ยังอ่านออก ไม่ใช่ตัวหนังสือเข้มบนภาพมืด
 *
 * Developed by Xman Studio
 */

import { computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import Sparkline from '@/Components/Trading/Sparkline.vue';
import BannerAd from '@/Components/BannerAd.vue';
import IntroVideo from '@/Components/Home/IntroVideo.vue';
import { useMarketData } from '@/Composables/useMarketData';
import { useSparklines } from '@/Composables/useSparklines';
import { useHomeLiveStats } from '@/Composables/useHomeLiveStats';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

// เปลี่ยนภาพชื่อเดิมเมื่อไหร่ต้องบวกเลขนี้ — Cloudflare แคชรูปใน public_html นาน 1 ปี
const ART_V = 1;
const art = (name) => `/images/home/${name}.webp?v=${ART_V}`;

// ── ตลาดสด (Binance proxy) ─────────────────────────────────────
const { topGainers, topVolume, isLoading, fetchTickers, startAutoRefresh } = useMarketData();
const { series, load: loadSparklines } = useSparklines();

watch([topGainers, topVolume], () => {
    const symbols = [...topGainers.value, ...topVolume.value].map(c => `${c.symbol}-USDT`);
    if (symbols.length) loadSparklines(symbols);
}, { immediate: true });

// เริ่มรีเฟรชหลังโหลดรอบแรกเสร็จ — ถ้าผู้ใช้เปลี่ยนหน้าไปก่อน ห้ามตั้ง interval ค้างไว้หลัง unmount
let alive = true;
onMounted(async () => {
    await fetchTickers();
    if (alive) startAutoRefresh();
});
onBeforeUnmount(() => { alive = false; });

const marketLists = computed(() => [
    { key: 'gainers', title: t('home.topGainers'), coins: topGainers.value },
    { key: 'volume', title: t('home.topVolume'), coins: topVolume.value },
]);

// ── ข้อมูลสดของ TPIX Chain / Masternode / รอบขาย ──────────────
const { price, chain, node, tiers, sale, loaded, failed } = useHomeLiveStats();

const DASH = '—';
const fmtInt = (n) => Number(n).toLocaleString('en-US');

function fmtUsd(n) {
    const digits = n >= 1 ? 2 : 4;
    return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: digits });
}

function fmtStake(n) {
    if (n >= 1_000_000) return `${n / 1_000_000}M`;
    if (n >= 1_000) return `${n / 1_000}K`;
    return String(n);
}

const shortAddr = (a) => (a ? `${a.slice(0, 6)}…${a.slice(-4)}` : '');

const priceText = computed(() => (price.value && !failed.value.price ? fmtUsd(price.value.price) : DASH));
const priceChange = computed(() => {
    const c = price.value?.change_24h || 0;
    return { text: `${c >= 0 ? '+' : ''}${c.toFixed(2)}%`, up: c >= 0 };
});
const priceSource = computed(() => {
    const key = price.value?.source;
    const known = ['dex', 'trades', 'admin', 'sale'].includes(key) ? key : 'default';
    return t(`homeClassic.source.${known}`);
});

const chainOk = computed(() => !!chain.value && !failed.value.chain);
const blockText = computed(() => (chainOk.value ? `#${fmtInt(chain.value.block_height)}` : DASH));
const validatorText = computed(() => (
    chainOk.value ? `${chain.value.active_validators ?? DASH} / ${chain.value.total_validators ?? DASH}` : DASH
));
const blockTime = computed(() => chain.value?.block_time || 2);

const heroStats = computed(() => [
    { key: 'price', label: t('homeClassic.statPrice'), value: priceText.value, live: !!price.value && !failed.value.price, loading: !loaded.value.price },
    { key: 'block', label: t('homeClassic.statBlock'), value: blockText.value, live: chainOk.value, loading: !loaded.value.chain, mono: true },
    { key: 'validators', label: t('homeClassic.statValidators'), value: validatorText.value, live: chainOk.value, loading: !loaded.value.chain },
    { key: 'speed', label: t('homeClassic.statSpeed'), value: t('homeClassic.statSpeedValue', { s: blockTime.value }), live: false, loading: false },
]);

// ── Masternode ───────────────────────────────────────────────
const registryLive = computed(() => !!node.value?.registry_deployed);
// อ่านเชนไม่ได้ (RPC สะดุด) = ไม่รู้สถานะ → ป้ายกลาง ๆ ไม่บอกว่า "เปิดเร็ว ๆ นี้"
const registryUnknown = computed(() => !!node.value && !registryLive.value && !node.value.rpc_connected);
const poolUnfunded = computed(() => registryLive.value && !!node.value?.pool_unfunded);
const registryLink = computed(() => (
    node.value?.registry_address ? `${node.value.explorer_url}/address/${node.value.registry_address}` : null
));
const tiersFromChain = computed(() => tiers.value.some(tier => tier.source === 'chain'));

// สีและภาพประจำระดับ — ไล่จากฟ้า (เริ่มต้น) ไปม่วงชมพู (ผู้ผลิตบล็อก) ตามโทนโลโก้
const TIER_STYLE = {
    Light: { img: 'tier-light', accent: '34 211 238' },
    Sentinel: { img: 'tier-sentinel', accent: '59 130 246' },
    Guardian: { img: 'tier-guardian', accent: '139 92 246' },
    Validator: { img: 'tier-validator', accent: '217 70 239' },
};

const tierCards = computed(() => tiers.value.map(tier => {
    const style = TIER_STYLE[tier.name] || TIER_STYLE.Light;
    const max = Number(tier.max_nodes) || 0;
    return {
        name: tier.name,
        role: t(`homeClassic.tierRole.${tier.name}`),
        img: art(style.img),
        accent: style.accent,
        stake: fmtStake(Number(tier.min_stake)),
        lock: t('homeClassic.tierDays', { n: tier.lock_days }),
        slots: max > 0 ? `${fmtInt(tier.active_nodes || 0)} / ${fmtInt(max)}` : t('homeClassic.tierUnlimited'),
        apr: tier.apr_cap_bps ? `${Math.round(Number(tier.apr_cap_bps) / 100)}%` : DASH,
        kyc: tier.name === 'Validator',
    };
}));

const nodeTrust = computed(() => [
    {
        key: 'contract',
        label: t('homeClassic.trustContract'),
        value: node.value?.registry_address ? shortAddr(node.value.registry_address) : (registryLive.value ? 'NodeRegistry' : DASH),
        href: registryLink.value,
        mono: true,
    },
    { key: 'validators', label: t('homeClassic.trustValidators'), value: validatorText.value },
    { key: 'block', label: t('homeClassic.trustBlock'), value: blockText.value, mono: true },
    { key: 'schedule', label: t('homeClassic.trustSchedule'), value: t('homeClassic.trustScheduleValue') },
]);

const nodeSteps = computed(() => [1, 2, 3].map(n => ({
    n,
    title: t(`homeClassic.step${n}`),
    desc: t(`homeClassic.step${n}Desc`),
})));

// ── TPIX Chain ───────────────────────────────────────────────
const chainFacts = computed(() => [
    { label: t('homeClassic.factChainId'), value: String(chain.value?.chain_id ?? 4289) },
    { label: t('homeClassic.factConsensus'), value: 'IBFT · PoA' },
    { label: t('homeClassic.factBlockTime'), value: `${blockTime.value}s` },
    { label: t('homeClassic.factFinality'), value: t('homeClassic.factFinalityValue') },
    { label: t('homeClassic.factGas'), value: t('homeClassic.factGasValue') },
    { label: t('homeClassic.factSupply'), value: '7,000,000,000 TPIX' },
]);
const explorerUrl = computed(() => node.value?.explorer_url || 'https://explorer.tpix.online');

// ── รอบขาย ───────────────────────────────────────────────────
const salePhases = computed(() => (sale.value?.phases || []).filter(p => p.price_usd > 0));
const salePay = computed(() => {
    const methods = sale.value?.payment_methods || {};
    const out = [];
    if (methods.card) out.push(t('homeClassic.payCard'));
    if (methods.bank) out.push(t('homeClassic.payBank'));
    if (methods.crypto) out.push(t('homeClassic.payCrypto'));
    return out;
});

// ── ระบบนิเวศ + จุดเด่น ────────────────────────────────────────
const ecosystem = computed(() => [
    { key: 'trade', href: '/trade', img: art('eco-trade') },
    { key: 'ai', href: '/ai-trade', img: art('eco-ai') },
    { key: 'node', href: '/masternode', img: art('eco-node') },
    { key: 'sale', href: '/token-sale', img: art('eco-sale') },
    { key: 'explorer', href: '/explorer', img: art('eco-explorer') },
    { key: 'apps', href: '/download', img: art('eco-apps') },
].map(card => ({
    ...card,
    title: t(`homeClassic.eco.${card.key}`),
    desc: t(`homeClassic.eco.${card.key}Desc`),
})));

// ไอคอน 3D ชุดเดียวกับหน้าแรก 3D (เจนจาก ChatGPT รอบ 2026-09-27)
const whyItems = computed(() => [
    { key: 'gas', img: '/images/home3d/feature-gas.webp' },
    { key: 'speed', img: '/images/home3d/feature-speed.webp' },
    { key: 'custody', img: '/images/home3d/feature-shield.webp' },
    { key: 'chains', img: '/images/home3d/feature-network.webp' },
].map(item => ({
    ...item,
    title: t(`homeClassic.why.${item.key}`),
    desc: t(`homeClassic.why.${item.key}Desc`),
})));
</script>

<template>
    <Head :title="t('homeClassic.metaTitle')" />

    <AppLayout :hide-sidebar="true">
        <!-- ═══ HERO — ภาพเต็มขอบจอ ข้อความฝั่งซ้าย ═══ -->
        <section class="hc-island hc-hero -mx-4 -mt-4 lg:-mx-6 lg:-mt-6">
            <img
                :src="art('hero')"
                :srcset="`${art('hero-960')} 960w, ${art('hero')} 1672w`"
                sizes="100vw"
                alt=""
                fetchpriority="high"
                decoding="async"
                class="hc-hero__img"
            />
            <div class="hc-hero__shade" aria-hidden="true"></div>
            <div class="hc-grid" aria-hidden="true"></div>
            <div class="hc-sweep" aria-hidden="true"></div>

            <div class="relative max-w-6xl mx-auto px-4 lg:px-6 pt-16 pb-10 md:pt-24 md:pb-14">
                <div class="max-w-3xl">
                    <div class="hc-eyebrow mb-6">
                        <span class="hc-dot"></span>
                        {{ t('homeClassic.heroEyebrow') }}
                    </div>

                    <h1 class="hc-balance text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-bold leading-[1.12] text-white mb-6">
                        {{ t('homeClassic.heroTitle1') }}
                        <span class="block hc-gradient-text">{{ t('homeClassic.heroTitle2') }}</span>
                    </h1>

                    <p class="text-base md:text-lg text-dark-300 leading-relaxed max-w-xl mb-9">
                        {{ t('homeClassic.heroDesc') }}
                    </p>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <Link href="/trade" class="hc-btn hc-btn--primary">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                            </svg>
                            {{ t('homeClassic.ctaTrade') }}
                        </Link>
                        <Link href="/masternode" class="hc-btn hc-btn--ghost">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                            </svg>
                            {{ t('homeClassic.ctaNode') }}
                        </Link>
                    </div>
                    <a href="#intro-video" class="hc-watch mt-5">
                        <span class="hc-watch__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13a1 1 0 001.5.86l10.5-6.5a1 1 0 000-1.72L9.5 4.64A1 1 0 008 5.5z" /></svg>
                        </span>
                        {{ t('introVideo.title') }}
                    </a>
                </div>

                <!-- สถิติสดของเชน — ทุกช่องดึงจาก API -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-14 md:mt-20">
                    <div v-for="stat in heroStats" :key="stat.key" class="hc-stat">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[11px] uppercase tracking-wider text-dark-400">{{ stat.label }}</span>
                            <span v-if="stat.live" class="hc-live">
                                <span class="hc-dot hc-dot--sm"></span>{{ t('homeClassic.live') }}
                            </span>
                        </div>
                        <div
                            :class="['text-lg sm:text-xl md:text-2xl font-bold text-white truncate', stat.mono && 'font-mono tabular-nums']"
                        >
                            <span v-if="stat.loading" class="hc-skeleton"></span>
                            <template v-else>{{ stat.value }}</template>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ คลิปแนะนำ (น้อง TPIX พากย์อังกฤษ ซับไทย) — โหลดไฟล์เมื่อกดเล่นเท่านั้น ═══ -->
        <section id="intro-video" class="max-w-6xl mx-auto pt-16 scroll-mt-24">
            <div class="grid lg:grid-cols-5 gap-8 items-center">
                <div class="lg:col-span-2">
                    <p class="hc-kicker">{{ t('introVideo.eyebrow') }}</p>
                    <h2 class="text-3xl md:text-4xl font-bold text-white leading-tight mb-4">{{ t('introVideo.title') }}</h2>
                    <p class="text-dark-400 leading-relaxed">{{ t('introVideo.desc') }}</p>
                </div>
                <div class="lg:col-span-3">
                    <IntroVideo />
                </div>
            </div>
        </section>

        <!-- ═══ ตลาดสด ═══ -->
        <section class="max-w-6xl mx-auto pt-16">
            <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
                <div>
                    <p class="hc-kicker">{{ t('homeClassic.marketsEyebrow') }}</p>
                    <h2 class="text-2xl md:text-3xl font-bold text-white">{{ t('homeClassic.marketsTitle') }}</h2>
                    <p class="text-sm text-dark-400 mt-1">{{ t('homeClassic.marketsDesc') }}</p>
                </div>
                <Link href="/markets" class="text-sm font-semibold text-primary-400 hover:text-primary-300">
                    {{ t('home.viewAll') }} →
                </Link>
            </div>

            <div class="grid md:grid-cols-2 gap-5">
                <div v-for="list in marketLists" :key="list.key" class="glass-dark rounded-2xl p-5 hc-edge">
                    <h3 class="text-sm font-semibold text-dark-300 uppercase tracking-wider mb-3">{{ list.title }}</h3>
                    <div v-if="isLoading" class="py-10 text-center text-dark-400 text-sm animate-pulse">
                        {{ t('homeClassic.loading') }}
                    </div>
                    <div v-else-if="!list.coins.length" class="py-10 text-center text-dark-500 text-sm">{{ DASH }}</div>
                    <div v-else class="divide-y divide-white/5">
                        <div
                            v-for="coin in list.coins"
                            :key="coin.symbol"
                            class="flex items-center justify-between gap-3 py-3"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <CoinIcon :symbol="coin.symbol" size="lg" />
                                <div class="min-w-0">
                                    <p class="font-semibold text-white">{{ coin.symbol }}</p>
                                    <p class="text-xs text-dark-400 truncate">{{ coin.name }}</p>
                                </div>
                            </div>
                            <Sparkline
                                class="hidden sm:block"
                                :points="series[`${coin.symbol}-USDT`] || []"
                                :is-up="coin.isUp"
                                :width="84"
                                :height="28"
                                :loading="series[`${coin.symbol}-USDT`] === undefined"
                            />
                            <div class="text-right">
                                <p class="font-mono text-white tabular-nums">${{ coin.price }}</p>
                                <p :class="['text-sm font-medium tabular-nums', coin.isUp ? 'text-trading-green' : 'text-trading-red']">
                                    {{ coin.change }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ MASTERNODE — ภาพศูนย์ข้อมูล + ข้อมูลจากสัญญาจริง ═══ -->
        <section id="masternode" class="max-w-6xl mx-auto pt-16">
            <div class="hc-island hc-panel">
                <img
                    :src="art('masternode')"
                    :srcset="`${art('masternode-960')} 960w, ${art('masternode')} 1672w`"
                    sizes="(min-width: 1152px) 1152px, 100vw"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="hc-panel__img hc-panel__img--top"
                />
                <div class="hc-panel__shade hc-panel__shade--node" aria-hidden="true"></div>

                <div class="relative p-6 md:p-10 lg:p-12">
                    <div class="max-w-xl">
                        <div :class="['hc-eyebrow mb-5', !registryLive && 'hc-eyebrow--muted']">
                            <span :class="['hc-dot', !registryLive && 'hc-dot--idle']"></span>
                            {{ registryLive ? t('homeClassic.nodeLive') : (!node || registryUnknown) ? t('homeClassic.nodeEyebrow') : t('homeClassic.nodeSoon') }}
                        </div>
                        <h2 class="text-3xl md:text-5xl font-bold text-white leading-tight mb-4">
                            {{ t('homeClassic.nodeTitle1') }}
                            <span class="block hc-gradient-text">{{ t('homeClassic.nodeTitle2') }}</span>
                        </h2>
                        <p class="text-dark-300 leading-relaxed">{{ t('homeClassic.nodeDesc') }}</p>
                    </div>

                    <!-- หลักฐานที่ตรวจเองได้ -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-8">
                        <component
                            :is="item.href ? 'a' : 'div'"
                            v-for="item in nodeTrust"
                            :key="item.key"
                            :href="item.href || undefined"
                            :target="item.href ? '_blank' : undefined"
                            :rel="item.href ? 'noopener noreferrer' : undefined"
                            :class="['hc-chip', item.href && 'hc-chip--link']"
                        >
                            <span class="text-[11px] uppercase tracking-wider text-dark-400">{{ item.label }}</span>
                            <span :class="['text-sm md:text-base font-semibold text-white truncate', item.mono && 'font-mono tabular-nums']">
                                {{ item.value }}
                            </span>
                            <span v-if="item.href" class="text-[11px] text-primary-300">{{ t('homeClassic.viewOnExplorer') }} ↗</span>
                        </component>
                    </div>

                    <!-- 3 ขั้นตอน -->
                    <ol class="grid md:grid-cols-3 gap-3 mt-3">
                        <li v-for="step in nodeSteps" :key="step.n" class="hc-step">
                            <span class="hc-step__n">{{ step.n }}</span>
                            <div>
                                <p class="font-semibold text-white">{{ step.title }}</p>
                                <p class="text-sm text-dark-400 leading-relaxed mt-1">{{ step.desc }}</p>
                            </div>
                        </li>
                    </ol>

                    <!-- ระดับโหนด อ่านจากสัญญา -->
                    <div class="flex items-center gap-2 mt-10 mb-4">
                        <h3 class="text-lg font-semibold text-white">{{ t('homeClassic.tiersTitle') }}</h3>
                        <span v-if="tiersFromChain" class="hc-live">
                            <span class="hc-dot hc-dot--sm"></span>{{ t('homeClassic.fromChain') }}
                        </span>
                    </div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div
                            v-for="tier in tierCards"
                            :key="tier.name"
                            class="hc-tier"
                            :style="{ '--tier': tier.accent }"
                        >
                            <img :src="tier.img" :alt="`${tier.name} tier emblem`" loading="lazy" decoding="async" class="hc-tier__img" width="160" height="160" />
                            <p class="text-lg font-bold text-white text-center">{{ tier.name }}</p>
                            <p class="text-xs text-dark-400 text-center mb-4">{{ tier.role }}</p>
                            <dl class="hc-tier__rows">
                                <div><dt>{{ t('homeClassic.tierStake') }}</dt><dd>{{ tier.stake }} TPIX</dd></div>
                                <div><dt>{{ t('homeClassic.tierLock') }}</dt><dd>{{ tier.lock }}</dd></div>
                                <div><dt>{{ t('homeClassic.tierSlots') }}</dt><dd>{{ tier.slots }}</dd></div>
                            </dl>
                            <div class="hc-tier__apr">
                                <span>{{ t('homeClassic.tierApr') }}</span>
                                <strong>{{ tier.apr }}</strong>
                            </div>
                            <p v-if="tier.kyc" class="text-[11px] text-amber-300/90 text-center mt-2">{{ t('homeClassic.tierKyc') }}</p>
                        </div>
                    </div>

                    <p class="text-xs text-dark-400 leading-relaxed mt-4 max-w-3xl">
                        <span v-if="poolUnfunded" class="text-amber-300/90 font-semibold mr-1.5">{{ t('homeClassic.poolUnfunded') }}</span>{{ t('homeClassic.aprNote') }}
                    </p>

                    <div class="flex flex-wrap gap-3 mt-8">
                        <Link href="/masternode" class="hc-btn hc-btn--primary">{{ t('homeClassic.nodeStake') }}</Link>
                        <Link href="/masternode/guide" class="hc-btn hc-btn--ghost">{{ t('homeClassic.nodeGuide') }}</Link>
                        <Link href="/download" class="hc-btn hc-btn--ghost">{{ t('homeClassic.nodeDownload') }}</Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ TPIX CHAIN + ราคา TPIX ═══ -->
        <section class="max-w-6xl mx-auto pt-16">
            <div class="hc-island hc-panel">
                <img
                    :src="art('chain')"
                    :srcset="`${art('chain-960')} 960w, ${art('chain')} 1672w`"
                    sizes="(min-width: 1152px) 1152px, 100vw"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="hc-panel__img"
                />
                <div class="hc-panel__shade" aria-hidden="true"></div>

                <div class="relative grid lg:grid-cols-5 gap-8 p-6 md:p-10 lg:p-12">
                    <div class="lg:col-span-3">
                        <p class="hc-kicker">{{ t('homeClassic.chainEyebrow') }}</p>
                        <h2 class="text-3xl md:text-4xl font-bold text-white leading-tight mb-4">{{ t('homeClassic.chainTitle') }}</h2>
                        <p class="text-dark-300 leading-relaxed max-w-xl">{{ t('homeClassic.chainDesc') }}</p>

                        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-8">
                            <div v-for="fact in chainFacts" :key="fact.label" class="hc-chip">
                                <dt class="text-[11px] uppercase tracking-wider text-dark-400">{{ fact.label }}</dt>
                                <dd class="text-sm md:text-base font-semibold text-white">{{ fact.value }}</dd>
                            </div>
                        </dl>

                        <div class="flex flex-wrap gap-3 mt-8">
                            <a :href="explorerUrl" target="_blank" rel="noopener noreferrer" class="hc-btn hc-btn--ghost">
                                {{ t('homeClassic.openExplorer') }} ↗
                            </a>
                            <Link href="/whitepaper" class="hc-btn hc-btn--ghost">{{ t('homeClassic.readWhitepaper') }}</Link>
                        </div>
                    </div>

                    <!-- ราคา TPIX สด -->
                    <div class="lg:col-span-2 lg:self-end">
                        <div class="hc-price">
                            <div class="flex items-center gap-3 mb-4">
                                <img src="/images/brand/tpix-coin.webp" alt="TPIX" class="w-10 h-10" width="40" height="40" loading="lazy" />
                                <div>
                                    <p class="font-semibold text-white">{{ t('homeClassic.priceTitle') }}</p>
                                    <p class="text-xs text-dark-400">TPIX · Chain {{ chain?.chain_id ?? 4289 }}</p>
                                </div>
                                <span v-if="price && !failed.price" class="hc-live ml-auto">
                                    <span class="hc-dot hc-dot--sm"></span>{{ t('homeClassic.live') }}
                                </span>
                            </div>
                            <div class="flex items-end gap-3">
                                <span class="text-4xl font-bold text-white tabular-nums">
                                    <span v-if="!loaded.price" class="hc-skeleton hc-skeleton--lg"></span>
                                    <template v-else>{{ priceText }}</template>
                                </span>
                                <span
                                    v-if="price && !failed.price"
                                    :class="['text-sm font-semibold mb-1.5 tabular-nums', priceChange.up ? 'text-trading-green' : 'text-trading-red']"
                                >{{ priceChange.text }}</span>
                            </div>
                            <p v-if="price && !failed.price" class="text-xs text-dark-400 mt-3">
                                {{ t('homeClassic.priceSource', { source: priceSource }) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ รอบขายเหรียญ ═══ -->
        <section class="max-w-6xl mx-auto pt-16">
            <div class="hc-island hc-panel hc-panel--sale">
                <div class="relative grid md:grid-cols-5 gap-8 items-center p-6 md:p-10 lg:p-12">
                    <div class="md:col-span-3 order-2 md:order-1">
                        <p class="hc-kicker">{{ t('homeClassic.saleEyebrow') }}</p>
                        <h2 class="text-3xl md:text-4xl font-bold text-white leading-tight mb-4">
                            {{ t('homeClassic.saleTitle1') }}
                            <span class="hc-gradient-text">{{ t('homeClassic.saleTitle2') }}</span>
                        </h2>
                        <p class="text-dark-300 leading-relaxed max-w-xl">{{ t('homeClassic.saleDesc') }}</p>

                        <div v-if="salePhases.length" class="grid grid-cols-3 gap-3 mt-7 max-w-lg">
                            <div
                                v-for="phase in salePhases"
                                :key="phase.name"
                                :class="['hc-chip text-center', phase.status === 'active' && 'hc-chip--active']"
                            >
                                <span class="text-[11px] text-dark-400 truncate">{{ phase.name }}</span>
                                <span class="text-lg font-bold text-white tabular-nums">{{ fmtUsd(phase.price_usd) }}</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 mt-5">
                            <span v-for="pay in salePay" :key="pay" class="hc-tag">{{ pay }}</span>
                            <span class="hc-tag">{{ t('homeClassic.receiveOn') }}</span>
                        </div>

                        <div class="flex flex-wrap gap-3 mt-8">
                            <Link href="/token-sale" class="hc-btn hc-btn--primary">{{ t('homeClassic.saleCta') }}</Link>
                            <Link href="/whitepaper" class="hc-btn hc-btn--ghost">{{ t('homeClassic.readWhitepaper') }}</Link>
                        </div>
                        <p class="text-xs text-dark-500 mt-4">{{ t('homeClassic.saleRisk') }}</p>
                    </div>

                    <div class="md:col-span-2 order-1 md:order-2 flex justify-center">
                        <div class="hc-coin">
                            <span class="hc-coin__glow" aria-hidden="true"></span>
                            <img :src="art('coin')" alt="TPIX coin" loading="lazy" decoding="async" width="560" height="560" class="hc-coin__img" />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ ระบบนิเวศ ═══ -->
        <section class="max-w-6xl mx-auto pt-20">
            <div class="text-center mb-10">
                <p class="hc-kicker">{{ t('homeClassic.ecoEyebrow') }}</p>
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-3">{{ t('homeClassic.ecoTitle') }}</h2>
                <p class="text-dark-400 max-w-2xl mx-auto">{{ t('homeClassic.ecoDesc') }}</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <Link v-for="card in ecosystem" :key="card.key" :href="card.href" class="eco-card group">
                    <div class="eco-card__media">
                        <img :src="card.img" alt="" loading="lazy" decoding="async" width="800" height="450" />
                        <span class="eco-card__scrim"></span>
                    </div>
                    <div class="relative p-5 pt-1">
                        <h3 class="text-lg font-semibold text-white mb-1.5 flex items-center justify-between gap-2">
                            {{ card.title }}
                            <span class="text-primary-400 transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                        </h3>
                        <p class="text-dark-400 text-sm leading-relaxed">{{ card.desc }}</p>
                    </div>
                </Link>
            </div>
        </section>

        <!-- ═══ ทำไมต้อง TPIX TRADE ═══ -->
        <section class="max-w-6xl mx-auto pt-20">
            <div class="text-center mb-10">
                <p class="hc-kicker">{{ t('homeClassic.whyEyebrow') }}</p>
                <h2 class="text-3xl md:text-4xl font-bold text-white">{{ t('homeClassic.whyTitle') }}</h2>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div v-for="item in whyItems" :key="item.key" class="glass-card hc-edge text-center group">
                    <img :src="item.img" alt="" loading="lazy" decoding="async" width="112" height="112" class="w-24 h-24 mx-auto mb-4 transition-transform duration-500 group-hover:scale-110" />
                    <h3 class="text-lg font-semibold text-white mb-2">{{ item.title }}</h3>
                    <p class="text-sm text-dark-400 leading-relaxed">{{ item.desc }}</p>
                </div>
            </div>
        </section>

        <!-- ป้ายโฆษณาด้านล่าง Home -->
        <BannerAd placement="home_bottom" class="pt-12 max-w-6xl mx-auto" />

        <!-- ═══ CTA ═══ -->
        <section class="max-w-6xl mx-auto pt-12 pb-6">
            <div class="hc-island hc-panel">
                <img
                    :src="art('cta')"
                    :srcset="`${art('cta-960')} 960w, ${art('cta')} 1672w`"
                    sizes="(min-width: 1152px) 1152px, 100vw"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="hc-panel__img"
                />
                <div class="hc-panel__shade hc-panel__shade--cta" aria-hidden="true"></div>
                <div class="relative grid md:grid-cols-3 items-end gap-6 px-6 md:px-12 pt-10 md:pt-12">
                    <div class="md:col-span-2 pb-10 md:pb-12 text-center md:text-left">
                        <h2 class="text-3xl md:text-5xl font-bold text-white mb-4">{{ t('homeClassic.ctaTitle') }}</h2>
                        <p class="text-dark-300 mb-8 max-w-xl md:mx-0 mx-auto">{{ t('homeClassic.ctaDesc') }}</p>
                        <Link href="/trade" class="hc-btn hc-btn--primary">
                            {{ t('homeClassic.ctaButton') }}
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </Link>
                        <p class="text-xs text-dark-500 mt-6">{{ t('homeClassic.riskNote') }}</p>
                    </div>
                    <img
                        src="/images/mascot/welcome.webp"
                        alt="Nong TPIX"
                        loading="lazy"
                        decoding="async"
                        class="hidden md:block w-full max-w-[260px] mx-auto self-end"
                    />
                </div>
            </div>
        </section>

        <!-- Footer ใช้จาก AppLayout — ไม่ต้องซ้ำที่นี่ -->
    </AppLayout>
</template>

<style scoped>
/* ── เกาะมืด: ตรึงตัวแปรสีของธีมมืดไว้ในแผงที่มีภาพพื้นมืด ──────────
   tailwind ทุกคลาส (text-white, text-dark-300 ฯลฯ) อ่านจาก --c-* ผ่าน rgb(var())
   ประกาศซ้ำที่นี่ = ลูกทุกตัวในแผงได้สีธีมมืด แม้ทั้งหน้าจะเป็น xp-silver   */
.hc-island {
    --c-white: 255 255 255;
    --c-black: 0 0 0;
    --c-dark-300: 203 213 225;
    --c-dark-400: 148 163 184;
    --c-dark-500: 100 116 139;
    --c-dark-900: 15 23 42;
    --c-dark-950: 2 6 23;
    --c-primary-300: 103 232 249;
    --c-primary-400: 34 211 238;
    --c-primary-500: 6 182 212;
    color: rgb(255 255 255);
    color-scheme: dark;
}

/* ── HERO ─────────────────────────────────────────────── */
.hc-hero {
    position: relative;
    overflow: hidden;
    background: #050914;
    min-height: 640px;
}

.hc-hero__img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 72% 50%;
}

/* เงาไล่จากซ้าย (ที่วางข้อความ) + ก้นภาพจมลงพื้นหน้า */
.hc-hero__shade {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(90deg, #050914 0%, rgb(5 9 20 / 0.92) 28%, rgb(5 9 20 / 0.35) 58%, rgb(5 9 20 / 0.1) 100%),
        linear-gradient(0deg, #050914 0%, rgb(5 9 20 / 0.6) 18%, transparent 45%);
}

@media (max-width: 767px) {
    .hc-hero { min-height: 0; }
    .hc-hero__img { object-position: 78% 30%; }
    .hc-hero__shade {
        background: linear-gradient(180deg, rgb(5 9 20 / 0.55) 0%, rgb(5 9 20 / 0.85) 45%, #050914 100%);
    }
}

/* ตารางเส้นบาง ๆ แบบจอเทรด — มาสก์ให้จางลงทางขวาและล่าง */
.hc-grid {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgb(34 211 238 / 0.06) 1px, transparent 1px),
        linear-gradient(90deg, rgb(34 211 238 / 0.06) 1px, transparent 1px);
    background-size: 56px 56px;
    mask-image: radial-gradient(ellipse 70% 80% at 20% 40%, #000 0%, transparent 75%);
    -webkit-mask-image: radial-gradient(ellipse 70% 80% at 20% 40%, #000 0%, transparent 75%);
    pointer-events: none;
}

/* แสงกวาดช้า ๆ หนึ่งเส้น — transform อย่างเดียว ใช้ GPU ไม่ทำให้เครื่องอ่อนช้า */
.hc-sweep {
    position: absolute;
    top: 0;
    bottom: 0;
    left: -30%;
    width: 30%;
    background: linear-gradient(90deg, transparent, rgb(34 211 238 / 0.07), rgb(139 92 246 / 0.06), transparent);
    transform: skewX(-18deg);
    animation: hc-sweep 9s ease-in-out infinite;
    pointer-events: none;
}

@keyframes hc-sweep {
    0% { transform: translateX(0) skewX(-18deg); }
    60%, 100% { transform: translateX(480%) skewX(-18deg); }
}

/* ── ชิ้นส่วนร่วม ─────────────────────────────────────── */
/* หัวข้อภาษาไทยยาว — แบ่งบรรทัดให้เท่ากัน ไม่ให้เหลือคำเดียวตกบรรทัดสุดท้าย */
.hc-balance,
.hc-balance > span {
    text-wrap: balance;
}

.hc-gradient-text {
    background-image: linear-gradient(90deg, #22d3ee 0%, #3b82f6 35%, #8b5cf6 65%, #d946ef 85%, #f59e0b 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.hc-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.9rem;
    border-radius: 9999px;
    font-size: 0.8rem;
    font-weight: 600;
    color: rgb(165 243 252);
    background: rgb(34 211 238 / 0.08);
    border: 1px solid rgb(34 211 238 / 0.25);
    backdrop-filter: blur(8px);
}

.hc-eyebrow--muted {
    color: rgb(203 213 225);
    background: rgb(255 255 255 / 0.05);
    border-color: rgb(255 255 255 / 0.12);
}

.hc-kicker {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgb(var(--c-primary-400));
    margin-bottom: 0.5rem;
}

.hc-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 9999px;
    background: #00c853;
    box-shadow: 0 0 0 0 rgb(0 200 83 / 0.6);
    animation: hc-ping 2s ease-out infinite;
    flex-shrink: 0;
}

.hc-dot--sm { width: 0.375rem; height: 0.375rem; }
.hc-dot--idle { background: #94a3b8; animation: none; }

@keyframes hc-ping {
    0% { box-shadow: 0 0 0 0 rgb(0 200 83 / 0.55); }
    80%, 100% { box-shadow: 0 0 0 6px rgb(0 200 83 / 0); }
}

.hc-live {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #69f0ae;
}

.hc-skeleton {
    display: inline-block;
    width: 6rem;
    height: 1.25em;
    border-radius: 0.375rem;
    background: linear-gradient(90deg, rgb(255 255 255 / 0.06), rgb(255 255 255 / 0.14), rgb(255 255 255 / 0.06));
    background-size: 200% 100%;
    animation: hc-shimmer 1.4s linear infinite;
    vertical-align: middle;
}

.hc-skeleton--lg { width: 8rem; }

@keyframes hc-shimmer {
    to { background-position: -200% 0; }
}

/* ปุ่มของหน้านี้เอง — ไม่ใช้ .btn-primary เพราะธีม xp-silver เปลี่ยนหน้าตาปุ่มนั้นเป็นแผ่นเงิน */
.hc-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.85rem 1.6rem;
    border-radius: 0.875rem;
    font-weight: 600;
    font-size: 0.95rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, border-color 0.2s ease;
}

.hc-btn:focus-visible {
    outline: 2px solid #22d3ee;
    outline-offset: 3px;
}

.hc-btn--primary {
    color: #fff;
    background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 50%, #8b5cf6 100%);
    box-shadow: 0 0 0 1px rgb(255 255 255 / 0.12) inset, 0 8px 28px -8px rgb(34 211 238 / 0.6);
}

.hc-btn--primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 0 0 1px rgb(255 255 255 / 0.2) inset, 0 12px 36px -8px rgb(139 92 246 / 0.7);
}

.hc-btn--ghost {
    color: #e2e8f0;
    background: rgb(255 255 255 / 0.05);
    border: 1px solid rgb(255 255 255 / 0.14);
    backdrop-filter: blur(10px);
}

.hc-btn--ghost:hover {
    background: rgb(255 255 255 / 0.1);
    border-color: rgb(34 211 238 / 0.45);
}

/* ลิงก์ "ดูคลิป" ใต้ปุ่มฮีโร่ */
.hc-watch {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #a5f3fc;
    transition: color 0.2s ease;
}

.hc-watch:hover { color: #fff; }

.hc-watch__icon {
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border-radius: 9999px;
    color: #fff;
    background: linear-gradient(135deg, #06b6d4, #8b5cf6);
    box-shadow: 0 0 0 4px rgb(34 211 238 / 0.15);
}

.hc-watch__icon svg { width: 0.9rem; height: 0.9rem; margin-left: 2px; }

/* การ์ดสถิติบนฮีโร่ — เส้นขอบไล่สีบาง ๆ ด้านบน
   การ์ดเล็กที่ซ้อนบนภาพทั้งหน้าไม่ใช้ backdrop-filter: หลายสิบใบเบลอพร้อมกันหนักเกินสำหรับเครื่องอ่อน */
.hc-stat {
    position: relative;
    padding: 1rem 1.1rem;
    border-radius: 1rem;
    background: rgb(8 13 28 / 0.78);
    border: 1px solid rgb(255 255 255 / 0.08);
    overflow: hidden;
    min-width: 0;
}

.hc-stat::before {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgb(34 211 238 / 0.7), rgb(139 92 246 / 0.6), transparent);
}

/* ขอบเรืองแสงตอนชี้ สำหรับการ์ดที่ใช้สีตามธีม */
.hc-edge {
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

.hc-edge:hover {
    border-color: rgb(var(--c-primary-500) / 0.35);
    box-shadow: 0 0 0 1px rgb(var(--c-primary-500) / 0.12), 0 18px 48px -20px rgb(var(--c-primary-500) / 0.45);
}

/* ── แผงภาพ (Masternode / Chain / Sale / CTA) ────────────── */
.hc-panel {
    position: relative;
    overflow: hidden;
    border-radius: 1.75rem;
    background: #050914;
    border: 1px solid rgb(34 211 238 / 0.16);
    box-shadow: 0 30px 80px -40px rgb(34 211 238 / 0.35);
}

.hc-panel__img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 75% 50%;
}

/* แผง Masternode สูงมาก — ภาพอยู่ส่วนบน แล้วจางลงพื้นมืดให้การ์ดระดับอ่านง่าย */
.hc-panel__img--top {
    height: 640px;
    object-position: 75% 35%;
}

.hc-panel__shade {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(90deg, #050914 0%, rgb(5 9 20 / 0.9) 32%, rgb(5 9 20 / 0.35) 65%, rgb(5 9 20 / 0.15) 100%),
        linear-gradient(0deg, rgb(5 9 20 / 0.7) 0%, transparent 40%);
}

.hc-panel__shade--node {
    background:
        linear-gradient(90deg, #050914 0%, rgb(5 9 20 / 0.88) 34%, rgb(5 9 20 / 0.3) 68%, rgb(5 9 20 / 0.1) 100%),
        linear-gradient(180deg, transparent 0px, transparent 380px, #050914 640px);
}

.hc-panel__shade--cta {
    background:
        linear-gradient(90deg, rgb(5 9 20 / 0.92) 0%, rgb(5 9 20 / 0.6) 50%, rgb(5 9 20 / 0.35) 100%);
}

.hc-panel--sale {
    background:
        radial-gradient(ellipse 60% 70% at 78% 50%, rgb(139 92 246 / 0.22), transparent 70%),
        radial-gradient(ellipse 50% 60% at 15% 100%, rgb(34 211 238 / 0.12), transparent 70%),
        #050914;
}

@media (max-width: 767px) {
    .hc-panel__img { object-position: 80% 30%; }
    .hc-panel__shade,
    .hc-panel__shade--cta {
        background: linear-gradient(180deg, rgb(5 9 20 / 0.6) 0%, rgb(5 9 20 / 0.92) 50%, #050914 100%);
    }
    .hc-panel__img--top { height: 420px; }
    .hc-panel__shade--node {
        background: linear-gradient(180deg, rgb(5 9 20 / 0.55) 0px, rgb(5 9 20 / 0.92) 260px, #050914 420px);
    }
}

.hc-chip {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
    padding: 0.8rem 1rem;
    border-radius: 0.875rem;
    background: rgb(8 13 28 / 0.8);
    border: 1px solid rgb(255 255 255 / 0.08);
}

.hc-chip--link {
    transition: border-color 0.2s ease, background-color 0.2s ease;
}

.hc-chip--link:hover {
    border-color: rgb(34 211 238 / 0.5);
    background: rgb(8 30 44 / 0.75);
}

.hc-chip--active {
    border-color: rgb(34 211 238 / 0.55);
    box-shadow: 0 0 24px -8px rgb(34 211 238 / 0.6);
}

.hc-tag {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: rgb(203 213 225);
    background: rgb(255 255 255 / 0.06);
    border: 1px solid rgb(255 255 255 / 0.1);
}

.hc-step {
    display: flex;
    gap: 0.9rem;
    padding: 1rem 1.1rem;
    border-radius: 0.875rem;
    background: rgb(8 13 28 / 0.78);
    border: 1px solid rgb(255 255 255 / 0.07);
}

.hc-step__n {
    flex-shrink: 0;
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.6rem;
    font-weight: 700;
    font-size: 0.9rem;
    color: #fff;
    background: linear-gradient(135deg, #06b6d4, #8b5cf6);
}

/* การ์ดระดับโหนด — สีเน้นมาจาก --tier ของแต่ละระดับ */
.hc-tier {
    position: relative;
    display: flex;
    flex-direction: column;
    padding: 1.1rem 1rem 1rem;
    border-radius: 1.1rem;
    background:
        radial-gradient(ellipse 80% 45% at 50% 0%, rgb(var(--tier) / 0.18), transparent 70%),
        rgb(10 15 30 / 0.85);
    border: 1px solid rgb(var(--tier) / 0.28);
    transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

.hc-tier:hover {
    transform: translateY(-3px);
    border-color: rgb(var(--tier) / 0.6);
    box-shadow: 0 18px 44px -20px rgb(var(--tier) / 0.7);
}

.hc-tier__img {
    width: 7.5rem;
    height: 7.5rem;
    margin: -0.25rem auto 0.5rem;
    object-fit: contain;
    filter: drop-shadow(0 8px 22px rgb(var(--tier) / 0.45));
}

.hc-tier__rows {
    display: grid;
    gap: 0.4rem;
    font-size: 0.8rem;
}

.hc-tier__rows > div {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.hc-tier__rows dt { color: rgb(148 163 184); }
.hc-tier__rows dd { color: #fff; font-weight: 600; text-align: right; }

.hc-tier__apr {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-top: 0.8rem;
    padding-top: 0.7rem;
    border-top: 1px solid rgb(255 255 255 / 0.08);
    font-size: 0.75rem;
    color: rgb(148 163 184);
}

.hc-tier__apr strong {
    font-size: 1.35rem;
    color: rgb(var(--tier));
}

/* การ์ดราคา TPIX */
.hc-price {
    padding: 1.4rem;
    border-radius: 1.25rem;
    background: rgb(8 13 28 / 0.86);
    border: 1px solid rgb(255 255 255 / 0.1);
}

/* เหรียญ TPIX ลอยช้า ๆ */
.hc-coin {
    position: relative;
    width: min(100%, 320px);
    aspect-ratio: 1;
}

.hc-coin__glow {
    position: absolute;
    inset: 12%;
    border-radius: 9999px;
    background: radial-gradient(circle, rgb(139 92 246 / 0.45), rgb(34 211 238 / 0.18) 50%, transparent 70%);
    filter: blur(24px);
}

.hc-coin__img {
    position: relative;
    width: 100%;
    height: 100%;
    object-fit: contain;
    animation: hc-float 6s ease-in-out infinite;
}

@keyframes hc-float {
    0%, 100% { transform: translateY(0) rotate(-2deg); }
    50% { transform: translateY(-12px) rotate(2deg); }
}

/* ── การ์ดระบบนิเวศ — แถบภาพเต็มขอบด้านบน ───────────────── */
.eco-card {
    position: relative;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 1.1rem;
    background: rgb(255 255 255 / 0.04);
    border: 1px solid rgb(255 255 255 / 0.09);
    transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
}

.eco-card:hover {
    transform: translateY(-3px);
    background: rgb(255 255 255 / 0.07);
    border-color: rgb(var(--c-primary-500) / 0.4);
    box-shadow: 0 20px 50px -24px rgb(var(--c-primary-500) / 0.55);
}

.eco-card__media {
    position: relative;
    aspect-ratio: 16 / 8;
    overflow: hidden;
}

.eco-card__media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
}

.eco-card:hover .eco-card__media img {
    transform: scale(1.06);
}

.eco-card__scrim {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgb(var(--c-dark-950) / 0.85) 0%, transparent 55%);
}

@media (prefers-reduced-motion: reduce) {
    .hc-sweep,
    .hc-dot,
    .hc-skeleton,
    .hc-coin__img {
        animation: none;
    }
    .eco-card,
    .eco-card__media img,
    .hc-tier,
    .hc-btn {
        transition: none;
    }
    .eco-card:hover,
    .hc-tier:hover,
    .hc-btn--primary:hover,
    .eco-card:hover .eco-card__media img {
        transform: none;
    }
}
</style>
