/**
 * TPIX TRADE — เอนจินหน้าแรก 3D
 *
 * renderer + ฉาก + กล้อง + ลูปเฟรม + ตัวคุมคุณภาพ (governor)
 *
 * governor: วัดเวลาต่อเฟรมจริงเป็นช่วงๆ ถ้าช้ากว่า ~28 fps ลดความละเอียด (pixel ratio) ทีละขั้น
 * ลดจนสุดแล้วยังช้า → เรียก onSlow() ให้หน้าเว็บถอยไปหน้าแรกแบบเดิม
 * ช่วงที่เฟรมขาดหาย (แท็บถูกซ่อน/เบราว์เซอร์หยุดวาด) ไม่นับ — ไม่งั้นจะเข้าใจผิดว่าเครื่องช้า
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';

export const SKY = '#050b1a';

/** ขั้นคุณภาพ: เพดาน pixel ratio */
export const QUALITY_STEPS = [1.75, 1.35, 1, 0.75];

/** ตัดสินจากหน้าต่างเวลาต่อเฟรม — แยกเป็นฟังก์ชันบริสุทธิ์ให้เทสต์ได้ */
export function createGovernor({ slowMs = 36, sample = 60, warmup = 1.5 } = {}) {
    let frames = [];
    let wait = warmup;
    return {
        /** @returns {'ok'|'degrade'|null} */
        push(dt) {
            // เฟรมที่ห่างเกิน 0.25 วิ = เบราว์เซอร์หยุดวาด ไม่ใช่เครื่องช้า
            if (dt > 0.25) {
                frames = [];
                return null;
            }
            if (wait > 0) {
                wait -= dt;
                return null;
            }
            frames.push(dt * 1000);
            if (frames.length < sample) return null;
            const sorted = [...frames].sort((a, b) => a - b);
            const median = sorted[Math.floor(sorted.length / 2)];
            frames = [];
            return median > slowMs ? 'degrade' : 'ok';
        },
        /** หลังเปลี่ยนคุณภาพ ให้เวลาตั้งตัวก่อนวัดใหม่ */
        settle(seconds = 1) {
            frames = [];
            wait = seconds;
        },
    };
}

/**
 * @param {HTMLCanvasElement} canvas
 * @param {{ onSlow?: ()=>void, onLost?: ()=>void, startLevel?: number }} opts
 */
export function createEngine(canvas, opts = {}) {
    const renderer = new THREE.WebGLRenderer({
        canvas,
        antialias: true,
        alpha: false,
        powerPreference: 'high-performance',
    });
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.08;

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(SKY);
    scene.fog = new THREE.FogExp2(SKY, 0.016);

    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 400);

    // แสงสะท้อนโลหะ (เหรียญทอง) — สร้างครั้งเดียวจากห้องจำลอง ไม่ต้องโหลดไฟล์ HDR
    const pmrem = new THREE.PMREMGenerator(renderer);
    const envRT = pmrem.fromScene(new RoomEnvironment(), 0.04);
    scene.environment = envRT.texture;
    pmrem.dispose();

    scene.add(new THREE.HemisphereLight('#cfeaff', '#141a33', 1.1));
    const sun = new THREE.DirectionalLight('#ffffff', 2.2);
    sun.position.set(12, 30, 18);
    scene.add(sun);
    const rim = new THREE.DirectionalLight('#5ee7ff', 1.1);
    rim.position.set(-20, 10, -30);
    scene.add(rim);

    let level = Math.min(QUALITY_STEPS.length - 1, Math.max(0, opts.startLevel ?? 0));
    const governor = createGovernor();

    function resize() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, QUALITY_STEPS[level]));
        renderer.setSize(w, h, false);
        camera.aspect = w / Math.max(1, h);
        // จอแนวตั้ง: กางมุมกล้องให้เห็นของครบ
        camera.fov = camera.aspect < 0.8 ? 58 : camera.aspect < 1.2 ? 50 : 42;
        camera.updateProjectionMatrix();
    }
    resize();
    window.addEventListener('resize', resize);

    let raf = 0;
    let running = false;
    let last = 0;
    let time = 0;
    let tick = null;

    function frame(now) {
        raf = requestAnimationFrame(frame);
        const raw = last ? (now - last) / 1000 : 1 / 60;
        last = now;
        const dt = Math.min(raw, 0.05);
        time += dt;
        tick?.(dt, time);
        renderer.render(scene, camera);

        const verdict = governor.push(raw);
        if (verdict === 'degrade') {
            if (level < QUALITY_STEPS.length - 1) {
                level += 1;
                resize();
                governor.settle(1.2);
            } else {
                opts.onSlow?.();
            }
        }
    }

    function start(fn) {
        if (fn) tick = fn;
        if (running) return;
        running = true;
        last = 0;
        governor.settle(0.6);
        raf = requestAnimationFrame(frame);
    }

    function stop() {
        running = false;
        cancelAnimationFrame(raf);
    }

    const onVisibility = () => (document.hidden ? stop() : start());
    document.addEventListener('visibilitychange', onVisibility);

    const onLost = (e) => {
        e.preventDefault();
        stop();
        opts.onLost?.();
    };
    canvas.addEventListener('webglcontextlost', onLost);

    return {
        THREE,
        renderer,
        scene,
        camera,
        start,
        stop,
        resize,
        get level() {
            return level;
        },
        /** วาดเฟรมเดียว (ดีบัก/ทดสอบ) */
        step(dt = 1 / 60) {
            time += dt;
            tick?.(dt, time);
            renderer.render(scene, camera);
        },
        /** คอมไพล์ shader ล่วงหน้า กันกระตุกตอนบินไปถึงสถานีแรกที่มีวัสดุใหม่ */
        async compile() {
            if (renderer.compileAsync) await renderer.compileAsync(scene, camera);
            else renderer.compile(scene, camera);
        },
        dispose() {
            stop();
            window.removeEventListener('resize', resize);
            document.removeEventListener('visibilitychange', onVisibility);
            canvas.removeEventListener('webglcontextlost', onLost);
            scene.traverse((o) => {
                o.geometry?.dispose?.();
                const mats = Array.isArray(o.material) ? o.material : o.material ? [o.material] : [];
                mats.forEach((m) => {
                    Object.values(m).forEach((v) => v?.isTexture && v.dispose());
                    m.dispose();
                });
            });
            envRT.dispose();
            renderer.dispose();
            renderer.forceContextLoss?.();
        },
    };
}
