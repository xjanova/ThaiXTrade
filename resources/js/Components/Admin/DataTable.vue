<script setup>
/**
 * TPIX TRADE - Admin DataTable Component
 * Reusable sortable data table with loading states
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';

const props = defineProps({
    columns: {
        type: Array,
        required: true,
        // [{ key: 'name', label: 'Name', sortable: true, align: 'left' }]
    },
    data: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    emptyText: {
        type: String,
        default: 'No data available',
    },
    emptyIcon: {
        type: String,
        default: 'table',
    },
    striped: {
        type: Boolean,
        default: false,
    },
    hoverable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['row-click', 'sort']);

const sortKey = ref('');
const sortOrder = ref('asc');

const sortedData = computed(() => {
    if (!sortKey.value) return props.data;

    return [...props.data].sort((a, b) => {
        const aVal = a[sortKey.value];
        const bVal = b[sortKey.value];

        if (aVal === bVal) return 0;
        if (aVal === null || aVal === undefined) return 1;
        if (bVal === null || bVal === undefined) return -1;

        const result = aVal < bVal ? -1 : 1;
        return sortOrder.value === 'asc' ? result : -result;
    });
});

const handleSort = (column) => {
    if (!column.sortable) return;

    if (sortKey.value === column.key) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = column.key;
        sortOrder.value = 'asc';
    }

    emit('sort', { key: sortKey.value, order: sortOrder.value });
};

const handleRowClick = (row, index) => {
    emit('row-click', row, index);
};
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-white/5">
        <table class="w-full">
            <thead>
                <tr class="bg-dark-800/50">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        class="text-left text-xs font-medium text-dark-400 uppercase tracking-wider py-3 px-4 border-b border-white/5"
                        :class="{
                            'cursor-pointer hover:text-dark-300 select-none': column.sortable,
                            'text-right': column.align === 'right',
                            'text-center': column.align === 'center',
                        }"
                        @click="handleSort(column)"
                    >
                        <div class="flex items-center gap-1.5" :class="{ 'justify-end': column.align === 'right', 'justify-center': column.align === 'center' }">
                            <span>{{ column.label }}</span>
                            <template v-if="column.sortable">
                                <svg v-if="sortKey === column.key" class="w-3.5 h-3.5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path v-if="sortOrder === 'asc'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                                <svg v-else class="w-3.5 h-3.5 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                </svg>
                            </template>
                        </div>
                    </th>
                </tr>
            </thead>

            <tbody v-if="loading">
                <tr v-for="i in 5" :key="i">
                    <td v-for="column in columns" :key="column.key" class="py-3 px-4">
                        <div class="h-4 bg-dark-700 animate-pulse rounded w-3/4"></div>
                    </td>
                </tr>
            </tbody>

            <tbody v-else-if="sortedData.length === 0">
                <tr>
                    <td :colspan="columns.length" class="py-16 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <svg class="w-12 h-12 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                            </svg>
                            <p class="text-dark-400 text-sm">{{ emptyText }}</p>
                        </div>
                    </td>
                </tr>
            </tbody>

            <tbody v-else>
                <tr
                    v-for="(row, index) in sortedData"
                    :key="index"
                    class="border-b border-white/5 transition-colors"
                    :class="{
                        'hover:bg-white/5 cursor-pointer': hoverable,
                        'bg-dark-800/20': striped && index % 2 === 1,
                    }"
                    @click="handleRowClick(row, index)"
                >
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="py-3 px-4 text-sm text-white"
                        :class="{
                            'text-right': column.align === 'right',
                            'text-center': column.align === 'center',
                        }"
                    >
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]" :index="index">
                            {{ row[column.key] }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
