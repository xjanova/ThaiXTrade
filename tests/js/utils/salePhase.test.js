/**
 * TPIX TRADE - ป้ายสถานะเฟสขายเหรียญ (utils/salePhase.js)
 *
 * รอบขายแบบขั้นบันได: ทุกขั้นเริ่มพร้อมกัน แต่เปิดทีละขั้นเมื่อขั้นก่อนหน้าขายหมด
 * ขั้นที่รอคิวต้องไม่ขึ้น "Coming Soon" (ฟังเหมือนรอวันที่) และขั้นที่เลยวันปิด
 * ไปแล้วต้องไม่บอกผู้ใช้ว่าเร็ว ๆ นี้
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { isPhaseOpen, phaseDisplayStatus } from '@/utils/salePhase';

const DAY = 86400000;
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString();

describe('phaseDisplayStatus — รอบขั้นบันได', () => {
    it('ขั้นที่เปิดอยู่ขึ้น Active Now', () => {
        const phase = { status: 'active', remaining: 500, starts_at: iso(-DAY), ends_at: iso(80 * DAY) };
        expect(isPhaseOpen(phase)).toBe(true);
        expect(phaseDisplayStatus(phase).label).toBe('Active Now');
    });

    it('ขั้นที่รอขั้นก่อนหน้าขายหมดขึ้น Next Tier และเลือกซื้อไม่ได้', () => {
        const phase = { status: 'upcoming', remaining: 1000, starts_at: iso(-DAY), ends_at: iso(80 * DAY) };
        expect(isPhaseOpen(phase)).toBe(false);
        expect(phaseDisplayStatus(phase)).toEqual({ key: 'upcoming', label: 'Next Tier' });
    });

    it('เฟสที่ยังไม่ถึงวันเริ่มยังเป็น Coming Soon เหมือนเดิม', () => {
        const phase = { status: 'upcoming', remaining: 1000, starts_at: iso(5 * DAY), ends_at: iso(60 * DAY) };
        expect(phaseDisplayStatus(phase).label).toBe('Coming Soon');
    });

    it('เฟสรอคิวที่เลยวันปิดแล้วต้องขึ้น Closed ไม่ใช่ Coming Soon', () => {
        const phase = { status: 'upcoming', remaining: 1000, starts_at: iso(-90 * DAY), ends_at: iso(-30 * DAY) };
        expect(phaseDisplayStatus(phase)).toEqual({ key: 'completed', label: 'Closed' });
    });

    it('เฟสรอคิวที่ไม่มีวันเลยยังเป็น Coming Soon', () => {
        expect(phaseDisplayStatus({ status: 'upcoming', remaining: 1000 }).label).toBe('Coming Soon');
    });
});
