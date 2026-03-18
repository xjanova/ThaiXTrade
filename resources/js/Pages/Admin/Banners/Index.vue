<script setup>
/**
 * TPIX TRADE — Admin Banner Management
 * จัดการป้ายโฆษณา: รูปภาพ, Google AdSense, Custom HTML
 * Developed by Xman Studio.
 */
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    banners: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    types: { type: Object, default: () => ({}) },
    placements: { type: Object, default: () => ({}) },
});

// สถานะ modal
const showModal = ref(false);
const editingBanner = ref(null);
const filterPlacement = ref('all');

// ฟอร์ม
const form = useForm({
    title: '',
    type: 'image',
    image_url: '',
    link_url: '',
    target: '_blank',
    ad_code: '',
    placement: 'all_pages_top',
    is_active: true,
    sort_order: 0,
    start_at: '',
    end_at: '',
});

// กรอง banners ตาม placement
const filteredBanners = computed(() => {
    if (filterPlacement.value === 'all') return props.banners;
    return props.banners.filter(b => b.placement === filterPlacement.value);
});

// เปิด modal สร้างใหม่
function openCreate() {
    editingBanner.value = null;
    form.reset();
    form.is_active = true;
    form.type = 'image';
    form.target = '_blank';
    form.placement = 'all_pages_top';
    showModal.value = true;
}

// เปิด modal แก้ไข
function openEdit(banner) {
    editingBanner.value = banner;
    form.title = banner.title;
    form.type = banner.type;
    form.image_url = banner.image_url || '';
    form.link_url = banner.link_url || '';
    form.target = banner.target;
    form.ad_code = banner.ad_code || '';
    form.placement = banner.placement;
    form.is_active = banner.is_active;
    form.sort_order = banner.sort_order;
    form.start_at = banner.start_at ? banner.start_at.slice(0, 16) : '';
    form.end_at = banner.end_at ? banner.end_at.slice(0, 16) : '';
    showModal.value = true;
}

// บันทึก
function save() {
    if (editingBanner.value) {
        form.put(`/admin/banners/${editingBanner.value.id}`, { onSuccess: () => showModal.value = false });
    } else {
        form.post('/admin/banners', { onSuccess: () => showModal.value = false });
    }
}

// Toggle active
function toggleActive(banner) {
    useForm({}).patch(`/admin/banners/${banner.id}/toggle`);
}

// ลบ
const confirmDelete = ref(null);
function deleteBanner(banner) {
    if (confirm(`ลบ "${banner.title}" ?`)) {
        useForm({}).delete(`/admin/banners/${banner.id}`);
    }
}

// Type badge color
function typeBadge(type) {
    const map = { image: 'bg-blue-500/20 text-blue-400', google_adsense: 'bg-yellow-500/20 text-yellow-400', html: 'bg-purple-500/20 text-purple-400' };
    return map[type] || 'bg-gray-500/20 text-gray-400';
}

// ป้ายชื่อ input class
const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors text-sm';
</script>

<template>
    <Head title="Banners — Admin" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-white">Banner Ads</h1>
                <p class="text-dark-400 text-sm mt-1">จัดการป้ายโฆษณา รองรับ Image, Google AdSense, Custom HTML</p>
            </div>
            <button @click="openCreate" class="btn-primary px-4 py-2 rounded-xl text-sm font-medium">
                + เพิ่ม Banner
            </button>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-white">{{ stats.total || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">ทั้งหมด</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-trading-green">{{ stats.active || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">กำลังแสดง</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-primary-400">{{ (stats.total_views || 0).toLocaleString() }}</p>
                <p class="text-xs text-dark-400 mt-1">Views รวม</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-yellow-400">{{ (stats.total_clicks || 0).toLocaleString() }}</p>
                <p class="text-xs text-dark-400 mt-1">Clicks รวม</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="flex gap-2 flex-wrap">
            <button @click="filterPlacement = 'all'"
                :class="filterPlacement === 'all' ? 'bg-primary-500 text-white' : 'bg-dark-700 text-dark-400 hover:text-white'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                ทั้งหมด
            </button>
            <button v-for="(label, key) in placements" :key="key" @click="filterPlacement = key"
                :class="filterPlacement === key ? 'bg-primary-500 text-white' : 'bg-dark-700 text-dark-400 hover:text-white'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                {{ label }}
            </button>
        </div>

        <!-- Table -->
        <div class="glass-card rounded-xl overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left p-4 text-xs text-dark-400 uppercase">Title</th>
                        <th class="text-left p-4 text-xs text-dark-400 uppercase">Type</th>
                        <th class="text-left p-4 text-xs text-dark-400 uppercase">Placement</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">Views</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">Clicks</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">Status</th>
                        <th class="text-right p-4 text-xs text-dark-400 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="banner in filteredBanners" :key="banner.id" class="border-b border-white/5 hover:bg-white/5 transition-colors">
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <img v-if="banner.type === 'image' && banner.image_url" :src="banner.image_url" class="w-16 h-10 object-cover rounded-lg border border-dark-600" />
                                <div v-else class="w-16 h-10 rounded-lg border border-dark-600 flex items-center justify-center bg-dark-700">
                                    <span class="text-xs text-dark-500">{{ banner.type === 'google_adsense' ? 'AdSense' : 'HTML' }}</span>
                                </div>
                                <div>
                                    <p class="text-white text-sm font-medium">{{ banner.title }}</p>
                                    <p v-if="banner.link_url" class="text-dark-500 text-xs truncate max-w-48">{{ banner.link_url }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="p-4"><span :class="typeBadge(banner.type)" class="px-2 py-1 rounded-md text-xs font-medium">{{ types[banner.type] || banner.type }}</span></td>
                        <td class="p-4 text-dark-400 text-xs">{{ placements[banner.placement] || banner.placement }}</td>
                        <td class="p-4 text-center text-dark-300 text-sm">{{ banner.view_count?.toLocaleString() }}</td>
                        <td class="p-4 text-center text-dark-300 text-sm">{{ banner.click_count?.toLocaleString() }}</td>
                        <td class="p-4 text-center">
                            <button @click="toggleActive(banner)" :class="banner.is_active ? 'bg-trading-green/20 text-trading-green' : 'bg-dark-600 text-dark-400'" class="px-3 py-1 rounded-full text-xs font-medium transition-colors">
                                {{ banner.is_active ? 'Active' : 'Off' }}
                            </button>
                        </td>
                        <td class="p-4 text-right">
                            <button @click="openEdit(banner)" class="text-primary-400 hover:text-primary-300 text-sm mr-3">แก้ไข</button>
                            <button @click="deleteBanner(banner)" class="text-trading-red hover:text-red-400 text-sm">ลบ</button>
                        </td>
                    </tr>
                    <tr v-if="!filteredBanners.length">
                        <td colspan="7" class="p-8 text-center text-dark-500">ยังไม่มี banner</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Create/Edit -->
    <Teleport to="body">
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="showModal = false">
            <div class="fixed inset-0 bg-black/60" @click="showModal = false"></div>
            <div class="relative bg-dark-800 rounded-2xl border border-dark-600 w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 space-y-5">
                <h2 class="text-xl font-bold text-white">{{ editingBanner ? 'แก้ไข Banner' : 'เพิ่ม Banner ใหม่' }}</h2>

                <form @submit.prevent="save" class="space-y-4">
                    <!-- Title -->
                    <div>
                        <label class="block text-sm text-dark-300 mb-1">ชื่อ Banner</label>
                        <input v-model="form.title" :class="inputClass" placeholder="เช่น Thaiprompt Promo" />
                        <p v-if="form.errors.title" class="text-trading-red text-xs mt-1">{{ form.errors.title }}</p>
                    </div>

                    <!-- Type -->
                    <div>
                        <label class="block text-sm text-dark-300 mb-1">ประเภท</label>
                        <select v-model="form.type" :class="inputClass">
                            <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>

                    <!-- Image fields -->
                    <template v-if="form.type === 'image'">
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">URL รูปภาพ</label>
                            <input v-model="form.image_url" :class="inputClass" placeholder="https://..." />
                        </div>
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">URL ลิงก์ (คลิกไปที่)</label>
                            <input v-model="form.link_url" :class="inputClass" placeholder="https://thaiprompt.com" />
                        </div>
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">เปิดลิงก์</label>
                            <select v-model="form.target" :class="inputClass">
                                <option value="_blank">Tab ใหม่</option>
                                <option value="_self">Tab เดิม</option>
                            </select>
                        </div>
                        <!-- Preview -->
                        <div v-if="form.image_url" class="border border-dark-600 rounded-xl p-3">
                            <p class="text-xs text-dark-500 mb-2">Preview:</p>
                            <img :src="form.image_url" class="max-h-32 rounded-lg" alt="Preview" />
                        </div>
                    </template>

                    <!-- Google AdSense fields -->
                    <template v-if="form.type === 'google_adsense'">
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">Google AdSense Code</label>
                            <textarea v-model="form.ad_code" :class="inputClass" rows="5" placeholder='วาง ad code จาก Google เช่น &lt;ins class="adsbygoogle" data-ad-client="ca-pub-xxx" ...&gt;&lt;/ins&gt;'></textarea>
                            <p class="text-xs text-dark-500 mt-1">วางโค้ดจาก Google AdSense ทั้งก้อน</p>
                        </div>
                    </template>

                    <!-- Custom HTML fields -->
                    <template v-if="form.type === 'html'">
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">Custom HTML</label>
                            <textarea v-model="form.ad_code" :class="inputClass" rows="6" placeholder="<div>...</div>"></textarea>
                        </div>
                    </template>

                    <!-- Placement -->
                    <div>
                        <label class="block text-sm text-dark-300 mb-1">ตำแหน่งที่แสดง</label>
                        <select v-model="form.placement" :class="inputClass">
                            <option v-for="(label, key) in placements" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>

                    <!-- Sort + Schedule -->
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">ลำดับ</label>
                            <input v-model.number="form.sort_order" type="number" :class="inputClass" min="0" />
                        </div>
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">เริ่มแสดง</label>
                            <input v-model="form.start_at" type="datetime-local" :class="inputClass" />
                        </div>
                        <div>
                            <label class="block text-sm text-dark-300 mb-1">หยุดแสดง</label>
                            <input v-model="form.end_at" type="datetime-local" :class="inputClass" />
                        </div>
                    </div>

                    <!-- Active -->
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded bg-dark-700 border-dark-500 text-primary-500 focus:ring-primary-500" />
                        <span class="text-sm text-dark-300">เปิดใช้งาน (Active)</span>
                    </label>

                    <!-- Buttons -->
                    <div class="flex justify-end gap-3 pt-3 border-t border-dark-600">
                        <button type="button" @click="showModal = false" class="px-4 py-2 text-sm text-dark-400 hover:text-white transition-colors">ยกเลิก</button>
                        <button type="submit" :disabled="form.processing" class="btn-primary px-6 py-2 rounded-xl text-sm font-medium">
                            {{ form.processing ? 'กำลังบันทึก...' : (editingBanner ? 'อัปเดต' : 'สร้าง') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
