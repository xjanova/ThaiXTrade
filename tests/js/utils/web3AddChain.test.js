/**
 * เพิ่มเชน/เหรียญลงกระเป๋า — RPC ต้องเป็น rpc1 ทุกทาง + ลำดับเพิ่ม→สลับ→ตรวจ
 *
 * เจ้าของสั่ง (2026-10-20): "เราใช้ rpc1 ... แก้ไขทุกจุด และโค้ดการเพิ่ม ต้องทำให้สมบูรณ์"
 * rpc.tpix.online อยู่หลัง Cloudflare ที่ตอบ 403 ให้แอปกระเป๋าบนมือถือ
 */
import { describe, it, expect, vi } from 'vitest';
import {
    TPIX_CHAIN_CONFIG,
    TPIX_RPC_URL,
    buildAddChainParams,
    addTPIXChainToWallet,
    addTokenToWallet,
    switchToChain,
    toChainIdHex,
    toChainIdNumber,
} from '@/utils/web3';

/** กระเป๋าจำลอง: รู้จักเชนตาม known · บางตัว "เพิ่มอย่างเดียวไม่สลับ" */
function wallet({ chain = 56, known = [56], addSwitches = true, rejectSwitch = false } = {}) {
    const state = { chain, known: new Set(known) };
    const calls = [];
    const request = vi.fn(async ({ method, params }) => {
        calls.push({ method, params });
        if (method === 'eth_chainId') return '0x' + state.chain.toString(16);
        if (method === 'wallet_switchEthereumChain') {
            if (rejectSwitch) throw Object.assign(new Error('User rejected'), { code: 4001 });
            const id = parseInt(params[0].chainId, 16);
            if (!state.known.has(id)) throw Object.assign(new Error('Unrecognized chain ID'), { code: 4902 });
            state.chain = id;
            return null;
        }
        if (method === 'wallet_addEthereumChain') {
            const id = parseInt(params[0].chainId, 16);
            state.known.add(id);
            if (addSwitches) state.chain = id;
            return null;
        }
        if (method === 'wallet_watchAsset') return true;
        throw new Error(`unexpected ${method}`);
    });
    return { request, calls, state };
}

describe('RPC ของ TPIX Chain', () => {
    it('ตัวหลักคือ rpc1 — ไม่ใช่ rpc.tpix.online', () => {
        expect(TPIX_RPC_URL).toBe('https://rpc1.tpix.online');
        expect(TPIX_CHAIN_CONFIG.rpcUrls).toEqual(['https://rpc1.tpix.online']);
    });

    it('API ส่ง rpc.tpix.online มา ก็ยังได้ rpc1 อย่างเดียว', () => {
        const p = buildAddChainParams({ chainId: 4289, name: 'TPIX Chain', rpc: ['https://rpc.tpix.online'] });
        expect(p.rpcUrls).toEqual(['https://rpc1.tpix.online']);
        expect(p.blockExplorerUrls).toEqual(['https://explorer.tpix.online']);
    });

    it('เชนอื่นใช้ RPC ตามที่ API ส่งมา', () => {
        const p = buildAddChainParams({ chainId: 56, name: 'BNB Smart Chain', rpc: ['https://bsc-dataseed.binance.org', 'http://insecure'] });
        expect(p.rpcUrls).toEqual(['https://bsc-dataseed.binance.org']);
        expect(p.chainId).toBe('0x38');
    });
});

describe('buildAddChainParams รับ config ฝั่งเว็บได้', () => {
    it('ไม่ได้ 0x0x10C1 และมีชื่อเชน (บั๊กหน้า TokenFactory เดิม)', () => {
        const p = buildAddChainParams(TPIX_CHAIN_CONFIG);
        expect(p.chainId).toBe('0x10c1');
        expect(p.chainName).toBe('TPIX Chain');
        expect(p.nativeCurrency).toEqual({ name: 'TPIX', symbol: 'TPIX', decimals: 18 });
    });

    it('แปลง chainId ได้ทุกรูปแบบ', () => {
        expect(toChainIdNumber('0x10C1')).toBe(4289);
        expect(toChainIdNumber('4289')).toBe(4289);
        expect(toChainIdNumber(4289n)).toBe(4289);
        expect(toChainIdHex('0x10C1')).toBe('0x10c1');
        expect(() => toChainIdHex('abc')).toThrow();
    });
});

describe('switchToChain', () => {
    it('กระเป๋าไม่รู้จักเชน → เพิ่ม (rpc1) แล้วอยู่บนเชนนั้น', async () => {
        const w = wallet();
        await switchToChain(w, 4289);
        const add = w.calls.find(c => c.method === 'wallet_addEthereumChain');
        expect(add.params[0].rpcUrls).toEqual(['https://rpc1.tpix.online']);
        expect(w.state.chain).toBe(4289);
    });

    it('กระเป๋าที่ "เพิ่มอย่างเดียวไม่สลับ" → ขอสลับซ้ำให้', async () => {
        const w = wallet({ addSwitches: false });
        await switchToChain(w, 4289);
        const switches = w.calls.filter(c => c.method === 'wallet_switchEthereumChain');
        expect(switches).toHaveLength(2);
        expect(w.state.chain).toBe(4289);
    });

    it('อยู่เชนนั้นแล้ว → ไม่เด้งอะไรเลย', async () => {
        const w = wallet({ chain: 4289, known: [4289] });
        await switchToChain(w, 4289);
        expect(w.calls.map(c => c.method)).toEqual(['eth_chainId']);
    });

    it('ผู้ใช้ปฏิเสธ → โยน 4001 ต่อ ไม่ไปขอเพิ่มเชน', async () => {
        const w = wallet({ rejectSwitch: true });
        await expect(switchToChain(w, 4289)).rejects.toMatchObject({ code: 4001 });
        expect(w.calls.map(c => c.method)).not.toContain('wallet_addEthereumChain');
    });
});

describe('addTPIXChainToWallet / addTokenToWallet', () => {
    it('เพิ่มเชนด้วย rpc1 + โลโก้', async () => {
        const w = wallet();
        expect(await addTPIXChainToWallet(w)).toBe(true);
        const p = w.calls[0].params[0];
        expect(p.chainId).toBe('0x10c1');
        expect(p.rpcUrls).toEqual(['https://rpc1.tpix.online']);
        expect(p.iconUrls[0]).toMatch(/^https:\/\/tpix\.online\//);
    });

    it('ที่อยู่ผิดรูป/ทศนิยมเพี้ยน = ไม่ส่งให้กระเป๋า', async () => {
        const w = wallet();
        expect(await addTokenToWallet({ address: '0x123', symbol: 'X', decimals: 18 }, w)).toBe(false);
        expect(await addTokenToWallet({ address: '0x' + 'a'.repeat(40), symbol: 'X', decimals: 99 }, w)).toBe(false);
        expect(w.request).not.toHaveBeenCalled();
    });

    it('symbol ยาวถูกตัดเหลือ 11 · โลโก้แบบสัมพัทธ์ได้โดเมนเต็ม', async () => {
        const w = wallet();
        const ok = await addTokenToWallet({
            address: '0x' + 'b'.repeat(40), symbol: 'VeryLongSymbol12', decimals: '6', image: '/storage/logo.png',
        }, w);
        expect(ok).toBe(true);
        const opts = w.calls[0].params.options;
        expect(opts.symbol).toBe('VeryLongSym');
        expect(opts.decimals).toBe(6);
        expect(opts.image).toBe(`${window.location.origin}/storage/logo.png`);
    });
});
