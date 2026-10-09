/**
 * TPIX TRADE — AiTradeCard tests
 *
 * ครอบคลุมกติกาหลักที่เจ้าของสั่งไว้:
 *  "กดแล้วไม่ได้เช่า → ขึ้นเตือนให้เติมเครดิต และมีปุ่มเข้าไปตั้งแบบละเอียด"
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js — การสลับภาษาทดสอบที่ i18n.test.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, computed } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

// ── ตัวปลอมของ store/composable (ควบคุมสถานะได้จากในเทสต์) ──────────────────
const wallet = { isConnected: false, address: null, openConnectModal: vi.fn() };

const state = {
    // สถานะที่โหลดได้แล้ว — การ์ดไม่ตกไป "ยังไม่ได้เช่า" ถ้ายังไม่รู้สถานะ (ดูเทสต์ด้านล่าง)
    status: ref({ is_active: false }),
    statusError: ref(null),
    isLoadingStatus: ref(false),
    credits: ref(0),
    isActive: ref(false),
    subscription: ref(null),
    bots: ref([]),
    needsVerification: ref(false),
    isWorking: ref(false),
    plans: ref([
        { code: 'starter', name: 'Starter', name_th: 'สตาร์ทเตอร์', tier: 'basic', credits_per_day: 30, max_bots: 1, max_capital_usd: 500, badge: null, description: 'One bot', description_th: 'บอท 1 ตัว' },
        { code: 'vip', name: 'VIP', name_th: 'วีไอพี', tier: 'vip', credits_per_day: 240, max_bots: 10, max_capital_usd: null, badge: 'VIP', description: 'Everything', description_th: 'ทุกกลยุทธ์' },
    ]),
    strategies: ref(new Array(8).fill(0).map((_, i) => ({ code: `s${i}` }))),
    /*
     * ต้องตรงกับที่ API ส่งจริง — เครดิตขายด้วย TPIX เท่านั้น ไม่มี price_usd แล้ว
     *
     * ฟิกซ์เจอร์เดิมปลอม price_usd ไว้ การ์ดจึงอ่านคีย์ที่ไม่มีอยู่จริงได้โดยเทสต์
     * ไม่ทักอะไร แล้วบนของจริงขึ้นเป็น "$" เปล่าๆ ให้ผู้ใช้เห็น
     */
    packs: ref([
        { code: 'pack_500', credits: 500, price_tpix: 50, bonus: 0 },
        { code: 'pack_1500', credits: 1500, price_tpix: 140, bonus: 100 },
    ]),
    rentalDays: ref([1, 7, 30]),
    quotaText: ref('0/1'),

    /*
     * ธงว่าอะไร "เปิดใช้จริงแล้ว" — ค่าปริยายคือปิด เหมือนที่เซิร์ฟเวอร์ส่งมาจริง
     * (config aibot.live_enabled และ aibot.credits.topup_enabled เป็น false)
     */
    liveEnabled: ref(false),
    topupEnabled: ref(false),
    // เทสต์กลไกการเช่าเดิมทั้งหมดถือว่าเปิดขายแล้ว — ด่านปิดขายมีเทสต์แยก
    canRent: ref(true),
    browserBots: ref([]),
};

/** เหตุผลที่แพลนไม่ให้บอทเดิน แยกรายบอท (ตัวปลอมของ planReasonText) */
const planReasons = {};
const tickErrors = {};

const subscribe = vi.fn(() => Promise.resolve({ ok: true }));
const requestTopup = vi.fn(() => Promise.resolve({ ok: true }));
const setBotState = vi.fn(() => Promise.resolve({ ok: true }));
const loadStatus = vi.fn(() => Promise.resolve());
const keepBrowserBotsRunning = vi.fn();
const showToast = vi.fn();

vi.mock('@/Composables/useToasts', () => ({ showToast: (...args) => showToast(...args) }));

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

vi.mock('@/Composables/useSounds', () => ({
    playClickSound: vi.fn(),
    playErrorSound: vi.fn(),
    playNotificationSound: vi.fn(),
}));

vi.mock('@/Composables/useAiBot', () => ({
    useAiBot: () => ({
        ...state,
        runningBots: computed(() => state.bots.value.filter(b => b.status === 'running')),
        loadCatalog: vi.fn(() => Promise.resolve()),
        loadStatus,
        keepBrowserBotsRunning,
        planReasonText: item => planReasons[item?.id] ?? '',
        tickErrorFor: id => tickErrors[id] ?? null,
        subscribe,
        requestTopup,
        setBotState,
        // บอทเงียบผิดปกติไหม — การ์ดใช้ตัดสินสีไฟสถานะ
        minutesSinceRun: () => null,
        isStale: () => false,
        costOf: (code, days) => (state.plans.value.find(p => p.code === code)?.credits_per_day ?? 0) * days,
        canAfford: (code, days) =>
            state.credits.value >= (state.plans.value.find(p => p.code === code)?.credits_per_day ?? 0) * days,
        // helper ภาษา — คอมโพเนนต์เรียกใช้จริง จึงต้องมีในตัวปลอมด้วย
        planLabel: p => p?.plan_name || p?.name || '',
        planDescription: p => p?.description || '',
        planFeatures: p => p?.features || [],
        strategyLabel: s => s?.strategy_name || s?.name || '',
        strategyDescription: s => s?.description || '',
    }),
}));

const AiTradeCard = (await import('@/Components/Trading/AiTradeCard.vue')).default;

/** ป๊อปอัพถูก teleport ไป body — ต้องอ่านจาก document ไม่ใช่จาก wrapper */
const gateText = () => document.body.textContent;

function reset() {
    wallet.isConnected = false;
    wallet.address = null;
    wallet.openConnectModal.mockClear();
    state.credits.value = 0;
    state.isActive.value = false;
    state.subscription.value = null;
    state.bots.value = [];
    state.needsVerification.value = false;
    state.isWorking.value = false;
    // ค่าปริยายตรงกับที่เซิร์ฟเวอร์ส่งจริงตอนนี้ — ทั้งสองอย่างยังไม่เปิด
    state.liveEnabled.value = false;
    state.topupEnabled.value = false;
    state.status.value = { is_active: false };
    state.statusError.value = null;
    state.isLoadingStatus.value = false;
    state.canRent.value = true;
    state.browserBots.value = [];
    Object.keys(planReasons).forEach(k => delete planReasons[k]);
    Object.keys(tickErrors).forEach(k => delete tickErrors[k]);
    subscribe.mockClear();
    requestTopup.mockClear();
    setBotState.mockReset();
    setBotState.mockImplementation(() => Promise.resolve({ ok: true }));
    loadStatus.mockClear();
    keepBrowserBotsRunning.mockClear();
    showToast.mockClear();
    document.body.innerHTML = '';
}

describe('AiTradeCard', () => {
    beforeEach(reset);

    it('invites the user to connect a wallet first', () => {
        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        expect(wrapper.text()).toContain('Let a bot trade for you 24/7');
        expect(wrapper.text()).toContain('Connect wallet to start');
    });

    it('opens the connect modal instead of the gate when no wallet', async () => {
        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Connect wallet')).trigger('click');

        expect(wallet.openConnectModal).toHaveBeenCalled();
        expect(gateText()).not.toContain('have not rented a bot yet');
    });

    it('warns to top up credits when the user has not rented a bot', async () => {
        wallet.isConnected = true;
        wallet.address = '0x1111111111111111111111111111111111111111';

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        // คำเตือนหลัก + ยอดที่ขาด + แพ็กเติมเครดิต ต้องขึ้นครบ
        expect(gateText()).toContain('have not rented a bot yet');
        expect(gateText()).toContain('Not enough credits');
        expect(gateText()).toContain('210 short');
        expect(gateText()).toContain('1,500');
    });

    it('has a link into the detailed settings page carrying the current pair', async () => {
        wallet.isConnected = true;

        const wrapper = mount(AiTradeCard, {
            props: { pair: 'ETH/USDT' },
            attachTo: document.body,
            global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
        });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const hrefs = [...document.querySelectorAll('a')].map(a => a.getAttribute('href'));
        expect(hrefs.some(h => h && h.startsWith('/ai-trade?pair=ETH%2FUSDT'))).toBe(true);
    });

    it('refuses to rent and keeps the warning when credits are short', async () => {
        wallet.isConnected = true;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const cta = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Top up first'));
        cta.click();
        await wrapper.vm.$nextTick();

        expect(subscribe).not.toHaveBeenCalled();
        expect(gateText()).toContain('Not enough work credits');
    });

    it('rents the selected plan when the balance covers it', async () => {
        wallet.isConnected = true;
        state.credits.value = 5000;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const cta = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Rent 7 days'));
        cta.click();
        await wrapper.vm.$nextTick();

        expect(subscribe).toHaveBeenCalledWith('starter', 7);
    });

    it('creates a top-up request when a credit pack is picked', async () => {
        wallet.isConnected = true;
        state.topupEnabled.value = true;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const pack = [...document.querySelectorAll('button')].find(b => b.textContent.includes('1,500'));
        pack.click();
        await wrapper.vm.$nextTick();

        expect(requestTopup).toHaveBeenCalledWith('pack_1500');
    });

    /**
     * ยังไม่เปิดรับเงิน = ปุ่มต้องกดไม่ได้ และต้องบอกเหตุผล
     *
     * ปุ่มที่กดได้แล้วตอบว่า "ส่งคำขอแล้ว" ทั้งที่ไม่มีใครมารับเงินต่อ
     * ทำให้ผู้ใช้รอเครดิตที่ไม่มีวันมา
     */
    it('disables the credit packs while payments are closed', async () => {
        wallet.isConnected = true;
        state.topupEnabled.value = false;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const pack = [...document.querySelectorAll('button')].find(b => b.textContent.includes('1,500'));

        expect(pack.disabled).toBe(true);
        expect(document.body.textContent).toContain('not open yet');

        pack.click();
        await wrapper.vm.$nextTick();

        expect(requestTopup).not.toHaveBeenCalled();
    });

    /** ราคาต้องเป็น TPIX ที่อ่านออก ไม่ใช่ "$" เปล่าๆ จากคีย์ที่ API ไม่ส่งแล้ว */
    it('prices the credit packs in TPIX', async () => {
        wallet.isConnected = true;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const gate = document.body.textContent;
        expect(gate).toContain('140 TPIX');
        expect(gate).not.toContain('$undefined');
    });

    /** คนที่เช่าจากการ์ดนี้ต้องเห็นคำเตือนโหมดทดลองก่อนจ่าย เหมือนที่หน้า /ai-trade */
    it('warns that only demo mode is open before the user pays', async () => {
        wallet.isConnected = true;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        expect(document.body.textContent).toContain('demo mode');
    });

    it('shows the active plan, remaining days and the running bots', () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.credits.value = 1200;
        state.subscription.value = {
            plan_code: 'vip', plan_name: 'VIP Cloud', plan_name_th: 'วีไอพี คลาวด์',
            tier: 'vip', days_remaining: 12,
        };
        state.quotaText.value = '2/10';
        state.bots.value = [
            { id: 1, name: 'BTC grid', pair: 'BTC/USDT', strategy_name: 'Grid Trading', timeframe: '1h', status: 'running' },
            { id: 2, name: 'ETH DCA', pair: 'ETH/USDT', strategy_name: 'Smart DCA', timeframe: '4h', status: 'paused' },
        ];

        const wrapper = mount(AiTradeCard, { attachTo: document.body });

        expect(wrapper.text()).toContain('VIP Cloud');
        expect(wrapper.text()).toContain('12 days left');
        expect(wrapper.text()).toContain('2/10');
        expect(wrapper.text()).toContain('1 bots'); // กำลังเทรด
        expect(wrapper.text()).toContain('BTC grid');
    });

    it('pauses a running bot and starts a paused one', async () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.subscription.value = { plan_code: 'vip', plan_name: 'VIP Cloud', tier: 'vip', days_remaining: 3 };
        state.bots.value = [
            { id: 7, name: 'BTC grid', pair: 'BTC/USDT', strategy_name: 'Grid Trading', timeframe: '1h', status: 'running' },
            { id: 8, name: 'ETH DCA', pair: 'ETH/USDT', strategy_name: 'Smart DCA', timeframe: '4h', status: 'paused' },
        ];

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        const buttons = wrapper.findAll('button').filter(b => ['Pause', 'Start'].includes(b.text().trim()));

        await buttons[0].trigger('click');
        await buttons[1].trigger('click');

        expect(setBotState).toHaveBeenNthCalledWith(1, 7, 'pause');
        expect(setBotState).toHaveBeenNthCalledWith(2, 8, 'start');
    });

    it('asks the user to re-sign when the wallet is not verified', () => {
        wallet.isConnected = true;
        state.needsVerification.value = true;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        expect(wrapper.text()).toContain('Wallet needs verifying');
    });

    /**
     * ⭐ เริ่มบอทไม่ผ่านต้องบอกเหตุผล — เดิมการ์ดทิ้งผลลัพธ์ไปเฉยๆ กดแล้วไม่มีอะไรเกิดขึ้นเลย.
     */
    it('shows the server reason when starting a bot fails', async () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.subscription.value = { plan_code: 'free', plan_name: 'Free', tier: 'free', days_remaining: 300 };
        state.bots.value = [{ id: 9, name: 'Grid', pair: 'BTC/USDT', strategy_name: 'Grid', timeframe: '1h', status: 'paused' }];
        setBotState.mockImplementation(() => Promise.resolve({ ok: false, error: { code: 'STRATEGY_LOCKED', message: 'Needs a higher plan' } }));

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().trim() === 'Start').trigger('click');
        await flushPromises();

        expect(showToast).toHaveBeenCalledWith({ text: 'Needs a higher plan', type: 'error' });
    });

    /**
     * ⭐ โหลดสถานะครั้งแรกไม่สำเร็จ (เช่นโดน 429) ต้องไม่พลิกไปเป็น "ยังไม่ได้เช่า".
     *
     * ผู้ใช้ที่เช่าอยู่เห็นปุ่มเช่าโผล่มา แล้วคิดว่าแพลนหายไป — ต้องเห็นว่าโหลดไม่สำเร็จ + ลองใหม่ได้
     */
    it('offers a retry instead of claiming the user has no plan when the status cannot load', async () => {
        wallet.isConnected = true;
        state.status.value = null;
        state.statusError.value = { code: 'RATE_LIMITED', message: 'Too many requests' };

        const wrapper = mount(AiTradeCard, { attachTo: document.body });

        expect(wrapper.text()).toContain('Could not load AI TRADE status');
        expect(wrapper.text()).toContain('Too many requests');
        expect(wrapper.text()).not.toContain('Activate AI TRADE');

        loadStatus.mockClear();
        await wrapper.findAll('button').find(b => b.text().trim() === 'Retry').trigger('click');
        expect(loadStatus).toHaveBeenCalledWith({ force: true });
    });

    /** โหลดรอบใหม่ไม่สำเร็จแต่มีข้อมูลเดิม — โชว์แพลนเดิมต่อ พร้อมบอกว่ารีเฟรชไม่สำเร็จ */
    it('keeps showing the last known plan when a refresh fails', () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.status.value = { is_active: true };
        state.subscription.value = { plan_code: 'vip', plan_name: 'VIP Cloud', tier: 'vip', days_remaining: 12 };
        state.statusError.value = { code: 'RATE_LIMITED', message: 'Too many requests' };

        const wrapper = mount(AiTradeCard, { attachTo: document.body });

        expect(wrapper.text()).toContain('VIP Cloud');
        expect(wrapper.text()).toContain('Too many requests');
        expect(wrapper.text()).not.toContain('Activate AI TRADE');
    });

    /** ยังไม่เปิดขาย — ห้ามชวนให้ "เติมเครดิตก่อน" เพราะเติมไปก็เช่าไม่ได้ */
    it('explains that renting is closed instead of asking for a top-up', async () => {
        wallet.isConnected = true;
        state.canRent.value = false;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const gate = document.body.textContent;
        expect(gate).toContain('renting is not open yet');
        expect(gate).not.toContain('Top up first');
        expect(gate).not.toContain('Not enough credits');

        const cta = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Renting not open yet'));
        expect(cta.disabled).toBe(true);
        expect(subscribe).not.toHaveBeenCalled();
    });

    /** บอทแพลนฟรีที่กดเริ่มจากการ์ดต้องได้เดินจริง — การ์ดขอให้ตัวเดินบอทฟรีทำงานตอนอยู่บนจอ */
    it('keeps free-plan bots running while the card is on screen', () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.subscription.value = { plan_code: 'free', plan_name: 'Free', tier: 'free', days_remaining: 300 };
        state.bots.value = [{ id: 3, name: 'Grid', pair: 'BTC/USDT', strategy_name: 'Grid', timeframe: '1h', status: 'running' }];
        state.browserBots.value = state.bots.value;

        const wrapper = mount(AiTradeCard, { attachTo: document.body });

        expect(keepBrowserBotsRunning).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('closing the tab stops them');
    });

    /** บอทที่แพลนไม่ให้เดินแล้ว ต้องบอกว่าเพราะแพลน — ไม่ใช่ไฟแดงเฉยๆ */
    it('says why a bot is not running when the plan no longer covers it', () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.subscription.value = { plan_code: 'free', plan_name: 'Free', tier: 'free', days_remaining: 300 };
        state.bots.value = [{ id: 4, name: 'Signal', pair: 'BTC/USDT', strategy_name: 'AI Signal', timeframe: '1h', status: 'paused', pause_reason: 'plan_locked' }];
        planReasons[4] = 'Your current plan does not include this strategy';

        const wrapper = mount(AiTradeCard, { attachTo: document.body });

        expect(wrapper.text()).toContain('Your current plan does not include this strategy');
    });

    /** รอบล่าสุดถูกเซิร์ฟเวอร์ปฏิเสธ — ต้องเห็นข้อความ ไม่ใช่ไฟเขียวกะพริบเหมือนเดินปกติ */
    it('shows the last rejected cycle of a running bot', () => {
        wallet.isConnected = true;
        state.isActive.value = true;
        state.subscription.value = { plan_code: 'free', plan_name: 'Free', tier: 'free', days_remaining: 300 };
        state.bots.value = [{ id: 5, name: 'Grid', pair: 'BTC/USDT', strategy_name: 'Grid', timeframe: '1h', status: 'running' }];
        tickErrors[5] = { code: 'BOT_BANNED', message: 'Suspended by the team' };

        const wrapper = mount(AiTradeCard, { attachTo: document.body });

        expect(wrapper.text()).toContain('Suspended by the team');
        expect(wrapper.find('.ai-bot-row .bg-trading-green').exists()).toBe(false);
        expect(wrapper.find('.ai-bot-row .bg-trading-red').exists()).toBe(true);
    });
});
