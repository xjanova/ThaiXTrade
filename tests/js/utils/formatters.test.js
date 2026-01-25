/**
 * ThaiXTrade - Formatter Utilities Tests
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';

// Test utility functions (these would be in resources/js/Utils/formatters.js)
const formatPrice = (price, decimals = 2) => {
    const num = parseFloat(price);
    if (isNaN(num)) return '0.00';
    return num.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
};

const formatAmount = (amount, decimals = 6) => {
    const num = parseFloat(amount);
    if (isNaN(num)) return '0';
    return num.toFixed(decimals);
};

const shortenAddress = (address) => {
    if (!address || address.length < 10) return address;
    return `${address.slice(0, 6)}...${address.slice(-4)}`;
};

const formatPercentage = (value) => {
    const num = parseFloat(value);
    if (isNaN(num)) return '0.00%';
    const prefix = num >= 0 ? '+' : '';
    return `${prefix}${num.toFixed(2)}%`;
};

const formatVolume = (volume) => {
    const num = parseFloat(volume);
    if (isNaN(num)) return '0';

    if (num >= 1e12) return `${(num / 1e12).toFixed(2)}T`;
    if (num >= 1e9) return `${(num / 1e9).toFixed(2)}B`;
    if (num >= 1e6) return `${(num / 1e6).toFixed(2)}M`;
    if (num >= 1e3) return `${(num / 1e3).toFixed(2)}K`;

    return num.toFixed(2);
};

describe('Formatter Utilities', () => {
    describe('formatPrice', () => {
        it('formats price with default decimals', () => {
            expect(formatPrice(1234.5678)).toBe('1,234.57');
        });

        it('formats price with custom decimals', () => {
            expect(formatPrice(1234.5678, 4)).toBe('1,234.5678');
        });

        it('handles string input', () => {
            expect(formatPrice('1234.56')).toBe('1,234.56');
        });

        it('handles invalid input', () => {
            expect(formatPrice('invalid')).toBe('0.00');
        });

        it('formats large numbers with commas', () => {
            expect(formatPrice(67234.50)).toBe('67,234.50');
        });
    });

    describe('formatAmount', () => {
        it('formats amount with default decimals', () => {
            expect(formatAmount(0.12345678)).toBe('0.123457');
        });

        it('formats amount with custom decimals', () => {
            expect(formatAmount(0.12345678, 4)).toBe('0.1235');
        });

        it('handles zero', () => {
            expect(formatAmount(0)).toBe('0.000000');
        });
    });

    describe('shortenAddress', () => {
        it('shortens valid address', () => {
            const address = '0x1234567890123456789012345678901234567890';
            expect(shortenAddress(address)).toBe('0x1234...7890');
        });

        it('returns short address as is', () => {
            expect(shortenAddress('0x123')).toBe('0x123');
        });

        it('handles null/undefined', () => {
            expect(shortenAddress(null)).toBe(null);
            expect(shortenAddress(undefined)).toBe(undefined);
        });
    });

    describe('formatPercentage', () => {
        it('formats positive percentage', () => {
            expect(formatPercentage(2.45)).toBe('+2.45%');
        });

        it('formats negative percentage', () => {
            expect(formatPercentage(-1.23)).toBe('-1.23%');
        });

        it('formats zero', () => {
            expect(formatPercentage(0)).toBe('+0.00%');
        });

        it('handles invalid input', () => {
            expect(formatPercentage('invalid')).toBe('0.00%');
        });
    });

    describe('formatVolume', () => {
        it('formats billions', () => {
            expect(formatVolume(45600000000)).toBe('45.60B');
        });

        it('formats millions', () => {
            expect(formatVolume(1234567)).toBe('1.23M');
        });

        it('formats thousands', () => {
            expect(formatVolume(12345)).toBe('12.35K');
        });

        it('formats small numbers', () => {
            expect(formatVolume(123.45)).toBe('123.45');
        });

        it('formats trillions', () => {
            expect(formatVolume(1500000000000)).toBe('1.50T');
        });
    });
});
