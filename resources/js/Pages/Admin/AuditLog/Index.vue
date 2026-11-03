<script setup>
/**
 * TPIX TRADE - Admin Audit Logs
 * Activity log viewer with expandable diff rows
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

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

// ป้ายชื่อ action ตามภาษา — ค่าที่ไม่รู้จักแสดงตามที่เซิร์ฟเวอร์ส่งมา
const actionLabel = (action) => {
    if (!action) return '-';
    const key = `admin.auditLog.actions.${action}`;
    const label = t(key);
    return label === key ? action.charAt(0).toUpperCase() + action.slice(1) : label;
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

// Decode HTML entities from Laravel pagination labels (ป้องกัน XSS)
function decodeLabel(html) {
    const txt = document.createElement("textarea");
    txt.innerHTML = html;
    // ป้าย Previous/Next ของ Laravel เป็นภาษาอังกฤษตายตัว — แปลตามภาษาที่เลือก
    return txt.value
        .replace('Previous', t('common.previous'))
        .replace('Next', t('common.next'));
}
</script>

<template>
    <Head :title="t('admin.auditLog.title')" />

    <AdminLayout :title="t('admin.auditLog.title')">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">{{ t('admin.auditLog.title') }}</h2>
                <p class="text-sm text-dark-400 mt-1">{{ t('admin.auditLog.subtitle') }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.auditLog.filters.admin') }}</label>
                    <select v-model="filterAdmin" :class="selectClass">
                        <option value="">{{ t('admin.auditLog.filters.allAdmins') }}</option>
                        <option v-for="admin in admins" :key="admin.id" :value="admin.id">{{ admin.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.auditLog.filters.action') }}</label>
                    <select v-model="filterAction" :class="selectClass">
                        <option value="">{{ t('admin.auditLog.filters.allActions') }}</option>
                        <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.auditLog.filters.from') }}</label>
                    <input v-model="filterDateFrom" type="date" :class="inputClass" />
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.auditLog.filters.to') }}</label>
                    <input v-model="filterDateTo" type="date" :class="inputClass" />
                </div>
                <div class="flex items-center gap-2">
                    <button @click="applyFilters" class="btn-primary px-4 py-2.5 text-sm">{{ t('common.filter') }}</button>
                    <button @click="resetFilters" class="px-4 py-2.5 text-sm text-dark-400 hover:text-white transition-colors">{{ t('admin.auditLog.filters.reset') }}</button>
                </div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="overflow-x-auto rounded-xl border border-white/5">
            <table class="w-full">
                <thead>
                    <tr class="bg-dark-800/50">
                        <th class="w-8 py-3 px-2"></th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">{{ t('admin.auditLog.table.date') }}</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 hidden sm:table-cell">{{ t('admin.auditLog.table.admin') }}</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4">{{ t('admin.auditLog.table.action') }}</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 hidden md:table-cell">{{ t('admin.auditLog.table.model') }}</th>
                        <th class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 hidden lg:table-cell">{{ t('admin.auditLog.table.ipAddress') }}</th>
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
                            <td class="py-3 px-4 text-sm text-white hidden sm:table-cell">{{ log.admin?.name || '-' }}</td>
                            <td class="py-3 px-4 text-sm capitalize" :class="actionColors[log.action] || 'text-dark-300'">
                                {{ actionLabel(log.action) }}
                            </td>
                            <td class="py-3 px-4 text-sm text-dark-300 hidden md:table-cell">
                                {{ log.auditable_type?.split('\\').pop() || '-' }}
                                <span v-if="log.auditable_id" class="text-dark-500"> #{{ log.auditable_id }}</span>
                            </td>
                            <td class="py-3 px-4 text-sm font-mono text-dark-500 hidden lg:table-cell">{{ log.ip_address || '-' }}</td>
                        </tr>

                        <!-- Expanded Diff Row -->
                        <tr v-if="expandedRows.has(log.id)">
                            <td colspan="6" class="px-4 py-4 bg-dark-800/30">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- Old Values -->
                                    <div v-if="log.old_values">
                                        <p class="text-xs font-medium text-red-400 mb-2 uppercase">{{ t('admin.auditLog.diff.oldValues') }}</p>
                                        <pre class="bg-dark-900/50 border border-red-500/10 rounded-xl p-3 text-xs text-dark-300 font-mono overflow-x-auto">{{ JSON.stringify(formatDiff(log.old_values), null, 2) }}</pre>
                                    </div>
                                    <!-- New Values -->
                                    <div v-if="log.new_values">
                                        <p class="text-xs font-medium text-green-400 mb-2 uppercase">{{ t('admin.auditLog.diff.newValues') }}</p>
                                        <pre class="bg-dark-900/50 border border-green-500/10 rounded-xl p-3 text-xs text-dark-300 font-mono overflow-x-auto">{{ JSON.stringify(formatDiff(log.new_values), null, 2) }}</pre>
                                    </div>
                                    <!-- No diff -->
                                    <div v-if="!log.old_values && !log.new_values" class="col-span-2">
                                        <p class="text-sm text-dark-500 italic">{{ t('admin.auditLog.diff.noChanges') }}</p>
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
                                <p class="text-dark-400 text-sm">{{ t('admin.auditLog.empty') }}</p>
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
                    v-text="decodeLabel(link.label)"
                    preserve-scroll
                />
                <span v-else class="px-3 py-2 text-sm text-dark-600" v-text="decodeLabel(link.label)" />
            </template>
        </div>
    </AdminLayout>
</template>
