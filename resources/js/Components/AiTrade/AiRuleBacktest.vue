<script setup>
/**
 * TPIX TRADE — ทดสอบกฎของโหมด "วางกลยุทธ์เอง" กับราคาย้อนหลัง ก่อนสร้างหรือเริ่มบอท
 *
 * เซิร์ฟเวอร์เล่นกฎผ่านเครื่องยนต์ตัวเดียวกับบอทจริง (POST /api/v1/ai-bot/backtest) แล้วคืนตัวเลขดิบกับ
 * คำตัดสินเป็นรหัส — ถ้อยคำเขียนที่นี่ และต้องตรงไปตรงมาแบบเดียวกับที่เทมเพลตของแคตตาล็อกใช้กับชุดที่ขาดทุน:
 *   - ไม้น้อยเกินไป = "ยังสรุปไม่ได้" ไม่ใช่ตัวเลขกำไร
 *   - ขาดทุน/ไม่ชนะต้นทุน = บอกตรงๆ พร้อมตัวเลข
 *   - ผลบวก = ผลในอดีตของเหรียญเดียวช่วงเดียว และโชว์ผลของการถือเฉยๆ คู่กันเสมอ
 *
 * ผลเป็นของ "กฎชุดที่กดทดสอบ" เท่านั้น — แก้กฎหรือค่าตั้งหลังจากนั้น ผลเดิมถูกติดป้ายว่าเป็นของชุดก่อน
 *
 * Developed by Xman Studio
 */

import { computed, ref, watch } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';
import { timeframeMinutes } from '@/utils/aiRules';

const props = defineProps({
    /** { pair, timeframe, params, risk } ของฟอร์มตอนนี้ */
    request: { type: Object, required: true },
    schema: { type: Object, default: null },
    /** async (payload) => { ok, data, error } — ตัวเรียก API (useAiBot.runBacktest) */
    runner: { type: Function, required: true },
    /** กฎยังไม่ถูกต้อง: ข้อความที่บอกว่าต้องแก้อะไรก่อน (ว่าง = ทดสอบได้) */
    blockedReason: { type: String, default: '' },
});

const { t } = useTranslation();

const isRunning = ref(false);
const result = ref(null);
const error = ref('');
const ranKey = ref('');

const limits = computed(() => props.schema?.backtest ?? {});

/** วันที่ทดสอบได้สูงสุดของกรอบเวลานี้ — เพดานจำนวนแท่งของเซิร์ฟเวอร์แปลงเป็นวัน */
const maxDays = computed(() => {
    const minutes = timeframeMinutes(props.request.timeframe) ?? 60;
    const byBars = Math.floor((Number(limits.value.max_bars) || 9000) * minutes / 1440);

    return Math.max(7, Math.min(Number(limits.value.max_days) || 730, byBars));
});

const dayOptions = computed(() => {
    const options = [30, 90, 180, 365, 730].filter((d) => d < maxDays.value);

    return [...options, maxDays.value];
});

const days = ref(0);

function pickDefaultDays() {
    const wanted = Number(limits.value.default_days) || 180;
    const fits = dayOptions.value.filter((d) => d <= wanted);

    days.value = fits.length ? fits[fits.length - 1] : dayOptions.value[0];
}

pickDefaultDays();

// เปลี่ยนกรอบเวลาแล้วตัวเลือกวันเปลี่ยน — ค่าที่เลือกไว้ต้องยังเป็นตัวเลือกที่มีอยู่
watch(dayOptions, (options) => {
    if (!options.includes(days.value)) pickDefaultDays();
});

const requestKey = computed(() => JSON.stringify([props.request, days.value]));
const isStale = computed(() => !!result.value && ranKey.value !== requestKey.value);

async function run() {
    // กดซ้ำระหว่างรอ = ไม่ยิงซ้ำ (งานคำนวณหนัก มีงบเรียกจำกัด) — จึงมีคำขอค้างได้ครั้งละหนึ่งเสมอ
    if (isRunning.value || props.blockedReason) return;

    // จำว่าผลนี้เป็นของกฎชุดไหน "ตอนกด" — ผู้ใช้แก้กฎระหว่างรอ ผลที่กลับมาต้องถูกติดป้ายว่าเป็นของชุดก่อน
    const key = requestKey.value;

    isRunning.value = true;
    error.value = '';

    try {
        const response = await props.runner({ strategy: 'custom', ...props.request, days: days.value });

        if (response?.ok && response.data) {
            result.value = response.data;
            ranKey.value = key;
        } else {
            error.value = response?.error?.message || t('aiTrade.backtest.failed');
        }
    } catch {
        error.value = t('aiTrade.backtest.failed');
    } finally {
        isRunning.value = false;
    }
}

// ── ถ้อยคำของผล ──────────────────────────────────────────────────────────────

const summary = computed(() => result.value?.summary ?? null);

function signedPct(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';

    const number = Number(value);

    return `${number > 0 ? '+' : ''}${number.toFixed(2)}%`;
}

function money(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return '—';

    return `${number > 0 ? '+' : ''}${number.toFixed(2)}`;
}

const verdictLevel = computed(() => result.value?.verdict?.level ?? 'insufficient');

const verdictTone = computed(() => ({
    insufficient: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
    losing: 'border-trading-red/30 bg-trading-red/10 text-trading-red',
    negative_expectancy: 'border-trading-red/30 bg-trading-red/10 text-trading-red',
    // ผลบวกไม่ใช้สีเขียวสด — เป็นผลในอดีต ไม่ใช่สัญญาณให้ลงเงิน
    positive: 'border-white/15 bg-white/5 text-dark-100',
}[verdictLevel.value] ?? 'border-amber-500/30 bg-amber-500/10 text-amber-200'));

const verdictText = computed(() => {
    const s = summary.value;
    if (!s) return '';

    const params = {
        pair: result.value.pair,
        days: s.days,
        closed: s.closed,
        min: result.value.verdict?.min_closed ?? 10,
        capital: signedPct(s.capital_return_pct),
        edge: s.edge_bps ?? '—',
        cost: s.cost_bps,
    };

    return t({
        insufficient: 'aiTrade.backtest.verdictInsufficient',
        losing: 'aiTrade.backtest.verdictLosing',
        negative_expectancy: 'aiTrade.backtest.verdictNegative',
        positive: 'aiTrade.backtest.verdictPositive',
    }[verdictLevel.value] ?? 'aiTrade.backtest.verdictInsufficient', params);
});

const holdText = computed(() => {
    const beats = result.value?.verdict?.beats_hold;
    if (beats === null || beats === undefined || !summary.value) return '';

    return t(beats ? 'aiTrade.backtest.holdBeats' : 'aiTrade.backtest.holdLoses', { hold: signedPct(summary.value.buy_hold_pct) });
});

const blockedBars = computed(() => Number(summary.value?.decisions?.blocked ?? 0));

const macroNote = computed(() => {
    if (!result.value) return '';
    if (result.value.macro_data_missing) return t('aiTrade.backtest.noteMacroMissing');

    return t(result.value.macro_filter_applied ? 'aiTrade.backtest.noteMacroOn' : 'aiTrade.backtest.noteMacroOff');
});

const recentTrades = computed(() => (result.value?.trades ?? []).slice(-6).reverse());

function tradeTime(ms) {
    const date = new Date(Number(ms));

    return Number.isNaN(date.getTime()) ? '' : date.toISOString().slice(0, 16).replace('T', ' ');
}
</script>

<template>
    <div class="rounded-xl border border-white/10 bg-white/5 p-3 space-y-3" data-testid="rule-backtest">
        <div>
            <p class="text-xs font-bold text-white">{{ t('aiTrade.backtest.title') }}</p>
            <p class="text-[10px] text-dark-400 leading-relaxed">{{ t('aiTrade.backtest.intro', { pair: request.pair }) }}</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <label class="block">
                <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.backtest.period') }}</span>
                <select v-model.number="days" class="trading-input text-xs py-1.5 w-auto" data-testid="backtest-days">
                    <option v-for="option in dayOptions" :key="option" :value="option">{{ t('aiTrade.backtest.days', { n: option }) }}</option>
                </select>
            </label>

            <button
                type="button"
                class="btn-secondary px-4 py-1.5 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="isRunning || !!blockedReason"
                data-testid="backtest-run"
                @click="run"
            >
                {{ isRunning ? t('aiTrade.backtest.running') : t('aiTrade.backtest.run') }}
            </button>

            <span class="text-[10px] text-dark-500">{{ t('aiTrade.backtest.maxDaysNote', { tf: request.timeframe, n: maxDays }) }}</span>
        </div>

        <p v-if="blockedReason" class="text-[11px] text-amber-300 leading-relaxed" data-testid="backtest-blocked">
            {{ t('aiTrade.backtest.blocked', { problem: blockedReason }) }}
        </p>

        <p v-if="error" class="text-[11px] text-trading-red leading-relaxed" data-testid="backtest-error">{{ error }}</p>

        <template v-if="result && summary">
            <p v-if="isStale" class="text-[11px] text-amber-300 leading-relaxed" data-testid="backtest-stale">
                {{ t('aiTrade.backtest.stale') }}
            </p>

            <div :class="['space-y-3 transition-opacity', isStale && 'opacity-50']" data-testid="backtest-result">
                <p class="text-[10px] text-dark-500 font-mono">
                    {{ t('aiTrade.backtest.range', { pair: result.pair, tf: result.timeframe, days: summary.days, bars: summary.bars }) }}
                </p>

                <div :class="['rounded-lg border px-3 py-2 text-xs leading-relaxed', verdictTone]" data-testid="backtest-verdict">
                    {{ verdictText }}
                </div>

                <p v-if="holdText" class="text-[11px] text-dark-300 leading-relaxed" data-testid="backtest-hold">{{ holdText }}</p>

                <p v-if="!summary.trades" class="text-[11px] text-amber-300 leading-relaxed" data-testid="backtest-no-trades">
                    {{ t('aiTrade.backtest.noTrades') }}
                </p>

                <dl class="grid gap-2 grid-cols-2 lg:grid-cols-4 text-xs">
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statClosed') }}</dt>
                        <dd class="font-mono text-white" data-testid="backtest-closed">{{ summary.closed }}</dd>
                        <dd v-if="summary.win_rate !== null && summary.win_rate !== undefined" class="text-[10px] text-dark-500">
                            {{ t('aiTrade.backtest.statWinRate', { pct: summary.win_rate }) }}
                        </dd>
                    </div>
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statCapital') }}</dt>
                        <dd
                            :class="['font-mono', Number(summary.capital_return_pct) < 0 ? 'text-trading-red' : 'text-white']"
                            data-testid="backtest-capital"
                        >
                            {{ signedPct(summary.capital_return_pct) }}
                        </dd>
                    </div>
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statHold') }}</dt>
                        <dd class="font-mono text-white">{{ signedPct(summary.buy_hold_pct) }}</dd>
                    </div>
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statEdge') }}</dt>
                        <dd class="font-mono text-white">{{ summary.edge_bps ?? '—' }} bps</dd>
                        <dd class="text-[10px] text-dark-500">{{ t('aiTrade.backtest.statEdgeVsCost', { cost: summary.cost_bps }) }}</dd>
                    </div>
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statRealized') }}</dt>
                        <dd :class="['font-mono', Number(summary.realized_pnl) < 0 ? 'text-trading-red' : 'text-white']">
                            {{ money(summary.realized_pnl) }}
                        </dd>
                        <dd v-if="Number(summary.unrealized_pnl)" class="text-[10px] text-dark-500">
                            {{ t('aiTrade.backtest.statOpen', { value: money(summary.unrealized_pnl) }) }}
                        </dd>
                    </div>
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statDrawdown') }}</dt>
                        <dd class="font-mono text-white">{{ money(-Math.abs(Number(summary.max_drawdown))) }}</dd>
                    </div>
                    <div class="rounded-lg bg-dark-900/40 p-2">
                        <dt class="text-[10px] text-dark-400">{{ t('aiTrade.backtest.statExposure') }}</dt>
                        <dd class="font-mono text-white">{{ summary.exposure_pct }}%</dd>
                    </div>
                </dl>

                <p v-if="blockedBars > 0" class="text-[10px] text-dark-400 leading-relaxed" data-testid="backtest-blocked-bars">
                    {{ t('aiTrade.backtest.blockedBars', { n: blockedBars, total: summary.bars }) }}
                </p>

                <div v-if="recentTrades.length">
                    <p class="text-[10px] text-dark-400 mb-1">{{ t('aiTrade.backtest.recentTrades') }}</p>
                    <ul class="space-y-1">
                        <li
                            v-for="(trade, index) in recentTrades"
                            :key="index"
                            class="flex flex-wrap items-baseline gap-x-2 text-[10px] leading-relaxed"
                            data-testid="backtest-trade"
                        >
                            <span class="font-mono text-dark-500">{{ tradeTime(trade.time) }}</span>
                            <span :class="trade.side === 'buy' ? 'text-trading-green' : 'text-trading-red'">
                                {{ trade.side === 'buy' ? t('aiTrade.backtest.buy') : t('aiTrade.backtest.sell') }}
                            </span>
                            <span v-if="trade.pnl !== null && trade.pnl !== undefined" class="font-mono text-dark-300">{{ money(trade.pnl) }}</span>
                            <span class="text-dark-400 min-w-0">{{ trade.reason }}</span>
                        </li>
                    </ul>
                </div>

                <ul class="space-y-0.5 text-[10px] text-dark-500 leading-relaxed list-disc pl-4" data-testid="backtest-notes">
                    <li>{{ t('aiTrade.backtest.noteRulesOnly') }}</li>
                    <li>{{ macroNote }}</li>
                    <li>{{ t('aiTrade.backtest.noteCloses') }}</li>
                    <li>{{ t('aiTrade.backtest.notePast') }}</li>
                </ul>
            </div>
        </template>
    </div>
</template>
