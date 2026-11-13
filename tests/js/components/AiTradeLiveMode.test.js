/**
 * TPIX TRADE — หน้า /ai-trade: โหมดเงินจริงที่บอทส่งคำสั่งเอง
 *
 * กัน:
 *  - สลับบอทเป็นโหมดจริงโดยไม่ถามยืนยัน ทั้งที่เงินจริงจะออกจากกระเป๋าบอทเองโดยไม่ถามทีละไม้
 *  - หน้ายังบอกว่า "ระบบส่งคำสั่งแทนไม่ได้" หลังเปิดให้บอทส่งคำสั่งเองแล้ว
 *  - ราคาแพลนโชว์ TPIX/วัน ที่ไม่ใช่ยอดที่ถูกตัดจริง
 *  - บอทที่มีคำสั่งค้าง / คู่ที่เทรดเงินจริงไม่ได้ ไม่มีอะไรบอกบนการ์ด
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, computed, reactive } from 'vue';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const WALLET = '0x1111111111111111111111111111111111111111';

const wallet = reactive({ isConnected: true, address: WALLET, openConnectModal: vi.fn(), verifyOwnership: vi.fn() });
const showToast = vi.fn();

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { success: true, data: [] } })) } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));
vi.mock('@/Composables/useToasts', () => ({ showToast: (...args) => showToast(...args) }));
vi.mock('@/Composables/useSounds', () => ({
    playClickSound: vi.fn(),
    playErrorSound: vi.fn(),
    playNotificationSound: vi.fn(),
}));

// แผงย่อยมีเทสต์ของตัวเอง — ที่นี่สนใจแค่ว่าหน้าเลือกวาดอะไร
const stub = (name) => ({ default: { name, template: `<div data-stub="${name}" />` } });
vi.mock('@/Components/PageArt.vue', () => stub('PageArt'));
vi.mock('@/Components/CoinIcon.vue', () => stub('CoinIcon'));
vi.mock('@/Components/Trading/AiDemoPanel.vue', () => stub('AiDemoPanel'));
vi.mock('@/Components/Trading/AiBotWalletPanel.vue', () => stub('AiBotWalletPanel'));
vi.mock('@/Components/Trading/AiRiskPanel.vue', () => stub('AiRiskPanel'));
vi.mock('@/Components/Trading/AiMarketViewPanel.vue', () => stub('AiMarketViewPanel'));
vi.mock('@/Components/Trading/AiLivePortfolio.vue', () => stub('AiLivePortfolio'));
vi.mock('@/Components/AiTrade/AiTradeTopupModal.vue', () => stub('AiTradeTopupModal'));

const state = {
    catalog: ref({ limits: {}, timeframes: ['1h'] }),
    status: ref({ unlocked_strategies: [], quota: { used_bots: 1, max_bots: 3 } }),
    needsVerification: ref(false),
    isWorking: ref(false),
    isAskingAdvice: ref(false),
    advice: ref(null),
    credits: ref(1000),
    isActive: ref(true),
    subscription: ref({ plan_code: 'pro', plan_name: 'Pro', days_remaining: 5 }),
    bots: ref([]),
    plans: ref([
        { code: 'free', name: 'Free', tier: 'free', execution: 'browser', credits_per_day: 0, price_tpix_per_day: 0 },
        { code: 'pro', name: 'Pro', tier: 'pro', execution: 'cloud', credits_per_day: 30, price_tpix_per_day: 300 },
    ]),
    packs: ref([{ code: 'pack_100', credits: 100, price_tpix: 1200, bonus: 0 }]),
    strategies: ref([]),
    rentalDays: ref([1, 7, 30]),
    quotaText: ref('1/3'),
    liveEnabled: ref(true),
    liveExecution: ref(true),
    live: ref(null),
    tpixPerCredit: ref(12),
    topupEnabled: ref(true),
    salesOpen: ref(true),
    isAdminWallet: ref(false),
    canRent: ref(true),
    welcomeAvailable: ref(false),
    browserLoopActive: ref(false),
    browserBots: ref([]),
    lastBrowserTick: ref(null),
    browserTickError: ref(null),
    demo: ref(null),
    isLoadingDemo: ref(false),
    demoResetsLeft: ref(0),
    marketView: ref(null),
    isLoadingMarketView: ref(false),
};

/** คำสั่งที่ยังไม่จบ แยกรายบอท (ตัวปลอมของ pendingOrderFor) */
const pendingOrders = reactive({});

const setBotMode = vi.fn();
const deleteBot = vi.fn();
const loadLive = vi.fn(() => Promise.resolve());
const loadDemo = vi.fn(() => Promise.resolve());

vi.mock('@/Composables/useAiBot', () => ({
    readPendingTopup: () => null,
    writePendingTopup: vi.fn(),
    useAiBot: () => ({
        ...state,
        runningBots: computed(() => state.bots.value.filter(b => b.status === 'running')),
        pendingOrderFor: id => pendingOrders[id] ?? null,
        loadCatalog: vi.fn(() => Promise.resolve()),
        loadStatus: vi.fn(() => Promise.resolve()),
        loadDemo,
        loadLive,
        loadMarketView: vi.fn(() => Promise.resolve()),
        loadRisk: vi.fn(() => Promise.resolve(null)),
        keepBrowserBotsRunning: vi.fn(),
        setBotMode,
        deleteBot,
        setBotState: vi.fn(() => Promise.resolve({ ok: true })),
        planReasonText: () => '',
        isStale: () => false,
        minutesSinceRun: () => null,
        strategyByCode: () => null,
        costOf: (code, days) => (state.plans.value.find(p => p.code === code)?.credits_per_day ?? 0) * days,
        canAfford: () => true,
        planLabel: p => p?.plan_name || p?.name || '',
        planDescription: () => '',
        planFeatures: () => [],
        strategyLabel: item => item?.strategy_name || item?.name || '',
        strategyDescription: () => '',
    }),
}));

const AiTrade = (await import('@/Pages/AiTrade.vue')).default;

function botRow(overrides = {}) {
    return {
        id: 7,
        name: 'Grid BTC',
        pair: 'BTC/USDT',
        strategy: 'grid',
        strategy_name: 'Grid',
        timeframe: '1h',
        status: 'running',
        mode: 'demo',
        online: true,
        risk_level: 'low',
        risk: { stop_loss_pct: 5, take_profit_pct: 10 },
        params: {},
        last_reason: 'Waiting for the next grid level',
        pending_order: null,
        live_supported: true,
        ...overrides,
    };
}

async function mountPage() {
    const wrapper = mount(AiTrade);
    await flushPromises();
    return wrapper;
}

const modeToggle = wrapper => wrapper.find('[data-testid="bot-mode-toggle"]');

beforeEach(() => {
    wallet.isConnected = true;
    wallet.address = WALLET;
    state.liveEnabled.value = true;
    state.liveExecution.value = true;
    state.live.value = null;
    state.isWorking.value = false;
    state.bots.value = [botRow()];
    Object.keys(pendingOrders).forEach((id) => { delete pendingOrders[id]; });

    showToast.mockClear();
    loadLive.mockClear();
    loadDemo.mockClear();
    setBotMode.mockReset();
    setBotMode.mockResolvedValue({ ok: true, data: {} });
    deleteBot.mockReset();
    vi.restoreAllMocks();
});

describe('AiTrade — switching a bot to live mode', () => {
    it('asks before going live and does nothing when the user declines', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');
        await flushPromises();

        expect(confirm).toHaveBeenCalledTimes(1);
        const text = confirm.mock.calls[0][0];
        expect(text).toContain('Grid BTC');
        expect(text).toContain('BTC/USDT');
        expect(text).toContain('real money');
        expect(text).toContain('bot wallet');
        expect(text).toContain('automatically');
        expect(text).toContain('Losses are possible');
        expect(setBotMode).not.toHaveBeenCalled();
    });

    it('includes the order size limits once the live portfolio has loaded them', async () => {
        state.live.value = { limits: { min_order_usd: 10, max_order_usd: 500, slippage_bps: 100 } };
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');

        expect(confirm.mock.calls[0][0]).toContain('$10–$500');
    });

    it('leaves the limits out rather than guessing when they are not loaded', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');

        expect(confirm.mock.calls[0][0]).not.toContain('$');
    });

    it('switches to live after the user confirms and refreshes the live portfolio', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');
        await flushPromises();

        expect(setBotMode).toHaveBeenCalledWith(7, 'live');
        expect(loadLive).toHaveBeenCalledWith({ force: true });
        expect(showToast).toHaveBeenCalledWith({ text: 'Mode switched', type: 'success' });
    });

    it('keeps the old behaviour — no confirmation — while live mode only produces signals', async () => {
        state.liveExecution.value = false;
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');
        await flushPromises();

        expect(confirm).not.toHaveBeenCalled();
        expect(setBotMode).toHaveBeenCalledWith(7, 'live');
        expect(loadLive).not.toHaveBeenCalled();
    });

    it('does not ask when going back to demo', async () => {
        state.bots.value = [botRow({ mode: 'live' })];
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');
        await flushPromises();

        expect(confirm).not.toHaveBeenCalled();
        expect(setBotMode).toHaveBeenCalledWith(7, 'demo');
    });

    it('stops before asking when the pair cannot trade real money', async () => {
        state.bots.value = [botRow({ pair: 'DOGE/USDT', live_supported: false })];
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');
        await flushPromises();

        expect(confirm).not.toHaveBeenCalled();
        expect(setBotMode).not.toHaveBeenCalled();
        expect(showToast).toHaveBeenCalledWith(expect.objectContaining({ type: 'error', text: expect.stringContaining('DOGE/USDT cannot trade real money yet') }));
    });

    it('shows the localized reason when the server refuses the switch', async () => {
        state.bots.value = [botRow({ mode: 'live' })];
        setBotMode.mockResolvedValue({ ok: false, error: { code: 'LIVE_POSITION_OPEN', message: 'Close the open position before switching modes' } });

        const wrapper = await mountPage();
        await modeToggle(wrapper).trigger('click');
        await flushPromises();

        expect(showToast).toHaveBeenCalledWith({ text: 'Close the open position before switching modes', type: 'error' });
    });

    it('shows the localized reason when a bot with a live position cannot be deleted', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        deleteBot.mockResolvedValue({ ok: false, error: { code: 'LIVE_POSITION_OPEN', message: 'Close the open position before deleting this bot' } });

        const wrapper = await mountPage();
        const remove = wrapper.findAll('button').find(b => b.text() === 'Delete' || b.text() === 'Remove');
        await remove.trigger('click');
        await flushPromises();

        expect(deleteBot).toHaveBeenCalledWith(7);
        expect(showToast).toHaveBeenCalledWith({ text: 'Close the open position before deleting this bot', type: 'error' });
    });
});

describe('AiTrade — what the bot card says', () => {
    it('marks a bot whose order is still in progress', async () => {
        pendingOrders[7] = { id: 31, status: 'queued' };

        const wrapper = await mountPage();
        const badge = wrapper.find('[data-testid="bot-order-pending"]');

        expect(badge.exists()).toBe(true);
        expect(badge.text()).toBe('Order in progress');
        expect(wrapper.text()).not.toMatch(/\b(sold|bought)\b/i);
    });

    it('shows no order badge when nothing is pending', async () => {
        const wrapper = await mountPage();

        expect(wrapper.find('[data-testid="bot-order-pending"]').exists()).toBe(false);
    });

    it('warns when a live bot trades a pair that cannot use real money', async () => {
        state.bots.value = [botRow({ mode: 'live', pair: 'DOGE/USDT', live_supported: false })];

        const wrapper = await mountPage();

        expect(wrapper.find('[data-testid="bot-live-unsupported"]').text()).toContain('DOGE/USDT cannot trade real money yet');
    });

    it('keeps the warning off demo bots and supported pairs', async () => {
        state.bots.value = [
            botRow({ id: 1, mode: 'demo', live_supported: false }),
            botRow({ id: 2, mode: 'live', live_supported: true }),
        ];

        const wrapper = await mountPage();

        expect(wrapper.find('[data-testid="bot-live-unsupported"]').exists()).toBe(false);
    });

    it('drops the "awaiting your confirmation" label once the bot trades by itself', async () => {
        state.bots.value = [botRow({ mode: 'live', last_reason: '[รอยืนยัน] Buy signal at 60,000' })];

        const executing = await mountPage();
        expect(executing.text()).not.toContain('Awaiting your confirmation');
        expect(executing.text()).toContain('Buy signal at 60,000');
        executing.unmount();

        state.liveExecution.value = false;
        const signalOnly = await mountPage();
        expect(signalOnly.text()).toContain('Awaiting your confirmation');
    });
});

describe('AiTrade — live copy and panels', () => {
    it('says the bot trades by itself when live execution is on', async () => {
        const wrapper = await mountPage();

        expect(wrapper.find('[data-testid="live-open-notice"]').text()).toContain('sends real orders on BSC by itself');
        expect(wrapper.text()).not.toContain('we cannot place orders for you');
        expect(wrapper.text()).not.toContain('proposes signals');
    });

    it('keeps the signal-only wording while live execution is off', async () => {
        state.liveExecution.value = false;

        const wrapper = await mountPage();

        expect(wrapper.find('[data-testid="live-open-notice"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('proposes signals');
    });

    it('renders the live portfolio only when live execution is on and a wallet is connected', async () => {
        const on = await mountPage();
        expect(on.find('[data-stub="AiLivePortfolio"]').exists()).toBe(true);
        expect(on.find('[data-stub="AiBotWalletPanel"]').exists()).toBe(true);
        on.unmount();

        state.liveExecution.value = false;
        const off = await mountPage();
        expect(off.find('[data-stub="AiLivePortfolio"]').exists()).toBe(false);
        off.unmount();

        state.liveExecution.value = true;
        wallet.isConnected = false;
        wallet.address = null;
        const disconnected = await mountPage();
        expect(disconnected.find('[data-stub="AiLivePortfolio"]').exists()).toBe(false);
    });
});

describe('AiTrade — plan price', () => {
    it('shows the credits that are really deducted, with TPIX only as an approximation', async () => {
        const wrapper = await mountPage();
        const plans = wrapper.find('#plans');

        // 30 เครดิต/วัน × 7 วัน (ค่าปริยาย) = 210 เครดิต · ≈ 210 × 12 TPIX
        expect(plans.text()).toContain('30 credits/day');
        expect(plans.text()).toContain('7 days = 210 credits');
        expect(plans.text()).toContain('≈ 2,520 TPIX approx.');
        // price_tpix_per_day (300) และ 300 × 7 ไม่ใช่ยอดที่ถูกตัด — ต้องไม่อยู่บนจอ
        expect(plans.text()).not.toContain('300');
        expect(plans.text()).not.toContain('2,100');
        expect(plans.text()).not.toContain('TPIX/day');
    });

    it('recomputes the total when another rental length is picked', async () => {
        const wrapper = await mountPage();
        const dayButton = wrapper.find('#plans').findAll('button').find(b => b.text() === '30');

        await dayButton.trigger('click');

        expect(wrapper.find('#plans').text()).toContain('30 days = 900 credits');
        expect(wrapper.find('#plans').text()).toContain('≈ 10,800 TPIX approx.');
    });

    it('leaves free plans as free', async () => {
        const wrapper = await mountPage();

        expect(wrapper.find('#plans').text()).toContain('Nothing to pay');
        expect(wrapper.find('#plans').text()).toContain('Start free');
    });
});
