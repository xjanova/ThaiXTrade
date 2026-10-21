/**
 * TPIX TRADE — ตัวช่วยของหน้าสะพาน USDT
 *
 * เงินคำนวณเป็น BigInt ทั้งหมด — เทสต์ชุดนี้กันไม่ให้มีใครแอบเปลี่ยนกลับไปใช้ Number/float
 * (1 USDT บน BSC = 1e18 เกิน Number.MAX_SAFE_INTEGER · 0.1 + 0.2 ≠ 0.3)
 *
 * Developed by Xman Studio
 */
import { describe, it, expect } from 'vitest';
import {
    parseAmountToUnits,
    formatUnitsPlain,
    withdrawReceiveUnits,
    checkDepositAmount,
    depositReceiveUnits,
    isValidEvmAddress,
    stateBadge,
    explorerTx,
    explorerTokenHolder,
    loadTracked,
    saveTracked,
    addTracked,
    friendlyWalletError,
} from '@/utils/bridge';

const memoryStorage = () => {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
    };
};

describe('parseAmountToUnits', () => {
    it('converts to the token\'s smallest unit exactly, including 18-decimal BSC USDT', () => {
        expect(parseAmountToUnits('12.345678', 18)).toBe(12345678000000000000n);
        expect(parseAmountToUnits('0.1', 6)).toBe(100000n);
        expect(parseAmountToUnits('1000', 6)).toBe(1000000000n);
        expect(parseAmountToUnits('1,000.5', 6)).toBe(1000500000n);
    });

    it('rejects more than 6 decimals even on 18-decimal chains (the extra would be dust left in the vault)', () => {
        expect(parseAmountToUnits('1.1234567', 18)).toBeNull();
        expect(parseAmountToUnits('1.1234567', 6)).toBeNull();
    });

    it('rejects anything that is not a plain positive number', () => {
        for (const bad of ['', 'abc', '-1', '1e6', '0x10', '1.2.3', ' . ', null, undefined]) {
            expect(parseAmountToUnits(bad, 6)).toBeNull();
        }
    });
});

describe('formatUnitsPlain', () => {
    it('formats without float artefacts', () => {
        expect(formatUnitsPlain(24700000000000000000n, 18)).toBe('24.7');
        expect(formatUnitsPlain(1n, 6)).toBe('0.000001');
        expect(formatUnitsPlain(0n, 6)).toBe('0');
        expect(formatUnitsPlain(-300000n, 6)).toBe('-0.3');
    });
});

describe('withdrawReceiveUnits', () => {
    it('subtracts the flat chain fee and never goes negative', () => {
        expect(withdrawReceiveUnits(25000000n, '0.3')).toBe(24700000n);
        expect(withdrawReceiveUnits(1000000n, '1')).toBe(0n);
        expect(withdrawReceiveUnits(500000n, '1')).toBe(0n);
    });
});

describe('checkDepositAmount', () => {
    const limits = { min_deposit: '1', max_deposit_per_tx: '1000' };

    it('blocks amounts below the minimum (they would never be minted)', () => {
        expect(checkDepositAmount(500000n, limits)).toMatchObject({ error: 'bridge.errBelowMin', params: { min: '1' } });
        expect(checkDepositAmount(0n, limits).error).toBe('bridge.errAmountInvalid');
        expect(checkDepositAmount(null, limits).error).toBe('bridge.errAmountInvalid');
    });

    it('blocks amounts above the per-transfer cap (they can never be minted — the money would be stuck in the vault)', () => {
        const result = checkDepositAmount(1500000000n, limits);
        expect(result).toMatchObject({ error: 'bridge.errAboveMaxBlocked', params: { max: '1000' } });
    });

    it('uses the effective cap from the server (min of site limit and on-chain maxPerTx) when given', () => {
        const loose = { min_deposit: '1', max_deposit_per_tx: '1500' };
        expect(checkDepositAmount(1200000000n, loose, '1000')).toMatchObject({ error: 'bridge.errAboveMaxBlocked', params: { max: '1000' } });
        expect(checkDepositAmount(1000000000n, loose, '1000').error).toBeNull();
    });

    it('passes a normal deposit', () => {
        expect(checkDepositAmount(25000000n, limits)).toEqual({ error: null, warning: null });
    });
});

describe('depositReceiveUnits', () => {
    it('subtracts a configured flat deposit fee and never goes negative', () => {
        expect(depositReceiveUnits(25000000n, '0')).toBe(25000000n);
        expect(depositReceiveUnits(25000000n, '0.5')).toBe(24500000n);
        expect(depositReceiveUnits(300000n, '0.5')).toBe(0n);
        expect(depositReceiveUnits(25000000n, undefined)).toBe(25000000n);
    });
});

describe('isValidEvmAddress', () => {
    it('accepts all-lowercase and correctly checksummed addresses', () => {
        expect(isValidEvmAddress('0xf46131c82819d7621163f482b3fe88a228a7807c')).toBe(true);
        expect(isValidEvmAddress('0xf46131C82819d7621163F482b3fe88a228A7807c')).toBe(true);
    });

    it('rejects typos caught by the checksum, the zero address and non-EVM formats', () => {
        expect(isValidEvmAddress('0xF46131C82819d7621163F482b3fe88a228A7807c')).toBe(false);
        expect(isValidEvmAddress('0x0000000000000000000000000000000000000000')).toBe(false);
        expect(isValidEvmAddress('TXYZ1234567890abcdef')).toBe(false);
        expect(isValidEvmAddress('0x123')).toBe(false);
    });
});

describe('stateBadge', () => {
    it('marks terminal states as done so polling stops', () => {
        expect(stateBadge('minted')).toMatchObject({ tone: 'success', done: true, labelKey: 'bridge.state.minted' });
        expect(stateBadge('paid').done).toBe(true);
        expect(stateBadge('confirming').done).toBe(false);
        expect(stateBadge('review')).toMatchObject({ tone: 'warning', done: false });
    });

    it('treats a deposit on a never-opened chain as final, but keeps polling a paused one', () => {
        expect(stateBadge('chain_disabled')).toMatchObject({ tone: 'danger', done: true });
        expect(stateBadge('chain_paused')).toMatchObject({ tone: 'warning', done: false });
    });
});

describe('explorer links', () => {
    it('builds tx and token-holder URLs and tolerates trailing slashes', () => {
        expect(explorerTx('https://bscscan.com/', '0xabc')).toBe('https://bscscan.com/tx/0xabc');
        expect(explorerTokenHolder('https://etherscan.io', '0xToken', '0xVault')).toBe('https://etherscan.io/token/0xToken?a=0xVault');
        expect(explorerTx('', '0xabc')).toBeNull();
    });
});

describe('tracked transfers', () => {
    const hash = `0x${'a'.repeat(64)}`;

    it('round-trips through storage and de-duplicates the same transfer', () => {
        const storage = memoryStorage();
        let list = addTracked([], { kind: 'deposit', chainId: 56, txHash: hash.toUpperCase().replace('0X', '0x') });
        list = addTracked(list, { kind: 'deposit', chainId: 56, txHash: hash });
        expect(list).toHaveLength(1);

        saveTracked(list, storage);
        expect(loadTracked(storage)).toEqual(list);
    });

    it('drops malformed entries and survives broken or blocked storage', () => {
        const storage = memoryStorage();
        storage.setItem('tpix.bridge.tracked.v2', JSON.stringify([{ kind: 'deposit', chainId: 1, txHash: 'nope' }, { kind: 'x', txHash: hash }]));
        expect(loadTracked(storage)).toEqual([]);

        storage.setItem('tpix.bridge.tracked.v2', '{not json');
        expect(loadTracked(storage)).toEqual([]);

        const throwing = { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } };
        expect(loadTracked(throwing)).toEqual([]);
        expect(() => saveTracked([{ kind: 'deposit', chainId: 1, txHash: hash }], throwing)).not.toThrow();
    });
});

describe('friendlyWalletError', () => {
    it('never surfaces raw exceptions', () => {
        expect(friendlyWalletError({ code: 4001 })).toBe('bridge.rejectedByUser');
        expect(friendlyWalletError({ code: 'ACTION_REJECTED' })).toBe('bridge.rejectedByUser');
        expect(friendlyWalletError({ message: 'execution reverted: insufficient balance' })).toBe('bridge.errInsufficient');
        expect(friendlyWalletError(new Error('random RPC html blob'))).toBe('bridge.errTxFailed');
    });
});
