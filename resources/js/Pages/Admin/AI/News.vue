<script setup>
/**
 * TPIX TRADE - AI News Management
 * Generate and manage AI-powered news articles
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { useForm, usePage, router } from '@inertiajs/vue3';

const props = defineProps({
    news: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

// Generate News Form
const generateForm = useForm({
    topic: '',
    category: 'market_update',
    language: 'th',
});

const categories = [
    { value: 'market_update', label: 'Market Update' },
    { value: 'analysis', label: 'Analysis' },
    { value: 'defi', label: 'DeFi' },
    { value: 'nft', label: 'NFT' },
    { value: 'regulation', label: 'Regulation' },
    { value: 'technology', label: 'Technology' },
    { value: 'tutorial', label: 'Tutorial' },
];

const languages = [
    { value: 'th', label: 'Thai' },
    { value: 'en', label: 'English' },
];

const generatedPreview = ref(null);

const generateNews = () => {
    generatedPreview.value = null;

    generateForm.post(route('admin.ai.news.generate'), {
        preserveScroll: true,
        onSuccess: () => {
            // Show the first item in the list as it should be the newly generated one
            if (props.news.data && props.news.data.length > 0) {
                generatedPreview.value = props.news.data[0];
            }
            generateForm.reset('topic');
        },
    });
};

// Edit functionality
const editingId = ref(null);
const editForm = useForm({
    title: '',
    content: '',
    summary: '',
    category: '',
    tags: [],
    status: '',
});

const startEdit = (article) => {
    editingId.value = article.id;
    editForm.title = article.title;
    editForm.content = article.content;
    editForm.summary = article.summary || '';
    editForm.category = article.category;
    editForm.tags = article.tags || [];
    editForm.status = article.status;
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.reset();
};

const saveEdit = (articleId) => {
    editForm.put(route('admin.ai.news.update', articleId), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
};

// Publish / Unpublish
const togglePublish = (article) => {
    router.patch(route('admin.ai.news.publish', article.id), {}, {
        preserveScroll: true,
    });
};

// Delete
const deletingId = ref(null);

const confirmDelete = (article) => {
    deletingId.value = article.id;
};

const cancelDelete = () => {
    deletingId.value = null;
};

const deleteArticle = (articleId) => {
    router.delete(route('admin.ai.news.destroy', articleId), {
        preserveScroll: true,
        onSuccess: () => {
            deletingId.value = null;
        },
    });
};

// Tag input
const tagInput = ref('');

const addTag = () => {
    const tag = tagInput.value.trim();
    if (tag && !editForm.tags.includes(tag)) {
        editForm.tags.push(tag);
    }
    tagInput.value = '';
};

const removeTag = (index) => {
    editForm.tags.splice(index, 1);
};

// Preview modal
const previewArticle = ref(null);
const showPreview = ref(false);

const openPreview = (article) => {
    previewArticle.value = article;
    showPreview.value = true;
};

const closePreview = () => {
    showPreview.value = false;
    previewArticle.value = null;
};

// Helpers
const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getCategoryColor = (category) => {
    const colors = {
        market_update: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
        analysis: 'bg-violet-500/10 text-violet-400 border-violet-500/20',
        defi: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        nft: 'bg-pink-500/10 text-pink-400 border-pink-500/20',
        regulation: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        technology: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',
        tutorial: 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20',
    };
    return colors[category] || 'bg-dark-500/10 text-dark-400 border-dark-500/20';
};

const getCategoryLabel = (category) => {
    const found = categories.find((c) => c.value === category);
    return found ? found.label : category;
};

const getStatusColor = (status) => {
    const colors = {
        draft: 'bg-dark-500/10 text-dark-400 border-dark-500/20',
        review: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
        published: 'bg-green-500/10 text-green-400 border-green-500/20',
        archived: 'bg-red-500/10 text-red-400 border-red-500/20',
    };
    return colors[status] || 'bg-dark-500/10 text-dark-400 border-dark-500/20';
};

const getLanguageLabel = (code) => {
    return code === 'th' ? 'TH' : 'EN';
};

// Pagination
const newsData = computed(() => {
    if (Array.isArray(props.news)) {
        return { data: props.news, links: [] };
    }
    return props.news;
});
</script>

<template>
    <div class="min-h-screen bg-dark-950 p-6">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white">AI News</h1>
                    <p class="text-dark-400 text-sm">Generate and manage AI-powered news articles</p>
                </div>
            </div>
            <div class="mt-4">
                <a
                    :href="route('admin.ai.index')"
                    class="inline-flex items-center gap-1 text-sm text-primary-400 hover:text-primary-300 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to AI Dashboard
                </a>
            </div>
        </div>

        <!-- Flash Messages -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="flash.success" class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-sm">
                {{ flash.success }}
            </div>
        </Transition>
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="flash.error" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                {{ flash.error }}
            </div>
        </Transition>

        <!-- Generate News Section -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-white/5">
                <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Generate News Article
                </h2>
            </div>

            <div class="p-6">
                <form @submit.prevent="generateNews" class="space-y-5">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                        <!-- Topic Input -->
                        <div class="lg:col-span-3">
                            <label class="block text-sm font-medium text-dark-300 mb-2">Topic / Prompt</label>
                            <textarea
                                v-model="generateForm.topic"
                                rows="3"
                                placeholder="Describe the news article topic, e.g. 'Bitcoin price breaks $100,000 for the first time in 2026...'"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200 resize-none"
                            ></textarea>
                            <p v-if="generateForm.errors.topic" class="mt-1 text-sm text-red-400">{{ generateForm.errors.topic }}</p>
                        </div>

                        <!-- Category -->
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-2">Category</label>
                            <select
                                v-model="generateForm.category"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                            >
                                <option v-for="cat in categories" :key="cat.value" :value="cat.value">
                                    {{ cat.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Language -->
                        <div>
                            <label class="block text-sm font-medium text-dark-300 mb-2">Language</label>
                            <select
                                v-model="generateForm.language"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                            >
                                <option v-for="lang in languages" :key="lang.value" :value="lang.value">
                                    {{ lang.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Generate Button -->
                        <div class="flex items-end">
                            <button
                                type="submit"
                                :disabled="generateForm.processing || !generateForm.topic"
                                class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl font-medium transition-all duration-200 bg-gradient-to-r from-emerald-500 to-teal-600 text-white hover:from-emerald-600 hover:to-teal-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-dark-900 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-emerald-500/25"
                            >
                                <svg v-if="generateForm.processing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                <span>{{ generateForm.processing ? 'Generating...' : 'Generate Article' }}</span>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Generated Preview -->
                <Transition
                    enter-active-class="transition ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4"
                    enter-to-class="opacity-100 translate-y-0"
                >
                    <div v-if="generatedPreview" class="mt-6 bg-dark-800/50 border border-emerald-500/20 rounded-xl p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-sm font-medium text-emerald-400">Article Generated Successfully</span>
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">{{ generatedPreview.title }}</h3>
                        <p v-if="generatedPreview.summary" class="text-sm text-dark-300 mb-3">{{ generatedPreview.summary }}</p>
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                :class="getCategoryColor(generatedPreview.category)"
                            >
                                {{ getCategoryLabel(generatedPreview.category) }}
                            </span>
                            <span class="text-xs text-dark-500">Status: Draft</span>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>

        <!-- News Articles Table -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
            <div class="px-6 py-4 border-b border-white/5 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                    </svg>
                    News Articles
                </h2>
                <span class="text-sm text-dark-400">{{ newsData.data.length }} articles</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-dark-800/50">
                            <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Title</th>
                            <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Category</th>
                            <th class="text-center text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Lang</th>
                            <th class="text-center text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Status</th>
                            <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Date</th>
                            <th class="text-right text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Views</th>
                            <th class="text-right text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5">Actions</th>
                        </tr>
                    </thead>

                    <!-- Loading State -->
                    <tbody v-if="!newsData.data || newsData.data.length === 0">
                        <tr>
                            <td colspan="7" class="py-16 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <svg class="w-12 h-12 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                                    </svg>
                                    <p class="text-dark-400 text-sm">No news articles yet</p>
                                    <p class="text-dark-500 text-xs">Generate your first article above</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <tbody v-else>
                        <template v-for="article in newsData.data" :key="article.id">
                            <!-- Normal Row -->
                            <tr
                                v-if="editingId !== article.id && deletingId !== article.id"
                                class="border-b border-white/5 hover:bg-white/5 transition-colors"
                            >
                                <td class="py-3 px-4">
                                    <button
                                        @click="openPreview(article)"
                                        class="text-sm font-medium text-white hover:text-primary-400 transition-colors text-left line-clamp-2 max-w-xs"
                                    >
                                        {{ article.title }}
                                    </button>
                                </td>
                                <td class="py-3 px-4">
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                        :class="getCategoryColor(article.category)"
                                    >
                                        {{ getCategoryLabel(article.category) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="text-xs font-medium text-dark-300 bg-dark-800/50 px-2 py-1 rounded">
                                        {{ getLanguageLabel(article.language_code) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize"
                                        :class="getStatusColor(article.status)"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full mr-1.5 bg-current opacity-60"></span>
                                        {{ article.status }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-sm text-dark-400">
                                    {{ formatDate(article.published_at || article.created_at) }}
                                </td>
                                <td class="py-3 px-4 text-right text-sm text-dark-400">
                                    {{ article.views.toLocaleString() }}
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Edit -->
                                        <button
                                            @click="startEdit(article)"
                                            class="p-1.5 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                                            title="Edit"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <!-- Publish Toggle -->
                                        <button
                                            @click="togglePublish(article)"
                                            class="p-1.5 rounded-lg transition-colors"
                                            :class="article.status === 'published'
                                                ? 'text-green-400 hover:text-green-300 hover:bg-green-500/10'
                                                : 'text-dark-400 hover:text-green-400 hover:bg-green-500/10'"
                                            :title="article.status === 'published' ? 'Unpublish' : 'Publish'"
                                        >
                                            <svg v-if="article.status === 'published'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            </svg>
                                        </button>
                                        <!-- Delete -->
                                        <button
                                            @click="confirmDelete(article)"
                                            class="p-1.5 rounded-lg text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                            title="Delete"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Edit Row -->
                            <tr v-else-if="editingId === article.id" class="border-b border-primary-500/20 bg-primary-500/5">
                                <td colspan="7" class="p-4">
                                    <div class="space-y-4">
                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-xs font-medium text-dark-300 mb-1">Title</label>
                                                <input
                                                    v-model="editForm.title"
                                                    type="text"
                                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-dark-300 mb-1">Category</label>
                                                <select
                                                    v-model="editForm.category"
                                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                                                >
                                                    <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-medium text-dark-300 mb-1">Summary</label>
                                            <textarea
                                                v-model="editForm.summary"
                                                rows="2"
                                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200 resize-none"
                                            ></textarea>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-medium text-dark-300 mb-1">Content</label>
                                            <textarea
                                                v-model="editForm.content"
                                                rows="6"
                                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200 resize-y font-mono"
                                            ></textarea>
                                        </div>

                                        <!-- Tags -->
                                        <div>
                                            <label class="block text-xs font-medium text-dark-300 mb-1">Tags</label>
                                            <div class="flex flex-wrap gap-2 mb-2">
                                                <span
                                                    v-for="(tag, index) in editForm.tags"
                                                    :key="index"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-primary-500/10 text-primary-400 text-xs font-medium border border-primary-500/20"
                                                >
                                                    {{ tag }}
                                                    <button @click="removeTag(index)" class="hover:text-red-400 transition-colors">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </span>
                                            </div>
                                            <div class="flex gap-2">
                                                <input
                                                    v-model="tagInput"
                                                    type="text"
                                                    placeholder="Add tag..."
                                                    class="flex-1 bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2 text-white text-sm placeholder-dark-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                                                    @keydown.enter.prevent="addTag"
                                                />
                                                <button
                                                    type="button"
                                                    @click="addTag"
                                                    class="px-3 py-2 rounded-xl bg-dark-700 text-dark-300 hover:text-white hover:bg-dark-600 transition-colors text-sm"
                                                >
                                                    Add
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-end gap-3 pt-2">
                                            <button
                                                @click="cancelEdit"
                                                class="px-4 py-2 rounded-xl text-sm font-medium text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                @click="saveEdit(article.id)"
                                                :disabled="editForm.processing"
                                                class="px-4 py-2 rounded-xl text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50"
                                            >
                                                {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Delete Confirmation Row -->
                            <tr v-else-if="deletingId === article.id" class="border-b border-red-500/20 bg-red-500/5">
                                <td colspan="7" class="p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                            </svg>
                                            <span class="text-sm text-white">
                                                Are you sure you want to delete "<span class="font-medium">{{ article.title }}</span>"?
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button
                                                @click="cancelDelete"
                                                class="px-4 py-2 rounded-xl text-sm font-medium text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                @click="deleteArticle(article.id)"
                                                class="px-4 py-2 rounded-xl text-sm font-medium bg-red-500 text-white hover:bg-red-600 transition-colors"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="newsData.links && newsData.links.length > 3" class="px-6 py-4 border-t border-white/5 flex items-center justify-center gap-1">
                <template v-for="link in newsData.links" :key="link.label">
                    <button
                        v-if="link.url"
                        @click="router.get(link.url, {}, { preserveScroll: true })"
                        class="px-3 py-1.5 rounded-lg text-sm transition-colors"
                        :class="link.active
                            ? 'bg-primary-500 text-white'
                            : 'text-dark-400 hover:text-white hover:bg-white/5'"
                        v-html="link.label"
                    ></button>
                    <span
                        v-else
                        class="px-3 py-1.5 text-sm text-dark-600"
                        v-html="link.label"
                    ></span>
                </template>
            </div>
        </div>

        <!-- Preview Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showPreview && previewArticle" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="fixed inset-0 bg-dark-950/80 backdrop-blur-sm" @click="closePreview"></div>

                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 scale-95 translate-y-4"
                        enter-to-class="opacity-100 scale-100 translate-y-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100 scale-100 translate-y-0"
                        leave-to-class="opacity-0 scale-95 translate-y-4"
                    >
                        <div
                            v-if="showPreview"
                            class="relative w-full max-w-3xl bg-dark-900 border border-white/10 rounded-2xl shadow-glass-lg overflow-hidden"
                        >
                            <!-- Modal Header -->
                            <div class="flex items-center justify-between px-6 py-4 border-b border-white/5">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                        :class="getCategoryColor(previewArticle.category)"
                                    >
                                        {{ getCategoryLabel(previewArticle.category) }}
                                    </span>
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize"
                                        :class="getStatusColor(previewArticle.status)"
                                    >
                                        {{ previewArticle.status }}
                                    </span>
                                </div>
                                <button
                                    @click="closePreview"
                                    class="p-1 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Modal Body -->
                            <div class="px-6 py-6 max-h-[70vh] overflow-y-auto">
                                <h2 class="text-xl font-bold text-white mb-3">{{ previewArticle.title }}</h2>

                                <div v-if="previewArticle.summary" class="text-sm text-dark-300 mb-4 pb-4 border-b border-white/5">
                                    {{ previewArticle.summary }}
                                </div>

                                <div class="prose prose-invert prose-sm max-w-none" v-html="previewArticle.content"></div>

                                <!-- Tags -->
                                <div v-if="previewArticle.tags && previewArticle.tags.length > 0" class="mt-6 pt-4 border-t border-white/5">
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="tag in previewArticle.tags"
                                            :key="tag"
                                            class="px-2.5 py-1 rounded-lg bg-dark-800/50 text-dark-300 text-xs font-medium border border-dark-600"
                                        >
                                            #{{ tag }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Meta -->
                                <div class="mt-4 flex items-center gap-4 text-xs text-dark-500">
                                    <span>{{ getLanguageLabel(previewArticle.language_code) }}</span>
                                    <span>{{ previewArticle.views }} views</span>
                                    <span>{{ formatDate(previewArticle.created_at) }}</span>
                                    <span v-if="previewArticle.ai_model">Model: {{ previewArticle.ai_model }}</span>
                                </div>
                            </div>

                            <!-- Modal Footer -->
                            <div class="px-6 py-4 border-t border-white/5 bg-dark-800/30 flex items-center justify-end gap-3">
                                <button
                                    @click="startEdit(previewArticle); closePreview()"
                                    class="px-4 py-2 rounded-xl text-sm font-medium text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                >
                                    Edit
                                </button>
                                <button
                                    @click="togglePublish(previewArticle); closePreview()"
                                    class="px-4 py-2 rounded-xl text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                                >
                                    {{ previewArticle.status === 'published' ? 'Unpublish' : 'Publish' }}
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
