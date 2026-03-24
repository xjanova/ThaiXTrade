<script setup>
/**
 * TPIX TRADE — Embedded Wallet Setup
 * สร้าง/Import TPIX Wallet ในตัวเว็บ — ไม่ต้อง MetaMask
 * Developed by Xman Studio.
 */
import { ref, computed } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';

const emit = defineEmits(['done', 'cancel']);
const walletStore = useWalletStore();

// Steps: choose → password → mnemonic → confirm → done
const step = ref('choose'); // 'choose', 'password', 'mnemonic', 'confirm', 'import', 'done'
const mode = ref('create'); // 'create' หรือ 'import'
const password = ref('');
const confirmPassword = ref('');
const mnemonic = ref('');
const importInput = ref('');
const resultAddress = ref('');
const isLoading = ref(false);
const errorMsg = ref('');

// Mnemonic confirmation — สุ่ม 3 คำให้ยืนยัน
const confirmWords = ref([]);
const confirmAnswers = ref({});
const mnemonicWords = computed(() => mnemonic.value.split(' '));

function startCreate() {
    mode.value = 'create';
    step.value = 'password';
}

function startImport() {
    mode.value = 'import';
    step.value = 'import';
}

async function handlePasswordSubmit() {
    errorMsg.value = '';
    if (password.value.length < 8) {
        errorMsg.value = 'Password ต้องมีอย่างน้อย 8 ตัวอักษร';
        return;
    }
    if (password.value !== confirmPassword.value) {
        errorMsg.value = 'Password ไม่ตรงกัน';
        return;
    }

    isLoading.value = true;
    try {
        const result = await walletStore.createEmbeddedWallet(password.value);
        mnemonic.value = result.mnemonic;
        resultAddress.value = result.address;
        step.value = 'mnemonic';
    } catch (err) {
        errorMsg.value = err.message;
    } finally {
        isLoading.value = false;
    }
}

function confirmMnemonic() {
    // สุ่ม 3 ตำแหน่ง
    const indices = [];
    const words = mnemonicWords.value;
    while (indices.length < 3) {
        const idx = Math.floor(Math.random() * words.length);
        if (!indices.includes(idx)) indices.push(idx);
    }
    indices.sort((a, b) => a - b);
    confirmWords.value = indices.map(i => ({ index: i, word: words[i] }));
    confirmAnswers.value = {};
    step.value = 'confirm';
}

function verifyConfirmation() {
    errorMsg.value = '';
    for (const cw of confirmWords.value) {
        if ((confirmAnswers.value[cw.index] || '').toLowerCase().trim() !== cw.word) {
            errorMsg.value = `คำที่ ${cw.index + 1} ไม่ถูกต้อง`;
            return;
        }
    }
    step.value = 'done';
}

async function handleImport() {
    errorMsg.value = '';
    if (!importInput.value.trim()) {
        errorMsg.value = 'กรุณาใส่ Mnemonic (12 คำ) หรือ Private Key';
        return;
    }
    if (password.value.length < 8) {
        errorMsg.value = 'Password ต้องมีอย่างน้อย 8 ตัวอักษร';
        return;
    }

    isLoading.value = true;
    try {
        const addr = await walletStore.importEmbeddedWallet(importInput.value, password.value);
        resultAddress.value = addr;
        step.value = 'done';
    } catch (err) {
        errorMsg.value = err.message;
    } finally {
        isLoading.value = false;
    }
}

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors text-sm';
</script>

<template>
    <div class="space-y-5">
        <!-- Step: Choose -->
        <template v-if="step === 'choose'">
            <div class="text-center space-y-4">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-primary-500/20 flex items-center justify-center">
                    <img src="/tpixlogo.webp" class="w-10 h-10" alt="TPIX" />
                </div>
                <h3 class="text-xl font-bold text-white">TPIX Wallet</h3>
                <p class="text-dark-400 text-sm">Wallet ในตัวเว็บ — ไม่ต้องติดตั้ง extension</p>

                <div class="space-y-3 pt-2">
                    <button @click="startCreate" class="w-full py-3 px-4 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">
                        สร้าง Wallet ใหม่
                    </button>
                    <button @click="startImport" class="w-full py-3 px-4 bg-dark-700 hover:bg-dark-600 text-dark-300 rounded-xl font-medium transition-colors border border-dark-600">
                        Import จาก Mnemonic / Private Key
                    </button>
                </div>
            </div>
        </template>

        <!-- Step: Password (สำหรับสร้างใหม่) -->
        <template v-if="step === 'password'">
            <h3 class="text-lg font-bold text-white">ตั้ง Password</h3>
            <p class="text-dark-400 text-sm">ใช้สำหรับ unlock wallet ทุกครั้ง</p>

            <div class="space-y-3">
                <input v-model="password" type="password" :class="inputClass" placeholder="Password (อย่างน้อย 8 ตัว)" />
                <input v-model="confirmPassword" type="password" :class="inputClass" placeholder="ยืนยัน Password" />
            </div>

            <p v-if="errorMsg" class="text-trading-red text-sm">{{ errorMsg }}</p>

            <div class="flex gap-3">
                <button @click="step = 'choose'" class="flex-1 py-2 text-dark-400 hover:text-white transition-colors">กลับ</button>
                <button @click="handlePasswordSubmit" :disabled="isLoading" class="flex-1 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">
                    {{ isLoading ? 'กำลังสร้าง...' : 'สร้าง Wallet' }}
                </button>
            </div>
        </template>

        <!-- Step: Show Mnemonic -->
        <template v-if="step === 'mnemonic'">
            <h3 class="text-lg font-bold text-white">Seed Phrase ของคุณ</h3>
            <div class="bg-trading-red/10 border border-trading-red/30 rounded-xl p-3">
                <p class="text-trading-red text-sm font-medium">จดไว้ให้ดี! ถ้าหาย จะเข้า wallet ไม่ได้อีก</p>
            </div>

            <div class="grid grid-cols-3 gap-2 bg-dark-900 rounded-xl p-4">
                <div v-for="(word, idx) in mnemonicWords" :key="idx" class="flex items-center gap-2 bg-dark-800 rounded-lg px-3 py-2">
                    <span class="text-dark-500 text-xs w-5">{{ idx + 1 }}</span>
                    <span class="text-white text-sm font-mono">{{ word }}</span>
                </div>
            </div>

            <button @click="confirmMnemonic" class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">
                จดแล้ว — ยืนยัน
            </button>
        </template>

        <!-- Step: Confirm Mnemonic -->
        <template v-if="step === 'confirm'">
            <h3 class="text-lg font-bold text-white">ยืนยัน Seed Phrase</h3>
            <p class="text-dark-400 text-sm">ใส่คำที่ถูกต้องตามตำแหน่ง</p>

            <div class="space-y-3">
                <div v-for="cw in confirmWords" :key="cw.index">
                    <label class="text-dark-400 text-sm mb-1 block">คำที่ {{ cw.index + 1 }}</label>
                    <input v-model="confirmAnswers[cw.index]" :class="inputClass" :placeholder="`คำที่ ${cw.index + 1}`" />
                </div>
            </div>

            <p v-if="errorMsg" class="text-trading-red text-sm">{{ errorMsg }}</p>

            <button @click="verifyConfirmation" class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">
                ยืนยัน
            </button>
        </template>

        <!-- Step: Import -->
        <template v-if="step === 'import'">
            <h3 class="text-lg font-bold text-white">Import Wallet</h3>

            <div class="space-y-3">
                <textarea v-model="importInput" :class="inputClass" rows="3" placeholder="ใส่ Mnemonic 12 คำ (คั่นด้วยเว้นวรรค) หรือ Private Key (0x...)"></textarea>
                <input v-model="password" type="password" :class="inputClass" placeholder="ตั้ง Password (อย่างน้อย 8 ตัว)" />
            </div>

            <p v-if="errorMsg" class="text-trading-red text-sm">{{ errorMsg }}</p>

            <div class="flex gap-3">
                <button @click="step = 'choose'" class="flex-1 py-2 text-dark-400 hover:text-white transition-colors">กลับ</button>
                <button @click="handleImport" :disabled="isLoading" class="flex-1 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">
                    {{ isLoading ? 'กำลัง Import...' : 'Import' }}
                </button>
            </div>
        </template>

        <!-- Step: Done! -->
        <template v-if="step === 'done'">
            <div class="text-center space-y-4">
                <div class="w-16 h-16 mx-auto rounded-full bg-trading-green/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-white">TPIX Wallet พร้อมใช้งาน!</h3>
                <p class="text-dark-400 text-sm font-mono bg-dark-800 rounded-lg px-3 py-2 break-all">{{ resultAddress }}</p>
                <p class="text-dark-500 text-xs">Chain: TPIX Chain (4289) • Gas: Free</p>

                <button @click="emit('done')" class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium transition-colors">
                    เริ่มใช้งาน
                </button>
            </div>
        </template>
    </div>
</template>
