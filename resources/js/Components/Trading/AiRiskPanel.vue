<script setup>
/**
 * TPIX TRADE — ด่านความเสี่ยงที่บอทใช้ตัดสินใจ แสดงให้ผู้ใช้เห็น.
 *
 * เหตุผลที่ต้องมีหน้านี้: บอทที่ "หยุดเทรดเฉยๆ" โดยไม่บอกเหตุผล ทำให้ผู้ใช้คิดว่าเสีย
 * พอเห็นว่ามันหยุดเพราะข่าวอะไร ความเงียบจะกลายเป็นความมั่นใจแทน
 *
 * ข้อมูลมาจาก /api/v1/ai-bot/risk ซึ่งคำนวณจากพฤติกรรมราคา + ข่าวที่ดึงทุก 15 นาที
 *
 * Developed by Xman Studio.
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    risk: { type: Object, default: null },
    pair: { type: String, default: '' },
});

const { t, locale } = useTranslation();

const level = computed(() => props.risk?.level ?? 'calm');

const levelTone = {
    calm: 'bg-trading-green/10 text-trading-green ring-trading-green/25',
    caution: 'bg-amber-500/10 text-amber-300 ring-amber-500/25',
    elevated: 'bg-orange-500/10 text-orange-300 ring-orange-500/25',
    panic: 'bg-trading-red/10 text-trading-red ring-trading-red/25',
};

const levelLabel = {
    calm: 'aiTrade.riskCalm',
    caution: 'aiTrade.riskCaution',
    elevated: 'aiTrade.riskElevated',
    panic: 'aiTrade.riskPanic',
};

/** แถบแสดงระดับความเสี่ยง 0–100% */
const scorePct = computed(() => Math.round(Number(props.risk?.score ?? 0) * 100));

const barTone = {
    calm: 'bg-trading-green',
    caution: 'bg-amber-400',
    elevated: 'bg-orange-400',
    panic: 'bg-trading-red',
};

const sizePct = computed(() => Math.round(Number(props.risk?.size_multiplier ?? 1) * 100));

const reasons = computed(() => props.risk?.reasons ?? []);
const headlines = computed(() => props.risk?.news?.headlines ?? []);

function when(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <section class="ai-risk relative overflow-hidden rounded-2xl border border-white/10 bg-dark-900/50">
        <header class="ai-risk__head relative flex flex-wrap items-center gap-3 px-5 py-3.5">
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-bold text-white leading-tight">{{ t('aiTrade.riskGate') }}</h2>
                <p class="text-[11px] text-dark-400 mt-0.5">
                    {{ pair }} · {{ t('aiTrade.newsScanned') }}
                </p>
            </div>

            <span :class="['px-2.5 py-1 rounded-full text-[11px] font-semibold ring-1', levelTone[level]]">
                {{ t(levelLabel[level]) }}
            </span>
        </header>

        <div class="relative p-5 pt-4 space-y-4">
            <!-- แถบระดับความเสี่ยง + สิ่งที่บอทจะทำ -->
            <div>
                <div class="flex items-center justify-between text-[11px] mb-1.5">
                    <span class="text-dark-400">{{ t('aiTrade.riskNow') }}</span>
                    <span class="font-mono text-dark-200">{{ scorePct }}%</span>
                </div>
                <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                    <div
                        :class="['h-full rounded-full transition-all duration-500', barTone[level]]"
                        :style="{ width: Math.max(2, scorePct) + '%' }"
                    />
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-white/10 bg-dark-950/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.riskSizeMultiplier') }}</p>
                        <p class="text-sm font-bold font-mono text-white mt-0.5">{{ sizePct }}%</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-dark-950/40 px-3 py-2">
                        <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.riskForceExit') }}</p>
                        <p :class="['text-sm font-bold mt-0.5', risk?.force_exit ? 'text-trading-red' : 'text-dark-300']">
                            {{ risk?.force_exit ? t('aiTrade.on') : t('aiTrade.off') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- เหตุผลที่ทำให้ระดับเป็นแบบนี้ -->
            <div>
                <h3 class="text-xs font-semibold text-dark-300 mb-2">{{ t('aiTrade.riskWhy') }}</h3>

                <ul v-if="reasons.length" class="space-y-1.5">
                    <li
                        v-for="(reason, index) in reasons"
                        :key="index"
                        class="flex gap-2 text-[11px] text-dark-300 leading-relaxed"
                    >
                        <span class="text-amber-400 shrink-0">•</span>
                        <span>{{ reason }}</span>
                    </li>
                </ul>

                <p v-else class="text-[11px] text-dark-500">{{ t('aiTrade.riskNoReason') }}</p>
            </div>

            <!-- พาดหัวข่าวที่บอทกำลังจับตา -->
            <div>
                <h3 class="text-xs font-semibold text-dark-300 mb-2">{{ t('aiTrade.newsHeadlines') }}</h3>

                <div v-if="headlines.length" class="space-y-2">
                    <a
                        v-for="item in headlines"
                        :key="item.url"
                        :href="item.url"
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        class="block rounded-xl border border-white/10 bg-dark-950/40 px-3 py-2 hover:border-white/20 transition-colors"
                    >
                        <p class="text-[11px] text-dark-200 leading-snug">{{ item.title }}</p>
                        <p class="text-[10px] text-dark-500 font-mono mt-1">
                            {{ item.source }} · {{ when(item.published_at) }} ·
                            <span class="text-trading-red">{{ Math.round(item.panic_score * 100) }}%</span>
                        </p>
                    </a>
                </div>

                <p v-else class="text-[11px] text-dark-500">{{ t('aiTrade.newsNone') }}</p>
            </div>
        </div>
    </section>
</template>

<style scoped>
.ai-risk__head {
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.01) 100%),
        repeating-linear-gradient(115deg, rgba(255, 255, 255, 0.03) 0 2px, transparent 2px 7px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.09);
}

.ai-risk {
    box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.04) inset,
        0 18px 40px -28px rgba(0, 0, 0, 0.9);
}
</style>
