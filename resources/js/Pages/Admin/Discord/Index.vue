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
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    settings: { type: Object, required: true },
    status: { type: Object, default: () => ({}) },
    channels: { type: Array, default: () => [] },
    channelMap: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    posts: { type: Array, default: () => [] },
    salePreview: { type: Object, default: () => ({}) },
    interactionsUrl: { type: String, default: '' },
    moderation: { type: Object, default: () => ({}) },
});

// ── ดูแลห้องอัตโนมัติ (เจ้าของ: "แบน เตะ คนได้หากมีแนวโน้มไม่ดี") ─────────────
const modForm = useForm({
    mode: props.moderation.mode || 'off',
    log_channel: props.moderation.log_channel || '',
    timeout_at: props.moderation.timeout_at,
    kick_at: props.moderation.kick_at,
    ban_at: props.moderation.ban_at,
    max_bans_per_day: props.moderation.max_bans_per_day,
});
const memberForm = useForm({ user_id: '', action: 'timeout', reason: '' });
const modPerm = ref(null);

function saveModeration() {
    if (modForm.mode === 'enforce' && props.moderation.mode !== 'enforce'
        && !window.confirm(t('admin.discord.confirm.enforce'))) return;
    modForm.put('/admin/discord/moderation', { preserveScroll: true });
}

const MOD_ACTIONS = ['timeout', 'kick', 'ban', 'unban'];
const modActionLabel = (a) => (MOD_ACTIONS.includes(a) ? t(`admin.discord.modAction.${a}`) : a);

function submitMember() {
    const label = modActionLabel(memberForm.action);
    if (!window.confirm(t('admin.discord.confirm.member', { action: label, user: memberForm.user_id, reason: memberForm.reason }))) return;
    memberForm.post('/admin/discord/moderation/member', { preserveScroll: true, onSuccess: () => memberForm.reset() });
}

async function checkModPermissions() {
    const data = await runTest('mod-perm', () => axios.get('/admin/discord/moderation/permissions'));
    if (data) modPerm.value = data;
}

const modStatusLabel = (s, mode) => ({
    done: [t('admin.discord.modStatus.done'), 'text-trading-green'],
    failed: [t('admin.discord.modStatus.failed'), 'text-trading-red'],
    capped: [t('admin.discord.modStatus.capped'), 'text-amber-300'],
    skipped: [mode === 'observe' ? t('admin.discord.modStatus.observe') : t('admin.discord.modStatus.skipped'), 'text-dark-300'],
}[s] || [s, 'text-dark-300']);

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
    action('sync', 'sync', t('admin.discord.confirm.sync'));
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
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', { hour12: false });
};

const saleTone = computed(() => ({
    open: 'border-trading-green/40 bg-trading-green/10 text-trading-green',
    closed: 'border-white/10 bg-white/5 text-dark-300',
}[props.salePreview.state] || 'border-amber-400/40 bg-amber-400/10 text-amber-300'));

const KINDS = [
    'sale_status', 'rules', 'whitepaper', 'ask_hint', 'article', 'video',
    'price_card', 'dex_pairs', 'release', 'dex_pair',
    'guide_help', 'guide_dex', 'guide_bugs', 'guide_ideas', 'guide_intro',
];
const kindLabel = (k) => (KINDS.includes(k) ? t(`admin.discord.kind.${k}`) : k);

// ชิ้นที่มีหลายตัวต่อเรื่อง (บทความ/วิดีโอ/แอปรุ่นใหม่/คู่เทรดใหม่) โชว์รหัสต่อท้ายให้แยกออก
const showsRef = (k) => ['article', 'video', 'release', 'dex_pair'].includes(k);

const POST_ACTIONS = ['created', 'edited', 'unchanged', 'error', 'no_channel', 'skipped'];
const actionLabel = (a) => (POST_ACTIONS.includes(a) ? t(`admin.discord.postAction.${a}`) : a);

const ready = computed(() => ({
    token: props.settings.has_token,
    identified: !!props.status.identified_at,
    guild: !!props.status.guild_name,
    commands: !!props.status.commands_registered_at,
}));

// ── ทดสอบบอทผ่านหน้าเว็บ (ไม่มีอะไรถูกส่งเข้า Discord) ─────────────────────
// เจ้าของสั่ง: "ต้องทดสอบบอทผ่านหน้าเว็บได้เลย"
const testTab = ref('ask');
const testTabs = [
    ['ask', 'admin.discord.test.tabs.ask'],
    ['preview', 'admin.discord.test.tabs.preview'],
    ['permissions', 'admin.discord.test.tabs.permissions'],
];
// null = ยังไม่ได้พิมพ์เอง → โชว์คำถามตัวอย่างตามภาษาปัจจุบัน
const testQuestionRaw = ref(null);
const testQuestion = computed({
    get: () => testQuestionRaw.value ?? t('admin.discord.test.defaultQuestion'),
    set: (v) => { testQuestionRaw.value = v; },
});
const testAnswer = ref(null);
const testNote = ref('');
const previewItems = ref(null);
const rooms = ref(null);
const testLoading = ref(null);
// เก็บเป็น { key } (แปลตอน render) หรือ { raw } (ข้อความ validation จากเซิร์ฟเวอร์)
const testError = ref(null);
const testErrorText = computed(() => {
    if (!testError.value) return '';
    return testError.value.key ? t(testError.value.key) : testError.value.raw;
});

/** ข้อความ error ตามภาษาที่เลือกเสมอ — ไม่โชว์ข้อความดิบจากเซิร์ฟเวอร์ ("Too Many Attempts." ฯลฯ) */
function explain(e) {
    const res = e?.response;
    if (res?.status === 422) {
        const raw = res.data?.errors?.question?.[0] || res.data?.message;
        return raw ? { raw } : { key: 'admin.discord.error.incomplete' };
    }
    if (res?.status === 429) return { key: 'admin.discord.error.tooMany' };
    if (res?.status === 403) return { key: 'admin.discord.error.forbidden' };
    return { key: 'admin.discord.error.generic' };
}

async function runTest(kind, request) {
    if (testLoading.value) return;
    testLoading.value = kind;
    testError.value = null;
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
        testError.value = { key: 'admin.discord.error.emptyQuestion' };
        return;
    }
    testAnswer.value = null;
    const data = await runTest('ask', () => axios.post('/admin/discord/test-ask', { question: testQuestion.value }));
    if (!data) return;
    testAnswer.value = data.message;
    testNote.value = data.success ? '' : 'admin.discord.test.aiFailed';
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
    new: [t('admin.discord.previewStatus.new'), 'bg-primary-500/20 text-primary-300'],
    update: [t('admin.discord.previewStatus.update'), 'bg-amber-400/15 text-amber-300'],
    current: [t('admin.discord.previewStatus.current'), 'bg-trading-green/15 text-trading-green'],
    no_channel: [t('admin.discord.previewStatus.no_channel'), 'bg-white/10 text-dark-300'],
    failed: [t('admin.discord.previewStatus.failed'), 'bg-trading-red/15 text-trading-red'],
    unavailable: [t('admin.discord.previewStatus.unavailable'), 'bg-amber-400/15 text-amber-300'],
}[s] || [s, 'bg-white/10 text-dark-300']);

const botName = computed(() => props.status.bot_name || t('admin.discord.botRoleFallback'));
</script>

<template>
    <Head :title="t('admin.discord.pageTitle')" />
    <AdminLayout>
        <div class="space-y-5">
            <!-- หัวเรื่อง -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white">{{ t('admin.discord.pageTitle') }}</h1>
                    <p class="text-xs text-dark-400 mt-1">
                        {{ t('admin.discord.header.subtitle') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50"
                            :disabled="!!busy" @click="action('connect', 'connect')">
                        {{ busy === 'connect' ? t('admin.discord.header.connecting') : t('admin.discord.header.testConnection') }}
                    </button>
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50"
                            :disabled="!!busy || !ready.guild" @click="action('commands', 'commands')">
                        {{ busy === 'commands' ? t('admin.discord.header.registering') : t('admin.discord.header.registerCommand') }}
                    </button>
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-primary-500/20 text-primary-300 hover:bg-primary-500/30 disabled:opacity-50"
                            :disabled="!!busy || !settings.enabled" :title="settings.enabled ? '' : t('admin.discord.header.enableFirst')" @click="syncNow">
                        {{ busy === 'sync' ? t('admin.discord.header.posting') : t('admin.discord.header.postNow') }}
                    </button>
                </div>
            </div>

            <!-- สถานะ -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">{{ t('admin.discord.stats.bot') }}</p>
                    <p class="text-sm font-semibold text-white truncate">{{ status.bot_name || t('admin.discord.stats.notConnected') }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">{{ t('admin.discord.stats.server') }}</p>
                    <p class="text-sm font-semibold text-white truncate">{{ status.guild_name || '—' }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">{{ t('admin.discord.stats.gateway') }}</p>
                    <p class="text-sm font-semibold" :class="ready.identified ? 'text-trading-green' : 'text-dark-300'">{{ ready.identified ? t('admin.discord.stats.yes') : t('admin.discord.stats.notYet') }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">{{ t('admin.discord.stats.command') }}</p>
                    <p class="text-sm font-semibold" :class="ready.commands ? 'text-trading-green' : 'text-dark-300'">{{ ready.commands ? t('admin.discord.stats.registered') : t('admin.discord.stats.notYet') }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">{{ t('admin.discord.stats.lastSync') }}</p>
                    <p class="text-sm font-semibold text-white">{{ when(status.last_sync_at) }}</p>
                </div>
            </div>

            <!-- ทดสอบบอทผ่านหน้าเว็บ -->
            <div class="glass-dark rounded-xl p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-white">🧪 {{ t('admin.discord.test.title') }}</h2>
                        <p class="text-[11px] text-dark-400">{{ t('admin.discord.test.subtitle') }}</p>
                    </div>
                    <div class="flex gap-1 text-xs">
                        <button v-for="tab in testTabs" :key="tab[0]" type="button"
                                class="px-3 py-1.5 rounded-lg" :class="testTab === tab[0] ? 'bg-primary-500/30 text-primary-200' : 'bg-white/5 text-dark-300 hover:bg-white/10'"
                                @click="testTab = tab[0]; testError = null">{{ t(tab[1]) }}</button>
                    </div>
                </div>

                <p v-if="testErrorText" class="text-xs text-trading-red">{{ testErrorText }}</p>

                <!-- ลองถาม -->
                <div v-if="testTab === 'ask'" class="space-y-3">
                    <form class="flex flex-col sm:flex-row gap-2" @submit.prevent="askTest">
                        <input v-model="testQuestion" type="text" maxlength="500" class="trading-input flex-1" :placeholder="t('admin.discord.test.questionPlaceholder')" />
                        <button type="submit" class="btn-primary whitespace-nowrap" :disabled="testLoading === 'ask'">
                            {{ testLoading === 'ask' ? t('admin.discord.test.thinking') : t('admin.discord.test.askNow') }}
                        </button>
                    </form>
                    <p v-if="testNote" class="text-xs text-amber-300">{{ t(testNote) }}</p>
                    <DiscordMessagePreview v-if="testAnswer" :payload="testAnswer" :bot-name="status.bot_name || 'TPIX TRADE'" />
                    <p class="text-[11px] text-dark-500">{{ t('admin.discord.test.askHint') }}</p>
                </div>

                <!-- ตัวอย่างโพสต์ -->
                <div v-else-if="testTab === 'preview'" class="space-y-3">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50" :disabled="testLoading === 'preview'" @click="loadPreview">
                        {{ testLoading === 'preview' ? t('admin.discord.test.preparing') : (previewItems ? t('admin.discord.test.reload') : t('admin.discord.test.loadPreview')) }}
                    </button>
                    <p v-if="previewItems && !previewItems.length" class="text-xs text-dark-400">{{ t('admin.discord.test.nothingPending') }}</p>
                    <div v-for="item in previewItems || []" :key="item.kind + item.ref" class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="text-white font-semibold">{{ kindLabel(item.kind) }}</span>
                            <span class="text-dark-400">→ {{ item.channel_name ? '#' + item.channel_name : t('admin.discord.postAction.no_channel') }}</span>
                            <span class="px-2 py-0.5 rounded" :class="previewStatus(item.status)[1]">{{ previewStatus(item.status)[0] }}</span>
                            <span v-if="item.error" class="text-amber-300">{{ t('admin.discord.test.lastError', { error: item.error }) }}</span>
                        </div>
                        <DiscordMessagePreview :payload="item.payload" :bot-name="status.bot_name || 'TPIX TRADE'" />
                    </div>
                </div>

                <!-- ตรวจสิทธิ์ห้อง -->
                <div v-else class="space-y-3">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50" :disabled="testLoading === 'permissions'" @click="checkRooms">
                        {{ testLoading === 'permissions' ? t('admin.discord.checking') : t('admin.discord.test.checkRooms') }}
                    </button>
                    <div v-if="rooms" class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-dark-400">
                                <tr>
                                    <th class="text-left py-1">{{ t('admin.discord.rooms.room') }}</th>
                                    <th class="text-left">{{ t('admin.discord.rooms.usedFor') }}</th>
                                    <th>{{ t('admin.discord.rooms.view') }}</th>
                                    <th>{{ t('admin.discord.rooms.send') }}</th>
                                    <th>{{ t('admin.discord.rooms.embed') }}</th>
                                    <th class="text-left">{{ t('admin.discord.rooms.todo') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rooms" :key="r.channel_id" class="border-t border-white/5">
                                    <td class="py-1.5 text-white">#{{ r.name || r.channel_id }}</td>
                                    <td class="text-dark-300">{{ r.roles.join(', ') }}</td>
                                    <td class="text-center">{{ r.view ? '✅' : '❌' }}</td>
                                    <td class="text-center">{{ r.send ? '✅' : '❌' }}</td>
                                    <td class="text-center">{{ r.embed ? '✅' : '❌' }}</td>
                                    <td :class="r.ok ? 'text-trading-green' : 'text-amber-300'">
                                        {{ r.ok ? t('admin.discord.rooms.ready') : t('admin.discord.rooms.fix', { bot: botName, missing: r.missing.join(', ') }) }}
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
                    <span class="text-sm font-semibold text-white">{{ t('admin.discord.guide.title') }}</span>
                    <span class="text-dark-400 text-xs">{{ showGuide ? t('admin.discord.guide.hide') : t('admin.discord.guide.show') }}</span>
                </button>
                <ol v-if="showGuide" class="mt-3 space-y-2 text-xs text-dark-200 list-decimal list-inside leading-relaxed">
                    <li>{{ t('admin.discord.guide.s1.before') }} <a href="https://discord.com/developers/applications" target="_blank" rel="noopener" class="text-primary-300 underline">Discord Developer Portal</a> {{ t('admin.discord.guide.s1.after') }}</li>
                    <li>{{ t('admin.discord.guide.s2.tab') }} <b>General Information</b> {{ t('admin.discord.guide.s2.copy') }} <b>Application ID</b> {{ t('admin.discord.guide.s2.and') }} <b>Public Key</b> {{ t('admin.discord.guide.s2.after') }}</li>
                    <li>
                        {{ t('admin.discord.guide.s3.field') }} <b>Interactions Endpoint URL</b> {{ t('admin.discord.guide.s3.enter') }}
                        <code class="px-1.5 py-0.5 rounded bg-black/40 text-primary-200 break-all">{{ interactionsUrl }}</code>
                        <button type="button" class="ml-1 text-primary-300 underline" @click="copyUrl">{{ copied ? t('admin.discord.guide.copied') : t('admin.discord.guide.copy') }}</button>
                        {{ t('admin.discord.guide.s3.after') }}
                    </li>
                    <li>{{ t('admin.discord.guide.s2.tab') }} <b>Bot</b> {{ t('admin.discord.guide.s4.after') }}</li>
                    <li>{{ t('admin.discord.guide.s2.tab') }} <b>OAuth2 → URL Generator</b> {{ t('admin.discord.guide.s5.select') }} <b>bot</b> + <b>applications.commands</b> {{ t('admin.discord.guide.s5.after') }}</li>
                    <li>{{ t('admin.discord.guide.s6.before') }} <b>Copy Server ID</b> {{ t('admin.discord.guide.s6.after') }}</li>
                    <li>{{ t('admin.discord.guide.press') }} <b>{{ t('admin.discord.header.testConnection') }}</b> {{ t('admin.discord.guide.s7.after') }}</li>
                    <li>{{ t('admin.discord.guide.press') }} <b>{{ t('admin.discord.header.registerCommand') }}</b> {{ t('admin.discord.guide.s8.thenEnable') }} <b>{{ t('admin.discord.guide.s8.enableBot') }}</b> {{ t('admin.discord.guide.s8.save') }} <b>{{ t('admin.discord.header.postNow') }}</b></li>
                </ol>
            </div>

            <div class="grid lg:grid-cols-2 gap-5">
                <!-- ตั้งค่า -->
                <form class="glass-dark rounded-xl p-4 space-y-3" @submit.prevent="save">
                    <h2 class="text-sm font-semibold text-white">{{ t('admin.discord.settings.title') }}</h2>

                    <label class="flex items-center justify-between gap-3 text-sm text-dark-200">
                        <span>{{ t('admin.discord.settings.enabled') }}</span>
                        <input v-model="form.enabled" type="checkbox" class="rounded" />
                    </label>
                    <label class="flex items-center justify-between gap-3 text-sm text-dark-200">
                        <span>{{ t('admin.discord.settings.askEnabled') }}</span>
                        <input v-model="form.ask_enabled" type="checkbox" class="rounded" />
                    </label>

                    <div>
                        <label class="text-xs text-dark-400">Application ID</label>
                        <input v-model="form.application_id" type="text" inputmode="numeric" class="trading-input w-full" :placeholder="t('admin.discord.settings.appIdPlaceholder')" />
                        <p v-if="form.errors.application_id" class="text-xs text-trading-red mt-1">{{ form.errors.application_id }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-dark-400">Public Key</label>
                        <input v-model="form.public_key" type="text" class="trading-input w-full font-mono text-xs" :placeholder="t('admin.discord.settings.publicKeyPlaceholder')" />
                        <p v-if="form.errors.public_key" class="text-xs text-trading-red mt-1">{{ form.errors.public_key }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-dark-400">{{ t('admin.discord.settings.botToken') }}</label>
                        <input v-model="form.bot_token" type="password" autocomplete="new-password" class="trading-input w-full font-mono text-xs"
                               :placeholder="settings.bot_token_masked ? t('admin.discord.settings.tokenSet', { masked: settings.bot_token_masked }) : t('admin.discord.settings.tokenPlaceholder')" />
                        <p v-if="form.errors.bot_token" class="text-xs text-trading-red mt-1">{{ form.errors.bot_token }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-dark-400">{{ t('admin.discord.settings.guildId') }}</label>
                        <input v-model="form.guild_id" type="text" inputmode="numeric" class="trading-input w-full" />
                        <p v-if="form.errors.guild_id" class="text-xs text-trading-red mt-1">{{ form.errors.guild_id }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-dark-400">{{ t('admin.discord.settings.dailyCap') }}</label>
                            <input v-model.number="form.ask_daily_cap" type="number" min="1" max="5000" class="trading-input w-full" />
                        </div>
                        <div>
                            <label class="text-xs text-dark-400">{{ t('admin.discord.settings.perUserHour') }}</label>
                            <input v-model.number="form.ask_user_per_hour" type="number" min="1" max="120" class="trading-input w-full" />
                        </div>
                    </div>
                    <p class="text-[11px] text-dark-500">{{ t('admin.discord.settings.quotaHint') }}</p>

                    <div>
                        <label class="text-xs text-dark-400">{{ t('admin.discord.settings.rules') }}</label>
                        <textarea v-model="form.rules_text" rows="9" maxlength="3500" class="trading-input w-full text-xs leading-relaxed"></textarea>
                        <p v-if="form.errors.rules_text" class="text-xs text-trading-red mt-1">{{ form.errors.rules_text }}</p>
                    </div>

                    <button type="submit" class="btn-primary w-full" :disabled="form.processing">
                        {{ form.processing ? t('admin.discord.saving') : t('admin.discord.save') }}
                    </button>
                </form>

                <div class="space-y-5">
                    <!-- ผังห้อง -->
                    <div class="glass-dark rounded-xl p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-white">{{ t('admin.discord.channelMap.title') }}</h2>
                            <button type="button" class="px-2.5 py-1 rounded-lg text-[11px] bg-white/5 text-dark-200 hover:bg-white/10 disabled:opacity-50"
                                    :disabled="!!busy || !channels.length" @click="action('replan', 'replan')">
                                {{ t('admin.discord.channelMap.replan') }}
                            </button>
                        </div>
                        <p v-if="!channels.length" class="text-xs text-dark-400">{{ t('admin.discord.channelMap.empty') }}</p>
                        <div v-else class="space-y-2">
                            <div v-for="role in roles" :key="role.key" class="flex items-center gap-2">
                                <span class="w-40 shrink-0 text-xs text-dark-300">{{ role.label }}</span>
                                <select v-model="form.channel_map[role.key]" class="trading-input flex-1 text-xs">
                                    <option :value="undefined">{{ t('admin.discord.channelMap.none') }}</option>
                                    <option v-for="c in channels" :key="c.id" :value="c.id">{{ channelLabel(c) }}</option>
                                </select>
                            </div>
                            <p class="text-[11px] text-dark-500">{{ t('admin.discord.channelMap.hint') }}</p>
                        </div>
                    </div>

                    <!-- ตัวอย่างประกาศการขาย -->
                    <div class="glass-dark rounded-xl p-4 space-y-2">
                        <h2 class="text-sm font-semibold text-white">{{ t('admin.discord.sale.title') }}</h2>
                        <div class="rounded-lg border px-3 py-2 text-sm" :class="saleTone">
                            <p class="font-semibold">{{ salePreview.headline }}</p>
                            <p class="text-xs mt-1 opacity-90">{{ salePreview.detail }}</p>
                        </div>
                        <div v-for="p in salePreview.phases || []" :key="p.name" class="flex flex-wrap items-baseline justify-between gap-2 text-xs border-b border-white/5 py-1.5">
                            <span class="text-white">{{ p.name }} · {{ p.price }}</span>
                            <span class="text-dark-300">{{ p.status_label }} · {{ p.window }}</span>
                        </div>
                        <p class="text-[11px] text-dark-500">
                            {{ t('admin.discord.sale.hint') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ดูแลห้องอัตโนมัติ -->
            <div class="glass-dark rounded-xl p-4 space-y-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">🛡️ {{ t('admin.discord.mod.title') }}</h2>
                    <p class="text-[11px] text-dark-400 mt-1">
                        {{ t('admin.discord.mod.layer1') }}
                        · {{ t('admin.discord.mod.layer2', { days: moderation.window_days }) }}
                    </p>
                </div>

                <div class="grid lg:grid-cols-2 gap-4">
                    <form class="space-y-3" @submit.prevent="saveModeration">
                        <div>
                            <label class="text-xs text-dark-400">{{ t('admin.discord.mod.mode') }}</label>
                            <select v-model="modForm.mode" class="trading-input w-full text-sm">
                                <option value="off">{{ t('admin.discord.mod.modes.off') }}</option>
                                <option value="observe">{{ t('admin.discord.mod.modes.observe') }}</option>
                                <option value="enforce">{{ t('admin.discord.mod.modes.enforce') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-dark-400">{{ t('admin.discord.mod.logChannel') }}</label>
                            <select v-model="modForm.log_channel" class="trading-input w-full text-sm">
                                <option value="">{{ t('admin.discord.mod.notSelected') }}</option>
                                <option v-for="c in channels" :key="c.id" :value="c.id">{{ channelLabel(c) }}</option>
                            </select>
                            <p v-if="modForm.errors.log_channel" class="text-xs text-trading-red mt-1">{{ modForm.errors.log_channel }}</p>
                        </div>
                        <div class="grid grid-cols-4 gap-2">
                            <div><label class="text-[11px] text-dark-400">{{ t('admin.discord.mod.timeoutAt') }}</label><input v-model.number="modForm.timeout_at" type="number" min="1" class="trading-input w-full" /></div>
                            <div><label class="text-[11px] text-dark-400">{{ t('admin.discord.mod.kickAt') }}</label><input v-model.number="modForm.kick_at" type="number" min="1" class="trading-input w-full" /></div>
                            <div><label class="text-[11px] text-dark-400">{{ t('admin.discord.mod.banAt') }}</label><input v-model.number="modForm.ban_at" type="number" min="1" class="trading-input w-full" /></div>
                            <div><label class="text-[11px] text-dark-400">{{ t('admin.discord.mod.maxBans') }}</label><input v-model.number="modForm.max_bans_per_day" type="number" min="0" class="trading-input w-full" /></div>
                        </div>
                        <p v-for="k in ['mode', 'log_channel', 'timeout_at', 'kick_at', 'ban_at', 'max_bans_per_day']" v-show="modForm.errors[k]" :key="k" class="text-xs text-trading-red">{{ modForm.errors[k] }}</p>
                        <p class="text-[11px] text-dark-500">{{ t('admin.discord.mod.maxBansHint') }}</p>
                        <div class="text-[11px] text-dark-500 space-y-0.5">
                            <p>{{ t('admin.discord.mod.weightsTitle') }}</p>
                            <p v-for="w in moderation.weights || []" :key="w.name">· {{ w.name }} = {{ w.weight > 0 ? w.weight : t('admin.discord.mod.weightZero') }}</p>
                        </div>
                        <button type="submit" class="btn-primary w-full" :disabled="modForm.processing">{{ modForm.processing ? t('admin.discord.saving') : t('admin.discord.mod.save') }}</button>
                    </form>

                    <div class="space-y-3">
                        <div class="rounded-lg border border-white/10 p-3 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-white font-semibold">{{ t('admin.discord.mod.permTitle') }}</p>
                                <button type="button" class="px-2.5 py-1 rounded-lg text-[11px] bg-white/5 text-dark-200 hover:bg-white/10" :disabled="testLoading === 'mod-perm'" @click="checkModPermissions">
                                    {{ testLoading === 'mod-perm' ? t('admin.discord.checking') : t('admin.discord.mod.checkPerm') }}
                                </button>
                            </div>
                            <template v-if="modPerm">
                                <p v-if="!modPerm.missing.length" class="text-xs text-trading-green">✅ {{ t('admin.discord.mod.permOk') }}</p>
                                <p v-else class="text-xs text-amber-300">{{ t('admin.discord.mod.permMissing', { missing: modPerm.missing.join(' · ') }) }}</p>
                                <p v-if="modPerm.above_bot.length" class="text-[11px] text-amber-300">
                                    {{ t('admin.discord.mod.aboveBot', { roles: modPerm.above_bot.join(', '), bot: botName }) }}
                                </p>
                            </template>
                            <a v-if="moderation.upgrade_url" :href="moderation.upgrade_url" target="_blank" rel="noopener noreferrer"
                               class="inline-block px-3 py-1.5 rounded-lg text-xs bg-[#5865f2] text-white hover:bg-[#4752c4]">{{ t('admin.discord.mod.grantPerm') }} ↗</a>
                        </div>

                        <div class="rounded-lg border border-white/10 p-3 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-white font-semibold">{{ t('admin.discord.mod.automodTitle') }}</p>
                                <button type="button" class="px-2.5 py-1 rounded-lg text-[11px] bg-primary-500/20 text-primary-300 hover:bg-primary-500/30 disabled:opacity-50" :disabled="!!busy" @click="action('moderation/automod', 'automod')">
                                    {{ busy === 'automod' ? t('admin.discord.mod.installing') : (moderation.installed_at ? t('admin.discord.mod.updateRules') : t('admin.discord.mod.installRules')) }}
                                </button>
                            </div>
                            <p v-if="!(moderation.rules || []).length" class="text-[11px] text-dark-400">{{ t('admin.discord.mod.notInstalled') }}</p>
                            <div v-for="r in moderation.rules || []" :key="r.key" class="flex justify-between gap-2 text-xs">
                                <span class="text-dark-200">{{ r.name }}</span>
                                <span :class="r.ok ? 'text-trading-green' : 'text-trading-red'">{{ r.note }}</span>
                            </div>
                            <p class="text-[11px] text-dark-500">{{ t('admin.discord.mod.automodHint') }}</p>
                        </div>

                        <form class="rounded-lg border border-white/10 p-3 space-y-2" @submit.prevent="submitMember">
                            <p class="text-xs text-white font-semibold">{{ t('admin.discord.mod.manualTitle') }}</p>
                            <input v-model="memberForm.user_id" type="text" inputmode="numeric" class="trading-input w-full text-xs" :placeholder="t('admin.discord.mod.userIdPlaceholder')" />
                            <p v-if="memberForm.errors.user_id" class="text-xs text-trading-red">{{ memberForm.errors.user_id }}</p>
                            <div class="flex gap-2">
                                <select v-model="memberForm.action" class="trading-input text-xs">
                                    <option v-for="a in MOD_ACTIONS" :key="a" :value="a">{{ modActionLabel(a) }}</option>
                                </select>
                                <input v-model="memberForm.reason" type="text" maxlength="200" class="trading-input flex-1 text-xs" :placeholder="t('admin.discord.mod.reasonPlaceholder')" />
                            </div>
                            <p v-if="memberForm.errors.reason" class="text-xs text-trading-red">{{ memberForm.errors.reason }}</p>
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs bg-trading-red/20 text-trading-red hover:bg-trading-red/30 disabled:opacity-50" :disabled="memberForm.processing || !memberForm.user_id || !memberForm.reason">{{ t('admin.discord.mod.execute') }}</button>
                        </form>
                    </div>
                </div>

                <div class="grid lg:grid-cols-2 gap-4 text-xs">
                    <div>
                        <p class="text-dark-400 mb-1">{{ t('admin.discord.mod.strikesTitle', { time: when(moderation.last_run_at) }) }}</p>
                        <p v-if="!(moderation.strikes || []).length" class="text-dark-500">{{ t('admin.discord.mod.none') }}</p>
                        <div v-for="(s, i) in moderation.strikes || []" :key="i" class="flex justify-between gap-2 border-t border-white/5 py-1">
                            <span class="text-white font-mono">{{ s.user_id }}</span>
                            <span class="text-dark-300 truncate">{{ s.rule_name || '-' }} (+{{ s.weight }})</span>
                            <span class="text-dark-400 whitespace-nowrap">{{ when(s.occurred_at) }}</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-dark-400 mb-1">{{ t('admin.discord.mod.actionsTitle') }}</p>
                        <p v-if="!(moderation.actions || []).length" class="text-dark-500">{{ t('admin.discord.mod.none') }}</p>
                        <div v-for="(a, i) in moderation.actions || []" :key="i" class="flex flex-wrap justify-between gap-2 border-t border-white/5 py-1">
                            <span class="text-white font-mono">{{ a.user_id }}</span>
                            <span class="text-dark-200">{{ modActionLabel(a.action) }} · {{ t('admin.discord.mod.score', { score: a.score }) }}</span>
                            <span :class="modStatusLabel(a.status, a.mode)[1]">{{ modStatusLabel(a.status, a.mode)[0] }}<template v-if="a.error && a.status === 'failed'"> — {{ a.error }}</template></span>
                            <span class="text-dark-400 whitespace-nowrap">{{ when(a.created_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ผลโพสต์ล่าสุด -->
            <div class="glass-dark rounded-xl p-4">
                <h2 class="text-sm font-semibold text-white mb-3">{{ t('admin.discord.results.title') }}</h2>
                <p v-if="!status.last_sync?.length && !posts.length" class="text-xs text-dark-400">{{ t('admin.discord.results.neverPosted') }}</p>
                <div v-if="status.last_sync?.length" class="mb-4 space-y-1">
                    <div v-for="(r, i) in status.last_sync" :key="i" class="flex flex-wrap gap-2 text-xs">
                        <span class="text-white">{{ kindLabel(r.kind) }} {{ showsRef(r.kind) ? '#' + r.ref : '' }}</span>
                        <span :class="r.action === 'error' || r.action === 'no_channel' ? 'text-trading-red' : (r.action === 'skipped' ? 'text-amber-300' : 'text-trading-green')">{{ actionLabel(r.action) }}</span>
                        <span v-if="r.channel_id" class="text-dark-400">{{ channelName(r.channel_id) }}</span>
                        <span v-if="r.error" class="text-amber-300">{{ r.error }}</span>
                    </div>
                </div>
                <div v-if="posts.length" class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-dark-400">
                            <tr>
                                <th class="text-left py-1">{{ t('admin.discord.results.topic') }}</th>
                                <th class="text-left">{{ t('admin.discord.rooms.room') }}</th>
                                <th class="text-left">{{ t('admin.discord.results.postedAt') }}</th>
                                <th class="text-left">{{ t('admin.discord.results.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in posts" :key="p.kind + p.ref_key" class="border-t border-white/5">
                                <td class="py-1.5 text-white">{{ kindLabel(p.kind) }} {{ showsRef(p.kind) ? '#' + p.ref_key : '' }}</td>
                                <td class="text-dark-300">{{ channelName(p.channel_id) }}</td>
                                <td class="text-dark-300">{{ when(p.posted_at) }}</td>
                                <td :class="p.last_error ? 'text-amber-300' : 'text-trading-green'">{{ p.last_error || (p.message_id ? t('admin.discord.results.inChannel') : t('admin.discord.results.waiting')) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
