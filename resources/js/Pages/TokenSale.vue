<script setup>
/**
 * TPIX TRADE - Token Sale Page (ICO/IDO)
 * หน้าขายเหรียญ TPIX — Hero + Countdown + Phases + Buy Form + Tokenomics
 * รับ props จาก TokenSaleController (Inertia)
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenSaleStore } from '@/Stores/tokenSaleStore';
import { useTokenSale } from '@/Composables/useTokenSale';
import CountdownTimer from '@/Components/TokenSale/CountdownTimer.vue';
import SaleProgressBar from '@/Components/TokenSale/SaleProgressBar.vue';
import PhaseCard from '@/Components/TokenSale/PhaseCard.vue';
import BuyForm from '@/Components/TokenSale/BuyForm.vue';
import TokenomicsChart from '@/Components/TokenSale/TokenomicsChart.vue';
import VestingSchedule from '@/Components/TokenSale/VestingSchedule.vue';
import { useTranslation } from '@/Composables/useTranslation';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';

const { t, locale } = useTranslation();

function formatDate(val) {
    return new Date(val).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US');
}

// สถานะการซื้อจากเซิร์ฟเวอร์ — แปลเฉพาะค่าที่รู้จัก ค่าอื่นแสดงตามที่ได้มา
const purchaseStatusKeys = {
    confirmed: 'page.tokenSale.status.confirmed',
    pending: 'page.tokenSale.status.pending',
    failed: 'page.tokenSale.status.failed',
    refunded: 'page.tokenSale.status.refunded',
};
function purchaseStatusLabel(status) {
    return purchaseStatusKeys[status] ? t(purchaseStatusKeys[status]) : status;
}

// Props จาก backend (Inertia)
const props = defineProps({
    sale: { type: Object, default: null },
    stats: { type: Object, default: null },
});

const walletStore = useWalletStore();
const tokenSaleStore = useTokenSaleStore();
const { retryPendingPurchases } = useTokenSale();

// Phase ที่ผู้ใช้เลือก
const selectedPhaseId = ref(null);

// ขั้นตอนการซื้อ (How to Buy)
// เก็บ key แปลภาษา — เรียก t() ตอน render เพื่อให้สลับภาษาได้ทันที
const howToBuySteps = [
    { step: 1, key: 'step1', icon: 'wallet' },
    { step: 2, key: 'step2', icon: 'calculator' },
    { step: 3, key: 'step3', icon: 'check' },
    { step: 4, key: 'step4', icon: 'gift' },
];

// Tokenomics data (7B total)
// computed — ป้ายในกราฟเปลี่ยนตามภาษาทันทีที่สลับ
const tokenomicsData = computed(() => [
    { label: t('page.tokenSale.tokenomics.publicSale'), percent: 10, color: '#06B6D4' },
    { label: t('page.tokenSale.tokenomics.liquidity'), percent: 30, color: '#8B5CF6' },
    { label: t('page.tokenSale.tokenomics.masterNode'), percent: 20, color: '#00C853' },
    { label: t('page.tokenSale.tokenomics.team'), percent: 20, color: '#F97316' },
    { label: t('page.tokenSale.tokenomics.ecosystem'), percent: 10, color: '#3B82F6' },
    { label: t('page.tokenSale.tokenomics.development'), percent: 10, color: '#EC4899' },
]);

onMounted(async () => {
    loadTradablePair();
    // โหลดข้อมูล real-time จาก API
    await tokenSaleStore.loadAll(walletStore.address);

    // ถ้ามี active phase ให้เลือกอัตโนมัติ
    if (tokenSaleStore.activePhase) {
        selectedPhaseId.value = tokenSaleStore.activePhase.id;
    }

    // ★ กู้คืนรายการที่จ่าย BSC สำเร็จแต่ API ยังไม่ได้บันทึก
    if (walletStore.address) {
        retryPendingPurchases();
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
    // เหรียญที่ปลดล็อกแล้วอยู่ในกระเป๋าบนเชน TPIX — เอาไปเทรดต่อได้เลยถ้ากระดานเปิด
    justPurchased.value = true;
}

/*
 * ปุ่ม "เทรดเลย" — ขึ้นเฉพาะตอนกระดาน TPIX บนเชนเราเปิดจริง
 *
 * ต้องถามเซิร์ฟเวอร์ว่าคู่นี้ส่งคำสั่งได้จริงไหม (execution_mode = onchain)
 * ไม่ใช่เดาจากชื่อคู่ ไม่งั้นผู้ใช้กดแล้วไปเจอหน้าที่เขียนว่า "เร็ว ๆ นี้" ทันทีหลังจ่ายเงิน
 */
const justPurchased = ref(false);
const tradablePair = ref(null);

async function loadTradablePair() {
    try {
        const { data } = await axios.get('/api/v1/dex/pairs');
        const pair = (data?.data || []).find(
            (p) => String(p.symbol).toUpperCase() === 'TPIX-USDT' && p.is_active,
        );
        tradablePair.value = pair ? pair.symbol : null;
    } catch {
        tradablePair.value = null;
    }
}
</script>

<template>
    <Head :title="t('page.tokenSale.metaTitle')" />

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
                <BrandLogo variant="mark" alt="TPIX" class="w-20 h-20 mx-auto mb-6" />

                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-3">
                    {{ t('tokenSale.title') }}
                </h1>
                <p class="text-lg text-gray-400 mb-8 max-w-2xl mx-auto">
                    {{ t('page.tokenSale.heroDesc') }}
                </p>

                <!-- Countdown Timer -->
                <div v-if="tokenSaleStore.sale?.ends_at" class="mb-8">
                    <p class="text-sm text-gray-400 mb-3 uppercase tracking-wider">{{ t('page.tokenSale.saleEndsIn') }}</p>
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
            <p class="text-gray-400 mb-8">{{ t('page.tokenSale.selectPhase') }}</p>

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
                <p class="text-gray-400">{{ t('page.tokenSale.phasesSoon') }}</p>
            </div>
        </section>

        <!-- ===== BUY FORM + INFO ===== -->
        <section id="buy-section" class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <!-- แจ้งเงื่อนไขก่อนจ่ายเงิน — ผู้ซื้อต้องรู้ล่วงหน้าว่าซื้อแล้วขายคืนไม่ได้
                 และเหรียญทยอยปลดตาม vesting ไม่ได้ครบทันที -->
            <div class="mb-8 rounded-xl border border-amber-500/30 bg-amber-500/5 p-5">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <div class="text-sm leading-relaxed">
                        <p class="font-semibold text-amber-300 mb-2">{{ t('tokenSale.riskTitle') }}</p>
                        <ul class="space-y-1.5 text-gray-300 list-disc list-inside marker:text-amber-500/60">
                            <li>{{ t('tokenSale.riskOneWay') }}</li>
                            <li>{{ t('tokenSale.riskVesting') }}</li>
                            <li>{{ t('tokenSale.riskNetwork') }}</li>
                            <li>{{ t('tokenSale.riskFinal') }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- ฟอร์มซื้อ -->
                <BuyForm @purchase-complete="handlePurchaseComplete" />

                <!-- ซื้อแล้วเทรดต่อได้ทันที — ขึ้นเฉพาะตอนกระดานบนเชน TPIX เปิดจริง -->
                <div
                    v-if="justPurchased && tradablePair"
                    class="glass-dark p-4 rounded-xl border border-trading-green/30 bg-trading-green/5 flex items-center gap-3"
                >
                    <span class="text-sm text-white">{{ t('tokenSale.tradeNowBody') }}</span>
                    <Link :href="`/trade/${tradablePair}`" class="btn-success ml-auto whitespace-nowrap px-4 py-2 text-sm">
                        {{ t('tokenSale.tradeNow') }}
                    </Link>
                </div>

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
                                <h4 class="text-white font-semibold mb-1">{{ t(`page.tokenSale.steps.${item.key}.title`) }}</h4>
                                <p class="text-sm text-gray-400">{{ t(`page.tokenSale.steps.${item.key}.desc`) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Network info -->
                    <div class="mt-8 p-4 rounded-lg bg-white/5 border border-white/10">
                        <p class="text-sm text-gray-400">
                            <span class="text-white font-semibold">{{ t('page.tokenSale.info.networkLabel') }}</span> {{ t('page.tokenSale.info.networkValue') }}
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            <span class="text-white font-semibold">{{ t('page.tokenSale.info.paymentLabel') }}</span> {{ t('page.tokenSale.info.paymentValue') }}
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            <span class="text-white font-semibold">{{ t('page.tokenSale.info.tokenLabel') }}</span> {{ t('page.tokenSale.info.tokenValue') }}
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
                        <span class="text-sm text-gray-400">{{ t('page.tokenSale.details.totalSupply') }}</span>
                        <p class="text-lg font-bold text-white">7,000,000,000</p>
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-400">{{ t('page.tokenSale.details.decimals') }}</span>
                        <p class="text-lg font-bold text-white">18</p>
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-400">{{ t('page.tokenSale.details.chain') }}</span>
                        <p class="text-lg font-bold text-white">TPIX Chain</p>
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-400">{{ t('page.tokenSale.details.consensus') }}</span>
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
                    <p class="text-xs text-gray-400 mb-1">{{ t('page.tokenSale.summary.myTotal') }}</p>
                    <p class="text-xl font-bold text-primary-400">{{ Number(tokenSaleStore.totalPurchased || 0).toLocaleString() }}</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-xs text-gray-400 mb-1">{{ t('page.tokenSale.summary.claimable') }}</p>
                    <p class="text-xl font-bold text-trading-green">{{ Number(tokenSaleStore.totalClaimable || 0).toLocaleString() }}</p>
                </div>
                <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                    <p class="text-xs text-gray-400 mb-1">{{ t('page.tokenSale.summary.totalPurchases') }}</p>
                    <p class="text-xl font-bold text-white">{{ tokenSaleStore.purchases?.length || 0 }}</p>
                </div>
            </div>

            <!-- Purchase History -->
            <div v-if="tokenSaleStore.purchases?.length > 0" class="glass-dark p-6 rounded-xl border border-white/10 mb-6">
                <h3 class="text-lg font-bold text-white mb-4">{{ t('page.tokenSale.history.title') }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="text-left pb-3 text-gray-400 font-medium">{{ t('page.tokenSale.history.date') }}</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">{{ t('page.tokenSale.history.amount') }}</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">{{ t('page.tokenSale.history.paid') }}</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">{{ t('page.tokenSale.history.status') }}</th>
                                <th class="text-left pb-3 text-gray-400 font-medium">{{ t('page.tokenSale.history.tx') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in tokenSaleStore.purchases" :key="p.id" class="border-b border-white/5">
                                <td class="py-3 text-gray-300">{{ formatDate(p.created_at) }}</td>
                                <td class="py-3 text-white font-medium">{{ Number(p.tpix_amount).toLocaleString() }} TPIX</td>
                                <td class="py-3 text-gray-300">{{ p.payment_amount }} {{ p.payment_currency }}</td>
                                <td class="py-3">
                                    <span :class="[
                                        'text-xs px-2 py-0.5 rounded-full',
                                        p.status === 'confirmed' ? 'bg-green-500/10 text-green-400' :
                                        p.status === 'pending' ? 'bg-yellow-500/10 text-yellow-400' :
                                        'bg-gray-500/10 text-gray-400'
                                    ]">{{ purchaseStatusLabel(p.status) }}</span>
                                    <p v-if="p.status === 'confirmed' && p.claim_hold_until && new Date(p.claim_hold_until) > new Date()" class="text-xs text-yellow-400/80 mt-1">
                                        {{ t('page.tokenSale.history.claimOpens', { date: formatDate(p.claim_hold_until) }) }}
                                    </p>
                                </td>
                                <td class="py-3">
                                    <!-- Stripe transactions ไม่ใช่ BSC tx_hash -->
                                    <template v-if="p.tx_hash?.startsWith('stripe_')">
                                        <span class="text-xs font-mono text-purple-400" :title="p.tx_hash">
                                            Stripe ✓
                                        </span>
                                    </template>
                                    <a v-else-if="p.tx_hash" :href="`https://bscscan.com/tx/${p.tx_hash}`" target="_blank" rel="noopener" class="text-primary-400 text-xs font-mono hover:underline">
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
            <h2 class="text-2xl font-bold text-white mb-8 text-center">{{ t('page.tokenSale.faq.title') }}</h2>

            <div class="space-y-4">
                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        {{ t('page.tokenSale.faq.q1') }}
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        {{ t('page.tokenSale.faq.a1') }}
                    </p>
                </details>

                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        {{ t('page.tokenSale.faq.q2') }}
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        {{ t('page.tokenSale.faq.a2') }}
                    </p>
                </details>

                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        {{ t('page.tokenSale.faq.q3') }}
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        {{ t('page.tokenSale.faq.a3') }}
                    </p>
                </details>

                <details class="glass-dark rounded-xl border border-white/10 p-4 group">
                    <summary class="text-white font-semibold cursor-pointer list-none flex justify-between items-center">
                        {{ t('page.tokenSale.faq.q4') }}
                        <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </summary>
                    <p class="text-gray-400 mt-3 text-sm">
                        {{ t('page.tokenSale.faq.a4') }}
                    </p>
                </details>
            </div>
        </section>

        <!-- ===== CTA ===== -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6 py-12 text-center">
            <div class="glass-brand p-8 sm:p-12 rounded-2xl">
                <h2 class="text-2xl sm:text-3xl font-bold text-white mb-3">{{ t('page.tokenSale.cta.title') }}</h2>
                <p class="text-gray-300 mb-6">
                    {{ t('page.tokenSale.cta.desc') }}
                </p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="#buy-section" class="btn-primary px-8 py-3 font-semibold">
                        {{ t('page.tokenSale.cta.buy') }}
                    </a>
                    <a href="/whitepaper" class="btn-secondary px-8 py-3 font-semibold">
                        {{ t('page.tokenSale.cta.whitepaper') }}
                    </a>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
