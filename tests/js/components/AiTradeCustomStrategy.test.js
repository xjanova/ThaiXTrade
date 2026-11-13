/**
 * TPIX TRADE — หน้า /ai-trade กับโหมด "วางกลยุทธ์เอง"
 *
 * กัน:
 *  - ช่องชนิด rules ถูกวาดเป็นช่องติ๊กถูก (สาขาสุดท้ายของตารางพารามิเตอร์) แทนตัวสร้างกฎ
 *  - ตัวอย่างโครงกฎถูกติดป้าย "ชุดที่ทีมงาน backtest ไว้" ทั้งที่ไม่มีผลวัด
 *  - กฎที่ยังผิดถูกส่งไปสร้างบอท · ธง invalid ของบอทเดิมถูกส่งกลับไปให้เซิร์ฟเวอร์
 *  - แก้กฎในฟอร์มแล้วไปแก้เทมเพลตในแคตตาล็อก หรือบอทที่โชว์อยู่ในรายการ
 *  - แก้กฎแล้วหน้ายังส่งรหัสเทมเพลตเดิมไป (เซิร์ฟเวอร์ใช้เทมเพลตเป็นฐาน)
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, computed, reactive } from 'vue';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import { RULE_SCHEMA, validRules } from '../fixtures/aiRuleSchema';

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

// แผงย่อยมีเทสต์ของตัวเอง — ที่นี่ใช้ตัวสร้างกฎกับแผงทดสอบตัวจริง ที่เหลือเป็นตัวปลอม
const stub = (name) => ({ default: { name, template: `<div data-stub="${name}" />` } });
vi.mock('@/Components/PageArt.vue', () => stub('PageArt'));
vi.mock('@/Components/CoinIcon.vue', () => stub('CoinIcon'));
vi.mock('@/Components/Trading/AiDemoPanel.vue', () => stub('AiDemoPanel'));
vi.mock('@/Components/Trading/AiBotWalletPanel.vue', () => stub('AiBotWalletPanel'));
vi.mock('@/Components/Trading/AiRiskPanel.vue', () => stub('AiRiskPanel'));
vi.mock('@/Components/Trading/AiMarketViewPanel.vue', () => stub('AiMarketViewPanel'));
vi.mock('@/Components/Trading/AiLivePortfolio.vue', () => stub('AiLivePortfolio'));
vi.mock('@/Components/AiTrade/AiTradeTopupModal.vue', () => stub('AiTradeTopupModal'));

function customStrategy() {
    return {
        code: 'custom',
        name: 'Build Your Own',
        risk: 'high',
        tier: 'pro',
        available: true,
        timeframes: ['15m', '1h', '4h', '1d'],
        default_timeframe: '4h',
        params: [
            { key: 'rules', label: 'กฎเข้า/ออก', label_en: 'Entry / exit rules', type: 'rules', default: null },
            { key: 'order_size_usd', label: 'ขนาดต่อไม้', label_en: 'Order size (USD)', type: 'number', default: 100, min: 5, max: 100000, step: 5 },
            { key: 'add_to_position', label: 'ซื้อเพิ่ม', label_en: 'Add to an open position', type: 'bool', default: false },
        ],
        templates: [
            {
                code: 'trend_follow', name: 'Example: trend', name_th: 'ตัวอย่าง', tagline_en: 'Example rule set — backtest it before use', timeframe: '4h',
                params: { rules: validRules(), order_size_usd: 100, add_to_position: false },
                risk: { max_position_usd: 100, stop_loss_pct: 5, take_profit_pct: 12, max_daily_loss_usd: 50 },
            },
        ],
    };
}

function gridStrategy() {
    return {
        code: 'grid', name: 'Grid', risk: 'low', tier: 'free', available: true, timeframes: ['1h', '4h'], default_timeframe: '4h',
        params: [{ key: 'grid_levels', label: 'ชั้น', label_en: 'Profit steps', type: 'number', default: 8, min: 3, max: 60, step: 1 }],
        templates: [{ code: 'balanced', name: 'Balanced', timeframe: '4h', params: { grid_levels: 8 }, risk: { max_position_usd: 100 } }],
    };
}

const state = {
    catalog: ref(null),
    status: ref(null),
    needsVerification: ref(false),
    isWorking: ref(false),
    isAskingAdvice: ref(false),
    advice: ref(null),
    credits: ref(1000),
    isActive: ref(true),
    subscription: ref({ plan_code: 'pro', plan_name: 'Pro', days_remaining: 5 }),
    bots: ref([]),
    plans: ref([]),
    packs: ref([]),
    strategies: ref([]),
    rentalDays: ref([1, 7, 30]),
    quotaText: ref('0/3'),
    liveEnabled: ref(false),
    liveExecution: ref(false),
    live: ref(null),
    tpixPerCredit: ref(12),
    topupEnabled: ref(false),
    salesOpen: ref(false),
    isAdminWallet: ref(false),
    canRent: ref(false),
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

const createBot = vi.fn();
const updateBot = vi.fn();
const runBacktest = vi.fn();

vi.mock('@/Composables/useAiBot', () => ({
    readPendingTopup: () => null,
    writePendingTopup: vi.fn(),
    useAiBot: () => ({
        ...state,
        runningBots: computed(() => state.bots.value.filter(b => b.status === 'running')),
        pendingOrderFor: () => null,
        loadCatalog: vi.fn(() => Promise.resolve()),
        loadStatus: vi.fn(() => Promise.resolve()),
        loadDemo: vi.fn(() => Promise.resolve()),
        loadLive: vi.fn(() => Promise.resolve()),
        loadMarketView: vi.fn(() => Promise.resolve()),
        loadRisk: vi.fn(() => Promise.resolve(null)),
        keepBrowserBotsRunning: vi.fn(),
        createBot,
        updateBot,
        runBacktest,
        setBotState: vi.fn(() => Promise.resolve({ ok: true })),
        planReasonText: () => '',
        isStale: () => false,
        minutesSinceRun: () => null,
        tickErrorFor: () => null,
        strategyByCode: code => state.strategies.value.find(s => s.code === code) ?? null,
        costOf: () => 0,
        canAfford: () => true,
        planLabel: p => p?.plan_name || p?.name || '',
        planDescription: () => '',
        planFeatures: () => [],
        strategyLabel: item => item?.strategy_name || item?.name || '',
        strategyDescription: () => '',
    }),
}));

const AiTrade = (await import('@/Pages/AiTrade.vue')).default;

const byId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);

async function mountPage() {
    const wrapper = mount(AiTrade);
    await flushPromises();

    return wrapper;
}

/** เปิดฟอร์มสร้างบอทใหม่ (กลยุทธ์แรกที่เลือกได้คือ custom) */
async function openNewBot(wrapper) {
    wrapper.vm.openBuilder();
    await flushPromises();
}

async function save(wrapper) {
    await wrapper.vm.saveBot();
    await flushPromises();
}

beforeEach(() => {
    state.strategies.value = [customStrategy(), gridStrategy()];
    state.catalog.value = {
        limits: {
            max_position_usd: { min: 10, max: 1000000, default: 100 },
            stop_loss_pct: { min: 0.5, max: 50, default: 5 },
            take_profit_pct: { min: 0.5, max: 200, default: 10 },
            max_daily_loss_usd: { min: 5, max: 1000000, default: 50 },
        },
        timeframes: ['1h', '4h'],
        common_params: [{ key: 'ai_gate', label: 'ให้ AI ร่วมตัดสินใจ', label_en: 'Let the AI weigh in', type: 'bool', default: true }],
        custom_rules: RULE_SCHEMA,
    };
    state.status.value = { unlocked_strategies: ['custom', 'grid'], quota: { used_bots: 0, max_bots: 3 } };
    state.bots.value = [];

    showToast.mockClear();
    createBot.mockReset();
    createBot.mockResolvedValue({ ok: true, data: {} });
    updateBot.mockReset();
    updateBot.mockResolvedValue({ ok: true, data: {} });
    runBacktest.mockReset();
});

describe('AiTrade — build-your-own strategy form', () => {
    it('draws the rule builder and the backtest panel instead of a checkbox for the rules', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        expect(byId(wrapper, 'rule-builder').exists()).toBe(true);
        expect(byId(wrapper, 'rule-backtest').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Entry / exit rules');
        // ช่องธรรมดาของกลยุทธ์ยังวาดตามเดิม
        expect(wrapper.text()).toContain('Order size (USD)');
        expect(wrapper.text()).toContain('Add to an open position');
        expect(wrapper.text()).toContain('Let the AI weigh in');
    });

    it('starts from the first example and labels examples as examples, not team-tested presets', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        expect(byId(wrapper, 'rule-type-entry-0').element.value).toBe('ema_cross');
        expect(wrapper.text()).toContain('Example rule sets');
        expect(wrapper.text()).toContain('not presets with a team-measured result');
        expect(wrapper.text()).not.toContain('Presets our team backtested');
    });

    it('does not draw the builder for a fixed strategy', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        wrapper.vm.form.strategy = 'grid';
        await flushPromises();

        expect(byId(wrapper, 'rule-builder').exists()).toBe(false);
        expect(byId(wrapper, 'rule-backtest').exists()).toBe(false);
        expect(wrapper.text()).toContain('Presets our team backtested');
    });

    it('creates the bot with the rules in canonical form and the example code while untouched', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);
        await save(wrapper);

        expect(createBot).toHaveBeenCalledTimes(1);
        const payload = createBot.mock.calls[0][0];

        expect(payload.strategy).toBe('custom');
        expect(payload.timeframe).toBe('4h');
        expect(payload.template).toBe('trend_follow');
        expect(payload.params.rules).toEqual(validRules());
        expect(payload.params.order_size_usd).toBe(100);
    });

    it('drops the example code once a rule is edited, and sends the edited rule', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');
        await save(wrapper);

        const payload = createBot.mock.calls[0][0];

        expect(payload.template).toBeNull();
        expect(payload.params.rules.entry.blocks[0].fast).toBe(9);
    });

    it('editing the form never changes the example in the catalogue', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');
        await byId(wrapper, 'rule-remove-exit-0').trigger('click');

        expect(state.strategies.value[0].templates[0].params.rules).toEqual(validRules());
    });

    it('refuses to save invalid rules and says what to fix', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        await byId(wrapper, 'rule-field-exit-0-level').setValue('500');
        await save(wrapper);

        expect(createBot).not.toHaveBeenCalled();
        expect(showToast).toHaveBeenCalledWith(expect.objectContaining({
            type: 'error',
            text: 'Fix the invalid condition before saving: Level must be between 1 and 99',
        }));
    });

    it('refuses to save a rule set with no entry condition', async () => {
        const wrapper = await mountPage();
        await openNewBot(wrapper);

        await byId(wrapper, 'rule-remove-entry-0').trigger('click');
        await save(wrapper);

        expect(createBot).not.toHaveBeenCalled();
        expect(byId(wrapper, 'backtest-blocked').exists()).toBe(true);
        expect(byId(wrapper, 'backtest-run').attributes('disabled')).toBeDefined();
    });

    it('backtests exactly what the form holds', async () => {
        runBacktest.mockResolvedValue({ ok: false, error: { code: 'X', message: 'nope' } });

        const wrapper = await mountPage();
        await openNewBot(wrapper);

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');
        await byId(wrapper, 'backtest-run').trigger('click');
        await flushPromises();

        const sent = runBacktest.mock.calls[0][0];

        expect(sent.strategy).toBe('custom');
        expect(sent.timeframe).toBe('4h');
        expect(sent.params.rules.entry.blocks[0].fast).toBe(9);
        expect(sent.risk.take_profit_pct).toBe(12);
        expect(byId(wrapper, 'backtest-error').text()).toBe('nope');
    });
});

describe('AiTrade — editing an existing build-your-own bot', () => {
    function customBot(params = {}) {
        return {
            id: 31, name: 'My rules', pair: 'BTC/USDT', strategy: 'custom', strategy_name: 'Build Your Own', timeframe: '1h',
            status: 'paused', mode: 'demo', online: false, risk_level: 'high',
            risk: { max_position_usd: 100, stop_loss_pct: 5, take_profit_pct: 12, max_daily_loss_usd: 50 },
            params: { rules: validRules(), order_size_usd: 40, add_to_position: false, ai_gate: false, ...params },
            last_reason: '', pending_order: null, live_supported: true,
        };
    }

    it('loads the saved rules and sends them back without the invalid flag', async () => {
        state.bots.value = [customBot({ rules: { ...validRules(), invalid: true } })];

        const wrapper = await mountPage();
        wrapper.vm.openBuilder(state.bots.value[0]);
        await flushPromises();

        expect(byId(wrapper, 'rule-field-exit-0-level').element.value).toBe('70');

        await save(wrapper);

        const [id, payload] = updateBot.mock.calls[0];

        expect(id).toBe(31);
        expect(Object.keys(payload.params.rules)).toEqual(['entry', 'exit']);
        expect(payload.params.order_size_usd).toBe(40);
        expect(payload.timeframe).toBe('1h');
    });

    it('editing then closing the form leaves the listed bot untouched', async () => {
        state.bots.value = [customBot()];

        const wrapper = await mountPage();
        wrapper.vm.openBuilder(state.bots.value[0]);
        await flushPromises();

        await byId(wrapper, 'rule-field-entry-0-fast').setValue('9');

        expect(state.bots.value[0].params.rules).toEqual(validRules());
    });

    it('shows a saved block the server no longer supports and blocks saving until it is removed', async () => {
        const rules = validRules();
        rules.entry.blocks.push({ type: 'retired_block' });
        state.bots.value = [customBot({ rules })];

        const wrapper = await mountPage();
        wrapper.vm.openBuilder(state.bots.value[0]);
        await flushPromises();

        expect(byId(wrapper, 'rule-problem-entry-1').text()).toContain('no longer supported');

        await save(wrapper);
        expect(updateBot).not.toHaveBeenCalled();

        await byId(wrapper, 'rule-remove-entry-1').trigger('click');
        await save(wrapper);
        expect(updateBot).toHaveBeenCalledTimes(1);
    });
});
