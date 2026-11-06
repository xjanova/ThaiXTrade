<script setup>
/**
 * TPIX TRADE — หน้าตรวจใบลงทะเบียนผู้สร้างเหรียญ (หลังบ้าน)
 *
 * คนตรวจเห็นในจอเดียว: ข้อมูลที่กรอก · ผล KYC จาก Thaiprompt · ผลตรวจลายเซ็นซ้ำ (เซิร์ฟเวอร์ ecrecover ใหม่)
 * แล้วตัดสิน — อนุมัติต้องกำหนดส่วนเริ่มโครงการ (≤ 20%) · ปฏิเสธต้องมีเหตุผล (ผู้สร้างเห็น)
 * ปุ่มอนุมัติปิดเมื่อ KYC ไม่ผ่านหรือหลักฐานไม่ผ่าน — เซิร์ฟเวอร์กันซ้ำอีกชั้น
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { formatBps } from '@/utils/creatorRegistration';

const { t, locale } = useTranslation();

const props = defineProps({
    registration: { type: Object, required: true },
    user: { type: Object, default: null },
    kyc: { type: Object, default: () => ({ approved: false, level: null }) },
    evidence: { type: Object, default: () => ({}) },
    defaultStarterBps: { type: Number, default: 1000 },
    maxStarterBps: { type: Number, default: 2000 },
    canDecide: { type: Boolean, default: false },
});

const flash = computed(() => usePage().props.flash || {});
const pageErrors = computed(() => usePage().props.errors || {});

const r = computed(() => props.registration);
const form = computed(() => props.registration.form || {});
const pending = computed(() => r.value.status === 'submitted');

const evidenceOk = computed(() => !!(props.evidence.agreement_signature_valid
    && props.evidence.agreement_hash_matches
    && props.evidence.form_hash_matches));
const canApprove = computed(() => props.canDecide && pending.value && props.kyc.approved && evidenceOk.value);

const approveForm = useForm({ starter_pct: props.defaultStarterBps / 100 });
const rejectForm = useForm({ reason: '' });

function approve() {
    if (!canApprove.value || approveForm.processing) return;
    const pct = Number(approveForm.starter_pct);
    if (!window.confirm(t('admin.creatorRegistrations.show.confirmApprove', { project: r.value.project_name, pct }))) return;
    approveForm
        .transform(() => ({ starter_allocation_bps: Math.round(pct * 100) }))
        .post(`/admin/creator-registrations/${r.value.uuid}/approve`, { preserveScroll: true });
}

function reject() {
    if (!props.canDecide || !pending.value || rejectForm.processing) return;
    if (!window.confirm(t('admin.creatorRegistrations.show.confirmReject', { project: r.value.project_name }))) return;
    rejectForm.post(`/admin/creator-registrations/${r.value.uuid}/reject`, { preserveScroll: true });
}

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-GB') : '—');

const isCompany = computed(() => form.value.applicant_type === 'company');
const applicantFields = computed(() => (isCompany.value
    ? ['company_name', 'company_name_en', 'registration_number', 'registration_country', 'incorporation_date']
    : ['full_name', 'full_name_en', 'date_of_birth', 'nationality', 'occupation'])
    .concat(['signatory_name', 'signatory_position']));
const contactFields = ['email', 'phone', 'address', 'country', 'telegram', 'line_id'];
const projectFields = ['project_name', 'token_name', 'token_symbol', 'planned_supply', 'website', 'whitepaper_url',
    'twitter', 'telegram_group', 'facebook', 'github', 'description', 'team_description', 'use_of_funds', 'prior_projects'];

const valueOf = (f) => {
    const v = form.value[f];
    if (v === null || v === undefined || v === '') return '—';
    if (f === 'category') return t(`page.tokenCreator.categories.${v}`);
    if (f === 'source_of_funds') return t(`page.tokenCreator.fundSources.${v}`);
    return String(v);
};

const evidenceRows = computed(() => [
    ['ownershipSig', props.evidence.ownership_signature_valid],
    ['agreementSig', props.evidence.agreement_signature_valid],
    ['agreementHash', props.evidence.agreement_hash_matches],
    ['formHash', props.evidence.form_hash_matches],
]);
</script>

<template>
    <Head :title="`${t('admin.creatorRegistrations.headTitle')} — ${r.project_name || r.uuid}`" />
    <AdminLayout>
        <div class="space-y-6">
            <Link href="/admin/creator-registrations" class="text-sm text-primary-400 hover:underline">← {{ t('admin.creatorRegistrations.show.back') }}</Link>

            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ r.project_name || '—' }} <span class="text-base text-gray-400">{{ r.token_symbol }}</span></h1>
                    <p class="font-mono text-xs text-gray-400 break-all">{{ r.wallet_address }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded px-2 py-1 text-xs bg-white/10 text-white" data-test="status">{{ t(`admin.creatorRegistrations.status.${r.status}`) }}</span>
                    <a :href="`/admin/creator-registrations/${r.uuid}/pdf`" class="btn-primary text-sm" data-test="pdf">{{ t('admin.creatorRegistrations.show.pdf') }}</a>
                </div>
            </header>

            <div v-if="flash.success" class="rounded-lg border border-trading-green/40 bg-trading-green/10 px-3 py-2 text-sm text-trading-green">{{ flash.success }}</div>
            <div v-for="(msg, key) in pageErrors" :key="key" class="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" data-test="error">{{ msg }}</div>

            <div class="grid lg:grid-cols-2 gap-6">
                <!-- KYC + บัญชี -->
                <section class="glass-card p-5 space-y-2 text-sm">
                    <h2 class="text-lg font-semibold text-white">{{ t('admin.creatorRegistrations.show.kyc') }}</h2>
                    <p v-if="kyc.approved" class="text-trading-green" data-test="kyc-ok">✓ {{ t('admin.creatorRegistrations.show.kycApproved', { level: kyc.level }) }}</p>
                    <p v-else class="text-trading-red" data-test="kyc-missing">✗ {{ t('admin.creatorRegistrations.show.kycMissing') }}</p>
                    <p v-if="user" class="text-gray-400">{{ t('admin.creatorRegistrations.show.account') }}: #{{ user.id }} {{ user.name }} {{ user.email }}</p>
                </section>

                <!-- หลักฐาน -->
                <section class="glass-card p-5 space-y-2 text-sm">
                    <h2 class="text-lg font-semibold text-white">{{ t('admin.creatorRegistrations.show.evidence') }}</h2>
                    <div v-for="[key, ok] in evidenceRows" :key="key" class="flex justify-between">
                        <span class="text-gray-400">{{ t(`admin.creatorRegistrations.show.${key}`) }}</span>
                        <span :class="ok ? 'text-trading-green' : 'text-trading-red'" :data-test="`evidence-${key}`">
                            {{ ok ? t('admin.creatorRegistrations.show.valid') : t('admin.creatorRegistrations.show.invalid') }}
                        </span>
                    </div>
                    <p class="text-gray-400">{{ t('admin.creatorRegistrations.show.signedAt') }}: {{ fmtDate(r.agreement_signed_at) }} · {{ t('admin.creatorRegistrations.show.ip') }} {{ r.agreement_ip || '—' }}</p>
                </section>
            </div>

            <!-- ข้อมูลที่กรอก -->
            <section class="glass-card p-5 grid md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h3 class="font-semibold text-white mb-2">{{ t('page.tokenCreator.form.applicant') }} · {{ form.applicant_type ? t(`admin.creatorRegistrations.type.${form.applicant_type}`) : '—' }}</h3>
                    <dl class="space-y-1">
                        <div v-for="f in applicantFields" :key="f"><dt class="text-gray-400 inline">{{ t(`page.tokenCreator.fields.${f}`) }}:</dt> <dd class="text-white inline break-words">{{ valueOf(f) }}</dd></div>
                    </dl>
                    <h3 class="font-semibold text-white mt-4 mb-2">{{ t('page.tokenCreator.form.contact') }}</h3>
                    <dl class="space-y-1">
                        <div v-for="f in contactFields" :key="f"><dt class="text-gray-400 inline">{{ t(`page.tokenCreator.fields.${f}`) }}:</dt> <dd class="text-white inline break-words">{{ valueOf(f) }}</dd></div>
                    </dl>
                    <h3 class="font-semibold text-white mt-4 mb-2">{{ t('page.tokenCreator.form.declarations') }}</h3>
                    <p class="text-gray-300">{{ t('admin.creatorRegistrations.show.pep') }}: {{ form.is_pep ? `${t('admin.creatorRegistrations.show.yes')} — ${form.pep_details || ''}` : t('admin.creatorRegistrations.show.no') }}</p>
                    <p class="text-gray-300">{{ t('page.tokenCreator.fields.source_of_funds') }}: {{ valueOf('source_of_funds') }} {{ form.source_of_funds_note ? `— ${form.source_of_funds_note}` : '' }}</p>
                </div>
                <div>
                    <h3 class="font-semibold text-white mb-2">{{ t('page.tokenCreator.form.project') }} · {{ valueOf('category') }}</h3>
                    <dl class="space-y-2">
                        <div v-for="f in projectFields" :key="f">
                            <dt class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</dt>
                            <dd class="text-white whitespace-pre-line break-words">{{ valueOf(f) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- ข้อความที่เซ็น -->
            <section v-if="r.agreement_message" class="glass-card p-5 space-y-2 text-sm">
                <h3 class="font-semibold text-white">{{ t('admin.creatorRegistrations.show.signedMessage') }}</h3>
                <pre class="whitespace-pre-wrap break-all rounded bg-black/30 p-3 text-xs text-gray-300">{{ r.agreement_message }}</pre>
                <h3 class="font-semibold text-white">{{ t('admin.creatorRegistrations.show.signature') }}</h3>
                <pre class="whitespace-pre-wrap break-all rounded bg-black/30 p-3 text-xs text-gray-300">{{ r.agreement_signature }}</pre>
                <p class="text-xs text-gray-500">{{ t('admin.creatorRegistrations.show.userAgent') }}: {{ r.agreement_user_agent || '—' }}</p>
            </section>

            <!-- ตัดสิน -->
            <section class="glass-card p-5 space-y-4" data-test="decision">
                <h2 class="text-lg font-semibold text-white">{{ t('admin.creatorRegistrations.show.decision') }}</h2>
                <p v-if="r.reviewed_at" class="text-sm text-gray-400">
                    {{ t('admin.creatorRegistrations.show.reviewed', { by: r.reviewer || '—', at: fmtDate(r.reviewed_at) }) }}
                    <span v-if="r.starter_allocation_bps != null"> · {{ formatBps(r.starter_allocation_bps) }}%</span>
                </p>
                <p v-if="r.rejection_reason" class="text-sm text-trading-red whitespace-pre-line">{{ r.rejection_reason }}</p>

                <p v-if="pending && !canDecide" class="text-sm text-gray-500" data-test="super-only">{{ t('admin.creatorRegistrations.show.superOnly') }}</p>
                <template v-if="pending && canDecide">
                    <div class="grid md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm">
                                <span class="text-gray-400">{{ t('admin.creatorRegistrations.show.starterLabel') }}</span>
                                <input v-model="approveForm.starter_pct" type="number" min="0" :max="maxStarterBps / 100" step="0.5"
                                       class="trading-input w-full" data-test="starter-input">
                                <span class="text-xs text-gray-500">{{ t('admin.creatorRegistrations.show.starterHelp', { pct: formatBps(defaultStarterBps), max: formatBps(maxStarterBps) }) }}</span>
                            </label>
                            <button type="button" class="btn-success" :disabled="!canApprove || approveForm.processing" data-test="approve" @click="approve">
                                {{ t('admin.creatorRegistrations.show.approve') }}
                            </button>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm">
                                <span class="text-gray-400">{{ t('admin.creatorRegistrations.show.reasonLabel') }}</span>
                                <textarea v-model="rejectForm.reason" rows="3" class="trading-input w-full" data-test="reason-input" />
                            </label>
                            <button type="button" class="btn-danger" :disabled="rejectForm.reason.trim().length < 5 || rejectForm.processing" data-test="reject" @click="reject">
                                {{ t('admin.creatorRegistrations.show.reject') }}
                            </button>
                        </div>
                    </div>
                </template>
                <p v-else class="text-sm text-gray-500">{{ t('admin.creatorRegistrations.show.notPending') }}</p>
            </section>
        </div>
    </AdminLayout>
</template>
