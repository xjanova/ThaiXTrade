<script setup>
/**
 * HowItWorks — Tutorial ละเอียด สอนทุกขั้นตอน
 * ง่ายเหมือนกดตู้น้ำ
 */
defineProps({
    categories: { type: Object, required: true },
    stageConfig: { type: Object, required: true },
});

const activeStep = defineModel('activeStep', { default: 1 });

const steps = [
    {
        num: 1,
        title: 'ลงทะเบียนสินค้า',
        subtitle: 'เกษตรกร / ผู้ผลิต',
        icon: '📝',
        color: 'green',
        details: [
            'เชื่อมต่อ Wallet (MetaMask, Trust Wallet, หรือ TPIX Wallet)',
            'กดแท็บ "ลงทะเบียน" → กรอกข้อมูลสินค้า',
            'ระบุ: ชื่อ, หมวดหมู่, แหล่งผลิต, น้ำหนัก, วันเก็บเกี่ยว',
            'กด "ลงทะเบียน" → Smart Contract บันทึกบน TPIX Chain',
            'ได้ Product ID → ใช้สำหรับ IoT tracking',
        ],
        code: `// Smart Contract เรียก registerProduct()
contract.registerProduct(
  "ข้าวหอมมะลิ ทุ่งกุลาร้องไห้",
  "grain",
  "สุรินทร์, ประเทศไทย"
);
// → ได้ Product ID: 42`,
        chainInfo: 'Transaction ถูกบันทึกบน TPIX Chain (Chain ID: 4289) — Gas FREE, ไม่มีค่าธรรมเนียม',
    },
    {
        num: 2,
        title: 'IoT Sensor บันทึกข้อมูล',
        subtitle: 'อัตโนมัติทุกจุด',
        icon: '📡',
        color: 'blue',
        details: [
            'ติดตั้ง IoT sensor ที่ฟาร์ม, โรงงาน, รถขนส่ง, คลังสินค้า',
            'Sensor วัด: อุณหภูมิ, ความชื้น, GPS, น้ำหนัก, ค่า pH',
            'ส่งข้อมูลอัตโนมัติผ่าน API ทุก 15 นาที (หรือตั้งเวลาเอง)',
            'ข้อมูลถูกบันทึกทั้งใน Database และ Blockchain',
            'ตรวจสอบ cold chain — แจ้งเตือนเมื่ออุณหภูมิผิดปกติ',
        ],
        code: `// IoT Device ส่ง HTTP POST
POST /api/v1/food-passport/iot/ingest
{
  "device_id": "TPIX-IOT-001",
  "product_id": 42,
  "stage": "transport",
  "temperature": 4.5,
  "humidity": 65.2,
  "location": "13.7563,100.5018"
}`,
        chainInfo: 'IoT device มี wallet address → เรียก addTrace() บน Smart Contract อัตโนมัติ',
    },
    {
        num: 3,
        title: 'Mint NFT ใบรับรอง',
        subtitle: 'กดปุ่มเดียว',
        icon: '🏆',
        color: 'purple',
        details: [
            'เมื่อสินค้าผ่านทุกจุดตรวจ (อย่างน้อย 2 จุด)',
            'กดปุ่ม "Mint Certificate" ในหน้า "สินค้าของฉัน"',
            'Smart Contract สร้าง NFT (ERC-721) บน TPIX Chain',
            'NFT เก็บข้อมูล: ชื่อสินค้า, เส้นทาง, อุณหภูมิ, ใบรับรอง',
            'เหรียญที่ Mint → อยู่ใน Wallet ของผู้ผลิต ตลอดไป',
        ],
        code: `// Smart Contract เรียก mintCertificate()
contract.mintCertificate(
  42,  // Product ID
  "ipfs://Qm.../metadata.json"  // Token URI
);
// → ได้ NFT Token ID: 1
// → NFT อยู่ใน wallet ของเกษตรกร`,
        chainInfo: 'NFT เป็น ERC-721 บน TPIX Chain — ดูได้ที่ Block Explorer, โอนให้คนอื่นได้',
    },
    {
        num: 4,
        title: 'ผู้บริโภคสแกน QR',
        subtitle: 'เห็นทุกอย่าง',
        icon: '📱',
        color: 'pink',
        details: [
            'ผู้บริโภคสแกน QR Code บนบรรจุภัณฑ์',
            'เห็นเส้นทางอาหารแบบ Timeline: ฟาร์ม → โรงงาน → ขนส่ง → ร้านค้า',
            'ดูข้อมูล IoT: อุณหภูมิตลอดการขนส่ง, GPS ติดตามสินค้า',
            'เห็น NFT ใบรับรอง — ยืนยันว่าของแท้ ผ่านมาตรฐาน',
            'ข้อมูลทั้งหมดอ่านจาก Blockchain โดยตรง — ปลอมไม่ได้',
        ],
        code: `// ผู้บริโภคเรียก API
GET /api/v1/food-passport/verify/42

// หรืออ่านจาก chain โดยตรง
const product = await contract.getProduct(42);
const traces = await contract.getProductTraceIds(42);
// → เห็นข้อมูลทั้งหมดแบบ real-time`,
        chainInfo: 'ข้อมูลบน Blockchain ไม่สามารถแก้ไขหรือลบได้ — ปลอดภัย 100%',
    },
];

const tokenCreationSteps = [
    { num: 1, title: 'เข้าหน้า Token Factory', desc: 'ไปที่ /token-factory → กรอกข้อมูลเหรียญ', icon: '🏭' },
    { num: 2, title: 'ตั้งค่าเหรียญ', desc: 'ชื่อ, สัญลักษณ์, จำนวน supply, ประเภท (mintable/burnable)', icon: '⚙️' },
    { num: 3, title: 'ชำระค่าสร้าง', desc: 'ค่าสร้าง 100 TPIX — จ่ายจาก wallet', icon: '💰' },
    { num: 4, title: 'รอ Admin อนุมัติ', desc: 'ทีม TPIX ตรวจสอบ → อนุมัติ → Deploy contract', icon: '✅' },
    { num: 5, title: 'เหรียญพร้อมใช้', desc: 'Contract ถูก deploy บน TPIX Chain → เทรดได้ทันที', icon: '🚀' },
];
</script>

<template>
    <div class="space-y-8">
        <!-- Header -->
        <div class="glass-card rounded-2xl p-6">
            <h2 class="text-2xl font-bold text-white mb-2">วิธีใช้งาน FoodPassport</h2>
            <p class="text-dark-400">ง่ายเหมือนกดตู้น้ำ — ทำตาม 4 ขั้นตอน ได้ใบรับรองอาหารบน Blockchain</p>
        </div>

        <!-- Step Cards -->
        <div class="space-y-6">
            <div v-for="step in steps" :key="step.num"
                class="glass-card rounded-2xl overflow-hidden transition-all"
                :class="{ 'ring-1 ring-primary-500/30': activeStep === step.num }">

                <!-- Step Header -->
                <button @click="activeStep = activeStep === step.num ? 0 : step.num"
                    class="w-full flex items-center gap-4 p-6 text-left hover:bg-white/[0.02] transition-all">
                    <span class="text-4xl">{{ step.icon }}</span>
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-gradient-to-br flex items-center justify-center text-sm font-bold text-white"
                                :class="{
                                    'from-green-500 to-green-600': step.color === 'green',
                                    'from-blue-500 to-blue-600': step.color === 'blue',
                                    'from-purple-500 to-purple-600': step.color === 'purple',
                                    'from-pink-500 to-pink-600': step.color === 'pink',
                                }">
                                {{ step.num }}
                            </span>
                            <h3 class="text-lg font-bold text-white">{{ step.title }}</h3>
                            <span class="text-dark-500 text-sm">— {{ step.subtitle }}</span>
                        </div>
                    </div>
                    <svg class="w-5 h-5 text-dark-500 transition-transform" :class="{ 'rotate-180': activeStep === step.num }"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <!-- Step Details (Expandable) -->
                <div v-if="activeStep === step.num" class="px-6 pb-6 space-y-4">
                    <!-- Checklist -->
                    <div class="bg-dark-800/50 rounded-xl p-4">
                        <h4 class="text-white font-medium text-sm mb-3">ขั้นตอนย่อย:</h4>
                        <div class="space-y-2">
                            <div v-for="(detail, i) in step.details" :key="i" class="flex items-start gap-2">
                                <span class="text-trading-green text-sm mt-0.5">✓</span>
                                <span class="text-dark-300 text-sm">{{ detail }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Code Example -->
                    <div class="bg-dark-900 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs text-dark-500">Code Example</span>
                            <span class="px-1.5 py-0.5 rounded bg-primary-500/10 text-primary-400 text-[10px]">Solidity / API</span>
                        </div>
                        <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap">{{ step.code }}</pre>
                    </div>

                    <!-- Chain Info -->
                    <div class="flex items-start gap-2 p-3 rounded-xl bg-cyan-500/5 border border-cyan-500/10">
                        <span class="text-cyan-400 text-sm mt-0.5">⛓️</span>
                        <span class="text-cyan-300 text-sm">{{ step.chainInfo }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Token Creation Guide -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-xl font-bold text-white mb-2">การสร้างเหรียญ (Token) บน TPIX Chain</h3>
            <p class="text-dark-400 text-sm mb-6">เหรียญที่สร้างจะอยู่บน TPIX Chain (Chain ID: 4289) — Gas FREE, ค่าสร้าง 100 TPIX</p>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div v-for="s in tokenCreationSteps" :key="s.num"
                    class="relative p-4 rounded-xl bg-dark-800/50 border border-white/5 text-center">
                    <span class="text-3xl">{{ s.icon }}</span>
                    <p class="text-white font-medium text-sm mt-2">{{ s.title }}</p>
                    <p class="text-dark-500 text-xs mt-1">{{ s.desc }}</p>
                    <!-- Arrow -->
                    <div v-if="s.num < 5" class="hidden md:block absolute -right-3 top-1/2 -translate-y-1/2 text-dark-600 z-10">→</div>
                </div>
            </div>

            <div class="mt-6 p-4 rounded-xl bg-dark-800/50 border border-white/5">
                <h4 class="text-white font-medium text-sm mb-2">เหรียญถูกเก็บที่ไหน?</h4>
                <div class="space-y-2 text-sm text-dark-300">
                    <p>1. <strong class="text-white">Smart Contract</strong> — เหรียญถูก deploy เป็น ERC-20 contract บน TPIX Chain</p>
                    <p>2. <strong class="text-white">Wallet ของ Creator</strong> — Total supply ทั้งหมดจะถูก mint เข้า wallet ผู้สร้าง</p>
                    <p>3. <strong class="text-white">TPIX Chain Explorer</strong> — ดูข้อมูล contract, ยอดถือ, ประวัติ transaction ได้ที่ Block Explorer</p>
                    <p>4. <strong class="text-white">DEX (TPIX TRADE)</strong> — สร้าง Liquidity Pool เพื่อให้คนอื่นเทรดเหรียญคุณได้</p>
                </div>
            </div>
        </div>

        <!-- IoT Architecture -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-xl font-bold text-white mb-4">สถาปัตยกรรม IoT + Blockchain</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-4 rounded-xl bg-gradient-to-br from-green-500/10 to-green-600/10 border border-green-500/10">
                    <p class="text-2xl mb-2">📡</p>
                    <h4 class="text-white font-semibold text-sm">IoT Layer</h4>
                    <ul class="mt-2 space-y-1 text-xs text-dark-400">
                        <li>• Temperature / Humidity sensor</li>
                        <li>• GPS tracker</li>
                        <li>• Camera + Weight scale</li>
                        <li>• pH meter</li>
                        <li>• ส่งข้อมูลผ่าน HTTP API</li>
                    </ul>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-br from-blue-500/10 to-blue-600/10 border border-blue-500/10">
                    <p class="text-2xl mb-2">🖥️</p>
                    <h4 class="text-white font-semibold text-sm">Backend Layer</h4>
                    <ul class="mt-2 space-y-1 text-xs text-dark-400">
                        <li>• Laravel API รับข้อมูล IoT</li>
                        <li>• Validate + บันทึกลง Database</li>
                        <li>• เรียก Smart Contract</li>
                        <li>• แจ้งเตือนผิดปกติ (Alert)</li>
                        <li>• Generate QR Code</li>
                    </ul>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-br from-purple-500/10 to-purple-600/10 border border-purple-500/10">
                    <p class="text-2xl mb-2">⛓️</p>
                    <h4 class="text-white font-semibold text-sm">Blockchain Layer</h4>
                    <ul class="mt-2 space-y-1 text-xs text-dark-400">
                        <li>• TPIX Chain (EVM, Gas FREE)</li>
                        <li>• FoodPassportNFT contract</li>
                        <li>• ERC-721 NFT ใบรับรอง</li>
                        <li>• Immutable trace records</li>
                        <li>• Block Explorer (Blockscout)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
