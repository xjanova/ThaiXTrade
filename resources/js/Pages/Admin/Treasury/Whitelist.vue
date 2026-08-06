<script setup>
/**
 * TPIX TRADE - whitelist ปลายทางของคลัง
 * คลังโอนไปได้เฉพาะที่อยู่ในรายการนี้เท่านั้น
 * Developed by Xman Studio
 */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
});

function fmtTpix(v) {
    if (v === null || v === undefined || v === '') return null;
    const [i, f] = String(v).split('.');
    const withCommas = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (!f) return withCommas;
    const t = f.replace(/0+$/, '').slice(0, 6);
    return t ? `${withCommas}.${t}` : withCommas;
}

/* wei -> TPIX ฝั่งหน้าจอ ใช้ได้เพราะเป็นแค่การแสดงผล และตัดด้วย string
 * ไม่ผ่าน Number() — ค่าระดับ 1e18 จะเพี้ยนทันทีถ้าแปลงเป็นตัวเลข JS */
function weiToTpix(wei) {
    if (!wei) return null;
    const s = String(wei).padStart(19, '0');
    const intPart = s.slice(0, -18).replace(/^0+(?=\d)/, '');
    const frac = s.slice(-18).replace(/0+$/, '');
    return frac ? `${intPart}.${frac}` : intPart;
}

const shortAddr = (a) => (a ? `${a.slice(0, 10)}…${a.slice(-8)}` : '—');

const purposeLabel = {
    masternode: 'masternode',
    token_sale: 'ขายเหรียญ',
    refund: 'คืนเงิน',
    other: 'อื่น ๆ',
};

const showForm = ref(false);
const form = ref({ address: '', label: '', note: '', purpose: 'other', max_per_tx: '', max_per_day: '' });
const formError = ref('');
const isSubmitting = ref(false);
const busyId = ref(null);

async function submit() {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    formError.value = '';
    try {
        const { data } = await axios.post('/admin/treasury/whitelist', form.value);
        if (data.success) {
            showForm.value = false;
            form.value = { address: '', label: '', note: '', purpose: 'other', max_per_tx: '', max_per_day: '' };
            router.reload({ only: ['entries'] });
        }
    } catch (e) {
        formError.value = e.response?.data?.message
            ?? Object.values(e.response?.data?.errors ?? {}).flat().join(' · ')
            ?? 'บันทึกไม่สำเร็จ';
    } finally { isSubmitting.value = false; }
}

async function toggle(entry) {
    busyId.value = entry.id;
    try {
        await axios.post(`/admin/treasury/whitelist/${entry.id}/toggle`);
        router.reload({ only: ['entries'] });
    } catch { /* ปล่อยไว้ หน้าจะรีเฟรชรอบหน้า */ }
    finally { busyId.value = null; }
}

async function remove(entry) {
    if (!confirm(`ลบ "${entry.label}" ออกจาก whitelist?\nที่อยู่นี้จะโอนไปไม่ได้อีก`)) return;
    busyId.value = entry.id;
    try {
        await axios.delete(`/admin/treasury/whitelist/${entry.id}`);
        router.reload({ only: ['entries'] });
    } catch { /* เช่นเดียวกัน */ }
    finally { busyId.value = null; }
}
</script>

<template>
    <Head title="Whitelist ปลายทาง — ชั้นคลัง" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">Whitelist ปลายทาง</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        คลังโอนไปได้เฉพาะที่อยู่ในรายการนี้
                        <span v-if="!limits.require_whitelist" class="text-amber-400">
                            (ตอนนี้ปิดการบังคับ whitelist อยู่ใน .env)
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/admin/treasury"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                        กลับหน้าคลัง
                    </Link>
                    <button @click="showForm = true; formError = ''"
                            class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all">
                        + เพิ่มปลายทาง
                    </button>
                </div>
            </div>

            <div class="glass-card rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-white/5 text-xs text-gray-500 uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-4 py-3">ชื่อเรียก</th>
                                <th class="text-left px-4 py-3">ที่อยู่</th>
                                <th class="text-left px-4 py-3">ใช้กับ</th>
                                <th class="text-right px-4 py-3">วงเงิน/ครั้ง</th>
                                <th class="text-right px-4 py-3">วงเงิน/วัน</th>
                                <th class="text-left px-4 py-3">สถานะ</th>
                                <th class="text-right px-4 py-3">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="e in entries" :key="e.id" class="hover:bg-white/[0.02]">
                                <td class="px-4 py-3">
                                    <div class="text-white font-medium">{{ e.label }}</div>
                                    <div v-if="e.note" class="text-xs text-gray-600 mt-0.5">{{ e.note }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-400">{{ shortAddr(e.address) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-400">{{ purposeLabel[e.purpose] ?? e.purpose }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs">
                                    <span v-if="e.max_per_tx_wei" class="text-gray-300">{{ fmtTpix(weiToTpix(e.max_per_tx_wei)) }}</span>
                                    <span v-else class="text-gray-600">ค่ากลาง</span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs">
                                    <span v-if="e.max_per_day_wei" class="text-gray-300">{{ fmtTpix(weiToTpix(e.max_per_day_wei)) }}</span>
                                    <span v-else class="text-gray-600">ค่ากลาง</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['px-2 py-1 text-xs rounded-md border',
                                                   e.is_active
                                                    ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                                    : 'bg-gray-600/30 text-gray-400 border-gray-500/30']">
                                        {{ e.is_active ? 'ใช้งาน' : 'ปิด' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <button @click="toggle(e)" :disabled="busyId === e.id"
                                                class="px-3 py-1.5 text-xs rounded-lg bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10 transition-all disabled:opacity-40">
                                            {{ e.is_active ? 'ปิด' : 'เปิด' }}
                                        </button>
                                        <button @click="remove(e)" :disabled="busyId === e.id"
                                                class="px-3 py-1.5 text-xs rounded-lg bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 transition-all disabled:opacity-40">
                                            ลบ
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!entries.length">
                                <td colspan="7" class="px-4 py-12 text-center">
                                    <div class="text-gray-500">ยังไม่มีปลายทางใน whitelist</div>
                                    <div class="text-xs text-gray-600 mt-1">ต้องเพิ่มอย่างน้อยหนึ่งรายการก่อนถึงจะสร้างรายการจ่ายเงินได้</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ฟอร์ม -->
            <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
                 @click.self="showForm = false">
                <div class="w-full max-w-lg glass-card rounded-2xl p-6 space-y-4">
                    <h3 class="text-lg font-bold text-white">เพิ่มปลายทาง</h3>

                    <div v-if="formError" class="rounded-xl border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-300">
                        {{ formError }}
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">ที่อยู่ (0x…)</label>
                        <input v-model="form.address" type="text" placeholder="0x…" maxlength="42"
                               class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm font-mono focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">ชื่อเรียก</label>
                        <input v-model="form.label" type="text" maxlength="120"
                               class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">ใช้กับ</label>
                        <select v-model="form.purpose"
                                class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm focus:ring-2 focus:ring-primary-500/50 focus:outline-none">
                            <option value="masternode">จ่ายรางวัล/คืนเงินค้ำ masternode</option>
                            <option value="token_sale">ส่งเหรียญให้ผู้ซื้อ</option>
                            <option value="refund">คืนเงิน</option>
                            <option value="other">อื่น ๆ</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">วงเงิน/ครั้ง (TPIX)</label>
                            <input v-model="form.max_per_tx" type="text" inputmode="decimal" :placeholder="`ค่ากลาง ${limits.per_transaction}`"
                                   class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm font-mono focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">วงเงิน/วัน (TPIX)</label>
                            <input v-model="form.max_per_day" type="text" inputmode="decimal" :placeholder="`ค่ากลาง ${limits.per_day}`"
                                   class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm font-mono focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">หมายเหตุ</label>
                        <input v-model="form.note" type="text" maxlength="500"
                               class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm focus:ring-2 focus:ring-primary-500/50 focus:outline-none" />
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button @click="showForm = false"
                                class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                            ยกเลิก
                        </button>
                        <button @click="submit" :disabled="isSubmitting || !form.address || !form.label"
                                class="px-4 py-2 text-sm rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all disabled:opacity-40">
                            {{ isSubmitting ? 'กำลังบันทึก…' : 'บันทึก' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
