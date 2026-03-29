<script setup>
/**
 * TPIX TRADE — Blog / Articles page
 * แสดงบทความที่ publish แล้ว (AI-generated + manual)
 * Developed by Xman Studio
 */
import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();
const articles = ref([]);
const isLoading = ref(true);
const currentPage = ref(1);
const totalPages = ref(1);
const activeCategory = ref('all');
const activeLanguage = ref('th');

const categories = [
    { id: 'all', label: 'ทั้งหมด' },
    { id: 'news', label: '📰 ข่าว' },
    { id: 'analysis', label: '📊 วิเคราะห์' },
    { id: 'tutorial', label: '📖 สอนใช้งาน' },
    { id: 'tpix_chain', label: '⛓️ TPIX Chain' },
    { id: 'defi', label: '🏦 DeFi' },
    { id: 'technology', label: '🔬 เทคโนโลยี' },
];

async function fetchArticles() {
    isLoading.value = true;
    try {
        const params = { page: currentPage.value, language: activeLanguage.value };
        if (activeCategory.value !== 'all') params.category = activeCategory.value;
        const { data } = await axios.get('/api/v1/articles', { params });
        articles.value = data.data || [];
        totalPages.value = data.meta?.last_page || 1;
    } catch { articles.value = []; }
    isLoading.value = false;
}

function setCategory(cat) {
    activeCategory.value = cat;
    currentPage.value = 1;
    fetchArticles();
}

function formatDate(d) {
    return new Date(d).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' });
}

onMounted(fetchArticles);
</script>

<template>
    <Head title="Blog — TPIX TRADE" />
    <AppLayout>
        <div class="max-w-6xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">{{ t('blog.title') }}</h1>
                <p class="text-dark-400">{{ t('blog.subtitle') }}</p>
            </div>

            <!-- Categories -->
            <div class="flex flex-wrap gap-2 mb-8 overflow-x-auto pb-1">
                <button v-for="cat in categories" :key="cat.id" @click="setCategory(cat.id)"
                    :class="['px-4 py-2 rounded-xl text-sm font-medium transition-all',
                        activeCategory === cat.id
                            ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                            : 'bg-white/5 text-dark-400 border border-white/10 hover:border-primary-500/30']">
                    {{ cat.label }}
                </button>
            </div>

            <!-- Loading -->
            <div v-if="isLoading" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="i in 6" :key="i" class="glass-card animate-pulse">
                    <div class="h-48 bg-dark-700 rounded-xl mb-4"></div>
                    <div class="h-4 bg-dark-700 rounded w-3/4 mb-2"></div>
                    <div class="h-3 bg-dark-700 rounded w-1/2"></div>
                </div>
            </div>

            <!-- Articles Grid -->
            <div v-else-if="articles.length" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <Link v-for="article in articles" :key="article.id" :href="`/blog/${article.slug}`"
                    class="glass-card group hover:border-primary-500/30 transition-all">
                    <div class="h-48 rounded-xl overflow-hidden mb-4 bg-dark-700">
                        <img v-if="article.cover_image" :src="article.cover_image" :alt="article.title"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                        <div v-else class="w-full h-full flex items-center justify-center bg-gradient-to-br from-primary-500/10 to-accent-500/10">
                            <span class="text-4xl">📝</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="px-2 py-0.5 bg-primary-500/20 text-primary-400 text-xs rounded-lg">{{ article.category }}</span>
                        <span class="text-dark-500 text-xs">{{ formatDate(article.published_at) }}</span>
                    </div>
                    <h3 class="text-white font-semibold mb-2 group-hover:text-primary-400 transition-colors line-clamp-2">{{ article.title }}</h3>
                    <p class="text-dark-400 text-sm line-clamp-2">{{ article.summary }}</p>
                    <div class="flex items-center justify-between mt-4 text-xs text-dark-500">
                        <span>{{ article.author_name }}</span>
                        <span>👁 {{ article.views }}</span>
                    </div>
                </Link>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-20 glass-card">
                <span class="text-6xl mb-4 block">📝</span>
                <h3 class="text-xl font-semibold text-white mb-2">{{ t('blog.noArticles') }}</h3>
                <p class="text-dark-400">บทความจะถูกสร้างโดย AI โปรดรอสักครู่</p>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex justify-center gap-2 mt-8">
                <button v-for="p in totalPages" :key="p" @click="currentPage = p; fetchArticles()"
                    :class="['w-10 h-10 rounded-xl text-sm transition-all',
                        currentPage === p ? 'bg-primary-500 text-white' : 'bg-white/5 text-dark-400 hover:bg-white/10']">
                    {{ p }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
