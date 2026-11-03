<script setup>
/**
 * TPIX Master Node — Setup Guide
 * คู่มือการตั้งค่า Master Node แบบละเอียดพร้อมภาพประกอบ
 * ภาษาไทย/อังกฤษ
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';

const { t } = useTranslation();

const activeStep = ref(0);
const lightboxImage = ref(null);

const P = 'page.masterNodeGuide';

const tiers = computed(() => [
    {
        id: 'light', name: 'Light Node',
        stake: '10,000 TPIX', apy: '4-6%', lock: t(`${P}.tiers.lockDays`, { n: 7 }),
        reward: { monthly: '~42 TPIX', yearly: '~500 TPIX' },
        hardware: t(`${P}.tiers.hardware`, { cpu: 2, ram: '4GB', ssd: '50GB' }),
        color: 'cyan', icon: '💎',
    },
    {
        id: 'sentinel', name: 'Sentinel Node',
        stake: '100,000 TPIX', apy: '7-9%', lock: t(`${P}.tiers.lockDays`, { n: 30 }),
        reward: { monthly: '~667 TPIX', yearly: '~8,000 TPIX' },
        hardware: t(`${P}.tiers.hardware`, { cpu: 4, ram: '8GB', ssd: '200GB' }),
        color: 'purple', icon: '🛡️',
    },
    {
        id: 'guardian', name: 'Guardian Node',
        stake: '1,000,000 TPIX', apy: '10-12%', lock: t(`${P}.tiers.lockDays`, { n: 90 }),
        reward: { monthly: '~9,167 TPIX', yearly: '~110,000 TPIX' },
        hardware: t(`${P}.tiers.hardware`, { cpu: 8, ram: '16GB', ssd: '500GB' }),
        color: 'yellow', icon: '⚡',
    },
    {
        id: 'validator', name: 'Validator Node',
        stake: '10,000,000 TPIX', apy: '15-20%', lock: t(`${P}.tiers.lockDays`, { n: 180 }),
        reward: { monthly: '~145,833 TPIX', yearly: '~1,750,000 TPIX' },
        hardware: t(`${P}.tiers.hardware`, { cpu: 16, ram: '32GB', ssd: '1TB' }),
        color: 'red', icon: '🔥',
    },
]);

// เก็บแค่ key + จำนวนข้อ แล้วแปลใน computed — สลับภาษาแล้วเนื้อหาเปลี่ยนตามทันที
const stepDefs = [
    { key: 's1', details: 4, img: 'step-download' },
    { key: 's2', details: 5, img: 'step-wallet' },
    { key: 's3', details: 5, img: 'step-fund' },
    { key: 's4', details: 5, img: 'step-tier' },
    { key: 's5', details: 6, img: 'step-run' },
    { key: 's6', details: 5, img: 'step-rewards' },
];

const steps = computed(() => stepDefs.map(({ key, details, img }) => {
    const base = `${P}.steps.${key}`;
    return {
        title: t(`${base}.title`),
        desc: t(`${base}.desc`),
        details: Array.from({ length: details }, (_, i) => t(`${base}.details.d${i + 1}`)),
        tip: t(`${base}.tip`),
        quick: [1, 2, 3].map((n) => t(`${base}.quick.q${n}`)),
        img,
    };
}));

const faqs = computed(() => [1, 2, 3, 4, 5, 6].map((n) => ({
    q: t(`${P}.faq.f${n}.q`),
    a: t(`${P}.faq.f${n}.a`),
})));

const screenshots = computed(() => [
    { src: '/images/guide/dashboard.webp', label: t(`${P}.screenshots.dashboard`) },
    { src: '/images/guide/setup-tier.webp', label: t(`${P}.screenshots.setupTier`) },
    { src: '/images/guide/wallet.webp', label: t(`${P}.screenshots.wallet`) },
    { src: '/images/guide/network.webp', label: t(`${P}.screenshots.network`) },
    { src: '/images/guide/links.webp', label: t(`${P}.screenshots.links`) },
    { src: '/images/guide/settings.webp', label: t(`${P}.screenshots.settings`) },
    { src: '/images/guide/about.webp', label: t(`${P}.screenshots.about`) },
]);

// Step screenshots — real captures from the app
const stepScreenshots = {
    'step-download': '/images/guide/about.webp',
    'step-wallet': '/images/guide/wallet.webp',
    'step-fund': '/images/guide/wallet.webp',
    'step-tier': '/images/guide/setup-tier.webp',
    'step-run': '/images/guide/dashboard.webp',
    'step-rewards': '/images/guide/network.webp',
};

// Step diagram data (overlay info on screenshots)
const stepDiagrams = {
    'step-download': { icon: '📥', color: '#06b6d4' },
    'step-wallet': { icon: '🔐', color: '#a855f7' },
    'step-fund': { icon: '💰', color: '#f59e0b' },
    'step-tier': { icon: '⚙️', color: '#06b6d4' },
    'step-run': { icon: '▶️', color: '#00c853' },
    'step-rewards': { icon: '🎁', color: '#f97316' },
};
</script>

<template>
    <Head :title="t('page.masterNodeGuide.headTitle')" />

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8">
            <!-- Header -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center gap-3 mb-4 px-4 py-2 rounded-full bg-cyan-500/10 border border-cyan-500/20">
                    <BrandLogo variant="icon" alt="TPIX" class="w-8 h-8" />
                    <span class="text-cyan-400 font-semibold text-sm">TPIX Master Node</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-extrabold text-white mb-3">
                    {{ t('page.masterNodeGuide.header.title') }}
                </h1>
                <p class="text-dark-400 max-w-2xl mx-auto">
                    {{ t('page.masterNodeGuide.header.subtitle') }}
                </p>

                <div class="flex gap-3 justify-center mt-6 flex-wrap">
                    <Link href="/masternode" class="btn-primary px-6 py-2.5 rounded-xl text-sm font-semibold">
                        {{ t('page.masterNodeGuide.header.setupNow') }}
                    </Link>
                    <a href="/api/v1/app/chain-download?type=masternode"
                        class="px-6 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-dark-300 hover:bg-white/5 transition">
                        📥 {{ t('page.masterNodeGuide.header.downloadApp') }}
                    </a>
                </div>
            </div>

            <!-- Tier Comparison -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ t('page.masterNodeGuide.tiers.title') }}
                </h2>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="tier in tiers" :key="tier.id"
                        class="glass-card p-5 rounded-2xl border border-white/10 hover:border-white/20 transition-all hover:-translate-y-1 hover:shadow-xl">
                        <div class="text-3xl mb-3">{{ tier.icon }}</div>
                        <h3 class="text-lg font-bold text-white mb-1">{{ tier.name }}</h3>
                        <div class="text-2xl font-extrabold mb-2" :class="`text-${tier.color}-400`">{{ tier.stake }}</div>
                        <div class="text-trading-green font-semibold mb-3">{{ t('page.masterNodeGuide.tiers.apy', { apy: tier.apy }) }}</div>

                        <div class="bg-white/5 rounded-xl p-3 mb-3 space-y-1">
                            <div class="text-xs text-dark-500 uppercase font-semibold">{{ t('page.masterNodeGuide.tiers.estRewards') }}</div>
                            <div class="text-sm text-cyan-400 font-medium">{{ t('page.masterNodeGuide.tiers.perMonth', { amount: tier.reward.monthly }) }}</div>
                            <div class="text-xs text-dark-400">{{ t('page.masterNodeGuide.tiers.perYear', { amount: tier.reward.yearly }) }}</div>
                        </div>

                        <div class="text-xs text-dark-500 space-y-1">
                            <div>🔒 {{ t('page.masterNodeGuide.tiers.lock', { period: tier.lock }) }}</div>
                            <div>💻 {{ tier.hardware }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step by Step Guide -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ t('page.masterNodeGuide.steps.title') }}
                </h2>

                <!-- Step Navigation -->
                <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
                    <button v-for="(step, idx) in steps" :key="idx"
                        @click="activeStep = idx"
                        :class="[
                            'flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap transition-all border',
                            activeStep === idx
                                ? 'bg-cyan-500/15 border-cyan-500/30 text-cyan-400'
                                : 'bg-white/5 border-white/5 text-dark-400 hover:bg-white/10'
                        ]">
                        <span :class="[
                            'w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold',
                            activeStep === idx ? 'bg-cyan-500 text-dark-900' : 'bg-white/10 text-dark-500'
                        ]">{{ idx + 1 }}</span>
                        {{ step.title }}
                    </button>
                </div>

                <!-- Active Step Content -->
                <div class="grid md:grid-cols-5 gap-6">
                    <!-- Left: Details (3 cols) -->
                    <div class="md:col-span-3 glass-card p-6 rounded-2xl border border-white/10">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                {{ activeStep + 1 }}
                            </span>
                            <div>
                                <h3 class="text-lg font-bold text-white">{{ steps[activeStep].title }}</h3>
                                <p class="text-sm text-dark-400">{{ steps[activeStep].desc }}</p>
                            </div>
                        </div>

                        <ol class="space-y-3 mb-6">
                            <li v-for="(detail, i) in steps[activeStep].details" :key="i"
                                class="flex gap-3 text-sm text-dark-300">
                                <span class="w-5 h-5 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-xs text-dark-500 flex-shrink-0 mt-0.5">
                                    {{ i + 1 }}
                                </span>
                                <span>{{ detail }}</span>
                            </li>
                        </ol>

                        <!-- Tip Box -->
                        <div class="bg-yellow-500/5 border border-yellow-500/20 rounded-xl p-4">
                            <div class="flex gap-2 text-sm">
                                <span class="text-yellow-400 flex-shrink-0">💡</span>
                                <span class="text-yellow-200/80">{{ steps[activeStep].tip }}</span>
                            </div>
                        </div>

                        <!-- Navigation -->
                        <div class="flex gap-2 mt-6">
                            <button v-if="activeStep > 0" @click="activeStep--"
                                class="px-4 py-2 rounded-xl text-sm border border-white/10 text-dark-400 hover:bg-white/5">
                                &larr; {{ t('page.masterNodeGuide.steps.previous') }}
                            </button>
                            <button v-if="activeStep < steps.length - 1" @click="activeStep++"
                                class="btn-primary px-4 py-2 rounded-xl text-sm font-semibold">
                                {{ t('page.masterNodeGuide.steps.next') }} &rarr;
                            </button>
                            <Link v-else href="/masternode" class="btn-primary px-4 py-2 rounded-xl text-sm font-semibold">
                                🚀 {{ t('page.masterNodeGuide.steps.setupNowExcl') }}
                            </Link>
                        </div>
                    </div>

                    <!-- Right: Screenshot + Info (2 cols) -->
                    <div class="md:col-span-2 glass-card p-4 rounded-2xl border border-white/10 flex flex-col">
                        <!-- Real screenshot from the app -->
                        <div class="relative rounded-xl overflow-hidden mb-4 border border-white/10 cursor-pointer group"
                            @click="lightboxImage = stepScreenshots[steps[activeStep].img]">
                            <img :src="stepScreenshots[steps[activeStep].img]"
                                :alt="steps[activeStep].title"
                                class="w-full h-auto rounded-xl transition-transform group-hover:scale-[1.02]"
                                loading="lazy">
                            <div class="absolute top-2 right-2 bg-black/60 backdrop-blur-sm px-2 py-1 rounded-lg text-xs text-cyan-400 font-semibold">
                                {{ stepDiagrams[steps[activeStep].img]?.icon }} {{ t('page.masterNodeGuide.steps.livePreview') }}
                            </div>
                            <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/20">
                                <span class="bg-black/60 text-white px-3 py-1 rounded-lg text-sm">🔍 {{ t('page.masterNodeGuide.steps.clickToEnlarge') }}</span>
                            </div>
                        </div>

                        <!-- Quick steps overlay -->
                        <div class="space-y-2">
                            <div v-for="(item, i) in steps[activeStep].quick" :key="i"
                                class="flex items-center gap-3 p-2.5 rounded-xl bg-white/5 border border-white/5">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"
                                    :style="{ background: stepDiagrams[steps[activeStep].img]?.color + '20', color: stepDiagrams[steps[activeStep].img]?.color }">
                                    {{ i + 1 }}
                                </div>
                                <span class="text-sm text-dark-300">{{ item }}</span>
                            </div>
                        </div>

                        <!-- Step progress -->
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-dark-500 mb-1">
                                <span>{{ t('page.masterNodeGuide.steps.progress', { current: activeStep + 1, total: steps.length }) }}</span>
                                <span>{{ Math.round(((activeStep + 1) / steps.length) * 100) }}%</span>
                            </div>
                            <div class="h-1.5 bg-white/5 rounded-full overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-cyan-500 to-purple-500 rounded-full transition-all duration-300"
                                    :style="{ width: ((activeStep + 1) / steps.length * 100) + '%' }">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reward Economics -->
            <div class="glass-card p-6 rounded-2xl border border-white/10 mb-12">
                <h2 class="text-xl font-bold text-white mb-4">
                    {{ t('page.masterNodeGuide.rewards.title') }}
                </h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-dark-500 text-xs uppercase border-b border-white/5">
                                <th class="text-left py-3 px-4">{{ t('page.masterNodeGuide.rewards.colYear') }}</th>
                                <th class="text-right py-3 px-4">{{ t('page.masterNodeGuide.rewards.colEmission') }}</th>
                                <th class="text-right py-3 px-4">{{ t('page.masterNodeGuide.rewards.colPerBlock') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-dark-300">
                            <tr class="border-b border-white/3"><td class="py-3 px-4">{{ t('page.masterNodeGuide.rewards.yearN', { n: 1 }) }}</td><td class="text-right px-4 text-cyan-400 font-semibold">600,000,000</td><td class="text-right px-4">~38.0 TPIX</td></tr>
                            <tr class="border-b border-white/3"><td class="py-3 px-4">{{ t('page.masterNodeGuide.rewards.yearN', { n: 2 }) }}</td><td class="text-right px-4">500,000,000</td><td class="text-right px-4">~31.7 TPIX</td></tr>
                            <tr class="border-b border-white/5"><td class="py-3 px-4">{{ t('page.masterNodeGuide.rewards.yearN', { n: 3 }) }}</td><td class="text-right px-4">300,000,000</td><td class="text-right px-4">~19.0 TPIX</td></tr>
                            <tr class="font-bold text-yellow-400"><td class="py-3 px-4">{{ t('page.masterNodeGuide.rewards.total') }}</td><td class="text-right px-4">1,400,000,000 TPIX</td><td class="text-right px-4">—</td></tr>
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-dark-500 mt-4 leading-relaxed">
                    {{ t('page.masterNodeGuide.rewards.note') }}
                </p>
            </div>

            <!-- FAQ -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ t('page.masterNodeGuide.faq.title') }}
                </h2>
                <div class="space-y-3">
                    <div v-for="(faq, i) in faqs" :key="i"
                        class="glass-card p-5 rounded-xl border border-white/10">
                        <h3 class="font-semibold text-white text-sm mb-2">{{ faq.q }}</h3>
                        <p class="text-dark-400 text-sm leading-relaxed">{{ faq.a }}</p>
                    </div>
                </div>
            </div>

            <!-- App Screenshots Gallery -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ t('page.masterNodeGuide.screenshots.title') }}
                </h2>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <div v-for="ss in screenshots" :key="ss.src"
                        class="group relative rounded-xl overflow-hidden border border-white/10 hover:border-cyan-500/30 transition-all hover:-translate-y-1 hover:shadow-lg hover:shadow-cyan-500/5 cursor-pointer"
                        @click="lightboxImage = ss.src">
                        <img :src="ss.src" :alt="ss.label" class="w-full h-auto" loading="lazy">
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-2">
                            <span class="text-xs font-semibold text-white">{{ ss.label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CTA -->
            <div class="text-center py-8 glass-card rounded-2xl border border-cyan-500/20 bg-gradient-to-r from-cyan-500/5 via-purple-500/5 to-cyan-500/5">
                <BrandLogo variant="mark" alt="TPIX" class="w-16 h-16 mx-auto mb-4" />
                <h2 class="text-2xl font-bold text-white mb-2">
                    {{ t('page.masterNodeGuide.cta.title') }}
                </h2>
                <p class="text-dark-400 mb-6 max-w-md mx-auto text-sm">
                    {{ t('page.masterNodeGuide.cta.desc') }}
                </p>
                <div class="flex gap-3 justify-center flex-wrap">
                    <a href="/api/v1/app/chain-download?type=masternode"
                        class="btn-primary px-8 py-3 rounded-xl font-semibold">
                        📥 {{ t('page.masterNodeGuide.cta.download') }}
                    </a>
                    <Link href="/masternode" class="px-8 py-3 rounded-xl font-semibold border border-white/10 text-white hover:bg-white/5 transition">
                        ⚡ {{ t('page.masterNodeGuide.cta.setupOnline') }}
                    </Link>
                </div>
                <p class="text-xs text-dark-600 mt-4">
                    {{ t('page.masterNodeGuide.cta.developedBy') }} · <a href="https://xman4289.com" target="_blank" class="text-cyan-500 hover:underline">xman4289.com</a>
                </p>
            </div>
        </div>

        <!-- Lightbox Modal -->
        <Teleport to="body">
            <div v-if="lightboxImage"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/85 backdrop-blur-sm cursor-pointer p-4"
                @click="lightboxImage = null">
                <div class="relative max-w-4xl max-h-[90vh]" @click.stop>
                    <img :src="lightboxImage" :alt="t('page.masterNodeGuide.screenshots.lightboxAlt')" class="max-w-full max-h-[85vh] rounded-2xl shadow-2xl">
                    <button @click="lightboxImage = null" :aria-label="t('page.masterNodeGuide.screenshots.close')"
                        class="absolute -top-3 -right-3 w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center text-lg transition">
                        &times;
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.glass-card {
    background: rgba(255, 255, 255, 0.03);
    backdrop-filter: blur(12px);
}
.btn-primary {
    background: linear-gradient(135deg, rgb(var(--c-primary-500)), rgb(var(--c-primary-600)));
    color: white;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
}
.btn-primary:hover {
    filter: brightness(1.15);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgb(var(--c-primary-500) / 0.3);
}
</style>
