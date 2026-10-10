/**
 * TPIX TRADE — เพิ่มเหรียญ TPIX ลงกระเป๋า (อัตโนมัติตอนเชื่อม + ปุ่มในเมนูกระเป๋า)
 *
 * เจ้าของสั่ง 2026-10-11: "ตอนเชื่อมกระเป๋า ควรเพิ่มเหรียญ tpix อัตโนมัติเลย พร้อมโลโก้ใหม่
 * ของเหรียญให้ถูกต้อง · มีปุ่มเพิ่มเหรียญต่างหากด้วย"
 *
 * จุดที่ชุดนี้คุม:
 *  - เครือข่ายที่เว็บส่งให้กระเป๋าต้องใช้โลโก้เหรียญชุดใหม่ (เดิมส่งตราทองเก่า)
 *  - USDT บนเชน TPIX มาจาก API คู่เทรด ไม่เดาที่อยู่/ทศนิยม
 *  - เสนออัตโนมัติครั้งเดียวต่อกระเป๋า — ปฏิเสธแล้วไม่ถามซ้ำ
 *  - กระเป๋าฝังไม่ไปแตะ window.ethereum
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';
import {
    fetchTpixChainTokens,
    wasAssetSuggestionShown,
    markAssetSuggestionShown,
} from '@/utils/walletAssets';
import { TPIX_CHAIN_LOGO_URL, buildAddChainParams } from '@/utils/web3';

vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })) },
}));
vi.mock('@/Composables/useSounds', () => ({
    playConnectSound: vi.fn(),
    playDisconnectSound: vi.fn(),
    playErrorSound: vi.fn(),
}));

const OWNER = '0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7';
const USDT = '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1';
const USDT_LOGO = 'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xdAC17F958D2ee523a2206206994597C13D831ec7/logo.png';

const TPIX_USDT_PAIR = {
    symbol: 'TPIX-USDT',
    base_address: '0x0000000000000000000000000000000000000000',
    quote_address: USDT,
    quote_asset: 'USDT',
    quote_decimals: 6,
    quote_logo: USDT_LOGO,
    chain_id: 4289,
};

function mockApi(pairs = [TPIX_USDT_PAIR]) {
    axios.get.mockImplementation((url) => {
        if (url === '/api/v1/dex/pairs') return Promise.resolve({ data: { success: true, data: pairs } });
        if (url === '/api/v1/chains') {
            return Promise.resolve({
                data: {
                    success: true,
                    data: [
                        { chainId: 4289, name: 'TPIX Chain', status: 'live', rpc: ['https://rpc.tpix.online'], nativeCurrency: { name: 'TPIX', symbol: 'TPIX', decimals: 18 } },
                        { chainId: 56, name: 'BNB Smart Chain', status: 'live' },
                    ],
                    meta: { default_chain_id: 4289 },
                },
            });
        }
        return Promise.resolve({ data: {} });
    });
}

/** กระเป๋าจำลองแบบ EIP-1193 — จดทุกคำขอไว้ตรวจ */
function fakeWallet({ chainHex = '0x10c1', rejectSwitch = false, rejectWatch = false } = {}) {
    const calls = [];
    let current = chainHex;
    const listeners = {};
    return {
        calls,
        isMetaMask: true,
        on: (ev, fn) => { listeners[ev] = fn; },
        removeListener: () => {},
        request: vi.fn(async ({ method, params }) => {
            calls.push({ method, params });
            switch (method) {
                case 'eth_requestAccounts':
                case 'eth_accounts':
                    return [OWNER];
                case 'eth_chainId':
                    return current;
                case 'wallet_switchEthereumChain':
                    if (rejectSwitch) throw Object.assign(new Error('User rejected'), { code: 4001 });
                    current = params[0].chainId.toLowerCase();
                    return null;
                case 'wallet_watchAsset':
                    if (rejectWatch) throw Object.assign(new Error('User rejected'), { code: 4001 });
                    return true;
                default:
                    return null;
            }
        }),
    };
}

const methods = (wallet) => wallet.calls.map(c => c.method);

describe('โลโก้ที่ส่งให้กระเป๋า', () => {
    it('เครือข่าย TPIX Chain ใช้โลโก้เหรียญชุดใหม่ ไม่ใช่ตราทองเก่า', () => {
        expect(TPIX_CHAIN_LOGO_URL).toMatch(/^https:\/\/tpix\.online\/images\/brand\/tpix-coin\.png\?v=\d+$/);
        expect(TPIX_CHAIN_LOGO_URL).not.toContain('tpix-logo-512');
    });

    it('ใช้ฟิลด์ icon จาก /api/v1/chains เป็นโลโก้เครือข่าย', () => {
        const params = buildAddChainParams({
            chainId: 4289, name: 'TPIX Chain', rpc: ['https://rpc.tpix.online'],
            icon: 'https://tpix.online/images/brand/tpix-coin.png',
        });
        expect(params.iconUrls).toEqual(['https://tpix.online/images/brand/tpix-coin.png']);
    });

    it('ไอคอนแบบที่อยู่สัมพัทธ์ใช้ไม่ได้ — ถอยไปโลโก้ทางการ', () => {
        const params = buildAddChainParams({
            chainId: 4289, name: 'TPIX Chain', rpc: ['https://rpc.tpix.online'], icon: '/images/x.png',
        });
        expect(params.iconUrls).toEqual([TPIX_CHAIN_LOGO_URL]);
    });
});

describe('fetchTpixChainTokens', () => {
    beforeEach(() => vi.clearAllMocks());

    it('ได้ USDT บนเชน TPIX พร้อมทศนิยมและโลโก้จาก API คู่เทรด', async () => {
        mockApi();
        expect(await fetchTpixChainTokens()).toEqual([
            { address: USDT, symbol: 'USDT', decimals: 6, image: USDT_LOGO },
        ]);
    });

    it('ข้ามคู่เชนอื่น คู่ที่ base ไม่ใช่ TPIX และข้อมูลผิดรูป', async () => {
        mockApi([
            { ...TPIX_USDT_PAIR, chain_id: 56 },
            { ...TPIX_USDT_PAIR, base_address: '0x1111111111111111111111111111111111111111' },
            { ...TPIX_USDT_PAIR, quote_address: '0x123' },
            { ...TPIX_USDT_PAIR, quote_decimals: 'six' },
            { ...TPIX_USDT_PAIR, quote_asset: 'WAYTOOLONGSYMBOL' },
        ]);
        expect(await fetchTpixChainTokens()).toEqual([]);
    });

    it('โลโก้ที่ไม่ใช่ https ไม่ถูกส่งให้กระเป๋า', async () => {
        mockApi([{ ...TPIX_USDT_PAIR, quote_logo: '/storage/usdt.png' }]);
        const [token] = await fetchTpixChainTokens();
        expect(token.image).toBeUndefined();
    });

    it('API ล่ม = ไม่เสนออะไรเลย (ไม่เดาที่อยู่)', async () => {
        axios.get.mockRejectedValue(new Error('network'));
        expect(await fetchTpixChainTokens()).toEqual([]);
    });
});

describe('walletStore.addTpixAssetsToWallet (ปุ่มในเมนูกระเป๋า)', () => {
    let store;

    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.removeItem('tpix_wallet_assets_suggested_v1');
        setActivePinia(createPinia());
        store = useWalletStore();
        mockApi();
    });

    afterEach(() => {
        delete window.ethereum;
    });

    it('อยู่บนเชน TPIX แล้ว → เพิ่ม USDT ด้วยข้อมูลจริงทันที ไม่สลับเชนซ้ำ', async () => {
        const wallet = fakeWallet();
        window.ethereum = wallet;
        store.address = OWNER;
        store.walletType = 'metamask';
        store.chainId = 4289;

        const res = await store.addTpixAssetsToWallet();

        expect(res).toEqual({ ok: true, added: ['USDT'], skipped: [] });
        expect(methods(wallet)).not.toContain('wallet_switchEthereumChain');
        const watch = wallet.calls.find(c => c.method === 'wallet_watchAsset');
        expect(watch.params.options).toEqual({ address: USDT, symbol: 'USDT', decimals: 6, image: USDT_LOGO });
        expect(wasAssetSuggestionShown(OWNER)).toBe(true);
    });

    it('อยู่บน BSC → สลับไปเชน TPIX ก่อน แล้วค่อยเพิ่ม USDT', async () => {
        const wallet = fakeWallet({ chainHex: '0x38' });
        window.ethereum = wallet;
        store.address = OWNER;
        store.walletType = 'metamask';
        store.chainId = 56;

        const res = await store.addTpixAssetsToWallet();

        expect(res.ok).toBe(true);
        const order = methods(wallet);
        expect(order.indexOf('wallet_switchEthereumChain')).toBeLessThan(order.indexOf('wallet_watchAsset'));
        expect(store.chainId).toBe(4289);
    });

    it('ผู้ใช้ไม่ยอมสลับเชน → ไม่ขอเพิ่มโทเคนบนเชนผิด', async () => {
        const wallet = fakeWallet({ chainHex: '0x38', rejectSwitch: true });
        window.ethereum = wallet;
        store.address = OWNER;
        store.walletType = 'metamask';
        store.chainId = 56;

        const res = await store.addTpixAssetsToWallet();

        expect(res.ok).toBe(false);
        expect(res.reason).toBe('chain');
        expect(methods(wallet)).not.toContain('wallet_watchAsset');
    });

    it('ผู้ใช้กดปฏิเสธ USDT → ok แต่ไม่นับว่าเพิ่ม', async () => {
        const wallet = fakeWallet({ rejectWatch: true });
        window.ethereum = wallet;
        store.address = OWNER;
        store.walletType = 'metamask';
        store.chainId = 4289;

        expect(await store.addTpixAssetsToWallet()).toEqual({ ok: true, added: [], skipped: ['USDT'] });
    });

    it('กระเป๋าฝัง (TPIX Wallet) ไม่แตะ window.ethereum เลย', async () => {
        const wallet = fakeWallet();
        window.ethereum = wallet;
        store.address = OWNER;
        store.walletType = 'tpix_wallet';
        store.chainId = 4289;

        expect(store.canAddTpixAssets).toBe(false);
        const res = await store.addTpixAssetsToWallet();

        expect(res.reason).toBe('unsupported');
        expect(wallet.request).not.toHaveBeenCalled();
    });
});

describe('เสนอเพิ่มเหรียญอัตโนมัติตอนเชื่อมกระเป๋า', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.removeItem('tpix_wallet_assets_suggested_v1');
        localStorage.removeItem('tpix_wallet');
        setActivePinia(createPinia());
        mockApi();
        // เซ็นยืนยันกระเป๋า: ให้เซิร์ฟเวอร์ตอบว่าไม่ต้องเซ็น จะได้ไม่ต้องจำลอง personal_sign
        axios.post.mockResolvedValue({ data: { success: false } });
    });

    afterEach(() => {
        delete window.ethereum;
    });

    it('เชื่อมครั้งแรก → เพิ่มเครือข่ายด้วยโลโก้ใหม่ แล้วเสนอ USDT', async () => {
        const wallet = fakeWallet();
        window.ethereum = wallet;
        const store = useWalletStore();

        await store.connect('metamask');
        await vi.waitFor(() => expect(methods(wallet)).toContain('wallet_watchAsset'));

        const addChain = wallet.calls.find(c => c.method === 'wallet_addEthereumChain');
        expect(addChain.params[0].iconUrls).toEqual([TPIX_CHAIN_LOGO_URL]);
        expect(wasAssetSuggestionShown(OWNER)).toBe(true);
    });

    it('เคยเสนอแล้ว (ปฏิเสธไปแล้ว) → เชื่อมใหม่ไม่ถามซ้ำ', async () => {
        markAssetSuggestionShown(OWNER);
        const wallet = fakeWallet();
        window.ethereum = wallet;
        const store = useWalletStore();

        await store.connect('metamask');
        // รอให้คิวหลังเชื่อมต่อ (ขอเซ็น → เสนอเหรียญ) เดินจบ
        await vi.waitFor(() => expect(axios.post).toHaveBeenCalledWith('/api/v1/wallet/sign', expect.anything()));
        await new Promise(r => setTimeout(r, 20));

        expect(methods(wallet)).not.toContain('wallet_watchAsset');
    });

    it('ผู้ใช้ไม่ยอมสลับมาเชน TPIX ตอนเชื่อม → ไม่เด้งขอเพิ่มโทเคนบนเชนอื่น', async () => {
        const wallet = fakeWallet({ chainHex: '0x38', rejectSwitch: true });
        window.ethereum = wallet;
        const store = useWalletStore();

        await store.connect('metamask');
        await vi.waitFor(() => expect(axios.post).toHaveBeenCalledWith('/api/v1/wallet/sign', expect.anything()));
        await new Promise(r => setTimeout(r, 20));

        expect(store.chainId).toBe(56);
        expect(methods(wallet)).not.toContain('wallet_watchAsset');
        expect(wasAssetSuggestionShown(OWNER)).toBe(false);
    });
});
