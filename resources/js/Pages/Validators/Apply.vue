<script setup>
/**
 * TPIX Validator Application — Apply to become a validator
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';

const walletStore = useWalletStore();

// Form state
const form = ref({
    wallet_address: '',
    tier: '',
    endpoint: '',
    country_code: '',
    country_name: '',
    latitude: '',
    longitude: '',
    contact_email: '',
    contact_telegram: '',
    hardware_cpu: '',
    hardware_ram: '',
    hardware_ssd: '',
    motivation: '',
});

const isSubmitting = ref(false);
const submitError = ref('');
const submitSuccess = ref(false);
const errors = ref({});

// Auto-fill from connected wallet
if (walletStore.isConnected && walletStore.address) {
    form.value.wallet_address = walletStore.address;
}

// Tiers
const tiers = [
    {
        id: 'validator', name: 'Validator Node',
        stake: '10,000,000 TPIX', apy: '15-20%', lock: '180 days',
        hardware: '16 CPU / 32GB RAM / 1TB SSD',
        color: 'border-red-500/40 bg-red-500/10', accent: 'text-red-400',
        note: 'Company KYC required (PDPA-compliant)',
    },
    {
        id: 'guardian', name: 'Guardian Node',
        stake: '1,000,000 TPIX', apy: '10-12%', lock: '90 days',
        hardware: '8 CPU / 16GB RAM / 500GB SSD',
        color: 'border-yellow-500/40 bg-yellow-500/10', accent: 'text-yellow-400',
    },
    {
        id: 'sentinel', name: 'Sentinel Node',
        stake: '100,000 TPIX', apy: '7-9%', lock: '30 days',
        hardware: '4 CPU / 8GB RAM / 200GB SSD',
        color: 'border-purple-500/40 bg-purple-500/10', accent: 'text-purple-400',
    },
    {
        id: 'light', name: 'Light Node',
        stake: '10,000 TPIX', apy: '4-6%', lock: '7 days',
        hardware: '2 CPU / 4GB RAM / 100GB SSD',
        color: 'border-cyan-500/40 bg-cyan-500/10', accent: 'text-cyan-400',
    },
];

// Country list (top countries + common ones)
const countries = [
    { code: 'TH', name: 'Thailand', lat: 13.7563, lng: 100.5018 },
    { code: 'US', name: 'United States', lat: 37.0902, lng: -95.7129 },
    { code: 'JP', name: 'Japan', lat: 36.2048, lng: 138.2529 },
    { code: 'KR', name: 'South Korea', lat: 35.9078, lng: 127.7669 },
    { code: 'SG', name: 'Singapore', lat: 1.3521, lng: 103.8198 },
    { code: 'DE', name: 'Germany', lat: 51.1657, lng: 10.4515 },
    { code: 'GB', name: 'United Kingdom', lat: 55.3781, lng: -3.4360 },
    { code: 'NL', name: 'Netherlands', lat: 52.1326, lng: 5.2913 },
    { code: 'CA', name: 'Canada', lat: 56.1304, lng: -106.3468 },
    { code: 'AU', name: 'Australia', lat: -25.2744, lng: 133.7751 },
    { code: 'FR', name: 'France', lat: 46.2276, lng: 2.2137 },
    { code: 'CH', name: 'Switzerland', lat: 46.8182, lng: 8.2275 },
    { code: 'HK', name: 'Hong Kong', lat: 22.3193, lng: 114.1694 },
    { code: 'IN', name: 'India', lat: 20.5937, lng: 78.9629 },
    { code: 'BR', name: 'Brazil', lat: -14.2350, lng: -51.9253 },
    { code: 'AE', name: 'UAE', lat: 23.4241, lng: 53.8478 },
    { code: 'VN', name: 'Vietnam', lat: 14.0583, lng: 108.2772 },
    { code: 'PH', name: 'Philippines', lat: 12.8797, lng: 121.7740 },
    { code: 'MY', name: 'Malaysia', lat: 4.2105, lng: 101.9758 },
    { code: 'ID', name: 'Indonesia', lat: -0.7893, lng: 113.9213 },
    { code: 'TW', name: 'Taiwan', lat: 23.6978, lng: 120.9605 },
    { code: 'FI', name: 'Finland', lat: 61.9241, lng: 25.7482 },
    { code: 'SE', name: 'Sweden', lat: 60.1282, lng: 18.6435 },
    { code: 'NO', name: 'Norway', lat: 60.4720, lng: 8.4689 },
].sort((a, b) => a.name.localeCompare(b.name));

function flag(code) {
    if (!code || code.length !== 2) return '🌐';
    return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
}

// Auto-fill coords when country changes
function onCountryChange() {
    const c = countries.find(c => c.code === form.value.country_code);
    if (c) {
        form.value.country_name = c.name;
        if (!form.value.latitude) form.value.latitude = c.lat;
        if (!form.value.longitude) form.value.longitude = c.lng;
    }
}

// Validation
function validate() {
    const e = {};
    if (!/^0x[a-fA-F0-9]{40}$/.test(form.value.wallet_address)) {
        e.wallet_address = 'Valid Ethereum address required (0x...)';
    }
    if (!form.value.tier) e.tier = 'Please select a node tier';
    if (!form.value.country_code) e.country_code = 'Please select a country';
    if (form.value.contact_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.contact_email)) {
        e.contact_email = 'Invalid email format';
    }
    errors.value = e;
    return Object.keys(e).length === 0;
}

async function submitApplication() {
    if (!validate()) return;
    isSubmitting.value = true;
    submitError.value = '';
    try {
        const payload = {
            ...form.value,
            hardware_specs: JSON.stringify({
                cpu: form.value.hardware_cpu,
                ram: form.value.hardware_ram,
                ssd: form.value.hardware_ssd,
            }),
        };
        const resp = await fetch('/api/v1/validators/apply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();
        if (data.success) {
            submitSuccess.value = true;
        } else {
            if (data.errors) {
                errors.value = data.errors;
            } else {
                submitError.value = data.error?.message || 'Submission failed. Please try again.';
            }
        }
    } catch {
        submitError.value = 'Network error. Please try again.';
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="Apply to Validate — TPIX Chain" />
    <AppLayout>
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

            <!-- Back -->
            <Link href="/validators" class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-white transition-colors">
                ← Back to Validator Network
            </Link>

            <!-- Header -->
            <div>
                <h1 class="text-3xl font-black text-white">Apply to Validate</h1>
                <p class="text-sm text-gray-400 mt-1">
                    Submit your application to join the TPIX Chain validator network. Applications are reviewed by the admin team.
                </p>
            </div>

            <!-- Success -->
            <div v-if="submitSuccess" class="glass rounded-2xl p-8 text-center space-y-4">
                <div class="text-5xl">✅</div>
                <h2 class="text-xl font-bold text-emerald-400">Application Submitted!</h2>
                <p class="text-sm text-gray-400 max-w-md mx-auto">
                    Your validator application has been received. The admin team will review it and you'll be notified of the result.
                    Make sure your node is running and synced before approval.
                </p>
                <div class="flex justify-center gap-3 mt-4">
                    <Link href="/validators" class="px-5 py-2.5 rounded-xl text-sm font-bold bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                        View Network
                    </Link>
                    <Link href="/masternode/guide" class="px-5 py-2.5 rounded-xl text-sm font-bold bg-cyan-500/20 text-cyan-400 border border-cyan-500/30 hover:bg-cyan-500/30 transition-all">
                        Setup Guide
                    </Link>
                </div>
            </div>

            <!-- Form -->
            <form v-else @submit.prevent="submitApplication" class="space-y-6">

                <!-- Step 1: Choose Tier -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">1. Choose Node Tier</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <button v-for="tier in tiers" :key="tier.id" type="button"
                            @click="form.tier = tier.id"
                            :class="['p-4 rounded-xl border-2 text-left transition-all',
                                form.tier === tier.id
                                    ? tier.color + ' ring-2 ring-offset-2 ring-offset-dark-900 ' + (tier.id === 'validator' ? 'ring-red-500' : tier.id === 'guardian' ? 'ring-yellow-500' : tier.id === 'sentinel' ? 'ring-purple-500' : 'ring-cyan-500')
                                    : 'border-white/10 bg-white/[0.02] hover:bg-white/[0.05]']">
                            <div :class="['font-bold text-sm mb-1', tier.accent]">{{ tier.name }}</div>
                            <div class="text-[10px] text-gray-400 space-y-0.5">
                                <div>Stake: <span class="text-white">{{ tier.stake }}</span></div>
                                <div>APY: <span class="text-white">{{ tier.apy }}</span></div>
                                <div>Lock: <span class="text-white">{{ tier.lock }}</span></div>
                                <div>Min HW: {{ tier.hardware }}</div>
                                <div v-if="tier.note" class="text-red-300 mt-1">{{ tier.note }}</div>
                            </div>
                        </button>
                    </div>
                    <p v-if="errors.tier" class="text-xs text-red-400">{{ errors.tier }}</p>
                </div>

                <!-- Step 2: Wallet & Node Info -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">2. Wallet & Node Info</h3>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1.5">Wallet Address *</label>
                        <input v-model="form.wallet_address" type="text" placeholder="0x..."
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        <p v-if="errors.wallet_address" class="text-xs text-red-400 mt-1">{{ errors.wallet_address }}</p>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1.5">Node RPC Endpoint (optional)</label>
                        <input v-model="form.endpoint" type="text" placeholder="https://your-node:8545"
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                    </div>
                </div>

                <!-- Step 3: Location -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">3. Node Location</h3>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1.5">Country *</label>
                        <select v-model="form.country_code" @change="onCountryChange"
                            class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none">
                            <option value="">Select country...</option>
                            <option v-for="c in countries" :key="c.code" :value="c.code">
                                {{ flag(c.code) }} {{ c.name }}
                            </option>
                        </select>
                        <p v-if="errors.country_code" class="text-xs text-red-400 mt-1">{{ errors.country_code }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Latitude</label>
                            <input v-model="form.latitude" type="number" step="any" placeholder="13.7563"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Longitude</label>
                            <input v-model="form.longitude" type="number" step="any" placeholder="100.5018"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        </div>
                    </div>
                </div>

                <!-- Step 4: Hardware -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">4. Hardware Specs (optional)</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">CPU Cores</label>
                            <input v-model="form.hardware_cpu" type="text" placeholder="8"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">RAM (GB)</label>
                            <input v-model="form.hardware_ram" type="text" placeholder="16"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">SSD (GB)</label>
                            <input v-model="form.hardware_ssd" type="text" placeholder="500"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        </div>
                    </div>
                </div>

                <!-- Step 5: Contact -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">5. Contact Info (optional)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Email</label>
                            <input v-model="form.contact_email" type="email" placeholder="you@example.com"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                            <p v-if="errors.contact_email" class="text-xs text-red-400 mt-1">{{ errors.contact_email }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Telegram</label>
                            <input v-model="form.contact_telegram" type="text" placeholder="@username"
                                class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        </div>
                    </div>
                </div>

                <!-- Step 6: Motivation -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">6. Motivation (optional)</h3>
                    <textarea v-model="form.motivation" rows="4" placeholder="Why do you want to run a TPIX validator? Tell us about your experience..."
                        class="w-full bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 resize-none"></textarea>
                </div>

                <!-- Error -->
                <div v-if="submitError" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                    {{ submitError }}
                </div>

                <!-- Submit -->
                <button type="submit" :disabled="isSubmitting"
                    class="w-full py-4 rounded-2xl text-base font-bold bg-gradient-to-r from-yellow-500/80 to-amber-500/80 text-black hover:from-yellow-500 hover:to-amber-500 transition-all hover:scale-[1.02] disabled:opacity-50 disabled:hover:scale-100">
                    {{ isSubmitting ? 'Submitting...' : 'Submit Validator Application' }}
                </button>
            </form>
        </div>
    </AppLayout>
</template>
