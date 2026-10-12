/**
 * TPIX TRADE — ตรรกะเลือกเครือข่ายของหน้า Swap (BSC / TPIX Chain)
 *
 * ด่านที่ห้ามพัง:
 *  - DEX ยังไม่พร้อม → BSC เสมอ ต่อให้ผู้ใช้/ค่าที่จำไว้บอกว่า TPIX (fail-closed)
 *  - BSC ผูก chain id 56 ตรง ๆ — ไม่ใช่เชนปริยายของระบบ (ซึ่งพลิกเป็น TPIX ได้)
 *  - รายการเหรียญ TPIX มาจากคู่ที่เปิดอยู่บนเชน 4289 เท่านั้น และ decimals ห้ามเดา
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import {
    SWAP_NETWORKS,
    TPIX_CHAIN_ID,
    PRICE_IMPACT_BLOCK_PCT,
    chainIdForNetwork,
    resolveSwapNetwork,
    buildTpixTokenList,
    defaultTokenPair,
    sameToken,
    isPriceImpactBlocked,
    exceedsBalance,
} from '@/utils/swapNetwork';

const ZERO = '0x0000000000000000000000000000000000000000';
const USDT = '0x2222222222222222222222222222222222222222';
const ABC = '0xAbCdEf0000000000000000000000000000000001';

/** คู่ในรูปของ /api/v1/dex/pairs */
function dexPair(overrides = {}) {
    return {
        symbol: 'TPIX-USDT',
        base_asset: 'TPIX',
        quote_asset: 'USDT',
        base_address: ZERO,
        quote_address: USDT,
        base_decimals: 18,
        quote_decimals: 6,
        base_logo: null,
        quote_logo: 'https://cdn.example/usdt.png',
        is_active: true,
        chain_id: 4289,
        ...overrides,
    };
}

describe('chainIdForNetwork', () => {
    it('binds BSC to 56 and TPIX to 4289', () => {
        expect(chainIdForNetwork(SWAP_NETWORKS.BSC)).toBe(56);
        expect(chainIdForNetwork(SWAP_NETWORKS.TPIX)).toBe(4289);
        expect(TPIX_CHAIN_ID).toBe(4289);
    });

    it('anything unknown falls back to BSC (56), never the system default chain', () => {
        expect(chainIdForNetwork(undefined)).toBe(56);
        expect(chainIdForNetwork('ethereum')).toBe(56);
    });
});

describe('resolveSwapNetwork (fail-closed)', () => {
    it('stays on BSC while the DEX is not confirmed ready', () => {
        expect(resolveSwapNetwork({ dexReady: false, tpixTokenCount: 5 })).toBe('bsc');
        expect(resolveSwapNetwork({ requested: 'tpix', dexReady: false, tpixTokenCount: 5 })).toBe('bsc');
        // ค่าที่ไม่ใช่ true ตรง ๆ (เช่น 'true' จาก API ที่เพี้ยน) ไม่นับว่าพร้อม
        expect(resolveSwapNetwork({ requested: 'tpix', dexReady: 'true', tpixTokenCount: 5 })).toBe('bsc');
        expect(resolveSwapNetwork()).toBe('bsc');
    });

    it('defaults to TPIX only when the DEX is ready and has something to swap', () => {
        expect(resolveSwapNetwork({ dexReady: true, tpixTokenCount: 2 })).toBe('tpix');
        expect(resolveSwapNetwork({ dexReady: true, tpixTokenCount: 1 })).toBe('bsc');
        expect(resolveSwapNetwork({ dexReady: true, tpixTokenCount: 0 })).toBe('bsc');
    });

    it('respects an explicit choice once the DEX is ready', () => {
        expect(resolveSwapNetwork({ requested: 'bsc', dexReady: true, tpixTokenCount: 4 })).toBe('bsc');
        // เลือก TPIX เองแม้ยังไม่มีคู่ — หน้าแสดงสถานะว่าง ไม่แอบพากลับ BSC
        expect(resolveSwapNetwork({ requested: 'tpix', dexReady: true, tpixTokenCount: 0 })).toBe('tpix');
    });
});

describe('buildTpixTokenList', () => {
    it('builds unique tokens from active DEX pairs with native TPIX first', () => {
        const tokens = buildTpixTokenList([
            dexPair({ symbol: 'ABC-USDT', base_asset: 'ABC', base_address: ABC, base_decimals: 9 }),
            dexPair(),
            dexPair({ symbol: 'ABC-TPIX', base_asset: 'ABC', quote_asset: 'TPIX', base_address: ABC, base_decimals: 9, quote_address: ZERO, quote_decimals: 18 }),
        ]);

        expect(tokens.map(t => t.symbol)).toEqual(['TPIX', 'ABC', 'USDT']);
        expect(tokens[0]).toMatchObject({ address: 'native', native: true, decimals: 18 });
        // ที่อยู่ ERC-20 เป็นตัวพิมพ์เล็ก (ตามที่ useTpixDex ใช้)
        expect(tokens[1]).toMatchObject({ address: ABC.toLowerCase(), decimals: 9, native: false });
        expect(tokens[2]).toMatchObject({ address: USDT, decimals: 6, logo: 'https://cdn.example/usdt.png' });
        // ยังไม่รู้ยอด ≠ ยอดศูนย์
        expect(tokens.every(t => t.balanceRaw === null && t.balance === '0.00')).toBe(true);
    });

    it('skips inactive pairs and pairs that are not on-chain TPIX DEX pairs', () => {
        const tokens = buildTpixTokenList([
            dexPair({ is_active: false }),
            dexPair({ is_active: undefined }),
            // รูปของ /api/v1/market/pairs: chain_id = PK ของตาราง — ต้องดู network_chain_id
            dexPair({ chain_id: 4289, network_chain_id: 56, execution_mode: 'onchain' }),
            dexPair({ chain_id: 3, network_chain_id: 4289, execution_mode: 'index' }),
            dexPair({ chain_id: 56 }),
        ]);

        expect(tokens).toEqual([]);
    });

    it('accepts the /api/v1/market/pairs shape (network_chain_id + execution_mode)', () => {
        const tokens = buildTpixTokenList([
            dexPair({ chain_id: 7, network_chain_id: 4289, execution_mode: 'onchain' }),
        ]);

        expect(tokens.map(t => t.symbol)).toEqual(['TPIX', 'USDT']);
    });

    it('never guesses decimals or treats a missing address as native', () => {
        const tokens = buildTpixTokenList([
            dexPair({ quote_decimals: null }),
            dexPair({ quote_decimals: '6.5' }),
            dexPair({ quote_decimals: 99 }),
            dexPair({ quote_address: null }),
            dexPair({ quote_address: '' }),
            dexPair({ quote_address: '0x1234' }),
        ]);

        // ทุกคู่มีฝั่งที่ข้อมูลเสีย → ทิ้งทั้งคู่ (ไม่มี TPIX ลอย ๆ ตัวเดียว)
        expect(tokens).toEqual([]);
    });

    it('drops a token whose decimals disagree between two pairs', () => {
        const tokens = buildTpixTokenList([
            dexPair(),
            dexPair({ symbol: 'USDT-ABC', base_asset: 'USDT', quote_asset: 'ABC', base_address: USDT, base_decimals: 18, quote_address: ABC, quote_decimals: 9 }),
        ]);

        expect(tokens.map(t => t.symbol)).toEqual(['TPIX', 'ABC']);
    });

    it('survives garbage input', () => {
        expect(buildTpixTokenList(null)).toEqual([]);
        expect(buildTpixTokenList([null, 1, 'x'])).toEqual([]);
    });
});

describe('defaultTokenPair', () => {
    it('picks TPIX → USDT when both exist', () => {
        const tokens = buildTpixTokenList([
            dexPair({ symbol: 'ABC-TPIX', base_asset: 'ABC', quote_asset: 'TPIX', base_address: ABC, base_decimals: 9, quote_address: ZERO }),
            dexPair(),
        ]);
        const pair = defaultTokenPair(tokens);

        expect(pair.from.symbol).toBe('TPIX');
        expect(pair.to.symbol).toBe('USDT');
    });

    it('returns null when there is nothing to swap', () => {
        expect(defaultTokenPair([])).toBeNull();
        expect(defaultTokenPair([{ symbol: 'TPIX', address: 'native', native: true }])).toBeNull();
    });
});

describe('sameToken', () => {
    it('compares addresses case-insensitively, not symbols', () => {
        expect(sameToken({ symbol: 'USDT', address: USDT.toUpperCase().replace('0X', '0x') }, { symbol: 'X', address: USDT })).toBe(true);
        expect(sameToken({ symbol: 'USDT', address: USDT }, { symbol: 'USDT', address: ABC })).toBe(false);
        expect(sameToken(null, { address: USDT })).toBe(false);
    });
});

describe('isPriceImpactBlocked', () => {
    it('blocks above the limit used on /trade', () => {
        expect(PRICE_IMPACT_BLOCK_PCT).toBe(10);
        expect(isPriceImpactBlocked(10)).toBe(false);
        expect(isPriceImpactBlocked(10.01)).toBe(true);
        expect(isPriceImpactBlocked(0.2)).toBe(false);
    });

    it('blocks when the impact is unknown', () => {
        expect(isPriceImpactBlocked(null)).toBe(true);
        expect(isPriceImpactBlocked(undefined)).toBe(true);
        expect(isPriceImpactBlocked(NaN)).toBe(true);
    });
});

describe('exceedsBalance', () => {
    it('flags amounts above a known balance', () => {
        expect(exceedsBalance('5', '1.5')).toBe(true);
        expect(exceedsBalance('1.5', '1.5')).toBe(false);
        expect(exceedsBalance('0.1', '1.5')).toBe(false);
    });

    it('does not judge without a known balance or a real amount', () => {
        expect(exceedsBalance('5', null)).toBe(false);
        expect(exceedsBalance('5', undefined)).toBe(false);
        expect(exceedsBalance('', '1')).toBe(false);
        expect(exceedsBalance('0', '0')).toBe(false);
    });
});
