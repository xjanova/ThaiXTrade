<script setup>
/**
 * TPIX TRADE — ยืนยันตัวตน (KYC)
 *
 * หน้านี้ทำสามอย่างในที่เดียว เพราะมันคือเรื่องเดียวกันในสายตาผู้ใช้:
 *   1. บอกว่าตอนนี้อยู่สถานะไหน และต้องยืนยันตัวตนไปเพื่อใช้อะไร
 *   2. ฟอร์มยื่นเอกสาร
 *   3. สิทธิตาม PDPA — ขอสำเนาข้อมูลตัวเอง และขอลบ
 *
 * ข้อ 3 ไม่ใช่ของแถม กฎหมายบังคับให้ใช้สิทธิได้จริง ไม่ใช่แค่เขียนไว้ในนโยบาย
 *
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    submission: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    gate: { type: Object, default: () => ({}) },
    features: { type: Array, default: () => [] },
    requirements: { type: Object, default: () => ({}) },
    uploads: { type: Object, default: () => ({}) },
    consent: { type: Object, default: () => ({}) },
    deletionRequest: { type: Object, default: null },
});

const page = usePage();

// ─── สถานะปัจจุบัน ────────────────────────────────────────────────────────

const STATUS_META = {
    pending: { label: 'กำลังตรวจสอบ', cls: 'status-pending', icon: '⏳' },
    approved: { label: 'ยืนยันตัวตนแล้ว', cls: 'status-approved', icon: '✓' },
    rejected: { label: 'ไม่ผ่านการตรวจ', cls: 'status-rejected', icon: '✕' },
    cancelled: { label: 'ยกเลิกแล้ว', cls: 'status-neutral', icon: '—' },
    expired: { label: 'หมดอายุ', cls: 'status-neutral', icon: '—' },
};

const currentStatus = computed(() => {
    if (!props.submission) return null;
    return STATUS_META[props.submission.status] ?? STATUS_META.cancelled;
});

const isPending = computed(() => props.submission?.status === 'pending');
const isApproved = computed(() => props.submission?.status === 'approved');

// ยื่นใหม่ได้เมื่อ: ยังไม่เคยยื่น · ถูกปฏิเสธ · ยกเลิกไปเอง
// ไม่ให้ยื่นทับตอนที่ทีมงานกำลังตรวจอยู่ — ใบจะสลับกันจนตรวจผิดใบ
const canSubmit = computed(() => !isPending.value && !isApproved.value);

// ─── ฟีเจอร์ที่ปลดล็อกได้ ─────────────────────────────────────────────────

const gatedFeatures = computed(() =>
    props.features.filter((f) => f.enabled)
);

const featureLabel = (f) => f.label_th || f.label_en || f.key;

// ─── ฟอร์มยื่น ────────────────────────────────────────────────────────────

const DOC_META = {
    id_card_front: { label: 'หน้าบัตรประชาชน / พาสปอร์ต', hint: 'ให้เห็นเลขบัตรและรูปหน้าชัด ไม่มีแสงสะท้อนบัง' },
    id_card_back: { label: 'หลังบัตรประชาชน', hint: 'ให้เห็นรหัสหลังบัตรครบ' },
    selfie_with_id: { label: 'รูปตัวเองถือบัตร', hint: 'ถือบัตรข้างหน้า เห็นทั้งหน้าคุณและหน้าบัตรในรูปเดียวกัน' },
    address_proof: { label: 'หลักฐานที่อยู่', hint: 'บิลค่าน้ำ/ไฟ/เน็ต หรือทะเบียนบ้าน อายุไม่เกิน 3 เดือน' },
};

const form = useForm({
    level: 'basic',
    full_name: '',
    full_name_en: '',
    id_type: 'national_id',
    national_id: '',
    date_of_birth: '',
    nationality: 'TH',
    address: '',
    occupation: '',
    phone: '',
    consent: false,
    documents: {
        id_card_front: null,
        id_card_back: null,
        selfie_with_id: null,
        address_proof: null,
    },
});

const requiredDocs = computed(() => props.requirements[form.level] ?? []);

// พรีวิวรูปที่เลือก — ผู้ใช้ต้องเห็นว่าเลือกไฟล์ถูกใบก่อนส่ง
// ส่งบัตรผิดด้านแล้วรอสามวันกว่าจะรู้ว่าไม่ผ่าน คือประสบการณ์ที่แย่ที่สุดของหน้านี้
const previews = ref({});

const pickFile = (type, event) => {
    const file = event.target.files?.[0] ?? null;
    form.documents[type] = file;

    if (previews.value[type]) {
        URL.revokeObjectURL(previews.value[type]);
    }

    previews.value[type] = file ? URL.createObjectURL(file) : null;
};

const maxSizeLabel = computed(() => {
    const kb = props.uploads.max_size_kb ?? 8192;
    return kb >= 1024 ? `${Math.round(kb / 1024)} MB` : `${kb} KB`;
});

const acceptAttr = computed(() =>
    (props.uploads.extensions ?? ['jpg', 'png']).map((e) => `.${e}`).join(',')
);

const missingDocs = computed(() =>
    requiredDocs.value.filter((type) => !form.documents[type])
);

const canSend = computed(() =>
    form.consent && missingDocs.value.length === 0 && !form.processing
);

const submit = () => {
    form.post('/kyc', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            previews.value = {};
        },
    });
};

const cancelSubmission = () => {
    if (!props.submission) return;
    if (!confirm('ยกเลิกคำขอนี้? เอกสารที่ส่งไปแล้วจะไม่ถูกตรวจ')) return;

    router.post(`/kyc/${props.submission.uuid}/cancel`, {}, { preserveScroll: true });
};

// ─── PDPA ─────────────────────────────────────────────────────────────────

const showDeletionForm = ref(false);

const deletionForm = useForm({ reason: '' });

const requestDeletion = () => {
    if (!confirm('ยืนยันขอลบข้อมูลยืนยันตัวตนทั้งหมด?\n\nเมื่อทีมงานดำเนินการแล้ว เอกสารและข้อมูลส่วนตัวจะถูกลบถาวร เอากลับมาไม่ได้ และสิทธิที่ได้จากการยืนยันตัวตนจะหายไปด้วย')) {
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
    return new Date(iso).toLocaleString('th-TH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
};
</script>

<template>
    <Head title="ยืนยันตัวตน" />

    <AppLayout>
        <div class="kyc-page">
            <header class="kyc-header">
                <h1>ยืนยันตัวตน</h1>
                <p>
                    ยืนยันตัวตนครั้งเดียว ใช้ได้กับทุกบริการที่ต้องการ
                    เอกสารของคุณถูกเข้ารหัสและเก็บไว้เฉพาะในเซิร์ฟเวอร์ของเรา
                </p>
            </header>

            <div v-if="page.props.flash?.success" class="alert alert-success">
                {{ page.props.flash.success }}
            </div>
            <div v-if="form.errors.kyc" class="alert alert-error">
                {{ form.errors.kyc }}
            </div>

            <!-- ── สถานะปัจจุบัน ─────────────────────────────────────────── -->
            <section v-if="submission" class="glass-card status-card" :class="currentStatus.cls">
                <div class="status-head">
                    <span class="status-icon">{{ currentStatus.icon }}</span>
                    <div>
                        <h2>{{ currentStatus.label }}</h2>
                        <p class="status-sub">
                            ยื่นเมื่อ {{ formatDate(submission.submitted_at) }}
                            <template v-if="submission.reviewed_at">
                                · ตรวจเมื่อ {{ formatDate(submission.reviewed_at) }}
                            </template>
                        </p>
                    </div>
                </div>

                <p v-if="isPending" class="status-note">
                    ทีมงานตรวจเอกสารด้วยตัวเอง โดยปกติใช้เวลา 1–3 วันทำการ
                </p>

                <div v-if="submission.reject_reason" class="reject-box">
                    <strong>เหตุผลที่ไม่ผ่าน</strong>
                    <p>{{ submission.reject_reason }}</p>
                    <p class="reject-hint">แก้ตามนี้แล้วยื่นใหม่ได้ทันทีจากฟอร์มด้านล่าง</p>
                </div>

                <button v-if="isPending" class="btn-ghost" @click="cancelSubmission">
                    ยกเลิกคำขอ
                </button>
            </section>

            <!-- ── ปลดล็อกอะไรบ้าง ───────────────────────────────────────── -->
            <section v-if="gatedFeatures.length" class="glass-card">
                <h2 class="section-title">ยืนยันตัวตนแล้วใช้อะไรได้</h2>
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
                            <span v-if="f.desc_th" class="feature-desc">{{ f.desc_th }}</span>
                            <span v-if="f.level === 'enhanced'" class="badge-level">ต้องระดับเพิ่มเติม</span>
                        </div>
                    </li>
                </ul>
            </section>

            <p v-else-if="!submission" class="glass-card muted-note">
                ตอนนี้ยังไม่มีบริการไหนที่บังคับให้ยืนยันตัวตน
                คุณยื่นล่วงหน้าไว้ได้ เผื่อมีการเปิดใช้ในภายหลัง
            </p>

            <!-- ── ฟอร์มยื่น ──────────────────────────────────────────────── -->
            <section v-if="canSubmit" class="glass-card">
                <h2 class="section-title">
                    {{ submission ? 'ยื่นใหม่' : 'ส่งเอกสารยืนยันตัวตน' }}
                </h2>

                <form @submit.prevent="submit">
                    <!-- ระดับ -->
                    <div class="level-picker">
                        <label class="level-option" :class="{ 'level-option--active': form.level === 'basic' }">
                            <input v-model="form.level" type="radio" value="basic" />
                            <div>
                                <strong>ระดับปกติ</strong>
                                <span>บัตรประชาชน + รูปถือบัตร</span>
                            </div>
                        </label>
                        <label class="level-option" :class="{ 'level-option--active': form.level === 'enhanced' }">
                            <input v-model="form.level" type="radio" value="enhanced" />
                            <div>
                                <strong>ระดับเพิ่มเติม</strong>
                                <span>เพิ่มหลังบัตร + หลักฐานที่อยู่</span>
                            </div>
                        </label>
                    </div>

                    <!-- ข้อมูลส่วนตัว -->
                    <div class="field-grid">
                        <div class="field">
                            <label>ชื่อ-นามสกุล (ตามบัตร) <span class="req">*</span></label>
                            <input v-model="form.full_name" type="text" class="trading-input" required />
                            <p v-if="form.errors.full_name" class="field-error">{{ form.errors.full_name }}</p>
                        </div>

                        <div class="field">
                            <label>ชื่อภาษาอังกฤษ</label>
                            <input v-model="form.full_name_en" type="text" class="trading-input" />
                        </div>

                        <div class="field">
                            <label>ประเภทเอกสาร <span class="req">*</span></label>
                            <select v-model="form.id_type" class="trading-input">
                                <option value="national_id">บัตรประชาชน</option>
                                <option value="passport">พาสปอร์ต</option>
                            </select>
                        </div>

                        <div class="field">
                            <label>
                                {{ form.id_type === 'passport' ? 'เลขพาสปอร์ต' : 'เลขบัตรประชาชน' }}
                                <span class="req">*</span>
                            </label>
                            <input v-model="form.national_id" type="text" class="trading-input" required />
                            <p v-if="form.errors.national_id" class="field-error">{{ form.errors.national_id }}</p>
                        </div>

                        <div class="field">
                            <label>วันเกิด <span class="req">*</span></label>
                            <input v-model="form.date_of_birth" type="date" class="trading-input" required />
                            <p v-if="form.errors.date_of_birth" class="field-error">{{ form.errors.date_of_birth }}</p>
                        </div>

                        <div class="field">
                            <label>สัญชาติ <span class="req">*</span></label>
                            <select v-model="form.nationality" class="trading-input">
                                <option value="TH">ไทย</option>
                                <option value="LA">ลาว</option>
                                <option value="MM">เมียนมา</option>
                                <option value="KH">กัมพูชา</option>
                                <option value="VN">เวียดนาม</option>
                                <option value="MY">มาเลเซีย</option>
                                <option value="SG">สิงคโปร์</option>
                                <option value="CN">จีน</option>
                                <option value="JP">ญี่ปุ่น</option>
                                <option value="KR">เกาหลีใต้</option>
                                <option value="US">สหรัฐอเมริกา</option>
                                <option value="GB">สหราชอาณาจักร</option>
                                <option value="XX">อื่นๆ</option>
                            </select>
                        </div>

                        <div class="field field--wide">
                            <label>ที่อยู่ปัจจุบัน <span class="req">*</span></label>
                            <textarea v-model="form.address" rows="2" class="trading-input" required></textarea>
                            <p v-if="form.errors.address" class="field-error">{{ form.errors.address }}</p>
                        </div>

                        <div class="field">
                            <label>อาชีพ</label>
                            <input v-model="form.occupation" type="text" class="trading-input" />
                        </div>

                        <div class="field">
                            <label>เบอร์โทร</label>
                            <input v-model="form.phone" type="tel" class="trading-input" />
                        </div>
                    </div>

                    <!-- เอกสาร -->
                    <h3 class="sub-title">เอกสารแนบ</h3>
                    <p class="upload-note">
                        รับไฟล์ {{ (uploads.extensions ?? []).join(', ') }} ขนาดไม่เกิน {{ maxSizeLabel }} ต่อไฟล์
                    </p>

                    <div class="doc-grid">
                        <div v-for="type in requiredDocs" :key="type" class="doc-slot">
                            <label class="doc-label">
                                {{ DOC_META[type]?.label ?? type }} <span class="req">*</span>
                            </label>
                            <p class="doc-hint">{{ DOC_META[type]?.hint }}</p>

                            <label class="doc-drop" :class="{ 'doc-drop--filled': form.documents[type] }">
                                <input
                                    type="file"
                                    :accept="acceptAttr"
                                    class="doc-input"
                                    @change="pickFile(type, $event)"
                                />
                                <img v-if="previews[type]" :src="previews[type]" class="doc-preview" alt="" />
                                <span v-else class="doc-placeholder">แตะเพื่อเลือกรูป</span>
                            </label>

                            <p v-if="form.errors[`documents.${type}`]" class="field-error">
                                {{ form.errors[`documents.${type}`] }}
                            </p>
                        </div>
                    </div>

                    <!-- ความยินยอม -->
                    <label class="consent-box">
                        <input v-model="form.consent" type="checkbox" />
                        <span>
                            ข้าพเจ้ายินยอมให้ TPIX TRADE เก็บและใช้ข้อมูลส่วนบุคคลและเอกสารข้างต้น
                            เพื่อการยืนยันตัวตนและปฏิบัติตามกฎหมายที่เกี่ยวข้องเท่านั้น
                            โดยจะเก็บไว้ไม่เกิน {{ consent.retention_days }} วันนับจากวันอนุมัติ
                            และข้าพเจ้าขอถอนความยินยอมหรือขอลบข้อมูลได้ตลอดเวลา
                            <em class="consent-version">(ข้อความยินยอมเวอร์ชัน {{ consent.version }})</em>
                        </span>
                    </label>

                    <p v-if="missingDocs.length" class="missing-note">
                        ยังขาดเอกสาร:
                        {{ missingDocs.map((t) => DOC_META[t]?.label ?? t).join(' · ') }}
                    </p>

                    <button type="submit" class="btn-primary btn-submit" :disabled="!canSend">
                        {{ form.processing ? 'กำลังส่ง…' : 'ส่งเอกสาร' }}
                    </button>
                </form>
            </section>

            <!-- ── ประวัติการยื่น ─────────────────────────────────────────── -->
            <section v-if="history.length > 1" class="glass-card">
                <h2 class="section-title">ประวัติการยื่น</h2>
                <ul class="history-list">
                    <li v-for="h in history" :key="h.uuid" class="history-row">
                        <span class="history-status" :class="STATUS_META[h.status]?.cls">
                            {{ STATUS_META[h.status]?.label ?? h.status }}
                        </span>
                        <span class="history-date">{{ formatDate(h.submitted_at) }}</span>
                        <span v-if="h.reject_reason" class="history-reason">{{ h.reject_reason }}</span>
                    </li>
                </ul>
            </section>

            <!-- ── สิทธิตาม PDPA ──────────────────────────────────────────── -->
            <section v-if="submission" class="glass-card pdpa-card">
                <h2 class="section-title">สิทธิของคุณเหนือข้อมูลนี้</h2>
                <p class="pdpa-intro">
                    ตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล คุณเป็นเจ้าของข้อมูลเหล่านี้
                    ไม่ใช่เรา — ขอดูหรือขอลบได้ตลอดเวลา
                </p>

                <div class="pdpa-actions">
                    <a href="/kyc/export" class="btn-ghost">
                        ขอสำเนาข้อมูลของฉัน
                        <span class="btn-sub">ดาวน์โหลดไฟล์ พร้อมรายชื่อทีมงานที่เคยเปิดดูเอกสาร</span>
                    </a>

                    <div v-if="deletionRequest" class="deletion-pending">
                        <strong>ส่งคำขอลบข้อมูลแล้ว</strong>
                        <span>ยื่นเมื่อ {{ formatDate(deletionRequest.requested_at) }} · รอทีมงานดำเนินการ</span>
                    </div>

                    <template v-else>
                        <button v-if="!showDeletionForm" class="btn-danger-ghost" @click="showDeletionForm = true">
                            ขอลบข้อมูลของฉัน
                        </button>

                        <div v-else class="deletion-form">
                            <label>เหตุผล (ไม่บังคับ)</label>
                            <textarea
                                v-model="deletionForm.reason"
                                rows="2"
                                class="trading-input"
                                placeholder="เช่น ไม่ประสงค์ใช้บริการต่อ"
                            ></textarea>
                            <p class="deletion-warn">
                                เมื่อลบแล้วเอกสารและข้อมูลส่วนตัวจะหายถาวร
                                และสิทธิที่ได้จากการยืนยันตัวตนจะถูกยกเลิกไปด้วย
                                หากต้องการใช้บริการที่ต้องยืนยันตัวตนอีกครั้ง ต้องส่งเอกสารใหม่ทั้งหมด
                            </p>
                            <div class="deletion-buttons">
                                <button class="btn-danger-ghost" :disabled="deletionForm.processing" @click="requestDeletion">
                                    {{ deletionForm.processing ? 'กำลังส่ง…' : 'ยืนยันขอลบ' }}
                                </button>
                                <button class="btn-ghost" @click="showDeletionForm = false">ยกเลิก</button>
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

.sub-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #fff;
    margin: 1.5rem 0 0.35rem;
}

.alert {
    padding: 0.85rem 1rem;
    border-radius: 12px;
    font-size: 0.9rem;
}

.alert-success {
    background: rgba(0, 200, 83, 0.12);
    border: 1px solid rgba(0, 200, 83, 0.3);
    color: #6ee7a8;
}

.alert-error {
    background: rgba(255, 23, 68, 0.12);
    border: 1px solid rgba(255, 23, 68, 0.3);
    color: #ff8a9b;
}

/* ── สถานะ ── */
.status-card {
    border-left: 3px solid rgba(255, 255, 255, 0.2);
}

.status-card.status-pending { border-left-color: #f59e0b; }
.status-card.status-approved { border-left-color: #00c853; }
.status-card.status-rejected { border-left-color: #ff1744; }

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
}

.reject-box {
    margin-top: 0.85rem;
    padding: 0.85rem;
    border-radius: 12px;
    background: rgba(255, 23, 68, 0.08);
    border: 1px solid rgba(255, 23, 68, 0.25);
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
    background: rgba(0, 200, 83, 0.18);
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

/* ── ระดับ ── */
.level-picker {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.7rem;
    margin-bottom: 1.25rem;
}

.level-option {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.85rem;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.02);
    cursor: pointer;
    transition: all 0.15s;
}

.level-option--active {
    border-color: rgba(59, 130, 246, 0.6);
    background: rgba(59, 130, 246, 0.08);
}

.level-option strong {
    display: block;
    color: #fff;
    font-size: 0.9rem;
}

.level-option span {
    font-size: 0.78rem;
    color: rgba(255, 255, 255, 0.5);
}

/* ── ฟิลด์ ── */
.field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.85rem;
}

.field--wide {
    grid-column: 1 / -1;
}

.field label {
    display: block;
    font-size: 0.82rem;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 0.3rem;
}

.req {
    color: #ff6b81;
}

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

.field-error {
    margin-top: 0.25rem;
    font-size: 0.78rem;
    color: #ff8a9b;
}

/* ── เอกสาร ── */
.upload-note {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.45);
    margin-bottom: 0.85rem;
}

.doc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.doc-label {
    display: block;
    font-size: 0.85rem;
    color: #fff;
    font-weight: 500;
}

.doc-hint {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.45);
    margin: 0.15rem 0 0.5rem;
    line-height: 1.4;
}

.doc-drop {
    display: block;
    position: relative;
    aspect-ratio: 16 / 10;
    border-radius: 12px;
    border: 1px dashed rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.02);
    cursor: pointer;
    overflow: hidden;
    transition: border-color 0.15s;
}

.doc-drop:hover {
    border-color: rgba(59, 130, 246, 0.5);
}

.doc-drop--filled {
    border-style: solid;
    border-color: rgba(0, 200, 83, 0.4);
}

.doc-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.doc-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.doc-placeholder {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    font-size: 0.82rem;
    color: rgba(255, 255, 255, 0.4);
}

/* ── ความยินยอม ── */
.consent-box {
    display: flex;
    gap: 0.6rem;
    align-items: flex-start;
    margin-top: 1.5rem;
    padding: 0.9rem;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.75);
    line-height: 1.6;
    cursor: pointer;
}

.consent-box input {
    margin-top: 0.2rem;
    flex-shrink: 0;
}

.consent-version {
    display: block;
    margin-top: 0.35rem;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.4);
    font-style: normal;
}

.missing-note {
    margin-top: 0.85rem;
    font-size: 0.82rem;
    color: #fbbf24;
}

.btn-submit {
    margin-top: 1rem;
    width: 100%;
}

.btn-primary {
    padding: 0.75rem 1.25rem;
    border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
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

.btn-sub {
    font-size: 0.74rem;
    color: rgba(255, 255, 255, 0.45);
}

.btn-danger-ghost {
    padding: 0.65rem 1rem;
    border-radius: 10px;
    background: rgba(255, 23, 68, 0.1);
    border: 1px solid rgba(255, 23, 68, 0.3);
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

.history-status.status-approved { background: rgba(0, 200, 83, 0.15); color: #6ee7a8; }
.history-status.status-rejected { background: rgba(255, 23, 68, 0.15); color: #ff8a9b; }
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
