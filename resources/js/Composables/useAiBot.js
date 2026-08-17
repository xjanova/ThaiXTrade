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

    /** ความเสี่ยงล่าสุดของคู่เทรด + พาดหัวข่าวที่ทำให้บอทตัดสินใจแบบนั้น */
    async function loadRisk(pair) {
        try {
            const { data } = await axios.get('/api/v1/ai-bot/risk', { params: { pair } });
            return data?.data ?? null;
        } catch {
            return null;
        }
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

    // มูลค่าพอร์ตทดลอง = เงินสด + ต้นทุนของที่ถืออยู่
    // ใช้ต้นทุน ไม่ใช่ราคาตลาด เพราะราคาตลาดเปลี่ยนทุกวินาทีและยังไม่ใช่เงินจริง
    const demoEquity = computed(() => {
        if (!demo.value) return 0;
        const held = (demo.value.positions ?? []).reduce((sum, p) => sum + Number(p.cost_basis || 0), 0);
        return Number(demo.value.account?.balance || 0) + held;
    });

    /** กำไรขาดทุนสะสมของพอร์ตทดลองเทียบทุนตั้งต้น (เฉพาะไม้ที่ปิดแล้ว) */
    const demoPnl = computed(() => Number(demo.value?.summary?.realized_pnl ?? 0));

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

    return {
        // state
        catalog, status, error, needsVerification,
        isLoadingCatalog, isLoadingStatus, isWorking,
        wallet, isConnected,
        demo, isLoadingDemo,
        // derived
        credits, isActive, subscription, bots, runningBots,
        plans, strategies, packs, rentalDays, quotaText,
        demoEquity, demoPnl, demoPnlPct, demoResetsLeft,
        // actions
        loadCatalog, loadStatus, subscribe, cancel, claimWelcome, requestTopup,
        createBot, updateBot, setBotState, setBotMode, deleteBot,
        loadDemo, resetDemo, loadRisk,
        costOf, canAfford, strategyByCode,
        // ป้ายชื่อตามภาษา
        planLabel, planDescription, planFeatures, strategyLabel, strategyDescription,
    };
}
