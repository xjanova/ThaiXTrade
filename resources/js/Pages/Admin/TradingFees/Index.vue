<script setup>
/**
 * TPIX TRADE — ตั้งค่าบริการวางไม้ (หลังบ้าน)
 *
 * เจ้าของสั่งว่า "เราตั้งอัตราเองได้หมดหลายขนาด" — หน้านี้จึงเป็นตารางที่เพิ่ม/ลบ
 * ขั้นได้ไม่จำกัด ไม่ใช่ฟอร์มช่องตายตัว
 *
 * ⚠️ ต้องเตือนช่องโหว่ของขั้นบันได
 *    ตั้งขั้น 0-100 กับ 500-1000 แล้วไม้ขนาด 300 จะไม่มีขั้นรองรับ และตกไปจ่าย
 *    แบบเดิมทั้งหมดโดยไม่มีอะไรฟ้อง — แอดมินจะไม่รู้จนกว่าจะมีคนบ่นว่าโดนเก็บแพง
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const numLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));
const fmtNum = (n) => Number(n).toLocaleString(numLocale.value);

const props = defineProps({
    tiers: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    // ค่าตั้งรวมมีกระเป๋ารับเงิน — แก้ได้เฉพาะ super_admin ระดับอื่นเห็นอย่างเดียว
    canManageMoney: { type: Boolean, default: false },
    coverageGaps: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});

const flash = computed(() => usePage().props.flash || {});

// ── ค่าตั้งรวม ───────────────────────────────────────────────────────────────
const settingsForm = useForm({
    tpix_fee_enabled: props.settings.tpix_fee_enabled ?? false,
    tpix_topup_wallet: props.settings.tpix_topup_wallet ?? '',
    tpix_topup_chain_id: props.settings.tpix_topup_chain_id ?? 4289,
    tpix_min_topup: props.settings.tpix_min_topup ?? 10,
    refund_gas_fee: props.settings.refund_gas_fee ?? 0,
    ticket_ttl_minutes: props.settings.ticket_ttl_minutes ?? 15,
});

const saveSettings = () => settingsForm.put('/admin/trading-fees/settings', { preserveScroll: true });

// ── ขั้นบันได ────────────────────────────────────────────────────────────────
const newTier = useForm({
    label: '',
    min_order_usd: 0,
    max_order_usd: null,
    fee_tpix: 1,
    sort_order: 0,
    is_active: true,
});

const addTier = () => newTier.post('/admin/trading-fees', {
    preserveScroll: true,
    onSuccess: () => newTier.reset(),
});

/** แก้ทีละแถวในตาราง — ไม่ต้องเปิดหน้าใหม่ */
const editingId = ref(null);
const editForm = useForm({
    label: '', min_order_usd: 0, max_order_usd: null, fee_tpix: 0, sort_order: 0, is_active: true,
});

function startEdit(tier) {
    editingId.value = tier.id;
    editForm.label = tier.label ?? '';
    editForm.min_order_usd = tier.min_order_usd;
    editForm.max_order_usd = tier.max_order_usd;
    editForm.fee_tpix = tier.fee_tpix;
    editForm.sort_order = tier.sort_order;
    editForm.is_active = tier.is_active;
}

const saveEdit = () => editForm.put(`/admin/trading-fees/${editingId.value}`, {
    preserveScroll: true,
    onSuccess: () => { editingId.value = null; },
});

function removeTier(tier) {
    if (!confirm(t('admin.tradingFees.confirmDelete', { name: tier.label || tier.range }))) return;
    router.delete(`/admin/trading-fees/${tier.id}`, { preserveScroll: true });
}

function gapLabel(gap) {
    const from = fmtNum(gap.from);
    return gap.to === null ? t('admin.tradingFees.gapFrom', { from }) : `$${from} – $${fmtNum(gap.to)}`;
}

const inputClass = 'w-full bg-dark-800/70 border border-dark-700 rounded-lg px-2.5 py-1.5 text-white text-sm focus:border-primary-500 outline-none transition-colors';
</script>

<template>
    <AdminLayout>
        <Head :title="t('admin.tradingFees.headTitle')" />

        <div class="max-w-5xl space-y-5">
            <div>
                <h1 class="text-xl font-bold text-white">{{ t('admin.tradingFees.title') }}</h1>
                <p class="text-dark-400 text-sm mt-1">{{ t('admin.tradingFees.subtitle') }}</p>
            </div>

            <div v-if="flash.success" class="p-3 rounded-xl bg-trading-green/10 border border-trading-green/30 text-trading-green text-sm">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">
                {{ flash.error }}
            </div>

            <!-- ⚠️ ช่องโหว่ต้องเห็นก่อนอย่างอื่น — เงียบไว้แล้วเก็บเงินผิดโดยไม่มีใครรู้ -->
            <div v-if="coverageGaps.length" class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/25">
                <p class="text-amber-300 text-sm font-semibold mb-1">{{ t('admin.tradingFees.gaps.title') }}</p>
                <p class="text-amber-300/80 text-xs leading-relaxed mb-2">{{ t('admin.tradingFees.gaps.desc') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="(gap, i) in coverageGaps" :key="i" class="text-[11px] font-mono px-2 py-0.5 rounded bg-amber-500/15 text-amber-200">
                        {{ gapLabel(gap) }}
                    </span>
                </div>
            </div>

            <!-- ค่าตั้งรวม -->
            <section class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-base font-semibold text-white mb-4">{{ t('admin.tradingFees.settings.title') }}</h2>

                <form @submit.prevent="saveSettings" class="space-y-4" :inert="canManageMoney ? undefined : true">
                    <label class="flex items-start gap-2.5 cursor-pointer">
                        <input v-model="settingsForm.tpix_fee_enabled" type="checkbox" class="mt-0.5 rounded border-dark-600 bg-dark-800 text-primary-500" />
                        <span>
                            <span class="text-sm text-white block">{{ t('admin.tradingFees.settings.enable') }}</span>
                            <span class="text-[11px] text-dark-500">{{ t('admin.tradingFees.settings.enableHint') }}</span>
                        </span>
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="block text-xs text-dark-400 mb-1">{{ t('admin.tradingFees.settings.wallet') }}</label>
                            <input v-model="settingsForm.tpix_topup_wallet" type="text" :class="[inputClass, 'font-mono text-xs']" :placeholder="t('admin.tradingFees.settings.walletPlaceholder')" />
                            <p class="text-[11px] text-dark-500 mt-1">{{ t('admin.tradingFees.settings.walletHint') }}</p>
                            <p v-if="settingsForm.errors.tpix_topup_wallet" class="text-[11px] text-red-400 mt-1">{{ settingsForm.errors.tpix_topup_wallet }}</p>
                        </div>

                        <div>
                            <label class="block text-xs text-dark-400 mb-1">{{ t('admin.tradingFees.settings.chain') }}</label>
                            <input v-model.number="settingsForm.tpix_topup_chain_id" type="number" :class="inputClass" />
                        </div>

                        <div>
                            <label class="block text-xs text-dark-400 mb-1">{{ t('admin.tradingFees.settings.minTopup') }}</label>
                            <input v-model.number="settingsForm.tpix_min_topup" type="number" step="0.0001" min="0" :class="inputClass" />
                        </div>

                        <div>
                            <label class="block text-xs text-dark-400 mb-1">{{ t('admin.tradingFees.settings.refundGas') }}</label>
                            <input v-model.number="settingsForm.refund_gas_fee" type="number" step="0.0001" min="0" :class="inputClass" />
                            <p class="text-[11px] text-dark-500 mt-1">{{ t('admin.tradingFees.settings.refundGasHint') }}</p>
                        </div>

                        <div>
                            <label class="block text-xs text-dark-400 mb-1">{{ t('admin.tradingFees.settings.ticketTtl') }}</label>
                            <input v-model.number="settingsForm.ticket_ttl_minutes" type="number" min="1" max="1440" :class="inputClass" />
                            <p class="text-[11px] text-dark-500 mt-1">{{ t('admin.tradingFees.settings.ticketTtlHint') }}</p>
                        </div>
                    </div>

                    <button v-if="canManageMoney" type="submit" :disabled="settingsForm.processing" class="btn-primary px-5 py-2 text-sm">
                        {{ settingsForm.processing ? t('admin.tradingFees.settings.saving') : t('admin.tradingFees.settings.save') }}
                    </button>
                </form>
                <p v-if="!canManageMoney" class="text-xs text-amber-300 mt-4">{{ t('admin.tradingFees.settings.superAdminOnly') }}</p>
            </section>

            <!-- ขั้นบันได -->
            <section class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h2 class="text-base font-semibold text-white">{{ t('admin.tradingFees.tiers.title') }}</h2>
                    <div class="flex items-center gap-3 text-[11px] text-dark-500 font-mono">
                        <span>{{ t('admin.tradingFees.stats.issued', { n: stats.issued ?? 0 }) }}</span>
                        <span>{{ t('admin.tradingFees.stats.consumed', { n: stats.consumed ?? 0 }) }}</span>
                        <span>{{ t('admin.tradingFees.stats.refunded', { n: stats.refunded ?? 0 }) }}</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] text-dark-500 border-b border-white/10">
                                <th class="pb-2 pr-2 font-medium">{{ t('admin.tradingFees.tiers.colName') }}</th>
                                <th class="pb-2 pr-2 font-medium">{{ t('admin.tradingFees.tiers.colFrom') }}</th>
                                <th class="pb-2 pr-2 font-medium">{{ t('admin.tradingFees.tiers.colTo') }}</th>
                                <th class="pb-2 pr-2 font-medium">{{ t('admin.tradingFees.tiers.colFee') }}</th>
                                <th class="pb-2 pr-2 font-medium">{{ t('admin.tradingFees.tiers.colActive') }}</th>
                                <th class="pb-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="tier in tiers" :key="tier.id" class="align-middle">
                                <template v-if="editingId === tier.id">
                                    <td class="py-2 pr-2"><input v-model="editForm.label" type="text" :class="inputClass" /></td>
                                    <td class="py-2 pr-2"><input v-model.number="editForm.min_order_usd" type="number" step="0.01" min="0" :class="inputClass" /></td>
                                    <td class="py-2 pr-2"><input v-model.number="editForm.max_order_usd" type="number" step="0.01" :placeholder="t('admin.tradingFees.tiers.unlimited')" :class="inputClass" /></td>
                                    <td class="py-2 pr-2"><input v-model.number="editForm.fee_tpix" type="number" step="0.0001" min="0" :class="inputClass" /></td>
                                    <td class="py-2 pr-2"><input v-model="editForm.is_active" type="checkbox" class="rounded border-dark-600 bg-dark-800 text-primary-500" /></td>
                                    <td class="py-2 whitespace-nowrap">
                                        <button type="button" class="text-xs text-trading-green hover:underline mr-2" @click="saveEdit">{{ t('common.save') }}</button>
                                        <button type="button" class="text-xs text-dark-400 hover:text-white" @click="editingId = null">{{ t('common.cancel') }}</button>
                                    </td>
                                </template>

                                <template v-else>
                                    <td class="py-2.5 pr-2 text-white">{{ tier.label || '—' }}</td>
                                    <td class="py-2.5 pr-2 text-dark-200 font-mono">{{ fmtNum(tier.min_order_usd) }}</td>
                                    <td class="py-2.5 pr-2 text-dark-200 font-mono">{{ tier.max_order_usd === null ? t('admin.tradingFees.tiers.unlimited') : fmtNum(tier.max_order_usd) }}</td>
                                    <td class="py-2.5 pr-2 text-primary-300 font-mono font-semibold">{{ tier.fee_tpix }}</td>
                                    <td class="py-2.5 pr-2">
                                        <span :class="['text-[10px] px-1.5 py-0.5 rounded', tier.is_active ? 'bg-trading-green/15 text-trading-green' : 'bg-dark-700 text-dark-400']">
                                            {{ tier.is_active ? t('admin.tradingFees.tiers.on') : t('admin.tradingFees.tiers.off') }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 whitespace-nowrap">
                                        <button type="button" class="text-xs text-primary-400 hover:underline mr-2" @click="startEdit(tier)">{{ t('common.edit') }}</button>
                                        <button type="button" class="text-xs text-red-400 hover:underline" @click="removeTier(tier)">{{ t('common.delete') }}</button>
                                    </td>
                                </template>
                            </tr>

                            <tr v-if="tiers.length === 0">
                                <td colspan="6" class="py-6 text-center text-dark-500 text-sm">
                                    {{ t('admin.tradingFees.tiers.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- เพิ่มขั้นใหม่ -->
                <form @submit.prevent="addTier" class="mt-4 pt-4 border-t border-white/10">
                    <p class="text-xs text-dark-400 mb-2">{{ t('admin.tradingFees.newTier.title') }}</p>
                    <div class="grid gap-2 sm:grid-cols-5">
                        <input v-model="newTier.label" type="text" :placeholder="t('admin.tradingFees.newTier.namePlaceholder')" :class="inputClass" />
                        <input v-model.number="newTier.min_order_usd" type="number" step="0.01" min="0" :placeholder="t('admin.tradingFees.newTier.fromPlaceholder')" :class="inputClass" />
                        <input v-model.number="newTier.max_order_usd" type="number" step="0.01" :placeholder="t('admin.tradingFees.newTier.toPlaceholder')" :class="inputClass" />
                        <input v-model.number="newTier.fee_tpix" type="number" step="0.0001" min="0" placeholder="TPIX" :class="inputClass" />
                        <button type="submit" :disabled="newTier.processing" class="btn-primary px-4 py-1.5 text-sm">{{ t('admin.tradingFees.newTier.add') }}</button>
                    </div>
                    <p v-if="newTier.errors.max_order_usd" class="text-[11px] text-red-400 mt-1.5">{{ newTier.errors.max_order_usd }}</p>
                </form>
            </section>
        </div>
    </AdminLayout>
</template>
