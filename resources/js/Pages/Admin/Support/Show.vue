<script setup>
/**
 * TPIX TRADE - Admin Support Ticket Detail
 * Chat-style ticket view with reply form and sidebar controls
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusBadge from '@/Components/Admin/StatusBadge.vue';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
    messages: {
        type: Array,
        default: () => [],
    },
    admins: {
        type: Array,
        default: () => [],
    },
    statusOptions: {
        type: Array,
        default: () => ['open', 'in_progress', 'waiting', 'resolved', 'closed'],
    },
    priorities: {
        type: Array,
        default: () => ['low', 'medium', 'high', 'urgent'],
    },
});

const replyForm = useForm({
    message: '',
    is_internal: false,
});

const sendReply = () => {
    replyForm.post(`/admin/support/${props.ticket.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => {
            replyForm.reset();
        },
    });
};

const updateStatus = (status) => {
    router.put(`/admin/support/${props.ticket.id}/status`, { status }, { preserveScroll: true });
};

const updatePriority = (priority) => {
    router.put(`/admin/support/${props.ticket.id}/priority`, { priority }, { preserveScroll: true });
};

const assignAdmin = (adminId) => {
    router.put(`/admin/support/${props.ticket.id}/assign`, { admin_id: adminId }, { preserveScroll: true });
};

const selectClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-primary-500';
</script>

<template>
    <Head :title="`Ticket #${ticket.id}`" />

    <AdminLayout :title="`Ticket #${ticket.id}`">
        <!-- Back Link -->
        <div class="mb-6">
            <Link href="/admin/support" class="inline-flex items-center gap-2 text-sm text-dark-400 hover:text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Tickets
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Ticket Header -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-white mb-2">{{ ticket.subject }}</h2>
                            <div class="flex items-center gap-3 flex-wrap">
                                <StatusBadge :status="ticket.status" type="ticket" />
                                <StatusBadge :status="ticket.priority" type="priority" />
                                <span class="text-xs text-dark-400 capitalize">{{ ticket.category }}</span>
                                <span class="text-xs text-dark-500">Created {{ ticket.created_at }}</span>
                            </div>
                        </div>
                        <span class="font-mono text-dark-400 text-sm">#{{ ticket.id }}</span>
                    </div>
                </div>

                <!-- Messages Thread -->
                <div class="space-y-4">
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="rounded-2xl p-5"
                        :class="{
                            'bg-white/5 border border-white/10 ml-0 mr-12': msg.type === 'user',
                            'bg-primary-500/5 border border-primary-500/10 ml-12 mr-0': msg.type === 'admin',
                            'bg-yellow-500/5 border border-yellow-500/10 ml-6 mr-6': msg.is_internal,
                        }"
                    >
                        <!-- Internal Note Label -->
                        <div v-if="msg.is_internal" class="flex items-center gap-2 mb-2">
                            <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <span class="text-xs font-medium text-yellow-400">Internal Note</span>
                        </div>

                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold"
                                :class="msg.type === 'admin' ? 'bg-primary-500/20 text-primary-400' : 'bg-dark-700 text-dark-300'"
                            >
                                {{ (msg.author_name || 'U').charAt(0).toUpperCase() }}
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">{{ msg.author_name }}</p>
                                <p class="text-xs text-dark-500">{{ msg.created_at }}</p>
                            </div>
                            <span v-if="msg.type === 'admin'" class="text-xs text-primary-400 bg-primary-500/10 px-2 py-0.5 rounded-full">Staff</span>
                        </div>

                        <div class="text-sm text-dark-300 leading-relaxed whitespace-pre-wrap">{{ msg.message }}</div>
                    </div>

                    <!-- Empty Messages -->
                    <div v-if="messages.length === 0" class="text-center py-12">
                        <p class="text-dark-400 text-sm">No messages yet</p>
                    </div>
                </div>

                <!-- Reply Form -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h3 class="text-sm font-semibold text-white mb-4">Reply</h3>
                    <form @submit.prevent="sendReply" class="space-y-4">
                        <textarea
                            v-model="replyForm.message"
                            rows="4"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200 resize-none"
                            placeholder="Type your reply..."
                        ></textarea>
                        <p v-if="replyForm.errors.message" class="text-sm text-red-400">{{ replyForm.errors.message }}</p>

                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    v-model="replyForm.is_internal"
                                    type="checkbox"
                                    class="w-4 h-4 rounded border-dark-600 bg-dark-800 text-yellow-500 focus:ring-yellow-500 focus:ring-offset-dark-900"
                                />
                                <span class="text-sm text-dark-400">Internal note (not visible to user)</span>
                            </label>

                            <button
                                type="submit"
                                :disabled="replyForm.processing || !replyForm.message.trim()"
                                class="btn-primary px-6 py-2.5 text-sm"
                            >
                                <svg v-if="replyForm.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ replyForm.is_internal ? 'Add Note' : 'Send Reply' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Ticket Info -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h3 class="text-sm font-semibold text-white mb-4">Ticket Information</h3>
                    <div class="space-y-4">
                        <div>
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">User</p>
                            <p class="text-sm text-white">{{ ticket.user?.name || ticket.user_email || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Email</p>
                            <p class="text-sm text-dark-300">{{ ticket.user?.email || ticket.user_email || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Category</p>
                            <p class="text-sm text-white capitalize">{{ ticket.category }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Created</p>
                            <p class="text-sm text-dark-300">{{ ticket.created_at }}</p>
                        </div>
                        <div v-if="ticket.updated_at">
                            <p class="text-xs text-dark-500 uppercase tracking-wider mb-1">Last Updated</p>
                            <p class="text-sm text-dark-300">{{ ticket.updated_at }}</p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                    <h3 class="text-sm font-semibold text-white mb-4">Actions</h3>
                    <div class="space-y-4">
                        <!-- Assign Admin -->
                        <div>
                            <label class="block text-xs text-dark-400 mb-1.5">Assign To</label>
                            <select
                                :value="ticket.assigned_admin_id || ''"
                                @change="assignAdmin($event.target.value)"
                                :class="selectClass"
                            >
                                <option value="">Unassigned</option>
                                <option v-for="admin in admins" :key="admin.id" :value="admin.id">{{ admin.name }}</option>
                            </select>
                        </div>

                        <!-- Change Status -->
                        <div>
                            <label class="block text-xs text-dark-400 mb-1.5">Status</label>
                            <select
                                :value="ticket.status"
                                @change="updateStatus($event.target.value)"
                                :class="selectClass"
                            >
                                <option v-for="s in statusOptions" :key="s" :value="s">{{ s.charAt(0).toUpperCase() + s.slice(1).replace('_', ' ') }}</option>
                            </select>
                        </div>

                        <!-- Change Priority -->
                        <div>
                            <label class="block text-xs text-dark-400 mb-1.5">Priority</label>
                            <select
                                :value="ticket.priority"
                                @change="updatePriority($event.target.value)"
                                :class="selectClass"
                            >
                                <option v-for="p in priorities" :key="p" :value="p">{{ p.charAt(0).toUpperCase() + p.slice(1) }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
