<script setup>
/**
 * TPIX TRADE — ตรวจ KYC ผู้สมัคร Validator (สัญญา ValidatorKYC)
 *
 * กระเป๋า owner ในเบราว์เซอร์เป็นคนเซ็น — เว็บไม่ถือกุญแจ owner
 * หลังเชนยืนยัน เซิร์ฟเวอร์อ่าน tx จริงซ้ำก่อนบันทึกเป็นประวัติ (ไม่เชื่อสิ่งที่หน้านี้บอก)
 *
 * Developed by Xman Studio
 */
import { ref, computed, reactive, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useInjectedOwnerWallet, loadEthers } from '@/Composables/useInjectedOwnerWallet';
import {
    KYC_STATUS, availableActions, resolveSubmitHash, encodeKycCall, humanWalletError, sameAddress,
} from '@/utils/validatorKyc';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const dateLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));

const props = defineProps({
    contract: { type: String, default: null },
    owner: { type: String, default: null },
    chainId: { type: Number, default: 4289 },
    rpcUrl: { type: String, required: true },
    explorerUrl: { type: String, default: 'https://explorer.tpix.online' },
    applicants: { type: Array, default: () => [] },
    loadError: { type: String, default: null },
});

const rows = ref(props.applicants);
const owner = ref(props.owner);
const loadError = ref(props.loadError);
const refreshing = ref(false);
const notice = ref('');

const wallet = useInjectedOwnerWallet({ chainId: props.chainId, rpcUrl: props.rpcUrl, explorerUrl: props.explorerUrl });
const isOwner = computed(() => !!wallet.address.value && sameAddress(wallet.address.value, owner.value));

/** สถานะต่อแถว — busy กันกดซ้ำ, pending = ส่งแล้วแต่เซิร์ฟเวอร์ยังยืนยันไม่ได้ */
const ui = reactive({});
function rowUi(addr) {
    if (!ui[addr]) ui[addr] = { open: '', manualHash: '', note: '', reason: '', busy: false, error: '', pending: null };
    return ui[addr];
}
// สร้างสถานะของทุกแถวก่อน render — ไม่สร้างกลางเทมเพลต (เขียน state ระหว่าง render = render ซ้ำ)
watch(rows, (list) => { for (const r of list) rowUi(r.applicant); }, { immediate: true });

// เก็บเป็นคีย์ — แปลตอน render เพื่อให้สลับภาษาแล้วเปลี่ยนตาม
const LOAD_ERRORS = {
    KYC_NOT_CONFIGURED: 'admin.validatorsKyc.loadErrors.KYC_NOT_CONFIGURED',
    CHAIN_UNREACHABLE: 'admin.validatorsKyc.loadErrors.CHAIN_UNREACHABLE',
};

const short = (a) => (a ? `${a.slice(0, 8)}…${a.slice(-6)}` : '—');
const when = (ts) => (ts ? new Date(ts * 1000).toLocaleString(dateLocale.value) : '—');
const whenIso = (iso) => (iso ? new Date(iso).toLocaleString(dateLocale.value) : '—');
/** ป้ายสถานะบนเชน — แปลจากชื่อสถานะของสัญญา ไม่รู้จักก็โชว์ค่าดิบ */
const chainStatusLabel = (status) => (KYC_STATUS[status] ? t(`admin.validatorsKyc.chainStatus.${status}`) : status);
const ACTION_KEYS = ['submit', 'approve', 'reject'];
const actionLabel = (act) => (ACTION_KEYS.includes(act) ? t(`admin.validatorsKyc.actions.${act}`) : act);
const toneClass = {
    gray: 'bg-white/5 text-gray-400 border-white/10',
    yellow: 'bg-yellow-500/15 text-yellow-300 border-yellow-500/30',
    cyan: 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30',
    green: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    red: 'bg-red-500/15 text-red-300 border-red-500/30',
};
const SITE_KYC_KEYS = ['approved', 'pending', 'rejected', 'none', 'no_account'];
const siteKycLabel = (status) => (SITE_KYC_KEYS.includes(status) ? t(`admin.validatorsKyc.siteKyc.${status}`) : status);

async function refresh() {
    refreshing.value = true;
    try {
        const { data } = await axios.get('/admin/validators/kyc/applicants');
        rows.value = data.data.applicants;
        owner.value = data.data.owner;
        loadError.value = data.data.loadError;
    } catch {
        notice.value = t('admin.validatorsKyc.notice.refreshFailed');
    } finally {
        refreshing.value = false;
    }
}

async function connectWallet() {
    notice.value = '';
    try {
        await wallet.connect();
    } catch (e) {
        notice.value = humanWalletError(e);
    }
}

/** ส่ง tx → รอเชน → ให้เซิร์ฟเวอร์ตรวจ tx จริงแล้วบันทึก */
async function run(row, action) {
    const st = rowUi(row.applicant);
    if (st.busy) return;
    st.error = '';

    let args = {};
    let confirmExtra = {};
    if (action === 'submit') {
        const r = resolveSubmitHash(row, st.manualHash, st.note);
        if (r.error) { st.error = r.error; return; }
        args = { hash: r.hash };
        confirmExtra = r.manual ? { note: st.note.trim() } : { kyc_submission_uuid: r.uuid };
    }
    if (action === 'reject') {
        if (!st.reason.trim()) { st.error = t('admin.validatorsKyc.errors.reasonRequired'); return; }
        if (!window.confirm(t('admin.validatorsKyc.confirm.reject', { addr: short(row.applicant) }))) return;
        args = { reason: st.reason.trim() };
    }
    if (action === 'approve' && !window.confirm(t('admin.validatorsKyc.confirm.approve', { addr: short(row.applicant) }))) return;

    st.busy = true;
    try {
        const ethers = await loadEthers();
        const data = encodeKycCall(ethers, action, row.applicant, args);
        const tx = await wallet.sendAs(owner.value, { to: props.contract, data });
        st.pending = { hash: tx.hash, action, extra: confirmExtra };
        try {
            const receipt = await wallet.waitReceipt(tx.hash);
            if (receipt && Number(receipt.status) !== 1) {
                st.pending = null;
                st.error = t('admin.validatorsKyc.errors.txReverted');
                return;
            }
        } catch {
            // ต่อ RPC ไม่ได้ระหว่างรอ — tx อาจผ่านแล้ว ห้ามบอกว่าล้ม ให้กดตรวจซ้ำ
            st.error = t('admin.validatorsKyc.errors.unconfirmed');
            return;
        }
        await confirmOnServer(row, st);
    } catch (e) {
        st.error = humanWalletError(e);
    } finally {
        st.busy = false;
    }
}

async function confirmOnServer(row, st) {
    if (!st.pending) return;
    try {
        await axios.post(`/admin/validators/kyc/${row.applicant}/confirm`, {
            action: st.pending.action,
            tx_hash: st.pending.hash,
            ...st.pending.extra,
        });
        st.pending = null;
        st.open = '';
        st.error = '';
        notice.value = t('admin.validatorsKyc.notice.saved', { addr: short(row.applicant) });
        await refresh();
    } catch (e) {
        st.error = e.response?.data?.error?.message || t('admin.validatorsKyc.errors.serverUnconfirmed');
    }
}

async function recheck(row) {
    const st = rowUi(row.applicant);
    if (st.busy) return;
    st.busy = true;
    try { await confirmOnServer(row, st); } finally { st.busy = false; }
}
</script>

<template>
    <Head :title="t('admin.validatorsKyc.title')" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link href="/admin/validators" class="text-xs text-gray-500 hover:text-gray-300">← {{ t('admin.validatorsKyc.back') }}</Link>
                    <h1 class="text-2xl font-bold text-white mt-1">{{ t('admin.validatorsKyc.title') }}</h1>
                    <p class="text-sm text-gray-400 mt-1">{{ t('admin.validatorsKyc.subtitle') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="refresh" :disabled="refreshing"
                        class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-50">
                        {{ refreshing ? t('admin.validatorsKyc.loading') : t('admin.validatorsKyc.refresh') }}
                    </button>
                    <button v-if="!wallet.address.value" @click="connectWallet" :disabled="wallet.connecting.value"
                        class="px-4 py-2 text-sm font-bold rounded-xl bg-primary-500/20 text-primary-300 border border-primary-500/30 hover:bg-primary-500/30 disabled:opacity-50">
                        {{ t('admin.validatorsKyc.connectOwner') }}
                    </button>
                    <span v-else :class="['px-3 py-2 text-xs rounded-xl border font-mono',
                        isOwner ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-red-500/10 text-red-300 border-red-500/30']">
                        {{ short(wallet.address.value) }} · {{ isOwner ? t('admin.validatorsKyc.ownerOk') : t('admin.validatorsKyc.notOwner') }}
                    </span>
                </div>
            </div>

            <div class="glass-card rounded-xl p-4 grid gap-2 text-xs text-gray-400 sm:grid-cols-2">
                <div>{{ t('admin.validatorsKyc.contractLabel') }} <a v-if="contract" :href="`${explorerUrl}/address/${contract}`" target="_blank" rel="noopener" class="font-mono text-cyan-400">{{ contract }}</a><span v-else class="text-red-300">{{ t('admin.validatorsKyc.notSet') }}</span></div>
                <div>{{ t('admin.validatorsKyc.ownerLabel') }} <span class="font-mono text-gray-200">{{ owner || '—' }}</span></div>
            </div>

            <div v-if="loadError" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-sm text-red-300">
                {{ LOAD_ERRORS[loadError] ? t(LOAD_ERRORS[loadError]) : loadError }}
            </div>
            <div v-if="notice" class="p-3 rounded-xl bg-white/5 border border-white/10 text-sm text-gray-200">{{ notice }}</div>
            <div v-if="wallet.address.value && !isOwner" class="p-3 rounded-xl bg-yellow-500/10 border border-yellow-500/20 text-sm text-yellow-200">
                {{ t('admin.validatorsKyc.switchWallet', { owner }) }}
            </div>

            <div v-if="!rows.length && !loadError" class="glass-card rounded-xl p-10 text-center text-gray-500">
                {{ t('admin.validatorsKyc.empty') }}
            </div>

            <div v-for="row in rows" :key="row.applicant" class="glass-card rounded-xl p-5 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <a :href="`${explorerUrl}/address/${row.applicant}`" target="_blank" rel="noopener" class="font-mono text-sm text-cyan-400 break-all">{{ row.applicant }}</a>
                    <span :class="['text-xs font-bold px-3 py-1 rounded-lg border', toneClass[KYC_STATUS[row.status]?.tone || 'gray']]">
                        {{ chainStatusLabel(row.status) }}
                    </span>
                </div>

                <div class="grid gap-3 text-xs sm:grid-cols-3">
                    <div class="space-y-1">
                        <div class="text-gray-500">{{ t('admin.validatorsKyc.siteKycLabel') }}</div>
                        <div class="text-gray-200">{{ siteKycLabel(row.site_kyc?.status) }}<span v-if="row.site_kyc?.level"> ({{ row.site_kyc.level }})</span></div>
                        <Link v-if="row.site_kyc?.uuid" :href="`/admin/kyc/${row.site_kyc.uuid}`" class="text-primary-300 hover:underline">{{ t('admin.validatorsKyc.openSiteKyc') }}</Link>
                    </div>
                    <div class="space-y-1">
                        <div class="text-gray-500">{{ t('admin.validatorsKyc.chainHash') }}</div>
                        <div class="font-mono text-gray-300 break-all">{{ row.kyc_hash || '—' }}</div>
                        <div v-if="row.hash_matches_site === true" class="text-emerald-300">{{ t('admin.validatorsKyc.hashMatch') }}</div>
                        <div v-else-if="row.hash_matches_site === false" class="text-yellow-300">{{ t('admin.validatorsKyc.hashMismatch') }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-gray-500">{{ t('admin.validatorsKyc.stakeKey') }}</div>
                        <div class="text-gray-200">{{ row.node ? `${row.node.tier} · ${row.node.status}` : t('admin.validatorsKyc.notStaked') }}</div>
                        <div class="text-gray-400">{{ row.binding ? t('admin.validatorsKyc.keyBound', { addr: short(row.binding.validator_address) }) : t('admin.validatorsKyc.keyNotBound') }}</div>
                    </div>
                </div>

                <div class="text-[11px] text-gray-500 flex flex-wrap gap-x-4">
                    <span>{{ t('admin.validatorsKyc.consentAt', { date: when(row.consent_at) }) }}</span>
                    <span>{{ t('admin.validatorsKyc.submittedAt', { date: when(row.submitted_at) }) }}</span>
                    <span>{{ t('admin.validatorsKyc.reviewedAt', { date: when(row.reviewed_at) }) }}</span>
                    <span v-if="row.reject_reason" class="text-red-300">{{ t('admin.validatorsKyc.reasonLabel') }} {{ row.reject_reason }}</span>
                </div>
                <div v-if="row.status === 'Rejected'" class="text-[11px] text-gray-500">
                    {{ t('admin.validatorsKyc.rejectedHint') }}
                </div>

                <!-- ปุ่ม -->
                <div v-if="availableActions(row.status).length" class="flex flex-wrap gap-2">
                    <button v-for="act in availableActions(row.status)" :key="act"
                        @click="rowUi(row.applicant).open = rowUi(row.applicant).open === act ? '' : act"
                        :disabled="!isOwner || !contract || rowUi(row.applicant).busy"
                        :class="['px-4 py-2 text-xs font-bold rounded-xl border disabled:opacity-40',
                            act === 'reject' ? 'bg-red-500/15 text-red-300 border-red-500/30' : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30']">
                        {{ actionLabel(act) }}
                    </button>
                </div>

                <div v-if="rowUi(row.applicant).open === 'submit'" class="space-y-2 rounded-xl bg-dark-800/60 p-4 text-xs">
                    <template v-if="row.site_kyc?.status === 'approved'">
                        <div class="text-gray-400">{{ t('admin.validatorsKyc.submit.siteHash') }}</div>
                        <div class="font-mono text-gray-200 break-all">{{ row.site_kyc.doc_hash }}</div>
                    </template>
                    <template v-else>
                        <div class="text-yellow-200">{{ t('admin.validatorsKyc.submit.noSiteKyc') }}</div>
                        <input v-model="rowUi(row.applicant).manualHash" :placeholder="t('admin.validatorsKyc.submit.hashPlaceholder')"
                            class="w-full bg-dark-900 border border-white/10 rounded-lg px-3 py-2 font-mono text-gray-200" />
                        <input v-model="rowUi(row.applicant).note" :placeholder="t('admin.validatorsKyc.submit.notePlaceholder')"
                            class="w-full bg-dark-900 border border-white/10 rounded-lg px-3 py-2 text-gray-200" />
                    </template>
                    <button @click="run(row, 'submit')" :disabled="rowUi(row.applicant).busy"
                        class="px-4 py-2 font-bold rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">
                        {{ rowUi(row.applicant).busy ? t('admin.validatorsKyc.waiting') : t('admin.validatorsKyc.submit.sign') }}
                    </button>
                </div>
                <div v-if="rowUi(row.applicant).open === 'approve'" class="rounded-xl bg-dark-800/60 p-4 text-xs space-y-2">
                    <div class="text-gray-400">{{ t('admin.validatorsKyc.approve.note') }}</div>
                    <button @click="run(row, 'approve')" :disabled="rowUi(row.applicant).busy"
                        class="px-4 py-2 font-bold rounded-lg bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 disabled:opacity-40">
                        {{ rowUi(row.applicant).busy ? t('admin.validatorsKyc.waiting') : t('admin.validatorsKyc.approve.sign') }}
                    </button>
                </div>
                <div v-if="rowUi(row.applicant).open === 'reject'" class="rounded-xl bg-dark-800/60 p-4 text-xs space-y-2">
                    <input v-model="rowUi(row.applicant).reason" maxlength="200" :placeholder="t('admin.validatorsKyc.reject.reasonPlaceholder')"
                        class="w-full bg-dark-900 border border-white/10 rounded-lg px-3 py-2 text-gray-200" />
                    <button @click="run(row, 'reject')" :disabled="rowUi(row.applicant).busy"
                        class="px-4 py-2 font-bold rounded-lg bg-red-500/20 text-red-300 border border-red-500/30 disabled:opacity-40">
                        {{ rowUi(row.applicant).busy ? t('admin.validatorsKyc.waiting') : t('admin.validatorsKyc.reject.sign') }}
                    </button>
                </div>

                <div v-if="rowUi(row.applicant).error" class="text-xs text-red-300">{{ rowUi(row.applicant).error }}</div>
                <div v-if="rowUi(row.applicant).pending" class="flex flex-wrap items-center gap-3 text-xs text-gray-400">
                    <span>tx: <a :href="`${explorerUrl}/tx/${rowUi(row.applicant).pending.hash}`" target="_blank" rel="noopener" class="font-mono text-cyan-400">{{ short(rowUi(row.applicant).pending.hash) }}</a></span>
                    <button @click="recheck(row)" :disabled="rowUi(row.applicant).busy" class="px-3 py-1 rounded-lg bg-white/5 border border-white/10 disabled:opacity-40">{{ t('admin.validatorsKyc.recheck') }}</button>
                </div>

                <div v-if="row.actions?.length" class="border-t border-white/5 pt-3 space-y-1 text-[11px] text-gray-500">
                    <div v-for="a in row.actions" :key="a.tx_hash">
                        {{ whenIso(a.at) }} · {{ actionLabel(a.action) }} → {{ chainStatusLabel(a.chain_status) }}
                        · <a :href="`${explorerUrl}/tx/${a.tx_hash}`" target="_blank" rel="noopener" class="font-mono text-cyan-500">{{ short(a.tx_hash) }}</a>
                        <span v-if="a.note"> · {{ a.note }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
