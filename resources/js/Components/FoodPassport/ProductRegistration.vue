<script setup>
/**
 * ProductRegistration — ฟอร์มลงทะเบียนสินค้าใหม่
 * ง่ายเหมือนกดตู้น้ำ: กรอก → กด → ได้ Product ID
 */
import { ref, computed } from 'vue';

const props = defineProps({
    categories: { type: Object, required: true },
    walletAddress: { type: String, default: null },
    isConnected: { type: Boolean, default: false },
});

const emit = defineEmits(['registered']);

const form = ref({
    name: '',
    category: '',
    origin: '',
    producer_name: '',
    description: '',
    weight_kg: '',
    harvest_date: '',
    expiry_date: '',
});

const loading = ref(false);
const error = ref(null);
const success = ref(null);

const isValid = computed(() => {
    return form.value.name && form.value.category && form.value.origin && props.isConnected;
});

async function submitForm() {
    if (!isValid.value) return;
    loading.value = true;
    error.value = null;
    success.value = null;

    try {
        const res = await fetch('/api/v1/food-passport/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Wallet-Address': props.walletAddress,
            },
            body: JSON.stringify({
                ...form.value,
                producer_address: props.walletAddress,
                weight_kg: form.value.weight_kg ? parseFloat(form.value.weight_kg) : null,
            }),
        });

        const json = await res.json();

        if (json.success) {
            success.value = `ลงทะเบียนสำเร็จ! Product ID: ${json.data.id} | Batch: ${json.data.batch_number}`;
            emit('registered', json.data);
            // Reset form
            form.value = { name: '', category: '', origin: '', producer_name: '', description: '', weight_kg: '', harvest_date: '', expiry_date: '' };
        } else {
            error.value = json.error?.message || 'ลงทะเบียนไม่สำเร็จ';
        }
    } catch (e) {
        error.value = e.message || 'Network error';
    }

    loading.value = false;
}
</script>

<template>
    <div class="max-w-2xl mx-auto">
        <!-- Header -->
        <div class="glass-card rounded-2xl p-6 mb-6">
            <div class="flex items-center gap-3">
                <span class="text-3xl">📝</span>
                <div>
                    <h2 class="text-xl font-bold text-white">ลงทะเบียนสินค้า</h2>
                    <p class="text-dark-400 text-sm">ขั้นตอนที่ 1 — กรอกข้อมูลสินค้าเพื่อเริ่มติดตามบน Blockchain</p>
                </div>
            </div>
        </div>

        <!-- Not Connected -->
        <div v-if="!isConnected" class="glass-card rounded-2xl p-12 text-center">
            <p class="text-4xl mb-3">🔒</p>
            <p class="text-white font-medium">กรุณาเชื่อมต่อ Wallet ก่อน</p>
            <p class="text-dark-400 text-sm mt-1">เชื่อมต่อ MetaMask, Trust Wallet, หรือ TPIX Wallet</p>
        </div>

        <!-- Registration Form -->
        <div v-else class="glass-card rounded-2xl p-6 space-y-5">
            <!-- Success -->
            <div v-if="success" class="p-4 rounded-xl bg-trading-green/10 border border-trading-green/30 text-trading-green text-sm">
                {{ success }}
            </div>

            <!-- Error -->
            <div v-if="error" class="p-4 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm">
                {{ error }}
            </div>

            <!-- Name -->
            <div>
                <label class="block text-sm font-medium text-dark-300 mb-1.5">ชื่อสินค้า <span class="text-trading-red">*</span></label>
                <input v-model="form.name" type="text"
                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                    placeholder="เช่น ข้าวหอมมะลิ ทุ่งกุลาร้องไห้" />
            </div>

            <!-- Category -->
            <div>
                <label class="block text-sm font-medium text-dark-300 mb-1.5">หมวดหมู่ <span class="text-trading-red">*</span></label>
                <div class="grid grid-cols-4 gap-2">
                    <button v-for="(cat, key) in categories" :key="key"
                        @click="form.category = key"
                        :class="[
                            'p-3 rounded-xl border text-center transition-all',
                            form.category === key
                                ? 'border-primary-500 bg-primary-500/10'
                                : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                        ]">
                        <span class="text-2xl">{{ cat.emoji }}</span>
                        <p class="text-xs mt-1" :class="form.category === key ? 'text-primary-400' : 'text-dark-400'">{{ cat.label }}</p>
                    </button>
                </div>
            </div>

            <!-- Origin -->
            <div>
                <label class="block text-sm font-medium text-dark-300 mb-1.5">แหล่งผลิต <span class="text-trading-red">*</span></label>
                <input v-model="form.origin" type="text"
                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                    placeholder="เช่น สุรินทร์, ประเทศไทย" />
            </div>

            <!-- Producer Name -->
            <div>
                <label class="block text-sm font-medium text-dark-300 mb-1.5">ชื่อเกษตรกร / ฟาร์ม</label>
                <input v-model="form.producer_name" type="text"
                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                    placeholder="เช่น ฟาร์มลุงสม" />
            </div>

            <!-- Weight + Dates -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-1.5">น้ำหนัก (กก.)</label>
                    <input v-model="form.weight_kg" type="number" step="0.01"
                        class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                        placeholder="0.00" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-1.5">วันเก็บเกี่ยว</label>
                    <input v-model="form.harvest_date" type="date"
                        class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white focus:border-primary-500 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark-300 mb-1.5">วันหมดอายุ</label>
                    <input v-model="form.expiry_date" type="date"
                        class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white focus:border-primary-500 text-sm" />
                </div>
            </div>

            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-dark-300 mb-1.5">รายละเอียดเพิ่มเติม</label>
                <textarea v-model="form.description" rows="3"
                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm resize-none"
                    placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับสินค้า..."></textarea>
            </div>

            <!-- Wallet Info -->
            <div class="p-3 rounded-xl bg-dark-800/30 border border-white/5">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-trading-green"></span>
                    <span class="text-dark-400 text-xs">Wallet:</span>
                    <span class="text-white text-xs font-mono">{{ walletAddress?.slice(0, 6) }}...{{ walletAddress?.slice(-4) }}</span>
                    <span class="text-dark-600 text-xs ml-auto">TPIX Chain | Gas FREE</span>
                </div>
            </div>

            <!-- Submit -->
            <button @click="submitForm" :disabled="!isValid || loading"
                :class="[
                    'w-full py-4 rounded-xl font-bold text-sm transition-all',
                    isValid && !loading
                        ? 'bg-gradient-to-r from-green-500 to-cyan-500 text-white hover:from-green-600 hover:to-cyan-600 shadow-lg shadow-green-500/25'
                        : 'bg-dark-700 text-dark-500 cursor-not-allowed'
                ]">
                {{ loading ? 'กำลังลงทะเบียน...' : 'ลงทะเบียนสินค้า' }}
            </button>
        </div>
    </div>
</template>
