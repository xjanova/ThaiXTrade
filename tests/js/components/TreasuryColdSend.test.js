/**
 * TPIX TRADE — หน้าต่างโอนออกจากกระเป๋าคลัง (เซ็นในเบราว์เซอร์แอดมิน)
 *
 * จำลองเชนและการถอดรหัส keystore — ทดสอบด่านกันพลาดทุกด่านของฝั่งหน้าจอ:
 * ฟอร์มไม่ครบห้ามไปต่อ · checksum ผิด · เกินยอด · keystore ผิดใบ · ต้องติ๊กยืนยัน ·
 * กดรัวต้องส่งครั้งเดียว · รหัสผิด/กุญแจผิดใบต้องไม่ส่ง · ระหว่างเซ็นปิดไม่ได้ ·
 * หน้าแม่รีเฟรชยอดต้องไม่ล้างฟอร์ม
 *
 * Developed by Xman Studio
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({
    chainId: '0x10c1', // 4289
    gasPrice: '0x0',
    balance: 1000n * 10n ** 18n,
    decrypt: null,
    sendTx: null,
}));

vi.mock('ethers', async (importOriginal) => {
    const real = await importOriginal();
    class FakeProvider {
        async send(method) {
            if (method === 'eth_chainId') return h.chainId;
            if (method === 'eth_gasPrice') return h.gasPrice;
            throw new Error(`unexpected ${method}`);
        }
        async getBalance() {
            return h.balance;
        }
    }
    return {
        ...real,
        JsonRpcProvider: FakeProvider,
        Wallet: { fromEncryptedJson: (...args) => h.decrypt(...args) },
    };
});

import ColdSendModal from '@/Components/Admin/Treasury/ColdSendModal.vue';
import Modal from '@/Components/Admin/Modal.vue';

const WALLET = {
    key: 'liquidity',
    role_th: 'สภาพคล่อง',
    address: '0x2644A740A06e0401D21F8B4A840400fFe8dB42A9',
    balance_wei: (1000n * 10n ** 18n).toString(),
};
const TO = '0xD2eAB07809921fcB36c7AB72D7B5D8D2C12A67d7';
const OTHER = '0x4BcC1844Ad9E8587f7005f092928a5D14C30F463';

const keystoreOf = (address) => ({ version: 3, address: address.slice(2).toLowerCase(), crypto: { kdf: 'scrypt' } });
const BUNDLE = JSON.stringify({ 'liquidity-market-making': JSON.stringify(keystoreOf(WALLET.address)) });

const signerFor = (address) => ({
    address,
    connect: () => ({ sendTransaction: h.sendTx }),
});

function mountModal(props = {}) {
    return mount(ColdSendModal, {
        props: { show: true, wallet: WALLET, explorerUrl: 'https://explorer.tpix.online', ...props },
        global: { stubs: { teleport: true } },
    });
}

const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text().includes(text));

async function chooseFile(wrapper, content, name = 'master-wallet.keystores.json') {
    const input = wrapper.find('input[type="file"]');
    const file = new File([content], name, { type: 'application/json' });
    if (typeof file.text !== 'function') file.text = async () => content; // jsdom รุ่นเก่า
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

async function fill(wrapper, { to = TO, amount = '210', file = BUNDLE, password = 'pw' } = {}) {
    await wrapper.find('input[placeholder="0x…"]').setValue(to);
    await wrapper.find('input[inputmode="decimal"]').setValue(amount);
    if (file !== null) await chooseFile(wrapper, file);
    await wrapper.find('input[type="password"]').setValue(password);
}

async function reviewAndTick(wrapper) {
    await button(wrapper, 'ตรวจรายการ').trigger('click');
    await wrapper.find('input[type="checkbox"]').setValue(true);
}

beforeEach(() => {
    h.chainId = '0x10c1';
    h.gasPrice = '0x0';
    h.balance = 1000n * 10n ** 18n;
    h.decrypt = vi.fn(async () => signerFor(WALLET.address));
    h.sendTx = vi.fn(async () => ({ hash: '0xfeed', wait: async () => ({ status: 1, blockNumber: 42 }) }));
});

describe('ColdSendModal — before signing', () => {
    it('keeps "review" disabled until every field is valid', async () => {
        const wrapper = mountModal();
        expect(button(wrapper, 'ตรวจรายการ').attributes('disabled')).toBeDefined();

        await fill(wrapper);
        expect(button(wrapper, 'ตรวจรายการ').attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('พบ keystore ของกระเป๋านี้');
    });

    it('flags a recipient whose checksum is wrong (one typo)', async () => {
        const wrapper = mountModal();
        await fill(wrapper, { to: '0xD2EAB07809921fcB36c7AB72D7B5D8D2C12A67d7' });
        expect(wrapper.text()).toContain('ที่อยู่ปลายทางไม่ถูกต้อง');
        expect(button(wrapper, 'ตรวจรายการ').attributes('disabled')).toBeDefined();
    });

    it('refuses more than the wallet holds, and sending to itself', async () => {
        const wrapper = mountModal();
        await fill(wrapper, { amount: '1000.000001' });
        expect(wrapper.text()).toContain('จำนวนเกินยอดในกระเป๋า');

        await wrapper.find('input[inputmode="decimal"]').setValue('1');
        await wrapper.find('input[placeholder="0x…"]').setValue(WALLET.address);
        expect(wrapper.text()).toContain('ปลายทางเป็นกระเป๋าเดียวกับต้นทาง');
        expect(button(wrapper, 'ตรวจรายการ').attributes('disabled')).toBeDefined();
    });

    it('rejects a keystore that belongs to another wallet', async () => {
        const wrapper = mountModal();
        await fill(wrapper, { file: JSON.stringify(keystoreOf(OTHER)) });
        expect(wrapper.text()).toContain('ไม่ใช่ของกระเป๋านี้');
        expect(button(wrapper, 'ตรวจรายการ').attributes('disabled')).toBeDefined();
    });

    it('does not wipe the form when the page refreshes balances (new object, same wallet)', async () => {
        const wrapper = mountModal();
        await fill(wrapper);
        await wrapper.setProps({ wallet: { ...WALLET } });
        expect(wrapper.find('input[placeholder="0x…"]').element.value).toBe(TO);
        expect(button(wrapper, 'ตรวจรายการ').attributes('disabled')).toBeUndefined();
    });
});

describe('ColdSendModal — signing', () => {
    it('needs the tick, then sends exactly once even when clicked twice', async () => {
        const wrapper = mountModal();
        await fill(wrapper);
        await button(wrapper, 'ตรวจรายการ').trigger('click');
        expect(button(wrapper, 'เซ็นและส่ง').attributes('disabled')).toBeDefined();

        await wrapper.find('input[type="checkbox"]').setValue(true);
        const send = button(wrapper, 'เซ็นและส่ง');
        await send.trigger('click');
        await send.trigger('click');
        await flushPromises();

        expect(h.sendTx).toHaveBeenCalledTimes(1);
        const tx = h.sendTx.mock.calls[0][0];
        expect(tx.to).toBe(TO);
        expect(tx.value).toBe(210n * 10n ** 18n);
        expect(tx.type).toBe(0);
        expect(tx.gasPrice).toBe(0n);
        expect(tx.gasLimit).toBe(21000n);

        expect(wrapper.text()).toContain('0xfeed');
        expect(wrapper.text()).toContain('ยืนยันแล้วในบล็อก #42');
        expect(wrapper.emitted('sent')).toEqual([['0xfeed']]);
    });

    it('wrong password: Thai error, back to the form, nothing sent, password cleared', async () => {
        h.decrypt = vi.fn(async () => { throw new Error('incorrect password'); });
        const wrapper = mountModal();
        await fill(wrapper);
        await reviewAndTick(wrapper);
        await button(wrapper, 'เซ็นและส่ง').trigger('click');
        await flushPromises();

        expect(h.sendTx).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('รหัสผ่าน keystore ไม่ถูกต้อง');
        expect(button(wrapper, 'ตรวจรายการ')).toBeTruthy();
        expect(wrapper.find('input[type="password"]').element.value).toBe('');
    });

    it('a key that decrypts to another address never sends', async () => {
        h.decrypt = vi.fn(async () => signerFor(OTHER));
        const wrapper = mountModal();
        await fill(wrapper);
        await reviewAndTick(wrapper);
        await button(wrapper, 'เซ็นและส่ง').trigger('click');
        await flushPromises();

        expect(h.sendTx).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('ไม่ใช่ของกระเป๋านี้');
    });

    it('stops when the RPC is not chain 4289 or the live balance is short', async () => {
        h.chainId = '0x1';
        let wrapper = mountModal();
        await fill(wrapper);
        await reviewAndTick(wrapper);
        await button(wrapper, 'เซ็นและส่ง').trigger('click');
        await flushPromises();
        expect(h.sendTx).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('ไม่ใช่ 4289');

        h.chainId = '0x10c1';
        h.balance = 1n;
        wrapper = mountModal();
        await fill(wrapper);
        await reviewAndTick(wrapper);
        await button(wrapper, 'เซ็นและส่ง').trigger('click');
        await flushPromises();
        expect(h.sendTx).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('ยอดในกระเป๋าไม่พอ');
    });

    it('cannot be closed while it is signing', async () => {
        let release;
        h.decrypt = vi.fn(() => new Promise((resolve) => { release = () => resolve(signerFor(WALLET.address)); }));
        const wrapper = mountModal();
        await fill(wrapper);
        await reviewAndTick(wrapper);
        await button(wrapper, 'เซ็นและส่ง').trigger('click');

        expect(wrapper.findComponent(Modal).props('closeable')).toBe(false);

        release();
        await flushPromises();
        expect(wrapper.findComponent(Modal).props('closeable')).toBe(true);
    });
});
