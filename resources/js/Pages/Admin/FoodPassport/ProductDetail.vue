<script setup>
/**
 * TPIX TRADE - Admin FoodPassport Product Detail
 * รายละเอียดสินค้า + trace timeline + journey verification
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    product: Object,
    journey: Object,
});

const showStatusModal = ref(false);
const newStatus = ref('');

const statusOptions = [
    { value: 'registered', label: 'Registered', color: 'text-blue-400' },
    { value: 'in_transit', label: 'In Transit', color: 'text-yellow-400' },
    { value: 'at_storage', label: 'At Storage', color: 'text-purple-400' },
    { value: 'at_retail', label: 'At Retail', color: 'text-cyan-400' },
    { value: 'certified', label: 'Certified', color: 'text-green-400' },
    { value: 'suspended', label: 'Suspended', color: 'text-red-400' },
];

function getStatusBadge(status) {
    const map = {
        registered: 'bg-blue-500/20 text-blue-400',
        in_transit: 'bg-yellow-500/20 text-yellow-400',
        at_storage: 'bg-purple-500/20 text-purple-400',
        at_retail: 'bg-cyan-500/20 text-cyan-400',
        certified: 'bg-green-500/20 text-green-400',
        suspended: 'bg-red-500/20 text-red-400',
    };
    return map[status] || 'bg-gray-500/20 text-gray-400';
}

function openStatusModal() {
    newStatus.value = props.product.status;
    showStatusModal.value = true;
}

function updateStatus() {
    router.patch(`/admin/food-passport/products/${props.product.id}/status`, {
        status: newStatus.value,
    });
    showStatusModal.value = false;
}

function suspendProduct() {
    if (!confirm('Suspend this product?')) return;
    router.post(`/admin/food-passport/products/${props.product.id}/suspend`);
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatTemp(t) {
    if (t == null) return '—';
    return `${t}°C`;
}
</script>

<template>
    <AdminLayout :title="`Product: ${product.name}`">
        <!-- Back + Header -->
        <div class="mb-6">
            <Link href="/admin/food-passport" class="text-sm text-gray-400 hover:text-white transition-colors mb-4 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                Back to FoodPassport
            </Link>
            <div class="flex items-start justify-between mt-2">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ product.name }}</h1>
                    <p class="text-sm text-gray-400 mt-1">Batch: {{ product.batch_id || '—' }} · {{ product.traces_count || 0 }} traces</p>
                </div>
                <div class="flex gap-2">
                    <button @click="openStatusModal" class="px-4 py-2 rounded-lg bg-primary-500/10 text-primary-400 text-sm font-medium hover:bg-primary-500/20 transition-colors">
                        Change Status
                    </button>
                    <button
                        v-if="product.status !== 'suspended'"
                        @click="suspendProduct"
                        class="px-4 py-2 rounded-lg bg-red-500/10 text-red-400 text-sm font-medium hover:bg-red-500/20 transition-colors"
                    >
                        Suspend
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Product Info -->
            <div class="lg:col-span-1 space-y-6">
                <div class="glass-dark rounded-xl border border-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Product Info</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-400">Status</span>
                            <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', getStatusBadge(product.status)]">
                                {{ product.status?.replace(/_/g, ' ') }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Category</span>
                            <span class="text-white">{{ product.category || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Origin</span>
                            <span class="text-white">{{ product.origin || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Producer</span>
                            <span class="text-xs text-gray-300 font-mono">{{ product.producer_address?.slice(0, 10) }}...{{ product.producer_address?.slice(-4) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Created</span>
                            <span class="text-white text-sm">{{ formatDate(product.created_at) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Certificate -->
                <div v-if="product.certificate" class="glass-dark rounded-xl border border-green-500/20 p-6">
                    <h3 class="text-sm font-semibold text-green-400 uppercase tracking-wider mb-4">NFT Certificate</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-400">Certificate #</span>
                            <span class="text-white">{{ product.certificate.id }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Status</span>
                            <span :class="product.certificate.status === 'active' ? 'text-green-400' : 'text-red-400'">
                                {{ product.certificate.status }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Issued</span>
                            <span class="text-white text-sm">{{ formatDate(product.certificate.created_at) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Journey Verification -->
                <div v-if="journey" class="glass-dark rounded-xl border border-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Journey Verification</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-400">Verified</span>
                            <span :class="journey.verified ? 'text-green-400' : 'text-red-400'">
                                {{ journey.verified ? 'Yes' : 'No' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Stages</span>
                            <span class="text-white">{{ journey.stages?.length || 0 }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Total Traces</span>
                            <span class="text-white">{{ journey.total_traces || 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trace Timeline -->
            <div class="lg:col-span-2">
                <div class="glass-dark rounded-xl border border-white/10 p-6">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-6">Trace Timeline</h3>
                    <div v-if="product.traces?.length" class="space-y-0">
                        <div
                            v-for="(trace, i) in product.traces"
                            :key="trace.id"
                            class="relative pl-8 pb-6"
                            :class="{ 'border-l-2 border-primary-500/30 ml-3': i < product.traces.length - 1 }"
                        >
                            <!-- Dot -->
                            <div class="absolute left-0 top-0 w-7 h-7 -ml-[14px] rounded-full flex items-center justify-center"
                                :class="i === 0 ? 'bg-primary-500 ring-4 ring-primary-500/20' : 'bg-dark-800 border-2 border-primary-500/40'"
                            >
                                <span class="text-xs text-white font-bold">{{ product.traces.length - i }}</span>
                            </div>

                            <!-- Content -->
                            <div class="glass-dark rounded-lg border border-white/5 p-4 ml-4">
                                <div class="flex items-start justify-between mb-2">
                                    <div>
                                        <p class="font-medium text-white capitalize">{{ trace.stage?.replace(/_/g, ' ') || 'Unknown Stage' }}</p>
                                        <p class="text-xs text-gray-400">{{ trace.location || '—' }}</p>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ formatDate(trace.recorded_at || trace.created_at) }}</span>
                                </div>

                                <!-- Sensor Data -->
                                <div class="flex flex-wrap gap-3 mt-3">
                                    <div v-if="trace.temperature != null" class="flex items-center gap-1.5 text-xs">
                                        <span class="text-gray-500">Temp:</span>
                                        <span :class="trace.temperature < 0 || trace.temperature > 40 ? 'text-red-400 font-bold' : 'text-white'">
                                            {{ formatTemp(trace.temperature) }}
                                        </span>
                                    </div>
                                    <div v-if="trace.humidity != null" class="flex items-center gap-1.5 text-xs">
                                        <span class="text-gray-500">Humidity:</span>
                                        <span class="text-white">{{ trace.humidity }}%</span>
                                    </div>
                                    <div v-if="trace.weight != null" class="flex items-center gap-1.5 text-xs">
                                        <span class="text-gray-500">Weight:</span>
                                        <span class="text-white">{{ trace.weight }} kg</span>
                                    </div>
                                    <div v-if="trace.ph_level != null" class="flex items-center gap-1.5 text-xs">
                                        <span class="text-gray-500">pH:</span>
                                        <span class="text-white">{{ trace.ph_level }}</span>
                                    </div>
                                </div>

                                <!-- Device -->
                                <div v-if="trace.device" class="mt-2 pt-2 border-t border-white/5">
                                    <span class="text-xs text-gray-500">Device: </span>
                                    <span class="text-xs text-cyan-400 font-mono">{{ trace.device.device_id }}</span>
                                    <span class="text-xs text-gray-500"> ({{ trace.device.type }})</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-12 text-gray-400">
                        <p>No traces recorded yet.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Change Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showStatusModal = false"></div>
                    <div class="relative glass-dark rounded-2xl border border-white/10 p-6 w-full max-w-md">
                        <h3 class="text-lg font-bold text-white mb-4">Change Product Status</h3>
                        <div class="space-y-2 mb-6">
                            <label
                                v-for="opt in statusOptions"
                                :key="opt.value"
                                class="flex items-center gap-3 p-3 rounded-lg cursor-pointer transition-colors hover:bg-white/5"
                                :class="{ 'bg-primary-500/10 border border-primary-500/30': newStatus === opt.value }"
                            >
                                <input type="radio" v-model="newStatus" :value="opt.value" class="hidden" />
                                <div class="w-4 h-4 rounded-full border-2" :class="newStatus === opt.value ? 'border-primary-500 bg-primary-500' : 'border-gray-500'"></div>
                                <span :class="opt.color">{{ opt.label }}</span>
                            </label>
                        </div>
                        <div class="flex gap-3">
                            <button @click="showStatusModal = false" class="flex-1 px-4 py-2.5 rounded-lg border border-white/10 text-gray-400 hover:bg-white/5 transition-colors">
                                Cancel
                            </button>
                            <button @click="updateStatus" class="flex-1 px-4 py-2.5 rounded-lg bg-primary-500 text-white font-medium hover:bg-primary-600 transition-colors">
                                Update Status
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>
