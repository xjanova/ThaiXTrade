/**
 * TPIX TRADE — useWalletBalance tests
 *
 * กระเป๋าสลับไป TPIX Chain อัตโนมัติตอนเชื่อม แต่เทรดจริงเกิดบน BSC — แท็บยอดคงเหลือ
 * ในหน้าเทรดจึงต้องบังคับอ่านยอดบน BSC ได้ ไม่งั้นผู้ใช้เห็นยอดไม่ขยับหลังเทรด
 * และยอดสำรอง (ethers) เดิมติดชื่อ 'BNB' ตายตัว แม้กระเป๋าอยู่ Ethereum
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive, ref, nextTick, effectScope } from 'vue';
import { flushPromises } from '@vue/test-utils';

const wallet = reactive({
    address: null,
    isConnected: false,
    chainId: null,
    provider: null,
});

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const get = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }));

const bscReadProvider = { name: 'bsc-read-provider' };
const getTokenBalance = vi.fn();
const getChainConfig = vi.fn();

vi.mock('@/utils/web3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        getTokenBalance: (...args) => getTokenBalance(...args),
        getChainConfig: (...args) => getChainConfig(...args),
        getBscReadProvider: () => bscReadProvider,
    };
});

const { useWalletBalance: createWalletBalance } = await import('@/Composables/useWalletBalance');

/**
 * สร้างใน effectScope แล้วหยุดหลังจบเทสต์ — ในแอปจริงถูกเรียกใน setup() ของคอมโพเนนต์
 * watch จึงถูกเก็บตอน unmount แต่ในเทสต์ไม่มีคอมโพเนนต์ ถ้าไม่หยุดเอง watch ของเทสต์ก่อนๆ
 * จะยิงโหลดซ้อนเข้ามาในเทสต์ถัดไป
 */
const scopes = [];
function useWalletBalance(options) {
    const scope = effectScope();
    scopes.push(scope);
    return scope.run(() => createWalletBalance(options));
}

const WALLET_A = '0x1111111111111111111111111111111111111111';
const WALLET_B = '0x2222222222222222222222222222222222222222';

function respondWith(balances) {
    get.mockResolvedValue({ data: { success: true, data: { balances } } });
}

const chainParam = call => call[1].params.chain_id;

describe('useWalletBalance', () => {
    afterEach(() => {
        scopes.splice(0).forEach(scope => scope.stop());
    });

    beforeEach(() => {
        get.mockReset();
        getTokenBalance.mockReset();
        getChainConfig.mockReset();
        wallet.address = WALLET_A;
        wallet.isConnected = true;
        wallet.chainId = 4289;   // กระเป๋าถูกสลับไป TPIX Chain ตอนเชื่อม
        wallet.provider = { name: 'wallet-provider' };
    });

    it('reads the wallet chain by default (backward compatible)', async () => {
        respondWith([{ symbol: 'TPIX', balance: '10' }]);
        const { fetchBalances, balances, chainId } = useWalletBalance();

        await fetchBalances();

        expect(chainId.value).toBe(4289);
        expect(chainParam(get.mock.calls[0])).toBe(4289);
        expect(balances.value).toEqual([{ symbol: 'TPIX', balance: '10' }]);
    });

    it('reads the forced chain no matter which chain the wallet is on', async () => {
        respondWith([{ symbol: 'BNB', balance: '1' }]);
        const { fetchBalances, chainId } = useWalletBalance({ chainId: 56 });

        await fetchBalances();

        expect(chainId.value).toBe(56);
        expect(chainParam(get.mock.calls[0])).toBe(56);
    });

    it('accepts a ref or a getter for the chain', async () => {
        respondWith([]);
        const target = ref(56);
        const fromRef = useWalletBalance({ chainId: target });
        const fromGetter = useWalletBalance({ chainId: () => 1 });

        expect(fromRef.chainId.value).toBe(56);
        expect(fromGetter.chainId.value).toBe(1);

        target.value = 137;
        await flushPromises();

        expect(fromRef.chainId.value).toBe(137);
        // เชนที่บังคับเปลี่ยน → ยิงใหม่เอง
        expect(get.mock.calls.some(call => chainParam(call) === 137)).toBe(true);
    });

    it('refetches when the wallet switches chain (default mode)', async () => {
        respondWith([]);
        useWalletBalance();

        wallet.chainId = 56;
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(chainParam(get.mock.calls[0])).toBe(56);
    });

    it('does not refetch on a wallet chain switch when the chain is forced', async () => {
        respondWith([]);
        useWalletBalance({ chainId: 56 });

        wallet.chainId = 1;
        await flushPromises();

        expect(get).not.toHaveBeenCalled();
    });

    it('flags needsVerification on 403 and still shows BNB from the BSC read provider', async () => {
        get.mockRejectedValue({ response: { status: 403 }, message: 'Request failed with status code 403' });
        getTokenBalance.mockResolvedValue('0.25');
        const { fetchBalances, balances, needsVerification } = useWalletBalance({ chainId: 56 });

        await fetchBalances();

        expect(needsVerification.value).toBe(true);
        // อ่านจาก RPC ของ BSC ไม่ใช่ provider ของกระเป๋าที่อยู่ TPIX
        expect(getTokenBalance.mock.calls[0][2]).toBe(bscReadProvider);
        expect(balances.value).toHaveLength(1);
        expect(balances.value[0]).toMatchObject({ symbol: 'BNB', balance: '0.25', is_native: true });
    });

    it('labels the fallback with the real native symbol of the chain (ETH on Ethereum)', async () => {
        wallet.chainId = 1;
        get.mockRejectedValue(new Error('Network Error'));
        getChainConfig.mockResolvedValue({ chainId: 1, nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 } });
        getTokenBalance.mockResolvedValue('2');
        const { fetchBalances, balances, needsVerification } = useWalletBalance();

        await fetchBalances();

        expect(needsVerification.value).toBe(false);
        expect(getTokenBalance.mock.calls[0][2]).toBe(wallet.provider);
        expect(balances.value[0]).toMatchObject({ symbol: 'ETH', name: 'Ether', balance: '2' });
    });

    it('skips the fallback instead of reading the wrong chain', async () => {
        wallet.chainId = 56;   // กระเป๋าอยู่ BSC แต่ขอยอดบน Ethereum
        get.mockRejectedValue(new Error('Network Error'));
        const { fetchBalances, balances, error } = useWalletBalance({ chainId: 1 });

        await fetchBalances();

        expect(getTokenBalance).not.toHaveBeenCalled();
        expect(balances.value).toEqual([]);
        expect(error.value).toBeTruthy();
    });

    it('never mislabels an unknown chain as BNB', async () => {
        wallet.chainId = 999999;
        get.mockRejectedValue(new Error('Network Error'));
        getChainConfig.mockResolvedValue(null);
        const { fetchBalances, balances } = useWalletBalance();

        await fetchBalances();

        expect(getTokenBalance).not.toHaveBeenCalled();
        expect(balances.value).toEqual([]);
    });

    it('drops a slow response for the previous wallet and clears on disconnect', async () => {
        let resolveOld;
        get.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }));
        const { fetchBalances, balances } = useWalletBalance({ chainId: 56 });

        const first = fetchBalances();
        respondWith([{ symbol: 'BNB', balance: '9' }]);
        wallet.address = WALLET_B;
        await flushPromises();
        expect(balances.value).toEqual([{ symbol: 'BNB', balance: '9' }]);

        resolveOld({ data: { success: true, data: { balances: [{ symbol: 'BNB', balance: '1' }] } } });
        await first;
        expect(balances.value).toEqual([{ symbol: 'BNB', balance: '9' }]);

        wallet.address = null;
        wallet.isConnected = false;
        await nextTick();
        expect(balances.value).toEqual([]);
    });
});
