/**
 * TPIX TRADE — ลิงก์ explorer ต้องตรงเชนของธุรกรรม
 *
 * เดิม getTxUrl ตกไป BscScan ทุกครั้งที่รายการเชนจากเซิร์ฟเวอร์ยังไม่โหลด (หรือโหลดล้ม)
 * ธุรกรรมบน TPIX Chain จึงได้ลิงก์ BscScan ที่หาธุรกรรมไม่เจอ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi } from 'vitest';

const get = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }));

const { getTxUrl, getAddressUrl, fetchSupportedChains } = await import('@/utils/web3');

const HASH = `0x${'c'.repeat(64)}`;
const ADDRESS = `0x${'d'.repeat(40)}`;

describe('explorer links', () => {
    it('uses the TPIX explorer for chain 4289 before the chain list has loaded', () => {
        expect(getTxUrl(HASH, 4289)).toBe(`https://explorer.tpix.online/tx/${HASH}`);
        expect(getAddressUrl(ADDRESS, 4289)).toBe(`https://explorer.tpix.online/address/${ADDRESS}`);
    });

    it('keeps BscScan for BSC and for unknown / missing chain ids', () => {
        expect(getTxUrl(HASH, 56)).toBe(`https://bscscan.com/tx/${HASH}`);
        expect(getTxUrl(HASH)).toBe(`https://bscscan.com/tx/${HASH}`);
        expect(getTxUrl(HASH, 999999)).toBe(`https://bscscan.com/tx/${HASH}`);
    });

    it('prefers the explorer the server lists, matching chain ids given as strings', async () => {
        get.mockResolvedValue({
            data: {
                success: true,
                data: [{ chainId: '4289', explorer: 'https://scan.tpix.example' }],
                meta: {},
            },
        });
        await fetchSupportedChains();

        expect(getTxUrl(HASH, 4289)).toBe(`https://scan.tpix.example/tx/${HASH}`);
        // เชนที่ไม่อยู่ในรายการของเซิร์ฟเวอร์ยังได้ explorer ของตัวเองจาก config ในไฟล์
        expect(getTxUrl(HASH, 56)).toBe(`https://bscscan.com/tx/${HASH}`);
    });
});
