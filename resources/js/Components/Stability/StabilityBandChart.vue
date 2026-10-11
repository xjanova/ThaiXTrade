<script setup>
/**
 * TPIX TRADE — กราฟกรอบราคาของกองทุนพยุงราคา (SVG ล้วน ไม่ลากไลบรารีกราฟเข้ามา)
 *
 * วาดจากจุดรายชั่วโมงที่ API ส่งมา (ค่าสุดท้ายของบันทึกการตัดสินใจในแต่ละชั่วโมง)
 * แถบสี = กรอบราคา · เส้นประ = ราคาอ้างอิง · เส้นทึบ = ราคาพูลจริง
 * จุดที่ค่าหายจะถูกข้าม ไม่ลากเส้นเชื่อมข้ามช่องว่าง (ไม่แต่งข้อมูลให้ดูต่อเนื่อง)
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    points: { type: Array, default: () => [] },
});

const { t } = useTranslation();

const W = 600;
const H = 220;
const PAD = { top: 12, right: 12, bottom: 22, left: 12 };

const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));

const clean = computed(() =>
    props.points
        .map((p) => ({ t: p.t, price: num(p.price), ref: num(p.reference), low: num(p.low), high: num(p.high) }))
        .filter((p) => Number.isFinite(p.price)),
);

const range = computed(() => {
    const values = clean.value.flatMap((p) => [p.price, p.low, p.high].filter(Number.isFinite));
    if (values.length === 0) return null;
    let min = Math.min(...values);
    let max = Math.max(...values);
    if (max === min) {
        max += max * 0.05 || 1;
        min -= min * 0.05 || 1;
    }
    const margin = (max - min) * 0.08;
    return { min: min - margin, max: max + margin };
});

const x = (i) => {
    const n = clean.value.length;
    return PAD.left + (n <= 1 ? (W - PAD.left - PAD.right) / 2 : (i * (W - PAD.left - PAD.right)) / (n - 1));
};
const y = (v) => {
    const r = range.value;
    return PAD.top + (1 - (v - r.min) / (r.max - r.min)) * (H - PAD.top - PAD.bottom);
};

/* เส้นแบบแยกช่วง — ค่าหายตรงไหนก็ตัดเส้นตรงนั้น */
function line(key) {
    let d = '';
    let pen = false;
    clean.value.forEach((p, i) => {
        if (!Number.isFinite(p[key])) {
            pen = false;
            return;
        }
        d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(p[key]).toFixed(1)} `;
        pen = true;
    });
    return d.trim();
}

const pricePath = computed(() => (range.value ? line('price') : ''));
const refPath = computed(() => (range.value ? line('ref') : ''));

/* แถบกรอบ: ขอบบนไปทางขวา แล้วย้อนขอบล่างกลับ — เฉพาะช่วงที่มีกรอบครบทั้งสองขอบ */
const bandPath = computed(() => {
    if (!range.value) return '';
    const idx = clean.value.map((p, i) => (Number.isFinite(p.low) && Number.isFinite(p.high) ? i : -1)).filter((i) => i >= 0);
    if (idx.length < 2) return '';
    const top = idx.map((i, k) => `${k === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(clean.value[i].high).toFixed(1)}`).join(' ');
    const bottom = [...idx].reverse().map((i) => `L${x(i).toFixed(1)},${y(clean.value[i].low).toFixed(1)}`).join(' ');
    return `${top} ${bottom} Z`;
});

const fmt = (v) => (Number.isFinite(v) ? v.toFixed(v < 1 ? 6 : 4) : '—');
</script>

<template>
    <div class="w-full">
        <div v-if="!range" class="text-sm text-dark-400 py-10 text-center" data-testid="chart-empty">
            {{ t('stability.chart.empty') }}
        </div>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" role="img" :aria-label="t('stability.chart.title')">
                <path v-if="bandPath" :d="bandPath" fill="rgba(56,189,248,0.12)" stroke="rgba(56,189,248,0.35)" stroke-width="1" />
                <path v-if="refPath" :d="refPath" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="5 4" />
                <path :d="pricePath" fill="none" stroke="#34d399" stroke-width="2" data-testid="price-line" />
                <text :x="PAD.left" :y="H - 6" fill="#64748b" font-size="11">{{ fmt(range.min) }}</text>
                <text :x="PAD.left" :y="PAD.top + 10" fill="#64748b" font-size="11">{{ fmt(range.max) }}</text>
            </svg>
            <div class="flex flex-wrap gap-4 mt-2 text-xs text-dark-300">
                <span class="flex items-center gap-1.5"><span class="inline-block w-4 h-0.5 bg-emerald-400"></span>{{ t('stability.chart.price') }}</span>
                <span class="flex items-center gap-1.5"><span class="inline-block w-4 border-t border-dashed border-slate-400"></span>{{ t('stability.chart.reference') }}</span>
                <span class="flex items-center gap-1.5"><span class="inline-block w-4 h-2 bg-sky-400/20 border border-sky-400/40"></span>{{ t('stability.chart.band') }}</span>
            </div>
        </template>
    </div>
</template>
