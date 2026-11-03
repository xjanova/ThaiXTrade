<script setup>
/**
 * TPIX TRADE - FoodPassport Page
 * ระบบตรวจสอบที่มาอาหารบน Blockchain + IoT
 * ง่ายเหมือนกดตู้น้ำ: ลงทะเบียน → IoT บันทึก → Mint NFT → สแกน QR
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import FoodPassportHero from '@/Components/FoodPassport/FoodPassportHero.vue';
import HowItWorks from '@/Components/FoodPassport/HowItWorks.vue';
import ProductRegistration from '@/Components/FoodPassport/ProductRegistration.vue';
import TraceTimeline from '@/Components/FoodPassport/TraceTimeline.vue';
import IoTDashboard from '@/Components/FoodPassport/IoTDashboard.vue';
import MintCertificate from '@/Components/FoodPassport/MintCertificate.vue';
import ProductExplorer from '@/Components/FoodPassport/ProductExplorer.vue';
import TokenGuide from '@/Components/FoodPassport/TokenGuide.vue';
import FDPTokenPanel from '@/Components/FoodPassport/FDPTokenPanel.vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    recentProducts: { type: Object, default: () => ({}) },
    certificates: { type: Object, default: () => ({}) },
    verifyMode: { type: Boolean, default: false },
    verifyData: { type: Object, default: null },
});

const walletStore = useWalletStore();
const { t } = useTranslation();
const activeTab = ref(props.verifyMode ? 'verify' : 'overview');
const selectedProduct = ref(null);
const showRegisterModal = ref(false);
const showMintModal = ref(false);
const showIoTModal = ref(false);

const tabs = [
    { id: 'overview', labelKey: 'overview', icon: 'grid' },
    { id: 'how-it-works', labelKey: 'howItWorks', icon: 'book' },
    { id: 'register', labelKey: 'register', icon: 'plus' },
    { id: 'my-products', labelKey: 'myProducts', icon: 'box' },
    { id: 'iot', labelKey: 'iot', icon: 'cpu' },
    { id: 'fdp-token', labelKey: 'fdpToken', icon: 'coin' },
    { id: 'token-guide', labelKey: 'tokenGuide', icon: 'factory' },
    { id: 'explore', labelKey: 'explore', icon: 'search' },
];

const categories = computed(() => ({
    fruit: { label: t('page.foodPassport.categories.fruit'), emoji: '🍎', color: '#EF4444' },
    vegetable: { label: t('page.foodPassport.categories.vegetable'), emoji: '🥬', color: '#22C55E' },
    meat: { label: t('page.foodPassport.categories.meat'), emoji: '🥩', color: '#DC2626' },
    dairy: { label: t('page.foodPassport.categories.dairy'), emoji: '🥛', color: '#F5F5DC' },
    seafood: { label: t('page.foodPassport.categories.seafood'), emoji: '🦐', color: '#06B6D4' },
    grain: { label: t('page.foodPassport.categories.grain'), emoji: '🌾', color: '#F59E0B' },
    processed: { label: t('page.foodPassport.categories.processed'), emoji: '🏭', color: '#8B5CF6' },
    beverage: { label: t('page.foodPassport.categories.beverage'), emoji: '🧃', color: '#3B82F6' },
}));

const stageConfig = computed(() => ({
    farm: { label: t('page.foodPassport.stages.farm'), icon: '🌱', color: '#22C55E' },
    processing: { label: t('page.foodPassport.stages.processing'), icon: '🏭', color: '#F59E0B' },
    storage: { label: t('page.foodPassport.stages.storage'), icon: '📦', color: '#6366F1' },
    transport: { label: t('page.foodPassport.stages.transport'), icon: '🚛', color: '#3B82F6' },
    retail: { label: t('page.foodPassport.stages.retail'), icon: '🏪', color: '#EC4899' },
}));

const briefSteps = computed(() => [
    { num: '1', key: 's1', icon: '📝', color: 'from-green-500/20 to-green-600/20' },
    { num: '2', key: 's2', icon: '📡', color: 'from-blue-500/20 to-blue-600/20' },
    { num: '3', key: 's3', icon: '🏆', color: 'from-purple-500/20 to-purple-600/20' },
    { num: '4', key: 's4', icon: '📱', color: 'from-pink-500/20 to-pink-600/20' },
].map((step) => ({
    ...step,
    title: t(`page.foodPassport.howBrief.${step.key}.title`),
    desc: t(`page.foodPassport.howBrief.${step.key}.desc`),
})));

// API calls
const loading = ref(false);
const products = ref([]);
const myProducts = ref([]);
const iotDevices = ref([]);
// เซิร์ฟเวอร์ตอบ 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า — ต้องไม่ดูเหมือน "ยังไม่มีสินค้า/อุปกรณ์"
const needsVerification = ref(false);
const isVerifying = ref(false);

async function fetchProducts() {
    loading.value = true;
    try {
        const res = await fetch('/api/v1/food-passport/products');
        const json = await res.json();
        if (json.success) products.value = json.data.data || [];
    } catch (e) { console.error(e); }
    loading.value = false;
}

/*
 * รายการ "ของฉัน" — ส่งเลขกระเป๋าในชื่อ wallet_address ซึ่งด่าน VerifyWalletOwnership ตรวจ
 *
 * ⚠️ เดิมส่ง ?address= ที่ด่านมองไม่เห็น เซิร์ฟเวอร์เลยคืนรายการของกระเป๋าไหนก็ได้ให้ทุกคน
 *    ยิงแบบ same-origin เพื่อให้คุกกี้ session ไปด้วย (ด่านรับ session ของบัญชีที่ผูกกระเป๋านี้)
 *    คืน null เมื่อไม่ได้ผล — รวมถึงกรณีสลับกระเป๋าระหว่างรอ (คำตอบนั้นเป็นของกระเป๋าเดิม)
 */
async function fetchMine(path) {
    const address = walletStore.address;
    if (!address) return null;
    try {
        const res = await fetch(`/api/v1/food-passport/${path}?wallet_address=${address}`);
        const json = await res.json();
        if (walletStore.address !== address) return null;
        needsVerification.value = res.status === 403;
        return json.success ? json.data.data || [] : null;
    } catch (e) {
        console.error(e);
        return null;
    }
}

async function fetchMyProducts() {
    const list = await fetchMine('my-products');
    if (list) myProducts.value = list;
}

async function fetchDevices() {
    const list = await fetchMine('iot/my-devices');
    if (list) iotDevices.value = list;
}

// แท็บรายการของกระเป๋าที่ยังไม่เซ็นยืนยัน — แสดงการ์ดให้เซ็นแทนรายการว่าง
const showVerifyCard = computed(() =>
    walletStore.isConnected && needsVerification.value && ['my-products', 'iot'].includes(activeTab.value));

/** เซ็นยืนยันกระเป๋าแล้วโหลดใหม่ — แบบเดียวกับ OpenOrders.verifyWallet() */
async function verifyWallet() {
    if (isVerifying.value) return;   // ป๊อปอัพขอลายเซ็นซ้อนกันไม่ได้

    isVerifying.value = true;
    try {
        const hasSigner = await walletStore.verifyOwnership();

        // กระเป๋าฝังที่ยังไม่ปลดล็อกไม่มี signer — ต้องใส่รหัสผ่านที่หน้าต่างเชื่อมกระเป๋า
        if (!hasSigner) {
            walletStore.openConnectModal();
            return;
        }

        await Promise.all([fetchMyProducts(), fetchDevices()]);
    } catch {
        // verifyOwnership จัดการ error ของตัวเองแล้ว — ปุ่มยืนยันยังอยู่ให้กดใหม่ได้
    } finally {
        isVerifying.value = false;
    }
}

function selectProduct(product) {
    selectedProduct.value = product;
    activeTab.value = 'verify';
}

onMounted(() => {
    fetchProducts();
});

/*
 * รายการผูกกับกระเป๋า: สลับ/ตัดกระเป๋า → ล้างของเดิมทันทีแล้วโหลดของใบใหม่
 * เพิ่งเซ็นผ่าน (verifiedAt) → โหลดใหม่ เพราะก่อนเซ็นเซิร์ฟเวอร์ตอบ 403
 *
 * immediate แทนการโหลดใน onMounted — AppLayout (ลูก) mount ก่อนและกู้กระเป๋าทันที
 * ถ้าโหลดทั้งสองที่ จะยิงซ้ำคู่ทุกครั้งที่เปิดหน้า
 */
watch(() => walletStore.address, () => {
    myProducts.value = [];
    iotDevices.value = [];
    needsVerification.value = false;
    fetchMyProducts();
    fetchDevices();
}, { immediate: true });

watch(() => walletStore.verifiedAt, (at) => {
    if (at) {
        fetchMyProducts();
        fetchDevices();
    }
});
</script>

<template>
    <Head :title="t('page.foodPassport.headTitle')" />
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
                        <span>{{ t(`page.foodPassport.tabs.${tab.labelKey}`) }}</span>
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
                            <p class="text-dark-400 text-sm mt-1">{{ t('page.foodPassport.stats.products') }}</p>
                        </div>
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-trading-green">{{ stats.certified_products || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">{{ t('page.foodPassport.stats.certified') }}</p>
                        </div>
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-primary-400">{{ stats.total_traces || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">{{ t('page.foodPassport.stats.traces') }}</p>
                        </div>
                        <div class="glass-card rounded-2xl p-5 text-center">
                            <p class="text-3xl font-bold text-accent-400">{{ stats.total_certificates || 0 }}</p>
                            <p class="text-dark-400 text-sm mt-1">{{ t('page.foodPassport.stats.certificates') }}</p>
                        </div>
                    </div>

                    <!-- How it works (brief) -->
                    <div class="glass-card rounded-2xl p-6 mb-8">
                        <h3 class="text-lg font-bold text-white mb-4">{{ t('page.foodPassport.howBrief.title') }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div v-for="(step, i) in briefSteps" :key="i" class="relative">
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
                            <h3 class="text-lg font-bold text-white">{{ t('page.foodPassport.recent.title') }}</h3>
                            <button @click="activeTab = 'explore'" class="text-primary-400 text-sm hover:text-primary-300">{{ t('page.foodPassport.recent.viewAll') }}</button>
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
                                        {{ product.status === 'certified' ? t('page.foodPassport.recent.certified') : product.status }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-dark-500">
                                    <span>{{ t('page.foodPassport.recent.batch', { batch: product.batch_number }) }}</span>
                                    <span v-if="product.traces_count">{{ t('page.foodPassport.recent.checkpoints', { n: product.traces_count }) }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-12 text-dark-500">
                            <p class="text-4xl mb-3">🌾</p>
                            <p>{{ t('page.foodPassport.recent.empty') }}</p>
                            <button @click="activeTab = 'register'" class="mt-3 text-primary-400 text-sm hover:text-primary-300">
                                {{ t('page.foodPassport.recent.registerFirst') }}
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

                <!-- ยังไม่ได้เซ็นยืนยันกระเป๋า (เซิร์ฟเวอร์ตอบ 403) — ต้องไม่ดูเหมือน "ยังไม่มีสินค้า/อุปกรณ์" -->
                <div v-if="showVerifyCard" class="glass-card rounded-2xl p-12 text-center" data-test="food-passport-verify">
                    <p class="text-4xl mb-3">✍️</p>
                    <p class="text-white font-medium">{{ t('page.foodPassport.verify.title') }}</p>
                    <p class="text-dark-400 text-sm mt-1">{{ t('page.foodPassport.verify.desc') }}</p>
                    <button @click="verifyWallet" :disabled="isVerifying"
                        class="btn-primary text-sm px-5 py-2.5 rounded-xl mt-4 disabled:opacity-50">
                        {{ isVerifying ? t('page.foodPassport.verify.waiting') : t('page.foodPassport.verify.button') }}
                    </button>
                </div>

                <!-- My Products -->
                <template v-if="activeTab === 'my-products' && !showVerifyCard">
                    <div v-if="!walletStore.isConnected" class="glass-card rounded-2xl p-12 text-center">
                        <p class="text-4xl mb-3">🔒</p>
                        <p class="text-white font-medium">{{ t('page.foodPassport.mine.connectTitle') }}</p>
                        <p class="text-dark-400 text-sm mt-1">{{ t('page.foodPassport.mine.connectDesc') }}</p>
                    </div>
                    <div v-else>
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold text-white">{{ t('page.foodPassport.mine.title') }}</h2>
                            <button @click="activeTab = 'register'" class="btn-primary text-sm px-4 py-2 rounded-xl">{{ t('page.foodPassport.mine.registerBtn') }}</button>
                        </div>
                        <div v-if="myProducts.length" class="space-y-4">
                            <div v-for="product in myProducts" :key="product.id"
                                class="glass-card rounded-2xl p-5">
                                <div class="flex items-center gap-4">
                                    <span class="text-3xl">{{ categories[product.category]?.emoji || '📦' }}</span>
                                    <div class="flex-1">
                                        <p class="text-white font-semibold">{{ product.name }}</p>
                                        <p class="text-dark-400 text-sm">{{ t('page.foodPassport.mine.originBatch', { origin: product.origin, batch: product.batch_number }) }}</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <button @click="selectProduct(product)" class="px-3 py-1.5 rounded-lg bg-primary-500/10 text-primary-400 text-sm hover:bg-primary-500/20">{{ t('page.foodPassport.mine.viewDetails') }}</button>
                                        <button v-if="product.status !== 'certified'" @click="selectedProduct = product; showMintModal = true"
                                            class="px-3 py-1.5 rounded-lg bg-accent-500/10 text-accent-400 text-sm hover:bg-accent-500/20">
                                            {{ t('page.foodPassport.mine.mint') }}
                                        </button>
                                        <span v-else class="px-3 py-1.5 rounded-lg bg-trading-green/10 text-trading-green text-sm">{{ t('page.foodPassport.mine.certified') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="glass-card rounded-2xl p-12 text-center">
                            <p class="text-4xl mb-3">📦</p>
                            <p class="text-dark-400">{{ t('page.foodPassport.mine.empty') }}</p>
                            <button @click="activeTab = 'register'" class="mt-3 text-primary-400 text-sm">{{ t('page.foodPassport.mine.registerNow') }}</button>
                        </div>
                    </div>
                </template>

                <!-- IoT Dashboard -->
                <template v-if="activeTab === 'iot' && !showVerifyCard">
                    <IoTDashboard
                        :devices="iotDevices"
                        :wallet-address="walletStore.address"
                        :is-connected="walletStore.isConnected"
                        @device-registered="(d) => iotDevices.unshift(d)"
                        @devices-updated="(list) => { iotDevices = list; }"
                    />
                </template>

                <!-- FDP Token -->
                <template v-if="activeTab === 'fdp-token'">
                    <FDPTokenPanel />
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
