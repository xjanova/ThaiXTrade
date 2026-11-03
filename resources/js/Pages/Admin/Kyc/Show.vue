<script setup>
/**
 * TPIX TRADE — หน้าตรวจใบยืนยันตัวตน (หลังบ้าน)
 *
 * คนตรวจต้องเทียบ "ข้อมูลที่พิมพ์มา" กับ "รูปบัตร" ทีละบรรทัด
 * จอนี้จึงวางสองอย่างนี้ข้างกัน ไม่ต้องสลับหน้าไปมา
 *
 * ⚠️ ทุกครั้งที่เปิดรูป ระบบบันทึกไว้ว่าใครเปิด เมื่อไหร่ จาก IP ไหน
 *    PDPA ให้เจ้าของข้อมูลขอดูรายการนี้ได้ — เปิดดูโดยไม่มีเรื่องต้องตรวจจะถูกเห็น
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    submission: { type: Object, required: true },
    user: { type: Object, default: () => ({}) },
    duplicateUserIds: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const flash = computed(() => usePage().props.flash || {});

// เก็บ "คีย์แปล" ไม่ใช่ข้อความ — สลับภาษาแล้วป้ายเปลี่ยนตาม
const DOC_LABEL_KEY = {
    id_card_front: 'admin.kycShow.docs.idCardFront',
    id_card_back: 'admin.kycShow.docs.idCardBack',
    selfie_with_id: 'admin.kycShow.docs.selfieWithId',
    address_proof: 'admin.kycShow.docs.addressProof',
    bank_book: 'admin.kycShow.docs.bankBook',
};
const docLabel = (type) => (DOC_LABEL_KEY[type] ? t(DOC_LABEL_KEY[type]) : type);

const STATUS_KEYS = ['pending', 'approved', 'rejected', 'cancelled', 'expired'];
const statusLabel = (status) => (STATUS_KEYS.includes(status) ? t(`admin.kycShow.status.${status}`) : status);

const isPending = computed(() => props.submission.status === 'pending');
const isPurged = computed(() => !!props.submission.purged_at);

// ── ฟิลด์ที่ต้องเทียบกับรูป ──────────────────────────────────────────────────
const fields = computed(() => [
    { key: 'fullName', label: t('admin.kycShow.fields.fullName'), value: props.submission.full_name },
    { key: 'fullNameEn', label: t('admin.kycShow.fields.fullNameEn'), value: props.submission.full_name_en },
    { key: 'idType', label: t('admin.kycShow.fields.idType'), value: props.submission.id_type === 'passport' ? t('admin.kycShow.fields.passport') : t('admin.kycShow.fields.nationalIdCard') },
    { key: 'idNumber', label: t('admin.kycShow.fields.idNumber'), value: props.submission.national_id, mono: true },
    { key: 'dateOfBirth', label: t('admin.kycShow.fields.dateOfBirth'), value: props.submission.date_of_birth },
    { key: 'nationality', label: t('admin.kycShow.fields.nationality'), value: props.submission.nationality },
    { key: 'address', label: t('admin.kycShow.fields.address'), value: props.submission.address },
    { key: 'occupation', label: t('admin.kycShow.fields.occupation'), value: props.submission.occupation },
    { key: 'phone', label: t('admin.kycShow.fields.phone'), value: props.submission.phone },
]);

// ── รูปที่กำลังดูแบบเต็มจอ ───────────────────────────────────────────────────
const lightbox = ref(null);

// ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────────
const approveForm = useForm({ note: '' });
const rejectForm = useForm({ reason: '', note: '' });

const showReject = ref(false);

// เหตุผลที่เจอบ่อย — กดเลือกได้ ไม่ต้องพิมพ์ใหม่ทุกครั้ง และข้อความจะได้สม่ำเสมอ
// เก็บเป็นคีย์แปล — ข้อความที่ส่งไปคือภาษาที่แอดมินเปิดอยู่ตอนกดเลือก
const COMMON_REASON_KEYS = [
    'admin.kycShow.reasons.blurryId',
    'admin.kycShow.reasons.glare',
    'admin.kycShow.reasons.nameMismatch',
    'admin.kycShow.reasons.idNumberMismatch',
    'admin.kycShow.reasons.selfieFaceUnclear',
    'admin.kycShow.reasons.selfieIdUnreadable',
    'admin.kycShow.reasons.documentExpired',
    'admin.kycShow.reasons.addressProofOld',
];

const approve = () => {
    if (!confirm(t('admin.kycShow.decide.approveConfirm'))) return;
    approveForm.post(`/admin/kyc/${props.submission.uuid}/approve`, { preserveScroll: true });
};

const reject = () => {
    if (!rejectForm.reason.trim()) return;
    rejectForm.post(`/admin/kyc/${props.submission.uuid}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejectForm.reset();
            showReject.value = false;
        },
    });
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

const formatSize = (bytes) => {
    if (!bytes) return '—';
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.round(bytes / 1024)} KB`;
};
</script>

<template>
    <Head :title="t('admin.kycShow.pageTitle')" />

    <AdminLayout>
        <div class="review-page">
            <header class="page-head">
                <Link href="/admin/kyc" class="back-link">← {{ t('admin.kycShow.backToQueue') }}</Link>
                <span class="badge" :class="`badge-${submission.status}`">
                    {{ statusLabel(submission.status) }}
                </span>
            </header>

            <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>

            <div v-if="isPurged" class="alert alert-warn">
                {{ t('admin.kycShow.purged.notice', {
                    date: formatDate(submission.purged_at),
                    reason: submission.purge_reason === 'user_request' ? t('admin.kycShow.purged.byUserRequest') : t('admin.kycShow.purged.retentionExpired'),
                }) }}
            </div>

            <!-- ── ธงเตือน ───────────────────────────────────────────────── -->
            <div v-if="duplicateUserIds.length" class="alert alert-warn">
                <strong>{{ t('admin.kycShow.duplicate.title') }}</strong>
                — {{ t('admin.kycShow.duplicate.accounts', { ids: duplicateUserIds.join(', #') }) }}
                <p class="alert-sub">
                    {{ t('admin.kycShow.duplicate.hint') }}
                </p>
            </div>

            <div class="review-grid">
                <!-- ── ข้อมูลที่กรอก ──────────────────────────────────────── -->
                <section class="card">
                    <h2 class="card-title">{{ t('admin.kycShow.info.title') }}</h2>

                    <dl class="field-list">
                        <div v-for="f in fields" :key="f.key" class="field-row">
                            <dt>{{ f.label }}</dt>
                            <dd :class="{ mono: f.mono }">{{ f.value || '—' }}</dd>
                        </div>
                    </dl>

                    <h3 class="sub-title">{{ t('admin.kycShow.account.title') }}</h3>
                    <dl class="field-list">
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.account.email') }}</dt>
                            <dd>{{ user.email || '—' }}</dd>
                        </div>
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.account.wallet') }}</dt>
                            <dd class="mono">{{ user.wallet_address || '—' }}</dd>
                        </div>
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.account.joinedAt') }}</dt>
                            <dd>{{ formatDate(user.joined_at) }}</dd>
                        </div>
                    </dl>

                    <h3 class="sub-title">{{ t('admin.kycShow.submissionInfo.title') }}</h3>
                    <dl class="field-list">
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.submissionInfo.level') }}</dt>
                            <dd>{{ submission.level === 'enhanced' ? t('admin.kycShow.submissionInfo.levelEnhanced') : t('admin.kycShow.submissionInfo.levelBasic') }}</dd>
                        </div>
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.submissionInfo.source') }}</dt>
                            <dd>
                                {{ submission.source === 'thaiprompt'
                                    ? t('admin.kycShow.submissionInfo.sourceThaiprompt')
                                    : t('admin.kycShow.submissionInfo.sourceDirect') }}
                            </dd>
                        </div>
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.submissionInfo.submittedAt') }}</dt>
                            <dd>{{ formatDate(submission.submitted_at) }}</dd>
                        </div>
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.submissionInfo.consentedAt') }}</dt>
                            <dd>{{ formatDate(submission.consented_at) }} (v{{ submission.consent_version }})</dd>
                        </div>
                        <div v-if="submission.reviewed_at" class="field-row">
                            <dt>{{ t('admin.kycShow.submissionInfo.reviewedBy') }}</dt>
                            <dd>{{ submission.reviewed_by || '—' }} · {{ formatDate(submission.reviewed_at) }}</dd>
                        </div>
                        <div class="field-row">
                            <dt>{{ t('admin.kycShow.submissionInfo.purgeAfter') }}</dt>
                            <dd>{{ formatDate(submission.purge_after) }}</dd>
                        </div>
                    </dl>

                    <div v-if="submission.reject_reason" class="prior-reject">
                        <strong>{{ t('admin.kycShow.submissionInfo.rejectReason') }}</strong>
                        <p>{{ submission.reject_reason }}</p>
                    </div>

                    <div v-if="submission.review_note" class="prior-note">
                        <strong>{{ t('admin.kycShow.submissionInfo.internalNote') }}</strong>
                        <p>{{ submission.review_note }}</p>
                    </div>
                </section>

                <!-- ── เอกสาร ─────────────────────────────────────────────── -->
                <section class="card">
                    <h2 class="card-title">{{ t('admin.kycShow.documents.title') }}</h2>

                    <p class="access-note">
                        {{ t('admin.kycShow.documents.accessNote') }}
                    </p>

                    <div class="doc-list">
                        <div v-for="doc in submission.documents" :key="doc.id" class="doc-card">
                            <div class="doc-head">
                                <strong>{{ docLabel(doc.type) }}</strong>
                                <span class="doc-meta">{{ formatSize(doc.size) }}</span>
                            </div>

                            <div v-if="doc.purged" class="doc-purged">{{ t('admin.kycShow.documents.purged') }}</div>

                            <img
                                v-else
                                :src="`/admin/kyc/documents/${doc.id}`"
                                class="doc-image"
                                :alt="docLabel(doc.type)"
                                @click="lightbox = `/admin/kyc/documents/${doc.id}`"
                            />

                            <details v-if="doc.views.length" class="doc-views">
                                <summary>{{ t('admin.kycShow.documents.viewedTimes', { n: doc.views.length }) }}</summary>
                                <ul>
                                    <li v-for="(v, i) in doc.views" :key="i">
                                        {{ v.admin }} · {{ formatDate(v.viewed_at) }}
                                    </li>
                                </ul>
                            </details>
                        </div>
                    </div>

                    <p v-if="!submission.documents.length" class="empty-note">{{ t('admin.kycShow.documents.empty') }}</p>
                </section>
            </div>

            <!-- ── ประวัติการยื่นก่อนหน้า ─────────────────────────────────── -->
            <section v-if="history.length" class="card">
                <h2 class="card-title">{{ t('admin.kycShow.history.title', { n: history.length }) }}</h2>
                <ul class="history-list">
                    <li v-for="h in history" :key="h.uuid">
                        <Link :href="`/admin/kyc/${h.uuid}`" class="history-link">
                            <span class="badge" :class="`badge-${h.status}`">{{ statusLabel(h.status) }}</span>
                            <span>{{ formatDate(h.submitted_at) }}</span>
                            <span v-if="h.reject_reason" class="history-reason">{{ h.reject_reason }}</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- ── ตัดสิน ────────────────────────────────────────────────── -->
            <section v-if="isPending && !isPurged" class="card decide-card">
                <h2 class="card-title">{{ t('admin.kycShow.decide.title') }}</h2>

                <div v-if="!showReject" class="decide-row">
                    <div class="decide-approve">
                        <label>{{ t('admin.kycShow.decide.approveNoteLabel') }}</label>
                        <input v-model="approveForm.note" type="text" class="input" :placeholder="t('admin.kycShow.decide.approveNotePlaceholder')" />
                        <button class="btn-approve" :disabled="approveForm.processing" @click="approve">
                            {{ approveForm.processing ? t('admin.kycShow.decide.saving') : '✓ ' + t('admin.kycShow.decide.approve') }}
                        </button>
                    </div>

                    <button class="btn-reject-open" @click="showReject = true">✕ {{ t('admin.kycShow.decide.reject') }}</button>
                </div>

                <div v-else class="reject-panel">
                    <label>{{ t('admin.kycShow.decide.rejectReasonLabel') }} <span class="req">*</span></label>
                    <p class="reject-hint">
                        {{ t('admin.kycShow.decide.rejectHint') }}
                    </p>

                    <div class="reason-chips">
                        <button
                            v-for="r in COMMON_REASON_KEYS"
                            :key="r"
                            type="button"
                            class="chip"
                            :class="{ 'chip--on': rejectForm.reason === t(r) }"
                            @click="rejectForm.reason = t(r)"
                        >
                            {{ t(r) }}
                        </button>
                    </div>

                    <textarea v-model="rejectForm.reason" rows="2" class="input" :placeholder="t('admin.kycShow.decide.rejectReasonPlaceholder')"></textarea>
                    <p v-if="rejectForm.errors.reason" class="field-error">{{ rejectForm.errors.reason }}</p>

                    <label class="mt">{{ t('admin.kycShow.decide.rejectNoteLabel') }}</label>
                    <input v-model="rejectForm.note" type="text" class="input" />

                    <div class="reject-buttons">
                        <button class="btn-reject" :disabled="!rejectForm.reason.trim() || rejectForm.processing" @click="reject">
                            {{ rejectForm.processing ? t('admin.kycShow.decide.saving') : t('admin.kycShow.decide.confirmReject') }}
                        </button>
                        <button class="btn-ghost" @click="showReject = false">{{ t('common.cancel') }}</button>
                    </div>
                </div>
            </section>

            <!-- ── ดูรูปเต็มจอ ───────────────────────────────────────────── -->
            <div v-if="lightbox" class="lightbox" @click="lightbox = null">
                <img :src="lightbox" alt="" />
                <button class="lightbox-close" :aria-label="t('common.close')">✕</button>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.review-page {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.back-link {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.88rem;
    text-decoration: none;
}

.card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 1.25rem;
}

.card-title {
    font-size: 1.02rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: 0.9rem;
}

.sub-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
    margin: 1.25rem 0 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.alert {
    padding: 0.85rem 1rem;
    border-radius: 12px;
    font-size: 0.88rem;
}

.alert-success {
    background: rgb(var(--c-trading-green) / 0.12);
    border: 1px solid rgb(var(--c-trading-green) / 0.3);
    color: #6ee7a8;
}

.alert-warn {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: #fbbf24;
}

.alert-sub {
    margin-top: 0.35rem;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.55);
}

.badge {
    padding: 0.2rem 0.65rem;
    border-radius: 8px;
    font-size: 0.78rem;
}

.badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
.badge-approved { background: rgb(var(--c-trading-green) / 0.15); color: #6ee7a8; }
.badge-rejected { background: rgb(var(--c-trading-red) / 0.15); color: #ff8a9b; }
.badge-cancelled,
.badge-expired { background: rgba(255, 255, 255, 0.07); color: rgba(255, 255, 255, 0.55); }

/* ── layout ── */
.review-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr);
    gap: 1.25rem;
    align-items: start;
}

@media (max-width: 900px) {
    .review-grid {
        grid-template-columns: 1fr;
    }
}

/* ── ฟิลด์ ── */
.field-list {
    display: flex;
    flex-direction: column;
}

.field-row {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 0.75rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 0.87rem;
}

.field-row dt {
    color: rgba(255, 255, 255, 0.45);
}

.field-row dd {
    color: #fff;
    word-break: break-word;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, monospace;
    letter-spacing: 0.02em;
}

.prior-reject,
.prior-note {
    margin-top: 1rem;
    padding: 0.75rem;
    border-radius: 10px;
    font-size: 0.85rem;
}

.prior-reject {
    background: rgb(var(--c-trading-red) / 0.08);
    border: 1px solid rgb(var(--c-trading-red) / 0.2);
}

.prior-note {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.prior-reject strong,
.prior-note strong {
    display: block;
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.78rem;
    margin-bottom: 0.25rem;
}

.prior-reject p,
.prior-note p {
    color: rgba(255, 255, 255, 0.85);
    line-height: 1.5;
}

/* ── เอกสาร ── */
.access-note {
    font-size: 0.78rem;
    color: rgba(255, 255, 255, 0.4);
    margin-bottom: 0.85rem;
    line-height: 1.5;
}

.doc-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.9rem;
}

.doc-card {
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.02);
    padding: 0.6rem;
}

.doc-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.doc-head strong {
    color: #fff;
    font-size: 0.85rem;
    font-weight: 500;
}

.doc-meta {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.35);
}

.doc-image {
    width: 100%;
    border-radius: 8px;
    cursor: zoom-in;
    display: block;
    background: rgba(0, 0, 0, 0.3);
}

.doc-purged {
    padding: 2rem 0;
    text-align: center;
    color: rgba(255, 255, 255, 0.3);
    font-size: 0.82rem;
    font-style: italic;
}

.doc-views {
    margin-top: 0.5rem;
    font-size: 0.74rem;
    color: rgba(255, 255, 255, 0.4);
}

.doc-views summary {
    cursor: pointer;
}

.doc-views ul {
    margin-top: 0.35rem;
    padding-left: 0.85rem;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

/* ── ประวัติ ── */
.history-list {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.history-link {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    padding: 0.4rem 0;
    font-size: 0.83rem;
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
}

.history-reason {
    color: rgba(255, 255, 255, 0.4);
    font-size: 0.78rem;
}

/* ── ตัดสิน ── */
.decide-card {
    border-color: rgba(59, 130, 246, 0.25);
}

.decide-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.decide-approve {
    flex: 1;
    min-width: 260px;
}

.decide-approve label,
.reject-panel label {
    display: block;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 0.3rem;
}

.input {
    width: 100%;
    padding: 0.55rem 0.7rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #fff;
    font-size: 0.87rem;
}

.btn-approve {
    margin-top: 0.6rem;
    padding: 0.6rem 1.4rem;
    border-radius: 10px;
    background: linear-gradient(135deg, rgb(var(--c-trading-green)), #00a844);
    color: #fff;
    font-weight: 600;
    font-size: 0.9rem;
    border: none;
    cursor: pointer;
}

.btn-reject-open {
    padding: 0.6rem 1.2rem;
    border-radius: 10px;
    background: rgb(var(--c-trading-red) / 0.1);
    border: 1px solid rgb(var(--c-trading-red) / 0.35);
    color: #ff8a9b;
    font-size: 0.9rem;
    cursor: pointer;
}

.reject-panel {
    max-width: 640px;
}

.reject-hint {
    font-size: 0.78rem;
    color: rgba(255, 255, 255, 0.4);
    margin-bottom: 0.6rem;
    line-height: 1.5;
}

.req {
    color: #ff6b81;
}

.reason-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-bottom: 0.6rem;
}

.chip {
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.65);
    font-size: 0.76rem;
    cursor: pointer;
}

.chip--on {
    background: rgb(var(--c-trading-red) / 0.15);
    border-color: rgb(var(--c-trading-red) / 0.4);
    color: #ff8a9b;
}

.mt {
    margin-top: 0.85rem;
}

.field-error {
    margin-top: 0.25rem;
    font-size: 0.78rem;
    color: #ff8a9b;
}

.reject-buttons {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.9rem;
}

.btn-reject {
    padding: 0.6rem 1.2rem;
    border-radius: 10px;
    background: rgb(var(--c-trading-red) / 0.85);
    color: #fff;
    font-weight: 600;
    font-size: 0.88rem;
    border: none;
    cursor: pointer;
}

.btn-reject:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-ghost {
    padding: 0.6rem 1.1rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.88rem;
    cursor: pointer;
}

.empty-note {
    padding: 1.5rem 0;
    text-align: center;
    color: rgba(255, 255, 255, 0.35);
    font-size: 0.86rem;
}

/* ── lightbox ── */
.lightbox {
    position: fixed;
    inset: 0;
    z-index: 200;
    background: rgba(0, 0, 0, 0.9);
    display: grid;
    place-items: center;
    padding: 2rem;
    cursor: zoom-out;
}

.lightbox img {
    max-width: 100%;
    max-height: 100%;
    border-radius: 8px;
}

.lightbox-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #fff;
    font-size: 1.1rem;
    cursor: pointer;
}
</style>
