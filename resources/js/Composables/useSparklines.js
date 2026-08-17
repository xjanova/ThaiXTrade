/**
 * TPIX TRADE — useSparklines
 * ดึงเส้นกราฟย่อ 24 ชม. ของหลายคู่เทรดพร้อมกันจาก backend (/api/v1/market/sparklines)
 * cache ไว้ระดับโมดูล 5 นาที — สลับหน้า/เปิด dropdown ซ้ำจึงไม่ยิงซ้ำ
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import axios from 'axios';

const TTL_MS = 5 * 60 * 1000;
const CHUNK_SIZE = 40; // ต้องไม่เกิน cap ฝั่ง API

/** symbol → { points: number[], at: number } — อยู่ระดับโมดูลเพื่อแชร์ข้ามคอมโพเนนต์ */
const cache = new Map();

/** symbol → Promise — กันยิงซ้ำเมื่อหลายคอมโพเนนต์ขอ symbol เดียวกันพร้อมกัน */
const inFlight = new Map();

function isFresh(entry) {
    return entry && Date.now() - entry.at < TTL_MS;
}

/** "BTC/USDT" หรือ "btc-usdt" → "BTC-USDT" (รูปแบบที่ API รับ) */
function canonical(symbol) {
    return String(symbol || '').toUpperCase().replace('/', '-').trim();
}

async function fetchChunk(symbols) {
    let result = null;

    try {
        const { data } = await axios.get('/api/v1/market/sparklines', {
            params: { symbols: symbols.join(','), interval: '1h', limit: 24 },
            // ฝั่งเซิร์ฟเวอร์ยิงขนานแล้วเสร็จใน ~1 วิ ให้เผื่อไว้พอสำหรับเน็ตช้า
            // แต่ไม่รอไม่มีที่สิ้นสุด ไม่งั้นแถวจะค้าง skeleton ไปเรื่อยๆ
            timeout: 15000,
        });
        if (data?.success) result = data.data || {};
    } catch {
        result = null;
    }

    /*
     * ⚠️ ยิงพลาด ≠ เหรียญนี้ไม่มีข้อมูล
     *
     * เดิมเขียนชุดว่างลงแคชทุกกรณี — เน็ตสะดุดแวบเดียวจะกลายเป็น "กราฟหายทั้งหน้า
     * นาน 5 นาที" โดยไม่มี error ให้เห็นสักบรรทัด และผู้ใช้กดรีเฟรชก็ไม่ช่วย
     * เพราะแคชอยู่ในหน่วยความจำของหน้าเว็บ
     *
     * ล้มเหลว = ไม่จำอะไรเลย ครั้งหน้าที่มีคนขอก็ลองใหม่ทันที
     */
    if (result === null) {
        return {};
    }

    const at = Date.now();

    // สำเร็จแล้วจำได้ทุกตัวที่ขอ รวมตัวที่ไม่มีข้อมูลจริงๆ — กันวนขอซ้ำทุกรอบ
    symbols.forEach(s => {
        cache.set(s, { points: Array.isArray(result[s]) ? result[s] : [], at });
    });

    return result;
}

export function useSparklines() {
    /** symbol → number[] สำหรับผูกกับ <Sparkline :points="..."> */
    const series = ref({});
    const isLoading = ref(false);

    function readCacheInto(symbols) {
        const next = { ...series.value };
        symbols.forEach(s => {
            const entry = cache.get(s);
            if (isFresh(entry)) next[s] = entry.points;
        });
        series.value = next;
    }

    /**
     * โหลดเส้นกราฟของ symbols ที่ให้มา (ข้ามตัวที่ cache ยังสด)
     * เรียกซ้ำได้ปลอดภัย — ใช้ตอนเปลี่ยนหน้า/เปลี่ยนตัวกรอง
     */
    async function load(symbols) {
        const wanted = [...new Set((symbols || []).map(canonical).filter(Boolean))];
        if (wanted.length === 0) return;

        readCacheInto(wanted);

        const missing = wanted.filter(s => !isFresh(cache.get(s)) && !inFlight.has(s));
        const pending = wanted.filter(s => inFlight.has(s)).map(s => inFlight.get(s));

        if (missing.length === 0 && pending.length === 0) return;

        isLoading.value = true;
        try {
            const jobs = [...pending];

            for (let i = 0; i < missing.length; i += CHUNK_SIZE) {
                const chunk = missing.slice(i, i + CHUNK_SIZE);
                const job = fetchChunk(chunk).catch(() => ({}));
                chunk.forEach(s => inFlight.set(s, job));
                jobs.push(
                    job.finally(() => chunk.forEach(s => inFlight.delete(s)))
                );
            }

            await Promise.all(jobs);
            readCacheInto(wanted);
        } finally {
            isLoading.value = false;
        }
    }

    /** อ่านค่าที่มีอยู่แบบไม่ยิง request (ใช้ใน template) */
    function pointsFor(symbol) {
        return series.value[canonical(symbol)] || [];
    }

    return { series, isLoading, load, pointsFor };
}
