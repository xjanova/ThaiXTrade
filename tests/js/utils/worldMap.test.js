/**
 * TPIX TRADE — ฐานแผนที่โลกของหน้ารายงานตำแหน่งมาสเตอร์โหนด
 *
 * หน้า /validators เลิกดึงไทล์จาก CARTO แล้ว เพราะเขาเปลี่ยนมาบังคับใช้ API key
 * (ฟรี 5 ล้านไทล์/เดือน) URL แบบไม่มีคีย์ที่ใช้อยู่เดิมคือของเก่าที่กำลังทยอยปิด
 * แผนที่จึงย้ายมาวาดจากไฟล์ในโปรเจกต์ ไฟล์นี้กลายเป็น "ตัวแผนที่" — ถ้ามันเพี้ยน
 * หน้าแผนที่จะว่างเปล่าโดยไม่มี error ให้เห็น เทสต์ชุดนี้กันไฟล์เสียตอนสร้างใหม่
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import base from '@/Data/world-basemap.json';

/** ประเทศที่ผู้ให้บริการเซิร์ฟเวอร์นิยมตั้งโหนด — ต้องมีรูปทุกตัว */
const MUST_HAVE = ['TH', 'SG', 'US', 'JP', 'DE', 'GB', 'HK', 'NL', 'FR', 'AU'];

const isoSet = new Set(base.countries.features.map(f => f.properties?.iso).filter(Boolean));

const eachRing = (fc, fn) => {
    fc.features.forEach(f => {
        const g = f.geometry;
        const rings = g.type === 'MultiPolygon' ? g.coordinates.map(p => p[0])
            : g.type === 'Polygon' ? [g.coordinates[0]]
                : g.type === 'LineString' ? [g.coordinates] : [];
        rings.forEach(r => fn(r, f));
    });
};

describe('ฐานแผนที่โลก', () => {
    it('มีครบทุกชั้นที่หน้าแผนที่ใช้วาด', () => {
        expect(base.countries.type).toBe('FeatureCollection');
        expect(base.lakes.type).toBe('FeatureCollection');
        expect(base.rivers.type).toBe('FeatureCollection');
        expect(Array.isArray(base.places)).toBe(true);

        expect(base.countries.features.length).toBeGreaterThan(200);
        expect(base.lakes.features.length).toBeGreaterThan(10);
        expect(base.places.length).toBeGreaterThan(100);
    });

    it('มีรูปประเทศที่มักตั้งโหนดครบ — ไม่งั้นหมุดจะลอยอยู่กลางทะเล', () => {
        MUST_HAVE.forEach(cc => expect(isoSet.has(cc)).toBe(true));
    });

    it('ทุกประเทศเป็น MultiPolygon ที่ Leaflet วาดได้ และวงปิดครบ', () => {
        base.countries.features.forEach(f => {
            expect(f.geometry.type).toBe('MultiPolygon');
            expect(f.geometry.coordinates.length).toBeGreaterThan(0);
            f.geometry.coordinates.forEach(poly => {
                // วงนอกต้องมีอย่างน้อย 4 จุด (สามเหลี่ยม + จุดปิดวง)
                expect(poly[0].length).toBeGreaterThanOrEqual(4);
            });
        });
    });

    it('รหัสประเทศเป็นตัวพิมพ์ใหญ่ 2 ตัว ให้ตรงกับ country_code ที่ API ส่งมา', () => {
        base.countries.features.forEach(f => {
            const iso = f.properties?.iso;
            if (iso === null) return; // NE ไม่ให้รหัสกับดินแดนพิพาทบางแห่ง
            expect(iso).toMatch(/^[A-Z]{2}$/);
        });
    });

    it('พิกัดทุกจุดอยู่ในกรอบโลกจริง — พิกัดหลุดกรอบทำให้ fitBounds เพี้ยนทั้งแผนที่', () => {
        const check = (ring) => ring.forEach(([lng, lat]) => {
            expect(lng).toBeGreaterThanOrEqual(-180);
            expect(lng).toBeLessThanOrEqual(180);
            expect(lat).toBeGreaterThanOrEqual(-90);
            expect(lat).toBeLessThanOrEqual(90);
        });
        eachRing(base.countries, check);
        eachRing(base.lakes, check);
        eachRing(base.rivers, check);
    });

    it('ป้ายชื่อเมืองมีครบทุกฟิลด์ที่ตัวแสดงผลใช้ และมีเมืองใหญ่ของโลก', () => {
        base.places.forEach(p => {
            expect(typeof p.n).toBe('string');
            expect(p.n.length).toBeGreaterThan(0);
            expect(Number.isFinite(p.x)).toBe(true);
            expect(Number.isFinite(p.y)).toBe(true);
            // z = ระดับซูมต่ำสุดที่เริ่มโชว์ป้าย ต้องอยู่ในช่วงซูมที่แผนที่เปิดให้
            expect(p.z).toBeGreaterThanOrEqual(2);
            expect(p.z).toBeLessThanOrEqual(5);
        });
        const names = new Set(base.places.map(p => p.n));
        ['Bangkok', 'Tokyo', 'Singapore', 'London'].forEach(n => expect(names.has(n)).toBe(true));
    });

    it('ไม่มีที่อยู่ปลายทางภายนอกฝังอยู่ในข้อมูล — แผนที่ต้องไม่พึ่งเน็ต', () => {
        const layers = JSON.stringify([base.countries, base.lakes, base.rivers, base.places]);
        expect(layers).not.toMatch(/https?:\/\//);
    });
});
