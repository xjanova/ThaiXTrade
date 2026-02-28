<script setup>
/**
 * TPIX TRADE - Admin Settings Page
 * Tabbed settings management: General, SEO, Trading, Security, Social
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

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
    { key: 'security', label: 'Security', icon: 'shield' },
    { key: 'social', label: 'Social', icon: 'share' },
];

// General form
const generalForm = useForm({
    site_name: props.settings.site_name || 'TPIX TRADE',
    site_description: props.settings.site_description || '',
    logo: null,
    favicon: null,
    primary_color: props.settings.primary_color || '#0ea5e9',
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

const saveGeneral = () => {
    generalForm.post('/admin/settings/general', {
        preserveScroll: true,
        forceFormData: true,
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
    </AdminLayout>
</template>
