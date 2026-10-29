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
