<script setup>
/**
 * TPIX TRADE — AI TRADE card
 * การ์ดเช่าบอทเทรดคลาวด์ในหน้าเทรด
 *
 * สถานะที่ต้องรองรับครบ:
 *  1) ยังไม่เชื่อมกระเป๋า      → ชวนเชื่อม
 *  2) เชื่อมแล้วแต่ยังไม่เช่า   → กดแล้วเด้งเตือนให้เติมเครดิต/เลือกแพลน
 *  3) เช่าอยู่                → เห็นแพลน วันคงเหลือ เครดิต และบอทของตัวเอง สั่งเริ่ม/พักได้
 *  4) กระเป๋ายังไม่เซ็นยืนยัน   → กดเซ็นยืนยันได้จากในการ์ดเลย (ลายเซ็นหมดอายุ 4 ชม.)
 *
 * ย่อตัวเองตามความกว้างของการ์ด — ย้ายไปคอลัมน์ซ้าย (276px) ก็ยังอ่านครบ
 * ระบบเดียวกับในแอพ TPIX (คลาวด์) — ข้อมูลมาจาก /api/v1/ai-bot/*
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, watch, useTemplateRef } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useElementSize } from '@vueuse/core';
import { useAiBot } from '@/Composables/useAiBot';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { playClickSound, playErrorSound, playNotificationSound } from '@/Composables/useSounds';

const props = defineProps({
    /** คู่เทรดที่เปิดอยู่ — ส่งต่อไปหน้าตั้งค่าเพื่อสร้างบอทของคู่นี้เลย */
    pair: { type: String, default: 'BTC/USDT' },
});

const walletStore = useWalletStore();
const bot = useAiBot();
const { t } = useTranslation();

const root = useTemplateRef('root');
const { width } = useElementSize(root);
/** ResizeObserver อาจยังไม่ยิงในเฟรมแรก — วัดเองหนึ่งครั้งตอน mount เป็นค่าสำรอง */
const initialWidth = ref(0);
const cardWidth = computed(() => Math.max(width.value, initialWidth.value));
/** การ์ดอยู่ในคอลัมน์แคบ (เช่นคอลัมน์คู่เทรดด้านซ้าย) → ตัดส่วนประดับออก เหลือแต่ข้อมูล */
const isCompact = computed(() => cardWidth.value > 0 && cardWidth.value < 300);

const showGate = ref(false);
const selectedPlan = ref(null);
const selectedDays = ref(7);
const gateNotice = ref(null); // { type: 'error' | 'success', text }

const tierStyle = {
    basic: { ring: 'ring-primary-500/40', text: 'text-primary-300', glow: 'shadow-glow-sm' },
    pro: { ring: 'ring-accent-500/50', text: 'text-accent-300', glow: 'shadow-glow-purple' },
    vip: { ring: 'ring-warm-500/50', text: 'text-warm-300', glow: 'shadow-glow-warm' },
};

const activeTier = computed(() => bot.subscription.value?.tier || 'basic');
const activeStyle = computed(() => tierStyle[activeTier.value] || tierStyle.basic);

const cost = computed(() => (selectedPlan.value ? bot.costOf(selectedPlan.value, selectedDays.value) : 0));
const affordable = computed(() => (selectedPlan.value ? bot.canAfford(selectedPlan.value, selectedDays.value) : false));
const shortfall = computed(() => Math.max(0, cost.value - bot.credits.value));

/** แพ็กที่เล็กที่สุดที่เติมแล้วพอจ่าย — ใช้ไฮไลต์ให้ผู้ใช้ไม่ต้องคิดเอง */
const suggestedPack = computed(() => {
    if (shortfall.value <= 0) return null;
    return [...bot.packs.value]
        .sort((a, b) => a.credits - b.credits)
        .find(p => p.credits + (p.bonus || 0) >= shortfall.value) || bot.packs.value.at(-1) || null;
});

const runningCount = computed(() => bot.runningBots.value.length);
const settingsHref = computed(() => `/ai-trade?pair=${encodeURIComponent(props.pair)}`);

function openGate() {
    playClickSound();
    gateNotice.value = null;

    if (!walletStore.isConnected) {
        walletStore.openConnectModal();
        return;
    }

    // เลือกแพลนที่ผู้ใช้น่าจะจ่ายไหวไว้ให้ก่อน ลดขั้นตอนการตัดสินใจ
    if (!selectedPlan.value) {
        const affordablePlan = bot.plans.value.find(p => bot.credits.value >= p.credits_per_day * selectedDays.value);
        selectedPlan.value = (affordablePlan || bot.plans.value[0])?.code || null;
    }

    showGate.value = true;
}

function closeGate() {
    showGate.value = false;
    gateNotice.value = null;
}

async function confirmRent() {
    if (!selectedPlan.value) return;

    if (!affordable.value) {
        playErrorSound();
        gateNotice.value = {
            type: 'error',
            text: t('aiTrade.notEnoughLong', { n: shortfall.value.toLocaleString() }),
        };
        return;
    }

    const result = await bot.subscribe(selectedPlan.value, selectedDays.value);

    if (result.ok) {
        playNotificationSound();
        gateNotice.value = { type: 'success', text: t('aiTrade.activated') };
        setTimeout(closeGate, 1400);
    } else {
        playErrorSound();
        gateNotice.value = { type: 'error', text: result.error.message };
    }
}

async function requestPack(code) {
    const result = await bot.requestTopup(code);
    gateNotice.value = result.ok
        ? { type: 'success', text: t('aiTrade.topupOk') }
        : { type: 'error', text: result.error.message };
}

async function toggleBot(item) {
    playClickSound();
    await bot.setBotState(item.id, item.status === 'running' ? 'pause' : 'start');
}

/**
 * เซ็นยืนยันกระเป๋าใหม่จากในการ์ด — ไม่ต้องตัดการเชื่อมต่อแล้วต่อใหม่.
 *
 * เดิมปุ่มนี้เปิดหน้าต่างเลือกกระเป๋า ซึ่งไม่ตรงกับปัญหา: กระเป๋าเชื่อมอยู่แล้ว
 * ที่หมดอายุคือลายเซ็นฝั่งเซิร์ฟเวอร์ (4 ชั่วโมง) ผู้ใช้ที่กดแล้วเห็นรายชื่อกระเป๋า
 * มักปิดทิ้งเพราะคิดว่ากดผิดปุ่ม แล้วก็ติดค้างอยู่ตรงนั้นต่อ
 */
const isVerifying = ref(false);

async function verifyWallet() {
    if (isVerifying.value) return;   // กันกดรัว — ป๊อปอัพขอลายเซ็นซ้อนกันไม่ได้

    isVerifying.value = true;
    playClickSound();

    try {
        const hasSigner = await walletStore.verifyOwnership();

        // กระเป๋าฝังที่ยังไม่ปลดล็อกไม่มี signer — ต้องใส่รหัสผ่านที่หน้าต่างเชื่อมกระเป๋า
        if (!hasSigner) {
            walletStore.openConnectModal();
            return;
        }

        await bot.loadStatus();
    } finally {
        isVerifying.value = false;
    }
}

onMounted(() => {
    if (root.value) initialWidth.value = root.value.getBoundingClientRect().width;

    bot.loadCatalog();
    if (walletStore.isConnected) bot.loadStatus();
});

// เชื่อมกระเป๋าทีหลัง / สลับ address → ต้องโหลดสถานะของ wallet ใหม่
watch(() => walletStore.address, (address) => {
    if (address) bot.loadStatus();
});
</script>

<template>
    <div ref="root" class="relative h-full overflow-hidden">
        <!-- บรรยากาศพื้นหลัง: ออร่าหมุนช้าๆ + เส้นตาราง (CSS ล้วน ไม่กินแบนด์วิดท์) -->
        <div class="ai-aura" aria-hidden="true"></div>
        <div class="ai-grid" aria-hidden="true"></div>

        <div class="relative h-full flex flex-col" :class="isCompact ? 'p-2.5' : 'p-3.5'">
            <!-- ── 1) ยังไม่เชื่อมกระเป๋า ───────────────────────────────────── -->
            <template v-if="!walletStore.isConnected">
                <div class="text-center py-2 flex-1 flex flex-col justify-center">
                    <div class="ai-orb mx-auto mb-3" :class="isCompact && 'ai-orb--sm'">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                        </svg>
                    </div>
                    <p class="text-sm font-bold text-white mb-1 leading-snug">{{ t('aiTrade.headline') }}</p>
                    <p v-if="!isCompact" class="text-[11px] text-dark-400 leading-relaxed mb-3">
                        {{ t('aiTrade.subheadline') }}
                    </p>
                    <button type="button" class="w-full btn-brand py-2.5 text-sm mt-2" @click="openGate">
                        {{ t('aiTrade.connectToStart') }}
                    </button>
                </div>
            </template>

            <!-- ── 4) กระเป๋ายังไม่เซ็นยืนยัน ───────────────────────────────── -->
            <template v-else-if="bot.needsVerification.value">
                <div class="text-center py-3 flex-1 flex flex-col justify-center">
                    <p class="text-sm font-semibold text-white mb-1">{{ t('aiTrade.needVerify') }}</p>
                    <p class="text-[11px] text-dark-400 leading-relaxed mb-3">{{ t('aiTrade.needVerifyBody') }}</p>
                    <button
                        type="button"
                        class="w-full btn-brand py-2 text-xs disabled:opacity-50 flex items-center justify-center gap-2"
                        :disabled="isVerifying"
                        @click="verifyWallet"
                    >
                        <span v-if="isVerifying" class="spinner !w-3 !h-3 !border-white/30 !border-t-white"></span>
                        {{ isVerifying ? t('aiTrade.verifying') : t('aiTrade.verifyNow') }}
                    </button>
                </div>
            </template>

            <!-- ── 3) เช่าอยู่ ───────────────────────────────────────────────── -->
            <template v-else-if="bot.isActive.value">
                <div class="flex items-center gap-2 mb-2.5 flex-shrink-0">
                    <div :class="['ai-orb ai-orb--sm', activeStyle.glow]">
                        <span class="text-[10px] font-black text-white">{{ activeTier.toUpperCase().slice(0, 3) }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-white truncate">
                            {{ bot.planLabel(bot.subscription.value) }}
                        </p>
                        <p class="text-[10px] text-dark-400 truncate">
                            {{ t('aiTrade.daysLeft', { days: bot.subscription.value.days_remaining }) }}
                            · {{ t('aiTrade.botsQuota', { used: bot.quotaText.value }) }}
                        </p>
                    </div>
                    <span :class="['px-2 py-0.5 rounded-full text-[10px] font-bold ring-1 flex-shrink-0', activeStyle.ring, activeStyle.text]">
                        {{ t('aiTrade.running') }}
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-2.5 flex-shrink-0">
                    <div class="ai-stat">
                        <span class="ai-stat__label">{{ t('aiTrade.credits') }}</span>
                        <span class="ai-stat__value">{{ bot.credits.value.toLocaleString() }}</span>
                    </div>
                    <div class="ai-stat">
                        <span class="ai-stat__label">{{ t('aiTrade.tradingNow') }}</span>
                        <span class="ai-stat__value flex items-center gap-1.5">
                            <span v-if="runningCount" class="w-1.5 h-1.5 rounded-full bg-trading-green animate-pulse"></span>
                            {{ t('aiTrade.botsUnit', { count: runningCount }) }}
                        </span>
                    </div>
                </div>

                <!-- บอทของฉัน — ยืดตามพื้นที่ที่การ์ดได้รับ แล้วเลื่อนข้างในเอง -->
                <div v-if="bot.bots.value.length" class="flex-1 min-h-0 overflow-y-auto custom-scrollbar space-y-1.5 mb-2.5 pr-0.5">
                    <div v-for="item in bot.bots.value" :key="item.id" class="ai-bot-row">
                        <span :class="['w-1.5 h-1.5 rounded-full shrink-0',
                            item.status === 'running' ? 'bg-trading-green animate-pulse'
                                : item.status === 'paused' ? 'bg-amber-400' : 'bg-dark-600']"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[11px] text-white font-medium truncate">{{ item.name }}</span>
                            <span class="block text-[9px] text-dark-500 font-mono truncate">
                                {{ item.pair }} · {{ bot.strategyLabel(item) }} · {{ item.timeframe }}
                            </span>
                        </span>
                        <button
                            type="button"
                            class="shrink-0 px-2 py-1 rounded-md text-[10px] font-medium bg-white/5 text-dark-300 hover:text-white hover:bg-white/10 transition-colors disabled:opacity-40"
                            :disabled="bot.isWorking.value"
                            @click="toggleBot(item)"
                        >
                            {{ item.status === 'running' ? t('aiTrade.pause') : t('aiTrade.start') }}
                        </button>
                    </div>
                </div>

                <p v-else class="flex-1 min-h-0 flex items-center justify-center text-[11px] text-dark-400 text-center py-3 mb-2 rounded-lg bg-white/5 leading-relaxed px-2">
                    {{ t('aiTrade.noBots') }}
                </p>

                <Link :href="settingsHref" class="block w-full btn-brand py-2 text-xs text-center flex-shrink-0">
                    {{ isCompact ? t('aiTrade.configureShort') : t('aiTrade.configure') }}
                </Link>
            </template>

            <!-- ── 2) ยังไม่เช่า ─────────────────────────────────────────────── -->
            <template v-else>
                <div class="flex items-start gap-2.5 mb-2.5 flex-shrink-0">
                    <div class="ai-orb shrink-0" :class="isCompact && 'ai-orb--sm'">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-white leading-tight">{{ t('aiTrade.cloudBot') }}</p>
                        <p class="text-[11px] text-dark-400 leading-relaxed mt-0.5">
                            {{ t('aiTrade.pitch', { count: bot.strategies.value.length || 8 }) }}
                        </p>
                    </div>
                </div>

                <div v-if="!isCompact" class="grid grid-cols-3 gap-1.5 mb-2.5 flex-shrink-0">
                    <div class="ai-chip"><span class="ai-chip__top">24/7</span><span class="ai-chip__sub">{{ t('aiTrade.chip247') }}</span></div>
                    <div class="ai-chip"><span class="ai-chip__top">SL/TP</span><span class="ai-chip__sub">{{ t('aiTrade.chipRisk') }}</span></div>
                    <div class="ai-chip"><span class="ai-chip__top">{{ t('aiTrade.chipWebApp') }}</span><span class="ai-chip__sub">{{ t('aiTrade.chipSync') }}</span></div>
                </div>

                <div class="flex items-center justify-between gap-2 mb-2 px-2.5 py-1.5 rounded-lg bg-white/5 flex-shrink-0">
                    <span class="text-[10px] text-dark-400 truncate">{{ t('aiTrade.credits') }}</span>
                    <span class="text-xs font-mono font-bold text-white">{{ bot.credits.value.toLocaleString() }}</span>
                </div>

                <div class="mt-auto flex-shrink-0">
                    <button type="button" class="w-full btn-brand py-2.5 text-sm mb-1.5" @click="openGate">
                        {{ t('aiTrade.activate') }}
                    </button>
                    <Link :href="settingsHref" class="block text-center text-[11px] text-primary-400 hover:text-primary-300 transition-colors">
                        {{ t('aiTrade.seeAllPlans') }} →
                    </Link>
                </div>
            </template>
        </div>

        <!-- ── ป๊อปอัพเตือน/เช่า ───────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showGate" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/85 backdrop-blur-sm" @click="closeGate"></div>

                    <div
                        class="relative w-full max-w-lg max-h-[88vh] overflow-y-auto custom-scrollbar rounded-2xl border border-white/10 bg-dark-900/95 shadow-glass-lg animate-scale-in"
                        role="dialog"
                        aria-modal="true"
                        :aria-label="t('aiTrade.gateTitle')"
                    >
                        <div class="sticky top-0 z-10 flex items-center gap-2 px-5 py-3.5 border-b border-white/5 bg-dark-900/95">
                            <span class="ai-orb ai-orb--sm">
                                <svg class="w-3.5 h-3.5 text-white" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 2l2.09 6.26L20.5 8.5l-5 3.9 1.9 6.35L12 15.9l-5.4 2.85 1.9-6.35-5-3.9 6.41-.24z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-sm font-bold text-white">{{ t('aiTrade.gateTitle') }}</h3>
                                <p class="text-[10px] text-dark-400">{{ t('aiTrade.gateSub') }}</p>
                            </div>
                            <button type="button" class="ml-auto p-1.5 rounded-lg text-dark-400 hover:text-white hover:bg-white/5" :aria-label="t('aiTrade.close')" @click="closeGate">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="p-5 space-y-4">
                            <!-- คำเตือนหลัก: ยังไม่ได้เช่า -->
                            <div class="flex gap-2.5 p-3 rounded-xl bg-amber-500/10 border border-amber-500/25">
                                <svg class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                </svg>
                                <p class="text-[11px] text-amber-200 leading-relaxed">{{ t('aiTrade.gateWarn') }}</p>
                            </div>

                            <!-- เครดิต + ยอดที่ต้องใช้ -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white/5">
                                <div>
                                    <p class="text-[10px] text-dark-400">{{ t('aiTrade.credits') }}</p>
                                    <p class="text-lg font-mono font-bold text-white leading-tight">
                                        {{ bot.credits.value.toLocaleString() }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] text-dark-400">{{ t('aiTrade.required') }}</p>
                                    <p :class="['text-lg font-mono font-bold leading-tight', affordable ? 'text-trading-green' : 'text-trading-red']">
                                        {{ cost.toLocaleString() }}
                                    </p>
                                </div>
                            </div>

                            <div>
                                <p class="text-[11px] text-dark-400 mb-1.5">{{ t('aiTrade.rentDays') }}</p>
                                <div class="flex gap-1.5">
                                    <button
                                        v-for="d in bot.rentalDays.value"
                                        :key="d"
                                        type="button"
                                        :class="['flex-1 py-1.5 rounded-lg text-xs font-medium transition-all',
                                            selectedDays === d ? 'bg-primary-500/20 text-primary-300 ring-1 ring-primary-500/40' : 'bg-dark-800 text-dark-400 hover:text-white']"
                                        @click="selectedDays = d"
                                    >
                                        {{ t('aiTrade.days', { n: d }) }}
                                    </button>
                                </div>
                            </div>

                            <!--
                                คำเตือนโหมดทดลอง — ต้องอยู่ตรงจุดที่ผู้ใช้กำลังจะจ่าย

                                หน้า /ai-trade มีป้ายนี้อยู่แล้ว แต่การ์ดนี้เช่าได้ครบวงจรเหมือนกัน
                                คนที่เช่าจากหน้าเทรดจึงจ่ายเงินโดยไม่เคยเห็นว่ายังไม่มีการส่งคำสั่งจริง
                            -->
                            <p
                                v-if="!bot.liveEnabled.value"
                                class="text-[10px] leading-relaxed px-3 py-2 rounded-lg bg-amber-500/10 text-amber-200/90 ring-1 ring-amber-500/20"
                            >
                                {{ t('aiTrade.demoOnlyNotice') }}
                            </p>
                            <p
                                v-if="!bot.topupEnabled.value"
                                class="text-[10px] leading-relaxed px-3 py-2 rounded-lg bg-dark-800/60 text-dark-300 ring-1 ring-white/10"
                            >
                                {{ t('aiTrade.topupClosed') }}
                            </p>

                            <!-- แพลน -->
                            <div class="space-y-2">
                                <p class="text-[11px] text-dark-400">{{ t('aiTrade.choosePlan') }}</p>
                                <button
                                    v-for="plan in bot.plans.value"
                                    :key="plan.code"
                                    type="button"
                                    :class="['w-full text-left p-3 rounded-xl border transition-all',
                                        selectedPlan === plan.code
                                            ? 'border-primary-500/50 bg-primary-500/10'
                                            : 'border-white/10 bg-white/5 hover:border-white/20']"
                                    @click="selectedPlan = plan.code"
                                >
                                    <span class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-white">{{ bot.planLabel(plan) }}</span>
                                        <span v-if="plan.badge" :class="['px-1.5 py-0.5 rounded text-[9px] font-bold',
                                            plan.tier === 'vip' ? 'bg-warm-500/20 text-warm-300' : 'bg-accent-500/20 text-accent-300']">
                                            {{ plan.badge }}
                                        </span>
                                        <span class="ml-auto text-xs font-mono text-primary-300">
                                            {{ plan.credits_per_day }} {{ t('aiTrade.creditsPerDay') }}
                                        </span>
                                    </span>
                                    <span class="block text-[11px] text-dark-400 mt-1 leading-relaxed">
                                        {{ bot.planDescription(plan) }}
                                    </span>
                                    <span class="block text-[10px] text-dark-500 mt-1 font-mono">
                                        {{ t('aiTrade.maxBots', { n: plan.max_bots }) }} ·
                                        {{ plan.max_capital_usd
                                            ? t('aiTrade.capPerTrade', { n: plan.max_capital_usd.toLocaleString() })
                                            : t('aiTrade.capUnlimited') }}
                                    </span>
                                </button>
                            </div>

                            <!-- เติมเครดิต (โผล่เมื่อเครดิตไม่พอ) -->
                            <div v-if="!affordable && selectedPlan" class="p-3 rounded-xl border border-trading-red/25 bg-trading-red/5 space-y-2">
                                <p class="text-[11px] text-trading-red font-medium">
                                    {{ t('aiTrade.notEnough', { n: shortfall.toLocaleString() }) }}
                                </p>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <button
                                        v-for="pack in bot.packs.value"
                                        :key="pack.code"
                                        type="button"
                                        :class="['p-2 rounded-lg border text-left transition-all',
                                            suggestedPack && suggestedPack.code === pack.code
                                                ? 'border-primary-500/50 bg-primary-500/10'
                                                : 'border-white/10 bg-white/5 hover:border-white/20']"
                                        :disabled="bot.isWorking.value || !bot.topupEnabled.value"
                                        :title="bot.topupEnabled.value ? '' : t('aiTrade.topupClosed')"
                                        @click="requestPack(pack.code)"
                                    >
                                        <span class="block text-xs font-bold text-white font-mono">
                                            {{ pack.credits.toLocaleString() }}
                                            <span v-if="pack.bonus" class="text-[9px] text-trading-green">+{{ pack.bonus }}</span>
                                        </span>
                                        <!--
                                            เซิร์ฟเวอร์ถอด price_usd ออกไปแล้ว เหลือแต่ price_tpix
                                            (เจ้าของสั่งว่าเครดิตขายด้วย TPIX เท่านั้น) การ์ดนี้ยังอ่านคีย์เก่า
                                            จึงขึ้นเป็น "$" เปล่าๆ — ผู้ใช้เห็นราคาว่าง แล้วไม่กล้ากด
                                        -->
                                        <span class="block text-[10px] text-dark-400 font-mono">
                                            {{ Number(pack.price_tpix || 0).toLocaleString() }} TPIX
                                        </span>
                                    </button>
                                </div>
                                <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.packHint') }}</p>
                            </div>

                            <!-- ผลลัพธ์ -->
                            <p
                                v-if="gateNotice"
                                :class="['text-[11px] leading-relaxed px-3 py-2 rounded-lg',
                                    gateNotice.type === 'success' ? 'bg-trading-green/10 text-trading-green' : 'bg-trading-red/10 text-trading-red']"
                            >
                                {{ gateNotice.text }}
                            </p>

                            <div class="flex gap-2 pt-1">
                                <Link :href="settingsHref" class="flex-1 btn-secondary py-2.5 text-xs justify-center">
                                    {{ t('aiTrade.configureShort') }}
                                </Link>
                                <button
                                    type="button"
                                    class="flex-1 btn-brand py-2.5 text-xs justify-center disabled:opacity-50"
                                    :disabled="bot.isWorking.value || !selectedPlan"
                                    @click="confirmRent"
                                >
                                    <span v-if="bot.isWorking.value" class="spinner !w-3.5 !h-3.5 !border-white/30 !border-t-white"></span>
                                    {{ affordable ? t('aiTrade.rentFor', { days: selectedDays }) : t('aiTrade.topupFirst') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
/* ออร่าเรืองแสงหมุนช้าๆ หลังการ์ด — ใช้ conic-gradient จึงไม่ต้องโหลดรูป */
.ai-aura {
    position: absolute;
    inset: -60% -30%;
    background: conic-gradient(
        from 0deg,
        rgba(139, 92, 246, 0.22),
        rgba(6, 182, 212, 0.22),
        rgba(249, 115, 22, 0.16),
        rgba(139, 92, 246, 0.22)
    );
    filter: blur(42px);
    animation: aiSpin 18s linear infinite;
    pointer-events: none;
}

.ai-grid {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
    background-size: 22px 22px;
    mask-image: radial-gradient(ellipse at 50% 0%, #000 10%, transparent 75%);
    -webkit-mask-image: radial-gradient(ellipse at 50% 0%, #000 10%, transparent 75%);
    pointer-events: none;
}

@keyframes aiSpin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ai-aura {
        animation: none;
    }
}

.ai-orb {
    @apply w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0;
    background: linear-gradient(135deg, #8b5cf6 0%, #06b6d4 55%, #f97316 100%);
    box-shadow: 0 0 22px rgba(6, 182, 212, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.25);
}

.ai-orb--sm {
    @apply w-8 h-8 rounded-xl;
}

.ai-stat {
    @apply flex flex-col gap-0.5 px-2.5 py-2 rounded-xl bg-white/5 border border-white/5 min-w-0;
}

.ai-stat__label {
    @apply text-[9px] text-dark-400 truncate;
}

.ai-stat__value {
    @apply text-xs font-mono font-bold text-white truncate;
}

.ai-chip {
    @apply flex flex-col items-center justify-center py-1.5 rounded-lg bg-white/5 border border-white/5 min-w-0;
}

.ai-chip__top {
    @apply text-[10px] font-bold text-white leading-none truncate max-w-full px-1;
}

.ai-chip__sub {
    @apply text-[9px] text-dark-500 mt-0.5 truncate max-w-full px-1;
}

.ai-bot-row {
    @apply flex items-center gap-2 px-2 py-1.5 rounded-lg bg-white/5 border border-white/5;
}
</style>
