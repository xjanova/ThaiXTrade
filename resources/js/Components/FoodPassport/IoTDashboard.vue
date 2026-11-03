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
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

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
const keyError = ref(null); // { deviceId, error: { key } | { text } }
const selectedDeviceForTest = ref(null);
const testResult = ref(null); // { ok, message }
const testLoading = ref(false);
const activeCodeTab = ref('esp32');

const deviceTypes = {
    // ป้าย/คำอธิบายเก็บเป็นคีย์ — แปลตอน render เพื่อให้สลับภาษาได้ทันที
    temperature: { labelKey: 'ui.foodPassportIoTDashboard.types.temperature.label', icon: '🌡️', descKey: 'ui.foodPassportIoTDashboard.types.temperature.desc', popular: true },
    humidity: { labelKey: 'ui.foodPassportIoTDashboard.types.humidity.label', icon: '💧', descKey: 'ui.foodPassportIoTDashboard.types.humidity.desc', popular: false },
    gps: { labelKey: 'ui.foodPassportIoTDashboard.types.gps.label', icon: '📍', descKey: 'ui.foodPassportIoTDashboard.types.gps.desc', popular: true },
    camera: { labelKey: 'ui.foodPassportIoTDashboard.types.camera.label', icon: '📸', descKey: 'ui.foodPassportIoTDashboard.types.camera.desc', popular: false },
    weight: { labelKey: 'ui.foodPassportIoTDashboard.types.weight.label', icon: '⚖️', descKey: 'ui.foodPassportIoTDashboard.types.weight.desc', popular: false },
    ph: { labelKey: 'ui.foodPassportIoTDashboard.types.ph.label', icon: '🧪', descKey: 'ui.foodPassportIoTDashboard.types.ph.desc', popular: false },
    multi: { labelKey: 'ui.foodPassportIoTDashboard.types.multi.label', icon: '📡', descKey: 'ui.foodPassportIoTDashboard.types.multi.desc', popular: true },
};

const protocols = [
    { id: 'http', label: 'HTTP API', icon: '🌐', descKey: 'ui.foodPassportIoTDashboard.protocols.http', recommended: true },
    { id: 'mqtt', label: 'MQTT', icon: '📶', descKey: 'ui.foodPassportIoTDashboard.protocols.mqtt', recommended: false },
    { id: 'webhook', label: 'Webhook', icon: '🔗', descKey: 'ui.foodPassportIoTDashboard.protocols.webhook', recommended: false },
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

/**
 * ข้อผิดพลาดจากคำตอบที่ไม่สำเร็จ — ไม่โชว์ข้อความดิบของ axios ("Request failed with status code 419")
 * คืน { key } (ข้อความของเรา แปลตอน render ให้สลับภาษาตาม) หรือ { text } (ข้อความจากเซิร์ฟเวอร์)
 */
function errorMessage(e, fallbackKey) {
    const code = e.response?.data?.error?.code;
    if (code === 'WALLET_NOT_VERIFIED' || code === 'WALLET_IP_MISMATCH') {
        return { key: 'ui.foodPassportIoTDashboard.errors.walletNotVerified' };
    }
    if (e.response?.status === 419) return { key: 'ui.foodPassportIoTDashboard.errors.sessionExpired' };
    const text = e.response?.data?.error?.message;
    return text ? { text } : { key: fallbackKey };
}

function errorText(err) {
    return err?.key ? t(err.key) : err?.text;
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
        error.value = errorMessage(e, 'ui.foodPassportIoTDashboard.errors.registerFailed');
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
        t('ui.foodPassportIoTDashboard.confirmRotate', { id: device.device_id })
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
        keyError.value = { deviceId: device.device_id, error: errorMessage(e, 'ui.foodPassportIoTDashboard.errors.issueKeyFailed') };
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
            // เก็บคีย์ไว้ ไม่ใช่ข้อความ — ผลค้างบนจอ สลับภาษาแล้วต้องเปลี่ยนตาม
            testResult.value = { ok: false, key: res.status === 403
                ? 'ui.foodPassportIoTDashboard.errors.walletNotVerified'
                : 'ui.foodPassportIoTDashboard.errors.statusCheckFailed' };
            return;
        }

        emit('devices-updated', list);
        testResult.value = describeLastPing(fresh);
    } catch {
        testResult.value = { ok: false, key: 'ui.foodPassportIoTDashboard.errors.serverUnreachable' };
    } finally {
        testLoading.value = false;
    }
}

function describeLastPing(device) {
    if (!device.has_api_key) {
        return { ok: false, key: 'ui.foodPassportIoTDashboard.ping.noKey' };
    }
    if (!device.last_ping_at) {
        return { ok: false, key: 'ui.foodPassportIoTDashboard.ping.neverReceived' };
    }
    const minutes = Math.max(0, Math.round((Date.now() - new Date(device.last_ping_at).getTime()) / 60000));
    const interval = device.config?.interval_minutes || 15;
    return minutes <= interval * 2
        ? { ok: true, key: 'ui.foodPassportIoTDashboard.ping.recent', params: { minutes } }
        : { ok: false, key: 'ui.foodPassportIoTDashboard.ping.stale', params: { minutes, interval } };
}

// Generate config code for different platforms — ใส่ API key จริงได้เฉพาะตอนเพิ่งออกกุญแจ
function getConfigCode(device, apiKey, platform) {
    const baseUrl = window.location.origin;
    const deviceId = device.device_id;
    const interval = parseInt(device.config?.interval_minutes) || 15;
    const location = device.location || '0,0';
    const key = apiKey || '<<YOUR_API_KEY>>';
    const c = (k, params) => t(`ui.foodPassportIoTDashboard.code.${k}`, params);
    const configs = {
        esp32: `// ESP32 — FoodPassport IoT Sensor
// ${c('installArduino')}

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

const char* WIFI_SSID     = "YOUR_WIFI";
const char* WIFI_PASSWORD  = "YOUR_PASSWORD";
const char* API_URL        = "${baseUrl}/api/v1/food-passport/iot/ingest";
const char* DEVICE_ID      = "${deviceId}";
const char* API_KEY        = "${key}";  // ← ${c('keySecret')}
const int   PRODUCT_ID     = 1;        // ← ${c('changeProductId')}
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

    // ${c('readSensorExample')}
    float temperature = analogRead(34) * 0.1;  // ← ${c('changePin')}
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
# ${c('installPip')}

import requests
import time
import json

API_URL    = "${baseUrl}/api/v1/food-passport/iot/ingest"
DEVICE_ID  = "${deviceId}"
API_KEY    = "${key}"  # ← ${c('keySecret')}
PRODUCT_ID = 1        # ← ${c('changeProductId')}
STAGE      = "farm"   # farm / processing / storage / transport / retail
INTERVAL   = ${interval * 60}  # ${c('seconds')}

def read_sensor():
    """${c('readSensorDoc')}"""
    # ${c('exampleDht')}
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
        curl: `# ${c('curlTitle')}

# 1) ${c('curlCheck')}
curl ${baseUrl}/api/v1/food-passport/iot/test/${deviceId} \\
  -H "Authorization: Bearer ${key}"

# 2) ${c('curlSend')}
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
// ${c('installNode')}

const API_URL    = "${baseUrl}/api/v1/food-passport/iot/ingest";
const DEVICE_ID  = "${deviceId}";
const API_KEY    = "${key}"; // ← ${c('keySecret')}
const PRODUCT_ID = 1;        // ← ${c('changeProductIdShort')}
const STAGE      = "farm";
const INTERVAL   = ${interval * 60} * 1000; // ms

async function sendData() {
  const payload = {
    device_id:   DEVICE_ID,
    product_id:  PRODUCT_ID,
    stage:       STAGE,
    temperature: 25.0,  // ← ${c('sensorValue')}
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

// ${c('sendEvery', { n: interval })}
setInterval(sendData, INTERVAL);
sendData(); // ${c('sendFirst')}`,
    };
    return configs[platform] || configs.curl;
}

/** สถานะจากเซิร์ฟเวอร์ → ป้ายตามภาษา (ค่าที่ไม่รู้จักโชว์ตามจริง) */
function statusLabel(status) {
    const known = ['active', 'inactive', 'maintenance'];
    return known.includes(status) ? t(`ui.foodPassportIoTDashboard.status.${status}`) : status;
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
            <p class="text-white font-medium">{{ t('ui.foodPassportIoTDashboard.notConnected.title') }}</p>
            <p class="text-dark-400 text-sm mt-1">{{ t('ui.foodPassportIoTDashboard.notConnected.desc') }}</p>
        </div>

        <div v-else>
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-xl font-bold text-white">{{ t('ui.foodPassportIoTDashboard.header.title') }}</h2>
                    <p class="text-dark-400 text-sm">{{ t('ui.foodPassportIoTDashboard.header.subtitle') }}</p>
                </div>
                <button @click="startWizard" class="btn-primary text-sm px-5 py-2.5 rounded-xl flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ t('ui.foodPassportIoTDashboard.header.addSensor') }}
                </button>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!--  WIZARD: 3 ขั้นตอน                         -->
            <!-- ═══════════════════════════════════════════ -->
            <div v-if="wizardStep > 0" class="glass-card rounded-2xl overflow-hidden mb-6">
                <!-- Progress Bar -->
                <div class="flex border-b border-white/5">
                    <div v-for="s in [
                        { n: 1, label: t('ui.foodPassportIoTDashboard.steps.type') },
                        { n: 2, label: t('ui.foodPassportIoTDashboard.steps.settings') },
                        { n: 3, label: t('ui.foodPassportIoTDashboard.steps.connect') },
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
                        <h3 class="text-white font-semibold mb-1">{{ t('ui.foodPassportIoTDashboard.step1.title') }}</h3>
                        <p class="text-dark-500 text-xs mb-5">{{ t('ui.foodPassportIoTDashboard.step1.subtitle') }}</p>

                        <!-- Popular -->
                        <p class="text-dark-400 text-xs font-medium mb-2">{{ t('ui.foodPassportIoTDashboard.step1.recommended') }}</p>
                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <button v-for="[key, dt] in popularTypes" :key="key"
                                @click="form.type = key"
                                :class="['p-4 rounded-xl border text-center transition-all',
                                    form.type === key ? 'border-primary-500 bg-primary-500/10 shadow-lg shadow-primary-500/10' : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                ]">
                                <span class="text-3xl">{{ dt.icon }}</span>
                                <p class="text-sm font-medium mt-2" :class="form.type === key ? 'text-primary-400' : 'text-white'">{{ t(dt.labelKey) }}</p>
                                <p class="text-[10px] text-dark-500 mt-0.5">{{ t(dt.descKey) }}</p>
                            </button>
                        </div>

                        <!-- Others -->
                        <p class="text-dark-400 text-xs font-medium mb-2">{{ t('ui.foodPassportIoTDashboard.step1.others') }}</p>
                        <div class="grid grid-cols-4 gap-2 mb-6">
                            <button v-for="[key, dt] in otherTypes" :key="key"
                                @click="form.type = key"
                                :class="['p-3 rounded-xl border text-center transition-all',
                                    form.type === key ? 'border-primary-500 bg-primary-500/10' : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                ]">
                                <span class="text-xl">{{ dt.icon }}</span>
                                <p class="text-[10px] mt-1" :class="form.type === key ? 'text-primary-400' : 'text-dark-400'">{{ t(dt.labelKey) }}</p>
                            </button>
                        </div>

                        <div class="flex justify-between">
                            <button @click="closeWizard" class="text-dark-400 hover:text-white text-sm">{{ t('ui.foodPassportIoTDashboard.step1.cancel') }}</button>
                            <button @click="wizardStep = 2" class="px-6 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl text-sm font-medium">
                                {{ t('ui.foodPassportIoTDashboard.step1.next') }}
                            </button>
                        </div>
                    </template>

                    <!-- Step 2: ตั้งค่า -->
                    <template v-if="wizardStep === 2">
                        <h3 class="text-white font-semibold mb-1">{{ t('ui.foodPassportIoTDashboard.step2.title') }}</h3>
                        <p class="text-dark-500 text-xs mb-5">{{ t('ui.foodPassportIoTDashboard.step2.subtitle') }}</p>

                        <div class="space-y-4">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">{{ t('ui.foodPassportIoTDashboard.step2.nameLabel') }} <span class="text-trading-red">*</span></label>
                                <input v-model="form.name" type="text"
                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                                    :placeholder="t('ui.foodPassportIoTDashboard.step2.namePlaceholder')" />
                            </div>

                            <!-- Protocol -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">{{ t('ui.foodPassportIoTDashboard.step2.protocolLabel') }}</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button v-for="p in protocols" :key="p.id"
                                        @click="form.protocol = p.id"
                                        :class="['p-3 rounded-xl border text-center transition-all relative',
                                            form.protocol === p.id ? 'border-primary-500 bg-primary-500/10' : 'border-white/5 bg-dark-800/50 hover:bg-white/5'
                                        ]">
                                        <span v-if="p.recommended" class="absolute -top-1.5 -right-1.5 text-[8px] bg-trading-green text-white px-1.5 py-0.5 rounded-full">{{ t('ui.foodPassportIoTDashboard.step2.recommendedBadge') }}</span>
                                        <span class="text-xl">{{ p.icon }}</span>
                                        <p class="text-xs font-medium mt-1" :class="form.protocol === p.id ? 'text-primary-400' : 'text-white'">{{ p.label }}</p>
                                        <p class="text-[10px] text-dark-500">{{ t(p.descKey) }}</p>
                                    </button>
                                </div>
                            </div>

                            <!-- Location -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">{{ t('ui.foodPassportIoTDashboard.step2.locationLabel') }}</label>
                                <input v-model="form.location" type="text"
                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 text-sm"
                                    :placeholder="t('ui.foodPassportIoTDashboard.step2.locationPlaceholder')" />
                                <p class="text-dark-600 text-[10px] mt-1">{{ t('ui.foodPassportIoTDashboard.step2.locationHint') }}</p>
                            </div>

                            <!-- Interval -->
                            <div>
                                <label class="block text-sm text-dark-300 mb-1.5">{{ t('ui.foodPassportIoTDashboard.step2.intervalLabel') }}</label>
                                <div class="flex gap-2">
                                    <button v-for="min in ['1', '5', '15', '30', '60']" :key="min"
                                        @click="form.interval = min"
                                        :class="['px-4 py-2 rounded-lg text-sm transition-all',
                                            form.interval === min ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white'
                                        ]">
                                        {{ t('ui.foodPassportIoTDashboard.minutes', { n: min }) }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="error" class="p-3 rounded-xl bg-trading-red/10 text-trading-red text-sm">{{ errorText(error) }}</div>
                        </div>

                        <div class="flex justify-between mt-6">
                            <button @click="wizardStep = 1" class="text-dark-400 hover:text-white text-sm">{{ t('ui.foodPassportIoTDashboard.step2.back') }}</button>
                            <button @click="registerDevice" :disabled="!form.name || loading"
                                class="px-6 py-2.5 bg-gradient-to-r from-green-500 to-cyan-500 hover:from-green-600 hover:to-cyan-600 text-white rounded-xl text-sm font-medium disabled:opacity-50">
                                {{ loading ? t('ui.foodPassportIoTDashboard.step2.creating') : t('ui.foodPassportIoTDashboard.step2.create') }}
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
                                {{ issuedKey?.mode === 'rotated' ? t('ui.foodPassportIoTDashboard.step3.titleRotated') : issuedKey?.mode === 'issued' ? t('ui.foodPassportIoTDashboard.step3.titleIssued') : t('ui.foodPassportIoTDashboard.step3.titleRegistered') }}
                            </h3>
                            <p class="text-dark-400 text-sm mt-1">{{ t('ui.foodPassportIoTDashboard.step3.subtitle') }}</p>
                        </div>

                        <!-- API key — เห็นได้ครั้งนี้ครั้งเดียว (เซิร์ฟเวอร์เก็บแค่ hash) -->
                        <div v-if="issuedKey" class="p-4 rounded-xl bg-amber-500/5 border border-amber-500/20 mb-5" data-test="iot-api-key">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <p class="text-amber-300 text-sm font-medium">🔑 {{ t('ui.foodPassportIoTDashboard.step3.apiKeyOf', { id: issuedKey.deviceId }) }}</p>
                                <button @click="copyKey"
                                    class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-dark-300 hover:text-white text-xs transition-all whitespace-nowrap">
                                    {{ keyCopied ? '✓ ' + t('ui.foodPassportIoTDashboard.step3.copied') : '📋 ' + t('ui.foodPassportIoTDashboard.step3.copy') }}
                                </button>
                            </div>
                            <p class="text-white font-mono text-xs break-all bg-dark-900 rounded-lg px-3 py-2">{{ issuedKey.apiKey }}</p>
                            <p class="text-dark-400 text-xs mt-2">
                                {{ t('ui.foodPassportIoTDashboard.step3.showOnce') }}
                                <span v-if="issuedKey.mode === 'rotated'" class="text-amber-400">· {{ t('ui.foodPassportIoTDashboard.step3.oldKeyRevoked') }}</span>
                            </p>
                        </div>

                        <!-- Device Info -->
                        <div class="p-4 rounded-xl bg-dark-800/50 border border-white/5 mb-5">
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <span class="text-dark-500">{{ t('ui.foodPassportIoTDashboard.step3.deviceId') }}</span>
                                    <p class="text-white font-mono font-medium">{{ registeredDevice.device_id }}</p>
                                </div>
                                <div>
                                    <span class="text-dark-500">{{ t('ui.foodPassportIoTDashboard.step3.type') }}</span>
                                    <p class="text-white">{{ deviceTypes[registeredDevice.type]?.icon }} {{ deviceTypes[registeredDevice.type] ? t(deviceTypes[registeredDevice.type].labelKey) : registeredDevice.type }}</p>
                                </div>
                                <div>
                                    <span class="text-dark-500">{{ t('ui.foodPassportIoTDashboard.step3.protocol') }}</span>
                                    <p class="text-primary-400">{{ (registeredDevice.config?.protocol || 'http').toUpperCase() }}</p>
                                </div>
                                <div>
                                    <span class="text-dark-500">{{ t('ui.foodPassportIoTDashboard.step3.interval') }}</span>
                                    <p class="text-white">{{ t('ui.foodPassportIoTDashboard.minutes', { n: registeredDevice.config?.interval_minutes || 15 }) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Code Tabs -->
                        <div class="mb-2">
                            <p class="text-dark-300 text-sm font-medium mb-2">{{ t('ui.foodPassportIoTDashboard.step3.copyConfig') }}</p>
                            <div class="flex gap-1 overflow-x-auto">
                                <button v-for="tab in [
                                    { id: 'esp32', label: 'ESP32 / Arduino', icon: '🔌' },
                                    { id: 'raspberry', label: 'Raspberry Pi', icon: '🍓' },
                                    { id: 'node', label: 'Node.js', icon: '🟢' },
                                    { id: 'curl', label: t('ui.foodPassportIoTDashboard.step3.curlTab'), icon: '⚡' },
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
                                📋 {{ t('ui.foodPassportIoTDashboard.step3.copyCode') }}
                            </button>
                            <pre class="p-4 text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto max-h-80 overflow-y-auto">{{ getConfigCode(registeredDevice, issuedKey?.apiKey, activeCodeTab) }}</pre>
                        </div>

                        <!-- Quick Steps -->
                        <div class="p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/10 mb-5">
                            <p class="text-cyan-300 text-sm font-medium mb-2">{{ t('ui.foodPassportIoTDashboard.nextSteps.title') }}</p>
                            <div class="space-y-1.5 text-xs text-dark-300">
                                <p>1. {{ t('ui.foodPassportIoTDashboard.nextSteps.step1') }}</p>
                                <p>2. {{ t('ui.foodPassportIoTDashboard.nextSteps.step2a') }} <code class="text-cyan-400 bg-dark-800 px-1 rounded">PRODUCT_ID</code> {{ t('ui.foodPassportIoTDashboard.nextSteps.step2b') }}</p>
                                <p>3. {{ t('ui.foodPassportIoTDashboard.nextSteps.step3') }}</p>
                            </div>
                        </div>

                        <!-- FDP Reward Info -->
                        <div class="p-4 rounded-xl bg-amber-500/5 border border-amber-500/10 mb-5">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-lg">🪙</span>
                                <p class="text-amber-300 text-sm font-medium">{{ t('ui.foodPassportIoTDashboard.reward.title') }}</p>
                            </div>
                            <p class="text-dark-400 text-xs">{{ t('ui.foodPassportIoTDashboard.reward.descA') }} <strong class="text-amber-400">1 FDP</strong> {{ t('ui.foodPassportIoTDashboard.reward.descB') }}</p>
                        </div>

                        <button @click="closeWizard" class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm">
                            {{ t('ui.foodPassportIoTDashboard.step3.done') }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!--  DEVICE LIST                               -->
            <!-- ═══════════════════════════════════════════ -->
            <div v-if="devices.length" class="space-y-3">
                <h3 class="text-white font-semibold text-sm">{{ t('ui.foodPassportIoTDashboard.list.title', { n: devices.length }) }}</h3>
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
                                ]">{{ statusLabel(device.status) }}</span>
                                <!-- ลงทะเบียนก่อนมีระบบกุญแจ — ส่งข้อมูลไม่ได้จนกว่าจะออกกุญแจ -->
                                <span v-if="!device.has_api_key"
                                    class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400">{{ t('ui.foodPassportIoTDashboard.list.noKey') }}</span>
                            </div>
                            <p v-if="device.last_ping_at" class="text-dark-600 text-[10px] mt-0.5">
                                {{ t('ui.foodPassportIoTDashboard.list.lastPing', { time: new Date(device.last_ping_at).toLocaleString(locale === 'th' ? 'th-TH' : 'en-US') }) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="device.traces_count" class="text-dark-500 text-xs">{{ t('ui.foodPassportIoTDashboard.list.traces', { n: device.traces_count }) }}</span>
                            <button @click="rotateKey(device)"
                                :disabled="rotatingId === device.device_id"
                                :class="['px-3 py-1.5 rounded-lg text-xs transition-all disabled:opacity-50',
                                    device.has_api_key ? 'bg-white/5 text-dark-300 hover:bg-white/10' : 'bg-amber-500/10 text-amber-400 hover:bg-amber-500/20']">
                                {{ rotatingId === device.device_id ? '...' : '🔑 ' + (device.has_api_key ? t('ui.foodPassportIoTDashboard.list.reissue') : t('ui.foodPassportIoTDashboard.list.issueKey')) }}
                            </button>
                            <button @click="checkStatus(device)"
                                :disabled="testLoading && selectedDeviceForTest === device.device_id"
                                class="px-3 py-1.5 rounded-lg bg-cyan-500/10 text-cyan-400 text-xs hover:bg-cyan-500/20 transition-all disabled:opacity-50">
                                {{ testLoading && selectedDeviceForTest === device.device_id ? '...' : '🔄 ' + t('ui.foodPassportIoTDashboard.list.checkStatus') }}
                            </button>
                        </div>
                    </div>
                    <!-- ออกกุญแจไม่สำเร็จ -->
                    <div v-if="keyError && keyError.deviceId === device.device_id"
                        class="mt-3 pt-3 border-t border-white/5 flex items-center gap-2 text-trading-red text-sm">
                        <span>⚠️</span> {{ errorText(keyError.error) }}
                    </div>
                    <!-- ผลตรวจสถานะ: เซิร์ฟเวอร์ได้ยินจากเครื่องล่าสุดเมื่อไร -->
                    <div v-if="selectedDeviceForTest === device.device_id && testResult" class="mt-3 pt-3 border-t border-white/5">
                        <div :class="['flex items-center gap-2 text-sm', testResult.ok ? 'text-trading-green' : 'text-amber-400']">
                            <span>{{ testResult.ok ? '✅' : '⚠️' }}</span> {{ t(testResult.key, testResult.params) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="wizardStep === 0" class="glass-card rounded-2xl p-12 text-center">
                <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-cyan-500/10 to-blue-500/10 flex items-center justify-center mb-4">
                    <span class="text-5xl">📡</span>
                </div>
                <h3 class="text-white font-semibold mb-1">{{ t('ui.foodPassportIoTDashboard.empty.title') }}</h3>
                <p class="text-dark-400 text-sm mb-4">{{ t('ui.foodPassportIoTDashboard.empty.desc') }}</p>
                <button @click="startWizard"
                    class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-blue-500 text-white rounded-xl font-medium text-sm hover:from-cyan-600 hover:to-blue-600 shadow-lg shadow-cyan-500/25">
                    {{ t('ui.foodPassportIoTDashboard.empty.cta') }}
                </button>
            </div>
        </div>
    </div>
</template>
