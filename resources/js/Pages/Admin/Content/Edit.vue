<script setup>
/**
 * TPIX TRADE — Article Editor (Full Page)
 * สร้าง/แก้ไขบทความ + AI image generation (หลาย provider)
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';
import { sanitizeHtml } from '@/utils/sanitize';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    article: Object,
    imageProviders: { type: Object, default: () => ({}) },
});

const isNew = computed(() => !props.article.id);

const form = useForm({
    title: props.article.title || '',
    content: props.article.content || '',
    summary: props.article.summary || '',
    category: props.article.category || 'news',
    language: props.article.language || 'th',
    tags: props.article.tags || [],
    status: props.article.status || 'draft',
    scheduled_at: props.article.scheduled_at || '',
    seo_title: props.article.seo_title || '',
    seo_description: props.article.seo_description || '',
});

const activeTab = ref('content');
const showPreview = ref(false);
const tagInput = ref('');
const showSaved = ref(false);
const imageLoading = ref(false);
const selectedProvider = ref('auto');
const generatedImageUrl = ref(null);
const imageError = ref('');

// labelKey → t() ตอน render ให้เปลี่ยนภาษาได้ทันที · TPIX Chain / DeFi เป็นชื่อเฉพาะ ไม่แปล
const categories = [
    { value: 'news', labelKey: 'admin.contentEdit.categories.news', icon: '📰' },
    { value: 'analysis', labelKey: 'admin.contentEdit.categories.analysis', icon: '📊' },
    { value: 'tutorial', labelKey: 'admin.contentEdit.categories.tutorial', icon: '📖' },
    { value: 'tpix_chain', label: 'TPIX Chain', icon: '⛓️' },
    { value: 'defi', label: 'DeFi', icon: '💰' },
    { value: 'technology', labelKey: 'admin.contentEdit.categories.technology', icon: '🔬' },
    // บทเรียนวิดีโอ — สร้างจาก config/learn.php (LearnLessonsSeeder) แก้ที่นี่จะถูกทับรอบ deploy ถัดไป
    { value: 'learn', labelKey: 'admin.contentEdit.categories.learn', icon: '🎓' },
];

const categoryEnglish = { news: 'news', analysis: 'market analysis', tutorial: 'tutorial', tpix_chain: 'TPIX blockchain', defi: 'DeFi', technology: 'technology', learn: 'tutorial video' };

// Auto-generate English prompt from title + category
function buildAutoPrompt() {
    const cat = categoryEnglish[form.category] || form.category;
    const titleText = form.title || 'crypto trading';
    return `Professional ${cat} article cover image about: ${titleText}, dark theme, cyan blue accent, futuristic digital art, minimalist, no text, high quality`;
}

const imagePrompt = ref(props.article.ai_image_prompt || buildAutoPrompt());

const wordCount = computed(() => {
    const text = form.content.replace(/<[^>]*>/g, '');
    return text.trim() ? text.trim().split(/\s+/).length : 0;
});

const readTime = computed(() => Math.max(1, Math.ceil(wordCount.value / 200)));

const coverImageSrc = computed(() => {
    if (generatedImageUrl.value) return generatedImageUrl.value;
    if (!props.article.cover_image) return null;
    return props.article.cover_image.startsWith('/') ? props.article.cover_image : '/storage/' + props.article.cover_image;
});

function save() {
    if (isNew.value) {
        form.post('/admin/content', {
            preserveScroll: true,
            onSuccess: () => { showSaved.value = true; setTimeout(() => showSaved.value = false, 2000); },
        });
    } else {
        form.put(`/admin/content/${props.article.id}`, {
            preserveScroll: true,
            onSuccess: () => { showSaved.value = true; setTimeout(() => showSaved.value = false, 2000); },
        });
    }
}

function publish() {
    form.status = 'published';
    save();
}

function addTag() {
    const tag = tagInput.value.trim();
    if (tag && !form.tags.includes(tag)) form.tags.push(tag);
    tagInput.value = '';
}

function removeTag(index) {
    form.tags.splice(index, 1);
}

function autoFillPrompt() {
    imagePrompt.value = buildAutoPrompt();
}

async function generateCoverImage() {
    if (!imagePrompt.value) {
        imagePrompt.value = buildAutoPrompt();
    }
    imageLoading.value = true;
    imageError.value = '';
    try {
        const { data } = await axios.post('/admin/content/generate-image', {
            prompt: imagePrompt.value,
            provider: selectedProvider.value,
            article_id: props.article.id || null,
        });
        if (data.success) {
            generatedImageUrl.value = data.image_url;
            if (props.article.id) router.reload({ only: ['article'] });
        } else {
            imageError.value = data.error || t('admin.contentEdit.media.generateFailed');
        }
    } catch (e) {
        const msg = e.response?.data?.error || e.response?.data?.message || e.message;
        imageError.value = msg || t('admin.contentEdit.media.networkError');
        console.error('Image gen error:', e.response?.data || e.message);
    } finally {
        imageLoading.value = false;
    }
}

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all';
const labelClass = 'block text-sm font-medium text-dark-300 mb-2';
// รายชื่อแท็กเป็นโค้ด ไม่แปล — เก็บใน script กัน '<' ไปปนใน template
const HTML_TAGS_HINT = '<h2>, <p>, <strong>, <ul>, <li>, <blockquote>, <code>';
</script>

<template>
    <Head :title="isNew ? t('admin.contentEdit.newArticle') : t('admin.contentEdit.editTitle', { title: article.title })" />
    <AdminLayout>
        <div class="space-y-4">
            <!-- Top Bar -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button @click="router.visit('/admin/content')" :aria-label="t('common.back')" :title="t('common.back')" class="p-2 rounded-xl bg-dark-800/50 border border-white/10 text-dark-400 hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-white line-clamp-1">
                            {{ isNew ? t('admin.contentEdit.newArticle') : (form.title || t('admin.contentEdit.untitled')) }}
                        </h1>
                        <div class="flex items-center gap-3 text-xs text-dark-500">
                            <span>{{ t('admin.contentEdit.words', { n: wordCount }) }}</span>
                            <span>{{ t('admin.contentEdit.readTime', { n: readTime }) }}</span>
                            <span v-if="isNew" class="text-primary-400">{{ t('admin.contentEdit.manual') }}</span>
                            <span v-else-if="article.is_ai_generated" class="text-accent-400">{{ t('admin.contentEdit.aiGenerated') }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span v-if="showSaved" class="text-trading-green text-sm animate-pulse">{{ t('admin.contentEdit.saved') }}</span>

                    <button @click="showPreview = !showPreview"
                        :class="['px-3 py-2 rounded-xl text-sm border transition-all', showPreview ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/10 hover:text-white']">
                        {{ t('admin.contentEdit.preview') }}
                    </button>

                    <button @click="save" :disabled="form.processing"
                        class="px-4 py-2 rounded-xl text-sm bg-dark-800 border border-white/10 text-white hover:bg-dark-700 transition-all disabled:opacity-50">
                        {{ form.processing ? t('admin.contentEdit.saving') : (isNew ? t('admin.contentEdit.saveArticle') : t('admin.contentEdit.saveDraft')) }}
                    </button>

                    <button v-if="form.status !== 'published'" @click="publish" :disabled="form.processing || !form.title"
                        class="px-4 py-2 rounded-xl text-sm bg-gradient-to-r from-trading-green to-emerald-500 text-white font-medium hover:shadow-lg hover:shadow-trading-green/20 transition-all disabled:opacity-50">
                        {{ t('admin.contentEdit.publish') }}
                    </button>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="grid gap-4" :class="showPreview ? 'lg:grid-cols-2' : 'lg:grid-cols-[1fr_320px]'">
                <!-- Editor -->
                <div class="space-y-4">
                    <input v-model="form.title" type="text" :placeholder="t('admin.contentEdit.titlePlaceholder')"
                        class="w-full bg-transparent text-2xl font-bold text-white placeholder-dark-600 border-none outline-none px-0" />

                    <!-- Tab Bar -->
                    <div class="flex items-center gap-1 bg-dark-800/30 p-1 rounded-xl border border-white/5">
                        <button @click="activeTab = 'content'" :class="['px-3 py-1.5 rounded-lg text-sm transition-all', activeTab === 'content' ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white']">{{ t('admin.contentEdit.tabs.content') }}</button>
                        <button @click="activeTab = 'seo'" :class="['px-3 py-1.5 rounded-lg text-sm transition-all', activeTab === 'seo' ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white']">SEO</button>
                        <button @click="activeTab = 'media'" :class="['px-3 py-1.5 rounded-lg text-sm transition-all', activeTab === 'media' ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white']">{{ t('admin.contentEdit.tabs.media') }}</button>
                    </div>

                    <!-- Content Tab -->
                    <div v-show="activeTab === 'content'" class="space-y-4">
                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.content.summary') }}</label>
                            <textarea v-model="form.summary" rows="2" :class="inputClass" :placeholder="t('admin.contentEdit.content.summaryPlaceholder')"></textarea>
                        </div>
                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.content.body') }}</label>
                            <textarea v-model="form.content" rows="20" :class="[inputClass, 'font-mono text-sm leading-relaxed']" :placeholder="t('admin.contentEdit.content.bodyPlaceholder')"></textarea>
                            <p class="text-xs text-dark-500 mt-1">{{ t('admin.contentEdit.content.htmlHint', { tags: HTML_TAGS_HINT }) }}</p>
                        </div>
                    </div>

                    <!-- SEO Tab -->
                    <div v-show="activeTab === 'seo'" class="space-y-4">
                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.seo.title') }}</label>
                            <input v-model="form.seo_title" type="text" :class="inputClass" :placeholder="t('admin.contentEdit.seo.titlePlaceholder')" />
                            <p class="text-xs text-dark-500 mt-1">{{ t('admin.contentEdit.seo.charCount', { n: (form.seo_title || '').length, max: 60 }) }}</p>
                        </div>
                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.seo.description') }}</label>
                            <textarea v-model="form.seo_description" rows="3" :class="inputClass" :placeholder="t('admin.contentEdit.seo.descriptionPlaceholder')"></textarea>
                            <p class="text-xs text-dark-500 mt-1">{{ t('admin.contentEdit.seo.charCount', { n: (form.seo_description || '').length, max: 160 }) }}</p>
                        </div>
                        <div class="p-4 bg-white rounded-xl">
                            <p class="text-[#1a0dab] text-lg font-normal hover:underline cursor-pointer">{{ form.seo_title || form.title || t('admin.contentEdit.seo.previewTitle') }}</p>
                            <p class="text-[#006621] text-sm">tpixtrade.com/blog/{{ article.slug || 'new-article' }}</p>
                            <p class="text-[#545454] text-sm">{{ form.seo_description || form.summary || t('admin.contentEdit.seo.previewDescription') }}</p>
                        </div>
                    </div>

                    <!-- Media Tab -->
                    <div v-show="activeTab === 'media'" class="space-y-4">
                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.media.coverImage') }}</label>
                            <div v-if="coverImageSrc" class="rounded-xl overflow-hidden border border-white/10 mb-3">
                                <img :src="coverImageSrc" :alt="t('admin.contentEdit.media.coverImage')" class="w-full h-48 object-cover" />
                            </div>
                            <div v-else class="w-full h-48 rounded-xl bg-dark-800 border border-white/10 border-dashed flex items-center justify-center text-dark-500 mb-3">
                                {{ t('admin.contentEdit.media.noCover') }}
                            </div>
                        </div>

                        <!-- AI Image Generator -->
                        <div class="p-4 rounded-xl bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5 border border-primary-500/10">
                            <h4 class="text-sm font-semibold text-primary-400 mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                {{ t('admin.contentEdit.media.generatorTitle') }}
                            </h4>

                            <!-- Provider Selector -->
                            <div class="mb-3">
                                <label :class="labelClass">{{ t('admin.contentEdit.media.provider') }}</label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <button type="button" @click="selectedProvider = 'auto'"
                                        :class="['px-3 py-2 rounded-xl text-xs text-left transition-all border', selectedProvider === 'auto' ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5 hover:border-white/20']">
                                        {{ t('admin.contentEdit.media.providerAuto') }}
                                    </button>
                                    <button v-for="(info, key) in imageProviders" :key="key" type="button"
                                        @click="selectedProvider = key"
                                        :class="['px-3 py-2 rounded-xl text-xs text-left transition-all border', selectedProvider === key ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5 hover:border-white/20']">
                                        <span class="block font-medium">{{ info.name }}</span>
                                        <span class="block text-[10px] opacity-70 mt-0.5">{{ info.requires_key ? t('admin.contentEdit.media.requiresKey') : t('admin.contentEdit.media.free') }}</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Prompt + Generate -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <label :class="labelClass">{{ t('admin.contentEdit.media.prompt') }}</label>
                                    <button type="button" @click="autoFillPrompt" class="text-xs text-primary-400 hover:text-primary-300 transition-colors">
                                        {{ t('admin.contentEdit.media.autoPrompt') }}
                                    </button>
                                </div>
                                <textarea v-model="imagePrompt" rows="3" :class="inputClass" :placeholder="t('admin.contentEdit.media.promptPlaceholder')"></textarea>

                                <!-- Error -->
                                <div v-if="imageError" class="p-2 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-xs">
                                    {{ imageError }}
                                </div>

                                <button @click="generateCoverImage" :disabled="imageLoading"
                                    class="w-full px-4 py-2.5 rounded-xl text-sm font-medium transition-all"
                                    :class="imageLoading || !imagePrompt
                                        ? 'bg-dark-700 text-dark-500 cursor-not-allowed'
                                        : 'bg-gradient-to-r from-accent-500 to-primary-500 text-white hover:shadow-lg hover:shadow-accent-500/25'">
                                    <span v-if="imageLoading" class="flex items-center justify-center gap-2">
                                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        {{ t('admin.contentEdit.media.generating') }}
                                    </span>
                                    <span v-else>{{ t('admin.contentEdit.media.generate') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar / Preview -->
                <div v-if="showPreview" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 overflow-y-auto max-h-[80vh]">
                    <h3 class="text-sm font-semibold text-dark-400 mb-4 uppercase tracking-wider">{{ t('admin.contentEdit.livePreview') }}</h3>
                    <article class="prose prose-invert max-w-none">
                        <h1 class="text-xl font-bold text-white mb-2">{{ form.title }}</h1>
                        <p v-if="form.summary" class="text-dark-400 italic text-sm mb-4">{{ form.summary }}</p>
                        <div v-html="sanitizeHtml(form.content)" class="text-dark-200 text-sm leading-relaxed"></div>
                    </article>
                </div>

                <div v-else class="space-y-4">
                    <!-- Status & Schedule -->
                    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-white">{{ t('admin.contentEdit.settings.title') }}</h3>

                        <div>
                            <label :class="labelClass">{{ t('common.status') }}</label>
                            <select v-model="form.status" :class="inputClass">
                                <option value="draft">{{ t('admin.contentEdit.status.draft') }}</option>
                                <option value="scheduled">{{ t('admin.contentEdit.status.scheduled') }}</option>
                                <option value="published">{{ t('admin.contentEdit.status.published') }}</option>
                                <option value="archived">{{ t('admin.contentEdit.status.archived') }}</option>
                            </select>
                        </div>

                        <div v-if="form.status === 'scheduled'">
                            <label :class="labelClass">{{ t('admin.contentEdit.settings.scheduleDate') }}</label>
                            <input v-model="form.scheduled_at" type="datetime-local" :class="inputClass" />
                        </div>

                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.settings.category') }}</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <button v-for="c in categories" :key="c.value" @click="form.category = c.value" type="button"
                                    :class="['px-3 py-2 rounded-xl text-xs text-left transition-all border', form.category === c.value ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5 hover:border-white/20']">
                                    {{ c.icon }} {{ c.labelKey ? t(c.labelKey) : c.label }}
                                </button>
                            </div>
                        </div>

                        <div>
                            <label :class="labelClass">{{ t('admin.contentEdit.settings.language') }}</label>
                            <div class="flex gap-2">
                                <button @click="form.language = 'th'" type="button"
                                    :class="['flex-1 px-3 py-2 rounded-xl text-sm text-center transition-all border', form.language === 'th' ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5']">
                                    TH
                                </button>
                                <button @click="form.language = 'en'" type="button"
                                    :class="['flex-1 px-3 py-2 rounded-xl text-sm text-center transition-all border', form.language === 'en' ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5']">
                                    EN
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tags -->
                    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 space-y-3">
                        <h3 class="text-sm font-semibold text-white">{{ t('admin.contentEdit.tags.title') }}</h3>
                        <div class="flex gap-2">
                            <input v-model="tagInput" @keydown.enter.prevent="addTag" type="text" :class="inputClass" :placeholder="t('admin.contentEdit.tags.placeholder')" />
                            <button @click="addTag" class="px-3 py-2 rounded-xl bg-dark-700 text-white text-sm border border-white/10 hover:bg-dark-600" :aria-label="t('admin.contentEdit.tags.add')">+</button>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="(tag, i) in form.tags" :key="i"
                                class="inline-flex items-center gap-1 px-2.5 py-1 bg-primary-500/10 text-primary-400 rounded-lg text-xs">
                                {{ tag }}
                                <button @click="removeTag(i)" :aria-label="t('admin.contentEdit.tags.remove')" class="text-primary-300 hover:text-white">&times;</button>
                            </span>
                            <span v-if="!form.tags.length" class="text-xs text-dark-500">{{ t('admin.contentEdit.tags.empty') }}</span>
                        </div>
                    </div>

                    <!-- Info -->
                    <div v-if="!isNew" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 space-y-2 text-xs text-dark-500">
                        <div class="flex justify-between"><span>{{ t('admin.contentEdit.info.created') }}</span><span class="text-dark-300">{{ new Date(article.created_at).toLocaleDateString(locale === 'th' ? 'th-TH' : 'en-US') }}</span></div>
                        <div class="flex justify-between"><span>{{ t('admin.contentEdit.info.views') }}</span><span class="text-dark-300">{{ article.views?.toLocaleString(locale === 'th' ? 'th-TH' : 'en-US') || 0 }}</span></div>
                        <div class="flex justify-between"><span>{{ t('admin.contentEdit.info.likes') }}</span><span class="text-dark-300">{{ article.likes || 0 }}</span></div>
                        <div v-if="article.ai_model" class="flex justify-between"><span>{{ t('admin.contentEdit.info.aiModel') }}</span><span class="text-accent-400">{{ article.ai_model }}</span></div>
                        <div class="flex justify-between"><span>{{ t('admin.contentEdit.info.slug') }}</span><span class="text-dark-300 truncate ml-2">{{ article.slug }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
