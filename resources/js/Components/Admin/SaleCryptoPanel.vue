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
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    crypto: { type: Object, default: () => ({}) },
    // ปุ่มทั้งสองขยับเงินจริง route รับเฉพาะ super_admin ระดับอื่นเปิดดูรายการได้อย่างเดียว
    canManage: { type: Boolean, default: false },
});

const review = computed(() => props.crypto?.review || []);
const chains = computed(() => props.crypto?.chains || []);

// รหัสเหตุผล/ปัญหาจากเซิร์ฟเวอร์ → คีย์แปล เรียก t() ตอน render เพื่อให้เปลี่ยนภาษาได้ทันที
const REASON_CODES = ['underpaid', 'overpaid', 'late', 'duplicate_payment', 'wrong_token', 'unmatched', 'insufficient_allocation', 'exceeds_wallet_allocation'];
const PROBLEM_CODES = ['not_installed', 'not_set', 'internal_address', 'key_not_proven'];

function reasonLabel(code) {
    return REASON_CODES.includes(code) ? t(`admin.saleCryptoPanel.reasons.${code}`) : code;
}

function problemLabel(code) {
    return PROBLEM_CODES.includes(code) ? t(`admin.saleCryptoPanel.problems.${code}`) : code;
}

const busyId = ref(null);

function canCredit(p) {
    return p.order && p.reason !== 'wrong_token';
}

function credit(p) {
    if (busyId.value) return;
    const msg = t('admin.saleCryptoPanel.confirmCredit', {
        amount: p.amount,
        symbol: p.token_symbol,
        reference: p.order.reference,
        price: p.order.price_per_tpix,
    });
    if (!window.confirm(msg)) return;

    busyId.value = p.id;
    router.post(`/admin/token-sales/crypto/${p.id}/credit`, {}, {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
}

function resolve(p) {
    if (busyId.value) return;
    const note = window.prompt(t('admin.saleCryptoPanel.resolvePrompt'), t('admin.saleCryptoPanel.resolveDefault'));
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
            <span>🪙</span> {{ t('admin.saleCryptoPanel.title') }}
        </h3>
        <p class="text-xs text-gray-400 mb-4">
            {{ t('admin.saleCryptoPanel.subtitle') }}
        </p>

        <div class="grid gap-2 sm:grid-cols-2 text-sm mb-4">
            <div class="p-3 rounded-lg bg-white/5">
                <p class="text-gray-400 text-xs mb-1">{{ t('admin.saleCryptoPanel.statusLabel') }}</p>
                <p v-if="crypto.receive_problem" class="text-yellow-300">{{ problemLabel(crypto.receive_problem) }}</p>
                <p v-else-if="!crypto.accepted" class="text-yellow-300">{{ t('admin.saleCryptoPanel.notAccepted') }}</p>
                <p v-else class="text-trading-green">{{ t('admin.saleCryptoPanel.accepting') }}</p>
            </div>
            <div class="p-3 rounded-lg bg-white/5">
                <p class="text-gray-400 text-xs mb-1">{{ t('admin.saleCryptoPanel.walletScanners') }}</p>
                <p class="font-mono text-gray-200 text-xs break-all">{{ crypto.receive_address || '—' }}</p>
                <p class="text-xs mt-1">
                    <span v-for="c in chains" :key="c.chain_id" class="mr-3" :class="c.scan_ok ? 'text-trading-green' : 'text-gray-500'">
                        {{ c.scan_ok ? '●' : '○' }} {{ c.name }}
                    </span>
                </p>
            </div>
        </div>

        <p v-if="!review.length" class="text-sm text-gray-500">{{ t('admin.saleCryptoPanel.noReview') }}</p>

        <div v-else class="space-y-2">
            <p v-if="!canManage" class="text-xs text-amber-300">{{ t('admin.saleCryptoPanel.superAdminOnly') }}</p>
            <div v-for="p in review" :key="p.id" class="p-3 rounded-lg bg-yellow-500/5 border border-yellow-500/20 text-sm" data-testid="crypto-review-row">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-yellow-300 font-semibold">{{ reasonLabel(p.reason) }}</span>
                    <span class="text-white">{{ p.amount }} {{ p.token_symbol }} · {{ p.chain }}</span>
                </div>
                <div class="text-xs text-gray-400 mt-1 space-x-3">
                    <span>{{ t('admin.saleCryptoPanel.from') }} <span class="font-mono">{{ short(p.from) }}</span></span>
                    <a v-if="p.explorer" :href="`${p.explorer}/tx/${p.tx_hash}`" target="_blank" rel="noopener" class="text-primary-300 hover:underline font-mono">{{ short(p.tx_hash) }}</a>
                </div>
                <div v-if="p.order" class="text-xs text-gray-400 mt-1">
                    {{ t('admin.saleCryptoPanel.order') }} <code class="text-primary-300">{{ p.order.reference }}</code>
                    · {{ t('admin.saleCryptoPanel.due', { amount: p.order.pay_amount }) }} · ${{ p.order.amount_usd }} = {{ p.order.tpix_amount }} TPIX
                    · {{ t('admin.saleCryptoPanel.wallet') }} <span class="font-mono">{{ short(p.order.wallet) }}</span>
                </div>
                <div v-if="canManage" class="flex gap-2 mt-2">
                    <button v-if="canCredit(p)" class="text-xs px-3 py-1.5 rounded-lg bg-trading-green/20 text-trading-green hover:bg-trading-green/30 disabled:opacity-40"
                        :disabled="busyId === p.id" @click="credit(p)">{{ t('admin.saleCryptoPanel.creditButton') }}</button>
                    <button class="text-xs px-3 py-1.5 rounded-lg bg-white/10 text-gray-200 hover:bg-white/20 disabled:opacity-40"
                        :disabled="busyId === p.id" @click="resolve(p)">{{ t('admin.saleCryptoPanel.resolveButton') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
