<script setup>
/**
 * TPIX Validator Network — Interactive Map + Dashboard
 * Leaflet.js map with CARTO dark tiles, validator filtering, reward checking
 * Developed by Xman Studio
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    validators: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    applications: { type: Array, default: () => [] },
});

// ============================================================
//  State
// ============================================================
const activeTab = ref('map');        // map | list | apply
const filterTier = ref('all');       // all | validator | sentinel | light
const filterCountry = ref('all');
const searchQuery = ref('');
const isLoading = ref(false);
const validators = ref(props.validators || []);
const networkStats = ref(props.stats || {});

// Map state
let leafletMap = null;
let markerGroup = null;

// Reward check
const rewardAddress = ref('');
const rewardResult = ref(null);
const isCheckingReward = ref(false);

// Polling
let pollInterval = null;

// ============================================================
//  Computed
// ============================================================
const countries = computed(() => {
    const map = {};
    validators.value.forEach(v => {
        if (v.country_code) {
            map[v.country_code] = v.country_name || v.country_code;
        }
    });
    return Object.entries(map).sort((a, b) => a[1].localeCompare(b[1]));
});

const filteredValidators = computed(() => {
    return validators.value.filter(v => {
        if (filterTier.value !== 'all' && v.tier !== filterTier.value) return false;
        if (filterCountry.value !== 'all' && v.country_code !== filterCountry.value) return false;
        if (searchQuery.value) {
            const q = searchQuery.value.toLowerCase();
            return (v.address || '').toLowerCase().includes(q)
                || (v.country_name || '').toLowerCase().includes(q)
                || (v.endpoint || '').toLowerCase().includes(q);
        }
        return true;
    });
});

const tierCounts = computed(() => {
    const counts = { validator: 0, sentinel: 0, light: 0 };
    validators.value.forEach(v => { if (counts[v.tier] !== undefined) counts[v.tier]++; });
    return counts;
});

// ============================================================
//  Tier config
// ============================================================
const tierConfig = {
    validator: { label: 'Validator', color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30', dot: '#f59e0b', icon: '🔱' },
    sentinel:  { label: 'Sentinel',  color: 'text-purple-400', bg: 'bg-purple-500/15 border-purple-500/30', dot: '#a855f7', icon: '🛡️' },
    light:     { label: 'Light',     color: 'text-cyan-400',   bg: 'bg-cyan-500/15 border-cyan-500/30',     dot: '#06b6d4', icon: '💡' },
};

// Country flag emoji from code
function flag(code) {
    if (!code || code.length !== 2) return '🌐';
    return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
}

function shortAddr(addr) {
    if (!addr) return '—';
    return addr.slice(0, 8) + '...' + addr.slice(-6);
}

function fmtNum(n) {
    return Number(n || 0).toLocaleString();
}

// ============================================================
//  Leaflet Map
// ============================================================
function initMap() {
    if (leafletMap || typeof L === 'undefined') return;

    leafletMap = L.map('validator-map', {
        center: [20, 100],
        zoom: 3,
        minZoom: 2,
        maxZoom: 15,
        zoomControl: true,
        attributionControl: false,
    });

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        subdomains: 'abcd',
        maxZoom: 19,
    }).addTo(leafletMap);

    // Attribution (required by CARTO)
    L.control.attribution({ prefix: false, position: 'bottomright' })
        .addAttribution('&copy; <a href="https://carto.com">CARTO</a> &copy; <a href="https://osm.org">OSM</a>')
        .addTo(leafletMap);

    markerGroup = L.layerGroup().addTo(leafletMap);
    addMarkers();
}

function addMarkers() {
    if (!markerGroup) return;
    markerGroup.clearLayers();

    filteredValidators.value.forEach(v => {
        if (!v.latitude || !v.longitude) return;

        const tc = tierConfig[v.tier] || tierConfig.light;
        const markerColor = tc.dot;

        const icon = L.divIcon({
            className: 'validator-marker',
            html: `<div style="
                width: 14px; height: 14px; border-radius: 50%;
                background: ${markerColor}; border: 2px solid rgba(255,255,255,0.3);
                box-shadow: 0 0 12px ${markerColor}80;
            "></div>`,
            iconSize: [14, 14],
            iconAnchor: [7, 7],
        });

        const marker = L.marker([v.latitude, v.longitude], { icon });

        marker.bindPopup(`
            <div style="font-family: system-ui; min-width: 200px;">
                <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 6px;">
                    ${flag(v.country_code)} ${escHtml(v.country_name) || 'Unknown'}
                </div>
                <div style="font-size: 11px; color: #9ca3af; margin-bottom: 4px;">
                    <span style="color: ${markerColor}; font-weight: 600;">${escHtml(tc.label)}</span> Node
                </div>
                <div style="font-size: 10px; color: #6b7280; font-family: monospace; word-break: break-all;">
                    ${escHtml(v.address) || '—'}
                </div>
                ${v.endpoint ? `<div style="font-size: 10px; color: #6b7280; margin-top: 4px;">EP: ${escHtml(v.endpoint)}</div>` : ''}
                <div style="margin-top: 6px; font-size: 11px;">
                    <span style="color: ${v.online ? '#10b981' : '#ef4444'};">● ${v.online ? 'Online' : 'Offline'}</span>
                    ${v.uptime ? `<span style="color: #9ca3af; margin-left: 8px;">Uptime: ${v.uptime}%</span>` : ''}
                </div>
            </div>
        `, { className: 'validator-popup' });

        marker.addTo(markerGroup);
    });

    // Fit bounds if we have markers
    if (filteredValidators.value.length > 0) {
        const coords = filteredValidators.value
            .filter(v => v.latitude && v.longitude)
            .map(v => [v.latitude, v.longitude]);
        if (coords.length > 0 && leafletMap) {
            leafletMap.fitBounds(coords, { padding: [40, 40], maxZoom: 6 });
        }
    }
}

function escHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function focusNode(v) {
    if (!leafletMap || !v.latitude || !v.longitude) return;
    leafletMap.flyTo([v.latitude, v.longitude], 8, { duration: 1.5 });
}

// Watch filter changes → update markers
watch([filterTier, filterCountry, searchQuery], () => {
    addMarkers();
});

// ============================================================
//  API calls
// ============================================================
async function refreshData() {
    isLoading.value = true;
    try {
        const [statsResp, listResp] = await Promise.all([
            fetch('/api/v1/validators/stats'),
            fetch('/api/v1/validators/list'),
        ]);
        if (statsResp.ok) {
            const d = await statsResp.json();
            if (d.success) networkStats.value = d.data;
        }
        if (listResp.ok) {
            const d = await listResp.json();
            if (d.success) {
                validators.value = d.data || [];
                addMarkers();
            }
        }
    } catch (e) {
        console.error('Refresh failed:', e);
    } finally {
        isLoading.value = false;
    }
}

async function checkRewards() {
    const addr = rewardAddress.value.trim();
    if (!/^0x[a-fA-F0-9]{40}$/.test(addr)) {
        rewardResult.value = { error: 'Invalid wallet address format' };
        return;
    }
    isCheckingReward.value = true;
    rewardResult.value = null;
    try {
        const resp = await fetch(`/api/v1/validators/rewards?address=${addr}`);
        if (resp.ok) {
            const d = await resp.json();
            rewardResult.value = d.success ? d.data : { error: d.error?.message || 'Failed' };
        }
    } catch {
        rewardResult.value = { error: 'Network error' };
    } finally {
        isCheckingReward.value = false;
    }
}

// ============================================================
//  Lifecycle
// ============================================================
onMounted(() => {
    // Load Leaflet CSS/JS dynamically if not loaded
    if (typeof L === 'undefined') {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);

        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => setTimeout(initMap, 100);
        document.head.appendChild(script);
    } else {
        setTimeout(initMap, 100);
    }

    pollInterval = setInterval(refreshData, 30000);
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
    if (leafletMap) { leafletMap.remove(); leafletMap = null; }
});
</script>

<template>
    <Head title="Validator Network" />
    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-black text-white tracking-tight">
                        Validator Network
                    </h1>
                    <p class="text-sm text-gray-400 mt-1">
                        Real-time map of TPIX Chain validators, sentinels, and light nodes worldwide
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="refreshData" :disabled="isLoading"
                        class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all disabled:opacity-50">
                        {{ isLoading ? 'Refreshing...' : 'Refresh' }}
                    </button>
                    <Link href="/validators/apply"
                        class="px-5 py-2.5 text-sm font-bold rounded-xl bg-gradient-to-r from-yellow-500/80 to-amber-500/80 text-black hover:from-yellow-500 hover:to-amber-500 transition-all hover:scale-105">
                        Apply to Validate
                    </Link>
                </div>
            </div>

            <!-- Stats Row -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <div v-for="stat in [
                    { label: 'Validators', value: tierCounts.validator, color: 'text-yellow-400' },
                    { label: 'Sentinels', value: tierCounts.sentinel, color: 'text-purple-400' },
                    { label: 'Light Nodes', value: tierCounts.light, color: 'text-cyan-400' },
                    { label: 'Countries', value: countries.length, color: 'text-emerald-400' },
                    { label: 'Block Height', value: fmtNum(networkStats.block_height || 0), color: 'text-white' },
                    { label: 'Total Staked', value: fmtNum(networkStats.total_staked || 0) + ' TPIX', color: 'text-trading-green' },
                ]" :key="stat.label"
                   class="glass rounded-xl p-4 text-center hover:scale-105 transition-transform">
                    <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">{{ stat.label }}</div>
                    <div :class="['text-xl font-black', stat.color]">{{ stat.value }}</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-dark-900/50 rounded-xl p-1 w-fit">
                <button v-for="tab in [
                    { id: 'map', label: 'World Map', icon: '🌍' },
                    { id: 'list', label: 'Node List', icon: '📋' },
                    { id: 'rewards', label: 'Check Rewards', icon: '💰' },
                ]" :key="tab.id" @click="activeTab = tab.id"
                   :class="['px-5 py-2.5 rounded-lg text-sm font-medium transition-all',
                       activeTab === tab.id
                           ? 'bg-white/10 text-white shadow-lg'
                           : 'text-gray-500 hover:text-gray-300']">
                    {{ tab.icon }} {{ tab.label }}
                </button>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: World Map                                               -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'map'" class="space-y-4">
                <!-- Filters -->
                <div class="flex flex-wrap gap-3">
                    <select v-model="filterTier"
                        class="bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2 focus:border-cyan-500/50 focus:outline-none">
                        <option value="all">All Tiers</option>
                        <option value="validator">🔱 Validator</option>
                        <option value="sentinel">🛡️ Sentinel</option>
                        <option value="light">💡 Light</option>
                    </select>
                    <select v-model="filterCountry"
                        class="bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2 focus:border-cyan-500/50 focus:outline-none">
                        <option value="all">All Countries</option>
                        <option v-for="[code, name] in countries" :key="code" :value="code">
                            {{ flag(code) }} {{ name }}
                        </option>
                    </select>
                    <div class="text-xs text-gray-500 self-center ml-auto">
                        {{ filteredValidators.length }} nodes shown
                    </div>
                </div>

                <!-- Map Container -->
                <div class="relative rounded-2xl overflow-hidden border border-white/10">
                    <div id="validator-map" class="w-full h-[500px] md:h-[600px] bg-dark-900"></div>
                </div>

                <!-- Legend -->
                <div class="flex flex-wrap gap-6 justify-center text-xs text-gray-400">
                    <div v-for="(tc, key) in tierConfig" :key="key" class="flex items-center gap-2">
                        <div :style="{ background: tc.dot }" class="w-3 h-3 rounded-full shadow-lg"></div>
                        <span>{{ tc.icon }} {{ tc.label }}</span>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Node List                                               -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'list'" class="space-y-4">
                <!-- Search + Filters -->
                <div class="flex flex-wrap gap-3">
                    <input v-model="searchQuery" type="text" placeholder="Search by address, country..."
                        class="flex-1 min-w-[200px] bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                    <select v-model="filterTier"
                        class="bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:border-cyan-500/50 focus:outline-none">
                        <option value="all">All Tiers</option>
                        <option value="validator">Validator</option>
                        <option value="sentinel">Sentinel</option>
                        <option value="light">Light</option>
                    </select>
                    <select v-model="filterCountry"
                        class="bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:border-cyan-500/50 focus:outline-none">
                        <option value="all">All Countries</option>
                        <option v-for="[code, name] in countries" :key="code" :value="code">
                            {{ flag(code) }} {{ name }}
                        </option>
                    </select>
                </div>

                <!-- Table -->
                <div class="glass rounded-2xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/5">
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Status</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Tier</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Address</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Location</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Endpoint</th>
                                    <th class="px-4 py-3 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Uptime</th>
                                    <th class="px-4 py-3 text-right text-[10px] text-gray-500 uppercase tracking-wider font-medium">Staked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in filteredValidators" :key="v.address"
                                    @click="activeTab = 'map'; $nextTick(() => focusNode(v))"
                                    class="border-b border-white/[0.03] hover:bg-white/[0.03] cursor-pointer transition-colors">
                                    <td class="px-4 py-3">
                                        <span :class="['inline-block w-2.5 h-2.5 rounded-full',
                                            v.online ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-red-500']"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border',
                                            tierConfig[v.tier]?.bg || 'bg-gray-500/15 border-gray-500/30']">
                                            <span :class="tierConfig[v.tier]?.color || 'text-gray-400'">
                                                {{ tierConfig[v.tier]?.label || v.tier }}
                                            </span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a :href="'https://explorer.tpix.online/address/' + v.address" target="_blank"
                                           class="font-mono text-xs text-cyan-400 hover:text-cyan-300">
                                            {{ shortAddr(v.address) }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-xs text-gray-300">
                                            {{ flag(v.country_code) }} {{ v.country_name || '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-xs text-gray-500 font-mono">{{ v.endpoint || '—' }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span :class="['text-xs font-medium',
                                            (v.uptime || 0) >= 95 ? 'text-emerald-400'
                                            : (v.uptime || 0) >= 80 ? 'text-yellow-400'
                                            : 'text-red-400']">
                                            {{ v.uptime || '—' }}{{ v.uptime ? '%' : '' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <span class="text-xs text-white font-semibold">{{ fmtNum(v.stake_amount || 0) }}</span>
                                        <span class="text-[10px] text-gray-500 ml-1">TPIX</span>
                                    </td>
                                </tr>
                                <tr v-if="filteredValidators.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-gray-500 text-sm">
                                        No validators found matching your criteria
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!--  TAB: Check Rewards                                           -->
            <!-- ============================================================ -->
            <div v-show="activeTab === 'rewards'" class="max-w-2xl mx-auto space-y-6">
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        💰 Check Validator Rewards
                    </h3>
                    <p class="text-sm text-gray-400">
                        Enter a validator wallet address to check pending rewards and reward history from the TPIX Chain.
                    </p>

                    <div class="flex gap-3">
                        <input v-model="rewardAddress" type="text"
                            placeholder="0x..."
                            class="flex-1 bg-dark-800 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600" />
                        <button @click="checkRewards" :disabled="isCheckingReward"
                            class="px-6 py-3 rounded-xl text-sm font-bold bg-gradient-to-r from-cyan-500/80 to-blue-500/80 text-white hover:from-cyan-500 hover:to-blue-500 transition-all disabled:opacity-50">
                            {{ isCheckingReward ? 'Checking...' : 'Check' }}
                        </button>
                    </div>

                    <!-- Result -->
                    <div v-if="rewardResult" class="space-y-4">
                        <div v-if="rewardResult.error" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                            {{ rewardResult.error }}
                        </div>
                        <template v-else>
                            <!-- Node info -->
                            <div class="grid grid-cols-2 gap-4">
                                <div class="glass-sm rounded-xl p-4 text-center">
                                    <div class="text-[10px] text-gray-500 uppercase mb-1">Tier</div>
                                    <div :class="['text-lg font-black', tierConfig[rewardResult.tier]?.color || 'text-white']">
                                        {{ tierConfig[rewardResult.tier]?.label || rewardResult.tier || '—' }}
                                    </div>
                                </div>
                                <div class="glass-sm rounded-xl p-4 text-center">
                                    <div class="text-[10px] text-gray-500 uppercase mb-1">Status</div>
                                    <div :class="['text-lg font-black', rewardResult.active ? 'text-emerald-400' : 'text-red-400']">
                                        {{ rewardResult.active ? 'Active' : 'Inactive' }}
                                    </div>
                                </div>
                            </div>

                            <!-- Rewards -->
                            <div class="glass-sm rounded-xl p-5">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-[10px] text-gray-500 uppercase mb-1">Pending Rewards</div>
                                        <div class="text-2xl font-black text-trading-green">
                                            {{ fmtNum(parseFloat(rewardResult.pending_rewards || 0).toFixed(4)) }}
                                            <span class="text-sm text-gray-400 ml-1">TPIX</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] text-gray-500 uppercase mb-1">Total Earned</div>
                                        <div class="text-lg font-bold text-white">
                                            {{ fmtNum(parseFloat(rewardResult.total_earned || 0).toFixed(2)) }}
                                            <span class="text-xs text-gray-400 ml-1">TPIX</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Stake info -->
                            <div class="glass-sm rounded-xl p-4 text-center">
                                <div class="text-[10px] text-gray-500 uppercase mb-1">Staked Amount</div>
                                <div class="text-xl font-black text-white">
                                    {{ fmtNum(parseFloat(rewardResult.stake_amount || 0)) }}
                                    <span class="text-sm text-gray-400 ml-1">TPIX</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- How rewards work -->
                <div class="glass rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">How Rewards Work</h3>
                    <div class="space-y-3 text-sm text-gray-400">
                        <div class="flex gap-3">
                            <span class="text-yellow-400 text-lg">🔱</span>
                            <div>
                                <span class="text-yellow-400 font-bold">Validators (50%)</span> — Seal blocks every ~2 seconds, receive the largest share of block rewards. Requires 1,000,000 TPIX stake.
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="text-purple-400 text-lg">🛡️</span>
                            <div>
                                <span class="text-purple-400 font-bold">Sentinels (30%)</span> — Relay data and perform light validation. 100,000 TPIX stake.
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="text-cyan-400 text-lg">💡</span>
                            <div>
                                <span class="text-cyan-400 font-bold">Light Nodes (20%)</span> — Support network availability. 10,000 TPIX stake.
                            </div>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5 text-xs text-gray-500">
                        Rewards are distributed per block (~2 seconds). Year 1 emits 400M TPIX total, decreasing over 5 years to a total of 1.4B TPIX.
                        Uptime score affects reward share — keep your node online 24/7 for maximum returns.
                    </div>
                </div>
            </div>

            <!-- CTA: Apply to Validate -->
            <div class="relative group">
                <div class="absolute -inset-1 bg-gradient-to-r from-yellow-500/20 via-purple-500/20 to-cyan-500/20 rounded-3xl blur-xl opacity-60 group-hover:opacity-100 transition-opacity"></div>
                <div class="glass relative rounded-2xl p-8 text-center">
                    <img src="/tpixlogo.webp" alt="TPIX" class="w-16 h-16 mx-auto mb-4 ring-2 ring-white/10 rounded-full" />
                    <h3 class="text-xl font-bold text-white mb-2">Become a TPIX Validator</h3>
                    <p class="text-sm text-gray-400 mb-5 max-w-lg mx-auto">
                        Help secure the TPIX Chain and earn rewards. Apply now to join the validator network.
                    </p>
                    <div class="flex justify-center gap-3 flex-wrap">
                        <Link href="/validators/apply"
                            class="px-6 py-2.5 rounded-xl font-bold text-sm bg-gradient-to-r from-yellow-500/80 to-amber-500/80 text-black hover:from-yellow-500 hover:to-amber-500 transition-all hover:scale-105">
                            Apply Now
                        </Link>
                        <Link href="/masternode/guide"
                            class="px-6 py-2.5 rounded-xl font-bold text-sm bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                            Setup Guide
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Leaflet popup dark theme override */
:deep(.validator-popup .leaflet-popup-content-wrapper) {
    background: rgba(15, 20, 35, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
}
:deep(.validator-popup .leaflet-popup-tip) {
    background: rgba(15, 20, 35, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
:deep(.validator-popup .leaflet-popup-close-button) {
    color: #6b7280;
}
:deep(.leaflet-control-zoom a) {
    background: rgba(15, 20, 35, 0.9) !important;
    color: #fff !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
}
:deep(.leaflet-control-attribution) {
    background: rgba(15, 20, 35, 0.8) !important;
    color: #6b7280 !important;
    font-size: 10px !important;
}
:deep(.leaflet-control-attribution a) {
    color: #06b6d4 !important;
}
</style>
