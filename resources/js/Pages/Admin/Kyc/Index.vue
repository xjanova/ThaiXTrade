<script setup>
/**
 * TPIX TRADE — คิวตรวจยืนยันตัวตน + ตั้งค่าด่าน (หลังบ้าน)
 *
 * เจ้าของสั่งว่า "มีทุกส่วน เปิดปิดได้ที่สำคัญๆ" — ตารางด่านจึงอยู่หน้าเดียวกับคิว
 * เพราะสองอย่างนี้ตัดสินใจร่วมกัน: เปิดด่านเพิ่มแปลว่าคิวตรวจจะยาวขึ้นทันที
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    submissions: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    settings: { type: Object, default: () => ({}) },
});

const flash = computed(() => usePage().props.flash || {});

const TABS = [
    { key: 'pending', label: 'admin.kyc.tabs.pending' },
    { key: 'approved', label: 'admin.kyc.tabs.approved' },
    { key: 'rejected', label: 'admin.kyc.tabs.rejected' },
    { key: 'cancelled', label: 'admin.kyc.tabs.cancelled' },
    { key: 'all', label: 'admin.kyc.tabs.all' },
];

const setStatus = (status) => {
    router.get('/admin/kyc', { status }, { preserveState: true, preserveScroll: true });
};

// ── ตั้งค่าด่าน ──────────────────────────────────────────────────────────────
const settingsForm = useForm({
    enabled: props.settings.enabled ?? false,
    retention_days: props.settings.retention_days ?? 1825,
    consent_version: props.settings.consent_version ?? '1.0',
    gates: JSON.parse(JSON.stringify(props.settings.gates ?? {})),
});

const gateKeys = computed(() => Object.keys(settingsForm.gates));

const saveSettings = () => settingsForm.put('/admin/kyc/settings', { preserveScroll: true });

// ด่านที่เปิดไว้แต่สวิตช์ใหญ่ปิดอยู่ = ไม่มีผลอะไรเลย ต้องบอกให้ชัด
// ไม่งั้นแอดมินกดเปิดทีละอันแล้วเข้าใจว่ากันได้แล้ว ทั้งที่ยังเปิดโล่งอยู่
const armedButDisabled = computed(() => {
    if (settingsForm.enabled) return [];
    return gateKeys.value.filter((k) => settingsForm.gates[k]?.enabled);
});

const STATUS_CLS = {
    pending: 'badge-pending',
    approved: 'badge-approved',
    rejected: 'badge-rejected',
    cancelled: 'badge-neutral',
    expired: 'badge-neutral',
};

const STATUS_LABEL = {
    pending: 'admin.kyc.status.pending',
    approved: 'admin.kyc.status.approved',
    rejected: 'admin.kyc.status.rejected',
    cancelled: 'admin.kyc.status.cancelled',
    expired: 'admin.kyc.status.expired',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', { dateStyle: 'short', timeStyle: 'short' }) : '—');

// ป้ายด่านมาจากเซิร์ฟเวอร์ — มี label_en ให้ ส่วนคำอธิบายมีแค่ภาษาไทย
const gateLabel = (g) => (locale.value === 'en' && g?.label_en ? g.label_en : g?.label_th);

// คำอธิบายด่านแปลฝั่งหน้าเว็บตามคีย์ด่าน — ด่านใหม่ที่ยังไม่มีคำแปลจะใช้ desc_th จากเซิร์ฟเวอร์
const gateDesc = (key, g) => {
    const k = `admin.kyc.gateDesc.${key}`;
    const text = t(k);
    return text === k ? g?.desc_th : text;
};

const shortWallet = (w) => (w ? `${w.slice(0, 6)}…${w.slice(-4)}` : '—');
</script>

<template>
    <Head :title="t('admin.kyc.pageTitle')" />

    <AdminLayout>
        <div class="kyc-admin">
            <header class="page-head">
                <h1>{{ t('admin.kyc.header.title') }}</h1>
                <Link href="/admin/kyc/deletions" class="link-deletions">
                    {{ t('admin.kyc.header.deletionRequests') }}
                    <span v-if="counts.deletion_pending" class="pill-alert">{{ counts.deletion_pending }}</span>
                </Link>
            </header>

            <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>

            <!-- ── ตั้งค่าด่าน ──────────────────────────────────────────── -->
            <section class="card">
                <h2 class="card-title">{{ t('admin.kyc.gates.title') }}</h2>

                <label class="master-switch">
                    <input v-model="settingsForm.enabled" type="checkbox" />
                    <div>
                        <strong>{{ t('admin.kyc.gates.masterSwitch') }}</strong>
                        <span>{{ t('admin.kyc.gates.masterSwitchHint') }}</span>
                    </div>
                </label>

                <div v-if="armedButDisabled.length" class="warn-box">
                    {{ t('admin.kyc.gates.armedButDisabled', { n: armedButDisabled.length }) }}
                    <strong>{{ t('admin.kyc.gates.noneActive') }}</strong>
                </div>

                <table class="gate-table">
                    <thead>
                        <tr>
                            <th>{{ t('admin.kyc.gates.colFeature') }}</th>
                            <th class="col-narrow">{{ t('admin.kyc.gates.colRequire') }}</th>
                            <th class="col-narrow">{{ t('admin.kyc.gates.colLevel') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="key in gateKeys" :key="key">
                            <td>
                                <strong>{{ gateLabel(settingsForm.gates[key]) }}</strong>
                                <span class="gate-desc">{{ gateDesc(key, settingsForm.gates[key]) }}</span>
                            </td>
                            <td class="col-narrow">
                                <input v-model="settingsForm.gates[key].enabled" type="checkbox" />
                            </td>
                            <td class="col-narrow">
                                <select v-model="settingsForm.gates[key].level" class="input-sm">
                                    <option value="basic">{{ t('admin.kyc.level.basic') }}</option>
                                    <option value="enhanced">{{ t('admin.kyc.level.enhanced') }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="settings-row">
                    <div class="field">
                        <label>{{ t('admin.kyc.settings.retentionLabel') }}</label>
                        <input v-model.number="settingsForm.retention_days" type="number" min="1" class="input-sm" />
                        <p class="field-hint">{{ t('admin.kyc.settings.retentionHint') }}</p>
                    </div>

                    <div class="field">
                        <label>{{ t('admin.kyc.settings.consentLabel') }}</label>
                        <input v-model="settingsForm.consent_version" type="text" class="input-sm" />
                        <p class="field-hint">{{ t('admin.kyc.settings.consentHint') }}</p>
                    </div>
                </div>

                <button class="btn-primary" :disabled="settingsForm.processing" @click="saveSettings">
                    {{ settingsForm.processing ? t('admin.kyc.settings.saving') : t('admin.kyc.settings.save') }}
                </button>
            </section>

            <!-- ── คิวตรวจ ──────────────────────────────────────────────── -->
            <section class="card">
                <h2 class="card-title">
                    {{ t('admin.kyc.queue.title') }}
                    <span v-if="counts.pending" class="pill-alert">{{ t('admin.kyc.queue.pendingCount', { n: counts.pending }) }}</span>
                </h2>

                <div class="tabs">
                    <button
                        v-for="tab in TABS"
                        :key="tab.key"
                        class="tab"
                        :class="{ 'tab--active': filters.status === tab.key }"
                        @click="setStatus(tab.key)"
                    >
                        {{ t(tab.label) }}
                        <span v-if="counts[tab.key]" class="tab-count">{{ counts[tab.key] }}</span>
                    </button>
                </div>

                <table v-if="submissions.data.length" class="data-table">
                    <thead>
                        <tr>
                            <th>{{ t('admin.kyc.queue.colApplicant') }}</th>
                            <th>{{ t('admin.kyc.queue.colName') }}</th>
                            <th>{{ t('admin.kyc.queue.colLevel') }}</th>
                            <th>{{ t('admin.kyc.queue.colStatus') }}</th>
                            <th>{{ t('admin.kyc.queue.colSubmitted') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in submissions.data" :key="s.uuid">
                            <td>
                                <div class="user-cell">
                                    <span>{{ s.user.email || s.user.name || '—' }}</span>
                                    <code>{{ shortWallet(s.user.wallet_address) }}</code>
                                </div>
                            </td>
                            <td>
                                <span v-if="s.purged_at" class="purged-tag">{{ t('admin.kyc.queue.purged') }}</span>
                                <span v-else-if="s.source === 'thaiprompt'" class="purged-tag">{{ t('admin.kyc.queue.viaThaiprompt') }}</span>
                                <template v-else>{{ s.full_name || '—' }}</template>
                            </td>
                            <td>{{ s.level === 'enhanced' ? t('admin.kyc.level.enhanced') : t('admin.kyc.level.basic') }}</td>
                            <td><span class="badge" :class="STATUS_CLS[s.status]">{{ STATUS_LABEL[s.status] ? t(STATUS_LABEL[s.status]) : s.status }}</span></td>
                            <td class="col-date">{{ formatDate(s.submitted_at) }}</td>
                            <td>
                                <Link :href="`/admin/kyc/${s.uuid}`" class="btn-sm">{{ t('admin.kyc.queue.review') }}</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="empty-note">{{ t('admin.kyc.queue.empty') }}</p>

                <div v-if="submissions.links" class="pagination">
                    <Link
                        v-for="link in submissions.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="page-link"
                        :class="{ 'page-link--active': link.active, 'page-link--off': !link.url }"
                        v-html="link.label"
                    />
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.kyc-admin {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.page-head h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #fff;
}

.link-deletions {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.85rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.85rem;
    text-decoration: none;
}

.card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 1.25rem;
}

.card-title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 1.05rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: 1rem;
}

.alert {
    padding: 0.85rem 1rem;
    border-radius: 12px;
    font-size: 0.9rem;
}

.alert-success {
    background: rgb(var(--c-trading-green) / 0.12);
    border: 1px solid rgb(var(--c-trading-green) / 0.3);
    color: #6ee7a8;
}

.pill-alert {
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
    font-size: 0.75rem;
    font-weight: 600;
}

/* ── สวิตช์ใหญ่ ── */
.master-switch {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    padding: 0.9rem;
    border-radius: 12px;
    background: rgba(59, 130, 246, 0.06);
    border: 1px solid rgba(59, 130, 246, 0.2);
    cursor: pointer;
    margin-bottom: 1rem;
}

.master-switch input {
    margin-top: 0.25rem;
}

.master-switch strong {
    display: block;
    color: #fff;
    font-size: 0.92rem;
}

.master-switch span {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.55);
    line-height: 1.55;
}

.warn-box {
    padding: 0.75rem 0.9rem;
    border-radius: 10px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: #fbbf24;
    font-size: 0.85rem;
    margin-bottom: 1rem;
}

/* ── ตาราง ── */
.gate-table,
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.87rem;
}

.gate-table th,
.data-table th {
    text-align: left;
    padding: 0.5rem 0.6rem;
    color: rgba(255, 255, 255, 0.45);
    font-weight: 500;
    font-size: 0.78rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.gate-table td,
.data-table td {
    padding: 0.65rem 0.6rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.85);
    vertical-align: middle;
}

.col-narrow {
    width: 130px;
    text-align: center;
}

.gate-table td strong {
    display: block;
    color: #fff;
    font-weight: 500;
}

.gate-desc {
    font-size: 0.78rem;
    color: rgba(255, 255, 255, 0.45);
}

.col-date {
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.8rem;
    white-space: nowrap;
}

.user-cell {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.user-cell code {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.4);
}

.purged-tag {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.35);
    font-style: italic;
}

.badge {
    padding: 0.15rem 0.55rem;
    border-radius: 6px;
    font-size: 0.74rem;
}

.badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
.badge-approved { background: rgb(var(--c-trading-green) / 0.15); color: #6ee7a8; }
.badge-rejected { background: rgb(var(--c-trading-red) / 0.15); color: #ff8a9b; }
.badge-neutral { background: rgba(255, 255, 255, 0.07); color: rgba(255, 255, 255, 0.55); }

/* ── แท็บ ── */
.tabs {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.tab {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.8rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.65);
    font-size: 0.83rem;
    cursor: pointer;
}

.tab--active {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.5);
    color: #fff;
}

.tab-count {
    font-size: 0.72rem;
    opacity: 0.7;
}

/* ── ฟิลด์ ── */
.settings-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
    margin: 1.25rem 0;
}

.field label {
    display: block;
    font-size: 0.82rem;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 0.3rem;
}

.field-hint {
    margin-top: 0.35rem;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.4);
    line-height: 1.5;
}

.input-sm {
    padding: 0.45rem 0.6rem;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #fff;
    font-size: 0.85rem;
    width: 100%;
    max-width: 220px;
}

.btn-primary {
    padding: 0.6rem 1.1rem;
    border-radius: 10px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
    font-weight: 600;
    font-size: 0.88rem;
    border: none;
    cursor: pointer;
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-sm {
    padding: 0.35rem 0.75rem;
    border-radius: 8px;
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.4);
    color: #93c5fd;
    font-size: 0.8rem;
    text-decoration: none;
    white-space: nowrap;
}

.empty-note {
    padding: 2rem 0;
    text-align: center;
    color: rgba(255, 255, 255, 0.35);
    font-size: 0.88rem;
}

.pagination {
    display: flex;
    gap: 0.3rem;
    flex-wrap: wrap;
    margin-top: 1rem;
}

.page-link {
    padding: 0.3rem 0.6rem;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.8rem;
    text-decoration: none;
}

.page-link--active {
    background: rgba(59, 130, 246, 0.2);
    color: #fff;
}

.page-link--off {
    opacity: 0.3;
    pointer-events: none;
}
</style>
