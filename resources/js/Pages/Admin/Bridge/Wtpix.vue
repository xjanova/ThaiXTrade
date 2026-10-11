<script setup>
/**
 * TPIX TRADE — หลังบ้านสะพาน TPIX (เชน 4289) ⇄ wTPIX (BSC)
 *
 * ภาพรวม (สวิตช์ · ด่านความพร้อม · บทบาท/แก๊ส BNB · เพดานบนสัญญา · เงินหนุน) · รายการสองทิศ · วงเงิน
 * ปุ่มที่กระทบเงินทุกปุ่มมีกล่องยืนยัน และกันกดเบิ้ลทั้งฝั่งหน้าจอ (busy) และฝั่งเซิร์ฟเวอร์ (409)
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';

const props = defineProps({
    tab: { type: String, default: 'overview' },
    filters: { type: Object, default: () => ({}) },
    state: { type: Object, default: () => ({}) },
    snapshot: { type: Object, default: null },
    transfers: { type: Object, default: () => ({ data: [] }) },
    counts: { type: Object, default: () => ({}) },
    settings: { type: Object, default: () => ({ limits: {} }) },
    explorers: { type: Object, default: () => ({}) },
    chains: { type: Object, default: () => ({ tpix: { chain_id: 4289, name: 'TPIX Chain' }, bsc: { chain_id: 56, name: 'BNB Smart Chain' } }) },
});

const activeTab = ref(props.tab);
const notice = ref(null);
const busy = ref(false);

function go(params) {
    router.get('/admin/bridge/wtpix', params, { preserveState: true, preserveScroll: true, replace: true });
}
function goTab(tab) {
    activeTab.value = tab;
    go({ tab });
}
function filter(status, direction = props.filters.direction) {
    go({ tab: 'transfers', ...(status ? { status } : {}), ...(direction ? { direction } : {}) });
}

const short = (v) => (v ? `${v.slice(0, 10)}…${v.slice(-6)}` : '—');
const txUrl = (chainId, hash) => (props.explorers?.[chainId] && hash ? `${props.explorers[chainId]}/tx/${hash}` : null);
const chainName = (id) => (id === props.chains.tpix?.chain_id ? 'TPIX' : id === props.chains.bsc?.chain_id ? props.chains.bsc.name : `#${id ?? '?'}`);
const directionLabel = { to_bsc: 'TPIX → wTPIX (BSC)', to_tpix: 'wTPIX (BSC) → TPIX' };

const statusStyle = {
    confirmed: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    awaiting_liquidity: 'bg-orange-500/20 text-orange-300 border-orange-500/30',
    sending: 'bg-violet-500/20 text-violet-300 border-violet-500/30',
    completed: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    needs_review: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    rejected: 'bg-gray-600/30 text-gray-400 border-gray-500/30',
    ignored: 'bg-gray-700/30 text-gray-500 border-gray-600/30',
};

const reasonLabel = {
    dust: 'ยอดศูนย์',
    internal_sender: 'โอนภายใน',
    below_fee: 'ไม่เกินค่าธรรมเนียม',
    below_minimum: 'ต่ำกว่าขั้นต่ำ',
    sender_is_contract: 'ผู้ส่งเป็นสัญญา (อีกเชนอาจไม่มีใครคุมที่อยู่นี้)',
    sender_mismatch: 'ผู้กดส่งไม่ใช่เจ้าของเหรียญ (burnFrom/bridgeBurn)',
    kyc_required: 'ยังไม่ผ่าน KYC',
    over_tx_limit: 'เกินวงเงินต่อครั้ง',
    over_daily_address_limit: 'เกินวงเงินต่อกระเป๋าต่อวัน',
    exceeds_onchain_max: 'เกิน maxPerTx บนสัญญา',
    mint_reverted: 'mint revert บน BSC',
    release_reverted: 'release revert บนเชน TPIX',
    mint_estimate_failed: 'BSC บอกว่า mint จะ revert',
    release_estimate_failed: 'เชน TPIX บอกว่า release จะ revert',
    nonce_consumed: 'nonce ถูกใช้โดยธุรกรรมอื่น',
    minted_elsewhere: 'ถูก mint โดยธุรกรรมที่ระบบไม่ได้ส่ง — ตรวจผู้รับก่อนอนุมัติ',
    released_elsewhere: 'ถูกปล่อยโดยธุรกรรมที่ระบบไม่ได้ส่ง — ตรวจผู้รับก่อนอนุมัติ',
    config_changed: 'สัญญาต้นทางไม่ใช่ตัวที่ตั้งไว้แล้ว',
};

const checkLabel = {
    installed: 'WTPIX_BRIDGE_ENABLED ใน .env', site_switch: 'สวิตช์หลังบ้าน', vault_address: 'ที่อยู่คลัง (ตรึงใน .env ตรงกับทะเบียน)',
    vault_code: 'มีสัญญาคลังบนเชน 4289', wtpix_address: 'มีสัญญา wTPIX บน BSC', minter_address: 'มีสัญญา WTPIXBridgeMinter บน BSC',
    bsc_chain: 'RPC ของ BSC ตรง chain id', minter_token: 'minter.token() = wTPIX', initial_minter_set: 'wTPIX.initialMinterSet()',
    minter_is_minter: 'wTPIX.minters(minter) = true', relayer_keystore: 'keystore + passphrase ของ relayer',
    minter_relayer_role: 'minter.relayers(relayer)', vault_relayer_role: 'vault.relayers(relayer)', relayer_bnb: 'BNB ค่าแก๊สของ relayer',
    vault_not_paused: 'คลังไม่ถูก pause', minter_not_paused: 'minter ไม่ถูก pause', token_not_paused: 'wTPIX ไม่ถูก pause',
    caps_aligned: 'vault.maxLock ≤ minter.maxPerTx', no_foreign_supply: 'wTPIX supply ≤ minter.totalMinted',
    guardian_keystore: 'keystore ของ guardian', minter_guardian_role: 'minter.guardians(guardian)', vault_guardian_role: 'vault.guardians(guardian)',
    guardian_bnb: 'BNB ของ guardian (ไว้ pause บน BSC)',
};

// ── สวิตช์ ──────────────────────────────────────────────────────────────
const confirmToggle = ref(false);
const toggleReason = ref('');

async function doToggle() {
    if (busy.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post('/admin/bridge/wtpix/toggle', { enabled: !props.state.site_switch, reason: toggleReason.value || null });
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
        const { data } = await axios.get('/admin/bridge/wtpix/measure');
        live.value = data.data;
    } catch (e) {
        notice.value = { type: 'error', text: e.response?.status === 429 ? 'รีเฟรชถี่เกินไป รอสักครู่' : 'อ่านยอดไม่สำเร็จ' };
    } finally {
        measuring.value = false;
    }
}

const view = computed(() => live.value ?? props.snapshot);
const isNonZero = (v) => v !== null && v !== undefined && v !== '0';

// ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────
const pending = ref(null); // { action, row }
const rejectReason = ref('');

function ask(action, row) {
    pending.value = { action, row };
    rejectReason.value = '';
}

const confirmMessage = computed(() => {
    const p = pending.value;
    if (!p) return '';
    const r = p.row;
    const toBsc = r.direction === 'to_bsc';
    if (p.action === 'approve') {
        return toBsc
            ? `อนุมัติ mint ${r.receive_amount} wTPIX บน ${props.chains.bsc?.name} ให้ ${r.recipient}? ระบบจะตรวจบนเชนซ้ำก่อน mint จริง`
            : `อนุมัติปล่อย ${r.receive_amount} TPIX จากคลังให้ ${r.recipient} บนเชน TPIX? TPIX จริงจะออกจากคลัง`;
    }
    return toBsc
        ? `ปฏิเสธ ${r.amount} TPIX ที่ ${r.from_address} ล็อกไว้? TPIX ค้างในคลัง (ไม่มีทางถอนนอกจาก release) — ต้องคืนด้วยมือผ่านกระบวนการของเจ้าของ`
        : `ปฏิเสธการเผา ${r.amount} wTPIX ของ ${r.from_address}? ผู้ใช้เผาเหรียญไปแล้ว ต้องคืนด้วยมือ`;
});

async function runPending() {
    const p = pending.value;
    if (!p || busy.value) return;
    if (p.action === 'reject' && !rejectReason.value.trim()) return;

    busy.value = true;
    try {
        const { data } = await axios.post(`/admin/bridge/wtpix/transfers/${p.row.id}/${p.action}`, p.action === 'reject' ? { reason: rejectReason.value.trim() } : {});
        notice.value = { type: 'success', text: data.message ?? 'ดำเนินการแล้ว' };
        pending.value = null;
        router.reload({ only: ['transfers', 'counts', 'state'] });
    } catch (e) {
        const errors = e.response?.data?.errors;
        notice.value = { type: 'error', text: Array.isArray(errors) ? errors.join(' · ') : (e.response?.data?.message ?? 'ดำเนินการไม่สำเร็จ') };
        pending.value = null;
        router.reload({ only: ['transfers', 'counts'] });
    } finally {
        busy.value = false;
    }
}

// ── วงเงิน ──────────────────────────────────────────────────────────────
const limitLabels = {
    min_lock: 'ล็อกขั้นต่ำ (TPIX → BSC)',
    max_lock_per_tx: 'ล็อกสูงสุดต่อครั้ง (เกิน = รอตรวจ)',
    max_lock_per_address_daily: 'ล็อกสูงสุดต่อกระเป๋าต่อ 24 ชม.',
    lock_fee: 'ค่าธรรมเนียมล็อก (ชดเชย BNB ตอน mint)',
    min_burn: 'เผาขั้นต่ำ (BSC → TPIX)',
    max_burn_per_tx: 'เผาสูงสุดต่อครั้ง (เกิน = รอตรวจ)',
    max_burn_per_address_daily: 'เผาสูงสุดต่อกระเป๋าต่อ 24 ชม.',
    burn_fee: 'ค่าธรรมเนียมเผา',
};
const limitsForm = ref({ ...props.settings.limits });
const settingsErrors = ref([]);
const confirmSettings = ref(false);

async function saveSettings() {
    if (busy.value) return;
    busy.value = true;
    settingsErrors.value = [];
    try {
        await axios.put('/admin/bridge/wtpix/settings', { limits: limitsForm.value });
        notice.value = { type: 'success', text: 'บันทึกวงเงินแล้ว' };
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

const statusFilters = ['needs_review', 'confirmed', 'awaiting_liquidity', 'sending', 'completed', 'ignored', 'rejected'];
const blockingFailed = computed(() => (props.state.checks || []).filter((c) => !c.ok && !c.optional).length);
</script>

<template>
    <Head title="สะพาน TPIX⇄wTPIX — หลังบ้าน" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">สะพาน TPIX ⇄ wTPIX ({{ chains.bsc?.name }})</h1>
                    <p class="text-sm text-gray-400 mt-1">ล็อก TPIX ในคลังบนเชน 4289 → mint wTPIX บน BSC · เผา wTPIX → ปล่อย TPIX จากคลัง · กระทบยอดทุก 5 นาที</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/admin/bridge" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">← สะพาน USDT</Link>
                    <a href="/bridge/reserves?asset=tpix" target="_blank" class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">Proof of Reserves ↗</a>
                </div>
            </div>

            <div v-if="notice" :class="['rounded-xl border p-4 text-sm', notice.type === 'success' ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-300' : 'border-red-500/30 bg-red-500/5 text-red-300']">
                {{ notice.text }}
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="tb in [['overview', 'ภาพรวม'], ['transfers', `รายการ${counts?.needs_review ? ` (${counts.needs_review} รอตรวจ)` : ''}`], ['settings', 'วงเงิน/ค่าธรรมเนียม']]"
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
                        <p class="text-xs text-gray-400">
                            ติดตั้ง (.env): <b :class="state.installed ? 'text-emerald-300' : 'text-red-300'">{{ state.installed ? 'ใช่' : 'ยังไม่ได้ตั้ง WTPIX_BRIDGE_ENABLED' }}</b>
                            · สวิตช์: <b>{{ state.site_switch ? 'เปิด' : 'ปิด' }}</b>
                            · mint: <b :class="state.mint_ready ? 'text-emerald-300' : 'text-amber-300'">{{ state.mint_ready ? 'พร้อม' : 'ไม่พร้อม' }}</b>
                            · ปล่อย: <b :class="state.release_ready ? 'text-emerald-300' : 'text-amber-300'">{{ state.release_ready ? 'พร้อม' : 'ไม่พร้อม' }}</b>
                        </p>
                        <p v-if="state.switch_reason" class="text-xs text-amber-300 break-words">{{ state.switch_reason }}</p>
                        <p v-if="state.shared_key_with_usdt" class="text-xs text-amber-300">⚠️ relayer ใช้กุญแจใบเดียวกับสะพาน USDT — ทำงานได้ (ระบบรอคิว nonce ให้) แต่คีย์หลุดใบเดียวกระทบสองสะพาน แนะนำแยกกุญแจ</p>
                        <button @click="confirmToggle = true" :disabled="busy"
                            :class="['w-full py-2.5 rounded-xl text-sm font-semibold border transition-all disabled:opacity-40', state.site_switch ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30']">
                            {{ state.site_switch ? 'หยุดสะพาน (mint + ปล่อย)' : 'เปิดสะพาน' }}
                        </button>
                        <p class="text-[11px] text-gray-500">หยุดแล้วตัวสแกนยังบันทึกรายการ และตัวเฝ้าเหตุ (mint/ปล่อยที่ระบบไม่ได้สั่ง · เพิ่ม minter) ยังเดิน · pause บนสัญญาปลดได้เฉพาะกระเป๋าเจ้าของ</p>
                    </div>

                    <div class="glass-card rounded-xl p-5">
                        <h3 class="font-semibold text-white mb-1">ด่านความพร้อม</h3>
                        <p class="text-[11px] text-gray-500 mb-3">{{ blockingFailed ? `ยังไม่ผ่าน ${blockingFailed} ข้อที่บังคับ` : 'ข้อบังคับผ่านครบ' }} · ข้อที่มี (แนะนำ) ไม่บล็อกการทำงาน</p>
                        <ul class="space-y-1.5 text-xs" data-test="readiness">
                            <li v-for="c in state.checks || []" :key="c.key" class="flex items-start gap-2">
                                <span :class="c.ok ? 'text-emerald-400' : (c.optional ? 'text-amber-400' : 'text-red-400')">{{ c.ok ? '✓' : (c.optional ? '!' : '✗') }}</span>
                                <span class="text-gray-300">{{ checkLabel[c.key] ?? c.key }}<span v-if="c.optional" class="text-gray-500"> (แนะนำ)</span></span>
                                <span v-if="!c.ok" class="text-gray-500 break-all">— {{ c.hint }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="glass-card rounded-xl p-5 text-xs space-y-2">
                        <h3 class="font-semibold text-white text-sm">กุญแจบนเซิร์ฟเวอร์</h3>
                        <div v-for="(s, role) in state.signers || {}" :key="role" class="space-y-0.5">
                            <div class="flex justify-between gap-2"><span class="text-gray-400">{{ role }}</span>
                                <span :class="s.ready ? 'text-emerald-400' : 'text-amber-400'">{{ s.ready ? 'พร้อม' : (s.passphrase_set ? 'ขาดไฟล์' : 'ขาด passphrase') }}</span></div>
                            <code class="font-mono text-white break-all block">{{ s.address ?? 'ยังไม่มีไฟล์ keystore' }}</code>
                            <div class="text-gray-500">BNB: <span class="font-mono text-gray-300">{{ s.bnb ?? '—' }}</span></div>
                        </div>
                        <div class="pt-2 border-t border-white/5 space-y-1">
                            <div><span class="text-gray-400">vault (4289)</span> <code class="font-mono text-white break-all">{{ state.addresses?.vault ?? '—' }}</code></div>
                            <div v-if="state.addresses?.vault_registry && state.addresses.vault_registry !== state.addresses.vault" class="text-red-300">ทะเบียน: {{ state.addresses.vault_registry }}</div>
                            <div><span class="text-gray-400">wTPIX</span> <code class="font-mono text-white break-all">{{ state.addresses?.wtpix ?? '—' }}</code></div>
                            <div><span class="text-gray-400">minter</span> <code class="font-mono text-white break-all">{{ state.addresses?.minter ?? '—' }}</code></div>
                        </div>
                    </div>
                    <div class="glass-card rounded-xl p-5 text-xs space-y-1.5">
                        <h3 class="font-semibold text-white text-sm mb-1">คลัง TPIXBridgeVault (4289)</h3>
                        <div class="flex justify-between"><span class="text-gray-400">paused</span><span :class="state.vault?.paused ? 'text-red-300' : 'text-white'">{{ state.vault?.paused == null ? '—' : (state.vault.paused ? 'ใช่' : 'ไม่') }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">TPIX ในคลัง</span><span class="text-white font-mono">{{ state.vault?.balance ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">ล็อก ต่ำสุด–สูงสุด</span><span class="text-white font-mono">{{ state.vault?.min_lock ?? '—' }} – {{ state.vault?.max_lock ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">ปล่อย maxPerTx / dailyCap</span><span class="text-white font-mono">{{ state.vault?.max_per_tx ?? '—' }} / {{ state.vault?.daily_cap ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">เหลือวันนี้ (UTC)</span><span class="text-white font-mono">{{ state.vault?.remaining_today ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">totalLocked / totalReleased</span><span class="text-white font-mono">{{ state.vault?.total_locked ?? '—' }} / {{ state.vault?.total_released ?? '—' }}</span></div>
                    </div>
                    <div class="glass-card rounded-xl p-5 text-xs space-y-1.5">
                        <h3 class="font-semibold text-white text-sm mb-1">WTPIXBridgeMinter + wTPIX ({{ chains.bsc?.name }})</h3>
                        <div class="flex justify-between"><span class="text-gray-400">minter paused</span><span :class="state.minter?.paused ? 'text-red-300' : 'text-white'">{{ state.minter?.paused == null ? '—' : (state.minter.paused ? 'ใช่' : 'ไม่') }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">mint maxPerTx / dailyCap</span><span class="text-white font-mono">{{ state.minter?.max_per_tx ?? '—' }} / {{ state.minter?.daily_cap ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">เหลือวันนี้ (UTC)</span><span class="text-white font-mono">{{ state.minter?.remaining_today ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">totalMinted</span><span class="text-white font-mono">{{ state.minter?.total_minted ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">wTPIX supply</span><span class="text-white font-mono">{{ state.token?.total_supply ?? '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-400">wTPIX paused</span><span :class="state.token?.paused ? 'text-red-300' : 'text-white'">{{ state.token?.paused == null ? '—' : (state.token.paused ? 'ใช่' : 'ไม่') }}</span></div>
                    </div>
                </div>

                <div class="glass-card rounded-xl p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-white">เงินหนุน {{ live ? '(สด)' : `(กระทบยอดล่าสุด ${snapshot?.checked_at ? new Date(snapshot.checked_at).toLocaleString() : '—'})` }}</h3>
                        <button @click="measure" :disabled="measuring" class="px-3 py-1.5 text-xs rounded-lg bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-40">
                            {{ measuring ? 'กำลังอ่าน…' : 'อ่านยอดสดสองเชน' }}
                        </button>
                    </div>
                    <p v-if="!view" class="text-sm text-gray-500">ยังไม่มีผลกระทบยอด (bridge:wtpix-reconcile ยังไม่เคยรัน)</p>
                    <template v-else>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs">
                            <div><p class="text-gray-500">wTPIX supply</p><p class="text-white font-mono text-base">{{ view.supply ?? '—' }}</p></div>
                            <div><p class="text-gray-500">TPIX ในคลัง</p><p class="text-white font-mono text-base">{{ view.vault ?? '—' }}</p></div>
                            <div><p class="text-gray-500">ค้างปล่อย</p><p class="text-white font-mono text-base">{{ view.owed }}</p></div>
                            <div><p class="text-gray-500">คลังสุทธิ</p><p class="text-white font-mono text-base">{{ view.backing }}</p></div>
                            <div><p class="text-gray-500">ขาด (ติดลบ = เกิน)</p><p :class="['font-mono text-base', String(view.deficit).startsWith('-') || view.deficit === '0' ? 'text-emerald-300' : 'text-red-300']">{{ view.deficit }}</p></div>
                        </div>
                        <p v-if="isNonZero(view.unexplained_mint)" class="text-sm text-red-300">⚠️ mint ที่ระบบไม่ได้สั่ง: {{ view.unexplained_mint }} wTPIX</p>
                        <p v-if="isNonZero(view.unexplained_release)" class="text-sm text-red-300">⚠️ ปล่อยที่ระบบไม่ได้สั่ง: {{ view.unexplained_release }} TPIX</p>
                        <p v-if="isNonZero(view.foreign_supply)" class="text-sm text-red-300">⚠️ wTPIX ที่ไม่ได้มาจาก minter ของสะพาน: {{ view.foreign_supply }}</p>
                        <p v-if="view.note" class="text-xs text-amber-300">{{ view.note }}</p>
                        <p v-if="view.action" class="text-xs text-gray-500">การกระทำ: {{ view.action }}</p>
                    </template>
                </div>
            </div>

            <!-- ═══════════ รายการ ═══════════ -->
            <div v-if="activeTab === 'transfers'" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button @click="filter(null, null)" :class="['px-3 py-1.5 text-xs rounded-lg border', !filters.status && !filters.direction ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">ทั้งหมด</button>
                    <button v-for="d in ['to_bsc', 'to_tpix']" :key="d" @click="filter(filters.status, filters.direction === d ? null : d)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.direction === d ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">{{ directionLabel[d] }}</button>
                    <span class="w-px bg-white/10"></span>
                    <button v-for="s in statusFilters" :key="s" @click="filter(filters.status === s ? null : s)"
                        :class="['px-3 py-1.5 text-xs rounded-lg border', filters.status === s ? 'bg-primary-500/20 text-primary-300 border-primary-500/30' : 'bg-white/5 text-gray-400 border-white/10']">
                        {{ s }} ({{ counts?.[s] ?? 0 }})
                    </button>
                </div>
                <div class="glass-card rounded-xl overflow-x-auto">
                    <table class="w-full text-xs min-w-[900px]">
                        <thead class="bg-white/5 text-gray-500 uppercase tracking-wider"><tr>
                            <th class="text-left px-3 py-2">ทิศ / tx ต้นทาง</th><th class="text-left px-3">ผู้ส่ง = ผู้รับ</th><th class="text-right px-3">จำนวน / ได้รับ</th>
                            <th class="text-left px-3">สถานะ</th><th class="text-left px-3">tx ปลายทาง</th><th class="text-right px-3">จัดการ</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="t in transfers.data" :key="t.id" class="align-top">
                                <td class="px-3 py-2">
                                    <div class="text-white">{{ directionLabel[t.direction] }}</div>
                                    <a :href="txUrl(t.source_chain_id, t.tx_hash)" target="_blank" rel="noopener" class="font-mono text-primary-300 hover:underline">{{ short(t.tx_hash) }}</a>
                                    <span class="text-gray-600"> #{{ t.log_index }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-gray-300">{{ short(t.recipient) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-white">{{ t.amount }}<div class="text-gray-500">ได้ {{ t.receive_amount }} (ค่าธรรมเนียม {{ t.fee }})</div></td>
                                <td class="px-3 py-2">
                                    <span :class="['px-2 py-0.5 rounded-md border', statusStyle[t.status]]">{{ t.status }}</span>
                                    <div v-if="t.review_reason" class="text-amber-300 mt-1">{{ reasonLabel[t.review_reason] ?? t.review_reason }}</div>
                                    <div v-if="t.last_error" class="text-gray-500 mt-1 max-w-[260px] break-words">{{ t.last_error }}</div>
                                    <div v-if="t.limits_overridden" class="text-gray-500 mt-1">แอดมินอนุมัติแล้ว</div>
                                </td>
                                <td class="px-3 py-2"><a v-if="t.dest_tx_hash" :href="txUrl(t.dest_chain_id, t.dest_tx_hash)" target="_blank" rel="noopener" class="font-mono text-primary-300 hover:underline">{{ short(t.dest_tx_hash) }}</a></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button v-if="t.status === 'needs_review' || (t.status === 'ignored' && t.review_reason === 'below_minimum')" @click="ask('approve', t)" :disabled="busy"
                                        class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">อนุมัติ</button>
                                    <button v-if="['needs_review', 'confirmed', 'awaiting_liquidity'].includes(t.status)" @click="ask('reject', t)" :disabled="busy"
                                        class="ml-1 px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 border border-white/10 disabled:opacity-40">ปฏิเสธ</button>
                                </td>
                            </tr>
                            <tr v-if="!transfers.data.length"><td colspan="6" class="px-3 py-10 text-center text-gray-500">ไม่มีรายการ</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2 text-xs">
                    <button v-if="transfers.prev_page_url" @click="router.get(transfers.prev_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">← ก่อนหน้า</button>
                    <button v-if="transfers.next_page_url" @click="router.get(transfers.next_page_url, {}, { preserveState: true })" class="px-3 py-1.5 rounded-lg bg-white/5 text-gray-300 border border-white/10">ถัดไป →</button>
                </div>
            </div>

            <!-- ═══════════ วงเงิน ═══════════ -->
            <div v-if="activeTab === 'settings'" class="glass-card rounded-xl p-5 space-y-3 max-w-2xl">
                <h3 class="font-semibold text-white">วงเงินและค่าธรรมเนียม (TPIX)</h3>
                <p class="text-xs text-gray-500">เกินวงเงิน = รอแอดมินตรวจ ไม่ทำเอง · ค่าธรรมเนียมค้างในคลังเป็นส่วนเกิน · เพดานบนสัญญา (maxLock / maxPerTx / dailyCap) เปลี่ยนได้เฉพาะกระเป๋าเจ้าของ</p>
                <div v-for="(label, key) in limitLabels" :key="key" class="flex items-center justify-between gap-3">
                    <label class="text-xs text-gray-300">{{ label }}</label>
                    <input v-model.trim="limitsForm[key]" type="text" inputmode="decimal" class="w-40 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-white text-sm font-mono text-right" />
                </div>
                <div v-if="settingsErrors.length" class="rounded-xl border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-300">
                    <div v-for="(e, i) in settingsErrors" :key="i">{{ e }}</div>
                </div>
                <button @click="confirmSettings = true" :disabled="busy" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-primary-500/20 text-primary-300 border border-primary-500/30 disabled:opacity-40">บันทึก</button>
            </div>
        </div>

        <ConfirmDialog :show="confirmToggle" :danger="state.site_switch" :loading="busy"
            :title="state.site_switch ? 'หยุดสะพาน TPIX⇄wTPIX?' : 'เปิดสะพาน TPIX⇄wTPIX?'"
            :message="state.site_switch ? 'mint และการปล่อย TPIX จะหยุดทันที (การสแกนยังเดินต่อ ไม่มีรายการหาย)' : 'ระบบจะเริ่ม mint/ปล่อยตามคิวภายใน 1 นาที — ตรวจด่านความพร้อมให้ครบก่อน'"
            :confirm-text="state.site_switch ? 'หยุด' : 'เปิด'" cancel-text="ยกเลิก"
            @confirm="doToggle" @cancel="confirmToggle = false" />

        <ConfirmDialog :show="!!pending && pending.action === 'approve'" :loading="busy" title="ยืนยันการอนุมัติ"
            :message="confirmMessage" confirm-text="อนุมัติ" cancel-text="ยกเลิก"
            @confirm="runPending" @cancel="pending = null" />

        <ConfirmDialog :show="confirmSettings" :loading="busy" title="บันทึกวงเงิน/ค่าธรรมเนียม?"
            message="มีผลกับรายการที่เข้ามาใหม่ทันที" confirm-text="บันทึก" cancel-text="ยกเลิก"
            @confirm="saveSettings" @cancel="confirmSettings = false" />

        <!-- ปฏิเสธ — ต้องมีเหตุผลเสมอ -->
        <div v-if="pending && pending.action === 'reject'" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm" @click.self="!busy && (pending = null)">
            <div class="w-full max-w-md glass-card rounded-2xl p-6 space-y-4">
                <h3 class="text-lg font-bold text-white">ยืนยันการปฏิเสธ</h3>
                <p class="text-sm text-gray-300 break-words">{{ confirmMessage }}</p>
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
