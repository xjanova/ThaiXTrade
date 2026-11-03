<script setup>
/**
 * TPIX TRADE — ยืนยันตัวตน (KYC)
 *
 * ยืนยันตัวตน "ทำในแอป TPIX TRADE เท่านั้น" (เจ้าของสั่ง: "การ kyc ในเว็บทำไม่ได้ ให้ขึ้นว่าทำในแอพ")
 *   ในแอปใช้บัญชี Thaiprompt ที่ยืนยันแล้ว → ผ่านทันที ผลผูกกับบัญชีของกระเป๋า
 *   เว็บจึงเห็นผลเดียวกันเมื่อเชื่อมกระเป๋าใบเดียวกันแล้วเซ็นยืนยัน (หรือล็อกอินบัญชีเดียวกัน)
 *
 * หน้านี้เหลือสามอย่าง:
 *   1. สถานะตอนนี้ + ฟีเจอร์ที่ปลดล็อก
 *   2. พาไปทำในแอป — มือถือ: ปุ่มเปิดแอป (tpixtrade://kyc) · คอมพิวเตอร์: QR ไปหน้าดาวน์โหลด
 *   3. สิทธิตาม PDPA — ขอสำเนาข้อมูลตัวเอง และขอลบ (กฎหมายบังคับให้ใช้สิทธิได้จริง)
 *
 * เปิดได้โดยไม่ต้องล็อกอิน — ปุ่ม "ยืนยันตัวตนก่อนเทรด" พาผู้ใช้กระเป๋าล้วนมาที่นี่
 *   เดิมเด้งไปหน้าเข้าสู่ระบบ ผู้ใช้กระเป๋าไม่มีรหัสผ่านจึงไปต่อไม่ได้
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import AppLayout from '@/Layouts/AppLayout.vue';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { isMobile } from '@/utils/mobileWallet';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const dateLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));

const props = defineProps({
    submission: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    gate: { type: Object, default: () => ({}) },
    features: { type: Array, default: () => [] },
    deletionRequest: { type: Object, default: null },
    app: { type: Object, default: () => ({ deep_link: 'tpixtrade://kyc', download_url: '/download' }) },
});

const page = usePage();
const walletStore = useWalletStore();

const signedIn = computed(() => !!page.props.auth?.user);

// error จาก redirect ของเส้นเดิม (เช่นกลับจาก Thaiprompt ทางเว็บ) — ยังต้องบอกผู้ใช้
const kycError = computed(() => page.props.errors?.kyc || '');

// ─── สถานะปัจจุบัน ────────────────────────────────────────────────────────

const STATUS_META = {
    pending: { labelKey: 'page.kyc.status.pending', cls: 'status-pending', icon: '⏳' },
    approved: { labelKey: 'page.kyc.status.approved', cls: 'status-approved', icon: '✓' },
    rejected: { labelKey: 'page.kyc.status.rejected', cls: 'status-rejected', icon: '✕' },
    cancelled: { labelKey: 'page.kyc.status.cancelled', cls: 'status-neutral', icon: '—' },
    expired: { labelKey: 'page.kyc.status.expired', cls: 'status-neutral', icon: '—' },
};

const currentStatus = computed(() => {
    if (!props.submission) return null;
    return STATUS_META[props.submission.status] ?? STATUS_META.cancelled;
});

const isPending = computed(() => props.submission?.status === 'pending');
const isApproved = computed(() => props.submission?.status === 'approved');
const isThaipromptSubmission = computed(() => props.submission?.source === 'thaiprompt');

// ใบที่ส่งเอกสารไว้ก่อนหน้านี้ยังรอตรวจได้ — ยกเลิกเองได้ (ทีมงานยังตรวจใบเดิมให้ตามปกติ)
const cancelSubmission = () => {
    if (!props.submission) return;
    if (!confirm(t('page.kyc.confirm.cancel'))) return;

    router.post(`/kyc/${props.submission.uuid}/cancel`, {}, { preserveScroll: true });
};

// ─── ฟีเจอร์ที่ปลดล็อกได้ ─────────────────────────────────────────────────

const gatedFeatures = computed(() => props.features.filter((f) => f.enabled));
const featureLabel = (f) => (locale.value === 'th'
    ? f.label_th || f.label_en || f.key
    : f.label_en || f.label_th || f.key);
// ฝั่งเซิร์ฟเวอร์มีคำอธิบายภาษาไทยอย่างเดียวตอนนี้ — มี desc_en เมื่อไหร่ใช้ได้ทันที
const featureDesc = (f) => (locale.value === 'th' ? f.desc_th : f.desc_en || f.desc_th);

// ─── ไปทำในแอป ────────────────────────────────────────────────────────────

const mobile = isMobile();
const downloadQr = ref(null);

/** URL เต็มของหน้าดาวน์โหลด — QR ต้องเป็นลิงก์เต็ม มือถือที่สแกนไม่รู้จักโดเมนเรา */
const downloadUrl = computed(() => {
    const url = props.app?.download_url || '/download';
    try {
        return new URL(url, window.location.origin).toString();
    } catch {
        return url;
    }
});

const walletShort = computed(() => {
    const a = walletStore.address;
    return a ? `${a.slice(0, 6)}…${a.slice(-4)}` : '';
});

const checking = ref(false);

/** ทำในแอปเสร็จแล้วกลับมา — ดึงสถานะใหม่จากเซิร์ฟเวอร์ ไม่ประกอบเองฝั่งหน้าเว็บ */
function recheck() {
    if (checking.value) return;
    checking.value = true;
    router.reload({
        only: ['submission', 'history', 'gate', 'deletionRequest', 'kyc', 'auth'],
        preserveScroll: true,
        onFinish: () => { checking.value = false; },
    });
}

const signing = ref(false);

/**
 * ผลยืนยันผูกกับบัญชีของกระเป๋า — เว็บต้องรู้ว่าเป็นใครก่อนถึงจะแสดงผลได้
 * เชื่อมกระเป๋าค้างไว้แล้ว (tryReconnect คืนแค่ที่อยู่ ไม่ได้เซ็น) → ขอเซ็นเลย
 * เซ็นผ่านเซิร์ฟเวอร์เปิด session ให้ → auth.user เปลี่ยน → watch ด้านล่างดึงสถานะใหม่
 */
async function connectWallet() {
    if (signing.value) return;
    if (walletStore.isConnected) {
        signing.value = true;
        try {
            if (await walletStore.verifyOwnership()) return;
        } finally {
            signing.value = false;
        }
    }
    walletStore.openConnectModal();
}

/*
 * เซ็นยืนยันกระเป๋าบนหน้านี้แล้วเซิร์ฟเวอร์เปิด session ให้ (auth.user เปลี่ยน)
 * props ของหน้านี้ยังเป็นของ "คนแปลกหน้า" — ต้องดึงสถานะของบัญชีนั้นมาแสดง
 */
watch(() => page.props.auth?.user?.id, (now, before) => {
    if (now && now !== before) recheck();
});

// กลับจากแอป (แท็บกลับมาเห็น) — ถามสถานะให้เลย ไม่ต้องให้ผู้ใช้กดเอง
let leftAt = 0;
function onVisibility() {
    if (document.hidden) {
        leftAt = Date.now();
    } else if (leftAt && Date.now() - leftAt > 5000 && signedIn.value && !isApproved.value) {
        recheck();
    }
}

onMounted(async () => {
    document.addEventListener('visibilitychange', onVisibility);

    if (!mobile && !isApproved.value) {
        downloadQr.value = await QRCode.toDataURL(downloadUrl.value, { width: 168, margin: 1 }).catch(() => null);
    }
});

onUnmounted(() => document.removeEventListener('visibilitychange', onVisibility));

// ─── PDPA ─────────────────────────────────────────────────────────────────

const showDeletionForm = ref(false);
const deletionForm = useForm({ reason: '' });

const requestDeletion = () => {
    if (!confirm(t('page.kyc.confirm.deletion'))) {
        return;
    }

    deletionForm.post('/kyc/deletion-request', {
        preserveScroll: true,
        onSuccess: () => {
            deletionForm.reset();
            showDeletionForm.value = false;
        },
    });
};

const formatDate = (iso) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(dateLocale.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
};
</script>

<template>
    <Head :title="t('page.kyc.headTitle')" />

    <AppLayout>
        <div class="kyc-page">
            <header class="kyc-header">
                <h1>{{ t('page.kyc.header.title') }}</h1>
                <p>{{ t('page.kyc.header.desc') }}</p>
            </header>

            <div v-if="page.props.flash?.success" class="alert alert-success">
                {{ page.props.flash.success }}
            </div>
            <div v-if="kycError" class="alert alert-error">
                {{ kycError }}
            </div>

            <!-- ── สถานะปัจจุบัน ─────────────────────────────────────────── -->
            <section v-if="submission" class="glass-card status-card" :class="currentStatus.cls">
                <div class="status-head">
                    <span class="status-icon">{{ currentStatus.icon }}</span>
                    <div>
                        <h2>{{ t(currentStatus.labelKey) }}</h2>
                        <p v-if="isThaipromptSubmission" class="status-sub">
                            {{ t('page.kyc.status.viaThaiprompt', { date: formatDate(submission.reviewed_at || submission.submitted_at) }) }}
                        </p>
                        <p v-else class="status-sub">
                            {{ t('page.kyc.status.submittedAt', { date: formatDate(submission.submitted_at) }) }}
                            <template v-if="submission.reviewed_at">
                                · {{ t('page.kyc.status.reviewedAt', { date: formatDate(submission.reviewed_at) }) }}
                            </template>
                        </p>
                    </div>
                </div>

                <p v-if="isPending" class="status-note">
                    {{ t('page.kyc.status.pendingNote') }}
                </p>

                <div v-if="submission.reject_reason" class="reject-box">
                    <strong>{{ t('page.kyc.reject.title') }}</strong>
                    <p>{{ submission.reject_reason }}</p>
                    <p class="reject-hint">{{ t('page.kyc.reject.hint') }}</p>
                </div>

                <button v-if="isPending" class="btn-ghost" @click="cancelSubmission">
                    {{ t('page.kyc.cancelRequest') }}
                </button>
            </section>

            <!-- ── ทำในแอป ───────────────────────────────────────────────── -->
            <section v-if="!isApproved" class="glass-card app-card" data-test="kyc-app-card">
                <div class="app-head">
                    <BrandLogo variant="icon" class="app-logo" alt="" />
                    <div>
                        <h2 class="section-title app-title">{{ t('page.kyc.app.title') }}</h2>
                        <p class="app-sub">{{ t('page.kyc.app.sub') }}</p>
                    </div>
                </div>

                <ol class="app-steps">
                    <li>{{ t('page.kyc.app.step1Before') }} <strong>TPIX TRADE</strong> {{ t('page.kyc.app.step1After') }}</li>
                    <li>
                        {{ t('page.kyc.app.step2Before') }} <strong>{{ t('page.kyc.app.step2Strong') }}</strong>
                        <span v-if="walletShort" class="wallet-chip">{{ walletShort }}</span>
                    </li>
                    <li>
                        {{ t('page.kyc.app.step3Open') }} <strong>{{ t('page.kyc.app.step3Menu') }}</strong>
                        {{ t('page.kyc.app.step3Tap') }} <strong>{{ t('page.kyc.app.step3Button') }}</strong>
                        {{ t('page.kyc.app.step3Note') }}
                    </li>
                    <li>{{ t('page.kyc.app.step4') }}</li>
                </ol>

                <div class="app-actions">
                    <template v-if="mobile">
                        <a :href="app.deep_link" class="btn-primary app-open" data-test="kyc-open-app">
                            {{ t('page.kyc.app.openApp') }}
                        </a>
                        <Link :href="app.download_url" class="btn-ghost">{{ t('page.kyc.app.noApp') }}</Link>
                    </template>

                    <template v-else>
                        <div v-if="downloadQr" class="app-qr">
                            <img :src="downloadQr" :alt="t('page.kyc.app.qrAlt')" width="168" height="168" />
                            <span>{{ t('page.kyc.app.qrScan') }}</span>
                        </div>
                        <Link :href="app.download_url" class="btn-ghost">{{ t('page.kyc.app.downloadPage') }}</Link>
                    </template>

                    <button
                        v-if="signedIn"
                        type="button"
                        class="btn-ghost"
                        :disabled="checking"
                        data-test="kyc-recheck"
                        @click="recheck"
                    >
                        {{ checking ? t('page.kyc.app.checking') : t('page.kyc.app.recheck') }}
                    </button>
                </div>

                <!-- ยังไม่รู้ว่าเป็นใคร — ผลยืนยันผูกกับบัญชีของกระเป๋า ต้องเชื่อม+เซ็นก่อนถึงจะเห็นผล -->
                <div v-if="!signedIn" class="guest-note">
                    <p>{{ t('page.kyc.guest.note') }}</p>
                    <button type="button" class="btn-ghost" :disabled="signing" data-test="kyc-connect-wallet" @click="connectWallet">
                        {{ signing ? t('page.kyc.guest.waitingSign') : (walletStore.isConnected ? t('page.kyc.guest.signWallet') : t('page.kyc.guest.connectWallet')) }}
                    </button>
                </div>
            </section>

            <!-- ── ปลดล็อกอะไรบ้าง ───────────────────────────────────────── -->
            <section v-if="gatedFeatures.length" class="glass-card">
                <h2 class="section-title">{{ t('page.kyc.features.title') }}</h2>
                <ul class="feature-list">
                    <li v-for="f in gatedFeatures" :key="f.key" class="feature-item">
                        <span
                            class="feature-check"
                            :class="{ 'feature-check--on': gate.features?.[f.key]?.passed }"
                        >
                            {{ gate.features?.[f.key]?.passed ? '✓' : '🔒' }}
                        </span>
                        <div>
                            <strong>{{ featureLabel(f) }}</strong>
                            <span v-if="featureDesc(f)" class="feature-desc">{{ featureDesc(f) }}</span>
                            <span v-if="f.level === 'enhanced'" class="badge-level">{{ t('page.kyc.features.enhanced') }}</span>
                        </div>
                    </li>
                </ul>
            </section>

            <p v-else-if="!submission" class="glass-card muted-note">
                {{ t('page.kyc.features.none') }}
            </p>

            <!-- ── ประวัติการยื่น ─────────────────────────────────────────── -->
            <section v-if="history.length > 1" class="glass-card">
                <h2 class="section-title">{{ t('page.kyc.history.title') }}</h2>
                <ul class="history-list">
                    <li v-for="h in history" :key="h.uuid" class="history-row">
                        <span class="history-status" :class="STATUS_META[h.status]?.cls">
                            {{ STATUS_META[h.status] ? t(STATUS_META[h.status].labelKey) : h.status }}
                        </span>
                        <span class="history-date">{{ formatDate(h.submitted_at) }}</span>
                        <span v-if="h.reject_reason" class="history-reason">{{ h.reject_reason }}</span>
                    </li>
                </ul>
            </section>

            <!-- ── สิทธิตาม PDPA ──────────────────────────────────────────── -->
            <section v-if="submission" class="glass-card pdpa-card">
                <h2 class="section-title">{{ t('page.kyc.pdpa.title') }}</h2>
                <p class="pdpa-intro">{{ t('page.kyc.pdpa.intro') }}</p>

                <div class="pdpa-actions">
                    <a href="/kyc/export" class="btn-ghost">
                        {{ t('page.kyc.pdpa.export') }}
                        <span class="btn-sub">{{ t('page.kyc.pdpa.exportSub') }}</span>
                    </a>

                    <div v-if="deletionRequest" class="deletion-pending">
                        <strong>{{ t('page.kyc.pdpa.deletionSent') }}</strong>
                        <span>{{ t('page.kyc.pdpa.deletionSentAt', { date: formatDate(deletionRequest.requested_at) }) }}</span>
                    </div>

                    <template v-else>
                        <button v-if="!showDeletionForm" class="btn-danger-ghost" @click="showDeletionForm = true">
                            {{ t('page.kyc.pdpa.requestDeletion') }}
                        </button>

                        <div v-else class="deletion-form">
                            <label>{{ t('page.kyc.pdpa.reason') }}</label>
                            <textarea
                                v-model="deletionForm.reason"
                                rows="2"
                                class="trading-input"
                                :placeholder="t('page.kyc.pdpa.reasonPlaceholder')"
                            ></textarea>
                            <p class="deletion-warn">
                                {{ t('page.kyc.pdpa.warn') }}
                            </p>
                            <div class="deletion-buttons">
                                <button class="btn-danger-ghost" :disabled="deletionForm.processing" @click="requestDeletion">
                                    {{ deletionForm.processing ? t('page.kyc.pdpa.sending') : t('page.kyc.pdpa.confirmDeletion') }}
                                </button>
                                <button class="btn-ghost" @click="showDeletionForm = false">{{ t('common.cancel') }}</button>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.kyc-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 1.5rem 1rem 4rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.kyc-header h1 {
    font-size: 1.75rem;
    font-weight: 700;
    color: #fff;
    margin-bottom: 0.35rem;
}

.kyc-header p {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.9rem;
    line-height: 1.6;
}

.glass-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 1.25rem;
    backdrop-filter: blur(12px);
}

.section-title {
    font-size: 1.05rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: 0.85rem;
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

.alert-error {
    background: rgb(var(--c-trading-red) / 0.12);
    border: 1px solid rgb(var(--c-trading-red) / 0.3);
    color: #ff8a9b;
}

/* ── สถานะ ── */
.status-card {
    border-left: 3px solid rgba(255, 255, 255, 0.2);
}

.status-card.status-pending { border-left-color: #f59e0b; }
.status-card.status-approved { border-left-color: rgb(var(--c-trading-green)); }
.status-card.status-rejected { border-left-color: rgb(var(--c-trading-red)); }

.status-head {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.status-icon {
    font-size: 1.5rem;
    width: 2.5rem;
    height: 2.5rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.06);
    flex-shrink: 0;
}

.status-head h2 {
    font-size: 1.05rem;
    font-weight: 600;
    color: #fff;
}

.status-sub {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.5);
    margin-top: 0.15rem;
}

.status-note {
    margin-top: 0.85rem;
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.6);
    line-height: 1.55;
}

.reject-box {
    margin-top: 0.85rem;
    padding: 0.85rem;
    border-radius: 12px;
    background: rgb(var(--c-trading-red) / 0.08);
    border: 1px solid rgb(var(--c-trading-red) / 0.25);
}

.reject-box strong {
    display: block;
    color: #ff8a9b;
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
}

.reject-box p {
    font-size: 0.88rem;
    color: rgba(255, 255, 255, 0.85);
    line-height: 1.5;
}

.reject-hint {
    margin-top: 0.5rem;
    font-size: 0.8rem !important;
    color: rgba(255, 255, 255, 0.5) !important;
}

/* ── ทำในแอป ── */
.app-card {
    border: 1px solid rgb(var(--c-primary-500) / 0.35);
    background:
        radial-gradient(120% 140% at 0% 0%, rgb(var(--c-primary-500) / 0.12), transparent 60%),
        radial-gradient(120% 140% at 100% 100%, rgb(var(--c-accent-500) / 0.1), transparent 60%),
        rgba(255, 255, 255, 0.03);
}

.app-head {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-bottom: 1rem;
}

.app-logo {
    width: 3rem;
    height: 3rem;
    flex-shrink: 0;
}

.app-title {
    margin-bottom: 0.1rem;
}

.app-sub {
    font-size: 0.8rem;
    color: rgb(var(--c-primary-300));
}

.app-steps {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    list-style: decimal;
    padding-left: 1.2rem;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.75);
    line-height: 1.6;
    margin-bottom: 1.1rem;
}

.app-steps strong {
    color: #fff;
    font-weight: 600;
}

.wallet-chip {
    display: inline-block;
    margin-left: 0.35rem;
    padding: 0.05rem 0.45rem;
    border-radius: 6px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 0.75rem;
    color: rgb(var(--c-primary-300));
    background: rgb(var(--c-primary-500) / 0.12);
    border: 1px solid rgb(var(--c-primary-500) / 0.25);
}

.app-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.85rem;
}

.app-actions .btn-ghost {
    margin-top: 0;
}

.app-open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.4rem;
    text-decoration: none;
}

.app-qr {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.6);
}

.app-qr img {
    border-radius: 12px;
    background: #fff;
    padding: 6px;
}

.guest-note {
    margin-top: 1.1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.6);
    line-height: 1.55;
}

.guest-note .btn-ghost {
    align-self: flex-start;
    margin-top: 0;
}

/* ── ฟีเจอร์ ── */
.feature-list {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.feature-item {
    display: flex;
    align-items: flex-start;
    gap: 0.7rem;
    font-size: 0.9rem;
}

.feature-check {
    width: 1.5rem;
    height: 1.5rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.06);
    font-size: 0.7rem;
    flex-shrink: 0;
}

.feature-check--on {
    background: rgb(var(--c-trading-green) / 0.18);
    color: #6ee7a8;
}

.feature-item strong {
    color: #fff;
    font-weight: 500;
}

.feature-desc {
    display: block;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.5);
}

.badge-level {
    display: inline-block;
    margin-top: 0.25rem;
    padding: 0.1rem 0.45rem;
    border-radius: 6px;
    font-size: 0.7rem;
    background: rgba(245, 158, 11, 0.15);
    color: #fbbf24;
}

.muted-note {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.88rem;
    line-height: 1.6;
}

/* ── ปุ่ม ── */
.trading-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #fff;
    font-size: 0.9rem;
}

.trading-input:focus {
    outline: none;
    border-color: rgba(59, 130, 246, 0.6);
}

.btn-primary {
    padding: 0.75rem 1.25rem;
    border-radius: 12px;
    background: linear-gradient(135deg, rgb(var(--c-primary-500)), rgb(var(--c-accent-600)));
    color: #fff;
    font-weight: 600;
    font-size: 0.92rem;
    border: none;
    cursor: pointer;
    transition: opacity 0.15s;
}

.btn-primary:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-ghost {
    display: inline-flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.65rem 1rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.87rem;
    cursor: pointer;
    text-decoration: none;
    margin-top: 0.85rem;
}

.btn-ghost:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-sub {
    font-size: 0.74rem;
    color: rgba(255, 255, 255, 0.45);
}

.btn-danger-ghost {
    padding: 0.65rem 1rem;
    border-radius: 10px;
    background: rgb(var(--c-trading-red) / 0.1);
    border: 1px solid rgb(var(--c-trading-red) / 0.3);
    color: #ff8a9b;
    font-size: 0.87rem;
    cursor: pointer;
}

/* ── ประวัติ ── */
.history-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.history-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    font-size: 0.83rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.history-status {
    padding: 0.15rem 0.5rem;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.06);
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.75rem;
}

.history-status.status-approved { background: rgb(var(--c-trading-green) / 0.15); color: #6ee7a8; }
.history-status.status-rejected { background: rgb(var(--c-trading-red) / 0.15); color: #ff8a9b; }
.history-status.status-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }

.history-date {
    color: rgba(255, 255, 255, 0.5);
}

.history-reason {
    color: rgba(255, 255, 255, 0.4);
    font-size: 0.78rem;
}

/* ── PDPA ── */
.pdpa-card {
    border: 1px solid rgba(59, 130, 246, 0.2);
}

.pdpa-intro {
    font-size: 0.86rem;
    color: rgba(255, 255, 255, 0.6);
    line-height: 1.6;
    margin-bottom: 0.5rem;
}

.pdpa-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    align-items: flex-start;
}

.deletion-pending {
    padding: 0.75rem 1rem;
    border-radius: 10px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.25);
    font-size: 0.85rem;
}

.deletion-pending strong {
    display: block;
    color: #fbbf24;
}

.deletion-pending span {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.8rem;
}

.deletion-form {
    width: 100%;
}

.deletion-form label {
    display: block;
    font-size: 0.82rem;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 0.3rem;
}

.deletion-warn {
    margin-top: 0.6rem;
    font-size: 0.8rem;
    color: #fbbf24;
    line-height: 1.55;
}

.deletion-buttons {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.75rem;
}

.deletion-buttons .btn-ghost {
    margin-top: 0;
}
</style>
