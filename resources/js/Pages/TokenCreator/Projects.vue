<script setup>
/**
 * TPIX TRADE — รวมโครงการผู้สร้างเหรียญที่ลงทะเบียนแล้ว (ผู้ซื้อเปิดดูได้โดยไม่ล็อกอิน)
 *
 * ตอบคำถาม "เหรียญนี้เป็นของใคร" — แสดงเฉพาะใบที่อนุมัติและยินยอมเผยแพร่ (เซิร์ฟเวอร์กรองแล้ว — CreatorPublicProfile::summary)
 * ไม่มีอีเมล เบอร์ ที่อยู่ เอกสาร หรือกระเป๋าผู้สร้างในหน้านี้ — รายละเอียดอยู่ในหน้าโครงการแต่ละหน้า
 *
 * Developed by Xman Studio
 */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    projects: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({ page: 1, last_page: 1, total: 0 }) },
    q: { type: String, default: '' },
});

const search = ref(props.q);
const searching = ref(false);

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-GB') : '');
const shortAddr = (a) => (a && a.length > 14 ? `${a.slice(0, 8)}…${a.slice(-6)}` : a);

function submit() {
    if (searching.value) return;
    const q = search.value.trim().slice(0, 64);
    router.get('/token-creator/projects', q ? { q } : {}, {
        preserveScroll: true,
        onStart: () => { searching.value = true; },
        onFinish: () => { searching.value = false; },
    });
}

function clear() {
    search.value = '';
    submit();
}
</script>

<template>
    <Head :title="t('page.creatorProjects.headTitle')" />
    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
            <header class="space-y-2">
                <p class="text-xs uppercase tracking-wider text-primary-400">{{ t('page.creatorProjects.kicker') }}</p>
                <h1 class="text-2xl md:text-3xl font-bold text-white">{{ t('page.creatorProjects.title') }}</h1>
                <p class="text-gray-400">{{ t('page.creatorProjects.subtitle') }}</p>
                <p class="text-xs text-gray-500">{{ t('page.creatorProjects.notEndorsement') }}</p>
            </header>

            <form class="flex flex-col sm:flex-row gap-2" role="search" data-test="search" @submit.prevent="submit">
                <input v-model="search" type="search" name="q" maxlength="64" class="trading-input flex-1"
                       :placeholder="t('page.creatorProjects.searchPlaceholder')" :aria-label="t('page.creatorProjects.searchPlaceholder')">
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary" :disabled="searching">{{ t('page.creatorProjects.search') }}</button>
                    <button v-if="q" type="button" class="btn-secondary" :disabled="searching" @click="clear">{{ t('page.creatorProjects.clear') }}</button>
                </div>
            </form>

            <p v-if="meta.total" class="text-sm text-gray-400" data-test="total">{{ t('page.creatorProjects.total', { n: meta.total }) }}</p>

            <!-- ว่าง -->
            <section v-if="!projects.length" class="glass-card p-8 text-center space-y-3" data-test="empty">
                <template v-if="q">
                    <p class="text-white font-semibold">{{ t('page.creatorProjects.noResults', { q }) }}</p>
                </template>
                <template v-else>
                    <h2 class="text-lg font-semibold text-white">{{ t('page.creatorProjects.emptyTitle') }}</h2>
                    <p class="text-sm text-gray-400">{{ t('page.creatorProjects.emptyDesc') }}</p>
                    <Link href="/token-creator" class="btn-primary inline-block">{{ t('page.creatorProjects.registerCta') }}</Link>
                </template>
            </section>

            <!-- รายชื่อ -->
            <ul v-else class="grid md:grid-cols-2 gap-4" data-test="projects">
                <li v-for="p in projects" :key="p.uuid" class="glass-card p-5 flex flex-col gap-3 text-sm" data-test="project">
                    <div class="space-y-1">
                        <Link :href="p.url" class="text-lg font-semibold text-white hover:text-primary-300 break-words">
                            {{ p.project_name }}
                            <span v-if="p.token_symbol" class="ml-1 align-middle rounded-full border border-accent-500/40 px-2 py-0.5 text-xs text-accent-300">{{ p.token_symbol }}</span>
                        </Link>
                        <p class="text-gray-300 break-words" data-test="display-name">
                            <template v-if="p.display_name">{{ p.display_name }}</template>
                            <span v-else class="text-gray-500 italic">{{ t('page.creatorProjects.nameHidden') }}</span>
                            <span v-if="p.display_name_en && p.display_name_en !== p.display_name" class="text-gray-500"> · {{ p.display_name_en }}</span>
                        </p>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-white/5 border border-white/10 px-2 py-0.5 text-gray-300">
                                {{ p.applicant_type === 'company' ? t('page.creatorProject.company') : t('page.creatorProject.individual') }}
                            </span>
                            <span v-if="p.registration_number" class="rounded-full bg-white/5 border border-white/10 px-2 py-0.5 text-gray-400">{{ t('page.creatorProjects.registrationNumber', { no: p.registration_number }) }}</span>
                            <span v-if="p.category" class="rounded-full bg-white/5 border border-white/10 px-2 py-0.5 text-gray-300">{{ t(`page.tokenCreator.categories.${p.category}`) }}</span>
                            <span v-if="p.approved_at" class="rounded-full bg-white/5 border border-white/10 px-2 py-0.5 text-gray-400">{{ t('page.creatorProject.approvedAt', { date: fmtDate(p.approved_at) }) }}</span>
                        </div>
                    </div>

                    <div class="border-t border-white/10 pt-3 flex-1">
                        <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">{{ t('page.creatorProjects.tokensLabel') }}</p>
                        <ul v-if="p.tokens.length" class="space-y-2" data-test="tokens">
                            <li v-for="token in p.tokens" :key="token.id" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-white font-semibold">{{ token.name }} ({{ token.symbol }})</span>
                                <code class="text-xs text-gray-500" :title="token.contract_address">{{ shortAddr(token.contract_address) }}</code>
                                <span class="ml-auto flex gap-3 text-xs">
                                    <a :href="token.whitepaper_url" class="text-primary-400 hover:underline" data-test="whitepaper-link">{{ t('page.creatorProject.whitepaper') }}</a>
                                    <a :href="token.explorer_url" target="_blank" rel="noopener noreferrer" class="text-primary-400 hover:underline" data-test="explorer-link">{{ t('page.creatorProjects.explorer') }} ↗</a>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-xs text-gray-500">{{ t('page.creatorProjects.noTokens') }}</p>
                    </div>

                    <Link :href="p.url" class="text-primary-400 hover:underline self-start" data-test="project-link">{{ t('page.creatorProjects.viewProject') }} →</Link>
                </li>
            </ul>

            <nav v-if="meta.last_page > 1" class="flex items-center justify-between text-sm" data-test="pagination">
                <Link v-if="meta.prev_url" :href="meta.prev_url" class="text-primary-400 hover:underline" preserve-scroll>← {{ t('page.creatorProjects.prev') }}</Link>
                <span v-else />
                <span class="text-gray-400">{{ t('page.creatorProjects.pageOf', { page: meta.page, last: meta.last_page }) }}</span>
                <Link v-if="meta.next_url" :href="meta.next_url" class="text-primary-400 hover:underline" preserve-scroll>{{ t('page.creatorProjects.next') }} →</Link>
                <span v-else />
            </nav>

            <p class="text-xs text-amber-200/80 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2">{{ t('page.creatorProjects.notListedNote') }}</p>

            <Link href="/token-factory" class="text-sm text-primary-400 hover:underline">← {{ t('page.creatorProject.back') }}</Link>
        </div>
    </AppLayout>
</template>
