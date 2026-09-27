/**
 * TPIX TRADE — จังหวะท่าว่างของน้อง TPIX (ไม่แตะ DOM เทสต์ได้ใน node)
 *
 * เจ้าของสั่ง: "เพิ่มท่าทาง idle ของน้องอีก หลายๆ ท่า เช่นท่าหลับ ท่าหาว อื่นๆ"
 *
 * ผู้ใช้เงียบไป (ไม่ขยับเมาส์/เลื่อน/พิมพ์) ยิ่งนานน้องยิ่งเบื่อ:
 *   ~12 วิ   → ขยับเล่นเป็นระยะ (มองซ้ายขวา · บิดขี้เกียจ · ม้วนผม) สุ่มไม่ซ้ำท่าเดิม
 *   ~40 วิ   → หาว (ครั้งเดียวต่อรอบง่วง)
 *   ~75 วิ   → สัปหงก แล้วหลับ (มี Zzz) ค้างจนกว่าผู้ใช้จะกลับมา
 *   ผู้ใช้ขยับ → ถ้าหลับอยู่ = สะดุ้งตื่น · ถ้ายังไม่หลับ = นับใหม่
 *
 * ระหว่างน้องพูด/บิน/รอบอทตอบ/ผู้ใช้เปิดบับเบิ้ลคุย นาฬิกาหยุด — ไม่หลับใส่หน้าคนที่กำลังคุยด้วย
 *
 * Developed by Xman Studio
 */

/** ท่าขยับเล่นตอนว่าง (หาว/หลับแยกไว้ตามเวลา) */
export const FIDGETS = ['look', 'stretch', 'twirl'];

/** เวลา (วินาทีที่ผู้ใช้เงียบ) */
export const IDLE_TIMING = {
    fidgetMin: 11,
    fidgetMax: 19,
    yawnAt: 40,
    sleepAt: 75,
};

/**
 * @param {{ random?: () => number, timing?: typeof IDLE_TIMING }} opts
 */
export function createIdleDirector(opts = {}) {
    const random = opts.random || Math.random;
    const T = { ...IDLE_TIMING, ...(opts.timing || {}) };

    let quiet = 0; // วินาทีที่ผู้ใช้เงียบ (นับเฉพาะตอนน้องว่าง)
    let nextFidget = 0;
    let yawned = false;
    let asleep = false;
    let lastFidget = null;

    const gap = () => T.fidgetMin + random() * (T.fidgetMax - T.fidgetMin);

    function reset() {
        quiet = 0;
        yawned = false;
        nextFidget = gap();
    }
    reset();

    function pickFidget() {
        const pool = FIDGETS.filter((f) => f !== lastFidget);
        const f = pool[Math.min(pool.length - 1, Math.floor(random() * pool.length))];
        lastFidget = f;
        return f;
    }

    return {
        /**
         * ผู้ใช้ขยับ (เมาส์/เลื่อน/พิมพ์/แตะ)
         * @returns {'wake' | null} 'wake' = น้องหลับอยู่ ต้องเล่นท่าตื่น
         */
        activity() {
            const wasAsleep = asleep;
            asleep = false;
            reset();
            return wasAsleep ? 'wake' : null;
        },

        /**
         * เรียกทุกเฟรม
         * @param {number} dt วินาที
         * @param {{ busy?: boolean, acting?: boolean }} ctx busy = กำลังคุย/พูด/บิน (นาฬิกาหยุด)
         *        acting = ยังเล่นท่าอื่นค้างอยู่ (รอให้จบก่อนค่อยสั่งท่าใหม่)
         * @returns {string | null} ท่าที่ควรเล่นตอนนี้ ('look' | 'stretch' | 'twirl' | 'yawn' | 'doze') หรือ null
         */
        update(dt, ctx = {}) {
            if (asleep) return null;
            if (ctx.busy) {
                // คุยกันอยู่ = ไม่ง่วง · เริ่มนับใหม่เมื่อว่าง (แต่ไม่ต้องหาวซ้ำถ้าเพิ่งหาว)
                quiet = Math.min(quiet, T.fidgetMin * 0.5);
                nextFidget = Math.max(nextFidget, quiet + T.fidgetMin * 0.5);
                return null;
            }
            quiet += Math.min(dt, 0.25);
            if (ctx.acting) return null;
            if (quiet >= T.sleepAt) {
                asleep = true;
                return 'doze';
            }
            if (!yawned && quiet >= T.yawnAt) {
                yawned = true;
                nextFidget = quiet + gap();
                return 'yawn';
            }
            if (quiet >= nextFidget) {
                nextFidget = quiet + gap();
                // ใกล้หลับแล้วไม่ขยับเล่น (บิดตัวจบแล้วหลับทันทีดูกระตุก) — ปล่อยนิ่งๆ ก่อนสัปหงก
                if (quiet > T.sleepAt - 8) return null;
                return pickFidget();
            }
            return null;
        },

        get asleep() {
            return asleep;
        },
        get quiet() {
            return quiet;
        },
    };
}
