<script setup>
/**
 * TPIX TRADE - Carbon Credit Documentation
 * คู่มือละเอียด — ภาพรวม, การทำงาน, ติดตั้ง, ตั้งค่า, API, Blockchain, วิธีใช้งาน
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const activeSection = ref('overview');

const sections = [
    { id: 'overview', label: 'ภาพรวมระบบ', icon: '🌍' },
    { id: 'howItWorks', label: 'การทำงาน', icon: '⚙️' },
    { id: 'setup', label: 'การติดตั้ง', icon: '📦' },
    { id: 'admin', label: 'Admin Guide', icon: '🛠️' },
    { id: 'api', label: 'API Reference', icon: '🔌' },
    { id: 'blockchain', label: 'Blockchain', icon: '⛓️' },
    { id: 'standards', label: 'มาตรฐาน', icon: '📜' },
    { id: 'mobile', label: 'Mobile App', icon: '📱' },
];

const setupSteps = [
    {
        num: 1,
        title: 'Run Migration',
        code: 'php artisan migrate\n\n# สร้าง 3 ตาราง:\n# - carbon_projects     (โปรเจกต์ Carbon Credit)\n# - carbon_credits      (หน่วย Credit ที่ซื้อ)\n# - carbon_retirements  (บันทึกการ retire)',
    },
    {
        num: 2,
        title: 'Seed Sample Data (Optional)',
        code: 'php artisan db:seed --class=TpixEcosystemSeeder\n\n# สร้าง 3 โปรเจกต์ตัวอย่าง:\n# 1. Thailand Northern Reforestation (50,000 credits @ $15)\n# 2. ASEAN Solar Energy Initiative (100,000 credits @ $12.50)\n# 3. Smart Farm Carbon Offset (25,000 credits @ $10)',
    },
    {
        num: 3,
        title: 'ตรวจสอบ Routes',
        code: 'php artisan route:list --path=carbon-credits\n\n# Public:\n# GET  /carbon-credits (Inertia page)\n# GET  /api/v1/carbon-credits/projects\n# GET  /api/v1/carbon-credits/stats\n#\n# Wallet-verified:\n# POST /api/v1/carbon-credits/purchase\n# POST /api/v1/carbon-credits/retire\n# GET  /api/v1/carbon-credits/my-credits/{wallet}\n#\n# Admin:\n# GET/POST/PUT/DELETE /admin/carbon-credits',
    },
    {
        num: 4,
        title: 'สร้าง Project แรก',
        code: '# 1. เข้า Admin Panel → Carbon Credits\n# 2. กด "+ New Project"\n# 3. ป้อนข้อมูล:\n#    Name: Thailand Mangrove Restoration\n#    Type: reforestation\n#    Location: Samut Songkhram, Thailand\n#    Standard: VCS\n#    Total Credits: 10,000\n#    Price: $12.00 / tCO2\n#    Status: draft\n# 4. Save → เปลี่ยน Status เป็น active\n# 5. Project จะแสดงใน Marketplace ทันที',
    },
];

const projectTypes = [
    { type: 'reforestation', icon: '🌳', label: 'Reforestation', desc: 'ปลูกป่าทดแทน — ดูดซับ CO2 ผ่านต้นไม้ที่ปลูกใหม่ในพื้นที่เสื่อมโทรม', example: 'Thailand Northern Reforestation' },
    { type: 'renewable_energy', icon: '⚡', label: 'Renewable Energy', desc: 'พลังงานหมุนเวียน — โซลาร์, ลม, น้ำ ลดการใช้เชื้อเพลิงฟอสซิล', example: 'ASEAN Solar Energy Initiative' },
    { type: 'methane_capture', icon: '🔥', label: 'Methane Capture', desc: 'ดักจับก๊าซมีเทนจากฟาร์มปศุสัตว์, บ่อขยะ, เหมืองถ่านหิน', example: 'Livestock Methane Reduction' },
    { type: 'ocean_cleanup', icon: '🌊', label: 'Ocean Cleanup', desc: 'ทำความสะอาดมหาสมุทร — เก็บขยะพลาสติก, ฟื้นฟูแนวปะการัง', example: 'Pacific Cleanup Project' },
    { type: 'carbon_capture', icon: '🏭', label: 'Carbon Capture', desc: 'เทคโนโลยีดักจับคาร์บอนจากอากาศ (DAC) หรือจากโรงงาน', example: 'Direct Air Capture Facility' },
    { type: 'biodiversity', icon: '🦋', label: 'Biodiversity', desc: 'อนุรักษ์ความหลากหลายทางชีวภาพ — ป้องกันป่าจากการทำลาย', example: 'Rainforest Conservation' },
    { type: 'other', icon: '🌐', label: 'Other', desc: 'โปรเจกต์อื่นๆ เช่น Smart Farm, การเกษตรยั่งยืน, biochar', example: 'Smart Farm Carbon Offset' },
];
</script>

<template>
    <AdminLayout title="Carbon Credit Docs">
        <!-- Header -->
        <div class="mb-6">
            <Link href="/admin/carbon-credits" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                Carbon Credits
            </Link>
            <h1 class="text-2xl font-bold text-white">Carbon Credit Documentation</h1>
            <p class="text-sm text-gray-400 mt-1">คู่มือสมบูรณ์ — ระบบ Carbon Credit Marketplace บน TPIX Chain</p>
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
                <span>{{ sec.label }}</span>
            </button>
        </div>

        <!-- ═══════════════════ OVERVIEW ═══════════════════ -->
        <template v-if="activeSection === 'overview'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="text-5xl">🌍</span>
                        <div>
                            <h2 class="text-2xl font-bold text-white">Carbon Credit คืออะไร?</h2>
                            <p class="text-gray-400 mt-1">ระบบซื้อ-ขาย-retire คาร์บอนเครดิตบน Blockchain</p>
                        </div>
                    </div>
                    <div class="text-gray-300 space-y-4">
                        <p><span class="text-white font-semibold">Carbon Credit</span> คือหน่วยวัดการลดก๊าซเรือนกระจก โดย 1 Credit = 1 ตัน CO2 ที่ถูกลดหรือดูดซับ เมื่อองค์กรหรือบุคคลซื้อ carbon credit จะเป็นการ "ชดเชย" การปล่อยคาร์บอนของตนเอง</p>
                        <p>ระบบ Carbon Credit ของ <span class="text-green-400 font-semibold">TPIX TRADE</span> ทำงานบน <span class="text-primary-400 font-semibold">TPIX Chain (Chain ID: 4289)</span> — โปร่งใส, ตรวจสอบได้, ไม่สามารถปลอมแปลง</p>
                    </div>
                </div>

                <!-- Key Concepts -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="concept in [
                        { icon: '🏗️', title: 'Carbon Project', desc: 'โปรเจกต์ที่ลดก๊าซเรือนกระจก เช่น ปลูกป่า, โซลาร์เซลล์, ดักจับมีเทน — ผ่านการรับรองมาตรฐานสากล' },
                        { icon: '🎫', title: 'Carbon Credit', desc: '1 Credit = 1 tCO2 ที่ถูกลด/ดูดซับ ซื้อได้ด้วย TPIX, BNB, USDT — มี Serial Number เฉพาะทุกใบ' },
                        { icon: '♻️', title: 'Retirement', desc: 'การใช้ credit เพื่อชดเชย CO2 — เมื่อ retire แล้วจะถูกลบออกจากระบบถาวร ได้ Certificate Hash' },
                        { icon: '📜', title: 'มาตรฐาน (Standards)', desc: 'VCS (Verra), Gold Standard, CDM (UN) — แต่ละ project มีมาตรฐานรับรอง แสดง Registry ID' },
                        { icon: '💰', title: 'Multi-Currency', desc: 'จ่ายด้วย TPIX (native coin), BNB, หรือ USDT ราคาคำนวณเป็น USD แล้วแปลงเป็นสกุลที่เลือก' },
                        { icon: '🔒', title: 'Blockchain Verified', desc: 'ทุก transaction (ซื้อ/retire) มี TX Hash บันทึกบน TPIX Chain — ตรวจสอบได้ตลอดกาล ไม่สามารถแก้ไข' },
                    ]" :key="concept.title" class="glass-dark rounded-xl border border-white/10 p-5">
                        <span class="text-3xl">{{ concept.icon }}</span>
                        <h3 class="text-white font-semibold mt-3 mb-1">{{ concept.title }}</h3>
                        <p class="text-sm text-gray-400">{{ concept.desc }}</p>
                    </div>
                </div>

                <!-- Project Types -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-6">ประเภทโปรเจกต์ Carbon Credit</h3>
                    <div class="space-y-4">
                        <div v-for="pt in projectTypes" :key="pt.type" class="flex gap-4 p-4 rounded-xl bg-white/5">
                            <span class="text-3xl flex-shrink-0">{{ pt.icon }}</span>
                            <div>
                                <p class="text-white font-semibold">{{ pt.label }} <span class="text-xs text-gray-500 font-mono">({{ pt.type }})</span></p>
                                <p class="text-sm text-gray-400">{{ pt.desc }}</p>
                                <p class="text-xs text-green-400 mt-1">Example: {{ pt.example }}</p>
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
                    <h2 class="text-xl font-bold text-white mb-6">User Flow — วิธีใช้งาน Carbon Credit</h2>

                    <div class="space-y-8">
                        <div v-for="(step, i) in [
                            { num: 1, title: 'เชื่อมต่อ Wallet', desc: 'เชื่อม MetaMask, Trust Wallet, หรือ TPIX Wallet เพื่อเข้าใช้งาน', detail: 'ผู้ใช้ต้องมี wallet address (0x...) เพื่อซื้อ/retire credits ทุก transaction จะผูกกับ wallet address' },
                            { num: 2, title: 'เลือก Carbon Project', desc: 'เลือกจาก Marketplace — ดูประเภท, ที่ตั้ง, มาตรฐาน, ราคา', detail: 'แต่ละ project มีข้อมูลครบ: จำนวน credit ที่เหลือ, vintage year, registry ID, progress bar, featured badge' },
                            { num: 3, title: 'ซื้อ Carbon Credit', desc: 'กำหนดจำนวน tCO2 → เลือกสกุลเงิน (TPIX/BNB/USDT) → ยืนยัน', detail: 'ระบบสร้าง Serial Number (CC-XXXXXXXX-YYYYMMDD), หัก available_credits, บันทึก TX Hash, สถานะ active' },
                            { num: 4, title: 'ดู Credits ของฉัน', desc: 'แท็บ My Credits แสดง credit ที่ถืออยู่ — serial, จำนวน, สถานะ', detail: 'Credits สถานะ active สามารถ retire ได้ โดยกดปุ่ม Retire แต่ละ credit' },
                            { num: 5, title: 'Retire Credits', desc: 'ระบุจำนวน, ชื่อผู้รับประโยชน์, เหตุผล → ยืนยัน', detail: 'ระบบสร้าง Certificate Hash (0x...), อัพเดท retired_credits ของ project, สถานะ credit เปลี่ยนเป็น retired' },
                        ]" :key="step.num">
                            <div class="flex gap-4">
                                <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                    {{ step.num }}
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-white">{{ step.title }}</h3>
                                    <p class="text-gray-400 mt-1">{{ step.desc }}</p>
                                    <p class="text-sm text-gray-500 mt-2 p-3 rounded-lg bg-white/5">{{ step.detail }}</p>
                                </div>
                            </div>
                            <div v-if="i < 4" class="ml-5 h-6 border-l-2 border-green-500/30"></div>
                        </div>
                    </div>
                </div>

                <!-- Admin Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-6">Admin Flow — จัดการโปรเจกต์</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div v-for="action in [
                            { icon: '➕', title: 'สร้าง Project', desc: 'ป้อนข้อมูล: ชื่อ, ประเภท, ที่ตั้ง, มาตรฐาน, จำนวน credit, ราคา → สถานะ draft' },
                            { icon: '✅', title: 'Activate Project', desc: 'เปลี่ยนสถานะเป็น active → แสดงใน Marketplace ให้ผู้ใช้ซื้อได้' },
                            { icon: '✏️', title: 'แก้ไข Project', desc: 'แก้ไขข้อมูลได้ทุกอย่าง ยกเว้น total_credits (เพื่อความโปร่งใส)' },
                            { icon: '🗑️', title: 'ลบ Project', desc: 'ลบได้เฉพาะ project ที่ยังไม่มีคนซื้อ credit (ป้องกัน orphan records)' },
                            { icon: '⏸️', title: 'Suspend Project', desc: 'ระงับชั่วคราว — ไม่แสดงใน Marketplace, ผู้ถือ credit ยังมีสิทธิ์' },
                            { icon: '📊', title: 'ดูสถิติ', desc: 'Total Projects, tCO2 Retired, Revenue USD, Unique Buyers — real-time' },
                        ]" :key="action.title" class="p-4 rounded-xl bg-white/5">
                            <span class="text-2xl">{{ action.icon }}</span>
                            <h4 class="text-white font-semibold mt-2">{{ action.title }}</h4>
                            <p class="text-sm text-gray-400 mt-1">{{ action.desc }}</p>
                        </div>
                    </div>
                </div>

                <!-- Data Flow -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">Data Flow — Purchase & Retire</h3>
                    <div class="space-y-3 font-mono text-sm">
                        <p class="text-gray-400 text-xs font-sans mb-2">Purchase Flow:</p>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-cyan-400">User Wallet</span> → POST /api/v1/carbon-credits/purchase → <span class="text-green-400">Laravel API</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">CarbonCreditService</span> → DB Transaction + Row Lock → <span class="text-blue-400">carbon_credits table</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-blue-400">Database</span> → Generate Serial Number → Decrement available_credits → <span class="text-purple-400">Return credit + TX Hash</span>
                        </div>

                        <p class="text-gray-400 text-xs font-sans mb-2 mt-6">Retire Flow:</p>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-cyan-400">Credit Owner</span> → POST /api/v1/carbon-credits/retire → <span class="text-green-400">Laravel API</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">CarbonCreditService</span> → Validate ownership → <span class="text-yellow-400">Generate Certificate Hash (0x...)</span>
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-yellow-400">Certificate</span> → Update credit status → Increment retired_credits → <span class="text-purple-400">Permanent record</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ SETUP ═══════════════════ -->
        <template v-if="activeSection === 'setup'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">การติดตั้งระบบ Carbon Credit</h2>

                    <div class="space-y-8">
                        <div v-for="(step, i) in setupSteps" :key="step.num">
                            <div>
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center text-white font-bold text-sm">{{ step.num }}</div>
                                    <h3 class="text-lg font-semibold text-white">{{ step.title }}</h3>
                                </div>
                                <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                                    <pre>{{ step.code }}</pre>
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
                    <h2 class="text-xl font-bold text-white mb-6">Admin Management Guide</h2>

                    <!-- Project Lifecycle -->
                    <h3 class="text-lg font-semibold text-white mb-4">Project Lifecycle (วงจรชีวิตโปรเจกต์)</h3>
                    <div class="flex flex-wrap items-center gap-3 mb-8">
                        <div v-for="(state, i) in [
                            { label: 'Draft', color: 'bg-gray-500/20 text-gray-400 border-gray-500/30', desc: 'ร่าง — ยังไม่แสดง' },
                            { label: 'Active', color: 'bg-green-500/20 text-green-400 border-green-500/30', desc: 'เปิดขาย' },
                            { label: 'Sold Out', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30', desc: 'ขายหมด (auto)' },
                            { label: 'Expired', color: 'bg-red-500/20 text-red-400 border-red-500/30', desc: 'หมดอายุ' },
                            { label: 'Suspended', color: 'bg-red-500/20 text-red-400 border-red-500/30', desc: 'ระงับชั่วคราว' },
                        ]" :key="state.label" class="flex items-center gap-3">
                            <div class="text-center">
                                <span :class="['text-xs px-3 py-1.5 rounded-full font-medium border', state.color]">{{ state.label }}</span>
                                <p class="text-xs text-gray-500 mt-1">{{ state.desc }}</p>
                            </div>
                            <svg v-if="i < 4" class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </div>

                    <!-- Database Schema -->
                    <h3 class="text-lg font-semibold text-white mb-4">Database Schema</h3>
                    <div class="space-y-4">
                        <div v-for="table in [
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
                        ]" :key="table.name" class="p-4 rounded-xl bg-white/5">
                            <p class="text-white font-semibold font-mono mb-2">{{ table.name }}</p>
                            <ul class="space-y-1">
                                <li v-for="(field, fi) in table.fields" :key="fi" class="text-xs text-gray-400 font-mono">{{ field }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Important Rules -->
                <div class="glass-dark rounded-2xl border border-yellow-500/20 p-8">
                    <h3 class="text-lg font-bold text-yellow-400 mb-4">กฎสำคัญที่ต้องรู้</h3>
                    <ul class="space-y-3 text-sm text-gray-300">
                        <li class="flex gap-3"><span class="text-yellow-400 flex-shrink-0">⚠️</span> <span><strong>ห้ามแก้ total_credits</strong> — หลังสร้าง project แล้ว จำนวน credit ทั้งหมดแก้ไม่ได้ (เพื่อความโปร่งใส)</span></li>
                        <li class="flex gap-3"><span class="text-yellow-400 flex-shrink-0">⚠️</span> <span><strong>ห้ามลบ project ที่ขายแล้ว</strong> — ถ้ามี credit ถูกซื้อไปแล้ว ลบ project ไม่ได้ (ข้อมูลต้องคงอยู่)</span></li>
                        <li class="flex gap-3"><span class="text-yellow-400 flex-shrink-0">⚠️</span> <span><strong>Retire ไม่สามารถย้อนกลับ</strong> — เมื่อ retire credit แล้ว จะไม่สามารถกลับมาเป็น active ได้อีก</span></li>
                        <li class="flex gap-3"><span class="text-yellow-400 flex-shrink-0">⚠️</span> <span><strong>Sold Out อัตโนมัติ</strong> — เมื่อ available_credits = 0 ระบบจะเปลี่ยนสถานะเป็น sold_out อัตโนมัติ</span></li>
                        <li class="flex gap-3"><span class="text-green-400 flex-shrink-0">✅</span> <span><strong>Row Locking</strong> — ระบบใช้ database row lock ป้องกัน race condition ตอนซื้อ credit พร้อมกัน</span></li>
                        <li class="flex gap-3"><span class="text-green-400 flex-shrink-0">✅</span> <span><strong>bcmath Precision</strong> — คำนวณจำนวน credit ด้วย bcmath เพื่อความแม่นยำทศนิยม</span></li>
                    </ul>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ API REFERENCE ═══════════════════ -->
        <template v-if="activeSection === 'api'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">API Reference</h2>

                    <!-- Endpoints -->
                    <div class="space-y-3 mb-8">
                        <div v-for="ep in [
                            { method: 'GET', path: '/api/v1/carbon-credits/projects', desc: 'ดึงรายการ project ที่ active (paginated, 12/page)', auth: false },
                            { method: 'GET', path: '/api/v1/carbon-credits/projects/{slug}', desc: 'ดึงรายละเอียด project ด้วย slug', auth: false },
                            { method: 'GET', path: '/api/v1/carbon-credits/stats', desc: 'สถิติรวม: projects, credits, revenue, buyers', auth: false },
                            { method: 'POST', path: '/api/v1/carbon-credits/purchase', desc: 'ซื้อ carbon credit — ต้องระบุ wallet_address', auth: true },
                            { method: 'POST', path: '/api/v1/carbon-credits/retire', desc: 'Retire credit — ต้องเป็นเจ้าของ credit', auth: true },
                            { method: 'GET', path: '/api/v1/carbon-credits/my-credits/{wallet}', desc: 'Credits ที่ wallet address ถืออยู่', auth: true },
                            { method: 'GET', path: '/api/v1/carbon-credits/my-retirements/{wallet}', desc: 'ประวัติ retirement ทั้งหมดของ wallet', auth: true },
                        ]" :key="ep.path + ep.method" class="flex items-start gap-3 p-3 rounded-lg bg-white/5">
                            <span
                                class="inline-block px-2 py-0.5 rounded text-xs font-bold font-mono flex-shrink-0 mt-0.5"
                                :class="ep.method === 'GET' ? 'bg-green-500/20 text-green-400' : 'bg-blue-500/20 text-blue-400'"
                            >
                                {{ ep.method }}
                            </span>
                            <div class="flex-1">
                                <p class="text-white font-mono text-sm">{{ ep.path }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ ep.desc }}</p>
                            </div>
                            <span v-if="ep.auth" class="text-xs px-2 py-0.5 rounded-full bg-yellow-500/20 text-yellow-400 flex-shrink-0">wallet</span>
                        </div>
                    </div>
                </div>

                <!-- Purchase Request/Response -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">Purchase Example</h3>
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
                            <p class="text-sm text-green-400 mb-2 font-semibold">Response (201)</p>
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
                    <h3 class="text-lg font-bold text-white mb-4">Retire Example</h3>
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
                            <p class="text-sm text-green-400 mb-2 font-semibold">Response (201)</p>
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
                    <h2 class="text-xl font-bold text-white mb-6">Blockchain Integration</h2>

                    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                        <p class="text-yellow-400 font-semibold flex items-center gap-2">
                            <span>📌</span> สถานะปัจจุบัน: Hybrid Model
                        </p>
                        <p class="text-sm text-gray-400 mt-1">
                            ระบบ Carbon Credit ใช้ <strong>Hybrid Model</strong> — ข้อมูลหลักเก็บใน Database (PostgreSQL/MySQL) พร้อม TX Hash อ้างอิงบน Blockchain
                            สามารถอัพเกรดเป็น fully on-chain ในอนาคตด้วย Smart Contract
                        </p>
                    </div>

                    <h3 class="text-lg font-semibold text-white mb-4">Current Architecture</h3>
                    <div class="space-y-3 font-mono text-sm mb-8">
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-green-400">Purchase</span> → Laravel creates record → Stores <span class="text-cyan-400">tx_hash</span> from wallet transaction
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-yellow-400">Retire</span> → Laravel creates retirement → Generates <span class="text-purple-400">certificate_hash</span> (0x + random 64 hex)
                        </div>
                        <div class="p-3 rounded-lg bg-white/5 text-gray-300">
                            <span class="text-blue-400">Verify</span> → TX Hash can be checked on <span class="text-primary-400">TPIX Chain Explorer</span>
                        </div>
                    </div>

                    <h3 class="text-lg font-semibold text-white mb-4">Future: Smart Contract (Planned)</h3>
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

// เหตุผลที่ใช้ ERC-1155:
// - Semi-fungible: credits ของ project เดียวกันมีมูลค่าเท่ากัน
// - Batch operations: ซื้อ/retire หลาย project พร้อมกัน
// - Gas efficient: ถูกกว่า ERC-721 สำหรับ fungible assets</pre>
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
                            { label: 'Token Model', value: 'Hybrid (DB + TX Hash)' },
                        ]" :key="item.label" class="p-4 rounded-xl bg-white/5">
                            <p class="text-xs text-gray-400">{{ item.label }}</p>
                            <p class="text-white font-semibold mt-1 text-sm">{{ item.value }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ═══════════════════ STANDARDS ═══════════════════ -->
        <template v-if="activeSection === 'standards'">
            <div class="space-y-6">
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h2 class="text-xl font-bold text-white mb-6">มาตรฐาน Carbon Credit ที่รองรับ</h2>

                    <div class="space-y-6">
                        <div v-for="std in [
                            {
                                name: 'VCS (Verified Carbon Standard)',
                                org: 'Verra',
                                icon: '🏛️',
                                desc: 'มาตรฐานสากลที่ใช้แพร่หลายที่สุดในโลก ครอบคลุมทุกประเภทโปรเจกต์ ตั้งแต่ปลูกป่าไปจนถึงพลังงานหมุนเวียน',
                                features: ['มีโปรเจกต์มากกว่า 1,900+ โครงการทั่วโลก', 'ออก VCU (Verified Carbon Unit) ที่มี Serial Number', 'ตรวจสอบได้ผ่าน Verra Registry', 'เป็นที่ยอมรับในตลาดคาร์บอนเครดิตสมัครใจ (VCM)'],
                            },
                            {
                                name: 'Gold Standard',
                                org: 'Gold Standard Foundation',
                                icon: '🥇',
                                desc: 'มาตรฐานระดับสูงที่เน้น SDGs (Sustainable Development Goals) — ไม่ใช่แค่ลด CO2 แต่ต้องส่งผลดีต่อชุมชนและสิ่งแวดล้อม',
                                features: ['ก่อตั้งโดย WWF', 'เข้มงวดกว่า VCS — ต้องผ่านเกณฑ์ SDGs', 'เน้นโปรเจกต์ในประเทศกำลังพัฒนา', 'ตรวจสอบผลกระทบทางสังคมและสิ่งแวดล้อม'],
                            },
                            {
                                name: 'CDM (Clean Development Mechanism)',
                                org: 'United Nations (UNFCCC)',
                                icon: '🇺🇳',
                                desc: 'กลไกการพัฒนาที่สะอาดของ UN ภายใต้ Kyoto Protocol ออก CER (Certified Emission Reduction)',
                                features: ['มาตรฐานของ UN อย่างเป็นทางการ', 'ใช้ในตลาดคาร์บอนภาคบังคับ (compliance market)', 'ให้ประเทศพัฒนาแล้วลงทุนในประเทศกำลังพัฒนา', 'กำลังเปลี่ยนผ่านเป็น Article 6.4 ของ Paris Agreement'],
                            },
                            {
                                name: 'ACR (American Carbon Registry)',
                                org: 'Winrock International',
                                icon: '🇺🇸',
                                desc: 'มาตรฐานคาร์บอนเครดิตที่เก่าแก่ที่สุดในโลก (1996) เน้นโปรเจกต์ในอเมริกาเหนือ',
                                features: ['เน้นโปรเจกต์ป่าไม้และเกษตรกรรม', 'ยอมรับในตลาด California Cap-and-Trade', 'มีวิธีการคำนวณเฉพาะ (methodology) มากกว่า 30 รูปแบบ'],
                            },
                        ]" :key="std.name" class="p-6 rounded-xl bg-white/5">
                            <div class="flex items-start gap-4">
                                <span class="text-4xl flex-shrink-0">{{ std.icon }}</span>
                                <div>
                                    <h3 class="text-white font-bold text-lg">{{ std.name }}</h3>
                                    <p class="text-xs text-primary-400 mb-2">{{ std.org }}</p>
                                    <p class="text-sm text-gray-400 mb-3">{{ std.desc }}</p>
                                    <ul class="space-y-1">
                                        <li v-for="(f, fi) in std.features" :key="fi" class="text-sm text-gray-400 flex gap-2">
                                            <span class="text-green-400">•</span> {{ f }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vintage Year -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">Vintage Year คืออะไร?</h3>
                    <p class="text-gray-400 mb-4">
                        <span class="text-white font-semibold">Vintage Year</span> คือปีที่การลด/ดูดซับ CO2 เกิดขึ้นจริง
                        ไม่ใช่ปีที่ credit ถูกออกหรือซื้อ ตัวอย่าง: ต้นไม้ปลูกปี 2024 ดูดซับ CO2 ในปี 2024 → Vintage Year = 2024
                    </p>
                    <div class="p-4 rounded-xl bg-white/5 text-sm text-gray-400">
                        <p><span class="text-yellow-400">💡 หมายเหตุ:</span> Credit vintage ใหม่กว่ามักมีราคาสูงกว่า เพราะ "สดกว่า" ในตลาด</p>
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
                            <p class="text-gray-400 mt-1">Carbon Credit ผ่าน Mobile App — ซื้อ, retire, ดูสถิติ</p>
                        </div>
                    </div>

                    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 mb-6">
                        <p class="text-yellow-400 font-semibold flex items-center gap-2">
                            <span>⚡</span> Coming Soon — Mobile App อยู่ในแผนพัฒนา
                        </p>
                        <p class="text-sm text-gray-400 mt-1">API ทั้งหมดพร้อมแล้ว — Mobile App เชื่อมต่อได้ทันทีผ่าน REST API</p>
                    </div>
                </div>

                <!-- Mobile Features -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="feature in [
                        { icon: '🛒', title: 'Browse & Purchase', desc: 'เลือก project, กำหนดจำนวน, จ่ายผ่าน wallet — ซื้อ carbon credit ได้ทุกที่', status: 'ready' },
                        { icon: '♻️', title: 'Retire Credits', desc: 'Retire credit จากมือถือ ระบุผู้รับประโยชน์ ได้ Certificate Hash ทันที', status: 'ready' },
                        { icon: '📊', title: 'Portfolio Dashboard', desc: 'ดู credit ที่ถืออยู่, ประวัติ retirement, สถิติ CO2 offset ของตัวเอง', status: 'ready' },
                        { icon: '🔔', title: 'Price Alerts', desc: 'แจ้งเตือนเมื่อ project ใหม่เปิดขาย หรือราคาเปลี่ยนแปลง', status: 'planned' },
                        { icon: '🏆', title: 'Impact Badge', desc: 'รับ badge ตามปริมาณ CO2 ที่ offset — แชร์บน social media ได้', status: 'planned' },
                        { icon: '📈', title: 'Market Analytics', desc: 'วิเคราะห์ตลาด carbon credit — ราคาเฉลี่ย, trend, volume', status: 'planned' },
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

                <!-- API for Mobile -->
                <div class="glass-dark rounded-2xl border border-white/10 p-8">
                    <h3 class="text-lg font-bold text-white mb-4">API Endpoints สำหรับ Mobile App</h3>
                    <div class="bg-dark-900 rounded-xl p-4 font-mono text-sm text-gray-300 overflow-x-auto">
                        <pre>// Browse Projects
GET /api/v1/carbon-credits/projects
GET /api/v1/carbon-credits/projects/{slug}
GET /api/v1/carbon-credits/stats

// Purchase & Retire (requires wallet)
POST /api/v1/carbon-credits/purchase
  { project_id, amount, wallet_address, payment_currency, payment_amount, tx_hash }

POST /api/v1/carbon-credits/retire
  { credit_id, amount, wallet_address, beneficiary_name, retirement_reason }

// My Portfolio
GET /api/v1/carbon-credits/my-credits/{walletAddress}
GET /api/v1/carbon-credits/my-retirements/{walletAddress}

// JSON REST API — ใช้ได้กับ React Native, Flutter, Swift, Kotlin</pre>
                    </div>
                </div>
            </div>
        </template>
    </AdminLayout>
</template>
