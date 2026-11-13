/**
 * TPIX TRADE — AiTradeTopupModal tests
 *
 * หน้าต่างเติมเครดิตบอทด้วยการโอน TPIX บนเชน — ชุดนี้คุมทางที่ผู้ใช้ "จ่ายแล้วไม่ได้เครดิต" หรือ "จ่ายซ้ำ":
 * กดซ้ำ · ผิดเชน · บัญชีในกระเป๋าไม่ตรง · ยกเลิกในกระเป๋า · เชนยังไม่ยืนยัน · ถูกปฏิเสธ
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const OWNER = '0xAbABaBabABAbabABABabABAbabABABAbaBAbAbAB';
const TREASURY = '0x7777777777777777777777777777777777777777';
const HASH = `0x${'1'.repeat(64)}`;
const PACK = { code: 'pack_1500', credits: 1500, bonus: 100, price_tpix: 140 };
const INTENT = {
    status: 'pending_payment',
    pack: PACK,
    pay_to: TREASURY,
    chain_id: 4289,
    amount_tpix: '140',
    amount_wei: '140000000000000000000',
    credits: 1600,
};

const signer = {
    getAddress: vi.fn(),
    sendTransaction: vi.fn(),
};

const wallet = {
    address: OWNER,
    chainId: 4289,
    walletType: 'metamask',
    signer,
    switchChain: vi.fn(),
    sendTPIX: vi.fn(),
};

const requestTopup = vi.fn();
const confirmTopup = vi.fn();
const loadStatus = vi.fn(() => Promise.resolve());
let pending = null;
const writePendingTopup = vi.fn((value) => { pending = value; });

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

vi.mock('@/Composables/useAiBot', () => ({
    readPendingTopup: () => pending,
    writePendingTopup: (...args) => writePendingTopup(...args),
    useAiBot: () => ({ requestTopup, confirmTopup, loadStatus }),
}));

const AiTradeTopupModal = (await import('@/Components/AiTrade/AiTradeTopupModal.vue')).default;

const text = () => document.body.textContent;
const button = (label) => [...document.querySelectorAll('button')].find(b => b.textContent.includes(label));

async function open(props = {}) {
    const wrapper = mount(AiTradeTopupModal, {
        props: { show: true, pack: PACK, ...props },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    document.body.innerHTML = '';
    pending = null;
    wallet.address = OWNER;
    wallet.chainId = 4289;
    wallet.walletType = 'metamask';
    wallet.signer = signer;

    for (const mock of [signer.getAddress, signer.sendTransaction, wallet.switchChain, wallet.sendTPIX,
        requestTopup, confirmTopup, loadStatus, writePendingTopup]) {
        mock.mockReset();
    }

    writePendingTopup.mockImplementation((value) => { pending = value; });
    loadStatus.mockImplementation(() => Promise.resolve());
    requestTopup.mockResolvedValue({ ok: true, data: INTENT });
    signer.getAddress.mockResolvedValue(OWNER.toLowerCase());
    signer.sendTransaction.mockResolvedValue({ hash: HASH });
    confirmTopup.mockResolvedValue({ ok: true, data: { status: 'credited', credited: 1600, balance: 1600, tx_hash: HASH } });
});

describe('AiTradeTopupModal', () => {
    it('shows the amount, network and payment wallet from the server intent', async () => {
        await open();

        expect(requestTopup).toHaveBeenCalledWith('pack_1500');
        expect(text()).toContain(TREASURY);
        expect(text()).toContain('TPIX Chain (4289)');
        expect(text()).toContain('1,600');
        expect(button('Pay 140 TPIX')).toBeTruthy();
    });

    it('sends the exact wei amount to the server wallet, then credits once confirmed', async () => {
        const wrapper = await open();

        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(signer.sendTransaction).toHaveBeenCalledTimes(1);
        expect(signer.sendTransaction).toHaveBeenCalledWith({ to: TREASURY, value: 140000000000000000000n });
        // จำ hash ไว้ก่อนถามเซิร์ฟเวอร์ แล้วล้างเมื่อได้ผลสุดท้าย
        expect(writePendingTopup).toHaveBeenNthCalledWith(1, { address: OWNER, pack: 'pack_1500', hash: HASH });
        expect(confirmTopup).toHaveBeenCalledWith('pack_1500', HASH);
        expect(writePendingTopup).toHaveBeenLastCalledWith(null);

        expect(text()).toContain('Top-up complete');
        expect(loadStatus).toHaveBeenCalledWith({ force: true });
        expect(wrapper.emitted('credited')).toHaveLength(1);
    });

    /** กดซ้ำระหว่างรอกระเป๋า = ธุรกรรมเดียว (จ่ายซ้ำคือเสียเงินจริง) */
    it('ignores a double click while the wallet is signing', async () => {
        let release;
        signer.sendTransaction.mockReturnValue(new Promise((resolve) => { release = resolve; }));

        await open();
        const pay = button('Pay 140 TPIX');
        pay.click();
        pay.click();
        await flushPromises();
        button('Pay 140 TPIX')?.click();
        await flushPromises();

        expect(signer.sendTransaction).toHaveBeenCalledTimes(1);

        release({ hash: HASH });
        await flushPromises();

        expect(confirmTopup).toHaveBeenCalledTimes(1);
    });

    /**
     * ⚠️ ปิดหน้าต่างระหว่างรอกระเป๋าแล้วเปิดใหม่: ห้ามเปิดคำขอโอนใบที่สอง (ยืนยันทั้งคู่ = จ่ายสองรอบ)
     *    และ hash ที่ได้กลับมาจากคำขอเดิมต้องถูกจำ แล้วตรวจต่อในหน้าต่างที่เปิดอยู่
     */
    it('never opens a second wallet request after closing and reopening mid-signing', async () => {
        let release;
        signer.sendTransaction.mockReturnValue(new Promise((resolve) => { release = resolve; }));

        const wrapper = await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        // ปิดได้แม้กระเป๋ายังไม่ตอบ (กระเป๋าบางตัวปิดป๊อปอัพแล้วเงียบไปเลย)
        await wrapper.setProps({ show: false });
        await flushPromises();
        await wrapper.setProps({ show: true });
        await flushPromises();

        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(signer.sendTransaction).toHaveBeenCalledTimes(1);
        expect(text()).toContain('still open in your wallet');

        release({ hash: HASH });
        await flushPromises();

        expect(confirmTopup).toHaveBeenCalledWith('pack_1500', HASH);
        expect(text()).toContain('Top-up complete');
        expect(pending).toBeNull();
    });

    /** ปิดหน้าต่างแล้วกระเป๋าค่อยตอบ: hash ต้องถูกจำไว้ให้หน้า /ai-trade ชวนตรวจต่อ */
    it('remembers the tx hash when the wallet answers after the dialog was closed', async () => {
        let release;
        signer.sendTransaction.mockReturnValue(new Promise((resolve) => { release = resolve; }));

        const wrapper = await open();
        button('Pay 140 TPIX').click();
        await flushPromises();
        await wrapper.setProps({ show: false });
        await flushPromises();

        release({ hash: HASH });
        await flushPromises();

        expect(pending).toEqual({ address: OWNER, pack: 'pack_1500', hash: HASH });
        expect(confirmTopup).not.toHaveBeenCalled();
    });

    it('says nothing was charged when the user cancels in the wallet', async () => {
        signer.sendTransaction.mockRejectedValue({ code: 'ACTION_REJECTED', message: 'user rejected action (raw wallet text)' });

        await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(text()).toContain('You cancelled the transfer');
        expect(text()).not.toContain('raw wallet text');
        expect(confirmTopup).not.toHaveBeenCalled();
        expect(writePendingTopup).not.toHaveBeenCalled();
        // กลับมาพร้อมกดใหม่ได้
        expect(button('Pay 140 TPIX')).toBeTruthy();
    });

    /** อยู่ผิดเชนและสลับไม่สำเร็จ = ไม่ส่งธุรกรรม (โอนจากเชนอื่นเงินไปไม่ถึง) */
    it('refuses to send on the wrong chain when the switch fails', async () => {
        wallet.chainId = 56;
        wallet.switchChain.mockRejectedValue(new Error('switch refused'));

        await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(wallet.switchChain).toHaveBeenCalledWith(4289);
        expect(signer.sendTransaction).not.toHaveBeenCalled();
        expect(text()).toContain('Switch your wallet to TPIX Chain (4289)');
    });

    it('switches chain first and then pays', async () => {
        wallet.chainId = 56;
        wallet.switchChain.mockImplementation(async (id) => { wallet.chainId = id; });

        await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(signer.sendTransaction).toHaveBeenCalledTimes(1);
        expect(text()).toContain('Top-up complete');
    });

    /** ⚠️ บัญชีในกระเป๋าไม่ใช่ใบที่เชื่อมไว้ — โอนไปเซิร์ฟเวอร์จะปฏิเสธ จึงต้องกันก่อนเงินออก */
    it('does not send when the wallet account differs from the connected wallet', async () => {
        signer.getAddress.mockResolvedValue('0x0000000000000000000000000000000000000001');

        await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(signer.sendTransaction).not.toHaveBeenCalled();
        expect(text()).toContain('not the connected wallet');
    });

    /** สลับกระเป๋าระหว่างสลับเชน = ยกเลิก ไม่ส่งธุรกรรมจากใบใหม่ด้วยใบแจ้งชำระของใบเดิม */
    it('aborts when the connected wallet changes mid-flow', async () => {
        wallet.chainId = 56;
        wallet.switchChain.mockImplementation(async (id) => {
            wallet.chainId = id;
            wallet.address = '0x0000000000000000000000000000000000000002';
        });

        await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(signer.sendTransaction).not.toHaveBeenCalled();
    });

    /** เชนยังไม่ยืนยัน: ไม่ขึ้นว่าสำเร็จ เก็บ hash ไว้ และกดตรวจซ้ำแล้วได้เครดิต */
    it('keeps the tx hash while the chain is pending and credits on a later check', async () => {
        vi.useFakeTimers();

        try {
            confirmTopup.mockResolvedValue({ ok: true, data: { status: 'pending', tx_hash: HASH, retry_after_seconds: 2 } });

            const wrapper = await open();
            button('Pay 140 TPIX').click();
            await flushPromises();

            // ถามซ้ำจนครบจำนวนรอบ แล้วให้ผู้ใช้กดเอง
            for (let i = 0; i < 12; i++) {
                await vi.advanceTimersByTimeAsync(2000);
            }
            await flushPromises();

            expect(confirmTopup).toHaveBeenCalledTimes(12);
            expect(text()).toContain('has not confirmed this transaction yet');
            expect(text()).not.toContain('Top-up complete');
            expect(pending).toEqual({ address: OWNER, pack: 'pack_1500', hash: HASH });
            expect(wrapper.emitted('credited')).toBeUndefined();

            confirmTopup.mockResolvedValue({ ok: true, data: { status: 'credited', credited: 1600, balance: 1600, tx_hash: HASH } });
            button('Check again').click();
            await flushPromises();

            expect(text()).toContain('Top-up complete');
            expect(pending).toBeNull();
        } finally {
            vi.useRealTimers();
        }
    });

    /** ถูกปฏิเสธถาวร: โชว์ข้อความของเซิร์ฟเวอร์ ไม่ใช่ "สำเร็จ" และเลิกจำ hash */
    it('shows the server reason when the payment is rejected', async () => {
        confirmTopup.mockResolvedValue({ ok: false, error: { code: 'TOPUP_WRONG_AMOUNT', message: 'The amount sent does not match' } });

        const wrapper = await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(text()).toContain('The amount sent does not match');
        expect(text()).not.toContain('Top-up complete');
        expect(pending).toBeNull();
        expect(wrapper.emitted('credited')).toBeUndefined();
    });

    /** เน็ตหลุด/โควตาเต็ม/ต้องเซ็นกระเป๋าใหม่ ไม่ใช่ผลสุดท้าย — ห้ามทิ้ง hash ของเงินที่โอนไปแล้ว */
    it('keeps the tx hash when the server answer is not final', async () => {
        confirmTopup.mockResolvedValue({ ok: false, error: { code: 'NETWORK', message: 'Cannot reach the server' } });

        await open();
        button('Pay 140 TPIX').click();
        await flushPromises();

        expect(text()).toContain('Cannot reach the server');
        expect(pending).toEqual({ address: OWNER, pack: 'pack_1500', hash: HASH });
        expect(button('Check again')).toBeTruthy();
    });

    /** เปิดมาเจอรายการที่โอนไปแล้วของแพ็กนี้ = ตรวจต่อเลย ไม่ชวนจ่ายใหม่ */
    it('resumes a payment that was already sent instead of offering to pay again', async () => {
        pending = { address: OWNER, pack: 'pack_1500', hash: HASH };

        await open();

        expect(confirmTopup).toHaveBeenCalledWith('pack_1500', HASH);
        expect(signer.sendTransaction).not.toHaveBeenCalled();
        expect(text()).toContain('Top-up complete');
    });

    /** ปิดรับเติมอยู่: บอกเหตุผลจากเซิร์ฟเวอร์ และไม่มีปุ่มจ่าย */
    it('offers no pay button when top-up is closed', async () => {
        requestTopup.mockResolvedValue({ ok: false, error: { code: 'TOPUP_UNAVAILABLE', message: 'Credit top-ups are not open yet' } });

        await open();

        expect(text()).toContain('Credit top-ups are not open yet');
        expect(button('Pay 140 TPIX')).toBeUndefined();
        expect(signer.sendTransaction).not.toHaveBeenCalled();
    });

    it('confirms with a pasted tx hash and rejects a malformed one', async () => {
        await open();

        button('Already paid?').click();
        await flushPromises();

        const input = document.querySelector('input[placeholder="0x..."]');
        input.value = '0x1234';
        input.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(text()).toContain('Invalid tx hash');
        expect(button('Confirm with tx hash').disabled).toBe(true);

        input.value = HASH.toUpperCase().replace('0X', '0x');
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        button('Confirm with tx hash').click();
        await flushPromises();

        expect(confirmTopup).toHaveBeenCalledWith('pack_1500', HASH);
        expect(signer.sendTransaction).not.toHaveBeenCalled();
        expect(text()).toContain('Top-up complete');
    });
});
