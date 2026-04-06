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
const filterTier = ref('all');       // all | validator | guardian | sentinel | light
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
    const counts = { validator: 0, guardian: 0, sentinel: 0, light: 0 };
    validators.value.forEach(v => { if (counts[v.tier] !== undefined) counts[v.tier]++; });
    return counts;
});

// ============================================================
//  Tier config
// ============================================================
const tierConfig = {
    validator: { label: 'Validator', color: 'text-red-400',    bg: 'bg-red-500/15 border-red-500/30',       dot: '#00e676', markerSize: 12, icon: '🔥' },
    guardian:  { label: 'Guardian',  color: 'text-yellow-400', bg: 'bg-yellow-500/15 border-yellow-500/30', dot: '#f59e0b', markerSize: 11, icon: '🔱' },
    sentinel:  { label: 'Sentinel',  color: 'text-purple-400', bg: 'bg-purple-500/15 border-purple-500/30', dot: '#a855f7', markerSize: 10, icon: '🛡️' },
    light:     { label: 'Light',     color: 'text-cyan-400',   bg: 'bg-cyan-500/15 border-cyan-500/30',     dot: '#06b6d4', markerSize: 8,  icon: '💡' },
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
        const color = tc.dot;
        const size = tc.markerSize || 10;

        // Neon glow marker with dual-layer box-shadow (from TPIX-Coin renderer.js)
        const icon = L.divIcon({
            className: 'validator-marker',
            html: `<div style="
                width: ${size}px; height: ${size}px; border-radius: 50%;
                background: ${color};
                border: 2px solid ${color}80;
                box-shadow: 0 0 ${size * 2}px ${color}, 0 0 ${size * 4}px ${color}40;
                animation: livePulse 2s ease-in-out infinite;
            "></div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
        });

        const marker = L.marker([v.latitude, v.longitude], { icon });

        // Cyberpunk popup (from TPIX-Coin renderer.js)
        const tierBadge = `<span style="
            display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 700;
            background: ${color}20; color: ${color}; border: 1px solid ${color}40;
        ">${escHtml(tc.icon)} ${escHtml(tc.label)}</span>`;

        marker.bindPopup(`
            <div style="font-family: system-ui; min-width: 220px; padding: 2px 0;">
                <div style="font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 8px;">
                    ${flag(v.country_code)} ${escHtml(v.country_name) || 'Unknown'}
                </div>
                <div style="margin-bottom: 8px;">${tierBadge}</div>
                <div style="font-size: 10px; color: #06b6d4; font-family: 'Courier New', monospace; word-break: break-all; padding: 6px 8px; background: rgba(6,182,212,0.06); border-radius: 6px; border: 1px solid rgba(6,182,212,0.1);">
                    ${escHtml(v.address) || '—'}
                </div>
                ${v.endpoint ? `<div style="font-size: 10px; color: #6b7280; margin-top: 6px;">Endpoint: <span style="color: #9ca3af;">${escHtml(v.endpoint)}</span></div>` : ''}
                <div style="margin-top: 8px; display: flex; align-items: center; gap: 12px; font-size: 11px;">
                    <span style="display: flex; align-items: center; gap: 4px; color: ${v.online ? '#10b981' : '#ef4444'};">
                        <span style="width: 6px; height: 6px; border-radius: 50%; background: ${v.online ? '#10b981' : '#ef4444'}; ${v.online ? 'box-shadow: 0 0 6px #10b98180;' : ''}"></span>
                        ${v.online ? 'Online' : 'Offline'}
                    </span>
                    ${v.uptime ? `<span style="color: #9ca3af;">Uptime: <strong style="color: #fff;">${v.uptime}%</strong></span>` : ''}
                </div>
                ${v.stake_amount ? `<div style="margin-top: 6px; font-size: 11px; color: #9ca3af;">Staked: <strong style="color: #fff;">${fmtNum(v.stake_amount)}</strong> TPIX</div>` : ''}
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
        link.href = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css';
        document.head.appendChild(link);

        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js';
        script.onload = () => setTimeout(initMap, 100);
        script.onerror = () => {
            // Fallback: try unpkg if jsdelivr fails
            const fb = document.createElement('script');
            fb.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            fb.onload = () => setTimeout(initMap, 100);
            document.head.appendChild(fb);
        };
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
        <div class="relative min-h-screen overflow-hidden">

            <!-- ============================================================ -->
            <!--  Animated Background Glow (3 layers — same as MasterNode)    -->
            <!-- ============================================================ -->
            <div class="fixed inset-0 pointer-events-none -z-10">
                <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] bg-cyan-500/8 rounded-full blur-3xl animate-float" />
                <div class="absolute top-1/2 right-1/3 w-[700px] h-[700px] bg-purple-500/6 rounded-full blur-3xl" style="animation: float 8s ease-in-out infinite reverse" />
                <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] bg-yellow-500/5 rounded-full blur-3xl animate-float" style="animation-delay: -3s" />
            </div>

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8 relative z-10">

                <!-- ============================================================ -->
                <!--  HERO Header with glow backdrop                              -->
                <!-- ============================================================ -->
                <div class="relative">
                    <div class="absolute -inset-2 bg-gradient-to-r from-cyan-500/20 via-purple-500/15 to-yellow-500/20 rounded-3xl blur-xl opacity-60" />
                    <div class="glass-brand relative rounded-3xl p-8 md:p-10 overflow-hidden">
                        <!-- Floating particles -->
                        <div class="absolute top-6 left-10 w-2 h-2 bg-cyan-400/40 rounded-full animate-float" />
                        <div class="absolute top-16 right-16 w-1.5 h-1.5 bg-purple-400/40 rounded-full animate-float" style="animation-delay: -2s" />
                        <div class="absolute bottom-8 left-1/4 w-1 h-1 bg-yellow-400/40 rounded-full animate-float" style="animation-delay: -4s" />
                        <div class="absolute bottom-12 right-1/3 w-1.5 h-1.5 bg-red-400/30 rounded-full animate-float" style="animation-delay: -1s" />

                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 relative">
                            <div class="flex items-center gap-5">
                                <!-- TPIX Logo with glow -->
                                <div class="relative shrink-0">
                                    <div class="absolute -inset-3 bg-gradient-to-r from-cyan-500/30 via-purple-500/30 to-yellow-500/30 rounded-full blur-2xl animate-glow-brand" />
                                    <img src="/tpixlogo.webp" alt="TPIX" class="relative w-16 h-16 ring-2 ring-white/10" />
                                </div>
                                <div>
                                    <h1 class="text-3xl md:text-4xl font-black">
                                        <span class="text-gradient-brand">Validator Network</span>
                                    </h1>
                                    <p class="text-sm text-gray-400 mt-1">
                                        Real-time map of TPIX Chain validators, guardians, sentinels & light nodes worldwide
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <button @click="refreshData" :disabled="isLoading"
                                    class="px-4 py-2.5 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all disabled:opacity-50">
                                    {{ isLoading ? 'Refreshing...' : 'Refresh' }}
                                </button>
                                <Link href="/validators/apply"
                                    class="btn-brand px-5 py-2.5 text-sm font-bold rounded-xl hover:scale-105 transition-all">
                                    Apply to Validate
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  Stats Row with glass-card glow                              -->
                <!-- ============================================================ -->
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                    <div v-for="stat in [
                        { label: 'Total Nodes', value: fmtNum(validators.length), color: 'text-white', glow: '' },
                        { label: 'Validators', value: tierCounts.validator, color: 'text-red-400', glow: 'hover:shadow-[0_0_20px_rgba(239,68,68,0.1)]' },
                        { label: 'Guardians', value: tierCounts.guardian, color: 'text-yellow-400', glow: 'hover:shadow-[0_0_20px_rgba(245,158,11,0.1)]' },
                        { label: 'Sentinels', value: tierCounts.sentinel, color: 'text-purple-400', glow: 'hover:shadow-[0_0_20px_rgba(168,85,247,0.1)]' },
                        { label: 'Light Nodes', value: tierCounts.light, color: 'text-cyan-400', glow: 'hover:shadow-[0_0_20px_rgba(6,182,212,0.1)]' },
                        { label: 'Countries', value: countries.length, color: 'text-emerald-400', glow: '' },
                        { label: 'Block Height', value: fmtNum(networkStats.block_height || 0), color: 'text-white', glow: '' },
                        { label: 'Total Staked', value: fmtNum(networkStats.total_staked || 0) + ' TPIX', color: 'text-trading-green', glow: 'hover:shadow-[0_0_20px_rgba(0,200,83,0.1)]' },
                    ]" :key="stat.label"
                       :class="['glass-card rounded-xl p-4 text-center group hover:scale-105 transition-all duration-300', stat.glow]">
                        <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">{{ stat.label }}</div>
                        <div :class="['text-2xl font-black', stat.color]">{{ stat.value }}</div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  Tier Filter Badges (clickable — like MasterNode tier cards)  -->
                <!-- ============================================================ -->
                <div class="flex flex-wrap gap-3 justify-center">
                    <button v-for="(tc, key) in { all: { label: 'All Nodes', color: 'text-white', bg: 'bg-white/10 border-white/20', dot: '#fff', icon: '🌐' }, ...tierConfig }"
                            :key="key" @click="filterTier = key"
                            :class="['flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border transition-all duration-300',
                                filterTier === key
                                    ? 'scale-105 shadow-lg ' + (key === 'all' ? 'bg-white/15 border-white/30 text-white' : tc.bg + ' ' + tc.color)
                                    : 'bg-white/[0.03] border-white/[0.06] text-gray-500 hover:bg-white/[0.06] hover:text-gray-300']">
                        <div v-if="key !== 'all'" :style="{ background: tc.dot }"
                             class="w-2.5 h-2.5 rounded-full"
                             :class="filterTier === key ? 'shadow-[0_0_8px_currentColor]' : ''" />
                        <span>{{ tc.icon }} {{ tc.label }}</span>
                        <span v-if="key !== 'all'" class="text-[10px] opacity-60 ml-0.5">({{ tierCounts[key] || 0 }})</span>
                    </button>
                </div>

                <!-- ============================================================ -->
                <!--  Tabs                                                        -->
                <!-- ============================================================ -->
                <div class="flex gap-1 p-1 glass-sm rounded-xl w-fit mx-auto">
                    <button v-for="tab in [
                        { id: 'map', label: 'World Map', icon: '🌍' },
                        { id: 'list', label: 'Node List', icon: '📋' },
                        { id: 'rewards', label: 'Rewards', icon: '💰' },
                    ]" :key="tab.id" @click="activeTab = tab.id"
                       :class="['px-6 py-2.5 rounded-lg text-sm font-semibold transition-all',
                           activeTab === tab.id
                               ? 'glass text-white shadow-lg'
                               : 'text-gray-400 hover:text-white']">
                        {{ tab.icon }} {{ tab.label }}
                    </button>
                </div>

                <!-- ============================================================ -->
                <!--  TAB: World Map                                               -->
                <!-- ============================================================ -->
                <div v-show="activeTab === 'map'" class="space-y-4">
                    <!-- Country filter -->
                    <div class="flex flex-wrap gap-3 items-center">
                        <select v-model="filterCountry"
                            class="bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:border-cyan-500/50 focus:outline-none backdrop-blur-sm">
                            <option value="all">All Countries</option>
                            <option v-for="[code, name] in countries" :key="code" :value="code">
                                {{ flag(code) }} {{ name }}
                            </option>
                        </select>
                        <div class="flex items-center gap-2 ml-auto">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.5)]" />
                            <span class="text-xs text-gray-400">{{ filteredValidators.length }} nodes live</span>
                        </div>
                    </div>

                    <!-- Map Container with glow border -->
                    <div class="relative group">
                        <div class="absolute -inset-0.5 bg-gradient-to-r from-cyan-500/20 via-purple-500/10 to-yellow-500/20 rounded-2xl blur opacity-40 group-hover:opacity-70 transition-opacity duration-500" />
                        <div class="relative rounded-2xl overflow-hidden border border-white/10">
                            <div id="validator-map" class="w-full h-[500px] md:h-[600px] bg-[#0a0f1e]"></div>
                        </div>
                    </div>

                    <!-- Legend with glow dots -->
                    <div class="flex flex-wrap gap-6 justify-center text-xs text-gray-400">
                        <div v-for="(tc, key) in tierConfig" :key="key" class="flex items-center gap-2">
                            <div :style="{ background: tc.dot, boxShadow: '0 0 8px ' + tc.dot + '80' }"
                                 class="w-3 h-3 rounded-full"></div>
                            <span>{{ tc.icon }} {{ tc.label }}</span>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  TAB: Node List                                               -->
                <!-- ============================================================ -->
                <div v-show="activeTab === 'list'" class="space-y-4">
                    <!-- Search -->
                    <div class="flex flex-wrap gap-3">
                        <div class="relative flex-1 min-w-[200px]">
                            <input v-model="searchQuery" type="text" placeholder="Search by address, country, endpoint..."
                                class="w-full bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 pl-10 focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <select v-model="filterCountry"
                            class="bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:border-cyan-500/50 focus:outline-none backdrop-blur-sm">
                            <option value="all">All Countries</option>
                            <option v-for="[code, name] in countries" :key="code" :value="code">
                                {{ flag(code) }} {{ name }}
                            </option>
                        </select>
                    </div>

                    <!-- Table with glass -->
                    <div class="glass rounded-2xl overflow-hidden border border-white/[0.06]">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-white/[0.06]">
                                        <th class="px-4 py-3.5 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Status</th>
                                        <th class="px-4 py-3.5 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Tier</th>
                                        <th class="px-4 py-3.5 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium">Address</th>
                                        <th class="px-4 py-3.5 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium hidden md:table-cell">Location</th>
                                        <th class="px-4 py-3.5 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium hidden lg:table-cell">Endpoint</th>
                                        <th class="px-4 py-3.5 text-left text-[10px] text-gray-500 uppercase tracking-wider font-medium hidden sm:table-cell">Uptime</th>
                                        <th class="px-4 py-3.5 text-right text-[10px] text-gray-500 uppercase tracking-wider font-medium hidden sm:table-cell">Staked</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="v in filteredValidators" :key="v.address"
                                        @click="activeTab = 'map'; $nextTick(() => focusNode(v))"
                                        class="border-b border-white/[0.03] hover:bg-white/[0.04] cursor-pointer transition-all duration-200 group/row">
                                        <td class="px-4 py-3.5">
                                            <span :class="['inline-block w-2.5 h-2.5 rounded-full transition-shadow',
                                                v.online ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)] group-hover/row:shadow-[0_0_12px_rgba(16,185,129,0.7)]' : 'bg-red-500']"></span>
                                        </td>
                                        <td class="px-4 py-3.5">
                                            <span :class="['text-[10px] font-bold px-2.5 py-1 rounded-lg border',
                                                tierConfig[v.tier]?.bg || 'bg-gray-500/15 border-gray-500/30']">
                                                <span :class="tierConfig[v.tier]?.color || 'text-gray-400'">
                                                    {{ tierConfig[v.tier]?.label || v.tier }}
                                                </span>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3.5">
                                            <a :href="'https://explorer.tpix.online/address/' + v.address" target="_blank"
                                               @click.stop
                                               class="font-mono text-xs text-cyan-400 hover:text-cyan-300 transition-colors">
                                                {{ shortAddr(v.address) }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3.5 hidden md:table-cell">
                                            <span class="text-xs text-gray-300">
                                                {{ flag(v.country_code) }} {{ v.country_name || '—' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3.5 hidden lg:table-cell">
                                            <span class="text-xs text-gray-500 font-mono">{{ v.endpoint || '—' }}</span>
                                        </td>
                                        <td class="px-4 py-3.5 hidden sm:table-cell">
                                            <span :class="['text-xs font-medium',
                                                (v.uptime || 0) >= 95 ? 'text-emerald-400'
                                                : (v.uptime || 0) >= 80 ? 'text-yellow-400'
                                                : 'text-red-400']">
                                                {{ v.uptime || '—' }}{{ v.uptime ? '%' : '' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3.5 text-right hidden sm:table-cell">
                                            <span class="text-xs text-white font-semibold">{{ fmtNum(v.stake_amount || 0) }}</span>
                                            <span class="text-[10px] text-gray-500 ml-1">TPIX</span>
                                        </td>
                                    </tr>
                                    <tr v-if="filteredValidators.length === 0">
                                        <td colspan="7" class="px-4 py-16 text-center text-gray-500 text-sm">
                                            No validators found matching your criteria
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  TAB: Rewards                                                 -->
                <!-- ============================================================ -->
                <div v-show="activeTab === 'rewards'" class="space-y-6">

                    <!-- Check Rewards Card -->
                    <div class="max-w-2xl mx-auto">
                        <div class="relative group">
                            <div class="absolute -inset-0.5 bg-gradient-to-r from-cyan-500/20 via-blue-500/15 to-purple-500/20 rounded-2xl blur opacity-40 group-hover:opacity-70 transition-opacity" />
                            <div class="glass relative rounded-2xl p-6 space-y-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/20 border border-cyan-500/20 flex items-center justify-center text-xl">💰</div>
                                    <div>
                                        <h3 class="text-lg font-bold text-white">Check Validator Rewards</h3>
                                        <p class="text-xs text-gray-400">Enter wallet address to check pending rewards</p>
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <input v-model="rewardAddress" type="text" placeholder="0x..."
                                        class="flex-1 bg-dark-800/80 border border-white/10 text-white text-sm rounded-xl px-4 py-3 font-mono focus:border-cyan-500/50 focus:outline-none placeholder-gray-600 backdrop-blur-sm" />
                                    <button @click="checkRewards" :disabled="isCheckingReward"
                                        class="btn-brand px-6 py-3 rounded-xl text-sm font-bold transition-all disabled:opacity-50">
                                        {{ isCheckingReward ? 'Checking...' : 'Check' }}
                                    </button>
                                </div>

                                <!-- Result -->
                                <div v-if="rewardResult" class="space-y-4">
                                    <div v-if="rewardResult.error" class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                                        {{ rewardResult.error }}
                                    </div>
                                    <template v-else>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="glass-sm rounded-xl p-4 text-center">
                                                <div class="text-[10px] text-gray-500 uppercase mb-1">Tier</div>
                                                <div :class="['text-lg font-black', tierConfig[rewardResult.tier]?.color || 'text-white']">
                                                    {{ tierConfig[rewardResult.tier]?.icon || '' }} {{ tierConfig[rewardResult.tier]?.label || rewardResult.tier || '—' }}
                                                </div>
                                            </div>
                                            <div class="glass-sm rounded-xl p-4 text-center">
                                                <div class="text-[10px] text-gray-500 uppercase mb-1">Status</div>
                                                <div :class="['text-lg font-black', rewardResult.active ? 'text-emerald-400' : 'text-red-400']">
                                                    {{ rewardResult.active ? 'Active' : 'Inactive' }}
                                                </div>
                                            </div>
                                        </div>
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
                        </div>
                    </div>

                    <!-- ============================================================ -->
                    <!--  Block Reward Split (copied from MasterNode)                 -->
                    <!-- ============================================================ -->
                    <div class="glass rounded-2xl p-6">
                        <h3 class="text-sm font-bold text-white mb-4">Block Reward Split</h3>
                        <div class="flex rounded-xl overflow-hidden h-8 sm:h-10 mb-3">
                            <div class="bg-gradient-to-r from-red-500 to-rose-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-white" style="width:20%">
                                <span class="hidden sm:inline">20% Validator</span><span class="sm:hidden">20%</span>
                            </div>
                            <div class="bg-gradient-to-r from-yellow-500 to-amber-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-black" style="width:35%">
                                <span class="hidden sm:inline">35% Guardian</span><span class="sm:hidden">35%</span>
                            </div>
                            <div class="bg-gradient-to-r from-purple-500 to-violet-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-white" style="width:30%">
                                <span class="hidden sm:inline">30% Sentinel</span><span class="sm:hidden">30%</span>
                            </div>
                            <div class="bg-gradient-to-r from-cyan-500 to-blue-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-white" style="width:15%">
                                <span class="hidden sm:inline">15% Light</span><span class="sm:hidden">15%</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400">
                            Each block (~2 seconds) distributes TPIX to node operators, weighted by tier and uptime score.
                        </p>
                    </div>

                    <!-- ============================================================ -->
                    <!--  Emission Schedule (copied from MasterNode)                  -->
                    <!-- ============================================================ -->
                    <div class="glass rounded-2xl p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <img src="/tpixlogo.webp" alt="TPIX" class="w-8 h-8 ring-1 ring-white/10" />
                            <div>
                                <h3 class="text-sm font-bold text-white">Emission Schedule</h3>
                                <p class="text-xs text-gray-400">1,400,000,000 TPIX distributed over 5 years</p>
                            </div>
                            <span class="ml-auto text-sm font-bold text-cyan-400">Year {{ networkStats.current_year || 1 }}</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                            <div v-for="em in [
                                { y: 1, amt: '400M', pb: '~25.5/block', pct: '28.6%' },
                                { y: 2, amt: '350M', pb: '~22.3/block', pct: '25.0%' },
                                { y: 3, amt: '300M', pb: '~19.1/block', pct: '21.4%' },
                                { y: 4, amt: '200M', pb: '~12.7/block', pct: '14.3%' },
                                { y: 5, amt: '150M', pb: '~9.6/block', pct: '10.7%' },
                            ]" :key="em.y"
                                 :class="[
                                     'rounded-xl p-3 text-center border transition-all',
                                     (networkStats.current_year || 1) === em.y
                                         ? 'glass border-cyan-500/40 shadow-[0_0_20px_rgba(6,182,212,0.15)] scale-105'
                                         : 'glass-sm border-white/5 opacity-60'
                                 ]">
                                <div :class="['text-xs font-black', (networkStats.current_year || 1) === em.y ? 'text-cyan-400' : 'text-gray-500']">
                                    Year {{ em.y }}
                                </div>
                                <div :class="['text-lg font-bold mt-1', (networkStats.current_year || 1) === em.y ? 'text-white' : 'text-gray-400']">
                                    {{ em.amt }}
                                </div>
                                <div class="text-[10px] text-gray-500 mt-0.5">{{ em.pb }}</div>
                                <div class="text-[10px] text-gray-600">{{ em.pct }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================================ -->
                    <!--  How Rewards Work — Tier Info Cards                           -->
                    <!-- ============================================================ -->
                    <div class="glass rounded-2xl p-6 space-y-4">
                        <h3 class="text-sm font-bold text-white uppercase tracking-wide">How Rewards Work</h3>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div v-for="info in [
                                { tier: 'validator', share: '20%', stake: '10,000,000 TPIX', desc: 'Real IBFT2 block sealers with governance power. Requires company KYC.' },
                                { tier: 'guardian', share: '35%', stake: '1,000,000 TPIX', desc: 'Premium masternodes with high rewards.' },
                                { tier: 'sentinel', share: '30%', stake: '100,000 TPIX', desc: 'Standard masternodes for network integrity.' },
                                { tier: 'light', share: '15%', stake: '10,000 TPIX', desc: 'Easiest entry to support the network and earn rewards.' },
                            ]" :key="info.tier"
                               :class="['rounded-xl p-4 border transition-all hover:scale-[1.02]', tierConfig[info.tier].bg]">
                                <div class="flex items-center gap-2 mb-2">
                                    <div :style="{ background: tierConfig[info.tier].dot, boxShadow: '0 0 10px ' + tierConfig[info.tier].dot + '60' }"
                                         class="w-3 h-3 rounded-full" />
                                    <span :class="['font-bold text-sm', tierConfig[info.tier].color]">
                                        {{ tierConfig[info.tier].icon }} {{ tierConfig[info.tier].label }}
                                    </span>
                                    <span class="ml-auto text-xs text-gray-400 font-bold">{{ info.share }}</span>
                                </div>
                                <div class="text-xs text-gray-400 mb-1">{{ info.desc }}</div>
                                <div class="text-[10px] text-gray-500">Min Stake: {{ info.stake }}</div>
                            </div>
                        </div>
                        <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5 text-xs text-gray-500">
                            Rewards are distributed per block (~2 seconds). Uptime score affects reward share — keep your node online 24/7 for maximum returns.
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  CTA: Apply to Validate — with glow backdrop                  -->
                <!-- ============================================================ -->
                <div class="relative group">
                    <div class="absolute -inset-1 bg-gradient-to-r from-red-500/20 via-purple-500/20 to-cyan-500/20 rounded-3xl blur-xl opacity-60 group-hover:opacity-100 transition-opacity duration-500" />
                    <div class="glass-brand relative rounded-2xl p-8 text-center overflow-hidden">
                        <!-- Particles -->
                        <div class="absolute top-4 left-12 w-1.5 h-1.5 bg-cyan-400/30 rounded-full animate-float" />
                        <div class="absolute bottom-6 right-10 w-1 h-1 bg-yellow-400/30 rounded-full animate-float" style="animation-delay: -2s" />

                        <div class="relative">
                            <div class="absolute -inset-4 bg-gradient-to-r from-cyan-500/20 via-purple-500/20 to-yellow-500/20 rounded-full blur-2xl mx-auto w-24 h-24 left-1/2 -translate-x-1/2" />
                            <img src="/tpixlogo.webp" alt="TPIX" class="relative w-16 h-16 mx-auto mb-4 ring-2 ring-white/10" />
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Become a TPIX Validator</h3>
                        <p class="text-sm text-gray-400 mb-5 max-w-lg mx-auto">
                            Help secure the TPIX Chain and earn rewards. Apply now to join the validator network.
                        </p>
                        <div class="flex justify-center gap-3 flex-wrap">
                            <Link href="/validators/apply"
                                class="btn-brand px-6 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                                Apply Now
                            </Link>
                            <Link href="/masternode"
                                class="px-6 py-2.5 rounded-xl font-bold text-sm bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                                Run a Node
                            </Link>
                            <Link href="/masternode/guide"
                                class="px-6 py-2.5 rounded-xl font-bold text-sm bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                                Setup Guide
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* ============================================================
   Leaflet — Cyberpunk dark popup (from TPIX-Coin renderer.js)
   ============================================================ */
:deep(.validator-popup .leaflet-popup-content-wrapper) {
    background: #0a0f1e;
    border: 1px solid rgba(6, 182, 212, 0.3);
    border-radius: 12px;
    box-shadow: 0 0 20px rgba(6, 182, 212, 0.15), 0 8px 32px rgba(0, 0, 0, 0.5);
}
:deep(.validator-popup .leaflet-popup-tip) {
    background: #0a0f1e;
    border: 1px solid rgba(6, 182, 212, 0.3);
}
:deep(.validator-popup .leaflet-popup-close-button) {
    color: #6b7280;
}
:deep(.validator-popup .leaflet-popup-close-button:hover) {
    color: #06b6d4;
}

/* ============================================================
   Leaflet — Zoom controls
   ============================================================ */
:deep(.leaflet-control-zoom a) {
    background: rgba(10, 15, 30, 0.9) !important;
    color: #fff !important;
    border-color: rgba(6, 182, 212, 0.2) !important;
}
:deep(.leaflet-control-zoom a:hover) {
    background: rgba(6, 182, 212, 0.15) !important;
}
:deep(.leaflet-control-attribution) {
    background: rgba(10, 15, 30, 0.8) !important;
    color: #6b7280 !important;
    font-size: 10px !important;
}
:deep(.leaflet-control-attribution a) {
    color: #06b6d4 !important;
}

/* ============================================================
   Neon marker pulse animation
   ============================================================ */
:deep(.validator-marker) {
    background: transparent !important;
    border: none !important;
}

@keyframes livePulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.7; transform: scale(1.3); }
}
</style>
