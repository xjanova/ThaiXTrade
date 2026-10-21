<script setup>
/**
 * BuyForm — ฟอร์มซื้อเหรียญ TPIX
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * โมเดลการขาย (เจ้าของกำหนด 21 ส.ค. 2026)
 * ═══════════════════════════════════════════════════════════════════════════
 *   รับเงิน : บัตรเครดิต (Stripe) หรือโอนเงินเข้าบัญชีธนาคาร
 *   ส่งมอบ  : TPIX เนทีฟบนเชน 4289 เท่านั้น
 *   ผู้ซื้อ  : ต้องต่อกระเป๋าอยู่บนเชน 4289 ถึงจะกดซื้อได้
 *
 * ทำไมต้องบังคับเชนก่อนซื้อ: TPIX เป็นเหรียญเนทีฟของเชน 4289 ไม่ใช่โทเคนบน BSC
 * ถ้าปล่อยให้ซื้อจากเชนอื่น ผู้ซื้อจะจ่ายเงินแล้วเปิดกระเป๋าไม่เห็นอะไรเลย
 * แล้วสรุปเองว่าโดนโกง — ความเชื่อมั่นที่เสียไปตรงนั้นเรียกคืนยากกว่าเงิน
 *
 * ไม่มีทางจ่ายด้วยคริปโตแล้ว (ด่านจริงอยู่ที่ TokenSaleService::assertCurrencyAccepted)
 *
 * Developed by Xman Studio
 */
import { ref, watch, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenSaleStore } from '@/Stores/tokenSaleStore';
import { useTokenSale } from '@/Composables/useTokenSale';
import { isMobile, downloadTpixApp } from '@/utils/mobileWallet';
import { TPIX_CHAIN_CONFIG } from '@/utils/web3';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';

const walletStore = useWalletStore();
const tokenSaleStore = useTokenSaleStore();
const mobile = isMobile();

const {
    selectedCurrency,
    paymentAmount,
    preview,
    isLoadingPreview,
    error,
    phaseClosedReason,
    currentPhase,
    currentPrice,
    isSoldOut,
    exceedsRemaining,
    calculatePreview,
} = useTokenSale();

const emit = defineEmits(['purchase-complete']);

// รอบขายนี้คิดเป็นเงินสดเสมอ — ช่องกรอกจึงเป็น USD ไม่ใช่สกุลคริปโต
selectedCurrency.value = 'USD';

/** วิธีชำระเงินที่เลือกอยู่: 'card' | 'bank' */
const method = ref('card');

const isSubmittingOrder = ref(false);

/** ผลลัพธ์คำสั่งซื้อทางโอนเงิน (รหัสอ้างอิง + เลขบัญชี) */
const bankOrder = ref(null);

// คำนวณ preview เมื่อจำนวนเงินเปลี่ยน (debounce 500ms)
let debounceTimer = null;
watch(paymentAmount, () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(calculatePreview, 500);
});

/** ช่องทางที่เปิดใช้ได้จริงตอนนี้ (หลังบ้านเป็นคนบอก) */
const methods = computed(() => tokenSaleStore.sale?.payment_methods || { card: false, bank: false });

const hasAnyMethod = computed(() => methods.value.card || methods.value.bank);

/** เชนที่ผู้ซื้อต้องอยู่เพื่อรับเหรียญ — ปกติคือ 4289 */
const receiveChainId = computed(
    () => tokenSaleStore.sale?.receive_chain_id || TPIX_CHAIN_CONFIG.chainIdNum
);

const isOnReceiveChain = computed(() => walletStore.chainId === receiveChainId.value);

/**
 * ยอดขั้นต่ำเป็นเงิน USD ของเฟสนี้ (ขั้นต่ำ TPIX × ราคา) ปัดขึ้นเป็นเซ็นต์
 * เซิร์ฟเวอร์ตรวจขั้นต่ำเป็นจำนวน TPIX อยู่แล้ว ตรงนี้มีไว้บอกผู้ซื้อก่อนกด
 * ไม่ใช่ให้เขากดแล้วเจอ "Minimum purchase is 100 TPIX." จากเซิร์ฟเวอร์
 */
const minUsd = computed(() => {
    const phase = currentPhase.value;
    if (!phase) return 0;
    const raw = Number(phase.min_purchase || 0) * Number(phase.price_usd || 0);
    return Math.ceil(raw * 100) / 100;
});

const amountNumber = computed(() => parseFloat(paymentAmount.value) || 0);

const belowMin = computed(() => amountNumber.value > 0 && amountNumber.value < minUsd.value);

/** ปุ่มเลือกยอดเร็ว — เริ่มที่ยอดขั้นต่ำ ไม่ต่ำกว่านั้น */
const quickAmounts = computed(() => {
    const base = [10, 50, 100, 500];
    const list = minUsd.value > 0 ? [minUsd.value, ...base] : base;
    return [...new Set(list.filter((v) => v >= minUsd.value))].sort((a, b) => a - b).slice(0, 5);
});

function setAmount(value) {
    paymentAmount.value = String(value);
}

/**
 * ค่าบริการชำระด้วยบัตร — บวกเพิ่มจากค่าเหรียญ ไม่ได้ลดจำนวนเหรียญ
 * เซิร์ฟเวอร์คิดเป็นเซ็นต์แบบเดียวกัน (StripePaymentService::feeCents) และเป็นคนตัดสินยอดจริง
 */
const cardFeePercent = computed(() => Number(tokenSaleStore.sale?.card_fee_percent || 0));

const feeUsd = computed(() => {
    if (method.value !== 'card' || !amountNumber.value) return 0;
    const baseCents = Math.round(amountNumber.value * 100);
    return Math.round((baseCents * cardFeePercent.value) / 100) / 100;
});

const totalUsd = computed(() => Math.round((amountNumber.value + feeUsd.value) * 100) / 100);

const canSubmit = computed(() => {
    return (
        walletStore.isConnected &&
        isOnReceiveChain.value &&
        currentPhase.value &&
        !isSoldOut.value &&
        !exceedsRemaining.value &&
        !phaseClosedReason.value &&
        amountNumber.value > 0 &&
        !belowMin.value &&
        preview.value &&
        !isSubmittingOrder.value &&
        methods.value[method.value]
    );
});

/** บอกเหตุผลที่ปุ่มยังกดไม่ได้ — ปุ่มสีจางเฉยๆ ทำให้คนไม่รู้ว่าต้องทำอะไรต่อ */
const submitHint = computed(() => {
    if (isSubmittingOrder.value) return '';
    if (!amountNumber.value) return 'เลือกหรือพิมพ์จำนวนเงินด้านบนก่อน';
    if (belowMin.value) return `ขั้นต่ำ $${formatMoney(minUsd.value)} ต่อการซื้อหนึ่งครั้ง`;
    if (isLoadingPreview.value || !preview.value) return 'กำลังคำนวณจำนวนเหรียญ…';
    return '';
});

function formatMoney(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

/** สลับกระเป๋าไปเชน TPIX (เพิ่มเครือข่ายให้อัตโนมัติถ้ายังไม่มี) */
async function switchToReceiveChain() {
    error.value = null;
    try {
        await walletStore.switchChain(receiveChainId.value);
    } catch (e) {
        error.value = e?.message || 'สลับเครือข่ายไม่สำเร็จ กรุณาสลับในแอปกระเป๋าเอง';
    }
}

/**
 * กดซื้อ — แยกทางตามวิธีชำระเงิน
 *
 * ทั้งสองทางผ่านด่านฝั่งเซิร์ฟเวอร์ชุดเดียวกัน (เฟสเปิด · กระเป๋ายืนยันแล้ว ·
 * ขั้นต่ำ/เพดาน · โควตาที่เหลือ) ก่อนจะมีอะไรเกิดขึ้นกับเงินจริง
 */
async function submitOrder() {
    if (!canSubmit.value) return;

    isSubmittingOrder.value = true;
    error.value = null;
    bankOrder.value = null;

    const payload = {
        wallet_address: walletStore.address,
        phase_id: currentPhase.value.id,
        amount_usd: Number(preview.value.payment_usd_value),
    };

    try {
        if (method.value === 'card') {
            const res = await tokenSaleStore.createStripeCheckout(payload);

            if (!res.ok) {
                error.value = res.message;
                return;
            }

            // พาไปหน้าชำระเงินของ Stripe
            window.location.href = res.url;

            return;
        }

        const res = await tokenSaleStore.createBankOrder(payload);

        if (!res.ok) {
            error.value = res.message;
            return;
        }

        bankOrder.value = res.data;
        emit('purchase-complete');
    } finally {
        isSubmittingOrder.value = false;
    }
}

function resetBankOrder() {
    bankOrder.value = null;
    paymentAmount.value = '';
    preview.value = null;
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(String(text));
    } catch {
        // เบราว์เซอร์บางตัวไม่ให้สิทธิ์คลิปบอร์ด — ผู้ใช้ยังเลือกคัดลอกเองได้
    }
}

function formatNumber(n) {
    if (!n) return '0';
    return Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 });
}
</script>

<template>
    <div class="buy-form glass-dark p-6 rounded-xl border border-white/10">
        <h3 class="text-xl font-bold text-white mb-1">ซื้อเหรียญ TPIX</h3>
        <p class="text-sm text-gray-400 mb-6">
            ราคาปัจจุบัน <span class="text-primary-400 font-semibold">${{ currentPrice }}</span> ต่อ TPIX
        </p>

        <!-- ยังไม่เชื่อมกระเป๋า -->
        <div v-if="!walletStore.isConnected" class="text-center py-6 space-y-4">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/20 flex items-center justify-center mx-auto">
                <svg class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3" />
                </svg>
            </div>
            <div>
                <p class="text-white font-semibold mb-1">เชื่อมต่อกระเป๋าเพื่อซื้อ TPIX</p>
                <p class="text-gray-400 text-xs">กระเป๋านี้คือที่ที่เหรียญจะถูกส่งไป ยังไม่มีก็สร้างได้เลย</p>
            </div>

            <button
                @click="walletStore.openConnectModal()"
                class="w-full py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-semibold hover:shadow-lg hover:shadow-primary-500/20 transition-all"
            >
                เชื่อมต่อกระเป๋า / สร้างกระเป๋า
            </button>

            <button
                v-if="mobile"
                @click="downloadTpixApp"
                class="w-full py-2.5 rounded-xl font-medium text-sm bg-accent-500/10 border border-accent-500/20 text-accent-300 hover:bg-accent-500/20 transition-all"
            >
                ดาวน์โหลดแอป TPIX
            </button>
        </div>

        <!--
            ★ ด่านเชน — ต้องอยู่บนเชน TPIX ก่อนถึงจะซื้อได้
            TPIX เป็นเหรียญเนทีฟของเชน 4289 ซื้อจากเชนอื่นแล้วจะเปิดกระเป๋าไม่เห็นเหรียญ
        -->
        <div v-else-if="!isOnReceiveChain" class="text-center py-8 space-y-4">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/20 flex items-center justify-center mx-auto">
                <BrandLogo variant="icon" alt="TPIX Chain" class="w-9 h-9" />
            </div>
            <div>
                <p class="text-white font-semibold mb-1">สลับไปเครือข่าย TPIX Chain ก่อน</p>
                <p class="text-gray-400 text-xs max-w-sm mx-auto">
                    TPIX เป็นเหรียญหลักของเครือข่ายนี้ ต้องต่อกระเป๋าอยู่บน TPIX Chain
                    จึงจะเห็นเหรียญที่ซื้อ — ถ้ายังไม่มีเครือข่ายนี้ ระบบจะเพิ่มให้อัตโนมัติ
                </p>
            </div>
            <button class="btn-primary px-6 py-2.5" @click="switchToReceiveChain">
                สลับไป TPIX Chain
            </button>
            <p v-if="error" class="text-sm text-trading-red">{{ error }}</p>
        </div>

        <!-- ไม่มีเฟสที่เปิดขาย -->
        <div v-else-if="!currentPhase" class="text-center py-8 text-gray-400">
            <p>ยังไม่มีรอบขายที่เปิดอยู่ในขณะนี้</p>
            <p v-if="phaseClosedReason" class="mt-2 text-sm text-yellow-400">{{ phaseClosedReason }}</p>
        </div>

        <!-- เหรียญหมดรอบ -->
        <div v-else-if="isSoldOut" class="text-center py-8">
            <div class="w-14 h-14 rounded-2xl bg-yellow-500/20 flex items-center justify-center mx-auto mb-3">
                <svg class="w-7 h-7 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <p class="text-yellow-400 font-semibold mb-1">ขายหมดรอบนี้แล้ว</p>
            <p class="text-gray-400 text-sm">โควตาของเฟสนี้ถูกจองครบแล้ว</p>
        </div>

        <!-- ผลลัพธ์คำสั่งซื้อทางโอนเงิน -->
        <div v-else-if="bankOrder" class="space-y-4">
            <div class="p-4 rounded-xl bg-trading-green/10 border border-trading-green/30">
                <p class="text-trading-green font-semibold mb-1">สร้างคำสั่งซื้อแล้ว</p>
                <p class="text-sm text-gray-300">
                    โอนเงิน <span class="text-white font-semibold">${{ formatNumber(bankOrder.amount_usd) }}</span>
                    แล้วทีมงานจะยืนยันและส่ง
                    <span class="text-white font-semibold">{{ formatNumber(bankOrder.tpix_amount) }} TPIX</span>
                    ให้ที่กระเป๋าของคุณบน TPIX Chain
                </p>
            </div>

            <!-- รหัสอ้างอิง — สำคัญที่สุด ต้องใส่ตอนโอน -->
            <div class="p-4 rounded-xl bg-white/5 border border-white/10">
                <p class="text-xs text-gray-400 mb-1">รหัสอ้างอิง (ใส่ในหมายเหตุตอนโอน)</p>
                <div class="flex items-center justify-between gap-3">
                    <code class="text-lg font-bold text-primary-300 tracking-wider">{{ bankOrder.reference }}</code>
                    <button class="text-xs px-3 py-1.5 rounded-lg bg-white/10 text-gray-200 hover:bg-white/20"
                        @click="copyText(bankOrder.reference)">คัดลอก</button>
                </div>
                <p class="text-xs text-yellow-400/80 mt-2">
                    ไม่ใส่รหัสนี้ ทีมงานจะจับคู่รายการโอนของคุณไม่ได้
                </p>
            </div>

            <!-- บัญชีปลายทาง -->
            <div class="p-4 rounded-xl bg-white/5 border border-white/10 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400">ธนาคาร</span>
                    <span class="text-white">{{ bankOrder.bank.bank_name || '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400">ชื่อบัญชี</span>
                    <span class="text-white">{{ bankOrder.bank.account_name || '—' }}</span>
                </div>
                <div class="flex justify-between gap-3 items-center">
                    <span class="text-gray-400">เลขบัญชี</span>
                    <span class="flex items-center gap-2">
                        <span class="text-white font-mono">{{ bankOrder.bank.account_no }}</span>
                        <button class="text-xs px-2 py-1 rounded bg-white/10 text-gray-200 hover:bg-white/20"
                            @click="copyText(bankOrder.bank.account_no)">คัดลอก</button>
                    </span>
                </div>
                <p v-if="bankOrder.bank.note" class="text-xs text-gray-400 pt-2 border-t border-white/5">
                    {{ bankOrder.bank.note }}
                </p>
            </div>

            <button class="w-full py-2.5 rounded-xl bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10"
                @click="resetBankOrder">
                สั่งซื้อรายการใหม่
            </button>
        </div>

        <!-- ฟอร์มซื้อ -->
        <div v-else>
            <!-- ยังไม่เปิดช่องทางชำระเงินสักทาง -->
            <div v-if="!hasAnyMethod" class="p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/30 text-sm text-yellow-300">
                ยังไม่เปิดช่องทางชำระเงิน กรุณาติดต่อทีมงาน
            </div>

            <template v-else>
                <!-- ① เลือกวิธีชำระเงิน -->
                <div class="mb-5">
                    <p class="flex items-center gap-2 text-sm text-gray-300 mb-2">
                        <span class="step-dot">1</span> เลือกวิธีชำระเงิน
                    </p>
                    <div class="grid gap-2" :class="methods.card && methods.bank ? 'sm:grid-cols-2' : ''">
                        <button
                            v-if="methods.card"
                            type="button"
                            class="method-card"
                            :class="method === 'card' ? 'method-card--active' : ''"
                            data-testid="method-card"
                            @click="method = 'card'"
                        >
                            <span class="flex items-center justify-between gap-3 w-full">
                                <span class="text-left">
                                    <span class="block text-white font-semibold">บัตรเครดิต / เดบิต</span>
                                    <span class="block text-xs text-gray-400 mt-0.5">จ่ายทันที รับเหรียญอัตโนมัติ</span>
                                </span>
                                <span class="radio-dot" :class="method === 'card' ? 'radio-dot--on' : ''"></span>
                            </span>
                            <span class="flex items-center gap-1.5 mt-3" aria-label="Visa, Mastercard, JCB, American Express">
                                <!-- Visa -->
                                <svg class="card-logo" viewBox="0 0 48 30" aria-hidden="true">
                                    <rect width="48" height="30" rx="4" fill="#fff" />
                                    <text x="24" y="20.5" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="900" font-style="italic" fill="#1A1F71">VISA</text>
                                </svg>
                                <!-- Mastercard -->
                                <svg class="card-logo" viewBox="0 0 48 30" aria-hidden="true">
                                    <rect width="48" height="30" rx="4" fill="#fff" />
                                    <circle cx="19.5" cy="15" r="8.5" fill="#EB001B" />
                                    <circle cx="28.5" cy="15" r="8.5" fill="#F79E1B" />
                                    <path d="M24 7.8a8.5 8.5 0 0 1 0 14.4a8.5 8.5 0 0 1 0-14.4z" fill="#FF5F00" />
                                </svg>
                                <!-- JCB -->
                                <svg class="card-logo" viewBox="0 0 48 30" aria-hidden="true">
                                    <rect width="48" height="30" rx="4" fill="#fff" />
                                    <rect x="9" y="6" width="9" height="18" rx="3" fill="#0E4C96" />
                                    <rect x="19.5" y="6" width="9" height="18" rx="3" fill="#E21836" />
                                    <rect x="30" y="6" width="9" height="18" rx="3" fill="#007B40" />
                                    <text x="24" y="18.3" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="8" font-weight="900" fill="#fff">JCB</text>
                                </svg>
                                <!-- American Express -->
                                <svg class="card-logo" viewBox="0 0 48 30" aria-hidden="true">
                                    <rect width="48" height="30" rx="4" fill="#2E77BC" />
                                    <text x="24" y="19" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="9" font-weight="900" fill="#fff">AMEX</text>
                                </svg>
                            </span>
                        </button>
                        <button
                            v-if="methods.bank"
                            type="button"
                            class="method-card"
                            :class="method === 'bank' ? 'method-card--active' : ''"
                            data-testid="method-bank"
                            @click="method = 'bank'"
                        >
                            <span class="flex items-center justify-between gap-3 w-full">
                                <span class="text-left">
                                    <span class="block text-white font-semibold">โอนเงินเข้าบัญชี</span>
                                    <span class="block text-xs text-gray-400 mt-0.5">ไม่มีค่าบริการ · ทีมงานยืนยันแล้วส่งเหรียญ</span>
                                </span>
                                <span class="radio-dot" :class="method === 'bank' ? 'radio-dot--on' : ''"></span>
                            </span>
                        </button>
                    </div>
                </div>

                <!-- ② จำนวนเงิน -->
                <div class="mb-5">
                    <p class="flex items-center gap-2 text-sm text-gray-300 mb-2">
                        <span class="step-dot">2</span> ใส่จำนวนเงินที่ต้องการซื้อ (USD)
                    </p>
                    <div class="flex flex-wrap gap-2 mb-2">
                        <button
                            v-for="amt in quickAmounts"
                            :key="amt"
                            type="button"
                            class="amount-chip"
                            :class="amountNumber === amt ? 'amount-chip--active' : ''"
                            @click="setAmount(amt)"
                        >
                            ${{ formatMoney(amt) }}
                        </button>
                    </div>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-lg text-gray-400">$</span>
                        <input
                            v-model="paymentAmount"
                            type="number"
                            inputmode="decimal"
                            step="any"
                            :min="minUsd"
                            :placeholder="minUsd ? formatMoney(minUsd) : '0.00'"
                            class="trading-input w-full pl-9 pr-16 py-3 text-lg"
                            data-testid="amount-input"
                        />
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-400">USD</span>
                    </div>
                    <p class="mt-1.5 text-xs" :class="belowMin ? 'text-yellow-400' : 'text-gray-500'">
                        ขั้นต่ำ ${{ formatMoney(minUsd) }}
                        <template v-if="currentPhase"> · ราคา ${{ currentPrice }} ต่อ TPIX</template>
                    </p>
                </div>

                <!-- สรุปยอด -->
                <div v-if="preview && !belowMin" class="mb-4 p-4 rounded-xl bg-white/5 border border-white/10 space-y-1.5 text-sm" data-testid="order-summary">
                    <div class="flex justify-between">
                        <span class="text-gray-400">คุณจะได้รับ</span>
                        <span class="text-white font-bold text-base">{{ formatNumber(preview.tpix_amount) }} TPIX</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">ค่าเหรียญ</span>
                        <span class="text-gray-200">${{ formatMoney(amountNumber) }}</span>
                    </div>
                    <div v-if="feeUsd > 0" class="flex justify-between" data-testid="fee-row">
                        <span class="text-gray-400">ค่าบริการชำระด้วยบัตร ({{ formatMoney(cardFeePercent) }}%)</span>
                        <span class="text-gray-200">${{ formatMoney(feeUsd) }}</span>
                    </div>
                    <div class="flex justify-between pt-2 mt-1 border-t border-white/10">
                        <span class="text-white font-semibold">ยอดชำระทั้งหมด</span>
                        <span class="text-primary-300 font-bold text-base" data-testid="total-usd">${{ formatMoney(totalUsd) }}</span>
                    </div>
                    <div class="flex justify-between text-xs text-gray-500 pt-1">
                        <span>ส่งเหรียญไปที่</span>
                        <span class="font-mono">{{ walletStore.shortAddress }} · TPIX Chain</span>
                    </div>
                </div>

                <div v-else-if="isLoadingPreview" class="mb-4 text-sm text-gray-400">กำลังคำนวณ…</div>

                <p v-if="exceedsRemaining" class="mb-3 text-sm text-yellow-400">
                    จำนวนนี้เกินโควตาที่เหลือของเฟสนี้
                </p>

                <p v-if="error" class="mb-3 text-sm text-trading-red">{{ error }}</p>

                <!-- ③ ไปหน้ากรอกบัตร / รับเลขบัญชี -->
                <p class="flex items-center gap-2 text-sm text-gray-300 mb-2">
                    <span class="step-dot">3</span>
                    {{ method === 'card' ? 'กดปุ่มด้านล่าง แล้วกรอกเลขบัตรในหน้าชำระเงินที่ปลอดภัย' : 'กดปุ่มด้านล่างเพื่อรับเลขบัญชีและรหัสอ้างอิง' }}
                </p>
                <button
                    type="button"
                    class="w-full py-3.5 rounded-xl font-semibold text-base transition-all disabled:opacity-40 disabled:cursor-not-allowed bg-gradient-to-r from-primary-500 to-accent-500 text-white hover:shadow-lg hover:shadow-primary-500/20 flex items-center justify-center gap-2"
                    :disabled="!canSubmit"
                    data-testid="submit-order"
                    @click="submitOrder"
                >
                    <svg v-if="method === 'card' && !isSubmittingOrder" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    <span v-if="isSubmittingOrder">กำลังเปิดหน้าชำระเงิน…</span>
                    <span v-else-if="method === 'card'">
                        ไปกรอกบัตร<template v-if="canSubmit"> · ชำระ ${{ formatMoney(totalUsd) }}</template>
                    </span>
                    <span v-else>รับเลขบัญชีเพื่อโอนเงิน</span>
                </button>

                <p v-if="!canSubmit && submitHint" class="mt-2 text-xs text-center text-yellow-400/90" data-testid="submit-hint">
                    {{ submitHint }}
                </p>

                <p v-if="method === 'card'" class="mt-3 flex items-center justify-center gap-1.5 text-xs text-gray-500">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                    ชำระผ่าน Stripe · เราไม่เห็นและไม่เก็บเลขบัตรของคุณ
                </p>

                <p class="mt-2 text-xs text-gray-500 text-center">
                    เหรียญจะถูกส่งเป็น TPIX เนทีฟบน TPIX Chain ตามตารางปลดล็อกของเฟสนี้
                </p>
            </template>
        </div>
    </div>
</template>

<style scoped>
.step-dot {
    @apply inline-flex items-center justify-center w-5 h-5 rounded-full bg-primary-500/20 text-primary-300 text-xs font-bold;
}
.method-card {
    @apply w-full p-4 rounded-xl border border-white/10 bg-white/5 text-sm transition-all hover:border-white/25;
}
.method-card--active {
    @apply border-primary-500 bg-primary-500/10 ring-1 ring-primary-500/40;
}
.radio-dot {
    @apply w-4 h-4 rounded-full border-2 border-white/30 shrink-0;
}
.radio-dot--on {
    @apply border-primary-400 bg-primary-400;
}
.card-logo {
    @apply h-6 w-auto rounded shadow-sm;
}
.amount-chip {
    @apply px-3.5 py-1.5 rounded-lg border border-white/10 bg-white/5 text-sm text-gray-200 transition-all hover:border-primary-500/50;
}
.amount-chip--active {
    @apply border-primary-500 bg-primary-500/15 text-primary-300;
}
</style>
