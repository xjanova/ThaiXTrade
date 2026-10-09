/**
 * TPIX TRADE — เปิด/ปิดผู้ช่วย AI (แชทน้อง TPIX) บนหน้าเทรด
 *
 * เจ้าของสั่ง "หน้าเว็บเทรดตั้งค่าเปิดปิดผู้ช่วยเอไอได้" — เทสต์ชุดนี้กัน 3 อาการ:
 *  1. ปิดแล้วปุ่มลอยยังโผล่ทับกระดาน
 *  2. ปิดบนหน้าเทรดแล้วหน้าอื่นหายตามไปด้วย (ต้องปิดเฉพาะหน้าเทรด)
 *  3. ปิดตอนหน้าต่างเปิดค้าง → isOpen ค้าง แล้วไปเด้งขึ้นเองในหน้าถัดไป
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';

const page = reactive({ url: '/trade/BTC-USDT' });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: { visit: vi.fn() },
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

import AIChatbot from '@/Components/AIChatbot.vue';
import { useChatbot, __resetChatbot } from '@/Composables/useChatbot';
import {
    useAssistantPref,
    isTradeUrl,
    ASSISTANT_TRADE_KEY,
    __reloadAssistantPref,
} from '@/Composables/useAssistantPref';
import { useToasts, dismissToast } from '@/Composables/useToasts';

const launcher = w => w.find('button.tpix-chat-launcher');

beforeEach(() => {
    localStorage.removeItem(ASSISTANT_TRADE_KEY);
    __reloadAssistantPref();
    __resetChatbot();
    page.url = '/trade/BTC-USDT';
    useToasts().toasts.value.forEach(t => dismissToast(t.id));
});

describe('isTradeUrl', () => {
    it.each([
        ['/trade', true],
        ['/trade/BTC-USDT', true],
        ['/trade?x=1', true],
        ['/trades', false],
        ['/trade-history', false],
        ['/ai-trade', false],
        ['/', false],
        [undefined, false],
    ])('%s → %s', (url, expected) => {
        expect(isTradeUrl(url)).toBe(expected);
    });
});

describe('useAssistantPref', () => {
    it('is on by default so existing users see no change', () => {
        expect(useAssistantPref().showOnTrade.value).toBe(true);
    });

    it('remembers the choice on this device', async () => {
        useAssistantPref().setShowOnTrade(false);
        await nextTick();
        expect(localStorage.getItem(ASSISTANT_TRADE_KEY)).toBe('0');

        __reloadAssistantPref();
        expect(useAssistantPref().showOnTrade.value).toBe(false);
    });
});

describe('AIChatbot on the trade page', () => {
    it('shows the floating button while the assistant is on', () => {
        const wrapper = mount(AIChatbot);
        expect(launcher(wrapper).exists()).toBe(true);
    });

    it('hides the floating button when turned off', async () => {
        const wrapper = mount(AIChatbot);
        useAssistantPref().setShowOnTrade(false);
        await nextTick();
        expect(launcher(wrapper).exists()).toBe(false);
    });

    it('keeps the assistant on other pages', async () => {
        useAssistantPref().setShowOnTrade(false);
        page.url = '/markets';
        const wrapper = mount(AIChatbot);
        await nextTick();
        expect(launcher(wrapper).exists()).toBe(true);
    });

    it('closes an open chat window when turned off, so it does not pop up on the next page', async () => {
        const chat = useChatbot();
        chat.open();
        const wrapper = mount(AIChatbot);
        await nextTick();
        expect(wrapper.text()).toContain('Hide on trade page');

        useAssistantPref().setShowOnTrade(false);
        await nextTick();
        expect(chat.isOpen.value).toBe(false);

        page.url = '/markets';
        await nextTick();
        expect(chat.isOpen.value).toBe(false);
        expect(launcher(wrapper).exists()).toBe(true);
    });

    it('cannot be opened by other code while turned off', async () => {
        useAssistantPref().setShowOnTrade(false);
        const wrapper = mount(AIChatbot);
        useChatbot().open();
        await nextTick();
        expect(useChatbot().isOpen.value).toBe(false);
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('the button in the chat header turns it off and says where to turn it back on', async () => {
        useChatbot().open();
        const wrapper = mount(AIChatbot);
        await nextTick();

        const hide = wrapper.findAll('button').find(b => b.text() === 'Hide on trade page');
        await hide.trigger('click');

        expect(useAssistantPref().showOnTrade.value).toBe(false);
        expect(useChatbot().isOpen.value).toBe(false);
        expect(useToasts().toasts.value.at(-1).text).toContain('Settings');
    });

    it('does not offer the trade-page button on other pages', async () => {
        page.url = '/markets';
        useChatbot().open();
        const wrapper = mount(AIChatbot);
        await nextTick();
        expect(wrapper.text()).not.toContain('Hide on trade page');
    });
});
