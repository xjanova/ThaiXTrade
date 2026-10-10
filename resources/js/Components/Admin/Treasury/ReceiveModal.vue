<script setup>
/**
 * TPIX TRADE - รับเงินเข้ากระเป๋าคลัง
 * ที่อยู่ + QR + ปุ่มคัดลอก — QR เป็น data: URI สร้างในเบราว์เซอร์ (CSP img-src อนุญาต data:)
 * Developed by Xman Studio
 */
import { ref, watch } from 'vue';
import QRCode from 'qrcode';
import Modal from '@/Components/Admin/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    wallet: { type: Object, default: null },
    explorerUrl: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const qr = ref('');
const copied = ref(false);

// แยก source ทีละตัว — หน้าแม่รีเฟรชยอดทุก 30 วิ (object ใหม่ ที่อยู่เดิม) ไม่ต้องสร้าง QR ใหม่
watch(
    [() => props.show, () => props.wallet?.address],
    async ([open, address]) => {
        qr.value = '';
        copied.value = false;
        if (!open || !address) return;
        try {
            const url = await QRCode.toDataURL(address, { margin: 1, width: 240 });
            // เปิดกระเป๋าอื่นระหว่างรอ → ทิ้ง QR ของใบเก่า
            if (props.wallet?.address === address) qr.value = url;
        } catch { /* ไม่มี QR ก็ยังคัดลอกที่อยู่ได้ */ }
    },
    { immediate: true },
);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.wallet.address);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch { /* คลิปบอร์ดใช้ไม่ได้ ผู้ใช้ยังเลือกข้อความเองได้ */ }
}
</script>

<template>
    <Modal :show="show" :title="wallet ? `รับเข้า — ${wallet.role_th}` : ''" max-width="md" @close="emit('close')">
        <div v-if="wallet" class="space-y-4 text-center">
            <div class="mx-auto w-[240px] h-[240px] rounded-xl bg-white p-2 flex items-center justify-center">
                <img v-if="qr" :src="qr" :alt="wallet.address" class="w-full h-full" />
                <span v-else class="text-xs text-gray-500">กำลังสร้าง QR…</span>
            </div>

            <button type="button" @click="copy"
                    class="w-full font-mono text-sm break-all px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-gray-200 hover:border-primary-500/40 transition-colors">
                {{ wallet.address }}
            </button>
            <p class="text-xs" :class="copied ? 'text-emerald-400' : 'text-gray-500'">
                {{ copied ? 'คัดลอกที่อยู่แล้ว' : 'แตะที่อยู่เพื่อคัดลอก' }}
            </p>

            <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-left text-xs text-amber-200 leading-relaxed">
                รับได้เฉพาะ <b>TPIX หรือโทเคนบนเชน TPIX (Chain ID 4289)</b> เท่านั้น
                ถ้าโอนมาจากเชนอื่น (BSC, Polygon ฯลฯ) เงินจะไปอยู่บนเชนนั้น ไม่เข้ากระเป๋านี้
            </div>

            <a v-if="explorerUrl" :href="`${explorerUrl}/address/${wallet.address}`" target="_blank" rel="noopener"
               class="inline-block text-xs text-gray-500 hover:text-primary-400 transition-colors">
                ดูรายการเข้าออกบน explorer &#8599;
            </a>
        </div>
    </Modal>
</template>
