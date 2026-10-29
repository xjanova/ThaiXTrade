/**
 * TPIX TRADE — คิวบันทึกไม้ที่ส่งไม่สำเร็จ
 *
 * สวอปลงเชนแล้วแต่ /swap/execute ล้ม (ลายเซ็นกระเป๋าหมดอายุ → 403, เน็ตหลุด)
 * เดิม console.warn แล้วจบ = ไม้จริงหายจากประวัติถาวร
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { flushPendingSwapRecords, PENDING_RECORDS_KEY } from '@/Composables/useSwap';

vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

const ME = '0xAbC0000000000000000000000000000000000001';
const OTHER = '0x0000000000000000000000000000000000000002';

const record = (wallet, hash) => ({ wallet_address: wallet, tx_hash: hash, from_amount: 1 });
const queue = () => JSON.parse(localStorage.getItem(PENDING_RECORDS_KEY) || '[]');

beforeEach(() => {
    localStorage.removeItem(PENDING_RECORDS_KEY);
    axios.post.mockReset();
});

describe('flushPendingSwapRecords', () => {
    it('does nothing when the queue is empty', async () => {
        expect(await flushPendingSwapRecords(ME)).toBe(0);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('sends only this wallet\'s records and keeps the rest', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([record(ME, '0x1'), record(OTHER, '0x2')]));
        axios.post.mockResolvedValue({ data: { success: true } });

        expect(await flushPendingSwapRecords(ME.toLowerCase())).toBe(1);
        expect(axios.post).toHaveBeenCalledWith('/api/v1/swap/execute', expect.objectContaining({ tx_hash: '0x1' }));
        expect(queue().map(r => r.tx_hash)).toEqual(['0x2']);
    });

    it('keeps a record that still fails with 403 for the next try', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([record(ME, '0x1')]));
        axios.post.mockRejectedValue({ response: { status: 403 } });

        expect(await flushPendingSwapRecords(ME)).toBe(0);
        expect(queue()).toHaveLength(1);
    });

    it('drops a record the server rejects for good (422 duplicate/invalid)', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([record(ME, '0x1')]));
        axios.post.mockRejectedValue({ response: { status: 422 } });

        await flushPendingSwapRecords(ME);
        expect(queue()).toHaveLength(0);
    });

    it('drops a swap that belongs to another wallet (409)', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([record(ME, '0x1')]));
        axios.post.mockRejectedValue({ response: { status: 409 } });

        await flushPendingSwapRecords(ME);
        expect(queue()).toHaveLength(0);
    });

    it('keeps a record the chain has not shown yet (202 without a row)', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([{ ...record(ME, '0x1'), _queuedAt: Date.now() }]));
        axios.post.mockResolvedValue({ status: 202, data: { success: true, data: { recorded: false } } });

        expect(await flushPendingSwapRecords(ME)).toBe(0);
        expect(queue()).toHaveLength(1);
    });

    it('treats a pending row on the server as delivered (202 with a row)', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([record(ME, '0x1')]));
        axios.post.mockResolvedValue({ status: 202, data: { success: true, data: { recorded: true } } });

        expect(await flushPendingSwapRecords(ME)).toBe(1);
        expect(queue()).toHaveLength(0);
    });

    it('gives up on a transaction the chain still has not seen after a day', async () => {
        const dayAndMinute = 24 * 60 * 60 * 1000 + 60000;
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([{ ...record(ME, '0x1'), _queuedAt: Date.now() - dayAndMinute }]));
        axios.post.mockRejectedValue({ response: { status: 503 } });

        await flushPendingSwapRecords(ME);
        expect(queue()).toHaveLength(0);
    });

    it('never sends the local queue timestamp to the server', async () => {
        localStorage.setItem(PENDING_RECORDS_KEY, JSON.stringify([{ ...record(ME, '0x1'), _queuedAt: Date.now() }]));
        axios.post.mockResolvedValue({ data: { success: true } });

        await flushPendingSwapRecords(ME);
        expect(axios.post.mock.calls[0][1]).not.toHaveProperty('_queuedAt');
    });
});
