<script setup>
/**
 * HowItWorks — Tutorial ละเอียด สอนทุกขั้นตอน
 * ง่ายเหมือนกดตู้น้ำ
 * ข้อความทั้งหมดอยู่ใน i18n (ui.foodPassportHowItWorks.*) — สร้างใน computed เพื่อให้สลับ TH/EN ได้ทันที
 */
import { computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

defineProps({
    categories: { type: Object, required: true },
    stageConfig: { type: Object, required: true },
});

const activeStep = defineModel('activeStep', { default: 1 });

const NS = 'ui.foodPassportHowItWorks.';
const stepMeta = [
    { num: 1, icon: '📝', color: 'green' },
    { num: 2, icon: '📡', color: 'blue' },
    { num: 3, icon: '🏆', color: 'purple' },
    { num: 4, icon: '📱', color: 'pink' },
];

const steps = computed(() => stepMeta.map((m) => {
    const base = `${NS}steps.s${m.num}.`;
    return {
        ...m,
        title: t(base + 'title'),
        subtitle: t(base + 'subtitle'),
        details: ['d1', 'd2', 'd3', 'd4', 'd5'].map((d) => t(`${base}details.${d}`)),
        code: t(base + 'code'),
        chainInfo: t(base + 'chainInfo'),
    };
}));

const tokenStepIcons = ['🏭', '⚙️', '💰', '✅', '🚀'];
const tokenCreationSteps = computed(() => tokenStepIcons.map((icon, i) => ({
    num: i + 1,
    icon,
    title: t(`${NS}token.steps.s${i + 1}.title`),
    desc: t(`${NS}token.steps.s${i + 1}.desc`),
})));

const storageItems = ['i1', 'i2', 'i3', 'i4'];
const archLayers = [
    { key: 'iot', icon: '📡', box: 'from-green-500/10 to-green-600/10 border-green-500/10' },
    { key: 'backend', icon: '🖥️', box: 'from-blue-500/10 to-blue-600/10 border-blue-500/10' },
    { key: 'chain', icon: '⛓️', box: 'from-purple-500/10 to-purple-600/10 border-purple-500/10' },
];
const archItems = ['i1', 'i2', 'i3', 'i4', 'i5'];
</script>

<template>
    <div class="space-y-8">
        <!-- Header -->
        <div class="glass-card rounded-2xl p-6">
            <h2 class="text-2xl font-bold text-white mb-2">{{ t('ui.foodPassportHowItWorks.header.title') }}</h2>
            <p class="text-dark-400">{{ t('ui.foodPassportHowItWorks.header.subtitle') }}</p>
        </div>

        <!-- Step Cards -->
        <div class="space-y-6">
            <div v-for="step in steps" :key="step.num"
                class="glass-card rounded-2xl overflow-hidden transition-all"
                :class="{ 'ring-1 ring-primary-500/30': activeStep === step.num }">

                <!-- Step Header -->
                <button @click="activeStep = activeStep === step.num ? 0 : step.num"
                    class="w-full flex items-center gap-4 p-6 text-left hover:bg-white/[0.02] transition-all">
                    <span class="text-4xl">{{ step.icon }}</span>
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-gradient-to-br flex items-center justify-center text-sm font-bold text-white"
                                :class="{
                                    'from-green-500 to-green-600': step.color === 'green',
                                    'from-blue-500 to-blue-600': step.color === 'blue',
                                    'from-purple-500 to-purple-600': step.color === 'purple',
                                    'from-pink-500 to-pink-600': step.color === 'pink',
                                }">
                                {{ step.num }}
                            </span>
                            <h3 class="text-lg font-bold text-white">{{ step.title }}</h3>
                            <span class="text-dark-500 text-sm">— {{ step.subtitle }}</span>
                        </div>
                    </div>
                    <svg class="w-5 h-5 text-dark-500 transition-transform" :class="{ 'rotate-180': activeStep === step.num }"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <!-- Step Details (Expandable) -->
                <div v-if="activeStep === step.num" class="px-6 pb-6 space-y-4">
                    <!-- Checklist -->
                    <div class="bg-dark-800/50 rounded-xl p-4">
                        <h4 class="text-white font-medium text-sm mb-3">{{ t('ui.foodPassportHowItWorks.substeps') }}</h4>
                        <div class="space-y-2">
                            <div v-for="(detail, i) in step.details" :key="i" class="flex items-start gap-2">
                                <span class="text-trading-green text-sm mt-0.5">✓</span>
                                <span class="text-dark-300 text-sm">{{ detail }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Code Example -->
                    <div class="bg-dark-900 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs text-dark-500">{{ t('ui.foodPassportHowItWorks.codeExample') }}</span>
                            <span class="px-1.5 py-0.5 rounded bg-primary-500/10 text-primary-400 text-[10px]">Solidity / API</span>
                        </div>
                        <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap">{{ step.code }}</pre>
                    </div>

                    <!-- Chain Info -->
                    <div class="flex items-start gap-2 p-3 rounded-xl bg-cyan-500/5 border border-cyan-500/10">
                        <span class="text-cyan-400 text-sm mt-0.5">⛓️</span>
                        <span class="text-cyan-300 text-sm">{{ step.chainInfo }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Token Creation Guide -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-xl font-bold text-white mb-2">{{ t('ui.foodPassportHowItWorks.token.title') }}</h3>
            <p class="text-dark-400 text-sm mb-6">{{ t('ui.foodPassportHowItWorks.token.subtitle') }}</p>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div v-for="s in tokenCreationSteps" :key="s.num"
                    class="relative p-4 rounded-xl bg-dark-800/50 border border-white/5 text-center">
                    <span class="text-3xl">{{ s.icon }}</span>
                    <p class="text-white font-medium text-sm mt-2">{{ s.title }}</p>
                    <p class="text-dark-500 text-xs mt-1">{{ s.desc }}</p>
                    <!-- Arrow -->
                    <div v-if="s.num < 5" class="hidden md:block absolute -right-3 top-1/2 -translate-y-1/2 text-dark-600 z-10">→</div>
                </div>
            </div>

            <div class="mt-6 p-4 rounded-xl bg-dark-800/50 border border-white/5">
                <h4 class="text-white font-medium text-sm mb-2">{{ t('ui.foodPassportHowItWorks.token.storage.title') }}</h4>
                <div class="space-y-2 text-sm text-dark-300">
                    <p v-for="(k, i) in storageItems" :key="k">{{ i + 1 }}. <strong class="text-white">{{ t(`ui.foodPassportHowItWorks.token.storage.${k}.label`) }}</strong> {{ t(`ui.foodPassportHowItWorks.token.storage.${k}.text`) }}</p>
                </div>
            </div>
        </div>

        <!-- IoT Architecture -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-xl font-bold text-white mb-4">{{ t('ui.foodPassportHowItWorks.arch.title') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div v-for="layer in archLayers" :key="layer.key" class="p-4 rounded-xl bg-gradient-to-br border" :class="layer.box">
                    <p class="text-2xl mb-2">{{ layer.icon }}</p>
                    <h4 class="text-white font-semibold text-sm">{{ t(`ui.foodPassportHowItWorks.arch.${layer.key}.title`) }}</h4>
                    <ul class="mt-2 space-y-1 text-xs text-dark-400">
                        <li v-for="item in archItems" :key="item">• {{ t(`ui.foodPassportHowItWorks.arch.${layer.key}.${item}`) }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
