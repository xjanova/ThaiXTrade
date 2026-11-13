/**
 * TPIX TRADE — useTradingFee: ใบอนุญาตวางไม้ฝั่งหน้าเว็บ
 *
 * ออดิท 2026-10-28:
 *  - ใบที่ผูกธุรกรรมแล้วคืนเงินได้เมื่อเชนยืนยันว่าไม้ไม่ลงเท่านั้น — หน้าเว็บต้องแยก "ยังไม่รู้ผล" ออกจาก "ล้ม"
 *  - หน้า Swap ถามสิทธิ์ยกเว้นของผู้เช่าบอทได้โดยไม่ทับใบเสนอราคาของฟอร์มบนหน้าเทรด
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

const post = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a) => post(...a) } }));

const { useTradingFee } = await import('@/Composables/useTradingFee');

const WALLET = `0x${'a'.repeat(40)}`;
const UUID = '9b2c6a10-0000-4000-8000-000000000001';
const HASH = `0x${'1'.repeat(64)}`;

beforeEach(() => {
    post.mockReset();
    useTradingFee().currentQuote.value = null;
});

describe('bindTicket', () => {
    it('binds the ticket to the transaction that left the wallet', async () => {
        post.mockResolvedValue({ data: { success: true, data: { uuid: UUID, status: 'issued' } } });

        const bound = await useTradingFee().bindTicket(WALLET, UUID, HASH);

        expect(post).toHaveBeenCalledWith(`/api/v1/trading-fee/tickets/${UUID}/bind`, { wallet_address: WALLET, order_tx_hash: HASH });
        expect(bound).toMatchObject({ uuid: UUID });
    });

    it('returns null instead of throwing when the bind fails', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { error: { code: 'TICKET_BOUND_ELSEWHERE' } } } });

        expect(await useTradingFee().bindTicket(WALLET, UUID, HASH)).toBeNull();
    });
});

describe('refundTicket', () => {
    it('says "pending" when the chain has not settled the bound trade yet', async () => {
        post.mockRejectedValue({ response: { status: 409, data: { error: { code: 'TICKET_TRADE_PENDING', message: 'รอผล' } } } });

        expect(await useTradingFee().refundTicket(WALLET, UUID)).toEqual({ pending: true });
    });

    it('returns null for any other refusal (already used, not found)', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { error: { code: 'TICKET_ALREADY_USED' } } } });

        expect(await useTradingFee().refundTicket(WALLET, UUID)).toBeNull();
    });
});

describe('waiverFor', () => {
    it('reports a cloud bot renter without touching the trade form quote', async () => {
        const fee = useTradingFee();
        const formQuote = { enabled: true, waived: false, order_value_usd: 500 };
        fee.currentQuote.value = formQuote;
        post.mockResolvedValue({ data: { success: true, data: { enabled: true, waived: true } } });

        expect(await fee.waiverFor(WALLET, 56)).toBe(true);
        expect(fee.currentQuote.value).toEqual(formQuote);
    });

    it('is false for everyone else and null when it cannot be checked', async () => {
        const fee = useTradingFee();
        post.mockResolvedValueOnce({ data: { success: true, data: { enabled: true, waived: false } } });
        expect(await fee.waiverFor(WALLET, 56)).toBe(false);

        post.mockRejectedValueOnce({ response: { status: 429 } });
        expect(await fee.waiverFor(WALLET, 56)).toBeNull();

        expect(await fee.waiverFor(null, 56)).toBe(false);
    });
});

/*
 * TPIX Chain: ค่าวางไม้ต้องจ่ายด้วยเครดิต TPIX ทุกไม้ (เจ้าของสั่ง 2026-11-13)
 * เดิมตอนกดส่งอ่านใบเสนอราคาที่ค้างในฟอร์ม (ของไม้อื่นได้) และถามไม่สำเร็จ = ถือว่าระบบปิด → ไม้ออกไปฟรี
 */
const ORDER = { wallet: WALLET, pair: 'TPIX/USDT', side: 'buy', orderValueUsd: 250, chainId: 4289 };
const CREDIT_ONLY = {
    enabled: true,
    can_place: true,
    reason: null,
    recommended: 'tpix_credit',
    tpix: { available: true, has_enough: true, fee_tpix: 2 },
    onchain: { available: false, reason: 'บน TPIX Chain ต้องจ่ายค่าวางไม้ด้วยเครดิต TPIX' },
};

/** ตอบคำขอตามปลายทาง — ลำดับการเรียกไม่ต้องตายตัว */
function respond(routes) {
    post.mockImplementation((url) => {
        const key = Object.keys(routes).find(suffix => url.endsWith(suffix));
        const answer = key ? routes[key] : undefined;
        if (answer === undefined) return Promise.reject(new Error(`unexpected ${url}`));
        if (answer instanceof Error || answer?.response) return Promise.reject(answer);
        return Promise.resolve({ data: { success: true, data: answer } });
    });
}

const calledUrls = () => post.mock.calls.map(c => c[0].replace('/api/v1/trading-fee', ''));

describe('freshQuote', () => {
    it('returns the quote for this exact order without touching the trade form quote', async () => {
        const fee = useTradingFee();
        fee.currentQuote.value = { enabled: false, stale: true };
        respond({ '/quote': CREDIT_ONLY });

        const quote = await fee.freshQuote(ORDER);

        expect(quote).toEqual(CREDIT_ONLY);
        expect(post).toHaveBeenCalledWith('/api/v1/trading-fee/quote', {
            wallet_address: WALLET, order_value_usd: 250, chain_id: 4289, pair: 'TPIX/USDT',
        });
        expect(fee.currentQuote.value).toEqual({ enabled: false, stale: true });
    });

    it('returns null when the server cannot be asked — never the stale form quote', async () => {
        const fee = useTradingFee();
        fee.currentQuote.value = { enabled: false, stale: true };

        post.mockRejectedValue(new Error('Network Error'));
        expect(await fee.freshQuote(ORDER)).toBeNull();

        post.mockResolvedValue({ data: { success: false } });
        expect(await fee.freshQuote(ORDER)).toBeNull();

        post.mockReset();
        expect(await fee.freshQuote({ ...ORDER, orderValueUsd: 0 })).toBeNull();
        expect(post).not.toHaveBeenCalled();
    });
});

describe('isCreditOnly', () => {
    it('is true only when the system is on, the wallet is not waived and the coin option is closed', () => {
        const { isCreditOnly } = useTradingFee();

        expect(isCreditOnly(CREDIT_ONLY)).toBe(true);
        expect(isCreditOnly({ ...CREDIT_ONLY, enabled: false })).toBe(false);
        expect(isCreditOnly({ ...CREDIT_ONLY, waived: true })).toBe(false);
        expect(isCreditOnly({ ...CREDIT_ONLY, onchain: { available: true } })).toBe(false);
        expect(isCreditOnly({ enabled: true })).toBe(false);
        expect(isCreditOnly(null)).toBe(false);
    });
});

describe('sendWithTicket', () => {
    it('refuses to send when the fee quote cannot be fetched (fail closed)', async () => {
        post.mockRejectedValue(new Error('Network Error'));
        const send = vi.fn();

        await expect(useTradingFee().sendWithTicket(ORDER, send)).rejects.toMatchObject({ feeCode: 'FEE_QUOTE_FAILED', isFriendly: true });
        expect(send).not.toHaveBeenCalled();
    });

    it('sends straight away when no ticket is needed (system off, waived, or coin option open)', async () => {
        const fee = useTradingFee();

        for (const quote of [
            { ...CREDIT_ONLY, enabled: false },
            { ...CREDIT_ONLY, waived: true },
            { ...CREDIT_ONLY, onchain: { available: true } },
        ]) {
            post.mockReset();
            respond({ '/quote': quote });
            const send = vi.fn().mockResolvedValue({ hash: HASH });

            expect(await fee.sendWithTicket(ORDER, send)).toEqual({ hash: HASH });
            expect(send).toHaveBeenCalledWith({ onSent: null });
            expect(calledUrls()).toEqual(['/quote']);
        }
    });

    it('stops before asking the wallet when the credit cannot cover the fee', async () => {
        respond({ '/quote': { ...CREDIT_ONLY, can_place: false, reason: 'เครดิต TPIX ไม่พอ' } });
        const send = vi.fn();

        await expect(useTradingFee().sendWithTicket(ORDER, send)).rejects.toMatchObject({
            feeCode: 'CREDIT_REQUIRED', message: 'เครดิต TPIX ไม่พอ',
        });
        expect(send).not.toHaveBeenCalled();
        expect(calledUrls()).toEqual(['/quote']);   // ไม่มีการขอใบ = ไม่มีการหักเครดิต
    });

    it('does not send when the ticket cannot be issued', async () => {
        respond({
            '/quote': CREDIT_ONLY,
            '/tickets': { response: { status: 422, data: { error: { code: 'INSUFFICIENT_CREDIT', message: 'เครดิตไม่พอ' } } } },
        });
        const send = vi.fn();

        await expect(useTradingFee().sendWithTicket(ORDER, send)).rejects.toMatchObject({ feeCode: 'TICKET_FAILED', message: 'เครดิตไม่พอ' });
        expect(send).not.toHaveBeenCalled();
    });

    it('issues a ticket, binds it when the transaction leaves the wallet, and closes it when the trade lands', async () => {
        respond({
            '/quote': CREDIT_ONLY,
            '/tickets': { uuid: UUID, balance: 8 },
            '/bind': { uuid: UUID },
            '/consume': { uuid: UUID, balance: 8 },
        });
        const send = vi.fn(async ({ onSent }) => {
            onSent(HASH);
            return { hash: HASH };
        });

        expect(await useTradingFee().sendWithTicket(ORDER, send)).toEqual({ hash: HASH });

        expect(calledUrls()).toEqual(['/quote', '/tickets', `/tickets/${UUID}/bind`, `/tickets/${UUID}/consume`]);
        expect(post).toHaveBeenCalledWith('/api/v1/trading-fee/tickets', expect.objectContaining({
            wallet_address: WALLET, pair: 'TPIX/USDT', side: 'buy', order_value_usd: 250, chain_id: 4289, method: 'tpix_credit',
        }));
        expect(post).toHaveBeenCalledWith(`/api/v1/trading-fee/tickets/${UUID}/consume`, { wallet_address: WALLET, order_tx_hash: HASH });
    });

    it('refunds the ticket when nothing left the wallet (user rejected / price check failed)', async () => {
        respond({ '/quote': CREDIT_ONLY, '/tickets': { uuid: UUID }, '/refund': { uuid: UUID, balance: 10 } });
        const rejected = Object.assign(new Error('คุณยกเลิกในกระเป๋า'), { isFriendly: true });
        const send = vi.fn().mockRejectedValue(rejected);

        await expect(useTradingFee().sendWithTicket(ORDER, send)).rejects.toBe(rejected);

        expect(calledUrls()).toEqual(['/quote', '/tickets', `/tickets/${UUID}/refund`]);
    });

    it('never refunds once a transaction is out — it binds the ticket so the chain result decides', async () => {
        respond({ '/quote': CREDIT_ONLY, '/tickets': { uuid: UUID }, '/bind': { uuid: UUID } });
        const unconfirmed = Object.assign(new Error('ยังยืนยันไม่ได้'), { isFriendly: true, txSent: true, txHash: HASH });

        // ผูกไปแล้วตอน onSent — ไม่ผูกซ้ำ ไม่คืน ไม่ปิดใบเอง
        const sentThenLost = vi.fn(async ({ onSent }) => {
            onSent(HASH);
            throw unconfirmed;
        });
        await expect(useTradingFee().sendWithTicket(ORDER, sentThenLost)).rejects.toBe(unconfirmed);
        expect(calledUrls()).toEqual(['/quote', '/tickets', `/tickets/${UUID}/bind`]);

        // onSent ไม่ถูกเรียก (กระเป๋าไม่แจ้ง) แต่ error บอก hash — ต้องผูกจาก hash นั้น
        post.mockClear();
        const lostWithoutOnSent = vi.fn().mockRejectedValue(unconfirmed);
        await expect(useTradingFee().sendWithTicket(ORDER, lostWithoutOnSent)).rejects.toBe(unconfirmed);
        expect(calledUrls()).toEqual(['/quote', '/tickets', `/tickets/${UUID}/bind`]);
        expect(post).toHaveBeenLastCalledWith(`/api/v1/trading-fee/tickets/${UUID}/bind`, { wallet_address: WALLET, order_tx_hash: HASH });
    });
});
