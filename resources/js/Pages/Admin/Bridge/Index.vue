<script setup>
/**
 * TPIX TRADE — หลังบ้านสะพาน USDT หลายเชน → TPIX Chain
 *
 * ภาพรวม (สวิตช์ · ด่านความพร้อม · ยอดเงินหนุน) · รายการฝาก · รายการถอน · วงเงิน/ค่าธรรมเนียม
 * ปุ่มที่กระทบเงินทุกปุ่มมีกล่องยืนยัน และกันกดเบิ้ลทั้งฝั่งหน้าจอ (busy) และฝั่งเซิร์ฟเวอร์ (409)
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    tab: { type: String, default: 'overview' },
    filters: { type: Object, default: () => ({}) },
    state: { type: Object, default: () => ({}) },
    snapshot: { type: Object, default: null },
    deposits: { type: Object, default: () => ({ data: [] }) },
    withdrawals: { type: Object, default: () => ({ data: [] }) },
    counts: { type: Object, default: () => ({ deposits: {}, withdrawals: {} }) },
    settings: { type: Object, default: () => ({ limits: {}, chains: [] }) },
    explorers: { type: Object, default: () => ({}) },
});

const activeTab = ref(props.tab);
const notice = ref(null); // { type, text }
const busy = ref(false);

function goTab(tab, status = null) {
    activeTab.value = tab;
    router.get('/admin/bridge', { tab, ...(status ? { status } : {}) }, { preserveState: true, preserveScroll: true, replace: true });
}

const short = (v) => (v ? `${v.slice(0, 10)}…${v.slice(-6)}` : '—');
const txUrl = (chainId, hash) => (props.explorers?.[chainId] && hash ? `${props.explorers[chainId]}/tx/${hash}` : null);
const chainName = (id) => props.settings.chains.find((c) => c.chain_id === id)?.name ?? (id === 4289 ? 'TPIX' : `#${id ?? '?'}`);

const statusStyle = {
    confirmed: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    minting: 'bg-violet-500/20 text-violet-300 border-violet-500/30',
    minted: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    needs_review: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    rejected: 'bg-gray-600/30 text-gray-400 border-gray-500/30',
    ignored: 'bg-gray-700/30 text-gray-500 border-gray-600/30',
    pending: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    awaiting_liquidity: 'bg-orange-500/20 text-orange-300 border-orange-500/30',
    sending: 'bg-violet-500/20 text-violet-300 border-violet-500/30',
    paid: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
};

const reasonLabel = {
    dust: 'ฝุ่น/ยอดศูนย์',
    below_minimum: 'ต่ำกว่าขั้นต่ำ',
    internal_sender: 'โอนภายใน',
    sender_is_contract: 'ผู้ส่งเป็นสัญญา',
    sender_mismatch: 'ผู้กดส่งไม่ใช่เจ้าของเหรียญ',
    kyc_required: 'ยังไม่ผ่าน KYC',
    over_tx_limit: 'เกินวงเงินต่อครั้ง',
    over_daily_address_limit: 'เกินวงเงินต่อกระเป๋าต่อวัน',
    exceeds_onchain_max: 'เกิน maxPerTx บนสัญญา',
    mint_reverted: 'mint revert บนเชน',
    mint_estimate_failed: 'เชนบอกว่า mint จะ revert',
    nonce_consumed: 'nonce ถูกใช้โดยธุรกรรมอื่น',
    minted_elsewhere: 'ถูก mint โดยธุรกรรมอื่น — ตรวจผู้รับก่อนอนุมัติ',
    unsupported_chain: 'เชนปลายทางไม่รองรับ',
    invalid_target_address: 'ที่อยู่ปลายทางผิด',
    suspicious_target: 'ปลายทางเป็นสัญญา/กระเป๋าของเราเอง',
    payout_reverted: 'โอนจ่าย revert',
    payout_estimate_failed: 'เชนบอกว่าโอนจ่ายจะ revert',
};

// ── สวิตช์ ──────────────────────────────────────────────────────────────
const confirmToggle = ref(false);
const toggleReason = ref('');

async function doToggle() {
    if (busy.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post('/admin/bridge/toggle', { enabled: !props.state.site_switch, reason: toggleReason.value || null });
        notice.value = { type: 'success', text: (data.data.enabled ? 'เปิดสะพานแล้ว' : 'ปิดสะพานแล้ว') + (data.data.warnings?.length ? ` · ${data.data.warnings.join(' · ')}` : '') };
        confirmToggle.value = false;
        toggleReason.value = '';
        router.reload({ only: ['state'] });
    } catch (e) {
        notice.value = { type: 'error', text: e.response?.data?.message ?? 'เปลี่ยนสวิตช์ไม่สำเร็จ' };
    } finally {
        busy.value = false;
    }
}

// ── ยอดสด ───────────────────────────────────────────────────────────────
const live = ref(null);
const measuring = ref(false);

async function measure() {
    if (measuring.value) return;
    measuring.value = true;
    try {
        const { data } = await axios.get('/admin/bridge/measure');
        live.value = data.data;
    } catch (e) {
        notice.value = { type: 'error', text: e.response?.status === 429 ? 'รีเฟรชถี่เกินไป รอสักครู่' : 'อ่านยอดไม่สำเร็จ' };
    } finally {
        measuring.value = false;
    }
}

const view = computed(() => live.value ?? props.snapshot);
const fmtRaw = (v, d = 6) => {
    if (v === null || v === undefined) return '—';
    const s = String(v).padStart(d + 1, '0');
    const whole = s.slice(0, -d);
    const frac = s.slice(-d).replace(/0+$/, '');
    return frac ? `${whole}.${frac}` : whole;
};
const fmtNative = (wei) => (wei === null || wei === undefined ? '—' : Number(fmtRaw(wei, 18)).toFixed(5));

// ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────
const pending = ref(null); // { kind, action, row }
const rejectReason = ref('');

function ask(kind, action, row) {
    pending.value = { kind, action, row };
    rejectReason.value = '';
}

const confirmMessage = computed(() => {
    const p = pending.value;
    if (!p) return '';
    const row = p.row;
    if (p.kind === 'deposit') {
        return p.action === 'approve'
            ? `อนุมัติ mint ${row.mint_amount} USDT ให้ ${row.from_address} (จาก ${chainName(row.source_chain_id)})? ระบบจะตรวจบนเชนซ้ำก่อน mint จริง`
            : `ปฏิเสธเงินฝาก ${row.amount} USDT จาก ${row.from_address}? เงินจะค้างอยู่ใน vault จนกว่าจะคืนด้วยมือ`;
    }
    return p.action === 'approve'
        ? `อนุมัติจ่าย ${row.payout_amount} USDT ไป ${row.target_address} บน ${chainName(row.target_chain_id)}? เงินจริงจะออกจากกระเป๋าจ่าย`
        : `ปฏิเสธการถอน ${row.amount} USDT ของ ${row.from_address}? ผู้ใช้เผาเหรียญไปแล้ว ต้องคืนด้วยมือ (mint คืนบน TPIX)`;
});

async function runPending() {
    const p = pending.value;
    if (!p || busy.value) return;
    if (p.action === 'reject' && !rejectReason.value.trim()) return;

    busy.value = true;
    try {
        const base = p.kind === 'deposit' ? '/admin/bridge/deposits' : '/admin/bridge/withdrawals';
        const { data } = await axios.post(`${base}/${p.row.id}/${p.action}`, p.action === 'reject' ? { reason: rejectReason.value.trim() } : {});
        notice.value = { type: 'success', text: data.message ?? 'ดำเนินการแล้ว' };
        pending.value = null;
        router.reload({ only: ['deposits', 'withdrawals', 'counts', 'state'] });
    } catch (e) {
        const errors = e.response?.data?.errors;
        notice.value = { type: 'error', text: Array.isArray(errors) ? errors.join(' · ') : (e.response?.data?.message ?? 'ดำเนินการไม่สำเร็จ') };
        pending.value = null;
    } finally {
        busy.value = false;
    }
}

// ── ตั้งค่า ──────────────────────────────────────────────────────────────
const limitLabels = {
    min_deposit: 'ฝากขั้นต่ำ',
    max_deposit_per_tx: 'ฝากสูงสุดต่อครั้ง (เกิน = รอตรวจ)',
    max_deposit_per_address_daily: 'ฝากสูงสุดต่อกระเป๋าต่อวัน',
    deposit_fee: 'ค่าธรรมเนียมฝาก',
    min_withdraw: 'ถอนขั้นต่ำ',
    max_withdraw_per_tx: 'ถอนสูงสุดต่อครั้ง (เกิน = รอตรวจ)',
    max_payout_daily: 'จ่ายรวมสูงสุดต่อวัน (UTC)',
};
const limitsForm = ref({ ...props.settings.limits });
const feesForm = ref(Object.fromEntries(props.settings.chains.map((c) => [c.chain_id, c.withdraw_fee])));
const chainsForm = ref(Object.fromEntries(props.settings.chains.map((c) => [c.chain_id, c.enabled])));
const settingsErrors = ref([]);
const confirmSettings = ref(false);

async function saveSettings() {
    if (busy.value) return;
    busy.value = true;
    settingsErrors.value = [];
    try {
        await axios.put('/admin/bridge/settings', { limits: limitsForm.value, fees: feesForm.value, chains: chainsForm.value });
        notice.value = { type: 'success', text: 'บันทึกวงเงินและค่าธรรมเนียมแล้ว' };
        confirmSettings.value = false;
        router.reload({ only: ['settings', 'state'] });
    } catch (e) {
        settingsErrors.value = e.response?.data?.errors
            ? (Array.isArray(e.response.data.errors) ? e.response.data.errors : Object.values(e.response.data.errors).flat())
            : [e.response?.data?.message ?? 'บันทึกไม่สำเร็จ'];
        confirmSettings.value = false;
    } finally {
        busy.value = false;
    }
}

const depositFilters = ['needs_review', 'confirmed', 'minting', 'minted', 'ignored', 'rejected'];
const withdrawFilters = ['needs_review', 'pending', 'awaiting_liquidity', 'sending', 'paid', 'rejected'];
const readinessLabel = {
    installed: 'BRIDGE_ENABLED ใน .env', site_switch: 'สวิตช์หลังบ้าน', usdt_tpix: 'ลงทะเบียน USDT_TPIX', minter: 'ลงทะเบียน TPIXBridgeMinter',
    relayer_keystore: 'keystore ของ relayer', relayer_passphrase: 'passphrase ของ relayer', minter_is_bridge: 'USDT_TPIX.bridges(minter) = true',
    relayer_role: 'minter.relayers(relayer) = true', minter_not_paused: 'minter ไม่ถูก pause', token_not_paused: 'USDT_TPIX ไม่ถูก pause',
};
</script>

<template>
    <Head title="สะพาน USDT — หลังบ้าน" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">สะพาน USDT → TPIX Chain</h1>
                    <p class="text-sm text-gray-400 mt-1">ฝาก USDT จาก 6 เชน → mint USDT_TPIX · ถอนกลับจากกระเป๋าจ่าย · กระทบยอดทุก 5 นาที</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="/admin/bridge/wtpix" class="px-4 py-2 text-sm rounded-xl bg-primary-500/10 text-primary-300 border border-primary-500/30 hover:bg-primary-500/20">สะพาน TPIX⇄wTPIX →</a>
                    <a href="/bridge/reserves" target="_blank" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">หน้า Proof of Reserves ↗</a>
                </div>
            </div>

            <div v-if="notice" :class="['rounded-xl border p-4 text-sm', notice.type === 'success' ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-300' : 'border-red-500/30 bg-red-500/5 text-red-300']">
                {{ notice.text }}
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="tb in [['overview', 'ภาพรวม'], ['deposits', `ฝาก${counts.deposits?.needs_review ? ` (${counts.deposits.needs_review} รอตรวจ)` : ''}`], ['withdrawals', `ถอน${counts.withdrawals?.needs_review ? ` (${counts.withdrawals.needs_review} รอตรวจ)` : ''}`], ['settings', 'วงเงิน/ค่าธรรมเนียม']]"
                    :key="tb[0]" @click="goTab(tb[0])"
                    :class="['px-4 py-2 text-sm rounded-xl border transition-all', activeTab === tb[0] ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10']">
                    {{ tb[1] }}
                </button>
            </div>

            <!-- ═══════════ ภาพรวม ═══════════ -->
            <div v-if="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="glass-card rounded-xl p-5 space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-white">สวิตช์สะพาน</h3>
                            <span :class="['px-2 py-1 text-xs rounded-md border', state.operational ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-red-500/20 text-red-300 border-red-500/30']">
                                {{ state.operational ? 'ทำงาน' : 'หยุด' }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-400">ติดตั้ง (.env): <b :class="state.installed ? 'text-emerald-300' : 'text-red-300'">{{ state.installed ? 'ใช่' : 'ยังไม่ได้ตั้ง BRIDGE_ENABLED' }}</b> · สวิตช์หลังบ้าน: <b>{{ state.site_switch ? 'เปิด' : 'ปิด' }}</b></p>
                        <p v-if="state.switch_reason" class="text-xs text-amber-300">{{ state.switch_reason }}</p>
                        <button @click="confirmToggle = true" :disabled="busy"
                            :class="['w-full py-2.5 rounded-xl text-sm font-semibold border transition-all disabled:opacity-40', state.site_switch ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30']">
                            {{ state.site_switch ? 'หยุดสะพาน (mint + จ่าย)' : 'เปิดสะพาน' }}
                        </button>
                        <p class="text-[11px] text-gray-500">หยุดแล้วตัวสแกนยังบันทึกเงินฝากต่อ — ไม่มีรายการหาย mint/จ่ายต่อเมื่อเปิดใหม่ · pause บนสัญญา (minter) ปลดได้เฉพาะกระเป๋าเจ้าของ</p>
                    </div>

                    <div class="glass-card rounded-xl p-5">
                        <h3 class="font-semibold text-white mb-3">ด่านความพร้อม (ฝั่ง mint)</h3>
                        <ul class="space-y-1.5 text-xs">
                            <li v-for="c in state.readiness || []" :key="c.key" class="flex items-start gap-2">
                                <span :class="c.ok ? 'text-emerald-400' : 'text-red-400'">{{ c.ok ? '✓' : '✗' }}</span>
                                <span class="text-gray-300">{{ readinessLabel[c.key] ?? c.key }}</span>
                                <span v-if="!c.ok" class="text-gray-500 break-all">— {{ c.hint }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="glass-card rounded-xl p-5 text-xs space-y-2">
                        <h3 class="font-semibold text-white text-sm">กุญแจบนเซิร์ฟเวอร์ (keystore)</h3>
                        <div v-for="(s, role) in state.signers || {}" :key="role" class="flex flex-wrap justify-between gap-2">
                            <span class="text-gray-400 w-20">{{ role }}</span>
                            <code class="font-mono text-white break-all flex-1">{{ s.address ?? 'ยังไม่มีไฟล์ keystore' }}</code>
                            <span :class="s.ready ? 'text-emerald-400' : 'text-amber-400'">{{ s.ready ? 'พร้อม' : (s.passphrase_set ? 'ขาดไฟล์' : 'ขาด passphrase') }}</span>
                        </div>
                        <div class="pt-2 border-t border-white/5 space-y-1">
                            <div class="flex justify-between"><span class="text-gray-400">vault</span><code class="font-mono text-white">{{ state.addresses?.vault }}</code></div>
                            <div class="flex justify-between"><span class="text-gray-400">USDT_TPIX</span><code class="font-mono text-white">{{ state.addresses?.usdt_tpix ?? '—' }}</code></div>
                            <div class="flex justify-between"><span class="text-gray-400">minter</span><code class="font-mono text-white">{{ state.addresses?.minter ?? 'ยังไม่ได้ deploy' }}</code></div>
                        </div>
                    </div>
                    <div class="glass-card rounded-xl p-5 text-xs space-y-1.5">
                        <h3 class="font-semibold text-white text-sm mb-1">เพดานบนสัญญา</h3>
                        <div class="flex justify-between"><span class="text-gray-400">paused</span><span :class="state.minter?.paused ? 'text-red-300' : 'text-white'">{{ state.minter?.paused === null || state.minter?.paused === undefined ? '—' : (state.minter.paused ? 'ใช่' : 'ไม่') }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">maxPerTx</span><span class="text-white font-mono">{{ state.minter?.max_per_tx ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">dailyCap</span><span class="text-white font-mono">{{ state.minter?.daily_cap ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">เหลือวันนี้</span><span class="text-white font-mono">{{ state.minter?.remaining_today ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">totalMinted (ผ่าน minter)</span><span class="text-white font-mono">{{ state.minter?.total_minted ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">จ่ายแล้ววันนี้ (UTC)</span><span class="text-white font-mono">{{ state.paid_today }}</span></div>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-white">เงินหนุน {{ live ? '(สด)' : `(กระทบยอดล่าสุด ${snapshot?.checked_at ? new Date(snapshot.checked_at).toLocaleString() : '—'})` }}</h3>
                        <button @click="measure" :disabled="measuring" class="px-3 py-1.5 text-xs rounded-lg bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-40">
                            {{ measuring ? 'กำลังอ่าน…' : 'อ่านยอดสดทุกเชน' }}
                        </button>
                    </div>
                    <p v-if="!view" class="text-sm text-gray-500">ยังไม่มีผลกระทบยอด (bridge:reconcile ยังไม่เคยรัน)</p>
                    <template v-else>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                            <div><p class="text-gray-500">supply</p><p class="text-white font-mono text-base">{{ view.supply ?? '—' }}</p></div>
                            <div><p class="text-gray-500">vault</p><p class="text-white font-mono text-base">{{ view.vault }}</p></div>
                            <div><p class="text-gray-500">กระเป๋าจ่าย</p><p class="text-white font-mono text-base">{{ view.payout_wallets }}</p></div>
                            <div><p class="text-gray-500">ค้างจ่าย</p><p class="text-white font-mono text-base">{{ view.owed }}</p></div>
                            <div><p class="text-gray-500">ขาด (ติดลบ = เกิน)</p><p :class="['font-mono text-base', String(view.deficit).startsWith('-') || view.deficit === '0' ? 'text-emerald-300' : 'text-red-300']">{{ view.deficit }}</p></div>
                        </div>
                        <p v-if="view.unexplained_mint && view.unexplained_mint !== '0'" class="text-sm text-red-300">⚠️ mint ที่ระบบไม่ได้สั่ง: {{ view.unexplained_mint }} USDT</p>
                        <p v-if="view.note" class="text-xs text-amber-300">{{ view.note }}</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs min-w-[640px]">
                                <thead class="text-gray-500"><tr>
                                    <th class="text-left py-1.5">เชน</th><th class="text-right">vault</th><th class="text-right">กระเป๋าจ่าย (USDT)</th><th class="text-right">กระเป๋าจ่าย (แก๊ส)</th>
                                </tr></thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="c in view.chains" :key="c.chain_id">
                                        <td class="py-2 text-white">{{ c.name }} <span v-if="!c.enabled" class="text-gray-500">(ปิด)</span></td>
                                        <td class="text-right font-mono"><a v-if="c.vault_url" :href="c.vault_url" target="_blank" class="text-primary-300 hover:underline">{{ c.readable ? fmtRaw(c.vault_units) : 'อ่านไม่ได้' }}</a></td>
                                        <td class="text-right font-mono"><a v-if="c.payout_url" :href="c.payout_url" target="_blank" class="text-primary-300 hover:underline">{{ c.readable ? fmtRaw(c.payout_units) : 'อ่านไม่ได้' }}</a><span v-else class="text-gray-600">—</span></td>
                                        <td class="text-right font-mono text-gray-300">{{ fmtNative(c.payout_native_wei) }} {{ c.native }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ═══════════ ฝาก ═══════════ -->
            <div v-if="activeTab === 'deposits'" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button @click="goTab('deposits')" :class="['px-3 py-1.5 text-xs rounded-lg border', !filters.status ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">ทั้งหมด</button>
                    <button v-for="s in depositFilters" :key="s" @click="goTab('deposits', s)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.status === s ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                        {{ s }} ({{ counts.deposits?.[s] ?? 0 }})
                    </button>
                </div>
                <div class="glass-card rounded-xl overflow-x-auto">
                    <table class="w-full text-xs min-w-[860px]">
                        <thead class="bg-white/5 text-gray-500 uppercase tracking-wider"><tr>
                            <th class="text-left px-3 py-2">เชน / tx</th><th class="text-left px-3">ผู้ฝาก (ผู้รับ)</th><th class="text-right px-3">จำนวน</th>
                            <th class="text-left px-3">สถานะ</th><th class="text-left px-3">mint</th><th class="text-right px-3">จัดการ</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="d in deposits.data" :key="d.id" class="align-top">
                                <td class="px-3 py-2">
                                    <div class="text-white">{{ chainName(d.source_chain_id) }}</div>
                                    <a :href="txUrl(d.source_chain_id, d.tx_hash)" target="_blank" class="font-mono text-primary-300 hover:underline">{{ short(d.tx_hash) }}</a>
                                    <span class="text-gray-600"> #{{ d.log_index }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-gray-300">{{ short(d.from_address) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-white">{{ d.amount }}<div v-if="d.mint_amount !== d.amount" class="text-gray-500">mint {{ d.mint_amount }}</div></td>
                                <td class="px-3 py-2">
                                    <span :class="['px-2 py-0.5 rounded-md border', statusStyle[d.status]]">{{ d.status }}</span>
                                    <div v-if="d.review_reason" class="text-amber-300 mt-1">{{ reasonLabel[d.review_reason] ?? d.review_reason }}</div>
                                    <div v-if="d.last_error" class="text-gray-500 mt-1 max-w-[240px] break-words">{{ d.last_error }}</div>
                                    <div v-if="d.limits_overridden" class="text-gray-500 mt-1">แอดมินอนุมัติแล้ว</div>
                                </td>
                                <td class="px-3 py-2"><a v-if="d.mint_tx_hash" :href="txUrl(4289, d.mint_tx_hash)" target="_blank" class="font-mono text-primary-300 hover:underline">{{ short(d.mint_tx_hash) }}</a></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <template v-if="d.status === 'needs_review' || (d.status === 'ignored' && d.review_reason === 'below_minimum')">
                                        <button @click="ask('deposit', 'approve', d)" :disabled="busy" class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">อนุมัติ</button>
                                    </template>
                                    <button v-if="(d.status === 'needs_review' || d.status === 'confirmed') && !d.mint_tx_hash" @click="ask('deposit', 'reject', d)" :disabled="busy"
                                        class="ml-1 px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-white/10 disabled:opacity-40">ปฏิเสธ</button>
                                </td>
                            </tr>
                            <tr v-if="!deposits.data.length"><td colspan="6" class="px-3 py-10 text-center text-gray-500">ไม่มีรายการ</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 text-xs">
                    <button v-if="deposits.prev_page_url" @click="router.get(deposits.prev_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">← ก่อนหน้า</button>
                    <button v-if="deposits.next_page_url" @click="router.get(deposits.next_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">ถัดไป →</button>
                </div>
            </div>

            <!-- ═══════════ ถอน ═══════════ -->
            <div v-if="activeTab === 'withdrawals'" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button @click="goTab('withdrawals')" :class="['px-3 py-1.5 text-xs rounded-lg border', !filters.status ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">ทั้งหมด</button>
                    <button v-for="s in withdrawFilters" :key="s" @click="goTab('withdrawals', s)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.status === s ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                        {{ s }} ({{ counts.withdrawals?.[s] ?? 0 }})
                    </button>
                </div>
                <div class="glass-card rounded-xl overflow-x-auto">
                    <table class="w-full text-xs min-w-[900px]">
                        <thead class="bg-white/5 text-gray-500 uppercase tracking-wider"><tr>
                            <th class="text-left px-3 py-2">burn tx</th><th class="text-left px-3">ผู้ถอน</th><th class="text-left px-3">ปลายทาง</th>
                            <th class="text-right px-3">จำนวน / จ่าย</th><th class="text-left px-3">สถานะ</th><th class="text-right px-3">จัดการ</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="w in withdrawals.data" :key="w.id" class="align-top">
                                <td class="px-3 py-2"><a :href="txUrl(4289, w.tx_hash)" target="_blank" class="font-mono text-primary-300 hover:underline">{{ short(w.tx_hash) }}</a></td>
                                <td class="px-3 py-2 font-mono text-gray-300">{{ short(w.from_address) }}</td>
                                <td class="px-3 py-2">
                                    <div class="text-white">{{ w.target_chain_id ? chainName(w.target_chain_id) : `chain ${String(w.target_chain_raw).slice(0, 20)}` }}</div>
                                    <div class="font-mono text-gray-400 break-all max-w-[220px]">{{ w.target_address }}</div>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-white">{{ w.amount }}<div class="text-gray-500">จ่าย {{ w.payout_amount }} (ค่าธรรมเนียม {{ w.fee }})</div></td>
                                <td class="px-3 py-2">
                                    <span :class="['px-2 py-0.5 rounded-md border', statusStyle[w.status]]">{{ w.status }}</span>
                                    <div v-if="w.review_reason" class="text-amber-300 mt-1">{{ reasonLabel[w.review_reason] ?? w.review_reason }}</div>
                                    <div v-if="w.last_error" class="text-gray-500 mt-1 max-w-[240px] break-words">{{ w.last_error }}</div>
                                    <a v-if="w.payout_tx_hash" :href="txUrl(w.target_chain_id, w.payout_tx_hash)" target="_blank" class="block font-mono text-primary-300 hover:underline mt-1">{{ short(w.payout_tx_hash) }}</a>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button v-if="w.status === 'needs_review'" @click="ask('withdrawal', 'approve', w)" :disabled="busy" class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">อนุมัติ</button>
                                    <button v-if="['needs_review', 'pending', 'awaiting_liquidity'].includes(w.status)" @click="ask('withdrawal', 'reject', w)" :disabled="busy"
                                        class="ml-1 px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-white/10 disabled:opacity-40">ปฏิเสธ</button>
                                </td>
                            </tr>
                            <tr v-if="!withdrawals.data.length"><td colspan="6" class="px-3 py-10 text-center text-gray-500">ไม่มีรายการ</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 text-xs">
                    <button v-if="withdrawals.prev_page_url" @click="router.get(withdrawals.prev_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">← ก่อนหน้า</button>
                    <button v-if="withdrawals.next_page_url" @click="router.get(withdrawals.next_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">ถัดไป →</button>
                </div>
            </div>

            <!-- ═══════════ ตั้งค่า ═══════════ -->
            <div v-if="activeTab === 'settings'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="glass-card rounded-xl p-5 space-y-3">
                    <h3 class="font-semibold text-white">วงเงิน (USDT)</h3>
                    <p class="text-xs text-gray-500">เกินวงเงิน = รอแอดมินตรวจ ไม่ mint/จ่ายเอง · เพดานบนสัญญา (maxPerTx / dailyCap) เปลี่ยนได้เฉพาะกระเป๋าเจ้าของ</p>
                    <div v-for="(label, key) in limitLabels" :key="key" class="flex items-center justify-between gap-3">
                        <label class="text-xs text-gray-300">{{ label }}</label>
                        <input v-model.trim="limitsForm[key]" type="text" inputmode="decimal" class="w-36 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                    </div>
                </div>
                <div class="glass-card rounded-xl p-5 space-y-3">
                    <h3 class="font-semibold text-white">เชนและค่าธรรมเนียมถอน</h3>
                    <div v-for="c in settings.chains" :key="c.chain_id" class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-xs">
                            <p class="text-white">{{ c.name }} <span class="text-gray-500">({{ c.usdt.symbol }} · {{ c.usdt.decimals }} decimals)</span></p>
                            <p v-if="!c.configured" class="text-gray-500">ปิดใน .env</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model.trim="feesForm[c.chain_id]" type="text" inputmode="decimal" class="w-24 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                            <label class="flex items-center gap-1 text-xs text-gray-300"><input type="checkbox" v-model="chainsForm[c.chain_id]" :disabled="!c.configured" /> เปิด</label>
                        </div>
                    </div>
                </div>
                <div class="lg:col-span-2 space-y-2">
                    <div v-if="settingsErrors.length" class="rounded-xl border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-300">
                        <div v-for="(e, i) in settingsErrors" :key="i">{{ e }}</div>
                    </div>
                    <button @click="confirmSettings = true" :disabled="busy" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-primary-500/20 text-primary-300 border border-primary-500/30 disabled:opacity-40">บันทึก</button>
                </div>
            </div>
        </div>

        <!-- กล่องยืนยัน -->
        <ConfirmDialog :show="confirmToggle" :danger="state.site_switch" :loading="busy"
            :title="state.site_switch ? 'หยุดสะพาน?' : 'เปิดสะพาน?'"
            :message="state.site_switch ? 'mint และการจ่ายจะหยุดทันที (การสแกนเงินฝากยังเดินต่อ ไม่มีรายการหาย)' : 'ระบบจะเริ่ม mint และจ่ายตามคิวภายใน 1 นาที — ตรวจด่านความพร้อมให้ครบก่อน'"
            :confirm-text="state.site_switch ? 'หยุด' : 'เปิด'" cancel-text="ยกเลิก"
            @confirm="doToggle" @cancel="confirmToggle = false" />

        <ConfirmDialog :show="!!pending && pending.action === 'approve'" :loading="busy" title="ยืนยันการอนุมัติ"
            :message="confirmMessage" confirm-text="อนุมัติ" cancel-text="ยกเลิก"
            @confirm="runPending" @cancel="pending = null" />

        <ConfirmDialog :show="confirmSettings" :loading="busy" title="บันทึกวงเงิน/ค่าธรรมเนียม?"
            message="มีผลกับรายการที่เข้ามาใหม่และรายการในคิวที่ยังไม่ได้จ่ายทันที" confirm-text="บันทึก" cancel-text="ยกเลิก"
            @confirm="saveSettings" @cancel="confirmSettings = false" />

        <!-- ปฏิเสธ — ต้องมีเหตุผลเสมอ -->
        <div v-if="pending && pending.action === 'reject'" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="!busy && (pending = null)">
            <div class="w-full max-w-md glass-card rounded-2xl p-6 space-y-4">
                <h3 class="text-lg font-bold text-white">ยืนยันการปฏิเสธ</h3>
                <p class="text-sm text-gray-300">{{ confirmMessage }}</p>
                <textarea v-model="rejectReason" rows="3" maxlength="500" placeholder="เหตุผล (จำเป็น)"
                    class="w-full px-3 py-2 rounded-xl bg-white/5 border border-white/10 text-white text-sm"></textarea>
                <div class="flex justify-end gap-3">
                    <button @click="pending = null" :disabled="busy" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10">ยกเลิก</button>
                    <button @click="runPending" :disabled="busy || !rejectReason.trim()" class="px-4 py-2 text-sm rounded-xl bg-red-500/20 text-red-300 border border-red-500/30 disabled:opacity-40">ปฏิเสธ</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
