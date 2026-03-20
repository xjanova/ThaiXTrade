<script setup>
/**
 * TraceTimeline — แสดงเส้นทางอาหารแบบ Timeline
 * ผู้บริโภคสแกน QR → เห็นที่นี่
 */
import { ref, onMounted } from 'vue';

const props = defineProps({
    product: { type: Object, default: null },
    verifyData: { type: Object, default: null },
    stageConfig: { type: Object, required: true },
    categories: { type: Object, required: true },
});

const emit = defineEmits(['back']);

const productData = ref(null);
const traces = ref([]);
const journey = ref([]);
const sensorData = ref(null);
const loading = ref(false);
const activeTrace = ref(null);

async function loadProductData() {
    if (!props.product?.id) return;
    loading.value = true;

    try {
        const res = await fetch(`/api/v1/food-passport/verify/${props.product.id}`);
        const json = await res.json();
        if (json.success) {
            productData.value = json.data.product;
            traces.value = json.data.traces || [];
            journey.value = json.data.journey || [];
        }

        // Load sensor data
        const sensorRes = await fetch(`/api/v1/food-passport/sensor-data/${props.product.id}`);
        const sensorJson = await sensorRes.json();
        if (sensorJson.success) {
            sensorData.value = sensorJson.data;
        }
    } catch (e) { console.error(e); }

    loading.value = false;
}

onMounted(() => {
    if (props.verifyData) {
        productData.value = props.verifyData.product;
        traces.value = props.verifyData.traces || [];
        journey.value = props.verifyData.journey || [];
    } else {
        loadProductData();
    }
});
</script>

<template>
    <div>
        <!-- Back Button -->
        <button @click="emit('back')" class="flex items-center gap-2 text-dark-400 hover:text-white text-sm mb-4 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            กลับ
        </button>

        <div v-if="loading" class="glass-card rounded-2xl p-12 text-center">
            <div class="spinner mx-auto mb-3"></div>
            <p class="text-dark-400">กำลังโหลดข้อมูล...</p>
        </div>

        <div v-else-if="productData || product" class="space-y-6">
            <!-- Product Info Card -->
            <div class="glass-card rounded-2xl p-6">
                <div class="flex items-start gap-4">
                    <span class="text-4xl">{{ categories[(productData || product)?.category]?.emoji || '📦' }}</span>
                    <div class="flex-1">
                        <h2 class="text-xl font-bold text-white">{{ (productData || product)?.name }}</h2>
                        <p class="text-dark-400 text-sm mt-1">{{ (productData || product)?.origin }}</p>
                        <div class="flex flex-wrap gap-3 mt-3">
                            <span class="px-2.5 py-1 rounded-lg bg-dark-800 text-dark-300 text-xs">
                                Batch: {{ (productData || product)?.batch_number }}
                            </span>
                            <span v-if="(productData || product)?.weight_kg" class="px-2.5 py-1 rounded-lg bg-dark-800 text-dark-300 text-xs">
                                {{ (productData || product)?.weight_kg }} kg
                            </span>
                            <span v-if="(productData || product)?.producer_name" class="px-2.5 py-1 rounded-lg bg-dark-800 text-dark-300 text-xs">
                                {{ (productData || product)?.producer_name }}
                            </span>
                            <span :class="[
                                'px-2.5 py-1 rounded-lg text-xs',
                                (productData || product)?.status === 'certified'
                                    ? 'bg-trading-green/10 text-trading-green'
                                    : 'bg-primary-500/10 text-primary-400'
                            ]">
                                {{ (productData || product)?.status === 'certified' ? 'Certified' : (productData || product)?.status }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Journey Timeline -->
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-6">เส้นทางอาหาร (Food Journey)</h3>

                <div class="relative">
                    <!-- Timeline Line -->
                    <div class="absolute left-6 top-0 bottom-0 w-0.5 bg-dark-700"></div>

                    <!-- Timeline Items -->
                    <div v-for="(stage, i) in (journey.length ? journey : Object.entries(stageConfig).map(([key, val]) => ({ stage: key, label: val, completed: false, traces: [] })))"
                        :key="i" class="relative flex gap-4 pb-8 last:pb-0">
                        <!-- Dot -->
                        <div class="relative z-10 flex-shrink-0">
                            <div :class="[
                                'w-12 h-12 rounded-xl flex items-center justify-center text-xl',
                                stage.completed
                                    ? 'bg-gradient-to-br shadow-lg'
                                    : 'bg-dark-800 border border-dark-600'
                            ]"
                            :style="stage.completed ? { background: `linear-gradient(135deg, ${stageConfig[stage.stage]?.color}30, ${stageConfig[stage.stage]?.color}10)`, boxShadow: `0 4px 15px ${stageConfig[stage.stage]?.color}20` } : {}">
                                {{ stageConfig[stage.stage]?.icon || '📍' }}
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 :class="['font-semibold', stage.completed ? 'text-white' : 'text-dark-500']">
                                    {{ stageConfig[stage.stage]?.label || stage.stage }}
                                </h4>
                                <span v-if="stage.completed" class="text-[10px] text-trading-green px-1.5 py-0.5 rounded-full bg-trading-green/10">PASSED</span>
                                <span v-else class="text-[10px] text-dark-600 px-1.5 py-0.5 rounded-full bg-dark-800">PENDING</span>
                            </div>

                            <!-- Trace records for this stage -->
                            <div v-if="stage.traces?.length" class="mt-2 space-y-2">
                                <div v-for="trace in (Array.isArray(stage.traces) ? stage.traces : [])" :key="trace.id"
                                    @click="activeTrace = activeTrace === trace.id ? null : trace.id"
                                    class="p-3 rounded-lg bg-dark-800/50 border border-white/5 cursor-pointer hover:border-white/10 transition-all">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 text-sm">
                                                <span v-if="trace.temperature !== null" class="text-cyan-400">{{ trace.temperature }}°C</span>
                                                <span v-if="trace.humidity !== null" class="text-blue-400">{{ trace.humidity }}%</span>
                                                <span v-if="trace.location" class="text-dark-500 text-xs">📍 {{ trace.location }}</span>
                                            </div>
                                        </div>
                                        <span class="text-dark-600 text-xs">{{ new Date(trace.recorded_at).toLocaleString('th-TH') }}</span>
                                    </div>

                                    <!-- Expanded details -->
                                    <div v-if="activeTrace === trace.id" class="mt-2 pt-2 border-t border-white/5 text-xs space-y-1">
                                        <p v-if="trace.weight_kg" class="text-dark-400">น้ำหนัก: {{ trace.weight_kg }} kg</p>
                                        <p v-if="trace.ph_level" class="text-dark-400">pH: {{ trace.ph_level }}</p>
                                        <p v-if="trace.notes" class="text-dark-400">{{ trace.notes }}</p>
                                        <p class="text-dark-600 font-mono">Recorder: {{ trace.recorder_address?.slice(0, 10) }}...</p>
                                        <p v-if="trace.tx_hash" class="text-primary-400 font-mono">TX: {{ trace.tx_hash?.slice(0, 16) }}...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sensor Data (if available) -->
            <div v-if="sensorData?.has_data" class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">IoT Sensor Data</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/10 text-center">
                        <p class="text-2xl font-bold text-cyan-400">{{ sensorData.temperature?.current }}°C</p>
                        <p class="text-dark-400 text-xs mt-1">อุณหภูมิล่าสุด</p>
                        <p class="text-dark-600 text-[10px] mt-0.5">Min: {{ sensorData.temperature?.min }}°C | Max: {{ sensorData.temperature?.max }}°C</p>
                    </div>
                    <div class="p-4 rounded-xl bg-blue-500/5 border border-blue-500/10 text-center">
                        <p class="text-2xl font-bold text-blue-400">{{ sensorData.humidity?.current }}%</p>
                        <p class="text-dark-400 text-xs mt-1">ความชื้นล่าสุด</p>
                        <p class="text-dark-600 text-[10px] mt-0.5">Avg: {{ sensorData.humidity?.avg }}%</p>
                    </div>
                    <div class="p-4 rounded-xl bg-green-500/5 border border-green-500/10 text-center">
                        <p class="text-2xl font-bold text-green-400">{{ sensorData.total_readings }}</p>
                        <p class="text-dark-400 text-xs mt-1">จำนวนข้อมูล</p>
                    </div>
                    <div class="p-4 rounded-xl bg-purple-500/5 border border-purple-500/10 text-center">
                        <p class="text-2xl font-bold text-purple-400">{{ sensorData.temperature?.avg }}°C</p>
                        <p class="text-dark-400 text-xs mt-1">อุณหภูมิเฉลี่ย</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="glass-card rounded-2xl p-12 text-center">
            <p class="text-4xl mb-3">🔍</p>
            <p class="text-dark-400">ไม่พบข้อมูลสินค้า</p>
        </div>
    </div>
</template>
