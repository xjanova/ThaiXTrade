<script setup>
/**
 * TPIX Master Node — Setup Guide
 * คู่มือการตั้งค่า Master Node แบบละเอียดพร้อมภาพประกอบ
 * ภาษาไทย/อังกฤษ
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const isTH = computed(() => locale.value === 'th');

const activeStep = ref(0);
const lightboxImage = ref(null);

const tiers = computed(() => [
    {
        id: 'light', name: 'Light Node',
        stake: '10,000 TPIX', apy: '4-6%', lock: isTH.value ? '7 วัน' : '7 days',
        reward: { monthly: '~42 TPIX', yearly: '~500 TPIX' },
        hardware: isTH.value ? 'CPU 2 cores · RAM 4GB · SSD 50GB' : '2 CPU · 4GB RAM · 50GB SSD',
        color: 'cyan', icon: '💎',
    },
    {
        id: 'sentinel', name: 'Sentinel Node',
        stake: '100,000 TPIX', apy: '7-9%', lock: isTH.value ? '30 วัน' : '30 days',
        reward: { monthly: '~667 TPIX', yearly: '~8,000 TPIX' },
        hardware: isTH.value ? 'CPU 4 cores · RAM 8GB · SSD 200GB' : '4 CPU · 8GB RAM · 200GB SSD',
        color: 'purple', icon: '🛡️',
    },
    {
        id: 'guardian', name: 'Guardian Node',
        stake: '1,000,000 TPIX', apy: '10-12%', lock: isTH.value ? '90 วัน' : '90 days',
        reward: { monthly: '~9,167 TPIX', yearly: '~110,000 TPIX' },
        hardware: isTH.value ? 'CPU 8 cores · RAM 16GB · SSD 500GB' : '8 CPU · 16GB RAM · 500GB SSD',
        color: 'yellow', icon: '⚡',
    },
    {
        id: 'validator', name: 'Validator Node',
        stake: '10,000,000 TPIX', apy: '15-20%', lock: isTH.value ? '180 วัน' : '180 days',
        reward: { monthly: '~145,833 TPIX', yearly: '~1,750,000 TPIX' },
        hardware: isTH.value ? 'CPU 16 cores · RAM 32GB · SSD 1TB' : '16 CPU · 32GB RAM · 1TB SSD',
        color: 'red', icon: '🔥',
    },
]);

const steps = computed(() => isTH.value ? [
    {
        title: 'ดาวน์โหลดโปรแกรม',
        desc: 'ดาวน์โหลด TPIX Master Node สำหรับ Windows',
        details: [
            'ไปที่หน้า TPIX Download หรือ GitHub Releases',
            'ดาวน์โหลดไฟล์ "TPIX Master Node.exe" (Portable — ไม่ต้องติดตั้ง)',
            'หรือดาวน์โหลด Installer (.exe) สำหรับติดตั้งถาวร',
            'วางไว้ที่ใดก็ได้ เช่น Desktop หรือ Documents',
        ],
        tip: 'แนะนำ: ใช้เวอร์ชัน Portable เพื่อความสะดวก ไม่ต้องติดตั้ง แค่ดับเบิลคลิกก็ใช้ได้เลย',
        img: 'step-download',
    },
    {
        title: 'เปิดโปรแกรมและสร้างกระเป๋า',
        desc: 'เปิด TPIX Master Node แล้วสร้างหรือนำเข้ากระเป๋าเงิน',
        details: [
            'ดับเบิลคลิกเปิด "TPIX Master Node.exe"',
            'คลิกแท็บ "กระเป๋าเงิน" (Wallet) ในเมนูด้านซ้าย',
            'คลิก "สร้างกระเป๋าใหม่" หรือ "นำเข้า Private Key" ถ้ามีอยู่แล้ว',
            '⚠️ สำคัญมาก: บันทึก Private Key ไว้ที่ปลอดภัย! จะแสดงเพียงครั้งเดียว',
            'Address ของคุณจะแสดงในหน้ากระเป๋า',
        ],
        tip: 'ถ้าคุณมี wallet จาก MetaMask หรือ Trust Wallet อยู่แล้ว สามารถ export private key แล้วนำเข้าได้เลย',
        img: 'step-wallet',
    },
    {
        title: 'เติม TPIX เข้ากระเป๋า',
        desc: 'ส่ง TPIX เข้า address ของคุณให้เพียงพอกับ tier ที่ต้องการ',
        details: [
            'คัดลอก Address จากหน้ากระเป๋าในโปรแกรม',
            'ส่ง TPIX จาก exchange หรือ wallet อื่นเข้ามา',
            'จำนวนขั้นต่ำตาม tier: Light 10,000 / Sentinel 100,000 / Guardian 1,000,000 / Validator 10,000,000 TPIX',
            'รอยืนยันบน TPIX Chain (ประมาณ 2-4 วินาที)',
            'ตรวจสอบยอดในหน้ากระเป๋าของโปรแกรม กด "รีเฟรช" เพื่ออัปเดต',
        ],
        tip: 'ซื้อ TPIX ได้ที่ tpix.online/trade หรือ Bridge จาก BSC ที่ tpix.online/bridge',
        img: 'step-fund',
    },
    {
        title: 'เลือกระดับโหนดและตั้งค่า',
        desc: 'เลือก tier ที่ต้องการและกรอกชื่อโหนด',
        details: [
            'คลิกแท็บ "ตั้งค่าโหนด" (Run a Node)',
            'เลือกระดับที่ต้องการ: Light / Sentinel / Guardian / Validator',
            'ระบบจะแสดงรางวัลโดยประมาณที่จะได้รับ',
            'คลิก "ถัดไป" เพื่อยืนยัน wallet',
            'กรอกชื่อโหนดของคุณ (เช่น "my-tpix-node")',
            'ตรวจสอบข้อมูลทั้งหมดในหน้าสรุป',
        ],
        tip: 'เริ่มจาก Light Node ก่อนก็ได้ สามารถอัปเกรดเป็น tier สูงกว่าได้ทีหลัง',
        img: 'step-tier',
    },
    {
        title: 'เริ่มรันโหนด!',
        desc: 'กด Launch เพื่อเริ่มรันโหนดของคุณ',
        details: [
            'คลิก "เริ่มรันโหนด" (Launch Node)',
            'โปรแกรมจะเชื่อมต่อกับ TPIX Chain โดยอัตโนมัติ',
            'สถานะจะเปลี่ยนเป็น "ทำงาน" (Running) เมื่อเชื่อมต่อสำเร็จ',
            'ดู Dashboard เพื่อติดตามสถานะ Block Height, Peers, Uptime',
            'โปรแกรมจะรันเป็น Background — ย่อลง System Tray ได้',
            'รางวัลจะสะสมตาม uptime ของคุณ',
        ],
        tip: 'เปิดโปรแกรมทิ้งไว้ 24/7 เพื่อ uptime สูงสุดและรับรางวัลเต็มที่ ใช้คอมที่เปิดตลอดหรือ VPS/Cloud',
        img: 'step-run',
    },
    {
        title: 'ดูรางวัลและอัปเดตอัตโนมัติ',
        desc: 'ติดตามรางวัลและให้โปรแกรมอัปเดตอัตโนมัติ',
        details: [
            'ดูรางวัลสะสมในหน้า Dashboard',
            'โปรแกรมจะตรวจสอบอัปเดตจาก GitHub อัตโนมัติทุก 30 นาที',
            'เมื่อมีเวอร์ชันใหม่ จะแจ้งเตือนให้ดาวน์โหลด + ติดตั้งอัตโนมัติ',
            'ดูประวัติรางวัลและ claim ได้เมื่อ contract พร้อม',
            'หน้า "เครือข่าย" แสดง validators ทั้งหมดและสถานะ chain',
        ],
        tip: 'ยิ่งมีคนรันโหนดเยอะ = เครือข่ายยิ่งเสถียร ไม่ล่มง่าย! ชวนเพื่อนมารันด้วย',
        img: 'step-rewards',
    },
] : [
    {
        title: 'Download the App',
        desc: 'Download TPIX Master Node for Windows',
        details: [
            'Go to TPIX Download page or GitHub Releases',
            'Download "TPIX Master Node.exe" (Portable — no installation needed)',
            'Or download the Installer (.exe) for permanent installation',
            'Place it anywhere — Desktop, Documents, etc.',
        ],
        tip: 'Recommended: Use the Portable version for convenience. Just double-click to run!',
        img: 'step-download',
    },
    {
        title: 'Open App & Create Wallet',
        desc: 'Open TPIX Master Node and create or import a wallet',
        details: [
            'Double-click "TPIX Master Node.exe" to open',
            'Click the "Wallet" tab in the left sidebar',
            'Click "Create New Wallet" or "Import Private Key" if you already have one',
            '⚠️ Important: Save your Private Key securely! It is shown only once.',
            'Your address will be displayed on the Wallet page',
        ],
        tip: 'If you already have a wallet from MetaMask or Trust Wallet, you can export the private key and import it.',
        img: 'step-wallet',
    },
    {
        title: 'Fund Your Wallet',
        desc: 'Send enough TPIX to your address for your desired tier',
        details: [
            'Copy your Address from the Wallet page in the app',
            'Send TPIX from an exchange or another wallet',
            'Minimum amounts by tier: Light 10,000 / Sentinel 100,000 / Guardian 1,000,000 / Validator 10,000,000 TPIX',
            'Wait for confirmation on TPIX Chain (~2-4 seconds)',
            'Check balance on the Wallet page — click "Refresh" to update',
        ],
        tip: 'Buy TPIX at tpix.online/trade or Bridge from BSC at tpix.online/bridge',
        img: 'step-fund',
    },
    {
        title: 'Choose Tier & Configure',
        desc: 'Select your node tier and enter your node name',
        details: [
            'Click the "Run a Node" tab',
            'Choose your tier: Light / Sentinel / Guardian / Validator',
            'The app will show estimated rewards for each tier',
            'Click "Next" to confirm your wallet',
            'Enter your node name (e.g., "my-tpix-node")',
            'Review all details on the summary page',
        ],
        tip: 'Start with a Light Node — you can upgrade to a higher tier later.',
        img: 'step-tier',
    },
    {
        title: 'Launch Your Node!',
        desc: 'Click Launch to start running your node',
        details: [
            'Click "Launch Node"',
            'The app will connect to TPIX Chain automatically',
            'Status will change to "Running" when connected',
            'Check Dashboard for Block Height, Peers, and Uptime',
            'The app runs in background — you can minimize to System Tray',
            'Rewards accumulate based on your uptime',
        ],
        tip: 'Keep the app running 24/7 for maximum uptime and rewards. Use a VPS/Cloud for best results.',
        img: 'step-run',
    },
    {
        title: 'Track Rewards & Auto-Update',
        desc: 'Monitor rewards and let the app auto-update',
        details: [
            'View accumulated rewards on the Dashboard',
            'The app checks for updates from GitHub every 30 minutes',
            'When a new version is available, it notifies you to download + install',
            'View reward history and claim when the contract is ready',
            'The "Network" tab shows all validators and chain health',
        ],
        tip: 'The more nodes running = the more stable the network. Invite your friends to run nodes too!',
        img: 'step-rewards',
    },
]);

// Step screenshots — real captures from the app
const stepScreenshots = {
    'step-download': '/images/guide/about.webp',
    'step-wallet': '/images/guide/wallet.webp',
    'step-fund': '/images/guide/wallet.webp',
    'step-tier': '/images/guide/setup-tier.webp',
    'step-run': '/images/guide/dashboard.webp',
    'step-rewards': '/images/guide/network.webp',
};

// Step diagram data (overlay info on screenshots)
const stepDiagrams = {
    'step-download': { icon: '📥', color: '#06b6d4', items: ['GitHub Release', 'TPIX Download Page', 'Portable .exe'] },
    'step-wallet': { icon: '🔐', color: '#a855f7', items: ['Create New', 'Import Key', 'Save Backup'] },
    'step-fund': { icon: '💰', color: '#f59e0b', items: ['Copy Address', 'Send TPIX', 'Verify Balance'] },
    'step-tier': { icon: '⚙️', color: '#06b6d4', items: ['Choose Tier', 'Set Name', 'Review Config'] },
    'step-run': { icon: '▶️', color: '#00c853', items: ['Launch Node', 'Sync Chain', 'Monitor Status'] },
    'step-rewards': { icon: '🎁', color: '#f97316', items: ['Track Rewards', 'Auto Update', 'Claim TPIX'] },
};
</script>

<template>
    <Head :title="isTH ? 'คู่มือตั้ง Master Node' : 'Master Node Setup Guide'" />

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 py-8">
            <!-- Header -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center gap-3 mb-4 px-4 py-2 rounded-full bg-cyan-500/10 border border-cyan-500/20">
                    <img src="/tpixlogo.webp" alt="TPIX" class="w-8 h-8">
                    <span class="text-cyan-400 font-semibold text-sm">TPIX Master Node</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-extrabold text-white mb-3">
                    {{ isTH ? 'คู่มือการตั้งค่า Master Node' : 'Master Node Setup Guide' }}
                </h1>
                <p class="text-dark-400 max-w-2xl mx-auto">
                    {{ isTH
                        ? 'แค่มี TPIX ในกระเป๋าพอ ก็เลือกได้เลยว่าจะตั้งโหนดระดับไหน ทำตามขั้นตอนง่ายๆ แค่ 6 ขั้นตอน'
                        : 'Just have enough TPIX in your wallet and choose your node tier. Follow these 6 simple steps.' }}
                </p>

                <div class="flex gap-3 justify-center mt-6 flex-wrap">
                    <Link href="/masternode" class="btn-primary px-6 py-2.5 rounded-xl text-sm font-semibold">
                        {{ isTH ? 'ตั้งค่าโหนดตอนนี้' : 'Set Up Node Now' }}
                    </Link>
                    <a href="https://github.com/xjanova/TPIX-Coin/releases" target="_blank"
                        class="px-6 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-dark-300 hover:bg-white/5 transition">
                        📥 {{ isTH ? 'ดาวน์โหลดโปรแกรม' : 'Download App' }}
                    </a>
                </div>
            </div>

            <!-- Tier Comparison -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ isTH ? 'เปรียบเทียบระดับโหนด' : 'Compare Node Tiers' }}
                </h2>
                <div class="grid md:grid-cols-3 gap-4">
                    <div v-for="tier in tiers" :key="tier.id"
                        class="glass-card p-5 rounded-2xl border border-white/10 hover:border-white/20 transition-all hover:-translate-y-1 hover:shadow-xl">
                        <div class="text-3xl mb-3">{{ tier.icon }}</div>
                        <h3 class="text-lg font-bold text-white mb-1">{{ tier.name }}</h3>
                        <div class="text-2xl font-extrabold mb-2" :class="`text-${tier.color}-400`">{{ tier.stake }}</div>
                        <div class="text-trading-green font-semibold mb-3">{{ tier.apy }} APY</div>

                        <div class="bg-white/5 rounded-xl p-3 mb-3 space-y-1">
                            <div class="text-xs text-dark-500 uppercase font-semibold">{{ isTH ? 'รางวัลโดยประมาณ' : 'Est. Rewards' }}</div>
                            <div class="text-sm text-cyan-400 font-medium">{{ tier.reward.monthly }}/{{ isTH ? 'เดือน' : 'month' }}</div>
                            <div class="text-xs text-dark-400">{{ tier.reward.yearly }}/{{ isTH ? 'ปี' : 'year' }}</div>
                        </div>

                        <div class="text-xs text-dark-500 space-y-1">
                            <div>🔒 {{ isTH ? 'ล็อค' : 'Lock' }}: {{ tier.lock }}</div>
                            <div>💻 {{ tier.hardware }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step by Step Guide -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ isTH ? 'ขั้นตอนการตั้งค่า (6 ขั้นตอน)' : 'Setup Steps (6 Steps)' }}
                </h2>

                <!-- Step Navigation -->
                <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
                    <button v-for="(step, idx) in steps" :key="idx"
                        @click="activeStep = idx"
                        :class="[
                            'flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap transition-all border',
                            activeStep === idx
                                ? 'bg-cyan-500/15 border-cyan-500/30 text-cyan-400'
                                : 'bg-white/5 border-white/5 text-dark-400 hover:bg-white/10'
                        ]">
                        <span :class="[
                            'w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold',
                            activeStep === idx ? 'bg-cyan-500 text-dark-900' : 'bg-white/10 text-dark-500'
                        ]">{{ idx + 1 }}</span>
                        {{ step.title }}
                    </button>
                </div>

                <!-- Active Step Content -->
                <div class="grid md:grid-cols-5 gap-6">
                    <!-- Left: Details (3 cols) -->
                    <div class="md:col-span-3 glass-card p-6 rounded-2xl border border-white/10">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg font-bold text-cyan-400">
                                {{ activeStep + 1 }}
                            </span>
                            <div>
                                <h3 class="text-lg font-bold text-white">{{ steps[activeStep].title }}</h3>
                                <p class="text-sm text-dark-400">{{ steps[activeStep].desc }}</p>
                            </div>
                        </div>

                        <ol class="space-y-3 mb-6">
                            <li v-for="(detail, i) in steps[activeStep].details" :key="i"
                                class="flex gap-3 text-sm text-dark-300">
                                <span class="w-5 h-5 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-xs text-dark-500 flex-shrink-0 mt-0.5">
                                    {{ i + 1 }}
                                </span>
                                <span>{{ detail }}</span>
                            </li>
                        </ol>

                        <!-- Tip Box -->
                        <div class="bg-yellow-500/5 border border-yellow-500/20 rounded-xl p-4">
                            <div class="flex gap-2 text-sm">
                                <span class="text-yellow-400 flex-shrink-0">💡</span>
                                <span class="text-yellow-200/80">{{ steps[activeStep].tip }}</span>
                            </div>
                        </div>

                        <!-- Navigation -->
                        <div class="flex gap-2 mt-6">
                            <button v-if="activeStep > 0" @click="activeStep--"
                                class="px-4 py-2 rounded-xl text-sm border border-white/10 text-dark-400 hover:bg-white/5">
                                &larr; {{ isTH ? 'ก่อนหน้า' : 'Previous' }}
                            </button>
                            <button v-if="activeStep < steps.length - 1" @click="activeStep++"
                                class="btn-primary px-4 py-2 rounded-xl text-sm font-semibold">
                                {{ isTH ? 'ถัดไป' : 'Next' }} &rarr;
                            </button>
                            <Link v-else href="/masternode" class="btn-primary px-4 py-2 rounded-xl text-sm font-semibold">
                                🚀 {{ isTH ? 'ตั้งค่าโหนดเลย!' : 'Set Up Node Now!' }}
                            </Link>
                        </div>
                    </div>

                    <!-- Right: Screenshot + Info (2 cols) -->
                    <div class="md:col-span-2 glass-card p-4 rounded-2xl border border-white/10 flex flex-col">
                        <!-- Real screenshot from the app -->
                        <div class="relative rounded-xl overflow-hidden mb-4 border border-white/10 cursor-pointer group"
                            @click="lightboxImage = stepScreenshots[steps[activeStep].img]">
                            <img :src="stepScreenshots[steps[activeStep].img]"
                                :alt="steps[activeStep].title"
                                class="w-full h-auto rounded-xl transition-transform group-hover:scale-[1.02]"
                                loading="lazy">
                            <div class="absolute top-2 right-2 bg-black/60 backdrop-blur-sm px-2 py-1 rounded-lg text-xs text-cyan-400 font-semibold">
                                {{ stepDiagrams[steps[activeStep].img]?.icon }} {{ isTH ? 'ตัวอย่างจริง' : 'Live Preview' }}
                            </div>
                            <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/20">
                                <span class="bg-black/60 text-white px-3 py-1 rounded-lg text-sm">🔍 {{ isTH ? 'คลิกเพื่อขยาย' : 'Click to enlarge' }}</span>
                            </div>
                        </div>

                        <!-- Quick steps overlay -->
                        <div class="space-y-2">
                            <div v-for="(item, i) in stepDiagrams[steps[activeStep].img]?.items" :key="i"
                                class="flex items-center gap-3 p-2.5 rounded-xl bg-white/5 border border-white/5">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"
                                    :style="{ background: stepDiagrams[steps[activeStep].img]?.color + '20', color: stepDiagrams[steps[activeStep].img]?.color }">
                                    {{ i + 1 }}
                                </div>
                                <span class="text-sm text-dark-300">{{ item }}</span>
                            </div>
                        </div>

                        <!-- Step progress -->
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-dark-500 mb-1">
                                <span>{{ isTH ? 'ขั้นตอน' : 'Step' }} {{ activeStep + 1 }}/{{ steps.length }}</span>
                                <span>{{ Math.round(((activeStep + 1) / steps.length) * 100) }}%</span>
                            </div>
                            <div class="h-1.5 bg-white/5 rounded-full overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-cyan-500 to-purple-500 rounded-full transition-all duration-300"
                                    :style="{ width: ((activeStep + 1) / steps.length * 100) + '%' }">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reward Economics -->
            <div class="glass-card p-6 rounded-2xl border border-white/10 mb-12">
                <h2 class="text-xl font-bold text-white mb-4">
                    {{ isTH ? '📊 ระบบรางวัล (Reward Pool 1.4 พันล้าน TPIX)' : '📊 Reward System (1.4 Billion TPIX Pool)' }}
                </h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-dark-500 text-xs uppercase border-b border-white/5">
                                <th class="text-left py-3 px-4">{{ isTH ? 'ปี' : 'Year' }}</th>
                                <th class="text-right py-3 px-4">{{ isTH ? 'จำนวนที่ปล่อย' : 'Emission' }}</th>
                                <th class="text-right py-3 px-4">{{ isTH ? 'ต่อบล็อก' : 'Per Block' }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-dark-300">
                            <tr class="border-b border-white/3"><td class="py-3 px-4">{{ isTH ? 'ปีที่' : 'Year' }} 1</td><td class="text-right px-4 text-cyan-400 font-semibold">400,000,000</td><td class="text-right px-4">~25.4 TPIX</td></tr>
                            <tr class="border-b border-white/3"><td class="py-3 px-4">{{ isTH ? 'ปีที่' : 'Year' }} 2</td><td class="text-right px-4">350,000,000</td><td class="text-right px-4">~22.2 TPIX</td></tr>
                            <tr class="border-b border-white/3"><td class="py-3 px-4">{{ isTH ? 'ปีที่' : 'Year' }} 3</td><td class="text-right px-4">300,000,000</td><td class="text-right px-4">~19.0 TPIX</td></tr>
                            <tr class="border-b border-white/3"><td class="py-3 px-4">{{ isTH ? 'ปีที่' : 'Year' }} 4</td><td class="text-right px-4">200,000,000</td><td class="text-right px-4">~12.7 TPIX</td></tr>
                            <tr class="border-b border-white/5"><td class="py-3 px-4">{{ isTH ? 'ปีที่' : 'Year' }} 5</td><td class="text-right px-4">150,000,000</td><td class="text-right px-4">~9.5 TPIX</td></tr>
                            <tr class="font-bold text-yellow-400"><td class="py-3 px-4">{{ isTH ? 'รวม' : 'Total' }}</td><td class="text-right px-4">1,400,000,000 TPIX</td><td class="text-right px-4">—</td></tr>
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-dark-500 mt-4 leading-relaxed">
                    {{ isTH
                        ? '* รางวัลแจกตามสัดส่วน stake + uptime ของคุณ Validator 20%, Guardian 35%, Sentinel 30%, Light 15%'
                        : '* Rewards distributed by stake + uptime proportion. Validators: 20%, Guardians: 35%, Sentinels: 30%, Light: 15%' }}
                </p>
            </div>

            <!-- FAQ -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ isTH ? '❓ คำถามที่พบบ่อย' : '❓ Frequently Asked Questions' }}
                </h2>
                <div class="space-y-3">
                    <div v-for="(faq, i) in (isTH ? [
                        { q: 'ต้องเปิดคอมทิ้งไว้ตลอดไหม?', a: 'ยิ่ง uptime สูง ยิ่งได้รางวัลมาก แนะนำเปิด 24/7 หรือใช้ VPS/Cloud Server' },
                        { q: 'รันหลายโหนดได้ไหม?', a: 'ได้ แต่แต่ละโหนดต้องมี wallet และ stake แยกกัน' },
                        { q: 'ถ้าเน็ตหลุดจะเป็นอย่างไร?', a: 'โหนดจะหยุดชั่วคราว เมื่อเน็ตกลับมาจะเชื่อมต่อใหม่อัตโนมัติ ไม่โดนลงโทษสำหรับ Light Node' },
                        { q: 'Validator กับ Guardian ต่างกันอย่างไร?', a: 'Validator คือ IBFT2 block sealer ตัวจริง ต้อง stake 10M TPIX + KYC บริษัท มีสิทธิ์โหวต governance Guardian คือ premium masternode ต้อง stake 1M TPIX' },
                        { q: 'ยิ่งมีโหนดเยอะ เชนยิ่งเสถียรจริงไหม?', a: 'ใช่! IBFT2 ต้องการ 2/3 ของ validators ออนไลน์ ยิ่งมีเยอะ ยิ่งทนทานต่อการล่ม' },
                        { q: 'โปรแกรมอัปเดตยังไง?', a: 'อัปเดตอัตโนมัติจาก GitHub! เมื่อมีเวอร์ชันใหม่จะแจ้งเตือนในแอป คลิกเดียวอัปเดตได้เลย' },
                    ] : [
                        { q: 'Do I need to keep my computer running 24/7?', a: 'Higher uptime = more rewards. Recommended 24/7 or use a VPS/Cloud Server.' },
                        { q: 'Can I run multiple nodes?', a: 'Yes, but each node needs its own wallet and separate stake.' },
                        { q: 'What happens if my internet disconnects?', a: 'The node pauses temporarily. It reconnects automatically when internet returns. Light Nodes are not penalized.' },
                        { q: 'What is the difference between Validator and Guardian?', a: 'Validators are real IBFT2 block sealers requiring 10M TPIX + company KYC, with governance voting power. Guardians are premium masternodes requiring 1M TPIX.' },
                        { q: 'More nodes = more stable chain?', a: 'Yes! IBFT2 requires 2/3 of validators online. More validators = more fault tolerance.' },
                        { q: 'How does the app update?', a: 'Auto-updates from GitHub! When a new version is available, you get notified in-app. One click to update.' },
                    ])" :key="i"
                        class="glass-card p-5 rounded-xl border border-white/10">
                        <h3 class="font-semibold text-white text-sm mb-2">{{ faq.q }}</h3>
                        <p class="text-dark-400 text-sm leading-relaxed">{{ faq.a }}</p>
                    </div>
                </div>
            </div>

            <!-- App Screenshots Gallery -->
            <div class="mb-12">
                <h2 class="text-xl font-bold text-white mb-6 text-center">
                    {{ isTH ? '📸 ภาพหน้าจอโปรแกรม' : '📸 App Screenshots' }}
                </h2>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <div v-for="ss in [
                        { src: '/images/guide/dashboard.webp', label: isTH ? 'แดชบอร์ด' : 'Dashboard' },
                        { src: '/images/guide/setup-tier.webp', label: isTH ? 'เลือกระดับโหนด' : 'Choose Tier' },
                        { src: '/images/guide/wallet.webp', label: isTH ? 'กระเป๋าเงิน' : 'Wallet' },
                        { src: '/images/guide/network.webp', label: isTH ? 'เครือข่าย' : 'Network' },
                        { src: '/images/guide/links.webp', label: isTH ? 'ลิงก์' : 'Links' },
                        { src: '/images/guide/settings.webp', label: isTH ? 'ตั้งค่า' : 'Settings' },
                        { src: '/images/guide/about.webp', label: isTH ? 'เกี่ยวกับ' : 'About' },
                    ]" :key="ss.src"
                        class="group relative rounded-xl overflow-hidden border border-white/10 hover:border-cyan-500/30 transition-all hover:-translate-y-1 hover:shadow-lg hover:shadow-cyan-500/5 cursor-pointer"
                        @click="lightboxImage = ss.src">
                        <img :src="ss.src" :alt="ss.label" class="w-full h-auto" loading="lazy">
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-2">
                            <span class="text-xs font-semibold text-white">{{ ss.label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CTA -->
            <div class="text-center py-8 glass-card rounded-2xl border border-cyan-500/20 bg-gradient-to-r from-cyan-500/5 via-purple-500/5 to-cyan-500/5">
                <img src="/tpixlogo.webp" alt="TPIX" class="w-16 h-16 mx-auto mb-4">
                <h2 class="text-2xl font-bold text-white mb-2">
                    {{ isTH ? 'พร้อมเริ่มรัน Master Node แล้ว?' : 'Ready to Run a Master Node?' }}
                </h2>
                <p class="text-dark-400 mb-6 max-w-md mx-auto text-sm">
                    {{ isTH
                        ? 'ดาวน์โหลดโปรแกรม สร้างกระเป๋า เติม TPIX แล้วเริ่มรันได้เลย!'
                        : 'Download the app, create a wallet, fund it with TPIX, and start running!' }}
                </p>
                <div class="flex gap-3 justify-center flex-wrap">
                    <a href="https://github.com/xjanova/TPIX-Coin/releases" target="_blank"
                        class="btn-primary px-8 py-3 rounded-xl font-semibold">
                        📥 {{ isTH ? 'ดาวน์โหลดโปรแกรม' : 'Download App' }}
                    </a>
                    <Link href="/masternode" class="px-8 py-3 rounded-xl font-semibold border border-white/10 text-white hover:bg-white/5 transition">
                        ⚡ {{ isTH ? 'ตั้งค่าออนไลน์' : 'Setup Online' }}
                    </Link>
                </div>
                <p class="text-xs text-dark-600 mt-4">
                    Developed by Xman Studio · <a href="https://xman4289.com" target="_blank" class="text-cyan-500 hover:underline">xman4289.com</a>
                </p>
            </div>
        </div>

        <!-- Lightbox Modal -->
        <Teleport to="body">
            <div v-if="lightboxImage"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/85 backdrop-blur-sm cursor-pointer p-4"
                @click="lightboxImage = null">
                <div class="relative max-w-4xl max-h-[90vh]" @click.stop>
                    <img :src="lightboxImage" alt="Screenshot" class="max-w-full max-h-[85vh] rounded-2xl shadow-2xl">
                    <button @click="lightboxImage = null"
                        class="absolute -top-3 -right-3 w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center text-lg transition">
                        &times;
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.glass-card {
    background: rgba(255, 255, 255, 0.03);
    backdrop-filter: blur(12px);
}
.btn-primary {
    background: linear-gradient(135deg, #06b6d4, #0891b2);
    color: white;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
}
.btn-primary:hover {
    filter: brightness(1.15);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(6, 182, 212, 0.3);
}
</style>
