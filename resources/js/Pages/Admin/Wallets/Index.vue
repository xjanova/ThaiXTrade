<script setup>
/**
 * TPIX TRADE — Admin Wallet Dashboard
 * ภาพรวม wallet ทั้งระบบ: สถิติ, chain distribution, top holders
 * Developed by Xman Studio.
 */
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    recentConnections: { type: Array, default: () => [] },
});

function shortAddr(addr) {
    return addr ? addr.slice(0, 10) + '...' + addr.slice(-6) : '-';
}

const walletTypeLabels = {
    metamask: 'MetaMask',
    trustwallet: 'Trust Wallet',
    coinbase: 'Coinbase',
    okx: 'OKX',
    tpix_wallet: 'TPIX Wallet',
};

const chainLabels = {
    56: 'BSC',
    1: 'Ethereum',
    137: 'Polygon',
    4289: 'TPIX Chain',
};
</script>

<template>
    <Head title="จัดการ Wallet — Admin" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-white">จัดการ Wallet</h1>
            <p class="text-dark-400 text-sm mt-1">ภาพรวม wallet ที่เชื่อมต่อทั้งระบบ</p>
        </div>

        <!-- สถิติ -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="glass-card p-5 rounded-xl text-center">
                <p class="text-3xl font-bold text-white">{{ stats.total_wallets || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">Wallet ทั้งหมด</p>
            </div>
            <div class="glass-card p-5 rounded-xl text-center">
                <p class="text-3xl font-bold text-primary-400">{{ stats.total_users || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">สมาชิกทั้งหมด</p>
            </div>
            <div class="glass-card p-5 rounded-xl text-center">
                <p class="text-3xl font-bold text-trading-green">{{ stats.active_users || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">Active</p>
            </div>
            <div class="glass-card p-5 rounded-xl text-center">
                <p class="text-3xl font-bold text-trading-red">{{ stats.banned_users || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">ถูกแบน</p>
            </div>
        </div>

        <!-- Wallet Type Distribution -->
        <div class="grid md:grid-cols-2 gap-6">
            <div class="glass-card rounded-xl p-5">
                <h2 class="text-lg font-bold text-white mb-4">ประเภท Wallet</h2>
                <div class="space-y-3">
                    <div v-for="(count, type) in (stats.wallet_types || {})" :key="type" class="flex items-center justify-between">
                        <span class="text-dark-300 text-sm">{{ walletTypeLabels[type] || type }}</span>
                        <div class="flex items-center gap-3">
                            <div class="w-32 h-2 bg-dark-700 rounded-full overflow-hidden">
                                <div class="h-full bg-primary-500 rounded-full" :style="{ width: Math.min(100, (count / Math.max(stats.total_wallets, 1)) * 100) + '%' }"></div>
                            </div>
                            <span class="text-white text-sm font-medium w-10 text-right">{{ count }}</span>
                        </div>
                    </div>
                    <p v-if="!stats.wallet_types || Object.keys(stats.wallet_types).length === 0" class="text-dark-500 text-sm text-center py-4">ยังไม่มีข้อมูล</p>
                </div>
            </div>

            <div class="glass-card rounded-xl p-5">
                <h2 class="text-lg font-bold text-white mb-4">Chain ที่ใช้</h2>
                <div class="space-y-3">
                    <div v-for="(count, chainId) in (stats.chain_distribution || {})" :key="chainId" class="flex items-center justify-between">
                        <span class="text-dark-300 text-sm">{{ chainLabels[chainId] || `Chain ${chainId}` }}</span>
                        <div class="flex items-center gap-3">
                            <div class="w-32 h-2 bg-dark-700 rounded-full overflow-hidden">
                                <div class="h-full bg-trading-green rounded-full" :style="{ width: Math.min(100, (count / Math.max(stats.total_users, 1)) * 100) + '%' }"></div>
                            </div>
                            <span class="text-white text-sm font-medium w-10 text-right">{{ count }}</span>
                        </div>
                    </div>
                    <p v-if="!stats.chain_distribution || Object.keys(stats.chain_distribution).length === 0" class="text-dark-500 text-sm text-center py-4">ยังไม่มีข้อมูล</p>
                </div>
            </div>
        </div>

        <!-- Master Wallet -->
        <div class="glass-card rounded-xl p-5">
            <h2 class="text-lg font-bold text-white mb-3">Master Wallet (Validator)</h2>
            <p class="text-dark-400 text-xs mb-2">Wallet ที่ถือ 7B TPIX (pre-mined ใน genesis)</p>
            <div class="bg-dark-800 rounded-lg p-3">
                <p class="text-primary-400 font-mono text-sm break-all">ดูได้จาก Explorer →
                    <a href="https://explorer.tpix.online" target="_blank" class="underline hover:text-primary-300">explorer.tpix.online</a>
                </p>
            </div>
        </div>

        <!-- การเชื่อมต่อล่าสุด -->
        <div class="glass-card rounded-xl">
            <div class="p-4 border-b border-white/5">
                <h2 class="text-lg font-bold text-white">การเชื่อมต่อล่าสุด</h2>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">Wallet</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">ประเภท</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">Chain</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">เวลา</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="conn in recentConnections" :key="conn.id" class="border-b border-white/5 hover:bg-white/5">
                        <td class="p-3 text-primary-400 font-mono text-xs">{{ shortAddr(conn.wallet_address) }}</td>
                        <td class="p-3 text-dark-300 text-xs">{{ walletTypeLabels[conn.wallet_type] || conn.wallet_type }}</td>
                        <td class="p-3 text-dark-300 text-xs">{{ chainLabels[conn.chain_id] || conn.chain_id }}</td>
                        <td class="p-3 text-dark-400 text-xs">{{ conn.connected_at ? new Date(conn.connected_at).toLocaleString('th-TH') : '-' }}</td>
                    </tr>
                    <tr v-if="!recentConnections.length">
                        <td colspan="4" class="p-8 text-center text-dark-500">ยังไม่มีการเชื่อมต่อ</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</template>
