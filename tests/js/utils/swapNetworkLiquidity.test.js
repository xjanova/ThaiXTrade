/**
 * TPIX TRADE — หน้าสภาพคล่อง: พูลไหนเป็นพูลของ TPIX และลิงก์ไปหน้าเทรดชื่ออะไร
 *
 * บั๊กที่ห้ามกลับมา:
 *  - พูลเดียวบน prod (TPIX-USDT ซึ่ง TPIX เป็นฝั่ง base) ไม่ขึ้นในรายการ → "ยังไม่มีพูล"
 *  - ลิงก์ "เทรด" ต่อชื่อเป็น USDT-TPIX ซึ่งไม่มีอยู่จริง → หน้า "เร็วๆ นี้" ทางตัน
 *  - เกณฑ์ผลกระทบราคามีชุดเดียว (re-export จาก useTpixDex) — /swap กับ /trade ตัดสินตรงกัน
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import {
    liquidityPoolFromPair,
    liquidityTradeSymbol,
    isPriceImpactBlocked,
    PRICE_IMPACT_BLOCK_PCT,
} from '@/utils/swapNetwork';
import * as dex from '@/Composables/useTpixDex';

const ZERO = '0x0000000000000000000000000000000000000000';
const USDT = '0x2222222222222222222222222222222222222222';
const ABC = '0xAbCdEf0000000000000000000000000000000001';

const TPIX_USDT = {
    symbol: 'TPIX-USDT',
    base_asset: 'TPIX', base_address: ZERO, base_decimals: 18,
    quote_asset: 'USDT', quote_address: USDT, quote_decimals: 6,
    is_active: true, chain_id: 4289,
};
const ABC_TPIX = {
    symbol: 'ABC-TPIX',
    base_asset: 'ABC', base_address: ABC, base_decimals: 18,
    quote_asset: 'TPIX', quote_address: ZERO, quote_decimals: 18,
    is_active: true, chain_id: 4289,
};

describe('liquidityPoolFromPair', () => {
    it('lists TPIX-USDT, where TPIX is the base side', () => {
        const pool = liquidityPoolFromPair(TPIX_USDT);

        expect(pool).toMatchObject({ tokenAddress: USDT, tokenAsset: 'USDT', tokenDecimals: 6, symbol: 'TPIX-USDT' });
    });

    it('still lists TOKEN/TPIX pools, where TPIX is the quote side', () => {
        expect(liquidityPoolFromPair(ABC_TPIX)).toMatchObject({ tokenAddress: ABC, tokenAsset: 'ABC', tokenDecimals: 18 });
    });

    it('skips pairs without TPIX and pairs whose token data is broken', () => {
        expect(liquidityPoolFromPair({ ...TPIX_USDT, base_address: ABC })).toBeNull();
        expect(liquidityPoolFromPair({ ...TPIX_USDT, quote_address: 'index:USDT' })).toBeNull();
        expect(liquidityPoolFromPair(null)).toBeNull();
    });

    it('never guesses decimals that the server did not send', () => {
        expect(liquidityPoolFromPair({ ...TPIX_USDT, quote_decimals: null }).tokenDecimals).toBeNull();
    });
});

describe('liquidityTradeSymbol', () => {
    const pools = [TPIX_USDT, ABC_TPIX].map(liquidityPoolFromPair);

    it('links to the pair name dex:sync actually created (TPIX-USDT, not USDT-TPIX)', () => {
        expect(liquidityTradeSymbol(pools, { address: USDT.toLowerCase(), symbol: 'USDT' })).toBe('TPIX-USDT');
        expect(liquidityTradeSymbol(pools, { address: ABC.toLowerCase(), symbol: 'ABC' })).toBe('ABC-TPIX');
    });

    it('falls back to TOKEN-TPIX for a brand new pool that is not synced yet', () => {
        expect(liquidityTradeSymbol(pools, { address: '0x9999999999999999999999999999999999999999', symbol: 'new' })).toBe('NEW-TPIX');
        expect(liquidityTradeSymbol(pools, null)).toBeNull();
    });
});

describe('price impact gate is shared', () => {
    it('is the very same function and limit as useTpixDex', () => {
        expect(isPriceImpactBlocked).toBe(dex.isPriceImpactBlocked);
        expect(PRICE_IMPACT_BLOCK_PCT).toBe(dex.PRICE_IMPACT_BLOCK_PCT);
    });
});
