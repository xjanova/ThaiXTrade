<script setup>
/**
 * TPIX TRADE — Blog Article Show page
 * แสดงบทความเดี่ยว + AI-generated content
 * Developed by Xman Studio
 */
import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({ slug: String });
const article = ref(null);
const isLoading = ref(true);

async function fetchArticle() {
    try {
        const { data } = await axios.get(`/api/v1/articles/${props.slug}`);
        article.value = data.data;
    } catch { article.value = null; }
    isLoading.value = false;
}

function formatDate(d) {
    return new Date(d).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' });
}

onMounted(fetchArticle);
</script>

<template>
    <Head :title="article?.title || 'Article'" />
    <AppLayout>
        <div class="max-w-4xl mx-auto">
            <!-- Loading -->
            <div v-if="isLoading" class="animate-pulse space-y-4">
                <div class="h-8 bg-dark-700 rounded w-3/4"></div>
                <div class="h-64 bg-dark-700 rounded-2xl"></div>
                <div class="space-y-3"><div v-for="i in 5" :key="i" class="h-4 bg-dark-700 rounded" :class="i===5&&'w-2/3'"></div></div>
            </div>

            <!-- Article -->
            <article v-else-if="article" class="space-y-6">
                <!-- Breadcrumb -->
                <div class="flex items-center gap-2 text-sm text-dark-400">
                    <Link href="/blog" class="hover:text-primary-400 transition-colors">Blog</Link>
                    <span>/</span>
                    <span class="text-dark-300">{{ article.category }}</span>
                </div>

                <!-- Title -->
                <h1 class="text-3xl md:text-4xl font-bold text-white leading-tight">{{ article.title }}</h1>

                <!-- Meta -->
                <div class="flex flex-wrap items-center gap-4 text-sm text-dark-400">
                    <span>{{ article.author_name }}</span>
                    <span>·</span>
                    <span>{{ formatDate(article.published_at) }}</span>
                    <span>·</span>
                    <span>👁 {{ article.views }} อ่าน</span>
                    <span v-if="article.is_ai_generated"
                        class="px-2 py-0.5 bg-accent-500/20 text-accent-400 rounded-lg text-xs">🤖 AI Generated</span>
                </div>

                <!-- Cover Image -->
                <div v-if="article.cover_image" class="rounded-2xl overflow-hidden">
                    <img :src="article.cover_image" :alt="article.title" class="w-full h-auto" />
                </div>

                <!-- Tags -->
                <div v-if="article.tags?.length" class="flex flex-wrap gap-2">
                    <span v-for="tag in article.tags" :key="tag"
                        class="px-3 py-1 bg-white/5 border border-white/10 rounded-lg text-xs text-dark-300">
                        #{{ tag }}
                    </span>
                </div>

                <!-- Content -->
                <div class="prose prose-invert prose-lg max-w-none
                    prose-headings:text-white prose-p:text-dark-200 prose-a:text-primary-400
                    prose-strong:text-white prose-li:text-dark-200"
                    v-html="article.content">
                </div>

                <!-- Back -->
                <div class="pt-8 border-t border-white/10">
                    <Link href="/blog" class="text-primary-400 hover:text-primary-300 transition-colors">← กลับไปหน้า Blog</Link>
                </div>
            </article>

            <!-- Not Found -->
            <div v-else class="text-center py-20">
                <span class="text-6xl mb-4 block">🔍</span>
                <h3 class="text-xl font-semibold text-white mb-2">ไม่พบบทความ</h3>
                <Link href="/blog" class="text-primary-400">← กลับไปหน้า Blog</Link>
            </div>
        </div>
    </AppLayout>
</template>
