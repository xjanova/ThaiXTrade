<script setup>
/**
 * TPIX TRADE - FoodPassport Page
 * ระบบตรวจสอบที่มาอาหารบน Blockchain + IoT
 * ง่ายเหมือนกดตู้น้ำ: ลงทะเบียน → IoT บันทึก → Mint NFT → สแกน QR
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import FoodPassportHero from '@/Components/FoodPassport/FoodPassportHero.vue';
import HowItWorks from '@/Components/FoodPassport/HowItWorks.vue';
import ProductRegistration from '@/Components/FoodPassport/ProductRegistration.vue';
import TraceTimeline from '@/Components/FoodPassport/TraceTimeline.vue';
import IoTDashboard from '@/Components/FoodPassport/IoTDashboard.vue';
import MintCertificate from '@/Components/FoodPassport/MintCertificate.vue';
import ProductExplorer from '@/Components/FoodPassport/ProductExplorer.vue';
import TokenGuide from '@/Components/FoodPassport/TokenGuide.vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    recentProducts: { type: Object, default: () => ({}) },
    certificates: { type: Object, default: () => ({}) },
    verifyMode: { type: Boolean, default: false },
    verifyData: { type: Object, default: null },
});

const walletStore = useWalletStore();
const activeTab = ref(props.verifyMode ? 'verify' : 'overview');
const selectedProduct = ref(null);
const showRegisterModal = ref(false);
const showMintModal = ref(false);
const showIoTModal = ref(false);

const tabs = [
    { id: 'overview', label: 'Overview', icon: 'grid' },
    { id: 'how-it-works', label: 'วิธีใช้งาน', icon: 'book' },
    { id: 'register', label: 'ลงทะเบียน', icon: 'plus' },
    { id: 'my-products', label: 'สินค้าของฉัน', icon: 'box' },
    { id: 'iot', label: 'IoT Devices', icon: 'cpu' },
    { id: 'token-guide', label: 'สร้างเหรียญ', icon: 'coin' },
    { id: 'explore', label: 'สำรวจ', icon: 'search' },
];

const categories = {
    fruit: { label: 'ผลไม้', emoji: '🍎', color: '#EF4444' },
    vegetable: { label: 'ผัก', emoji: '🥬', color: '#22C55E' },
    meat: { label: 'เนื้อสัตว์', emoji: '🥩', color: '#DC2626' },
    dairy: { label: 'นม / ผลิตภัณฑ์นม', emoji: '🥛', color: '#F5F5DC' },
    seafood: { label: 'อาหารทะเล', emoji: '🦐', color: '#06B6D4' },
    grain: { label: 'ธัญพืช / ข้าว', emoji: '🌾', color: '#F59E0B' },
    processed: { label: 'อาหารแปรรูป', emoji: '🏭', color: '#8B5CF6' },
    beverage: { label: 'เครื่องดื่ม', emoji: '🧃', color: '#3B82F6' },
};

const stageConfig = {
    farm: { label: 'ฟาร์ม', icon: '🌱', color: '#22C55E' },
    processing: { label: 'แปรรูป', icon: '🏭', color: '#F59E0B' },
    storage: { label: 'คลังสินค้า', icon: '📦', color: '#6366F1' },
    transport: { label: 'ขนส่ง', icon: '🚛', color: '#3B82F6' },
    retail: { label: 'ร้านค้า', icon: '🏪', color: '#EC4899' },
};

// API calls
const loading = ref(false);
const products = ref([]);
const myProducts = ref([]);
const iotDevices = ref([]);

async function fetchProducts() {
    loading.value = true;
    try {
        const res = await fetch('/api/v1/food-passport/products');
        const json = await res.json();
        if (json.success) products.value = json.data.data || [];
    } catch (e) { console.error(e); }
    loading.value = false;
}

async function fetchMyProducts() {
    if (!walletStore.address) return;
    try {
        const res = await fetch(`/api/v1/food-passport/my-products?address=${walletStore.address}`);
        const json = await res.json();
        if (json.success) myProducts.value = json.data.data || [];
    } catch (e) { console.error(e); }
}

async function fetchDevices() {
    if (!walletStore.address) return;
    try {
        const res = await fetch(`/api/v1/food-passport/iot/my-devices?address=${walletStore.address}`);
        const json = await res.json();
        if (json.success) iotDevices.value = json.data.data || [];
    } catch (e) { console.error(e); }
}

function selectProduct(product) {
    selectedProduct.value = product;
    activeTab.value = 'verify';
}

onMounted(() => {
    fetchProducts();
    if (walletStore.address) {
        fetchMyProducts();
        fetchDevices();
    }
});
</script>

<template>
    <Head title="FoodPassport — ตรวจสอบที่มาอาหาร" />
    <AppLayout>
        <div class="min-h-screen">
            <!-- Hero Section -->
            <FoodPassportHero :stats="stats" />

            <!-- Tab Navigation -->
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 relative z-10">
                <div class="glass-dark rounded-2xl p-1.5 flex gap-1 overflow-x-auto scrollbar-hide">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        :class="[
                            'flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all whitespace-nowrap',
                            activeTab === tab.id
                                ? 'bg-primary-500 text-white shadow-lg shadow-primary-500/25'
                                : 'text-dark-400 hover:text-white hover:bg-white/5'
                        ]"
                    >
                        <span>{{ tab.label }}</span>
                    </button>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

                <!-- Overview -->
                <template v-if="activeTab === 'overview'">
                    <!-- Stats Cards -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-white">{{ stats.total_products || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">สินค้าลงทะเบียน</p>
                        </div>
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-trading-green">{{ stats.certified_products || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">ผ่านการรับรอง</p>
                        </div>
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-primary-400">{{ stats.total_traces || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">จุดตรวจ IoT</p>
                        </div>
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-accent-400">{{ stats.total_certificates || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">NFT ใบรับรอง</p>
                        </div>
                    </div>

                    <!-- How it works (brief) -->
                    <div class="glass-card rounded-2xl p-6 mb-8">
                        <h3 class="text-lg font-bold text-white mb-4">ง่ายเหมือนกดตู้น้ำ — 4 ขั้นตอน</h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div v-for="(step, i) in [
                                { num: '1', title: 'ลงทะเบียนสินค้า', desc: 'เกษตรกรกดปุ่ม ลงทะเบียนสินค้า → ได้ Product ID', icon: '📝', color: 'from-green-500/20 to-green-600/20' },
                                { num: '2', title: 'IoT บันทึกอัตโนมัติ', desc: 'Sensor วัดอุณหภูมิ ความชื้น GPS ส่งขึ้น Chain ทุกจุด', icon: '📡', color: 'from-blue-500/20 to-blue-600/20' },
                                { num: '3', title: 'Mint NFT ใบรับรอง', desc: 'ผ่านทุกจุด → กดปุ่ม Mint → ได้ NFT ใบรับรองบน TPIX Chain', icon: '🏆', color: 'from-purple-500/20 to-purple-600/20' },
                                { num: '4', title: 'ผู้บริโภคสแกน QR', desc: 'สแกน QR Code → เห็นเส้นทางอาหารทั้งหมดแบบ real-time', icon: '📱', color: 'from-pink-500/20 to-pink-600/20' },
                            ]" :key="i" class="relative">
                                <div :class="['rounded-2xl p-5 bg-gradient-to-br border border-white/5', step.color]">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="text-2xl">{{ step.icon }}</span>
                                        <span class="w-7 h-7 rounded-full bg-white/10 flex items-center justify-center text-xs font-bold text-white">{{ step.num }}</span>
                                    </div>
                                    <h4 class="text-white font-semibold text-sm">{{ step.title }}</h4>
                                    <p class="text-dark-400 text-xs mt-1">{{ step.desc }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Products -->
                    <div class="glass-card rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-white">สินค้าล่าสุด</h3>
                            <button @click="activeTab = 'explore'" class="text-primary-400 text-sm hover:text-primary-300">ดูทั้งหมด →</button>
                        </div>
                        <div v-if="products.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="product in products.slice(0, 6)" :key="product.id"
                                @click="selectProduct(product)"
                                class="p-4 rounded-xl bg-dark-800/50 border border-white/5 hover:border-primary-500/30 cursor-pointer transition-all group">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-2xl">{{ categories[product.category]?.emoji || '📦' }}</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-white font-medium truncate group-hover:text-primary-400 transition-colors">{{ product.name }}</p>
                                        <p class="text-dark-500 text-xs">{{ product.origin }}</p>
                                    </div>
                                    <span :class="[
                                        'text-[10px] px-2 py-0.5 rounded-full',
                                        product.status === 'certified' ? 'bg-trading-green/10 text-trading-green' : 'bg-primary-500/10 text-primary-400'
                                    ]">
                                        {{ product.status === 'certified' ? 'Certified' : product.status }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-dark-500">
                                    <span>Batch: {{ product.batch_number }}</span>
                                    <span v-if="product.traces_count">{{ product.traces_count }} checkpoints</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-12 text-dark-500">
                            <p class="text-4xl mb-3">🌾</p>
                            <p>ยังไม่มีสินค้าลงทะเบียน</p>
                            <button @click="activeTab = 'register'" class="mt-3 text-primary-400 text-sm hover:text-primary-300">
                                ลงทะเบียนสินค้าแรก →
                            </button>
                        </div>
                    </div>
                </template>

                <!-- How It Works (Tutorial) -->
                <template v-if="activeTab === 'how-it-works'">
                    <HowItWorks :categories="categories" :stage-config="stageConfig" />
                </template>

                <!-- Register Product -->
                <template v-if="activeTab === 'register'">
                    <ProductRegistration
                        :categories="categories"
                        :wallet-address="walletStore.address"
                        :is-connected="walletStore.isConnected"
                        @registered="(p) => { myProducts.unshift(p); activeTab = 'my-products'; }"
                    />
                </template>

                <!-- My Products -->
                <template v-if="activeTab === 'my-products'">
                    <div v-if="!walletStore.isConnected" class="glass-card rounded-2xl p-12 text-center">
                        <p class="text-4xl mb-3">🔒</p>
                        <p class="text-white font-medium">กรุณาเชื่อมต่อ Wallet</p>
                        <p class="text-dark-400 text-sm mt-1">เชื่อมต่อ wallet เพื่อดูสินค้าของคุณ</p>
                    </div>
                    <div v-else>
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold text-white">สินค้าของฉัน</h2>
                            <button @click="activeTab = 'register'" class="btn-primary text-sm px-4 py-2 rounded-xl">+ ลงทะเบียนสินค้า</button>
                        </div>
                        <div v-if="myProducts.length" class="space-y-4">
                            <div v-for="product in myProducts" :key="product.id"
                                class="glass-card rounded-2xl p-5">
                                <div class="flex items-center gap-4">
                                    <span class="text-3xl">{{ categories[product.category]?.emoji || '📦' }}</span>
                                    <div class="flex-1">
                                        <p class="text-white font-semibold">{{ product.name }}</p>
                                        <p class="text-dark-400 text-sm">{{ product.origin }} | Batch: {{ product.batch_number }}</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <button @click="selectProduct(product)" class="px-3 py-1.5 rounded-lg bg-primary-500/10 text-primary-400 text-sm hover:bg-primary-500/20">ดูรายละเอียด</button>
                                        <button v-if="product.status !== 'certified'" @click="selectedProduct = product; showMintModal = true"
                                            class="px-3 py-1.5 rounded-lg bg-accent-500/10 text-accent-400 text-sm hover:bg-accent-500/20">
                                            Mint NFT
                                        </button>
                                        <span v-else class="px-3 py-1.5 rounded-lg bg-trading-green/10 text-trading-green text-sm">Certified</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="glass-card rounded-2xl p-12 text-center">
                            <p class="text-4xl mb-3">📦</p>
                            <p class="text-dark-400">ยังไม่มีสินค้า</p>
                            <button @click="activeTab = 'register'" class="mt-3 text-primary-400 text-sm">ลงทะเบียนเลย →</button>
                        </div>
                    </div>
                </template>

                <!-- IoT Dashboard -->
                <template v-if="activeTab === 'iot'">
                    <IoTDashboard
                        :devices="iotDevices"
                        :wallet-address="walletStore.address"
                        :is-connected="walletStore.isConnected"
                        @device-registered="(d) => iotDevices.unshift(d)"
                    />
                </template>

                <!-- Token Guide -->
                <template v-if="activeTab === 'token-guide'">
                    <TokenGuide />
                </template>

                <!-- Explore -->
                <template v-if="activeTab === 'explore'">
                    <ProductExplorer :categories="categories" :stage-config="stageConfig" />
                </template>

                <!-- Verify Product (QR scan result) -->
                <template v-if="activeTab === 'verify'">
                    <TraceTimeline
                        :product="verifyData?.product || selectedProduct"
                        :verify-data="verifyData"
                        :stage-config="stageConfig"
                        :categories="categories"
                        @back="activeTab = 'overview'"
                    />
                </template>
            </div>
        </div>

        <!-- Mint Certificate Modal -->
        <Teleport to="body">
            <div v-if="showMintModal" class="modal-overlay" @click.self="showMintModal = false">
                <MintCertificate
                    :product="selectedProduct"
                    :wallet-address="walletStore.address"
                    @close="showMintModal = false"
                    @minted="(cert) => { fetchMyProducts(); showMintModal = false; }"
                />
            </div>
        </Teleport>
    </AppLayout>
</template>
