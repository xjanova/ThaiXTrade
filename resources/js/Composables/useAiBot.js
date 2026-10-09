/**
 * TPIX TRADE — useAiBot
 * ตัวกลางคุยกับ /api/v1/ai-bot/* (ระบบเช่าบอทเทรดคลาวด์)
 *
 * เป็น singleton ระดับโมดูล — การ์ดในหน้าเทรดกับหน้า /ai-trade ใช้ state ก้อนเดียวกัน
 * เช่าบอทที่หน้าไหนก็ตาม อีกหน้าเห็นทันทีโดยไม่ต้องยิงซ้ำ
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, effectScope, getCurrentScope, onScopeDispose } from 'vue';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation, t } from '@/Composables/useTranslation';

const catalog = ref(null);          // { plans, strategies, packs, rental_days, timeframes, limits }
const status = ref(null);           // { credits, is_active, subscription, quota, bots, unlocked_strategies }
const isLoadingCatalog = ref(false);
const isLoadingStatus = ref(false);
const isWorking = ref(false);       // มี action ที่เขียนข้อมูลกำลังทำงาน
const error = ref(null);
/*
 * โหลดสถานะครั้งล่าสุดล้มเหลว { code, message } — null เมื่อครั้งล่าสุดสำเร็จ
 *
 * แยกจาก `status` โดยตั้งใจ: เดิมโหลดพลาดครั้งเดียว (เช่นโดน 429) status กลายเป็น null
 * แล้วการ์ดพลิกไปโชว์ "ยังไม่ได้เช่า" ทั้งที่ผู้ใช้เช่าอยู่ — ตอนนี้เก็บสถานะล่าสุดไว้
 * แล้วบอกว่าโหลดใหม่ไม่สำเร็จพร้อมปุ่มลองใหม่แทน
 */
const statusError = ref(null);
/** true เมื่อ backend ตอบ WALLET_NOT_VERIFIED / WALLET_IP_MISMATCH — ต้องเซ็นข้อความยืนยันกระเป๋าก่อน */
const needsVerification = ref(false);

// โหมดทดลอง — พอร์ตกระดาษที่ใช้ราคาจริง { account, positions, trades, summary }
const demo = ref(null);
const isLoadingDemo = ref(false);

/*
 * ไม้ของบอททุกโหมดสำหรับปักบนกราฟ — แยกจาก demo.trades เพราะ /demo ให้เฉพาะ
 * ไม้กระดาษและตัดจำนวนไว้ พอเปิดโหมดจริงกราฟจะเห็นไม่ครบ
 * เก็บเป็น { pair, items } เพื่อไม่เอาไม้ของคู่เก่าไปปักบนกราฟคู่ใหม่ระหว่างรอโหลด
 */
const trades = ref({ pair: null, items: [] });
const isLoadingTrades = ref(false);

/*
 * กระเป๋าบอท — กระเป๋าแยกที่บอทใช้ในโหมดจริง { enabled, chain_id, wallet, transfers }
 * เก็บระดับโมดูลเหมือนตัวอื่น: หน้า /ai-trade กับการ์ดในหน้าเทรดเห็นยอดเดียวกัน
 */
const botWallet = ref(null);
const isLoadingBotWallet = ref(false);

// คำแนะนำล่าสุดจากที่ปรึกษา AI { ok, provider, text, reason }
// เก็บระดับโมดูลเพราะฝั่งเซิร์ฟเวอร์แคชไว้ 15 นาทีอยู่แล้ว — สลับหน้าไม่ควรยิงซ้ำ
const advice = ref(null);
const isAskingAdvice = ref(false);

/*
 * มุมมองตลาดของ AI — เก็บระดับโมดูลเหมือน catalog
 *
 * หน้าเทรดกับหน้า /ai-trade เรียก composable นี้พร้อมกันได้ ถ้าเก็บแยกในแต่ละ
 * คอมโพเนนต์จะยิง API ซ้ำสองรอบเพื่อข้อมูลชุดเดียวกัน (เป็นภาพตลาดรวม
 * ไม่ได้ผูกกับกระเป๋าใคร จึงใช้ร่วมกันได้ปลอดภัย)
 */
const marketView = ref(null);
const isLoadingMarketView = ref(false);

/*
 * ตัวเดินบอทของแพลนฟรี
 *
 * แพลนฟรีไม่ได้ซื้อการรันบนคลาวด์ ตัวจับเวลาฝั่งเซิร์ฟเวอร์จึงข้ามบอทพวกนี้ไป
 * หน้าเว็บที่เปิดค้างอยู่เป็นคนสั่งให้บอทคิดทีละรอบแทน — ปิดแท็บแล้วหยุดจริงๆ
 * ซึ่งตรงกับที่โฆษณาไว้บนหน้าเช่า ไม่ใช่แค่คำพูด
 *
 * ตัวจับเวลามีได้ตัวเดียวทั้งแท็บ (ระดับโมดูล) — การ์ดในหน้าเทรดกับหน้า /ai-trade
 * ต่างขอให้เดินผ่าน keepBrowserBotsRunning() แล้วนับจำนวนผู้ขอไว้ ถ้าต่างคนต่างเปิด
 * ตัวจับเวลาเอง บอทจะถูกสั่งเดินซ้อนกันสองตัว · ถ้าต่างคนต่างปิด ฝ่ายที่ออกจากจอก่อน
 * จะไปหยุดบอทของอีกฝ่ายที่ยังเปิดอยู่
 */
const browserLoopActive = ref(false);
const lastBrowserTick = ref(null);
const browserTickLog = ref([]);
/** ข้อผิดพลาดล่าสุดของการสั่งบอทเดินจากหน้าเว็บ แยกรายบอท { [botId]: { code, message, bot, at } } */
const browserTickErrors = ref({});
const loopHolders = ref(0);
let loopTimer = null;
let cycleInFlight = false;

/** รอบที่หน้าเว็บสั่งบอทฟรีเดิน (วินาที) — เท่ากับขั้นต่ำฝั่งเซิร์ฟเวอร์ (aibot.browser_tick_min_seconds) */
const BROWSER_TICK_SECONDS = 30;

let catalogPromise = null;

// ── ค่าที่ตัวเดินบอทใช้ร่วม — ต้องอยู่ระดับโมดูล ไม่ผูกกับคอมโพเนนต์ตัวไหน ───────────
const bots = computed(() => status.value?.bots ?? []);

/** แพลนที่ถืออยู่ให้เซิร์ฟเวอร์เดินบอทให้ไหม */
const runsInCloud = computed(() => status.value?.subscription?.execution === 'cloud');

/** บอทที่ต้องให้หน้าเว็บสั่งเดินเอง (แพลนฟรี + สถานะกำลังทำงาน) */
const browserBots = computed(() =>
    runsInCloud.value ? [] : bots.value.filter(b => b.status === 'running')
);

// ── กระเป๋าที่กำลังคุยด้วย ─────────────────────────────────────────────────────
let walletStoreRef = null;
let backgroundScope = null;

function normalizeAddress(address) {
    return address ? String(address).toLowerCase() : null;
}

function currentAddress() {
    return walletStoreRef?.address || null;
}

/** คำตอบนี้ยังเป็นของกระเป๋าที่เปิดอยู่ไหม (ที่อยู่แบบ checksum กับตัวเล็กคือใบเดียวกัน) */
function isCurrentWallet(address) {
    return !!address && normalizeAddress(address) === normalizeAddress(currentAddress());
}

/*
 * คำขอที่ผูกกับกระเป๋า — กันสามเรื่องในที่เดียว
 *
 *  1) คำตอบของกระเป๋าเดิมที่มาถึงหลังสลับกระเป๋า/ตัดการเชื่อมต่อ ต้องทิ้ง ไม่ใช่เขียนทับ
 *     (เดิมหน้าเทรดปักไม้และเส้นทุน/SL/TP ของกระเป๋าก่อนหน้าค้างอยู่บนกราฟ)
 *  2) คำขอเก่าที่ช้ากว่าคำขอใหม่ต้องไม่ทับผลที่ใหม่กว่า — นับเลขรอบต่อชนิด ใบล่าสุดชนะเสมอ
 *  3) หลายจอขอข้อมูลชุดเดียวกันพร้อมกัน = ยิงครั้งเดียว (ไม่เผาโควตา rate limit เปล่าๆ)
 *     ยกเว้น force — หลังเขียนข้อมูลต้องได้ของใหม่จริง ไม่ใช่ผลของคำขอที่ยิงไปก่อนเขียน
 */
const requestSeq = { status: 0, demo: 0, trades: 0, botWallet: 0 };
const inflight = new Map();
/** ข้อมูลชุดไหนที่หน้าจอเคยขอไว้แล้ว — หลังเซ็นยืนยันผ่านจะโหลดชุดเหล่านี้ใหม่ให้เอง */
const requested = { demo: false, trades: null, botWallet: false };

function track(kind, key, run, { force = false } = {}) {
    const current = inflight.get(kind);
    if (!force && current && current.key === key) return current.promise;

    const seq = ++requestSeq[kind];
    const isLatest = () => requestSeq[kind] === seq;

    const promise = run(isLatest).finally(() => {
        if (inflight.get(kind)?.promise === promise) inflight.delete(kind);
    });

    inflight.set(kind, { key, promise });

    return promise;
}

/**
 * แปลงข้อผิดพลาดจาก API เป็น { code, message } ที่ผู้ใช้อ่านได้.
 *
 * WALLET_IP_MISMATCH คือการยืนยันกระเป๋าที่ใช้ไม่ได้แล้ว (เปลี่ยนเน็ต/สลับ Wi-Fi เป็นมือถือ)
 * แก้ด้วยการเซ็นใหม่เหมือน WALLET_NOT_VERIFIED ทุกอย่าง — เดิมไม่ถูกนับ ผู้ใช้จึงไม่เห็นปุ่มเซ็น
 * เห็นแต่ข้อความผิดพลาดที่ไม่บอกว่าต้องทำอะไร
 */
function readError(err, fallback = null) {
    const response = err?.response;
    const payload = response?.data?.error;
    const code = payload?.code;

    if (code === 'WALLET_NOT_VERIFIED' || code === 'WALLET_IP_MISMATCH') needsVerification.value = true;

    // ตัวจำกัดอัตราของ Laravel ไม่ได้ส่งรหัสของเรามา — ต้องดูจาก HTTP status เอง
    if (response?.status === 429) return { code: 'RATE_LIMITED', message: t('aiTrade.errRateLimited') };
    if (!response && err?.request) return { code: 'NETWORK', message: t('aiTrade.errNetwork') };

    return { code: code || 'REQUEST_FAILED', message: payload?.message || fallback || t('aiTrade.errGeneric') };
}

/**
 * สลับกระเป๋า/ตัดการเชื่อมต่อ = ล้างทุกอย่างที่ผูกกับกระเป๋าเดิมทันที.
 *
 * เดิม loadDemo/loadTrades แค่ return ออกไปเมื่อไม่มีกระเป๋า ของเดิมจึงค้างอยู่ — หน้าเทรด
 * ยังวาดไม้ของบอทและเส้นทุน/SL/TP ของคนก่อนหน้า ผู้ใช้ที่ยืมเครื่องกันเห็นพอร์ตของอีกคน
 */
function resetWalletState() {
    Object.keys(requestSeq).forEach((kind) => { requestSeq[kind]++; });
    inflight.clear();
    requested.demo = false;
    requested.trades = null;
    requested.botWallet = false;

    status.value = null;
    statusError.value = null;
    error.value = null;
    needsVerification.value = false;
    demo.value = null;
    trades.value = { pair: null, items: [] };
    botWallet.value = null;
    advice.value = null;
    lastBrowserTick.value = null;
    browserTickLog.value = [];
    browserTickErrors.value = {};

    // คำขอที่ค้างของกระเป๋าเดิมจะไม่ปลดธงพวกนี้ให้ (ไม่ใช่ใบล่าสุดแล้ว) — ปลดเองที่นี่
    isLoadingStatus.value = false;
    isLoadingDemo.value = false;
    isLoadingTrades.value = false;
    isLoadingBotWallet.value = false;
}

// ── โหลดข้อมูลที่ผูกกับกระเป๋า ─────────────────────────────────────────────────

function loadStatus({ force = false } = {}) {
    const address = currentAddress();

    if (!address) {
        status.value = null;
        statusError.value = null;
        return Promise.resolve(null);
    }

    return track('status', normalizeAddress(address), async (isLatest) => {
        isLoadingStatus.value = true;

        try {
            const { data } = await axios.get('/api/v1/ai-bot/status', {
                params: { wallet_address: address },
            });

            if (!isLatest()) return status.value;

            if (data?.success) {
                status.value = data.data;
                statusError.value = null;
                needsVerification.value = false;
            }

            return status.value;
        } catch (err) {
            if (!isLatest()) return status.value;

            // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า (readError ตั้งธงให้) — ไม่ใช่ error ที่ต้องเด้งให้ผู้ใช้ตกใจ
            // สถานะเดิมเก็บไว้ ไม่ล้างเป็น null (ดู statusError ด้านบน)
            const parsed = readError(err, t('aiTrade.errLoadStatus'));
            statusError.value = parsed;
            error.value = parsed;

            return null;
        } finally {
            if (isLatest()) isLoadingStatus.value = false;
        }
    }, { force });
}

/** โหลดพอร์ตทดลอง — เงิน ของที่ถือ ประวัติไม้ และสรุปผล */
function loadDemo({ force = false } = {}) {
    const address = currentAddress();

    if (!address) {
        demo.value = null;
        return Promise.resolve(null);
    }

    requested.demo = true;

    return track('demo', normalizeAddress(address), async (isLatest) => {
        isLoadingDemo.value = true;

        try {
            const { data } = await axios.get('/api/v1/ai-bot/demo', {
                params: { wallet_address: address },
            });

            if (!isLatest()) return demo.value;

            demo.value = data?.data ?? null;
            return demo.value;
        } catch (err) {
            // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า ไม่ใช่ error ที่ต้องเด้งเตือน (readError ตั้งธงให้)
            if (isLatest()) readError(err);
            return null;
        } finally {
            if (isLatest()) isLoadingDemo.value = false;
        }
    }, { force });
}

/** ไม้ของบอทในคู่ที่ระบุ (ทุกโหมด) — เรียงเก่าไปใหม่พร้อมปักกราฟได้ทันที */
function loadTrades(pair, { limit = 300, force = false } = {}) {
    const address = currentAddress();

    if (!address || !pair) {
        // ไม่มีกระเป๋า = ต้องไม่เหลือไม้ของใครค้างบนกราฟ
        trades.value = { pair: null, items: [] };
        return Promise.resolve([]);
    }

    requested.trades = { pair, limit };

    return track('trades', `${normalizeAddress(address)}|${pair}|${limit}`, async (isLatest) => {
        isLoadingTrades.value = true;

        try {
            const { data } = await axios.get('/api/v1/ai-bot/trades', {
                params: { wallet_address: address, pair, limit },
            });
            const items = Array.isArray(data?.data) ? data.data : [];

            if (isLatest()) trades.value = { pair, items };

            return items;
        } catch (err) {
            if (isLatest()) readError(err);
            return [];
        } finally {
            if (isLatest()) isLoadingTrades.value = false;
        }
    }, { force });
}

/** สถานะกระเป๋าบอท + ยอด + รายการโอน (wallet = null ถ้ายังไม่ได้สร้าง) */
function loadBotWallet({ force = false } = {}) {
    const address = currentAddress();

    if (!address) {
        botWallet.value = null;
        return Promise.resolve(null);
    }

    requested.botWallet = true;

    return track('botWallet', normalizeAddress(address), async (isLatest) => {
        isLoadingBotWallet.value = true;

        try {
            const { data } = await axios.get('/api/v1/ai-bot/wallet', {
                params: { wallet_address: address },
            });

            if (!isLatest()) return botWallet.value;

            botWallet.value = data?.data ?? null;
            return botWallet.value;
        } catch (err) {
            if (isLatest()) readError(err);
            return null;
        } finally {
            if (isLatest()) isLoadingBotWallet.value = false;
        }
    }, { force });
}

/** ยิง action ที่เขียนข้อมูล แล้ว sync status กลับมา */
async function mutate(request, { refresh = true } = {}) {
    const address = currentAddress();
    if (!address) return { ok: false, error: { code: 'NO_WALLET', message: t('aiTrade.connectPrompt') } };

    isWorking.value = true;
    error.value = null;

    try {
        const { data } = await request(address);

        // สลับกระเป๋าระหว่างรอ — ผลนี้เป็นของกระเป๋าเดิม ห้ามเอาไปวาดทับของกระเป๋าใหม่
        if (!isCurrentWallet(address)) {
            return { ok: false, error: { code: 'WALLET_CHANGED', message: t('aiTrade.errWalletChanged') } };
        }

        // endpoint ที่คืน status เต็มอยู่แล้ว (subscribe/cancel) ใช้ค่านั้นเลย
        if (data?.data?.bots && data?.data?.quota) {
            // คำขอสถานะที่ยิงไปก่อนเขียนต้องไม่ทับค่าที่ใหม่กว่านี้
            requestSeq.status++;
            inflight.delete('status');
            isLoadingStatus.value = false;
            status.value = data.data;
            statusError.value = null;
        } else if (refresh) {
            await loadStatus({ force: true });
        }

        return { ok: true, data: data?.data ?? null };
    } catch (err) {
        const parsed = readError(err);
        error.value = parsed;
        return { ok: false, error: parsed };
    } finally {
        isWorking.value = false;
    }
}

// ── ตัวเดินบอทของแพลนฟรี ──────────────────────────────────────────────────────

/** สั่งบอทตัวหนึ่งคิดหนึ่งรอบ — คืน { error: { code, message } } เมื่อเซิร์ฟเวอร์ปฏิเสธ */
async function tickBot(id, address = currentAddress()) {
    if (!address) return null;

    try {
        const { data } = await axios.post(`/api/v1/ai-bot/bots/${id}/tick`, {
            wallet_address: address,
        });
        return data?.data ?? null;
    } catch (err) {
        // บอทตัวเดียวพังต้องไม่หยุดลูปทั้งหมด
        return { error: readError(err, t('aiTrade.errTick')) };
    }
}

function pushTickLog(entry) {
    browserTickLog.value = [entry, ...browserTickLog.value].slice(0, 20);
}

function recordTickError(item, failure) {
    const at = new Date().toISOString();

    browserTickErrors.value = {
        ...browserTickErrors.value,
        [item.id]: { ...failure, bot: item.name, at },
    };
    pushTickLog({ at, bot: item.name, error: failure.message });
}

function clearTickError(id) {
    if (!(id in browserTickErrors.value)) return;

    const next = { ...browserTickErrors.value };
    delete next[id];
    browserTickErrors.value = next;
}

/** เอาข้อมูลบอทที่เซิร์ฟเวอร์คืนมาหลังเดินรอบ แทนแถวเดิมในสถานะ (ไฟออนไลน์/เหตุผลล่าสุดสดทันที) */
function mergeBot(updated) {
    if (!updated?.id || !Array.isArray(status.value?.bots)) return;

    status.value = {
        ...status.value,
        bots: status.value.bots.map(b => (b.id === updated.id ? updated : b)),
    };
}

/** เดินบอทฟรีทุกตัวหนึ่งรอบ */
async function runBrowserCycle() {
    // รอบก่อนยังไม่จบ (เน็ตช้า/ตลาดตอบช้า) — ไม่ซ้อนรอบ
    if (cycleInFlight) return;

    const address = currentAddress();
    const targets = browserBots.value;

    if (!address || !targets.length) return;

    cycleInFlight = true;
    let needsStatus = false;

    try {
        for (const item of targets) {
            const result = await tickBot(item.id, address);

            // สลับกระเป๋ากลางรอบ — ผลของกระเป๋าเดิมทิ้งทั้งหมด
            if (!isCurrentWallet(address)) return;
            if (!result) continue;

            /*
             * ⚠️ ต้องเช็ค error ก่อน skipped
             *
             * tickBot คืน { error } เมื่อพลาด ซึ่ง `!result.skipped` เป็นจริงเสมอ — เดิมจึงนับเป็น
             * "เดินสำเร็จ" ทั้ง STRATEGY_LOCKED / BOT_LIMIT / BOT_BANNED / 429 / IP เปลี่ยน
             * หน้าจอโชว์เวลาเดินรอบล่าสุดสดใหม่ทุก 30 วิ ทั้งที่บอทไม่ได้คิดเลยสักรอบ
             */
            if (result.error) {
                recordTickError(item, result.error);

                // โดนจำกัดอัตรา/เน็ตหลุด = ยิงต่อก็พลาดอีก รอรอบหน้า
                if (result.error.code === 'RATE_LIMITED' || result.error.code === 'NETWORK') break;

                // อย่างอื่น (แพลนหมด · โควตาเกิน · ถูกแบน) เซิร์ฟเวอร์อาจพักบอทไปแล้ว — ดึงสถานะใหม่
                needsStatus = true;
                continue;
            }

            clearTickError(item.id);

            if (result.skipped) continue;

            lastBrowserTick.value = new Date().toISOString();
            pushTickLog({ at: lastBrowserTick.value, bot: item.name, action: result.action, reason: result.reason });
            mergeBot(result.bot);
        }
    } finally {
        cycleInFlight = false;
    }

    if (!isCurrentWallet(address)) return;

    if (needsStatus) await loadStatus({ force: true });

    // สถานะบอทเปลี่ยนหลังเดินรอบ — ดึงพอร์ตทดลองใหม่ให้ตัวเลขตรง
    await loadDemo();
}

function startLoopTimer() {
    if (loopTimer !== null) return;

    browserLoopActive.value = true;
    runBrowserCycle();
    loopTimer = window.setInterval(runBrowserCycle, BROWSER_TICK_SECONDS * 1000);
}

function stopLoopTimer() {
    if (loopTimer !== null) {
        window.clearInterval(loopTimer);
        loopTimer = null;
    }
    browserLoopActive.value = false;
}

/**
 * ขอให้ตัวเดินบอทฟรีทำงานตราบที่คอมโพเนนต์นี้ยังอยู่บนจอ — เรียกใน setup.
 *
 * ตัวจับเวลามีตัวเดียวทั้งแท็บ เดินเมื่อ "มีคนขอ" และ "มีบอทฟรีที่เปิดไว้" เท่านั้น
 * คอมโพเนนต์ถูกถอดออกจากจอแล้วคืนสิทธิ์ให้เอง — ถ้าลืม ลูปจะเดินต่อหลังผู้ใช้ไปหน้าอื่น
 * กลายเป็นบอทเดินอยู่เบื้องหลังโดยที่ผู้ใช้ไม่เห็นและไม่ได้ตั้งใจ
 *
 * เดิมมีแค่หน้า /ai-trade ที่เดินบอท — บอทแพลนฟรีที่กดเริ่มจากการ์ดในหน้าเทรดจึงไม่เคย
 * ได้คิดเลย การ์ดโชว์เขียวราว 20 นาทีแล้วกลายเป็นแดง "ออฟไลน์" โดยไม่มีเหตุผล
 *
 * @returns {Function} คืนสิทธิ์ก่อนเวลา (ไม่จำเป็นต้องเรียกถ้าอยู่ใน setup)
 */
function keepBrowserBotsRunning() {
    loopHolders.value += 1;
    let released = false;

    const release = () => {
        if (released) return;
        released = true;
        loopHolders.value = Math.max(0, loopHolders.value - 1);
    };

    if (getCurrentScope()) onScopeDispose(release);

    return release;
}

/** หลังเซ็นยืนยันผ่าน — โหลดทุกชุดที่หน้าจอเคยขอแล้วโดน 403 ใหม่ */
function refreshAfterVerification() {
    if (!currentAddress()) return;

    loadStatus();
    if (requested.demo) loadDemo();
    if (requested.trades) loadTrades(requested.trades.pair, { limit: requested.trades.limit });
    if (requested.botWallet) loadBotWallet();
}

/**
 * ตัวเฝ้าระดับโมดูล — ติดตั้งครั้งเดียวต่อแท็บ ไม่ผูกกับคอมโพเนนต์ตัวไหน.
 *
 * ถ้าให้แต่ละคอมโพเนนต์เฝ้าเอง: การ์ด + หน้า + แผงย่อยเฝ้าซ้อนกันหลายชุด ยิงคำขอซ้ำ
 * และตัวที่ถูกถอดออกจากจอไปแล้วจะพาตัวเฝ้าหายไปด้วย
 */
function installBackgroundWatchers(store) {
    walletStoreRef = store;

    if (backgroundScope) return;

    backgroundScope = effectScope(true);
    backgroundScope.run(() => {
        // สลับกระเป๋า/ตัดการเชื่อมต่อ → ล้างของเดิม "ทันที" ก่อนตัวเฝ้าของคอมโพเนนต์จะโหลดของใหม่
        watch(
            () => normalizeAddress(store.address),
            (next, prev) => { if (next !== prev) resetWalletState(); },
            { flush: 'sync' }
        );

        /*
         * เซ็นยืนยันผ่าน → โหลดใหม่เอง
         *
         * address ถูกตั้งก่อนลายเซ็นเสร็จ ตอนเชื่อมกระเป๋าครั้งแรกทุกจอจึงยิงไปก่อนแล้วได้ 403
         * เดิมเซ็นผ่านแล้วไม่มีใครโหลดใหม่ การ์ดค้างที่ "ยืนยันกระเป๋า" — ผู้ใช้ต้องเซ็นซ้ำอีกรอบ
         */
        watch(() => store.verifiedAt, (at) => { if (at) refreshAfterVerification(); });

        watch(
            () => loopHolders.value > 0 && browserBots.value.length > 0 && !!normalizeAddress(store.address),
            (shouldRun) => (shouldRun ? startLoopTimer() : stopLoopTimer()),
            { immediate: true }
        );
    });
}

/** ข้อความของรหัสเหตุผลที่แพลนไม่ให้บอทเดิน (จาก pause_reason / offline_reason) */
const PLAN_REASON_KEYS = {
    plan_expired: 'aiTrade.planReasonExpired',
    plan_locked: 'aiTrade.planReasonLocked',
    plan_quota: 'aiTrade.planReasonQuota',
    strategy_retired: 'aiTrade.planReasonRetired',
};

export function useAiBot() {
    const walletStore = useWalletStore();
    installBackgroundWatchers(walletStore);

    const { locale } = useTranslation();

    /**
     * เลือกฟิลด์ตามภาษาปัจจุบัน — backend ส่งมาทั้งคู่ (`name` / `name_th`)
     * ถ้าภาษาที่เลือกไม่มีค่า ให้ตกกลับไปอีกภาษาแทนที่จะโชว์ค่าว่าง
     */
    function pickLocalized(source, field) {
        if (!source) return '';
        const thai = source[`${field}_th`];
        const english = source[field];
        return (locale.value === 'th' ? thai || english : english || thai) || '';
    }

    const wallet = computed(() => walletStore.address || null);
    const isConnected = computed(() => !!wallet.value);

    /** แคตตาล็อกเป็น public และไม่เปลี่ยนบ่อย — ยิงครั้งเดียวต่อเซสชัน */
    async function loadCatalog() {
        if (catalog.value) return catalog.value;
        if (catalogPromise) return catalogPromise;

        isLoadingCatalog.value = true;
        catalogPromise = axios.get('/api/v1/ai-bot/catalog')
            .then(({ data }) => {
                if (data?.success) catalog.value = data.data;
                return catalog.value;
            })
            .catch(() => null)
            .finally(() => {
                isLoadingCatalog.value = false;
                catalogPromise = null;
            });

        return catalogPromise;
    }

    const subscribe = (planCode, days) => mutate(address =>
        axios.post('/api/v1/ai-bot/subscribe', { wallet_address: address, plan_code: planCode, days })
    );

    const cancel = () => mutate(address =>
        axios.post('/api/v1/ai-bot/cancel', { wallet_address: address })
    );

    /** รับโบนัสต้อนรับ — ผลมี `granted` (false = เคยรับไปแล้ว ครั้งนี้ไม่ได้เพิ่ม) */
    const claimWelcome = () => mutate(address =>
        axios.post('/api/v1/ai-bot/welcome', { wallet_address: address })
    );

    const requestTopup = (pack) => mutate(address =>
        axios.post('/api/v1/ai-bot/topup', { wallet_address: address, pack })
    , { refresh: false });

    const createBot = (payload) => mutate(address =>
        axios.post('/api/v1/ai-bot/bots', { ...payload, wallet_address: address })
    );

    const updateBot = (id, payload) => mutate(address =>
        axios.put(`/api/v1/ai-bot/bots/${id}`, { ...payload, wallet_address: address })
    );

    const setBotState = (id, action) => mutate(address =>
        axios.post(`/api/v1/ai-bot/bots/${id}/state`, { wallet_address: address, action })
    );

    const deleteBot = (id) => mutate(address =>
        axios.delete(`/api/v1/ai-bot/bots/${id}`, { data: { wallet_address: address } })
    );

    /** สลับบอทระหว่างโหมดทดลองกับโหมดจริง */
    const setBotMode = (id, mode) => mutate(address =>
        axios.post(`/api/v1/ai-bot/bots/${id}/mode`, { wallet_address: address, mode })
    );

    // ── โหมดทดลอง ────────────────────────────────────────────────────────────

    /** ล้างพอร์ตทดลองกลับไปตั้งต้น (จำกัดจำนวนครั้งต่อวันที่ฝั่งเซิร์ฟเวอร์) */
    async function resetDemo() {
        const result = await mutate(address =>
            axios.post('/api/v1/ai-bot/demo/reset', { wallet_address: address }),
        { refresh: false });

        if (result.ok) {
            // คำขอพอร์ตที่ยิงไปก่อนล้างต้องไม่ทับพอร์ตที่เพิ่งล้าง
            requestSeq.demo++;
            inflight.delete('demo');
            isLoadingDemo.value = false;
            demo.value = result.data;
        }

        return result;
    }

    // ── กระเป๋าบอท ───────────────────────────────────────────────────────────

    /** ผลจาก endpoint ของกระเป๋าบอทมีรูป { wallet?, transfers? } — รวมเข้า state โดยไม่ทับส่วนที่ไม่ได้ส่งมา */
    function mergeBotWallet(payload) {
        if (!payload) return;
        botWallet.value = {
            ...(botWallet.value || {}),
            ...(payload.wallet ? { wallet: payload.wallet } : {}),
            ...(Array.isArray(payload.transfers) ? { transfers: payload.transfers } : {}),
        };
    }

    /**
     * รวมผลเข้า state แล้วโหลดกระเป๋าใหม่ถ้าเซิร์ฟเวอร์ไม่ได้ส่งตัวกระเป๋ามา.
     *
     * ถอน/ยกเลิกถอนคืนแค่รายการโอน ไม่มี `wallet` — เดิม has_pending_withdraw กับยอดจึงค้าง
     * ค่าก่อนถอน ปุ่มถอนยังกดได้ทั้งที่มีคำขอค้างอยู่ จนกว่าผู้ใช้จะรีเฟรชหน้าเอง
     */
    async function applyBotWalletResult(result) {
        if (!result.ok) return result;

        mergeBotWallet(result.data);
        if (!result.data?.wallet) await loadBotWallet({ force: true });

        return result;
    }

    async function createBotWallet() {
        return applyBotWalletResult(await mutate(address =>
            axios.post('/api/v1/ai-bot/wallet', { wallet_address: address }),
        { refresh: false }));
    }

    async function refreshBotWallet() {
        return applyBotWalletResult(await mutate(address =>
            axios.post('/api/v1/ai-bot/wallet/refresh', { wallet_address: address }),
        { refresh: false }));
    }

    /** ขอถอนกลับกระเป๋าของตัวเอง — ไม่มีช่องปลายทางโดยตั้งใจ */
    async function withdrawBotWallet(asset, amount) {
        return applyBotWalletResult(await mutate(address =>
            axios.post('/api/v1/ai-bot/wallet/withdraw', { wallet_address: address, asset, amount }),
        { refresh: false }));
    }

    async function cancelBotWalletWithdraw(id) {
        return applyBotWalletResult(await mutate(address =>
            axios.post(`/api/v1/ai-bot/wallet/withdraw/${id}/cancel`, { wallet_address: address }),
        { refresh: false }));
    }

    /** ข้อผิดพลาดล่าสุดของตัวเดินบอทฟรี (ตัวที่ใหม่สุด) — null เมื่อทุกตัวเดินผ่าน */
    const browserTickError = computed(() => {
        const list = Object.values(browserTickErrors.value);
        if (!list.length) return null;
        return list.reduce((latest, item) => (item.at > latest.at ? item : latest));
    });

    /** ข้อผิดพลาดของการสั่งบอทตัวนี้เดินจากหน้าเว็บรอบล่าสุด (null = ผ่าน) */
    function tickErrorFor(id) {
        return browserTickErrors.value[id] ?? null;
    }

    /** ความเสี่ยงล่าสุดของคู่เทรด + พาดหัวข่าวที่ทำให้บอทตัดสินใจแบบนั้น */
    async function loadRisk(pair) {
        try {
            const { data } = await axios.get('/api/v1/ai-bot/risk', { params: { pair } });
            return data?.data ?? null;
        } catch {
            return null;
        }
    }

    /**
     * ขอความเห็นจากที่ปรึกษา AI ตามแพลนที่เช่าไว้.
     *
     * คืน { ok, provider, text, reason } เสมอ ไม่ throw — ที่ปรึกษาล้มเหลวเป็นเรื่องปกติ
     * (ยังไม่ได้ตั้งคีย์ · โควตาหมด · ผู้ให้บริการล่ม) และไม่ควรทำให้หน้าจอพัง
     *
     * ⚠️ คำแนะนำนี้ไม่มีผลต่อการเทรด บอทยังตัดสินใจจากกฎเหมือนเดิม
     */
    async function askAdvice() {
        if (!wallet.value) {
            return { ok: false, reason: t('aiTrade.advisorNeedWallet') };
        }

        isAskingAdvice.value = true;
        try {
            const { data } = await axios.post('/api/v1/ai-bot/advice', { wallet_address: wallet.value });
            advice.value = data?.data ?? { ok: false, reason: t('aiTrade.errNoResponse') };
        } catch (err) {
            const { message } = readError(err, t('aiTrade.errAdvice'));
            advice.value = { ok: false, reason: message };
        } finally {
            isAskingAdvice.value = false;
        }

        return advice.value;
    }

    // ── ค่าที่หน้าจอใช้บ่อย ──────────────────────────────────────────────────
    const credits = computed(() => status.value?.credits ?? 0);
    const isActive = computed(() => !!status.value?.is_active);
    const subscription = computed(() => status.value?.subscription ?? null);
    const runningBots = computed(() => bots.value.filter(b => b.status === 'running'));
    const plans = computed(() => catalog.value?.plans ?? []);
    const strategies = computed(() => catalog.value?.strategies ?? []);
    const packs = computed(() => catalog.value?.packs ?? []);
    const rentalDays = computed(() => catalog.value?.rental_days ?? [1, 7, 30]);

    /*
     * อะไรเปิดใช้จริงแล้ว — อ่านจากเซิร์ฟเวอร์ ไม่ใช่เดาหรือฮาร์ดโค้ดในหน้าจอ
     *
     * ตั้งค่าปริยายเป็น false ตอนแคตตาล็อกยังไม่โหลด เพื่อให้จอเริ่มจาก "ยังไม่เปิด"
     * แล้วค่อยเปิดเมื่อรู้จริง — เดาว่าเปิดไว้ก่อนแล้วผิด แปลว่าผู้ใช้กดปุ่มที่ใช้ไม่ได้
     */
    const liveEnabled = computed(() => catalog.value?.features?.live_trading === true);
    const topupEnabled = computed(() => catalog.value?.features?.credit_topup === true);

    /** เปิดให้คนทั่วไปเช่าแล้วหรือยัง — ทีมงานข้ามด่านนี้ได้ */
    const salesOpen = computed(() => catalog.value?.features?.sales_open === true);

    /** กระเป๋านี้เป็นของทีมงานไหม (เซิร์ฟเวอร์เป็นคนตัดสิน หน้าเว็บแค่แสดงผลตาม) */
    const isAdminWallet = computed(() => status.value?.is_admin === true);

    /** เช่าได้ไหมในตอนนี้ — ปิดขายอยู่และไม่ใช่ทีมงาน = ยังเช่าไม่ได้ */
    const canRent = computed(() => salesOpen.value || isAdminWallet.value);

    /**
     * ยังรับโบนัสต้อนรับได้ไหม — เซิร์ฟเวอร์บอกว่ารับไปแล้วหรือยัง
     *
     * เดิมดูแค่ "เครดิตเป็นศูนย์" ซึ่งจริงอีกครั้งหลังใช้เครดิตหมด ปุ่มจึงโผล่กลับมาให้กดรับซ้ำ
     * (สถานะจากเซิร์ฟเวอร์รุ่นเก่าที่ยังไม่มีธงนี้ ถอยไปใช้เกณฑ์เดิม)
     */
    const welcomeAvailable = computed(() => {
        const s = status.value;
        if (!s) return false;
        if (typeof s.welcome_claimed !== 'boolean') return credits.value === 0;
        return !s.welcome_claimed && Number(s.welcome_bonus ?? 0) > 0;
    });

    /**
     * บอทตัวนี้เงียบไปนานผิดปกติไหม (นาที) — null เมื่อยังไม่เคยเดินหรือไม่ได้เปิดอยู่
     *
     * ตัวจับเวลาฝั่งเซิร์ฟเวอร์ตายเงียบได้ และหน้าจอก็ยังวาดจุดเขียวกะพริบต่อไป
     * เพราะดูแค่ `status === 'running'` ซึ่งเป็นสิ่งที่ผู้ใช้ตั้งไว้ ไม่ใช่สิ่งที่เกิดขึ้นจริง
     */
    function minutesSinceRun(item) {
        if (!item || item.status !== 'running' || !item.last_run_at) return null;

        const last = new Date(item.last_run_at).getTime();
        if (Number.isNaN(last)) return null;

        return Math.floor((Date.now() - last) / 60000);
    }

    /** เกินสองเท่าของรอบที่ช้าที่สุด (5 นาที) = ผิดปกติพอที่จะเตือน */
    function isStale(item) {
        // เซิร์ฟเวอร์ตัดสินให้แล้วจากสัญญาณชีพของวอร์กเกอร์ — เชื่อถือกว่าการนับนาทีฝั่งเบราว์เซอร์
        if (item?.status === 'running' && item?.online === false) return true;

        const minutes = minutesSinceRun(item);

        return minutes !== null && minutes >= 10;
    }

    /**
     * ทำไมแพลนไม่ให้บอทตัวนี้เดิน — ข้อความตามภาษา หรือ '' เมื่อไม่ใช่เรื่องของแพลน
     *
     * ดูทั้งบอทที่ระบบพักไว้ (pause_reason) และบอทที่ยังเปิดอยู่แต่แพลนไม่ให้เดินแล้ว
     * (offline_reason) — แยกให้ออกจาก "วอร์กเกอร์เงียบ" ที่ระบบกู้คืนเอง ผู้ใช้ต้องรู้ว่า
     * ครั้งนี้ต้องต่ออายุแพลน ไม่ใช่นั่งรอ
     */
    function planReasonText(item) {
        if (!item) return '';

        const code = item.status === 'paused' ? item.pause_reason
            : item.status === 'running' ? item.offline_reason : null;
        const key = PLAN_REASON_KEYS[code];

        return key ? t(key) : '';
    }

    /*
     * มูลค่าพอร์ตทดลอง = เงินสด + ของที่ถืออยู่ "ตีด้วยราคาตลาด"
     *
     * เดิมตีด้วยราคาทุน ซึ่งทำให้ไม้ที่กำลังติดลบไม่ปรากฏเลย — ซื้อที่ $100
     * ราคาร่วงเหลือ $50 พอร์ตยังโชว์เต็ม ขาดทุนจะโผล่ก็ต่อเมื่อบอทยอมปิดไม้
     * ซึ่งอาจไม่เกิดขึ้นเลย ผู้ใช้จึงตัดสินใจ "เช่าจริงไหม" จากตัวเลขที่สวยเกินจริง
     *
     * เซิร์ฟเวอร์คำนวณ equity มาให้แล้ว (เห็นราคาจริง) — ใช้ค่านั้นเป็นหลัก
     * ส่วนการบวกเองเป็นทางสำรองตอน payload เก่ายังค้างอยู่ในหน้าที่เปิดทิ้งไว้
     */
    const demoEquity = computed(() => {
        if (!demo.value) return 0;

        const fromServer = demo.value.summary?.equity;
        if (fromServer != null) return Number(fromServer);

        const held = (demo.value.positions ?? [])
            .reduce((sum, p) => sum + Number(p.market_value ?? p.cost_basis ?? 0), 0);

        return Number(demo.value.account?.balance || 0) + held;
    });

    /** กำไร/ขาดทุนของไม้ที่ยังไม่ปิด — ส่วนที่เคยถูกซ่อนไว้ทั้งหมด */
    const demoUnrealized = computed(() => Number(demo.value?.summary?.unrealized_pnl ?? 0));

    /** กำไร/ขาดทุนที่ปิดไม้แล้ว (ตัวเลขที่ "เกิดขึ้นจริง" ในพอร์ตทดลอง) */
    const demoPnl = computed(() => Number(demo.value?.summary?.realized_pnl ?? 0));

    /** ผลรวมที่ผู้ใช้ควรใช้ตัดสินใจ — ปิดแล้ว + ที่ยังค้างอยู่ */
    const demoTotalPnl = computed(() => demoPnl.value + demoUnrealized.value);

    const demoPnlPct = computed(() => {
        const start = Number(demo.value?.account?.starting_balance ?? 0);
        return start > 0 ? (demoPnl.value / start) * 100 : 0;
    });

    const demoResetsLeft = computed(() => {
        const account = demo.value?.account;
        if (!account) return 0;
        return Math.max(0, (account.resets_per_day ?? 0) - (account.resets_used_today ?? 0));
    });

    const quotaText = computed(() => {
        const q = status.value?.quota;
        return q ? `${q.used_bots}/${q.max_bots}` : '0/0';
    });

    /** เครดิตที่ต้องใช้สำหรับแพลน + จำนวนวันที่เลือก */
    function costOf(planCode, days) {
        const plan = plans.value.find(p => p.code === planCode);
        return plan ? plan.credits_per_day * days : 0;
    }

    function canAfford(planCode, days) {
        return credits.value >= costOf(planCode, days);
    }

    function strategyByCode(code) {
        return strategies.value.find(s => s.code === code) || null;
    }

    // ── ป้ายชื่อตามภาษา (ใช้ทั้งการ์ดในหน้าเทรดและหน้า /ai-trade) ────────────
    /** แพลน หรือ subscription (ซึ่งมี plan_name / plan_name_th) */
    const planLabel = (plan) => (plan?.plan_code || plan?.plan_name
        ? pickLocalized(plan, 'plan_name')
        : pickLocalized(plan, 'name'));

    const planDescription = (plan) => pickLocalized(plan, 'description');

    /** กลยุทธ์จากแคตตาล็อก หรือจากบอทที่ backend ส่ง strategy_name / strategy_name_th มาแล้ว */
    const strategyLabel = (item) => (item?.strategy_name || item?.strategy_name_th
        ? pickLocalized(item, 'strategy_name')
        : pickLocalized(item, 'name'));

    const strategyDescription = (strategy) => pickLocalized(strategy, 'description');

    /** จุดเด่นของแพลน — seeder เก็บทั้ง features (EN) และ features_th */
    function planFeatures(plan) {
        if (!plan) return [];
        const thai = plan.features_th;
        const english = plan.features;
        const chosen = locale.value === 'th' ? thai || english : english || thai;
        return Array.isArray(chosen) ? chosen : [];
    }

    /**
     * มุมมองตลาดล่าสุดที่ AI สรุปไว้ — ตัวที่มีผลต่อการเทรดจริง
     *
     * ต่างจาก askAdvice() ที่เป็นคำแนะนำให้คนอ่านเฉยๆ · ล้มแล้วไม่ throw
     * เพราะแผงนี้เป็นข้อมูลประกอบ ไม่ควรทำให้ทั้งหน้าพัง
     */
    async function loadMarketView() {
        isLoadingMarketView.value = true;

        try {
            const { data } = await axios.get('/api/v1/ai-bot/market-view');
            if (data?.success) marketView.value = data.data;
        } catch {
            marketView.value = null;
        } finally {
            isLoadingMarketView.value = false;
        }

        return marketView.value;
    }

    return {
        // state
        catalog, status, statusError, error, needsVerification,
        marketView, isLoadingMarketView,
        isLoadingCatalog, isLoadingStatus, isWorking,
        wallet, isConnected,
        demo, isLoadingDemo,
        trades, isLoadingTrades,
        botWallet, isLoadingBotWallet,
        advice, isAskingAdvice,
        browserLoopActive, lastBrowserTick, browserTickLog, browserTickError,
        // derived
        credits, isActive, subscription, bots, runningBots,
        plans, strategies, packs, rentalDays, quotaText,
        liveEnabled, topupEnabled, salesOpen, isAdminWallet, canRent, welcomeAvailable,
        minutesSinceRun, isStale, planReasonText, tickErrorFor,
        demoEquity, demoPnl, demoPnlPct, demoResetsLeft, demoUnrealized, demoTotalPnl,
        runsInCloud, browserBots,
        // actions
        loadCatalog, loadStatus, subscribe, cancel, claimWelcome, requestTopup,
        createBot, updateBot, setBotState, setBotMode, deleteBot,
        loadDemo, resetDemo, loadRisk, askAdvice, loadMarketView,
        loadTrades,
        loadBotWallet, createBotWallet, refreshBotWallet, withdrawBotWallet, cancelBotWalletWithdraw,
        keepBrowserBotsRunning, tickBot,
        costOf, canAfford, strategyByCode,
        // ป้ายชื่อตามภาษา
        planLabel, planDescription, planFeatures, strategyLabel, strategyDescription,
    };
}
