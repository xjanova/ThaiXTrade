<script setup>
/**
 * TPIX TRADE — พอร์ตทดลองของ AI TRADE.
 *
 * เป็นด่านแรกที่ผู้ใช้เจอก่อนตัดสินใจจ่ายเงินเช่าบอท จึงต้องแสดงผลตรงไปตรงมา:
 * บอกค่าธรรมเนียมกับ slippage ที่ใช้จำลอง และ **แยก** กำไรที่ปิดไม้แล้ว
 * ออกจากกำไร/ขาดทุนของไม้ที่ยังค้างอยู่
 *
 * ⚠️ แยกกันไม่ได้แปลว่าซ่อนอันหลังไว้ — เดิมแสดงเฉพาะไม้ที่ปิดแล้ว โดยให้เหตุผลว่า
 *    "กำไรลอยยังไม่ใช่เงิน" ซึ่งจริงครึ่งเดียว: ไม้ที่กำลังติดลบก็ไม่โผล่เหมือนกัน
 *    ผู้ใช้จึงเห็นแต่ฝั่งที่ปิดสวยๆ ส่วนไม้ที่ค้างขาดทุนถูกตีเท่าทุนไว้ทั้งก้อน
 *    ซึ่งเป็นตัวเลขที่หลอกให้ตัดสินใจจ่ายเงินผิด
 *
 * Developed by Xman Studio.
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';
import PageArt from '@/Components/PageArt.vue';

const props = defineProps({
    demo: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    resetsLeft: { type: Number, default: 0 },
    working: { type: Boolean, default: false },
});

const emit = defineEmits(['reset']);

const { t, locale } = useTranslation();

const account = computed(() => props.demo?.account ?? null);
const summary = computed(() => props.demo?.summary ?? null);
const positions = computed(() => props.demo?.positions ?? []);
const trades = computed(() => props.demo?.trades ?? []);

/** มูลค่าพอร์ตรวม = เงินสด + ของที่ถืออยู่ตีด้วย "ราคาตลาดปัจจุบัน" */
const equity = computed(() => {
    if (!account.value) return 0;

    const fromServer = summary.value?.equity;
    if (fromServer != null) return Number(fromServer);

    const held = positions.value.reduce((sum, p) => sum + Number(p.market_value ?? p.cost_basis ?? 0), 0);

    return Number(account.value.balance || 0) + held;
});

const pnl = computed(() => Number(summary.value?.realized_pnl ?? 0));

/** กำไร/ขาดทุนของไม้ที่ยังไม่ปิด — ตัวเลขที่เคยถูกซ่อนไว้ทั้งหมด */
const unrealized = computed(() => Number(summary.value?.unrealized_pnl ?? 0));

const unrealizedTone = computed(() => {
    if (unrealized.value > 0) return 'text-trading-green';
    if (unrealized.value < 0) return 'text-trading-red';
    return 'text-dark-300';
});

function pnlToneOf(value) {
    const n = Number(value || 0);
    if (n > 0) return 'text-trading-green';
    if (n < 0) return 'text-trading-red';
    return 'text-dark-400';
}

const pnlPct = computed(() => {
    const start = Number(account.value?.starting_balance ?? 0);
    return start > 0 ? (pnl.value / start) * 100 : 0;
});

const pnlTone = computed(() => {
    if (pnl.value > 0) return 'text-trading-green';
    if (pnl.value < 0) return 'text-trading-red';
    return 'text-dark-300';
});

/** ป้ายระดับความเสี่ยงที่ติดมากับแต่ละไม้ */
const riskTone = {
    calm: 'bg-trading-green/10 text-trading-green ring-trading-green/20',
    caution: 'bg-amber-500/10 text-amber-300 ring-amber-500/20',
    elevated: 'bg-orange-500/10 text-orange-300 ring-orange-500/20',
    panic: 'bg-trading-red/10 text-trading-red ring-trading-red/20',
};

const riskLabel = {
    calm: 'aiTrade.riskCalm',
    caution: 'aiTrade.riskCaution',
    elevated: 'aiTrade.riskElevated',
    panic: 'aiTrade.riskPanic',
};

function money(value, digits = 2) {
    return Number(value || 0).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/**
 * เงินที่มีเครื่องหมาย — ต้องได้ "-$420.25" ไม่ใช่ "$-420.25".
 *
 * ตำแหน่งเครื่องหมายลบต้องอยู่หน้าสัญลักษณ์สกุลเงิน ไม่งั้นอ่านผิดได้ง่าย
 * โดยเฉพาะตอนกวาดสายตาดูคอลัมน์กำไรขาดทุนเร็วๆ
 */
function signedMoney(value, digits = 2) {
    const n = Number(value || 0);
    return `${n < 0 ? '-' : '+'}$${money(Math.abs(n), digits)}`;
}

/** ราคาเหรียญถูกๆ ต้องการทศนิยมมากกว่าเหรียญแพง ไม่งั้นกลายเป็น 0.00 หมด */
function price(value) {
    const n = Number(value || 0);
    if (n >= 1000) return money(n, 2);
    if (n >= 1) return money(n, 4);
    return money(n, 8);
}

function amount(value) {
    const n = Number(value || 0);
    return n.toLocaleString('en-US', { maximumFractionDigits: n >= 1 ? 4 : 8 });
}

function when(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function confirmReset() {
    if (window.confirm(t('aiTrade.demoResetConfirm'))) emit('reset');
}
</script>

<template>
    <section class="ai-demo relative overflow-hidden rounded-2xl border border-white/10 bg-dark-900/50">
        <PageArt art="card-aitrade" :opacity="9" fade="edges" rounded="rounded-2xl" />

        <!-- หัวการ์ดแบบ 3D ให้เข้าชุดกับการ์ดในหน้าเทรด -->
        <header class="ai-demo__head relative flex flex-wrap items-center gap-3 px-5 py-3.5">
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-bold text-white leading-tight">
                    {{ t('aiTrade.demoTitle') }}
                    <span class="ml-2 align-middle px-2 py-0.5 rounded-full text-[10px] font-medium ring-1 bg-primary-500/10 text-primary-300 ring-primary-500/20">
                        {{ t('aiTrade.modeDemoShort') }}
                    </span>
                </h2>
                <p class="text-[11px] text-dark-400 mt-0.5">{{ t('aiTrade.demoSubtitle') }}</p>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-[10px] text-dark-500 font-mono">
                    {{ resetsLeft > 0 ? t('aiTrade.demoResetsLeft', { count: resetsLeft }) : t('aiTrade.demoResetNone') }}
                </span>
                <button
                    type="button"
                    class="px-3 py-1 rounded-lg text-[11px] font-medium bg-white/5 text-dark-300 hover:text-white transition-colors disabled:opacity-40"
                    :disabled="working || resetsLeft <= 0"
                    @click="confirmReset"
                >
                    {{ t('aiTrade.demoReset') }}
                </button>
            </div>
        </header>

        <div class="relative p-5 pt-4 space-y-5">
            <!-- ตัวเลขหลัก -->
            <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                    <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.demoBalance') }}</p>
                    <p class="text-lg font-black font-mono text-white mt-1">${{ money(account?.balance) }}</p>
                </div>
                <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                    <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.demoEquity') }}</p>
                    <p class="text-lg font-black font-mono text-white mt-1">${{ money(equity) }}</p>
                    <p class="text-[10px] text-dark-500 font-mono mt-0.5">
                        {{ t('aiTrade.demoStarting') }} ${{ money(account?.starting_balance, 0) }}
                    </p>
                </div>
                <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                    <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.demoPnl') }}</p>
                    <p :class="['text-lg font-black font-mono mt-1', pnlTone]">
                        {{ signedMoney(pnl) }}
                    </p>
                    <p :class="['text-[10px] font-mono mt-0.5', pnlTone]">
                        {{ pnl >= 0 ? '+' : '' }}{{ pnlPct.toFixed(2) }}%
                    </p>

                    <!--
                        ไม้ที่ยังไม่ปิด — แยกบรรทัดไว้ ไม่รวมกับตัวเลขที่ปิดแล้ว

                        รวมกันจะทำให้ "กำไรที่เกิดขึ้นจริง" ปนกับ "กำไรลอยที่ยังไม่ใช่เงิน"
                        แต่ซ่อนไปเลยก็ทำให้ไม้ที่กำลังติดลบหายไปจากสายตาผู้ใช้ทั้งก้อน
                    -->
                    <p v-if="unrealized !== 0" :class="['text-[10px] font-mono mt-1', unrealizedTone]">
                        {{ t('aiTrade.unrealized') }} {{ signedMoney(unrealized) }}
                    </p>
                </div>
                <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                    <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.demoWinRate') }}</p>
                    <p class="text-lg font-black font-mono text-white mt-1">
                        {{ summary?.win_rate === null || summary?.win_rate === undefined ? '—' : summary.win_rate + '%' }}
                    </p>
                    <p class="text-[10px] text-dark-500 font-mono mt-0.5">
                        {{ summary?.wins ?? 0 }}W / {{ summary?.losses ?? 0 }}L
                    </p>
                </div>
            </div>

            <!-- ของที่ถืออยู่ -->
            <div>
                <h3 class="text-xs font-semibold text-dark-300 mb-2">{{ t('aiTrade.demoOpenPositions') }}</h3>

                <div v-if="positions.length" class="space-y-2">
                    <div
                        v-for="position in positions"
                        :key="position.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-white/10 bg-dark-950/40 px-3 py-2"
                    >
                        <span class="text-sm font-semibold text-white font-mono">{{ position.pair }}</span>
                        <span v-if="position.bot_name" class="text-[11px] text-dark-400 truncate">{{ position.bot_name }}</span>
                        <span class="text-[11px] text-dark-400 font-mono">
                            {{ t('aiTrade.tradeAmount') }} {{ amount(position.quantity) }}
                        </span>
                        <span class="text-[11px] text-dark-400 font-mono">
                            {{ t('aiTrade.entryPrice') }} ${{ price(position.entry_price) }}
                        </span>
                        <span class="text-[11px] text-dark-400 font-mono">
                            {{ t('aiTrade.costBasis') }} ${{ money(position.cost_basis) }}
                        </span>

                        <!--
                            ไม้นี้ตอนนี้กำไรหรือขาดทุนอยู่เท่าไหร่ — ถ้าปิดเดี๋ยวนี้
                            เดิมแถวนี้มีแต่ราคาทุน ผู้ใช้จึงไม่มีทางรู้เลยว่าไม้ที่ค้างอยู่ติดลบ
                        -->
                        <span v-if="position.priced" class="text-[11px] font-mono" :class="pnlToneOf(position.unrealized_pnl)">
                            {{ t('aiTrade.unrealized') }} {{ signedMoney(position.unrealized_pnl) }}
                            ({{ position.unrealized_pct >= 0 ? '+' : '' }}{{ position.unrealized_pct }}%)
                        </span>
                        <span v-else class="text-[11px] text-dark-500 font-mono">
                            {{ t('aiTrade.unpriced') }}
                        </span>
                        <span v-if="position.entry_count > 1" class="text-[11px] text-primary-300 font-mono">
                            {{ t('aiTrade.entryCount') }} ×{{ position.entry_count }}
                        </span>
                    </div>
                </div>

                <p v-else class="rounded-xl border border-white/5 bg-dark-950/30 px-3 py-4 text-center text-[11px] text-dark-500">
                    {{ t('aiTrade.demoNoPositions') }}
                </p>
            </div>

            <!-- ประวัติไม้ พร้อมเหตุผลที่บอทตัดสินใจ -->
            <div>
                <h3 class="text-xs font-semibold text-dark-300 mb-2">{{ t('aiTrade.tradeLog') }}</h3>

                <div v-if="trades.length" class="overflow-x-auto rounded-xl border border-white/10">
                    <table class="w-full text-left border-collapse min-w-[640px]">
                        <thead>
                            <tr class="bg-dark-950/60 text-[10px] uppercase tracking-wide text-dark-500">
                                <th class="px-3 py-2 font-medium">{{ t('aiTrade.tradeTime') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('aiTrade.pair') }}</th>
                                <th class="px-3 py-2 font-medium text-right">{{ t('aiTrade.tradePrice') }}</th>
                                <th class="px-3 py-2 font-medium text-right">{{ t('aiTrade.tradeAmount') }}</th>
                                <th class="px-3 py-2 font-medium text-right">{{ t('aiTrade.tradePnl') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('aiTrade.tradeReason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="trade in trades"
                                :key="trade.id"
                                class="border-t border-white/5 text-[11px] hover:bg-white/[0.02] transition-colors"
                            >
                                <td class="px-3 py-2 text-dark-400 font-mono whitespace-nowrap">{{ when(trade.created_at) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span :class="['font-semibold', trade.side === 'buy' ? 'text-trading-green' : 'text-trading-red']">
                                        {{ trade.side === 'buy' ? t('aiTrade.tradeBuy') : t('aiTrade.tradeSell') }}
                                    </span>
                                    <span class="text-dark-300 font-mono ml-1.5">{{ trade.pair }}</span>
                                </td>
                                <td class="px-3 py-2 text-right text-dark-200 font-mono whitespace-nowrap">${{ price(trade.price) }}</td>
                                <td class="px-3 py-2 text-right text-dark-300 font-mono whitespace-nowrap">{{ amount(trade.quantity) }}</td>
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                    <span
                                        v-if="trade.realized_pnl !== null"
                                        :class="trade.realized_pnl >= 0 ? 'text-trading-green' : 'text-trading-red'"
                                    >
                                        {{ signedMoney(trade.realized_pnl) }}
                                    </span>
                                    <span v-else class="text-dark-600">—</span>
                                </td>
                                <td class="px-3 py-2 text-dark-400">
                                    <span
                                        v-if="trade.risk_level && trade.risk_level !== 'calm'"
                                        :class="['mr-1.5 px-1.5 py-0.5 rounded text-[9px] font-medium ring-1', riskTone[trade.risk_level]]"
                                    >
                                        {{ t(riskLabel[trade.risk_level]) }}
                                    </span>
                                    {{ trade.reason }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="rounded-xl border border-white/5 bg-dark-950/30 px-3 py-6 text-center text-[11px] text-dark-500">
                    {{ loading ? '…' : t('aiTrade.demoStartHint') }}
                </p>
            </div>

            <!-- เปิดเผยสมมติฐานให้เห็นชัด ไม่ซ่อนไว้ในเอกสาร -->
            <p v-if="account" class="text-[10px] leading-relaxed text-dark-500 border-t border-white/5 pt-3">
                {{ t('aiTrade.demoAssumptions', { fee: account.fee_rate, slippage: account.slippage_bps }) }}
            </p>
        </div>
    </section>
</template>

<style scoped>
/* หัวการ์ดแบบเดียวกับการ์ดในหน้าเทรด — ไล่เฉดบางๆ + ขอบสว่างด้านบนให้ดูนูน */
.ai-demo__head {
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.01) 100%),
        repeating-linear-gradient(115deg, rgba(255, 255, 255, 0.03) 0 2px, transparent 2px 7px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.09);
}

.ai-demo {
    box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.04) inset,
        0 18px 40px -28px rgba(0, 0, 0, 0.9);
}
</style>
