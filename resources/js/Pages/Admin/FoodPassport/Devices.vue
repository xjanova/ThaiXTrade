<script setup>
/**
 * TPIX TRADE - Admin FoodPassport IoT Devices
 * จัดการ IoT devices — สถานะ, regenerate key, ลบ
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    devices: Object,
    deviceStats: Object,
    currentStatus: String,
    currentType: String,
});

const statusFilters = [
    { value: null, label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'maintenance', label: 'Maintenance' },
];

function filterByStatus(status) {
    const params = {};
    if (status) params.status = status;
    if (props.currentType) params.type = props.currentType;
    router.get('/admin/food-passport/devices', params, { preserveState: true });
}

function filterByType(type) {
    const params = {};
    if (props.currentStatus) params.status = props.currentStatus;
    if (type) params.type = type;
    router.get('/admin/food-passport/devices', params, { preserveState: true });
}

function updateDeviceStatus(id, status) {
    router.patch(`/admin/food-passport/devices/${id}/status`, { status });
}

function regenerateKey(id) {
    if (!confirm('Generate a new API key? The old key will stop working immediately.')) return;
    router.post(`/admin/food-passport/devices/${id}/regenerate-key`);
}

function deleteDevice(id) {
    if (!confirm('Delete this device? This action cannot be undone.')) return;
    router.delete(`/admin/food-passport/devices/${id}`);
}

function getStatusBadge(status) {
    const map = {
        active: 'bg-green-500/20 text-green-400',
        inactive: 'bg-gray-500/20 text-gray-400',
        maintenance: 'bg-yellow-500/20 text-yellow-400',
    };
    return map[status] || 'bg-gray-500/20 text-gray-400';
}

function formatDate(d) {
    if (!d) return 'Never';
    return new Date(d).toLocaleString('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function isOffline(device) {
    if (!device.last_ping_at) return true;
    return new Date(device.last_ping_at) < new Date(Date.now() - 60 * 60 * 1000);
}
</script>

<template>
    <AdminLayout title="IoT Devices">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <Link href="/admin/food-passport" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    FoodPassport
                </Link>
                <h1 class="text-2xl font-bold text-white">IoT Device Management</h1>
            </div>
        </div>

        <!-- Device Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-white">{{ deviceStats?.total || 0 }}</p>
                <p class="text-xs text-gray-400">Total Devices</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-green-500/20">
                <p class="text-2xl font-bold text-green-400">{{ deviceStats?.active || 0 }}</p>
                <p class="text-xs text-gray-400">Active</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-gray-500/20">
                <p class="text-2xl font-bold text-gray-400">{{ deviceStats?.inactive || 0 }}</p>
                <p class="text-xs text-gray-400">Inactive</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-yellow-500/20">
                <p class="text-2xl font-bold text-yellow-400">{{ deviceStats?.maintenance || 0 }}</p>
                <p class="text-xs text-gray-400">Maintenance</p>
            </div>
        </div>

        <!-- Device Types -->
        <div v-if="deviceStats?.types && Object.keys(deviceStats.types).length" class="flex flex-wrap gap-2 mb-4">
            <span class="text-xs text-gray-500 leading-7">Types:</span>
            <button
                v-for="(count, type) in deviceStats.types"
                :key="type"
                @click="filterByType(currentType === type ? null : type)"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                :class="currentType === type ? 'bg-cyan-500/20 text-cyan-400' : 'bg-white/5 text-gray-400 hover:bg-white/10'"
            >
                {{ type }} ({{ count }})
            </button>
        </div>

        <!-- Status Filters -->
        <div class="flex gap-2 mb-6">
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

        <!-- Devices Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">Device</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Type</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Owner</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Traces</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Status</th>
                            <th class="text-left p-4 text-gray-400 font-medium">Last Ping</th>
                            <th class="text-right p-4 text-gray-400 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="device in devices?.data || []"
                            :key="device.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="relative">
                                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center">
                                            <span class="text-sm">📡</span>
                                        </div>
                                        <div
                                            v-if="device.status === 'active'"
                                            class="absolute -top-1 -right-1 w-3 h-3 rounded-full border-2 border-dark-900"
                                            :class="isOffline(device) ? 'bg-red-500' : 'bg-green-500'"
                                        ></div>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white font-mono text-xs">{{ device.device_id }}</p>
                                        <p class="text-xs text-gray-400">{{ device.name || '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 text-gray-300">{{ device.type || '—' }}</td>
                            <td class="p-4">
                                <span class="text-xs text-gray-400 font-mono">{{ device.owner_address?.slice(0, 8) }}...{{ device.owner_address?.slice(-4) }}</span>
                            </td>
                            <td class="p-4 text-white font-medium">{{ device.traces_count || 0 }}</td>
                            <td class="p-4">
                                <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', getStatusBadge(device.status)]">
                                    {{ device.status }}
                                </span>
                            </td>
                            <td class="p-4">
                                <span class="text-xs" :class="isOffline(device) ? 'text-red-400' : 'text-gray-400'">
                                    {{ formatDate(device.last_ping_at) }}
                                </span>
                            </td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <!-- Status Toggle -->
                                    <select
                                        :value="device.status"
                                        @change="updateDeviceStatus(device.id, $event.target.value)"
                                        class="bg-dark-800 border border-white/10 rounded-lg text-xs text-gray-300 px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary-500"
                                    >
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="maintenance">Maintenance</option>
                                    </select>
                                    <button
                                        @click="regenerateKey(device.id)"
                                        class="px-3 py-1.5 rounded-lg bg-yellow-500/10 text-yellow-400 text-xs font-medium hover:bg-yellow-500/20 transition-colors"
                                        title="Regenerate API Key"
                                    >
                                        Key
                                    </button>
                                    <button
                                        @click="deleteDevice(device.id)"
                                        class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!devices?.data?.length">
                            <td colspan="7" class="p-8 text-center text-gray-400">No devices found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="devices?.last_page > 1" class="flex items-center justify-between p-4 border-t border-white/5">
                <p class="text-xs text-gray-400">
                    Showing {{ devices.from }}–{{ devices.to }} of {{ devices.total }}
                </p>
                <div class="flex gap-1">
                    <Link
                        v-for="link in devices.links"
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
