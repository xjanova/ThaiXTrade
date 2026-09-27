/**
 * TPIX TRADE — เก็บโลก 3D ไว้ข้ามการเปลี่ยนหน้า
 *
 * เจ้าของถาม: "การโหลดหน้าเว็บทำไมต้องโหลดทุกรอบ ถ้าไม่มีอะไรอัพเดท ควรใช้ของที่แคชไว้เลยไม่ใช่เหรอ"
 * ไฟล์ (JS/รูป/วิดีโอ) แคชในเบราว์เซอร์อยู่แล้ว — ที่ช้าทุกรอบคือ Inertia ถอดหน้าแรกทิ้งเมื่อไปหน้าอื่น
 * แล้วกลับมาต้องสร้างฉาก + คอมไพล์ shader ใหม่หมด (ของพวกนี้อยู่ใน GPU แคชเป็นไฟล์ไม่ได้)
 *
 * วิธีแก้: ตอนออกจากหน้าแรก "พัก" เอนจิน (หยุดวาด ถอด canvas ออกจากหน้า) แล้วเก็บไว้ที่นี่
 * กลับมาภายใน KEEP_MS → เสียบ canvas เดิมกลับ วาดต่อทันที ไม่มีจอโหลด
 * ไม่กลับมา → ทิ้งเองคืนหน่วยความจำ GPU
 *
 * hooks = ตัวกลางของ callback: ฉากถูกสร้างครั้งเดียว แต่คอมโพเนนต์ถูกสร้างใหม่ทุกครั้งที่เข้าหน้า
 * ฉากจึงเรียกผ่าน hooks ซึ่งคอมโพเนนต์ตัวที่อยู่บนจอตอนนี้เป็นคนตั้ง
 *
 * Developed by Xman Studio
 */

export const KEEP_MS = 5 * 60 * 1000;

/** callback ของคอมโพเนนต์ตัวล่าสุด (ฉาก/เอนจินเรียกผ่านตรงนี้เสมอ) */
export const hooks = {
    resolveTarget: null,
    onTarget: null,
    onCandleHover: null,
    onCoinLand: null,
    onSlow: null,
    onLost: null,
};

let kept = null; // { engine, world, canvas, timer }

/** เก็บโลกที่พักแล้ว (ของเก่าที่ค้างอยู่ถูกทิ้งก่อน — มีได้ชุดเดียว) */
export function keep(entry, ms = KEEP_MS) {
    discard();
    kept = { ...entry, timer: setTimeout(discard, ms) };
}

/** เอาโลกที่เก็บไว้กลับมาใช้ (ไม่มี / GPU หลุดไปแล้ว = null) */
export function takeKept() {
    if (!kept) return null;
    if (kept.engine.lost) {
        discard();
        return null;
    }
    clearTimeout(kept.timer);
    const k = kept;
    kept = null;
    return k;
}

/** ทิ้งของที่เก็บไว้ คืนหน่วยความจำ GPU */
export function discard() {
    if (!kept) return;
    clearTimeout(kept.timer);
    const k = kept;
    kept = null;
    try {
        k.world?.dispose();
        k.engine?.dispose();
    } finally {
        k.canvas?.remove();
    }
}

/** มีโลกเก็บไว้ไหม (หน้าแรกใช้ตัดสินว่าต้องโชว์ป้ายกำลังโหลดหรือเปล่า) */
export function hasKept() {
    return !!kept && !kept.engine.lost;
}
