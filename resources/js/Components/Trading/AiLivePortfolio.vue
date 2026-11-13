<script setup>
/**
 * TPIX TRADE — พอร์ตเงินจริงของ AI TRADE
 *
 * ของที่บอทถืออยู่จริงบนเชน BSC + คำสั่งที่บอทส่งเองจากกระเป๋าบอท
 *
 * ⚠️ คำสั่งเป็นงานอะซิงก์: เซิร์ฟเวอร์แค่ "เข้าคิว" ให้ จะเป็นไม้จริงก็ต่อเมื่อเชนยืนยัน (1–3 นาที)
 *    แผงนี้จึงห้ามพูดว่า "ขายแล้ว/ซื้อแล้ว" ตอนที่คำสั่งยังอยู่ในคิว — บอกสถานะตามที่เซิร์ฟเวอร์รายงาน
 *    แล้วถามซ้ำทุก 15 วิจนกว่าคำสั่งจะจบ
 *
 * ปุ่ม "ปิดไม้ทันที" ขายทุกอย่างที่บอทตัวนั้นถืออยู่ — ของจริง ย้อนไม่ได้ จึงถามยืนยันก่อนเสมอ
 * และล็อกปุ่มระหว่างส่งคำขอ/ระหว่างที่บอทตัวนั้นยังมีคำสั่งค้าง (กดซ้ำ = คำสั่งขายซ้อน)
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useAiBot } from '@/Composables/useAiBot';
import { useTranslation } from '@/Composables/useTranslation';

const bot = useAiBot();
const { t, locale } = useTranslation();

/** รอบถามสถานะซ้ำระหว่างที่ยังมีคำสั่งไม่จบ */
const POLL_MS = 15000;

const message = ref(null);      // { ok, text } — ผลของการกดปิดไม้ครั้งล่าสุด
const closingId = ref(null);    // bot_id ที่กำลังส่งคำขอปิดไม้อยู่ (null = ไม่มี)

let pollTimer = null;
let alive = true;

const data = computed(() => bot.live.value);
const positions = computed(() => data.value?.positions ?? []);
const orders = computed(() => data.value?.orders ?? []);
const limits = computed(() => data.value?.limits ?? null);
const hasPending = computed(() => bot.pendingLiveOrders.value.length > 0);

/** โหลดครั้งแรก (ยังไม่มีอะไรให้ดู) ต่างจากการอัปเดตรอบถัดไป — รอบถัดไปห้ามบังของที่โชว์อยู่ */
const isFirstLoad = computed(() => !data.value && bot.isLoadingLive.value);
const isRefreshing = computed(() => !!data.value && bot.isLoadingLive.value);
const isEmpty = computed(() => !!data.value && !positions.value.length && !orders.value.length);

const numberLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));

function money(value, digits = 2) {
    const n = Number(value);
    return (Number.isFinite(n) ? n : 0).toLocaleString('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/** "-$4.20" ไม่ใช่ "$-4.20" — เครื่องหมายต้องอยู่หน้าสัญลักษณ์เงิน */
function signedMoney(value) {
    const n = Number(value);
    const safe = Number.isFinite(n) ? n : 0;
    return `${safe < 0 ? '-' : '+'}$${money(Math.abs(safe))}`;
}

function toneOf(value) {
    const n = Number(value);
    if (n > 0) return 'text-trading-green';
    if (n < 0) return 'text-trading-red';
    return 'text-dark-300';
}

/** เหรียญถูกต้องการทศนิยมมากกว่าเหรียญแพง ไม่งั้นราคาเข้ากลายเป็น 0.00 */
function price(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '0';
    if (n >= 1000) return money(n, 2);
    if (n >= 1) return money(n, 4);
    return n.toLocaleString('en-US', { maximumFractionDigits: 8 });
}

function quantity(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString('en-US', { maximumFractionDigits: n >= 1 ? 4 : 8 });
}

function timeOf(value) {
    const date = value ? new Date(value) : null;
    return date && !Number.isNaN(date.getTime()) ? date.toLocaleString(numberLocale.value) : '';
}

const baseOf = (pair) => String(pair || '').split('/')[0];

/** ลิงก์ explorer มาจากเซิร์ฟเวอร์ — รับเฉพาะ https กันค่าแปลกปลอมกลายเป็นลิงก์ที่กดได้ */
function txLink(order) {
    const url = order?.tx_url;
    return typeof url === 'string' && /^https:\/\//i.test(url) ? url : null;
}

// ── ป้ายสถานะคำสั่ง ──────────────────────────────────────────────────────────
const STAGES = ['queued', 'approving', 'signing', 'broadcasting', 'confirmed', 'failed', 'cancelled'];

/** stage ละเอียดกว่า status (มี approving) — ไม่รู้จักก็ถอยไปใช้ status */
function stageOf(order) {
    if (STAGES.includes(order?.stage)) return order.stage;
    return STAGES.includes(order?.status) ? order.status : null;
}

function stageLabel(order) {
    const stage = stageOf(order);
    if (stage) return t(`aiTrade.live.stage.${stage}`);
    // สถานะที่หน้าเว็บรุ่นนี้ยังไม่รู้จัก — ไม่เดาว่าจบแล้ว
    return t('aiTrade.live.orderInProgress');
}

const stageClass = (order) => ({
    queued: 'text-amber-300 bg-amber-400/10',
    approving: 'text-sky-300 bg-sky-400/10',
    signing: 'text-sky-300 bg-sky-400/10',
    broadcasting: 'text-sky-300 bg-sky-400/10',
    confirmed: 'text-trading-green bg-trading-green/10',
    failed: 'text-trading-red bg-trading-red/10',
    cancelled: 'text-dark-400 bg-white/5',
}[stageOf(order)] || 'text-amber-300 bg-amber-400/10');

/** เหตุผลที่ล้ม — เซิร์ฟเวอร์แปลมาให้แล้ว ถ้าไม่ได้ส่งมาใช้ข้อความกลางของหน้าเว็บ ไม่โชว์รหัสดิบ */
function failureText(order) {
    return (typeof order?.failure_reason === 'string' && order.failure_reason.trim())
        || t('aiTrade.live.failedGeneric');
}

// ── ปิดไม้ ───────────────────────────────────────────────────────────────────

/** กดปิดไม้ของบอทตัวนี้ไม่ได้ตอนนี้ไหม — กำลังส่งคำขอ หรือบอทยังมีคำสั่งที่ไม่จบ */
function closeBlocked(position) {
    return closingId.value !== null
        || bot.isWorking.value
        || !!bot.pendingOrderFor(position.bot_id);
}

async function closeNow(position) {
    if (closeBlocked(position)) return;

    const confirmed = window.confirm(t('aiTrade.live.closeConfirm', {
        pair: position.pair,
        bot: position.bot_name || `#${position.bot_id}`,
    }));
    if (!confirmed) return;

    // ตั้งธงก่อน await ตัวแรก — คลิกที่สองที่มาถึงก่อนคำตอบจะเจอธงนี้แล้วหยุด
    closingId.value = position.bot_id;
    message.value = null;
    const owner = String(bot.wallet.value || '').toLowerCase();

    try {
        const result = await bot.closePosition(position.bot_id);

        // แผงถูกถอดออกจากจอ หรือสลับกระเป๋าระหว่างรอ — ผลนี้ไม่ใช่ของจอที่เปิดอยู่แล้ว
        if (!alive || String(bot.wallet.value || '').toLowerCase() !== owner) return;

        if (result.ok) {
            // queued = แค่เข้าคิว ยังไม่ได้ขาย — ข้อความต้องบอกตามจริง
            message.value = {
                ok: true,
                text: result.data?.queued ? t('aiTrade.live.closeQueued') : t('aiTrade.live.closeDone'),
            };
        } else {
            message.value = { ok: false, text: result.error?.message || t('aiTrade.errGeneric') };
        }
    } catch {
        // closePosition ไม่ควร throw — กันไว้ไม่ให้ข้อความดิบของระบบหลุดถึงผู้ใช้
        if (alive) message.value = { ok: false, text: t('aiTrade.errGeneric') };
    } finally {
        closingId.value = null;
    }
}

// ── ถามสถานะซ้ำระหว่างรอเชน ──────────────────────────────────────────────────

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function startPolling() {
    if (pollTimer !== null) return;

    pollTimer = window.setInterval(() => {
        // แท็บที่ไม่ได้เปิดดูอยู่ไม่ต้องยิง — กลับมาเมื่อไหร่รอบถัดไปก็ตามทัน
        if (document.hidden || !bot.isConnected.value) return;
        bot.loadLive();
    }, POLL_MS);
}

function reload() {
    if (bot.isConnected.value) bot.loadLive({ force: true });
}

// มีคำสั่งที่ยังไม่จบ = ถามซ้ำ · ไม่มีแล้ว = หยุด (ไม่ยิงเซิร์ฟเวอร์ทิ้งตลอดเวลาที่เปิดหน้าค้าง)
watch(hasPending, (pending) => (pending ? startPolling() : stopPolling()), { immediate: true });

/*
 * คำสั่งที่เพิ่งจบ → ยอดในกระเป๋าบอทและ pending_order ของบอทเปลี่ยนแล้ว โหลดใหม่ให้ตรงกัน
 * ไม่งั้นการ์ดบอทยังติดป้าย "กำลังส่งคำสั่ง" และยอดกระเป๋ายังเป็นค่าก่อนเทรด
 */
watch(() => bot.pendingLiveOrders.value.map(o => o.id).join(','), (now, before) => {
    // live ถูกล้าง (สลับกระเป๋า) ไม่ใช่คำสั่งจบ
    if (!before || !bot.live.value) return;

    const still = new Set(now ? now.split(',') : []);
    if (before.split(',').some(id => !still.has(id))) {
        bot.loadStatus({ force: true });
        bot.loadBotWallet({ force: true });
    }
});

/*
 * บอทส่งคำสั่งเองเมื่อไหร่ก็ได้ (ไม่ได้เกิดจากการกดในแผงนี้) — สถานะบอทบอกว่ามีคำสั่งค้าง
 * ที่แผงนี้ยังไม่รู้จัก ให้โหลดพอร์ตใหม่ การถามซ้ำจะเริ่มเองเมื่อเห็นคำสั่งนั้น
 */
watch(() => bot.bots.value.map(b => b.pending_order?.id ?? '').filter(Boolean).join(','), (ids) => {
    if (!ids || !bot.isConnected.value) return;

    const known = new Set(orders.value.map(o => String(o.id)));
    if (ids.split(',').some(id => !known.has(id))) bot.loadLive({ force: true });
});

onMounted(() => { if (bot.isConnected.value) bot.loadLive(); });

// สลับกระเป๋า = ผลลัพธ์และตัวจับเวลาของกระเป๋าเดิมไม่เกี่ยวแล้ว
watch(() => bot.wallet.value, (address) => {
    message.value = null;
    stopPolling();
    if (address) bot.loadLive();
    // ตัวเฝ้า hasPending ไม่ยิงถ้าค่าก่อน/หลังสลับเป็น true เหมือนกัน — เริ่มใหม่เองให้ครบ
    if (hasPending.value) startPolling();
});

onBeforeUnmount(() => {
    alive = false;
    stopPolling();
});
</script>

<template>
    <section class="glass-dark rounded-2xl p-5 space-y-4" data-testid="ai-live-portfolio">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-white">{{ t('aiTrade.live.title') }}</h3>
                <p class="text-[11px] text-dark-400 mt-1 leading-relaxed">{{ t('aiTrade.live.pitch') }}</p>
            </div>
            <div class="shrink-0 flex items-center gap-2">
                <!-- อัปเดตรอบถัดไป: จุดเล็กๆ พอ ไม่บังของที่โชว์อยู่ -->
                <span
                    v-if="isRefreshing"
                    class="flex items-center gap-1 text-[10px] text-dark-400"
                    role="status"
                    data-testid="live-refreshing"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-primary-400 animate-pulse" aria-hidden="true"></span>
                    {{ t('aiTrade.live.refreshing') }}
                </span>
                <button
                    type="button"
                    class="px-2.5 py-1 rounded-md text-[11px] bg-white/5 text-dark-300 hover:text-white hover:bg-white/10 disabled:opacity-40"
                    :disabled="bot.isLoadingLive.value"
                    data-testid="live-reload"
                    @click="reload"
                >{{ t('aiTrade.live.refresh') }}</button>
            </div>
        </div>

        <!-- โหลดครั้งแรก -->
        <p v-if="isFirstLoad" class="text-[12px] text-dark-400" role="status">{{ t('aiTrade.live.loading') }}</p>

        <!-- โหลดไม่สำเร็จและยังไม่มีอะไรให้ดู -->
        <p
            v-else-if="!data && bot.liveError.value"
            class="text-[12px] rounded-xl bg-trading-red/10 text-trading-red px-3 py-2.5 leading-relaxed"
            role="alert"
        >
            {{ t('aiTrade.live.loadFailed') }}
        </p>

        <template v-else-if="data">
            <!-- อัปเดตรอบล่าสุดพลาด — ของเดิมยังโชว์อยู่ แต่ต้องบอกว่าอาจไม่ใช่ค่าล่าสุด -->
            <p v-if="bot.liveError.value" class="text-[11px] text-amber-300/90 leading-relaxed" role="status">
                {{ t('aiTrade.live.refreshFailed') }}
            </p>

            <p
                v-if="data.entries_paused"
                class="text-[11px] rounded-xl bg-amber-500/10 text-amber-200/90 ring-1 ring-amber-500/20 px-3 py-2 leading-relaxed"
                data-testid="live-entries-paused"
            >
                {{ t('aiTrade.live.entriesPaused') }}
            </p>

            <!-- สรุป -->
            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-white/5 px-3 py-2">
                    <p class="text-[10px] text-dark-500">{{ t('aiTrade.live.realizedPnl') }}</p>
                    <p :class="['text-sm font-mono font-semibold', toneOf(data.realized_pnl)]" data-testid="live-realized">
                        {{ signedMoney(data.realized_pnl) }}
                    </p>
                </div>
                <div class="rounded-xl bg-white/5 px-3 py-2">
                    <p class="text-[10px] text-dark-500">{{ t('aiTrade.live.feesPaid') }}</p>
                    <p class="text-sm font-mono font-semibold text-white" data-testid="live-fees">${{ money(data.fees_paid) }}</p>
                </div>
            </div>
            <p v-if="limits" class="text-[10px] text-dark-500 font-mono">
                {{ t('aiTrade.live.limits', {
                    min: money(limits.min_order_usd, 0),
                    max: money(limits.max_order_usd, 0),
                    slippage: (Number(limits.slippage_bps || 0) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 }),
                }) }}
            </p>

            <p
                v-if="message"
                :class="['text-[11px] rounded-lg px-3 py-2 leading-relaxed', message.ok ? 'bg-trading-green/10 text-trading-green' : 'bg-trading-red/10 text-trading-red']"
                :role="message.ok ? 'status' : 'alert'"
                data-testid="live-message"
            >
                {{ message.text }}
            </p>

            <!-- ยังไม่มีอะไรเลย -->
            <p v-if="isEmpty" class="text-[12px] text-dark-300 rounded-xl bg-white/5 px-3 py-2.5 leading-relaxed" data-testid="live-empty">
                {{ t('aiTrade.live.empty') }}
            </p>

            <template v-else>
                <!-- ไม้ที่ถืออยู่ -->
                <div class="space-y-1.5">
                    <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.live.positions') }}</p>
                    <p v-if="!positions.length" class="text-[11px] text-dark-500">{{ t('aiTrade.live.noPositions') }}</p>
                    <div
                        v-for="p in positions"
                        :key="`${p.bot_id}-${p.pair}`"
                        class="rounded-xl bg-white/5 px-3 py-2.5 flex items-center gap-3 flex-wrap"
                        data-testid="live-position"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-white flex items-center gap-1.5 flex-wrap">
                                <span>{{ p.pair }}</span>
                                <span class="text-dark-400 font-normal truncate">· {{ p.bot_name }}</span>
                                <span
                                    v-if="bot.pendingOrderFor(p.bot_id)"
                                    class="px-1.5 py-px rounded text-[9px] font-medium text-sky-300 bg-sky-400/10"
                                >{{ t('aiTrade.live.orderInProgress') }}</span>
                            </p>
                            <p class="text-[11px] text-dark-400 font-mono mt-0.5">
                                {{ t('aiTrade.live.quantity') }} {{ quantity(p.quantity) }} {{ baseOf(p.pair) }}
                                · {{ t('aiTrade.live.entry') }} ${{ price(p.entry_price) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <template v-if="p.value !== null && p.value !== undefined">
                                <p class="text-xs font-mono text-white">{{ t('aiTrade.live.value') }} ${{ money(p.value) }}</p>
                                <p
                                    v-if="p.unrealized_pnl !== null && p.unrealized_pnl !== undefined"
                                    :class="['text-[11px] font-mono', toneOf(p.unrealized_pnl)]"
                                    data-testid="live-unrealized"
                                >
                                    {{ t('aiTrade.unrealized') }} {{ signedMoney(p.unrealized_pnl) }}
                                </p>
                            </template>
                            <!-- ยังไม่มีราคาตลาด — ไม่ตีมูลค่าเดาให้ดูเหมือนรู้ -->
                            <p v-else class="text-[11px] text-dark-500">{{ t('aiTrade.unpriced') }}</p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 px-2.5 py-1 rounded-lg text-[11px] font-medium bg-trading-red/15 text-trading-red hover:bg-trading-red/25 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="closeBlocked(p)"
                            :title="bot.pendingOrderFor(p.bot_id) ? t('aiTrade.live.orderInProgressHint') : ''"
                            data-testid="live-close"
                            @click="closeNow(p)"
                        >
                            {{ closingId === p.bot_id ? t('aiTrade.live.closing') : t('aiTrade.live.closeNow') }}
                        </button>
                    </div>
                </div>

                <!-- คำสั่งล่าสุด -->
                <div v-if="orders.length" class="space-y-1.5">
                    <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.live.orders') }}</p>
                    <div
                        v-for="o in orders"
                        :key="o.id"
                        class="rounded-lg bg-white/5 px-3 py-2 text-[11px] space-y-1"
                        data-testid="live-order"
                    >
                        <div class="flex items-center gap-2 flex-wrap">
                            <span :class="['px-1.5 py-px rounded text-[10px] font-medium shrink-0', stageClass(o)]" data-testid="live-order-stage">
                                {{ stageLabel(o) }}
                            </span>
                            <span :class="['font-semibold', o.side === 'buy' ? 'text-trading-green' : 'text-trading-red']">
                                {{ o.side === 'buy' ? t('aiTrade.tradeBuy') : t('aiTrade.tradeSell') }}
                            </span>
                            <span class="text-white font-medium">{{ o.pair }}</span>
                            <span class="font-mono text-dark-200">{{ quantity(o.amount) }} {{ o.asset }}</span>
                            <span v-if="o.manual" class="px-1 rounded bg-white/5 text-dark-400 text-[10px]">{{ t('aiTrade.live.manual') }}</span>
                            <span class="ml-auto text-dark-500">{{ timeOf(o.created_at) }}</span>
                            <a
                                v-if="txLink(o)"
                                :href="txLink(o)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-primary-400 hover:underline shrink-0"
                            >{{ t('aiTrade.live.viewTx') }}</a>
                        </div>
                        <p v-if="stageOf(o) === 'failed'" class="text-trading-red/90 leading-relaxed" data-testid="live-order-failure">
                            {{ failureText(o) }}
                        </p>
                    </div>
                </div>
            </template>
        </template>
    </section>
</template>
