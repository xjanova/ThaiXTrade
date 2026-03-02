<script setup>
/**
 * TPIX TRADE - Admin Notifications Index
 * Notification management with read/unread states
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    notifications: {
        type: Object,
        default: () => ({ data: [], links: [] }),
    },
    unreadCount: {
        type: Number,
        default: 0,
    },
});

const markingAll = ref(false);

const typeIcons = {
    ticket_new: { color: 'text-blue-400 bg-blue-500/10', label: 'New Ticket' },
    ticket_reply: { color: 'text-green-400 bg-green-500/10', label: 'Ticket Reply' },
    ticket_assigned: { color: 'text-yellow-400 bg-yellow-500/10', label: 'Assigned' },
    system: { color: 'text-purple-400 bg-purple-500/10', label: 'System' },
    user_report: { color: 'text-red-400 bg-red-500/10', label: 'Report' },
};

const getTypeInfo = (type) => typeIcons[type] || typeIcons.system;

const formatTime = (dateStr) => {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = now - date;
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return date.toLocaleDateString();
};

const markAsRead = (notification) => {
    if (notification.read_at) return;
    router.patch(`/admin/notifications/${notification.id}/read`, {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

const markAllAsRead = () => {
    markingAll.value = true;
    router.post('/admin/notifications/mark-all-read', {}, {
        preserveScroll: true,
        onFinish: () => {
            markingAll.value = false;
        },
    });
};

const handleNotificationClick = (notification) => {
    markAsRead(notification);
    if (notification.data?.url) {
        router.get(notification.data.url);
    }
};
</script>

<template>
    <Head title="Notifications" />

    <AdminLayout title="Notifications">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold text-white">Notifications</h2>
                <p class="text-sm text-dark-400 mt-1">
                    {{ unreadCount > 0 ? `${unreadCount} unread notification${unreadCount > 1 ? 's' : ''}` : 'All caught up!' }}
                </p>
            </div>
            <button
                v-if="unreadCount > 0"
                @click="markAllAsRead"
                :disabled="markingAll"
                class="px-4 py-2 rounded-xl text-sm font-medium text-primary-400 bg-primary-500/10 border border-primary-500/20 hover:bg-primary-500/20 transition-colors disabled:opacity-50"
            >
                <span v-if="markingAll">Marking...</span>
                <span v-else>Mark all as read</span>
            </button>
        </div>

        <!-- Notification List -->
        <div v-if="notifications.data.length > 0" class="space-y-2">
            <div
                v-for="notification in notifications.data"
                :key="notification.id"
                @click="handleNotificationClick(notification)"
                class="flex items-start gap-4 p-4 rounded-xl border transition-all cursor-pointer"
                :class="notification.read_at
                    ? 'bg-white/[0.02] border-white/5 hover:bg-white/5'
                    : 'bg-primary-500/5 border-primary-500/20 hover:bg-primary-500/10'"
            >
                <!-- Type Icon -->
                <div
                    class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center"
                    :class="getTypeInfo(notification.type).color"
                >
                    <!-- Ticket New -->
                    <svg v-if="notification.type === 'ticket_new'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                    </svg>
                    <!-- Ticket Reply -->
                    <svg v-else-if="notification.type === 'ticket_reply'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                    </svg>
                    <!-- Ticket Assigned -->
                    <svg v-else-if="notification.type === 'ticket_assigned'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <!-- System -->
                    <svg v-else-if="notification.type === 'system'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <!-- User Report -->
                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h4 class="text-sm font-medium" :class="notification.read_at ? 'text-dark-300' : 'text-white'">
                            {{ notification.title }}
                        </h4>
                        <span
                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium"
                            :class="getTypeInfo(notification.type).color"
                        >
                            {{ getTypeInfo(notification.type).label }}
                        </span>
                    </div>
                    <p class="text-sm mt-0.5" :class="notification.read_at ? 'text-dark-500' : 'text-dark-400'">
                        {{ notification.message }}
                    </p>
                    <p class="text-xs text-dark-500 mt-1">{{ formatTime(notification.created_at) }}</p>
                </div>

                <!-- Unread Indicator -->
                <div v-if="!notification.read_at" class="flex-shrink-0 w-2.5 h-2.5 rounded-full bg-primary-500 mt-1.5"></div>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else class="flex flex-col items-center justify-center py-20">
            <div class="w-16 h-16 rounded-2xl bg-dark-800 flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-dark-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
            </div>
            <p class="text-dark-400 text-sm">No notifications yet</p>
        </div>

        <!-- Pagination -->
        <div v-if="notifications.links && notifications.links.length > 3" class="flex items-center justify-center gap-1 mt-6">
            <template v-for="link in notifications.links" :key="link.label">
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
