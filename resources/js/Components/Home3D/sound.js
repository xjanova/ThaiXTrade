/**
 * TPIX TRADE — เสียงประกอบหน้าแรก 3D (สังเคราะห์สดด้วย Web Audio ไม่มีไฟล์เสียงให้โหลด)
 *
 * เอฟเฟกต์ล้วน ไม่มีเพลง (แนวที่เจ้าของเลือกไว้กับ Thaiprompt: "ไม่ต้องมีเสียงดนตรี แต่มีเอฟเฟคในการเลือก")
 *   swoosh  เสียง "วูบ" แบบมีโน้ตตอนเริ่มบินไปสถานีอื่น (เดิมเป็นลมจาก noise — เจ้าของบอกว่าเป็นเสียงซ่า จึงเลิกใช้ noise)
 *   arrive  ระฆังแก้ว + เสียงทุ้มสั้นๆ เมื่อกล้องถึงสถานี (โน้ตคนละตัวต่อสถานี)
 *   hover   ติ๊กสั้นตามตำแหน่งเมาส์ (ซ้าย/ขวา = แพนเสียง, สูง/ต่ำ = ระดับเสียง)
 *   click / chime / reply / card / tick (แท่งเทียนขึ้น-ลง) / coin (เหรียญร่วงลงกอง)
 *   น้อง TPIX: fly (ประกายตอนบิน) · land · poke (ดึ๋ง) · pip (จังหวะพูด เบามาก)
 *
 * เบราว์เซอร์ให้ส่งเสียงได้หลังผู้ใช้คลิก/แตะ/กดคีย์ครั้งแรกเท่านั้น → สร้าง AudioContext ใน unlock()
 * ก่อนหน้านั้นทุกฟังก์ชันเรียกได้ปลอดภัย แค่ไม่มีเสียงออก
 *
 * Developed by Xman Studio
 */

import { ref, readonly } from 'vue';

const STORAGE_KEY = 'tpix_home_sound';

// C major pentatonic (C5 ขึ้นไป) — ทุกเสียงอยู่บันไดเดียวกัน ไม่มีโน้ตไหนขัดกัน
const SCALE = [72, 74, 76, 79, 81, 84, 86, 88, 91, 93];
const midi = (m) => 440 * Math.pow(2, (m - 69) / 12);

function readPref() {
    try {
        return localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch {
        return true;
    }
}

const enabled = ref(typeof window === 'undefined' ? false : readPref());

let ctx = null;
let master = null;
let reverbIn = null;
const last = {};

function AC() {
    return typeof window === 'undefined' ? null : window.AudioContext || window.webkitAudioContext || null;
}

function live() {
    return !!ctx && enabled.value && ctx.state === 'running';
}

/** กันเสียงเดิมรัว (เช่นเลื่อนเมาส์ผ่านปุ่มเร็วๆ) */
function throttle(key, ms) {
    const now = performance.now();
    if (now - (last[key] || 0) < ms) return true;
    last[key] = now;
    return false;
}

function impulse(seconds, decay) {
    const len = Math.floor(ctx.sampleRate * seconds);
    const buf = ctx.createBuffer(2, len, ctx.sampleRate);
    for (let c = 0; c < 2; c++) {
        const d = buf.getChannelData(c);
        for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, decay);
    }
    return buf;
}

function build() {
    const Ctor = AC();
    if (!Ctor) return;
    ctx = new Ctor({ latencyHint: 'interactive' });

    master = ctx.createGain();
    master.gain.value = 0.8;
    const limiter = ctx.createDynamicsCompressor();
    limiter.threshold.value = -12;
    limiter.ratio.value = 6;
    master.connect(limiter).connect(ctx.destination);

    // ห้องเสียงสั้นๆ ให้ระฆัง/ประกายมีหางเสียง
    const reverb = ctx.createConvolver();
    reverb.buffer = impulse(1.6, 2.6);
    reverbIn = ctx.createGain();
    const wet = ctx.createGain();
    wet.gain.value = 0.35;
    reverbIn.connect(reverb).connect(wet).connect(master);
    // เดิมมีเสียง "ลม" จาก white noise ดังตามความเร็วกล้อง — เจ้าของฟังแล้วเป็น "เสียงซ่า" จึงถอดออก
    // (เลื่อนทีละซีนทำให้กล้องบินเร็วตลอด noise เลยดังแทบทุกครั้ง) → ใช้ swoosh() แบบมีโน้ตแทน
}

/** เรียกจากการคลิก/แตะ/กดคีย์ของผู้ใช้เท่านั้น */
function unlock() {
    if (!enabled.value || !AC()) return;
    if (!ctx) build();
    if (ctx && ctx.state !== 'running') ctx.resume().catch(() => {});
}

/** โน้ตหนึ่งตัว: ระดับเสียงขึ้นลงตาม envelope แล้วจบเอง */
function tone({ freq, type = 'sine', gain = 0.08, attack = 0.005, decay = 0.12, pan = 0, when = 0, glide = null, send = 0 }) {
    const t = ctx.currentTime + when;
    const osc = ctx.createOscillator();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t);
    if (glide) osc.frequency.exponentialRampToValueAtTime(glide, t + attack + decay);
    const g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(gain, t + attack);
    g.gain.exponentialRampToValueAtTime(0.0001, t + attack + decay);
    const p = ctx.createStereoPanner();
    p.pan.value = Math.max(-1, Math.min(1, pan));
    osc.connect(g).connect(p).connect(master);
    if (send) {
        const s = ctx.createGain();
        s.gain.value = send;
        p.connect(s).connect(reverbIn);
    }
    osc.start(t);
    osc.stop(t + attack + decay + 0.05);
}

export const sfx = {
    enabled: readonly(enabled),

    unlock,

    /** ผูกการปลดล็อกเสียงกับท่าทางแรกของผู้ใช้ — คืนฟังก์ชันถอด */
    attachUnlock() {
        const fn = () => unlock();
        // ตามสเปก HTML: นิ้วนับตอนยก (pointerup/touchend/click) ไม่ใช่ตอนแตะลง — iOS Safari ปลดล็อกได้แค่ touchend/click
        const evs = ['pointerdown', 'pointerup', 'keydown', 'touchend', 'click'];
        evs.forEach((e) => window.addEventListener(e, fn, { passive: true }));
        return () => evs.forEach((e) => window.removeEventListener(e, fn));
    },

    setEnabled(on) {
        enabled.value = !!on;
        try {
            localStorage.setItem(STORAGE_KEY, on ? 'on' : 'off');
        } catch {
            // จำไม่ได้ก็ไม่เป็นไร ใช้ได้เฉพาะรอบนี้
        }
        if (on) unlock();
        else if (ctx) ctx.suspend().catch(() => {});
    },

    /** ออกจากหน้า / ซ่อนแท็บ: หยุดทุกเสียง */
    pause() {
        if (!ctx) return;
        ctx.suspend().catch(() => {});
    },

    resume() {
        if (ctx && enabled.value) ctx.resume().catch(() => {});
    },

    /**
     * เริ่มบินไปสถานีอื่น: เสียง "วูบ" นุ่มๆ สองเสียงห่างคู่ห้า ไต่ขึ้น (ไปข้างหน้า) หรือไต่ลง (ย้อนกลับ)
     * เป็นโน้ตล้วน ไม่มี noise — ไม่ซ่า
     */
    swoosh(dir = 1) {
        if (!live() || throttle('swoosh', 300)) return;
        const from = midi(dir > 0 ? 67 : 79);
        const to = midi(dir > 0 ? 79 : 67);
        tone({ freq: from, glide: to, gain: 0.035, attack: 0.12, decay: 0.5, send: 0.7 });
        tone({ freq: from * 1.5, glide: to * 1.5, gain: 0.018, attack: 0.15, decay: 0.45, send: 0.7, when: 0.03 });
    },

    /** ถึงสถานีที่ i: ระฆังแก้ว (โน้ตตามสถานี) + ทุ้มสั้น */
    arrive(i = 0) {
        if (!live() || throttle('arrive', 600)) return;
        const f = midi(SCALE[(i * 2) % SCALE.length] - 12);
        [1, 2.76, 5.4].forEach((k, n) => tone({ freq: f * k, gain: 0.07 / (n + 1), attack: 0.004, decay: 1.4 - n * 0.35, send: 0.8 }));
        tone({ freq: 110, glide: 62, gain: 0.12, attack: 0.01, decay: 0.45 });
    },

    /** ชี้ปุ่ม/ลิงก์: x,y = ตำแหน่งบนจอ (0..1) */
    hover(x = 0.5, y = 0.5) {
        if (!live() || throttle('hover', 45)) return;
        const idx = Math.round((1 - Math.max(0, Math.min(1, y))) * 6);
        tone({ freq: midi(SCALE[idx] + 12), gain: 0.035, attack: 0.002, decay: 0.06, pan: (x - 0.5) * 1.4 });
    },

    click() {
        if (!live() || throttle('click', 60)) return;
        tone({ freq: midi(84), type: 'triangle', gain: 0.07, decay: 0.05 });
        tone({ freq: midi(91), type: 'triangle', gain: 0.05, decay: 0.08, when: 0.04 });
    },

    /** ขั้นถัดไปของทัวร์ */
    chime() {
        if (!live() || throttle('chime', 200)) return;
        [76, 81, 88].forEach((m, n) => tone({ freq: midi(m), gain: 0.06, decay: 0.35, when: n * 0.07, send: 0.6 }));
    },

    /** คำตอบจาก AI มาถึง */
    reply() {
        if (!live()) return;
        tone({ freq: midi(88), gain: 0.06, decay: 0.25, send: 0.5 });
        tone({ freq: midi(95), gain: 0.05, decay: 0.4, when: 0.09, send: 0.5 });
    },

    /** ชี้การ์ดในฉาก 3D */
    card(x = 0.5) {
        if (!live() || throttle('card', 120)) return;
        tone({ freq: 1760, gain: 0.04, decay: 0.3, pan: (x - 0.5) * 1.2, send: 0.7 });
        tone({ freq: 2640, gain: 0.02, decay: 0.2, pan: (x - 0.5) * 1.2 });
    },

    /** ชี้แท่งเทียน: ขึ้น = สูง, ลง = ต่ำ */
    tick(up = true) {
        if (!live() || throttle('tick', 50)) return;
        tone({ freq: up ? 1320 : 780, type: 'square', gain: 0.018, attack: 0.001, decay: 0.03 });
    },

    /** เหรียญร่วงลงกอง: เสียงโลหะกระทบ (FM) สุ่มระดับนิดหน่อย */
    coin() {
        if (!live() || throttle('coin', 45)) return;
        const t = ctx.currentTime;
        const base = 2100 * (0.9 + Math.random() * 0.25);
        const car = ctx.createOscillator();
        const mod = ctx.createOscillator();
        const modGain = ctx.createGain();
        const g = ctx.createGain();
        car.frequency.value = base;
        mod.frequency.value = base * 3.07;
        modGain.gain.setValueAtTime(base * 1.8, t);
        modGain.gain.exponentialRampToValueAtTime(1, t + 0.2);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.05, t + 0.002);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
        mod.connect(modGain).connect(car.frequency);
        car.connect(g).connect(master);
        g.connect(reverbIn);
        car.start(t);
        mod.start(t);
        car.stop(t + 0.25);
        mod.stop(t + 0.25);
    },

    // ── น้อง TPIX ──────────────────────────────────────────────────────────
    /** เริ่มบิน: ประกายไล่ขึ้น แพนตามทิศ */
    fly(dir = 1) {
        if (!live() || throttle('fly', 400)) return;
        [0, 2, 4, 5, 7, 9].forEach((k, n) =>
            tone({ freq: midi(SCALE[k % SCALE.length] + (k > 6 ? 12 : 0)), type: 'triangle', gain: 0.04, decay: 0.12, when: n * 0.045, pan: dir * (n / 6 - 0.3), send: 0.6 }),
        );
    },

    land() {
        if (!live() || throttle('land', 300)) return;
        tone({ freq: 520, glide: 780, gain: 0.05, attack: 0.004, decay: 0.09 });
    },

    /** จิ้มน้อง: ดึ๋ง */
    poke() {
        if (!live() || throttle('poke', 150)) return;
        tone({ freq: 330, glide: 720, gain: 0.08, attack: 0.005, decay: 0.12 });
        tone({ freq: 720, glide: 480, gain: 0.05, attack: 0.005, decay: 0.16, when: 0.11 });
    },

    /** หาว: เสียงนุ่มไหลลง (ตอนหลับไม่มีเสียงเลย — คนอาจกำลังอ่านเงียบๆ) */
    yawn() {
        if (!live() || throttle('yawn', 2000)) return;
        tone({ freq: midi(81), glide: midi(69), gain: 0.045, attack: 0.25, decay: 0.9, send: 0.5 });
        tone({ freq: midi(88), glide: midi(76), gain: 0.02, attack: 0.3, decay: 0.8, send: 0.5 });
    },

    /** สะดุ้งตื่น: โน้ตสั้นไต่ขึ้นสองตัว */
    wake() {
        if (!live() || throttle('wake', 800)) return;
        tone({ freq: midi(79), type: 'triangle', gain: 0.05, decay: 0.08 });
        tone({ freq: midi(86), type: 'triangle', gain: 0.05, decay: 0.16, when: 0.07, send: 0.4 });
    },

    /** จังหวะพูด (เบามาก ไม่เกิน ~12 ครั้ง/วินาที) */
    pip(level = 1) {
        if (!live() || level <= 0 || throttle('pip', 85)) return;
        tone({ freq: 680 + Math.random() * 260, type: 'triangle', gain: 0.022, attack: 0.003, decay: 0.035 });
    },
};

/** ให้คอมโพเนนต์ผูกปุ่มเปิด/ปิดเสียง */
export function useHomeSound() {
    return { enabled: readonly(enabled), toggle: () => sfx.setEnabled(!enabled.value) };
}

/** สำหรับเทสต์เท่านั้น */
export function __soundState() {
    return { hasContext: !!ctx, enabled: enabled.value };
}
