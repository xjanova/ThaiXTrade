<script setup>
/**
 * TPIX TRADE - Token Sale Page (ICO/IDO)
 * หน้าขายเหรียญ TPIX — Hero + Countdown + Phases + Buy Form + Tokenomics
 * รับ props จาก TokenSaleController (Inertia)
 * Developed by Xman Studio
 */

import { ref, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenSaleStore } from '@/Stores/tokenSaleStore';
import CountdownTimer from '@/Components/TokenSale/CountdownTimer.vue';
import SaleProgressBar from '@/Components/TokenSale/SaleProgressBar.vue';
import PhaseCard from '@/Components/TokenSale/PhaseCard.vue';
import BuyForm from '@/Components/TokenSale/BuyForm.vue';
import TokenomicsChart from '@/Components/TokenSale/TokenomicsChart.vue';
import VestingSchedule from '@/Components/TokenSale/VestingSchedule.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

// Props จาก backend (Inertia)
const props = defineProps({
    sale: { type: Object, default: null },
    stats: { type: Object, default: null },
});

const walletStore = useWalletStore();
const tokenSaleStore = useTokenSaleStore();

// Phase ที่ผู้ใช้เลือก
const selectedPhaseId = ref(null);

// ขั้นตอนการซื้อ (How to Buy)
const howToBuySteps = [
    {
        step: 1,
        title: 'Connect Wallet',
        description: 'Connect your MetaMask, Trust Wallet, or other BSC-compatible wallet.',
        icon: 'wallet',
    },
    {
        step: 2,
        title: 'Choose Amount',
        description: 'Select your payment currency (BNB or USDT) and enter the amount.',
        icon: 'calculator',
    },
    {
        step: 3,
        title: 'Confirm Transaction',
        description: 'Review the details and confirm the transaction in your wallet.',
        icon: 'check',
    },
    {
        step: 4,
        title: 'Receive TPIX',
        description: 'Your TPIX tokens will be allocated with a vesting schedule.',
        icon: 'gift',
    },
];

// Tokenomics data (7B total)
const tokenomicsData = [
    { label: 'Public Sale (ICO)', percent: 10, color: '#06B6D4' },
    { label: 'Liquidity Pool', percent: 30, color: '#8B5CF6' },
    { label: 'Master Node Rewards', percent: 20, color: '#00C853' },
    { label: 'Team & Advisors', percent: 20, color: '#F97316' },
    { label: 'Ecosystem Fund', percent: 10, color: '#3B82F6' },
    { label: 'Development', percent: 10, color: '#EC4899' },
];

onMounted(async () => {
    // โหลดข้อมูล real-time จาก API
    await tokenSaleStore.loadAll(walletStore.address);

    // ถ้ามี active phase ให้เลือกอัตโนมัติ
    if (tokenSaleStore.activePhase) {
        selectedPhaseId.value = tokenSaleStore.activePhase.id;
    }
});

// เมื่อ wallet connect/disconnect ให้โหลดข้อมูลใหม่
watch(() => walletStore.address, async (newAddr) => {
    if (newAddr) {
        await tokenSaleStore.fetchPurchases(newAddr);
        await tokenSaleStore.fetchVesting(newAddr);
    }
});

function handlePhaseSelect(phaseId) {
    selectedPhaseId.value = phaseId;
    // Scroll ไปที่ BuyForm
    document.getElementById('buy-section')?.scrollIntoView({ behavior: 'smooth' });
}

function handlePurchaseComplete() {
    // Refresh data หลังซื้อสำเร็จ
    if (walletStore.address) {
        tokenSaleStore.fetchPurchases(walletStore.address);
        tokenSaleStore.fetchVesting(walletStore.address);
    }
}
</script>

<template>
    <Head title="TPIX Token Sale — Buy TPIX" />

    <AppLayout :hide-sidebar="true">
        <!-- ===== HERO SECTION ===== -->
        <section class="relative py-16 sm:py-20 overflow-hidden">
            <!-- Background glow -->
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/4 left-1/4 w-96 h-96 rounded-full bg-primary-500/10 blur-[120px]" />
                <div class="absolute bottom-1/4 right-1/4 w-96 h-96 rounded-full bg-accent-500/10 blur-[120px]" />
            </div>

            <div class="relative max-w-6xl mx-auto px-4 sm:px-6 text-center">
                <!-- Logo -->
                <img src="/logo.webp" alt="TPIX" class="w-20 h-20 mx-auto mb-6" />

                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-3">
                    {{ t('tokenSale.title') }}
                </h1>
                <p class="text-lg text-gray-400 mb-8 max-w-2xl mx-auto">
                    Be part of the TPIX Chain ecosystem. Purchase TPIX tokens at the best price
                    during our token sale event.
                </p>

                <!-- Countdown Timer -->
                <div v-if="tokenSaleStore.sale?.ends_at" class="mb-8">
                    <p class="text-sm text-gray-400 mb-3 uppercase tracking-wider">Sale Ends In</p>
                    <CountdownTimer :target-date="tokenSaleStore.sale.ends_at" />
                </div>

                <!-- Progress Bar -->
                <div class="max-w-2xl mx-auto">
                    <SaleProgressBar
                        :sold="tokenSaleStore.sale?.total_sold || 0"
                        :total="tokenSaleStore.sale?.total_supply || 700000000"
                        :raised-usd="tokenSaleStore.stats?.total_raised_usd || 0"
                        :buyers="tokenSaleStore.stats?.total_buyers || 0"
                    />
                </div>
            </div>
        </section>

        <!-- ===== SALE PHASES ===== -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <h2 class="text-2xl font-bold text-white mb-2">{{ t('tokenSale.phase') }}</h2>
            <p class="text-gray-400 mb-8">Select a phase to purchase TPIX tokens.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <PhaseCard
                    v-for="phase in tokenSaleStore.phases"
                    :key="phase.id"
                    :phase="phase"
                    :selected="selectedPhaseId === phase.id"
                    @select="handlePhaseSelect"
                />
            </div>

            <!-- ถ้ายังไม่มี phases แสดง placeholder -->
            <div v-if="tokenSaleStore.phases.length === 0 && !tokenSaleStore.isLoadingSale" class="text-center py-12">
                <p class="text-gray-400">Token sale phases will be announced soon.</p>
            </div>
        </section>

        <!-- ===== BUY FORM + INFO ===== -->
        <section id="buy-section" class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- ฟอร์มซื้อ -->
                <BuyForm @purchase-complete="handlePurchaseComplete" />

                <!-- How to Buy -->
                <div class="glass-dark p-6 rounded-xl border border-white/10">
                    <h3 class="text-xl font-bold text-white mb-6">{{ t('tokenSale.howToBuy') }}</h3>

                    <div class="space-y-6">
                        <div
                            v-for="item in howToBuySteps"
                            :key="item.step"
                            class="flex gap-4"
                        >
                            <!-- Step number -->
                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-primary-500/20 border border-primary-500/30 flex items-center justify-center">
                                <span class="text-sm font-bold text-primary-400">{{ item.step }}</span>
                            </div>
                            <!-- Content -->
                            <div>
                                <h4 class="text-white font-semibold mb-1">{{ item.title }}</h4>
                                <p class="text-sm text-gray-400">{{ item.description }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Network info -->
                    <div class="mt-8 p-4 rounded-lg bg-white/5 border border-white/10">
                        <p class="text-sm text-gray-400">
                            <span class="text-white font-semibold">Network:</span> BNB Smart Chain (BSC)
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            <span class="text-white font-semibold">Accepted:</span> BNB, USDT, BUSD
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            <span class="text-white font-semibold">Token:</span> TPIX (Native on TPIX Chain)
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== TOKENOMICS ===== -->
        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <div class="glass-dark p-8 rounded-xl border border-white/10">
                <TokenomicsChart :data="tokenomicsData" />

                <!-- Token details -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8 pt-6 border-t border-white/10">
                    <div class="text-center">
                        <span class="text-sm text-gray-400">Total Supply</span>
                        <p class="text-lg font-bold text-white">7,000,000,000</p>
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-400">Decimals</span>
                        <p class="text-lg font-bold text-white">18</p>
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-400">Chain</span>
                        <p class="text-lg font-bold text-white">TPIX Chain</p>
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-400">Consensus</span>
                        <p class="text-lg font-bold text-white">IBFT PoA</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== MY PURCHASES / VESTING (ถ้า wallet เชื่อมต่ออยู่) ===== -->
        <section v-if="walletStore.isConnected" class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <!-- Purchase Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-xs text-gray-400 mb-1">My Total TPIX</p>
                    <p class="text-xl font-bold text-primary-400">{{ Number(tokenSaleStore.totalPurchased || 0).toLocaleString() }}</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-xs text-gray-400 mb-1">Claimable Now</p>
                    <p class="text-xl font-bold text-trading-green">{{ Number(tokenSaleStore.totalClaimable || 0).toLocaleString() }}</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-xs text-gray-400 mb-1">Total Purchases</p>
                    <p class="text-xl font-bold text-white">{{ tokenSaleStore.purchases?.length || 0 }}</p>
                </div>
            </div>

            <!-- Purchase History -->
            <div v-if="tokenSaleStore.purchases?.length > 0" class="glass-dark p-6 rounded-xl border border-white/10 mb-6">
                <h3 class="text-lg font-bold text-white mb-4">Purchase History</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="text-left pb-3 text-gray-400 font-medium">Date</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">Amount</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">Paid</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">Status</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">Tx</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in tokenSaleStore.purchases" :key="p.id" class="border-b border-white/5">
                                <td class="py-3 text-gray-300">{{ new Date(p.created_at).toLocaleDateString() }}</td>
                                <td class="py-3 text-white font-medium">{{ Number(p.tpix_amount).toLocaleString() }} TPIX</td>
                                <td class="py-3 text-gray-300">{{ p.payment_amount }} {{ p.payment_currency }}</td>
                                <td class="py-3">
                                    <span :class="[
                                        'text-xs px-2 py-0.5 rounded-full',
                                        p.status === 'confirmed' ? 'bg-green-500/10 text-green-400' :
                                        p.status === 'pending' ? 'bg-yellow-500/10 text-yellow-400' :
                                        'bg-gray-500/10 text-gray-400'
                                    ]">{{ p.status }}</span>
                                </td>
                                <td class="py-3">
                                    <a v-if="p.tx_hash" :href="`https://bscscan.com/tx/${p.tx_hash}`" target="_blank" rel="noopener" class="text-primary-400 text-xs font-mono hover:underline">
                                        {{ p.tx_hash?.slice(0, 8) }}...
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Vesting Schedule -->
            <div class="glass-dark p-6 rounded-xl border border-white/10">
                <VestingSchedule
                    :entries="tokenSaleStore.vestingSchedule"
                    :loading="tokenSaleStore.isLoadingVesting"
                />
            </div>
        </section>

        <!-- ===== FAQ ===== -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6 py-12">
            <h2 class="text-2xl font-bold text-white mb-8 text-center">Frequently Asked Questions</h2>

            <div class="space-y-4">
                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        What is TPIX?
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        TPIX is the native coin of TPIX Chain — a Polygon Edge-based blockchain with IBFT consensus,
                        zero gas fees, and 2-second block times. TPIX powers the entire ecosystem including DEX, master node,
                        token factory, and cross-chain bridge.
                    </p>
                </details>

                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        How does the vesting work?
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        Each phase has its own vesting schedule. A percentage is unlocked at TGE (Token Generation Event),
                        followed by a cliff period, then linear daily vesting over the remaining duration.
                        Private Sale: 10% TGE, 30-day cliff, 180-day vesting.
                    </p>
                </details>

                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        Which wallets are supported?
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        We support MetaMask, Trust Wallet, Coinbase Wallet, and OKX Wallet.
                        Make sure you're connected to BNB Smart Chain (BSC) before purchasing.
                    </p>
                </details>

                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        When can I claim my tokens?
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        TGE tokens are available immediately after the sale ends. Remaining tokens vest
                        according to the schedule. You can also receive wTPIX (Wrapped TPIX) on BSC as an
                        interim tradeable token before the TPIX Chain bridge is live.
                    </p>
                </details>
            </div>
        </section>

        <!-- ===== CTA ===== -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6 py-12 text-center">
            <div class="glass-brand p-8 sm:p-12 rounded-2xl">
                <h2 class="text-2xl sm:text-3xl font-bold text-white mb-3">Ready to Join?</h2>
                <p class="text-gray-300 mb-6">
                    Don't miss the opportunity to be an early TPIX holder.
                </p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="#buy-section" class="btn-primary px-8 py-3 font-semibold">
                        Buy TPIX Now
                    </a>
                    <a href="/whitepaper" class="btn-secondary px-8 py-3 font-semibold">
                        Read Whitepaper
                    </a>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
