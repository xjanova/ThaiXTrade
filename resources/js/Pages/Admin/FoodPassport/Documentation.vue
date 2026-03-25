<script setup>
/**
 * TPIX TRADE - FoodPassport Documentation
 * คู่มือละเอียด — การทำงาน, ติดตั้ง, ตั้งค่า IoT, Mobile App Integration
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const activeSection = ref('overview');

const sections = [
    { id: 'overview', label: 'ภาพรวมระบบ', icon: '🏗️' },
    { id: 'architecture', label: 'สถาปัตยกรรม', icon: '🔧' },
    { id: 'setup', label: 'การติดตั้ง', icon: '📦' },
    { id: 'iot', label: 'IoT Devices', icon: '📡' },
    { id: 'api', label: 'API Reference', icon: '🔌' },
    { id: 'blockchain', label: 'Smart Contracts', icon: '⛓️' },
    { id: 'mobile', label: 'Mobile App', icon: '📱' },
    { id: 'admin', label: 'Admin Guide', icon: '⚙️' },
];
</script>

<template>
    <AdminLayout title="FoodPassport Docs">
        <!-- Header -->
        <div class="mb-6">
            <Link href="/admin/food-passport" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                FoodPassport
            </Link>
            <h1 class="text-2xl font-bold text-white">FoodPassport Documentation</h1>
            <p class="text-sm text-gray-400 mt-1">คู่มือติดตั้ง ตั้งค่า และการทำงานของระบบ Food Traceability ทั้งหมด</p>
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
                            <h2 class="text-2xl font-bold text-white">FoodPassport คืออะไร?</h2>
                            <p class="text-gray-400 mt-1">ระบบตรวจสอบย้อนกลับอาหารบน Blockchain + IoT</p>
                        </div>
                    </div>
                    <div class="prose prose-invert max-w-none text-gray-300 space-y-4">
                        <p>FoodPassport เป็นระบบ <span class="text-white font-semibold">Food Traceability</span> ที่ผสาน Blockchain กับ IoT Sensor เพื่อติดตามสินค้าเกษตรตั้งแต่ฟาร์มจนถึงผู้บริโภค ทุกข้อมูลถูกบันทึกอย่างโปร่งใส ไม่สามารถแก้ไขได้</p>
                        <p>ระบบทำงานบน <span class="text-primary-400 font-semibold">TPIX Chain (Chain ID: 4289)</span> — Gasless, ไม่มีค่าธรรมเนียม</p>
                    </div>
                </div>

                <!-- Key Features -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="feature in [
                        { icon: '📦', title: 'ลงทะเบียนสินค้า', desc: 'เกษตรกร/ผู้ผลิตลงทะเบียนสินค้าพร้อม Batch ID, แหล่งผลิต, หมวดหมู่ บันทึกบน Blockchain' },
                        { icon: '📡', title: 'IoT Sensor Tracking', desc: 'ติดตามอุณหภูมิ ความชื้น pH น้ำหนัก GPS อัตโนมัติผ่าน IoT ทุกจุดใน Supply Chain' },
                        { icon: '🌡️', title: 'Cold Chain Alert', desc: 'แจ้งเตือนเมื่ออุณหภูมิ/สภาพแวดล้อมผิดปกติ ป้องกันสินค้าเสียหาย' },
                        { icon: '🏆', title: 'NFT Certificate', desc: 'ออกใบรับรอง NFT (ERC-721) เมื่อสินค้าผ่านมาตรฐาน ตรวจสอบได้ตลอดกาล' },
                        { icon: '🪙', title: 'FDP Token Reward', desc: 'รับ FDP Token (ERC-20) เมื่อใช้งาน — 100 FDP/ใบรับรอง, 1 FDP/Trace' },
                        { icon: '📱', title: 'Mobile App Ready', desc: 'พร้อมเชื่อมต่อกับ Mobile App สแกน QR ดูข้อมูลสินค้า ผ่าน API' },
                    ]" :key="feature.title" class="glass-dark rounded-xl border border-white/10 p-5">
                        <span class="text-3xl">{{ feature.icon }}</span>
                        <h3 class="text-white font-semibold mt-3 mb-1">{{ feature.title }}</h3>
                        <p class="text-sm text-gray-400">{{ feature.desc }}</p>
                    </div>
                </div>

                <!-- Flow Diagram -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-6">Supply Chain Flow</h3>
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <div v-for="(step, i) in [
                            { icon: '🌱', label: 'Farm', stage: 'registered' },
                            { icon: '🏭', label: 'Processing', stage: 'in_transit' },
                            { icon: '🚛', label: 'Transport', stage: 'in_transit' },
                            { icon: '🏪', label: 'Storage', stage: 'at_storage' },
                            { icon: '🛒', label: 'Retail', stage: 'at_retail' },
                            { icon: '✅', label: 'Certified', stage: 'certified' },
                        ]" :key="step.label" class="flex items-center gap-3">
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
                    <p class="text-center text-xs text-gray-500 mt-4">ทุกจุดมี IoT Sensor บันทึกข้อมูลอัตโนมัติ → Blockchain</p>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ ARCHITECTURE ═══════════════════ -->
        <template v-if="activeSection === 'architecture'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">System Architecture</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Backend Stack -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Backend (Laravel)</h3>
                            <div class="space-y-2">
                                <div v-for="item in [
                                    { label: 'FoodPassportApiController', desc: '17 API endpoints (products, traces, certificates, IoT)' },
                                    { label: 'FoodPassportService', desc: 'Business logic — register, trace, mint, verify' },
                                    { label: 'IoTService', desc: 'Device management, data ingestion, batch processing' },
                                    { label: 'FoodPassportController (Admin)', desc: 'Dashboard, CRUD, alerts, device management' },
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
                            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Database Models</h3>
                            <div class="space-y-2">
                                <div v-for="item in [
                                    { label: 'FoodProduct', desc: 'สินค้า — name, batch_id, category, origin, status, producer_address' },
                                    { label: 'FoodTrace', desc: 'ข้อมูล trace — stage, temperature, humidity, weight, pH, GPS, device' },
                                    { label: 'FoodCertificate', desc: 'NFT certificate — token_id, tx_hash, certificate_data, status' },
                                    { label: 'IoTDevice', desc: 'อุปกรณ์ IoT — device_id, type, owner, config (API key), last_ping' },
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
                    <h3 class="text-lg font-bold text-white mb-4">Smart Contracts (TPIX Chain)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-4 rounded-xl bg-gradient-to-br from-purple-500/5 to-blue-500/5 border border-purple-500/20">
                            <p class="text-white font-semibold mb-1">FoodPassportNFT.sol</p>
                            <p class="text-xs text-gray-400 mb-3">ERC-721 — ใบรับรองอาหาร NFT</p>
                            <ul class="text-xs text-gray-400 space-y-1">
                                <li>• registerProduct() — ลงทะเบียนสินค้า</li>
                                <li>• addTrace() — เพิ่ม trace data</li>
                                <li>• mintCertificate() — ออกใบรับรอง NFT</li>
                                <li>• getProductHistory() — ดูประวัติสินค้า</li>
                            </ul>
                        </div>
                        <div class="p-4 rounded-xl bg-gradient-to-br from-amber-500/5 to-orange-500/5 border border-amber-500/20">
                            <p class="text-white font-semibold mb-1">FoodPassportToken.sol</p>
                            <p class="text-xs text-gray-400 mb-3">ERC-20 — FDP Reward Token</p>
                            <ul class="text-xs text-gray-400 space-y-1">
                                <li>• 100 FDP ต่อการออกใบรับรอง</li>
                                <li>• 1 FDP ต่อการบันทึก trace</li>
                                <li>• Staking สำหรับ Trust Score</li>
                                <li>• Governance voting rights</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Data Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">Data Flow</h3>
                    <div class="space-y-3 font-mono text-sm">
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-cyan-400">IoT Device</span> → HTTP POST /api/v1/food-passport/iot/ingest → <span class="text-green-400">Laravel API</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">Laravel API</span> → Validate + IoTService.ingestData() → <span class="text-blue-400">Database (food_traces)</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-blue-400">Database</span> → FoodPassportService.addTrace() → <span class="text-purple-400">TPIX Blockchain</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-purple-400">Blockchain</span> → Smart Contract event → <span class="text-yellow-400">NFT Certificate / FDP Reward</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ SETUP ═══════════════════ -->
        <template v-if="activeSection === 'setup'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">การติดตั้งระบบ FoodPassport</h2>

                    <!-- Step 1 -->
                    <div class="space-y-8">
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">1</div>
                                <h3 class="text-lg font-semibold text-white">Run Migration</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre>php artisan migrate

# สร้างตาราง:
# - iot_devices      (อุปกรณ์ IoT)
# - food_products    (สินค้าเกษตร)
# - food_traces      (ข้อมูล trace ทุกจุด)
# - food_certificates (ใบรับรอง NFT)</pre>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">2</div>
                                <h3 class="text-lg font-semibold text-white">Deploy Smart Contracts</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre># Deploy FoodPassportNFT (ERC-721)
npx hardhat run scripts/deploy-food-passport-nft.js --network tpix

# Deploy FoodPassportToken (ERC-20 FDP)
npx hardhat run scripts/deploy-food-passport-token.js --network tpix

# บันทึก Contract Address ลงใน .env
FOOD_PASSPORT_NFT_ADDRESS=0x...
FOOD_PASSPORT_TOKEN_ADDRESS=0x...</pre>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">3</div>
                                <h3 class="text-lg font-semibold text-white">ตั้งค่า Environment</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre># .env — เพิ่มค่าเหล่านี้
FOOD_PASSPORT_NFT_ADDRESS=0x...
FOOD_PASSPORT_TOKEN_ADDRESS=0x...
TPIX_RPC_URL=https://rpc.tpixchain.com
TPIX_CHAIN_ID=4289

# IoT Settings
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
                                <h3 class="text-lg font-semibold text-white">ตรวจสอบ Routes</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre>php artisan route:list --path=food-passport

# ต้องเห็น:
# GET  /api/v1/food-passport/products
# POST /api/v1/food-passport/register
# POST /api/v1/food-passport/trace
# POST /api/v1/food-passport/iot/ingest
# POST /api/v1/food-passport/iot/batch-ingest
# GET  /admin/food-passport
# ... (และอื่นๆ)</pre>
                            </div>
                        </div>

                        <!-- Step 5 -->
                        <div>
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-sm">5</div>
                                <h3 class="text-lg font-semibold text-white">Test ระบบ</h3>
                            </div>
                            <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                <pre># ทดสอบ IoT device registration
curl -X POST /api/v1/food-passport/iot/register-device \
  -d '{"device_id":"SENSOR-001","type":"temperature","owner_address":"0x..."}'

# ทดสอบ data ingestion
curl -X POST /api/v1/food-passport/iot/ingest \
  -d '{"device_id":"SENSOR-001","product_id":1,"temperature":4.5,"humidity":80}'

# ดู Admin dashboard
# เปิด /admin/food-passport ในเบราว์เซอร์</pre>
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
                    <h2 class="text-xl font-bold text-white mb-2">IoT Device Setup Guide</h2>
                    <p class="text-gray-400 mb-6">วิธีติดตั้งและตั้งค่า IoT Sensor เพื่อส่งข้อมูลเข้าระบบ FoodPassport</p>

                    <!-- Supported Devices -->
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Supported Device Types</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div v-for="dev in [
                            { icon: '🌡️', type: 'temperature', name: 'Temperature Sensor', desc: 'วัดอุณหภูมิ -40°C to 125°C, ความแม่นยำ ±0.5°C' },
                            { icon: '💧', type: 'humidity', name: 'Humidity Sensor', desc: 'วัดความชื้นสัมพัทธ์ 0-100% RH' },
                            { icon: '📍', type: 'gps', name: 'GPS Tracker', desc: 'ติดตามตำแหน่ง real-time, geofencing' },
                            { icon: '⚖️', type: 'weight', name: 'Weight Scale', desc: 'ชั่งน้ำหนักอัตโนมัติ, ตรวจจับการเปลี่ยนแปลง' },
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
                    <h3 class="text-lg font-bold text-white mb-4">1. ลงทะเบียน Device</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>POST /api/v1/food-passport/iot/register-device

{
  "device_id": "SENSOR-FARM-001",     // รหัส unique ของอุปกรณ์
  "type": "temperature",               // ประเภท: temperature, humidity, gps, weight, multi
  "name": "ฟาร์มสุรินทร์ Sensor #1",   // ชื่อเรียก (optional)
  "owner_address": "0xAbC...123",      // Wallet address เจ้าของ
  "location": "สุรินทร์, ประเทศไทย"     // ที่ตั้ง (optional)
}

// Response:
{
  "success": true,
  "data": {
    "id": 1,
    "device_id": "SENSOR-FARM-001",
    "config": {
      "api_key": "fpk_a1b2c3d4e5f6...",   // ← ใช้ key นี้ในการส่งข้อมูล
      "endpoint": "/api/v1/food-passport/iot/ingest",
      "interval_seconds": 900
    }
  }
}</pre>
                    </div>
                </div>

                <!-- Data Ingestion -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">2. ส่งข้อมูล (Data Ingestion)</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto mb-4">
                        <pre>POST /api/v1/food-passport/iot/ingest
Authorization: Bearer fpk_a1b2c3d4e5f6...

{
  "device_id": "SENSOR-FARM-001",
  "product_id": 42,                    // สินค้าที่ track
  "stage": "at_storage",               // ขั้นตอน: registered, in_transit, at_storage, at_retail
  "location": "คลังสินค้า บางนา",
  "temperature": 4.5,                  // °C (optional)
  "humidity": 78.2,                    // % (optional)
  "weight": 25.3,                      // kg (optional)
  "ph_level": 6.8,                     // (optional)
  "sensor_data": {                     // ข้อมูลเพิ่มเติม (optional)
    "battery": 85,
    "signal_strength": -42,
    "gps": { "lat": 13.7563, "lng": 100.5018 }
  }
}</pre>
                    </div>

                    <h4 class="text-white font-semibold mb-2">Batch Ingestion (ส่งหลายรายการพร้อมกัน)</h4>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>POST /api/v1/food-passport/iot/batch-ingest

{
  "readings": [
    { "device_id": "SENSOR-001", "product_id": 42, "temperature": 4.5, "humidity": 78 },
    { "device_id": "SENSOR-002", "product_id": 43, "temperature": 3.8, "humidity": 80 },
    { "device_id": "SENSOR-003", "product_id": 42, "temperature": 5.1, "humidity": 76 }
  ]
}
// สูงสุด 100 readings ต่อ request</pre>
                    </div>
                </div>

                <!-- Arduino/ESP32 Example -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">3. ตัวอย่างโค้ด Arduino/ESP32</h3>
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
const char* deviceId = "SENSOR-FARM-001";
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
  delay(900000); // ทุก 15 นาที
}</pre>
                    </div>
                </div>

                <!-- Config Management -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">4. จัดการ Device ผ่าน Admin</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div v-for="action in [
                            { title: 'เปลี่ยนสถานะ', desc: 'Active / Inactive / Maintenance — inactive device จะถูกปฏิเสธข้อมูล', color: 'green' },
                            { title: 'Regenerate API Key', desc: 'สร้าง key ใหม่ — key เดิมใช้งานไม่ได้ทันที ต้องอัพเดท device', color: 'yellow' },
                            { title: 'ดู Trace Count', desc: 'จำนวน traces ที่ device ส่งมา ดูว่ายังทำงานอยู่หรือไม่', color: 'blue' },
                            { title: 'ลบ Device', desc: 'ลบ device ออกจากระบบ — traces ที่บันทึกไว้แล้วยังคงอยู่', color: 'red' },
                        ]" :key="action.title" class="p-4 rounded-xl bg-white/5">
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
                    <h2 class="text-xl font-bold text-white mb-6">API Reference</h2>

                    <div class="space-y-3">
                        <div v-for="ep in [
                            { method: 'GET', path: '/api/v1/food-passport/products', desc: 'ดึงรายการสินค้าทั้งหมด (paginated)' },
                            { method: 'GET', path: '/api/v1/food-passport/products/{id}', desc: 'ดึงรายละเอียดสินค้า + traces' },
                            { method: 'GET', path: '/api/v1/food-passport/verify/{id}', desc: 'ตรวจสอบ journey ของสินค้า' },
                            { method: 'GET', path: '/api/v1/food-passport/stats', desc: 'สถิติรวมของระบบ' },
                            { method: 'GET', path: '/api/v1/food-passport/certificates', desc: 'ใบรับรอง NFT ทั้งหมด' },
                            { method: 'GET', path: '/api/v1/food-passport/sensor-data/{productId}', desc: 'ข้อมูล sensor ล่าสุดของสินค้า' },
                            { method: 'POST', path: '/api/v1/food-passport/register', desc: 'ลงทะเบียนสินค้าใหม่' },
                            { method: 'POST', path: '/api/v1/food-passport/trace', desc: 'เพิ่ม trace (manual)' },
                            { method: 'POST', path: '/api/v1/food-passport/mint', desc: 'Mint NFT certificate' },
                            { method: 'GET', path: '/api/v1/food-passport/my-products', desc: 'สินค้าของ wallet address' },
                            { method: 'POST', path: '/api/v1/food-passport/iot/ingest', desc: 'IoT data ingestion (single)' },
                            { method: 'POST', path: '/api/v1/food-passport/iot/batch-ingest', desc: 'IoT batch ingestion (max 100)' },
                            { method: 'POST', path: '/api/v1/food-passport/iot/register-device', desc: 'ลงทะเบียน IoT device' },
                            { method: 'GET', path: '/api/v1/food-passport/iot/my-devices', desc: 'Devices ของเจ้าของ' },
                            { method: 'POST', path: '/api/v1/food-passport/iot/test-device/{id}', desc: 'ทดสอบ device connection' },
                            { method: 'GET', path: '/api/v1/food-passport/iot/device-config/{id}', desc: 'ดึง config ของ device' },
                            { method: 'GET', path: '/api/v1/food-passport/fdp-token-info', desc: 'ข้อมูล FDP Token' },
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
                    <h3 class="text-lg font-bold text-white mb-4">Response Format</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-green-400 mb-2 font-semibold">Success Response</p>
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
                            <p class="text-sm text-red-400 mb-2 font-semibold">Error Response</p>
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
                    <h2 class="text-xl font-bold text-white mb-6">Smart Contract Details</h2>

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
  → บันทึกสินค้าบน blockchain
  → Returns: productId

addTrace(productId, stage, location, data)
  → เพิ่ม trace point
  → Emits: TraceAdded event

mintCertificate(productId)
  → ออก NFT ใบรับรอง (ต้องมี >= 2 traces)
  → Mints ERC-721 token
  → Emits: CertificateMinted event

getProductHistory(productId)
  → ดึงประวัติ traces ทั้งหมด
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
mintCertificate → +100 FDP (ให้เจ้าของสินค้า)
addTrace        → +1 FDP (ให้ผู้บันทึก trace)

// Staking:
stake(amount) → เพิ่ม Trust Score
unstake()     → ถอน stake (7 วัน cooldown)
getTrustScore(address) → คำนวณคะแนนความน่าเชื่อถือ</pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chain Config -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">TPIX Chain Configuration</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div v-for="item in [
                            { label: 'Chain ID', value: '4289' },
                            { label: 'RPC URL', value: 'rpc.tpixchain.com' },
                            { label: 'Gas', value: 'FREE (Gasless)' },
                            { label: 'Block Time', value: '~3 seconds' },
                        ]" :key="item.label" class="p-4 rounded-xl bg-white/5">
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
                            <h2 class="text-2xl font-bold text-white">Mobile App Integration</h2>
                            <p class="text-gray-400 mt-1">FoodPassport Mobile App — สแกน QR, ดูข้อมูลสินค้า, IoT Dashboard</p>
                        </div>
                    </div>

                    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                        <p class="text-yellow-400 font-semibold flex items-center gap-2">
                            <span>⚡</span> Coming Soon — Mobile App อยู่ในแผนพัฒนา
                        </p>
                        <p class="text-sm text-gray-400 mt-1">API ทั้งหมดพร้อมแล้ว — Mobile App สามารถเชื่อมต่อได้ทันทีผ่าน REST API</p>
                    </div>
                </div>

                <!-- Mobile Features -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="feature in [
                        { icon: '📷', title: 'QR Code Scanner', desc: 'สแกน QR Code บนสินค้า ดู journey ตั้งแต่ฟาร์มถึงชั้นวาง แสดง trace timeline + sensor data + certificate', status: 'ready' },
                        { icon: '📊', title: 'IoT Dashboard', desc: 'ดู sensor data แบบ real-time, กราฟอุณหภูมิ/ความชื้น, alerts เมื่อผิดปกติ', status: 'ready' },
                        { icon: '📝', title: 'Product Registration', desc: 'เกษตรกรลงทะเบียนสินค้าจากมือถือ ถ่ายรูป เลือกหมวดหมู่ ระบุแหล่งผลิต', status: 'ready' },
                        { icon: '🔔', title: 'Push Notifications', desc: 'แจ้งเตือนเมื่ออุณหภูมิผิดปกติ, สินค้าเปลี่ยนสถานะ, ได้รับ FDP Token', status: 'planned' },
                        { icon: '👛', title: 'Wallet Integration', desc: 'เชื่อมต่อ Wallet, ดู FDP Token balance, stake/unstake, ดู Trust Score', status: 'ready' },
                        { icon: '🗺️', title: 'Supply Chain Map', desc: 'แผนที่แสดง journey ของสินค้า จากต้นทางถึงปลายทาง GPS tracking', status: 'planned' },
                    ]" :key="feature.title" class="glass-dark rounded-xl border border-white/10 p-5">
                        <div class="flex items-start justify-between mb-2">
                            <span class="text-3xl">{{ feature.icon }}</span>
                            <span
                                class="text-xs px-2 py-0.5 rounded-full font-medium"
                                :class="feature.status === 'ready' ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'"
                            >
                                {{ feature.status === 'ready' ? 'API Ready' : 'Planned' }}
                            </span>
                        </div>
                        <h3 class="text-white font-semibold mt-2 mb-1">{{ feature.title }}</h3>
                        <p class="text-sm text-gray-400">{{ feature.desc }}</p>
                    </div>
                </div>

                <!-- API Endpoints for Mobile -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">API Endpoints สำหรับ Mobile App</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>// QR Scanner → ดูข้อมูลสินค้า
GET /api/v1/food-passport/verify/{productId}

// ลงทะเบียนสินค้า
POST /api/v1/food-passport/register
  { name, category, origin, producer_address }

// ดูสินค้าของฉัน
GET /api/v1/food-passport/my-products?wallet_address=0x...

// IoT Dashboard
GET /api/v1/food-passport/sensor-data/{productId}
GET /api/v1/food-passport/iot/my-devices?wallet_address=0x...

// FDP Token
GET /api/v1/food-passport/fdp-token-info

// ทั้งหมดเป็น JSON REST API — ใช้ได้กับ React Native, Flutter, Swift, Kotlin</pre>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ ADMIN GUIDE ═══════════════════ -->
        <template v-if="activeSection === 'admin'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">Admin Management Guide</h2>

                    <div class="space-y-8">
                        <!-- Dashboard -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">📊 Dashboard</h3>
                            <p class="text-gray-400 mb-3">หน้าแรกแสดงสถิติภาพรวม — จำนวนสินค้า, certificates, devices, alerts</p>
                            <div class="grid grid-cols-2 gap-3">
                                <div v-for="stat in [
                                    'Total Products — จำนวนสินค้าทั้งหมด',
                                    'Certified — สินค้าที่ได้ใบรับรอง',
                                    'Total Traces — จำนวน trace records',
                                    'Certificates — ใบรับรอง NFT ที่ออก',
                                    'IoT Devices — อุปกรณ์ทั้งหมด',
                                    'Active Devices — อุปกรณ์ที่ทำงานอยู่',
                                    'Offline Devices — อุปกรณ์ที่ไม่ตอบสนอง > 1 ชั่วโมง',
                                    'Temp Alerts (24h) — การแจ้งเตือนอุณหภูมิ',
                                ]" :key="stat" class="text-sm text-gray-400 flex items-start gap-2">
                                    <div class="w-1.5 h-1.5 rounded-full bg-primary-500 mt-1.5 flex-shrink-0"></div>
                                    {{ stat }}
                                </div>
                            </div>
                        </div>

                        <!-- Product Management -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">📦 Product Management</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-green-400">•</span> ดูรายการสินค้าทั้งหมด พร้อมกรองตาม status</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> เปลี่ยนสถานะสินค้า: registered → in_transit → at_storage → at_retail → certified</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> ดู trace timeline ของสินค้า พร้อมข้อมูล sensor</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> Suspend สินค้าที่มีปัญหา (soft delete)</li>
                                <li class="flex gap-2"><span class="text-green-400">•</span> ดู journey verification — blockchain ยืนยันว่าข้อมูลถูกต้อง</li>
                            </ul>
                        </div>

                        <!-- Device Management -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">📡 IoT Device Management</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> ดูรายการ devices ทั้งหมด กรองตาม status/type</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> เปลี่ยนสถานะ: Active / Inactive / Maintenance</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> Regenerate API Key — เมื่อ key รั่วไหลหรือต้องการความปลอดภัยใหม่</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> ดูจำนวน traces ที่ device ส่งมา</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> ตรวจสอบ last ping — device ที่ offline > 1 ชม. จะมีสถานะสีแดง</li>
                                <li class="flex gap-2"><span class="text-cyan-400">•</span> ลบ device ที่ไม่ใช้แล้ว</li>
                            </ul>
                        </div>

                        <!-- Certificates -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">🏆 Certificate Management</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-yellow-400">•</span> ดูใบรับรอง NFT ทั้งหมด</li>
                                <li class="flex gap-2"><span class="text-yellow-400">•</span> Revoke ใบรับรอง — ระบุเหตุผล, สินค้าจะกลับสถานะ registered</li>
                                <li class="flex gap-2"><span class="text-yellow-400">•</span> ดูสถิติ: total, active, revoked</li>
                            </ul>
                        </div>

                        <!-- Temperature Alerts -->
                        <div>
                            <h3 class="text-lg font-semibold text-white mb-3">🌡️ Temperature Alerts</h3>
                            <ul class="space-y-2 text-sm text-gray-400">
                                <li class="flex gap-2"><span class="text-red-400">•</span> ดูรายการอุณหภูมิที่เกินค่าที่ตั้งไว้ (default: 0°C - 40°C)</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> ปรับ threshold ได้ตามต้องการ (min/max temp)</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> เลือกช่วงเวลา: 1 ชม. ถึง 7 วัน</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> แสดง deviation — สินค้าเกิน/ต่ำกว่าเกณฑ์เท่าไร</li>
                                <li class="flex gap-2"><span class="text-red-400">•</span> Link ไปยัง product detail เพื่อดูข้อมูลเพิ่มเติม</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
