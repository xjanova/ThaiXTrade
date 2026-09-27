/**
 * TPIX TRADE — ประกอบโลก 3D ของหน้าแรก
 *
 * วางวัตถุของทุกสถานี + พื้นกริด + เมืองแท่งกราฟ แล้วคุมกล้องตามความคืบหน้าการเลื่อน (f)
 * กล้องบินตามเส้นโค้ง CatmullRom ผ่านมุมกล้องของแต่ละสถานี ค้างที่สถานีช่วงกลางให้อ่านแผงได้
 * เมาส์ขยับกล้องเล็กน้อย (parallax) · ชี้แท่งเทียน/การ์ดระบบนิเวศได้
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { STATIONS, cameraFor, pathParam } from '../stations.js';
import { smoothstep } from './common.js';
import { buildFloor, buildCity } from './floor.js';
import { buildCandleWall } from './candleWall.js';
import { buildCoin, buildNodes, buildTickers, buildSale, buildEcosystem, buildFeatures, buildGate } from './stationsWorld.js';

const at = (st, dx = 0, dy = 0, dz = 0) => new THREE.Vector3(st.pos[0] + dx, st.pos[1] + dy, st.pos[2] + dz);

/**
 * @param {ReturnType<import('../engine.js').createEngine>} engine
 * @param {{ ecosystem: object[], features: object[], onCandleHover?: Function, onCursor?: (c:string)=>void }} opts
 */
export function buildWorld(engine, opts) {
    const { scene, camera } = engine;
    const byKey = Object.fromEntries(STATIONS.map((st, i) => [st.key, { st, i }]));

    const floor = buildFloor();
    scene.add(floor.object);
    const city = buildCity(STATIONS);
    scene.add(city.object);

    const pieces = [];
    const place = (key, piece, offset = [0, 0, 0], rotY = 0) => {
        const { st, i } = byKey[key];
        piece.object.position.copy(at(st, ...offset));
        piece.object.rotation.y = rotY;
        scene.add(piece.object);
        pieces.push({ piece, i });
        return piece;
    };

    const wall = place('hero', buildCandleWall({ onHover: opts.onCandleHover }), [1.5, 0, -3], -0.12);
    place('hero', buildCoin({ radius: 3.2, thickness: 0.42 }), [4.5, 10.6, -17]);
    place('node', buildNodes(), [0, 0, -1]);
    const tickers = place('markets', buildTickers(), [0, 0, -3]);
    place('sale', buildSale(), [0, 0, -1]);
    const eco = place('ecosystem', buildEcosystem(opts.ecosystem), [0, 0, -4]);
    place('features', buildFeatures(opts.features), [0, 0, -1]);
    place('cta', buildGate(), [0, 0, -4]);

    // ── เส้นทางกล้อง ──────────────────────────────────────────────────────
    let posCurve = null;
    let lookCurve = null;
    function layout({ portrait = false } = {}) {
        const keys = STATIONS.map((st) => cameraFor(st, { portrait }));
        posCurve = new THREE.CatmullRomCurve3(keys.map((k) => new THREE.Vector3(...k.pos)), false, 'centripetal');
        lookCurve = new THREE.CatmullRomCurve3(keys.map((k) => new THREE.Vector3(...k.look)), false, 'centripetal');
    }
    layout({ portrait: window.innerWidth / window.innerHeight < 0.9 });

    // ── เมาส์ ───────────────────────────────────────────────────────────────
    const ndc = new THREE.Vector2(10, 10);
    const par = { x: 0, y: 0, tx: 0, ty: 0 };
    const raycaster = new THREE.Raycaster();
    let pointerIn = false;
    let hoverHref = null;

    function pointer(x, y, inside = true) {
        ndc.set(x * 2 - 1, -(y * 2 - 1));
        par.tx = x - 0.5;
        par.ty = y - 0.5;
        pointerIn = inside;
    }

    /** คลิกบนฉาก → href ของการ์ดที่ชี้อยู่ (ให้หน้าเว็บพาไป) */
    function clickHref() {
        return hoverHref;
    }

    const look = new THREE.Vector3();
    const n = STATIONS.length;
    const fogBase = scene.fog.density;

    function update(dt, t, f) {
        const s = pathParam(f, n);
        const u = s / (n - 1);
        posCurve.getPoint(u, camera.position);
        lookCurve.getPoint(u, look);

        // ลอยเบาๆ + ตามเมาส์ (ไม่ขยับตอนกำลังบินเร็ว)
        const k = 1 - Math.exp(-3 * dt);
        par.x += (par.tx - par.x) * k;
        par.y += (par.ty - par.y) * k;
        camera.position.x += par.x * 1.6 + Math.sin(t * 0.21) * 0.25;
        camera.position.y += -par.y * 0.9 + Math.sin(t * 0.33) * 0.15;
        camera.lookAt(look);

        // จบทาง (เลื่อนเลยสถานีสุดท้ายไป footer) → หมอกหนาขึ้น ฉากจางลง
        const dim = smoothstep(n - 1 + 0.2, n - 1 + 0.9, f);
        scene.fog.density = fogBase + dim * 0.05;
        floor.update(t, dim);

        for (const { piece, i } of pieces) {
            const focus = 1 - smoothstep(0, 1.1, Math.abs(f - i));
            // วัตถุไกลเกิน 2 สถานีไม่ต้องคำนวณแอนิเมชัน (ยังวาดได้ถ้าอยู่ในภาพ)
            if (Math.abs(f - i) > 2.2) continue;
            piece.update?.(t, dt, focus);
        }

        // ชี้วัตถุ: เฉพาะสถานีที่ยืนอยู่
        hoverHref = null;
        let cursor = '';
        if (pointerIn) {
            raycaster.setFromCamera(ndc, camera);
            const near = Math.round(f);
            if (near === byKey.hero.i && Math.abs(f - near) < 0.3) wall.pick(raycaster, camera);
            else wall.clearHover();
            if (near === byKey.ecosystem.i && Math.abs(f - near) < 0.3) {
                hoverHref = eco.pick(raycaster);
                if (hoverHref) cursor = 'pointer';
            } else {
                eco.clearHover();
            }
        } else {
            wall.clearHover();
            eco.clearHover();
        }
        opts.onCursor?.(cursor);
    }

    return {
        wall,
        tickers,
        layout,
        pointer,
        update,
        clickHref,
        dispose() {
            pieces.forEach(({ piece }) => piece.dispose?.());
        },
    };
}
