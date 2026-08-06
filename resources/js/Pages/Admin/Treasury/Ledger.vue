<script setup>
/**
 * TPIX TRADE - สมุดบัญชีคลัง + ตัวกระทบยอด
 * เทียบยอดจริงบนเชนกับยอดที่ระบบบันทึกไว้ ไม่ตรงต้องดัง
 * Developed by Xman Studio
 */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';

const props = defineProps({
    entries: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    reconciliation: { type: Object, default: () => ({ rows: [], all_in_sync: true }) },
    pools: { type: Array, default: () => [] },
    explorerUrl: { type: String, default: '' },
});

const recon = ref(props.reconciliation);
const isChecking = ref(false);

function fmtTpix(v) {
    if (v === null || v === undefined) return '—';
    const neg = String(v).startsWith('-');
    const [i, f] = String(v).replace('-', '').split('.');
    const withCommas = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const t = f ? f.replace(/0+$/, '').slice(0, 6) : '';
    return (neg ? '-' : '') + (t ? `${withCommas}.${t}` : withCommas);
}

const shortAddr = (a) => (a ? `${a.slice(0, 10)}…${a.slice(-8)}` : '—');
const shortHash = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-8)}` : '—');

async function recheck() {
    if (isChecking.value) return;
    isChecking.value = true;
    try {
        const { data } = await axios.get('/admin/treasury/reconcile');
        if (data.success) recon.value = data.data;
    } catch { /* ค่าเดิมยังอยู่ */ }
    finally { isChecking.value = false; }
}

function filterWallet(key) {
    router.get('/admin/treasury/ledger', key ? { wallet: key } : {}, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="สมุดบัญชีคลัง" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">สมุดบัญชีคลัง</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        เชนคือแหล่งความจริง สมุดนี้มีไว้เทียบว่าระบบรู้เห็นครบไหม
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/admin/treasury"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                        กลับหน้าคลัง
                    </Link>
                    <button @click="recheck" :disabled="isChecking"
                            class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-50">
                        {{ isChecking ? 'กำลังกระทบยอด…' : 'กระทบยอดใหม่' }}
                    </button>
                </div>
            </div>

            <!-- ผลกระทบยอด -->
            <div :class="['rounded-xl border p-5',
                          recon.all_in_sync
                            ? 'border-emerald-500/30 bg-emerald-500/5'
                            : 'border-red-500/40 bg-red-500/10']">
                <div class="flex items-start gap-3">
                    <span class="text-2xl leading-none">{{ recon.all_in_sync ? '&#10003;' : '&#9888;' }}</span>
                    <div class="flex-1">
                        <h3 :class="['font-bold', recon.all_in_sync ? 'text-emerald-300' : 'text-red-300']">
                            <template v-if="recon.all_in_sync">ยอดตรงกันทุกใบ</template>
                            <template v-else-if="recon.drift_count">
                                ยอดไม่ตรง {{ recon.drift_count }} ใบ — มีเงินเคลื่อนโดยที่ระบบไม่รู้เห็น
                            </template>
                            <template v-else>อ่านยอดไม่ได้ {{ recon.unreadable_count }} ใบ</template>
                        </h3>
                        <p class="text-sm text-gray-400 mt-1">
                            ยอดที่ควรเป็น = ยอดตอน genesis + ยอดสุทธิที่สมุดบันทึกไว้
                            <span v-if="recon.tolerance_wei === '0'">· ต้องตรงเป๊ะทุก wei</span>
                        </p>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase tracking-wider">
                            <tr>
                                <th class="text-left py-2">กระเป๋า</th>
                                <th class="text-right py-2">ยอดจริงบนเชน</th>
                                <th class="text-right py-2">ยอดที่ควรเป็น</th>
                                <th class="text-right py-2">ส่วนต่าง</th>
                                <th class="text-center py-2">ผล</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="r in recon.rows" :key="r.key">
                                <td class="py-2">
                                    <div class="text-gray-200">{{ r.role_th }}</div>
                                    <div class="font-mono text-xs text-gray-600">{{ shortAddr(r.address) }}</div>
                                </td>
                                <td class="py-2 text-right font-mono text-white">
                                    {{ r.readable ? fmtTpix(r.on_chain_tpix) : '—' }}
                                </td>
                                <td class="py-2 text-right font-mono text-gray-400">
                                    {{ r.readable ? fmtTpix(r.expected_tpix) : '—' }}
                                </td>
                                <td :class="['py-2 text-right font-mono',
                                             !r.readable ? 'text-gray-600'
                                             : r.in_sync ? 'text-gray-600' : 'text-red-400 font-bold']">
                                    {{ r.readable ? fmtTpix(r.drift_tpix) : '—' }}
                                </td>
                                <td class="py-2 text-center">
                                    <span v-if="!r.readable" class="text-xs text-gray-500">อ่านไม่ได้</span>
                                    <span v-else-if="r.in_sync" class="text-emerald-400">&#10003;</span>
                                    <span v-else class="text-red-400 font-bold">&#9888;</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ตัวกรอง -->
            <div class="flex flex-wrap gap-2">
                <button @click="filterWallet(null)"
                        :class="['px-3 py-1.5 text-xs font-medium rounded-lg border transition-all',
                                 !filters.wallet ? 'bg-primary-500/20 text-primary-400 border-primary-500/30'
                                                 : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10']">
                    ทั้งหมด
                </button>
                <button v-for="p in pools" :key="p.key" @click="filterWallet(p.key)"
                        :class="['px-3 py-1.5 text-xs font-medium rounded-lg border transition-all',
                                 filters.wallet === p.key ? 'bg-primary-500/20 text-primary-400 border-primary-500/30'
                                                          : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10']">
                    {{ p.role_th }}
                </button>
                <button @click="filterWallet('hot_wallet')"
                        :class="['px-3 py-1.5 text-xs font-medium rounded-lg border transition-all',
                                 filters.wallet === 'hot_wallet' ? 'bg-primary-500/20 text-primary-400 border-primary-500/30'
                                                                 : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10']">
                    กระเป๋าร้อน
                </button>
            </div>

            <!-- รายการเคลื่อนไหว -->
            <div class="glass-card rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-white/5 text-xs text-gray-500 uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-4 py-3">เวลา</th>
                                <th class="text-left px-4 py-3">กระเป๋า</th>
                                <th class="text-left px-4 py-3">ทิศทาง</th>
                                <th class="text-right px-4 py-3">จำนวน</th>
                                <th class="text-left px-4 py-3">ที่มา</th>
                                <th class="text-left px-4 py-3">ธุรกรรม</th>
                                <th class="text-left px-4 py-3">บันทึกโดย</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="e in entries.data" :key="e.id" class="hover:bg-white/[0.02]">
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ new Date(e.created_at).toLocaleString('th-TH') }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-300">{{ e.wallet_key }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['px-2 py-1 text-xs rounded-md border',
                                                   e.direction === 'credit'
                                                    ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                                    : 'bg-amber-500/20 text-amber-400 border-amber-500/30']">
                                        {{ e.direction === 'credit' ? 'เข้า' : 'ออก' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-white">{{ fmtTpix(e.amount_tpix) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-400">
                                    {{ e.source }}
                                    <div v-if="e.note" class="text-gray-600">{{ e.note }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <a v-if="e.tx_hash" :href="`${explorerUrl}/tx/${e.tx_hash}`" target="_blank" rel="noopener"
                                       class="font-mono text-xs text-primary-400 hover:underline">{{ shortHash(e.tx_hash) }}</a>
                                    <span v-else class="text-xs text-gray-600">—</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ e.recorder?.name ?? 'ระบบ' }}</td>
                            </tr>
                            <tr v-if="!entries.data.length">
                                <td colspan="7" class="px-4 py-12 text-center">
                                    <div class="text-gray-500">ยังไม่มีรายการเคลื่อนไหว</div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        สมุดจะเริ่มบันทึกเมื่อมีการจ่ายเงินผ่านคิว หรือบันทึกรายการมือ
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
