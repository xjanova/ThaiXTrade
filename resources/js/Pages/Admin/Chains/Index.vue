<script setup>
/**
 * TPIX TRADE - Admin Chains Management
 * Blockchain chains management with create/edit/toggle
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Admin/Modal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    chains: {
        type: Array,
        default: () => [],
    },
});

const showModal = ref(false);
const showDeleteConfirm = ref(false);
const editingChain = ref(null);
const deletingChain = ref(null);

const form = useForm({
    name: '',
    symbol: '',
    chain_id_hex: '',
    rpc_url: '',
    explorer_url: '',
    /*
     * ★ แยกสองคีย์ออกจากกัน — เดิมยัดไฟล์ลง `logo` ซึ่งฝั่งเซิร์ฟเวอร์ตรวจเป็น string
     *   ทำให้อัปโหลดไอคอนเชนไม่เคยสำเร็จเลยสักครั้ง และ error ก็ไม่เคยถูกแสดง
     *
     *   logo      = URL ข้อความ (ทางเลือกสำรอง เช่น CDN ภายนอก)
     *   logo_file = ไฟล์ที่เลือกจากเครื่อง
     */
    logo: '',
    logo_file: null,
    is_active: true,
    is_testnet: false,
    native_currency_name: '',
    native_currency_symbol: '',
    native_currency_decimals: 18,
    block_confirmations: 12,
    sort_order: 0,
    // ── ย้ายมาจาก config/chains.php — แก้จากหลังบ้านได้แล้ว ──
    short_name: '',
    status: 'coming_soon',
    color: '#06B6D4',
    gasless: false,
    block_time: null,
    consensus: '',
});

const logoPreview = ref(null);

const handleLogoChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.logo_file = file;
        logoPreview.value = URL.createObjectURL(file);
    }
};

/** ล้างไฟล์ที่เลือกไว้ กลับไปใช้ไอคอนเดิมของเชน */
const clearLogoFile = () => {
    form.logo_file = null;
    logoPreview.value = editingChain.value?.logo_url || null;
};

const openCreateModal = () => {
    editingChain.value = null;
    form.reset();
    form.clearErrors();
    logoPreview.value = null;
    showModal.value = true;
};

const openEditModal = (chain) => {
    editingChain.value = chain;
    form.name = chain.name;
    form.symbol = chain.symbol;
    form.chain_id_hex = chain.chain_id_hex;
    form.rpc_url = chain.rpc_url;
    form.explorer_url = chain.explorer_url || '';
    form.is_active = chain.is_active;
    form.is_testnet = chain.is_testnet;
    form.native_currency_name = chain.native_currency_name || '';
    form.native_currency_symbol = chain.native_currency_symbol || '';
    form.native_currency_decimals = chain.native_currency_decimals || 18;
    form.block_confirmations = chain.block_confirmations || 12;
    form.sort_order = chain.sort_order ?? 0;
    form.short_name = chain.short_name || '';
    form.status = chain.status || 'coming_soon';
    form.color = chain.color || '#06B6D4';
    form.gasless = !!chain.gasless;
    form.block_time = chain.block_time ?? null;
    form.consensus = chain.consensus || '';

    /*
     * ★ ห้ามตั้ง logo เป็น null ตรงนี้
     *
     * เดิมตั้งเป็น null แล้วส่งคีย์นั้นไปด้วยทุกครั้ง ฝั่งเซิร์ฟเวอร์จึงเขียนทับ
     * ไอคอนเดิมเป็น NULL ทันทีที่กด Update — แอดมินที่เข้ามาแก้แค่ RPC
     * ก็ทำให้ไอคอนหายถาวร โดยระบบขึ้นข้อความว่าสำเร็จ
     *
     * ใส่กลับเฉพาะกรณีที่เป็น URL ภายนอก เพราะไฟล์ที่อัปโหลดเองจะถูกคงไว้
     * โดยฝั่งเซิร์ฟเวอร์อยู่แล้ว (resolveLogoInput คืนค่าเดิมเมื่อไม่ได้ส่งอะไรมา)
     */
    form.logo = (chain.logo && chain.logo.startsWith('http')) ? chain.logo : '';
    form.logo_file = null;
    logoPreview.value = chain.logo_url || null;
    form.clearErrors();
    showModal.value = true;
};

const saveChain = () => {
    const opts = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { showModal.value = false; },
    };

    if (editingChain.value) {
        /*
         * ═══════════════════════════════════════════════════════════════════
         * ★ ต้องเป็น POST + _method:'put' ห้ามใช้ form.put() กับ multipart
         * ═══════════════════════════════════════════════════════════════════
         * PHP แกะ multipart/form-data ให้เฉพาะคำขอที่เป็น POST เท่านั้น
         * คำขอ PUT ที่แนบ FormData มาจะมาถึงแบบตัวเปล่า — $request ว่างทั้งก้อน
         *
         * ผลที่เกิดจริง: กด Update แล้วขึ้น "The name field is required."
         * ใต้ช่องที่มีคำว่า "TPIX Chain" อยู่เต็มๆ → แก้เชนไม่ได้เลยสักแถว
         * ซึ่งเป็นเหตุผลที่ 11 แถวบน production ยังค้างค่าจาก seeder ทุกตัว
         *
         * วิธีนี้คือวิธีเดียวกับที่หน้า Tokens ใช้อยู่แล้วและทำงานได้จริง
         */
        form.transform((data) => ({ ...data, _method: 'put' }))
            .post(`/admin/chains/${editingChain.value.id}`, opts);
    } else {
        form.post('/admin/chains', opts);
    }
};

const confirmDelete = (chain) => {
    deletingChain.value = chain;
    showDeleteConfirm.value = true;
};

const isDeleting = ref(false);
const togglingId = ref(null);

const deleteChain = () => {
    if (isDeleting.value) return;
    isDeleting.value = true;

    router.delete(`/admin/chains/${deletingChain.value.id}`, {
        preserveScroll: true,
        // ปิดกล่องเฉพาะตอนลบสำเร็จจริง ถ้าถูกปฏิเสธเพราะยังมีของผูกอยู่
        // ต้องปล่อยให้แบนเนอร์ error ใน AdminLayout อธิบายเหตุผล
        onSuccess: () => { showDeleteConfirm.value = false; },
        onFinish: () => { isDeleting.value = false; },
    });
};

const toggleActive = (chain) => {
    // กดรัวๆ = สลับไปกลับ แล้วแอดมินไม่รู้ว่าสุดท้ายค้างสถานะไหน
    if (togglingId.value !== null) return;
    togglingId.value = chain.id;

    router.patch(`/admin/chains/${chain.id}/toggle`, {}, {
        preserveScroll: true,
        onFinish: () => { togglingId.value = null; },
    });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
</script>

<template>
    <Head title="Chains Management" />

    <AdminLayout title="Chains Management">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Blockchain Chains</h2>
                <p class="text-sm text-dark-400 mt-1">Manage supported blockchain networks</p>
            </div>
            <button @click="openCreateModal" class="btn-primary px-4 py-2.5 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Chain
            </button>
        </div>

        <!-- Chains Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div
                v-for="chain in chains"
                :key="chain.id"
                class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 hover:bg-white/[0.07] transition-all duration-200 group"
            >
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div v-if="chain.logo_url" class="w-10 h-10 rounded-full overflow-hidden bg-dark-800">
                            <img :src="chain.logo_url" :alt="chain.name" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
                            <span class="text-white font-bold text-sm">{{ (chain.symbol || chain.name || '?').charAt(0) }}</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">{{ chain.name }}</h3>
                            <p class="text-xs text-dark-400">{{ chain.symbol }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <!--
                            สถานะนี้คือตัวตัดสินว่าเชนกดเลือกได้ไหมในหน้าเว็บ
                            เดิมอยู่ในไฟล์ PHP เท่านั้น หน้าแอดมินจึงมองไม่เห็นเลยว่าเชนไหนเปิดอยู่
                        -->
                        <span
                            class="px-2 py-0.5 text-[10px] font-semibold rounded-full border"
                            :class="{
                                'bg-green-500/20 text-green-300 border-green-500/30': chain.status === 'live',
                                'bg-amber-500/20 text-amber-300 border-amber-500/30': chain.status === 'coming_soon',
                                'bg-red-500/20 text-red-300 border-red-500/30': chain.status === 'maintenance',
                            }"
                        >{{ chain.status === 'live' ? 'เทรดได้' : (chain.status === 'maintenance' ? 'ปิดซ่อม' : 'เร็วๆ นี้') }}</span>
                        <StatusBadge v-if="chain.is_testnet" status="testnet" />
                        <button @click="toggleActive(chain)">
                            <span v-if="chain.is_active" class="w-2.5 h-2.5 rounded-full bg-green-400 inline-block"></span>
                            <span v-else class="w-2.5 h-2.5 rounded-full bg-dark-600 inline-block"></span>
                        </button>
                    </div>
                </div>

                <div class="space-y-2 text-sm mb-4">
                    <div class="flex justify-between">
                        <span class="text-dark-400">Chain ID</span>
                        <span class="font-mono text-white">{{ chain.chain_id_hex }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-dark-400">RPC URL</span>
                        <span class="text-dark-300 truncate ml-4 max-w-[180px]" :title="chain.rpc_url">{{ chain.rpc_url }}</span>
                    </div>
                    <!-- เห็นตั้งแต่แรกว่าเชนนี้มีของผูกอยู่เท่าไหร่ ก่อนจะไปกดลบ -->
                    <div class="flex justify-between">
                        <span class="text-dark-400">ผูกอยู่</span>
                        <span class="text-dark-300">
                            {{ chain.tokens_count ?? 0 }} โทเคน · {{ chain.trading_pairs_count ?? 0 }} คู่เทรด
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-3 border-t border-white/5">
                    <Link
                        :href="`/admin/chains/${chain.id}/tokens`"
                        class="flex-1 text-center px-3 py-2 rounded-lg text-xs font-medium text-primary-400 hover:bg-primary-500/10 transition-colors"
                    >
                        View Tokens
                    </Link>
                    <button
                        @click="openEditModal(chain)"
                        class="px-3 py-2 rounded-lg text-xs font-medium text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                    >
                        Edit
                    </button>
                    <button
                        :disabled="(chain.tokens_count || 0) > 0 || (chain.trading_pairs_count || 0) > 0"
                        :title="((chain.tokens_count || 0) > 0 || (chain.trading_pairs_count || 0) > 0)
                            ? 'ลบไม่ได้ — ยังมีโทเคน/คู่เทรดผูกอยู่ ใช้ปุ่มปิดใช้งานแทน'
                            : 'ลบเชนนี้'"
                        @click="confirmDelete(chain)"
                        class="px-3 py-2 rounded-lg text-xs font-medium transition-colors disabled:opacity-30 disabled:cursor-not-allowed text-dark-400 hover:text-red-400 hover:bg-red-500/10"
                    >
                        Delete
                    </button>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="chains.length === 0" class="col-span-full flex flex-col items-center justify-center py-16 text-center">
                <svg class="w-12 h-12 text-dark-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
                <p class="text-dark-400 text-sm">No chains configured yet</p>
                <button @click="openCreateModal" class="mt-3 text-sm text-primary-400 hover:text-primary-300">Add your first chain</button>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" :title="editingChain ? 'Edit Chain' : 'Add Chain'" max-width="xl" @close="showModal = false">
            <form @submit.prevent="saveChain" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Chain Name</label>
                        <input v-model="form.name" type="text" :class="inputClass" placeholder="Ethereum" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Symbol</label>
                        <input v-model="form.symbol" type="text" :class="inputClass" placeholder="ETH" />
                        <p v-if="form.errors.symbol" class="mt-1 text-sm text-red-400">{{ form.errors.symbol }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Chain ID (Hex)</label>
                    <input v-model="form.chain_id_hex" type="text" :class="inputClass" placeholder="0x1" />
                    <p v-if="form.errors.chain_id_hex" class="mt-1 text-sm text-red-400">{{ form.errors.chain_id_hex }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">RPC URL</label>
                    <input v-model="form.rpc_url" type="url" :class="inputClass" placeholder="https://mainnet.infura.io/v3/..." />
                    <p v-if="form.errors.rpc_url" class="mt-1 text-sm text-red-400">{{ form.errors.rpc_url }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">Explorer URL</label>
                    <input v-model="form.explorer_url" type="url" :class="inputClass" placeholder="https://etherscan.io" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-2">ไอคอนเชน</label>
                    <div class="flex items-center gap-4">
                        <div v-if="logoPreview" class="w-10 h-10 rounded-full overflow-hidden bg-dark-800 shrink-0">
                            <img :src="logoPreview" alt="Logo" class="w-full h-full object-cover" />
                        </div>
                        <div v-else class="w-10 h-10 rounded-full bg-dark-800 border border-white/10 flex items-center justify-center shrink-0">
                            <span class="text-dark-500 text-xs">ว่าง</span>
                        </div>
                        <label class="cursor-pointer px-4 py-2 rounded-xl bg-dark-800 border border-white/10 text-sm text-dark-300 hover:text-white hover:bg-dark-700 transition-colors">
                            เลือกไฟล์
                            <input type="file" @change="handleLogoChange" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" />
                        </label>
                        <button
                            v-if="form.logo_file"
                            type="button"
                            class="text-xs text-dark-400 hover:text-white"
                            @click="clearLogoFile"
                        >ยกเลิกไฟล์ที่เลือก</button>
                    </div>
                    <p v-if="form.errors.logo_file" class="mt-1 text-sm text-red-400">{{ form.errors.logo_file }}</p>

                    <!-- ทางเลือกสำรอง: ชี้ไปยัง URL ภายนอก หรือไฟล์ใน public_html เช่น /tpixlogo.webp -->
                    <input
                        v-model="form.logo"
                        type="text"
                        :class="[inputClass, 'mt-2']"
                        placeholder="หรือใส่ URL / path เช่น /tpixlogo.webp"
                    />
                    <p v-if="form.errors.logo" class="mt-1 text-sm text-red-400">{{ form.errors.logo }}</p>
                    <p class="mt-1 text-xs text-dark-500">เว้นว่างทั้งสองช่อง = คงไอคอนเดิมไว้ (ไม่ลบทิ้ง)</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">
                            Currency Name <span class="text-red-400">*</span>
                        </label>
                        <input v-model="form.native_currency_name" type="text" :class="inputClass" placeholder="Ether" />
                        <p v-if="form.errors.native_currency_name" class="mt-1 text-sm text-red-400">{{ form.errors.native_currency_name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">
                            Currency Symbol <span class="text-red-400">*</span>
                        </label>
                        <input v-model="form.native_currency_symbol" type="text" :class="inputClass" placeholder="ETH" />
                        <p v-if="form.errors.native_currency_symbol" class="mt-1 text-sm text-red-400">{{ form.errors.native_currency_symbol }}</p>
                        <!-- เตือนกับดักที่พลาดกันบ่อย: L2 ทุกตัวจ่ายแก๊สเป็น ETH ไม่ใช่เหรียญ governance -->
                        <p class="mt-1 text-xs text-dark-500">เหรียญที่ใช้จ่ายค่าแก๊ส (Arbitrum/Optimism/Base/zkSync = ETH)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Decimals</label>
                        <input v-model.number="form.native_currency_decimals" type="number" :class="inputClass" placeholder="18" />
                        <p v-if="form.errors.native_currency_decimals" class="mt-1 text-sm text-red-400">{{ form.errors.native_currency_decimals }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Block Confirmations</label>
                        <input v-model.number="form.block_confirmations" type="number" :class="inputClass" placeholder="12" />
                        <p v-if="form.errors.block_confirmations" class="mt-1 text-sm text-red-400">{{ form.errors.block_confirmations }}</p>
                    </div>
                    <div>
                        <!-- เดิมช่องนี้ไม่มีในฟอร์ม เชนที่เพิ่มใหม่จึงได้ 0 เสมอ แล้วแทรกขึ้นหน้าสุด -->
                        <label class="block text-sm font-medium text-dark-300 mb-2">ลำดับการแสดงผล</label>
                        <input v-model.number="form.sort_order" type="number" min="0" :class="inputClass" placeholder="0" />
                        <p v-if="form.errors.sort_order" class="mt-1 text-sm text-red-400">{{ form.errors.sort_order }}</p>
                        <p class="mt-1 text-xs text-dark-500">เลขน้อย = อยู่บนสุด</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <!--
                            ★ ค่านี้ตัดสินว่าเชนกดเลือกได้ไหมในหน้าเว็บและแอปมือถือ
                            เดิมฝังอยู่ใน config/chains.php แก้ได้ด้วยการ deploy เท่านั้น
                        -->
                        <label class="block text-sm font-medium text-dark-300 mb-2">
                            สถานะการเทรด <span class="text-red-400">*</span>
                        </label>
                        <select v-model="form.status" :class="inputClass">
                            <option value="live">เทรดได้จริง (live)</option>
                            <option value="coming_soon">เห็นแต่กดไม่ได้ (coming soon)</option>
                            <option value="maintenance">ปิดซ่อมชั่วคราว (maintenance)</option>
                        </select>
                        <p v-if="form.errors.status" class="mt-1 text-sm text-red-400">{{ form.errors.status }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">ชื่อย่อ (Short Name)</label>
                        <input v-model="form.short_name" type="text" :class="inputClass" placeholder="BSC" />
                        <p v-if="form.errors.short_name" class="mt-1 text-sm text-red-400">{{ form.errors.short_name }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">สีประจำเชน</label>
                        <div class="flex items-center gap-2">
                            <input v-model="form.color" type="color" class="h-11 w-14 rounded-xl bg-dark-800 border border-dark-600 cursor-pointer" />
                            <input v-model="form.color" type="text" :class="inputClass" placeholder="#06B6D4" />
                        </div>
                        <p v-if="form.errors.color" class="mt-1 text-sm text-red-400">{{ form.errors.color }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Block Time (วินาที)</label>
                        <input v-model.number="form.block_time" type="number" min="1" :class="inputClass" placeholder="2" />
                        <p v-if="form.errors.block_time" class="mt-1 text-sm text-red-400">{{ form.errors.block_time }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Consensus</label>
                        <input v-model="form.consensus" type="text" :class="inputClass" placeholder="IBFT" />
                        <p v-if="form.errors.consensus" class="mt-1 text-sm text-red-400">{{ form.errors.consensus }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-dark-300">ไม่มีค่าแก๊ส</label>
                        <button type="button" @click="form.gasless = !form.gasless" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.gasless ? 'bg-emerald-500' : 'bg-dark-600'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.gasless ? 'translate-x-6' : 'translate-x-1'"></span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-dark-300">Active</label>
                        <button type="button" @click="form.is_active = !form.is_active" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_active ? 'bg-primary-500' : 'bg-dark-600'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-dark-300">Testnet</label>
                        <button type="button" @click="form.is_testnet = !form.is_testnet" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="form.is_testnet ? 'bg-purple-500' : 'bg-dark-600'">
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="form.is_testnet ? 'translate-x-6' : 'translate-x-1'"></span>
                        </button>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 rounded-xl text-sm text-dark-300 hover:text-white transition-colors">Cancel</button>
                    <button @click="saveChain" :disabled="form.processing" class="btn-primary px-6 py-2.5 text-sm">
                        {{ form.processing ? 'Saving...' : (editingChain ? 'Update' : 'Create') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete Chain"
            :message="`Are you sure you want to delete '${deletingChain?.name}'? All associated tokens and pairs will be affected.`"
            :confirm-text="isDeleting ? 'กำลังลบ...' : 'Delete'"
            :danger="true"
            @confirm="deleteChain"
            @cancel="showDeleteConfirm = false"
        />
    </AdminLayout>
</template>
