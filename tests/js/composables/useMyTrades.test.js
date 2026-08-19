/**
 * TPIX TRADE — useMyTrades tests
 *
 * ป้ายบนกราฟพังแบบเงียบได้ง่ายมาก: หน่วยเวลาผิด (มิลลิวินาที vs วินาที) หรือ
 * ชื่อคู่เขียนคนละแบบ (`BTC-USDT` vs `BTC/USDT`) แล้วป้ายก็หายไปเฉยๆ
 * โดยไม่มี error ให้เห็น — ผู้ใช้จะสรุปว่า "บอทไม่ได้เทรด" ทั้งที่เทรดอยู่
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

const wallet = { address: null };

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }));

const { useMyTrades } = await import('@/Composables/useMyTrades');

const WALLET = '0x1111111111111111111111111111111111111111';

/** 2026-08-19T10:00:00Z = 1787133600 วินาที */
const ISO = '2026-08-19T10:00:00.000Z';
const SECONDS = Math.floor(new Date(ISO).getTime() / 1000);

function respondWith(rows) {
    get.mockResolvedValue({ data: { success: true, data: rows } });
}

describe('useMyTrades', () => {
    beforeEach(() => {
        wallet.address = WALLET;
        get.mockReset();
        // ล้างแคชระดับโมดูลด้วยการสลับกระเป๋าไปมา
        wallet.address = null;
        useMyTrades().load();
        wallet.address = WALLET;
    });

    it('ไม่ยิง API เลยเมื่อยังไม่ได้เชื่อมกระเป๋า', async () => {
        wallet.address = null;

        const result = await useMyTrades().load();

        expect(result).toEqual([]);
        expect(get).not.toHaveBeenCalled();
    });

    it('แปลงเวลาเป็นวินาทีตามที่กราฟใช้ ไม่ใช่มิลลิวินาที', async () => {
        respondWith([{ pair: 'BTC/USDT', side: 'buy', price: '100', created_at: ISO }]);

        const trades = useMyTrades();
        await trades.load(true);

        expect(trades.markersFor('BTC/USDT')[0].time).toBe(SECONDS);
    });

    /** สองฝั่งเขียนชื่อคู่ไม่เหมือนกัน — หน้าเทรดใช้ `BTC-USDT` ส่วน API คืน `BTC/USDT` */
    it('จับคู่ชื่อคู่เทรดได้ทั้งแบบขีดและแบบทับ', async () => {
        respondWith([{ pair: 'BTC-USDT', side: 'sell', price: '120', created_at: ISO }]);

        const trades = useMyTrades();
        await trades.load(true);

        expect(trades.markersFor('BTC/USDT')).toHaveLength(1);
        expect(trades.markersFor('BTC-USDT')).toHaveLength(1);
    });

    it('เอาเฉพาะไม้ของคู่ที่กำลังดูอยู่', async () => {
        respondWith([
            { pair: 'BTC/USDT', side: 'buy', price: '100', created_at: ISO },
            { pair: 'ETH/USDT', side: 'buy', price: '50', created_at: ISO },
        ]);

        const trades = useMyTrades();
        await trades.load(true);

        expect(trades.markersFor('BTC/USDT')).toHaveLength(1);
        expect(trades.markersFor('BTC/USDT')[0].source).toBe('mine');
    });

    /** แถวที่ side ไม่ใช่ buy/sell วาดลูกศรไม่ได้ ต้องทิ้ง ไม่ใช่วาดมั่ว */
    it('ทิ้งแถวที่บอกทิศทางไม่ได้', async () => {
        respondWith([
            { pair: 'BTC/USDT', side: 'deposit', price: '100', created_at: ISO },
            { pair: 'BTC/USDT', side: 'BUY', price: '100', created_at: ISO },
        ]);

        const trades = useMyTrades();
        await trades.load(true);

        const markers = trades.markersFor('BTC/USDT');

        expect(markers).toHaveLength(1);
        expect(markers[0].side).toBe('buy');
    });

    it('กระเป๋าที่ยังไม่ยืนยัน (403) ต้องเงียบ ไม่ใช่พังทั้งหน้า', async () => {
        get.mockRejectedValue({ response: { status: 403 } });

        const trades = useMyTrades();
        const result = await trades.load(true);

        expect(result).toEqual([]);
        expect(trades.markersFor('BTC/USDT')).toEqual([]);
    });

    it('เรียกซ้ำโดยไม่ force ไม่ยิง API ใหม่', async () => {
        respondWith([{ pair: 'BTC/USDT', side: 'buy', price: '100', created_at: ISO }]);

        const trades = useMyTrades();
        await trades.load(true);
        const callsAfterFirst = get.mock.calls.length;

        await trades.load();

        expect(get.mock.calls.length).toBe(callsAfterFirst);
    });
});
