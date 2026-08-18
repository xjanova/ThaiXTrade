<script setup>
/**
 * TPIX TRADE — คำขอลบข้อมูลตาม PDPA (หลังบ้าน)
 *
 * เจ้าของข้อมูลมีสิทธิขอให้ลบ และเราต้องทำจริงในเวลาที่สมเหตุสมผล
 * ปฏิเสธได้เฉพาะเมื่อมีฐานทางกฎหมายให้เก็บต่อ — ไม่ใช่เพราะไม่อยากลบ
 * จึงบังคับกรอกเหตุผลตอนปฏิเสธ และเหตุผลนั้นส่งกลับให้เจ้าของข้อมูลเห็น
 *
 * ⚠️ กด "ลบข้อมูล" แล้วไฟล์หายจากดิสก์จริง เอากลับมาไม่ได้
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    requests: { type: Object, default: () => ({ data: [] }) },
});

const flash = computed(() => usePage().props.flash || {});

const completeForm = useForm({ note: '' });
const rejectForm = useForm({ note: '' });

const rejectingId = ref(null);

const complete = (req) => {
    const label = req.user.email || req.user.wallet_address || `#${req.user.id}`;

    if (!confirm(`ลบข้อมูลยืนยันตัวตนของ ${label} ทั้งหมด?\n\nเอกสารจะถูกลบออกจากดิสก์จริง และข้อมูลส่วนตัวในใบคำขอจะถูกล้าง เอากลับมาไม่ได้`)) {
        return;
    }

    completeForm.post(`/admin/kyc/deletions/${req.id}/complete`, {
        preserveScroll: true,
        onSuccess: () => completeForm.reset(),
    });
};

const reject = (req) => {
    if (!rejectForm.note.trim()) return;

    rejectForm.post(`/admin/kyc/deletions/${req.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejectForm.reset();
            rejectingId.value = null;
        },
    });
};

const STATUS_LABEL = {
    pending: 'รอดำเนินการ',
    completed: 'ลบแล้ว',
    rejected: 'ปฏิเสธ',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
</script>

<template>
    <Head title="คำขอลบข้อมูล — หลังบ้าน" />

    <AdminLayout>
        <div class="deletions-page">
            <header class="page-head">
                <div>
                    <Link href="/admin/kyc" class="back-link">← กลับคิวตรวจ</Link>
                    <h1>คำขอลบข้อมูล (PDPA)</h1>
                </div>
            </header>

            <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>

            <p class="intro">
                ผู้ใช้มีสิทธิขอให้ลบข้อมูลยืนยันตัวตนของตัวเอง
                ปฏิเสธได้เฉพาะเมื่อมีกฎหมายอื่นกำหนดให้เราต้องเก็บต่อ
                และต้องบันทึกเหตุผลไว้เสมอ
            </p>

            <section class="card">
                <table v-if="requests.data.length" class="data-table">
                    <thead>
                        <tr>
                            <th>ผู้ขอ</th>
                            <th>เหตุผลของผู้ขอ</th>
                            <th>ยื่นเมื่อ</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="req in requests.data" :key="req.id">
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <span>{{ req.user.email || req.user.name || '—' }}</span>
                                        <code>{{ req.user.wallet_address || '' }}</code>
                                    </div>
                                </td>
                                <td class="reason-cell">{{ req.reason || '—' }}</td>
                                <td class="col-date">{{ formatDate(req.requested_at) }}</td>
                                <td>
                                    <span class="badge" :class="`badge-${req.status}`">
                                        {{ STATUS_LABEL[req.status] }}
                                    </span>
                                    <span v-if="req.status === 'completed'" class="files-count">
                                        {{ req.files_deleted }} ไฟล์
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <template v-if="req.status === 'pending'">
                                        <button class="btn-delete" @click="complete(req)">ลบข้อมูล</button>
                                        <button class="btn-ghost-sm" @click="rejectingId = rejectingId === req.id ? null : req.id">
                                            ปฏิเสธ
                                        </button>
                                    </template>
                                    <span v-else class="handled-by">
                                        {{ req.handler || '—' }}<br />
                                        <small>{{ formatDate(req.handled_at) }}</small>
                                    </span>
                                </td>
                            </tr>

                            <tr v-if="req.handler_note" class="note-row">
                                <td colspan="5">
                                    <strong>บันทึก:</strong> {{ req.handler_note }}
                                </td>
                            </tr>

                            <tr v-if="rejectingId === req.id" class="reject-row">
                                <td colspan="5">
                                    <label>เหตุผลที่ปฏิเสธ (ผู้ใช้จะเห็นข้อความนี้) <span class="req">*</span></label>
                                    <textarea
                                        v-model="rejectForm.note"
                                        rows="2"
                                        class="input"
                                        placeholder="เช่น ต้องเก็บไว้ตามกฎหมายป้องกันการฟอกเงินอีก 2 ปี"
                                    ></textarea>
                                    <div class="reject-buttons">
                                        <button
                                            class="btn-reject"
                                            :disabled="!rejectForm.note.trim() || rejectForm.processing"
                                            @click="reject(req)"
                                        >
                                            ยืนยันปฏิเสธ
                                        </button>
                                        <button class="btn-ghost-sm" @click="rejectingId = null">ยกเลิก</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p v-else class="empty-note">ยังไม่มีคำขอลบข้อมูล</p>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.deletions-page {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.back-link {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.85rem;
    text-decoration: none;
}

.page-head h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #fff;
    margin-top: 0.35rem;
}

.intro {
    font-size: 0.87rem;
    color: rgba(255, 255, 255, 0.55);
    line-height: 1.6;
    max-width: 680px;
}

.card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 1.25rem;
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

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.87rem;
}

.data-table th {
    text-align: left;
    padding: 0.5rem 0.6rem;
    color: rgba(255, 255, 255, 0.45);
    font-weight: 500;
    font-size: 0.78rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.data-table td {
    padding: 0.7rem 0.6rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.85);
    vertical-align: top;
}

.user-cell {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.user-cell code {
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.35);
    word-break: break-all;
}

.reason-cell {
    max-width: 260px;
    color: rgba(255, 255, 255, 0.65);
    font-size: 0.83rem;
}

.col-date {
    white-space: nowrap;
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.8rem;
}

.badge {
    display: inline-block;
    padding: 0.15rem 0.55rem;
    border-radius: 6px;
    font-size: 0.74rem;
}

.badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
.badge-completed { background: rgba(0, 200, 83, 0.15); color: #6ee7a8; }
.badge-rejected { background: rgba(255, 23, 68, 0.15); color: #ff8a9b; }

.files-count {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.4);
}

.actions-cell {
    display: flex;
    gap: 0.4rem;
    align-items: flex-start;
    white-space: nowrap;
}

.handled-by {
    font-size: 0.78rem;
    color: rgba(255, 255, 255, 0.5);
}

.note-row td {
    padding-top: 0;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.5);
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.reject-row td {
    background: rgba(255, 23, 68, 0.04);
}

.reject-row label {
    display: block;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.65);
    margin-bottom: 0.35rem;
}

.req {
    color: #ff6b81;
}

.input {
    width: 100%;
    max-width: 560px;
    padding: 0.55rem 0.7rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #fff;
    font-size: 0.86rem;
}

.reject-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.6rem;
}

.btn-delete {
    padding: 0.4rem 0.85rem;
    border-radius: 8px;
    background: rgba(255, 23, 68, 0.85);
    color: #fff;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
}

.btn-reject {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    background: rgba(255, 23, 68, 0.85);
    color: #fff;
    font-size: 0.83rem;
    border: none;
    cursor: pointer;
}

.btn-reject:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-ghost-sm {
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.8rem;
    cursor: pointer;
}

.empty-note {
    padding: 2rem 0;
    text-align: center;
    color: rgba(255, 255, 255, 0.35);
    font-size: 0.88rem;
}
</style>
