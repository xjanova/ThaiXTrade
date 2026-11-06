<script setup>
/**
 * TPIX TRADE — หน้าโครงการสาธารณะของผู้สร้างเหรียญ (ผู้ซื้อเปิดดูได้โดยไม่ล็อกอิน)
 *
 * แสดงเฉพาะสิ่งที่ผู้สร้างยินยอมเผยแพร่ (เซิร์ฟเวอร์กรองมาแล้ว — CreatorPublicProfile)
 * เอกสารเป็นภาพลายน้ำ "ใช้ตรวจสอบโครงการบน tpix.online เท่านั้น" ที่เซิร์ฟเวอร์วาดใหม่ ไม่มีไฟล์ต้นฉบับ
 * บัตรผู้มีอำนาจลงนามไม่มาถึงหน้านี้เลย — ผู้ซื้อเห็นแค่ป้าย "ยืนยันตัวตนผ่าน Thaiprompt แล้ว"
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { formatBps } from '@/utils/creatorRegistration';

const { t, locale } = useTranslation();

const props = defineProps({
    project: { type: Object, required: true },
    risks: { type: Array, default: () => [] },
});

const p = computed(() => props.project);
const isCompany = computed(() => p.value.applicant_type === 'company');
const entityRows = computed(() => Object.entries(p.value.entity || {}).filter(([, v]) => !!v));
const linkRows = computed(() => Object.entries(p.value.links || {}).filter(([, v]) => typeof v === 'string' && v.startsWith('https://')));
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-GB') : '');
const riskText = (risk) => (locale.value === 'th' ? risk.th : risk.en);
</script>

<template>
    <Head :title="`${p.project_name} — ${t('page.creatorProject.headTitle')}`" />
    <AppLayout>
        <div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
            <header class="space-y-2">
                <p class="text-xs uppercase tracking-wider text-primary-400">{{ t('page.creatorProject.kicker') }}</p>
                <h1 class="text-2xl md:text-3xl font-bold text-white break-words" data-test="project-name">
                    {{ p.project_name }}
                    <span v-if="p.token_symbol" class="ml-2 align-middle rounded-full border border-accent-500/40 px-3 py-0.5 text-sm text-accent-300">{{ p.token_symbol }}</span>
                </h1>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span v-if="p.kyc_verified" class="rounded-full bg-trading-green/15 border border-trading-green/40 px-3 py-1 text-trading-green" data-test="kyc-badge">
                        ✓ {{ t('page.creatorProject.kycBadge') }}
                    </span>
                    <span class="rounded-full bg-white/5 border border-white/10 px-3 py-1 text-gray-300">
                        {{ isCompany ? t('page.creatorProject.company') : t('page.creatorProject.individual') }}
                    </span>
                    <span v-if="p.category" class="rounded-full bg-white/5 border border-white/10 px-3 py-1 text-gray-300">{{ t(`page.tokenCreator.categories.${p.category}`) }}</span>
                    <span v-if="p.approved_at" class="rounded-full bg-white/5 border border-white/10 px-3 py-1 text-gray-400">{{ t('page.creatorProject.approvedAt', { date: fmtDate(p.approved_at) }) }}</span>
                </div>
                <p class="text-xs text-gray-500">{{ t('page.creatorProject.notEndorsement') }}</p>
            </header>

            <!-- คำเตือน -->
            <section class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-2" data-test="risks">
                <h2 class="font-semibold text-amber-200">{{ t('page.creatorProject.risksTitle') }}</h2>
                <ul class="list-disc pl-5 space-y-1 text-sm text-amber-100/90">
                    <li v-for="risk in risks" :key="risk.id">{{ riskText(risk) }}</li>
                </ul>
            </section>

            <!-- ผู้สร้าง -->
            <section v-if="entityRows.length" class="glass-card p-5 space-y-2 text-sm" data-test="entity">
                <h2 class="text-lg font-semibold text-white">{{ t('page.creatorProject.creatorTitle') }}</h2>
                <dl class="grid md:grid-cols-2 gap-x-6 gap-y-1">
                    <template v-for="[key, value] in entityRows" :key="key">
                        <dt class="text-gray-400">{{ t(`page.creatorProject.entity.${key}`) }}</dt>
                        <dd class="text-white break-words">{{ value }}</dd>
                    </template>
                </dl>
            </section>

            <!-- โครงการ -->
            <section class="glass-card p-5 space-y-4 text-sm" data-test="about">
                <h2 class="text-lg font-semibold text-white">{{ t('page.creatorProject.aboutTitle') }}</h2>
                <div v-if="p.description">
                    <p class="text-gray-200 whitespace-pre-line break-words">{{ p.description }}</p>
                </div>
                <div v-if="p.team_description">
                    <h3 class="font-semibold text-white">{{ t('page.tokenCreator.fields.team_description') }}</h3>
                    <p class="text-gray-300 whitespace-pre-line break-words">{{ p.team_description }}</p>
                </div>
                <div v-if="p.use_of_funds">
                    <h3 class="font-semibold text-white">{{ t('page.tokenCreator.fields.use_of_funds') }}</h3>
                    <p class="text-gray-300 whitespace-pre-line break-words">{{ p.use_of_funds }}</p>
                </div>
                <p v-if="p.starter_allocation_bps !== null && p.starter_allocation_bps !== undefined" class="text-primary-300" data-test="starter">
                    {{ t('page.creatorProject.starter', { pct: formatBps(p.starter_allocation_bps) }) }}
                </p>
                <div v-if="linkRows.length" class="flex flex-wrap gap-3">
                    <a v-for="[key, url] in linkRows" :key="key" :href="url" target="_blank" rel="nofollow noopener noreferrer"
                       class="text-primary-400 hover:underline">{{ t(`page.tokenCreator.fields.${key}`) }} ↗</a>
                </div>
                <p v-if="linkRows.length" class="text-xs text-gray-500">{{ t('page.creatorProject.linksNote') }}</p>
            </section>

            <!-- เหรียญ -->
            <section v-if="(p.tokens || []).length" class="glass-card p-5 space-y-2 text-sm" data-test="tokens">
                <h2 class="text-lg font-semibold text-white">{{ t('page.creatorProject.tokensTitle') }}</h2>
                <ul class="divide-y divide-white/10">
                    <li v-for="token in p.tokens" :key="token.id" class="py-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="text-white font-semibold">{{ token.name }} ({{ token.symbol }})</span>
                        <code class="text-xs text-gray-500 break-all">{{ token.contract_address }}</code>
                        <a :href="token.whitepaper_url" class="ml-auto text-primary-400 hover:underline" data-test="whitepaper-link">{{ t('page.creatorProject.whitepaper') }}</a>
                        <p v-if="token.claimed && token.owner_wallet !== (p.wallet_address || '').toLowerCase()" class="w-full text-xs text-gray-400" data-test="claimed-token-note">
                            {{ t('page.creatorProject.claimedNote') }}
                            <span class="font-mono break-all">{{ token.owner_wallet }}</span>
                        </p>
                    </li>
                </ul>
            </section>

            <!-- เอกสาร -->
            <section v-if="(p.documents || []).length" class="glass-card p-5 space-y-3 text-sm" data-test="documents">
                <h2 class="text-lg font-semibold text-white">{{ t('page.creatorProject.documentsTitle') }}</h2>
                <p class="text-xs text-gray-400">{{ t('page.creatorProject.documentsNote') }}</p>
                <div class="grid sm:grid-cols-2 gap-4">
                    <figure v-for="doc in p.documents" :key="doc.uuid" class="space-y-1">
                        <img :src="doc.url" :alt="t(`page.tokenCreator.docs.types.${doc.type}`)" loading="lazy"
                             class="w-full rounded-lg border border-white/10 bg-white" data-test="document-image"
                             @contextmenu.prevent>
                        <figcaption class="text-xs text-gray-400">{{ t(`page.tokenCreator.docs.types.${doc.type}`) }}</figcaption>
                    </figure>
                </div>
            </section>

            <!-- ข้อตกลงที่ลงนาม -->
            <section v-if="p.agreement_version" class="glass-card p-5 space-y-1 text-xs text-gray-400" data-test="agreement">
                <p>{{ t('page.creatorProject.agreement', { version: p.agreement_version }) }}</p>
                <p class="font-mono break-all">SHA-256 {{ p.agreement_hash }}</p>
                <p class="font-mono break-all">{{ t('page.creatorProject.wallet') }}: {{ p.wallet_address }}</p>
            </section>

            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <Link href="/token-creator/projects" class="text-primary-400 hover:underline" data-test="all-projects">← {{ t('page.creatorProject.allProjects') }}</Link>
                <Link href="/token-factory" class="text-primary-400 hover:underline">← {{ t('page.creatorProject.back') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
