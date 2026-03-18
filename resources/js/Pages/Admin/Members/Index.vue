<script setup>
/**
 * TPIX TRADE — Admin Members Management
 * จัดการสมาชิก (Traders): ดูข้อมูล, search, filter, ban/unban, KYC
 * Developed by Xman Studio.
 */
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    members: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const kycFilter = ref(props.filters.kyc || '');

// Search debounce
let searchTimeout;
watch(search, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 400);
});

function applyFilters() {
    router.get('/admin/members', {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        kyc: kycFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

function toggleBan(member) {
    if (member.is_banned) {
        useForm({}).patch(`/admin/members/${member.id}/unban`);
    } else {
        const reason = prompt('เหตุผลที่แบน:');
        if (reason !== null) {
            useForm({ reason }).patch(`/admin/members/${member.id}/ban`);
        }
    }
}

function shortAddr(addr) {
    return addr ? addr.slice(0, 8) + '...' + addr.slice(-6) : '-';
}

function kycBadge(status) {
    const map = {
        none: 'bg-dark-600 text-dark-400',
        pending: 'bg-yellow-500/20 text-yellow-400',
        approved: 'bg-trading-green/20 text-trading-green',
        rejected: 'bg-trading-red/20 text-trading-red',
    };
    return map[status] || map.none;
}

const inputClass = 'bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2 text-white placeholder-dark-500 focus:border-primary-500 text-sm';
</script>

<template>
    <Head title="Members — Admin" />

    <div class="space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-bold text-white">Members</h1>
            <p class="text-dark-400 text-sm mt-1">จัดการสมาชิก — สมัครอัตโนมัติเมื่อ Connect Wallet</p>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-white">{{ stats.total_users || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">สมาชิกทั้งหมด</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-trading-green">{{ stats.active_users || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">Active</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-trading-red">{{ stats.banned_users || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">Banned</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-2xl font-bold text-primary-400">{{ stats.total_wallets || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">Wallets Connected</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3">
            <input v-model="search" :class="inputClass" placeholder="ค้นหา wallet, email, ชื่อ..." class="flex-1 min-w-48" />
            <select v-model="statusFilter" :class="inputClass" @change="applyFilters">
                <option value="">ทุกสถานะ</option>
                <option value="active">Active</option>
                <option value="banned">Banned</option>
            </select>
            <select v-model="kycFilter" :class="inputClass" @change="applyFilters">
                <option value="">ทุก KYC</option>
                <option value="none">ไม่มี</option>
                <option value="pending">รอตรวจ</option>
                <option value="approved">ผ่าน</option>
                <option value="rejected">ไม่ผ่าน</option>
            </select>
        </div>

        <!-- Table -->
        <div class="glass-card rounded-xl overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left p-4 text-xs text-dark-400 uppercase">Wallet</th>
                        <th class="text-left p-4 text-xs text-dark-400 uppercase">Email</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">KYC</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">Wallets</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">Trades</th>
                        <th class="text-center p-4 text-xs text-dark-400 uppercase">Status</th>
                        <th class="text-left p-4 text-xs text-dark-400 uppercase">Last Active</th>
                        <th class="text-right p-4 text-xs text-dark-400 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="member in members.data" :key="member.id" class="border-b border-white/5 hover:bg-white/5 transition-colors">
                        <td class="p-4">
                            <Link :href="`/admin/members/${member.id}`" class="text-primary-400 hover:text-primary-300 font-mono text-sm">
                                {{ shortAddr(member.wallet_address) }}
                            </Link>
                        </td>
                        <td class="p-4 text-dark-300 text-sm">{{ member.email || '-' }}</td>
                        <td class="p-4 text-center">
                            <span :class="kycBadge(member.kyc_status)" class="px-2 py-1 rounded-md text-xs font-medium">{{ member.kyc_status }}</span>
                        </td>
                        <td class="p-4 text-center text-dark-300 text-sm">{{ member.wallet_connections_count }}</td>
                        <td class="p-4 text-center text-dark-300 text-sm">{{ member.total_trades }}</td>
                        <td class="p-4 text-center">
                            <span :class="member.is_banned ? 'bg-trading-red/20 text-trading-red' : 'bg-trading-green/20 text-trading-green'" class="px-2 py-1 rounded-full text-xs font-medium">
                                {{ member.is_banned ? 'Banned' : 'Active' }}
                            </span>
                        </td>
                        <td class="p-4 text-dark-400 text-xs">{{ member.last_active_at ? new Date(member.last_active_at).toLocaleDateString() : '-' }}</td>
                        <td class="p-4 text-right">
                            <Link :href="`/admin/members/${member.id}`" class="text-primary-400 hover:text-primary-300 text-sm mr-3">ดู</Link>
                            <button @click="toggleBan(member)" :class="member.is_banned ? 'text-trading-green' : 'text-trading-red'" class="text-sm hover:opacity-80">
                                {{ member.is_banned ? 'ปลดแบน' : 'แบน' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!members.data?.length">
                        <td colspan="8" class="p-8 text-center text-dark-500">ยังไม่มีสมาชิก</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="members.links?.length > 3" class="flex justify-center gap-1">
            <Link v-for="link in members.links" :key="link.label" :href="link.url || '#'" :class="[
                'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                link.active ? 'bg-primary-500 text-white' : 'bg-dark-700 text-dark-400 hover:text-white'
            ]" v-html="link.label" />
        </div>
    </div>
</template>
