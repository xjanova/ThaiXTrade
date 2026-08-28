/**
 * TPIX TRADE — useAiBot
 * ตัวกลางคุยกับ /api/v1/ai-bot/* (ระบบเช่าบอทเทรดคลาวด์)
 *
 * เป็น singleton ระดับโมดูล — การ์ดในหน้าเทรดกับหน้า /ai-trade ใช้ state ก้อนเดียวกัน
 * เช่าบอทที่หน้าไหนก็ตาม อีกหน้าเห็นทันทีโดยไม่ต้องยิงซ้ำ
 *
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';

const catalog = ref(null);          // { plans, strategies, packs, rental_days, timeframes, limits }
const status = ref(null);           // { credits, is_active, subscription, quota, bots, unlocked_strategies }
const isLoadingCatalog = ref(false);
const isLoadingStatus = ref(false);
const isWorking = ref(false);       // มี action ที่เขียนข้อมูลกำลังทำงาน
const error = ref(null);
/** true เมื่อ backend ตอบ WALLET_NOT_VERIFIED — ต้องเซ็นข้อความยืนยันกระเป๋าก่อน */
const needsVerification = ref(false);

// โหมดทดลอง — พอร์ตกระดาษที่ใช้ราคาจริง { account, positions, trades, summary }
const demo = ref(null);
const isLoadingDemo = ref(false);

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
 * เก็บ interval ไว้ระดับโมดูล (ไม่ใช่ในคอมโพเนนต์) เพราะหน้าเทรดกับหน้า /ai-trade
 * เรียก composable นี้พร้อมกันได้ ถ้าเก็บแยกจะเดินบอทซ้อนกันสองตัว
 */
const browserLoopId = ref(null);
const browserLoopActive = ref(false);
const lastBrowserTick = ref(null);
const browserTickLog = ref([]);

let catalogPromise = null;

function readError(err, fallback = 'ทำรายการไม่สำเร็จ') {
    const payload = err?.response?.data?.error;
    if (payload?.code === 'WALLET_NOT_VERIFIED') needsVerification.value = true;

    return { code: payload?.code || 'REQUEST_FAILED', message: payload?.message || fallback };
}

export function useAiBot() {
    const walletStore = useWalletStore();
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

    async function loadStatus() {
        if (!wallet.value) {
            status.value = null;
            return null;
        }

        isLoadingStatus.value = true;
        try {
            const { data } = await axios.get('/api/v1/ai-bot/status', {
                params: { wallet_address: wallet.value },
            });
            if (data?.success) {
                status.value = data.data;
                needsVerification.value = false;
            }
            return status.value;
        } catch (err) {
            // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า — ไม่ใช่ error ที่ต้องเด้งให้ผู้ใช้ตกใจ
            error.value = readError(err, 'โหลดสถานะบอทไม่สำเร็จ');
            status.value = null;
            return null;
        } finally {
            isLoadingStatus.value = false;
        }
    }

    /** ยิง action ที่เขียนข้อมูล แล้ว sync status กลับมา */
    async function mutate(request, { refresh = true } = {}) {
        if (!wallet.value) return { ok: false, error: { code: 'NO_WALLET', message: 'กรุณาเชื่อมกระเป๋าก่อน' } };

        isWorking.value = true;
        error.value = null;

        try {
            const { data } = await request(wallet.value);

            // endpoint ที่คืน status เต็มอยู่แล้ว (subscribe/cancel) ใช้ค่านั้นเลย
            if (data?.data?.bots && data?.data?.quota) {
                status.value = data.data;
            } else if (refresh) {
                await loadStatus();
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

    const subscribe = (planCode, days) => mutate(address =>
        axios.post('/api/v1/ai-bot/subscribe', { wallet_address: address, plan_code: planCode, days })
    );

    const cancel = () => mutate(address =>
        axios.post('/api/v1/ai-bot/cancel', { wallet_address: address })
    );

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

    /** โหลดพอร์ตทดลอง — เงิน ของที่ถือ ประวัติไม้ และสรุปผล */
    async function loadDemo() {
        if (!wallet.value) return null;

        isLoadingDemo.value = true;

        try {
            const { data } = await axios.get('/api/v1/ai-bot/demo', {
                params: { wallet_address: wallet.value },
            });
            demo.value = data?.data ?? null;
            return demo.value;
        } catch (err) {
            // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า ไม่ใช่ error ที่ต้องเด้งเตือน
            if (err?.response?.status === 403) needsVerification.value = true;
            return null;
        } finally {
            isLoadingDemo.value = false;
        }
    }

    /** ล้างพอร์ตทดลองกลับไปตั้งต้น (จำกัดจำนวนครั้งต่อวันที่ฝั่งเซิร์ฟเวอร์) */
    async function resetDemo() {
        const result = await mutate(address =>
            axios.post('/api/v1/ai-bot/demo/reset', { wallet_address: address }),
        { refresh: false });

        if (result.ok) demo.value = result.data;

        return result;
    }

    // ── ตัวเดินบอทของแพลนฟรี ────────────────────────────────────────────────

    /** แพลนที่ถืออยู่ให้เซิร์ฟเวอร์เดินบอทให้ไหม */
    const runsInCloud = computed(() => status.value?.subscription?.execution === 'cloud');

    /** บอทที่ต้องให้หน้าเว็บสั่งเดินเอง (แพลนฟรี + สถานะกำลังทำงาน) */
    const browserBots = computed(() =>
        runsInCloud.value ? [] : bots.value.filter(b => b.status === 'running')
    );

    /** สั่งบอทตัวหนึ่งคิดหนึ่งรอบ */
    async function tickBot(id) {
        if (!wallet.value) return null;

        try {
            const { data } = await axios.post(`/api/v1/ai-bot/bots/${id}/tick`, {
                wallet_address: wallet.value,
            });
            return data?.data ?? null;
        } catch (err) {
            // บอทตัวเดียวพังต้องไม่หยุดลูปทั้งหมด
            return { error: readError(err).message };
        }
    }

    /** เดินบอทฟรีทุกตัวหนึ่งรอบ */
    async function runBrowserCycle() {
        const targets = browserBots.value;

        if (!targets.length) return;

        for (const bot of targets) {
            const result = await tickBot(bot.id);

            if (result && !result.skipped) {
                lastBrowserTick.value = new Date().toISOString();
                browserTickLog.value = [
                    { at: lastBrowserTick.value, bot: bot.name, action: result.action, reason: result.reason },
                    ...browserTickLog.value,
                ].slice(0, 20);
            }
        }

        // สถานะบอทเปลี่ยนหลังเดินรอบ — ดึงพอร์ตทดลองใหม่ให้ตัวเลขตรง
        await loadDemo();
    }

    /**
     * เริ่มเดินบอทฟรีจากหน้าเว็บ.
     *
     * ปลอดภัยที่จะเรียกซ้ำ — ถ้าเดินอยู่แล้วจะไม่สร้างตัวจับเวลาใหม่
     */
    function startBrowserLoop(intervalSeconds = 30) {
        if (browserLoopId.value !== null) return;

        browserLoopActive.value = true;
        runBrowserCycle();
        browserLoopId.value = window.setInterval(runBrowserCycle, Math.max(15, intervalSeconds) * 1000);
    }

    function stopBrowserLoop() {
        if (browserLoopId.value !== null) {
            window.clearInterval(browserLoopId.value);
            browserLoopId.value = null;
        }
        browserLoopActive.value = false;
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
            return { ok: false, reason: 'กรุณาเชื่อมกระเป๋าก่อน' };
        }

        isAskingAdvice.value = true;
        try {
            const { data } = await axios.post('/api/v1/ai-bot/advice', { wallet_address: wallet.value });
            advice.value = data?.data ?? { ok: false, reason: 'ไม่ได้รับคำตอบจากเซิร์ฟเวอร์' };
        } catch (err) {
            const { message } = readError(err, 'ขอคำแนะนำไม่สำเร็จ');
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
    const bots = computed(() => status.value?.bots ?? []);
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
        const minutes = minutesSinceRun(item);

        return minutes !== null && minutes >= 10;
    }

    // มูลค่าพอร์ตทดลอง = เงินสด + ต้นทุนของที่ถืออยู่
    // ใช้ต้นทุน ไม่ใช่ราคาตลาด เพราะราคาตลาดเปลี่ยนทุกวินาทีและยังไม่ใช่เงินจริง
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

    /** กำไรขาดทุนสะสมของพอร์ตทดลองเทียบทุนตั้งต้น (เฉพาะไม้ที่ปิดแล้ว) */
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
        catalog, status, error, needsVerification,
        marketView, isLoadingMarketView,
        isLoadingCatalog, isLoadingStatus, isWorking,
        wallet, isConnected,
        demo, isLoadingDemo,
        advice, isAskingAdvice,
        browserLoopActive, lastBrowserTick, browserTickLog,
        // derived
        credits, isActive, subscription, bots, runningBots,
        plans, strategies, packs, rentalDays, quotaText,
        liveEnabled, topupEnabled, salesOpen, isAdminWallet, canRent, minutesSinceRun, isStale,
        demoEquity, demoPnl, demoPnlPct, demoResetsLeft, demoUnrealized, demoTotalPnl,
        runsInCloud, browserBots,
        // actions
        loadCatalog, loadStatus, subscribe, cancel, claimWelcome, requestTopup,
        createBot, updateBot, setBotState, setBotMode, deleteBot,
        loadDemo, resetDemo, loadRisk, askAdvice, loadMarketView,
        startBrowserLoop, stopBrowserLoop, tickBot,
        costOf, canAfford, strategyByCode,
        // ป้ายชื่อตามภาษา
        planLabel, planDescription, planFeatures, strategyLabel, strategyDescription,
    };
}
