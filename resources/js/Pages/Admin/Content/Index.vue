<script setup>
/**
 * TPIX TRADE — Admin Content Management
 * สร้างบทความด้วย AI, แก้ไข, ตั้งเวลา publish
 * รองรับ: Groq (หลาย model), AI image (Pollinations.ai ฟรี)
 * Developed by Xman Studio
 */
import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import { decodeHtmlEntities } from '@/utils/sanitize';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const numLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));
const fmtNum = (n) => Number(n ?? 0).toLocaleString(numLocale.value);

// ป้าย « Previous / Next » ของ Laravel มาเป็นภาษาอังกฤษเสมอ — แปลฝั่งหน้าเว็บให้ตามภาษาที่เลือก
function pageLabel(label) {
    const s = decodeHtmlEntities(label);
    if (/previous/i.test(s) || s.includes('«')) return '« ' + t('common.previous');
    if (/next/i.test(s) || s.includes('»')) return t('common.next') + ' »';
    return s;
}

const props = defineProps({
    articles: Object,
    stats: Object,
    filters: Object,
});

// ฟอร์มสร้างบทความ AI
const showGenerator = ref(false);
const generateForm = useForm({
    topic: '',
    category: 'tpix_chain',
    language: 'th',
    model: 'llama-3.3-70b-versatile',
});

function generateArticle() {
    generateForm.post('/admin/content/generate', {
        preserveScroll: true,
        onSuccess: () => {
            generateForm.reset();
            showGenerator.value = false;
        },
    });
}

// ฟอร์มแก้ไขบทความ
const editingId = ref(null);
const editForm = useForm({ title: '', status: '', scheduled_at: '' });

function startEdit(article) {
    editingId.value = article.id;
    editForm.title = article.title;
    editForm.status = article.status;
    editForm.scheduled_at = article.scheduled_at || '';
}

function saveEdit() {
    editForm.put(`/admin/content/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function deleteArticle(id) {
    if (confirm(t('admin.content.confirmDelete'))) {
        router.delete(`/admin/content/${id}`, { preserveScroll: true });
    }
}

function publishArticle(id) {
    router.put(`/admin/content/${id}`, { status: 'published' }, { preserveScroll: true });
}

const categories = computed(() => [
    { value: 'news', label: t('admin.content.categories.news') },
    { value: 'analysis', label: t('admin.content.categories.analysis') },
    { value: 'tutorial', label: t('admin.content.categories.tutorial') },
    { value: 'tpix_chain', label: 'TPIX Chain' },
    { value: 'defi', label: 'DeFi' },
    { value: 'technology', label: t('admin.content.categories.technology') },
]);

const categoryLabel = (value) => categories.value.find((c) => c.value === value)?.label || value;

const STATUSES = ['draft', 'scheduled', 'published', 'archived'];
const statusLabel = (status) => (STATUSES.includes(status) ? t(`admin.content.status.${status}`) : status);

const models = computed(() => [
    { value: 'llama-3.3-70b-versatile', label: `Llama 3.3 70B (${t('admin.content.models.recommended')})` },
    { value: 'llama-3.1-8b-instant', label: `Llama 3.1 8B (${t('admin.content.models.fast')})` },
    { value: 'mixtral-8x7b-32768', label: 'Mixtral 8x7B' },
    { value: 'gemma2-9b-it', label: 'Gemma 2 9B' },
]);

// หัวข้อแนะนำ — ตามภาษาที่เลือกอยู่ (กดแล้วใส่ลงช่องหัวข้อเป็นภาษานั้น)
const topicSuggestions = computed(() =>
    ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'].map((k) => t(`admin.content.suggestions.${k}`)),
);

// Filters
const filterStatus = ref(props.filters?.status || '');
const filterLanguage = ref(props.filters?.language || '');
const searchQuery = ref(props.filters?.search || '');
let searchTimeout = null;

function applyFilters() {
    router.get('/admin/content', {
        status: filterStatus.value || undefined,
        language: filterLanguage.value || undefined,
        search: searchQuery.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

watch([filterStatus, filterLanguage], applyFilters);
watch(searchQuery, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 400);
});

function useSuggestion(topic) {
    generateForm.topic = topic;
}

function formatDate(d) {
    return d ? new Date(d).toLocaleDateString(numLocale.value, { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
}

const statusColors = {
    draft: 'bg-dark-600 text-dark-300',
    scheduled: 'bg-yellow-500/20 text-yellow-400',
    published: 'bg-trading-green/20 text-trading-green',
    archived: 'bg-dark-700 text-dark-500',
};
</script>

<template>
    <Head :title="t('admin.content.title')" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.content.title') }}</h1>
                    <p class="text-dark-400 text-sm">{{ t('admin.content.subtitle') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/admin/content/create"
                        class="px-4 py-2 bg-dark-800 border border-white/10 text-white rounded-xl text-sm font-medium hover:bg-dark-700 transition-all">
                        + {{ t('admin.content.writeArticle') }}
                    </Link>
                    <button @click="showGenerator = !showGenerator"
                        class="px-4 py-2 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl text-sm font-medium hover:shadow-lg hover:shadow-primary-500/20 transition-all">
                        🤖 {{ t('admin.content.generateWithAi') }}
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-white">{{ stats?.total || 0 }}</p>
                    <p class="text-xs text-dark-400">{{ t('admin.content.stats.total') }}</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-trading-green">{{ stats?.published || 0 }}</p>
                    <p class="text-xs text-dark-400">{{ t('admin.content.stats.published') }}</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-yellow-400">{{ stats?.draft || 0 }}</p>
                    <p class="text-xs text-dark-400">{{ t('admin.content.stats.draft') }}</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-primary-400">{{ stats?.scheduled || 0 }}</p>
                    <p class="text-xs text-dark-400">{{ t('admin.content.stats.scheduled') }}</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-accent-400">{{ stats?.ai_generated || 0 }}</p>
                    <p class="text-xs text-dark-400">{{ t('admin.content.stats.aiGenerated') }}</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-white">{{ fmtNum(stats?.total_views) }}</p>
                    <p class="text-xs text-dark-400">{{ t('admin.content.stats.views') }}</p>
                </div>
            </div>

            <!-- AI Generator Panel -->
            <div v-if="showGenerator" class="glass-card border border-primary-500/20 space-y-4">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">🤖 {{ t('admin.content.generator.title') }}</h3>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-dark-300 mb-1 block">{{ t('admin.content.generator.topic') }}</label>
                        <textarea v-model="generateForm.topic" rows="3" :placeholder="t('admin.content.generator.topicPlaceholder')"
                            class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-500 focus:border-primary-500 outline-none"></textarea>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm text-dark-300 mb-1 block">{{ t('admin.content.generator.category') }}</label>
                            <select v-model="generateForm.category" class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                                <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="text-sm text-dark-300 mb-1 block">{{ t('admin.content.generator.language') }}</label>
                                <select v-model="generateForm.language" class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                                    <option value="th">{{ t('admin.content.languages.th') }}</option>
                                    <option value="en">{{ t('admin.content.languages.en') }}</option>
                                </select>
                            </div>
                            <div class="flex-1">
                                <label class="text-sm text-dark-300 mb-1 block">{{ t('admin.content.generator.model') }}</label>
                                <select v-model="generateForm.model" class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                                    <option v-for="m in models" :key="m.value" :value="m.value">{{ m.label }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Topic Suggestions -->
                <div>
                    <p class="text-xs text-dark-500 mb-2">💡 {{ t('admin.content.generator.suggestions') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in topicSuggestions" :key="s" @click="useSuggestion(s)"
                            class="px-3 py-1 bg-white/5 border border-white/10 rounded-lg text-xs text-dark-300 hover:bg-primary-500/10 hover:border-primary-500/30 transition-all">
                            {{ s }}
                        </button>
                    </div>
                </div>

                <button @click="generateArticle" :disabled="generateForm.processing || !generateForm.topic"
                    class="w-full py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-medium hover:shadow-lg disabled:opacity-50 transition-all">
                    {{ generateForm.processing ? '🔄 ' + t('admin.content.generator.generating') : '✨ ' + t('admin.content.generator.submit') }}
                </button>
            </div>

            <!-- Filter Bar -->
            <div class="flex flex-wrap items-center gap-3 p-4 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
                <input v-model="searchQuery" type="text" :placeholder="t('admin.content.filters.search')"
                    class="flex-1 min-w-[200px] bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-500 focus:outline-none focus:border-primary-500" />
                <select v-model="filterStatus" class="bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                    <option value="">{{ t('admin.content.filters.allStatuses') }}</option>
                    <option value="draft">{{ t('admin.content.status.draft') }}</option>
                    <option value="scheduled">{{ t('admin.content.status.scheduled') }}</option>
                    <option value="published">{{ t('admin.content.status.published') }}</option>
                    <option value="archived">{{ t('admin.content.status.archived') }}</option>
                </select>
                <select v-model="filterLanguage" class="bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                    <option value="">{{ t('admin.content.filters.allLanguages') }}</option>
                    <option value="th">{{ t('admin.content.languages.th') }}</option>
                    <option value="en">{{ t('admin.content.languages.en') }}</option>
                </select>
            </div>

            <!-- Articles Table -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/10 text-dark-400 text-xs uppercase">
                            <th class="text-left p-4">{{ t('admin.content.table.article') }}</th>
                            <th class="text-left p-4 hidden md:table-cell">{{ t('admin.content.table.category') }}</th>
                            <th class="text-left p-4 hidden sm:table-cell">{{ t('admin.content.table.status') }}</th>
                            <th class="text-left p-4 hidden lg:table-cell">{{ t('admin.content.table.views') }}</th>
                            <th class="text-left p-4 hidden lg:table-cell">{{ t('admin.content.table.date') }}</th>
                            <th class="text-right p-4">{{ t('admin.content.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="article in articles?.data" :key="article.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors group">
                            <td class="p-4">
                                <Link :href="`/admin/content/${article.id}/edit`" class="flex items-center gap-3 group-hover:opacity-80">
                                    <img v-if="article.cover_image" :src="article.cover_image.startsWith('/') ? article.cover_image : '/storage/' + article.cover_image" class="w-14 h-10 rounded-lg object-cover flex-shrink-0" />
                                    <div v-else class="w-14 h-10 rounded-lg bg-dark-700 flex items-center justify-center text-xs flex-shrink-0">📝</div>
                                    <div class="min-w-0">
                                        <p class="text-white text-sm font-medium line-clamp-1">{{ article.title }}</p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-dark-500 text-xs">{{ article.language === 'th' ? 'TH' : 'EN' }}</span>
                                            <span v-if="article.is_ai_generated" class="text-accent-400 text-[10px]">AI</span>
                                        </div>
                                    </div>
                                </Link>
                            </td>
                            <td class="p-4 text-dark-300 text-sm hidden md:table-cell">
                                <span class="px-2 py-0.5 bg-dark-700 rounded text-xs">{{ categoryLabel(article.category) }}</span>
                            </td>
                            <td class="p-4 hidden sm:table-cell">
                                <span :class="['px-2 py-0.5 rounded-lg text-xs', statusColors[article.status]]">
                                    {{ statusLabel(article.status) }}
                                </span>
                            </td>
                            <td class="p-4 text-dark-300 text-sm hidden lg:table-cell">{{ fmtNum(article.views) }}</td>
                            <td class="p-4 text-dark-400 text-xs hidden lg:table-cell">{{ formatDate(article.created_at) }}</td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <Link :href="`/admin/content/${article.id}/edit`"
                                        class="px-2.5 py-1 bg-primary-500/20 text-primary-400 rounded-lg text-xs hover:bg-primary-500/30 transition-colors">
                                        {{ t('admin.content.actions.edit') }}
                                    </Link>
                                    <button v-if="article.status === 'draft'" @click="publishArticle(article.id)"
                                        class="px-2.5 py-1 bg-trading-green/20 text-trading-green rounded-lg text-xs hover:bg-trading-green/30 transition-colors">
                                        {{ t('admin.content.actions.publish') }}
                                    </button>
                                    <button @click="deleteArticle(article.id)"
                                        :title="t('admin.content.actions.delete')" :aria-label="t('admin.content.actions.delete')"
                                        class="px-2.5 py-1 bg-trading-red/10 text-trading-red rounded-lg text-xs hover:bg-trading-red/20 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="!articles?.data?.length" class="text-center py-16 text-dark-500">
                    <p class="text-5xl mb-3">📝</p>
                    <p class="text-lg text-dark-400 mb-1">{{ t('admin.content.empty.title') }}</p>
                    <p class="text-sm">{{ t('admin.content.empty.hint') }}</p>
                </div>

                <!-- Pagination -->
                <div v-if="articles?.links?.length > 3" class="flex items-center justify-between px-4 py-3 border-t border-white/5">
                    <p class="text-xs text-dark-500">
                        {{ t('admin.content.pagination', { from: articles.from, to: articles.to, total: articles.total }) }}
                    </p>
                    <div class="flex gap-1">
                        <template v-for="link in articles.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url"
                                class="px-3 py-1 rounded-lg text-xs transition-colors"
                                :class="link.active ? 'bg-primary-500 text-white' : 'text-dark-400 hover:bg-white/5'"
                                preserve-scroll>{{ pageLabel(link.label) }}</Link>
                            <span v-else class="px-3 py-1 text-xs text-dark-600">{{ pageLabel(link.label) }}</span>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
