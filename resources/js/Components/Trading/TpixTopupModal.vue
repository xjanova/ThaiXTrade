<script setup>
/**
 * TPIX TRADE — เติมเครดิต TPIX เข้าคลังของเว็บ
 *
 * ทำไมต้องเติมล่วงหน้า ไม่จ่ายทีละไม้: TPIX เป็นเหรียญหลักของเชน 4289
 * แต่การเทรดอยู่บน BSC และกระเป๋าอยู่ได้ทีละเชนเดียว — ถ้าจ่ายทีละไม้ต้อง
 * สลับเชนไปกลับทุกครั้ง ใช้งานจริงไม่ไหว เติมครั้งเดียวแล้วเทรดยาวจึงเป็นทางที่เวิร์ก
 *
 * ⚠️ ยอดที่ลงเครดิตอ่านจากเชนเท่านั้น ไม่ได้เชื่อตัวเลขที่หน้าเว็บส่งไป
 *    (เซิร์ฟเวอร์ตรวจ from/to/value เองทุกครั้ง)
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTradingFee } from '@/Composables/useTradingFee';
import { showToast } from '@/Composables/useToasts';

const props = defineProps({
    show: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'credited']);

const walletStore = useWalletStore();
const fee = useTradingFee();

const txHash = ref('');
const copied = ref(false);

const info = computed(() => fee.topupInfo.value);
const wallet = computed(() => walletStore.address);

/** รูปแบบ tx hash ถูกต้องไหม — กันยิงคำขอที่รู้อยู่แล้วว่าไม่ผ่าน */
const hashValid = computed(() => /^0x[a-fA-F0-9]{64}$/.test(txHash.value.trim()));

const onRightChain = computed(() =>
    !!info.value && walletStore.chainId === info.value.chain_id
);

watch(() => props.show, (open) => {
    if (!open) return;
    txHash.value = '';
    fee.loadTiers();
    if (wallet.value) fee.loadBalance(wallet.value);
});

function copyAddress() {
    if (!info.value?.wallet) return;
    navigator.clipboard?.writeText(info.value.wallet);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1800);
}

async function switchChain() {
    if (!info.value) return;
    try {
        await walletStore.switchChain(info.value.chain_id);
    } catch (err) {
        showToast({ text: walletStore.error || err?.message || 'สลับเครือข่ายไม่สำเร็จ', type: 'error' });
    }
}

async function confirm() {
    if (!hashValid.value || !wallet.value) return;

    const result = await fee.confirmTopup(wallet.value, txHash.value.trim());

    if (!result) {
        showToast({ text: fee.error.value || 'ยืนยันการเติมเครดิตไม่สำเร็จ', type: 'error' });
        return;
    }

    showToast({
        text: `เติมเครดิตสำเร็จ ${result.credited} TPIX · ยอดคงเหลือ ${result.balance} TPIX`,
        type: 'success',
    });
    emit('credited', result);
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-white/10 bg-dark-900/95 backdrop-blur-xl shadow-2xl p-5">
                <div class="flex items-start justify-between gap-3 mb-1">
                    <h3 class="text-base font-bold text-white">เติมเครดิต TPIX</h3>
                    <button type="button" class="text-dark-500 hover:text-white transition-colors" @click="emit('close')">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <p class="text-[11px] text-dark-400 mb-4">
                    เครดิตใช้จ่ายค่าบริการวางไม้ · เติมครั้งเดียวใช้ได้หลายไม้ ไม่ต้องสลับเชนทุกครั้ง
                </p>

                <!-- ยังไม่เปิดให้เติม — บอกตรงๆ ว่าอะไรขาด ไม่ใช่ปล่อยฟอร์มที่กดแล้วพัง -->
                <div v-if="!info?.configured" class="rounded-xl bg-amber-500/10 border border-amber-500/25 p-3.5 text-sm text-amber-300">
                    ยังไม่เปิดให้เติมเครดิต — ผู้ดูแลระบบยังไม่ได้ตั้งกระเป๋ารับเงิน
                </div>

                <div v-else class="space-y-3.5">
                    <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                        <div class="flex items-center justify-between text-[11px] mb-1">
                            <span class="text-dark-400">ยอดคงเหลือในคลัง</span>
                            <span class="text-white font-mono font-semibold">{{ fee.balance.value }} TPIX</span>
                        </div>
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="text-dark-400">เติมขั้นต่ำ</span>
                            <span class="text-dark-200 font-mono">{{ info.minimum }} TPIX</span>
                        </div>
                    </div>

                    <!-- ขั้นที่ 1 -->
                    <div>
                        <p class="text-[11px] text-dark-300 mb-1.5">
                            <span class="text-primary-400 font-semibold">1.</span>
                            โอน TPIX บนเชน {{ info.chain_id }} ไปที่กระเป๋านี้
                        </p>
                        <div class="flex items-center gap-2 rounded-lg bg-dark-800/70 border border-dark-700 px-2.5 py-2">
                            <code class="flex-1 min-w-0 text-[11px] text-white font-mono break-all">{{ info.wallet }}</code>
                            <button type="button" class="shrink-0 text-[10px] px-2 py-1 rounded bg-white/5 hover:bg-white/10 text-dark-200 transition-colors" @click="copyAddress">
                                {{ copied ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                            </button>
                        </div>

                        <!-- อยู่ผิดเชนอยู่ = โอนไปก็ไม่ถึง เตือนก่อนเสียเงิน -->
                        <div v-if="wallet && !onRightChain" class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-amber-500/10 border border-amber-500/20 px-2.5 py-1.5">
                            <span class="text-[10px] text-amber-300 leading-tight">
                                กระเป๋าอยู่คนละเชน — โอนจากเชนอื่นเครดิตจะไม่เข้า
                            </span>
                            <button type="button" class="shrink-0 text-[10px] px-2 py-1 rounded bg-amber-500/20 hover:bg-amber-500/30 text-amber-200 transition-colors" @click="switchChain">
                                สลับเชน
                            </button>
                        </div>
                    </div>

                    <!-- ขั้นที่ 2 -->
                    <div>
                        <label class="block text-[11px] text-dark-300 mb-1.5">
                            <span class="text-primary-400 font-semibold">2.</span>
                            วางรหัสธุรกรรม (tx hash) ที่โอน
                        </label>
                        <input
                            v-model="txHash"
                            type="text"
                            placeholder="0x..."
                            spellcheck="false"
                            class="w-full bg-dark-800/70 border border-dark-700 rounded-lg px-2.5 py-2 text-white text-[11px] font-mono placeholder-dark-500 focus:border-primary-500 outline-none transition-colors"
                        />
                        <p v-if="txHash && !hashValid" class="mt-1 text-[10px] text-trading-red">
                            รูปแบบรหัสธุรกรรมไม่ถูกต้อง (ต้องขึ้นต้น 0x และยาว 66 ตัว)
                        </p>
                    </div>

                    <button
                        type="button"
                        :disabled="!hashValid || !wallet || fee.isWorking.value"
                        class="w-full btn-brand py-2.5 text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="confirm"
                    >
                        {{ fee.isWorking.value ? 'กำลังตรวจสอบบนเชน...' : 'ยืนยันการเติม' }}
                    </button>

                    <p class="text-[9px] text-dark-500 leading-relaxed">
                        ระบบตรวจธุรกรรมบนเชนเองทุกครั้ง — ยอดที่ลงเครดิตคือยอดที่โอนจริง
                        ไม่ใช่ตัวเลขที่กรอก · โอนแล้วยืนยันภายหลังได้ ธุรกรรมไม่หาย
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
