<script setup>
/**
 * TPIX TRADE - Admin StatusBadge Component
 * Colored status badge with automatic color mapping
 * Developed by Xman Studio
 */

import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'default',
        validator: (value) => ['default', 'transaction', 'ticket', 'priority', 'chain'].includes(value),
    },
});

const statusColors = {
    // Transaction statuses
    completed: 'bg-green-500/10 text-green-400 border-green-500/20',
    confirmed: 'bg-green-500/10 text-green-400 border-green-500/20',
    success: 'bg-green-500/10 text-green-400 border-green-500/20',
    active: 'bg-green-500/10 text-green-400 border-green-500/20',
    open: 'bg-green-500/10 text-green-400 border-green-500/20',

    pending: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
    processing: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
    waiting: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
    in_progress: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',

    failed: 'bg-red-500/10 text-red-400 border-red-500/20',
    rejected: 'bg-red-500/10 text-red-400 border-red-500/20',
    cancelled: 'bg-red-500/10 text-red-400 border-red-500/20',
    closed: 'bg-red-500/10 text-red-400 border-red-500/20',
    critical: 'bg-red-500/10 text-red-400 border-red-500/20',
    urgent: 'bg-red-500/10 text-red-400 border-red-500/20',

    inactive: 'bg-dark-500/10 text-dark-400 border-dark-500/20',
    disabled: 'bg-dark-500/10 text-dark-400 border-dark-500/20',
    resolved: 'bg-blue-500/10 text-blue-400 border-blue-500/20',

    low: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
    medium: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
    high: 'bg-orange-500/10 text-orange-400 border-orange-500/20',

    testnet: 'bg-purple-500/10 text-purple-400 border-purple-500/20',
    mainnet: 'bg-green-500/10 text-green-400 border-green-500/20',

    default: 'bg-primary-500/10 text-primary-400 border-primary-500/20',
};

const colorClass = computed(() => {
    const key = props.status.toLowerCase().replace(/\s+/g, '_');
    return statusColors[key] || statusColors.default;
});

const displayText = computed(() => {
    return props.status.charAt(0).toUpperCase() + props.status.slice(1).replace(/_/g, ' ');
});
</script>

<template>
    <span
        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
        :class="colorClass"
    >
        <span class="w-1.5 h-1.5 rounded-full mr-1.5 bg-current opacity-60"></span>
        {{ displayText }}
    </span>
</template>
