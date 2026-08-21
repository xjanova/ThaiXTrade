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

const canSubmit = computed(() => {
    return (
        walletStore.isConnected &&
        isOnReceiveChain.value &&
        currentPhase.value &&
        !isSoldOut.value &&
        !exceedsRemaining.value &&
        !phaseClosedReason.value &&
        parseFloat(paymentAmount.value) > 0 &&
        preview.value &&
        !isSubmittingOrder.value &&
        methods.value[method.value]
    );
});

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
                <img src="/tpixlogo.webp" alt="TPIX Chain" class="w-9 h-9 object-contain" />
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
                <!-- เลือกวิธีชำระเงิน -->
                <div class="mb-4">
                    <label class="block text-sm text-gray-400 mb-2">ชำระด้วย</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-if="methods.card"
                            class="px-4 py-3 rounded-lg border transition-all text-sm font-medium"
                            :class="method === 'card'
                                ? 'border-primary-500 bg-primary-500/10 text-primary-400'
                                : 'border-white/10 bg-white/5 text-gray-300 hover:border-white/20'"
                            @click="method = 'card'"
                        >
                            บัตรเครดิต / เดบิต
                        </button>
                        <button
                            v-if="methods.bank"
                            class="px-4 py-3 rounded-lg border transition-all text-sm font-medium"
                            :class="method === 'bank'
                                ? 'border-primary-500 bg-primary-500/10 text-primary-400'
                                : 'border-white/10 bg-white/5 text-gray-300 hover:border-white/20'"
                            @click="method = 'bank'"
                        >
                            โอนเงินเข้าบัญชี
                        </button>
                    </div>
                </div>

                <!-- จำนวนเงิน -->
                <div class="mb-4">
                    <label class="block text-sm text-gray-400 mb-2">จำนวนเงิน (USD)</label>
                    <div class="relative">
                        <input
                            v-model="paymentAmount"
                            type="number"
                            step="any"
                            min="0"
                            placeholder="0.00"
                            class="trading-input w-full pr-16"
                        />
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">USD</span>
                    </div>
                </div>

                <!-- จำนวนเหรียญที่จะได้ -->
                <div v-if="preview" class="mb-4 p-4 rounded-lg bg-white/5 border border-white/10">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-400">จะได้รับ</span>
                        <span class="text-white font-semibold">{{ formatNumber(preview.tpix_amount) }} TPIX</span>
                    </div>
                    <div class="flex justify-between text-xs text-gray-500">
                        <span>ส่งไปที่</span>
                        <span class="font-mono">{{ walletStore.shortAddress }} · TPIX Chain</span>
                    </div>
                </div>

                <div v-else-if="isLoadingPreview" class="mb-4 text-sm text-gray-400">กำลังคำนวณ…</div>

                <p v-if="exceedsRemaining" class="mb-3 text-sm text-yellow-400">
                    จำนวนนี้เกินโควตาที่เหลือของเฟสนี้
                </p>

                <p v-if="error" class="mb-3 text-sm text-trading-red">{{ error }}</p>

                <button
                    class="w-full py-3 rounded-xl font-semibold transition-all disabled:opacity-40 disabled:cursor-not-allowed bg-gradient-to-r from-primary-500 to-accent-500 text-white hover:shadow-lg hover:shadow-primary-500/20"
                    :disabled="!canSubmit"
                    @click="submitOrder"
                >
                    <span v-if="isSubmittingOrder">กำลังดำเนินการ…</span>
                    <span v-else-if="method === 'card'">ชำระด้วยบัตร</span>
                    <span v-else>รับเลขบัญชีเพื่อโอนเงิน</span>
                </button>

                <p class="mt-3 text-xs text-gray-500 text-center">
                    เหรียญจะถูกส่งเป็น TPIX เนทีฟบน TPIX Chain ตามตารางปลดล็อกของเฟสนี้
                </p>
            </template>
        </div>
    </div>
</template>
