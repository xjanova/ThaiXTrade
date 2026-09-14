<script setup>
/**
 * TPIX TRADE — หลังบ้านบอท Discord (/admin/discord)
 *
 * เจ้าของสั่ง: "โทเค็นให้ใส่ในหลังบ้านได้" · "บอทรู้ว่าจะโพสต์อะไรในห้องไหนได้เอง"
 * · "ประกาศการขายตามจริง" · "ตอบคำถามจากข้อมูลในเว็บ สมองเดียวกับหน้าเว็บ"
 *
 * โทเค็นไม่เคยถูกส่งกลับมาหน้านี้ (เห็นแค่ 4 ตัวท้าย) — ช่องว่าง = ใช้โทเค็นเดิม
 *
 * Developed by Xman Studio.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DiscordMessagePreview from '@/Components/Discord/DiscordMessagePreview.vue';

const props = defineProps({
    settings: { type: Object, required: true },
    status: { type: Object, default: () => ({}) },
    channels: { type: Array, default: () => [] },
    channelMap: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    posts: { type: Array, default: () => [] },
    salePreview: { type: Object, default: () => ({}) },
    interactionsUrl: { type: String, default: '' },
});

const form = useForm({
    enabled: props.settings.enabled,
    ask_enabled: props.settings.ask_enabled,
    application_id: props.settings.application_id,
    public_key: props.settings.public_key,
    guild_id: props.settings.guild_id,
    bot_token: '',
    rules_text: props.settings.rules_text,
    ask_daily_cap: props.settings.ask_daily_cap,
    ask_user_per_hour: props.settings.ask_user_per_hour,
    channel_map: { ...props.channelMap },
});

const busy = ref(null);
const showGuide = ref(!props.status.bot_name);
const copied = ref(false);

function save() {
    form.put('/admin/discord', {
        preserveScroll: true,
        onSuccess: () => form.reset('bot_token'),
    });
}

function action(path, name, confirmText = null) {
    if (confirmText && !window.confirm(confirmText)) return;
    busy.value = name;
    router.post(`/admin/discord/${path}`, {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = null; },
    });
}

function syncNow() {
    action('sync', 'sync', 'บอทจะโพสต์/แก้ข้อความในเซิร์ฟเวอร์ Discord จริง สมาชิกทุกคนจะเห็น — ยืนยันโพสต์ตอนนี้?');
}

async function copyUrl() {
    try {
        await navigator.clipboard.writeText(props.interactionsUrl);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (_) { /* เบราว์เซอร์ไม่ให้เข้าคลิปบอร์ด */ }
}

const channelLabel = (c) => (c.category ? `${c.category} › ` : '') + `#${c.name}`;
const channelName = (id) => {
    const c = props.channels.find((x) => x.id === id);
    return c ? channelLabel(c) : (id || '—');
};

const when = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('th-TH', { hour12: false });
};

const saleTone = computed(() => ({
    open: 'border-trading-green/40 bg-trading-green/10 text-trading-green',
    closed: 'border-white/10 bg-white/5 text-dark-300',
}[props.salePreview.state] || 'border-amber-400/40 bg-amber-400/10 text-amber-300'));

const kindLabel = (k) => ({
    sale_status: 'สถานะการขาย', rules: 'กฎ', whitepaper: 'Whitepaper', ask_hint: 'วิธีถาม', article: 'บทความ', video: 'วิดีโอ',
}[k] || k);

const actionLabel = (a) => ({
    created: 'โพสต์ใหม่', edited: 'แก้ข้อความเดิม', unchanged: 'ไม่เปลี่ยน', error: 'ผิดพลาด', no_channel: 'ยังไม่มีห้อง',
}[a] || a);

const ready = computed(() => ({
    token: props.settings.has_token,
    identified: !!props.status.identified_at,
    guild: !!props.status.guild_name,
    commands: !!props.status.commands_registered_at,
}));

// ── ทดสอบบอทผ่านหน้าเว็บ (ไม่มีอะไรถูกส่งเข้า Discord) ─────────────────────
// เจ้าของสั่ง: "ต้องทดสอบบอทผ่านหน้าเว็บได้เลย"
const testTab = ref('ask');
const testQuestion = ref('ตอนนี้เปิดขายเหรียญ TPIX หรือยัง ราคาเท่าไหร่');
const testAnswer = ref(null);
const testNote = ref('');
const previewItems = ref(null);
const rooms = ref(null);
const testLoading = ref(null);
const testError = ref('');

/** ข้อความ error ภาษาไทยเสมอ — ไม่โชว์ข้อความดิบจากเซิร์ฟเวอร์ ("Too Many Attempts." ฯลฯ) */
function explain(e) {
    const res = e?.response;
    if (res?.status === 422) return res.data?.errors?.question?.[0] || res.data?.message || 'ข้อมูลไม่ครบ';
    if (res?.status === 429) return 'ทดสอบถี่เกินไป รอสักครู่แล้วลองใหม่';
    if (res?.status === 403) return 'หน้านี้ใช้ได้เฉพาะ super_admin';
    return 'ทดสอบไม่สำเร็จ ลองใหม่อีกครั้ง';
}

async function runTest(kind, request) {
    if (testLoading.value) return;
    testLoading.value = kind;
    testError.value = '';
    try {
        return (await request()).data;
    } catch (e) {
        testError.value = explain(e);
        return null;
    } finally {
        testLoading.value = null;
    }
}

async function askTest() {
    if (!testQuestion.value.trim()) {
        testError.value = 'พิมพ์คำถามก่อน';
        return;
    }
    testAnswer.value = null;
    const data = await runTest('ask', () => axios.post('/admin/discord/test-ask', { question: testQuestion.value }));
    if (!data) return;
    testAnswer.value = data.message;
    testNote.value = data.success ? '' : 'ผู้ช่วย AI ตอบไม่สำเร็จ — ตรวจคีย์ OpenAI ที่ /admin/settings (แท็บ AI)';
}

async function loadPreview() {
    const data = await runTest('preview', () => axios.get('/admin/discord/preview'));
    if (data) previewItems.value = data.items;
}

async function checkRooms() {
    const data = await runTest('permissions', () => axios.get('/admin/discord/permissions'));
    if (data) rooms.value = data.rooms;
}

const previewStatus = (s) => ({
    new: ['ยังไม่ได้โพสต์ — รอบถัดไปจะโพสต์', 'bg-primary-500/20 text-primary-300'],
    update: ['ข้อมูลเปลี่ยน — จะแก้ข้อความเดิม', 'bg-amber-400/15 text-amber-300'],
    current: ['อยู่ในห้องแล้ว ตรงกับนี้', 'bg-trading-green/15 text-trading-green'],
    no_channel: ['ยังไม่ได้เลือกห้อง', 'bg-white/10 text-dark-300'],
    failed: ['เคยโพสต์ไม่สำเร็จ — จะลองใหม่', 'bg-trading-red/15 text-trading-red'],
}[s] || [s, 'bg-white/10 text-dark-300']);
</script>

<template>
    <Head title="บอท Discord" />
    <AdminLayout>
        <div class="space-y-5">
            <!-- หัวเรื่อง -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white">บอท Discord</h1>
                    <p class="text-xs text-dark-400 mt-1">
                        ส่งข่าว วิดีโอ กฎ Whitepaper และสถานะการขายเหรียญ (ตามจริง) เข้าเซิร์ฟเวอร์ชุมชนทุก 10 นาที · ตอบคำถามด้วย /ถาม จากสมองเดียวกับผู้ช่วยบนหน้าเว็บ
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50"
                            :disabled="!!busy" @click="action('connect', 'connect')">
                        {{ busy === 'connect' ? 'กำลังเชื่อมต่อ…' : 'ทดสอบการเชื่อมต่อ' }}
                    </button>
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50"
                            :disabled="!!busy || !ready.guild" @click="action('commands', 'commands')">
                        {{ busy === 'commands' ? 'กำลังลงทะเบียน…' : 'ลงทะเบียนคำสั่ง /ถาม' }}
                    </button>
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-primary-500/20 text-primary-300 hover:bg-primary-500/30 disabled:opacity-50"
                            :disabled="!!busy || !settings.enabled" :title="settings.enabled ? '' : 'เปิดสวิตช์ “เปิดบอท” แล้วบันทึกก่อน'" @click="syncNow">
                        {{ busy === 'sync' ? 'กำลังโพสต์…' : 'โพสต์/อัปเดตตอนนี้' }}
                    </button>
                </div>
            </div>

            <!-- สถานะ -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">บอท</p>
                    <p class="text-sm font-semibold text-white truncate">{{ status.bot_name || 'ยังไม่เชื่อม' }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">เซิร์ฟเวอร์</p>
                    <p class="text-sm font-semibold text-white truncate">{{ status.guild_name || '—' }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">ยืนยันตัวกับ Gateway</p>
                    <p class="text-sm font-semibold" :class="ready.identified ? 'text-trading-green' : 'text-dark-300'">{{ ready.identified ? 'แล้ว' : 'ยัง' }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">คำสั่ง /ถาม</p>
                    <p class="text-sm font-semibold" :class="ready.commands ? 'text-trading-green' : 'text-dark-300'">{{ ready.commands ? 'ลงทะเบียนแล้ว' : 'ยัง' }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">ซิงก์ล่าสุด</p>
                    <p class="text-sm font-semibold text-white">{{ when(status.last_sync_at) }}</p>
                </div>
            </div>

            <!-- ทดสอบบอทผ่านหน้าเว็บ -->
            <div class="glass-dark rounded-xl p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-white">🧪 ทดสอบบอทผ่านหน้าเว็บ</h2>
                        <p class="text-[11px] text-dark-400">ไม่มีอะไรถูกส่งเข้า Discord — เห็นผลแบบเดียวกับที่สมาชิกจะเห็น</p>
                    </div>
                    <div class="flex gap-1 text-xs">
                        <button v-for="t in [['ask', 'ลองถาม /ถาม'], ['preview', 'ตัวอย่างโพสต์'], ['permissions', 'ตรวจสิทธิ์ห้อง']]" :key="t[0]" type="button"
                                class="px-3 py-1.5 rounded-lg" :class="testTab === t[0] ? 'bg-primary-500/30 text-primary-200' : 'bg-white/5 text-dark-300 hover:bg-white/10'"
                                @click="testTab = t[0]; testError = ''">{{ t[1] }}</button>
                    </div>
                </div>

                <p v-if="testError" class="text-xs text-trading-red">{{ testError }}</p>

                <!-- ลองถาม -->
                <div v-if="testTab === 'ask'" class="space-y-3">
                    <form class="flex flex-col sm:flex-row gap-2" @submit.prevent="askTest">
                        <input v-model="testQuestion" type="text" maxlength="500" class="trading-input flex-1" placeholder="พิมพ์คำถามแบบที่สมาชิกจะถามใน Discord" />
                        <button type="submit" class="btn-primary whitespace-nowrap" :disabled="testLoading === 'ask'">
                            {{ testLoading === 'ask' ? 'ผู้ช่วยกำลังคิด…' : 'ถามเลย' }}
                        </button>
                    </form>
                    <p v-if="testNote" class="text-xs text-amber-300">{{ testNote }}</p>
                    <DiscordMessagePreview v-if="testAnswer" :payload="testAnswer" :bot-name="status.bot_name || 'TPIX TRADE'" />
                    <p class="text-[11px] text-dark-500">ใช้สมองตัวเดียวกับผู้ช่วยบนหน้าเว็บและคำสั่ง /ถาม ใน Discord · ลิงก์ที่ไม่ใช่ tpix.online ถูกซ่อนเหมือนของจริง · กินโควตา AI จริง (จำกัด 20 ครั้ง/นาที)</p>
                </div>

                <!-- ตัวอย่างโพสต์ -->
                <div v-else-if="testTab === 'preview'" class="space-y-3">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50" :disabled="testLoading === 'preview'" @click="loadPreview">
                        {{ testLoading === 'preview' ? 'กำลังเตรียมตัวอย่าง…' : (previewItems ? 'โหลดใหม่' : 'ดูตัวอย่างสิ่งที่บอทจะโพสต์') }}
                    </button>
                    <p v-if="previewItems && !previewItems.length" class="text-xs text-dark-400">ไม่มีอะไรรอโพสต์</p>
                    <div v-for="item in previewItems || []" :key="item.kind + item.ref" class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="text-white font-semibold">{{ kindLabel(item.kind) }}</span>
                            <span class="text-dark-400">→ {{ item.channel_name ? '#' + item.channel_name : 'ยังไม่มีห้อง' }}</span>
                            <span class="px-2 py-0.5 rounded" :class="previewStatus(item.status)[1]">{{ previewStatus(item.status)[0] }}</span>
                            <span v-if="item.error" class="text-amber-300">ครั้งก่อน: {{ item.error }}</span>
                        </div>
                        <DiscordMessagePreview :payload="item.payload" :bot-name="status.bot_name || 'TPIX TRADE'" />
                    </div>
                </div>

                <!-- ตรวจสิทธิ์ห้อง -->
                <div v-else class="space-y-3">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50" :disabled="testLoading === 'permissions'" @click="checkRooms">
                        {{ testLoading === 'permissions' ? 'กำลังตรวจ…' : 'ตรวจว่าบอทโพสต์ได้ในทุกห้องที่ใช้' }}
                    </button>
                    <div v-if="rooms" class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-dark-400">
                                <tr><th class="text-left py-1">ห้อง</th><th class="text-left">ใช้ลง</th><th>มองเห็น</th><th>ส่งข้อความ</th><th>การ์ด</th><th class="text-left">ต้องทำ</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rooms" :key="r.channel_id" class="border-t border-white/5">
                                    <td class="py-1.5 text-white">#{{ r.name || r.channel_id }}</td>
                                    <td class="text-dark-300">{{ r.roles.join(', ') }}</td>
                                    <td class="text-center">{{ r.view ? '✅' : '❌' }}</td>
                                    <td class="text-center">{{ r.send ? '✅' : '❌' }}</td>
                                    <td class="text-center">{{ r.embed ? '✅' : '❌' }}</td>
                                    <td :class="r.ok ? 'text-trading-green' : 'text-amber-300'">
                                        {{ r.ok ? 'พร้อมโพสต์' : `คลิกขวาห้อง → Edit Channel → Permissions → เพิ่มยศ ${status.bot_name || 'ของบอท'} → ✅ ${r.missing.join(', ')}` }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- วิธีตั้งค่า -->
            <div class="glass-dark rounded-xl p-4">
                <button type="button" class="w-full flex items-center justify-between text-left" @click="showGuide = !showGuide">
                    <span class="text-sm font-semibold text-white">ขั้นตอนตั้งค่าครั้งแรก (ทำครั้งเดียว ~10 นาที)</span>
                    <span class="text-dark-400 text-xs">{{ showGuide ? 'ซ่อน' : 'แสดง' }}</span>
                </button>
                <ol v-if="showGuide" class="mt-3 space-y-2 text-xs text-dark-200 list-decimal list-inside leading-relaxed">
                    <li>เข้า <a href="https://discord.com/developers/applications" target="_blank" rel="noopener" class="text-primary-300 underline">Discord Developer Portal</a> → New Application (ตั้งชื่อ เช่น TPIX)</li>
                    <li>แท็บ <b>General Information</b> → คัดลอก <b>Application ID</b> และ <b>Public Key</b> มาใส่ด้านล่าง แล้วกดบันทึก</li>
                    <li>
                        ช่อง <b>Interactions Endpoint URL</b> (หน้าเดิม) ใส่
                        <code class="px-1.5 py-0.5 rounded bg-black/40 text-primary-200 break-all">{{ interactionsUrl }}</code>
                        <button type="button" class="ml-1 text-primary-300 underline" @click="copyUrl">{{ copied ? 'คัดลอกแล้ว' : 'คัดลอก' }}</button>
                        — ต้องบันทึก Public Key ในหน้านี้ก่อน Discord ถึงจะยอมรับ
                    </li>
                    <li>แท็บ <b>Bot</b> → Reset Token → คัดลอกมาใส่ช่อง "โทเค็นบอท" (เก็บแบบเข้ารหัส ไม่แสดงกลับ)</li>
                    <li>แท็บ <b>OAuth2 → URL Generator</b> เลือก <b>bot</b> + <b>applications.commands</b> · สิทธิ์ View Channels, Send Messages, Embed Links, Read Message History → เปิดลิงก์เชิญบอทเข้าเซิร์ฟเวอร์</li>
                    <li>ใน Discord เปิด Developer Mode (User Settings → Advanced) → คลิกขวาชื่อเซิร์ฟเวอร์ → <b>Copy Server ID</b> มาใส่ Guild ID</li>
                    <li>กด <b>ทดสอบการเชื่อมต่อ</b> — บอทจะอ่านรายชื่อห้องทั้งหมดและจัดให้เองว่าเรื่องไหนลงห้องไหน (แก้ได้ด้านล่าง)</li>
                    <li>กด <b>ลงทะเบียนคำสั่ง /ถาม</b> แล้วเปิดสวิตช์ <b>เปิดบอท</b> → บันทึก → <b>โพสต์/อัปเดตตอนนี้</b></li>
                </ol>
            </div>

            <div class="grid lg:grid-cols-2 gap-5">
                <!-- ตั้งค่า -->
                <form class="glass-dark rounded-xl p-4 space-y-3" @submit.prevent="save">
                    <h2 class="text-sm font-semibold text-white">ตั้งค่า</h2>

                    <label class="flex items-center justify-between gap-3 text-sm text-dark-200">
                        <span>เปิดบอท (ให้โพสต์เข้าเซิร์ฟเวอร์อัตโนมัติ)</span>
                        <input v-model="form.enabled" type="checkbox" class="rounded" />
                    </label>
                    <label class="flex items-center justify-between gap-3 text-sm text-dark-200">
                        <span>เปิดให้ถามผู้ช่วย AI ด้วย /ถาม</span>
                        <input v-model="form.ask_enabled" type="checkbox" class="rounded" />
                    </label>

                    <div>
                        <label class="text-xs text-dark-400">Application ID</label>
                        <input v-model="form.application_id" type="text" inputmode="numeric" class="trading-input w-full" placeholder="ตัวเลข 17-20 หลัก" />
                        <p v-if="form.errors.application_id" class="text-xs text-trading-red mt-1">{{ form.errors.application_id }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-dark-400">Public Key</label>
                        <input v-model="form.public_key" type="text" class="trading-input w-full font-mono text-xs" placeholder="hex 64 ตัวอักษร" />
                        <p v-if="form.errors.public_key" class="text-xs text-trading-red mt-1">{{ form.errors.public_key }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-dark-400">โทเค็นบอท</label>
                        <input v-model="form.bot_token" type="password" autocomplete="new-password" class="trading-input w-full font-mono text-xs"
                               :placeholder="settings.bot_token_masked ? `ตั้งไว้แล้ว ${settings.bot_token_masked} — เว้นว่างเพื่อใช้ตัวเดิม` : 'วางโทเค็นจากแท็บ Bot'" />
                        <p v-if="form.errors.bot_token" class="text-xs text-trading-red mt-1">{{ form.errors.bot_token }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-dark-400">Guild ID (ID ของเซิร์ฟเวอร์)</label>
                        <input v-model="form.guild_id" type="text" inputmode="numeric" class="trading-input w-full" />
                        <p v-if="form.errors.guild_id" class="text-xs text-trading-red mt-1">{{ form.errors.guild_id }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-dark-400">/ถาม ได้กี่ครั้งต่อวัน (ทั้งเซิร์ฟเวอร์)</label>
                            <input v-model.number="form.ask_daily_cap" type="number" min="1" max="5000" class="trading-input w-full" />
                        </div>
                        <div>
                            <label class="text-xs text-dark-400">ต่อคนต่อชั่วโมง</label>
                            <input v-model.number="form.ask_user_per_hour" type="number" min="1" max="120" class="trading-input w-full" />
                        </div>
                    </div>
                    <p class="text-[11px] text-dark-500">ทุกคำถามใช้โควตา OpenAI ก้อนเดียวกับทั้งองค์กร — เพดานนี้คุมค่าใช้จ่าย</p>

                    <div>
                        <label class="text-xs text-dark-400">กฎของชุมชน (บอทแก้ข้อความในห้องกฎให้เองเมื่อบันทึกแล้วซิงก์)</label>
                        <textarea v-model="form.rules_text" rows="9" maxlength="3500" class="trading-input w-full text-xs leading-relaxed"></textarea>
                        <p v-if="form.errors.rules_text" class="text-xs text-trading-red mt-1">{{ form.errors.rules_text }}</p>
                    </div>

                    <button type="submit" class="btn-primary w-full" :disabled="form.processing">
                        {{ form.processing ? 'กำลังบันทึก…' : 'บันทึก' }}
                    </button>
                </form>

                <div class="space-y-5">
                    <!-- ผังห้อง -->
                    <div class="glass-dark rounded-xl p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-white">เรื่องไหนลงห้องไหน</h2>
                            <button type="button" class="px-2.5 py-1 rounded-lg text-[11px] bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50"
                                    :disabled="!!busy || !channels.length" @click="action('replan', 'replan')">
                                จัดห้องใหม่อัตโนมัติ
                            </button>
                        </div>
                        <p v-if="!channels.length" class="text-xs text-dark-400">ยังไม่มีรายชื่อห้อง — ตั้งค่าแล้วกด "ทดสอบการเชื่อมต่อ"</p>
                        <div v-else class="space-y-2">
                            <div v-for="role in roles" :key="role.key" class="flex items-center gap-2">
                                <span class="w-40 shrink-0 text-xs text-dark-300">{{ role.label }}</span>
                                <select v-model="form.channel_map[role.key]" class="trading-input flex-1 text-xs">
                                    <option :value="undefined">— ไม่โพสต์ —</option>
                                    <option v-for="c in channels" :key="c.id" :value="c.id">{{ channelLabel(c) }}</option>
                                </select>
                            </div>
                            <p class="text-[11px] text-dark-500">เปลี่ยนแล้วกด "บันทึก" ในกล่องตั้งค่า · ห้องที่เลือกต้องให้สิทธิ์บอท Send Messages + Embed Links</p>
                        </div>
                    </div>

                    <!-- ตัวอย่างประกาศการขาย -->
                    <div class="glass-dark rounded-xl p-4 space-y-2">
                        <h2 class="text-sm font-semibold text-white">ประกาศการขายเหรียญที่บอทจะโพสต์ (ตามจริง ณ ตอนนี้)</h2>
                        <div class="rounded-lg border px-3 py-2 text-sm" :class="saleTone">
                            <p class="font-semibold">{{ salePreview.headline }}</p>
                            <p class="text-xs mt-1 opacity-90">{{ salePreview.detail }}</p>
                        </div>
                        <div v-for="p in salePreview.phases || []" :key="p.name" class="flex flex-wrap items-baseline justify-between gap-2 text-xs border-b border-white/5 py-1.5">
                            <span class="text-white">{{ p.name }} · {{ p.price }}</span>
                            <span class="text-dark-300">{{ p.status_label }} · {{ p.window }}</span>
                        </div>
                        <p class="text-[11px] text-dark-500">
                            "เปิดขาย" จะขึ้นเองเมื่อ: มีเฟสอยู่ในช่วงเวลา + มีช่องทางจ่ายเงินที่ใช้ได้ + ระบบจ่ายเหรียญพร้อม (ด่านเดียวกับ /admin/token-sales)
                        </p>
                    </div>
                </div>
            </div>

            <!-- ผลโพสต์ล่าสุด -->
            <div class="glass-dark rounded-xl p-4">
                <h2 class="text-sm font-semibold text-white mb-3">ผลรอบซิงก์ล่าสุด</h2>
                <p v-if="!status.last_sync?.length && !posts.length" class="text-xs text-dark-400">ยังไม่เคยโพสต์</p>
                <div v-if="status.last_sync?.length" class="mb-4 space-y-1">
                    <div v-for="(r, i) in status.last_sync" :key="i" class="flex flex-wrap gap-2 text-xs">
                        <span class="text-white">{{ kindLabel(r.kind) }} {{ r.kind === 'article' || r.kind === 'video' ? '#' + r.ref : '' }}</span>
                        <span :class="r.action === 'error' || r.action === 'no_channel' ? 'text-trading-red' : 'text-trading-green'">{{ actionLabel(r.action) }}</span>
                        <span v-if="r.channel_id" class="text-dark-400">{{ channelName(r.channel_id) }}</span>
                        <span v-if="r.error" class="text-amber-300">{{ r.error }}</span>
                    </div>
                </div>
                <div v-if="posts.length" class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-dark-400">
                            <tr><th class="text-left py-1">เรื่อง</th><th class="text-left">ห้อง</th><th class="text-left">โพสต์เมื่อ</th><th class="text-left">สถานะ</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in posts" :key="p.kind + p.ref_key" class="border-t border-white/5">
                                <td class="py-1.5 text-white">{{ kindLabel(p.kind) }} {{ p.kind === 'article' || p.kind === 'video' ? '#' + p.ref_key : '' }}</td>
                                <td class="text-dark-300">{{ channelName(p.channel_id) }}</td>
                                <td class="text-dark-300">{{ when(p.posted_at) }}</td>
                                <td :class="p.last_error ? 'text-amber-300' : 'text-trading-green'">{{ p.last_error || (p.message_id ? 'อยู่ในห้องแล้ว' : 'รอโพสต์') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
