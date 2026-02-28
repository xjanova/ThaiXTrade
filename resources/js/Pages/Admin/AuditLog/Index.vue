<script setup>
/**
 * TPIX TRADE - Admin Audit Logs
 * Activity log viewer with expandable diff rows
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    logs: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    admins: {
        type: Array,
        default: () => [],
    },
    actions: {
        type: Array,
        default: () => ['created', 'updated', 'deleted', 'login', 'logout', 'toggled', 'assigned'],
    },
});

const filterAdmin = ref(props.filters.admin_id || '');
const filterAction = ref(props.filters.action || '');
const filterDateFrom = ref(props.filters.date_from || '');
const filterDateTo = ref(props.filters.date_to || '');
const expandedRows = ref(new Set());

const toggleRow = (logId) => {
    if (expandedRows.value.has(logId)) {
        expandedRows.value.delete(logId);
    } else {
        expandedRows.value.add(logId);
    }
};

const applyFilters = () => {
    router.get('/admin/audit-logs', {
        admin_id: filterAdmin.value || undefined,
        action: filterAction.value || undefined,
        date_from: filterDateFrom.value || undefined,
        date_to: filterDateTo.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filterAdmin.value = '';
    filterAction.value = '';
    filterDateFrom.value = '';
    filterDateTo.value = '';
    router.get('/admin/audit-logs', {}, { preserveState: true });
};

const formatDiff = (data) => {
    if (!data) return null;
    try {
        if (typeof data === 'string') return JSON.parse(data);
        return data;
    } catch {
        return data;
    }
};

const actionColors = {
    created: 'text-green-400',
    updated: 'text-blue-400',
    deleted: 'text-red-400',
    login: 'text-primary-400',
    logout: 'text-dark-400',
    toggled: 'text-yellow-400',
    assigned: 'text-purple-400',
};

const selectClass = 'bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500';
const inputClass = 'bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white placeholder-dark-500 focus:outline-none focus:border-primary-500';
</script>

<template>
    <Head title="Audit Logs" />

    <AdminLayout title="Audit Logs">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Audit Logs</h2>
                <p class="text-sm text-dark-400 mt-1">Track all admin actions and changes</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Admin</label>
                    <select v-model="filterAdmin" :class="selectClass">
                        <option value="">All Admins</option>
                        <option v-for="admin in admins" :key="admin.id" :value="admin.id">{{ admin.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Action</label>
                    <select v-model="filterAction" :class="selectClass">
                        <option value="">All Actions</option>
                        <option v-for="a in actions" :key="a" :value="a">{{ a.charAt(0).toUpperCase() + a.slice(1) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">From</label>
                    <input v-model="filterDateFrom" type="date" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">To</label>
                    <input v-model="filterDateTo" type="date" :class="inputClass" />
                </div>
                <div class="flex items-center gap-2">
                    <button @click="applyFilters" class="btn-primary px-4 py-2.5 text-sm">Filter</button>
                    <button @click="resetFilters" class="px-4 py-2.5 text-sm text-dark-400 hover:text-white transition-colors">Reset</button>
                </div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="overflow-x-auto rounded-xl border border-white/5">
            <table class="w-full">
                <thead>
                    <tr class="bg-dark-800/50">
                        <th class="w-8 py-3 px-2"></th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Date</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Admin</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Action</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">Model</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="log in logs.data" :key="log.id">
                        <tr
                            class="border-b border-white/5 hover:bg-white/5 transition-colors cursor-pointer"
                            @click="toggleRow(log.id)"
                        >
                            <td class="py-3 px-2 text-center">
                                <svg
                                    class="w-4 h-4 text-dark-500 transition-transform duration-200 mx-auto"
                                    :class="{ 'rotate-90': expandedRows.has(log.id) }"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </td>
                            <td class="py-3 px-4 text-sm text-dark-400">{{ log.created_at }}</td>
                            <td class="py-3 px-4 text-sm text-white">{{ log.admin?.name || '-' }}</td>
                            <td class="py-3 px-4 text-sm capitalize" :class="actionColors[log.action] || 'text-dark-300'">
                                {{ log.action }}
                            </td>
                            <td class="py-3 px-4 text-sm text-dark-300">
                                {{ log.auditable_type?.split('\\').pop() || '-' }}
                                <span v-if="log.auditable_id" class="text-dark-500"> #{{ log.auditable_id }}</span>
                            </td>
                            <td class="py-3 px-4 text-sm font-mono text-dark-500">{{ log.ip_address || '-' }}</td>
                        </tr>

                        <!-- Expanded Diff Row -->
                        <tr v-if="expandedRows.has(log.id)">
                            <td colspan="6" class="px-4 py-4 bg-dark-800/30">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- Old Values -->
                                    <div v-if="log.old_values">
                                        <p class="text-xs font-medium text-red-400 mb-2 uppercase">Old Values</p>
                                        <pre class="bg-dark-900/50 border border-red-500/10 rounded-xl p-3 text-xs text-dark-300 font-mono overflow-x-auto">{{ JSON.stringify(formatDiff(log.old_values), null, 2) }}</pre>
                                    </div>
                                    <!-- New Values -->
                                    <div v-if="log.new_values">
                                        <p class="text-xs font-medium text-green-400 mb-2 uppercase">New Values</p>
                                        <pre class="bg-dark-900/50 border border-green-500/10 rounded-xl p-3 text-xs text-dark-300 font-mono overflow-x-auto">{{ JSON.stringify(formatDiff(log.new_values), null, 2) }}</pre>
                                    </div>
                                    <!-- No diff -->
                                    <div v-if="!log.old_values && !log.new_values" class="col-span-2">
                                        <p class="text-sm text-dark-500 italic">No detailed changes recorded</p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tbody v-if="logs.data.length === 0">
                    <tr>
                        <td colspan="6" class="py-16 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <svg class="w-12 h-12 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                <p class="text-dark-400 text-sm">No audit logs found</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="logs.links && logs.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in logs.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="px-3 py-2 rounded-lg text-sm transition-colors"
                    :class="link.active ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5'"
                    v-html="link.label"
                    preserve-scroll
                />
                <span v-else class="px-3 py-2 text-sm text-dark-600" v-html="link.label" />
            </template>
        </div>
    </AdminLayout>
</template>
