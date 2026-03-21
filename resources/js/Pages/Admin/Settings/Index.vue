<script setup>
/**
 * TPIX TRADE - Admin Settings Page
 * Tabbed settings management: General, SEO, Trading, Security, Social
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// Flash message จาก backend
const flash = computed(() => usePage().props.flash || {});

const props = defineProps({
    settings: {
        type: Object,
        default: () => ({}),
    },
});

const activeTab = ref('general');

const tabs = [
    { key: 'general', label: 'General', icon: 'settings' },
    { key: 'seo', label: 'SEO', icon: 'search' },
    { key: 'trading', label: 'Trading', icon: 'chart' },
    { key: 'payment', label: 'Payment', icon: 'card' },
    { key: 'ai', label: 'AI', icon: 'brain' },
    { key: 'email', label: 'Email', icon: 'mail' },
    { key: 'security', label: 'Security', icon: 'shield' },
    { key: 'social', label: 'Social', icon: 'share' },
];

// General form
const generalForm = useForm({
    site_name: props.settings.site_name || 'TPIX TRADE',
    site_description: props.settings.site_description || '',
    logo: null,
    favicon: null,
    primary_color: props.settings.primary_color || '#06b6d4',
});

const logoPreview = ref(props.settings.logo_url || null);
const faviconPreview = ref(props.settings.favicon_url || null);

const handleLogoChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        generalForm.logo = file;
        logoPreview.value = URL.createObjectURL(file);
    }
};

const handleFaviconChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        generalForm.favicon = file;
        faviconPreview.value = URL.createObjectURL(file);
    }
};

const showSuccess = ref(false);
const saveGeneral = () => {
    generalForm.post('/admin/settings/general', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showSuccess.value = true;
            setTimeout(() => showSuccess.value = false, 3000);
        },
    });
};

// SEO form
const seoForm = useForm({
    meta_title: props.settings.meta_title || '',
    meta_description: props.settings.meta_description || '',
    og_image: null,
});

const ogImagePreview = ref(props.settings.og_image_url || null);

const handleOgImageChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        seoForm.og_image = file;
        ogImagePreview.value = URL.createObjectURL(file);
    }
};

const saveSeo = () => {
    seoForm.post('/admin/settings/seo', {
        preserveScroll: true,
        forceFormData: true,
    });
};

// Trading form
const tradingForm = useForm({
    default_slippage: props.settings.default_slippage || 0.5,
    max_slippage: props.settings.max_slippage || 50,
    fee_collector_wallet: props.settings.fee_collector_wallet || '',
    default_fee_rate: props.settings.default_fee_rate || 0.3,
    max_fee_rate: props.settings.max_fee_rate || 5.0,
    staking_enabled: props.settings.staking_enabled ?? true,
    bridge_enabled: props.settings.bridge_enabled ?? true,
});

const saveTrading = () => {
    tradingForm.put('/admin/settings/trading', { preserveScroll: true });
};

// Security form
const securityForm = useForm({
    turnstile_enabled: props.settings.turnstile_enabled || false,
    turnstile_site_key: props.settings.turnstile_site_key || '',
    turnstile_secret_key: props.settings.turnstile_secret_key || '',
    max_login_attempts: props.settings.max_login_attempts || 5,
    lockout_duration: props.settings.lockout_duration || 15,
});

const saveSecurity = () => {
    securityForm.put('/admin/settings/security', { preserveScroll: true });
};

// Social form
const socialForm = useForm({
    twitter_url: props.settings.twitter_url || '',
    telegram_url: props.settings.telegram_url || '',
    discord_url: props.settings.discord_url || '',
    github_url: props.settings.github_url || '',
});

const saveSocial = () => {
    socialForm.put('/admin/settings/social', { preserveScroll: true });
};

// Payment (Stripe) form
const paymentForm = useForm({
    stripe_public_key: props.settings.stripe_public_key || '',
    stripe_secret_key: props.settings.stripe_secret_key || '',
    stripe_webhook_secret: props.settings.stripe_webhook_secret || '',
    stripe_enabled: props.settings.stripe_enabled || false,
});

const savePayment = () => {
    paymentForm.put('/admin/settings/payment', { preserveScroll: true });
};

// AI form
const aiForm = useForm({
    groq_api_key: props.settings.groq_api_key || '',
    groq_default_model: props.settings.groq_default_model || 'llama-3.3-70b-versatile',
    ai_chatbot_enabled: props.settings.ai_chatbot_enabled || true,
    ai_content_enabled: props.settings.ai_content_enabled || true,
});

const saveAi = () => {
    aiForm.put('/admin/settings/ai', { preserveScroll: true });
};

// Email form
const emailForm = useForm({
    resend_api_key: props.settings.resend_api_key || '',
    mail_from_address: props.settings.mail_from_address || 'tpixtrade@xman4289.com',
    mail_from_name: props.settings.mail_from_name || 'TPIX TRADE',
});

const testEmailAddress = ref('');
const emailTestLoading = ref(false);

const saveEmail = () => {
    emailForm.put('/admin/settings/email', { preserveScroll: true });
};

const sendTestEmail = () => {
    if (!testEmailAddress.value) return;
    emailTestLoading.value = true;

    const form = useForm({ test_email: testEmailAddress.value });
    form.post('/admin/settings/email/test', {
        preserveScroll: true,
        onFinish: () => { emailTestLoading.value = false; },
    });
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200';
const labelClass = 'block text-sm font-medium text-dark-300 mb-2';
</script>

<template>
    <Head title="Settings" />

    <AdminLayout title="Settings">
        <!-- Tabs -->
        <div class="flex items-center gap-1 mb-6 bg-dark-800/30 p-1 rounded-xl border border-white/5 overflow-x-auto">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                @click="activeTab = tab.key"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 whitespace-nowrap"
                :class="activeTab === tab.key ? 'bg-primary-500/10 text-primary-400' : 'text-dark-400 hover:text-white hover:bg-white/5'"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- General Tab -->
        <div v-show="activeTab === 'general'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <!-- Success notification -->
            <div v-if="showSuccess" class="mb-4 p-3 rounded-xl bg-trading-green/10 border border-trading-green/30 text-trading-green text-sm">
                บันทึกสำเร็จ!
            </div>
            <h3 class="text-lg font-semibold text-white mb-6">General Settings</h3>
            <form @submit.prevent="saveGeneral" class="space-y-6 max-w-2xl">
                <div>
                    <label :class="labelClass">Site Name</label>
                    <input v-model="generalForm.site_name" type="text" :class="inputClass" placeholder="TPIX TRADE" />
                    <p v-if="generalForm.errors.site_name" class="mt-1 text-sm text-red-400">{{ generalForm.errors.site_name }}</p>
                </div>

                <div>
                    <label :class="labelClass">Site Description</label>
                    <textarea v-model="generalForm.site_description" :class="inputClass" rows="3" placeholder="Decentralized exchange platform"></textarea>
                    <p v-if="generalForm.errors.site_description" class="mt-1 text-sm text-red-400">{{ generalForm.errors.site_description }}</p>
                </div>

                <div>
                    <label :class="labelClass">Logo</label>
                    <div class="flex items-center gap-4">
                        <div v-if="logoPreview" class="w-16 h-16 rounded-xl bg-dark-800 border border-white/10 overflow-hidden flex items-center justify-center">
                            <img :src="logoPreview" alt="Logo" class="max-w-full max-h-full object-contain" />
                        </div>
                        <div v-else class="w-16 h-16 rounded-xl bg-dark-800 border border-white/10 flex items-center justify-center">
                            <svg class="w-6 h-6 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <label class="cursor-pointer px-4 py-2 rounded-xl bg-dark-800 border border-white/10 text-sm text-dark-300 hover:text-white hover:bg-dark-700 transition-colors">
                            Choose File
                            <input type="file" @change="handleLogoChange" accept="image/*" class="hidden" />
                        </label>
                    </div>
                </div>

                <div>
                    <label :class="labelClass">Favicon</label>
                    <div class="flex items-center gap-4">
                        <div v-if="faviconPreview" class="w-10 h-10 rounded-lg bg-dark-800 border border-white/10 overflow-hidden flex items-center justify-center">
                            <img :src="faviconPreview" alt="Favicon" class="max-w-full max-h-full object-contain" />
                        </div>
                        <div v-else class="w-10 h-10 rounded-lg bg-dark-800 border border-white/10 flex items-center justify-center">
                            <svg class="w-4 h-4 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <label class="cursor-pointer px-4 py-2 rounded-xl bg-dark-800 border border-white/10 text-sm text-dark-300 hover:text-white hover:bg-dark-700 transition-colors">
                            Choose File
                            <input type="file" @change="handleFaviconChange" accept="image/*" class="hidden" />
                        </label>
                    </div>
                </div>

                <div>
                    <label :class="labelClass">Primary Color</label>
                    <div class="flex items-center gap-3">
                        <input v-model="generalForm.primary_color" type="color" class="w-12 h-12 rounded-xl border border-dark-600 bg-dark-800 cursor-pointer" />
                        <input v-model="generalForm.primary_color" type="text" :class="inputClass" class="flex-1" placeholder="#0ea5e9" />
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="generalForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="generalForm.processing">Saving...</span>
                        <span v-else>Save General Settings</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- SEO Tab -->
        <div v-show="activeTab === 'seo'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">SEO Settings</h3>
            <form @submit.prevent="saveSeo" class="space-y-6 max-w-2xl">
                <div>
                    <label :class="labelClass">Meta Title</label>
                    <input v-model="seoForm.meta_title" type="text" :class="inputClass" placeholder="TPIX TRADE - Decentralized Exchange" />
                </div>

                <div>
                    <label :class="labelClass">Meta Description</label>
                    <textarea v-model="seoForm.meta_description" :class="inputClass" rows="3" placeholder="Trade across all major blockchains from one interface"></textarea>
                    <p class="mt-1 text-xs text-dark-500">{{ (seoForm.meta_description || '').length }}/160 characters</p>
                </div>

                <div>
                    <label :class="labelClass">OG Image</label>
                    <div class="flex items-center gap-4">
                        <div v-if="ogImagePreview" class="w-32 h-20 rounded-xl bg-dark-800 border border-white/10 overflow-hidden">
                            <img :src="ogImagePreview" alt="OG Image" class="w-full h-full object-cover" />
                        </div>
                        <label class="cursor-pointer px-4 py-2 rounded-xl bg-dark-800 border border-white/10 text-sm text-dark-300 hover:text-white hover:bg-dark-700 transition-colors">
                            Choose File
                            <input type="file" @change="handleOgImageChange" accept="image/*" class="hidden" />
                        </label>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="seoForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="seoForm.processing">Saving...</span>
                        <span v-else>Save SEO Settings</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Trading Tab -->
        <div v-show="activeTab === 'trading'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">Trading Settings</h3>
            <form @submit.prevent="saveTrading" class="space-y-6 max-w-2xl">
                <!-- Feature Toggles -->
                <div class="p-4 rounded-xl bg-dark-800/50 border border-white/10">
                    <h4 class="text-sm font-semibold text-white mb-4">Feature Toggles</h4>
                    <div class="flex flex-wrap gap-6">
                        <div class="flex items-center gap-3">
                            <label class="text-sm text-dark-300">🏦 Staking</label>
                            <button type="button" @click="tradingForm.staking_enabled = !tradingForm.staking_enabled"
                                :class="['w-12 h-6 rounded-full transition-colors', tradingForm.staking_enabled ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', tradingForm.staking_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span :class="['text-xs', tradingForm.staking_enabled ? 'text-trading-green' : 'text-dark-500']">{{ tradingForm.staking_enabled ? 'ON' : 'OFF' }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="text-sm text-dark-300">🌉 Bridge</label>
                            <button type="button" @click="tradingForm.bridge_enabled = !tradingForm.bridge_enabled"
                                :class="['w-12 h-6 rounded-full transition-colors', tradingForm.bridge_enabled ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', tradingForm.bridge_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span :class="['text-xs', tradingForm.bridge_enabled ? 'text-trading-green' : 'text-dark-500']">{{ tradingForm.bridge_enabled ? 'ON' : 'OFF' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Fee Collection Section -->
                <div class="p-4 rounded-xl bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5 border border-primary-500/10">
                    <h4 class="text-sm font-semibold text-primary-400 mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Fee Collection (DEX Revenue)
                    </h4>

                    <div class="space-y-4">
                        <div>
                            <label :class="labelClass">Fee Collector Wallet Address</label>
                            <input v-model="tradingForm.fee_collector_wallet" type="text" :class="inputClass" placeholder="0x..." />
                            <p class="mt-1 text-xs text-dark-500">EVM wallet address that receives all platform swap fees</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label :class="labelClass">Default Fee Rate (%)</label>
                                <input v-model.number="tradingForm.default_fee_rate" type="number" step="0.01" min="0" max="5" :class="inputClass" placeholder="0.3" />
                                <p class="mt-1 text-xs text-dark-500">Default: 0.3% per swap</p>
                            </div>
                            <div>
                                <label :class="labelClass">Max Fee Rate (%)</label>
                                <input v-model.number="tradingForm.max_fee_rate" type="number" step="0.1" min="0" max="10" :class="inputClass" placeholder="5.0" />
                                <p class="mt-1 text-xs text-dark-500">Maximum allowed fee cap</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slippage Section -->
                <div>
                    <label :class="labelClass">Default Slippage (%)</label>
                    <input v-model.number="tradingForm.default_slippage" type="number" step="0.1" min="0" :class="inputClass" placeholder="0.5" />
                    <p class="mt-1 text-xs text-dark-500">Default slippage tolerance for trades</p>
                </div>

                <div>
                    <label :class="labelClass">Max Slippage (%)</label>
                    <input v-model.number="tradingForm.max_slippage" type="number" step="0.1" min="0" :class="inputClass" placeholder="50" />
                    <p class="mt-1 text-xs text-dark-500">Maximum allowed slippage tolerance</p>
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="tradingForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="tradingForm.processing">Saving...</span>
                        <span v-else>Save Trading Settings</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Email Tab -->
        <div v-show="activeTab === 'email'" class="space-y-6">
            <!-- Flash messages -->
            <div v-if="flash.success && activeTab === 'email'" class="p-4 rounded-xl bg-trading-green/10 border border-trading-green/30 text-trading-green text-sm flex items-center gap-2">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ flash.success }}
            </div>
            <div v-if="flash.error && activeTab === 'email'" class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center gap-2">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ flash.error }}
            </div>

            <!-- Email Configuration -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                <h3 class="text-lg font-semibold text-white mb-2">Email Configuration</h3>
                <p class="text-dark-400 text-sm mb-6">ตั้งค่า Resend API สำหรับส่งอีเมลจากระบบ</p>

                <form @submit.prevent="saveEmail" class="space-y-6 max-w-2xl">
                    <div>
                        <label :class="labelClass">Resend API Key</label>
                        <input v-model="emailForm.resend_api_key" type="password" :class="inputClass" placeholder="re_xxxxxxxxxxxxxxxx" />
                        <p class="text-dark-500 text-xs mt-1">สมัครฟรีที่ <a href="https://resend.com" target="_blank" class="text-primary-400 hover:underline">resend.com</a> — ส่งได้ 3,000 เมล/เดือน (ฟรี)</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label :class="labelClass">From Email</label>
                            <input v-model="emailForm.mail_from_address" type="email" :class="inputClass" placeholder="noreply@xman4289.com" />
                            <p class="text-dark-500 text-xs mt-1">ต้องเป็น domain ที่ verified ใน Resend</p>
                        </div>
                        <div>
                            <label :class="labelClass">From Name</label>
                            <input v-model="emailForm.mail_from_name" type="text" :class="inputClass" placeholder="TPIX TRADE" />
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit" :disabled="emailForm.processing" class="btn-primary px-6 py-2.5">
                            <span v-if="emailForm.processing">Saving...</span>
                            <span v-else>Save Email Settings</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Send Test Email -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                <h3 class="text-lg font-semibold text-white mb-2">ทดสอบส่งอีเมล</h3>
                <p class="text-dark-400 text-sm mb-6">ส่งอีเมลทดสอบเพื่อตรวจสอบว่าระบบทำงานปกติ</p>

                <div class="flex items-end gap-3 max-w-2xl">
                    <div class="flex-1">
                        <label :class="labelClass">Email ปลายทาง</label>
                        <input v-model="testEmailAddress" type="email" :class="inputClass" placeholder="your@email.com" />
                    </div>
                    <button
                        @click="sendTestEmail"
                        :disabled="emailTestLoading || !testEmailAddress"
                        class="px-6 py-3 rounded-xl font-medium text-sm transition-all whitespace-nowrap"
                        :class="emailTestLoading || !testEmailAddress
                            ? 'bg-dark-700 text-dark-500 cursor-not-allowed'
                            : 'bg-gradient-to-r from-primary-500 to-accent-500 text-white hover:shadow-lg hover:shadow-primary-500/25'"
                    >
                        <span v-if="emailTestLoading" class="flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Sending...
                        </span>
                        <span v-else class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            Send Test Email
                        </span>
                    </button>
                </div>
            </div>

            <!-- Info Section -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
                <h3 class="text-lg font-semibold text-white mb-4">ข้อมูลระบบอีเมล</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex items-start gap-3 p-3 rounded-xl bg-dark-800/50">
                        <span class="text-primary-400 mt-0.5">📧</span>
                        <div>
                            <p class="text-white font-medium">Resend</p>
                            <p class="text-dark-400">ใช้ Resend API สำหรับส่งอีเมล — เร็ว เสถียร รองรับ SPF/DKIM</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 rounded-xl bg-dark-800/50">
                        <span class="text-trading-green mt-0.5">✅</span>
                        <div>
                            <p class="text-white font-medium">Domain ที่ Verified</p>
                            <p class="text-dark-400">xman4289.com — ส่งอีเมลได้ทันที ไม่ถูก spam</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 rounded-xl bg-dark-800/50">
                        <span class="text-yellow-400 mt-0.5">📊</span>
                        <div>
                            <p class="text-white font-medium">Free Plan</p>
                            <p class="text-dark-400">ส่งได้ 3,000 เมล/เดือน, 100 เมล/วัน — เพียงพอสำหรับ notification</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 rounded-xl bg-dark-800/50">
                        <span class="text-accent-400 mt-0.5">🎨</span>
                        <div>
                            <p class="text-white font-medium">Template</p>
                            <p class="text-dark-400">Dark theme แบบ TPIX TRADE พร้อมรองรับ Thai + responsive ทุกอุปกรณ์</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Tab -->
        <div v-show="activeTab === 'security'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">Security Settings</h3>
            <form @submit.prevent="saveSecurity" class="space-y-6 max-w-2xl">
                <!-- Turnstile Toggle -->
                <div>
                    <div class="flex items-center justify-between">
                        <div>
                            <label class="text-sm font-medium text-white">Cloudflare Turnstile</label>
                            <p class="text-xs text-dark-500 mt-0.5">Enable bot protection on login</p>
                        </div>
                        <button
                            type="button"
                            @click="securityForm.turnstile_enabled = !securityForm.turnstile_enabled"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                            :class="securityForm.turnstile_enabled ? 'bg-primary-500' : 'bg-dark-600'"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                :class="securityForm.turnstile_enabled ? 'translate-x-6' : 'translate-x-1'"
                            ></span>
                        </button>
                    </div>
                </div>

                <div v-if="securityForm.turnstile_enabled" class="space-y-4 pl-4 border-l-2 border-primary-500/30">
                    <div>
                        <label :class="labelClass">Site Key</label>
                        <input v-model="securityForm.turnstile_site_key" type="text" :class="inputClass" placeholder="0x4AAAAAAxxxxxxx" />
                    </div>
                    <div>
                        <label :class="labelClass">Secret Key</label>
                        <input v-model="securityForm.turnstile_secret_key" type="password" :class="inputClass" placeholder="0x4AAAAAAxxxxxxx" />
                    </div>
                </div>

                <div>
                    <label :class="labelClass">Max Login Attempts</label>
                    <input v-model.number="securityForm.max_login_attempts" type="number" min="1" :class="inputClass" placeholder="5" />
                </div>

                <div>
                    <label :class="labelClass">Lockout Duration (minutes)</label>
                    <input v-model.number="securityForm.lockout_duration" type="number" min="1" :class="inputClass" placeholder="15" />
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="securityForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="securityForm.processing">Saving...</span>
                        <span v-else>Save Security Settings</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Social Tab -->
        <div v-show="activeTab === 'social'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">Social Links</h3>
            <form @submit.prevent="saveSocial" class="space-y-6 max-w-2xl">
                <div>
                    <label :class="labelClass">Twitter / X</label>
                    <input v-model="socialForm.twitter_url" type="url" :class="inputClass" placeholder="https://twitter.com/tpixtrade" />
                </div>
                <div>
                    <label :class="labelClass">Telegram</label>
                    <input v-model="socialForm.telegram_url" type="url" :class="inputClass" placeholder="https://t.me/tpixtrade" />
                </div>
                <div>
                    <label :class="labelClass">Discord</label>
                    <input v-model="socialForm.discord_url" type="url" :class="inputClass" placeholder="https://discord.gg/tpixtrade" />
                </div>
                <div>
                    <label :class="labelClass">GitHub</label>
                    <input v-model="socialForm.github_url" type="url" :class="inputClass" placeholder="https://github.com/tpixtrade" />
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="socialForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="socialForm.processing">Saving...</span>
                        <span v-else>Save Social Links</span>
                    </button>
                </div>
            </form>
        </div>
        <!-- Payment (Stripe) Tab -->
        <div v-show="activeTab === 'payment'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">💳 Payment Settings (Stripe)</h3>
            <form @submit.prevent="savePayment" class="space-y-6 max-w-2xl">
                <div class="flex items-center gap-3 mb-4">
                    <label class="text-sm text-dark-300">เปิดใช้ Stripe</label>
                    <button type="button" @click="paymentForm.stripe_enabled = !paymentForm.stripe_enabled"
                        :class="['w-12 h-6 rounded-full transition-colors', paymentForm.stripe_enabled ? 'bg-primary-500' : 'bg-dark-600']">
                        <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', paymentForm.stripe_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                    </button>
                </div>
                <div>
                    <label :class="labelClass">Stripe Publishable Key</label>
                    <input v-model="paymentForm.stripe_public_key" type="text" :class="inputClass" placeholder="pk_live_..." />
                    <p class="text-dark-500 text-xs mt-1">ดูได้ที่ https://dashboard.stripe.com/apikeys</p>
                </div>
                <div>
                    <label :class="labelClass">Stripe Secret Key</label>
                    <input v-model="paymentForm.stripe_secret_key" type="password" :class="inputClass" placeholder="sk_live_..." />
                </div>
                <div>
                    <label :class="labelClass">Stripe Webhook Secret</label>
                    <input v-model="paymentForm.stripe_webhook_secret" type="password" :class="inputClass" placeholder="whsec_..." />
                    <p class="text-dark-500 text-xs mt-1">Webhook endpoint: {{ $page.props.appUrl || '' }}/api/v1/stripe/webhook</p>
                </div>
                <div class="pt-4">
                    <button type="submit" :disabled="paymentForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="paymentForm.processing">Saving...</span>
                        <span v-else>Save Payment Settings</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- AI Tab -->
        <div v-show="activeTab === 'ai'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">🤖 AI Settings (Groq)</h3>
            <form @submit.prevent="saveAi" class="space-y-6 max-w-2xl">
                <div>
                    <label :class="labelClass">Groq API Key</label>
                    <input v-model="aiForm.groq_api_key" type="password" :class="inputClass" placeholder="gsk_..." />
                    <p class="text-dark-500 text-xs mt-1">สมัครฟรีที่ https://console.groq.com/keys</p>
                </div>
                <div>
                    <label :class="labelClass">Default AI Model</label>
                    <select v-model="aiForm.groq_default_model" :class="inputClass">
                        <option value="llama-3.3-70b-versatile">Llama 3.3 70B (แนะนำ)</option>
                        <option value="llama-3.1-8b-instant">Llama 3.1 8B (เร็ว)</option>
                        <option value="mixtral-8x7b-32768">Mixtral 8x7B</option>
                        <option value="gemma2-9b-it">Gemma 2 9B</option>
                    </select>
                </div>
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-3">
                        <label class="text-sm text-dark-300">AI Chatbot</label>
                        <button type="button" @click="aiForm.ai_chatbot_enabled = !aiForm.ai_chatbot_enabled"
                            :class="['w-12 h-6 rounded-full transition-colors', aiForm.ai_chatbot_enabled ? 'bg-primary-500' : 'bg-dark-600']">
                            <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', aiForm.ai_chatbot_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="text-sm text-dark-300">AI Content</label>
                        <button type="button" @click="aiForm.ai_content_enabled = !aiForm.ai_content_enabled"
                            :class="['w-12 h-6 rounded-full transition-colors', aiForm.ai_content_enabled ? 'bg-primary-500' : 'bg-dark-600']">
                            <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', aiForm.ai_content_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                        </button>
                    </div>
                </div>
                <div class="pt-4">
                    <button type="submit" :disabled="aiForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="aiForm.processing">Saving...</span>
                        <span v-else>Save AI Settings</span>
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
