<script setup>
/**
 * TPIX TRADE - Launch Page (Bonding Curve Token Sale)
 *
 * Zero-liquidity launch: ผู้ซื้อ bridge USDT มา TPIX chain แล้วซื้อตาม curve
 * ราคาเพิ่มตาม supply ที่ขาย — เมื่อถึง threshold → migrate ไป DEX pool
 *
 * Components:
 *   - Hero + live price
 *   - Progress bars (USDT raised + TPIX sold toward migration)
 *   - Buy form with slippage control
 *   - User position panel (if connected)
 *   - Migration info + tokenomics
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatUnits } from 'ethers';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useBondingCurve } from '@/Composables/useBondingCurve';
import { TPIX_CHAIN_ID } from '@/Config/launchContracts';

const walletStore = useWalletStore();
const curve = useBondingCurve();

// Destructure form refs สำหรับ v-model ที่ทำงานถูกต้อง
// (nested refs ผ่าน curve.xxx ใน template ไม่ auto-unwrap)
const { usdtAmount, slippageBps } = curve;

// =========================================================================
// Debounced quote refresh เวลาเปลี่ยน usdtAmount
// =========================================================================

let quoteTimer = null;
watch(usdtAmount, () => {
    if (quoteTimer) clearTimeout(quoteTimer);
    quoteTimer = setTimeout(() => curve.refreshQuote(), 400);
});

// =========================================================================
// Computed formatters
// =========================================================================

function formatUsdt(bn) {
    if (!bn) return '0.00';
    const num = parseFloat(formatUnits(bn, 6));
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatTpix(bn) {
    if (!bn) return '0';
    const num = parseFloat(formatUnits(bn, 18));
    if (num < 0.01) return num.toFixed(6);
    if (num < 1) return num.toFixed(4);
    if (num < 1000) return num.toFixed(2);
    return num.toLocaleString('en-US', { maximumFractionDigits: 2 });
}

const tpixOutDisplay = computed(() => formatTpix(curve.quote.value.tpixOut));
const effectivePriceDisplay = computed(() => {
    const p = curve.quote.value.effectivePrice;
    if (!p || p === 0n) return '—';
    return '$' + parseFloat(formatUnits(p, 6)).toFixed(4);
});

const totalSoldDisplay = computed(() => formatTpix(curve.state.value.totalSold));
const totalRaisedDisplay = computed(() => formatUsdt(curve.state.value.totalRaised));
const saleSupplyDisplay = computed(() => formatTpix(curve.state.value.saleSupply));
const migrationUsdtDisplay = computed(() =>
    formatUsdt(curve.state.value.migrationUsdtThreshold)
);
const migrationTpixDisplay = computed(() =>
    formatTpix(curve.state.value.migrationTpixThreshold)
);

const userBoughtDisplay = computed(() => formatTpix(curve.userBought.value));
const userWtpixDisplay = computed(() => formatTpix(curve.wtpixBalance.value));
const userUsdtDisplay = computed(() => formatUsdt(curve.usdtBalance.value));

// Min TPIX out ตาม slippage tolerance
const minTpixOutDisplay = computed(() => {
    const out = curve.quote.value.tpixOut;
    if (!out || out === 0n) return '0';
    const min = (out * BigInt(10000 - slippageBps.value)) / 10000n;
    return formatTpix(min);
});

// =========================================================================
// UI state: slippage picker + wrong-chain banner
// =========================================================================

const slippageOptions = [100, 200, 500, 1000]; // 1%, 2%, 5%, 10%
const showSlippageMenu = ref(false);

const onWrongChain = computed(() =>
    walletStore.isConnected && walletStore.chainId !== TPIX_CHAIN_ID
);

async function handleBuy() {
    try {
        await curve.buy();
    } catch (err) {
        // already set to curve.error, composable logged it
    }
}

async function switchChain() {
    try {
        await walletStore.switchChain(TPIX_CHAIN_ID);
    } catch {
        // wallet ปฏิเสธ — ไม่ทำอะไรเพิ่ม
    }
}
</script>

<template>
    <Head title="TPIX Launch — Fair Bonding Curve Sale" />

    <AppLayout>
        <div class="launch-page min-h-screen py-8 px-4 sm:px-6 lg:px-8">
            <div class="max-w-5xl mx-auto space-y-8">

                <!-- ============================================================ -->
                <!-- Hero -->
                <!-- ============================================================ -->
                <section class="text-center space-y-4">
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full glass-card text-xs font-semibold tracking-wider text-primary-400 uppercase">
                        <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                        Fair Launch — Zero Pre-funded Liquidity
                    </div>
                    <h1 class="text-4xl sm:text-5xl font-bold text-white">
                        TPIX <span class="text-primary-400">Bonding Curve</span> Sale
                    </h1>
                    <p class="text-gray-400 max-w-2xl mx-auto text-lg">
                        700M TPIX เข้าสู่ตลาดผ่าน curve ราคา $0.10 → $1.00 —
                        ผู้ถือ bridge USDT เข้ามาเป็นคน fund liquidity ของ DEX pool หลัง migration
                    </p>
                </section>

                <!-- ============================================================ -->
                <!-- Loading / not-deployed banner -->
                <!-- ============================================================ -->
                <div
                    v-if="!curve.readyToUse.value"
                    class="glass-card p-6 border-l-4 border-yellow-500 text-yellow-200"
                >
                    <div class="font-semibold mb-1">⏳ Contracts ยังไม่ deploy</div>
                    <p class="text-sm text-yellow-100/80">
                        ทีมงานจะประกาศ launch date เร็วๆ นี้ ติดตามได้ที่
                        <a href="https://twitter.com/tpixofficial" class="underline">@tpixofficial</a>
                    </p>
                </div>

                <div
                    v-else-if="curve.loadError.value"
                    class="glass-card p-6 border-l-4 border-red-500 text-red-200"
                >
                    <div class="font-semibold mb-1">โหลดข้อมูลไม่ได้</div>
                    <p class="text-sm">{{ curve.loadError.value }}</p>
                    <button
                        class="mt-3 btn-primary text-xs px-3 py-1.5"
                        @click="curve.refreshState()"
                    >ลองใหม่</button>
                </div>

                <div
                    v-else-if="!curve.loaded.value"
                    class="glass-card p-12 text-center text-gray-400"
                >
                    <div class="animate-pulse">กำลังโหลดข้อมูล curve...</div>
                </div>

                <template v-else>
                    <!-- ============================================================ -->
                    <!-- Live price + progress -->
                    <!-- ============================================================ -->
                    <section class="grid md:grid-cols-3 gap-4">
                        <div class="glass-card p-5">
                            <div class="text-xs uppercase tracking-wider text-gray-400 mb-1">
                                ราคาปัจจุบัน
                            </div>
                            <div class="text-3xl font-bold text-white">
                                ${{ curve.currentPriceFormatted.value }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">per TPIX</div>
                        </div>
                        <div class="glass-card p-5">
                            <div class="text-xs uppercase tracking-wider text-gray-400 mb-1">
                                USDT Raised
                            </div>
                            <div class="text-3xl font-bold text-trading-green">
                                ${{ totalRaisedDisplay }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                of ${{ migrationUsdtDisplay }} to migrate
                            </div>
                        </div>
                        <div class="glass-card p-5">
                            <div class="text-xs uppercase tracking-wider text-gray-400 mb-1">
                                TPIX Sold
                            </div>
                            <div class="text-3xl font-bold text-primary-400">
                                {{ totalSoldDisplay }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                of {{ migrationTpixDisplay }} to migrate
                            </div>
                        </div>
                    </section>

                    <!-- Progress bars -->
                    <section class="glass-card p-6 space-y-4">
                        <div>
                            <div class="flex justify-between text-xs text-gray-400 mb-1.5">
                                <span>USDT Progress</span>
                                <span>{{ curve.progressUsdt.value.toFixed(1) }}%</span>
                            </div>
                            <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-trading-green to-primary-500 transition-all duration-500"
                                    :style="{ width: curve.progressUsdt.value + '%' }"
                                ></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs text-gray-400 mb-1.5">
                                <span>TPIX Progress</span>
                                <span>{{ curve.progressTpix.value.toFixed(1) }}%</span>
                            </div>
                            <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-primary-500 to-purple-500 transition-all duration-500"
                                    :style="{ width: curve.progressTpix.value + '%' }"
                                ></div>
                            </div>
                        </div>
                        <p
                            v-if="curve.state.value.isMigrationReady"
                            class="text-sm text-yellow-300 text-center font-semibold"
                        >
                            🎉 Migration threshold reached — กำลังย้ายไป DEX pool
                        </p>
                        <p
                            v-else-if="curve.state.value.migrated"
                            class="text-sm text-green-300 text-center font-semibold"
                        >
                            ✅ Migrated to DEX — trade TPIX ที่ DEX ได้แล้ว
                        </p>
                    </section>

                    <!-- ============================================================ -->
                    <!-- Buy form -->
                    <!-- ============================================================ -->
                    <section class="glass-card p-6 space-y-5">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xl font-bold text-white">ซื้อ TPIX</h2>
                            <div class="relative">
                                <button
                                    class="text-xs text-gray-400 hover:text-white px-3 py-1 rounded-lg bg-gray-800/50"
                                    @click="showSlippageMenu = !showSlippageMenu"
                                >
                                    Slippage: {{ (slippageBps / 100).toFixed(1) }}%
                                </button>
                                <div
                                    v-if="showSlippageMenu"
                                    class="absolute right-0 top-full mt-1 glass-card p-2 z-10 min-w-[120px]"
                                >
                                    <button
                                        v-for="opt in slippageOptions"
                                        :key="opt"
                                        class="block w-full text-left px-3 py-1.5 text-sm text-gray-300 hover:bg-primary-500/20 rounded"
                                        :class="{ 'bg-primary-500/30 text-white': slippageBps === opt }"
                                        @click="slippageBps = opt; showSlippageMenu = false"
                                    >
                                        {{ (opt / 100).toFixed(1) }}%
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Wrong chain banner -->
                        <div
                            v-if="onWrongChain"
                            class="bg-yellow-500/10 border border-yellow-500/30 text-yellow-200 p-3 rounded-lg text-sm flex items-center justify-between"
                        >
                            <span>กรุณาสลับไปยัง TPIX Chain (4289)</span>
                            <button class="btn-primary text-xs px-3 py-1" @click="switchChain">
                                สลับ
                            </button>
                        </div>

                        <!-- Input USDT -->
                        <div>
                            <div class="flex justify-between text-xs text-gray-400 mb-1.5">
                                <span>จ่าย (USDT)</span>
                                <span v-if="walletStore.isConnected">
                                    Balance: {{ userUsdtDisplay }}
                                </span>
                            </div>
                            <div class="relative">
                                <input
                                    v-model="usdtAmount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    class="trading-input w-full pr-20 text-2xl font-semibold"
                                    :disabled="!curve.saleActive.value"
                                />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 font-semibold">
                                    USDT
                                </span>
                            </div>
                        </div>

                        <!-- Preview TPIX -->
                        <div class="bg-black/30 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-gray-400">ได้รับ (ประมาณ)</span>
                                <span v-if="curve.isQuoting.value" class="text-xs text-gray-500 animate-pulse">
                                    คำนวณ...
                                </span>
                            </div>
                            <div class="text-3xl font-bold text-primary-300">
                                {{ tpixOutDisplay }}
                                <span class="text-lg text-gray-500">TPIX</span>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>ราคาเฉลี่ย (เมื่อซื้อ)</span>
                                <span class="text-gray-300">{{ effectivePriceDisplay }}</span>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>Min TPIX (slippage guard)</span>
                                <span class="text-gray-300">{{ minTpixOutDisplay }}</span>
                            </div>
                        </div>

                        <!-- Buy button -->
                        <button
                            class="btn-success w-full py-3 text-lg font-bold"
                            :disabled="!curve.canBuy.value"
                            @click="handleBuy"
                        >
                            <span v-if="!walletStore.isConnected">เชื่อมต่อ Wallet ก่อน</span>
                            <span v-else-if="curve.state.value.migrated">Migrated — ซื้อที่ DEX</span>
                            <span v-else-if="curve.state.value.paused">Sale Paused</span>
                            <span v-else-if="curve.isApproving.value">Approving USDT...</span>
                            <span v-else-if="curve.isBuying.value">Processing...</span>
                            <span v-else-if="curve.needsApproval.value">Approve & Buy TPIX</span>
                            <span v-else>Buy TPIX</span>
                        </button>

                        <!-- Error -->
                        <div
                            v-if="curve.error.value"
                            class="text-sm text-red-400 bg-red-500/10 border border-red-500/30 p-3 rounded-lg"
                        >
                            {{ curve.error.value }}
                        </div>

                        <!-- TX success -->
                        <div
                            v-if="curve.txHash.value"
                            class="text-sm text-green-400 bg-green-500/10 border border-green-500/30 p-3 rounded-lg flex items-center justify-between"
                        >
                            <span>ธุรกรรมสำเร็จ</span>
                            <a
                                :href="`https://explorer.tpix.online/tx/${curve.txHash.value}`"
                                target="_blank"
                                class="underline hover:text-green-300 text-xs"
                            >ดูบน Explorer ↗</a>
                        </div>
                    </section>

                    <!-- ============================================================ -->
                    <!-- User position (if connected) -->
                    <!-- ============================================================ -->
                    <section
                        v-if="walletStore.isConnected"
                        class="glass-card p-6 space-y-3"
                    >
                        <h3 class="text-lg font-bold text-white">ตำแหน่งของคุณ</h3>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <div class="text-xs text-gray-400">ซื้อรวม</div>
                                <div class="text-xl font-semibold text-primary-300">
                                    {{ userBoughtDisplay }}
                                    <span class="text-xs text-gray-500">TPIX</span>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-400">WTPIX Balance</div>
                                <div class="text-xl font-semibold text-white">
                                    {{ userWtpixDisplay }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-400">USDT Balance</div>
                                <div class="text-xl font-semibold text-trading-green">
                                    ${{ userUsdtDisplay }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================================ -->
                    <!-- How it works -->
                    <!-- ============================================================ -->
                    <section class="glass-card p-6 space-y-4">
                        <h3 class="text-lg font-bold text-white">ทำงานยังไง</h3>
                        <ol class="space-y-3 text-sm text-gray-300 list-decimal list-inside">
                            <li>Bridge USDT จาก BSC/ETH มายัง TPIX chain ผ่าน
                                <a href="/bridge" class="text-primary-400 hover:underline">Bridge</a>
                            </li>
                            <li>Approve + ซื้อ TPIX ได้เลยที่หน้านี้ — ราคาตาม curve (ยิ่งคนซื้อเยอะ ราคายิ่งสูงขึ้น)</li>
                            <li>ได้ WTPIX (wrapped TPIX ERC-20) ทันที ถ้าอยากได้ native TPIX → unwrap</li>
                            <li>เมื่อระดมได้ $5M หรือขายหมด 350M TPIX → contract migrate USDT+TPIX ที่เหลือไปสร้าง DEX pool</li>
                            <li>หลัง migrate ซื้อขายได้บน DEX ปกติ (ราคาเป็นไปตาม AMM)</li>
                        </ol>
                    </section>
                </template>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.launch-page {
    background: radial-gradient(ellipse at top, rgba(59, 130, 246, 0.05), transparent 60%),
                radial-gradient(ellipse at bottom, rgba(139, 92, 246, 0.05), transparent 60%);
}
</style>
