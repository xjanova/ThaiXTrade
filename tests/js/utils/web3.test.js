/**
 * ThaiXTrade - Web3 Utilities Tests
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Web3 utility functions
const isValidAddress = (address) => {
    if (!address) return false;
    return /^0x[a-fA-F0-9]{40}$/.test(address);
};

const isValidChainId = (chainId) => {
    if (!chainId) return false;
    const num = typeof chainId === 'string' ? parseInt(chainId, 16) : chainId;
    return Number.isInteger(num) && num > 0;
};

const hexToDecimal = (hex) => {
    if (typeof hex === 'number') return hex;
    return parseInt(hex, 16);
};

const decimalToHex = (decimal) => {
    return '0x' + decimal.toString(16);
};

const weiToEther = (wei) => {
    const num = BigInt(wei);
    return Number(num) / 1e18;
};

const etherToWei = (ether) => {
    return BigInt(Math.floor(parseFloat(ether) * 1e18)).toString();
};

describe('Web3 Utilities', () => {
    describe('isValidAddress', () => {
        it('validates correct Ethereum address', () => {
            expect(isValidAddress('0x1234567890123456789012345678901234567890')).toBe(true);
        });

        it('rejects address without 0x prefix', () => {
            expect(isValidAddress('1234567890123456789012345678901234567890')).toBe(false);
        });

        it('rejects short address', () => {
            expect(isValidAddress('0x1234')).toBe(false);
        });

        it('rejects null/undefined', () => {
            expect(isValidAddress(null)).toBe(false);
            expect(isValidAddress(undefined)).toBe(false);
        });

        it('validates lowercase address', () => {
            expect(isValidAddress('0xabcdef0123456789abcdef0123456789abcdef01')).toBe(true);
        });

        it('validates uppercase address', () => {
            expect(isValidAddress('0xABCDEF0123456789ABCDEF0123456789ABCDEF01')).toBe(true);
        });
    });

    describe('isValidChainId', () => {
        it('validates decimal chain ID', () => {
            expect(isValidChainId(1)).toBe(true);
            expect(isValidChainId(56)).toBe(true);
        });

        it('validates hex chain ID', () => {
            expect(isValidChainId('0x1')).toBe(true);
            expect(isValidChainId('0x38')).toBe(true); // BSC
        });

        it('rejects zero', () => {
            expect(isValidChainId(0)).toBe(false);
        });

        it('rejects negative', () => {
            expect(isValidChainId(-1)).toBe(false);
        });

        it('rejects null/undefined', () => {
            expect(isValidChainId(null)).toBe(false);
            expect(isValidChainId(undefined)).toBe(false);
        });
    });

    describe('hexToDecimal', () => {
        it('converts hex to decimal', () => {
            expect(hexToDecimal('0x1')).toBe(1);
            expect(hexToDecimal('0x38')).toBe(56); // BSC
            expect(hexToDecimal('0xa')).toBe(10);
        });

        it('handles number input', () => {
            expect(hexToDecimal(56)).toBe(56);
        });
    });

    describe('decimalToHex', () => {
        it('converts decimal to hex', () => {
            expect(decimalToHex(1)).toBe('0x1');
            expect(decimalToHex(56)).toBe('0x38'); // BSC
            expect(decimalToHex(10)).toBe('0xa');
        });
    });

    describe('weiToEther', () => {
        it('converts wei to ether', () => {
            expect(weiToEther('1000000000000000000')).toBe(1);
            expect(weiToEther('500000000000000000')).toBe(0.5);
        });

        it('handles small amounts', () => {
            expect(weiToEther('1000000000000000')).toBe(0.001);
        });
    });

    describe('etherToWei', () => {
        it('converts ether to wei', () => {
            expect(etherToWei('1')).toBe('1000000000000000000');
            expect(etherToWei('0.5')).toBe('500000000000000000');
        });

        it('handles decimal input', () => {
            expect(etherToWei(1)).toBe('1000000000000000000');
        });
    });
});

describe('Ethereum Provider Mock', () => {
    it('mock provider is available', () => {
        expect(global.ethereum).toBeDefined();
        expect(global.ethereum.isMetaMask).toBe(true);
    });

    it('can request chain ID', async () => {
        const chainId = await global.ethereum.request({ method: 'eth_chainId' });
        expect(chainId).toBe('0x38'); // BSC
    });

    it('can request accounts', async () => {
        const accounts = await global.ethereum.request({ method: 'eth_requestAccounts' });
        expect(Array.isArray(accounts)).toBe(true);
        expect(accounts.length).toBeGreaterThan(0);
    });
});
