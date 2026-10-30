<script setup>
/**
 * SaleCryptoPanel — กล่อง "ชำระด้วย USDT" บนหน้า /admin/token-sales
 *
 * การชำระที่ยอดเป๊ะและทันเวลา ระบบออกเหรียญเองไม่ต้องมีใครกด
 * กล่องนี้มีไว้สองเรื่อง:
 *   1. บอกว่าทางคริปโตเปิดรับอยู่จริงไหม และติดตรงไหน (ติดตั้ง · ประกาศรับ · กระเป๋า · ตัวสแกน)
 *   2. เงินที่เข้ามาแต่ระบบตัดสินเองไม่ได้ — ทีมงานเลือก "ออกเหรียญตามยอดที่ได้รับ" หรือ "บันทึกว่าคืนเงินแล้ว"
 *
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    crypto: { type: Object, default: () => ({}) },
    // ปุ่มทั้งสองขยับเงินจริง route รับเฉพาะ super_admin ระดับอื่นเปิดดูรายการได้อย่างเดียว
    canManage: { type: Boolean, default: false },
});

const review = computed(() => props.crypto?.review || []);
const chains = computed(() => props.crypto?.chains || []);

const REASONS = {
    underpaid: 'โอนขาด',
    overpaid: 'โอนเกิน',
    late: 'โอนหลังราคาที่ล็อกหมดอายุ',
    duplicate_payment: 'จ่ายซ้ำ (คำสั่งซื้อนี้จ่ายแล้ว)',
    wrong_token: 'โอนผิดเหรียญ (ไม่ใช่ USDT)',
    unmatched: 'หาคำสั่งซื้อไม่เจอ',
    insufficient_allocation: 'โควตาเฟสไม่พอ',
    exceeds_wallet_allocation: 'เกินเพดานต่อกระเป๋า',
};

const PROBLEMS = {
    not_installed: 'ยังไม่ติดตั้ง (SALE_CRYPTO_ENABLED=false)',
    not_set: 'ยังไม่ได้ตั้งกระเป๋ารับเงิน (sale_wallet_address)',
    internal_address: 'กระเป๋ารับเงินเป็นกระเป๋าของระบบ (vault สะพาน/กระเป๋าที่เซิร์ฟเวอร์ถือคีย์) — ใช้ไม่ได้',
    key_not_proven: 'กระเป๋ารับเงินยังไม่เคยส่งธุรกรรมออก — ส่ง 0 ให้ตัวเองหนึ่งครั้งเพื่อพิสูจน์ว่าถือคีย์',
};

const busyId = ref(null);

function canCredit(p) {
    return p.order && p.reason !== 'wrong_token';
}

function credit(p) {
    if (busyId.value) return;
    const msg = `ออกเหรียญตามยอดที่ได้รับจริง ${p.amount} ${p.token_symbol}\n`
        + `ในราคาที่คำสั่งซื้อ ${p.order.reference} ล็อกไว้ ($${p.order.price_per_tpix}/TPIX)?\n\n`
        + 'ตรวจในเบราว์เซอร์เชนก่อนว่าเงินเข้ากระเป๋ารับเงินจริง';
    if (!window.confirm(msg)) return;

    busyId.value = p.id;
    router.post(`/admin/token-sales/crypto/${p.id}/credit`, {}, {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
}

function resolve(p) {
    if (busyId.value) return;
    const note = window.prompt('บันทึกว่าจัดการอย่างไร (เช่น คืนเงินแล้ว tx 0x…)', 'คืนเงินแล้ว tx ');
    if (!note) return;

    busyId.value = p.id;
    router.post(`/admin/token-sales/crypto/${p.id}/resolve`, { note }, {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
}

function short(addr) {
    return addr ? `${addr.slice(0, 8)}…${addr.slice(-6)}` : '—';
}
</script>

<template>
    <div class="glass-dark rounded-xl border border-white/10 p-5 mb-4" data-testid="sale-crypto-panel">
        <h3 class="text-lg font-bold text-white flex items-center gap-2 mb-1">
            <span>🪙</span> ชำระด้วย USDT
        </h3>
        <p class="text-xs text-gray-400 mb-4">
            ยอดเป๊ะและทันเวลา = ระบบออกเหรียญเอง · รายการด้านล่างคือเงินที่เข้ามาแต่ต้องให้คนตัดสิน
        </p>

        <div class="grid gap-2 sm:grid-cols-2 text-sm mb-4">
            <div class="p-3 rounded-lg bg-white/5">
                <p class="text-gray-400 text-xs mb-1">สถานะ</p>
                <p v-if="crypto.receive_problem" class="text-yellow-300">{{ PROBLEMS[crypto.receive_problem] || crypto.receive_problem }}</p>
                <p v-else-if="!crypto.accepted" class="text-yellow-300">รอบขายยังไม่เปิดรับ — แก้รอบขายแล้วติ๊ก "USDT ตรวจเงินเข้าบนเชน"</p>
                <p v-else class="text-trading-green">เปิดรับอยู่</p>
            </div>
            <div class="p-3 rounded-lg bg-white/5">
                <p class="text-gray-400 text-xs mb-1">กระเป๋ารับเงิน · ตัวสแกนรายเชน</p>
                <p class="font-mono text-gray-200 text-xs break-all">{{ crypto.receive_address || '—' }}</p>
                <p class="text-xs mt-1">
                    <span v-for="c in chains" :key="c.chain_id" class="mr-3" :class="c.scan_ok ? 'text-trading-green' : 'text-gray-500'">
                        {{ c.scan_ok ? '●' : '○' }} {{ c.name }}
                    </span>
                </p>
            </div>
        </div>

        <p v-if="!review.length" class="text-sm text-gray-500">ไม่มีรายการรอตัดสิน</p>

        <div v-else class="space-y-2">
            <p v-if="!canManage" class="text-xs text-amber-300">ออกเหรียญหรือปิดรายการได้เฉพาะ Super Admin</p>
            <div v-for="p in review" :key="p.id" class="p-3 rounded-lg bg-yellow-500/5 border border-yellow-500/20 text-sm" data-testid="crypto-review-row">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-yellow-300 font-semibold">{{ REASONS[p.reason] || p.reason }}</span>
                    <span class="text-white">{{ p.amount }} {{ p.token_symbol }} · {{ p.chain }}</span>
                </div>
                <div class="text-xs text-gray-400 mt-1 space-x-3">
                    <span>จาก <span class="font-mono">{{ short(p.from) }}</span></span>
                    <a v-if="p.explorer" :href="`${p.explorer}/tx/${p.tx_hash}`" target="_blank" rel="noopener" class="text-primary-300 hover:underline font-mono">{{ short(p.tx_hash) }}</a>
                </div>
                <div v-if="p.order" class="text-xs text-gray-400 mt-1">
                    คำสั่งซื้อ <code class="text-primary-300">{{ p.order.reference }}</code>
                    · ต้องโอน {{ p.order.pay_amount }} · ${{ p.order.amount_usd }} = {{ p.order.tpix_amount }} TPIX
                    · กระเป๋า <span class="font-mono">{{ short(p.order.wallet) }}</span>
                </div>
                <div v-if="canManage" class="flex gap-2 mt-2">
                    <button v-if="canCredit(p)" class="text-xs px-3 py-1.5 rounded-lg bg-trading-green/20 text-trading-green hover:bg-trading-green/30 disabled:opacity-40"
                        :disabled="busyId === p.id" @click="credit(p)">ออกเหรียญตามยอดที่ได้รับ</button>
                    <button class="text-xs px-3 py-1.5 rounded-lg bg-white/10 text-gray-200 hover:bg-white/20 disabled:opacity-40"
                        :disabled="busyId === p.id" @click="resolve(p)">บันทึกว่าคืนเงิน/จัดการแล้ว</button>
                </div>
            </div>
        </div>
    </div>
</template>
