<script setup>
/**
 * TPIX TRADE - Admin Token Factory Management
 * จัดการ Token ที่สร้างจาก Factory — อนุมัติ, ปฏิเสธ, verify, list/delist
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    tokens: Object,
    stats: Object,
    currentStatus: String,
    currentSearch: String,
    factoryReady: Object,
    factoryConfig: Object,
    canManagePolicy: { type: Boolean, default: false },
    canModerate: { type: Boolean, default: false },
    logoRules: { type: Object, default: () => ({ max_kb: 512, min_px: 128, types: 'PNG, WebP' }) },
    refundMode: { type: String, default: 'manual' },
});

const flash = computed(() => usePage().props.flash || {});
const pageErrors = computed(() => usePage().props.errors || {});

/*
 * โลโก้ของเหรียญที่ deploy แล้ว — แอดมินตั้ง/เปลี่ยนแทนผู้สร้างได้ (ขึ้น tokenlist.json เมื่อลิสต์)
 * ตรวจไฟล์ฝั่งหน้าเว็บก่อนส่ง เซิร์ฟเวอร์ตรวจซ้ำเสมอ
 */
const logoInput = ref(null);
const logoTarget = ref(null);
// เก็บเป็น key + params แล้วแปลตอน render — ข้อความค้างบนจอจึงเปลี่ยนภาษาตามได้
const logoErrorState = ref(null);
const logoError = computed(() => (logoErrorState.value
    ? t(logoErrorState.value.key, logoErrorState.value.params)
    : ''));
const logoUploading = ref(false);

function pickLogo(token) {
    logoTarget.value = token;
    logoErrorState.value = null;
    logoInput.value?.click();
}

function readImageSize(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => { resolve({ width: img.naturalWidth, height: img.naturalHeight }); URL.revokeObjectURL(url); };
        img.onerror = () => { resolve(null); URL.revokeObjectURL(url); };
        img.src = url;
    });
}

async function onLogoPicked(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    const token = logoTarget.value;
    if (!file || !token || logoUploading.value) return;

    const { max_kb: maxKb, min_px: minPx } = props.logoRules;
    if (!['image/png', 'image/webp'].includes(file.type)) {
        logoErrorState.value = { key: 'admin.tokenFactory.logo.errType', params: { symbol: token.symbol } };
        return;
    }
    if (file.size > maxKb * 1024) {
        logoErrorState.value = { key: 'admin.tokenFactory.logo.errSize', params: { symbol: token.symbol, kb: maxKb } };
        return;
    }
    const size = await readImageSize(file);
    if (!size || size.width !== size.height || size.width < minPx) {
        logoErrorState.value = { key: 'admin.tokenFactory.logo.errDim', params: { symbol: token.symbol, px: minPx } };
        return;
    }

    // เขียนทับโลโก้เดิม (ของผู้สร้างหรือของแอดมิน) — ถามก่อน เพราะเปลี่ยนทั้งเว็บ explorer และ wallet
    if (token.logo_url && !confirm(t('admin.tokenFactory.logo.confirmReplace', { symbol: token.symbol }))) {
        return;
    }

    logoUploading.value = true;
    router.post(`/admin/token-factory/${token.id}/logo`, { logo: file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { logoUploading.value = false; },
    });
}

const showRejectModal = ref(false);
const rejectTokenId = ref(null);
const rejectReason = ref('');
const searchQuery = ref(props.currentSearch || '');

const statusFilters = computed(() => [
    { value: null, label: t('common.all'), count: props.stats?.total },
    { value: 'pending', label: t('admin.tokenFactory.stats.pending'), count: props.stats?.pending },
    { value: 'deploying', label: t('admin.tokenFactory.stats.deploying'), count: props.stats?.deploying },
    { value: 'deployed', label: t('admin.tokenFactory.stats.deployed'), count: props.stats?.deployed },
    { value: 'failed', label: t('admin.tokenFactory.stats.failed'), count: props.stats?.failed },
    { value: 'rejected', label: t('admin.tokenFactory.stats.rejected'), count: props.stats?.rejected },
]);

const KNOWN_STATUSES = ['pending', 'deploying', 'deployed', 'failed', 'rejected'];
const statusLabel = (s) => (KNOWN_STATUSES.includes(s) ? t(`admin.tokenFactory.status.${s}`) : s);

const enabledTypes = computed(() => Object.entries(props.factoryConfig?.policy?.types || {})
    .filter(([, ty]) => ty.state === 'on')
    .map(([, ty]) => ty.label_th)
    .join(' · ') || t('admin.tokenFactory.none'));

function filterByStatus(status) {
    const params = {};
    if (status) params.status = status;
    if (searchQuery.value) params.search = searchQuery.value;
    router.get('/admin/token-factory', params, { preserveState: true });
}

function handleSearch() {
    const params = {};
    if (props.currentStatus) params.status = props.currentStatus;
    if (searchQuery.value) params.search = searchQuery.value;
    router.get('/admin/token-factory', params, { preserveState: true });
}

function approveToken(token) {
    const risks = (token.metadata?.risk_ack || []).length
        ? t('admin.tokenFactory.confirm.risks', { list: token.metadata.risk_ack.join(', ') })
        : '';
    if (!confirm(t('admin.tokenFactory.confirm.approve', { symbol: token.symbol, risks }))) return;
    router.post(`/admin/token-factory/${token.id}/approve`, {}, { preserveScroll: true });
}

function recheckDeploy(id) {
    router.post(`/admin/token-factory/${id}/recheck`, {}, { preserveScroll: true });
}

function resetStuck(token) {
    if (!confirm(t('admin.tokenFactory.confirm.resetStuck', { symbol: token.symbol }))) return;
    router.post(`/admin/token-factory/${token.id}/reset-stuck`, {}, { preserveScroll: true });
}

/** ข้อความผิดพลาดตอน deploy — งานเขียนไว้ที่ deploy_error (ของเก่าอ่าน error ซึ่งไม่เคยถูกเขียน) */
function deployError(token) {
    return token.metadata?.deploy_error || token.metadata?.error || '';
}

const explorerTx = (hash) => `https://explorer.tpix.online/tx/${hash}`;

function openRejectModal(id) {
    rejectTokenId.value = id;
    rejectReason.value = '';
    showRejectModal.value = true;
}

function submitReject() {
    if (!rejectReason.value) return;
    router.post(`/admin/token-factory/${rejectTokenId.value}/reject`, {
        reason: rejectReason.value,
    });
    showRejectModal.value = false;
}

/*
 * คืนค่าธรรมเนียมของคำขอที่ถูกปฏิเสธ (เต็มจำนวนที่โอนมา)
 *   manual — แอดมินโอน TPIX คืนจากกระเป๋ารับค่าธรรมเนียม/กระเป๋าร้อน แล้ววาง tx hash ให้ระบบตรวจบนเชน
 *   auto   — ระบบเข้าคิวจ่ายจากกระเป๋าร้อนของคลัง (กดสั่งใหม่ได้เมื่อรอบก่อนล้มเหลว)
 */
const REFUND_LABELS = {
    pending: { key: 'admin.tokenFactory.refund.pending', cls: 'text-amber-300' },
    sending: { key: 'admin.tokenFactory.refund.sending', cls: 'text-blue-300' },
    refunded: { key: 'admin.tokenFactory.refund.refunded', cls: 'text-green-400' },
    failed: { key: 'admin.tokenFactory.refund.failed', cls: 'text-red-400' },
};
const refundLabel = (s) => (REFUND_LABELS[s] ? t(REFUND_LABELS[s].key) : s);

const refundTarget = ref(null);
const refundTxHash = ref('');
const refundSubmitting = ref(false);
const refundTxValid = computed(() => /^0x[a-fA-F0-9]{64}$/.test(refundTxHash.value.trim()));

function weiToTpix(wei) {
    if (!wei || !/^[0-9]+$/.test(String(wei))) return '-';
    const v = BigInt(wei);
    const unit = 10n ** 18n;
    const frac = (v % unit).toString().padStart(18, '0').slice(0, 6).replace(/0+$/, '');
    return `${(v / unit).toLocaleString('en-US')}${frac ? '.' + frac : ''}`;
}

const canRefund = (token) => props.canModerate && token.status === 'rejected'
    && ['pending', 'failed'].includes(token.refund_status);

function openRefundModal(token) {
    refundTarget.value = token;
    refundTxHash.value = '';
}

function submitManualRefund() {
    const token = refundTarget.value;
    if (!token || !refundTxValid.value || refundSubmitting.value) return;
    refundSubmitting.value = true;
    router.post(`/admin/token-factory/${token.id}/refund/manual`, { tx_hash: refundTxHash.value.trim() }, {
        preserveScroll: true,
        onSuccess: (page) => {
            // ปิดเฉพาะเมื่อปิดรายการได้จริง — ยังยืนยันไม่ครบ/ผิดเงื่อนไข แก้แล้วกดซ้ำได้โดยไม่ต้องวางใหม่
            if (!page.props.flash?.error) refundTarget.value = null;
        },
        onFinish: () => { refundSubmitting.value = false; },
    });
}

function queueAutoRefund(token) {
    if (!confirm(t('admin.tokenFactory.confirm.autoRefund', { amount: weiToTpix(token.refund_amount_wei), address: token.creator_address }))) return;
    router.post(`/admin/token-factory/${token.id}/refund/auto`, {}, { preserveScroll: true });
}

function copyText(text) {
    navigator.clipboard?.writeText(text).catch(() => {});
}

function retryDeploy(id) {
    if (!confirm(t('admin.tokenFactory.confirm.retry'))) return;
    router.post(`/admin/token-factory/${id}/retry`, {}, { preserveScroll: true });
}

function toggleVerified(id) {
    router.patch(`/admin/token-factory/${id}/verify`);
}

function toggleListed(id) {
    router.patch(`/admin/token-factory/${id}/list`);
}

function getStatusBadge(status) {
    const map = {
        pending: 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/20',
        deploying: 'bg-blue-500/10 text-blue-400 border border-blue-500/20',
        deployed: 'bg-green-500/10 text-green-400 border border-green-500/20',
        failed: 'bg-red-500/10 text-red-400 border border-red-500/20',
        rejected: 'bg-red-500/10 text-red-400 border border-red-500/20',
    };
    return map[status] || 'bg-gray-500/10 text-gray-400';
}

function formatDate(d) {
    return new Date(d).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function shortAddr(addr) {
    if (!addr) return '-';
    return addr.slice(0, 8) + '...' + addr.slice(-4);
}

// Pagination
const pagination = computed(() => props.tokens || {});
function goToPage(page) {
    const params = { page };
    if (props.currentStatus) params.status = props.currentStatus;
    if (props.currentSearch) params.search = props.currentSearch;
    router.get('/admin/token-factory', params, { preserveState: true });
}
</script>

<template>
    <AdminLayout title="Token Factory">
        <!-- Flash Messages -->
        <div v-if="flash.success" class="mb-4 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-sm">
            {{ flash.success }}
        </div>
        <div v-if="flash.error" class="mb-4 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
            {{ flash.error }}
        </div>
        <div v-if="logoError || pageErrors.logo" class="mb-4 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm" data-test="logo-error">
            {{ logoError || pageErrors.logo }}
        </div>
        <input ref="logoInput" type="file" accept="image/png,image/webp" class="hidden" data-test="logo-input" @change="onLogoPicked" />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-dark-400">
                {{ t('admin.tokenFactory.enabledTypes', { types: enabledTypes }) }}
            </p>
            <Link v-if="canManagePolicy" href="/admin/token-factory/policy" class="text-xs px-3 py-1.5 rounded-lg bg-primary-500/10 text-primary-300 hover:bg-primary-500/20" data-test="policy-link">
                {{ t('admin.tokenFactory.policyLink') }}
            </Link>
        </div>

        <!-- Factory Readiness Warning -->
        <div v-if="factoryReady && !factoryReady.ready" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 flex items-start gap-3">
            <svg class="w-6 h-6 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <h3 class="text-sm font-semibold text-red-400">{{ t('admin.tokenFactory.notReady.title') }}</h3>
                <p class="text-xs text-red-400/70 mt-1">
                    <span v-for="issue in factoryReady.issues" :key="issue">{{ issue }}. </span>
                </p>
                <p v-if="factoryReady.details" class="text-[11px] text-red-300/70 mt-1 font-mono break-all">
                    {{ t('admin.tokenFactory.notReady.deployer') }}: {{ factoryReady.details.deployer_address || '-' }}
                    · {{ t('admin.tokenFactory.notReady.erc20Owner') }}: {{ factoryReady.details.erc20_factory?.owner || '-' }}
                    · {{ t('admin.tokenFactory.notReady.nftOwner') }}: {{ factoryReady.details.nft_factory?.owner || '-' }}
                </p>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-white">{{ stats?.total || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.total') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-yellow-400">{{ stats?.pending || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.pending') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-blue-400">{{ stats?.deploying || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.deploying') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-green-400">{{ stats?.deployed || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.deployed') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-red-400">{{ stats?.failed || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.failed') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-red-300">{{ stats?.rejected || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.rejected') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-blue-300">{{ stats?.verified || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.verified') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-purple-400">{{ stats?.unique_creators || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.tokenFactory.stats.creators') }}</p>
            </div>
        </div>

        <!-- Filters + Search -->
        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex gap-2 flex-wrap">
                <button
                    v-for="f in statusFilters"
                    :key="f.value"
                    @click="filterByStatus(f.value)"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all flex items-center gap-1.5"
                    :class="currentStatus === f.value ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
                >
                    {{ f.label }}
                    <span v-if="f.count" class="bg-white/10 px-1.5 py-0.5 rounded text-[10px]">{{ f.count }}</span>
                </button>
            </div>
            <div class="flex-1 max-w-xs ml-auto">
                <form @submit.prevent="handleSearch" class="flex gap-2">
                    <input v-model="searchQuery" type="text" class="trading-input flex-1 text-sm" :placeholder="t('admin.tokenFactory.searchPlaceholder')" />
                    <button type="submit" class="px-3 py-2 rounded-lg bg-primary-500/10 text-primary-400 hover:bg-primary-500/20 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </button>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.tokenFactory.table.token') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden sm:table-cell">{{ t('admin.tokenFactory.table.creator') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden md:table-cell">{{ t('admin.tokenFactory.table.supply') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden lg:table-cell">{{ t('admin.tokenFactory.table.type') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.tokenFactory.table.status') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden md:table-cell">{{ t('admin.tokenFactory.table.listed') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden lg:table-cell">{{ t('admin.tokenFactory.table.date') }}</th>
                            <th class="text-right p-4 text-gray-400 font-medium">{{ t('admin.tokenFactory.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="token in tokens?.data || []"
                            :key="token.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="p-4">
                                <div class="flex items-center gap-2">
                                    <div v-if="token.logo_url" class="w-8 h-8 rounded-full overflow-hidden bg-dark-800">
                                        <img :src="token.logo_url" :alt="token.symbol" class="w-full h-full object-cover" />
                                    </div>
                                    <div v-else class="w-8 h-8 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                        <span class="text-xs font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white flex items-center gap-1">
                                            {{ token.name }}
                                            <svg v-if="token.is_verified" class="w-3.5 h-3.5 text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        </p>
                                        <p class="text-xs text-gray-400">{{ token.symbol }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 hidden sm:table-cell">
                                <span class="text-xs text-gray-400 font-mono">{{ shortAddr(token.creator_address) }}</span>
                            </td>
                            <td class="p-4 text-white font-mono text-xs hidden md:table-cell">{{ Number(token.total_supply).toLocaleString(locale === 'th' ? 'th-TH' : 'en-US') }}</td>
                            <td class="p-4 hidden lg:table-cell">
                                <span class="text-xs text-gray-300 capitalize">{{ token.token_type?.replace('_', ' ') }}</span>
                                <span class="block text-[10px] text-gray-500">{{ token.token_category }}</span>
                            </td>
                            <td class="p-4">
                                <span :class="['text-xs px-2.5 py-1 rounded-full font-medium inline-flex items-center gap-1', getStatusBadge(token.status)]">
                                    <span v-if="token.status === 'deploying'" class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                                    {{ statusLabel(token.status) }}
                                </span>
                                <p v-if="token.reject_reason" class="text-[10px] text-red-400 mt-1 max-w-[150px] truncate" :title="token.reject_reason">{{ token.reject_reason }}</p>
                                <p v-if="token.status === 'failed' && deployError(token)" class="text-[10px] text-red-400 mt-1 max-w-[220px] break-words" :title="deployError(token)" data-test="deploy-error">{{ deployError(token) }}</p>
                                <p v-if="token.status === 'deploying' && token.metadata?.deploy_warning" class="text-[10px] text-amber-400 mt-1 max-w-[220px]">{{ token.metadata.deploy_warning }}</p>
                                <a v-if="token.status === 'deploying' && token.tx_hash" :href="explorerTx(token.tx_hash)" target="_blank" rel="noopener" class="block text-[10px] text-blue-300 mt-1 font-mono">tx {{ shortAddr(token.tx_hash) }}</a>
                                <p v-if="(token.metadata?.risk_ack || []).length" class="text-[10px] text-red-300 mt-1">⚠ {{ token.metadata.risk_ack.join(', ') }}</p>
                                <a v-if="token.fee_tx_hash" :href="explorerTx(token.fee_tx_hash)" target="_blank" rel="noopener" class="block text-[10px] text-gray-500 mt-1">{{ t('admin.tokenFactory.fee', { amount: token.metadata?.fee_amount }) }}</a>
                                <div v-if="token.refund_status" class="mt-1 text-[10px]" data-test="refund-status">
                                    <span :class="REFUND_LABELS[token.refund_status]?.cls">
                                        {{ refundLabel(token.refund_status) }} · {{ weiToTpix(token.refund_amount_wei) }} TPIX
                                        <span v-if="token.refund_mode" class="text-gray-500">({{ token.refund_mode === 'auto' ? t('admin.tokenFactory.refund.modeAuto') : t('admin.tokenFactory.refund.modeManual') }})</span>
                                    </span>
                                    <a v-if="token.refund_tx_hash" :href="explorerTx(token.refund_tx_hash)" target="_blank" rel="noopener" class="block text-green-300 font-mono">{{ t('admin.tokenFactory.refund.tx', { hash: shortAddr(token.refund_tx_hash) }) }}</a>
                                    <span v-else-if="token.refund_status === 'sending' && token.refund_payout_id" class="block text-gray-500">{{ t('admin.tokenFactory.refund.queue', { id: token.refund_payout_id }) }}</span>
                                    <p v-if="token.refund_status === 'failed' && token.metadata?.refund_error" class="text-red-400 max-w-[220px] break-words">{{ token.metadata.refund_error }}</p>
                                </div>
                            </td>
                            <td class="p-4 hidden md:table-cell">
                                <span v-if="token.status === 'deployed'" :class="['text-xs', token.is_listed ? 'text-green-400' : 'text-gray-500']">
                                    {{ token.is_listed ? t('common.yes') : t('common.no') }}
                                </span>
                                <span v-else class="text-xs text-gray-600">-</span>
                            </td>
                            <td class="p-4 text-gray-400 text-xs hidden lg:table-cell">{{ formatDate(token.created_at) }}</td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    <!-- Pending actions -->
                                    <button
                                        v-if="token.status === 'pending' && canModerate"
                                        @click="approveToken(token)"
                                        class="px-2.5 py-1 rounded-lg bg-green-500/10 text-green-400 text-xs font-medium hover:bg-green-500/20 transition-colors"
                                    >
                                        {{ t('admin.tokenFactory.actions.approve') }}
                                    </button>
                                    <button
                                        v-if="token.status === 'pending' && canModerate"
                                        @click="openRejectModal(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        {{ t('admin.tokenFactory.actions.reject') }}
                                    </button>
                                    <!-- Failed actions -->
                                    <button
                                        v-if="token.status === 'deploying' && !token.tx_hash && canModerate"
                                        @click="resetStuck(token)"
                                        class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-300 text-xs font-medium hover:bg-amber-500/20 transition-colors"
                                        :title="t('admin.tokenFactory.actions.resetStuckHint')"
                                    >
                                        {{ t('admin.tokenFactory.actions.resetStuck') }}
                                    </button>
                                    <button
                                        v-if="token.status === 'deploying' && token.tx_hash && canModerate"
                                        @click="recheckDeploy(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-300 text-xs font-medium hover:bg-blue-500/20 transition-colors"
                                    >
                                        {{ t('admin.tokenFactory.actions.recheck') }}
                                    </button>
                                    <button
                                        v-if="token.status === 'failed' && canModerate"
                                        @click="retryDeploy(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-yellow-500/10 text-yellow-400 text-xs font-medium hover:bg-yellow-500/20 transition-colors"
                                    >
                                        {{ t('admin.tokenFactory.actions.retry') }}
                                    </button>
                                    <button
                                        v-if="token.status === 'failed' && canModerate"
                                        @click="openRejectModal(token.id)"
                                        class="px-2.5 py-1 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                    >
                                        {{ t('admin.tokenFactory.actions.reject') }}
                                    </button>
                                    <!-- Rejected: คืนค่าธรรมเนียม -->
                                    <button
                                        v-if="canRefund(token)"
                                        @click="openRefundModal(token)"
                                        class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-300 text-xs font-medium hover:bg-amber-500/20 transition-colors"
                                        data-test="refund-manual-button"
                                    >
                                        {{ t('admin.tokenFactory.actions.refundManual') }}
                                    </button>
                                    <button
                                        v-if="canRefund(token)"
                                        @click="queueAutoRefund(token)"
                                        class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-300 text-xs font-medium hover:bg-blue-500/20 transition-colors"
                                        data-test="refund-auto-button"
                                    >
                                        {{ token.refund_status === 'failed' ? t('admin.tokenFactory.actions.refundAutoRetry') : t('admin.tokenFactory.actions.refundAuto') }}
                                    </button>
                                    <!-- Deployed actions -->
                                    <button
                                        v-if="token.status === 'deployed' && canModerate"
                                        @click="toggleVerified(token.id)"
                                        class="px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                                        :class="token.is_verified ? 'bg-blue-500/10 text-blue-400 hover:bg-blue-500/20' : 'bg-white/5 text-gray-400 hover:bg-white/10'"
                                    >
                                        {{ token.is_verified ? t('admin.tokenFactory.actions.unverify') : t('admin.tokenFactory.actions.verify') }}
                                    </button>
                                    <button
                                        v-if="token.status === 'deployed' && canModerate"
                                        @click="toggleListed(token.id)"
                                        class="px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                                        :class="token.is_listed ? 'bg-green-500/10 text-green-400 hover:bg-green-500/20' : 'bg-white/5 text-gray-400 hover:bg-white/10'"
                                    >
                                        {{ token.is_listed ? t('admin.tokenFactory.actions.delist') : t('admin.tokenFactory.actions.list') }}
                                    </button>
                                    <button
                                        v-if="token.status === 'deployed' && canModerate"
                                        @click="pickLogo(token)"
                                        :disabled="logoUploading"
                                        class="px-2.5 py-1 rounded-lg bg-white/5 text-gray-300 text-xs font-medium hover:bg-white/10 disabled:opacity-50 transition-colors"
                                        :title="t('admin.tokenFactory.actions.logoHint', { types: logoRules.types, px: logoRules.min_px, kb: logoRules.max_kb })"
                                        data-test="logo-button"
                                    >
                                        {{ token.logo_url ? t('admin.tokenFactory.actions.changeLogo') : t('admin.tokenFactory.actions.setLogo') }}
                                    </button>
                                    <!-- Contract link -->
                                    <a
                                        v-if="token.contract_address"
                                        :href="`https://explorer.tpix.online/address/${token.contract_address}`"
                                        target="_blank"
                                        class="px-2.5 py-1 rounded-lg bg-white/5 text-gray-400 text-xs font-medium hover:bg-white/10 transition-colors"
                                    >
                                        Explorer
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!tokens?.data?.length">
                            <td colspan="8" class="p-8 text-center text-gray-400">
                                {{ currentSearch ? t('admin.tokenFactory.empty.noMatch') : t('admin.tokenFactory.empty.none') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="pagination.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-white/5">
                <p class="text-xs text-gray-400">
                    {{ t('admin.tokenFactory.showing', { from: pagination.from, to: pagination.to, total: pagination.total }) }}
                </p>
                <div class="flex gap-1">
                    <button
                        v-for="page in pagination.last_page"
                        :key="page"
                        @click="goToPage(page)"
                        class="w-8 h-8 rounded-lg text-xs font-medium transition-colors"
                        :class="page === pagination.current_page ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white hover:bg-white/5'"
                    >
                        {{ page }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showRejectModal = false"></div>
                    <div class="relative glass-dark p-6 rounded-2xl border border-white/10 max-w-md w-full">
                        <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.tokenFactory.rejectModal.title') }}</h3>
                        <div class="mb-4">
                            <label class="block text-sm text-gray-300 mb-1.5">{{ t('admin.tokenFactory.rejectModal.reason') }} <span class="text-red-400">*</span></label>
                            <textarea v-model="rejectReason" rows="3" class="trading-input w-full resize-none" :placeholder="t('admin.tokenFactory.rejectModal.reasonPlaceholder')"></textarea>
                        </div>
                        <p class="mb-4 text-xs text-amber-300/90" data-test="reject-refund-note">
                            {{ t('admin.tokenFactory.rejectModal.refundNote') }}
                            {{ refundMode === 'auto' ? t('admin.tokenFactory.rejectModal.refundNoteAuto') : t('admin.tokenFactory.rejectModal.refundNoteManual') }}
                        </p>
                        <div class="flex gap-3">
                            <button @click="showRejectModal = false" class="flex-1 btn-secondary py-2">{{ t('common.cancel') }}</button>
                            <button @click="submitReject" :disabled="!rejectReason.trim()" class="flex-1 py-2 rounded-lg bg-red-500/20 text-red-400 font-medium hover:bg-red-500/30 disabled:opacity-50 transition-colors">{{ t('admin.tokenFactory.rejectModal.submit') }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Manual refund modal -->
        <Teleport to="body">
            <div v-if="refundTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-test="refund-modal">
                <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="refundTarget = null"></div>
                <div class="relative glass-dark p-6 rounded-2xl border border-white/10 max-w-lg w-full">
                    <h3 class="text-lg font-bold text-white mb-1">{{ t('admin.tokenFactory.refundModal.title', { symbol: refundTarget.symbol }) }}</h3>
                    <p class="text-xs text-gray-400 mb-4">{{ t('admin.tokenFactory.refundModal.desc') }}</p>
                    <div class="space-y-2 mb-4 text-sm">
                        <div class="flex items-center justify-between gap-2 p-3 rounded-lg bg-white/5">
                            <span class="text-gray-400 shrink-0">{{ t('admin.tokenFactory.refundModal.recipient') }}</span>
                            <span class="font-mono text-xs text-white break-all text-right">{{ refundTarget.creator_address }}</span>
                            <button type="button" class="text-xs text-primary-400 shrink-0" @click="copyText(refundTarget.creator_address)">{{ t('admin.tokenFactory.refundModal.copy') }}</button>
                        </div>
                        <div class="flex items-center justify-between gap-2 p-3 rounded-lg bg-white/5">
                            <span class="text-gray-400">{{ t('admin.tokenFactory.refundModal.minAmount') }}</span>
                            <span class="font-mono text-white">{{ weiToTpix(refundTarget.refund_amount_wei) }} TPIX</span>
                        </div>
                    </div>
                    <label class="block text-sm text-gray-300 mb-1.5">{{ t('admin.tokenFactory.refundModal.txLabel') }}</label>
                    <input v-model="refundTxHash" type="text" class="trading-input w-full font-mono text-xs" placeholder="0x…" data-test="refund-tx-input" />
                    <p v-if="refundTxHash && !refundTxValid" class="text-xs text-red-400 mt-1">{{ t('admin.tokenFactory.refundModal.txInvalid') }}</p>
                    <div class="flex gap-3 mt-5">
                        <button @click="refundTarget = null" class="flex-1 btn-secondary py-2">{{ t('common.cancel') }}</button>
                        <button
                            @click="submitManualRefund"
                            :disabled="!refundTxValid || refundSubmitting"
                            class="flex-1 py-2 rounded-lg bg-amber-500/20 text-amber-300 font-medium hover:bg-amber-500/30 disabled:opacity-50 transition-colors"
                            data-test="refund-submit"
                        >
                            {{ refundSubmitting ? t('admin.tokenFactory.refundModal.submitting') : t('admin.tokenFactory.refundModal.submit') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
