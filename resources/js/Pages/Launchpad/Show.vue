<script setup>
/**
 * TPIX TRADE — หน้าขายของเหรียญหนึ่ง (/launchpad/{symbol})
 *
 * ซ้าย: ข้อมูลหน้าขาย (จากเชนตอนลงทะเบียน) + ความคืบหน้า + ป้ายความเสี่ยงที่อ่านสด
 * ขวา: กล่องซื้อ (LaunchpadBuyBox) — เตือนความเสี่ยงและติ๊กยอมรับก่อนเซ็นทุกครั้ง
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import LaunchpadRiskPanel from '@/Components/Launchpad/LaunchpadRiskPanel.vue';
import LaunchpadBuyBox from '@/Components/Launchpad/LaunchpadBuyBox.vue';
import LaunchpadReportModal from '@/Components/Launchpad/LaunchpadReportModal.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { saleState, timeLeft, formatDecimal, unitsToDecimal, soldPercent, explorerUrl, shortAddr } from '@/utils/launchpad';

const { t, locale } = useTranslation();
const wallet = useWalletStore();

const props = defineProps({
    sale: { type: Object, required: true },
    launchpad: { type: Object, required: true },
});

const risk = ref(null);
const riskFailed = ref(false);
const now = ref(Date.now());

const state = computed(() => saleState(props.sale, now.value));
const decimals = computed(() => Number(props.sale.token?.decimals ?? 18));
const stats = computed(() => risk.value?.stats ?? null);
const capUnits = computed(() => {
    // sale_cap มาเป็นทศนิยมของเหรียญ — แปลงกลับเป็นหน่วยเล็กสุดเพื่อเทียบกับ sold (หน่วยเล็กสุด) แบบ BigInt
    const [w, f = ''] = String(props.sale.sale_cap ?? '0').split('.');
    try {
        return (BigInt(w) * 10n ** BigInt(decimals.value) + BigInt(f.padEnd(decimals.value, '0').slice(0, decimals.value) || '0')).toString();
    } catch {
        return '0';
    }
});
const percent = computed(() => (stats.value?.sold ? soldPercent(stats.value.sold, capUnits.value) : 0));
const tokenAmount = (units) => (units === null || units === undefined ? '—' : formatDecimal(unitsToDecimal(units, decimals.value), 2));
const usdtAmount = (units) => (units === null || units === undefined ? '—' : formatDecimal(unitsToDecimal(units, 6), 2));

function dateText(iso) {
    const d = new Date(iso ?? '');
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function duration(iso) {
    const { d, h, m } = timeLeft(iso, now.value);
    return t('launchpad.time.dhm', { d, h, m });
}

const addr = (value) => explorerUrl(props.launchpad.tpix_explorer, 'address', value);

async function loadRisk() {
    try {
        const { data } = await axios.get(`/api/v1/launchpad/sales/${props.sale.id}/risk`);
        risk.value = data.data;
        riskFailed.value = false;
    } catch {
        riskFailed.value = true;
    }
}

let timer = null;
let riskTimer = null;
onMounted(() => {
    loadRisk();
    timer = setInterval(() => { now.value = Date.now(); }, 30_000);
    riskTimer = setInterval(loadRisk, 60_000);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
    if (riskTimer) clearInterval(riskTimer);
});

const stateClass = {
    live: 'bg-trading-green/15 text-trading-green border-trading-green/30',
    upcoming: 'bg-primary-500/15 text-primary-300 border-primary-500/30',
    ended: 'bg-white/5 text-dark-300 border-white/10',
};
</script>

<template>
    <Head :title="`${sale.token.symbol} — ${t('launchpad.title')}`" />
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-5 px-1">
            <Link href="/launchpad" class="text-xs text-primary-400 hover:underline">← {{ t('launchpad.backToList') }}</Link>

            <header class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-2xl md:text-3xl font-bold text-white truncate">{{ sale.token.name }} <span class="text-dark-400">({{ sale.token.symbol }})</span></h1>
                    <p class="text-xs text-dark-400 mt-1">{{ t('launchpad.sale.onChainNote') }}</p>
                </div>
                <span :class="['px-3 py-1 rounded-full text-xs border', stateClass[state]]" data-test="sale-state">
                    {{ t(`launchpad.state.${state}`) }}
                    <template v-if="state === 'live'"> · {{ t('launchpad.card.endsIn', { time: duration(sale.ends_at) }) }}</template>
                    <template v-else-if="state === 'upcoming'"> · {{ t('launchpad.card.startsIn', { time: duration(sale.starts_at) }) }}</template>
                </span>
            </header>

            <div v-if="!launchpad.enabled" class="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 text-sm" data-test="not-open">
                <p class="font-semibold text-amber-300">{{ t('launchpad.notOpen') }}</p>
                <p class="text-xs text-dark-300 mt-1">{{ t('launchpad.notOpenBody') }}</p>
            </div>

            <div class="grid lg:grid-cols-5 gap-5">
                <div class="lg:col-span-3 space-y-5">
                    <!-- ตัวเลขหลัก -->
                    <section class="glass-dark rounded-2xl p-5 border border-white/10">
                        <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <div><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.price') }}</dt><dd class="text-white font-mono">${{ sale.price_usdt }}</dd></div>
                            <div><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.perUsdt') }}</dt><dd class="text-white font-mono">{{ formatDecimal(sale.tokens_per_usdt, 2) }}</dd></div>
                            <div><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.cap') }}</dt><dd class="text-white font-mono">{{ formatDecimal(sale.sale_cap, 0) }}</dd></div>
                            <div><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.poolShare') }}</dt><dd class="text-white">{{ (sale.pool_share_bps / 100).toFixed(2) }}%</dd></div>
                            <div><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.starts') }}</dt><dd class="text-white">{{ dateText(sale.starts_at) }}</dd></div>
                            <div><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.ends') }}</dt><dd class="text-white">{{ dateText(sale.ends_at) }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-dark-400 text-xs">{{ t('launchpad.sale.lpUnlock') }}</dt><dd class="text-white">{{ dateText(sale.lp_unlock_at) }}</dd></div>
                        </dl>

                        <!-- ความคืบหน้า (อ่านสด) -->
                        <div class="mt-5" data-test="progress">
                            <template v-if="stats && stats.sold !== null">
                                <div class="h-2 rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-primary-500 to-trading-green" :style="{ width: `${percent}%` }" />
                                </div>
                                <div class="mt-2 grid grid-cols-3 gap-2 text-xs">
                                    <div><p class="text-dark-400">{{ t('launchpad.sale.sold') }}</p><p class="text-white font-mono">{{ tokenAmount(stats.sold) }} ({{ percent.toFixed(2) }}%)</p></div>
                                    <div><p class="text-dark-400">{{ t('launchpad.sale.raised') }}</p><p class="text-white font-mono">{{ usdtAmount(stats.raised) }} USDT</p></div>
                                    <div><p class="text-dark-400">{{ t('launchpad.sale.poolLiquidity') }}</p><p class="text-white font-mono">{{ stats.pool_quote === null ? '—' : `${usdtAmount(stats.pool_quote)} USDT` }}</p></div>
                                </div>
                            </template>
                            <p v-else-if="riskFailed || risk" class="text-xs text-dark-500">{{ t('launchpad.sale.progressUnavailable') }}</p>
                        </div>
                    </section>

                    <LaunchpadRiskPanel :risk="risk" :failed="riskFailed" />

                    <!-- ที่อยู่สัญญา -->
                    <section class="glass-dark rounded-2xl p-5 border border-white/10 text-sm">
                        <h2 class="text-sm font-semibold text-white mb-3">{{ t('launchpad.sale.contracts') }}</h2>
                        <dl class="space-y-2">
                            <div v-for="row in [
                                { k: 'token', v: sale.token.address },
                                { k: 'saleContract', v: sale.sale_address },
                                { k: 'feeder', v: sale.feeder_address },
                                { k: 'creator', v: sale.creator_address },
                            ]" :key="row.k" class="flex flex-wrap justify-between gap-2">
                                <dt class="text-dark-400">{{ t(`launchpad.sale.${row.k}`) }}</dt>
                                <dd class="font-mono text-xs">
                                    <a v-if="addr(row.v)" :href="addr(row.v)" target="_blank" rel="noopener noreferrer" class="text-primary-400 hover:underline">{{ shortAddr(row.v) }}</a>
                                    <span v-else class="text-white">{{ shortAddr(row.v) }}</span>
                                </dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex justify-end">
                            <LaunchpadReportModal :sale-id="sale.id" :wallet="wallet.address ?? null" />
                        </div>
                    </section>
                </div>

                <div class="lg:col-span-2">
                    <div class="lg:sticky lg:top-20">
                        <LaunchpadBuyBox :sale="sale" :launchpad="launchpad" @ordered="loadRisk" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
