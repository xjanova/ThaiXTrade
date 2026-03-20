<script setup>
/**
 * TokenGuide — สอนสร้างเหรียญ (Token) บน TPIX Chain ละเอียด
 * อธิบายว่าเหรียญถูกผลิตอย่างไร เก็บไว้ที่ไหน ใช้งานอย่างไร
 */
import { ref } from 'vue';

const activeSection = ref('create');
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="glass-card rounded-2xl p-6 bg-gradient-to-r from-amber-500/5 to-purple-500/5">
            <div class="flex items-center gap-3">
                <span class="text-4xl">🪙</span>
                <div>
                    <h2 class="text-2xl font-bold text-white">สร้างเหรียญ (Token) บน TPIX Chain</h2>
                    <p class="text-dark-400 text-sm">คู่มือละเอียด — ง่ายเหมือนกดตู้น้ำ, เข้าใจได้ทุกคน</p>
                </div>
            </div>
        </div>

        <!-- Section Tabs -->
        <div class="flex gap-2 overflow-x-auto">
            <button v-for="sec in [
                { id: 'create', label: 'วิธีสร้างเหรียญ', icon: '🏭' },
                { id: 'where', label: 'เก็บไว้ที่ไหน', icon: '📍' },
                { id: 'use', label: 'ใช้งานอย่างไร', icon: '🔄' },
                { id: 'foodpassport', label: 'เหรียญ FoodPassport', icon: '🌾' },
            ]" :key="sec.id"
            @click="activeSection = sec.id"
            :class="[
                'flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm whitespace-nowrap transition-all',
                activeSection === sec.id ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white'
            ]">
                <span>{{ sec.icon }}</span>
                <span>{{ sec.label }}</span>
            </button>
        </div>

        <!-- Section: วิธีสร้างเหรียญ -->
        <template v-if="activeSection === 'create'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">ขั้นตอนสร้างเหรียญ — ง่ายมาก 5 ขั้นตอน</h3>

                <div class="space-y-6">
                    <div v-for="(step, i) in [
                        {
                            num: 1, title: 'เข้าหน้า Token Factory',
                            desc: 'ไปที่เมนู Token Factory หรือ /token-factory',
                            detail: 'คุณต้องเชื่อมต่อ Wallet ก่อน (MetaMask, Trust Wallet, หรือ TPIX Wallet)',
                            icon: '🏭', color: 'from-blue-500/20 to-blue-600/20'
                        },
                        {
                            num: 2, title: 'กรอกข้อมูลเหรียญ',
                            desc: 'ตั้งชื่อ, สัญลักษณ์, จำนวน supply',
                            detail: 'ชื่อ: FoodCoin | Symbol: FOOD | Supply: 1,000,000 | Decimals: 18 | ประเภท: mintable (สร้างเพิ่มได้)',
                            icon: '📝', color: 'from-green-500/20 to-green-600/20'
                        },
                        {
                            num: 3, title: 'เลือกประเภทเหรียญ',
                            desc: '4 ประเภทให้เลือก',
                            detail: 'Standard (โอนได้อย่างเดียว) | Mintable (สร้างเพิ่มได้) | Burnable (เผาทำลายได้) | Mintable+Burnable (ทำได้ทั้งสอง)',
                            icon: '⚙️', color: 'from-purple-500/20 to-purple-600/20'
                        },
                        {
                            num: 4, title: 'ชำระค่าสร้าง 100 TPIX',
                            desc: 'จ่ายผ่าน wallet — รอ admin ตรวจสอบ',
                            detail: 'ค่าสร้าง 100 TPIX (ประมาณ $10) — ทีม TPIX จะตรวจสอบว่าเหรียญไม่ผิดกฎหมาย แล้วอนุมัติ',
                            icon: '💰', color: 'from-amber-500/20 to-amber-600/20'
                        },
                        {
                            num: 5, title: 'เหรียญพร้อมใช้!',
                            desc: 'Smart Contract ถูก deploy → เทรดได้ทันที',
                            detail: 'เหรียญถูก deploy เป็น ERC-20 บน TPIX Chain (Gas FREE) → Total supply ส่งเข้า wallet คุณ → สร้าง Liquidity Pool เพื่อเทรด',
                            icon: '🚀', color: 'from-pink-500/20 to-pink-600/20'
                        },
                    ]" :key="i" :class="['rounded-2xl p-5 bg-gradient-to-br border border-white/5', step.color]">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center text-xl flex-shrink-0">
                                {{ step.icon }}
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-xs font-bold text-white">{{ step.num }}</span>
                                    <h4 class="text-white font-semibold">{{ step.title }}</h4>
                                </div>
                                <p class="text-dark-300 text-sm mt-1">{{ step.desc }}</p>
                                <p class="text-dark-500 text-xs mt-2">{{ step.detail }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Section: เก็บไว้ที่ไหน -->
        <template v-if="activeSection === 'where'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">เหรียญที่ผลิตมาเก็บไว้ที่ไหน?</h3>

                <div class="space-y-4">
                    <div class="p-5 rounded-xl bg-gradient-to-br from-cyan-500/10 to-blue-500/10 border border-cyan-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">⛓️</span>
                            <div>
                                <h4 class="text-white font-semibold">1. Smart Contract บน TPIX Chain</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    เมื่อเหรียญถูกสร้าง → ระบบจะ <strong class="text-cyan-400">deploy Smart Contract</strong> (โค้ด ERC-20)
                                    บน TPIX Chain (Chain ID: 4289)
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    Smart Contract คือ "กฎของเหรียญ" — กำหนดชื่อ, supply, ใครโอนได้, ใคร mint ได้
                                    <br/>ทุกคนอ่านโค้ดได้ที่ Block Explorer — โปร่งใส 100%
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-green-500/10 to-emerald-500/10 border border-green-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">👛</span>
                            <div>
                                <h4 class="text-white font-semibold">2. Wallet ของผู้สร้าง</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    Total Supply ทั้งหมดจะถูก <strong class="text-green-400">mint เข้า wallet</strong> ของคุณโดยอัตโนมัติ
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    เช่น สร้าง FOOD 1,000,000 เหรียญ → 1,000,000 FOOD อยู่ใน wallet คุณ
                                    <br/>คุณเป็นเจ้าของ 100% — จะแจก, ขาย, หรือสร้าง Liquidity Pool ก็ได้
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-purple-500/10 to-violet-500/10 border border-purple-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🔍</span>
                            <div>
                                <h4 class="text-white font-semibold">3. Block Explorer (ดูข้อมูลทั้งหมด)</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    ดูข้อมูลเหรียญได้ที่ <strong class="text-purple-400">TPIX Chain Block Explorer</strong> (Blockscout)
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    เห็น: Contract address, Total supply, Holders, ทุก Transaction
                                    <br/>ทุกคนดูได้ — ไม่มีอะไรซ่อน
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 border border-amber-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">💱</span>
                            <div>
                                <h4 class="text-white font-semibold">4. DEX (TPIX TRADE) — สร้าง Liquidity Pool</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    สร้าง <strong class="text-amber-400">Liquidity Pool</strong> เพื่อให้คนอื่นซื้อ-ขายเหรียญคุณได้
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    เช่น สร้าง Pool: FOOD/TPIX → ใส่ 100,000 FOOD + 1,000 TPIX
                                    <br/>คนอื่นจะ swap TPIX ↔ FOOD ได้ทันที — ราคาปรับตามอุปสงค์-อุปทาน
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visual Diagram -->
                <div class="mt-6 p-4 rounded-xl bg-dark-800/50 border border-white/5">
                    <p class="text-white font-medium text-sm mb-3 text-center">Flow: เหรียญถูกสร้างและเก็บอย่างไร</p>
                    <div class="flex items-center justify-center gap-2 text-sm flex-wrap">
                        <span class="px-3 py-1.5 rounded-lg bg-blue-500/10 text-blue-400">Token Factory</span>
                        <span class="text-dark-600">→</span>
                        <span class="px-3 py-1.5 rounded-lg bg-cyan-500/10 text-cyan-400">Deploy Contract</span>
                        <span class="text-dark-600">→</span>
                        <span class="px-3 py-1.5 rounded-lg bg-green-500/10 text-green-400">Mint to Wallet</span>
                        <span class="text-dark-600">→</span>
                        <span class="px-3 py-1.5 rounded-lg bg-amber-500/10 text-amber-400">Trade on DEX</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Section: ใช้งานอย่างไร -->
        <template v-if="activeSection === 'use'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">ใช้งานเหรียญอย่างไร?</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="useCase in [
                        { icon: '💸', title: 'โอนให้คนอื่น', desc: 'โอน token จาก wallet ไป wallet อื่น — ฟรีไม่มีค่า gas บน TPIX Chain' },
                        { icon: '💱', title: 'เทรดบน DEX', desc: 'สร้าง Liquidity Pool → คนอื่นซื้อ-ขายได้ที่ TPIX TRADE' },
                        { icon: '🎁', title: 'Airdrop / Reward', desc: 'แจก token เป็นรางวัล — เช่น เกษตรกรที่ผ่าน FoodPassport ได้รับ FOOD token' },
                        { icon: '🗳️', title: 'Governance / Voting', desc: 'ใช้ token เป็นสิทธิ์โหวต — ผู้ถือเหรียญมีส่วนร่วมในการตัดสินใจ' },
                        { icon: '🔒', title: 'Staking', desc: 'ล็อค token เพื่อรับผลตอบแทน — คล้ายฝากประจำ' },
                        { icon: '🔥', title: 'Burn (เผาทำลาย)', desc: 'เผา token เพื่อลด supply — ทำให้เหรียญมีค่ามากขึ้น (ถ้าเป็นประเภท Burnable)' },
                    ]" :key="useCase.title"
                    class="p-4 rounded-xl bg-dark-800/50 border border-white/5">
                        <span class="text-2xl">{{ useCase.icon }}</span>
                        <h4 class="text-white font-semibold text-sm mt-2">{{ useCase.title }}</h4>
                        <p class="text-dark-400 text-xs mt-1">{{ useCase.desc }}</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- Section: เหรียญ FoodPassport -->
        <template v-if="activeSection === 'foodpassport'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">เหรียญในระบบ FoodPassport</h3>

                <div class="space-y-4">
                    <div class="p-5 rounded-xl bg-gradient-to-br from-green-500/10 to-cyan-500/10 border border-green-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🏆</span>
                            <div>
                                <h4 class="text-white font-semibold">NFT Certificate (ERC-721)</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    <strong class="text-green-400">ใบรับรองอาหาร</strong> — ทุกสินค้าที่ผ่านการตรวจสอบจะได้ NFT
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    • แต่ละใบไม่ซ้ำกัน (Non-Fungible)
                                    <br/>• เก็บข้อมูลเส้นทางอาหาร + IoT data ทั้งหมด
                                    <br/>• ผู้บริโภคสแกน QR → เห็น NFT + ประวัติ
                                    <br/>• Contract: FoodPassportNFT.sol บน TPIX Chain
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 border border-amber-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🪙</span>
                            <div>
                                <h4 class="text-white font-semibold">TPIX Token (Native Coin)</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    <strong class="text-amber-400">เหรียญหลัก</strong> ของระบบ TPIX — ใช้ในทุก ecosystem
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    • Total Supply: 7,000,000,000 TPIX (fixed, ไม่เพิ่ม)
                                    <br/>• ใช้จ่ายค่าสร้าง Token (100 TPIX)
                                    <br/>• ใช้ซื้อ Carbon Credits
                                    <br/>• ใช้ Staking รับผลตอบแทน 5-200% APY
                                    <br/>• Gas FREE บน TPIX Chain
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-purple-500/10 to-pink-500/10 border border-purple-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🌾</span>
                            <div>
                                <h4 class="text-white font-semibold">สร้างเหรียญเฉพาะ FoodPassport (ตัวอย่าง)</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    คุณสามารถสร้าง <strong class="text-purple-400">เหรียญของตัวเอง</strong> สำหรับ FoodPassport
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    ตัวอย่าง: สร้างเหรียญ RICE สำหรับระบบรับรองข้าว
                                    <br/>• ชื่อ: Thai Rice Token | Symbol: RICE
                                    <br/>• Supply: 100,000 | Type: Mintable
                                    <br/>• ใช้: แจกเกษตรกรที่ผ่าน FoodPassport เป็นรางวัล
                                    <br/>• เทรดได้บน TPIX TRADE DEX
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CTA -->
                <div class="mt-6 p-4 rounded-xl bg-primary-500/10 border border-primary-500/20 text-center">
                    <p class="text-white font-medium text-sm mb-2">พร้อมสร้างเหรียญของคุณ?</p>
                    <a href="/token-factory" class="inline-block px-6 py-2.5 rounded-xl bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-all">
                        ไปที่ Token Factory →
                    </a>
                </div>
            </div>
        </template>
    </div>
</template>
