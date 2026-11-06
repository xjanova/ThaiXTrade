<script setup>
/**
 * TPIX TRADE — ลงทะเบียนผู้สร้างเหรียญ
 *
 *   1. เข้าด้วยกระเป๋า — เซ็นข้อความยืนยันความเป็นเจ้าของ (personal_sign, nonce ใช้ครั้งเดียว)
 *      กระเป๋านี้คือกระเป๋ารับเหรียญส่วนเริ่มโครงการ
 *   2. กรอกข้อมูลผู้สร้าง/โครงการ (เซิร์ฟเวอร์ตรวจ ข้อความ error ตามภาษาที่เลือก) + ยินยอมเผยแพร่ทีละรายการ
 *      แนบเอกสาร — นิติบุคคลต้องมีหนังสือรับรองบริษัท บุคคลธรรมดาไม่ต้อง · เลือกเผยแพร่ทีละไฟล์ (บัตรไม่เผยแพร่)
 *   3. ยืนยันตัวตนในแอป Thaiprompt (เจ้าของสั่ง: "การตรวจ kyc ให้ส่งไปตรวจที่แอพ thaiprompt ต้องโหลดแอพ thaiprompt ด้วย")
 *      โหลดแอป → ทำ eKYC ในแอป → เชื่อมบัญชี Thaiprompt กับเว็บนี้ (OAuth เดิมของ /kyc) แล้วกลับมาหน้านี้
 *   4. อ่านข้อตกลง (ไทยเป็นหลัก มีอังกฤษกำกับ) แล้วเซ็นด้วยกระเป๋า → ส่งตรวจ
 *   5. รอแอดมินอนุมัติ — ดาวน์โหลด PDF ข้อตกลงที่เซ็นแล้วได้
 *
 * ⚠️ ไม่มีช่องกรอก private key ที่ไหนทั้งสิ้น — ทุกขั้นเซ็นในกระเป๋าของผู้สร้าง
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, reactive, onMounted, onUnmounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CreatorClaimGuide from '@/Components/TokenCreator/CreatorClaimGuide.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { isMobile } from '@/utils/mobileWallet';
import {
    COUNTRIES,
    blankCreatorForm,
    creatorStep,
    describeCreatorError,
    documentPublishable,
    documentTypesFor,
    formatBps,
    signWithWallet,
} from '@/utils/creatorRegistration';

const { t, locale } = useTranslation();

const props = defineProps({
    registration: { type: Object, default: null },
    kyc: { type: Object, default: () => ({ approved: false, level: null }) },
    agreement: { type: Object, required: true },
    defaultStarterBps: { type: Number, default: 1000 },
    options: { type: Object, default: () => ({ categories: [], fund_sources: [], document_types: {} }) },
    // ลิงก์เชิญให้เหรียญที่ขึ้นเชนแล้ว (หรือใบที่ผูกเหรียญไว้แล้ว) — null = ลงทะเบียนปกติ
    claim: { type: Object, default: null },
});

const claimError = ref('');
const claimInfo = computed(() => (props.claim ? { ...props.claim, error: claimError.value || props.claim.error } : null));
// ชื่อ/สัญลักษณ์มาจากเหรียญจริง — เซิร์ฟเวอร์บังคับทับอีกชั้น
const lockedTokenFields = ['token_name', 'token_symbol', 'planned_supply'];

const walletStore = useWalletStore();

const registration = ref(props.registration);
watch(() => props.registration, (r) => { registration.value = r; });

const kycApproved = computed(() => !!props.kyc?.approved);

const form = reactive(blankCreatorForm(props.registration?.form));
if (props.claim) {
    form.token_name = props.claim.name;
    form.token_symbol = String(props.claim.symbol || '').toUpperCase();
    form.planned_supply = null;
    if (!form.project_name) form.project_name = props.claim.name;
}
const errors = ref({});
const notice = ref('');
const errorKey = ref('');
const busy = ref('');          // '' | challenge | sign | submit | save
const editing = ref(!props.registration?.form_hash);
const showEnglish = ref(true);
const ack = reactive({ read: false, thai: false, risk: false });

const step = computed(() => creatorStep(registration.value, editing.value));

const stepList = ['wallet', 'form', 'kyc', 'agreement', 'review'];
const stepIndex = computed(() => {
    if (step.value === 'status') return 4;
    if (step.value === 'review') return kycApproved.value ? 3 : 2;
    return stepList.indexOf(step.value);
});

const connected = computed(() => (walletStore.address || '').toLowerCase());
const walletMismatch = computed(() => !!registration.value
    && !!connected.value
    && connected.value !== registration.value.wallet_address);

const starterPct = computed(() => formatBps(registration.value?.starter_allocation_bps ?? props.defaultStarterBps));
const missingDocs = computed(() => registration.value?.missing_documents || []);
const canSign = computed(() => !busy.value && kycApproved.value && !!registration.value?.form_hash
    && missingDocs.value.length === 0
    && ack.read && ack.thai && ack.risk && !walletMismatch.value);

const errorText = computed(() => (errorKey.value ? (errorKey.value.startsWith('page.') ? t(errorKey.value) : errorKey.value) : ''));

const busyLabel = computed(() => (busy.value ? t(`page.tokenCreator.stage.${busy.value}`) : ''));

function resetMessages() {
    errorKey.value = '';
    notice.value = '';
}

async function startRegistration() {
    if (busy.value) return;
    resetMessages();
    const wallet = connected.value;
    try {
        const signature = await signWithWallet(walletStore, wallet, async () => {
            busy.value = 'challenge';
            const res = await axios.post('/token-creator/challenge', { wallet_address: wallet });
            busy.value = 'sign';
            return res.data.data;
        });

        busy.value = 'submit';
        const res = await axios.post('/token-creator/start', {
            wallet_address: wallet,
            nonce: signature.nonce,
            signature: signature.signature,
            claim_token_id: props.claim?.token_id ?? null,
        });
        registration.value = res.data.data.registration;
        claimError.value = res.data.data.claim_error || '';
        editing.value = !registration.value?.form_hash;
        // เข้าสู่ระบบแล้ว — ดึง props ของหน้า (auth, kyc, ใบลงทะเบียน) ใหม่ทั้งชุด
        router.reload({ preserveScroll: true });
    } catch (err) {
        errorKey.value = describeCreatorError(err);
    } finally {
        busy.value = '';
    }
}

async function saveForm() {
    if (busy.value) return;
    resetMessages();
    errors.value = {};
    busy.value = 'save';
    try {
        const res = await axios.put('/token-creator/form', { ...form });
        registration.value = res.data.data.registration;
        editing.value = false;
        ack.read = ack.thai = ack.risk = false;
        notice.value = t('page.tokenCreator.form.saved');
    } catch (err) {
        const fieldErrors = err?.response?.status === 422 ? err.response.data?.errors : null;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
            errorKey.value = 'page.tokenCreator.form.checkFields';
        } else {
            errorKey.value = describeCreatorError(err);
        }
    } finally {
        busy.value = '';
    }
}

async function signAgreement() {
    if (!canSign.value) return;
    resetMessages();
    const wallet = registration.value.wallet_address;
    try {
        const signed = await signWithWallet(walletStore, wallet, async () => {
            busy.value = 'challenge';
            const res = await axios.post('/token-creator/agreement/challenge');
            busy.value = 'sign';
            return res.data.data;
        });

        busy.value = 'submit';
        const res = await axios.post('/token-creator/submit', { nonce: signed.nonce, signature: signed.signature });
        registration.value = res.data.data.registration;
    } catch (err) {
        errorKey.value = describeCreatorError(err);
    } finally {
        busy.value = '';
    }
}

// ─── เอกสารประกอบ ──────────────────────────────────────────────────────────

const docTypes = computed(() => props.options?.document_types || {});
const allowedDocTypes = computed(() => documentTypesFor(registration.value?.form?.applicant_type, docTypes.value));
const documents = computed(() => registration.value?.documents || []);
const docEditable = computed(() => !!registration.value?.form_hash && ['draft', 'rejected'].includes(registration.value?.status));
const upload = reactive({ type: '', publish: false, file: null });
const fileInput = ref(null);
const docBusy = ref('');       // '' | upload | <uuid>
const docError = ref('');

watch(allowedDocTypes, (types) => {
    if (!types.includes(upload.type)) upload.type = types[0] || '';
}, { immediate: true });

const uploadPublishable = computed(() => !!docTypes.value[upload.type]?.public
    && !!upload.file && String(upload.file.type || '').startsWith('image/'));

function pickFile(e) {
    upload.file = e.target.files?.[0] || null;
    if (!uploadPublishable.value) upload.publish = false;
}

function docErrorText(err) {
    const key = describeCreatorError(err);
    return key.startsWith('page.') ? t(key) : key;
}

async function uploadDocument() {
    if (docBusy.value || !upload.file || !upload.type) return;
    docError.value = '';
    docBusy.value = 'upload';
    try {
        const body = new FormData();
        body.append('type', upload.type);
        body.append('file', upload.file);
        body.append('publish', uploadPublishable.value && upload.publish ? '1' : '0');
        const res = await axios.post('/token-creator/documents', body);
        registration.value = res.data.data.registration;
        upload.file = null;
        upload.publish = false;
        if (fileInput.value) fileInput.value.value = '';
        ack.read = ack.thai = ack.risk = false;
    } catch (err) {
        docError.value = docErrorText(err);
    } finally {
        docBusy.value = '';
    }
}

async function togglePublish(doc) {
    if (docBusy.value) return;
    docError.value = '';
    docBusy.value = doc.uuid;
    try {
        const res = await axios.patch(`/token-creator/documents/${doc.uuid}`, { publish: !doc.publish });
        registration.value = res.data.data.registration;
        ack.read = ack.thai = ack.risk = false;
    } catch (err) {
        docError.value = docErrorText(err);
    } finally {
        docBusy.value = '';
    }
}

async function deleteDocument(doc) {
    if (docBusy.value) return;
    if (!window.confirm(t('page.tokenCreator.docs.confirmDelete'))) return;
    docError.value = '';
    docBusy.value = doc.uuid;
    try {
        const res = await axios.delete(`/token-creator/documents/${doc.uuid}`);
        registration.value = res.data.data.registration;
        ack.read = ack.thai = ack.risk = false;
    } catch (err) {
        docError.value = docErrorText(err);
    } finally {
        docBusy.value = '';
    }
}

const fmtSize = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

function editAgain() {
    Object.assign(form, blankCreatorForm(registration.value?.form));
    editing.value = true;
    resetMessages();
}

// ─── KYC ในแอป Thaiprompt ────────────────────────────────────────────────

const page = usePage();
const thaiprompt = computed(() => props.kyc?.thaiprompt || {});
const tpLinked = computed(() => !!thaiprompt.value.link && !thaiprompt.value.link.needs_reconnect);
const kycServerError = computed(() => page.props.errors?.kyc || '');
const kycFlash = computed(() => page.props.flash?.success || '');
const mobile = isMobile();
const downloadQr = ref(null);
const tpConsent = ref(false);
const tpBusy = ref(false);
const tpError = ref('');

/** QR ต้องเป็นลิงก์เต็ม — มือถือที่สแกนไม่รู้จักโดเมนเรา */
const downloadUrl = computed(() => {
    const url = thaiprompt.value.download_url || '';
    try {
        return url ? new URL(url, window.location.origin).toString() : '';
    } catch {
        return url;
    }
});

/** เชื่อมบัญชี Thaiprompt (หน้าอนุญาตของ Thaiprompt) แล้วกลับมาหน้านี้ — เซิร์ฟเวอร์รับแค่ชื่อในรายการ ไม่รับ URL */
function connectThaiprompt() {
    if (!tpConsent.value || tpBusy.value) return;
    tpBusy.value = true;
    tpError.value = '';
    router.post('/kyc/thaiprompt/connect', { consent: true, return: 'token-creator' }, {
        preserveScroll: true,
        onFinish: () => { tpBusy.value = false; },
    });
}

/** ทำ eKYC ในแอปเสร็จแล้ว — ถาม Thaiprompt ใหม่ (ถ้าเชื่อมแล้ว) แล้วดึงสถานะจากเซิร์ฟเวอร์ */
async function refreshKyc() {
    if (tpBusy.value) return;
    tpBusy.value = true;
    tpError.value = '';
    if (thaiprompt.value.link) {
        try {
            await axios.post('/kyc/thaiprompt/refresh');
        } catch (err) {
            const key = describeCreatorError(err);
            tpError.value = err?.response?.data?.message || (key.startsWith('page.') ? t(key) : key);
        }
    }
    router.reload({
        only: ['kyc', 'registration'],
        preserveScroll: true,
        onFinish: () => { tpBusy.value = false; },
    });
}

// กลับจากแอป Thaiprompt (แท็บกลับมาเห็น) — ถามผลให้เลย ไม่ต้องให้กดเอง
let leftAt = 0;
function onVisibility() {
    if (document.hidden) {
        leftAt = Date.now();
    } else if (leftAt && Date.now() - leftAt > 5000 && registration.value && !kycApproved.value && thaiprompt.value.link) {
        leftAt = 0;
        refreshKyc();
    }
}

onMounted(async () => {
    document.addEventListener('visibilitychange', onVisibility);
    if (!mobile && !kycApproved.value && downloadUrl.value) {
        downloadQr.value = await QRCode.toDataURL(downloadUrl.value, { width: 160, margin: 1 }).catch(() => null);
    }
});

onUnmounted(() => document.removeEventListener('visibilitychange', onVisibility));

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-GB') : '');

const fieldCls = (name) => [
    'trading-input w-full',
    errors.value[name] ? 'border-red-500/60' : '',
];

const isCompany = computed(() => form.applicant_type === 'company');
</script>

<template>
    <Head :title="t('page.tokenCreator.headTitle')" />
    <AppLayout>
        <div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
            <header class="space-y-2">
                <h1 class="text-2xl md:text-3xl font-bold text-white">{{ t('page.tokenCreator.title') }}</h1>
                <p class="text-gray-400">{{ t('page.tokenCreator.subtitle') }}</p>
                <p class="text-sm text-amber-300 bg-amber-500/10 border border-amber-500/30 rounded-lg px-3 py-2" data-test="no-key-warning">
                    {{ t('page.tokenCreator.noKeyWarning') }}
                </p>
            </header>

            <!-- เหรียญที่ขึ้นเชนแล้ว (ลิงก์เชิญ) -->
            <CreatorClaimGuide v-if="claimInfo" :claim="claimInfo"
                               :project-wallet="registration?.wallet_address || connected"
                               :registered="!!registration && registration.factory_token_id === claimInfo.token_id" />

            <!-- ขั้นตอน -->
            <ol class="grid grid-cols-5 gap-2 text-xs" data-test="steps">
                <li v-for="(s, i) in stepList" :key="s"
                    class="rounded-lg px-2 py-2 text-center border"
                    :class="i < stepIndex ? 'border-trading-green/40 text-trading-green bg-trading-green/10'
                        : i === stepIndex ? 'border-primary-500/60 text-white bg-primary-500/15'
                        : 'border-white/10 text-gray-500'">
                    {{ i + 1 }}. {{ t(`page.tokenCreator.steps.${s}`) }}
                </li>
            </ol>

            <div v-if="errorText" class="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" data-test="error">{{ errorText }}</div>
            <div v-if="notice" class="rounded-lg border border-trading-green/40 bg-trading-green/10 px-3 py-2 text-sm text-trading-green" data-test="notice">{{ notice }}</div>

            <!-- 1. กระเป๋า -->
            <section class="glass-card p-5 space-y-3" data-test="step-wallet">
                <h2 class="text-lg font-semibold text-white">{{ claim ? t('page.tokenCreator.claim.walletTitle') : t('page.tokenCreator.wallet.title') }}</h2>
                <p class="text-sm text-gray-400">{{ claim ? t('page.tokenCreator.claim.walletDesc', { symbol: claim.symbol }) : t('page.tokenCreator.wallet.desc') }}</p>
                <div v-if="registration" class="text-sm">
                    <span class="text-gray-400">{{ t('page.tokenCreator.wallet.registered') }}:</span>
                    <code class="ml-2 text-white break-all" data-test="registered-wallet">{{ registration.wallet_address }}</code>
                </div>
                <p v-if="walletMismatch" class="text-sm text-amber-300" data-test="wallet-mismatch">
                    {{ t('page.tokenCreator.wallet.mismatch', { connected, registered: registration.wallet_address }) }}
                </p>
                <template v-if="!registration">
                    <button v-if="!connected" type="button" class="btn-primary" data-test="connect" @click="walletStore.openConnectModal()">
                        {{ t('wallet.connect') }}
                    </button>
                    <button v-else type="button" class="btn-primary" data-test="start" :disabled="!!busy" @click="startRegistration">
                        {{ busy ? busyLabel : t('page.tokenCreator.wallet.sign') }}
                    </button>
                </template>
            </section>

            <!-- 2. ข้อมูล -->
            <section v-if="registration" class="glass-card p-5 space-y-4" data-test="step-form">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-white">{{ t('page.tokenCreator.form.title') }}</h2>
                    <button v-if="!editing && step === 'review'" type="button" class="text-sm text-primary-400 hover:underline" data-test="edit" @click="editAgain">
                        {{ t('page.tokenCreator.form.edit') }}
                    </button>
                </div>

                <form v-if="editing" class="space-y-5" data-test="creator-form" @submit.prevent="saveForm">
                    <p v-if="registration.form_hash" class="text-xs text-amber-300">{{ t('page.tokenCreator.form.editWarning') }}</p>

                    <!-- ผู้สมัคร -->
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-gray-300 mb-1">{{ t('page.tokenCreator.form.applicant') }}</legend>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2 text-white">
                                <input v-model="form.applicant_type" type="radio" value="individual" data-test="type-individual"> {{ t('page.tokenCreator.form.individual') }}
                            </label>
                            <label class="flex items-center gap-2 text-white">
                                <input v-model="form.applicant_type" type="radio" value="company" data-test="type-company"> {{ t('page.tokenCreator.form.company') }}
                            </label>
                        </div>
                        <div class="grid md:grid-cols-2 gap-3">
                            <template v-if="!isCompany">
                                <label v-for="f in ['full_name', 'full_name_en', 'occupation']" :key="f" class="block text-sm">
                                    <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                    <input v-model="form[f]" :class="fieldCls(f)" :name="f" type="text">
                                    <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                                </label>
                                <label class="block text-sm">
                                    <span class="text-gray-400">{{ t('page.tokenCreator.fields.date_of_birth') }}</span>
                                    <input v-model="form.date_of_birth" :class="fieldCls('date_of_birth')" name="date_of_birth" type="date">
                                    <span v-if="errors.date_of_birth" class="text-xs text-red-400">{{ errors.date_of_birth }}</span>
                                </label>
                                <label class="block text-sm">
                                    <span class="text-gray-400">{{ t('page.tokenCreator.fields.nationality') }}</span>
                                    <select v-model="form.nationality" :class="fieldCls('nationality')" name="nationality">
                                        <option :value="null">{{ t('page.tokenCreator.form.selectCountry') }}</option>
                                        <option v-for="c in COUNTRIES" :key="c.code" :value="c.code">{{ locale === 'th' ? c.th : c.en }}</option>
                                    </select>
                                    <span v-if="errors.nationality" class="text-xs text-red-400">{{ errors.nationality }}</span>
                                </label>
                            </template>
                            <template v-else>
                                <label v-for="f in ['company_name', 'company_name_en', 'registration_number']" :key="f" class="block text-sm">
                                    <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                    <input v-model="form[f]" :class="fieldCls(f)" :name="f" type="text">
                                    <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                                </label>
                                <label class="block text-sm">
                                    <span class="text-gray-400">{{ t('page.tokenCreator.fields.registration_country') }}</span>
                                    <select v-model="form.registration_country" :class="fieldCls('registration_country')" name="registration_country">
                                        <option :value="null">{{ t('page.tokenCreator.form.selectCountry') }}</option>
                                        <option v-for="c in COUNTRIES" :key="c.code" :value="c.code">{{ locale === 'th' ? c.th : c.en }}</option>
                                    </select>
                                    <span v-if="errors.registration_country" class="text-xs text-red-400">{{ errors.registration_country }}</span>
                                </label>
                                <label class="block text-sm">
                                    <span class="text-gray-400">{{ t('page.tokenCreator.fields.incorporation_date') }}</span>
                                    <input v-model="form.incorporation_date" :class="fieldCls('incorporation_date')" name="incorporation_date" type="date">
                                    <span v-if="errors.incorporation_date" class="text-xs text-red-400">{{ errors.incorporation_date }}</span>
                                </label>
                            </template>
                            <label v-for="f in ['signatory_name', 'signatory_position']" :key="f" class="block text-sm">
                                <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                <input v-model="form[f]" :class="fieldCls(f)" :name="f" type="text">
                                <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- ผู้ติดต่อ -->
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-gray-300 mb-1">{{ t('page.tokenCreator.form.contact') }}</legend>
                        <div class="grid md:grid-cols-2 gap-3">
                            <label v-for="f in ['email', 'phone', 'telegram', 'line_id']" :key="f" class="block text-sm">
                                <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                <input v-model="form[f]" :class="fieldCls(f)" :name="f" :type="f === 'email' ? 'email' : (f === 'phone' ? 'tel' : 'text')">
                                <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                            </label>
                            <label class="block text-sm md:col-span-2">
                                <span class="text-gray-400">{{ t('page.tokenCreator.fields.address') }}</span>
                                <textarea v-model="form.address" :class="fieldCls('address')" name="address" rows="2" />
                                <span v-if="errors.address" class="text-xs text-red-400">{{ errors.address }}</span>
                            </label>
                            <label class="block text-sm">
                                <span class="text-gray-400">{{ t('page.tokenCreator.fields.country') }}</span>
                                <select v-model="form.country" :class="fieldCls('country')" name="country">
                                    <option :value="null">{{ t('page.tokenCreator.form.selectCountry') }}</option>
                                    <option v-for="c in COUNTRIES" :key="c.code" :value="c.code">{{ locale === 'th' ? c.th : c.en }}</option>
                                </select>
                                <span v-if="errors.country" class="text-xs text-red-400">{{ errors.country }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- โครงการ -->
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-gray-300 mb-1">{{ t('page.tokenCreator.form.project') }}</legend>
                        <div class="grid md:grid-cols-2 gap-3">
                            <label v-for="f in ['project_name', 'token_name', 'token_symbol', 'planned_supply']" :key="f" class="block text-sm">
                                <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                <input v-model="form[f]" :class="[fieldCls(f), claim && lockedTokenFields.includes(f) ? 'opacity-60 cursor-not-allowed' : '']" :name="f" type="text"
                                       :inputmode="f === 'planned_supply' ? 'numeric' : undefined"
                                       :disabled="!!claim && lockedTokenFields.includes(f)">
                                <span v-if="claim && lockedTokenFields.includes(f)" class="text-xs text-gray-500">🔒 {{ t('page.tokenCreator.claim.fromToken') }}</span>
                                <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                            </label>
                            <label class="block text-sm">
                                <span class="text-gray-400">{{ t('page.tokenCreator.fields.category') }}</span>
                                <select v-model="form.category" :class="fieldCls('category')" name="category">
                                    <option :value="null">{{ t('page.tokenCreator.form.selectOption') }}</option>
                                    <option v-for="c in options.categories" :key="c" :value="c">{{ t(`page.tokenCreator.categories.${c}`) }}</option>
                                </select>
                                <span v-if="errors.category" class="text-xs text-red-400">{{ errors.category }}</span>
                            </label>
                            <label v-for="f in ['website', 'whitepaper_url', 'twitter', 'telegram_group', 'facebook', 'github']" :key="f" class="block text-sm">
                                <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                <input v-model="form[f]" :class="fieldCls(f)" :name="f" type="url" placeholder="https://">
                                <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                            </label>
                            <label v-for="f in ['description', 'team_description', 'use_of_funds', 'prior_projects']" :key="f" class="block text-sm md:col-span-2">
                                <span class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</span>
                                <textarea v-model="form[f]" :class="fieldCls(f)" :name="f" rows="3" />
                                <span v-if="errors[f]" class="text-xs text-red-400">{{ errors[f] }}</span>
                            </label>
                            <label class="block text-sm">
                                <span class="text-gray-400">{{ t('page.tokenCreator.fields.source_of_funds') }}</span>
                                <select v-model="form.source_of_funds" :class="fieldCls('source_of_funds')" name="source_of_funds">
                                    <option :value="null">{{ t('page.tokenCreator.form.selectOption') }}</option>
                                    <option v-for="s in options.fund_sources" :key="s" :value="s">{{ t(`page.tokenCreator.fundSources.${s}`) }}</option>
                                </select>
                                <span v-if="errors.source_of_funds" class="text-xs text-red-400">{{ errors.source_of_funds }}</span>
                            </label>
                            <label v-if="form.source_of_funds === 'other'" class="block text-sm">
                                <span class="text-gray-400">{{ t('page.tokenCreator.fields.source_of_funds_note') }}</span>
                                <input v-model="form.source_of_funds_note" :class="fieldCls('source_of_funds_note')" name="source_of_funds_note" type="text">
                                <span v-if="errors.source_of_funds_note" class="text-xs text-red-400">{{ errors.source_of_funds_note }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- คำรับรอง -->
                    <fieldset class="space-y-2 text-sm">
                        <legend class="text-sm font-semibold text-gray-300 mb-1">{{ t('page.tokenCreator.form.declarations') }}</legend>
                        <label class="flex items-start gap-2 text-white">
                            <input v-model="form.is_pep" type="checkbox" class="mt-1" name="is_pep"> <span>{{ t('page.tokenCreator.form.pep') }}</span>
                        </label>
                        <label v-if="form.is_pep" class="block">
                            <span class="text-gray-400">{{ t('page.tokenCreator.fields.pep_details') }}</span>
                            <input v-model="form.pep_details" :class="fieldCls('pep_details')" name="pep_details" type="text">
                            <span v-if="errors.pep_details" class="text-xs text-red-400">{{ errors.pep_details }}</span>
                        </label>
                        <label class="flex items-start gap-2 text-white">
                            <input v-model="form.declare_not_sanctioned" type="checkbox" class="mt-1" name="declare_not_sanctioned"> <span>{{ t('page.tokenCreator.form.notSanctioned') }}</span>
                        </label>
                        <span v-if="errors.declare_not_sanctioned" class="text-xs text-red-400">{{ errors.declare_not_sanctioned }}</span>
                        <label class="flex items-start gap-2 text-white">
                            <input v-model="form.declare_true" type="checkbox" class="mt-1" name="declare_true"> <span>{{ t('page.tokenCreator.form.declareTrue') }}</span>
                        </label>
                        <span v-if="errors.declare_true" class="text-xs text-red-400">{{ errors.declare_true }}</span>
                    </fieldset>

                    <!-- ความยินยอมเผยแพร่ (PDPA) ทีละรายการ -->
                    <fieldset class="space-y-2 text-sm" data-test="consents">
                        <legend class="text-sm font-semibold text-gray-300 mb-1">{{ t('page.tokenCreator.form.consents') }}</legend>
                        <p class="text-xs text-gray-400">{{ t('page.tokenCreator.form.consentsHint') }}</p>
                        <label class="flex items-start gap-2 text-white">
                            <input v-model="form.consent_publish_project" type="checkbox" class="mt-1" name="consent_publish_project" data-test="consent-project">
                            <span>{{ t('page.tokenCreator.form.consentProject') }}</span>
                        </label>
                        <span v-if="errors.consent_publish_project" class="text-xs text-red-400">{{ errors.consent_publish_project }}</span>
                        <label class="flex items-start gap-2 text-white">
                            <input v-model="form.consent_publish_name" type="checkbox" class="mt-1" name="consent_publish_name" data-test="consent-name">
                            <span>{{ t('page.tokenCreator.form.consentName') }}</span>
                        </label>
                    </fieldset>

                    <button type="submit" class="btn-primary" data-test="save" :disabled="!!busy">
                        {{ busy === 'save' ? busyLabel : t('page.tokenCreator.form.save') }}
                    </button>
                </form>

                <dl v-else-if="registration.form" class="grid md:grid-cols-2 gap-x-6 gap-y-1 text-sm" data-test="form-summary">
                    <template v-for="f in ['project_name', 'token_name', 'token_symbol', 'email']" :key="f">
                        <dt class="text-gray-400">{{ t(`page.tokenCreator.fields.${f}`) }}</dt>
                        <dd class="text-white break-all">{{ registration.form[f] }}</dd>
                    </template>
                </dl>
            </section>

            <!-- 2b. เอกสารประกอบ -->
            <section v-if="registration && registration.form_hash && step !== 'status'" class="glass-card p-5 space-y-4" data-test="step-documents">
                <h2 class="text-lg font-semibold text-white">{{ t('page.tokenCreator.docs.title') }}</h2>
                <p class="text-sm text-gray-400">
                    {{ registration.form?.applicant_type === 'company' ? t('page.tokenCreator.docs.companyIntro') : t('page.tokenCreator.docs.individualIntro') }}
                </p>
                <p class="text-xs text-gray-500">{{ t('page.tokenCreator.docs.publicNote') }}</p>
                <p v-if="missingDocs.length" class="text-sm text-amber-300" data-test="docs-missing">
                    {{ t('page.tokenCreator.docs.missing', { types: missingDocs.map((d) => t(`page.tokenCreator.docs.types.${d}`)).join(', ') }) }}
                </p>
                <p v-if="docError" class="text-sm text-red-300" data-test="docs-error">{{ docError }}</p>
                <p v-if="registration.signed && docEditable" class="text-xs text-amber-300">{{ t('page.tokenCreator.docs.resignWarning') }}</p>

                <ul v-if="documents.length" class="divide-y divide-white/10 text-sm" data-test="docs-list">
                    <li v-for="doc in documents" :key="doc.uuid" class="py-2 flex flex-wrap items-center gap-x-4 gap-y-1" data-test="doc-row">
                        <span class="text-white">{{ t(`page.tokenCreator.docs.types.${doc.type}`) }}</span>
                        <span class="text-gray-500 break-all">{{ doc.original_name }} · {{ fmtSize(doc.size) }}</span>
                        <span class="ml-auto flex items-center gap-3">
                            <label v-if="doc.publishable" class="flex items-center gap-1 text-xs text-gray-300">
                                <input type="checkbox" :checked="doc.publish" :disabled="!docEditable || !!docBusy" data-test="doc-publish" @change="togglePublish(doc)">
                                {{ t('page.tokenCreator.docs.publish') }}
                            </label>
                            <span v-else class="text-xs text-gray-500" data-test="doc-private">{{ t('page.tokenCreator.docs.private') }}</span>
                            <a :href="`/token-creator/documents/${doc.uuid}`" target="_blank" rel="noopener" class="text-xs text-primary-400 hover:underline">{{ t('page.tokenCreator.docs.view') }}</a>
                            <button v-if="docEditable" type="button" class="text-xs text-red-400 hover:underline" :disabled="!!docBusy" data-test="doc-delete" @click="deleteDocument(doc)">
                                {{ t('page.tokenCreator.docs.delete') }}
                            </button>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-500" data-test="docs-empty">{{ t('page.tokenCreator.docs.empty') }}</p>

                <form v-if="docEditable && allowedDocTypes.length" class="grid md:grid-cols-3 gap-3 items-end" data-test="doc-upload" @submit.prevent="uploadDocument">
                    <label class="block text-sm">
                        <span class="text-gray-400">{{ t('page.tokenCreator.docs.type') }}</span>
                        <select v-model="upload.type" class="trading-input w-full" data-test="doc-type">
                            <option v-for="type in allowedDocTypes" :key="type" :value="type">{{ t(`page.tokenCreator.docs.types.${type}`) }}</option>
                        </select>
                    </label>
                    <label class="block text-sm md:col-span-2">
                        <span class="text-gray-400">{{ t('page.tokenCreator.docs.file', { mb: Math.round((options.document_max_kb || 10240) / 1024) }) }}</span>
                        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="block w-full text-sm text-gray-300" data-test="doc-file" @change="pickFile">
                    </label>
                    <label v-if="docTypes[upload.type]?.public" class="flex items-start gap-2 text-sm text-white md:col-span-2">
                        <input v-model="upload.publish" type="checkbox" class="mt-1" :disabled="!uploadPublishable" data-test="doc-upload-publish">
                        <span>{{ uploadPublishable ? t('page.tokenCreator.docs.publishUpload') : t('page.tokenCreator.docs.publishImageOnly') }}</span>
                    </label>
                    <p v-else class="text-xs text-gray-500 md:col-span-2">{{ t('page.tokenCreator.docs.adminOnly') }}</p>
                    <button type="submit" class="btn-primary" :disabled="!upload.file || !!docBusy" data-test="doc-upload-btn">
                        {{ docBusy === 'upload' ? t('page.tokenCreator.docs.uploading') : t('page.tokenCreator.docs.upload') }}
                    </button>
                </form>
            </section>

            <!-- 3. KYC -->
            <section v-if="registration && step !== 'status'" class="glass-card p-5 space-y-3" data-test="step-kyc">
                <h2 class="text-lg font-semibold text-white">{{ t('page.tokenCreator.kyc.title') }}</h2>
                <p v-if="kycFlash" class="text-sm text-trading-green" data-test="kyc-flash">{{ kycFlash }}</p>
                <p v-if="kycServerError || tpError" class="text-sm text-red-300" data-test="kyc-error">{{ kycServerError || tpError }}</p>
                <p v-if="kycApproved" class="text-trading-green text-sm" data-test="kyc-ok">✓ {{ t('page.tokenCreator.kyc.approved') }}</p>
                <template v-else>
                    <p class="text-sm text-amber-300" data-test="kyc-pending">{{ t('page.tokenCreator.kyc.pending') }}</p>

                    <ol class="space-y-4 text-sm text-gray-300" data-test="kyc-thaiprompt-steps">
                        <!-- 1. โหลดแอป Thaiprompt -->
                        <li class="space-y-2">
                            <p><strong class="text-white">1.</strong> {{ t('page.tokenCreator.kyc.appStep1') }}</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <img v-if="downloadQr" :src="downloadQr" :alt="t('page.tokenCreator.kyc.qrAlt')" width="160" height="160"
                                     class="rounded bg-white p-1" data-test="kyc-download-qr">
                                <a v-if="downloadUrl" :href="downloadUrl" target="_blank" rel="noopener noreferrer"
                                   class="btn-primary" data-test="kyc-download">{{ t('page.tokenCreator.kyc.download') }}</a>
                            </div>
                            <p v-if="downloadQr" class="text-xs text-gray-500">{{ t('page.tokenCreator.kyc.qrScan') }}</p>
                        </li>

                        <!-- 2. ทำ eKYC ในแอป -->
                        <li class="space-y-2">
                            <p><strong class="text-white">2.</strong> {{ t('page.tokenCreator.kyc.appStep2') }}</p>
                            <a v-if="mobile && thaiprompt.app_link" :href="thaiprompt.app_link" class="btn-primary inline-block"
                               data-test="kyc-open-app">{{ t('page.tokenCreator.kyc.openApp') }}</a>
                        </li>

                        <!-- 3. เชื่อมผลกลับมาที่เว็บนี้ -->
                        <li class="space-y-2">
                            <p><strong class="text-white">3.</strong> {{ t('page.tokenCreator.kyc.appStep3') }}</p>
                            <template v-if="thaiprompt.available && !tpLinked">
                                <label class="flex items-start gap-2">
                                    <input v-model="tpConsent" type="checkbox" class="mt-1" data-test="kyc-consent">
                                    <span>{{ t('page.tokenCreator.kyc.consent') }}</span>
                                </label>
                                <button type="button" class="btn-primary" :disabled="!tpConsent || tpBusy" data-test="kyc-connect" @click="connectThaiprompt">
                                    {{ thaiprompt.link?.needs_reconnect ? t('page.tokenCreator.kyc.reconnect') : t('page.tokenCreator.kyc.connect') }}
                                </button>
                            </template>
                            <p v-else-if="tpLinked" class="text-xs text-gray-400" data-test="kyc-linked">
                                {{ t('page.tokenCreator.kyc.linked', { status: t(`page.tokenCreator.kyc.tpStatus.${thaiprompt.link.status || 'none'}`) }) }}
                            </p>
                            <Link v-else href="/kyc" class="btn-primary inline-block" data-test="kyc-go">{{ t('page.tokenCreator.kyc.go') }}</Link>
                        </li>
                    </ol>

                    <button type="button" class="text-sm text-primary-400 hover:underline" :disabled="tpBusy" data-test="kyc-refresh" @click="refreshKyc">
                        {{ tpBusy ? t('page.tokenCreator.kyc.checking') : t('page.tokenCreator.kyc.refresh') }}
                    </button>
                </template>
            </section>

            <!-- 4. ข้อตกลง -->
            <section v-if="registration && step === 'review'" class="glass-card p-5 space-y-4" data-test="step-agreement">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-white">{{ t('page.tokenCreator.agreement.title') }}</h2>
                    <span class="text-xs text-gray-400">{{ t('page.tokenCreator.agreement.version', { version: agreement.version }) }} · SHA-256 {{ agreement.hash.slice(0, 12) }}…</span>
                </div>

                <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-200">
                    <p>{{ agreement.document.draft_notice_th }}</p>
                    <p v-if="showEnglish" class="mt-1 text-amber-200/70 text-xs">{{ agreement.document.draft_notice_en }}</p>
                </div>

                <label class="flex items-center gap-2 text-xs text-gray-400">
                    <input v-model="showEnglish" type="checkbox"> {{ t('page.tokenCreator.agreement.showEnglish') }}
                    <span class="ml-2">· {{ t('page.tokenCreator.agreement.thaiPrevails') }}</span>
                </label>

                <article class="max-h-[28rem] overflow-y-auto rounded-lg border border-white/10 bg-black/20 p-4 space-y-4 text-sm leading-relaxed" data-test="agreement-text">
                    <h3 class="text-base font-semibold text-white">{{ agreement.document.title_th }}</h3>
                    <p v-if="showEnglish" class="-mt-3 text-xs text-gray-500">{{ agreement.document.title_en }}</p>
                    <p class="text-gray-200">{{ agreement.document.preamble_th }}</p>
                    <p v-if="showEnglish" class="text-xs text-gray-500">{{ agreement.document.preamble_en }}</p>
                    <div v-for="(section, i) in agreement.document.sections" :key="section.id" class="space-y-2">
                        <h4 class="font-semibold text-white">
                            {{ i + 1 }}. {{ section.title_th }}
                            <span v-if="showEnglish" class="font-normal text-xs text-gray-500">/ {{ section.title_en }}</span>
                        </h4>
                        <div v-for="(clause, j) in section.clauses" :key="j">
                            <p class="text-gray-200">{{ i + 1 }}.{{ j + 1 }} {{ clause.th }}</p>
                            <p v-if="showEnglish" class="text-xs text-gray-500">{{ i + 1 }}.{{ j + 1 }} {{ clause.en }}</p>
                        </div>
                    </div>
                </article>

                <p class="text-sm text-primary-300" data-test="starter-note">{{ claim ? t('page.tokenCreator.claim.agreementNote', { symbol: claim.symbol, owner: claim.owner_wallet }) : t('page.tokenCreator.agreement.starter', { pct: starterPct }) }}</p>

                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2 text-white"><input v-model="ack.read" type="checkbox" class="mt-1" data-test="ack-read"> {{ t('page.tokenCreator.agreement.ackRead') }}</label>
                    <label class="flex items-start gap-2 text-white"><input v-model="ack.thai" type="checkbox" class="mt-1" data-test="ack-thai"> {{ t('page.tokenCreator.agreement.ackThai') }}</label>
                    <label class="flex items-start gap-2 text-white"><input v-model="ack.risk" type="checkbox" class="mt-1" data-test="ack-risk"> {{ t('page.tokenCreator.agreement.ackRisk') }}</label>
                </div>

                <p v-if="!kycApproved" class="text-xs text-amber-300">{{ t('page.tokenCreator.agreement.needKyc') }}</p>
                <p v-if="missingDocs.length" class="text-xs text-amber-300" data-test="need-docs">{{ t('page.tokenCreator.agreement.needDocs') }}</p>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="btn-success" data-test="sign-agreement" :disabled="!canSign" @click="signAgreement">
                        {{ busy ? busyLabel : t('page.tokenCreator.agreement.sign') }}
                    </button>
                    <a href="/token-creator/pdf" class="text-sm text-primary-400 hover:underline" data-test="draft-pdf">{{ t('page.tokenCreator.agreement.downloadDraft') }}</a>
                </div>
            </section>

            <!-- 5. สถานะ -->
            <section v-if="registration && step === 'status'" class="glass-card p-5 space-y-3" data-test="step-status">
                <template v-if="registration.status === 'submitted'">
                    <h2 class="text-lg font-semibold text-white">{{ t('page.tokenCreator.status.submittedTitle') }}</h2>
                    <p class="text-sm text-gray-400">{{ t('page.tokenCreator.status.submittedDesc') }}</p>
                </template>
                <template v-else-if="registration.status === 'approved'">
                    <h2 class="text-lg font-semibold text-trading-green">{{ t('page.tokenCreator.status.approvedTitle') }}</h2>
                    <p class="text-sm text-gray-300" data-test="approved-desc">{{ claim ? t('page.tokenCreator.claim.approvedDesc', { symbol: claim.symbol, pct: starterPct }) : t('page.tokenCreator.status.approvedDesc', { pct: starterPct }) }}</p>
                    <div class="flex flex-wrap gap-3">
                        <Link href="/token-factory" class="btn-primary inline-block">{{ t('page.tokenCreator.status.approvedCta') }}</Link>
                        <a v-if="registration.public_url" :href="registration.public_url" class="btn-secondary inline-block" data-test="public-page">{{ t('page.tokenCreator.status.publicPage') }}</a>
                    </div>
                </template>
                <template v-else-if="registration.status === 'rejected'">
                    <h2 class="text-lg font-semibold text-trading-red">{{ t('page.tokenCreator.status.rejectedTitle') }}</h2>
                    <p class="text-sm text-gray-300" data-test="rejection-reason">
                        <span class="text-gray-400">{{ t('page.tokenCreator.status.reason') }}:</span> {{ registration.rejection_reason }}
                    </p>
                    <button type="button" class="btn-primary" data-test="resubmit" @click="editAgain">{{ t('page.tokenCreator.status.resubmit') }}</button>
                </template>
                <p v-if="registration.agreement_signed_at" class="text-xs text-gray-500">
                    {{ t('page.tokenCreator.status.signedAt', { at: fmtDate(registration.agreement_signed_at) }) }}
                </p>
                <a v-if="registration.signed" href="/token-creator/pdf" class="text-sm text-primary-400 hover:underline" data-test="signed-pdf">
                    {{ t('page.tokenCreator.status.downloadPdf') }}
                </a>
            </section>
        </div>
    </AppLayout>
</template>
