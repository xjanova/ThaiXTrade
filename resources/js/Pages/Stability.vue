<script setup>
/**
 * TPIX TRADE — หน้าความโปร่งใสของ TPIX Stability Reserve (/stability)
 *
 * ทุกอย่างบนหน้านี้มาจาก /api/v1/stability (อ่านอย่างเดียว) — ไม่มีตัวเลขฝังในหน้า
 * กฎที่แสดงคือค่าที่บอทใช้จริงในตอนนี้ แอดมินเปลี่ยนเมื่อไหร่หน้านี้เปลี่ยนตาม
 *
 * ถ้อยคำต้องซื่อตรงเสมอ: ไม่การันตีราคา · บอกชัดว่าโครงการเป็นคนดำเนินการ ·
 * AI แค่เขียนสรุป ไม่ได้ตัดสินใจ (คำเตือนอยู่ด้านบน ไม่ซ่อนไว้ท้ายหน้า)
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import StabilityBandChart from '@/Components/Stability/StabilityBandChart.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const data = ref(null);
const loading = ref(true);
const failed = ref(false);
let timer = null;
let disposed = false;

async function load() {
    try {
        const res = await axios.get('/api/v1/stability');
        if (disposed) return;
        if (res.data?.success) {
            data.value = res.data.data;
            failed.value = false;
        } else {
            failed.value = data.value === null;
        }
    } catch {
        // โหลดรอบรีเฟรชพลาด = คงข้อมูลเดิมไว้ (ดีกว่าล้างหน้าเป็นว่างแล้วคนอ่านผิด)
        if (!disposed) failed.value = data.value === null;
    } finally {
        if (!disposed) loading.value = false;
    }
}

onMounted(() => {
    load();
    timer = setInterval(load, 60000);
});
onUnmounted(() => {
    disposed = true;
    clearInterval(timer);
});

const status = computed(() => data.value?.status ?? null);
const rules = computed(() => data.value?.rules ?? null);
const market = computed(() => data.value?.market ?? null);
const wallet = computed(() => data.value?.wallet ?? null);
const backing = computed(() => data.value?.usdt_backing ?? null);
const budget = computed(() => data.value?.budget_today ?? null);
const isThai = computed(() => locale.value === 'th');

/* ราคาเป็นสตริงทศนิยม 18 ตำแหน่ง — แสดงแค่พอดีอ่าน ไม่ปัดค่าที่เก็บ */
function fmtPrice(v) {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (!Number.isFinite(n)) return '—';
    return n < 1 ? n.toFixed(6) : n.toFixed(4);
}

/* จำนวนเงินเป็นสตริงทศนิยมจากฝั่ง PHP — ใส่คอมมาเอง ไม่แปลงเป็น Number (กันเศษหาย) */
function fmtAmount(v, maxFrac = 4) {
    if (v === null || v === undefined || v === '') return '—';
    const [i, f = ''] = String(v).split('.');
    const withCommas = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const frac = f.slice(0, maxFrac).replace(/0+$/, '');
    return frac ? `${withCommas}.${frac}` : withCommas;
}

function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString(isThai.value ? 'th-TH' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

const short = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-6)}` : '—');

const statusLabel = computed(() => {
    const s = status.value?.state;
    return s ? t(`stability.status.${s}`) : '';
});

const statusClass = computed(() => ({
    active: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    paused: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    off: 'bg-slate-500/15 text-slate-300 border-slate-500/30',
}[status.value?.state] ?? 'bg-slate-500/15 text-slate-300 border-slate-500/30'));

/* เหตุผลที่หยุด — รหัสที่รู้จักแปลได้ ข้อความอิสระจากแอดมินแสดงตามที่พิมพ์ (ผ่าน {{ }} = escape แล้ว) */
const pauseText = computed(() => {
    const reason = status.value?.pause_reason;
    if (!reason) return null;
    const key = `stability.pauseReasons.${reason}`;
    const translated = t(key);
    return t('stability.status.pausedBecause', { reason: translated === key ? reason : translated });
});

const positionText = computed(() => {
    const p = market.value?.position;
    return p ? t(`stability.market.${p}`) : '—';
});

const reasonText = (code) => {
    const key = `stability.reasons.${code}`;
    const text = t(key);
    return text === key ? code : text;
};

const txTypeText = (tx) => (tx.purpose === 'approve' ? t('stability.trades.approve') : t(`stability.trades.${tx.side}`));

const txStatusClass = (s) => ({
    confirmed: 'text-emerald-300',
    failed: 'text-rose-300',
    dropped: 'text-slate-400',
}[s] ?? 'text-amber-300');

const inToken = (tx) => (tx.token_in === 'usdt' ? 'USDT' : 'TPIX');
const outToken = (tx) => (tx.side === 'buy' ? 'TPIX' : 'USDT');

const reportSummary = (r) => (isThai.value ? r.summary_th : r.summary_en);
const reportFacts = (r) => (isThai.value ? r.facts_th : r.facts_en);
</script>

<template>
    <Head :title="t('stability.title')" />

    <AppLayout :hide-sidebar="true">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
            <!-- หัวเรื่อง + สถานะ -->
            <header class="space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-bold text-white">{{ t('stability.title') }}</h1>
                    <span v-if="status" class="px-3 py-1 rounded-full border text-xs font-semibold" :class="statusClass" data-testid="status-badge">
                        {{ statusLabel }}
                    </span>
                </div>
                <p class="text-dark-300 max-w-3xl">{{ t('stability.subtitle') }}</p>
                <p class="text-sm text-sky-300/90 max-w-3xl" data-testid="ai-note">{{ t('stability.aiNote') }}</p>
                <p v-if="pauseText" class="text-sm text-amber-300">{{ pauseText }}</p>
                <p v-else-if="status?.state === 'off'" class="text-sm text-dark-400">{{ t('stability.status.offHint') }}</p>
                <p v-if="status?.last_run_at" class="text-xs text-dark-500">{{ t('stability.status.lastRun', { time: fmtTime(status.last_run_at) }) }}</p>
            </header>

            <!-- คำเตือน — อยู่บนสุด ไม่ซ่อนท้ายหน้า -->
            <section class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4" data-testid="disclaimer">
                <h2 class="text-sm font-semibold text-amber-200 mb-1">{{ t('stability.disclaimer.title') }}</h2>
                <p class="text-sm text-amber-100/90">{{ t('stability.disclaimer.body') }}</p>
            </section>

            <div v-if="loading && !data" class="glass-dark rounded-2xl p-8 text-center text-dark-300">{{ t('stability.loading') }}</div>
            <div v-else-if="failed && !data" class="glass-dark rounded-2xl p-8 text-center text-rose-300" data-testid="load-error">{{ t('stability.loadError') }}</div>

            <template v-if="data">
                <!-- ตลาดตอนนี้ + กราฟ -->
                <section class="grid lg:grid-cols-3 gap-4">
                    <div class="glass-dark rounded-2xl p-5 space-y-3">
                        <h2 class="text-lg font-semibold text-white">{{ t('stability.market.title') }}</h2>
                        <p v-if="!market" class="text-sm text-dark-400">{{ t('stability.market.noData') }}</p>
                        <dl v-else class="space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-dark-400">{{ t('stability.market.poolPrice') }}</dt><dd class="text-white font-mono" data-testid="pool-price">{{ fmtPrice(market.pool_price) }} USDT</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-dark-400">{{ t('stability.market.reference') }}</dt><dd class="text-white font-mono">{{ fmtPrice(market.reference_price) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-dark-400">{{ t('stability.market.band') }}</dt><dd class="text-white font-mono">{{ fmtPrice(market.band_low) }} – {{ fmtPrice(market.band_high) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-dark-400">{{ t('stability.market.position') }}</dt><dd class="text-white text-right">{{ positionText }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-dark-400">{{ t('stability.market.poolDepth') }}</dt><dd class="text-white font-mono text-right">{{ fmtAmount(market.pool_tpix, 2) }} TPIX / {{ fmtAmount(market.pool_usdt, 2) }} USDT</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-dark-400">{{ t('stability.market.coverage') }}</dt><dd class="text-white font-mono">{{ market.history_coverage_pct }}%</dd></div>
                        </dl>
                    </div>
                    <div class="glass-dark rounded-2xl p-5 lg:col-span-2">
                        <h2 class="text-lg font-semibold text-white mb-3">{{ t('stability.chart.title') }}</h2>
                        <StabilityBandChart :points="data.chart" />
                    </div>
                </section>

                <!-- กฎ (ค่าที่ใช้จริง) -->
                <section v-if="rules" class="glass-dark rounded-2xl p-5" data-testid="rules">
                    <h2 class="text-lg font-semibold text-white mb-3">{{ t('stability.rules.title') }}</h2>
                    <ul class="space-y-2 text-sm text-dark-200 list-disc pl-5">
                        <li>{{ t('stability.rules.reference', { days: rules.lookback_days }) }}</li>
                        <li>{{ t('stability.rules.band', { pct: rules.band_pct }) }}</li>
                        <li>{{ t('stability.rules.below') }}</li>
                        <li>{{ t('stability.rules.above') }}</li>
                        <li>{{ t('stability.rules.impact', { pct: rules.max_impact_pct }) }}</li>
                        <li>{{ t('stability.rules.size', { usdt: rules.max_trade_usdt, tpix: rules.max_trade_tpix, minUsdt: rules.min_trade_usdt, minTpix: rules.min_trade_tpix }) }}</li>
                        <li>{{ t('stability.rules.daily', { usdt: rules.daily_budget_usdt, tpix: rules.daily_budget_tpix }) }}</li>
                        <li>{{ t('stability.rules.timing', { minutes: rules.trade_interval_minutes, hours: rules.opposite_cooldown_hours }) }}</li>
                        <li>{{ t('stability.rules.safety', { usdt: rules.min_pool_usdt, tpix: rules.min_pool_tpix, minutes: rules.max_data_age_minutes, coverage: rules.min_history_coverage_pct }) }}</li>
                        <li>{{ t('stability.rules.slippage', { bps: rules.slippage_bps }) }}</li>
                        <li>{{ t('stability.rules.schedule', { minutes: rules.schedule_minutes, tz: rules.timezone }) }}</li>
                        <li>{{ t('stability.rules.failsafe') }}</li>
                    </ul>
                </section>

                <!-- กระเป๋า + งบ -->
                <section class="grid lg:grid-cols-2 gap-4">
                    <div class="glass-dark rounded-2xl p-5 space-y-3">
                        <h2 class="text-lg font-semibold text-white">{{ t('stability.wallet.title') }}</h2>
                        <template v-if="wallet?.address">
                            <p class="font-mono text-sm text-white break-all" data-testid="wallet-address">{{ wallet.address }}</p>
                            <a :href="wallet.explorer_url" target="_blank" rel="noopener noreferrer" class="text-sm text-sky-300 hover:underline">{{ t('stability.wallet.viewOnExplorer') }} ↗</a>
                            <div v-if="wallet.balances" class="text-sm text-dark-200">
                                <span class="text-dark-400">{{ t('stability.wallet.balances') }}:</span>
                                <span class="font-mono ml-2">{{ fmtAmount(wallet.balances.tpix, 2) }} TPIX</span>
                                <span class="font-mono ml-3">{{ fmtAmount(wallet.balances.usdt, 2) }} USDT</span>
                            </div>
                        </template>
                        <p v-else class="text-sm text-dark-400" data-testid="wallet-not-set">{{ t('stability.wallet.notSet') }}</p>

                        <div v-if="backing" class="border-t border-white/5 pt-3 space-y-2 text-sm">
                            <h3 class="font-semibold text-white">{{ t('stability.wallet.backing') }}</h3>
                            <p class="text-dark-300">{{ backing.mode === 'bridge_proof' ? t('stability.wallet.modeBridge') : t('stability.wallet.modeDeposits') }}</p>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-xl bg-white/5 p-2"><div class="text-xs text-dark-400">{{ t('stability.wallet.deposited') }}</div><div class="font-mono text-white">{{ fmtAmount(backing.deposits_total, 2) }}</div></div>
                                <div class="rounded-xl bg-white/5 p-2"><div class="text-xs text-dark-400">{{ t('stability.wallet.spent') }}</div><div class="font-mono text-white">{{ fmtAmount(backing.spent_total, 2) }}</div></div>
                                <div class="rounded-xl bg-white/5 p-2"><div class="text-xs text-dark-400">{{ t('stability.wallet.remaining') }}</div><div class="font-mono text-white">{{ fmtAmount(backing.deposits_remaining, 2) }}</div></div>
                            </div>
                        </div>

                        <div class="border-t border-white/5 pt-3 text-sm">
                            <h3 class="font-semibold text-white mb-2">{{ t('stability.wallet.deposits') }}</h3>
                            <p v-if="!data.deposits?.length" class="text-dark-400">{{ t('stability.wallet.noDeposits') }}</p>
                            <ul v-else class="space-y-1">
                                <li v-for="d in data.deposits" :key="d.tx_hash" class="flex flex-wrap justify-between gap-2">
                                    <span class="font-mono text-white">{{ fmtAmount(d.amount, 2) }} {{ d.token.toUpperCase() }}</span>
                                    <a :href="d.tx_url" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(d.tx_hash) }}</a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass-dark rounded-2xl p-5 space-y-3">
                        <h2 class="text-lg font-semibold text-white">{{ t('stability.budget.title') }}</h2>
                        <dl v-if="budget" class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-dark-400">{{ t('stability.budget.usdt') }}</dt><dd class="font-mono text-white">{{ fmtAmount(budget.usdt_spent, 2) }} / {{ fmtAmount(budget.usdt_limit, 2) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-dark-400">{{ t('stability.budget.tpix') }}</dt><dd class="font-mono text-white">{{ fmtAmount(budget.tpix_sold, 2) }} / {{ fmtAmount(budget.tpix_limit, 2) }}</dd></div>
                        </dl>

                        <div v-if="data.contracts" class="border-t border-white/5 pt-3 text-sm space-y-1">
                            <h3 class="font-semibold text-white mb-1">{{ t('stability.contracts.title') }}</h3>
                            <div v-for="key in ['router', 'pair', 'usdt', 'wtpix']" :key="key" class="flex flex-wrap justify-between gap-2">
                                <span class="text-dark-400">{{ t(`stability.contracts.${key}`) }}</span>
                                <a :href="`${data.explorer}/address/${data.contracts[key]}`" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(data.contracts[key]) }}</a>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ทุกธุรกรรม -->
                <section class="glass-dark rounded-2xl p-5">
                    <h2 class="text-lg font-semibold text-white mb-3">{{ t('stability.trades.title') }}</h2>
                    <p v-if="!data.transactions?.length" class="text-sm text-dark-400" data-testid="no-trades">{{ t('stability.trades.empty') }}</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm min-w-[640px]">
                            <thead>
                                <tr class="text-left text-dark-400 border-b border-white/5">
                                    <th class="py-2 pr-3">{{ t('stability.trades.time') }}</th>
                                    <th class="py-2 pr-3">{{ t('stability.trades.type') }}</th>
                                    <th class="py-2 pr-3">{{ t('stability.trades.amountIn') }}</th>
                                    <th class="py-2 pr-3">{{ t('stability.trades.received') }}</th>
                                    <th class="py-2 pr-3">{{ t('stability.trades.status') }}</th>
                                    <th class="py-2">{{ t('stability.trades.tx') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tx in data.transactions" :key="tx.tx_hash" class="border-b border-white/5" data-testid="trade-row">
                                    <td class="py-2 pr-3 text-dark-300">{{ fmtTime(tx.at) }}</td>
                                    <td class="py-2 pr-3 text-white">{{ txTypeText(tx) }}</td>
                                    <td class="py-2 pr-3 font-mono text-white">{{ fmtAmount(tx.amount_in) }} {{ inToken(tx) }}</td>
                                    <td class="py-2 pr-3 font-mono text-white">
                                        <span v-if="tx.purpose === 'swap'">{{ fmtAmount(tx.actual_out ?? tx.expected_out) }} {{ outToken(tx) }}</span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="py-2 pr-3" :class="txStatusClass(tx.status)">{{ t(`stability.trades.statuses.${tx.status}`) }}</td>
                                    <td class="py-2"><a :href="tx.tx_url" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(tx.tx_hash) }}</a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- การตัดสินใจล่าสุด (รวมรอบที่ไม่ทำอะไร) -->
                <section class="glass-dark rounded-2xl p-5">
                    <h2 class="text-lg font-semibold text-white mb-3">{{ t('stability.decisions.title') }}</h2>
                    <p v-if="!data.decisions?.length" class="text-sm text-dark-400">{{ t('stability.decisions.empty') }}</p>
                    <ul v-else class="divide-y divide-white/5 text-sm">
                        <li v-for="(d, i) in data.decisions" :key="i" class="py-2 flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3" data-testid="decision-row">
                            <span class="text-dark-400 sm:w-44 shrink-0">{{ fmtTime(d.at) }}</span>
                            <span class="font-semibold text-white sm:w-24 shrink-0">{{ t(`stability.decisions.actions.${d.action}`) }}</span>
                            <span class="text-dark-200 flex-1">{{ reasonText(d.reason_code) }}</span>
                            <span v-if="d.pool_price" class="font-mono text-xs text-dark-400">{{ fmtPrice(d.pool_price) }}<template v-if="d.band_low"> · {{ fmtPrice(d.band_low) }}–{{ fmtPrice(d.band_high) }}</template></span>
                        </li>
                    </ul>
                </section>

                <!-- รายงานรายวัน -->
                <section class="glass-dark rounded-2xl p-5">
                    <h2 class="text-lg font-semibold text-white mb-3">{{ t('stability.reports.title') }}</h2>
                    <p v-if="!data.reports?.length" class="text-sm text-dark-400">{{ t('stability.reports.empty') }}</p>
                    <div v-else class="space-y-4">
                        <article v-for="r in data.reports" :key="r.date" class="rounded-xl bg-white/5 p-4 space-y-2" data-testid="report">
                            <h3 class="font-semibold text-white">{{ r.date }}</h3>
                            <div v-if="reportSummary(r)">
                                <div class="text-xs uppercase tracking-wide text-sky-300 mb-1">{{ t('stability.reports.aiWritten') }}</div>
                                <p class="text-sm text-dark-100 whitespace-pre-line">{{ reportSummary(r) }}</p>
                            </div>
                            <p v-else class="text-xs text-dark-400">{{ t('stability.reports.noAi') }}</p>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-dark-400 mb-1">{{ t('stability.reports.facts') }}</div>
                                <p class="text-sm text-dark-300">{{ reportFacts(r) }}</p>
                            </div>
                        </article>
                    </div>
                </section>

                <p class="text-xs text-dark-500">
                    {{ t('stability.api') }} <a href="/api/v1/stability" class="font-mono text-sky-300 hover:underline">/api/v1/stability</a>
                </p>
            </template>
        </div>
    </AppLayout>
</template>
