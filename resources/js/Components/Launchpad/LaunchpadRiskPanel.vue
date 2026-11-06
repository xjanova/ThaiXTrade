<script setup>
/**
 * TPIX TRADE — ป้ายความเสี่ยงของหน้าขาย (อ่านสดจากเชนผ่าน /api/v1/launchpad/sales/{id}/risk)
 *
 * ป้ายมาจากเซิร์ฟเวอร์เป็น { key, level, params } — คอมโพเนนต์นี้แค่แปลและระบายสี
 * "ไม่ทราบ" แสดงเป็นสีเทาพร้อมคำว่าไม่ทราบเสมอ — ห้ามดูเหมือน "ผ่าน"
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    risk: { type: Object, default: null },      // ผลจาก API หรือ null = กำลังโหลด
    failed: { type: Boolean, default: false },  // เรียก API ไม่ได้เลย
});

const icon = { ok: '✅', warn: '⚠️', danger: '⛔', info: 'ℹ️', unknown: '❔' };
const tone = {
    ok: 'border-trading-green/25 bg-trading-green/[0.06]',
    warn: 'border-amber-500/30 bg-amber-500/[0.06]',
    danger: 'border-trading-red/35 bg-trading-red/[0.08]',
    info: 'border-primary-500/25 bg-primary-500/[0.06]',
    unknown: 'border-white/10 bg-white/[0.03]',
};

function dateText(iso) {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-GB', { year: 'numeric', month: 'short', day: 'numeric' });
}

const badges = computed(() => (props.risk?.badges ?? []).map((b) => {
    const params = { ...(b.params ?? {}) };
    if (params.date) params.date = dateText(params.date);
    const key = `launchpad.badges.${b.key}.${b.level}`;
    const text = t(key, params);
    // คีย์ที่ไม่รู้จัก (เซิร์ฟเวอร์ใหม่กว่าหน้าเว็บ) — ไม่โชว์ชื่อคีย์ดิบ
    return { ...b, text: text === key ? t(`launchpad.badges.level.${b.level}`) : text };
}));

const checkedAt = computed(() => {
    const d = new Date(props.risk?.checked_at ?? '');
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString(locale.value === 'th' ? 'th-TH' : 'en-GB', { hour: '2-digit', minute: '2-digit' });
});
</script>

<template>
    <section class="glass-dark rounded-2xl p-5 border border-white/10" data-test="risk-panel">
        <div class="flex items-start justify-between gap-3 mb-1">
            <h2 class="text-base font-semibold text-white">🛡️ {{ t('launchpad.badges.title') }}</h2>
            <span v-if="checkedAt" class="text-[11px] text-dark-500 shrink-0">{{ t('launchpad.badges.checkedAt', { time: checkedAt }) }}</span>
        </div>
        <p class="text-xs text-dark-400 mb-4">{{ t('launchpad.badges.subtitle') }}</p>

        <p v-if="!risk && !failed" class="text-sm text-dark-400">{{ t('launchpad.badges.loading') }}</p>

        <div v-else-if="failed || risk?.available === false" class="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-3 text-sm text-amber-300 mb-3" data-test="risk-unavailable">
            {{ t('launchpad.badges.unavailable') }}
        </div>

        <ul v-if="badges.length" class="grid sm:grid-cols-2 gap-2">
            <li v-for="b in badges" :key="b.key" :class="['rounded-xl border px-3 py-2 flex gap-2 text-sm', tone[b.level] ?? tone.unknown]" :data-test="`badge-${b.key}`" :data-level="b.level">
                <span class="shrink-0" aria-hidden="true">{{ icon[b.level] ?? icon.unknown }}</span>
                <span class="text-dark-200">
                    <span class="sr-only">{{ t(`launchpad.badges.level.${b.level}`) }}: </span>{{ b.text }}
                </span>
            </li>
        </ul>
    </section>
</template>
