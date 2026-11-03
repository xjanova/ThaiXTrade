<script setup>
/**
 * TPIX TRADE - Carbon Credit Documentation
 * คู่มือละเอียด — ภาพรวม, การทำงาน, ติดตั้ง, ตั้งค่า, API, Blockchain, วิธีใช้งาน
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

// คีย์แปลทั้งหมดของหน้านี้อยู่ใต้ namespace เดียว — อาร์เรย์ด้านล่างเก็บแค่ key แล้วเรียก t() ตอน render
// ห้ามเรียก t() ตอน setup เพราะข้อความจะค้างเป็นภาษาแรกเมื่อผู้ใช้สลับภาษา
const NS = 'admin.carbonCreditsDocumentation';
const tk = (key, params) => t(`${NS}.${key}`, params);

const activeSection = ref('overview');

const sections = [
    { id: 'overview', icon: '🌍' },
    { id: 'howItWorks', icon: '⚙️' },
    { id: 'setup', icon: '📦' },
    { id: 'admin', icon: '🛠️' },
    { id: 'api', icon: '🔌' },
    { id: 'blockchain', icon: '⛓️' },
    { id: 'standards', icon: '📜' },
    { id: 'mobile', icon: '📱' },
];

const setupSteps = [
    { num: 1, key: 's1' },
    { num: 2, key: 's2' },
    { num: 3, key: 's3' },
    { num: 4, key: 's4' },
];

// example = ชื่อโปรเจกต์ (ชื่อเฉพาะ) — ไม่แปล
const projectTypes = [
    { type: 'reforestation', icon: '🌳', example: 'Thailand Northern Reforestation' },
    { type: 'renewable_energy', icon: '⚡', example: 'ASEAN Solar Energy Initiative' },
    { type: 'methane_capture', icon: '🔥', example: 'Livestock Methane Reduction' },
    { type: 'ocean_cleanup', icon: '🌊', example: 'Pacific Cleanup Project' },
    { type: 'carbon_capture', icon: '🏭', example: 'Direct Air Capture Facility' },
    { type: 'biodiversity', icon: '🦋', example: 'Rainforest Conservation' },
    { type: 'other', icon: '🌐', example: 'Smart Farm Carbon Offset' },
];

const concepts = [
    { icon: '🏗️', key: 'project' },
    { icon: '🎫', key: 'credit' },
    { icon: '♻️', key: 'retirement' },
    { icon: '📜', key: 'standards' },
    { icon: '💰', key: 'multiCurrency' },
    { icon: '🔒', key: 'verified' },
];

const userSteps = [
    { num: 1, key: 's1' },
    { num: 2, key: 's2' },
    { num: 3, key: 's3' },
    { num: 4, key: 's4' },
    { num: 5, key: 's5' },
];

const adminActions = [
    { icon: '➕', key: 'create' },
    { icon: '✅', key: 'activate' },
    { icon: '✏️', key: 'edit' },
    { icon: '🗑️', key: 'delete' },
    { icon: '⏸️', key: 'suspend' },
    { icon: '📊', key: 'stats' },
];

const lifecycleStates = [
    { key: 'draft', color: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
    { key: 'active', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
    { key: 'soldOut', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
    { key: 'expired', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
    { key: 'suspended', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
];

// ชื่อตาราง/คอลัมน์เป็นชื่อเทคนิค — ไม่แปล
const schemaTables = [
    {
        name: 'carbon_projects',
        fields: [
            'id, name, slug (unique), description, location, country (2 char)',
            'project_type (enum: 7 types), standard, registry_id',
            'total_credits, available_credits, retired_credits',
            'price_per_credit_usd, price_per_credit_tpix',
            'vintage_year, status (enum: 5 states), is_featured, metadata (JSON)',
        ],
    },
    {
        name: 'carbon_credits',
        fields: [
            'id, carbon_project_id (FK), serial_number (unique, CC-XXXXXXXX-YYYYMMDD)',
            'owner_address (42 chars, 0x...), amount (decimal 18,4)',
            'price_paid_usd, payment_currency (TPIX/BNB/USDT), payment_amount',
            'tx_hash (66 chars), status (enum: active/retired/transferred/pending)',
        ],
    },
    {
        name: 'carbon_retirements',
        fields: [
            'id, carbon_credit_id (FK), retiree_address (42 chars)',
            'beneficiary_name, retirement_reason (text), amount',
            'certificate_hash (66 chars, 0x...), tx_hash',
        ],
    },
];

const rules = [
    { key: 'r1', icon: '⚠️', color: 'text-yellow-400' },
    { key: 'r2', icon: '⚠️', color: 'text-yellow-400' },
    { key: 'r3', icon: '⚠️', color: 'text-yellow-400' },
    { key: 'r4', icon: '⚠️', color: 'text-yellow-400' },
    { key: 'r5', icon: '✅', color: 'text-green-400' },
    { key: 'r6', icon: '✅', color: 'text-green-400' },
];

const endpoints = [
    { method: 'GET', path: '/api/v1/carbon-credits/projects', key: 'projects', auth: false },
    { method: 'GET', path: '/api/v1/carbon-credits/projects/{slug}', key: 'projectBySlug', auth: false },
    { method: 'GET', path: '/api/v1/carbon-credits/stats', key: 'stats', auth: false },
    { method: 'POST', path: '/api/v1/carbon-credits/purchase', key: 'purchase', auth: true },
    { method: 'POST', path: '/api/v1/carbon-credits/retire', key: 'retire', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/my-credits/{wallet}', key: 'myCredits', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/my-retirements/{wallet}', key: 'myRetirements', auth: true },
];

const chainConfig = [
    { labelKey: 'chainId', value: '4289' },
    { labelKey: 'rpcUrl', value: 'rpc.tpixchain.com' },
    { labelKey: 'gas', valueKey: 'gasValue' },
    { labelKey: 'tokenModel', valueKey: 'tokenModelValue' },
];

// name/org = ชื่อมาตรฐานและองค์กร (ชื่อเฉพาะ) — ไม่แปล
const standards = [
    { key: 'vcs', name: 'VCS (Verified Carbon Standard)', org: 'Verra', icon: '🏛️', features: ['f1', 'f2', 'f3', 'f4'] },
    { key: 'gold', name: 'Gold Standard', org: 'Gold Standard Foundation', icon: '🥇', features: ['f1', 'f2', 'f3', 'f4'] },
    { key: 'cdm', name: 'CDM (Clean Development Mechanism)', org: 'United Nations (UNFCCC)', icon: '🇺🇳', features: ['f1', 'f2', 'f3', 'f4'] },
    { key: 'acr', name: 'ACR (American Carbon Registry)', org: 'Winrock International', icon: '🇺🇸', features: ['f1', 'f2', 'f3'] },
];

const mobileFeatures = [
    { icon: '🛒', key: 'browse', status: 'ready' },
    { icon: '♻️', key: 'retire', status: 'ready' },
    { icon: '📊', key: 'portfolio', status: 'ready' },
    { icon: '🔔', key: 'alerts', status: 'planned' },
    { icon: '🏆', key: 'badge', status: 'planned' },
    { icon: '📈', key: 'analytics', status: 'planned' },
];
</script>

<template>
    <AdminLayout :title="tk('pageTitle')">
        <!-- Header -->
        <div class="mb-6">
            <Link href="/admin/carbon-credits" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                {{ tk('header.back') }}
            </Link>
            <h1 class="text-2xl font-bold text-white">{{ tk('header.title') }}</h1>
            <p class="text-sm text-gray-400 mt-1">{{ tk('header.subtitle') }}</p>
        </div>

        <!-- Section Nav -->
        <div class="flex flex-wrap gap-2 mb-8">
            <button
                v-for="sec in sections"
                :key="sec.id"
                @click="activeSection = sec.id"
                class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm whitespace-nowrap transition-all"
                :class="activeSection === sec.id ? 'bg-green-500 text-white' : 'glass-dark text-gray-400 hover:text-white hover:bg-white/5'"
            >
                <span>{{ sec.icon }}</span>
                <span>{{ tk('nav.' + sec.id) }}</span>
            </button>
        </div>

        <!-- ═══════════════════ OVERVIEW ═══════════════════ -->
        <template v-if="activeSection === 'overview'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="text-5xl">🌍</span>
                        <div>
                            <h2 class="text-2xl font-bold text-white">{{ tk('overview.title') }}</h2>
                            <p class="text-gray-400 mt-1">{{ tk('overview.subtitle') }}</p>
                        </div>
                    </div>
                    <div class="text-gray-300 space-y-4">
                        <p><span class="text-white font-semibold">{{ tk('overview.p1Term') }}</span> {{ tk('overview.p1') }}</p>
                        <p>{{ tk('overview.p2a') }} <span class="text-green-400 font-semibold">TPIX TRADE</span> {{ tk('overview.p2b') }} <span class="text-primary-400 font-semibold">{{ tk('overview.p2Chain', { id: 4289 }) }}</span> {{ tk('overview.p2c') }}</p>
                    </div>
                </div>

                <!-- Key Concepts -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="concept in concepts" :key="concept.key" class="glass-dark rounded-xl border border-white/10 p-5">
                        <span class="text-3xl">{{ concept.icon }}</span>
                        <h3 class="text-white font-semibold mt-3 mb-1">{{ tk(`overview.concepts.${concept.key}.title`) }}</h3>
                        <p class="text-sm text-gray-400">{{ tk(`overview.concepts.${concept.key}.desc`) }}</p>
                    </div>
                </div>

                <!-- Project Types -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-6">{{ tk('overview.projectTypesTitle') }}</h3>
                    <div class="space-y-4">
                        <div v-for="pt in projectTypes" :key="pt.type" class="flex gap-4 p-4 rounded-xl bg-white/5">
                            <span class="text-3xl flex-shrink-0">{{ pt.icon }}</span>
                            <div>
                                <p class="text-white font-semibold">{{ tk(`overview.types.${pt.type}.label`) }} <span class="text-xs text-gray-500 font-mono">({{ pt.type }})</span></p>
                                <p class="text-sm text-gray-400">{{ tk(`overview.types.${pt.type}.desc`) }}</p>
                                <p class="text-xs text-green-400 mt-1">{{ tk('overview.example', { name: pt.example }) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ HOW IT WORKS ═══════════════════ -->
        <template v-if="activeSection === 'howItWorks'">
            <div class="space-y-6">
                <!-- User Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ tk('howItWorks.userFlowTitle') }}</h2>

                    <div class="space-y-8">
                        <div v-for="(step, i) in userSteps" :key="step.num">
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                    {{ step.num }}
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-white">{{ tk(`howItWorks.steps.${step.key}.title`) }}</h3>
                                    <p class="text-gray-400 mt-1">{{ tk(`howItWorks.steps.${step.key}.desc`) }}</p>
                                    <p class="text-sm text-gray-500 mt-2 p-3 rounded-lg bg-white/5">{{ tk(`howItWorks.steps.${step.key}.detail`) }}</p>
                                </div>
                            </div>
                            <div v-if="i < userSteps.length - 1" class="ml-5 h-6 border-l-2 border-green-500/30"></div>
                        </div>
                    </div>
                </div>

                <!-- Admin Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-6">{{ tk('howItWorks.adminFlowTitle') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div v-for="action in adminActions" :key="action.key" class="p-4 rounded-xl bg-white/5">
                            <span class="text-2xl">{{ action.icon }}</span>
                            <h4 class="text-white font-semibold mt-2">{{ tk(`howItWorks.actions.${action.key}.title`) }}</h4>
                            <p class="text-sm text-gray-400 mt-1">{{ tk(`howItWorks.actions.${action.key}.desc`) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Data Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ tk('howItWorks.dataFlowTitle') }}</h3>
                    <div class="space-y-3 font-mono text-sm">
                        <p class="text-gray-400 text-xs font-sans mb-2">{{ tk('howItWorks.purchaseFlow') }}</p>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-cyan-400">{{ tk('howItWorks.flow.userWallet') }}</span> → POST /api/v1/carbon-credits/purchase → <span class="text-green-400">Laravel API</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">CarbonCreditService</span> → {{ tk('howItWorks.flow.dbLock') }} → <span class="text-blue-400">carbon_credits table</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-blue-400">{{ tk('howItWorks.flow.database') }}</span> → {{ tk('howItWorks.flow.genSerial') }} → <span class="text-purple-400">{{ tk('howItWorks.flow.returnCredit') }}</span>
                        </div>

                        <p class="text-gray-400 text-xs font-sans mb-2 mt-6">{{ tk('howItWorks.retireFlow') }}</p>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-cyan-400">{{ tk('howItWorks.flow.creditOwner') }}</span> → POST /api/v1/carbon-credits/retire → <span class="text-green-400">Laravel API</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">CarbonCreditService</span> → {{ tk('howItWorks.flow.validateOwnership') }} → <span class="text-yellow-400">{{ tk('howItWorks.flow.genCertificate') }}</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-yellow-400">{{ tk('howItWorks.flow.certificate') }}</span> → {{ tk('howItWorks.flow.updateStatus') }} → <span class="text-purple-400">{{ tk('howItWorks.flow.permanent') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ SETUP ═══════════════════ -->
        <template v-if="activeSection === 'setup'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ tk('setup.title') }}</h2>

                    <div class="space-y-8">
                        <div v-for="step in setupSteps" :key="step.num">
                            <div>
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center text-white font-bold text-sm">{{ step.num }}</div>
                                    <h3 class="text-lg font-semibold text-white">{{ tk(`setup.steps.${step.key}.title`) }}</h3>
                                </div>
                                <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                    <pre>{{ tk(`setup.steps.${step.key}.code`) }}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ ADMIN GUIDE ═══════════════════ -->
        <template v-if="activeSection === 'admin'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ tk('admin.title') }}</h2>

                    <!-- Project Lifecycle -->
                    <h3 class="text-lg font-semibold text-white mb-4">{{ tk('admin.lifecycleTitle') }}</h3>
                    <div class="flex flex-wrap items-center gap-3 mb-8">
                        <div v-for="(state, i) in lifecycleStates" :key="state.key" class="flex items-center gap-3">
                            <div class="text-center">
                                <span :class="['text-xs px-3 py-1.5 rounded-full font-medium border', state.color]">{{ tk(`admin.states.${state.key}.label`) }}</span>
                                <p class="text-xs text-gray-500 mt-1">{{ tk(`admin.states.${state.key}.desc`) }}</p>
                            </div>
                            <svg v-if="i < lifecycleStates.length - 1" class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </div>

                    <!-- Database Schema -->
                    <h3 class="text-lg font-semibold text-white mb-4">{{ tk('admin.schemaTitle') }}</h3>
                    <div class="space-y-4">
                        <div v-for="table in schemaTables" :key="table.name" class="p-4 rounded-xl bg-white/5">
                            <p class="text-white font-semibold font-mono mb-2">{{ table.name }}</p>
                            <ul class="space-y-1">
                                <li v-for="(field, fi) in table.fields" :key="fi" class="text-xs text-gray-400 font-mono">{{ field }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Important Rules -->
                <div class="glass-dark rounded-2xl border border-yellow-500/20 p-8">
                    <h3 class="text-lg font-bold text-yellow-400 mb-4">{{ tk('admin.rulesTitle') }}</h3>
                    <ul class="space-y-3 text-sm text-gray-300">
                        <li v-for="rule in rules" :key="rule.key" class="flex gap-3">
                            <span :class="[rule.color, 'flex-shrink-0']">{{ rule.icon }}</span>
                            <span><strong>{{ tk(`admin.rules.${rule.key}.title`) }}</strong> {{ tk(`admin.rules.${rule.key}.desc`) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ API REFERENCE ═══════════════════ -->
        <template v-if="activeSection === 'api'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ tk('api.title') }}</h2>

                    <!-- Endpoints -->
                    <div class="space-y-3 mb-8">
                        <div v-for="ep in endpoints" :key="ep.path + ep.method" class="flex items-start gap-3 p-3 rounded-lg bg-white/5">
                            <span
                                class="inline-block px-2 py-0.5 rounded text-xs font-bold font-mono flex-shrink-0 mt-0.5"
                                :class="ep.method === 'GET' ? 'bg-green-500/20 text-green-400' : 'bg-blue-500/20 text-blue-400'"
                            >
                                {{ ep.method }}
                            </span>
                            <div class="flex-1">
                                <p class="text-white font-mono text-sm">{{ ep.path }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ tk(`api.endpoints.${ep.key}`) }}</p>
                            </div>
                            <span v-if="ep.auth" class="text-xs px-2 py-0.5 rounded-full bg-yellow-500/20 text-yellow-400 flex-shrink-0">{{ tk('api.walletBadge') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Purchase Request/Response -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ tk('api.purchaseExample') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-blue-400 mb-2 font-semibold">POST /api/v1/carbon-credits/purchase</p>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300">
                                <pre>{
  "project_id": 1,
  "amount": 10,
  "wallet_address": "0xAbC...123",
  "payment_currency": "TPIX",
  "payment_amount": "150.00",
  "tx_hash": "0xabc...def"
}</pre>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm text-green-400 mb-2 font-semibold">{{ tk('api.response', { code: 201 }) }}</p>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300">
                                <pre>{
  "success": true,
  "data": {
    "id": 42,
    "serial_number": "CC-A1B2C3D4-20260326",
    "amount": 10,
    "status": "active",
    "price_paid_usd": 150.00,
    "payment_currency": "TPIX"
  },
  "message": "Credits purchased."
}</pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Retire Request/Response -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ tk('api.retireExample') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-blue-400 mb-2 font-semibold">POST /api/v1/carbon-credits/retire</p>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300">
                                <pre>{
  "credit_id": 42,
  "amount": 5,
  "wallet_address": "0xAbC...123",
  "beneficiary_name": "Xman Studio",
  "retirement_reason": "Q1 2026 offset"
}</pre>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm text-green-400 mb-2 font-semibold">{{ tk('api.response', { code: 201 }) }}</p>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300">
                                <pre>{
  "success": true,
  "data": {
    "id": 7,
    "amount": 5,
    "beneficiary_name": "Xman Studio",
    "certificate_hash": "0x8f2a...b4c1",
    "retiree_address": "0xAbC...123"
  },
  "message": "Credits retired."
}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ BLOCKCHAIN ═══════════════════ -->
        <template v-if="activeSection === 'blockchain'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ tk('blockchain.title') }}</h2>

                    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                        <p class="text-yellow-400 font-semibold flex items-center gap-2">
                            <span>📌</span> {{ tk('blockchain.statusTitle') }}
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            {{ tk('blockchain.statusA') }} <strong>{{ tk('blockchain.statusModel') }}</strong> {{ tk('blockchain.statusB') }}
                        </p>
                    </div>

                    <h3 class="text-lg font-semibold text-white mb-4">{{ tk('blockchain.archTitle') }}</h3>
                    <div class="space-y-3 font-mono text-sm mb-8">
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">{{ tk('blockchain.arch.purchase') }}</span> {{ tk('blockchain.arch.purchaseA') }} <span class="text-cyan-400">tx_hash</span> {{ tk('blockchain.arch.purchaseB') }}
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-yellow-400">{{ tk('blockchain.arch.retire') }}</span> {{ tk('blockchain.arch.retireA') }} <span class="text-purple-400">certificate_hash</span> {{ tk('blockchain.arch.retireB') }}
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-blue-400">{{ tk('blockchain.arch.verify') }}</span> {{ tk('blockchain.arch.verifyA') }} <span class="text-primary-400">{{ tk('blockchain.arch.explorer') }}</span>
                        </div>
                    </div>

                    <h3 class="text-lg font-semibold text-white mb-4">{{ tk('blockchain.futureTitle') }}</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>// CarbonCreditNFT.sol (ERC-1155 — Semi-Fungible Token)
// Planned for future release

contract CarbonCreditNFT is ERC1155 {
    struct Project {
        string name;
        string projectType;
        uint256 totalCredits;
        uint256 availableCredits;
        uint256 retiredCredits;
        uint256 priceWei;
        bool active;
    }

    // Purchase: mint tokens to buyer
    function purchase(uint256 projectId, uint256 amount) external payable;

    // Retire: burn tokens permanently
    function retire(uint256 projectId, uint256 amount,
                    string calldata beneficiary) external;

    // Certificate: get retirement receipt
    function getCertificate(uint256 retirementId)
        external view returns (RetirementCertificate memory);
}

{{ tk('blockchain.whyErc1155.l1') }}
{{ tk('blockchain.whyErc1155.l2') }}
{{ tk('blockchain.whyErc1155.l3') }}
{{ tk('blockchain.whyErc1155.l4') }}</pre>
                    </div>
                </div>

                <!-- Chain Config -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ tk('blockchain.chainConfigTitle') }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div v-for="item in chainConfig" :key="item.labelKey" class="p-4 rounded-xl bg-white/5">
                            <p class="text-xs text-gray-400">{{ tk(`blockchain.chainConfig.${item.labelKey}`) }}</p>
                            <p class="text-white font-semibold mt-1 text-sm">{{ item.valueKey ? tk(`blockchain.chainConfig.${item.valueKey}`) : item.value }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ STANDARDS ═══════════════════ -->
        <template v-if="activeSection === 'standards'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ tk('standards.title') }}</h2>

                    <div class="space-y-6">
                        <div v-for="std in standards" :key="std.key" class="p-6 rounded-xl bg-white/5">
                            <div class="flex items-start gap-4">
                                <span class="text-4xl flex-shrink-0">{{ std.icon }}</span>
                                <div>
                                    <h3 class="text-white font-bold text-lg">{{ std.name }}</h3>
                                    <p class="text-xs text-primary-400 mb-2">{{ std.org }}</p>
                                    <p class="text-sm text-gray-400 mb-3">{{ tk(`standards.${std.key}.desc`) }}</p>
                                    <ul class="space-y-1">
                                        <li v-for="f in std.features" :key="f" class="text-sm text-gray-400 flex gap-2">
                                            <span class="text-green-400">•</span> {{ tk(`standards.${std.key}.${f}`) }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vintage Year -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ tk('standards.vintageTitle') }}</h3>
                    <p class="text-gray-400 mb-4">
                        <span class="text-white font-semibold">{{ tk('standards.vintageTerm') }}</span> {{ tk('standards.vintageBody') }}
                    </p>
                    <div class="p-4 rounded-xl bg-white/5 text-sm text-gray-400">
                        <p><span class="text-yellow-400">{{ tk('standards.noteLabel') }}</span> {{ tk('standards.note') }}</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ MOBILE APP ═══════════════════ -->
        <template v-if="activeSection === 'mobile'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="text-5xl">📱</span>
                        <div>
                            <h2 class="text-2xl font-bold text-white">{{ tk('mobile.title') }}</h2>
                            <p class="text-gray-400 mt-1">{{ tk('mobile.subtitle') }}</p>
                        </div>
                    </div>

                    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                        <p class="text-yellow-400 font-semibold flex items-center gap-2">
                            <span>⚡</span> {{ tk('mobile.comingSoon') }}
                        </p>
                        <p class="text-sm text-gray-400 mt-1">{{ tk('mobile.apiReady') }}</p>
                    </div>
                </div>

                <!-- Mobile Features -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="feature in mobileFeatures" :key="feature.key" class="glass-dark rounded-xl border border-white/10 p-5">
                        <div class="flex items-start justify-between mb-2">
                            <span class="text-3xl">{{ feature.icon }}</span>
                            <span
                                class="text-xs px-2 py-0.5 rounded-full font-medium"
                                :class="feature.status === 'ready' ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'"
                            >
                                {{ feature.status === 'ready' ? tk('mobile.statusReady') : tk('mobile.statusPlanned') }}
                            </span>
                        </div>
                        <h3 class="text-white font-semibold mt-2 mb-1">{{ tk(`mobile.features.${feature.key}.title`) }}</h3>
                        <p class="text-sm text-gray-400">{{ tk(`mobile.features.${feature.key}.desc`) }}</p>
                    </div>
                </div>

                <!-- API for Mobile -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ tk('mobile.apiTitle') }}</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>{{ tk('mobile.code.browse') }}
GET /api/v1/carbon-credits/projects
GET /api/v1/carbon-credits/projects/{slug}
GET /api/v1/carbon-credits/stats

{{ tk('mobile.code.purchaseRetire') }}
POST /api/v1/carbon-credits/purchase
  { project_id, amount, wallet_address, payment_currency, payment_amount, tx_hash }

POST /api/v1/carbon-credits/retire
  { credit_id, amount, wallet_address, beneficiary_name, retirement_reason }

{{ tk('mobile.code.portfolio') }}
GET /api/v1/carbon-credits/my-credits/{walletAddress}
GET /api/v1/carbon-credits/my-retirements/{walletAddress}

{{ tk('mobile.code.footer') }}</pre>
                    </div>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
