/**
 * TPIX TRADE — coin logo resolution
 *
 * ทุกเหรียญที่ "เปิดเทรดจริง" ต้องมีโลโก้อย่างน้อย 2 ชั้น (หลัก + สำรอง)
 * ไม่งั้นถ้า CDN ตัวใดตัวหนึ่งล่ม ตารางคู่เทรดจะเหลือแต่ตัวอักษร
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import {
    getCoinLogo,
    getCoinLogoFallback,
    getBSCTokenLogo,
    hasCoinLogo,
    getBaseSymbol,
    getPairLogo,
} from '@/utils/cryptoLogos';
import { BSC_TRADE_TOKENS } from '@/Config/bscTradeTokens';

/** เหรียญที่หน้าเทรดเปิดให้เทรดจริง (แหล่งความจริงเดียวกับที่ใช้ส่งธุรกรรม) */
const TRADABLE = Object.keys(BSC_TRADE_TOKENS);

describe('getCoinLogo', () => {
    it('uses the TPIX coin logo, not the 333KB platform brand logo', () => {
        // ของเดิมชี้ /logo.png ซึ่งเป็นโลโก้แบรนด์ TPIX TRADE คนละภาพกับเหรียญ
        expect(getCoinLogo('TPIX')).toBe('/tpixlogo.webp');
        expect(getCoinLogo('tpix')).toBe('/tpixlogo.webp');
    });

    it('maps wrapped TPIX to the same local asset', () => {
        expect(getCoinLogo('WTPIX')).toBe('/tpixlogo.webp');
    });

    it('maps wrapped tokens to their base coin', () => {
        expect(getCoinLogo('WBTC')).toContain('/btc@2x.png');
        expect(getCoinLogo('WETH')).toContain('/eth@2x.png');
        expect(getCoinLogo('BTCB')).toContain('/btc@2x.png');
    });

    it('accepts a full pair and resolves the base coin', () => {
        expect(getPairLogo('BTC/USDT')).toContain('/btc@2x.png');
        expect(getPairLogo('ETH-USDT')).toContain('/eth@2x.png');
    });

    it('returns an empty string for junk input', () => {
        expect(getCoinLogo('')).toBe('');
        expect(getCoinLogo(null)).toBe('');
        expect(getCoinLogo(undefined)).toBe('');
    });
});

describe('getCoinLogoFallback', () => {
    it('gives every tradable coin a second source', () => {
        const missing = TRADABLE.filter(s => !getCoinLogoFallback(s));
        expect(missing).toEqual([]);
    });

    it('covers POL, which was left without a fallback after the MATIC rename', () => {
        expect(getCoinLogoFallback('POL')).toContain('polygon-matic-logo');
    });

    it('uses the Trust Wallet CDN, not the rate-limited raw GitHub host', () => {
        const url = getBSCTokenLogo('0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c');
        expect(url).toContain('assets-cdn.trustwallet.com');
        expect(url).not.toContain('raw.githubusercontent.com');
    });

    it('has no fallback for local logos (they cannot fail over the network)', () => {
        expect(getCoinLogoFallback('TPIX')).toBeNull();
    });

    it('returns null for an unknown coin so the caller can show a letter', () => {
        expect(getCoinLogoFallback('ZZZZNOTACOIN')).toBeNull();
    });
});

describe('hasCoinLogo', () => {
    it('is true for every tradable coin', () => {
        const missing = TRADABLE.filter(s => !hasCoinLogo(s));
        expect(missing).toEqual([]);
    });

    it('is false for an unknown coin', () => {
        // ของเดิมคืน true ให้ทุกสตริงที่ไม่ว่าง ทำให้ฟังก์ชันไม่มีประโยชน์
        expect(hasCoinLogo('ZZZZNOTACOIN')).toBe(false);
        expect(hasCoinLogo('')).toBe(false);
    });
});

describe('getBaseSymbol', () => {
    it('handles both separators and casing', () => {
        expect(getBaseSymbol('btc/usdt')).toBe('BTC');
        expect(getBaseSymbol('SHIB-USDT')).toBe('SHIB');
        expect(getBaseSymbol('  eth  ')).toBe('ETH');
        expect(getBaseSymbol('')).toBe('');
    });
});

describe('BSC fallback address map', () => {
    it('stays in sync with the tokens the app actually trades', () => {
        // เหรียญที่เทรดได้ทุกตัวต้องมีแหล่งสำรอง — ผ่าน CryptoLogos หรือ Trust Wallet
        for (const symbol of TRADABLE) {
            const fallback = getCoinLogoFallback(symbol);
            expect(fallback, `${symbol} ไม่มีแหล่งสำรอง`).toBeTruthy();
        }
    });
});
