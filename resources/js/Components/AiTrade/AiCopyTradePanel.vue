<script setup>
/**
 * TPIX TRADE — แผง Master / Copy Trade ในหน้า AI TRADE
 *
 * สี่ส่วน: คำชวนเปิดตัวเป็นมาสเตอร์ (เมื่อผลเงินจริงผ่านเกณฑ์) · โปรไฟล์มาสเตอร์ของฉัน ·
 * กระดานมาสเตอร์ + ช่องแนะนำ · มาสเตอร์ที่ฉันตาม
 *
 * ถ้อยคำบนจอต้องไม่ทำให้เข้าใจผิดเรื่องเงิน:
 *  - ทุกตัวเลขผลงานติดป้ายว่าเป็นเงินจริงจากไม้ที่ปิดแล้ว (เซิร์ฟเวอร์ไม่ส่งผลโหมดทดลองมาเลย)
 *  - ช่อง "แนะนำ" ติดป้ายและบอกว่าเรียงตามส่วนแบ่งที่มาสเตอร์ให้แพลตฟอร์ม ไม่ใช่อันดับผลงาน
 *  - ก่อนกดตามต้องเห็นอัตราค่าบริการ เงื่อนไข high-water mark และคำเตือนว่าเป็นเงินจริง
 *  - ค่าบริการที่ยังไม่ถูกเก็บจริงต้องบอกว่า "ลงบัญชีไว้ให้เห็นเท่านั้น"
 *  - เปิดเก็บค่าบริการแล้ว: ค่าบริการหักจากยอด TPIX ที่เติมไว้ — ยอดไม่พอต้องเห็นคำเตือนพร้อมตัวเลขก่อนกดตาม
 *    (ปุ่มยืนยันถูกปิด) และระหว่างตามต้องเห็นว่าค้างเท่าไหร่ บอทหยุดเปิดไม้ใหม่เพราะอะไร
 *
 * กติกาทั้งหมด (ช่วงอัตรา · เกณฑ์ · ธง) มาจากเซิร์ฟเวอร์ — แผงนี้ไม่ฮาร์ดโค้ดตัวเลข และด่านจริงอยู่ที่ API
 *
 * Developed by Xman Studio
 */

import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AiCopyClip from '@/Components/AiTrade/AiCopyClip.vue';
import TpixTopupModal from '@/Components/Trading/TpixTopupModal.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { providerName, safeLink } from '@/utils/copyMedia';

const props = defineProps({
    /** ตัวเรียก API (useAiBot().copyTrade) — ทุกเมธอดคืน { ok, data, error } */
    api: { type: Object, required: true },
    /** กระเป๋าที่เชื่อมอยู่ (ว่าง = ยังไม่เชื่อม ดูได้แค่กระดาน) */
    wallet: { type: String, default: '' },
    limits: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['notify']);

const { t, locale } = useTranslation();

const board = ref(null);      // { enabled, live_ready, fees, requirements, masters, promoted }
const mine = ref(null);       // { master, invite, candidates, follows, kyc_approved }
const loadError = ref('');
const busy = ref(false);

const followTarget = ref(null);
const followRisk = ref({});
const showRegister = ref(false);
const registerForm = ref({ bot_id: null, display_name: '', bio: '', fee_rate_pct: 20, platform_share_pct: 20 });
const rateForm = ref({ fee_rate_pct: 20, platform_share_pct: 20 });

const enabled = computed(() => board.value?.enabled === true);
const liveReady = computed(() => board.value?.live_ready === true);
const fees = computed(() => board.value?.fees ?? {});
const masters = computed(() => board.value?.masters ?? []);
const promoted = computed(() => masters.value.filter((m) => m.promoted));
const myMaster = computed(() => (mine.value?.master && mine.value.master.status !== 'closed' ? mine.value.master : null));
const follows = computed(() => mine.value?.follows ?? []);
/** คลัง TPIX สำหรับค่าบริการของกระเป๋านี้ (null = ยังไม่เปิดเก็บค่าบริการ หรือยังไม่เชื่อมกระเป๋า) */
const feeBalance = computed(() => mine.value?.fee_balance ?? null);
const hasActiveFollow = computed(() => follows.value.some((f) => f.status === 'active'));
const showTopup = ref(false);
/** มาสเตอร์ที่ถูกส่งมาจากหน้าโปรไฟล์ (?master=) — การ์ดของคนนี้ถูกเน้นและเลื่อนมาให้เห็น */
const focusMaster = ref(null);
const eligibleBots = computed(() => (mine.value?.candidates ?? []).filter((c) => c.eligible));
/** บอทที่ใกล้ผ่านเกณฑ์ที่สุดที่ยังไม่ผ่าน — โชว์ว่ายังขาดอะไร (เฉพาะตอนยังไม่มีตัวที่ผ่าน) */
const progressBot = computed(() => (eligibleBots.value.length ? null : (mine.value?.candidates ?? [])[0] ?? null));

const riskFields = [
    { key: 'max_position_usd', labelKey: 'aiTrade.maxPosition' },
    { key: 'stop_loss_pct', labelKey: 'aiTrade.stopLoss' },
    { key: 'take_profit_pct', labelKey: 'aiTrade.takeProfit' },
    { key: 'max_daily_loss_usd', labelKey: 'aiTrade.maxDailyLoss' },
];

function signed(value, digits = 2) {
    const number = Number(value);
    if (!Number.isFinite(number)) return '—';

    return `${number > 0 ? '+' : ''}${number.toFixed(digits)}`;
}

/** จำนวน TPIX สำหรับแสดง — ไม่เกิน 2 ตำแหน่ง ไม่มีศูนย์ต่อท้าย */
function tpix(value) {
    const number = Number(value);

    return Number.isFinite(number) ? String(Math.round(number * 100) / 100) : '0';
}

async function load() {
    loadError.value = '';

    const result = await props.api.loadMasters();
    if (!result.ok) {
        loadError.value = result.error?.message || t('aiTrade.copy.loadFailed');
        return;
    }
    board.value = result.data;

    // ของกระเป๋านี้โหลดเมื่อเชื่อมกระเป๋าแล้วและระบบเปิดอยู่เท่านั้น
    if (props.wallet && result.data?.enabled) {
        const own = await props.api.loadMine();
        mine.value = own.ok ? own.data : null;

        if (own.ok && own.data?.master) {
            rateForm.value = { fee_rate_pct: own.data.master.fee_rate_pct, platform_share_pct: own.data.master.platform_share_pct };
        }
    } else {
        mine.value = null;
    }
}

onMounted(async () => {
    await load();

    // มาจากหน้าโปรไฟล์มาสเตอร์ — เลื่อนไปที่การ์ดของคนนั้น (การกดตามยังต้องกดเองและผ่านฟอร์มยืนยัน)
    const id = Number(new URLSearchParams(window.location.search).get('master'));
    if (id && masters.value.some((m) => m.id === id)) {
        focusMaster.value = id;
        await nextTick();
        document.querySelector(`[data-testid="copy-master-${id}"]`)?.scrollIntoView?.({ block: 'center' });
    }
});
// สลับกระเป๋า = ของคนก่อนต้องหายทันที แล้วโหลดของกระเป๋าใหม่
watch(() => props.wallet, () => {
    mine.value = null;
    followTarget.value = null;
    showRegister.value = false;
    showTopup.value = false;
    // ร่างที่พิมพ์ค้างเป็นของกระเป๋าก่อนหน้า — ห้ามตามไปบันทึกลงโปรไฟล์ของกระเป๋าใหม่
    introDraft.value = null;
    featuredForm.value = { url: '', title: '' };
    load();
});

/** เรียก API หนึ่งครั้ง กันกดซ้ำ แล้วโหลดใหม่ — คืน true เมื่อสำเร็จ */
async function act(call, okMessage) {
    if (busy.value) return false;
    busy.value = true;

    try {
        const result = await call();

        if (!result?.ok) {
            emit('notify', { type: 'error', text: result?.error?.message || t('aiTrade.copy.failed') });
            return false;
        }

        if (okMessage) emit('notify', { type: 'success', text: okMessage });
        await load();

        return true;
    } finally {
        busy.value = false;
    }
}

// ── ตามมาสเตอร์ ──────────────────────────────────────────────────────────────

function openFollow(master) {
    followTarget.value = master;
    followRisk.value = Object.fromEntries(riskFields.map(({ key }) => [key, props.limits?.[key]?.default ?? 0]));
}

/**
 * ยอด TPIX ที่ต้องมีก่อนกดตามมาสเตอร์คนนี้ด้วยทุนที่ตั้งอยู่ — สูตรเดียวกับด่านของเซิร์ฟเวอร์ (FeeCollector)
 * ใช้เตือนและปิดปุ่มล่วงหน้าเท่านั้น ด่านจริงอยู่ที่ API
 */
const followNeed = computed(() => {
    if (!fees.value.collect || !followTarget.value) return null;

    const price = Number(fees.value.tpix_usd_price);
    const cap = Number(followRisk.value.max_position_usd) || 0;
    const reserve = price > 0
        ? cap * (Number(fees.value.reserve_profit_pct) || 0) / 100 * (Number(followTarget.value.fee_rate_pct) || 0) / 100 / price
        : 0;
    const owed = Number(feeBalance.value?.owed_tpix ?? 0);
    const required = Math.max(Number(fees.value.min_balance_tpix ?? 0), reserve) + owed;
    const balance = Number(feeBalance.value?.balance_tpix ?? 0);

    return {
        required,
        balance,
        short: Math.max(0, required - balance),
        ok: balance + 1e-6 >= required && !(Number(feeBalance.value?.owed_count ?? 0) > 0),
    };
});

async function confirmFollow() {
    const master = followTarget.value;
    if (!master || (followNeed.value && !followNeed.value.ok)) return;

    const ok = await act(() => props.api.follow({
        master_id: master.id,
        // อัตราที่ผู้ตาม "เห็นบนจอ" ตอนกด — เซิร์ฟเวอร์ปฏิเสธถ้ามาสเตอร์เพิ่งเปลี่ยนอัตรา
        accept_fee_rate_pct: master.fee_rate_pct,
        risk: { ...followRisk.value },
    }), t('aiTrade.copy.followOk', { name: master.display_name }));

    if (ok) followTarget.value = null;
}

function stopFollow(follow) {
    if (!window.confirm(t('aiTrade.copy.stopConfirm', { name: follow.master?.display_name ?? '' }))) return;

    act(() => props.api.stopFollow(follow.id));
}

function acceptRate(follow) {
    act(() => props.api.acceptRate(follow.id));
}

// ── มาสเตอร์ ─────────────────────────────────────────────────────────────────

function openRegister() {
    registerForm.value = {
        bot_id: eligibleBots.value[0]?.bot_id ?? null,
        display_name: '',
        bio: '',
        fee_rate_pct: fees.value.rate_pct?.default ?? 20,
        platform_share_pct: fees.value.platform_share_pct?.default ?? 20,
    };
    showRegister.value = true;
}

async function submitRegister() {
    const ok = await act(() => props.api.registerMaster({ ...registerForm.value, display_name: registerForm.value.display_name.trim() }), t('aiTrade.copy.registerOk'));

    if (ok) showRegister.value = false;
}

function saveRates() {
    act(() => props.api.updateMaster({ ...rateForm.value }), t('aiTrade.copy.savedOk'));
}

// ── หน้าโปรไฟล์: คำแนะนำตัว + คลิปแนะนำ ─────────────────────────────────────

const introDraft = ref(null);      // null = ยังไม่ได้แตะ ใช้ค่าจากเซิร์ฟเวอร์
const introText = computed({
    get: () => introDraft.value ?? myMaster.value?.intro ?? '',
    set: (value) => { introDraft.value = value; },
});
const featuredForm = ref({ url: '', title: '' });
const featuredItems = computed(() => (myMaster.value?.media?.items ?? []).filter((item) => item.featured));
const libraryItems = computed(() => (myMaster.value?.media?.items ?? []).filter((item) => !item.featured));
const featuredFull = computed(() => featuredItems.value.length >= Number(myMaster.value?.media?.featured_max ?? 0));

async function saveIntro() {
    const ok = await act(() => props.api.updateMaster({ intro: introText.value.trim() || null }), t('aiTrade.copy.savedOk'));

    if (ok) introDraft.value = null;
}

async function addFeatured() {
    const ok = await act(
        () => props.api.addMedia({ kind: 'clip', url: featuredForm.value.url.trim(), title: featuredForm.value.title, featured: true }),
        t('aiTrade.copy.featuredAdded'),
    );

    if (ok) featuredForm.value = { url: '', title: '' };
}

function toggleFeatured(item) {
    act(() => props.api.featureMedia(item.id, !item.featured));
}

function toggleAccepting() {
    act(() => props.api.updateMaster({ status: myMaster.value.status === 'active' ? 'paused' : 'active' }));
}

function closeMaster() {
    if (!window.confirm(t('aiTrade.copy.closeConfirm'))) return;

    act(() => props.api.closeMaster());
}

/** ตัวอย่างการแบ่งค่าบริการจากกำไรผู้ตาม 100 — ให้มาสเตอร์เห็นผลของตัวเลขที่ตั้งก่อนกดยืนยัน */
function splitText(form) {
    const fee = Number(form.fee_rate_pct) || 0;
    const platform = fee * (Number(form.platform_share_pct) || 0) / 100;

    return t('aiTrade.copy.registerSplit', { fee: fee.toFixed(2), you: (fee - platform).toFixed(2), platform: platform.toFixed(2) });
}

function checkText(check) {
    return t(`aiTrade.copy.check_${check.code}`, { need: check.need, have: check.have });
}

// ── ระดับและดาว ──────────────────────────────────────────────────────────────

/** ชื่อระดับตามภาษา — ชื่อมาจากเซิร์ฟเวอร์ (config) เปลี่ยนได้โดยไม่แตะหน้าเว็บ */
function rankName(master) {
    if (!master.rank) return t('aiTrade.copy.rankNone');

    return (locale.value === 'th' ? master.rank.name_th : master.rank.name_en) || master.rank.key;
}

function ratingText(rating) {
    if (!rating?.count) return t('aiTrade.copy.ratingNone');

    return t('aiTrade.copy.ratingText', { avg: Number(rating.avg).toFixed(1), count: rating.count });
}

const openReviews = ref(null);     // รหัสมาสเตอร์ที่กำลังเปิดดูรีวิว
const reviewList = ref([]);
const reviewDrafts = ref({});      // { [followId]: { stars, comment } }

async function toggleReviews(master) {
    if (openReviews.value === master.id) {
        openReviews.value = null;
        return;
    }

    const result = await props.api.loadReviews(master.id);
    reviewList.value = result.ok ? (result.data?.reviews ?? []) : [];
    openReviews.value = master.id;
}

async function reportReview(review) {
    if (!window.confirm(t('aiTrade.copy.reportConfirm'))) return;
    if (busy.value) return;

    busy.value = true;
    try {
        const result = await props.api.reportReview(review.id);
        emit('notify', result.ok
            ? { type: 'success', text: t('aiTrade.copy.reported') }
            : { type: 'error', text: result.error?.message || t('aiTrade.copy.failed') });
    } finally {
        busy.value = false;
    }
}

function draftFor(follow) {
    if (!reviewDrafts.value[follow.id]) {
        reviewDrafts.value[follow.id] = { stars: follow.my_review?.stars ?? 0, comment: follow.my_review?.comment ?? '' };
    }

    return reviewDrafts.value[follow.id];
}

// ── ไลฟ์ / คลิป ──────────────────────────────────────────────────────────────

// ต้นทางที่ยอมให้เป็นลิงก์/กรอบวิดีโอถูกตรวจซ้ำที่หน้าเว็บ (utils/copyMedia · AiCopyClip) แม้เซิร์ฟเวอร์จะประกอบลิงก์เอง

const openMedia = ref(null);        // รหัสมาสเตอร์ที่กำลังเปิดดูไลฟ์/คลิป
const mediaList = ref([]);
const mediaEmbed = ref(false);      // เซิร์ฟเวอร์อนุญาตให้ฝังเครื่องเล่นในหน้าไหม
const mediaForm = ref({ kind: 'clip', url: '', title: '' });

async function toggleMedia(master) {
    if (openMedia.value === master.id) {
        openMedia.value = null;
        return;
    }

    const result = await props.api.loadMedia(master.id);
    // รายการที่ลิงก์ไม่ผ่านการตรวจต้นทางถูกทิ้งทั้งรายการ
    mediaList.value = result.ok ? (result.data?.items ?? []).filter((item) => safeLink(item.url)) : [];
    mediaEmbed.value = result.ok && result.data?.embed === true;
    openMedia.value = master.id;
}

async function addMedia() {
    const ok = await act(() => props.api.addMedia({ ...mediaForm.value, url: mediaForm.value.url.trim() }), t('aiTrade.copy.mediaAdded'));

    if (ok) mediaForm.value = { kind: 'clip', url: '', title: '' };
}

function removeMedia(item) {
    act(() => props.api.removeMedia(item.id));
}

function mediaLockedText(media) {
    return t('aiTrade.copy.mediaLocked', {
        tierNeed: media.tier?.need, tierHave: media.tier?.have,
        revNeed: Number(media.revenue_usd?.need ?? 0).toFixed(0), revHave: Number(media.revenue_usd?.have ?? 0).toFixed(2),
    });
}

async function submitReview(follow) {
    const draft = draftFor(follow);
    if (!draft.stars || !follow.master) return;

    const ok = await act(() => props.api.review(follow.master.id, { stars: draft.stars, comment: draft.comment || null }), t('aiTrade.copy.reviewOk'));

    if (ok) delete reviewDrafts.value[follow.id];
}
</script>

<template>
    <!-- id = ปลายทางของลิงก์ #copy-trade จากหน้าโปรไฟล์มาสเตอร์ -->
    <section id="copy-trade" class="glass-dark rounded-2xl p-5 space-y-4 scroll-mt-24" data-testid="copy-panel">
        <div>
            <h2 class="text-base font-bold text-white">{{ t('aiTrade.copy.title') }}</h2>
            <p class="text-xs text-dark-400 leading-relaxed">{{ t('aiTrade.copy.intro') }}</p>
        </div>

        <p v-if="loadError" class="text-xs text-trading-red" data-testid="copy-load-error">
            {{ loadError }}
            <button type="button" class="underline ml-1" @click="load">{{ t('aiTrade.copy.retry') }}</button>
        </p>

        <p v-else-if="board && !enabled" class="text-xs text-dark-400" data-testid="copy-disabled">{{ t('aiTrade.copy.disabled') }}</p>

        <template v-else-if="board">
            <p class="text-[11px] text-amber-200/90 leading-relaxed" data-testid="copy-real-only">{{ t('aiTrade.copy.realOnly') }}</p>
            <p v-if="!liveReady" class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200 leading-relaxed" data-testid="copy-needs-live">
                {{ t('aiTrade.copy.needsLive') }}
            </p>

            <!-- คลัง TPIX สำหรับค่าบริการ — ยอด · ที่ค้าง · คำเตือนเมื่อบอทหยุดเปิดไม้ใหม่ -->
            <div
                v-if="feeBalance"
                :class="['rounded-xl border p-3 space-y-1', feeBalance.owed_count || (feeBalance.blocked && hasActiveFollow) ? 'border-trading-red/40 bg-trading-red/10' : 'border-white/10 bg-white/5']"
                data-testid="copy-fee-balance"
            >
                <p class="text-xs text-white">
                    {{ t('aiTrade.copy.feeBalanceTitle') }}:
                    <span class="font-mono font-bold">{{ tpix(feeBalance.balance_tpix) }} {{ fees.currency ?? 'TPIX' }}</span>
                    <button type="button" class="ml-2 underline text-primary-300 hover:text-primary-200" data-testid="copy-fee-topup" @click="showTopup = true">
                        {{ t('aiTrade.copy.feeTopup') }}
                    </button>
                </p>
                <p v-if="feeBalance.owed_count" class="text-[11px] text-trading-red leading-relaxed" data-testid="copy-fee-owed">
                    {{ t('aiTrade.copy.feeOwed', { owed: tpix(feeBalance.owed_tpix), short: tpix(feeBalance.shortfall_tpix) }) }}
                </p>
                <p v-else-if="feeBalance.blocked && hasActiveFollow" class="text-[11px] text-trading-red leading-relaxed" data-testid="copy-fee-low">
                    {{ t('aiTrade.copy.feeLow', { min: tpix(feeBalance.minimum_tpix), short: tpix(feeBalance.shortfall_tpix) }) }}
                </p>
                <p v-else class="text-[11px] text-dark-400 leading-relaxed">{{ t('aiTrade.copy.feeBalanceNote', { min: tpix(feeBalance.minimum_tpix) }) }}</p>
            </div>

            <!-- คำชวนเปิดตัวเป็นมาสเตอร์ — ขึ้นเมื่อมีบอทที่ผลเงินจริงผ่านเกณฑ์ -->
            <div v-if="mine?.invite && !showRegister" class="rounded-xl border border-primary-500/40 bg-primary-500/10 p-3 space-y-2" data-testid="copy-invite">
                <p class="text-sm font-bold text-white">{{ t('aiTrade.copy.inviteTitle') }}</p>
                <p class="text-xs text-dark-300 leading-relaxed">{{ t('aiTrade.copy.inviteBody') }}</p>
                <p v-if="!mine.kyc_approved" class="text-[11px] text-amber-300" data-testid="copy-kyc-needed">{{ t('aiTrade.copy.kycNeeded') }}</p>
                <button type="button" class="btn-brand px-4 py-1.5 text-xs" data-testid="copy-become-master" @click="openRegister">
                    {{ t('aiTrade.copy.becomeMaster') }}
                </button>
            </div>

            <!-- ยังไม่ผ่านเกณฑ์: บอกว่าขาดอะไร (เฉพาะบอทที่มีไม้เงินจริงแล้ว) -->
            <div v-else-if="progressBot && !myMaster" class="rounded-xl border border-white/10 bg-white/5 p-3" data-testid="copy-progress">
                <p class="text-xs font-bold text-white mb-1">{{ t('aiTrade.copy.progressTitle') }} — {{ progressBot.name }}</p>
                <ul class="space-y-0.5 text-[11px]">
                    <li v-for="check in progressBot.checks" :key="check.code" :class="check.ok ? 'text-trading-green' : 'text-dark-300'">
                        {{ check.ok ? '✓' : '○' }} {{ checkText(check) }}
                    </li>
                </ul>
            </div>

            <!-- ฟอร์มเปิดตัวเป็นมาสเตอร์ -->
            <form v-if="showRegister" class="rounded-xl border border-white/10 bg-white/5 p-3 space-y-3" data-testid="copy-register" @submit.prevent="submitRegister">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerBot') }}</span>
                        <select v-model.number="registerForm.bot_id" class="trading-input text-sm" data-testid="copy-register-bot">
                            <option v-for="c in eligibleBots" :key="c.bot_id" :value="c.bot_id">{{ c.name }} · {{ c.pair }} · {{ signed(c.record.return_pct) }}%</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerName') }}</span>
                        <input v-model="registerForm.display_name" type="text" class="trading-input text-sm" :maxlength="board.requirements?.max_name_length ?? 40" data-testid="copy-register-name">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerBio') }}</span>
                        <input v-model="registerForm.bio" type="text" class="trading-input text-sm" :maxlength="board.requirements?.max_bio_length ?? 280">
                    </label>
                    <label class="block">
                        <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerRate') }} ({{ fees.rate_pct?.min }}–{{ fees.rate_pct?.max }})</span>
                        <input v-model.number="registerForm.fee_rate_pct" type="number" class="trading-input text-sm font-mono" :min="fees.rate_pct?.min" :max="fees.rate_pct?.max" step="1" data-testid="copy-register-rate">
                    </label>
                    <label class="block">
                        <span class="block text-[11px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerShare') }} ({{ fees.platform_share_pct?.min }}–{{ fees.platform_share_pct?.max }})</span>
                        <input v-model.number="registerForm.platform_share_pct" type="number" class="trading-input text-sm font-mono" :min="fees.platform_share_pct?.min" :max="fees.platform_share_pct?.max" step="1" data-testid="copy-register-share">
                    </label>
                </div>
                <p class="text-[11px] text-dark-300 font-mono" data-testid="copy-register-split">{{ splitText(registerForm) }}</p>
                <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.copy.registerShareNote') }}</p>
                <p v-if="!mine?.kyc_approved" class="text-[11px] text-amber-300">{{ t('aiTrade.copy.kycNeeded') }}</p>
                <div class="flex gap-2">
                    <button type="submit" class="btn-brand px-4 py-1.5 text-xs disabled:opacity-50" :disabled="busy || !registerForm.bot_id || registerForm.display_name.trim().length < 3" data-testid="copy-register-submit">
                        {{ t('aiTrade.copy.registerSubmit') }}
                    </button>
                    <button type="button" class="btn-secondary px-4 py-1.5 text-xs" @click="showRegister = false">{{ t('aiTrade.copy.cancel') }}</button>
                </div>
            </form>

            <!-- โปรไฟล์มาสเตอร์ของฉัน -->
            <div v-if="myMaster" class="rounded-xl border border-white/10 bg-white/5 p-3 space-y-2" data-testid="copy-my-master">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <p class="text-sm font-bold text-white">{{ t('aiTrade.copy.myMaster') }} — {{ myMaster.display_name }}</p>
                    <span class="text-[11px] text-dark-400">{{ t('aiTrade.copy.myFollowers', { n: myMaster.followers }) }}</span>
                    <span v-if="myMaster.status === 'paused'" class="text-[11px] text-amber-300">{{ t('aiTrade.copy.statusPaused') }}</span>
                </div>
                <p class="text-[11px] text-dark-300">
                    {{ t('aiTrade.copy.myEarnings') }}: <span class="font-mono text-white">${{ Number(myMaster.earnings?.master_usd ?? 0).toFixed(2) }}</span>
                    <span class="text-dark-500" data-testid="copy-my-earnings-note">
                        — {{ myMaster.earnings?.collect
                            ? t('aiTrade.copy.earningsPaid', { paid: tpix(myMaster.earnings.master_tpix_paid), pending: tpix(myMaster.earnings.master_tpix_pending) })
                            : t('aiTrade.copy.feesRecorded') }}
                    </span>
                </p>
                <div class="grid gap-2 sm:grid-cols-3 items-end">
                    <label class="block">
                        <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerRate') }}</span>
                        <input v-model.number="rateForm.fee_rate_pct" type="number" class="trading-input text-xs py-1.5 font-mono" :min="fees.rate_pct?.min" :max="fees.rate_pct?.max" data-testid="copy-rate-input">
                    </label>
                    <label class="block">
                        <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.registerShare') }}</span>
                        <input v-model.number="rateForm.platform_share_pct" type="number" class="trading-input text-xs py-1.5 font-mono" :min="fees.platform_share_pct?.min" :max="fees.platform_share_pct?.max">
                    </label>
                    <button type="button" class="btn-secondary px-3 py-1.5 text-xs disabled:opacity-50" :disabled="busy" data-testid="copy-rate-save" @click="saveRates">{{ t('aiTrade.copy.editRate') }}</button>
                </div>
                <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.copy.editRateNote') }}</p>
                <!-- หน้าโปรไฟล์ของฉัน: คำแนะนำตัว + คลิปแนะนำ (มาสเตอร์ทุกคนตั้งได้ ไม่ต้องรอปลดล็อก) -->
                <div class="rounded-lg bg-dark-900/40 p-2.5 space-y-2" data-testid="copy-my-profile">
                    <p class="text-xs font-bold text-white">
                        {{ t('aiTrade.copy.profileMine') }}
                        <a :href="`/ai-trade/master/${myMaster.id}`" class="ml-2 font-normal underline text-primary-300 hover:text-primary-200" data-testid="copy-my-profile-link">{{ t('aiTrade.copy.profileOpenMine') }}</a>
                    </p>
                    <label class="block">
                        <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.introLabel') }}</span>
                        <textarea v-model="introText" rows="4" class="trading-input text-xs" :maxlength="board.requirements?.max_intro_length ?? 1500" data-testid="copy-intro-input" />
                    </label>
                    <button type="button" class="btn-secondary px-3 py-1.5 text-xs disabled:opacity-50" :disabled="busy || introDraft === null" data-testid="copy-intro-save" @click="saveIntro">
                        {{ t('aiTrade.copy.introSave') }}
                    </button>

                    <template v-if="myMaster.media">
                        <p class="text-xs font-bold text-white pt-1">
                            {{ t('aiTrade.copy.featuredTitle') }}
                            <span class="text-[10px] text-dark-500 font-mono font-normal ml-1" data-testid="copy-featured-count">{{ featuredItems.length }}/{{ myMaster.media.featured_max }}</span>
                        </p>
                        <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.copy.featuredNote', { max: myMaster.media.featured_max }) }}</p>
                        <form v-if="!myMaster.media.revoked && !featuredFull" class="grid gap-2 sm:grid-cols-4 items-end" data-testid="copy-featured-form" @submit.prevent="addFeatured">
                            <label class="block sm:col-span-2">
                                <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.mediaUrl') }}</span>
                                <input v-model="featuredForm.url" type="url" maxlength="300" class="trading-input text-xs py-1.5" placeholder="https://" data-testid="copy-featured-url">
                            </label>
                            <label class="block">
                                <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.mediaTitle') }}</span>
                                <input v-model="featuredForm.title" type="text" maxlength="120" class="trading-input text-xs py-1.5" data-testid="copy-featured-title">
                            </label>
                            <button type="submit" class="btn-secondary px-3 py-1.5 text-xs disabled:opacity-50" :disabled="busy || !featuredForm.url.trim()" data-testid="copy-featured-add">{{ t('aiTrade.copy.featuredAdd') }}</button>
                        </form>
                    </template>
                </div>

                <div v-if="myMaster.media" class="rounded-lg bg-dark-900/40 p-2.5 space-y-2" data-testid="copy-my-media">
                    <p class="text-xs font-bold text-white">
                        {{ t('aiTrade.copy.mediaMine') }}
                        <span class="text-[10px] text-dark-500 font-mono font-normal ml-1">{{ t('aiTrade.copy.mediaCount', { used: libraryItems.length, max: myMaster.media.max_items }) }}</span>
                    </p>
                    <p v-if="myMaster.media.revoked" class="text-[11px] text-amber-300 leading-relaxed" data-testid="copy-media-revoked">{{ t('aiTrade.copy.mediaRevoked') }}</p>
                    <p v-else-if="!myMaster.media.unlocked" class="text-[11px] text-dark-400 leading-relaxed" data-testid="copy-media-locked">{{ mediaLockedText(myMaster.media) }}</p>
                    <form v-else class="grid gap-2 sm:grid-cols-4 items-end" data-testid="copy-media-form" @submit.prevent="addMedia">
                        <label class="block">
                            <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.mediaKind') }}</span>
                            <select v-model="mediaForm.kind" class="trading-input text-xs py-1.5">
                                <option value="clip">{{ t('aiTrade.copy.mediaClip') }}</option>
                                <option value="live">{{ t('aiTrade.copy.mediaLive') }}</option>
                            </select>
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.mediaUrl') }}</span>
                            <input v-model="mediaForm.url" type="url" maxlength="300" class="trading-input text-xs py-1.5" placeholder="https://" data-testid="copy-media-url">
                        </label>
                        <label class="block">
                            <span class="block text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.mediaTitle') }}</span>
                            <input v-model="mediaForm.title" type="text" maxlength="120" class="trading-input text-xs py-1.5" data-testid="copy-media-title">
                        </label>
                        <button type="submit" class="btn-secondary px-3 py-1.5 text-xs disabled:opacity-50" :disabled="busy || !mediaForm.url.trim()" data-testid="copy-media-add">{{ t('aiTrade.copy.mediaAdd') }}</button>
                    </form>
                    <ul v-if="myMaster.media.items.length" class="space-y-1">
                        <li v-for="item in myMaster.media.items" :key="item.id" class="text-[11px] text-dark-200" :data-testid="`copy-my-media-item-${item.id}`">
                            <span class="px-1 rounded bg-dark-700 text-[9px]">{{ item.kind === 'live' ? t('aiTrade.copy.mediaLive') : t('aiTrade.copy.mediaClip') }}</span>
                            <span class="ml-1">{{ item.title }}</span>
                            <span class="text-dark-500 ml-1">{{ providerName(item.provider) }}</span>
                            <span v-if="item.featured" class="ml-1 px-1 rounded bg-primary-500/15 text-primary-200 text-[9px]">{{ t('aiTrade.copy.featuredBadge') }}</span>
                            <span v-if="item.status === 'hidden'" class="text-amber-300 ml-1">{{ t('aiTrade.copy.mediaHidden') }}</span>
                            <button
                                v-if="item.kind === 'clip' && (item.featured || (!featuredFull && !myMaster.media.revoked))"
                                type="button"
                                class="ml-2 text-primary-300 hover:text-primary-200"
                                :disabled="busy"
                                :data-testid="`copy-media-feature-${item.id}`"
                                @click="toggleFeatured(item)"
                            >
                                {{ item.featured ? t('aiTrade.copy.featuredOff') : t('aiTrade.copy.featuredOn') }}
                            </button>
                            <button type="button" class="ml-2 text-dark-500 hover:text-trading-red" :disabled="busy" :data-testid="`copy-media-remove-${item.id}`" @click="removeMedia(item)">{{ t('aiTrade.copy.mediaRemove') }}</button>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-wrap gap-3 text-[11px]">
                    <button type="button" class="text-primary-300 hover:text-primary-200" :disabled="busy" data-testid="copy-toggle-accepting" @click="toggleAccepting">
                        {{ myMaster.status === 'active' ? t('aiTrade.copy.pause') : t('aiTrade.copy.resume') }}
                    </button>
                    <button type="button" class="text-dark-400 hover:text-trading-red" :disabled="busy" data-testid="copy-close-master" @click="closeMaster">{{ t('aiTrade.copy.close') }}</button>
                </div>
            </div>

            <!-- ช่องแนะนำ — ติดป้ายและบอกว่าเรียงตามอะไร -->
            <div v-if="promoted.length" class="rounded-xl border border-amber-500/25 bg-amber-500/5 p-3" data-testid="copy-promoted">
                <p class="text-xs font-bold text-amber-200">{{ t('aiTrade.copy.promotedTitle') }}</p>
                <p class="text-[10px] text-dark-400 leading-relaxed mb-2">{{ t('aiTrade.copy.promotedNote') }}</p>
                <div class="flex flex-wrap gap-2">
                    <span v-for="m in promoted" :key="m.id" class="rounded-lg bg-dark-900/50 px-2.5 py-1 text-xs text-white">
                        {{ m.display_name }} <span class="font-mono text-dark-300">{{ signed(m.record?.return_pct) }}%</span>
                    </span>
                </div>
            </div>

            <!-- กระดานมาสเตอร์ — เรียงตามผลงานล้วน -->
            <div>
                <p class="text-xs font-bold text-white">{{ t('aiTrade.copy.boardTitle') }}</p>
                <p class="text-[10px] text-dark-500 mb-2">{{ t('aiTrade.copy.boardNote') }}</p>

                <p v-if="!masters.length" class="text-xs text-dark-400" data-testid="copy-empty">{{ t('aiTrade.copy.noMasters') }}</p>

                <ul v-else class="space-y-2">
                    <li
                        v-for="(m, index) in masters"
                        :key="m.id"
                        :class="['rounded-xl border bg-white/5 p-3', focusMaster === m.id ? 'border-primary-500/60 ring-1 ring-primary-500/40' : 'border-white/10']"
                        :data-testid="`copy-master-${m.id}`"
                    >
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="text-[10px] text-dark-500 font-mono">#{{ index + 1 }}</span>
                            <span class="text-sm font-bold text-white">{{ m.display_name }}</span>
                            <span class="text-[10px] text-dark-500 font-mono">{{ m.wallet_short }}</span>
                            <span
                                :class="['text-[10px] px-1.5 py-0.5 rounded font-bold', m.rank ? 'bg-primary-500/15 text-primary-200' : 'bg-dark-700 text-dark-400']"
                                :title="t('aiTrade.copy.rankNote')"
                                :data-testid="`copy-rank-${m.id}`"
                            >
                                {{ rankName(m) }}
                            </span>
                            <span class="text-[10px] text-amber-200/90" :data-testid="`copy-rating-${m.id}`">★ {{ ratingText(m.rating) }}</span>
                            <span v-if="m.promoted" class="text-[9px] px-1 rounded bg-amber-500/15 text-amber-300">{{ t('aiTrade.copy.promotedBadge') }}</span>
                            <span v-if="!m.meets_floor" class="text-[10px] text-amber-300">{{ t('aiTrade.copy.belowFloor') }}</span>
                            <span class="text-[10px] text-dark-400 ml-auto font-mono">{{ m.pair }} · {{ m.timeframe }}</span>
                        </div>
                        <p v-if="m.bio" class="text-[11px] text-dark-400 leading-relaxed mt-0.5">{{ m.bio }}</p>

                        <dl class="grid grid-cols-3 sm:grid-cols-7 gap-2 mt-2 text-xs">
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colReturn') }}</dt><dd :class="['font-mono', Number(m.record?.return_pct) < 0 ? 'text-trading-red' : 'text-white']">{{ signed(m.record?.return_pct) }}%</dd></div>
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colTrades') }}</dt><dd class="font-mono text-white">{{ m.record?.closed ?? 0 }}</dd></div>
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colWin') }}</dt><dd class="font-mono text-white">{{ m.record?.win_rate ?? '—' }}%</dd></div>
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colDrawdown') }}</dt><dd class="font-mono text-white">-{{ Number(m.record?.max_drawdown_pct ?? 0).toFixed(2) }}%</dd></div>
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colDays') }}</dt><dd class="font-mono text-white">{{ t('aiTrade.copy.days', { n: Math.floor(m.record?.days ?? 0) }) }}</dd></div>
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colFollowers') }}</dt><dd class="font-mono text-white">{{ m.followers }}</dd></div>
                            <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colFee') }}</dt><dd class="font-mono text-white">{{ t('aiTrade.copy.feeOfProfit', { rate: m.fee_rate_pct }) }}</dd></div>
                        </dl>

                        <div class="mt-2">
                            <span v-if="!m.accepting" class="text-[11px] text-dark-400">{{ t('aiTrade.copy.notAccepting') }}</span>
                            <button
                                v-else-if="wallet && myMaster?.id !== m.id"
                                type="button"
                                class="btn-secondary px-3 py-1 text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                                :disabled="!liveReady || busy"
                                :data-testid="`copy-follow-${m.id}`"
                                @click="openFollow(m)"
                            >
                                {{ t('aiTrade.copy.follow') }}
                            </button>
                            <a :href="`/ai-trade/master/${m.id}`" class="ml-3 text-[11px] text-primary-300 hover:text-primary-200" :data-testid="`copy-profile-link-${m.id}`">
                                {{ t('aiTrade.copy.viewProfile') }}
                            </a>
                            <button
                                v-if="m.rating?.count"
                                type="button"
                                class="ml-3 text-[11px] text-primary-300 hover:text-primary-200"
                                :data-testid="`copy-reviews-toggle-${m.id}`"
                                @click="toggleReviews(m)"
                            >
                                {{ openReviews === m.id ? t('aiTrade.copy.hideReviews') : t('aiTrade.copy.showReviews') }}
                            </button>
                            <button
                                v-if="m.media_count"
                                type="button"
                                class="ml-3 text-[11px] text-primary-300 hover:text-primary-200"
                                :data-testid="`copy-media-toggle-${m.id}`"
                                @click="toggleMedia(m)"
                            >
                                {{ openMedia === m.id ? t('aiTrade.copy.mediaHide') : t('aiTrade.copy.mediaToggle', { n: m.media_count }) }}
                            </button>
                        </div>

                        <!-- ไลฟ์/คลิป — ลิงก์ออกไปช่องของมาสเตอร์ (ผ่านการตรวจต้นทางแล้วเท่านั้น) ชื่อเรื่องแสดงแบบ escape -->
                        <div v-if="openMedia === m.id" class="mt-2 rounded-lg bg-dark-900/40 p-2.5 space-y-1.5" :data-testid="`copy-media-${m.id}`">
                            <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.copy.mediaExternalNote') }}</p>
                            <AiCopyClip v-for="item in mediaList" :key="item.id" :item="item" :embed="mediaEmbed" />
                        </div>

                        <!-- รีวิว — ข้อความแสดงแบบ escape เสมอ (interpolation ธรรมดา ห้ามใช้ v-html กับข้อความจากผู้ใช้) -->
                        <div v-if="openReviews === m.id" class="mt-2 rounded-lg bg-dark-900/40 p-2.5" :data-testid="`copy-reviews-${m.id}`">
                            <p class="text-[10px] text-dark-400 mb-1">{{ t('aiTrade.copy.reviewsFrom') }}</p>
                            <p v-if="!reviewList.length" class="text-[11px] text-dark-400">{{ t('aiTrade.copy.noReviews') }}</p>
                            <ul v-else class="space-y-1.5">
                                <li v-for="r in reviewList" :key="r.id" class="text-[11px] leading-relaxed" :data-testid="`copy-review-${r.id}`">
                                    <span class="text-amber-200">{{ '★'.repeat(r.stars) }}</span>
                                    <span class="text-dark-500 font-mono ml-1">{{ r.reviewer }}</span>
                                    <span v-if="r.comment" class="text-dark-200 ml-1">{{ r.comment }}</span>
                                    <button v-if="wallet" type="button" class="ml-2 text-[10px] text-dark-500 hover:text-trading-red" :disabled="busy" :data-testid="`copy-report-${r.id}`" @click="reportReview(r)">
                                        {{ t('aiTrade.copy.report') }}
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- ยืนยันก่อนตาม: อัตรา · เงื่อนไข · กรอบความเสี่ยงของผู้ตาม · คำเตือนเงินจริง -->
                        <form v-if="followTarget?.id === m.id" class="mt-3 rounded-lg border border-white/10 bg-dark-900/40 p-3 space-y-2" data-testid="copy-follow-form" @submit.prevent="confirmFollow">
                            <p class="text-xs font-bold text-white">{{ t('aiTrade.copy.followTitle', { name: m.display_name }) }}</p>
                            <p class="text-[11px] text-dark-200 leading-relaxed" data-testid="copy-follow-terms">{{ t('aiTrade.copy.followTerms', { rate: m.fee_rate_pct, currency: fees.currency ?? 'TPIX' }) }}</p>
                            <p class="text-[11px] text-dark-400 leading-relaxed">{{ t('aiTrade.copy.followRisk') }}</p>
                            <div class="grid gap-2 grid-cols-2 sm:grid-cols-4">
                                <label v-for="field in riskFields" :key="field.key" class="block">
                                    <span class="block text-[10px] text-dark-400 mb-1">{{ t(field.labelKey) }}</span>
                                    <input v-model.number="followRisk[field.key]" type="number" class="trading-input text-xs py-1.5 font-mono" :min="limits[field.key]?.min" :max="limits[field.key]?.max" :data-testid="`copy-follow-${field.key}`">
                                </label>
                            </div>
                            <!-- ค่าบริการหักจากยอด TPIX ที่เติมไว้ — ยอดไม่พอ = เตือนพร้อมตัวเลข และกดยืนยันไม่ได้ -->
                            <p
                                v-if="followNeed"
                                :class="['text-[11px] leading-relaxed', followNeed.ok ? 'text-dark-300' : 'text-trading-red font-bold']"
                                data-testid="copy-follow-balance"
                            >
                                {{ followNeed.ok
                                    ? t('aiTrade.copy.followBalanceOk', { need: tpix(followNeed.required), have: tpix(followNeed.balance) })
                                    : t('aiTrade.copy.followBalanceLow', { need: tpix(followNeed.required), have: tpix(followNeed.balance), short: tpix(followNeed.short) }) }}
                                <button v-if="!followNeed.ok" type="button" class="underline ml-1 font-normal" data-testid="copy-follow-topup" @click="showTopup = true">
                                    {{ t('aiTrade.copy.feeTopup') }}
                                </button>
                            </p>
                            <p class="text-[11px] text-amber-300 leading-relaxed" data-testid="copy-follow-warning">{{ t('aiTrade.copy.followRealMoney') }}</p>
                            <div class="flex gap-2">
                                <button type="submit" class="btn-brand px-4 py-1.5 text-xs disabled:opacity-50 disabled:cursor-not-allowed" :disabled="busy || (followNeed !== null && !followNeed.ok)" data-testid="copy-follow-confirm">{{ t('aiTrade.copy.followConfirm') }}</button>
                                <button type="button" class="btn-secondary px-4 py-1.5 text-xs" @click="followTarget = null">{{ t('aiTrade.copy.cancel') }}</button>
                            </div>
                        </form>
                    </li>
                </ul>
            </div>

            <!-- มาสเตอร์ที่ฉันตาม -->
            <div v-if="follows.length" data-testid="copy-my-follows">
                <p class="text-xs font-bold text-white mb-2">{{ t('aiTrade.copy.myFollows') }}</p>
                <ul class="space-y-2">
                    <li v-for="f in follows" :key="f.id" class="rounded-xl border border-white/10 bg-white/5 p-3 space-y-1" :data-testid="`copy-follow-row-${f.id}`">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-sm font-bold text-white">{{ f.master?.display_name ?? '—' }}</span>
                            <span :class="['text-[11px]', f.status === 'active' ? 'text-trading-green' : 'text-dark-400']">
                                {{ f.status === 'active' ? t('aiTrade.copy.followActive') : t('aiTrade.copy.followStopped') }}
                            </span>
                            <span class="text-[11px] text-dark-400 ml-auto">{{ t('aiTrade.copy.feeOfProfit', { rate: f.fee_rate_pct }) }}</span>
                        </div>
                        <p class="text-[11px] text-dark-300">
                            {{ t('aiTrade.copy.followPnl') }}: <span :class="['font-mono', Number(f.realized_pnl) < 0 ? 'text-trading-red' : 'text-white']">${{ signed(f.realized_pnl) }}</span>
                            · {{ t('aiTrade.copy.followFees') }}: <span class="font-mono text-white">${{ Number(f.fees_usd).toFixed(2) }}</span>
                            <span v-if="Number(f.fees_tpix) > 0" class="font-mono text-dark-400"> (≈ {{ f.fees_tpix }} {{ fees.currency ?? 'TPIX' }})</span>
                            <span class="text-dark-500"> — {{ f.fees_collect ? t('aiTrade.copy.feesCharged', { paid: tpix(f.fees_paid_tpix) }) : t('aiTrade.copy.feesRecorded') }}</span>
                        </p>
                        <p v-if="f.fees_collect && Number(f.fees_owed_count) > 0" class="text-[11px] text-trading-red leading-relaxed" :data-testid="`copy-follow-owed-${f.id}`">
                            {{ t('aiTrade.copy.followOwed', { owed: tpix(f.fees_owed_tpix) }) }}
                        </p>
                        <p v-if="f.rate_changed" class="text-[11px] text-amber-300 leading-relaxed" :data-testid="`copy-rate-changed-${f.id}`">
                            {{ t('aiTrade.copy.rateChanged', { rate: f.master?.fee_rate_pct, mine: f.fee_rate_pct }) }}
                            <button type="button" class="underline ml-1" :disabled="busy" :data-testid="`copy-accept-${f.id}`" @click="acceptRate(f)">
                                {{ t('aiTrade.copy.acceptRate', { rate: f.master?.fee_rate_pct }) }}
                            </button>
                        </p>
                        <form v-if="f.can_review" class="rounded-lg bg-dark-900/40 p-2 space-y-1.5" :data-testid="`copy-review-form-${f.id}`" @submit.prevent="submitReview(f)">
                            <p class="text-[11px] text-dark-300">{{ f.my_review ? t('aiTrade.copy.reviewEdit') : t('aiTrade.copy.reviewTitle') }}</p>
                            <div class="flex items-center gap-1">
                                <button
                                    v-for="n in 5"
                                    :key="n"
                                    type="button"
                                    :class="['text-lg leading-none', n <= draftFor(f).stars ? 'text-amber-300' : 'text-dark-600']"
                                    :aria-label="t('aiTrade.copy.reviewStars', { n })"
                                    :data-testid="`copy-star-${f.id}-${n}`"
                                    @click="draftFor(f).stars = n"
                                >
                                    ★
                                </button>
                            </div>
                            <input v-model="draftFor(f).comment" type="text" maxlength="500" class="trading-input text-xs py-1.5" :placeholder="t('aiTrade.copy.reviewComment')" :data-testid="`copy-review-comment-${f.id}`">
                            <button type="submit" class="btn-secondary px-3 py-1 text-xs disabled:opacity-50" :disabled="busy || !draftFor(f).stars" :data-testid="`copy-review-submit-${f.id}`">
                                {{ t('aiTrade.copy.reviewSubmit') }}
                            </button>
                        </form>
                        <p v-else-if="f.status === 'active'" class="text-[10px] text-dark-500">{{ t('aiTrade.copy.reviewLocked') }}</p>

                        <button v-if="f.status === 'active'" type="button" class="text-[11px] text-dark-400 hover:text-trading-red" :disabled="busy" :data-testid="`copy-stop-${f.id}`" @click="stopFollow(f)">
                            {{ t('aiTrade.copy.stop') }}
                        </button>
                    </li>
                </ul>
            </div>
        </template>

        <!-- เติม TPIX เข้าคลังของเว็บ (คลังเดียวกับค่าบริการวางไม้) — เติมแล้วโหลดยอดใหม่ -->
        <TpixTopupModal v-if="showTopup" :show="showTopup" @close="showTopup = false" @credited="load" />
    </section>
</template>
