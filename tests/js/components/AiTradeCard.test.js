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
import { mount } from '@vue/test-utils';

// ── ตัวปลอมของ store/composable (ควบคุมสถานะได้จากในเทสต์) ──────────────────
const wallet = { isConnected: false, address: null, openConnectModal: vi.fn() };

const state = {
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
    packs: ref([
        { code: 'pack_500', credits: 500, price_usd: 5, bonus: 0 },
        { code: 'pack_1500', credits: 1500, price_usd: 14, bonus: 100 },
    ]),
    rentalDays: ref([1, 7, 30]),
    quotaText: ref('0/1'),
};

const subscribe = vi.fn(() => Promise.resolve({ ok: true }));
const requestTopup = vi.fn(() => Promise.resolve({ ok: true }));
const setBotState = vi.fn(() => Promise.resolve({ ok: true }));

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
        loadStatus: vi.fn(() => Promise.resolve()),
        subscribe,
        requestTopup,
        setBotState,
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
    subscribe.mockClear();
    requestTopup.mockClear();
    setBotState.mockClear();
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

        const wrapper = mount(AiTradeCard, { attachTo: document.body });
        await wrapper.findAll('button').find(b => b.text().includes('Activate AI TRADE')).trigger('click');

        const pack = [...document.querySelectorAll('button')].find(b => b.textContent.includes('1,500'));
        pack.click();
        await wrapper.vm.$nextTick();

        expect(requestTopup).toHaveBeenCalledWith('pack_1500');
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
});
