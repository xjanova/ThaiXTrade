/**
 * TPIX TRADE - Sound Effects System
 * เสียงเอฟเฟกต์แบบแอพ Wallet (Web Audio API)
 * ไม่ต้องโหลดไฟล์เสียง — สร้างจาก oscillator
 * Developed by Xman Studio
 */

let audioCtx = null;

function getAudioContext() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    // Resume if suspended (browser autoplay policy)
    if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(() => {});
    }
    return audioCtx;
}

/**
 * เล่นเสียง beep จาก oscillator
 */
function playTone(frequency, duration, type = 'sine', volume = 0.15) {
    try {
        const ctx = getAudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = type;
        osc.frequency.setValueAtTime(frequency, ctx.currentTime);

        gain.gain.setValueAtTime(volume, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + duration);
    } catch {
        // เสียงไม่ critical — ข้ามถ้า AudioContext ไม่พร้อม
    }
}

/**
 * เล่นหลายโน้ตต่อกัน (melody)
 */
function playMelody(notes, type = 'sine', volume = 0.12) {
    try {
        const ctx = getAudioContext();
        let time = ctx.currentTime;

        for (const [freq, dur] of notes) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = type;
            osc.frequency.setValueAtTime(freq, time);

            gain.gain.setValueAtTime(volume, time);
            gain.gain.exponentialRampToValueAtTime(0.001, time + dur);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(time);
            osc.stop(time + dur);

            time += dur * 0.8; // overlap เล็กน้อย
        }
    } catch {
        // silent fail
    }
}

// === เสียงเอฟเฟกต์ ===

/** เสียง splash/startup — ฟังดูเหมือนแอพ wallet เปิดตัว */
export function playSplashSound() {
    playMelody([
        [523.25, 0.15],  // C5
        [659.25, 0.15],  // E5
        [783.99, 0.2],   // G5
        [1046.50, 0.35], // C6
    ], 'sine', 0.1);
}

/** เสียงเชื่อมต่อ wallet สำเร็จ */
export function playConnectSound() {
    playMelody([
        [880, 0.1],   // A5
        [1108, 0.1],  // C#6
        [1318, 0.2],  // E6
    ], 'sine', 0.12);
}

/** เสียง disconnect */
export function playDisconnectSound() {
    playMelody([
        [880, 0.1],
        [660, 0.1],
        [440, 0.2],
    ], 'sine', 0.08);
}

/** เสียง trade/order สำเร็จ */
export function playTradeSound() {
    playMelody([
        [587.33, 0.08],  // D5
        [783.99, 0.08],  // G5
        [987.77, 0.12],  // B5
        [1174.66, 0.2],  // D6
    ], 'triangle', 0.1);
}

/** เสียง error/fail */
export function playErrorSound() {
    playMelody([
        [440, 0.15],
        [349.23, 0.25],
    ], 'sawtooth', 0.06);
}

/** เสียง notification (ข้อความ, toast) */
export function playNotificationSound() {
    playTone(1046.50, 0.12, 'sine', 0.1); // C6 สั้น
}

/** เสียง click เบาๆ */
export function playClickSound() {
    playTone(1200, 0.05, 'sine', 0.06);
}

/** เสียง swap สำเร็จ */
export function playSwapSound() {
    playMelody([
        [659.25, 0.1],  // E5
        [783.99, 0.1],  // G5
        [1046.50, 0.15], // C6
        [1318.51, 0.2],  // E6
    ], 'sine', 0.1);
}

/**
 * Initialize audio context on first user interaction
 * (required by browser autoplay policy)
 */
export function initAudio() {
    const handler = () => {
        getAudioContext();
        document.removeEventListener('click', handler);
        document.removeEventListener('touchstart', handler);
        document.removeEventListener('keydown', handler);
    };
    document.addEventListener('click', handler, { once: true });
    document.addEventListener('touchstart', handler, { once: true });
    document.addEventListener('keydown', handler, { once: true });
}
