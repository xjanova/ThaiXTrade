<script setup>
/**
 * TPIX TRADE - Admin Support Tickets Index
 * Ticket management with filtering and priority badges
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/Admin/DataTable.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    tickets: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    categories: {
        type: Array,
        default: () => ['general', 'trading', 'wallet', 'deposit', 'withdrawal', 'bug', 'feature'],
    },
    priorities: {
        type: Array,
        default: () => ['low', 'medium', 'high', 'urgent'],
    },
    statusOptions: {
        type: Array,
        default: () => ['open', 'in_progress', 'waiting', 'resolved', 'closed'],
    },
});

const columns = [
    { key: 'id', label: '#', sortable: true },
    { key: 'subject', label: 'Subject', sortable: true },
    { key: 'category', label: 'Category' },
    { key: 'priority', label: 'Priority' },
    { key: 'status', label: 'Status' },
    { key: 'assigned_to', label: 'Assigned' },
    { key: 'created_at', label: 'Date', sortable: true },
];

const filterStatus = ref(props.filters.status || '');
const filterPriority = ref(props.filters.priority || '');
const filterCategory = ref(props.filters.category || '');

const applyFilters = () => {
    router.get('/admin/support', {
        status: filterStatus.value || undefined,
        priority: filterPriority.value || undefined,
        category: filterCategory.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filterStatus.value = '';
    filterPriority.value = '';
    filterCategory.value = '';
    router.get('/admin/support', {}, { preserveState: true });
};

const viewTicket = (ticket) => {
    router.get(`/admin/support/${ticket.id}`);
};

const selectClass = 'bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500';
</script>

<template>
    <Head title="Support Tickets" />

    <AdminLayout title="Support Tickets">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Support Tickets</h2>
                <p class="text-sm text-dark-400 mt-1">Manage customer support requests</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Status</label>
                    <select v-model="filterStatus" :class="selectClass">
                        <option value="">All Statuses</option>
                        <option v-for="s in statusOptions" :key="s" :value="s">{{ s.charAt(0).toUpperCase() + s.slice(1).replace('_', ' ') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Priority</label>
                    <select v-model="filterPriority" :class="selectClass">
                        <option value="">All Priorities</option>
                        <option v-for="p in priorities" :key="p" :value="p">{{ p.charAt(0).toUpperCase() + p.slice(1) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-dark-400 mb-1.5">Category</label>
                    <select v-model="filterCategory" :class="selectClass">
                        <option value="">All Categories</option>
                        <option v-for="c in categories" :key="c" :value="c">{{ c.charAt(0).toUpperCase() + c.slice(1) }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="applyFilters" class="btn-primary px-4 py-2.5 text-sm">Filter</button>
                    <button @click="resetFilters" class="px-4 py-2.5 text-sm text-dark-400 hover:text-white transition-colors">Reset</button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <DataTable :columns="columns" :data="tickets.data" @row-click="viewTicket">
            <template #cell-id="{ value }">
                <span class="font-mono text-dark-300">#{{ value }}</span>
            </template>
            <template #cell-subject="{ value }">
                <span class="font-medium text-white">{{ value }}</span>
            </template>
            <template #cell-category="{ value }">
                <span class="capitalize text-dark-300">{{ value }}</span>
            </template>
            <template #cell-priority="{ value }">
                <StatusBadge :status="value" type="priority" />
            </template>
            <template #cell-status="{ value }">
                <StatusBadge :status="value" type="ticket" />
            </template>
            <template #cell-assigned_to="{ row }">
                <span v-if="row.assigned_admin" class="text-dark-300">{{ row.assigned_admin.name }}</span>
                <span v-else class="text-dark-500 italic">Unassigned</span>
            </template>
            <template #cell-created_at="{ value }">
                <span class="text-dark-400 text-xs">{{ value }}</span>
            </template>
        </DataTable>

        <!-- Pagination -->
        <div v-if="tickets.links && tickets.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in tickets.links" :key="link.label">
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
