<script setup>
/**
 * TPIX Validator Application — Apply to become a validator
 *
 * ใบสมัครต้องเซ็นด้วยกระเป๋าที่สมัคร (EIP-191 personal_sign):
 *   1. ขอข้อความจากเซิร์ฟเวอร์ (ผูกกระเป๋า + ชั้น + endpoint + nonce ครั้งเดียว)
 *   2. ให้กระเป๋าเซ็น — ฟรี ไม่ส่งธุรกรรม
 *   3. ส่งใบสมัครพร้อมลายเซ็น
 * เดิมพิมพ์ที่อยู่กระเป๋าของใครก็ได้ลงช่อง แล้วประเทศที่เลือกไปโผล่บนแผนที่เป็นตำแหน่งโหนด
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    // ค่าชั้นจาก NodeRegistryV2 บนเชน (ชื่อชั้นตัวเล็ก => ค่า) — อ่านไม่ได้ = {} แล้วโชว์ "—"
    tiers: { type: [Object, Array], default: () => ({}) },
});

const walletStore = useWalletStore();

// Form state
const form = ref({
    wallet_address: '',
    tier: '',
    endpoint: '',
    country_code: '',
    country_name: '',
    contact_email: '',
    contact_telegram: '',
    hardware_cpu: '',
    hardware_ram: '',
    hardware_ssd: '',
    motivation: '',
});

const isSubmitting = ref(false);
const stage = ref('');               // '' | challenge | sign | submit
const submitError = ref('');
const submitSuccess = ref(false);
const errors = ref({});

// ใบสมัครเป็นของกระเป๋าที่เชื่อมอยู่เท่านั้น — สลับบัญชีในกระเป๋าเมื่อไหร่ ช่องนี้เปลี่ยนตาม
watch(() => walletStore.address, (addr) => {
    form.value.wallet_address = addr || '';
}, { immediate: true });

// Tiers — styling mirrors MasterNode tier cards; ตัวเลข (ค้ำ/ล็อก/เพดาน APR) มาจากเชนเท่านั้น
const tierStyles = [
    {
        id: 'validator', name: 'Validator Node',
        hardware: '16 CPU / 32GB RAM / 1TB SSD',
        color: 'border-red-500/40 bg-red-500/10', accent: 'text-red-400',
        gradient: 'from-red-500/30 via-rose-500/20 to-pink-500/10',
        border: 'border-red-500/30',
        glow: 'shadow-[0_0_40px_rgba(239,68,68,0.15)]',
        badge: 'bg-red-500/20 text-red-300 border-red-500/40',
        ring: 'ring-red-500/40',
        noteKey: 'page.validatorsApply.tier.validatorNote',
    },
    {
        id: 'guardian', name: 'Guardian Node',
        hardware: '8 CPU / 16GB RAM / 500GB SSD',
        color: 'border-yellow-500/40 bg-yellow-500/10', accent: 'text-yellow-400',
        gradient: 'from-yellow-500/30 via-amber-500/20 to-orange-500/10',
        border: 'border-yellow-500/30',
        glow: 'shadow-[0_0_40px_rgba(245,158,11,0.15)]',
        badge: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40',
        ring: 'ring-yellow-500/40',
    },
    {
        id: 'sentinel', name: 'Sentinel Node',
        hardware: '4 CPU / 8GB RAM / 200GB SSD',
        color: 'border-purple-500/40 bg-purple-500/10', accent: 'text-purple-400',
        gradient: 'from-purple-500/30 via-violet-500/20 to-fuchsia-500/10',
        border: 'border-purple-500/30',
        glow: 'shadow-[0_0_40px_rgba(139,92,246,0.15)]',
        badge: 'bg-purple-500/20 text-purple-300 border-purple-500/40',
        ring: 'ring-purple-500/40',
    },
    {
        id: 'light', name: 'Light Node',
        hardware: '2 CPU / 4GB RAM / 100GB SSD',
        color: 'border-cyan-500/40 bg-cyan-500/10', accent: 'text-cyan-400',
        gradient: 'from-cyan-500/30 via-blue-500/20 to-teal-500/10',
        border: 'border-cyan-500/30',
        glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        badge: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',
        ring: 'ring-cyan-500/40',
    },
];

const tiers = computed(() => tierStyles.map((ts) => {
    const c = props.tiers?.[ts.id];
    return {
        ...ts,
        note: ts.noteKey ? t(ts.noteKey) : '',
        stake: c?.min_stake != null ? `${Number(c.min_stake).toLocaleString()} TPIX` : '—',
        lock: c?.lock_days != null ? t('page.validatorsApply.tier.lockDays', { n: c.lock_days }) : '—',
        // เพดาน APR ต่อโหนดที่สัญญาบังคับ — ไม่ใช่ผลตอบแทนที่รับประกัน
        apr: c?.apr_cap_bps != null ? t('page.validatorsApply.tier.aprCap', { pct: (c.apr_cap_bps / 100).toLocaleString() }) : 'APR —',
    };
}));

// ประเทศที่ผู้สมัครแจ้ง — ให้ทีมงานดูประกอบการพิจารณาเท่านั้น ไม่ได้ขึ้นแผนที่
const countries = [
    { code: 'TH', name: 'Thailand' },
    { code: 'US', name: 'United States' },
    { code: 'JP', name: 'Japan' },
    { code: 'KR', name: 'South Korea' },
    { code: 'SG', name: 'Singapore' },
    { code: 'DE', name: 'Germany' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'CA', name: 'Canada' },
    { code: 'AU', name: 'Australia' },
    { code: 'FR', name: 'France' },
    { code: 'CH', name: 'Switzerland' },
    { code: 'HK', name: 'Hong Kong' },
    { code: 'IN', name: 'India' },
    { code: 'BR', name: 'Brazil' },
    { code: 'AE', name: 'UAE' },
    { code: 'VN', name: 'Vietnam' },
    { code: 'PH', name: 'Philippines' },
    { code: 'MY', name: 'Malaysia' },
    { code: 'ID', name: 'Indonesia' },
    { code: 'TW', name: 'Taiwan' },
    { code: 'FI', name: 'Finland' },
    { code: 'SE', name: 'Sweden' },
    { code: 'NO', name: 'Norway' },
];

// ชื่อประเทศที่แสดงตามภาษา — ส่งชื่ออังกฤษ (c.name) ให้เซิร์ฟเวอร์เหมือนเดิม
const countryOptions = computed(() => countries
    .map((c) => ({ ...c, label: t(`page.validatorsApply.countries.${c.code}`) }))
    .sort((a, b) => a.label.localeCompare(b.label)));

// ข้อความผิดพลาดฝั่งเราเก็บเป็นคีย์ (แปลตอนแสดง สลับภาษาแล้วเปลี่ยนตาม) — ข้อความจากเซิร์ฟเวอร์แสดงตามที่ได้มา
function msg(v) {
    return typeof v === 'string' && v.startsWith('page.validatorsApply.') ? t(v) : v;
}

function flag(code) {
    if (!code || code.length !== 2) return '🌐';
    return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
}

function onCountryChange() {
    const c = countries.find(c => c.code === form.value.country_code);
    form.value.country_name = c ? c.name : '';
}

// Validation
function validate() {
    const e = {};
    if (!/^0x[a-fA-F0-9]{40}$/.test(form.value.wallet_address)) {
        e.wallet_address = 'page.validatorsApply.errors.walletRequired';
    }
    if (!form.value.tier) e.tier = 'page.validatorsApply.errors.tierRequired';
    if (!form.value.country_code) e.country_code = 'page.validatorsApply.errors.countryRequired';
    if (form.value.contact_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.contact_email)) {
        e.contact_email = 'page.validatorsApply.errors.emailInvalid';
    }
    errors.value = e;
    return Object.keys(e).length === 0;
}

// กระเป๋าบนมือถือ (WalletConnect) อาจไม่ตอบกลับเลย — ไม่ปล่อยปุ่มค้าง "Submitting..." ไปตลอด
// สั้นกว่าอายุข้อความบนเซิร์ฟเวอร์ (10 นาที) ลายเซ็นที่ได้มาทันเวลาจึงยังใช้ได้เสมอ
const SIGN_TIMEOUT_MS = 5 * 60 * 1000;

function codeError(code) {
    const e = new Error(code);
    e.code = code;
    return e;
}

function withTimeout(promise, ms) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => { timer = setTimeout(() => reject(codeError('SIGN_TIMEOUT')), ms); }),
    ]).finally(() => clearTimeout(timer));
}

function describeError(err) {
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') {
        return 'page.validatorsApply.errors.signCancelled';
    }
    if (err?.code === 'NO_SIGNER') {
        return 'page.validatorsApply.errors.noSigner';
    }
    if (err?.code === 'WALLET_CHANGED') {
        return 'page.validatorsApply.errors.walletChanged';
    }
    if (err?.code === 'SIGN_TIMEOUT') {
        return 'page.validatorsApply.errors.signTimeout';
    }

    const res = err?.response;
    if (res?.status === 429) return 'page.validatorsApply.errors.rateLimited';
    if (res?.data?.error?.message) return res.data.error.message;
    if (res) return 'page.validatorsApply.errors.submitFailed';
    return 'page.validatorsApply.errors.network';
}

async function submitApplication() {
    // ดับเบิลคลิกระหว่างรอกระเป๋า — ไม่ขอข้อความ/ลายเซ็นซ้อนกันสองชุด
    if (isSubmitting.value) return;
    if (!validate()) return;

    isSubmitting.value = true;
    submitError.value = '';
    const wallet = form.value.wallet_address;
    const endpoint = form.value.endpoint.trim() || null;

    try {
        const signer = walletStore.signer;
        if (!signer) throw codeError('NO_SIGNER');
        if ((await signer.getAddress()).toLowerCase() !== wallet.toLowerCase()) throw codeError('WALLET_CHANGED');

        stage.value = 'challenge';
        const challenge = (await axios.post('/api/v1/validators/apply/challenge', {
            wallet_address: wallet,
            tier: form.value.tier,
            endpoint,
        })).data.data;

        stage.value = 'sign';
        const signature = await withTimeout(signer.signMessage(challenge.message), SIGN_TIMEOUT_MS);

        // ผู้ใช้สลับบัญชีระหว่างเปิดกระเป๋า — ใบสมัครต้องเป็นของกระเป๋าที่เซ็นจริง
        if ((walletStore.address || '').toLowerCase() !== wallet.toLowerCase()) throw codeError('WALLET_CHANGED');

        stage.value = 'submit';
        await axios.post('/api/v1/validators/apply', {
            wallet_address: wallet,
            tier: form.value.tier,
            endpoint,
            country_code: form.value.country_code,
            country_name: form.value.country_name,
            contact_email: form.value.contact_email || null,
            contact_telegram: form.value.contact_telegram || null,
            hardware_specs: JSON.stringify({
                cpu: form.value.hardware_cpu,
                ram: form.value.hardware_ram,
                ssd: form.value.hardware_ssd,
            }),
            motivation: form.value.motivation || null,
            nonce: challenge.nonce,
            signature,
        });
        submitSuccess.value = true;
    } catch (err) {
        // Laravel 422: { errors: { field: [ข้อความ] } } — โชว์ใต้ช่องที่ผิด
        const fieldErrors = err?.response?.status === 422 ? err.response.data?.errors : null;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
            submitError.value = 'page.validatorsApply.errors.checkFields';
        } else {
            submitError.value = describeError(err);
        }
    } finally {
        isSubmitting.value = false;
        stage.value = '';
    }
}

const submitLabel = computed(() => t({
    challenge: 'page.validatorsApply.stage.challenge',
    sign: 'page.validatorsApply.stage.sign',
    submit: 'page.validatorsApply.stage.submit',
}[stage.value] || 'page.validatorsApply.stage.submit'));
</script>

<template>
    <Head :title="t('page.validatorsApply.headTitle')" />
    <AppLayout>
        <div class="relative min-h-screen overflow-hidden">

            <!-- ============================================================ -->
            <!--  Animated Background Glow (3 layers — mirrors MasterNode)    -->
            <!-- ============================================================ -->
            <div class="fixed inset-0 pointer-events-none -z-10">
                <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] bg-accent-500/8 rounded-full blur-3xl animate-float" />
                <div class="absolute top-1/2 right-1/3 w-[700px] h-[700px] bg-primary-500/6 rounded-full blur-3xl" style="animation: float 8s ease-in-out infinite reverse" />
                <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] bg-warm-500/5 rounded-full blur-3xl animate-float" style="animation-delay: -3s" />
            </div>

            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8 relative z-10">

                <!-- Back -->
                <Link href="/validators"
                      class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-cyan-400 transition-colors">
                    <span aria-hidden="true">&larr;</span> {{ t('page.validatorsApply.back') }}
                </Link>

                <!-- ============================================================ -->
                <!--  HERO header with gradient glow backdrop                     -->
                <!-- ============================================================ -->
                <div class="relative">
                    <div class="absolute -inset-2 bg-gradient-to-r from-yellow-500/20 via-amber-500/15 to-cyan-500/20 rounded-3xl blur-xl opacity-60" />
                    <div class="glass-brand relative rounded-3xl p-8 md:p-10 text-center overflow-hidden">
                        <!-- Floating particles -->
                        <div class="absolute top-6 left-10 w-2 h-2 bg-cyan-400/40 rounded-full animate-float" />
                        <div class="absolute top-16 right-16 w-1.5 h-1.5 bg-yellow-400/40 rounded-full animate-float" style="animation-delay: -2s" />
                        <div class="absolute bottom-8 left-1/4 w-1 h-1 bg-purple-400/40 rounded-full animate-float" style="animation-delay: -4s" />

                        <!-- TPIX Logo with radial glow -->
                        <div class="relative inline-block mb-5">
                            <div class="absolute -inset-3 bg-gradient-to-r from-yellow-500/30 via-amber-500/30 to-cyan-500/30 rounded-full blur-2xl animate-glow-brand" />
                            <BrandLogo variant="mark" alt="TPIX" class="relative w-20 h-20 rounded-full ring-2 ring-white/10" />
                        </div>

                        <h1 class="text-3xl md:text-4xl font-black mb-3">
                            <span class="text-gradient-brand">{{ t('page.validatorsApply.hero.title') }}</span>
                        </h1>
                        <p class="text-sm text-gray-400 max-w-lg mx-auto">
                            {{ t('page.validatorsApply.hero.subtitle') }}
                        </p>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  Success State — MasterNode-style success banner             -->
                <!-- ============================================================ -->
                <div v-if="submitSuccess" class="relative">
                    <div class="absolute -inset-1 bg-gradient-to-r from-green-500/20 via-emerald-500/20 to-cyan-500/20 rounded-3xl blur-xl opacity-60" />
                    <div class="glass-brand relative rounded-2xl p-10 text-center space-y-5 border-l-4 border-green-500 overflow-hidden">
                        <!-- particle dots -->
                        <div class="absolute top-4 right-6 w-1.5 h-1.5 bg-green-400/40 rounded-full animate-float" />
                        <div class="absolute bottom-6 left-8 w-1 h-1 bg-cyan-400/40 rounded-full animate-float" style="animation-delay: -2s" />

                        <div class="relative inline-block">
                            <div class="absolute -inset-3 bg-green-500/30 rounded-full blur-2xl animate-glow-brand" />
                            <div class="relative w-16 h-16 mx-auto rounded-2xl bg-green-500/20 border border-green-500/30 flex items-center justify-center text-3xl ring-2 ring-white/10">
                                <span aria-hidden="true">&check;</span>
                            </div>
                        </div>
                        <h2 class="text-2xl font-black text-green-400">{{ t('page.validatorsApply.success.title') }}</h2>
                        <p class="text-sm text-gray-400 max-w-md mx-auto">
                            {{ t('page.validatorsApply.success.body') }}
                        </p>
                        <div class="flex justify-center gap-3 flex-wrap pt-2">
                            <Link href="/validators"
                                  class="btn-secondary px-6 py-2.5 rounded-xl font-bold text-sm">
                                {{ t('page.validatorsApply.success.viewNetwork') }}
                            </Link>
                            <Link href="/masternode/guide"
                                  class="btn-brand px-6 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                                {{ t('page.validatorsApply.success.setupGuide') }}
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  Form                                                        -->
                <!-- ============================================================ -->
                <form v-else @submit.prevent="submitApplication" class="space-y-6">

                    <!-- ========================= -->
                    <!--  Step 1: Choose Tier      -->
                    <!-- ========================= -->
                    <div class="glass rounded-2xl p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                1
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ t('page.validatorsApply.step1.title') }}</h3>
                                <p class="text-xs text-gray-400">{{ t('page.validatorsApply.step1.desc') }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <button v-for="tier in tiers" :key="tier.id" type="button"
                                    @click="form.tier = tier.id"
                                    :class="[
                                        'group relative rounded-2xl overflow-hidden transition-all duration-500 text-left',
                                        form.tier === tier.id ? 'scale-[1.02] -translate-y-1' : 'hover:scale-[1.03] hover:-translate-y-1'
                                    ]">
                                <!-- Glow backdrop -->
                                <div :class="[
                                        'absolute -inset-1 bg-gradient-to-b rounded-3xl blur-xl transition-opacity duration-500',
                                        tier.gradient,
                                        form.tier === tier.id ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'
                                     ]" />

                                <!-- Card body -->
                                <div :class="[
                                        'relative glass rounded-2xl p-4 h-full flex flex-col border transition-all',
                                        tier.border,
                                        form.tier === tier.id ? tier.glow + ' ring-2 ' + tier.ring : ''
                                     ]">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="relative">
                                            <div :class="['absolute -inset-1.5 rounded-xl blur-lg opacity-40', tier.gradient]" />
                                            <BrandLogo variant="icon" alt="TPIX" class="relative w-9 h-9 rounded-full ring-1 ring-white/10" />
                                        </div>
                                        <div :class="['px-2 py-0.5 rounded-full text-[10px] font-black border', tier.badge]">
                                            {{ tier.apr }}
                                        </div>
                                    </div>
                                    <div :class="['font-bold text-sm mb-2', tier.accent]">{{ tier.name }}</div>
                                    <div class="text-[10px] text-gray-400 space-y-1">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">{{ t('page.validatorsApply.tier.stake') }}</span>
                                            <span :class="['font-bold', tier.accent]">{{ tier.stake }}</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">{{ t('page.validatorsApply.tier.lock') }}</span>
                                            <span class="text-gray-300">{{ tier.lock }}</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="text-[10px] text-gray-500 leading-snug pt-1">{{ tier.hardware }}</div>
                                        <div v-if="tier.note" class="text-red-300 mt-1 text-[10px]">{{ tier.note }}</div>
                                    </div>
                                </div>
                            </button>
                        </div>
                        <p v-if="errors.tier" class="text-xs text-red-400">{{ msg(errors.tier) }}</p>
                    </div>

                    <!-- ========================= -->
                    <!--  Step 2: Wallet & Node    -->
                    <!-- ========================= -->
                    <div class="glass rounded-2xl p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                2
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ t('page.validatorsApply.step2.title') }}</h3>
                                <p class="text-xs text-gray-400">{{ t('page.validatorsApply.step2.desc') }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step2.walletLabel') }} *</label>
                            <!-- อ่านจากกระเป๋าที่เชื่อมอยู่เท่านั้น — พิมพ์ที่อยู่ของคนอื่นไม่ได้ -->
                            <div v-if="form.wallet_address"
                                 data-test="apply-wallet"
                                 class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono break-all">
                                {{ form.wallet_address }}
                            </div>
                            <button v-else type="button" data-test="apply-connect"
                                    @click="walletStore.openConnectModal()"
                                    class="btn-brand w-full py-3 rounded-xl text-sm font-bold">
                                {{ t('wallet.connect') }}
                            </button>
                            <p class="text-[11px] text-gray-500 mt-1.5">
                                {{ t('page.validatorsApply.step2.signHint') }}
                            </p>
                            <p v-if="errors.wallet_address" class="text-xs text-red-400 mt-1">{{ msg(errors.wallet_address) }}</p>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step2.endpointLabel') }}</label>
                            <input v-model="form.endpoint" type="text" placeholder="https://your-node:8545"
                                class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                        </div>
                    </div>

                    <!-- ========================= -->
                    <!--  Step 3: Location         -->
                    <!-- ========================= -->
                    <div class="glass rounded-2xl p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                3
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ t('page.validatorsApply.step3.title') }}</h3>
                                <p class="text-xs text-gray-400">
                                    {{ t('page.validatorsApply.step3.desc') }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step3.countryLabel') }} *</label>
                            <select v-model="form.country_code" @change="onCountryChange"
                                class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none backdrop-blur-sm">
                                <option value="">{{ t('page.validatorsApply.step3.selectCountry') }}</option>
                                <option v-for="c in countryOptions" :key="c.code" :value="c.code">
                                    {{ flag(c.code) }} {{ c.label }}
                                </option>
                            </select>
                            <p v-if="errors.country_code" class="text-xs text-red-400 mt-1">{{ msg(errors.country_code) }}</p>
                        </div>
                    </div>

                    <!-- ========================= -->
                    <!--  Step 4: Hardware         -->
                    <!-- ========================= -->
                    <div class="glass rounded-2xl p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                4
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ t('page.validatorsApply.step4.title') }} <span class="text-gray-500 font-normal">{{ t('page.validatorsApply.optional') }}</span></h3>
                                <p class="text-xs text-gray-400">{{ t('page.validatorsApply.step4.desc') }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step4.cpu') }}</label>
                                <input v-model="form.hardware_cpu" type="text" placeholder="8"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step4.ram') }}</label>
                                <input v-model="form.hardware_ram" type="text" placeholder="16"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step4.ssd') }}</label>
                                <input v-model="form.hardware_ssd" type="text" placeholder="500"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                        </div>
                    </div>

                    <!-- ========================= -->
                    <!--  Step 5: Contact          -->
                    <!-- ========================= -->
                    <div class="glass rounded-2xl p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                5
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ t('page.validatorsApply.step5.title') }} <span class="text-gray-500 font-normal">{{ t('page.validatorsApply.optional') }}</span></h3>
                                <p class="text-xs text-gray-400">{{ t('page.validatorsApply.step5.desc') }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">{{ t('page.validatorsApply.step5.email') }}</label>
                                <input v-model="form.contact_email" type="email" placeholder="you@example.com"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                                <p v-if="errors.contact_email" class="text-xs text-red-400 mt-1">{{ msg(errors.contact_email) }}</p>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">Telegram</label>
                                <input v-model="form.contact_telegram" type="text" placeholder="@username"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                        </div>
                    </div>

                    <!-- ========================= -->
                    <!--  Step 6: Motivation       -->
                    <!-- ========================= -->
                    <div class="glass rounded-2xl p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                6
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white">{{ t('page.validatorsApply.step6.title') }} <span class="text-gray-500 font-normal">{{ t('page.validatorsApply.optional') }}</span></h3>
                                <p class="text-xs text-gray-400">{{ t('page.validatorsApply.step6.desc') }}</p>
                            </div>
                        </div>

                        <textarea v-model="form.motivation" rows="4" :placeholder="t('page.validatorsApply.step6.placeholder')"
                            class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 resize-none backdrop-blur-sm"></textarea>
                    </div>

                    <!-- Error banner — MasterNode-style border-l-4 -->
                    <div v-if="submitError" class="glass rounded-2xl p-4 border-l-4 border-red-500">
                        <div class="text-red-400 text-sm">{{ msg(submitError) }}</div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" :disabled="isSubmitting || !form.wallet_address" data-test="apply-submit"
                        class="btn-brand w-full py-4 rounded-2xl text-base font-bold hover:shadow-lg hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:hover:scale-100">
                        <span v-if="isSubmitting" class="inline-flex items-center justify-center gap-2">
                            <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.42" stroke-dashoffset="10"/></svg>
                            {{ submitLabel }}
                        </span>
                        <span v-else>{{ form.wallet_address ? t('page.validatorsApply.submit.signAndSubmit') : t('page.validatorsApply.submit.connectToApply') }}</span>
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
