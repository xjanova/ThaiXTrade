<script setup>
/**
 * IoTDashboard — Plug & Play Wizard
 * เชื่อมต่อ IoT sensor ง่ายที่สุด 3 ขั้นตอน:
 * 1. เลือกประเภท sensor → 2. ตั้งชื่อ + ตำแหน่ง → 3. ได้ API Key + Config สำเร็จรูป
 * รองรับ: ESP32, Arduino, Raspberry Pi, HTTP API, MQTT
 *
 * API key ของเครื่องเห็นได้ครั้งเดียว (เซิร์ฟเวอร์เก็บแค่ hash) — ตอนลงทะเบียน หรือตอนกด "ออก API key"
 * เครื่องต้องส่ง `Authorization: Bearer <API key>` ทุกคำขอ ไม่มีกุญแจ = ส่งข้อมูลเข้าไม่ได้
 */
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    walletAddress: { type: String, default: null },
    isConnected: { type: Boolean, default: false },
});

const emit = defineEmits(['device-registered', 'devices-updated']);

// Wizard state
const wizardStep = ref(0); // 0=closed, 1=type, 2=info, 3=done
const loading = ref(false);
const error = ref(null);
const registeredDevice = ref(null);
// กุญแจที่เพิ่งออก — อยู่แค่ในหน่วยความจำของหน้านี้ ปิดแผงหรือสลับกระเป๋าแล้วหายไป
const issuedKey = ref(null); // { deviceId, apiKey, mode: 'registered' | 'issued' | 'rotated' }
const keyCopied = ref(false);
const rotatingId = ref(null);
const keyError = ref(null); // { deviceId, message }
const selectedDeviceForTest = ref(null);
const testResult = ref(null); // { ok, message }
const testLoading = ref(false);
const activeCodeTab = ref('esp32');

const deviceTypes = {
    temperature: { label: 'Temperature', icon: '🌡️', desc: 'วัดอุณหภูมิ (Cold Chain)', popular: true },
    humidity: { label: 'Humidity', icon: '💧', desc: 'วัดความชื้น', popular: false },
    gps: { label: 'GPS Tracker', icon: '📍', desc: 'ติดตามตำแหน่งสินค้า', popular: true },
    camera: { label: 'Camera', icon: '📸', desc: 'ถ่ายภาพสินค้าอัตโนมัติ', popular: false },
    weight: { label: 'Weight Scale', icon: '⚖️', desc: 'ชั่งน้ำหนักอัตโนมัติ', popular: false },
    ph: { label: 'pH Meter', icon: '🧪', desc: 'วัดค่า pH (ดิน/น้ำ)', popular: false },
    multi: { label: 'Multi-Sensor', icon: '📡', desc: 'อุณหภูมิ + ความชื้น + GPS รวม', popular: true },
};

const protocols = [
    { id: 'http', label: 'HTTP API', icon: '🌐', desc: 'ง่ายสุด — ส่ง POST request', recommended: true },
    { id: 'mqtt', label: 'MQTT', icon: '📶', desc: 'ประหยัดพลังงาน — สำหรับ sensor ขนาดเล็ก', recommended: false },
    { id: 'webhook', label: 'Webhook', icon: '🔗', desc: 'รับข้อมูลจากระบบอื่น (PLC, SCADA)', recommended: false },
];

const form = ref({
    name: '',
    type: 'multi',
    protocol: 'http',
    location: '',
    interval: '15', // minutes
});

const popularTypes = computed(() => Object.entries(deviceTypes).filter(([, v]) => v.popular));
const otherTypes = computed(() => Object.entries(deviceTypes).filter(([, v]) => !v.popular));

function startWizard() {
    wizardStep.value = 1;
    error.value = null;
    registeredDevice.value = null;
    issuedKey.value = null;
    form.value = { name: '', type: 'multi', protocol: 'http', location: '', interval: '15' };
}

function closeWizard() {
    wizardStep.value = 0;
    issuedKey.value = null;
}

// สลับ/ตัดกระเป๋า → ปิดแผงที่โชว์กุญแจของเครื่องในกระเป๋าเดิม (เช่น เครื่องคอมใช้ร่วมกัน)
watch(() => props.walletAddress, () => {
    closeWizard();
    keyError.value = null;
    testResult.value = null;
});

/** ข้อความภาษาไทยจากคำตอบที่ไม่สำเร็จ — ไม่โชว์ข้อความดิบของ axios ("Request failed with status code 419") */
function errorMessage(e, fallback) {
    const code = e.response?.data?.error?.code;
    if (code === 'WALLET_NOT_VERIFIED' || code === 'WALLET_IP_MISMATCH') {
        return 'ยืนยันกระเป๋าก่อน (เซ็นข้อความในกระเป๋า) แล้วลองใหม่';
    }
    if (e.response?.status === 419) return 'หน้าเว็บหมดอายุ — รีเฟรชหน้าแล้วลองใหม่';
    return e.response?.data?.error?.message || fallback;
}

/*
 * ส่งด้วย axios — แนบ X-XSRF-TOKEN จากคุกกี้ให้เอง (เว็บได้ session แบบ Sanctum จึงโดนตรวจ CSRF)
 * ⚠️ เดิมใช้ fetch ที่ไม่แนบ CSRF — บน production ที่ session ติด คำขอนี้ได้ 419 ตลอด
 */
async function registerDevice() {
    if (loading.value) return;
    loading.value = true;
    error.value = null;
    const wallet = props.walletAddress;

    try {
        const { data: json } = await axios.post('/api/v1/food-passport/iot/register-device', {
            // ด่าน VerifyWalletOwnership ตรวจ wallet_address — เครื่องจะเป็นของกระเป๋าที่ยืนยันแล้วนี้
            wallet_address: wallet,
            name: form.value.name,
            type: form.value.type,
            location: form.value.location || null,
            config: {
                protocol: form.value.protocol,
                interval_minutes: parseInt(form.value.interval),
            },
        });
        if (props.walletAddress !== wallet) return; // สลับกระเป๋าระหว่างรอ — แผงถูกปิดไปแล้ว

        // กุญแจไม่ไปกับรายการอุปกรณ์ — เก็บไว้โชว์ในแผงนี้ครั้งเดียว
        const { api_key: apiKey, ...device } = json.data;
        registeredDevice.value = device;
        issuedKey.value = { deviceId: device.device_id, apiKey, mode: 'registered' };
        activeCodeTab.value = 'esp32';
        keyCopied.value = false;
        wizardStep.value = 3;
        emit('device-registered', device);
    } catch (e) {
        error.value = errorMessage(e, 'ลงทะเบียนไม่สำเร็จ');
    } finally {
        loading.value = false;
    }
}

/**
 * ออก API key ให้เครื่อง (เครื่องที่ลงทะเบียนก่อนมีระบบกุญแจ) หรือออกใหม่แทนดอกที่หาย/หลุด.
 * มีกุญแจอยู่แล้ว = ทับของเดิม เครื่องที่ใช้กุญแจเดิมจะส่งไม่ได้ทันที — ต้องยืนยันก่อน
 */
async function rotateKey(device) {
    if (rotatingId.value) return;
    if (device.has_api_key && !confirm(
        `ออก API key ใหม่ให้ ${device.device_id}?\n\nกุญแจเดิมจะใช้ไม่ได้ทันที — อุปกรณ์จะส่งข้อมูลไม่ได้จนกว่าจะใส่กุญแจใหม่`
    )) return;

    rotatingId.value = device.device_id;
    keyError.value = null;
    const wallet = props.walletAddress;

    try {
        const { data: json } = await axios.post(
            `/api/v1/food-passport/iot/rotate-key/${encodeURIComponent(device.device_id)}`,
            { wallet_address: wallet },
        );
        if (props.walletAddress !== wallet) return;

        registeredDevice.value = { ...device, has_api_key: true };
        issuedKey.value = {
            deviceId: device.device_id,
            apiKey: json.data.api_key,
            mode: device.has_api_key ? 'rotated' : 'issued',
        };
        keyCopied.value = false;
        activeCodeTab.value = 'esp32';
        wizardStep.value = 3;
        emit('devices-updated', props.devices.map((d) => (
            d.device_id === device.device_id ? { ...d, has_api_key: true } : d
        )));
    } catch (e) {
        keyError.value = { deviceId: device.device_id, message: errorMessage(e, 'ออก API key ไม่สำเร็จ') };
    } finally {
        rotatingId.value = null;
    }
}

function copyKey() {
    if (!issuedKey.value) return;
    navigator.clipboard?.writeText(issuedKey.value.apiKey);
    keyCopied.value = true;
}

/*
 * ตรวจสถานะ = ถามเซิร์ฟเวอร์ว่าได้ยินจากเครื่องล่าสุดเมื่อไร
 *
 * ⚠️ เดิมปุ่มนี้ยิงข้อมูลปลอม (product_id 0) เข้า /iot/ingest จากเบราว์เซอร์ ซึ่งไม่มีวันสำเร็จ
 *    และตอนเน็ตหลุดกลับขึ้นว่า "Connection OK" — เบราว์เซอร์ไม่มีกุญแจของเครื่อง (และไม่ควรมี)
 */
async function checkStatus(device) {
    if (testLoading.value) return;
    selectedDeviceForTest.value = device.device_id;
    testLoading.value = true;
    testResult.value = null;
    const wallet = props.walletAddress;

    try {
        const res = await fetch(`/api/v1/food-passport/iot/my-devices?wallet_address=${wallet}`);
        const json = await res.json();
        if (props.walletAddress !== wallet) return;

        const list = json.success ? json.data.data || [] : null;
        const fresh = list?.find((d) => d.device_id === device.device_id);
        if (!fresh) {
            testResult.value = { ok: false, message: res.status === 403
                ? 'ยืนยันกระเป๋าก่อน (เซ็นข้อความในกระเป๋า) แล้วลองใหม่'
                : 'ตรวจสถานะไม่สำเร็จ — ลองใหม่อีกครั้ง' };
            return;
        }

        emit('devices-updated', list);
        testResult.value = describeLastPing(fresh);
    } catch {
        testResult.value = { ok: false, message: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ — ลองใหม่อีกครั้ง' };
    } finally {
        testLoading.value = false;
    }
}

function describeLastPing(device) {
    if (!device.has_api_key) {
        return { ok: false, message: 'ยังไม่มี API key — กด "ออก API key" แล้วใส่กุญแจในอุปกรณ์' };
    }
    if (!device.last_ping_at) {
        return { ok: false, message: 'ยังไม่เคยได้รับข้อมูลจากอุปกรณ์นี้ — ตรวจว่าใส่ Device ID และ API key ถูกต้อง' };
    }
    const minutes = Math.max(0, Math.round((Date.now() - new Date(device.last_ping_at).getTime()) / 60000));
    const interval = device.config?.interval_minutes || 15;
    return minutes <= interval * 2
        ? { ok: true, message: `อุปกรณ์ส่งข้อมูลล่าสุดเมื่อ ${minutes} นาทีที่แล้ว` }
        : { ok: false, message: `ไม่ได้รับข้อมูลมา ${minutes} นาทีแล้ว (ตั้งไว้ส่งทุก ${interval} นาที)` };
}

// Generate config code for different platforms — ใส่ API key จริงได้เฉพาะตอนเพิ่งออกกุญแจ
function getConfigCode(device, apiKey, platform) {
    const baseUrl = window.location.origin;
    const deviceId = device.device_id;
    const interval = parseInt(device.config?.interval_minutes) || 15;
    const location = device.location || '0,0';
    const key = apiKey || '<<YOUR_API_KEY>>';
    const configs = {
        esp32: `// ESP32 — FoodPassport IoT Sensor
// ติดตั้ง: Arduino IDE → Library Manager → ArduinoJson + HTTPClient

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

const char* WIFI_SSID     = "YOUR_WIFI";
const char* WIFI_PASSWORD  = "YOUR_PASSWORD";
const char* API_URL        = "${baseUrl}/api/v1/food-passport/iot/ingest";
const char* DEVICE_ID      = "${deviceId}";
const char* API_KEY        = "${key}";  // ← กุญแจของเครื่องนี้ — เก็บเป็นความลับ
const int   PRODUCT_ID     = 1;        // ← เปลี่ยนเป็น Product ID ของคุณ
const char* STAGE          = "farm";   // farm / processing / storage / transport / retail
const int   INTERVAL_MS    = ${interval * 60000};

void setup() {
  Serial.begin(115200);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED) { delay(500); Serial.print("."); }
  Serial.println("\\nWiFi Connected!");
}

void loop() {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(API_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Authorization", String("Bearer ") + API_KEY);

    // อ่านค่า sensor (ตัวอย่าง — เปลี่ยนเป็น sensor จริงของคุณ)
    float temperature = analogRead(34) * 0.1;  // ← เปลี่ยนเป็น pin จริง
    float humidity    = analogRead(35) * 0.1;

    JsonDocument doc;
    doc["device_id"]   = DEVICE_ID;
    doc["product_id"]  = PRODUCT_ID;
    doc["stage"]       = STAGE;
    doc["temperature"] = temperature;
    doc["humidity"]    = humidity;
    doc["location"]    = "${location}";

    String json;
    serializeJson(doc, json);

    int code = http.POST(json);
    Serial.printf("Sent! HTTP %d\\n", code);
    http.end();
  }
  delay(INTERVAL_MS);
}`,
        raspberry: `#!/usr/bin/env python3
# Raspberry Pi — FoodPassport IoT Sensor
# ติดตั้ง: pip install requests

import requests
import time
import json

API_URL    = "${baseUrl}/api/v1/food-passport/iot/ingest"
DEVICE_ID  = "${deviceId}"
API_KEY    = "${key}"  # ← กุญแจของเครื่องนี้ — เก็บเป็นความลับ
PRODUCT_ID = 1        # ← เปลี่ยนเป็น Product ID ของคุณ
STAGE      = "farm"   # farm / processing / storage / transport / retail
INTERVAL   = ${interval * 60}  # วินาที

def read_sensor():
    """อ่านค่า sensor — เปลี่ยนเป็น sensor จริงของคุณ"""
    # ตัวอย่าง: DHT22
    # import Adafruit_DHT
    # humidity, temperature = Adafruit_DHT.read_retry(Adafruit_DHT.DHT22, 4)
    return {"temperature": 25.0, "humidity": 60.0}

while True:
    try:
        data = read_sensor()
        payload = {
            "device_id":   DEVICE_ID,
            "product_id":  PRODUCT_ID,
            "stage":       STAGE,
            "temperature": data["temperature"],
            "humidity":    data["humidity"],
            "location":   "${location}",
        }
        r = requests.post(API_URL, json=payload, headers={"Authorization": "Bearer " + API_KEY}, timeout=10)
        print(f"Sent! Status: {r.status_code}")
    except Exception as e:
        print(f"Error: {e}")

    time.sleep(INTERVAL)`,
        curl: `# ทดสอบด้วย cURL — คัดลอกแล้วรันใน Terminal ได้เลย

# 1) เช็คว่า API key ใช้ได้ (ยังไม่บันทึกข้อมูล)
curl ${baseUrl}/api/v1/food-passport/iot/test/${deviceId} \\
  -H "Authorization: Bearer ${key}"

# 2) ส่งข้อมูลจริง
curl -X POST ${baseUrl}/api/v1/food-passport/iot/ingest \\
  -H "Content-Type: application/json" \\
  -H "Authorization: Bearer ${key}" \\
  -d '{
    "device_id": "${deviceId}",
    "product_id": 1,
    "stage": "farm",
    "temperature": 25.5,
    "humidity": 65.0,
    "location": "${device.location || '13.7563,100.5018'}",
    "data": { "battery": 100, "signal": "good" }
  }'`,
        node: `// Node.js / JavaScript — FoodPassport IoT
// ติดตั้ง: npm install node-fetch (หรือใช้ fetch ใน Node 18+)

const API_URL    = "${baseUrl}/api/v1/food-passport/iot/ingest";
const DEVICE_ID  = "${deviceId}";
const API_KEY    = "${key}"; // ← กุญแจของเครื่องนี้ — เก็บเป็นความลับ
const PRODUCT_ID = 1;        // ← เปลี่ยนเป็น Product ID
const STAGE      = "farm";
const INTERVAL   = ${interval * 60} * 1000; // ms

async function sendData() {
  const payload = {
    device_id:   DEVICE_ID,
    product_id:  PRODUCT_ID,
    stage:       STAGE,
    temperature: 25.0,  // ← ค่าจาก sensor จริง
    humidity:    60.0,
    location:    "${location}",
  };

  try {
    const res = await fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Authorization": "Bearer " + API_KEY },
      body: JSON.stringify(payload),
    });
    // IoT data sent
  } catch (e) {
    console.error("Error:", e.message);
  }
}

// ส่งทุก ${interval} นาที
setInterval(sendData, INTERVAL);
sendData(); // ส่งครั้งแรกทันที`,
    };
    return configs[platform] || configs.curl;
}

function copyCode(text) {
    navigator.clipboard?.writeText(text);
}
</script>

<template>
    <div>
        <!-- Not Connected -->
        <div v-if="!isConnected" class="glass-card rounded-2xl p-12 text-center">
            <p class="text-4xl mb-3">🔒</p>
            <p class="text-white font-medium">กรุณาเชื่อมต่อ Wallet</p>
            <p class="text-dark-400 text-sm mt-1">เชื่อมต่อ wallet เพื่อจัดการ IoT devices</p>
        </div>

        <div v-else>
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-xl font-bold text-white">IoT Devices</h2>
                    <p class="text-dark-400 text-sm">เชื่อมต่อ sensor ง่ายๆ — Plug & Play</p>
                </div>
                <button @click="startWizard" class="btn-primary text-sm px-5 py-2.5 rounded-xl flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    เพิ่ม Sensor ใหม่
                </button>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!--  WIZARD: 3 ขั้นตอน                         -->
            <!-- ═══════════════════════════════════════════ -->
            <div v-if="wizardStep > 0" class="glass-card rounded-2xl overflow-hidden mb-6">
                <!-- Progress Bar -->
                <div class="flex border-b border-white/5">
                    <div v-for="s in [
                        { n: 1, label: 'เลือกประเภท' },
                        { n: 2, label: 'ตั้งค่า' },
                        { n: 3, label: 'เชื่อมต่อ' },
                    ]" :key="s.n"
                    :class="['flex-1 py-3 text-center text-sm font-medium border-b-2 transition-all',
                        wizardStep === s.n ? 'text-primary-400 border-primary-500 bg-primary-500/5' :
                        wizardStep > s.n ? 'text-trading-green border-trading-green/50' :
                        'text-dark-600 border-transparent'
                    ]">
                        <span v-if="wizardStep > s.n" class="text-trading-green mr-1">✓</span>
                        {{ s.n }}. {{ s.label }}
                    </div>
                </div>

                <div class="p-6">
                    <!-- Step 1: เลือกประเภท Sensor -->
                    <template v-if="wizardStep === 1">
                        <h3 class="text-white font-semibold mb-1">คุณต้องการเชื่อมต่อ Sensor แบบไหน?</h3>
                        <p class="text-dark-500 text-xs mb-5">เลือกประเภทที่ตรงกับอุปกรณ์ของคุณ — เปลี่ยนทีหลังได้</p>

                        <!-- Popular -->
                        <p class="text-dark-400 text-xs font-medium mb-2">แนะนำ</p>
                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <button v-for="[key, dt] in popularTypes" :key="key"
                                @click="form.type = key"
                                :class="['p-4 rounded-xl border text-center transition-all',
                                    form.type === key ? 'border-primary-500 bg-primary-500/10 shadow-lg shadow-primary-500/10' : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                ]">
                                <span class="text-3xl">{{ dt.icon }}</span>
                                <p class="text-sm font-medium mt-2" :class="form.type === key ? 'text-primary-400' : 'text-white'">{{ dt.label }}</p>
                                <p class="text-[10px] text-dark-500 mt-0.5">{{ dt.desc }}</p>
                            </button>
                        </div>

                        <!-- Others -->
                        <p class="text-dark-400 text-xs font-medium mb-2">อื่นๆ</p>
                        <div class="grid grid-cols-4 gap-2 mb-6">
                            <button v-for="[key, dt] in otherTypes" :key="key"
                                @click="form.type = key"
                                :class="['p-3 rounded-xl border text-center transition-all',
                                    form.type === key ? 'border-primary-500 bg-primary-500/10' : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                ]">
                                <span class="text-xl">{{ dt.icon }}</span>
                                <p class="text-[10px] mt-1" :class="form.type === key ? 'text-primary-400' : 'text-dark-400'">{{ dt.label }}</p>
                            </button>
                        </div>

                        <div class="flex justify-between">
                            <button @click="closeWizard" class="text-dark-400 hover:text-white text-sm">ยกเลิก</button>
                            <button @click="wizardStep = 2" class="px-6 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl text-sm font-medium">
                                ถัดไป →
                            </button>
                        </div>
                    </template>

                    <!-- Step 2: ตั้งค่า -->
                    <template v-if="wizardStep === 2">
                        <h3 class="text-white font-semibold mb-1">ตั้งค่าอุปกรณ์ของคุณ</h3>
                        <p class="text-dark-500 text-xs mb-5">ตั้งชื่อ เลือกโปรโตคอล กำหนดตำแหน่ง</p>

                        <div class="space-y-4">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">ชื่ออุปกรณ์ <span class="text-trading-red">*</span></label>
                                <input v-model="form.name" type="text"
                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                                    placeholder="เช่น Sensor ฟาร์มข้าว สุรินทร์" />
                            </div>

                            <!-- Protocol -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">วิธีส่งข้อมูล</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button v-for="p in protocols" :key="p.id"
                                        @click="form.protocol = p.id"
                                        :class="['p-3 rounded-xl border text-center transition-all relative',
                                            form.protocol === p.id ? 'border-primary-500 bg-primary-500/10' : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                        ]">
                                        <span v-if="p.recommended" class="absolute -top-1.5 -right-1.5 text-[8px] bg-trading-green text-white px-1.5 py-0.5 rounded-full">แนะนำ</span>
                                        <span class="text-xl">{{ p.icon }}</span>
                                        <p class="text-xs font-medium mt-1" :class="form.protocol === p.id ? 'text-primary-400' : 'text-white'">{{ p.label }}</p>
                                        <p class="text-[10px] text-dark-500">{{ p.desc }}</p>
                                    </button>
                                </div>
                            </div>

                            <!-- Location -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">ตำแหน่ง</label>
                                <input v-model="form.location" type="text"
                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                                    placeholder="เช่น 14.8826,103.4945 หรือ ฟาร์มลุงสม สุรินทร์" />
                                <p class="text-dark-600 text-[10px] mt-1">GPS coordinates หรือชื่อสถานที่ — ไม่บังคับ</p>
                            </div>

                            <!-- Interval -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">ส่งข้อมูลทุกๆ</label>
                                <div class="flex gap-2">
                                    <button v-for="min in ['1', '5', '15', '30', '60']" :key="min"
                                        @click="form.interval = min"
                                        :class="['px-4 py-2 rounded-lg text-sm transition-all',
                                            form.interval === min ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white'
                                        ]">
                                        {{ min }} นาที
                                    </button>
                                </div>
                            </div>

                            <div v-if="error" class="p-3 rounded-xl bg-trading-red/10 text-trading-red text-sm">{{ error }}</div>
                        </div>

                        <div class="flex justify-between mt-6">
                            <button @click="wizardStep = 1" class="text-dark-400 hover:text-white text-sm">← ย้อนกลับ</button>
                            <button @click="registerDevice" :disabled="!form.name || loading"
                                class="px-6 py-2.5 bg-gradient-to-r from-green-500 to-cyan-500 hover:from-green-600 hover:to-cyan-600 text-white rounded-xl text-sm font-medium disabled:opacity-50">
                                {{ loading ? 'กำลังสร้าง...' : 'สร้าง Device →' }}
                            </button>
                        </div>
                    </template>

                    <!-- Step 3: เชื่อมต่อสำเร็จ + Config -->
                    <template v-if="wizardStep === 3 && registeredDevice">
                        <div class="text-center mb-6">
                            <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-green-500/20 to-cyan-500/20 flex items-center justify-center mb-3">
                                <span class="text-4xl">{{ issuedKey?.mode === 'registered' ? '✅' : '🔑' }}</span>
                            </div>
                            <h3 class="text-xl font-bold text-white">
                                {{ issuedKey?.mode === 'rotated' ? 'ออก API key ใหม่แล้ว' : issuedKey?.mode === 'issued' ? 'ออก API key แล้ว' : 'ลงทะเบียนสำเร็จ!' }}
                            </h3>
                            <p class="text-dark-400 text-sm mt-1">คัดลอก API key และ Config ด้านล่างไปใส่ในอุปกรณ์ของคุณ</p>
                        </div>

                        <!-- API key — เห็นได้ครั้งนี้ครั้งเดียว (เซิร์ฟเวอร์เก็บแค่ hash) -->
                        <div v-if="issuedKey" class="p-4 rounded-xl bg-amber-500/5 border border-amber-500/20 mb-5" data-test="iot-api-key">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <p class="text-amber-300 text-sm font-medium">🔑 API key ของ {{ issuedKey.deviceId }}</p>
                                <button @click="copyKey"
                                    class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-dark-300 hover:text-white text-xs transition-all whitespace-nowrap">
                                    {{ keyCopied ? '✓ คัดลอกแล้ว' : '📋 คัดลอก' }}
                                </button>
                            </div>
                            <p class="text-white font-mono text-xs break-all bg-dark-900 rounded-lg px-3 py-2">{{ issuedKey.apiKey }}</p>
                            <p class="text-dark-400 text-xs mt-2">
                                แสดงครั้งเดียว — ระบบไม่ได้เก็บกุญแจตัวจริงไว้ ถ้าหายหรือหลุดให้กด "ออก API key ใหม่" ที่รายการอุปกรณ์
                                <span v-if="issuedKey.mode === 'rotated'" class="text-amber-400">· กุญแจเดิมใช้ไม่ได้แล้ว</span>
                            </p>
                        </div>

                        <!-- Device Info -->
                        <div class="p-4 rounded-xl bg-dark-800/50 border border-white/5 mb-5">
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <span class="text-dark-500">Device ID:</span>
                                    <p class="text-white font-mono font-medium">{{ registeredDevice.device_id }}</p>
                                </div>
                                <div>
                                    <span class="text-dark-500">ประเภท:</span>
                                    <p class="text-white">{{ deviceTypes[registeredDevice.type]?.icon }} {{ deviceTypes[registeredDevice.type]?.label }}</p>
                                </div>
                                <div>
                                    <span class="text-dark-500">โปรโตคอล:</span>
                                    <p class="text-primary-400">{{ (registeredDevice.config?.protocol || 'http').toUpperCase() }}</p>
                                </div>
                                <div>
                                    <span class="text-dark-500">ส่งข้อมูลทุก:</span>
                                    <p class="text-white">{{ registeredDevice.config?.interval_minutes || 15 }} นาที</p>
                                </div>
                            </div>
                        </div>

                        <!-- Code Tabs -->
                        <div class="mb-2">
                            <p class="text-dark-300 text-sm font-medium mb-2">คัดลอก Config สำหรับอุปกรณ์ของคุณ:</p>
                            <div class="flex gap-1 overflow-x-auto">
                                <button v-for="tab in [
                                    { id: 'esp32', label: 'ESP32 / Arduino', icon: '🔌' },
                                    { id: 'raspberry', label: 'Raspberry Pi', icon: '🍓' },
                                    { id: 'node', label: 'Node.js', icon: '🟢' },
                                    { id: 'curl', label: 'cURL (ทดสอบ)', icon: '⚡' },
                                ]" :key="tab.id"
                                @click="activeCodeTab = tab.id"
                                :class="['px-3 py-1.5 rounded-lg text-xs whitespace-nowrap transition-all',
                                    activeCodeTab === tab.id ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white'
                                ]">
                                    {{ tab.icon }} {{ tab.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Code Block -->
                        <div class="relative bg-dark-900 rounded-xl border border-white/5 mb-5">
                            <button @click="copyCode(getConfigCode(registeredDevice, issuedKey?.apiKey, activeCodeTab))"
                                class="absolute top-2 right-2 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-dark-400 hover:text-white text-xs transition-all">
                                📋 Copy
                            </button>
                            <pre class="p-4 text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto">{{ getConfigCode(registeredDevice, issuedKey?.apiKey, activeCodeTab) }}</pre>
                        </div>

                        <!-- Quick Steps -->
                        <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/10 mb-5">
                            <p class="text-cyan-300 text-sm font-medium mb-2">ขั้นตอนต่อไป (3 ขั้นตอน):</p>
                            <div class="space-y-1.5 text-xs text-dark-300">
                                <p>1. คัดลอก code ด้านบน (มี API key ใส่ไว้แล้ว) → ใส่ในอุปกรณ์ของคุณ</p>
                                <p>2. เปลี่ยน <code class="text-cyan-400 bg-dark-800 px-1 rounded">PRODUCT_ID</code> เป็น ID ของสินค้าที่ลงทะเบียนไว้</p>
                                <p>3. รัน — ข้อมูลจะถูกส่งเข้าระบบ FoodPassport อัตโนมัติ (ไม่มี API key = ระบบไม่รับข้อมูล)</p>
                            </div>
                        </div>

                        <!-- FDP Reward Info -->
                        <div class="p-4 rounded-xl bg-amber-500/5 border border-amber-500/10 mb-5">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-lg">🪙</span>
                                <p class="text-amber-300 text-sm font-medium">ได้รับ FDP Token ทุกครั้งที่ส่งข้อมูล!</p>
                            </div>
                            <p class="text-dark-400 text-xs">IoT sensor ของคุณจะได้รับ <strong class="text-amber-400">1 FDP</strong> ต่อทุก trace ที่ส่งสำเร็จ</p>
                        </div>

                        <button @click="closeWizard" class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm">
                            เสร็จสิ้น
                        </button>
                    </template>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!--  DEVICE LIST                               -->
            <!-- ═══════════════════════════════════════════ -->
            <div v-if="devices.length" class="space-y-3">
                <h3 class="text-white font-semibold text-sm">อุปกรณ์ของฉัน ({{ devices.length }})</h3>
                <div v-for="device in devices" :key="device.id"
                    class="glass-card rounded-xl p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl"
                            :class="device.status === 'active' ? 'bg-trading-green/10' : 'bg-dark-800'">
                            {{ deviceTypes[device.type]?.icon || '📡' }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white font-medium text-sm">{{ device.name }}</p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-dark-500 text-xs font-mono">{{ device.device_id }}</span>
                                <span :class="[
                                    'text-[10px] px-1.5 py-0.5 rounded-full',
                                    device.status === 'active' ? 'bg-trading-green/10 text-trading-green' :
                                    device.status === 'maintenance' ? 'bg-amber-500/10 text-amber-400' :
                                    'bg-dark-700 text-dark-500'
                                ]">{{ device.status }}</span>
                                <!-- ลงทะเบียนก่อนมีระบบกุญแจ — ส่งข้อมูลไม่ได้จนกว่าจะออกกุญแจ -->
                                <span v-if="!device.has_api_key"
                                    class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400">ยังไม่มี API key</span>
                            </div>
                            <p v-if="device.last_ping_at" class="text-dark-600 text-[10px] mt-0.5">
                                Last ping: {{ new Date(device.last_ping_at).toLocaleString('th-TH') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="device.traces_count" class="text-dark-500 text-xs">{{ device.traces_count }} traces</span>
                            <button @click="rotateKey(device)"
                                :disabled="rotatingId === device.device_id"
                                :class="['px-3 py-1.5 rounded-lg text-xs transition-all disabled:opacity-50',
                                    device.has_api_key ? 'bg-white/5 text-dark-300 hover:bg-white/10' : 'bg-amber-500/10 text-amber-400 hover:bg-amber-500/20']">
                                {{ rotatingId === device.device_id ? '...' : device.has_api_key ? '🔑 ออกใหม่' : '🔑 ออก API key' }}
                            </button>
                            <button @click="checkStatus(device)"
                                :disabled="testLoading && selectedDeviceForTest === device.device_id"
                                class="px-3 py-1.5 rounded-lg bg-cyan-500/10 text-cyan-400 text-xs hover:bg-cyan-500/20 transition-all disabled:opacity-50">
                                {{ testLoading && selectedDeviceForTest === device.device_id ? '...' : '🔄 ตรวจสถานะ' }}
                            </button>
                        </div>
                    </div>
                    <!-- ออกกุญแจไม่สำเร็จ -->
                    <div v-if="keyError && keyError.deviceId === device.device_id"
                        class="mt-3 pt-3 border-t border-white/5 flex items-center gap-2 text-trading-red text-sm">
                        <span>⚠️</span> {{ keyError.message }}
                    </div>
                    <!-- ผลตรวจสถานะ: เซิร์ฟเวอร์ได้ยินจากเครื่องล่าสุดเมื่อไร -->
                    <div v-if="selectedDeviceForTest === device.device_id && testResult" class="mt-3 pt-3 border-t border-white/5">
                        <div :class="['flex items-center gap-2 text-sm', testResult.ok ? 'text-trading-green' : 'text-amber-400']">
                            <span>{{ testResult.ok ? '✅' : '⚠️' }}</span> {{ testResult.message }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="wizardStep === 0" class="glass-card rounded-2xl p-12 text-center">
                <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-cyan-500/10 to-blue-500/10 flex items-center justify-center mb-4">
                    <span class="text-5xl">📡</span>
                </div>
                <h3 class="text-white font-semibold mb-1">ยังไม่มี IoT Device</h3>
                <p class="text-dark-400 text-sm mb-4">เชื่อมต่อ sensor ง่ายๆ ใน 3 ขั้นตอน — รองรับ ESP32, Raspberry Pi, Arduino</p>
                <button @click="startWizard"
                    class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-blue-500 text-white rounded-xl font-medium text-sm hover:from-cyan-600 hover:to-blue-600 shadow-lg shadow-cyan-500/25">
                    เพิ่ม Sensor ตัวแรก
                </button>
            </div>
        </div>
    </div>
</template>
