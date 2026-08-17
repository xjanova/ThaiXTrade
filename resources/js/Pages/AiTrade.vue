<script setup>
/**
 * TPIX TRADE — AI Trade (Cloud Bot) console
 * หน้าตั้งค่าบอทเทรดคลาวด์แบบละเอียด: เช่าแพลน · เครดิตการทำงาน · กลยุทธ์ · ความเสี่ยง
 *
 * ระบบเดียวกับการ์ด AI TRADE ในหน้าเทรดและในแอพ TPIX (state มาจาก /api/v1/ai-bot/*)
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageArt from '@/Components/PageArt.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import AiDemoPanel from '@/Components/Trading/AiDemoPanel.vue';
import AiRiskPanel from '@/Components/Trading/AiRiskPanel.vue';
import { useAiBot } from '@/Composables/useAiBot';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { playClickSound, playErrorSound, playNotificationSound } from '@/Composables/useSounds';

const walletStore = useWalletStore();
const bot = useAiBot();
const { t, locale } = useTranslation();

const notice = ref(null); // { type: 'success' | 'error', text }
const selectedDays = ref(7);
const pairs = ref([]);
const editingId = ref(null);
const showBuilder = ref(false);

// ด่านความเสี่ยงของคู่ที่กำลังดูอยู่ (โหลดจาก /ai-bot/risk)
const risk = ref(null);
const riskPair = ref('BTC/USDT');

/** ฟอร์มสร้าง/แก้บอท — params/risk เติมจาก schema ของกลยุทธ์ที่เลือก */
const form = ref({
    name: '',
    pair: 'BTC/USDT',
    strategy: 'grid',
    timeframe: '1h',
    params: {},
    risk: {},
});

const riskFields = [
    { key: 'max_position_usd', labelKey: 'aiTrade.maxPosition' },
    { key: 'stop_loss_pct', labelKey: 'aiTrade.stopLoss' },
    { key: 'take_profit_pct', labelKey: 'aiTrade.takeProfit' },
    { key: 'max_daily_loss_usd', labelKey: 'aiTrade.maxDailyLoss' },
];

const riskTone = {
    low: 'text-trading-green bg-trading-green/10 ring-trading-green/25',
    medium: 'text-amber-300 bg-amber-500/10 ring-amber-500/25',
    high: 'text-trading-red bg-trading-red/10 ring-trading-red/25',
};

// เก็บเป็นคีย์คำแปล ไม่ใช่ข้อความ — สลับภาษาแล้วต้องเปลี่ยนตาม
const riskLabel = { low: 'aiTrade.riskLow', medium: 'aiTrade.riskMedium', high: 'aiTrade.riskHigh' };
const tierLabel = { basic: 'aiTrade.tierBasic', pro: 'aiTrade.tierPro', vip: 'aiTrade.tierVip' };

const unlocked = computed(() => bot.status.value?.unlocked_strategies ?? []);
const selectedStrategy = computed(() => bot.strategyByCode(form.value.strategy));
const limits = computed(() => bot.catalog.value?.limits ?? {});
const timeframes = computed(() => selectedStrategy.value?.timeframes ?? bot.catalog.value?.timeframes ?? ['1h']);

const quotaFull = computed(() => {
    const q = bot.status.value?.quota;
    return !!q && !editingId.value && q.used_bots >= q.max_bots;
});

/** ป้ายพารามิเตอร์ของกลยุทธ์ — config ส่ง label (ไทย) กับ label_en มาให้ทั้งคู่ */
function paramLabel(spec) {
    return (locale.value === 'th' ? spec.label : spec.label_en || spec.label) || spec.key;
}

function isUnlocked(code) {
    return unlocked.value.includes(code);
}

function flash(type, text) {
    notice.value = { type, text };
    if (type === 'success') playNotificationSound();
    else playErrorSound();
    setTimeout(() => { notice.value = null; }, 5000);
}

// ── ข้อมูลตั้งต้น ───────────────────────────────────────────────────────────
async function loadPairs() {
    try {
        const { data } = await axios.get('/api/v1/market/pairs');
        if (data?.success) {
            pairs.value = data.data.map(p => ({
                symbol: `${p.base_asset}/${p.quote_asset}`,
                base: p.base_asset,
                logo: p.base_logo,
            }));
        }
    } catch {
        pairs.value = [{ symbol: 'BTC/USDT', base: 'BTC', logo: null }];
    }
}

/** เติมค่าเริ่มต้นของพารามิเตอร์จาก schema ของกลยุทธ์ (ไม่ทับค่าที่ผู้ใช้ตั้งไว้แล้ว) */
function syncParamDefaults() {
    const strategy = selectedStrategy.value;
    if (!strategy) return;

    const next = {};
    (strategy.params || []).forEach((spec) => {
        next[spec.key] = form.value.params[spec.key] ?? spec.default;
    });
    form.value.params = next;

    if (!strategy.timeframes.includes(form.value.timeframe)) {
        form.value.timeframe = strategy.timeframes[0];
    }
}

function syncRiskDefaults() {
    const l = limits.value;
    riskFields.forEach(({ key }) => {
        if (form.value.risk[key] === undefined || form.value.risk[key] === null) {
            form.value.risk[key] = l[key]?.default ?? 0;
        }
    });
}

watch(() => form.value.strategy, syncParamDefaults);
watch(limits, syncRiskDefaults);

// ── การเช่า ─────────────────────────────────────────────────────────────────
async function rent(planCode) {
    playClickSound();

    if (!walletStore.isConnected) {
        walletStore.openConnectModal();
        return;
    }

    if (!bot.canAfford(planCode, selectedDays.value)) {
        const need = bot.costOf(planCode, selectedDays.value) - bot.credits.value;
        flash('error', t('aiTrade.notEnoughLong', { n: need.toLocaleString() }));
        return;
    }

    const result = await bot.subscribe(planCode, selectedDays.value);
    if (result.ok) flash('success', t('aiTrade.planActivated'));
    else flash('error', result.error.message);
}

async function cancelPlan() {
    if (!window.confirm(t('aiTrade.cancelConfirm'))) return;

    const result = await bot.cancel();
    if (result.ok) flash('success', t('aiTrade.cancelOk'));
    else flash('error', result.error.message);
}

async function topup(code) {
    playClickSound();

    if (!walletStore.isConnected) {
        walletStore.openConnectModal();
        return;
    }

    const result = await bot.requestTopup(code);
    if (result.ok) flash('success', t('aiTrade.topupOk'));
    else flash('error', result.error.message);
}

async function claimWelcome() {
    const result = await bot.claimWelcome();
    if (result.ok) flash('success', t('aiTrade.welcomeOk'));
    else flash('error', result.error.message);
}

// ── บอท ─────────────────────────────────────────────────────────────────────
function openBuilder(existing = null) {
    playClickSound();

    if (!bot.isActive.value) {
        flash('error', t('aiTrade.needPlan'));
        return;
    }

    if (existing) {
        editingId.value = existing.id;
        form.value = {
            name: existing.name,
            pair: existing.pair,
            strategy: existing.strategy,
            timeframe: existing.timeframe,
            params: { ...existing.params },
            risk: { ...existing.risk },
        };
    } else {
        editingId.value = null;
        const firstUnlocked = bot.strategies.value.find(s => isUnlocked(s.code))?.code || 'grid';
        form.value = {
            name: t('aiTrade.defaultBotName', { n: bot.bots.value.length + 1 }),
            pair: form.value.pair,
            strategy: firstUnlocked,
            timeframe: '1h',
            params: {},
            risk: {},
        };
        syncParamDefaults();
        syncRiskDefaults();
    }

    showBuilder.value = true;
}

async function saveBot() {
    if (!form.value.name.trim()) {
        flash('error', t('aiTrade.nameRequired'));
        return;
    }

    const payload = { ...form.value, name: form.value.name.trim() };
    const result = editingId.value
        ? await bot.updateBot(editingId.value, payload)
        : await bot.createBot(payload);

    if (result.ok) {
        flash('success', editingId.value ? t('aiTrade.savedOk') : t('aiTrade.createdOk'));
        showBuilder.value = false;
        editingId.value = null;
    } else {
        flash('error', result.error.message);
    }
}

async function setState(item, action) {
    playClickSound();
    const result = await bot.setBotState(item.id, action);
    if (!result.ok) flash('error', result.error.message);
}

/** สลับบอทระหว่างโหมดทดลองกับโหมดจริง */
async function toggleMode(item) {
    playClickSound();

    const next = item.mode === 'demo' ? 'live' : 'demo';
    const result = await bot.setBotMode(item.id, next);

    if (!result.ok) {
        flash('error', result.error.message);
        return;
    }

    flash('success', t('aiTrade.modeSwitched'));
    // ไม้ของโหมดใหม่คนละชุดกับโหมดเดิม — ต้องโหลดพอร์ตทดลองใหม่
    bot.loadDemo();
}

async function resetDemo() {
    playClickSound();

    const result = await bot.resetDemo();

    if (!result.ok) {
        flash('error', result.error.message);
        return;
    }

    flash('success', t('aiTrade.demoResetOk'));
}

/** ความเสี่ยง + ข่าวของคู่ที่กำลังดู */
async function loadRisk(pair) {
    if (!pair) return;
    riskPair.value = pair;
    risk.value = await bot.loadRisk(pair);
}

async function removeBot(item) {
    if (!window.confirm(t('aiTrade.deleteConfirm', { name: item.name }))) return;

    const result = await bot.deleteBot(item.id);
    if (result.ok) flash('success', t('aiTrade.deletedOk'));
    else flash('error', result.error.message);
}

onMounted(() => {
    bot.loadCatalog().then(syncRiskDefaults);
    loadPairs();

    if (walletStore.isConnected) {
        bot.loadStatus();
        bot.loadDemo();
    }

    // มาจากการ์ดในหน้าเทรด → ตั้งคู่เทรดให้ตรงกับที่ผู้ใช้ดูอยู่
    const pairParam = new URLSearchParams(window.location.search).get('pair');
    if (pairParam && /^[A-Za-z0-9]{2,15}\/[A-Za-z0-9]{2,15}$/.test(pairParam)) {
        form.value.pair = pairParam.toUpperCase();
    }

    loadRisk(form.value.pair || 'BTC/USDT');
});

// เปลี่ยนคู่ในฟอร์ม → ดูความเสี่ยงของคู่นั้นทันที
watch(() => form.value.pair, (pair) => loadRisk(pair));

watch(() => walletStore.address, (address) => {
    if (!address) return;
    bot.loadStatus();
    bot.loadDemo();
});

/*
 * แพลนฟรี = หน้าเว็บนี้เป็นคนเดินบอท
 *
 * เริ่มลูปเมื่อมีบอทฟรีที่กำลังทำงาน และหยุดทันทีที่ออกจากหน้านี้
 * onUnmounted สำคัญมาก — ถ้าลืม ลูปจะเดินต่อหลังผู้ใช้ไปหน้าอื่นแล้ว
 * กลายเป็นบอทเดินอยู่เบื้องหลังโดยที่ผู้ใช้ไม่เห็นและไม่ได้ตั้งใจ
 */
watch(
    () => bot.browserBots.value.length,
    (count) => {
        if (count > 0) bot.startBrowserLoop();
        else bot.stopBrowserLoop();
    },
    { immediate: true }
);

onUnmounted(() => bot.stopBrowserLoop());
</script>

<template>
    <Head :title="`${t('aiTrade.title')} — ${t('aiTrade.cloudBot')}`" />

    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-5">
            <!-- Hero — ภาพเจนของหน้านี้เอง + ชั้นออร่า/โครงข่ายด้วย CSS+SVG ทับอีกที -->
            <section class="relative overflow-hidden rounded-3xl border border-white/10 bg-dark-900/50 p-6 sm:p-8">
                <PageArt art="hero-aitrade" :opacity="34" fade="edges" rounded="rounded-3xl" loading="eager" />
                <div class="ai-hero-aura" aria-hidden="true"></div>
                <svg class="ai-hero-net" viewBox="0 0 600 200" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="aiNetStroke" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#8b5cf6" stop-opacity="0.9" />
                            <stop offset="50%" stop-color="#06b6d4" stop-opacity="0.9" />
                            <stop offset="100%" stop-color="#f97316" stop-opacity="0.7" />
                        </linearGradient>
                    </defs>
                    <g stroke="url(#aiNetStroke)" stroke-width="0.7" fill="none">
                        <path d="M20 160 L110 96 L210 128 L300 54 L400 92 L500 36 L580 70" />
                        <path d="M20 182 L110 150 L210 168 L300 120 L400 146 L500 104 L580 128" />
                        <path d="M110 96 L110 150 M300 54 L300 120 M500 36 L500 104" stroke-opacity="0.35" />
                    </g>
                    <g fill="url(#aiNetStroke)">
                        <circle cx="110" cy="96" r="3.2" /><circle cx="300" cy="54" r="3.6" />
                        <circle cx="500" cy="36" r="3.2" /><circle cx="210" cy="128" r="2.4" />
                        <circle cx="400" cy="92" r="2.4" />
                    </g>
                </svg>
                <div class="relative">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand-gradient text-white">{{ t('aiTrade.badge') }}</span>
                        <Link href="/trade" class="text-[11px] text-primary-400 hover:text-primary-300">← {{ t('aiTrade.backToBoard') }}</Link>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-black text-white leading-tight mb-2">{{ t('aiTrade.title') }}</h1>
                    <p class="text-sm text-dark-300 max-w-2xl leading-relaxed">{{ t('aiTrade.intro') }}</p>
                </div>
            </section>

            <!-- แจ้งผล -->
            <p
                v-if="notice"
                :class="['px-4 py-2.5 rounded-xl text-sm',
                    notice.type === 'success' ? 'bg-trading-green/10 text-trading-green' : 'bg-trading-red/10 text-trading-red']"
            >
                {{ notice.text }}
            </p>

            <!-- ยังไม่เชื่อมกระเป๋า -->
            <!-- ยังไม่เชื่อมกระเป๋า — ยังดูราคาและกลยุทธ์ได้ครบ แค่ยังสร้างบอทไม่ได้ -->
            <section v-if="!walletStore.isConnected" class="glass-dark rounded-2xl p-5 flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-white font-semibold text-sm">{{ t('aiTrade.connectPrompt') }}</p>
                    <p class="text-dark-400 text-xs mt-0.5">{{ t('aiTrade.connectHint') }}</p>
                </div>
                <button type="button" class="btn-brand px-6 py-2 text-sm" @click="walletStore.openConnectModal()">
                    {{ t('aiTrade.connectWallet') }}
                </button>
            </section>

            <!-- สถานะของ wallet — เห็นเฉพาะตอนเชื่อมแล้ว -->
            <template v-if="walletStore.isConnected">
                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="glass-dark rounded-2xl p-4">
                        <p class="text-[11px] text-dark-400 mb-1">{{ t('aiTrade.creditsFull') }}</p>
                        <p class="text-2xl font-black font-mono text-white leading-none">
                            {{ bot.credits.value.toLocaleString() }}
                        </p>
                        <button
                            v-if="bot.credits.value === 0"
                            type="button"
                            class="mt-2 text-[11px] text-primary-400 hover:text-primary-300"
                            @click="claimWelcome"
                        >
                            {{ t('aiTrade.welcomeFree') }} →
                        </button>
                    </div>

                    <div class="glass-dark rounded-2xl p-4">
                        <p class="text-[11px] text-dark-400 mb-1">{{ t('aiTrade.currentPlan') }}</p>
                        <p class="text-lg font-bold text-white leading-tight">
                            {{ bot.isActive.value ? bot.planLabel(bot.subscription.value) : t('aiTrade.notRented') }}
                        </p>
                        <p v-if="bot.isActive.value" class="text-[11px] text-dark-400 mt-0.5">
                            {{ t('aiTrade.daysLeft', { days: bot.subscription.value.days_remaining }) }}
                            <button type="button" class="ml-1 text-trading-red hover:underline" @click="cancelPlan">{{ t('aiTrade.cancelPlan') }}</button>
                        </p>
                    </div>

                    <div class="glass-dark rounded-2xl p-4">
                        <p class="text-[11px] text-dark-400 mb-1">{{ t('aiTrade.botsUsed') }}</p>
                        <p class="text-2xl font-black font-mono text-white leading-none">{{ bot.quotaText.value }}</p>
                        <p class="text-[11px] text-dark-400 mt-0.5">
                            {{ t('aiTrade.tradingNow') }} · {{ t('aiTrade.botsUnit', { count: bot.runningBots.value.length }) }}
                        </p>
                    </div>
                </section>
            </template>

            <!-- แพลน + เติมเครดิต — เปิดให้ดูราคาได้ก่อนเชื่อมกระเป๋า -->
            <section id="plans">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h2 class="text-lg font-bold text-white">{{ t('aiTrade.choosePlanTitle') }}</h2>
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] text-dark-400">{{ t('aiTrade.dayCount') }}</span>
                        <button
                            v-for="d in bot.rentalDays.value"
                            :key="d"
                            type="button"
                            :class="['px-2.5 py-1 rounded-lg text-xs font-medium transition-all',
                                selectedDays === d ? 'bg-primary-500/20 text-primary-300 ring-1 ring-primary-500/40' : 'bg-dark-800 text-dark-400 hover:text-white']"
                            @click="selectedDays = d"
                        >
                            {{ d }}
                        </button>
                    </div>
                </div>

                <!-- เทียบให้เห็นชัดก่อนดูราคา: ต่างกันที่ "บอทเดินตอนไหน" ไม่ใช่จำนวนบอท
                     ผู้ใช้ที่เข้าใจผิดตรงนี้จะเช่าไปแล้วรู้สึกว่าไม่คุ้ม -->
                <div class="grid gap-3 sm:grid-cols-2 mb-4">
                    <div class="rounded-2xl border border-amber-500/25 bg-amber-500/[0.04] p-4">
                        <p class="text-sm font-bold text-amber-300 flex items-center gap-1.5">
                            🖥️ {{ t('aiTrade.compareFreeTitle') }}
                        </p>
                        <p class="text-[11px] text-dark-300 leading-relaxed mt-1.5">
                            {{ t('aiTrade.compareFreeBody') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-primary-500/25 bg-primary-500/[0.04] p-4">
                        <p class="text-sm font-bold text-primary-300 flex items-center gap-1.5">
                            ☁️ {{ t('aiTrade.compareCloudTitle') }}
                        </p>
                        <p class="text-[11px] text-dark-300 leading-relaxed mt-1.5">
                            {{ t('aiTrade.compareCloudBody') }}
                        </p>
                    </div>
                </div>

                <!-- ตอนนี้เปิดเฉพาะโหมดทดลอง — ต้องบอกก่อนจ่ายเงิน ไม่ใช่ให้มารู้ทีหลัง -->
                <p class="rounded-xl border border-white/10 bg-dark-900/50 px-3 py-2 text-[11px] text-dark-300 mb-4 flex gap-2">
                    <span class="text-primary-400 shrink-0">ℹ️</span>
                    <span>{{ t('aiTrade.demoOnlyNotice') }}</span>
                </p>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="plan in bot.plans.value"
                        :key="plan.code"
                        :class="['relative rounded-2xl border p-4 flex flex-col transition-all',
                            bot.subscription.value?.plan_code === plan.code
                                ? 'border-primary-500/50 bg-primary-500/5'
                                : plan.tier === 'vip'
                                    ? 'border-warm-500/30 bg-warm-500/[0.03] hover:border-warm-500/50'
                                    : 'border-white/10 bg-dark-900/50 hover:border-white/20']"
                    >
                        <span
                            v-if="plan.badge"
                            :class="['absolute -top-2 right-4 px-2 py-0.5 rounded-full text-[10px] font-bold',
                                plan.tier === 'vip' ? 'bg-warm-500 text-dark-950' : 'bg-accent-500 text-white']"
                        >
                            {{ plan.badge }}
                        </span>

                        <h3 class="text-base font-bold text-white">{{ bot.planLabel(plan) }}</h3>

                        <!-- ป้ายบอก "บอทเดินที่ไหน" — เป็นความต่างหลักระหว่างฟรีกับเสียเงิน
                             จึงต้องอยู่บนสุด เห็นก่อนราคา ไม่ใช่ซ่อนอยู่ในรายการคุณสมบัติ -->
                        <span
                            :class="['inline-flex items-center gap-1 self-start mt-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold ring-1',
                                plan.execution === 'cloud'
                                    ? 'bg-primary-500/10 text-primary-300 ring-primary-500/25'
                                    : 'bg-amber-500/10 text-amber-300 ring-amber-500/25']"
                        >
                            {{ plan.execution === 'cloud' ? '☁️' : '🖥️' }}
                            {{ plan.execution === 'cloud' ? t('aiTrade.runsInCloud') : t('aiTrade.runsInBrowser') }}
                        </span>

                        <p class="text-[11px] text-dark-400 leading-relaxed mt-2 mb-3 min-h-[32px]">
                            {{ bot.planDescription(plan) }}
                        </p>

                        <p v-if="plan.price_tpix_per_day > 0" class="text-2xl font-black font-mono text-white leading-none">
                            {{ Number(plan.price_tpix_per_day).toLocaleString() }}
                            <span class="text-[11px] font-normal text-dark-400">{{ t('aiTrade.tpixPerDay') }}</span>
                        </p>
                        <p v-else class="text-2xl font-black font-mono text-trading-green leading-none">
                            {{ t('aiTrade.freeForever') }}
                        </p>

                        <p v-if="plan.price_tpix_per_day > 0" class="text-[11px] text-dark-500 mt-1 mb-3 font-mono">
                            {{ t('aiTrade.totalCostTpix', {
                                days: selectedDays,
                                tpix: (Number(plan.price_tpix_per_day) * selectedDays).toLocaleString(),
                            }) }}
                        </p>
                        <p v-else class="text-[11px] text-dark-500 mt-1 mb-3">{{ t('aiTrade.noPaymentNeeded') }}</p>

                        <ul class="space-y-1.5 mb-4 flex-1">
                            <li v-for="feature in bot.planFeatures(plan)" :key="feature" class="flex gap-1.5 text-[11px] text-dark-300 leading-relaxed">
                                <span class="text-primary-400 shrink-0">✓</span>{{ feature }}
                            </li>
                        </ul>

                        <button
                            type="button"
                            :class="['w-full py-2 rounded-xl text-xs font-bold transition-all disabled:opacity-50',
                                bot.subscription.value?.plan_code === plan.code ? 'bg-white/10 text-white' : 'btn-brand']"
                            :disabled="bot.isWorking.value"
                            @click="rent(plan.code)"
                        >
                            {{ bot.subscription.value?.plan_code === plan.code
                                ? (plan.price_tpix_per_day > 0 ? t('aiTrade.renewFor', { days: selectedDays }) : t('aiTrade.currentPlan'))
                                : (plan.price_tpix_per_day > 0 ? t('aiTrade.rentFor', { days: selectedDays }) : t('aiTrade.startFree')) }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- เติมเครดิต -->
            <section>
                <h2 class="text-lg font-bold text-white mb-1">{{ t('aiTrade.topupTitle') }}</h2>
                <p class="text-[11px] text-dark-400 mb-3">{{ t('aiTrade.topupSub') }}</p>
                <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
                    <button
                        v-for="pack in bot.packs.value"
                        :key="pack.code"
                        type="button"
                        class="rounded-2xl border border-white/10 bg-dark-900/50 p-4 text-left hover:border-primary-500/40 transition-all disabled:opacity-50"
                        :disabled="bot.isWorking.value"
                        @click="topup(pack.code)"
                    >
                        <p class="text-xl font-black font-mono text-white leading-none">
                            {{ pack.credits.toLocaleString() }}
                        </p>
                        <p v-if="pack.bonus" class="text-[11px] text-trading-green font-mono mt-0.5">
                            + {{ t('aiTrade.bonus', { n: pack.bonus.toLocaleString() }) }}
                        </p>
                        <p class="text-sm text-dark-300 mt-2 font-mono">
                            {{ Number(pack.price_tpix).toLocaleString() }}
                            <span class="text-[10px] text-dark-500">TPIX</span>
                        </p>
                    </button>
                </div>
            </section>

            <!-- บอทฟรีเดินอยู่ในแท็บนี้ — ต้องบอกให้ชัดว่าปิดแล้วหยุด ไม่ใช่ปล่อยให้เข้าใจผิด -->
            <section
                v-if="bot.browserLoopActive.value && bot.browserBots.value.length"
                class="rounded-2xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 flex flex-wrap items-center gap-3"
            >
                <span class="relative flex h-2.5 w-2.5 shrink-0">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-60"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-400"></span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-amber-300">
                        {{ t('aiTrade.browserBotRunning') }}
                        <span class="text-dark-400 font-normal">· {{ bot.browserBots.value.length }} {{ t('aiTrade.botsUnit') }}</span>
                    </p>
                    <p class="text-[11px] text-dark-300 mt-0.5">
                        {{ t('aiTrade.browserBotWarning') }}
                        <span v-if="bot.lastBrowserTick.value" class="text-dark-500 font-mono ml-1">
                            · {{ t('aiTrade.lastTickAt') }} {{ new Date(bot.lastBrowserTick.value).toLocaleTimeString() }}
                        </span>
                    </p>
                </div>

                <a href="#plans" class="text-[11px] font-medium text-primary-300 hover:text-primary-200 whitespace-nowrap">
                    {{ t('aiTrade.upgradeForCloud') }} →
                </a>
            </section>

            <!-- โหมดทดลอง + ด่านความเสี่ยง — ต้องเชื่อมกระเป๋าก่อนถึงจะมีพอร์ตทดลอง -->
            <template v-if="walletStore.isConnected">
                <section class="grid gap-4 lg:grid-cols-[1.6fr_1fr] items-start">
                    <AiDemoPanel
                        :demo="bot.demo.value"
                        :loading="bot.isLoadingDemo.value"
                        :resets-left="bot.demoResetsLeft.value"
                        :working="bot.isWorking.value"
                        @reset="resetDemo"
                    />
                    <AiRiskPanel :risk="risk" :pair="riskPair" />
                </section>
            </template>

            <!-- ด่านความเสี่ยงยังดูได้แม้ยังไม่เชื่อมกระเป๋า (เป็นข้อมูลตลาด) -->
            <section v-else>
                <AiRiskPanel :risk="risk" :pair="riskPair" />
            </section>

            <!-- บอทของฉัน + ฟอร์มสร้างบอท — ต้องเชื่อมกระเป๋าก่อน -->
            <template v-if="walletStore.isConnected">
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-lg font-bold text-white">{{ t('aiTrade.myBots') }}</h2>
                        <button
                            type="button"
                            class="btn-brand px-4 py-1.5 text-xs disabled:opacity-50"
                            :disabled="quotaFull"
                            :title="quotaFull ? t('aiTrade.quotaFull') : ''"
                            @click="openBuilder()"
                        >
                            + {{ t('aiTrade.newBot') }}
                        </button>
                    </div>

                    <div v-if="bot.bots.value.length" class="space-y-2">
                        <div
                            v-for="item in bot.bots.value"
                            :key="item.id"
                            class="flex items-center gap-3 rounded-2xl border border-white/10 bg-dark-900/50 p-3 flex-wrap"
                        >
                            <span :class="['w-2 h-2 rounded-full shrink-0',
                                item.status === 'running' ? 'bg-trading-green animate-pulse'
                                    : item.status === 'paused' ? 'bg-amber-400' : 'bg-dark-600']"></span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-white truncate">{{ item.name }}</p>
                                <p class="text-[11px] text-dark-400 font-mono truncate">
                                    {{ item.pair }} · {{ bot.strategyLabel(item) }} · {{ item.timeframe }} ·
                                    SL {{ item.risk.stop_loss_pct }}% / TP {{ item.risk.take_profit_pct }}%
                                </p>
                                <!-- บอทกำลังคิดอะไรอยู่ — ผู้ใช้ต้องเห็นเหตุผล ไม่ใช่แค่ไฟกะพริบ -->
                                <p v-if="item.last_reason" class="text-[11px] text-dark-500 truncate mt-0.5">
                                    <span
                                        v-if="item.last_reason.startsWith('[รอยืนยัน]')"
                                        class="text-primary-300 font-medium"
                                    >{{ t('aiTrade.awaitingConfirm') }} · </span>{{ item.last_reason.replace('[รอยืนยัน] ', '') }}
                                </p>
                                <p v-else class="text-[11px] text-dark-600 truncate mt-0.5">
                                    {{ t('aiTrade.noDecisionYet') }}
                                </p>
                            </div>

                            <!-- โหมด: กดสลับได้ตรงนี้เลย -->
                            <button
                                type="button"
                                :class="['px-2 py-0.5 rounded-full text-[10px] font-medium ring-1 transition-colors',
                                    item.mode === 'live'
                                        ? 'bg-primary-500/15 text-primary-300 ring-primary-500/25 hover:bg-primary-500/25'
                                        : 'bg-white/5 text-dark-300 ring-white/10 hover:text-white']"
                                :title="item.mode === 'live' ? t('aiTrade.switchToDemo') : t('aiTrade.switchToLive')"
                                :disabled="bot.isWorking.value"
                                @click="toggleMode(item)"
                            >
                                {{ item.mode === 'live' ? t('aiTrade.modeLiveShort') : t('aiTrade.modeDemoShort') }}
                            </button>

                            <span :class="['px-2 py-0.5 rounded-full text-[10px] font-medium ring-1', riskTone[item.risk_level]]">
                                {{ t(riskLabel[item.risk_level]) }}
                            </span>

                            <div class="flex items-center gap-1.5">
                                <button
                                    v-if="item.status !== 'running'"
                                    type="button"
                                    class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-trading-green/15 text-trading-green hover:bg-trading-green/25 transition-colors"
                                    @click="setState(item, 'start')"
                                >
                                    {{ t('aiTrade.start') }}
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-amber-500/15 text-amber-300 hover:bg-amber-500/25 transition-colors"
                                    @click="setState(item, 'pause')"
                                >
                                    {{ t('aiTrade.pause') }}
                                </button>
                                <button
                                    type="button"
                                    class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-white/5 text-dark-300 hover:text-white transition-colors"
                                    @click="openBuilder(item)"
                                >
                                    {{ t('aiTrade.edit') }}
                                </button>
                                <button
                                    type="button"
                                    class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-white/5 text-dark-400 hover:text-trading-red transition-colors"
                                    @click="removeBot(item)"
                                >
                                    {{ t('aiTrade.remove') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-else class="glass-dark rounded-2xl p-8 text-center text-sm text-dark-400">
                        {{ t('aiTrade.noBotsLong') }}
                    </p>
                </section>

                <!-- ฟอร์มสร้าง/แก้บอท -->
                <section v-if="showBuilder" class="glass-dark rounded-2xl p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-white">
                            {{ editingId ? t('aiTrade.editBot') : t('aiTrade.createBot') }}
                        </h2>
                        <button type="button" class="text-dark-400 hover:text-white text-sm" @click="showBuilder = false">{{ t('aiTrade.close') }}</button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.botName') }}</span>
                            <input v-model="form.name" type="text" maxlength="60" class="trading-input text-sm" :placeholder="t('aiTrade.botNamePlaceholder')">
                        </label>

                        <label class="block">
                            <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.pair') }}</span>
                            <select v-model="form.pair" class="trading-input text-sm">
                                <option v-for="p in pairs" :key="p.symbol" :value="p.symbol">{{ p.symbol }}</option>
                            </select>
                        </label>
                    </div>

                    <!-- กลยุทธ์ -->
                    <div>
                        <p class="text-[11px] text-dark-400 mb-2">{{ t('aiTrade.strategy') }}</p>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <button
                                v-for="s in bot.strategies.value"
                                :key="s.code"
                                type="button"
                                :disabled="!isUnlocked(s.code)"
                                :title="isUnlocked(s.code) ? '' : t('aiTrade.needTier', { tier: t(tierLabel[s.tier]) })"
                                :class="['relative text-left p-3 rounded-xl border transition-all',
                                    form.strategy === s.code ? 'border-primary-500/60 bg-primary-500/10' : 'border-white/10 bg-white/5 hover:border-white/25',
                                    !isUnlocked(s.code) && 'opacity-45 cursor-not-allowed']"
                                @click="form.strategy = s.code"
                            >
                                <span class="flex items-center gap-1.5 mb-1">
                                    <span class="text-xs font-bold text-white truncate">{{ bot.strategyLabel(s) }}</span>
                                    <span v-if="!isUnlocked(s.code)" class="text-[9px] px-1 rounded bg-warm-500/20 text-warm-300 shrink-0">
                                        {{ t(tierLabel[s.tier]) }}
                                    </span>
                                </span>
                                <span class="block text-[10px] text-dark-400 leading-relaxed line-clamp-3">{{ bot.strategyDescription(s) }}</span>
                                <span :class="['inline-block mt-1.5 px-1.5 py-0.5 rounded text-[9px] ring-1', riskTone[s.risk]]">
                                    {{ t(riskLabel[s.risk]) }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Timeframe + พารามิเตอร์ของกลยุทธ์ -->
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="block">
                            <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.timeframe') }}</span>
                            <select v-model="form.timeframe" class="trading-input text-sm">
                                <option v-for="tf in timeframes" :key="tf" :value="tf">{{ tf }}</option>
                            </select>
                        </label>

                        <label v-for="spec in selectedStrategy?.params || []" :key="spec.key" class="block">
                            <span class="block text-[11px] text-dark-400 mb-1">{{ paramLabel(spec) }}</span>

                            <input
                                v-if="spec.type === 'number'"
                                v-model.number="form.params[spec.key]"
                                type="number"
                                :min="spec.min"
                                :max="spec.max"
                                :step="spec.step"
                                class="trading-input text-sm font-mono"
                            >
                            <select v-else-if="spec.type === 'select'" v-model="form.params[spec.key]" class="trading-input text-sm">
                                <option v-for="opt in spec.options" :key="opt" :value="opt">{{ opt }}</option>
                            </select>
                            <span v-else class="flex items-center gap-2 h-[46px]">
                                <input
                                    v-model="form.params[spec.key]"
                                    type="checkbox"
                                    class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-4 h-4"
                                >
                                <span class="text-xs text-dark-300">{{ form.params[spec.key] ? t('aiTrade.on') : t('aiTrade.off') }}</span>
                            </span>
                        </label>
                    </div>

                    <!-- ความเสี่ยง -->
                    <div>
                        <p class="text-[11px] text-dark-400 mb-2">
                            {{ t('aiTrade.riskFrame') }}
                            <span class="text-dark-500">— {{ t('aiTrade.riskNote') }}</span>
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label v-for="field in riskFields" :key="field.key" class="block">
                                <span class="block text-[11px] text-dark-400 mb-1">{{ t(field.labelKey) }}</span>
                                <input
                                    v-model.number="form.risk[field.key]"
                                    type="number"
                                    :min="limits[field.key]?.min"
                                    :max="limits[field.key]?.max"
                                    class="trading-input text-sm font-mono"
                                >
                            </label>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="btn-brand px-6 py-2 text-sm disabled:opacity-50"
                            :disabled="bot.isWorking.value"
                            @click="saveBot"
                        >
                            {{ editingId ? t('aiTrade.saveEdit') : t('aiTrade.createBot') }}
                        </button>
                        <button type="button" class="btn-secondary px-6 py-2 text-sm" @click="showBuilder = false">{{ t('aiTrade.cancel') }}</button>
                    </div>
                </section>
            </template>

            <section>
                <h2 class="text-lg font-bold text-white mb-1">{{ t('aiTrade.allStrategies') }}</h2>
                <p class="text-[11px] text-dark-400 mb-3">{{ t('aiTrade.allStrategiesSub') }}</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="s in bot.strategies.value"
                        :key="s.code"
                        class="rounded-2xl border border-white/10 bg-dark-900/50 p-4"
                    >
                        <div class="flex items-center gap-2 mb-2">
                            <h3 class="text-sm font-bold text-white">{{ bot.strategyLabel(s) }}</h3>
                            <span v-if="s.tier !== 'basic'" class="ml-auto text-[9px] px-1.5 py-0.5 rounded bg-warm-500/15 text-warm-300">
                                {{ t(tierLabel[s.tier]) }}
                            </span>
                        </div>
                        <p class="text-[11px] text-dark-400 leading-relaxed mb-2">{{ bot.strategyDescription(s) }}</p>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span :class="['px-1.5 py-0.5 rounded text-[9px] ring-1', riskTone[s.risk]]">{{ t(riskLabel[s.risk]) }}</span>
                            <span class="text-[9px] text-dark-500 font-mono">{{ s.timeframes.join(' · ') }}</span>
                        </div>
                    </article>
                </div>
            </section>

            <!-- คู่เทรดยอดนิยม (ทางลัดกลับกระดาน) -->
            <section v-if="pairs.length">
                <h2 class="text-lg font-bold text-white mb-3">{{ t('aiTrade.openBoard') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="p in pairs.slice(0, 12)"
                        :key="p.symbol"
                        :href="`/trade/${p.symbol.replace('/', '-')}`"
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border border-white/10 bg-dark-900/50 hover:border-primary-500/40 transition-colors"
                    >
                        <CoinIcon :symbol="p.base" size="xs" :src="p.logo || undefined" />
                        <span class="text-[11px] text-white font-medium">{{ p.symbol }}</span>
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
/* ออร่าไล่สีแบรนด์หมุนช้าๆ หลัง hero */
.ai-hero-aura {
    position: absolute;
    inset: -80% -20%;
    background: conic-gradient(
        from 0deg,
        rgba(139, 92, 246, 0.20),
        rgba(6, 182, 212, 0.20),
        rgba(249, 115, 22, 0.14),
        rgba(139, 92, 246, 0.20)
    );
    filter: blur(70px);
    animation: aiHeroSpin 26s linear infinite;
    pointer-events: none;
}

/* โครงข่ายสัญญาณ — บอกว่าเป็นหน้าของ AI โดยไม่ต้องใช้ภาพแรสเตอร์ */
.ai-hero-net {
    position: absolute;
    inset: auto 0 0 0;
    width: 100%;
    height: 62%;
    opacity: 0.5;
    pointer-events: none;
    mask-image: linear-gradient(90deg, transparent, #000 25%, #000 75%, transparent);
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 25%, #000 75%, transparent);
}

@keyframes aiHeroSpin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ai-hero-aura {
        animation: none;
    }
}
</style>
