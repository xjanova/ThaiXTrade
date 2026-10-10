import { describe, it, expect } from 'vitest';
import { findKeystoreFor, normalizeRecipient, parseTpixAmount, friendlySendError } from '@/utils/treasuryColdWallet';

// keystore รูปแบบ V3 แบบย่อ — ฟังก์ชันอ่านแค่ address กับการมีอยู่ของ crypto ไม่ถอดรหัส
const ks = (addressHex) => ({ version: 3, address: addressHex, crypto: { kdf: 'scrypt' } });
const LIQUIDITY = '0x2644A740A06e0401D21F8B4A840400fFe8dB42A9';
const TOKEN_SALE = '0x4BcC1844Ad9E8587f7005f092928a5D14C30F463';

describe('findKeystoreFor', () => {
    it('accepts a single keystore for the right wallet', () => {
        const text = JSON.stringify(ks(LIQUIDITY.slice(2).toLowerCase()));
        expect(JSON.parse(findKeystoreFor(text, LIQUIDITY)).address).toBe(LIQUIDITY.slice(2).toLowerCase());
    });

    it('rejects a single keystore of another wallet and names it', () => {
        const text = JSON.stringify(ks(TOKEN_SALE.slice(2).toLowerCase()));
        expect(() => findKeystoreFor(text, LIQUIDITY)).toThrow(TOKEN_SALE.toLowerCase());
    });

    it('picks the matching entry out of master-wallet.keystores.json', () => {
        // ไฟล์จริงเก็บค่าเป็น "string ของ JSON" ต่อ role
        const bundle = {
            'token-sale': JSON.stringify(ks(TOKEN_SALE.slice(2).toLowerCase())),
            'liquidity-market-making': JSON.stringify(ks(LIQUIDITY.slice(2).toLowerCase())),
        };
        const found = JSON.parse(findKeystoreFor(JSON.stringify(bundle), LIQUIDITY));
        expect(`0x${found.address}`).toBe(LIQUIDITY.toLowerCase());
    });

    it('accepts a bundle whose entries are objects, and 0x-prefixed addresses', () => {
        const bundle = { a: ks(`0x${LIQUIDITY.slice(2)}`) };
        expect(() => findKeystoreFor(JSON.stringify(bundle), LIQUIDITY)).not.toThrow();
    });

    it('says so when the bundle has no entry for this wallet', () => {
        const bundle = { 'token-sale': JSON.stringify(ks(TOKEN_SALE.slice(2))) };
        expect(() => findKeystoreFor(JSON.stringify(bundle), LIQUIDITY)).toThrow('ไม่มี keystore');
    });

    it('rejects non-JSON and arrays', () => {
        expect(() => findKeystoreFor('not json', LIQUIDITY)).toThrow('ไม่ใช่ JSON');
        expect(() => findKeystoreFor('[1,2]', LIQUIDITY)).toThrow('ไม่รู้จักรูปแบบไฟล์');
    });
});

describe('normalizeRecipient', () => {
    it('returns the checksum form for valid input', () => {
        expect(normalizeRecipient('0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7')).toBe('0xD2eAB07809921fcB36c7AB72D7B5D8D2C12A67d7');
        expect(normalizeRecipient('  0xD2eAB07809921fcB36c7AB72D7B5D8D2C12A67d7 ')).toBe('0xD2eAB07809921fcB36c7AB72D7B5D8D2C12A67d7');
    });

    it('rejects a mixed-case address with a wrong checksum (one typo)', () => {
        expect(normalizeRecipient('0xD2EAB07809921fcB36c7AB72D7B5D8D2C12A67d7')).toBeNull();
    });

    it('rejects malformed input', () => {
        expect(normalizeRecipient('')).toBeNull();
        expect(normalizeRecipient('0x123')).toBeNull();
        expect(normalizeRecipient('d2eab07809921fcb36c7ab72d7b5d8d2c12a67d7')).toBeNull();
        expect(normalizeRecipient(null)).toBeNull();
    });
});

describe('parseTpixAmount', () => {
    it('parses whole and fractional amounts to wei', () => {
        expect(parseTpixAmount('210')).toBe(210n * 10n ** 18n);
        expect(parseTpixAmount('0.5')).toBe(5n * 10n ** 17n);
        expect(parseTpixAmount('1,000,000')).toBe(10n ** 24n);
    });

    it('rejects zero, negatives, junk and >18 decimals', () => {
        expect(parseTpixAmount('0')).toBeNull();
        expect(parseTpixAmount('0.000')).toBeNull();
        expect(parseTpixAmount('-5')).toBeNull();
        expect(parseTpixAmount('1e3')).toBeNull();
        expect(parseTpixAmount('abc')).toBeNull();
        expect(parseTpixAmount('1.0000000000000000001')).toBeNull();
        expect(parseTpixAmount('')).toBeNull();
    });
});

describe('friendlySendError', () => {
    it('maps the errors an admin will actually hit', () => {
        expect(friendlySendError(new Error('incorrect password'))).toBe('รหัสผ่าน keystore ไม่ถูกต้อง');
        expect(friendlySendError({ shortMessage: 'insufficient funds for intrinsic transaction cost' })).toBe('ยอดในกระเป๋าไม่พอ');
        expect(friendlySendError(new Error('nonce too low'))).toContain('อย่ากดส่งซ้ำ');
        expect(friendlySendError(new TypeError('Failed to fetch'))).toContain('ต่อเชนไม่ได้');
    });

    it('truncates long unknown messages instead of dumping them', () => {
        expect(friendlySendError(new Error('x'.repeat(500))).length).toBeLessThanOrEqual(201);
    });
});
