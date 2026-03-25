<script setup>
/**
 * TPIX TRADE - Admin FoodPassport Dashboard
 * ภาพรวมระบบ Food Traceability — สถิติ, สินค้า, alerts
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    stats: Object,
    products: Object,
    currentStatus: String,
    currentCategory: String,
});

const statusFilters = [
    { value: null, label: 'All' },
    { value: 'registered', label: 'Registered' },
    { value: 'in_transit', label: 'In Transit' },
    { value: 'at_storage', label: 'At Storage' },
    { value: 'at_retail', label: 'At Retail' },
    { value: 'certified', label: 'Certified' },
    { value: 'suspended', label: 'Suspended' },
];

function filterByStatus(status) {
    const params = {};
    if (status) params.status = status;
    if (props.currentCategory) params.category = props.currentCategory;
    router.get('/admin/food-passport', params, { preserveState: true });
}

function suspendProduct(id) {
    if (!confirm('Suspend this product?')) return;
    router.post(`/admin/food-passport/products/${id}/suspend`);
}

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

function formatDate(d) {
    return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

const statCards = [
    { key: 'total_products', label: 'Total Products', icon: '📦', color: 'from-blue-500 to-blue-600' },
    { key: 'certified_products', label: 'Certified', icon: '✅', color: 'from-green-500 to-green-600' },
    { key: 'total_traces', label: 'Total Traces', icon: '📍', color: 'from-purple-500 to-purple-600' },
    { key: 'total_certificates', label: 'Certificates', icon: '🏆', color: 'from-yellow-500 to-yellow-600' },
    { key: 'total_devices', label: 'IoT Devices', icon: '📡', color: 'from-cyan-500 to-cyan-600' },
    { key: 'active_devices', label: 'Active Devices', icon: '🟢', color: 'from-emerald-500 to-emerald-600' },
    { key: 'offline_devices', label: 'Offline Devices', icon: '🔴', color: 'from-red-500 to-red-600' },
    { key: 'temp_alerts', label: 'Temp Alerts (24h)', icon: '🌡️', color: 'from-orange-500 to-orange-600' },
];
</script>

<template>
    <AdminLayout title="FoodPassport">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-white">FoodPassport Management</h1>
                <p class="text-sm text-gray-400 mt-1">Food traceability, IoT devices, and certificate management</p>
            </div>
            <div class="flex gap-3">
                <Link href="/admin/food-passport/devices" class="px-4 py-2 rounded-lg bg-cyan-500/10 text-cyan-400 text-sm font-medium hover:bg-cyan-500/20 transition-colors">
                    IoT Devices
                </Link>
                <Link href="/admin/food-passport/certificates" class="px-4 py-2 rounded-lg bg-yellow-500/10 text-yellow-400 text-sm font-medium hover:bg-yellow-500/20 transition-colors">
                    Certificates
                </Link>
                <Link href="/admin/food-passport/alerts" class="px-4 py-2 rounded-lg bg-red-500/10 text-red-400 text-sm font-medium hover:bg-red-500/20 transition-colors">
                    Alerts
                </Link>
                <Link href="/admin/food-passport/docs" class="px-4 py-2 rounded-lg bg-white/5 text-gray-400 text-sm font-medium hover:bg-white/10 transition-colors">
                    Docs
                </Link>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-4 mb-6">
            <div v-for="card in statCards" :key="card.key" class="glass-dark p-4 rounded-xl border border-white/10">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-lg">{{ card.icon }}</span>
                </div>
                <p class="text-2xl font-bold text-white">{{ stats?.[card.key] ?? 0 }}</p>
                <p class="text-xs text-gray-400">{{ card.label }}</p>
            </div>
        </div>

        <!-- Status Filters -->
        <div class="flex flex-wrap gap-2 mb-6">
            <button
                v-for="f in statusFilters"
                :key="f.value"
                @click="filterByStatus(f.value)"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
                :class="currentStatus === f.value ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
            >
                {{ f.label }}
            </button>
        </div>

        <!-- Products Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">Product</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Category</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Origin</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Traces</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Status</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Certificate</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Date</th>
                            <th class="text-right p-4 text-gray-400 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="product in products?.data || []"
                            :key="product.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center">
                                        <span class="text-sm">🍃</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">{{ product.name }}</p>
                                        <p class="text-xs text-gray-400 font-mono">{{ product.batch_id || '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 text-gray-300">{{ product.category || '—' }}</td>
                            <td class="p-4 text-gray-300">{{ product.origin || '—' }}</td>
                            <td class="p-4">
                                <span class="text-white font-medium">{{ product.traces_count || 0 }}</span>
                            </td>
                            <td class="p-4">
                                <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', getStatusBadge(product.status)]">
                                    {{ product.status?.replace(/_/g, ' ') }}
                                </span>
                            </td>
                            <td class="p-4">
                                <span v-if="product.certificate" class="text-xs px-2.5 py-1 rounded-full font-medium bg-green-500/20 text-green-400">
                                    NFT #{{ product.certificate.id }}
                                </span>
                                <span v-else class="text-xs text-gray-500">None</span>
                            </td>
                            <td class="p-4 text-gray-400 text-xs">{{ formatDate(product.created_at) }}</td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <Link
                                        :href="`/admin/food-passport/products/${product.id}`"
                                        class="px-3 py-1.5 rounded-lg bg-primary-500/10 text-primary-400 text-xs font-medium hover:bg-primary-500/20 transition-colors"
                                    >
                                        View
                                    </Link>
                                    <button
                                        v-if="product.status !== 'suspended'"
                                        @click="suspendProduct(product.id)"
                                        class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        Suspend
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!products?.data?.length">
                            <td colspan="8" class="p-8 text-center text-gray-400">No products found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="products?.last_page > 1" class="flex items-center justify-between p-4 border-t border-white/5">
                <p class="text-xs text-gray-400">
                    Showing {{ products.from }}–{{ products.to }} of {{ products.total }}
                </p>
                <div class="flex gap-1">
                    <Link
                        v-for="link in products.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        :class="link.active ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:bg-white/5'"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
