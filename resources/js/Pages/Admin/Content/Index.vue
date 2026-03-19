<script setup>
/**
 * TPIX TRADE — Admin Content Management
 * สร้างบทความด้วย AI, แก้ไข, ตั้งเวลา publish
 * รองรับ: Groq (หลาย model), AI image (Pollinations.ai ฟรี)
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

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
    if (confirm('ลบบทความนี้?')) {
        router.delete(`/admin/content/${id}`, { preserveScroll: true });
    }
}

function publishArticle(id) {
    router.put(`/admin/content/${id}`, { status: 'published' }, { preserveScroll: true });
}

const categories = [
    { value: 'news', label: 'ข่าว' },
    { value: 'analysis', label: 'วิเคราะห์' },
    { value: 'tutorial', label: 'สอนใช้งาน' },
    { value: 'tpix_chain', label: 'TPIX Chain' },
    { value: 'defi', label: 'DeFi' },
    { value: 'technology', label: 'เทคโนโลยี' },
];

const models = [
    { value: 'llama-3.3-70b-versatile', label: 'Llama 3.3 70B (แนะนำ)' },
    { value: 'llama-3.1-8b-instant', label: 'Llama 3.1 8B (เร็ว)' },
    { value: 'mixtral-8x7b-32768', label: 'Mixtral 8x7B' },
    { value: 'gemma2-9b-it', label: 'Gemma 2 9B' },
];

const topicSuggestions = [
    'TPIX Chain — Blockchain ที่ไม่มีค่าแก๊สสำหรับคนไทย',
    'วิธีเพิ่ม TPIX Chain เข้า MetaMask อัตโนมัติ',
    'ทำไม TPIX Chain ถึงเร็วกว่า Ethereum 30 เท่า',
    'Carbon Credit บน Blockchain คืออะไร',
    'Token Factory — สร้างเหรียญของคุณเองบน TPIX Chain',
    'Staking TPIX ได้ APY สูงสุด 200% ต่อปี',
    'TPIX DEX vs PancakeSwap เปรียบเทียบข้อดีข้อเสีย',
    'FoodPassport — ตรวจสอบที่มาอาหารด้วย Blockchain',
];

function useSuggestion(topic) {
    generateForm.topic = topic;
}

function formatDate(d) {
    return d ? new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
}

const statusColors = {
    draft: 'bg-dark-600 text-dark-300',
    scheduled: 'bg-yellow-500/20 text-yellow-400',
    published: 'bg-trading-green/20 text-trading-green',
    archived: 'bg-dark-700 text-dark-500',
};
</script>

<template>
    <Head title="Content Management" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Content Management</h1>
                    <p class="text-dark-400 text-sm">สร้างบทความด้วย AI + จัดการ content</p>
                </div>
                <button @click="showGenerator = !showGenerator"
                    class="px-4 py-2 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl text-sm font-medium hover:shadow-lg hover:shadow-primary-500/20 transition-all">
                    🤖 สร้างบทความด้วย AI
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-white">{{ stats?.total || 0 }}</p>
                    <p class="text-xs text-dark-400">ทั้งหมด</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-trading-green">{{ stats?.published || 0 }}</p>
                    <p class="text-xs text-dark-400">เผยแพร่</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-yellow-400">{{ stats?.draft || 0 }}</p>
                    <p class="text-xs text-dark-400">แบบร่าง</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-primary-400">{{ stats?.scheduled || 0 }}</p>
                    <p class="text-xs text-dark-400">ตั้งเวลา</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-accent-400">{{ stats?.ai_generated || 0 }}</p>
                    <p class="text-xs text-dark-400">AI สร้าง</p>
                </div>
                <div class="glass-card text-center py-3">
                    <p class="text-2xl font-bold text-white">{{ stats?.total_views?.toLocaleString() || 0 }}</p>
                    <p class="text-xs text-dark-400">ยอดอ่าน</p>
                </div>
            </div>

            <!-- AI Generator Panel -->
            <div v-if="showGenerator" class="glass-card border border-primary-500/20 space-y-4">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">🤖 AI Article Generator</h3>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-dark-300 mb-1 block">หัวข้อ / Topic</label>
                        <textarea v-model="generateForm.topic" rows="3" placeholder="เช่น: TPIX Chain — Blockchain ที่ไม่มีค่าแก๊ส..."
                            class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-500 focus:border-primary-500 outline-none"></textarea>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm text-dark-300 mb-1 block">หมวดหมู่</label>
                            <select v-model="generateForm.category" class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                                <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="text-sm text-dark-300 mb-1 block">ภาษา</label>
                                <select v-model="generateForm.language" class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                                    <option value="th">ไทย</option>
                                    <option value="en">English</option>
                                </select>
                            </div>
                            <div class="flex-1">
                                <label class="text-sm text-dark-300 mb-1 block">AI Model</label>
                                <select v-model="generateForm.model" class="w-full bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm">
                                    <option v-for="m in models" :key="m.value" :value="m.value">{{ m.label }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Topic Suggestions -->
                <div>
                    <p class="text-xs text-dark-500 mb-2">💡 หัวข้อแนะนำ:</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in topicSuggestions" :key="s" @click="useSuggestion(s)"
                            class="px-3 py-1 bg-white/5 border border-white/10 rounded-lg text-xs text-dark-300 hover:bg-primary-500/10 hover:border-primary-500/30 transition-all">
                            {{ s }}
                        </button>
                    </div>
                </div>

                <button @click="generateArticle" :disabled="generateForm.processing || !generateForm.topic"
                    class="w-full py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-medium hover:shadow-lg disabled:opacity-50 transition-all">
                    {{ generateForm.processing ? '🔄 กำลังสร้าง...' : '✨ สร้างบทความ + ภาพ AI' }}
                </button>
            </div>

            <!-- Articles Table -->
            <div class="glass-card overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/10 text-dark-400 text-xs uppercase">
                            <th class="text-left p-4">บทความ</th>
                            <th class="text-left p-4">หมวด</th>
                            <th class="text-left p-4">ภาษา</th>
                            <th class="text-left p-4">สถานะ</th>
                            <th class="text-left p-4">อ่าน</th>
                            <th class="text-left p-4">วันที่</th>
                            <th class="text-right p-4">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="article in articles?.data" :key="article.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <img v-if="article.cover_image" :src="article.cover_image" class="w-12 h-8 rounded object-cover" />
                                    <div v-else class="w-12 h-8 rounded bg-dark-700 flex items-center justify-center text-xs">📝</div>
                                    <div>
                                        <p class="text-white text-sm font-medium line-clamp-1">{{ article.title }}</p>
                                        <p v-if="article.is_ai_generated" class="text-accent-400 text-[10px]">🤖 AI</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 text-dark-300 text-sm">{{ article.category }}</td>
                            <td class="p-4 text-dark-300 text-sm">{{ article.language === 'th' ? '🇹🇭' : '🇺🇸' }}</td>
                            <td class="p-4">
                                <span :class="['px-2 py-0.5 rounded-lg text-xs', statusColors[article.status]]">
                                    {{ article.status }}
                                </span>
                            </td>
                            <td class="p-4 text-dark-300 text-sm">{{ article.views }}</td>
                            <td class="p-4 text-dark-400 text-xs">{{ formatDate(article.created_at) }}</td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button v-if="article.status === 'draft'" @click="publishArticle(article.id)"
                                        class="px-2 py-1 bg-trading-green/20 text-trading-green rounded text-xs hover:bg-trading-green/30">
                                        Publish
                                    </button>
                                    <button @click="deleteArticle(article.id)"
                                        class="px-2 py-1 bg-trading-red/20 text-trading-red rounded text-xs hover:bg-trading-red/30">
                                        ลบ
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="!articles?.data?.length" class="text-center py-12 text-dark-500">
                    <p class="text-4xl mb-2">📝</p>
                    <p>ยังไม่มีบทความ — กด "สร้างบทความด้วย AI" เพื่อเริ่มต้น</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
