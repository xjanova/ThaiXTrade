<script setup>
/**
 * TPIX TRADE — Launchpad: รวมหน้าขายเหรียญลูกที่แอดมินอนุมัติแล้ว
 *
 * หน้าเดียวใช้กับทุกเหรียญ — เพิ่มเหรียญใหม่ = ลงทะเบียน + อนุมัติในหลังบ้าน ไม่ต้องแก้โค้ด
 * ราคา/ช่วงเวลาทุกตัวมาจากเชน (launchpad:register) — หน้านี้แค่แสดง
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { saleState, timeLeft, formatDecimal } from '@/utils/launchpad';

const { t } = useTranslation();

const props = defineProps({
    sales: { type: Array, default: () => [] },
    launchpad: { type: Object, default: () => ({ enabled: false, chains: [], order_ttl_minutes: 30 }) },
});

const now = ref(Date.now());
let timer = null;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 30_000); });
onUnmounted(() => { if (timer) clearInterval(timer); });

// กำลังขายขึ้นก่อน → ใกล้เปิด → ปิดแล้ว
const order = { live: 0, upcoming: 1, ended: 2 };
const sorted = computed(() => [...props.sales]
    .map((s) => ({ ...s, state: saleState(s, now.value) }))
    .sort((a, b) => order[a.state] - order[b.state]));

function duration(iso) {
    const { d, h, m } = timeLeft(iso, now.value);
    return t('launchpad.time.dhm', { d, h, m });
}

const stateClass = {
    live: 'bg-trading-green/15 text-trading-green border-trading-green/30',
    upcoming: 'bg-primary-500/15 text-primary-300 border-primary-500/30',
    ended: 'bg-white/5 text-dark-300 border-white/10',
};
</script>

<template>
    <Head :title="t('launchpad.title')" />
    <AppLayout>
        <div class="max-w-5xl mx-auto space-y-6 px-1">
            <div class="text-center">
                <h1 class="text-2xl md:text-3xl font-bold text-white mb-2">🚀 {{ t('launchpad.title') }}</h1>
                <p class="text-dark-400 text-sm max-w-2xl mx-auto">{{ t('launchpad.subtitle') }}</p>
            </div>

            <div v-if="!launchpad.enabled" class="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex gap-3" data-test="not-open">
                <span class="text-lg shrink-0">🚧</span>
                <div>
                    <p class="text-sm font-semibold text-amber-300">{{ t('launchpad.notOpen') }}</p>
                    <p class="text-xs text-dark-300 mt-1">{{ t('launchpad.notOpenBody') }}</p>
                </div>
            </div>

            <!-- ขั้นตอน 3 ข้อ -->
            <div class="glass-dark rounded-2xl p-5 border border-white/10">
                <h2 class="text-sm font-semibold text-white mb-3">{{ t('launchpad.howTitle') }}</h2>
                <ol class="grid md:grid-cols-3 gap-3 text-sm text-dark-300">
                    <li class="flex gap-2"><span class="text-primary-400 font-bold">1</span>{{ t('launchpad.how1') }}</li>
                    <li class="flex gap-2"><span class="text-primary-400 font-bold">2</span>{{ t('launchpad.how2', { minutes: launchpad.order_ttl_minutes }) }}</li>
                    <li class="flex gap-2"><span class="text-primary-400 font-bold">3</span>{{ t('launchpad.how3') }}</li>
                </ol>
                <p class="mt-4 text-xs text-amber-300/90 flex gap-2"><span>⚠️</span>{{ t('launchpad.riskShort') }}</p>
            </div>

            <div v-if="!sorted.length" class="glass-dark rounded-2xl p-10 text-center border border-white/10" data-test="empty">
                <p class="text-white font-semibold">{{ t('launchpad.empty') }}</p>
                <p class="text-dark-400 text-sm mt-1">{{ t('launchpad.emptyBody') }}</p>
            </div>

            <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <Link v-for="sale in sorted" :key="sale.id" :href="`/launchpad/${encodeURIComponent(sale.token.symbol)}`"
                    class="glass-dark rounded-2xl p-5 border border-white/10 hover:border-primary-500/40 transition-colors block" data-test="sale-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-lg font-bold text-white truncate">{{ sale.token.symbol }}</p>
                            <p class="text-xs text-dark-400 truncate">{{ sale.token.name }}</p>
                        </div>
                        <span :class="['shrink-0 px-2 py-0.5 rounded-full text-[11px] border', stateClass[sale.state]]">{{ t(`launchpad.state.${sale.state}`) }}</span>
                    </div>
                    <dl class="mt-4 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-dark-400">{{ t('launchpad.card.price') }}</dt><dd class="text-white font-mono">${{ sale.price_usdt }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-dark-400">{{ t('launchpad.card.perUsdt') }}</dt><dd class="text-white font-mono">{{ formatDecimal(sale.tokens_per_usdt, 2) }}</dd></div>
                    </dl>
                    <p class="mt-4 text-xs text-dark-300">
                        <template v-if="sale.state === 'live'">{{ t('launchpad.card.endsIn', { time: duration(sale.ends_at) }) }}</template>
                        <template v-else-if="sale.state === 'upcoming'">{{ t('launchpad.card.startsIn', { time: duration(sale.starts_at) }) }}</template>
                        <template v-else>{{ t('launchpad.card.ended') }}</template>
                    </p>
                    <p class="mt-3 text-sm text-primary-400">{{ t('launchpad.viewSale') }} →</p>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
