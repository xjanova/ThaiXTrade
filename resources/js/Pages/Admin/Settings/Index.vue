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
    { key: 'factory', label: 'Token Factory', icon: 'settings' },
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
    masternode_enabled: props.settings.masternode_enabled ?? true,
    bridge_enabled: props.settings.bridge_enabled ?? true,
});

const saveTrading = () => {
    tradingForm.put('/admin/settings/trading', { preserveScroll: true });
};

// Factory form
const factoryForm = useForm({
    creation_enabled: props.settings.creation_enabled ?? true,
    auto_approve: props.settings.auto_approve ?? false,
    nft_enabled: props.settings.nft_enabled ?? true,
    fee_wallet: props.settings.fee_wallet || '',
    creation_fee_tpix: props.settings.creation_fee_tpix || 100,
    creation_fee_usd: props.settings.creation_fee_usd || 10,
    fee_payment_method: props.settings.fee_payment_method || 'tpix',
    max_supply_limit: props.settings.max_supply_limit || 999999999999999,
});

const saveFactory = () => {
    factoryForm.put('/admin/settings/factory', { preserveScroll: true });
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
    cloudflare_image_url: props.settings.cloudflare_image_url || 'https://tpix-image-gen.xjanovax.workers.dev/',
    cloudflare_image_key: props.settings.cloudflare_image_key || '',
    together_api_key: props.settings.together_api_key || '',
    huggingface_api_key: props.settings.huggingface_api_key || '',
    gemini_api_key: props.settings.gemini_api_key || '',
});

const saveAi = () => {
    aiForm.put('/admin/settings/ai', { preserveScroll: true });
};

// Content Schedule form (group: content)
const contentForm = useForm({
    content_auto_enabled: props.settings.content_auto_enabled || false,
    content_max_per_day: props.settings.content_max_per_day || 2,
    content_time_slots: props.settings.content_time_slots || '["09:00","18:00"]',
    content_categories: props.settings.content_categories || '["tpix_chain","defi","news"]',
    content_language: props.settings.content_language || 'th',
    content_auto_publish: props.settings.content_auto_publish || false,
});

const saveContent = () => {
    contentForm.put('/admin/settings/content', { preserveScroll: true });
};

const timeSlots = ref(JSON.parse(contentForm.content_time_slots || '["09:00"]'));
const newTimeSlot = ref('');

function addTimeSlot() {
    if (newTimeSlot.value && !timeSlots.value.includes(newTimeSlot.value)) {
        timeSlots.value.push(newTimeSlot.value);
        timeSlots.value.sort();
        contentForm.content_time_slots = JSON.stringify(timeSlots.value);
    }
    newTimeSlot.value = '';
}

function removeTimeSlot(index) {
    timeSlots.value.splice(index, 1);
    contentForm.content_time_slots = JSON.stringify(timeSlots.value);
}

const selectedCategories = ref(JSON.parse(contentForm.content_categories || '[]'));
const allCategories = [
    { value: 'tpix_chain', label: 'TPIX Chain' },
    { value: 'defi', label: 'DeFi' },
    { value: 'news', label: 'News' },
    { value: 'analysis', label: 'Analysis' },
    { value: 'tutorial', label: 'Tutorial' },
    { value: 'technology', label: 'Technology' },
];

function toggleCategory(cat) {
    const idx = selectedCategories.value.indexOf(cat);
    if (idx >= 0) selectedCategories.value.splice(idx, 1);
    else selectedCategories.value.push(cat);
    contentForm.content_categories = JSON.stringify(selectedCategories.value);
}

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
                            <label class="text-sm text-dark-300">🛡️ Master Node</label>
                            <button type="button" @click="tradingForm.masternode_enabled = !tradingForm.masternode_enabled"
                                :class="['w-12 h-6 rounded-full transition-colors', tradingForm.masternode_enabled ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', tradingForm.masternode_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span :class="['text-xs', tradingForm.masternode_enabled ? 'text-trading-green' : 'text-dark-500']">{{ tradingForm.masternode_enabled ? 'ON' : 'OFF' }}</span>
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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

        <!-- Factory Tab -->
        <div v-show="activeTab === 'factory'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-white mb-6">Token Factory Settings</h3>
            <form @submit.prevent="saveFactory" class="space-y-6 max-w-2xl">
                <!-- Feature Toggles -->
                <div class="p-4 rounded-xl bg-dark-800/50 border border-white/10">
                    <h4 class="text-sm font-semibold text-white mb-4">Feature Toggles</h4>
                    <div class="flex flex-wrap gap-6">
                        <div class="flex items-center gap-3">
                            <label class="text-sm text-dark-300">Token Creation</label>
                            <button type="button" @click="factoryForm.creation_enabled = !factoryForm.creation_enabled"
                                :class="['w-12 h-6 rounded-full transition-colors', factoryForm.creation_enabled ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', factoryForm.creation_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span :class="['text-xs', factoryForm.creation_enabled ? 'text-trading-green' : 'text-dark-500']">{{ factoryForm.creation_enabled ? 'ON' : 'OFF' }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="text-sm text-dark-300">Auto-Approve</label>
                            <button type="button" @click="factoryForm.auto_approve = !factoryForm.auto_approve"
                                :class="['w-12 h-6 rounded-full transition-colors', factoryForm.auto_approve ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', factoryForm.auto_approve ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span :class="['text-xs', factoryForm.auto_approve ? 'text-trading-green' : 'text-dark-500']">{{ factoryForm.auto_approve ? 'ON' : 'OFF' }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="text-sm text-dark-300">NFT Creation</label>
                            <button type="button" @click="factoryForm.nft_enabled = !factoryForm.nft_enabled"
                                :class="['w-12 h-6 rounded-full transition-colors', factoryForm.nft_enabled ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', factoryForm.nft_enabled ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span :class="['text-xs', factoryForm.nft_enabled ? 'text-trading-green' : 'text-dark-500']">{{ factoryForm.nft_enabled ? 'ON' : 'OFF' }}</span>
                        </div>
                    </div>
                    <p v-if="factoryForm.auto_approve" class="mt-3 text-xs text-yellow-400 bg-yellow-500/10 p-2 rounded-lg">Auto-approve only applies to standard fungible tokens (standard, mintable, burnable). Governance, stablecoin, and NFT always require manual review.</p>
                </div>

                <!-- Fee Configuration -->
                <div class="p-4 rounded-xl bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5 border border-primary-500/10">
                    <h4 class="text-sm font-semibold text-primary-400 mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Fee Configuration
                    </h4>
                    <div class="space-y-4">
                        <div>
                            <label :class="labelClass">Fee Wallet Address</label>
                            <input v-model="factoryForm.fee_wallet" type="text" :class="inputClass" placeholder="0x..." />
                            <p class="mt-1 text-xs text-dark-500">EVM wallet that receives token creation fees</p>
                        </div>
                        <div>
                            <label :class="labelClass">Payment Method</label>
                            <select v-model="factoryForm.fee_payment_method" :class="inputClass">
                                <option value="tpix">TPIX Token</option>
                                <option value="usd">USD (Stripe)</option>
                                <option value="free">Free (No Fee)</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label :class="labelClass">Fee (TPIX)</label>
                                <input v-model.number="factoryForm.creation_fee_tpix" type="number" step="1" min="0" :class="inputClass" placeholder="100" />
                            </div>
                            <div>
                                <label :class="labelClass">Fee (USD)</label>
                                <input v-model.number="factoryForm.creation_fee_usd" type="number" step="0.01" min="0" :class="inputClass" placeholder="10" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Limits -->
                <div>
                    <label :class="labelClass">Max Supply Limit</label>
                    <input v-model.number="factoryForm.max_supply_limit" type="number" min="1" :class="inputClass" placeholder="999999999999999" />
                    <p class="mt-1 text-xs text-dark-500">Maximum total supply a user can set when creating a token</p>
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="factoryForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="factoryForm.processing">Saving...</span>
                        <span v-else>Save Factory Settings</span>
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
                <!-- Image Generation API Keys -->
                <div class="p-4 rounded-xl bg-gradient-to-br from-accent-500/5 via-primary-500/5 to-warm-500/5 border border-primary-500/10">
                    <h4 class="text-sm font-semibold text-primary-400 mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        AI Image Generation
                    </h4>
                    <p class="text-dark-500 text-xs mb-4">ไม่จำเป็นต้องใส่ — ระบบจะใช้ Pollinations.ai (ฟรี) เป็น fallback อัตโนมัติ</p>

                    <div class="space-y-4">
                        <div>
                            <label :class="labelClass">Cloudflare Worker URL</label>
                            <input v-model="aiForm.cloudflare_image_url" type="text" :class="inputClass" placeholder="https://tpix-image-gen.xjanovax.workers.dev/" />
                            <p class="text-dark-500 text-xs mt-1">FLUX.1 Schnell via Cloudflare Workers AI — เร็วที่สุด ฟรี</p>
                        </div>
                        <div>
                            <label :class="labelClass">Cloudflare Worker API Key</label>
                            <input v-model="aiForm.cloudflare_image_key" type="password" :class="inputClass" placeholder="X-API-Key สำหรับ Worker" />
                            <p class="text-dark-500 text-xs mt-1">ตั้งค่าใน Worker Settings → Variables and Secrets</p>
                        </div>
                        <hr class="border-white/5" />
                        <div>
                            <label :class="labelClass">Together.ai API Key (FLUX)</label>
                            <input v-model="aiForm.together_api_key" type="password" :class="inputClass" placeholder="(optional)" />
                            <p class="text-dark-500 text-xs mt-1">FLUX.1 Schnell — คุณภาพสูง สมัครฟรีที่ together.ai</p>
                        </div>
                        <div>
                            <label :class="labelClass">Hugging Face API Key</label>
                            <input v-model="aiForm.huggingface_api_key" type="password" :class="inputClass" placeholder="hf_... (optional)" />
                            <p class="text-dark-500 text-xs mt-1">SDXL-Lightning — เร็ว สมัครฟรีที่ huggingface.co</p>
                        </div>
                        <div>
                            <label :class="labelClass">Google Gemini API Key</label>
                            <input v-model="aiForm.gemini_api_key" type="password" :class="inputClass" placeholder="(optional)" />
                            <p class="text-dark-500 text-xs mt-1">Imagen — 500 รูป/วัน ฟรี สมัครที่ aistudio.google.com</p>
                        </div>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" :disabled="aiForm.processing" class="btn-primary px-6 py-2.5">
                        <span v-if="aiForm.processing">Saving...</span>
                        <span v-else>Save AI Settings</span>
                    </button>
                </div>
            </form>

            <!-- Content Auto-Generation Schedule -->
            <div class="mt-6 p-5 rounded-xl bg-gradient-to-br from-trading-green/5 via-primary-500/5 to-accent-500/5 border border-trading-green/10">
                <h4 class="text-base font-semibold text-white mb-1 flex items-center gap-2">
                    <svg class="w-5 h-5 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Auto Content Schedule
                </h4>
                <p class="text-dark-500 text-xs mb-4">ตั้งเวลาสร้างบทความ AI อัตโนมัติ — ระบบจะสร้าง + gen รูปให้เอง</p>

                <form @submit.prevent="saveContent" class="space-y-5 max-w-2xl">
                    <!-- Enable Toggle -->
                    <div class="flex items-center justify-between">
                        <div>
                            <label class="text-sm font-medium text-white">เปิดใช้ Auto Content</label>
                            <p class="text-xs text-dark-500 mt-0.5">สร้างบทความ AI อัตโนมัติตามเวลาที่ตั้ง</p>
                        </div>
                        <button type="button" @click="contentForm.content_auto_enabled = !contentForm.content_auto_enabled"
                            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', contentForm.content_auto_enabled ? 'bg-trading-green' : 'bg-dark-600']">
                            <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform', contentForm.content_auto_enabled ? 'translate-x-6' : 'translate-x-1']"></span>
                        </button>
                    </div>

                    <div v-if="contentForm.content_auto_enabled" class="space-y-4 pl-4 border-l-2 border-trading-green/30">
                        <!-- Time Slots -->
                        <div>
                            <label :class="labelClass">ช่วงเวลาสร้าง</label>
                            <div class="flex flex-wrap gap-2 mb-2">
                                <span v-for="(slot, i) in timeSlots" :key="i"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-trading-green/10 text-trading-green rounded-lg text-sm">
                                    {{ slot }}
                                    <button type="button" @click="removeTimeSlot(i)" class="hover:text-white ml-1">&times;</button>
                                </span>
                                <span v-if="!timeSlots.length" class="text-xs text-dark-500">ยังไม่มี</span>
                            </div>
                            <div class="flex gap-2">
                                <input v-model="newTimeSlot" type="time" :class="inputClass" class="!w-40" />
                                <button type="button" @click="addTimeSlot" class="px-3 py-2 rounded-xl bg-dark-700 text-white text-sm border border-white/10 hover:bg-dark-600">+</button>
                            </div>
                        </div>

                        <!-- Max per day -->
                        <div>
                            <label :class="labelClass">จำนวนสูงสุดต่อวัน</label>
                            <input v-model.number="contentForm.content_max_per_day" type="number" min="1" max="10" :class="inputClass" class="!w-32" />
                        </div>

                        <!-- Categories -->
                        <div>
                            <label :class="labelClass">หมวดหมู่ที่สร้าง</label>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="cat in allCategories" :key="cat.value" type="button" @click="toggleCategory(cat.value)"
                                    :class="['px-3 py-1.5 rounded-xl text-xs transition-all border', selectedCategories.includes(cat.value) ? 'bg-primary-500/10 text-primary-400 border-primary-500/30' : 'bg-dark-800/50 text-dark-400 border-white/5 hover:border-white/20']">
                                    {{ cat.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Language -->
                        <div>
                            <label :class="labelClass">ภาษา</label>
                            <select v-model="contentForm.content_language" :class="inputClass" class="!w-48">
                                <option value="th">ไทย</option>
                                <option value="en">English</option>
                                <option value="both">ทั้งสองภาษา</option>
                            </select>
                        </div>

                        <!-- Auto Publish -->
                        <div class="flex items-center gap-3">
                            <label class="text-sm text-dark-300">Publish ทันที</label>
                            <button type="button" @click="contentForm.content_auto_publish = !contentForm.content_auto_publish"
                                :class="['w-12 h-6 rounded-full transition-colors', contentForm.content_auto_publish ? 'bg-trading-green' : 'bg-dark-600']">
                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', contentForm.content_auto_publish ? 'translate-x-6' : 'translate-x-0.5']"></div>
                            </button>
                            <span class="text-xs text-dark-500">{{ contentForm.content_auto_publish ? 'Publish ทันทีเมื่อสร้างเสร็จ' : 'บันทึกเป็น Draft (ตรวจก่อน publish)' }}</span>
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit" :disabled="contentForm.processing" class="btn-primary px-6 py-2.5">
                            <span v-if="contentForm.processing">Saving...</span>
                            <span v-else>Save Content Schedule</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
