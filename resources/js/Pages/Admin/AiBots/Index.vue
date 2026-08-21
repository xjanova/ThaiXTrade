<script setup>
/**
 * TPIX TRADE — ศูนย์เฝ้าดูบอทเทรด (หลังบ้าน)
 *
 * เจ้าของสั่ง: ทีมงานต้องเห็นการวางไม้และการทำงานของบอททุกตัว ทั้งบนคลาวด์และ
 * บนหน้าเว็บแบบฟรี · เห็นเงินทุนกับการทำกำไร · และสั่งหยุดหรือระงับบอทได้
 *
 * กราฟทั้งหมดวาดเองด้วย SVG/div ตามแบบของ Admin/Dashboard.vue — ไม่ดึงไลบรารีกราฟ
 * เข้ามาเพิ่มเพราะหน้านี้ต้องเปิดเร็วและถูกรีเฟรชบ่อยระหว่างเฝ้าดู
 *
 * Developed by Xman Studio.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    mode: { type: String, default: 'demo' },
    summary: { type: Object, required: true },
    hourly: { type: Array, default: () => [] },
    strategies: { type: Array, default: () => [] },
    bots: { type: Array, default: () => [] },
    trades: { type: Array, default: () => [] },
    decisions: { type: Array, default: () => [] },
});

// ─────────────────────────────────────────── ตัวช่วยแสดงผล

const money = (n, digits = 2) =>
    Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });

const compact = (n) => {
    const v = Number(n ?? 0);
    if (Math.abs(v) >= 1_000_000) return (v / 1_000_000).toFixed(2) + 'M';
    if (Math.abs(v) >= 1_000) return (v / 1_000).toFixed(1) + 'K';
    return v.toLocaleString('en-US', { maximumFractionDigits: 2 });
};

const signed = (n) => (Number(n ?? 0) >= 0 ? '+' : '') + money(n);
const pnlClass = (n) => (Number(n ?? 0) >= 0 ? 'text-trading-green' : 'text-trading-red');
const shortWallet = (a) => (a ? a.slice(0, 6) + '…' + a.slice(-4) : '—');

const ago = (seconds) => {
    if (seconds === null || seconds === undefined) return 'ยังไม่เคยเดิน';
    if (seconds < 60) return `${Math.floor(seconds)} วินาทีที่แล้ว`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} นาทีที่แล้ว`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} ชั่วโมงที่แล้ว`;
    return `${Math.floor(seconds / 86400)} วันที่แล้ว`;
};

const timeOf = (iso) => (iso ? new Date(iso).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—');

/*
 * ไฟสถานะการเต้นของบอท — เกณฑ์ต่างกันตามวิธีเดิน
 *
 * บอทคลาวด์ควรเดินทุกนาที เงียบเกิน 5 นาทีคือผิดปกติจริง
 * ส่วนบอทของแพลนฟรีเดินเฉพาะตอนเจ้าของเปิดหน้าเว็บค้างไว้ — เงียบเป็นเรื่องปกติ
 * เอาเกณฑ์เดียวมาตัดสินทั้งสองแบบจะได้ไฟแดงเต็มหน้าจนไม่มีใครดู
 */
const heartbeat = (bot) => {
    if (bot.banned) return { dot: 'bg-red-500', text: 'text-red-400', label: 'ถูกระงับ' };
    if (bot.status !== 'running') return { dot: 'bg-gray-500', text: 'text-gray-400', label: 'ไม่ได้เดิน' };
    if (bot.silent_seconds === null) return { dot: 'bg-amber-400', text: 'text-amber-400', label: 'ยังไม่เคยเดิน' };

    const limit = bot.execution === 'cloud' ? 300 : 3600;
    if (bot.silent_seconds <= limit) return { dot: 'bg-trading-green', text: 'text-trading-green', label: 'ปกติ' };
    return { dot: 'bg-red-500', text: 'text-red-400', label: 'เงียบผิดปกติ' };
};

const actionChip = (action) => ({
    buy: 'bg-trading-green/15 text-trading-green border-trading-green/30',
    sell: 'bg-trading-red/15 text-trading-red border-trading-red/30',
    hold: 'bg-white/5 text-gray-400 border-white/10',
    error: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
    stopped: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
}[action] || 'bg-white/5 text-gray-400 border-white/10');

const actionLabel = (action) => ({ buy: 'ซื้อ', sell: 'ขาย', hold: 'ถือ', error: 'ผิดพลาด', stopped: 'หยุด' }[action] || action);

const riskChip = (level) => ({
    calm: 'text-trading-green',
    elevated: 'text-amber-400',
    high: 'text-orange-400',
    extreme: 'text-trading-red',
}[level] || 'text-gray-400');

// ─────────────────────────────────────────── โดนัทสัดส่วนการตัดสินใจ

const donut = computed(() => {
    const slices = [
        { key: 'buy', label: 'ซื้อ', value: props.summary.buys_24h, color: '#00C853' },
        { key: 'sell', label: 'ขาย', value: props.summary.sells_24h, color: '#FF1744' },
        { key: 'hold', label: 'ถือ', value: props.summary.holds_24h, color: '#64748b' },
        { key: 'error', label: 'ผิดพลาด', value: props.summary.errors_24h, color: '#f59e0b' },
    ].filter((s) => s.value > 0);

    const total = slices.reduce((a, s) => a + s.value, 0);
    if (!total) return { slices: [], total: 0 };

    const circumference = 2 * Math.PI * 40;
    let offset = 0;

    return {
        total,
        slices: slices.map((s) => {
            const length = (s.value / total) * circumference;
            const seg = { ...s, pct: Math.round((s.value / total) * 100), dashArray: `${length} ${circumference - length}`, dashOffset: -offset };
            offset += length;
            return seg;
        }),
    };
});

const maxHourly = computed(() => Math.max(1, ...props.hourly.map((h) => h.total)));

/*
 * เส้นกำไรสะสมของกลยุทธ์ — ทำเป็น path ของ SVG
 *
 * คืนค่าว่างเมื่อยังไม่มีไม้ปิด เพื่อให้เทมเพลตขึ้นข้อความ "ยังไม่มีไม้ปิด"
 * แทนที่จะวาดเส้นแบนๆ ที่อ่านเหมือนผลลัพธ์เท่าทุน ทั้งที่ยังไม่มีผลอะไรเลย
 */
const curvePath = (curve) => {
    if (!curve || curve.length < 2) return '';
    const min = Math.min(...curve);
    const max = Math.max(...curve);
    const span = max - min || 1;
    const step = 100 / (curve.length - 1);

    return curve
        .map((v, i) => `${i === 0 ? 'M' : 'L'} ${(i * step).toFixed(2)} ${(28 - ((v - min) / span) * 26).toFixed(2)}`)
        .join(' ');
};

// ─────────────────────────────────────────── ตัวกรอง + การสั่งการ

const strategyFilter = ref('all');
const executionFilter = ref('all');

const filteredBots = computed(() =>
    props.bots.filter(
        (b) =>
            (strategyFilter.value === 'all' || b.strategy === strategyFilter.value) &&
            (executionFilter.value === 'all' || b.execution === executionFilter.value),
    ),
);

const busy = ref(null);
const banTarget = ref(null);
const banReason = ref('');

const act = (bot, action) => {
    busy.value = bot.id;
    router.post(`/admin/ai-bots/${bot.id}/${action}`, {}, {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
};

const openBan = (bot) => {
    banTarget.value = bot;
    banReason.value = '';
};

const submitBan = () => {
    if (!banReason.value.trim()) return;
    const bot = banTarget.value;
    busy.value = bot.id;
    router.post(`/admin/ai-bots/${bot.id}/ban`, { reason: banReason.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => (banTarget.value = null),
        onFinish: () => (busy.value = null),
    });
};

const switchMode = (mode) => router.get('/admin/ai-bots', { mode }, { preserveScroll: true, preserveState: false });
</script>

<template>
    <Head title="ศูนย์เฝ้าดูบอทเทรด" />

    <AdminLayout title="ศูนย์เฝ้าดูบอทเทรด">
        <!-- ───────────── หัวเรื่อง + สลับโหมด ───────────── -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
                <h2 class="text-xl font-bold text-white">บอทเทรดทั้งหมดในระบบ</h2>
                <p class="text-sm text-gray-400 mt-0.5">
                    ทั้งบอทที่เดินบนคลาวด์และบอทของแพลนฟรีที่เดินในเบราว์เซอร์ของผู้ใช้
                </p>
            </div>

            <div class="flex items-center gap-2">
                <div class="flex bg-dark-800/60 rounded-xl p-1 border border-white/10">
                    <button
                        v-for="m in [{ key: 'demo', label: 'โหมดทดลอง' }, { key: 'live', label: 'เงินจริง' }]"
                        :key="m.key"
                        @click="switchMode(m.key)"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                        :class="mode === m.key ? 'bg-primary-500/20 text-primary-300' : 'text-gray-400 hover:text-white'"
                    >{{ m.label }}</button>
                </div>
                <button
                    @click="router.reload({ preserveScroll: true })"
                    class="px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-gray-300 hover:text-white hover:bg-white/10 transition-all"
                    title="ดึงข้อมูลใหม่"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- ───────────── แถบตัวเลขสำคัญ ───────────── -->
        <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3 mb-6">
            <div class="bg-gradient-to-br from-primary-500/10 to-white/[0.02] backdrop-blur-xl border border-primary-500/20 rounded-2xl p-4">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">เงินทุนตั้งต้น</p>
                <p class="text-2xl font-bold text-white tabular-nums">${{ compact(summary.capital) }}</p>
                <p class="text-[11px] text-gray-500 mt-1">พอร์ตทดลองรวมทุกกลยุทธ์</p>
            </div>

            <div class="bg-gradient-to-br from-white/5 to-white/[0.02] backdrop-blur-xl border border-white/10 rounded-2xl p-4">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">มูลค่าตอนนี้</p>
                <p class="text-2xl font-bold text-white tabular-nums">${{ compact(summary.equity) }}</p>
                <p class="text-[11px] mt-1 tabular-nums" :class="pnlClass(summary.net_pnl)">
                    {{ signed(summary.net_pnl) }} ({{ signed(summary.net_pnl_pct) }}%)
                </p>
            </div>

            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">กำไรที่ปิดแล้ว</p>
                <p class="text-2xl font-bold tabular-nums" :class="pnlClass(summary.realized_pnl)">
                    {{ signed(summary.realized_pnl) }}
                </p>
                <p class="text-[11px] text-gray-500 mt-1">
                    ปิด {{ summary.closed_trades }} ไม้
                    <span v-if="summary.win_rate !== null"> · ชนะ {{ summary.win_rate }}%</span>
                </p>
            </div>

            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">ไม้ที่ถืออยู่</p>
                <p class="text-2xl font-bold text-white tabular-nums">{{ summary.open_positions }}</p>
                <p class="text-[11px] mt-1 tabular-nums" :class="pnlClass(summary.unrealized_pnl)">
                    มูลค่า ${{ compact(summary.open_value) }} · {{ signed(summary.unrealized_pnl) }}
                </p>
            </div>

            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">บอทที่เดินอยู่</p>
                <p class="text-2xl font-bold text-white tabular-nums">
                    {{ summary.bots_running }}<span class="text-base text-gray-500">/{{ summary.bots_total }}</span>
                </p>
                <p class="text-[11px] text-gray-500 mt-1">
                    คลาวด์ {{ summary.cloud }} · เบราว์เซอร์ {{ summary.browser }} · {{ summary.wallets }} กระเป๋า
                </p>
            </div>

            <div class="backdrop-blur-xl border rounded-2xl p-4"
                :class="summary.bots_banned > 0 ? 'bg-red-500/10 border-red-500/25' : 'bg-white/5 border-white/10'">
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">ถูกระงับ</p>
                <p class="text-2xl font-bold tabular-nums" :class="summary.bots_banned > 0 ? 'text-red-400' : 'text-white'">
                    {{ summary.bots_banned }}
                </p>
                <p class="text-[11px] text-gray-500 mt-1">รอบคิด 24 ชม. {{ compact(summary.ticks_24h) }}</p>
            </div>
        </div>

        <!-- ───────────── กราฟ: สัดส่วนการตัดสินใจ + รายชั่วโมง ───────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
            <!-- โดนัท -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                <p class="text-sm font-medium text-white mb-1">การตัดสินใจ 24 ชั่วโมง</p>
                <p class="text-[11px] text-gray-500 mb-4">นับทุกครั้งที่บอทคิด ไม่ใช่เฉพาะตอนลงมือ</p>

                <div class="flex items-center justify-center mb-4">
                    <div class="relative w-36 h-36">
                        <svg viewBox="0 0 100 100" class="w-full h-full -rotate-90">
                            <circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="12" />
                            <circle
                                v-for="seg in donut.slices"
                                :key="seg.key"
                                cx="50" cy="50" r="40"
                                fill="none"
                                :stroke="seg.color"
                                stroke-width="12"
                                :stroke-dasharray="seg.dashArray"
                                :stroke-dashoffset="seg.dashOffset"
                                class="transition-all duration-700"
                            />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-[11px] text-gray-400">รอบคิด</span>
                            <span class="text-2xl font-bold text-white tabular-nums">{{ compact(donut.total) }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <div v-for="seg in donut.slices" :key="seg.key" class="flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: seg.color }"></span>
                            <span class="text-gray-300">{{ seg.label }}</span>
                        </div>
                        <span class="text-white font-mono text-xs tabular-nums">{{ seg.value }} ({{ seg.pct }}%)</span>
                    </div>
                    <p v-if="!donut.slices.length" class="text-center py-6 text-gray-500 text-sm">
                        ยังไม่มีการตัดสินใจใน 24 ชั่วโมงที่ผ่านมา
                    </p>
                </div>
            </div>

            <!-- แท่งซ้อนรายชั่วโมง -->
            <div class="lg:col-span-2 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-medium text-white">จังหวะการทำงานรายชั่วโมง</p>
                    <div class="flex items-center gap-3 text-[11px]">
                        <span class="flex items-center gap-1.5 text-gray-400"><span class="w-2.5 h-2.5 rounded-sm bg-trading-green/80"></span>ซื้อ</span>
                        <span class="flex items-center gap-1.5 text-gray-400"><span class="w-2.5 h-2.5 rounded-sm bg-trading-red/80"></span>ขาย</span>
                        <span class="flex items-center gap-1.5 text-gray-400"><span class="w-2.5 h-2.5 rounded-sm bg-slate-500/70"></span>ถือ</span>
                        <span class="flex items-center gap-1.5 text-gray-400"><span class="w-2.5 h-2.5 rounded-sm bg-amber-500/80"></span>ผิดพลาด</span>
                    </div>
                </div>
                <p class="text-[11px] text-gray-500 mb-4">ช่องที่ว่างคือชั่วโมงที่ไม่มีบอทตัวไหนคิดเลย</p>

                <div class="relative h-52">
                    <div class="absolute left-0 top-0 bottom-6 w-10 flex flex-col justify-between text-right pr-2">
                        <span class="text-[10px] text-gray-500">{{ maxHourly }}</span>
                        <span class="text-[10px] text-gray-500">{{ Math.round(maxHourly / 2) }}</span>
                        <span class="text-[10px] text-gray-500">0</span>
                    </div>

                    <div class="absolute left-10 right-0 top-0 bottom-6">
                        <div class="absolute top-0 left-0 right-0 border-t border-white/5"></div>
                        <div class="absolute top-1/2 left-0 right-0 border-t border-white/5"></div>
                        <div class="absolute bottom-0 left-0 right-0 border-t border-white/10"></div>
                    </div>

                    <div class="absolute left-10 right-0 top-0 bottom-6 flex items-end gap-[3px]">
                        <div v-for="h in hourly" :key="h.label" class="flex-1 flex flex-col justify-end group relative min-w-[4px] h-full">
                            <div
                                class="w-full flex flex-col-reverse rounded-t overflow-hidden transition-all duration-500"
                                :style="{ height: (h.total / maxHourly) * 100 + '%' }"
                            >
                                <div v-if="h.hold" class="w-full bg-slate-500/70" :style="{ height: (h.hold / h.total) * 100 + '%', minHeight: '1px' }"></div>
                                <div v-if="h.buy" class="w-full bg-trading-green/80" :style="{ height: (h.buy / h.total) * 100 + '%', minHeight: '2px' }"></div>
                                <div v-if="h.sell" class="w-full bg-trading-red/80" :style="{ height: (h.sell / h.total) * 100 + '%', minHeight: '2px' }"></div>
                                <div v-if="h.error" class="w-full bg-amber-500/80" :style="{ height: (h.error / h.total) * 100 + '%', minHeight: '2px' }"></div>
                            </div>

                            <div class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 hidden group-hover:block z-20 pointer-events-none">
                                <div class="bg-dark-800 border border-white/10 rounded-lg p-2.5 text-xs whitespace-nowrap shadow-xl">
                                    <p class="text-white font-medium mb-1">{{ h.label }} น.</p>
                                    <p class="text-trading-green">ซื้อ {{ h.buy }}</p>
                                    <p class="text-trading-red">ขาย {{ h.sell }}</p>
                                    <p class="text-gray-400">ถือ {{ h.hold }}</p>
                                    <p v-if="h.error" class="text-amber-400">ผิดพลาด {{ h.error }}</p>
                                    <p class="text-white font-semibold border-t border-white/10 pt-1 mt-1">รวม {{ h.total }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="absolute left-10 right-0 bottom-0 flex justify-between">
                        <span class="text-[10px] text-gray-500">{{ hourly[0]?.label }}</span>
                        <span class="text-[10px] text-gray-500">{{ hourly[Math.floor(hourly.length / 2)]?.label }}</span>
                        <span class="text-[10px] text-gray-500">{{ hourly[hourly.length - 1]?.label }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ───────────── การ์ดผลงานรายกลยุทธ์ ───────────── -->
        <div class="mb-6">
            <div class="flex items-baseline gap-3 mb-3">
                <h3 class="text-lg font-semibold text-white">ผลงานรายกลยุทธ์</h3>
                <p class="text-[11px] text-gray-500">พอร์ตทดลองแยกกันคนละก้อน จึงเทียบกันได้ตรงๆ</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                <div
                    v-for="s in strategies"
                    :key="s.code"
                    class="bg-white/5 backdrop-blur-xl border rounded-2xl p-4 transition-all hover:bg-white/[0.07]"
                    :class="s.bots > 0 ? 'border-white/10' : 'border-white/5 opacity-60'"
                >
                    <div class="flex items-start justify-between mb-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white truncate">{{ s.name_th }}</p>
                            <p class="text-[11px] text-gray-500 font-mono">{{ s.code }}</p>
                        </div>
                        <span class="shrink-0 px-2 py-0.5 rounded-md text-[10px] font-medium border"
                            :class="s.running > 0 ? 'bg-trading-green/10 text-trading-green border-trading-green/25' : 'bg-white/5 text-gray-500 border-white/10'">
                            {{ s.running }}/{{ s.bots }} เดิน
                        </span>
                    </div>

                    <!-- แถบสัดส่วนการตัดสินใจ -->
                    <div class="h-1.5 rounded-full overflow-hidden bg-white/5 flex mb-3">
                        <div v-if="s.buy" class="bg-trading-green" :style="{ width: (s.buy / s.ticks) * 100 + '%' }"></div>
                        <div v-if="s.sell" class="bg-trading-red" :style="{ width: (s.sell / s.ticks) * 100 + '%' }"></div>
                        <div v-if="s.hold" class="bg-slate-500/70" :style="{ width: (s.hold / s.ticks) * 100 + '%' }"></div>
                    </div>

                    <!-- เส้นกำไรสะสม -->
                    <div class="h-8 mb-3">
                        <svg v-if="s.curve.length > 1" viewBox="0 0 100 30" preserveAspectRatio="none" class="w-full h-full">
                            <path
                                :d="curvePath(s.curve)"
                                fill="none"
                                :stroke="s.realized_pnl >= 0 ? '#00C853' : '#FF1744'"
                                stroke-width="1.5"
                                vector-effect="non-scaling-stroke"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div v-else class="h-full flex items-center justify-center text-[10px] text-gray-600 border border-dashed border-white/5 rounded-lg">
                            ยังไม่มีไม้ที่ปิดแล้ว
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-[11px]">
                        <div>
                            <p class="text-gray-500">เงินทุน</p>
                            <p class="text-white font-mono tabular-nums">${{ compact(s.capital) }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">มูลค่าตอนนี้</p>
                            <p class="font-mono tabular-nums" :class="pnlClass(s.equity - s.capital)">
                                ${{ compact(s.equity) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-gray-500">ส่วนต่าง</p>
                            <p class="font-mono tabular-nums" :class="pnlClass(s.pnl_pct)">{{ signed(s.pnl_pct) }}%</p>
                        </div>
                        <div>
                            <p class="text-gray-500">ปิดไม้ / ชนะ</p>
                            <p class="text-white font-mono tabular-nums">
                                {{ s.closed }}<span v-if="s.win_rate !== null" class="text-gray-500"> · {{ s.win_rate }}%</span>
                            </p>
                        </div>
                    </div>

                    <p v-if="s.top_hold_reason" class="mt-3 pt-2 border-t border-white/5 text-[10px] text-gray-500 line-clamp-2">
                        ถือบ่อยสุดเพราะ: {{ s.top_hold_reason }}
                    </p>
                </div>
            </div>
        </div>

        <!-- ───────────── ตารางบอททุกตัว ───────────── -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-white/10">
                <h3 class="text-lg font-semibold text-white">บอททุกตัว ({{ filteredBots.length }})</h3>

                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="executionFilter" class="bg-dark-800/60 border border-white/10 rounded-lg px-2.5 py-1.5 text-xs text-gray-200">
                        <option value="all">ทุกวิธีเดิน</option>
                        <option value="cloud">คลาวด์</option>
                        <option value="browser">เบราว์เซอร์ (ฟรี)</option>
                    </select>
                    <select v-model="strategyFilter" class="bg-dark-800/60 border border-white/10 rounded-lg px-2.5 py-1.5 text-xs text-gray-200">
                        <option value="all">ทุกกลยุทธ์</option>
                        <option v-for="s in strategies" :key="s.code" :value="s.code">{{ s.name_th }}</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-white/[0.03] text-[11px] uppercase tracking-wider text-gray-400">
                        <tr>
                            <th class="text-left font-medium px-4 py-3">บอท</th>
                            <th class="text-left font-medium px-4 py-3">เจ้าของ</th>
                            <th class="text-left font-medium px-4 py-3">วิธีเดิน</th>
                            <th class="text-left font-medium px-4 py-3">สถานะ</th>
                            <th class="text-right font-medium px-4 py-3">ไม้ที่ถือ</th>
                            <th class="text-left font-medium px-4 py-3">คิดล่าสุด</th>
                            <th class="text-right font-medium px-4 py-3">สั่งการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="bot in filteredBots" :key="bot.id" class="hover:bg-white/[0.03] transition-colors">
                            <td class="px-4 py-3">
                                <p class="text-white font-medium">{{ bot.name }}</p>
                                <p class="text-[11px] text-gray-500 font-mono">
                                    #{{ bot.id }} · {{ bot.pair }} · {{ bot.timeframe }} · {{ bot.strategy }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-gray-300 font-mono text-xs">{{ shortWallet(bot.wallet) }}</p>
                                <p class="text-[11px] text-gray-500">{{ bot.plan_name_th || bot.plan || '—' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-medium border"
                                    :class="bot.execution === 'cloud'
                                        ? 'bg-primary-500/10 text-primary-300 border-primary-500/25'
                                        : 'bg-amber-500/10 text-amber-300 border-amber-500/25'">
                                    {{ bot.execution === 'cloud' ? 'คลาวด์' : 'เบราว์เซอร์' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full" :class="heartbeat(bot).dot"></span>
                                    <span class="text-xs" :class="heartbeat(bot).text">{{ heartbeat(bot).label }}</span>
                                </div>
                                <p class="text-[11px] text-gray-500">{{ ago(bot.silent_seconds) }}</p>
                                <p v-if="bot.banned" class="text-[10px] text-red-400 mt-0.5 max-w-[200px]">{{ bot.banned_reason }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <template v-if="bot.position">
                                    <p class="text-white font-mono text-xs tabular-nums">${{ money(bot.position.value) }}</p>
                                    <p class="text-[11px] font-mono tabular-nums" :class="pnlClass(bot.position.pnl)">
                                        {{ signed(bot.position.pnl) }} ({{ signed(bot.position.pnl_pct) }}%)
                                    </p>
                                </template>
                                <span v-else class="text-gray-600 text-xs">ไม่ได้ถือ</span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-gray-300 text-xs max-w-[220px] truncate" :title="bot.last_reason">
                                    {{ bot.last_reason || '—' }}
                                </p>
                                <p class="text-[11px] text-gray-500">รอบคิด 24 ชม. {{ bot.ticks_24h }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        v-if="bot.status === 'running' && !bot.banned"
                                        @click="act(bot, 'pause')"
                                        :disabled="busy === bot.id"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-amber-500/10 text-amber-300 border border-amber-500/25 hover:bg-amber-500/20 disabled:opacity-40 transition-all"
                                    >หยุด</button>

                                    <button
                                        v-else-if="!bot.banned"
                                        @click="act(bot, 'resume')"
                                        :disabled="busy === bot.id"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-trading-green/10 text-trading-green border border-trading-green/25 hover:bg-trading-green/20 disabled:opacity-40 transition-all"
                                    >เดินต่อ</button>

                                    <button
                                        v-if="!bot.banned"
                                        @click="openBan(bot)"
                                        :disabled="busy === bot.id"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500/20 disabled:opacity-40 transition-all"
                                    >ระงับ</button>

                                    <button
                                        v-else
                                        @click="act(bot, 'unban')"
                                        :disabled="busy === bot.id"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-white/5 text-gray-300 border border-white/15 hover:bg-white/10 disabled:opacity-40 transition-all"
                                    >ปลดระงับ</button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="!filteredBots.length">
                            <td colspan="7" class="px-4 py-10 text-center text-gray-500 text-sm">
                                ยังไม่มีบอทที่ตรงกับตัวกรองนี้
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ───────────── ฟีดการวางไม้ + การตัดสินใจ ───────────── -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <!-- การวางไม้ -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                <div class="px-4 py-3 border-b border-white/10">
                    <h3 class="text-base font-semibold text-white">การวางไม้ล่าสุด</h3>
                    <p class="text-[11px] text-gray-500">ทุกไม้ที่บอทเปิดและปิดจริง</p>
                </div>

                <div class="max-h-[26rem] overflow-y-auto divide-y divide-white/5">
                    <div v-for="t in trades" :key="t.id" class="px-4 py-2.5 hover:bg-white/[0.03] transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold border shrink-0"
                                    :class="t.side === 'buy' ? 'bg-trading-green/15 text-trading-green border-trading-green/30' : 'bg-trading-red/15 text-trading-red border-trading-red/30'">
                                    {{ t.side === 'buy' ? 'ซื้อ' : 'ขาย' }}
                                </span>
                                <span class="text-white text-xs font-medium truncate">{{ t.pair }}</span>
                                <span class="text-[11px] text-gray-500 font-mono shrink-0">{{ t.strategy }}</span>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-white text-xs font-mono tabular-nums">${{ money(t.value) }}</p>
                                <p v-if="t.realized_pnl !== null" class="text-[11px] font-mono tabular-nums" :class="pnlClass(t.realized_pnl)">
                                    {{ signed(t.realized_pnl) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-1">
                            <p class="text-[11px] text-gray-500 truncate">{{ t.reason }}</p>
                            <p class="text-[10px] text-gray-600 font-mono shrink-0">
                                #{{ t.bot_id }} · {{ timeOf(t.at) }}
                            </p>
                        </div>
                    </div>

                    <p v-if="!trades.length" class="px-4 py-10 text-center text-gray-500 text-sm">
                        ยังไม่มีไม้ที่วางเลย
                    </p>
                </div>
            </div>

            <!-- การตัดสินใจ -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                <div class="px-4 py-3 border-b border-white/10">
                    <h3 class="text-base font-semibold text-white">การตัดสินใจล่าสุด</h3>
                    <p class="text-[11px] text-gray-500">รวมรอบที่ตัดสินใจ "ไม่ทำอะไร" ด้วย พร้อมเหตุผล</p>
                </div>

                <div class="max-h-[26rem] overflow-y-auto divide-y divide-white/5">
                    <div v-for="d in decisions" :key="d.id" class="px-4 py-2.5 hover:bg-white/[0.03] transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold border shrink-0" :class="actionChip(d.action)">
                                    {{ actionLabel(d.action) }}
                                </span>
                                <span class="text-gray-300 text-xs font-mono truncate">{{ d.strategy }}</span>
                                <span class="text-[10px] shrink-0" :class="riskChip(d.risk_level)">● {{ d.risk_level }}</span>
                            </div>
                            <p class="text-[10px] text-gray-600 font-mono shrink-0">{{ timeOf(d.at) }}</p>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">{{ d.reason }}</p>
                        <p v-if="d.budget" class="text-[10px] text-gray-600 font-mono mt-0.5">
                            งบไม้นี้ ${{ money(d.budget) }} · ราคา {{ money(d.price, 2) }}
                        </p>
                    </div>

                    <p v-if="!decisions.length" class="px-4 py-10 text-center text-gray-500 text-sm">
                        ยังไม่มีการตัดสินใจ
                    </p>
                </div>
            </div>
        </div>

        <!-- ───────────── กล่องระบุเหตุผลก่อนระงับ ───────────── -->
        <div v-if="banTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="banTarget = null">
            <div class="w-full max-w-md bg-dark-800 border border-red-500/25 rounded-2xl p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-white mb-1">ระงับบอท #{{ banTarget.id }}</h3>
                <p class="text-sm text-gray-400 mb-4">
                    {{ banTarget.name }} · {{ shortWallet(banTarget.wallet) }}
                </p>

                <p class="text-xs text-gray-400 mb-4 p-3 rounded-xl bg-white/5 border border-white/10">
                    บอทจะหยุดทันทีทั้งฝั่งคลาวด์และฝั่งเบราว์เซอร์ และ<span class="text-white font-medium">เจ้าของกดเริ่มใหม่เองไม่ได้</span>
                    จนกว่าทีมงานจะปลดระงับ — เหตุผลที่กรอกจะแสดงให้เจ้าของเห็น
                </p>

                <label class="block text-xs font-medium text-gray-300 mb-1.5">เหตุผล (บังคับกรอก)</label>
                <input
                    v-model="banReason"
                    type="text"
                    maxlength="255"
                    placeholder="เช่น ตั้งค่าเสี่ยงเกินเพดาน / ต้องสงสัยว่าปั่นราคา"
                    class="w-full bg-dark-900/60 border border-white/10 rounded-xl px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-red-500/50"
                    @keyup.enter="submitBan"
                />

                <div class="flex justify-end gap-2 mt-5">
                    <button @click="banTarget = null" class="px-4 py-2 rounded-xl text-sm text-gray-300 bg-white/5 border border-white/10 hover:bg-white/10 transition-all">
                        ยกเลิก
                    </button>
                    <button
                        @click="submitBan"
                        :disabled="!banReason.trim() || busy === banTarget.id"
                        class="px-4 py-2 rounded-xl text-sm font-medium text-white bg-red-500/80 hover:bg-red-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all"
                    >
                        ระงับบอทตัวนี้
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
