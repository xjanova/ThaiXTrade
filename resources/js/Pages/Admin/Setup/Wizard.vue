<script setup>
/**
 * TPIX TRADE — Super Admin Setup Wizard
 * First-time setup: create super admin + configure fee wallet
 * Only shown when no admin exists in database
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const step = ref(1);
const totalSteps = 3;

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    fee_collector_wallet: '',
});

const isSubmitting = ref(false);

const canProceedStep1 = computed(() => form.name.length >= 2 && form.email.includes('@'));
const canProceedStep2 = computed(() => form.password.length >= 8 && form.password === form.password_confirmation);

function nextStep() {
    if (step.value < totalSteps) step.value++;
}

function prevStep() {
    if (step.value > 1) step.value--;
}

function submitSetup() {
    isSubmitting.value = true;
    form.post('/admin/setup', {
        onFinish: () => { isSubmitting.value = false; },
    });
}
</script>

<template>
    <Head title="TPIX TRADE — First Time Setup" />

    <div class="min-h-screen bg-dark-950 flex items-center justify-center p-4">
        <!-- Subtle background -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute inset-0 bg-cover bg-center opacity-[0.03]"
                style="background-image: url('/images/bg1.webp')" />
        </div>

        <div class="relative w-full max-w-lg">
            <!-- Logo + Title -->
            <div class="text-center mb-8">
                <img src="/logo.webp" alt="TPIX TRADE" class="w-20 h-20 mx-auto mb-4 object-contain" />
                <h1 class="text-3xl font-black text-white">TPIX <span class="text-cyan-400">TRADE</span></h1>
                <p class="text-gray-500 mt-1">Super Admin Setup Wizard</p>
            </div>

            <!-- Progress -->
            <div class="flex items-center justify-center gap-2 mb-8">
                <div v-for="s in totalSteps" :key="s"
                    class="h-1.5 rounded-full transition-all duration-300"
                    :class="s <= step ? 'bg-cyan-500 w-12' : 'bg-white/10 w-8'"
                />
            </div>

            <!-- Card -->
            <div class="bg-dark-900/80 backdrop-blur-xl rounded-2xl border border-white/10 p-8">

                <!-- Step 1: Admin Info -->
                <div v-if="step === 1">
                    <h2 class="text-xl font-bold text-white mb-1">👤 สร้างบัญชี Super Admin</h2>
                    <p class="text-sm text-gray-500 mb-6">Create your administrator account</p>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">ชื่อ / Name</label>
                            <input v-model="form.name" type="text" placeholder="Admin Name"
                                class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none" />
                            <p v-if="form.errors.name" class="text-red-400 text-xs mt-1">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">อีเมล / Email</label>
                            <input v-model="form.email" type="email" placeholder="admin@tpix.online"
                                class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none" />
                            <p v-if="form.errors.email" class="text-red-400 text-xs mt-1">{{ form.errors.email }}</p>
                        </div>
                    </div>

                    <button @click="nextStep" :disabled="!canProceedStep1"
                        class="w-full mt-6 py-3 rounded-xl font-bold text-white transition"
                        :class="canProceedStep1 ? 'bg-cyan-500 hover:bg-cyan-600' : 'bg-gray-700 cursor-not-allowed'">
                        ถัดไป →
                    </button>
                </div>

                <!-- Step 2: Password -->
                <div v-if="step === 2">
                    <h2 class="text-xl font-bold text-white mb-1">🔒 ตั้งรหัสผ่าน</h2>
                    <p class="text-sm text-gray-500 mb-6">Set a strong password (min 8 characters)</p>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">รหัสผ่าน / Password</label>
                            <input v-model="form.password" type="password" placeholder="••••••••"
                                class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">ยืนยันรหัสผ่าน / Confirm</label>
                            <input v-model="form.password_confirmation" type="password" placeholder="••••••••"
                                class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none" />
                            <p v-if="form.password && form.password_confirmation && form.password !== form.password_confirmation"
                                class="text-red-400 text-xs mt-1">รหัสผ่านไม่ตรงกัน</p>
                        </div>
                    </div>

                    <div class="flex gap-3 mt-6">
                        <button @click="prevStep" class="flex-1 py-3 rounded-xl font-bold text-gray-400 border border-white/10 hover:bg-white/5 transition">
                            ← ย้อนกลับ
                        </button>
                        <button @click="nextStep" :disabled="!canProceedStep2"
                            class="flex-1 py-3 rounded-xl font-bold text-white transition"
                            :class="canProceedStep2 ? 'bg-cyan-500 hover:bg-cyan-600' : 'bg-gray-700 cursor-not-allowed'">
                            ถัดไป →
                        </button>
                    </div>
                </div>

                <!-- Step 3: Fee Wallet + Confirm -->
                <div v-if="step === 3">
                    <h2 class="text-xl font-bold text-white mb-1">💰 กระเป๋ารับค่าธรรมเนียม</h2>
                    <p class="text-sm text-gray-500 mb-6">Fee collector wallet (optional — can set later in admin)</p>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Wallet Address (0x...)</label>
                            <input v-model="form.fee_collector_wallet" type="text" placeholder="0x..."
                                class="w-full bg-dark-800 border border-white/10 rounded-xl px-4 py-3 text-white font-mono text-sm focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none" />
                            <p class="text-xs text-gray-600 mt-1">ที่อยู่กระเป๋าสำหรับรับค่า fee จากการเทรด/swap</p>
                        </div>

                        <!-- Summary -->
                        <div class="bg-dark-800/50 rounded-xl p-4 border border-white/5">
                            <h3 class="text-sm font-bold text-gray-400 mb-3">สรุปการตั้งค่า</h3>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Name</span>
                                    <span class="text-white font-medium">{{ form.name }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Email</span>
                                    <span class="text-cyan-400">{{ form.email }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Role</span>
                                    <span class="text-amber-400 font-semibold">Super Admin</span>
                                </div>
                                <div class="flex justify-between" v-if="form.fee_collector_wallet">
                                    <span class="text-gray-500">Fee Wallet</span>
                                    <span class="text-green-400 font-mono text-xs">{{ form.fee_collector_wallet.slice(0, 10) }}...{{ form.fee_collector_wallet.slice(-6) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 mt-6">
                        <button @click="prevStep" class="flex-1 py-3 rounded-xl font-bold text-gray-400 border border-white/10 hover:bg-white/5 transition">
                            ← ย้อนกลับ
                        </button>
                        <button @click="submitSetup" :disabled="isSubmitting"
                            class="flex-1 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 transition disabled:opacity-50">
                            {{ isSubmitting ? 'กำลังสร้าง...' : '✨ เริ่มต้นใช้งาน' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <p class="text-center text-xs text-gray-600 mt-6">
                TPIX TRADE v1.0 · Developed by Xman Studio
            </p>
        </div>
    </div>
</template>
