<script setup>
/**
 * TPIX TRADE - FoodPassport Documentation
 * คู่มือละเอียด — การทำงาน, ติดตั้ง, ตั้งค่า IoT, Mobile App Integration
 * Developed by Xman Studio
 */

import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const activeSection = ref('overview');

// computed เพื่อให้ป้ายเมนูเปลี่ยนภาษาทันทีเมื่อสลับ TH/EN
const sections = computed(() => [
    { id: 'overview', label: t('admin.foodPassportDocumentation.sections.overview'), icon: '🏗️' },
    { id: 'architecture', label: t('admin.foodPassportDocumentation.sections.architecture'), icon: '🔧' },
    { id: 'setup', label: t('admin.foodPassportDocumentation.sections.setup'), icon: '📦' },
    { id: 'iot', label: t('admin.foodPassportDocumentation.sections.iot'), icon: '📡' },
    { id: 'api', label: t('admin.foodPassportDocumentation.sections.api'), icon: '🔌' },
    { id: 'blockchain', label: t('admin.foodPassportDocumentation.sections.blockchain'), icon: '⛓️' },
    { id: 'mobile', label: t('admin.foodPassportDocumentation.sections.mobile'), icon: '📱' },
    { id: 'admin', label: t('admin.foodPassportDocumentation.sections.admin'), icon: '⚙️' },
]);
</script>

<template>
    <AdminLayout :title="t('admin.foodPassportDocumentation.pageTitle')">
        <!-- Header -->
        <div class="mb-6">
            <Link href="/admin/food-passport" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                FoodPassport
            </Link>
            <h1 class="text-2xl font-bold text-white">{{ t('admin.foodPassportDocumentation.header.title') }}</h1>
            <p class="text-sm text-gray-400 mt-1">{{ t('admin.foodPassportDocumentation.header.subtitle') }}</p>
        </div>

        <!-- Section Nav -->
        <div class="flex flex-wrap gap-2 mb-8">
            <button
                v-for="sec in sections"
                :key="sec.id"
                @click="activeSection = sec.id"
                class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm whitespace-nowrap transition-all"
                :class="activeSection === sec.id ? 'bg-primary-500 text-white' : 'glass-dark text-gray-400 hover:text-white hover:bg-white/5'"
            >
                <span>{{ sec.icon }}</span>
                <span>{{ sec.label }}</span>
            </button>
        </div>

        <!-- ═══════════════════ OVERVIEW ═══════════════════ -->
        <template v-if="activeSection === 'overview'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="text-5xl">🌾</span>
                        <div>
                            <h2 class="text-2xl font-bold text-white">{{ t('admin.foodPassportDocumentation.overview.title') }}</h2>
                            <p class="text-gray-400 mt-1">{{ t('admin.foodPassportDocumentation.overview.subtitle') }}</p>
                        </div>
                    </div>
                    <div class="prose prose-invert max-w-none text-gray-300 space-y-4">
                        <p>{{ t('admin.foodPassportDocumentation.overview.p1Before') }} <span class="text-white font-semibold">{{ t('admin.foodPassportDocumentation.overview.p1Highlight') }}</span> {{ t('admin.foodPassportDocumentation.overview.p1After') }}</p>
                        <p>{{ t('admin.foodPassportDocumentation.overview.p2Before') }} <span class="text-primary-400 font-semibold">TPIX Chain (Chain ID: 4289)</span> {{ t('admin.foodPassportDocumentation.overview.p2After') }}</p>
                    </div>
                </div>

                <!-- Key Features -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="feature in [
                        { id: 'register', icon: '📦', title: t('admin.foodPassportDocumentation.overview.features.register.title'), desc: t('admin.foodPassportDocumentation.overview.features.register.desc') },
                        { id: 'iot', icon: '📡', title: t('admin.foodPassportDocumentation.overview.features.iot.title'), desc: t('admin.foodPassportDocumentation.overview.features.iot.desc') },
                        { id: 'coldChain', icon: '🌡️', title: t('admin.foodPassportDocumentation.overview.features.coldChain.title'), desc: t('admin.foodPassportDocumentation.overview.features.coldChain.desc') },
                        { id: 'nft', icon: '🏆', title: t('admin.foodPassportDocumentation.overview.features.nft.title'), desc: t('admin.foodPassportDocumentation.overview.features.nft.desc') },
                        { id: 'fdp', icon: '🪙', title: t('admin.foodPassportDocumentation.overview.features.fdp.title'), desc: t('admin.foodPassportDocumentation.overview.features.fdp.desc') },
                        { id: 'mobile', icon: '📱', title: t('admin.foodPassportDocumentation.overview.features.mobile.title'), desc: t('admin.foodPassportDocumentation.overview.features.mobile.desc') },
                    ]" :key="feature.id" class="glass-dark rounded-xl border border-white/10 p-5">
                        <span class="text-3xl">{{ feature.icon }}</span>
                        <h3 class="text-white font-semibold mt-3 mb-1">{{ feature.title }}</h3>
                        <p class="text-sm text-gray-400">{{ feature.desc }}</p>
                    </div>
                </div>

                <!-- Flow Diagram -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-6">{{ t('admin.foodPassportDocumentation.overview.flow.title') }}</h3>
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <div v-for="(step, i) in [
                            { id: 'farm', icon: '🌱', label: t('admin.foodPassportDocumentation.overview.flow.farm'), stage: 'registered' },
                            { id: 'processing', icon: '🏭', label: t('admin.foodPassportDocumentation.overview.flow.processing'), stage: 'in_transit' },
                            { id: 'transport', icon: '🚛', label: t('admin.foodPassportDocumentation.overview.flow.transport'), stage: 'in_transit' },
                            { id: 'storage', icon: '🏪', label: t('admin.foodPassportDocumentation.overview.flow.storage'), stage: 'at_storage' },
                            { id: 'retail', icon: '🛒', label: t('admin.foodPassportDocumentation.overview.flow.retail'), stage: 'at_retail' },
                            { id: 'certified', icon: '✅', label: t('admin.foodPassportDocumentation.overview.flow.certified'), stage: 'certified' },
                        ]" :key="step.id" class="flex items-center gap-3">
                            <div class="text-center">
                                <div class="w-16 h-16 rounded-xl bg-gradient-to-br from-green-500/10 to-emerald-500/10 border border-green-500/20 flex items-center justify-center mb-1">
                                    <span class="text-2xl">{{ step.icon }}</span>
                                </div>
                                <span class="text-xs text-gray-400">{{ step.label }}</span>
                            </div>
                            <svg v-if="i < 5" class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-center text-xs text-gray-500 mt-4">{{ t('admin.foodPassportDocumentation.overview.flow.note') }}</p>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ ARCHITECTURE ═══════════════════ -->
        <template v-if="activeSection === 'architecture'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ t('admin.foodPassportDocumentation.architecture.title') }}</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Backend Stack -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">{{ t('admin.foodPassportDocumentation.architecture.backendTitle') }}</h3>
                            <div class="space-y-2">
                                <div v-for="item in [
                                    { label: 'FoodPassportApiController', desc: t('admin.foodPassportDocumentation.architecture.backend.apiController') },
                                    { label: 'FoodPassportService', desc: t('admin.foodPassportDocumentation.architecture.backend.service') },
                                    { label: 'IoTService', desc: t('admin.foodPassportDocumentation.architecture.backend.iotService') },
                                    { label: 'FoodPassportController (Admin)', desc: t('admin.foodPassportDocumentation.architecture.backend.adminController') },
                                ]" :key="item.label" class="flex gap-3 p-3 rounded-lg bg-white/5">
                                    <div class="w-2 h-2 rounded-full bg-green-500 mt-2 flex-shrink-0"></div>
                                    <div>
                                        <p class="text-white text-sm font-mono">{{ item.label }}</p>
                                        <p class="text-xs text-gray-400">{{ item.desc }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Models -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">{{ t('admin.foodPassportDocumentation.architecture.modelsTitle') }}</h3>
                            <div class="space-y-2">
                                <div v-for="item in [
                                    { label: 'FoodProduct', desc: t('admin.foodPassportDocumentation.architecture.models.product') },
                                    { label: 'FoodTrace', desc: t('admin.foodPassportDocumentation.architecture.models.trace') },
                                    { label: 'FoodCertificate', desc: t('admin.foodPassportDocumentation.architecture.models.certificate') },
                                    { label: 'IoTDevice', desc: t('admin.foodPassportDocumentation.architecture.models.device') },
                                ]" :key="item.label" class="flex gap-3 p-3 rounded-lg bg-white/5">
                                    <div class="w-2 h-2 rounded-full bg-blue-500 mt-2 flex-shrink-0"></div>
                                    <div>
                                        <p class="text-white text-sm font-mono">{{ item.label }}</p>
                                        <p class="text-xs text-gray-400">{{ item.desc }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Smart Contracts -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.architecture.contractsTitle') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-4 rounded-xl bg-gradient-to-br from-purple-500/5 to-blue-500/5 border border-purple-500/20">
                            <p class="text-white font-semibold mb-1">FoodPassportNFT.sol</p>
                            <p class="text-xs text-gray-400 mb-3">{{ t('admin.foodPassportDocumentation.architecture.nft.desc') }}</p>
                            <ul class="text-xs text-gray-400 space-y-1">
                                <li>• registerProduct() — {{ t('admin.foodPassportDocumentation.architecture.nft.registerProduct') }}</li>
                                <li>• addTrace() — {{ t('admin.foodPassportDocumentation.architecture.nft.addTrace') }}</li>
                                <li>• mintCertificate() — {{ t('admin.foodPassportDocumentation.architecture.nft.mintCertificate') }}</li>
                                <li>• getProductHistory() — {{ t('admin.foodPassportDocumentation.architecture.nft.getProductHistory') }}</li>
                            </ul>
                        </div>
                        <div class="p-4 rounded-xl bg-gradient-to-br from-amber-500/5 to-orange-500/5 border border-amber-500/20">
                            <p class="text-white font-semibold mb-1">FoodPassportToken.sol</p>
                            <p class="text-xs text-gray-400 mb-3">{{ t('admin.foodPassportDocumentation.architecture.token.desc') }}</p>
                            <ul class="text-xs text-gray-400 space-y-1">
                                <li>• {{ t('admin.foodPassportDocumentation.architecture.token.item1') }}</li>
                                <li>• {{ t('admin.foodPassportDocumentation.architecture.token.item2') }}</li>
                                <li>• {{ t('admin.foodPassportDocumentation.architecture.token.item3') }}</li>
                                <li>• {{ t('admin.foodPassportDocumentation.architecture.token.item4') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Data Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.architecture.dataFlow.title') }}</h3>
                    <div class="space-y-3 font-mono text-sm">
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-cyan-400">{{ t('admin.foodPassportDocumentation.architecture.dataFlow.iotDevice') }}</span> → HTTP POST /api/v1/food-passport/iot/ingest → <span class="text-green-400">Laravel API</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">Laravel API</span> → {{ t('admin.foodPassportDocumentation.architecture.dataFlow.validate') }} + IoTService.ingestData() → <span class="text-blue-400">{{ t('admin.foodPassportDocumentation.architecture.dataFlow.database') }} (food_traces)</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-blue-400">{{ t('admin.foodPassportDocumentation.architecture.dataFlow.database') }}</span> → FoodPassportService.addTrace() → <span class="text-purple-400">TPIX Blockchain</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-purple-400">Blockchain</span> → {{ t('admin.foodPassportDocumentation.architecture.dataFlow.contractEvent') }} → <span class="text-yellow-400">{{ t('admin.foodPassportDocumentation.architecture.dataFlow.reward') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ SETUP ═══════════════════ -->
        <template v-if="activeSection === 'setup'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ t('admin.foodPassportDocumentation.setup.title') }}</h2>

                    <!-- Step 1 -->
                    <div class="space-y-8">
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">1</div>
                                <h3 class="text-lg font-semibold text-white">{{ t('admin.foodPassportDocumentation.setup.step1.title') }}</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre>php artisan migrate

# {{ t('admin.foodPassportDocumentation.setup.step1.createsTables') }}
# - iot_devices      ({{ t('admin.foodPassportDocumentation.setup.step1.iotDevices') }})
# - food_products    ({{ t('admin.foodPassportDocumentation.setup.step1.foodProducts') }})
# - food_traces      ({{ t('admin.foodPassportDocumentation.setup.step1.foodTraces') }})
# - food_certificates ({{ t('admin.foodPassportDocumentation.setup.step1.foodCertificates') }})</pre>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">2</div>
                                <h3 class="text-lg font-semibold text-white">{{ t('admin.foodPassportDocumentation.setup.step2.title') }}</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre># Deploy FoodPassportNFT (ERC-721)
npx hardhat run scripts/deploy-food-passport-nft.js --network tpix

# Deploy FoodPassportToken (ERC-20 FDP)
npx hardhat run scripts/deploy-food-passport-token.js --network tpix

# {{ t('admin.foodPassportDocumentation.setup.step2.saveAddress') }}
FOOD_PASSPORT_NFT_ADDRESS=0x...
FOOD_PASSPORT_TOKEN_ADDRESS=0x...</pre>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">3</div>
                                <h3 class="text-lg font-semibold text-white">{{ t('admin.foodPassportDocumentation.setup.step3.title') }}</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre># .env — {{ t('admin.foodPassportDocumentation.setup.step3.addValues') }}
FOOD_PASSPORT_NFT_ADDRESS=0x...
FOOD_PASSPORT_TOKEN_ADDRESS=0x...
TPIX_RPC_URL=https://rpc.tpixchain.com
TPIX_CHAIN_ID=4289

# {{ t('admin.foodPassportDocumentation.setup.step3.iotSettings') }}
IOT_API_KEY_PREFIX=fpk_
IOT_BATCH_LIMIT=100
IOT_TEMP_ALERT_MIN=0
IOT_TEMP_ALERT_MAX=40</pre>
                            </div>
                        </div>

                        <!-- Step 4 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">4</div>
                                <h3 class="text-lg font-semibold text-white">{{ t('admin.foodPassportDocumentation.setup.step4.title') }}</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre>php artisan route:list --path=food-passport

# {{ t('admin.foodPassportDocumentation.setup.step4.shouldSee') }}
# GET  /api/v1/food-passport/products
# POST /api/v1/food-passport/register
# POST /api/v1/food-passport/trace
# POST /api/v1/food-passport/iot/ingest
# POST /api/v1/food-passport/iot/batch-ingest
# GET  /admin/food-passport
# ... ({{ t('admin.foodPassportDocumentation.setup.step4.andMore') }})</pre>
                            </div>
                        </div>

                        <!-- Step 5 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">5</div>
                                <h3 class="text-lg font-semibold text-white">{{ t('admin.foodPassportDocumentation.setup.step5.title') }}</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre># {{ t('admin.foodPassportDocumentation.setup.step5.testRegister') }}
curl -X POST /api/v1/food-passport/iot/register-device \
  -d '{"wallet_address":"0x...","name":"{{ t('admin.foodPassportDocumentation.setup.step5.sampleName') }}","type":"temperature"}'
# → data.device_id + data.api_key (fpk_...) — {{ t('admin.foodPassportDocumentation.setup.step5.keyShownOnce') }}

# {{ t('admin.foodPassportDocumentation.setup.step5.testIngest') }}
curl -X POST /api/v1/food-passport/iot/ingest \
  -H "Authorization: Bearer fpk_..." \
  -d '{"device_id":"TPIX-IOT-1A2B3C4D","product_id":1,"stage":"farm","temperature":4.5,"humidity":80}'

# {{ t('admin.foodPassportDocumentation.setup.step5.viewDashboard') }}
# {{ t('admin.foodPassportDocumentation.setup.step5.openInBrowser') }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ IOT DEVICES ═══════════════════ -->
        <template v-if="activeSection === 'iot'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-2">{{ t('admin.foodPassportDocumentation.iot.title') }}</h2>
                    <p class="text-gray-400 mb-6">{{ t('admin.foodPassportDocumentation.iot.subtitle') }}</p>

                    <!-- Supported Devices -->
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">{{ t('admin.foodPassportDocumentation.iot.supportedTitle') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div v-for="dev in [
                            { icon: '🌡️', type: 'temperature', name: t('admin.foodPassportDocumentation.iot.devices.temperature.name'), desc: t('admin.foodPassportDocumentation.iot.devices.temperature.desc') },
                            { icon: '💧', type: 'humidity', name: t('admin.foodPassportDocumentation.iot.devices.humidity.name'), desc: t('admin.foodPassportDocumentation.iot.devices.humidity.desc') },
                            { icon: '📍', type: 'gps', name: t('admin.foodPassportDocumentation.iot.devices.gps.name'), desc: t('admin.foodPassportDocumentation.iot.devices.gps.desc') },
                            { icon: '⚖️', type: 'weight', name: t('admin.foodPassportDocumentation.iot.devices.weight.name'), desc: t('admin.foodPassportDocumentation.iot.devices.weight.desc') },
                        ]" :key="dev.type" class="p-4 rounded-xl bg-white/5 border border-white/5">
                            <span class="text-2xl">{{ dev.icon }}</span>
                            <p class="text-white font-semibold mt-2">{{ dev.name }}</p>
                            <p class="text-xs text-gray-400 mt-1">{{ dev.desc }}</p>
                            <span class="inline-block mt-2 text-xs px-2 py-0.5 rounded-full bg-cyan-500/20 text-cyan-400 font-mono">{{ dev.type }}</span>
                        </div>
                    </div>
                </div>

                <!-- Registration -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.iot.register.title') }}</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>POST /api/v1/food-passport/iot/register-device
// {{ t('admin.foodPassportDocumentation.iot.register.authNote') }}

{
  "wallet_address": "0xAbC...123",     // {{ t('admin.foodPassportDocumentation.iot.register.walletComment') }}
  "type": "temperature",               // {{ t('admin.foodPassportDocumentation.iot.register.typeComment') }}: temperature, humidity, gps, camera, weight, ph, multi
  "name": "{{ t('admin.foodPassportDocumentation.iot.register.sampleName') }}",   // {{ t('admin.foodPassportDocumentation.iot.register.nameComment') }}
  "location": "{{ t('admin.foodPassportDocumentation.iot.register.sampleLocation') }}",    // {{ t('admin.foodPassportDocumentation.iot.register.locationComment') }}
  "config": { "protocol": "http", "interval_minutes": 15 }
}

// Response:
{
  "success": true,
  "data": {
    "id": 1,
    "device_id": "TPIX-IOT-1A2B3C4D",   // {{ t('admin.foodPassportDocumentation.iot.register.issuedBySystem') }}
    "owner_address": "0xabc...123",
    "has_api_key": true,
    "api_key": "fpk_a1b2c3d4e5f6..."      // ← {{ t('admin.foodPassportDocumentation.iot.register.shownOnce') }}
  }
}

// {{ t('admin.foodPassportDocumentation.iot.register.rotateNote') }}
POST /api/v1/food-passport/iot/rotate-key/{deviceId}
{ "wallet_address": "0xAbC...123" }   // → data.api_key</pre>
                    </div>
                </div>

                <!-- Data Ingestion -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.iot.ingest.title') }}</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto mb-4">
                        <pre>POST /api/v1/food-passport/iot/ingest
Authorization: Bearer fpk_a1b2c3d4e5f6...

{
  "device_id": "TPIX-IOT-1A2B3C4D",    // {{ t('admin.foodPassportDocumentation.iot.ingest.deviceComment') }}
  "product_id": 42,                    // {{ t('admin.foodPassportDocumentation.iot.ingest.productComment') }}
  "stage": "storage",                  // {{ t('admin.foodPassportDocumentation.iot.ingest.stageComment') }}: farm, processing, storage, transport, retail
  "location": "{{ t('admin.foodPassportDocumentation.iot.ingest.sampleLocation') }}",
  "temperature": 4.5,                  // °C (optional)
  "humidity": 78.2,                    // % (optional)
  "weight_kg": 25.3,                   // kg (optional)
  "ph_level": 6.8,                     // (optional)
  "data": {                            // {{ t('admin.foodPassportDocumentation.iot.ingest.extraComment') }}
    "battery": 85,
    "signal_strength": -42,
    "gps": { "lat": 13.7563, "lng": 100.5018 }
  }
}</pre>
                    </div>

                    <h4 class="text-white font-semibold mb-2">{{ t('admin.foodPassportDocumentation.iot.batch.title') }}</h4>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>POST /api/v1/food-passport/iot/batch-ingest
Authorization: Bearer fpk_a1b2c3d4e5f6...

{
  "records": [
    { "device_id": "TPIX-IOT-1A2B3C4D", "product_id": 42, "stage": "storage", "temperature": 4.5, "humidity": 78 },
    { "device_id": "TPIX-IOT-1A2B3C4D", "product_id": 43, "stage": "storage", "temperature": 3.8, "humidity": 80 },
    { "device_id": "TPIX-IOT-1A2B3C4D", "product_id": 42, "stage": "storage", "temperature": 5.1, "humidity": 76 }
  ]
}
// {{ t('admin.foodPassportDocumentation.iot.batch.limitNote') }}
// {{ t('admin.foodPassportDocumentation.iot.batch.mismatchNote') }}</pre>
                    </div>
                </div>

                <!-- Arduino/ESP32 Example -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.iot.arduino.title') }}</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>// ESP32 + DHT22 Temperature/Humidity Sensor
#include &lt;WiFi.h&gt;
#include &lt;HTTPClient.h&gt;
#include &lt;DHT.h&gt;

#define DHTPIN 4
#define DHTTYPE DHT22
DHT dht(DHTPIN, DHTTYPE);

const char* serverUrl = "https://yourdomain.com/api/v1/food-passport/iot/ingest";
const char* apiKey = "fpk_a1b2c3d4e5f6...";
const char* deviceId = "TPIX-IOT-1A2B3C4D";
const int productId = 42;

void sendData() {
  float temp = dht.readTemperature();
  float hum = dht.readHumidity();

  if (isnan(temp) || isnan(hum)) return;

  HTTPClient http;
  http.begin(serverUrl);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + apiKey);

  String payload = "{";
  payload += "\"device_id\":\"" + String(deviceId) + "\",";
  payload += "\"product_id\":" + String(productId) + ",";
  payload += "\"stage\":\"storage\",";
  payload += "\"temperature\":" + String(temp, 1) + ",";
  payload += "\"humidity\":" + String(hum, 1);
  payload += "}";

  int httpCode = http.POST(payload);
  http.end();
}

void setup() {
  dht.begin();
  // WiFi connect...
}

void loop() {
  sendData();
  delay(900000); // {{ t('admin.foodPassportDocumentation.iot.arduino.every15') }}
}</pre>
                    </div>
                </div>

                <!-- Config Management -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.iot.manage.title') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div v-for="action in [
                            { id: 'status', title: t('admin.foodPassportDocumentation.iot.manage.status.title'), desc: t('admin.foodPassportDocumentation.iot.manage.status.desc'), color: 'green' },
                            { id: 'regenerate', title: t('admin.foodPassportDocumentation.iot.manage.regenerate.title'), desc: t('admin.foodPassportDocumentation.iot.manage.regenerate.desc'), color: 'yellow' },
                            { id: 'traceCount', title: t('admin.foodPassportDocumentation.iot.manage.traceCount.title'), desc: t('admin.foodPassportDocumentation.iot.manage.traceCount.desc'), color: 'blue' },
                            { id: 'delete', title: t('admin.foodPassportDocumentation.iot.manage.delete.title'), desc: t('admin.foodPassportDocumentation.iot.manage.delete.desc'), color: 'red' },
                        ]" :key="action.id" class="p-4 rounded-xl bg-white/5">
                            <p class="text-white font-semibold">{{ action.title }}</p>
                            <p class="text-xs text-gray-400 mt-1">{{ action.desc }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ API REFERENCE ═══════════════════ -->
        <template v-if="activeSection === 'api'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ t('admin.foodPassportDocumentation.api.title') }}</h2>

                    <div class="space-y-3">
                        <div v-for="ep in [
                            { method: 'GET', path: '/api/v1/food-passport/products', desc: t('admin.foodPassportDocumentation.api.endpoints.products') },
                            { method: 'GET', path: '/api/v1/food-passport/products/{id}', desc: t('admin.foodPassportDocumentation.api.endpoints.productDetail') },
                            { method: 'GET', path: '/api/v1/food-passport/verify/{id}', desc: t('admin.foodPassportDocumentation.api.endpoints.verify') },
                            { method: 'GET', path: '/api/v1/food-passport/stats', desc: t('admin.foodPassportDocumentation.api.endpoints.stats') },
                            { method: 'GET', path: '/api/v1/food-passport/certificates', desc: t('admin.foodPassportDocumentation.api.endpoints.certificates') },
                            { method: 'GET', path: '/api/v1/food-passport/sensor-data/{productId}', desc: t('admin.foodPassportDocumentation.api.endpoints.sensorData') },
                            { method: 'POST', path: '/api/v1/food-passport/register', desc: t('admin.foodPassportDocumentation.api.endpoints.register') },
                            { method: 'POST', path: '/api/v1/food-passport/trace', desc: t('admin.foodPassportDocumentation.api.endpoints.trace') },
                            { method: 'POST', path: '/api/v1/food-passport/mint', desc: t('admin.foodPassportDocumentation.api.endpoints.mint') },
                            { method: 'GET', path: '/api/v1/food-passport/my-products?wallet_address=0x...', desc: t('admin.foodPassportDocumentation.api.endpoints.myProducts') },
                            { method: 'POST', path: '/api/v1/food-passport/iot/ingest', desc: t('admin.foodPassportDocumentation.api.endpoints.ingest') },
                            { method: 'POST', path: '/api/v1/food-passport/iot/batch-ingest', desc: t('admin.foodPassportDocumentation.api.endpoints.batchIngest') },
                            { method: 'POST', path: '/api/v1/food-passport/iot/register-device', desc: t('admin.foodPassportDocumentation.api.endpoints.registerDevice') },
                            { method: 'POST', path: '/api/v1/food-passport/iot/rotate-key/{deviceId}', desc: t('admin.foodPassportDocumentation.api.endpoints.rotateKey') },
                            { method: 'GET', path: '/api/v1/food-passport/iot/my-devices?wallet_address=0x...', desc: t('admin.foodPassportDocumentation.api.endpoints.myDevices') },
                            { method: 'GET', path: '/api/v1/food-passport/iot/test/{deviceId}', desc: t('admin.foodPassportDocumentation.api.endpoints.testDevice') },
                            { method: 'GET', path: '/api/v1/food-passport/iot/config/{deviceId}', desc: t('admin.foodPassportDocumentation.api.endpoints.deviceConfig') },
                            { method: 'GET', path: '/api/v1/food-passport/fdp-token-info', desc: t('admin.foodPassportDocumentation.api.endpoints.fdpTokenInfo') },
                        ]" :key="ep.path + ep.method" class="flex items-start gap-3 p-3 rounded-lg bg-white/5">
                            <span
                                class="inline-block px-2 py-0.5 rounded text-xs font-bold font-mono flex-shrink-0 mt-0.5"
                                :class="ep.method === 'GET' ? 'bg-green-500/20 text-green-400' : 'bg-blue-500/20 text-blue-400'"
                            >
                                {{ ep.method }}
                            </span>
                            <div>
                                <p class="text-white font-mono text-sm">{{ ep.path }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ ep.desc }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Response Format -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.api.responseFormat') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-green-400 mb-2 font-semibold">{{ t('admin.foodPassportDocumentation.api.successResponse') }}</p>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300">
                                <pre>{
  "success": true,
  "data": { ... },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150
  }
}</pre>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm text-red-400 mb-2 font-semibold">{{ t('admin.foodPassportDocumentation.api.errorResponse') }}</p>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300">
                                <pre>{
  "success": false,
  "error": {
    "code": "DEVICE_INACTIVE",
    "message": "Device is not active"
  }
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
                    <h2 class="text-xl font-bold text-white mb-6">{{ t('admin.foodPassportDocumentation.blockchain.title') }}</h2>

                    <div class="space-y-8">
                        <!-- NFT Contract -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                                <span class="text-purple-400">⛓️</span> FoodPassportNFT (ERC-721)
                            </h3>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre>// SPDX-License-Identifier: MIT
// Contract Functions:

registerProduct(name, category, origin)
  → {{ t('admin.foodPassportDocumentation.blockchain.nft.registerProduct') }}
  → Returns: productId

addTrace(productId, stage, location, data)
  → {{ t('admin.foodPassportDocumentation.blockchain.nft.addTrace') }}
  → Emits: TraceAdded event

mintCertificate(productId)
  → {{ t('admin.foodPassportDocumentation.blockchain.nft.mintCertificate') }}
  → Mints ERC-721 token
  → Emits: CertificateMinted event

getProductHistory(productId)
  → {{ t('admin.foodPassportDocumentation.blockchain.nft.getProductHistory') }}
  → Returns: Trace[]</pre>
                            </div>
                        </div>

                        <!-- Token Contract -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                                <span class="text-amber-400">🪙</span> FoodPassportToken — FDP (ERC-20)
                            </h3>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre>// Token Details:
Name:     FoodPassport Token
Symbol:   FDP
Decimals: 18
Chain:    TPIX Chain (4289)

// Reward System:
mintCertificate → +100 FDP ({{ t('admin.foodPassportDocumentation.blockchain.token.toOwner') }})
addTrace        → +1 FDP ({{ t('admin.foodPassportDocumentation.blockchain.token.toRecorder') }})

// Staking:
stake(amount) → {{ t('admin.foodPassportDocumentation.blockchain.token.stake') }}
unstake()     → {{ t('admin.foodPassportDocumentation.blockchain.token.unstake') }}
getTrustScore(address) → {{ t('admin.foodPassportDocumentation.blockchain.token.trustScore') }}</pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chain Config -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.blockchain.chainConfig.title') }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div v-for="item in [
                            { id: 'chainId', label: 'Chain ID', value: '4289' },
                            { id: 'rpc', label: 'RPC URL', value: 'rpc.tpixchain.com' },
                            { id: 'gas', label: t('admin.foodPassportDocumentation.blockchain.chainConfig.gasLabel'), value: t('admin.foodPassportDocumentation.blockchain.chainConfig.gasValue') },
                            { id: 'blockTime', label: t('admin.foodPassportDocumentation.blockchain.chainConfig.blockTimeLabel'), value: t('admin.foodPassportDocumentation.blockchain.chainConfig.blockTimeValue') },
                        ]" :key="item.id" class="p-4 rounded-xl bg-white/5">
                            <p class="text-xs text-gray-400">{{ item.label }}</p>
                            <p class="text-white font-semibold mt-1">{{ item.value }}</p>
                        </div>
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
                            <h2 class="text-2xl font-bold text-white">{{ t('admin.foodPassportDocumentation.mobile.title') }}</h2>
                            <p class="text-gray-400 mt-1">{{ t('admin.foodPassportDocumentation.mobile.subtitle') }}</p>
                        </div>
                    </div>

                    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                        <p class="text-yellow-400 font-semibold flex items-center gap-2">
                            <span>⚡</span> {{ t('admin.foodPassportDocumentation.mobile.comingSoon') }}
                        </p>
                        <p class="text-sm text-gray-400 mt-1">{{ t('admin.foodPassportDocumentation.mobile.comingSoonDesc') }}</p>
                    </div>
                </div>

                <!-- Mobile Features -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="feature in [
                        { id: 'qr', icon: '📷', title: t('admin.foodPassportDocumentation.mobile.features.qr.title'), desc: t('admin.foodPassportDocumentation.mobile.features.qr.desc'), status: 'ready' },
                        { id: 'iotDashboard', icon: '📊', title: t('admin.foodPassportDocumentation.mobile.features.iotDashboard.title'), desc: t('admin.foodPassportDocumentation.mobile.features.iotDashboard.desc'), status: 'ready' },
                        { id: 'registration', icon: '📝', title: t('admin.foodPassportDocumentation.mobile.features.registration.title'), desc: t('admin.foodPassportDocumentation.mobile.features.registration.desc'), status: 'ready' },
                        { id: 'push', icon: '🔔', title: t('admin.foodPassportDocumentation.mobile.features.push.title'), desc: t('admin.foodPassportDocumentation.mobile.features.push.desc'), status: 'planned' },
                        { id: 'wallet', icon: '👛', title: t('admin.foodPassportDocumentation.mobile.features.wallet.title'), desc: t('admin.foodPassportDocumentation.mobile.features.wallet.desc'), status: 'ready' },
                        { id: 'map', icon: '🗺️', title: t('admin.foodPassportDocumentation.mobile.features.map.title'), desc: t('admin.foodPassportDocumentation.mobile.features.map.desc'), status: 'planned' },
                    ]" :key="feature.id" class="glass-dark rounded-xl border border-white/10 p-5">
                        <div class="flex items-start justify-between mb-2">
                            <span class="text-3xl">{{ feature.icon }}</span>
                            <span
                                class="text-xs px-2 py-0.5 rounded-full font-medium"
                                :class="feature.status === 'ready' ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'"
                            >
                                {{ feature.status === 'ready' ? t('admin.foodPassportDocumentation.mobile.statusReady') : t('admin.foodPassportDocumentation.mobile.statusPlanned') }}
                            </span>
                        </div>
                        <h3 class="text-white font-semibold mt-2 mb-1">{{ feature.title }}</h3>
                        <p class="text-sm text-gray-400">{{ feature.desc }}</p>
                    </div>
                </div>

                <!-- API Endpoints for Mobile -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">{{ t('admin.foodPassportDocumentation.mobile.endpointsTitle') }}</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>// {{ t('admin.foodPassportDocumentation.mobile.code.qr') }}
GET /api/v1/food-passport/verify/{productId}

// {{ t('admin.foodPassportDocumentation.mobile.code.register') }}
POST /api/v1/food-passport/register
  { name, category, origin, producer_address }

// {{ t('admin.foodPassportDocumentation.mobile.code.myProducts') }}
GET /api/v1/food-passport/my-products?wallet_address=0x...

// {{ t('admin.foodPassportDocumentation.mobile.code.iotDashboard') }}
GET /api/v1/food-passport/sensor-data/{productId}
GET /api/v1/food-passport/iot/my-devices?wallet_address=0x...

// FDP Token
GET /api/v1/food-passport/fdp-token-info

// {{ t('admin.foodPassportDocumentation.mobile.code.allRest') }}</pre>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ ADMIN GUIDE ═══════════════════ -->
        <template v-if="activeSection === 'admin'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">{{ t('admin.foodPassportDocumentation.adminGuide.title') }}</h2>

                    <div class="space-y-8">
                        <!-- Dashboard -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">📊 {{ t('admin.foodPassportDocumentation.adminGuide.dashboard.title') }}</h3>
                            <p class="text-gray-400 mb-3">{{ t('admin.foodPassportDocumentation.adminGuide.dashboard.desc') }}</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div v-for="stat in [
                                    { id: 's1', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s1') },
                                    { id: 's2', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s2') },
                                    { id: 's3', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s3') },
                                    { id: 's4', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s4') },
                                    { id: 's5', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s5') },
                                    { id: 's6', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s6') },
                                    { id: 's7', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s7') },
                                    { id: 's8', text: t('admin.foodPassportDocumentation.adminGuide.dashboard.stats.s8') },
                                ]" :key="stat.id" class="text-sm text-gray-400 flex items-start gap-2">
                                    <div class="w-1.5 h-1.5 rounded-full bg-primary-500 mt-1.5 flex-shrink-0"></div>
                                    {{ stat.text }}
                                </div>
                            </div>
                        </div>

                        <!-- Product Management -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">📦 {{ t('admin.foodPassportDocumentation.adminGuide.products.title') }}</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-green-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.products.item1') }}</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.products.item2') }}</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.products.item3') }}</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.products.item4') }}</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.products.item5') }}</li>
                            </ul>
                        </div>

                        <!-- Device Management -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">📡 {{ t('admin.foodPassportDocumentation.adminGuide.devices.title') }}</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.devices.item1') }}</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.devices.item2') }}</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.devices.item3') }}</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.devices.item4') }}</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.devices.item5') }}</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.devices.item6') }}</li>
                            </ul>
                        </div>

                        <!-- Certificates -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">🏆 {{ t('admin.foodPassportDocumentation.adminGuide.certificates.title') }}</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-yellow-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.certificates.item1') }}</li>
                                <li class="flex gap-2"><span class="text-yellow-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.certificates.item2') }}</li>
                                <li class="flex gap-2"><span class="text-yellow-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.certificates.item3') }}</li>
                            </ul>
                        </div>

                        <!-- Temperature Alerts -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">🌡️ {{ t('admin.foodPassportDocumentation.adminGuide.alerts.title') }}</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-red-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.alerts.item1') }}</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.alerts.item2') }}</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.alerts.item3') }}</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.alerts.item4') }}</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> {{ t('admin.foodPassportDocumentation.adminGuide.alerts.item5') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
