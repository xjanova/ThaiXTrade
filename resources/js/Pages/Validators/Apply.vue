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

// Tiers — gradient/glow/border styling mirrors MasterNode tier cards
const tiers = [
    {
        id: 'validator', name: 'Validator Node',
        stake: '10,000,000 TPIX', apy: '15-20%', lock: '180 days',
        hardware: '16 CPU / 32GB RAM / 1TB SSD',
        color: 'border-red-500/40 bg-red-500/10', accent: 'text-red-400',
        gradient: 'from-red-500/30 via-rose-500/20 to-pink-500/10',
        border: 'border-red-500/30',
        glow: 'shadow-[0_0_40px_rgba(239,68,68,0.15)]',
        badge: 'bg-red-500/20 text-red-300 border-red-500/40',
        ring: 'ring-red-500/40',
        note: 'Company KYC required (PDPA-compliant)',
    },
    {
        id: 'guardian', name: 'Guardian Node',
        stake: '1,000,000 TPIX', apy: '10-12%', lock: '90 days',
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
        stake: '100,000 TPIX', apy: '7-9%', lock: '30 days',
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
        stake: '10,000 TPIX', apy: '4-6%', lock: '7 days',
        hardware: '2 CPU / 4GB RAM / 100GB SSD',
        color: 'border-cyan-500/40 bg-cyan-500/10', accent: 'text-cyan-400',
        gradient: 'from-cyan-500/30 via-blue-500/20 to-teal-500/10',
        border: 'border-cyan-500/30',
        glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        badge: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',
        ring: 'ring-cyan-500/40',
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
                    <span aria-hidden="true">&larr;</span> Back to Validator Network
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
                            <img src="/tpixlogo.webp" alt="TPIX" class="relative w-20 h-20 ring-2 ring-white/10" />
                        </div>

                        <h1 class="text-3xl md:text-4xl font-black mb-3">
                            <span class="text-gradient-brand">Apply to Validate</span>
                        </h1>
                        <p class="text-sm text-gray-400 max-w-lg mx-auto">
                            Submit your application to join the TPIX Chain validator network. Applications are reviewed by the admin team.
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
                        <h2 class="text-2xl font-black text-green-400">Application Submitted!</h2>
                        <p class="text-sm text-gray-400 max-w-md mx-auto">
                            Your validator application has been received. The admin team will review it and you'll be notified of the result.
                            Make sure your node is running and synced before approval.
                        </p>
                        <div class="flex justify-center gap-3 flex-wrap pt-2">
                            <Link href="/validators"
                                  class="btn-secondary px-6 py-2.5 rounded-xl font-bold text-sm">
                                View Network
                            </Link>
                            <Link href="/masternode/guide"
                                  class="btn-brand px-6 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                                Setup Guide
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
                                <h3 class="text-base font-bold text-white">Choose Node Tier</h3>
                                <p class="text-xs text-gray-400">Pick the tier that matches your stake and hardware</p>
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
                                            <img src="/tpixlogo.webp" alt="TPIX" class="relative w-9 h-9 ring-1 ring-white/10" />
                                        </div>
                                        <div :class="['px-2 py-0.5 rounded-full text-[10px] font-black border', tier.badge]">
                                            {{ tier.apy }}
                                        </div>
                                    </div>
                                    <div :class="['font-bold text-sm mb-2', tier.accent]">{{ tier.name }}</div>
                                    <div class="text-[10px] text-gray-400 space-y-1">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Stake</span>
                                            <span :class="['font-bold', tier.accent]">{{ tier.stake }}</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Lock</span>
                                            <span class="text-gray-300">{{ tier.lock }}</span>
                                        </div>
                                        <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                        <div class="text-[10px] text-gray-500 leading-snug pt-1">{{ tier.hardware }}</div>
                                        <div v-if="tier.note" class="text-red-300 mt-1 text-[10px]">{{ tier.note }}</div>
                                    </div>
                                </div>
                            </button>
                        </div>
                        <p v-if="errors.tier" class="text-xs text-red-400">{{ errors.tier }}</p>
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
                                <h3 class="text-base font-bold text-white">Wallet &amp; Node Info</h3>
                                <p class="text-xs text-gray-400">The address that will receive rewards</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Wallet Address *</label>
                            <input v-model="form.wallet_address" type="text" placeholder="0x..."
                                class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            <p v-if="errors.wallet_address" class="text-xs text-red-400 mt-1">{{ errors.wallet_address }}</p>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Node RPC Endpoint (optional)</label>
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
                                <h3 class="text-base font-bold text-white">Node Location</h3>
                                <p class="text-xs text-gray-400">Shown as a dot on the global map</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-400 mb-1.5">Country *</label>
                            <select v-model="form.country_code" @change="onCountryChange"
                                class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none backdrop-blur-sm">
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
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">Longitude</label>
                                <input v-model="form.longitude" type="number" step="any" placeholder="100.5018"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
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
                                <h3 class="text-base font-bold text-white">Hardware Specs <span class="text-gray-500 font-normal">(optional)</span></h3>
                                <p class="text-xs text-gray-400">Helps reviewers validate uptime capacity</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">CPU Cores</label>
                                <input v-model="form.hardware_cpu" type="text" placeholder="8"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">RAM (GB)</label>
                                <input v-model="form.hardware_ram" type="text" placeholder="16"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">SSD (GB)</label>
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
                                <h3 class="text-base font-bold text-white">Contact Info <span class="text-gray-500 font-normal">(optional)</span></h3>
                                <p class="text-xs text-gray-400">So we can reach you about the review</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1.5">Email</label>
                                <input v-model="form.contact_email" type="email" placeholder="you@example.com"
                                    class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                                <p v-if="errors.contact_email" class="text-xs text-red-400 mt-1">{{ errors.contact_email }}</p>
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
                                <h3 class="text-base font-bold text-white">Motivation <span class="text-gray-500 font-normal">(optional)</span></h3>
                                <p class="text-xs text-gray-400">A short note about why you want to validate</p>
                            </div>
                        </div>

                        <textarea v-model="form.motivation" rows="4" placeholder="Why do you want to run a TPIX validator? Tell us about your experience..."
                            class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 resize-none backdrop-blur-sm"></textarea>
                    </div>

                    <!-- Error banner — MasterNode-style border-l-4 -->
                    <div v-if="submitError" class="glass rounded-2xl p-4 border-l-4 border-red-500">
                        <div class="text-red-400 text-sm">{{ submitError }}</div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" :disabled="isSubmitting"
                        class="btn-brand w-full py-4 rounded-2xl text-base font-bold hover:shadow-lg hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:hover:scale-100">
                        <span v-if="isSubmitting" class="inline-flex items-center justify-center gap-2">
                            <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.42" stroke-dashoffset="10"/></svg>
                            Submitting...
                        </span>
                        <span v-else>Submit Validator Application</span>
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
