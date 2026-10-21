/**
 * แผงกระเป๋าบอท — เหตุผลที่รายการถอนล้มต้องแปลจากรหัส ไม่ใช่โชว์ข้อความที่เซิร์ฟเวอร์เก็บไว้ตรง ๆ
 * (เดิมข้อความดิบจาก RPC / ตัวถอดกุญแจไหลมาถึงหน้าจอผู้ใช้)
 */
import { describe, it, expect, vi } from 'vitest';
import { ref } from 'vue';
import { mount } from '@vue/test-utils';

const transfers = ref([]);

vi.mock('@/Composables/useAiBot', () => ({
    useAiBot: () => ({
        botWallet: ref({ enabled: true, wallet: walletPayload(), transfers: transfers.value }),
        wallet: ref('0x1111111111111111111111111111111111111111'),
        isConnected: ref(false),
        isWorking: ref(false),
        loadBotWallet: vi.fn(),
    }),
}));

function walletPayload() {
    return {
        address: '0x2222222222222222222222222222222222222222',
        chain_name: 'BSC',
        native_symbol: 'BNB',
        gas_reserve: 0.002,
        has_pending_withdraw: false,
        assets: [{ symbol: 'USDT', type: 'erc20', balance: 10, daily_cap: 5000 }],
        balances_at: null,
    };
}

const Panel = (await import('@/Components/Trading/AiBotWalletPanel.vue')).default;

describe('AiBotWalletPanel — failure reasons', () => {
    it('shows the localized message for a known failure code instead of the stored text', () => {
        transfers.value = [{ id: 1, status: 'failed', asset: 'USDT', amount: 5, failure_code: 'dropped', failure_reason: 'RAW-SERVER-TEXT', created_at: '2026-10-21T00:00:00Z', cancellable: false }];
        const wrapper = mount(Panel);

        expect(wrapper.text()).toContain('left the network without being mined');
        expect(wrapper.text()).not.toContain('RAW-SERVER-TEXT');
    });

    it('falls back to the server message for a code this page does not know yet', () => {
        transfers.value = [{ id: 2, status: 'failed', asset: 'USDT', amount: 5, failure_code: 'some_future_code', failure_reason: 'ข้อความสำรอง', created_at: '2026-10-21T00:00:00Z', cancellable: false }];
        const wrapper = mount(Panel);

        expect(wrapper.text()).toContain('ข้อความสำรอง');
    });
});
