/**
 * TPIX TRADE - BSC Trade Token Registry Tests
 * ตรวจความถูกต้องของ map เหรียญ major → BSC address ที่ใช้เทรดจริง
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { BSC_TRADE_TOKENS, getBscTradeToken } from '@/Config/bscTradeTokens';

// คู่ major ทั้ง 18 ตัวที่ seed ใน MajorTradingPairsSeeder ต้องเทรดได้ครบ
const SEEDED_MAJORS = [
    'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'DOGE', 'ADA', 'POL', 'AVAX',
    'DOT', 'LINK', 'UNI', 'LTC', 'TRX', 'ATOM', 'NEAR', 'SHIB', 'PEPE',
];

describe('BSC_TRADE_TOKENS registry', () => {
    it('has a valid EVM address for every token', () => {
        for (const [symbol, entry] of Object.entries(BSC_TRADE_TOKENS)) {
            expect(entry.address, `${symbol} address`).toMatch(/^0x[a-fA-F0-9]{40}$/);
        }
    });

    it('has no duplicate addresses', () => {
        const addresses = Object.values(BSC_TRADE_TOKENS).map(t => t.address.toLowerCase());
        expect(new Set(addresses).size).toBe(addresses.length);
    });

    it('has sane decimals for every token', () => {
        for (const [symbol, entry] of Object.entries(BSC_TRADE_TOKENS)) {
            expect(entry.decimals, `${symbol} decimals`).toBeGreaterThanOrEqual(0);
            expect(entry.decimals, `${symbol} decimals`).toBeLessThanOrEqual(18);
        }
    });

    it('covers all 18 seeded major pairs plus USDT quote', () => {
        for (const symbol of SEEDED_MAJORS) {
            expect(getBscTradeToken(symbol), `${symbol} must be tradable`).toBeTruthy();
        }
        expect(getBscTradeToken('USDT')).toBeTruthy();
    });

    it('marks only BNB as native', () => {
        const natives = Object.entries(BSC_TRADE_TOKENS).filter(([, t]) => t.native);
        expect(natives.map(([s]) => s)).toEqual(['BNB']);
    });

    it('every non-native token declares accepted on-chain symbols', () => {
        for (const [symbol, entry] of Object.entries(BSC_TRADE_TOKENS)) {
            if (entry.native) continue;
            expect(Array.isArray(entry.onchainSymbols), `${symbol} onchainSymbols`).toBe(true);
            expect(entry.onchainSymbols.length, `${symbol} onchainSymbols`).toBeGreaterThan(0);
        }
    });

    it('getBscTradeToken is case-insensitive and null-safe', () => {
        expect(getBscTradeToken('btc')).toBe(BSC_TRADE_TOKENS.BTC);
        expect(getBscTradeToken('TPIX')).toBeNull();
        expect(getBscTradeToken('')).toBeNull();
        expect(getBscTradeToken(null)).toBeNull();
    });
});
