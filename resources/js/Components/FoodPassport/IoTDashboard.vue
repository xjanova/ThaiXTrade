<script setup>
/**
 * IoTDashboard — จัดการอุปกรณ์ IoT
 * ลงทะเบียน device, ดูสถานะ, ตั้งค่า
 */
import { ref } from 'vue';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    walletAddress: { type: String, default: null },
    isConnected: { type: Boolean, default: false },
});

const emit = defineEmits(['device-registered']);

const showRegister = ref(false);
const loading = ref(false);
const error = ref(null);
const success = ref(null);

const deviceTypes = {
    temperature: { label: 'Temperature', icon: '🌡️', desc: 'วัดอุณหภูมิ (Cold Chain)' },
    humidity: { label: 'Humidity', icon: '💧', desc: 'วัดความชื้น' },
    gps: { label: 'GPS', icon: '📍', desc: 'ติดตามตำแหน่ง' },
    camera: { label: 'Camera', icon: '📸', desc: 'ถ่ายภาพสินค้า' },
    weight: { label: 'Weight', icon: '⚖️', desc: 'ชั่งน้ำหนัก' },
    ph: { label: 'pH Meter', icon: '🧪', desc: 'วัดค่า pH' },
    multi: { label: 'Multi-Sensor', icon: '📡', desc: 'หลาย sensor ในตัวเดียว' },
};

const form = ref({
    name: '',
    type: 'multi',
    location: '',
});

async function registerDevice() {
    loading.value = true;
    error.value = null;

    try {
        const res = await fetch('/api/v1/food-passport/iot/register-device', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Wallet-Address': props.walletAddress,
            },
            body: JSON.stringify({
                ...form.value,
                owner_address: props.walletAddress,
            }),
        });

        const json = await res.json();

        if (json.success) {
            success.value = `ลงทะเบียนสำเร็จ! Device ID: ${json.data.device_id}`;
            emit('device-registered', json.data);
            showRegister.value = false;
            form.value = { name: '', type: 'multi', location: '' };
        } else {
            error.value = json.error?.message || 'ลงทะเบียนไม่สำเร็จ';
        }
    } catch (e) {
        error.value = e.message;
    }

    loading.value = false;
}
</script>

<template>
    <div>
        <!-- Not Connected -->
        <div v-if="!isConnected" class="glass-card rounded-2xl p-12 text-center">
            <p class="text-4xl mb-3">🔒</p>
            <p class="text-white font-medium">กรุณาเชื่อมต่อ Wallet</p>
        </div>

        <div v-else>
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-xl font-bold text-white">IoT Devices</h2>
                    <p class="text-dark-400 text-sm">จัดการอุปกรณ์ IoT ที่ส่งข้อมูลเข้าระบบ FoodPassport</p>
                </div>
                <button @click="showRegister = !showRegister" class="btn-primary text-sm px-4 py-2 rounded-xl">
                    + ลงทะเบียน Device
                </button>
            </div>

            <!-- Success Message -->
            <div v-if="success" class="mb-4 p-4 rounded-xl bg-trading-green/10 border border-trading-green/30 text-trading-green text-sm">
                {{ success }}
            </div>

            <!-- Register Form -->
            <div v-if="showRegister" class="glass-card rounded-2xl p-6 mb-6">
                <h3 class="text-white font-semibold mb-4">ลงทะเบียน IoT Device ใหม่</h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm text-dark-300 mb-1.5">ชื่ออุปกรณ์</label>
                        <input v-model="form.name" type="text"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                            placeholder="เช่น Sensor ฟาร์มข้าว สุรินทร์" />
                    </div>

                    <div>
                        <label class="block text-sm text-dark-300 mb-1.5">ประเภท</label>
                        <div class="grid grid-cols-4 gap-2">
                            <button v-for="(dt, key) in deviceTypes" :key="key"
                                @click="form.type = key"
                                :class="[
                                    'p-3 rounded-xl border text-center transition-all',
                                    form.type === key
                                        ? 'border-primary-500 bg-primary-500/10'
                                        : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                ]">
                                <span class="text-xl">{{ dt.icon }}</span>
                                <p class="text-[10px] mt-1" :class="form.type === key ? 'text-primary-400' : 'text-dark-400'">{{ dt.label }}</p>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm text-dark-300 mb-1.5">ตำแหน่ง (GPS/ที่อยู่)</label>
                        <input v-model="form.location" type="text"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                            placeholder="เช่น 14.8826,103.4945 หรือ ฟาร์มลุงสม สุรินทร์" />
                    </div>

                    <div v-if="error" class="p-3 rounded-xl bg-trading-red/10 text-trading-red text-sm">{{ error }}</div>

                    <div class="flex gap-3">
                        <button @click="showRegister = false" class="flex-1 py-3 text-dark-400 hover:text-white text-sm">ยกเลิก</button>
                        <button @click="registerDevice" :disabled="!form.name || loading"
                            class="flex-1 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm disabled:opacity-50">
                            {{ loading ? 'กำลังลงทะเบียน...' : 'ลงทะเบียน' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- API Guide -->
            <div class="glass-card rounded-2xl p-6 mb-6">
                <h3 class="text-white font-semibold mb-3">วิธีส่งข้อมูลจาก IoT Device</h3>
                <div class="bg-dark-900 rounded-xl p-4 border border-white/5">
                    <p class="text-dark-500 text-xs mb-2">HTTP POST — ส่งจาก sensor ทุก 15 นาที</p>
                    <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap">POST /api/v1/food-passport/iot/ingest
Content-Type: application/json

{
  "device_id": "TPIX-IOT-XXXXXXXX",
  "product_id": 42,
  "stage": "transport",
  "temperature": 4.5,
  "humidity": 65.2,
  "location": "13.7563,100.5018",
  "data": { "battery": 85 }
}</pre>
                </div>
                <p class="text-dark-500 text-xs mt-2">Rate limit: 120 requests/min per device | Batch: POST /iot/batch-ingest (สูงสุด 100 records)</p>
            </div>

            <!-- Device List -->
            <div v-if="devices.length" class="space-y-3">
                <div v-for="device in devices" :key="device.id"
                    class="glass-card rounded-xl p-4 flex items-center gap-4">
                    <span class="text-2xl">{{ deviceTypes[device.type]?.icon || '📡' }}</span>
                    <div class="flex-1">
                        <p class="text-white font-medium text-sm">{{ device.name }}</p>
                        <p class="text-dark-500 text-xs font-mono">{{ device.device_id }}</p>
                    </div>
                    <div class="text-right">
                        <span :class="[
                            'text-[10px] px-2 py-0.5 rounded-full',
                            device.status === 'active' ? 'bg-trading-green/10 text-trading-green' : 'bg-dark-700 text-dark-500'
                        ]">{{ device.status }}</span>
                        <p v-if="device.traces_count" class="text-dark-500 text-[10px] mt-1">{{ device.traces_count }} traces</p>
                    </div>
                </div>
            </div>
            <div v-else class="glass-card rounded-2xl p-12 text-center">
                <p class="text-4xl mb-3">📡</p>
                <p class="text-dark-400">ยังไม่มี IoT device</p>
                <button @click="showRegister = true" class="mt-3 text-primary-400 text-sm">ลงทะเบียนเลย →</button>
            </div>
        </div>
    </div>
</template>
