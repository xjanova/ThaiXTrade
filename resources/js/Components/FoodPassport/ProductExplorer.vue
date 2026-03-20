<script setup>
/**
 * ProductExplorer — สำรวจสินค้าทั้งหมดในระบบ
 */
import { ref, onMounted, watch } from 'vue';

const props = defineProps({
    categories: { type: Object, required: true },
    stageConfig: { type: Object, required: true },
});

const products = ref([]);
const loading = ref(false);
const selectedCategory = ref('');
const selectedStatus = ref('');
const currentPage = ref(1);

async function fetchProducts() {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (selectedCategory.value) params.set('category', selectedCategory.value);
        if (selectedStatus.value) params.set('status', selectedStatus.value);
        params.set('per_page', '12');
        params.set('page', currentPage.value);

        const res = await fetch(`/api/v1/food-passport/products?${params}`);
        const json = await res.json();
        if (json.success) products.value = json.data.data || [];
    } catch (e) { console.error(e); }
    loading.value = false;
}

watch([selectedCategory, selectedStatus], () => {
    currentPage.value = 1;
    fetchProducts();
});

onMounted(fetchProducts);
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white">สำรวจสินค้า FoodPassport</h2>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 mb-6">
            <button @click="selectedCategory = ''"
                :class="['px-3 py-1.5 rounded-lg text-sm transition-all', !selectedCategory ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white']">
                ทั้งหมด
            </button>
            <button v-for="(cat, key) in categories" :key="key"
                @click="selectedCategory = key"
                :class="['px-3 py-1.5 rounded-lg text-sm transition-all', selectedCategory === key ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white']">
                {{ cat.emoji }} {{ cat.label }}
            </button>
        </div>

        <div class="flex gap-2 mb-6">
            <button v-for="status in ['', 'registered', 'in_transit', 'certified']" :key="status"
                @click="selectedStatus = status"
                :class="['px-3 py-1.5 rounded-lg text-xs transition-all', selectedStatus === status ? 'bg-accent-500 text-white' : 'bg-dark-800 text-dark-500 hover:text-white']">
                {{ status || 'All Status' }}
            </button>
        </div>

        <!-- Products Grid -->
        <div v-if="loading" class="text-center py-12">
            <div class="spinner mx-auto"></div>
        </div>
        <div v-else-if="products.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="product in products" :key="product.id"
                class="glass-card rounded-xl p-5 hover:border-primary-500/30 transition-all cursor-pointer group">
                <div class="flex items-start gap-3">
                    <span class="text-3xl">{{ categories[product.category]?.emoji || '📦' }}</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-white font-semibold truncate group-hover:text-primary-400 transition-colors">{{ product.name }}</p>
                        <p class="text-dark-500 text-xs mt-0.5">{{ product.origin }}</p>
                    </div>
                </div>
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-white/5">
                    <div class="flex gap-2">
                        <span class="text-dark-600 text-xs">Batch: {{ product.batch_number }}</span>
                    </div>
                    <span :class="[
                        'text-[10px] px-2 py-0.5 rounded-full',
                        product.status === 'certified' ? 'bg-trading-green/10 text-trading-green' :
                        product.status === 'in_transit' ? 'bg-blue-500/10 text-blue-400' :
                        'bg-dark-700 text-dark-400'
                    ]">
                        {{ product.status }}
                    </span>
                </div>
                <div v-if="product.traces_count" class="mt-2 text-dark-600 text-[10px]">
                    {{ product.traces_count }} checkpoints recorded
                </div>
            </div>
        </div>
        <div v-else class="glass-card rounded-2xl p-12 text-center">
            <p class="text-4xl mb-3">🔍</p>
            <p class="text-dark-400">ไม่พบสินค้าในหมวดหมู่นี้</p>
        </div>
    </div>
</template>
