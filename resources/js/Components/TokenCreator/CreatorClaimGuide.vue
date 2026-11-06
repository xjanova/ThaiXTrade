<script setup>
/**
 * TPIX TRADE — ลงทะเบียนผู้สร้างให้เหรียญที่ขึ้นเชนแล้ว (ลิงก์เชิญจากหลังบ้าน)
 *
 * แสดงเหรียญที่ลิงก์นี้ผูกไว้ + กระเป๋าเจ้าของเหรียญแบบล็อก (ไม่เปลี่ยน)
 * + กระเป๋ารับเหรียญของโครงการ (กระเป๋าที่ผู้แทนเชื่อมและเซ็น) + คู่มือทีละขั้น
 *
 * Developed by Xman Studio
 */
import { computed, ref } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    claim: { type: Object, required: true },
    // กระเป๋าของใบ (ถ้าเริ่มแล้ว) หรือกระเป๋าที่เชื่อมอยู่
    projectWallet: { type: String, default: '' },
    registered: { type: Boolean, default: false },
});

const showGuide = ref(true);

const guideSteps = ['wallet', 'sign', 'form', 'docs', 'kyc', 'agreement', 'review', 'after'];

const sameAsOwner = computed(() => !!props.projectWallet
    && props.projectWallet.toLowerCase() === (props.claim.owner_wallet || '').toLowerCase());
</script>

<template>
    <section class="glass-card p-5 space-y-4 border border-primary-500/30" data-test="claim-card">
        <div class="flex items-start gap-3">
            <img v-if="claim.logo_url" :src="claim.logo_url" :alt="claim.symbol" class="w-12 h-12 rounded-full bg-white/5 object-contain">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-primary-300">{{ t('page.tokenCreator.claim.kicker') }}</p>
                <h2 class="text-xl font-bold text-white" data-test="claim-token">{{ claim.name }} ({{ claim.symbol }})</h2>
                <p class="text-sm text-gray-400">{{ t('page.tokenCreator.claim.intro', { symbol: claim.symbol }) }}</p>
            </div>
        </div>

        <div v-if="claim.error" class="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" data-test="claim-error">{{ claim.error }}</div>

        <div class="grid gap-3 text-sm">
            <label class="block">
                <span class="text-gray-400">{{ t('page.tokenCreator.claim.contract') }}</span>
                <div class="flex flex-wrap items-center gap-2">
                    <code class="text-white break-all">{{ claim.contract_address }}</code>
                    <a :href="claim.explorer_url" target="_blank" rel="noopener" class="text-xs text-primary-400 hover:underline">{{ t('page.tokenCreator.claim.viewExplorer') }}</a>
                    <a :href="claim.whitepaper_url" class="text-xs text-primary-400 hover:underline">{{ t('page.tokenCreator.claim.viewWhitepaper') }}</a>
                </div>
            </label>

            <!-- กระเป๋าเจ้าของเหรียญ — ล็อกไว้ ไม่เปลี่ยน -->
            <label class="block">
                <span class="text-gray-400">{{ t('page.tokenCreator.claim.ownerWallet') }}</span>
                <div class="relative">
                    <input :value="claim.owner_wallet" type="text" disabled readonly
                           class="trading-input w-full pr-24 opacity-60 cursor-not-allowed font-mono text-xs" data-test="owner-wallet">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">🔒 {{ t('page.tokenCreator.claim.locked') }}</span>
                </div>
                <span class="text-xs text-gray-500">{{ t('page.tokenCreator.claim.ownerWalletNote') }}</span>
            </label>

            <!-- กระเป๋ารับเหรียญของโครงการ = กระเป๋าที่เชื่อมและเซ็น -->
            <label class="block">
                <span class="text-gray-400">{{ t('page.tokenCreator.claim.projectWallet') }}</span>
                <input :value="projectWallet || t('page.tokenCreator.claim.projectWalletEmpty')" type="text" readonly
                       class="trading-input w-full font-mono text-xs" :class="projectWallet ? 'text-white' : 'text-gray-500'" data-test="project-wallet">
                <span class="text-xs text-gray-500">{{ registered ? t('page.tokenCreator.claim.projectWalletBound') : t('page.tokenCreator.claim.projectWalletNote') }}</span>
                <span v-if="sameAsOwner" class="block text-xs text-amber-300" data-test="same-as-owner">{{ t('page.tokenCreator.claim.sameAsOwner') }}</span>
            </label>
        </div>

        <!-- คู่มือทีละขั้น -->
        <div class="rounded-lg border border-white/10 bg-white/5">
            <button type="button" class="w-full flex items-center justify-between px-4 py-3 text-left" data-test="claim-guide-toggle" @click="showGuide = !showGuide">
                <span class="font-semibold text-white">{{ t('page.tokenCreator.claim.guideTitle') }}</span>
                <span class="text-gray-400 text-sm">{{ showGuide ? '−' : '+' }}</span>
            </button>
            <ol v-if="showGuide" class="px-4 pb-4 space-y-3 text-sm" data-test="claim-guide">
                <li v-for="(s, i) in guideSteps" :key="s" class="flex gap-3">
                    <span class="shrink-0 w-6 h-6 rounded-full bg-primary-500/20 text-primary-300 text-xs flex items-center justify-center">{{ i + 1 }}</span>
                    <div>
                        <p class="text-white font-medium">{{ t(`page.tokenCreator.claim.guide.${s}.title`) }}</p>
                        <p class="text-gray-400 whitespace-pre-line">{{ t(`page.tokenCreator.claim.guide.${s}.body`, { symbol: claim.symbol }) }}</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>
</template>
