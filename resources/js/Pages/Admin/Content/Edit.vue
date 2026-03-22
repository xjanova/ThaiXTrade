<script setup>
/**
 * TPIX TRADE — Article Editor (Full Page)
 * Rich content editing with preview, SEO, scheduling
 * Developed by Xman Studio
 */
import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    article: Object,
});

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
const imagePrompt = ref('');
const imageLoading = ref(false);

const categories = [
    { value: 'news', label: 'ข่าว', icon: '📰' },
    { value: 'analysis', label: 'วิเคราะห์', icon: '📊' },
    { value: 'tutorial', label: 'สอนใช้งาน', icon: '📖' },
    { value: 'tpix_chain', label: 'TPIX Chain', icon: '⛓️' },
    { value: 'defi', label: 'DeFi', icon: '💰' },
    { value: 'technology', label: 'เทคโนโลยี', icon: '🔬' },
];

const wordCount = computed(() => {
    const text = form.content.replace(/<[^>]*>/g, '');
    return text.trim() ? text.trim().split(/\s+/).length : 0;
});

const readTime = computed(() => Math.max(1, Math.ceil(wordCount.value / 200)));

function save() {
    form.put(`/admin/content/${props.article.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showSaved.value = true;
            setTimeout(() => showSaved.value = false, 2000);
        },
    });
}

function publish() {
    form.status = 'published';
    save();
}

function addTag() {
    const tag = tagInput.value.trim();
    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }
    tagInput.value = '';
}

function removeTag(index) {
    form.tags.splice(index, 1);
}

async function generateCoverImage() {
    if (!imagePrompt.value) return;
    imageLoading.value = true;
    try {
        const response = await fetch('/admin/content/generate-image', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
            body: JSON.stringify({ prompt: imagePrompt.value }),
        });
        const data = await response.json();
        if (data.success) {
            router.reload({ only: ['article'] });
        }
    } catch (e) {
        console.error(e);
    } finally {
        imageLoading.value = false;
    }
}

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all';
const labelClass = 'block text-sm font-medium text-dark-300 mb-2';
</script>

<template>
    <Head :title="`Edit: ${article.title}`" />
    <AdminLayout>
        <div class="space-y-4">
            <!-- Top Bar -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button @click="router.visit('/admin/content')" class="p-2 rounded-xl bg-dark-800/50 border border-white/10 text-dark-400 hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-white line-clamp-1">{{ form.title || 'Untitled' }}</h1>
                        <div class="flex items-center gap-3 text-xs text-dark-500">
                            <span>{{ wordCount }} words</span>
                            <span>{{ readTime }} min read</span>
                            <span v-if="article.is_ai_generated" class="text-accent-400">AI Generated</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Saved indicator -->
                    <span v-if="showSaved" class="text-trading-green text-sm animate-pulse">Saved!</span>

                    <button @click="showPreview = !showPreview"
                        :class="['px-3 py-2 rounded-xl text-sm border transition-all', showPreview ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/10 hover:text-white']">
                        Preview
                    </button>

                    <button @click="save" :disabled="form.processing"
                        class="px-4 py-2 rounded-xl text-sm bg-dark-800 border border-white/10 text-white hover:bg-dark-700 transition-all disabled:opacity-50">
                        {{ form.processing ? 'Saving...' : 'Save Draft' }}
                    </button>

                    <button v-if="form.status !== 'published'" @click="publish" :disabled="form.processing"
                        class="px-4 py-2 rounded-xl text-sm bg-gradient-to-r from-trading-green to-emerald-500 text-white font-medium hover:shadow-lg hover:shadow-trading-green/20 transition-all disabled:opacity-50">
                        Publish
                    </button>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="grid gap-4" :class="showPreview ? 'lg:grid-cols-2' : 'lg:grid-cols-[1fr_320px]'">
                <!-- Editor -->
                <div class="space-y-4">
                    <!-- Title -->
                    <input v-model="form.title" type="text" placeholder="Article title..."
                        class="w-full bg-transparent text-2xl font-bold text-white placeholder-dark-600 border-none outline-none px-0" />

                    <!-- Tab Bar -->
                    <div class="flex items-center gap-1 bg-dark-800/30 p-1 rounded-xl border border-white/5">
                        <button @click="activeTab = 'content'" :class="['px-3 py-1.5 rounded-lg text-sm transition-all', activeTab === 'content' ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white']">Content</button>
                        <button @click="activeTab = 'seo'" :class="['px-3 py-1.5 rounded-lg text-sm transition-all', activeTab === 'seo' ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white']">SEO</button>
                        <button @click="activeTab = 'media'" :class="['px-3 py-1.5 rounded-lg text-sm transition-all', activeTab === 'media' ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white']">Media</button>
                    </div>

                    <!-- Content Tab -->
                    <div v-show="activeTab === 'content'" class="space-y-4">
                        <div>
                            <label :class="labelClass">Summary</label>
                            <textarea v-model="form.summary" rows="2" :class="inputClass" placeholder="Brief summary of the article..."></textarea>
                        </div>
                        <div>
                            <label :class="labelClass">Content (HTML)</label>
                            <textarea v-model="form.content" rows="20" :class="[inputClass, 'font-mono text-sm leading-relaxed']" placeholder="Write your article content here..."></textarea>
                            <p class="text-xs text-dark-500 mt-1">Supports HTML tags: &lt;h2&gt;, &lt;p&gt;, &lt;strong&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;blockquote&gt;, &lt;code&gt;</p>
                        </div>
                    </div>

                    <!-- SEO Tab -->
                    <div v-show="activeTab === 'seo'" class="space-y-4">
                        <div>
                            <label :class="labelClass">SEO Title</label>
                            <input v-model="form.seo_title" type="text" :class="inputClass" placeholder="SEO optimized title..." />
                            <p class="text-xs text-dark-500 mt-1">{{ (form.seo_title || '').length }}/60 characters</p>
                        </div>
                        <div>
                            <label :class="labelClass">SEO Description</label>
                            <textarea v-model="form.seo_description" rows="3" :class="inputClass" placeholder="Meta description for search engines..."></textarea>
                            <p class="text-xs text-dark-500 mt-1">{{ (form.seo_description || '').length }}/160 characters</p>
                        </div>
                        <!-- Google Preview -->
                        <div class="p-4 bg-white rounded-xl">
                            <p class="text-[#1a0dab] text-lg font-normal hover:underline cursor-pointer">{{ form.seo_title || form.title || 'Article Title' }}</p>
                            <p class="text-[#006621] text-sm">tpixtrade.com/blog/{{ article.slug }}</p>
                            <p class="text-[#545454] text-sm">{{ form.seo_description || form.summary || 'Article description will appear here...' }}</p>
                        </div>
                    </div>

                    <!-- Media Tab -->
                    <div v-show="activeTab === 'media'" class="space-y-4">
                        <div>
                            <label :class="labelClass">Cover Image</label>
                            <div v-if="article.cover_image" class="rounded-xl overflow-hidden border border-white/10 mb-3">
                                <img :src="'/storage/' + article.cover_image" class="w-full h-48 object-cover" />
                            </div>
                            <div v-else class="w-full h-48 rounded-xl bg-dark-800 border border-white/10 border-dashed flex items-center justify-center text-dark-500 mb-3">
                                No cover image
                            </div>
                        </div>
                        <div>
                            <label :class="labelClass">Generate AI Cover Image</label>
                            <div class="flex gap-2">
                                <input v-model="imagePrompt" type="text" :class="inputClass" placeholder="Describe the image..." />
                                <button @click="generateCoverImage" :disabled="imageLoading || !imagePrompt"
                                    class="px-4 py-2 rounded-xl text-sm bg-accent-500 text-white whitespace-nowrap disabled:opacity-50 hover:bg-accent-600 transition-colors">
                                    {{ imageLoading ? 'Generating...' : 'Generate' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar / Preview -->
                <div v-if="showPreview" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 overflow-y-auto max-h-[80vh]">
                    <h3 class="text-sm font-semibold text-dark-400 mb-4 uppercase tracking-wider">Live Preview</h3>
                    <article class="prose prose-invert max-w-none">
                        <h1 class="text-xl font-bold text-white mb-2">{{ form.title }}</h1>
                        <p v-if="form.summary" class="text-dark-400 italic text-sm mb-4">{{ form.summary }}</p>
                        <div v-html="form.content" class="text-dark-200 text-sm leading-relaxed"></div>
                    </article>
                </div>

                <div v-else class="space-y-4">
                    <!-- Status & Schedule -->
                    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-white">Publish Settings</h3>

                        <div>
                            <label :class="labelClass">Status</label>
                            <select v-model="form.status" :class="inputClass">
                                <option value="draft">Draft</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="published">Published</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>

                        <div v-if="form.status === 'scheduled'">
                            <label :class="labelClass">Schedule Date</label>
                            <input v-model="form.scheduled_at" type="datetime-local" :class="inputClass" />
                        </div>

                        <div>
                            <label :class="labelClass">Category</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button v-for="c in categories" :key="c.value" @click="form.category = c.value" type="button"
                                    :class="['px-3 py-2 rounded-xl text-xs text-left transition-all border', form.category === c.value ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5 hover:border-white/20']">
                                    {{ c.icon }} {{ c.label }}
                                </button>
                            </div>
                        </div>

                        <div>
                            <label :class="labelClass">Language</label>
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
                        <h3 class="text-sm font-semibold text-white">Tags</h3>
                        <div class="flex gap-2">
                            <input v-model="tagInput" @keydown.enter.prevent="addTag" type="text" :class="inputClass" placeholder="Add tag..." />
                            <button @click="addTag" class="px-3 py-2 rounded-xl bg-dark-700 text-white text-sm border border-white/10 hover:bg-dark-600">+</button>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="(tag, i) in form.tags" :key="i"
                                class="inline-flex items-center gap-1 px-2.5 py-1 bg-primary-500/10 text-primary-400 rounded-lg text-xs">
                                {{ tag }}
                                <button @click="removeTag(i)" class="text-primary-300 hover:text-white">&times;</button>
                            </span>
                            <span v-if="!form.tags.length" class="text-xs text-dark-500">No tags yet</span>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 space-y-2 text-xs text-dark-500">
                        <div class="flex justify-between"><span>Created</span><span class="text-dark-300">{{ new Date(article.created_at).toLocaleDateString('th-TH') }}</span></div>
                        <div class="flex justify-between"><span>Views</span><span class="text-dark-300">{{ article.views?.toLocaleString() || 0 }}</span></div>
                        <div class="flex justify-between"><span>Likes</span><span class="text-dark-300">{{ article.likes || 0 }}</span></div>
                        <div v-if="article.ai_model" class="flex justify-between"><span>AI Model</span><span class="text-accent-400">{{ article.ai_model }}</span></div>
                        <div class="flex justify-between"><span>Slug</span><span class="text-dark-300 truncate ml-2">{{ article.slug }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
