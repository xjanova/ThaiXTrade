<script setup>
/**
 * TPIX TRADE — Admin Wallet Dashboard
 * ภาพรวม wallet ทั้งระบบ: สถิติ, active wallets, chain distribution
 * Developed by Xman Studio.
 */
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    activeWallets: { type: Array, default: () => [] },
    recentConnections: { type: Array, default: () => [] },
});

function shortAddr(addr) {
    return addr ? addr.slice(0, 10) + '...' + addr.slice(-6) : '-';
}

function timeAgo(dateStr) {
    if (!dateStr) return '-';
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'เมื่อกี้';
    if (mins < 60) return `${mins} นาทีที่แล้ว`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours} ชม.ที่แล้ว`;
    const days = Math.floor(hours / 24);
    return `${days} วันที่แล้ว`;
}

const walletTypeLabels = {
    metamask: 'MetaMask',
    trustwallet: 'Trust Wallet',
    coinbase: 'Coinbase',
    okx: 'OKX',
    tpix_wallet: 'TPIX Wallet',
};

const walletTypeIcons = {
    metamask: '🦊',
    trustwallet: '🛡️',
    coinbase: '🔵',
    okx: '⚫',
    tpix_wallet: '💎',
};

const chainLabels = {
    1: 'Ethereum',
    56: 'BSC',
    137: 'Polygon',
    42161: 'Arbitrum',
    10: 'Optimism',
    43114: 'Avalanche',
    250: 'Fantom',
    8453: 'Base',
    324: 'zkSync',
    4289: 'TPIX Chain',
};

const chainColors = {
    1: 'bg-blue-500',
    56: 'bg-yellow-500',
    137: 'bg-purple-500',
    42161: 'bg-blue-400',
    10: 'bg-red-500',
    43114: 'bg-red-400',
    250: 'bg-blue-600',
    8453: 'bg-blue-300',
    324: 'bg-indigo-400',
    4289: 'bg-primary-500',
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
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="glass-card p-5 rounded-xl text-center">
                <p class="text-3xl font-bold text-white">{{ stats.total_wallets || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">Wallet ทั้งหมด</p>
            </div>
            <div class="glass-card p-5 rounded-xl text-center">
                <p class="text-3xl font-bold text-trading-green">{{ stats.connected_wallets || 0 }}</p>
                <p class="text-xs text-dark-400 mt-1">เชื่อมต่ออยู่</p>
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

        <!-- Wallet Type + Chain Distribution -->
        <div class="grid md:grid-cols-2 gap-6">
            <div class="glass-card rounded-xl p-5">
                <h2 class="text-lg font-bold text-white mb-4">ประเภท Wallet</h2>
                <div class="space-y-3">
                    <div v-for="(count, type) in (stats.wallet_types || {})" :key="type" class="flex items-center justify-between">
                        <span class="text-dark-300 text-sm">
                            <span class="mr-1.5">{{ walletTypeIcons[type] || '📱' }}</span>
                            {{ walletTypeLabels[type] || type }}
                        </span>
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
                        <span class="text-dark-300 text-sm flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" :class="chainColors[chainId] || 'bg-gray-500'"></span>
                            {{ chainLabels[chainId] || `Chain ${chainId}` }}
                        </span>
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

        <!-- Wallet ที่เชื่อมต่ออยู่ -->
        <div class="glass-card rounded-xl">
            <div class="p-4 border-b border-white/5 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-white">Wallet ที่เชื่อมต่ออยู่</h2>
                    <p class="text-dark-400 text-xs mt-0.5">แสดง wallet ที่ยังเชื่อมต่อ พร้อม chain ที่ใช้</p>
                </div>
                <span class="bg-trading-green/20 text-trading-green text-xs font-medium px-2.5 py-1 rounded-full">
                    {{ activeWallets.length }} wallet{{ activeWallets.length !== 1 ? 's' : '' }}
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-3 text-xs text-dark-400 uppercase">Wallet Address</th>
                            <th class="text-left p-3 text-xs text-dark-400 uppercase">ผู้ใช้</th>
                            <th class="text-left p-3 text-xs text-dark-400 uppercase">ประเภท</th>
                            <th class="text-left p-3 text-xs text-dark-400 uppercase">Chain ที่เชื่อม</th>
                            <th class="text-left p-3 text-xs text-dark-400 uppercase">สมัครเมื่อ</th>
                            <th class="text-left p-3 text-xs text-dark-400 uppercase">ใช้งานล่าสุด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="wallet in activeWallets" :key="wallet.wallet_address" class="border-b border-white/5 hover:bg-white/5">
                            <td class="p-3">
                                <a :href="'https://explorer.tpix.online/address/' + wallet.wallet_address"
                                   target="_blank"
                                   class="text-primary-400 font-mono text-xs hover:text-primary-300 hover:underline">
                                    {{ shortAddr(wallet.wallet_address) }}
                                </a>
                            </td>
                            <td class="p-3 text-xs">
                                <span v-if="wallet.user_name" class="text-white">{{ wallet.user_name }}</span>
                                <span v-else-if="wallet.user_email" class="text-dark-300">{{ wallet.user_email }}</span>
                                <span v-else class="text-dark-500 italic">ไม่ได้ตั้งชื่อ</span>
                            </td>
                            <td class="p-3 text-dark-300 text-xs">
                                <span class="mr-1">{{ walletTypeIcons[wallet.wallet_type] || '📱' }}</span>
                                {{ walletTypeLabels[wallet.wallet_type] || wallet.wallet_type }}
                            </td>
                            <td class="p-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="chainId in wallet.chains" :key="chainId"
                                          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-white/10 text-dark-200">
                                        <span class="w-1.5 h-1.5 rounded-full" :class="chainColors[chainId] || 'bg-gray-500'"></span>
                                        {{ chainLabels[chainId] || chainId }}
                                    </span>
                                </div>
                            </td>
                            <td class="p-3 text-dark-400 text-xs">
                                {{ wallet.user_created_at ? new Date(wallet.user_created_at).toLocaleDateString('th-TH') : '-' }}
                            </td>
                            <td class="p-3 text-dark-400 text-xs">
                                {{ timeAgo(wallet.last_active_at) }}
                            </td>
                        </tr>
                        <tr v-if="!activeWallets.length">
                            <td colspan="6" class="p-8 text-center text-dark-500">ยังไม่มี wallet ที่เชื่อมต่ออยู่</td>
                        </tr>
                    </tbody>
                </table>
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

        <!-- ประวัติการเชื่อมต่อ -->
        <div class="glass-card rounded-xl">
            <div class="p-4 border-b border-white/5">
                <h2 class="text-lg font-bold text-white">ประวัติการเชื่อมต่อ</h2>
                <p class="text-dark-400 text-xs mt-0.5">30 รายการล่าสุด — ทั้งสร้างใหม่และเชื่อมต่อแต่ละ chain</p>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">Wallet</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">ผู้ใช้</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">ประเภท</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">Chain</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">สถานะ</th>
                        <th class="text-left p-3 text-xs text-dark-400 uppercase">เวลา</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="conn in recentConnections" :key="conn.id" class="border-b border-white/5 hover:bg-white/5">
                        <td class="p-3 text-primary-400 font-mono text-xs">{{ shortAddr(conn.wallet_address) }}</td>
                        <td class="p-3 text-xs">
                            <span v-if="conn.user?.name" class="text-white">{{ conn.user.name }}</span>
                            <span v-else class="text-dark-500">-</span>
                        </td>
                        <td class="p-3 text-dark-300 text-xs">
                            <span class="mr-1">{{ walletTypeIcons[conn.wallet_type] || '📱' }}</span>
                            {{ walletTypeLabels[conn.wallet_type] || conn.wallet_type }}
                        </td>
                        <td class="p-3 text-xs">
                            <span class="inline-flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full" :class="chainColors[conn.chain_id] || 'bg-gray-500'"></span>
                                <span class="text-dark-300">{{ chainLabels[conn.chain_id] || conn.chain_id }}</span>
                            </span>
                        </td>
                        <td class="p-3 text-xs">
                            <span v-if="!conn.disconnected_at" class="inline-flex items-center gap-1 text-trading-green">
                                <span class="w-1.5 h-1.5 rounded-full bg-trading-green animate-pulse"></span>
                                เชื่อมต่อ
                            </span>
                            <span v-else class="text-dark-500">ยกเลิก</span>
                        </td>
                        <td class="p-3 text-dark-400 text-xs">{{ conn.connected_at ? new Date(conn.connected_at).toLocaleString('th-TH') : '-' }}</td>
                    </tr>
                    <tr v-if="!recentConnections.length">
                        <td colspan="6" class="p-8 text-center text-dark-500">ยังไม่มีการเชื่อมต่อ</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</template>
