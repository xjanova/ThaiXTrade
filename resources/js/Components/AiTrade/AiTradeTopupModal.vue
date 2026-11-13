<script setup>
/**
 * TPIX TRADE — เติมเครดิตบอท AI TRADE ด้วยการโอน TPIX บนเชน
 *
 * ขั้นตอน: เลือกแพ็ก → เซิร์ฟเวอร์บอกยอด/กระเป๋ารับเงิน/เชน → โอน TPIX จากกระเป๋าที่เชื่อมอยู่
 * → ส่ง tx hash ให้เซิร์ฟเวอร์ตรวจบนเชน → เครดิตเข้า
 *
 * ⚠️ หน้านี้ไม่ได้ตัดสินอะไรเรื่องเงินเลย — ยอด ผู้รับ และการลงเครดิต เซิร์ฟเวอร์เป็นคนตรวจจากเชน
 *    หน้าที่ของหน้านี้คือ "ไม่ให้ผู้ใช้โอนผิด" (ผิดเชน · ผิดบัญชี · กดซ้ำ) และ "ไม่ทำ tx hash หาย"
 *    จึงจำ tx hash ที่ส่งออกไปแล้วไว้ใน localStorage จนกว่าเซิร์ฟเวอร์จะตอบผลสุดท้าย
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useAiBot, readPendingTopup, writePendingTopup } from '@/Composables/useAiBot';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** แพ็กจากแคตตาล็อก: { code, credits, bonus, price_tpix } */
    pack: { type: Object, default: null },
});

const emit = defineEmits(['close', 'credited']);

const { t } = useTranslation();
const bot = useAiBot();
const walletStore = useWalletStore();

/** ถามเชนซ้ำได้กี่รอบก่อนให้ผู้ใช้กดตรวจเอง (รอบละ ~5 วิ ตามที่เซิร์ฟเวอร์บอก) */
const MAX_POLLS = 12;

/*
 * รหัสที่เซิร์ฟเวอร์ "ตัดสินแล้ว" ว่าธุรกรรมนี้เติมแพ็กนี้ไม่ได้ — เลิกจำ tx hash ได้
 *
 * ต้องเป็นรายการที่ระบุชัด ไม่ใช่ "อะไรก็ตามที่ไม่สำเร็จ": เน็ตหลุด · โควตาเต็ม · ต้องเซ็นยืนยันกระเป๋าใหม่
 * · ระบบปิดเติมชั่วคราว ล้วนเป็นข้อผิดพลาดที่ธุรกรรมยังใช้ได้ ทิ้ง hash ตอนนั้นคือทำเงินของผู้ใช้หาย
 */
const FINAL_CODES = ['TOPUP_TX_FAILED', 'TOPUP_WRONG_SENDER', 'TOPUP_WRONG_RECIPIENT', 'TOPUP_WRONG_AMOUNT', 'TOPUP_TX_ALREADY_USED'];

// loading → ready → switching → signing → verifying → success | pending | rejected · closed = เติมไม่ได้ตอนนี้
const phase = ref('loading');
const intent = ref(null);
const txHash = ref('');
const notice = ref(null);
const result = ref(null);
const manualHash = ref('');
const showManual = ref(false);
const copied = ref(false);

let runId = 0;        // เปลี่ยนเมื่อปิด/เปิดใหม่ — งานค้างของรอบก่อนต้องไม่มาเขียนทับ
let busy = false;     // กันกดซ้ำ (ตัวแปรธรรมดา ไม่รอ re-render)
let copyTimer = null;

/*
 * มีคำขอโอนค้างอยู่ในกระเป๋า (ยังไม่กดยืนยัน/ปฏิเสธ) — ไม่ถูกล้างตอนปิด/เปิดหน้าต่างใหม่
 *
 * หน้าต่างปิดได้เสมอ (กระเป๋าบางตัวปิดป๊อปอัพแล้วไม่ตอบกลับเลย ถ้าล็อกหน้าต่างไว้ผู้ใช้จะติดอยู่ตรงนั้น)
 * แต่ถ้าปิดแล้วเปิดใหม่ขณะคำขอเดิมยังค้าง การกดจ่ายอีกครั้งคือคำขอที่สอง — ยืนยันทั้งคู่ = จ่ายสองรอบ
 */
let walletRequestOpen = false;

const isBusy = computed(() => ['loading', 'switching', 'signing', 'verifying'].includes(phase.value));

const manualValid = computed(() => /^0x[a-fA-F0-9]{64}$/.test(manualHash.value.trim()));

const credits = computed(() => Number(intent.value?.credits ?? ((props.pack?.credits ?? 0) + (props.pack?.bonus ?? 0))));
const amount = computed(() => intent.value?.amount_tpix ?? String(props.pack?.price_tpix ?? ''));

function sameAddress(a, b) {
    return !!a && !!b && String(a).toLowerCase() === String(b).toLowerCase();
}

function setNotice(type, text) {
    notice.value = { type, text };
}

watch(() => [props.show, props.pack?.code], ([open]) => {
    runId++;
    busy = false;

    if (!open || !props.pack) return;

    notice.value = null;
    result.value = null;
    intent.value = null;
    txHash.value = '';
    manualHash.value = '';
    showManual.value = false;

    prepare(runId);
}, { immediate: true });

onBeforeUnmount(() => {
    runId++;
    clearTimeout(copyTimer);
});

/** ขอใบแจ้งชำระ แล้วถ้ามีธุรกรรมของแพ็กนี้ค้างยืนยันอยู่ก็ตรวจต่อเลย */
async function prepare(run) {
    phase.value = 'loading';

    const response = await bot.requestTopup(props.pack.code);
    if (run !== runId) return;

    // ไม่ได้ใบแจ้งชำระที่ใช้ได้ (ปิดรับเติม · เซิร์ฟเวอร์ตอบไม่ครบ) = ไม่มีปุ่มจ่าย ห้ามเดากระเป๋ารับเงินเอง
    if (!response.ok || !response.data?.pay_to || !response.data?.amount_wei) {
        phase.value = 'closed';
        setNotice('error', response.ok ? t('aiTrade.errGeneric') : response.error.message);
        return;
    }

    intent.value = response.data;
    phase.value = 'ready';

    const pending = readPendingTopup(walletStore.address);
    if (pending && pending.pack === props.pack.code) {
        txHash.value = pending.hash;
        verify(run);
    }
}

/** ปิดได้ทุกสถานะ — tx hash ที่ได้กลับมาหลังปิดยังถูกจำไว้ (ดู pay) และหน้า /ai-trade จะชวนให้ตรวจต่อ */
function close() {
    emit('close');
}

function copyAddress() {
    if (!intent.value?.pay_to) return;

    navigator.clipboard?.writeText(intent.value.pay_to);
    copied.value = true;
    clearTimeout(copyTimer);
    copyTimer = setTimeout(() => { copied.value = false; }, 1800);
}

/**
 * โอน TPIX จากกระเป๋าที่เชื่อมอยู่.
 *
 * ทุกทางออกที่ "ยังไม่ได้ส่งธุรกรรม" ต้องบอกชัดว่ายังไม่มีการหักเงิน และกลับไปสถานะพร้อมกดใหม่
 */
async function pay() {
    if (busy || phase.value !== 'ready' || !intent.value) return;

    if (walletRequestOpen) {
        setNotice('error', t('aiTrade.topup.walletBusy'));
        return;
    }

    busy = true;
    const run = runId;
    const owner = walletStore.address;
    const packCode = props.pack.code;
    notice.value = null;

    try {
        // 1) ต้องอยู่บนเชนที่รับชำระ — โอนจากเชนอื่นเงินไปไม่ถึง
        if (Number(walletStore.chainId) !== Number(intent.value.chain_id)) {
            phase.value = 'switching';

            try {
                await walletStore.switchChain(intent.value.chain_id);
            } catch {
                if (run !== runId) return;
                phase.value = 'ready';
                setNotice('error', t('aiTrade.topup.wrongChain', { chain: intent.value.chain_id }));
                return;
            }

            if (run !== runId) return;

            if (Number(walletStore.chainId) !== Number(intent.value.chain_id)) {
                phase.value = 'ready';
                setNotice('error', t('aiTrade.topup.wrongChain', { chain: intent.value.chain_id }));
                return;
            }
        }

        // 2) สลับกระเป๋าระหว่างทาง — ใบแจ้งชำระนี้เป็นของกระเป๋าเดิม
        if (!sameAddress(walletStore.address, owner)) {
            phase.value = 'ready';
            setNotice('error', t('aiTrade.errWalletChanged'));
            return;
        }

        const signer = walletStore.signer;
        if (!signer) {
            phase.value = 'ready';
            setNotice('error', t('aiTrade.topup.needSigner'));
            return;
        }

        /*
         * 3) บัญชีที่จะเซ็นต้องเป็นใบเดียวกับที่เชื่อม (และยืนยันกับเซิร์ฟเวอร์) ไว้
         *
         * ผู้ใช้สลับบัญชีใน MetaMask ได้โดยที่หน้าเว็บยังโชว์ใบเดิม — โอนจากอีกบัญชี
         * เซิร์ฟเวอร์จะปฏิเสธ (ผู้ส่งไม่ตรง) = เงินออกไปแล้วแต่เครดิตไม่เข้า ต้องกันก่อนส่ง
         */
        let signerAddress = null;
        try {
            signerAddress = await signer.getAddress();
        } catch {
            signerAddress = null;
        }
        if (run !== runId) return;

        if (!sameAddress(signerAddress, owner)) {
            phase.value = 'ready';
            setNotice('error', t('aiTrade.topup.accountMismatch'));
            return;
        }

        // 4) ส่งธุรกรรม — ยอดเป็น wei จากเซิร์ฟเวอร์ ไม่คำนวณเองฝั่งหน้าเว็บ
        phase.value = 'signing';
        let tx;
        const payTo = intent.value.pay_to;
        const amountTpix = String(intent.value.amount_tpix);
        const amountWei = BigInt(intent.value.amount_wei);

        walletRequestOpen = true;
        try {
            tx = walletStore.walletType === 'tpix_wallet'
                ? await walletStore.sendTPIX(payTo, amountTpix)
                : await signer.sendTransaction({ to: payTo, value: amountWei });
        } catch (err) {
            if (run !== runId) return;
            phase.value = 'ready';
            setNotice('error', sendErrorText(err));
            return;
        } finally {
            walletRequestOpen = false;
        }

        if (!tx?.hash) {
            if (run !== runId) return;
            phase.value = 'ready';
            setNotice('error', t('aiTrade.topup.sendFailed'));
            return;
        }

        // เงินออกแล้ว — จำ hash ก่อนทำอย่างอื่น แม้หน้าต่างถูกปิดไปแล้วก็ต้องจำ
        writePendingTopup({ address: owner, pack: packCode, hash: tx.hash });

        /*
         * หน้าต่างถูกปิด (หรือเปลี่ยนแพ็ก) ระหว่างรอกระเป๋า — hash ถูกจำไว้แล้ว หน้า /ai-trade ชวนให้ตรวจต่อ
         * แต่ถ้าหน้าต่างของแพ็กเดิมถูกเปิดกลับมารออยู่ ให้ตรวจต่อในหน้าต่างนั้นเลย ไม่ปล่อยให้ชวนจ่ายซ้ำ
         */
        if (!props.show || props.pack?.code !== packCode || !sameAddress(walletStore.address, owner)) return;

        txHash.value = tx.hash;
        await verify(runId);
    } finally {
        busy = false;
    }
}

/** ข้อผิดพลาดตอนส่งธุรกรรม → ข้อความที่ผู้ใช้อ่านรู้เรื่อง (ไม่โชว์ข้อความดิบของกระเป๋า/RPC) */
function sendErrorText(err) {
    const code = err?.code ?? err?.info?.error?.code;
    const text = String(err?.shortMessage || err?.message || '').toLowerCase();

    if (code === 4001 || code === 'ACTION_REJECTED' || text.includes('user rejected') || text.includes('user denied')) {
        return t('aiTrade.topup.cancelled');
    }
    if (code === 'INSUFFICIENT_FUNDS' || text.includes('insufficient')) {
        return t('aiTrade.topup.insufficient');
    }

    return t('aiTrade.topup.sendFailed');
}

/** ให้เซิร์ฟเวอร์ตรวจธุรกรรมบนเชน — เชนยังไม่ยืนยันก็ถามซ้ำเป็นระยะ แล้วค่อยให้ผู้ใช้กดเอง */
async function verify(run = runId) {
    const hash = txHash.value;
    // กำลังตรวจอยู่แล้ว = กดซ้ำ ไม่เริ่มวงถามซ้อนอีกวง
    if (!hash || !props.pack || phase.value === 'verifying') return;

    phase.value = 'verifying';
    notice.value = null;

    for (let attempt = 0; attempt < MAX_POLLS; attempt++) {
        const response = await bot.confirmTopup(props.pack.code, hash);
        if (run !== runId) return;

        if (!response.ok) {
            // ปฏิเสธถาวร (ผู้ส่ง/ผู้รับ/ยอดไม่ตรง · ธุรกรรมล้ม · ใช้ไปแล้ว) — ไม่ต้องจำ hash นี้อีก
            if (FINAL_CODES.includes(response.error.code)) {
                writePendingTopup(null);
                phase.value = 'rejected';
                setNotice('error', response.error.message);
                return;
            }

            // อย่างอื่นทั้งหมด = ยังไม่รู้ผล (เน็ต · โควตา · สลับกระเป๋า · ต้องยืนยันกระเป๋าใหม่) เก็บ hash ไว้ลองใหม่
            phase.value = 'pending';
            setNotice('error', response.error.message);
            return;
        }

        if (response.data?.status === 'credited') {
            writePendingTopup(null);
            result.value = response.data;
            phase.value = 'success';
            bot.loadStatus({ force: true });
            emit('credited', response.data);
            return;
        }

        const wait = Math.min(15, Math.max(2, Number(response.data?.retry_after_seconds) || 5));
        await new Promise((resolve) => { setTimeout(resolve, wait * 1000); });
        if (run !== runId) return;
    }

    phase.value = 'pending';
}

/** ผู้ใช้โอนเองจากแอปกระเป๋าแล้ว (หรือ hash หายจากเครื่องนี้) — ยืนยันด้วยการวาง hash */
function confirmManual() {
    if (busy || isBusy.value || !manualValid.value || !intent.value) return;

    const hash = manualHash.value.trim().toLowerCase();
    writePendingTopup({ address: walletStore.address, pack: props.pack.code, hash });
    txHash.value = hash;
    verify(runId);
}

/** ยังไม่ได้โอนจริง (หรือวาง hash ผิด) — ล้างรายการค้างแล้วเริ่มใหม่ */
function forget() {
    if (isBusy.value) return;

    writePendingTopup(null);
    txHash.value = '';
    notice.value = null;
    phase.value = intent.value ? 'ready' : 'closed';
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show && pack" class="fixed inset-0 z-[100] flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="close"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-white/10 bg-dark-900/95 backdrop-blur-xl shadow-2xl p-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-3 mb-1">
                    <h3 class="text-base font-bold text-white">{{ t('aiTrade.topup.title') }}</h3>
                    <button
                        type="button"
                        class="text-dark-500 hover:text-white transition-colors"
                        :aria-label="t('aiTrade.close')"
                        @click="close"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <p class="text-[11px] text-dark-400 mb-4">{{ t('aiTrade.topup.intro') }}</p>

                <!-- สรุปแพ็ก — ตัวเลขจากเซิร์ฟเวอร์เมื่อได้ใบแจ้งชำระแล้ว -->
                <div class="rounded-xl bg-white/5 border border-white/10 p-3 space-y-1.5 mb-3.5">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-dark-400">{{ t('aiTrade.topup.youGet') }}</span>
                        <span class="text-white font-mono font-semibold">{{ credits.toLocaleString() }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-dark-400">{{ t('aiTrade.topup.amount') }}</span>
                        <span class="text-white font-mono font-semibold">{{ Number(amount).toLocaleString() }} TPIX</span>
                    </div>
                    <template v-if="intent">
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="text-dark-400">{{ t('aiTrade.topup.network') }}</span>
                            <span class="text-dark-200 font-mono">TPIX Chain ({{ intent.chain_id }})</span>
                        </div>
                        <div class="pt-1">
                            <p class="text-[11px] text-dark-400 mb-1">{{ t('aiTrade.topup.payTo') }}</p>
                            <div class="flex items-center gap-2 rounded-lg bg-dark-800/70 border border-dark-700 px-2.5 py-2">
                                <code class="flex-1 min-w-0 text-[11px] text-white font-mono break-all">{{ intent.pay_to }}</code>
                                <button type="button" class="shrink-0 text-[10px] px-2 py-1 rounded bg-white/5 hover:bg-white/10 text-dark-200 transition-colors" @click="copyAddress">
                                    {{ copied ? t('aiTrade.topup.copied') : t('aiTrade.topup.copy') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- สถานะระหว่างทำรายการ -->
                <p v-if="phase === 'loading'" class="flex items-center gap-2 text-[12px] text-dark-300 mb-3">
                    <span class="spinner !w-3.5 !h-3.5"></span>{{ t('aiTrade.topup.loading') }}
                </p>
                <p v-else-if="phase === 'switching'" class="flex items-center gap-2 text-[12px] text-primary-300 mb-3">
                    <span class="spinner !w-3.5 !h-3.5"></span>{{ t('aiTrade.topup.switching') }}
                </p>
                <p v-else-if="phase === 'signing'" class="flex items-center gap-2 text-[12px] text-primary-300 mb-3">
                    <span class="spinner !w-3.5 !h-3.5"></span>{{ t('aiTrade.topup.signing') }}
                </p>
                <p v-else-if="phase === 'verifying'" class="flex items-center gap-2 text-[12px] text-primary-300 mb-3">
                    <span class="spinner !w-3.5 !h-3.5"></span>{{ t('aiTrade.topup.verifying') }}
                </p>

                <!-- สำเร็จ -->
                <div v-if="phase === 'success' && result" class="rounded-xl bg-trading-green/10 border border-trading-green/25 p-3.5 text-[12px] text-trading-green mb-3">
                    {{ t('aiTrade.topup.success', {
                        credited: Number(result.credited).toLocaleString(),
                        balance: Number(result.balance).toLocaleString(),
                    }) }}
                </div>

                <!-- เชนยังไม่ยืนยัน — เงินไม่หาย กดตรวจซ้ำได้ -->
                <div v-if="phase === 'pending'" class="rounded-xl bg-amber-500/10 border border-amber-500/25 p-3.5 text-[12px] text-amber-200 mb-3">
                    {{ t('aiTrade.topup.pending') }}
                </div>

                <!-- ข้อความแจ้ง (ยกเลิก · ผิดเชน · ถูกปฏิเสธ ฯลฯ) -->
                <p
                    v-if="notice"
                    :class="['text-[12px] rounded-xl px-3 py-2.5 mb-3 leading-relaxed',
                        notice.type === 'error' ? 'bg-trading-red/10 text-trading-red' : 'bg-white/5 text-dark-200']"
                    role="alert"
                >
                    {{ notice.text }}
                </p>

                <p v-if="txHash" class="text-[10px] text-dark-500 font-mono break-all mb-3">
                    {{ t('aiTrade.topup.txLabel') }}: {{ txHash }}
                </p>

                <!-- ปุ่มหลักตามสถานะ -->
                <button
                    v-if="phase === 'ready'"
                    type="button"
                    class="w-full btn-brand py-2.5 text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                    :disabled="isBusy"
                    @click="pay"
                >
                    {{ t('aiTrade.topup.pay', { amount: Number(amount).toLocaleString() }) }}
                </button>

                <div v-else-if="phase === 'pending'" class="space-y-2">
                    <button type="button" class="w-full btn-brand py-2.5 text-sm" @click="verify()">
                        {{ t('aiTrade.topup.checkAgain') }}
                    </button>
                    <button type="button" class="w-full text-[11px] text-dark-400 hover:text-white transition-colors" @click="forget">
                        {{ t('aiTrade.topup.forget') }}
                    </button>
                </div>

                <button
                    v-else-if="phase === 'success' || phase === 'rejected' || phase === 'closed'"
                    type="button"
                    class="w-full btn-secondary py-2.5 text-sm"
                    @click="close"
                >
                    {{ phase === 'success' ? t('aiTrade.topup.done') : t('aiTrade.close') }}
                </button>

                <!-- โอนไปแล้วจากที่อื่น / hash หาย — ยืนยันด้วยการวาง hash -->
                <div v-if="phase === 'ready'" class="mt-3">
                    <button type="button" class="text-[11px] text-primary-300 hover:text-primary-200 transition-colors" @click="showManual = !showManual">
                        {{ showManual ? '▾' : '▸' }} {{ t('aiTrade.topup.haveHash') }}
                    </button>
                    <div v-if="showManual" class="mt-2 space-y-2">
                        <input
                            v-model="manualHash"
                            type="text"
                            placeholder="0x..."
                            spellcheck="false"
                            autocomplete="off"
                            class="w-full bg-dark-800/70 border border-dark-700 rounded-lg px-2.5 py-2 text-white text-[11px] font-mono placeholder-dark-500 focus:border-primary-500 outline-none transition-colors"
                        >
                        <p v-if="manualHash && !manualValid" class="text-[10px] text-trading-red">{{ t('aiTrade.topup.invalidHash') }}</p>
                        <button
                            type="button"
                            class="w-full btn-secondary py-2 text-xs disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="!manualValid || isBusy"
                            @click="confirmManual"
                        >
                            {{ t('aiTrade.topup.confirmHash') }}
                        </button>
                    </div>
                </div>

                <p class="mt-3 text-[9px] text-dark-500 leading-relaxed">{{ t('aiTrade.topup.footnote') }}</p>
            </div>
        </div>
    </Teleport>
</template>
