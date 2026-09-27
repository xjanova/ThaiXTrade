/**
 * TPIX TRADE — ประกอบโลก 3D ของหน้าแรก
 *
 * วางวัตถุของทุกสถานี + พื้นกริด + เมืองแท่งกราฟ แล้วคุมกล้องตามความคืบหน้าการเลื่อน (f)
 * กล้องบินตามเส้นโค้ง CatmullRom ผ่านมุมกล้องของแต่ละสถานี ค้างที่สถานีช่วงกลางให้อ่านแผงได้
 *
 * ทุกวัตถุหลักกดได้: ชี้แล้วเน้น (ขยาย/เรืองแสง) + แจ้งป้าย "ไปหน้า … →" ให้หน้าเว็บแสดง
 * กด/แตะ → hrefAt(x, y) ยิง raycast ณ จุดนั้นทันที (มือถือไม่มี hover มาก่อน)
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
 * @param {{ ecosystem: object[], features: object[], resolveTarget: (key:string)=>({href:string,label:string}|null),
 *           onCandleHover?: Function, onTarget?: (info:object|null)=>void, onCoinLand?: Function }} opts
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
        pieces.push({ piece, i, key });
        return piece;
    };

    const wall = place('hero', buildCandleWall({ onHover: opts.onCandleHover }), [1.5, 0, -3], -0.12);
    // กำแพงกราฟทั้งแผงกดได้ → กระดานเทรด BTC/USDT
    wall.object.traverse((o) => {
        o.userData.hitKey = 'chart';
    });
    wall.targets = [{ object: wall.object, key: 'chart' }];
    wall.setHover = () => {};
    place('hero', buildCoin({ radius: 3.2, thickness: 0.42 }), [4.5, 10.6, -17]);
    place('node', buildNodes(), [0, 0, -1]);
    const tickers = place('markets', buildTickers(), [0, 0, -3]);
    place('sale', buildSale({ onCoinLand: opts.onCoinLand }), [0, 0, -1]);
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

    // ── ชี้/กด ─────────────────────────────────────────────────────────────
    const ndc = new THREE.Vector2(10, 10);
    const par = { x: 0, y: 0, tx: 0, ty: 0 };
    const raycaster = new THREE.Raycaster();
    let pointerIn = false;
    let hover = null; // { key, href, label, owner, hit }
    let lastF = 0;

    function pointer(x, y, inside = true) {
        ndc.set(x * 2 - 1, -(y * 2 - 1));
        par.tx = x - 0.5;
        par.ty = y - 0.5;
        pointerIn = inside;
    }

    /** ชิ้นที่กดได้ของสถานีที่ยืนอยู่ ณ จุด ndc */
    function pickAt(point) {
        const near = Math.round(lastF);
        if (Math.abs(lastF - near) > 0.3) return null;
        const stationPieces = pieces.filter((p) => p.i === near && p.piece.targets?.length);
        if (!stationPieces.length) return null;
        raycaster.setFromCamera(point, camera);
        const objects = stationPieces.flatMap((p) => p.piece.targets.map((tg) => tg.object));
        for (const hit of raycaster.intersectObjects(objects, true)) {
            let o = hit.object;
            while (o && !o.userData.hitKey) o = o.parent;
            const key = o?.userData.hitKey;
            if (!key) continue;
            const res = opts.resolveTarget(key);
            if (!res?.href) continue;
            let owner = null;
            for (const p of stationPieces) {
                let inside = false;
                p.piece.object.traverse((c) => {
                    if (c === hit.object) inside = true;
                });
                if (inside) {
                    owner = p.piece;
                    break;
                }
            }
            return { key, hit, owner, ...res };
        }
        return null;
    }

    /** กด/แตะที่จุด (x, y สัดส่วนของจอ) → ลิงก์ หรือ null */
    function hrefAt(x, y) {
        return pickAt(new THREE.Vector2(x * 2 - 1, -(y * 2 - 1)))?.href ?? null;
    }

    function setHover(next) {
        const changed = (next?.key ?? null) !== (hover?.key ?? null);
        if (changed && hover) hover.owner?.setHover?.(null);
        hover = next;
        if (hover) hover.owner?.setHover?.(hover.key, hover.hit);
        if (changed) opts.onTarget?.(hover ? { key: hover.key, href: hover.href, label: hover.label } : null);
    }

    const look = new THREE.Vector3();
    const prevCam = new THREE.Vector3();
    const n = STATIONS.length;
    const fogBase = scene.fog.density;
    let speed = 0;

    function update(dt, t, f) {
        lastF = f;
        const s = pathParam(f, n);
        const u = s / (n - 1);
        prevCam.copy(camera.position);
        posCurve.getPoint(u, camera.position);
        lookCurve.getPoint(u, look);
        // ความเร็วกล้องบนเส้นทาง (ก่อนบวก parallax) — ใช้คุมเสียงลม
        speed = dt > 0 ? prevCam.distanceTo(camera.position) / dt : 0;

        // ลอยเบาๆ + ตามเมาส์
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
            if (Math.abs(f - i) > 2.2) continue;
            piece.update?.(t, dt, 1 - smoothstep(0, 1.1, Math.abs(f - i)));
        }

        // ชี้วัตถุ (เมาส์เท่านั้น — นิ้วใช้ hrefAt ตอนแตะ)
        if (pointerIn) {
            setHover(pickAt(ndc));
            const near = Math.round(f);
            if (near === byKey.hero.i && Math.abs(f - near) < 0.3) wall.pick(raycaster, camera);
            else wall.clearHover();
        } else {
            setHover(null);
            wall.clearHover();
        }
    }

    return {
        wall,
        tickers,
        layout,
        pointer,
        update,
        hrefAt,
        /** สลับภาษา: วาดข้อความบนการ์ดระบบนิเวศใหม่ */
        relabel(ecosystem) {
            eco.relabel(ecosystem);
        },
        get speed() {
            return speed;
        },
        dispose() {
            pieces.forEach(({ piece }) => piece.dispose?.());
        },
    };
}
