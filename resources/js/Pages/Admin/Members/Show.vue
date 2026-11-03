<script setup>
/**
 * TPIX TRADE — Admin Member Detail
 * รายละเอียดสมาชิก + wallet history + ban/unban + KYC
 * Developed by Xman Studio.
 */
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const dateLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));

defineOptions({ layout: AdminLayout });

const props = defineProps({
    member: { type: Object, required: true },
    connections: { type: Array, default: () => [] },
    referrals_count: { type: Number, default: 0 },
});

function toggleBan() {
    if (props.member.is_banned) {
        useForm({}).patch(`/admin/members/${props.member.id}/unban`);
    } else {
        const reason = prompt(t('admin.membersShow.banReasonPrompt'));
        if (reason !== null) {
            useForm({ reason }).patch(`/admin/members/${props.member.id}/ban`);
        }
    }
}

function updateKyc(status) {
    useForm({ kyc_status: status }).patch(`/admin/members/${props.member.id}/kyc`);
}

function shortAddr(addr) {
    return addr ? addr.slice(0, 10) + '...' + addr.slice(-6) : '-';
}
</script>

<template>
    <Head :title="t('admin.membersShow.headTitle', { addr: member.wallet_address.slice(0, 10) })" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center gap-4">
            <Link href="/admin/members" class="text-dark-400 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            </Link>
            <div class="flex-1">
                <h1 class="text-2xl font-bold text-white">{{ t('admin.membersShow.title') }}</h1>
                <p class="text-dark-400 text-sm font-mono">{{ member.wallet_address }}</p>
            </div>
            <button @click="toggleBan" :class="member.is_banned ? 'bg-trading-green hover:bg-green-600' : 'bg-trading-red hover:bg-red-600'" class="px-4 py-2 text-white rounded-xl text-sm font-medium transition-colors">
                {{ member.is_banned ? t('admin.membersShow.unban') : t('admin.membersShow.ban') }}
            </button>
        </div>

        <!-- Profile Card -->
        <div class="grid md:grid-cols-3 gap-4">
            <div class="glass-card p-5 rounded-xl">
                <p class="text-dark-400 text-xs uppercase mb-2">{{ t('admin.membersShow.walletAddress') }}</p>
                <p class="text-white font-mono text-sm break-all">{{ member.wallet_address }}</p>
                <a :href="`https://explorer.tpix.online/address/${member.wallet_address}`" target="_blank" class="text-primary-400 text-xs mt-2 inline-block hover:underline">{{ t('nav.viewOnExplorer') }} →</a>
            </div>
            <div class="glass-card p-5 rounded-xl">
                <p class="text-dark-400 text-xs uppercase mb-2">{{ t('admin.membersShow.profile') }}</p>
                <p class="text-white text-sm">{{ member.name || t('admin.membersShow.notSpecified') }}</p>
                <p class="text-dark-400 text-xs">{{ member.email || t('admin.membersShow.noEmail') }}</p>
                <p class="text-dark-500 text-xs mt-1">{{ t('admin.membersShow.referralCode', { code: member.referral_code }) }}</p>
            </div>
            <div class="glass-card p-5 rounded-xl">
                <p class="text-dark-400 text-xs uppercase mb-2">{{ t('admin.membersShow.status') }}</p>
                <span :class="member.is_banned ? 'bg-trading-red/20 text-trading-red' : 'bg-trading-green/20 text-trading-green'" class="px-3 py-1 rounded-full text-sm font-medium">
                    {{ member.is_banned ? t('admin.membersShow.banned') : t('admin.membersShow.active') }}
                </span>
                <p v-if="member.ban_reason" class="text-trading-red text-xs mt-2">{{ member.ban_reason }}</p>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-xl font-bold text-white">{{ member.total_trades }}</p>
                <p class="text-xs text-dark-400">{{ t('admin.membersShow.stats.trades') }}</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-xl font-bold text-primary-400">${{ parseFloat(member.total_volume_usd || 0).toLocaleString(dateLocale) }}</p>
                <p class="text-xs text-dark-400">{{ t('admin.membersShow.stats.volume') }}</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-xl font-bold text-yellow-400">{{ referrals_count }}</p>
                <p class="text-xs text-dark-400">{{ t('admin.membersShow.stats.referrals') }}</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <p class="text-xl font-bold text-dark-300">{{ connections.length }}</p>
                <p class="text-xs text-dark-400">{{ t('admin.membersShow.stats.connections') }}</p>
            </div>
            <div class="glass-card p-4 rounded-xl text-center">
                <!-- KYC -->
                <select @change="updateKyc($event.target.value)" :value="member.kyc_status"
                    class="bg-dark-700 border border-dark-600 rounded-lg px-2 py-1 text-white text-sm w-full">
                    <option value="none">{{ t('admin.membersShow.kyc.none') }}</option>
                    <option value="pending">{{ t('admin.membersShow.kyc.pending') }}</option>
                    <option value="approved">{{ t('admin.membersShow.kyc.approved') }}</option>
                    <option value="rejected">{{ t('admin.membersShow.kyc.rejected') }}</option>
                </select>
                <p class="text-xs text-dark-400 mt-1">{{ t('admin.membersShow.kyc.status') }}</p>
            </div>
        </div>

        <!-- Wallet Connections History -->
        <div class="glass-card rounded-xl">
            <div class="p-4 border-b border-white/5">
                <h2 class="text-lg font-bold text-white">{{ t('admin.membersShow.connections.title') }}</h2>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">{{ t('admin.membersShow.connections.wallet') }}</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase hidden sm:table-cell">{{ t('admin.membersShow.connections.type') }}</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase hidden sm:table-cell">{{ t('admin.membersShow.connections.chain') }}</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">{{ t('admin.membersShow.connections.connected') }}</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase hidden md:table-cell">{{ t('admin.membersShow.connections.disconnected') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="conn in connections" :key="conn.id" class="border-b border-white/5">
                        <td class="p-3 text-primary-400 font-mono text-xs">{{ shortAddr(conn.wallet_address) }}</td>
                        <td class="p-3 text-dark-300 text-xs capitalize hidden sm:table-cell">{{ conn.wallet_type }}</td>
                        <td class="p-3 text-dark-300 text-xs hidden sm:table-cell">{{ conn.chain_id }}</td>
                        <td class="p-3 text-dark-400 text-xs">{{ new Date(conn.connected_at).toLocaleString(dateLocale) }}</td>
                        <td class="p-3 text-dark-400 text-xs hidden md:table-cell">{{ conn.disconnected_at ? new Date(conn.disconnected_at).toLocaleString(dateLocale) : t('admin.membersShow.active') }}</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</template>
