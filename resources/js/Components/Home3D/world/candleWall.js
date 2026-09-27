/**
 * TPIX TRADE — กำแพงกราฟแท่งเทียน 3D (สถานีแรก)
 *
 * แท่งเทียนจริงจาก /api/v1/market/klines + เส้น EMA เรืองแสง + เส้นราคาล่าสุด + ป้ายราคา
 * ด้านหลังเป็น Depth Chart ฝั่ง Bid/Ask จากสมุดคำสั่งซื้อจริง
 * แท่งสุดท้ายขยับตามราคาสด · ชี้แท่งไหนได้ค่า OHLC ของแท่งนั้น
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { C, glowingStandard, glowTexture, canvasTexture, easeOutBack } from './common.js';

const W = 18;
const Y0 = 0.8;
const Y1 = 7.2;
const MAX = 72;

export function normalizeCandles(raw) {
    if (!Array.isArray(raw)) return [];
    return raw
        .map((k) => ({
            time: Number(k.time ?? k[0]),
            open: Number(k.open ?? k[1]),
            high: Number(k.high ?? k[2]),
            low: Number(k.low ?? k[3]),
            close: Number(k.close ?? k[4]),
            volume: Number(k.volume ?? k[5]),
        }))
        .filter((c) => [c.open, c.high, c.low, c.close].every((v) => Number.isFinite(v) && v > 0));
}

export function ema(values, period) {
    const k = 2 / (period + 1);
    const out = [];
    let prev = values[0];
    for (const v of values) {
        prev = v * k + prev * (1 - k);
        out.push(prev);
    }
    return out;
}

export function cumulativeDepth(levels) {
    let sum = 0;
    return (levels || [])
        .map(([p, q]) => [Number(p), Number(q)])
        .filter(([p, q]) => Number.isFinite(p) && Number.isFinite(q))
        .map(([p, q]) => {
            sum += q;
            return [p, sum];
        });
}

export function formatPrice(p) {
    if (!Number.isFinite(p)) return '';
    if (p >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (p >= 1) return p.toFixed(2);
    return p.toFixed(4);
}

function priceTag() {
    const t = canvasTexture(320, 88);
    const mat = new THREE.SpriteMaterial({ map: t.tex, transparent: true, depthTest: false, fog: false });
    const sprite = new THREE.Sprite(mat);
    sprite.scale.set(2.9, 0.8, 1);
    sprite.renderOrder = 10;
    function draw(text, up) {
        const { ctx } = t;
        const w = 320;
        const h = 88;
        ctx.clearRect(0, 0, w, h);
        ctx.fillStyle = up ? '#00c853' : '#ff1744';
        ctx.beginPath();
        ctx.moveTo(24, 8);
        ctx.lineTo(w - 10, 8);
        ctx.quadraticCurveTo(w - 4, 8, w - 4, 16);
        ctx.lineTo(w - 4, h - 16);
        ctx.quadraticCurveTo(w - 4, h - 8, w - 10, h - 8);
        ctx.lineTo(24, h - 8);
        ctx.lineTo(4, h / 2);
        ctx.closePath();
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 40px ui-monospace, Menlo, monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, (w + 20) / 2, h / 2 + 1);
        t.tex.needsUpdate = true;
    }
    return { sprite, draw, dispose: () => (t.tex.dispose(), mat.dispose()) };
}

/**
 * ป้ายหัวกราฟ: บอกชัดว่าเป็นกราฟเหรียญอะไร กรอบเวลาไหน ราคาตอนนี้เท่าไร
 * (เจ้าของทัก: "กราฟอันแรกบอกด้วยว่ากราฟเหรียญอะไร")
 */
function chartHeader() {
    const W = 900;
    const H = 210;
    const t = canvasTexture(W, H);
    const mat = new THREE.SpriteMaterial({ map: t.tex, transparent: true, depthWrite: false, fog: false });
    const sprite = new THREE.Sprite(mat);
    sprite.scale.set(7.4, (7.4 * H) / W, 1);
    sprite.renderOrder = 9;
    sprite.raycast = () => {};
    const font = 'ui-sans-serif, system-ui, "Noto Sans Thai", "Leelawadee UI", sans-serif';
    function draw({ base = 'BTC', quote = 'USDT', sub = '', price = null, change = null }) {
        const { ctx } = t;
        ctx.clearRect(0, 0, W, H);
        ctx.fillStyle = 'rgba(6, 14, 34, 0.78)';
        ctx.beginPath();
        ctx.moveTo(30, 8);
        ctx.arcTo(W - 8, 8, W - 8, H - 8, 26);
        ctx.arcTo(W - 8, H - 8, 8, H - 8, 26);
        ctx.arcTo(8, H - 8, 8, 8, 26);
        ctx.arcTo(8, 8, W - 8, 8, 26);
        ctx.closePath();
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = 'rgba(34, 211, 238, 0.55)';
        ctx.stroke();
        // โลโก้เหรียญ
        ctx.fillStyle = '#f7931a';
        ctx.beginPath();
        ctx.arc(95, H / 2, 58, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.font = `800 70px ${font}`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(base === 'BTC' ? '₿' : base.slice(0, 1), 95, H / 2 + 4);
        // ชื่อคู่
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#ffffff';
        ctx.font = `800 64px ${font}`;
        ctx.fillText(base, 180, 98);
        const bw = ctx.measureText(base).width;
        ctx.fillStyle = '#94a3b8';
        ctx.font = `600 40px ${font}`;
        ctx.fillText(` / ${quote}`, 180 + bw, 98);
        ctx.fillStyle = '#7dd3fc';
        ctx.font = `600 30px ${font}`;
        ctx.fillText(sub, 182, 152);
        // ราคา + เปลี่ยนแปลง 24 ชม.
        if (Number.isFinite(price)) {
            ctx.textAlign = 'right';
            ctx.fillStyle = '#ffffff';
            ctx.font = `700 50px ui-monospace, Menlo, monospace`;
            ctx.fillText(`$${formatPrice(price)}`, W - 40, 98);
            if (Number.isFinite(change)) {
                const up = change >= 0;
                ctx.fillStyle = up ? '#00e676' : '#ff5252';
                ctx.font = `700 36px ui-monospace, Menlo, monospace`;
                ctx.fillText(`${up ? '▲ +' : '▼ '}${change.toFixed(2)}%`, W - 40, 150);
            }
        }
        t.tex.needsUpdate = true;
    }
    return { sprite, draw, dispose: () => (t.tex.dispose(), mat.dispose()) };
}

/**
 * @param {{ onHover?: (info:object|null)=>void }} opts
 */
export function buildCandleWall(opts = {}) {
    const group = new THREE.Group();
    const chart = new THREE.Group();
    group.add(chart);

    const geo = new THREE.BoxGeometry(1, 1, 1);
    geo.translate(0, 0.5, 0);
    const bodies = new THREE.InstancedMesh(geo, glowingStandard({ boost: 0.45 }), MAX);
    const wicks = new THREE.InstancedMesh(geo, new THREE.MeshBasicMaterial({ transparent: true, opacity: 0.9 }), MAX);
    const vols = new THREE.InstancedMesh(geo, new THREE.MeshBasicMaterial({ transparent: true, opacity: 0.25, depthWrite: false }), MAX);
    for (const m of [bodies, wicks, vols]) {
        m.count = 0;
        m.frustumCulled = false;
        m.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
        chart.add(m);
    }

    const emaCore = new THREE.MeshBasicMaterial({ color: C.cyan });
    const emaGlow = new THREE.MeshBasicMaterial({ color: C.cyan, transparent: true, opacity: 0.3, depthWrite: false, blending: THREE.AdditiveBlending });
    let emaMeshes = [];

    const lineMat = new THREE.LineDashedMaterial({ color: C.green, dashSize: 0.25, gapSize: 0.16, transparent: true, opacity: 0.85 });
    const line = new THREE.Line(
        new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(-W / 2, 0, 0.02), new THREE.Vector3(W / 2 + 0.4, 0, 0.02)]),
        lineMat,
    );
    line.computeLineDistances();
    line.visible = false;
    chart.add(line);
    const tag = priceTag();
    tag.sprite.visible = false;
    chart.add(tag.sprite);

    const header = chartHeader();
    // ครึ่งขวาของกราฟ (ครึ่งซ้ายโดนแผงข้อความทับ) และต่ำกว่าแถบนำทาง
    header.sprite.position.set(0.9, Y1 + 0.35, 0.3);
    header.draw({});
    chart.add(header.sprite);
    let headerInfo = { base: 'BTC', quote: 'USDT', sub: '', change: null };
    let headerAt = 0;

    const pulseTex = glowTexture();
    const pulse = new THREE.Sprite(new THREE.SpriteMaterial({ map: pulseTex, color: C.green, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    pulse.visible = false;
    chart.add(pulse);

    const depth = new THREE.Group();
    depth.position.set(0, 0, -3.2);
    chart.add(depth);
    let depthMeshes = [];

    // ── ข้อมูล ────────────────────────────────────────────────────────────
    let candles = [];
    let lo = 0;
    let hi = 1;
    let spacing = 0.25;
    let volMax = 1;
    let live = null;
    let liveTarget = null;
    let intro = 0;
    let hovered = -1;
    let hasData = false;

    const mtx = new THREE.Matrix4();
    const q = new THREE.Quaternion();
    const p = new THREE.Vector3();
    const s = new THREE.Vector3();
    const col = new THREE.Color();
    const white = new THREE.Color('#ffffff');

    const yOf = (v) => Y0 + ((v - lo) / Math.max(1e-9, hi - lo)) * (Y1 - Y0);
    const xOf = (i) => -W / 2 + spacing * (i + 0.5);

    function rescale() {
        lo = Math.min(...candles.map((c) => c.low));
        hi = Math.max(...candles.map((c) => c.high));
        const pad = (hi - lo) * 0.06 || hi * 0.01;
        lo -= pad;
        hi += pad;
    }

    function write(i, g) {
        const c = candles[i];
        const up = c.close >= c.open;
        const w = spacing * 0.62;
        const gg = Math.max(0.001, g);
        const yO = yOf(c.open);
        const yC = yOf(c.close);
        p.set(xOf(i), Math.min(yO, yC) * gg, 0);
        s.set(w, Math.max(0.04, Math.abs(yC - yO)) * gg, w * 0.9);
        bodies.setMatrixAt(i, mtx.compose(p, q, s));
        const yL = yOf(c.low);
        const yH = yOf(c.high);
        p.set(xOf(i), yL * gg, 0);
        s.set(w * 0.16, Math.max(0.02, (yH - yL) * gg), w * 0.16);
        wicks.setMatrixAt(i, mtx.compose(p, q, s));
        p.set(xOf(i), 0, 0.9);
        s.set(w, Math.max(0.005, (c.volume / volMax) * 0.45 * gg), w * 0.5);
        vols.setMatrixAt(i, mtx.compose(p, q, s));
        col.copy(up ? C.green : C.red);
        if (i === hovered) col.lerp(white, 0.45);
        bodies.setColorAt(i, col);
        wicks.setColorAt(i, col);
        vols.setColorAt(i, col);
    }

    function flush() {
        for (const m of [bodies, wicks, vols]) {
            m.count = candles.length;
            m.instanceMatrix.needsUpdate = true;
            if (m.instanceColor) m.instanceColor.needsUpdate = true;
        }
    }

    function writeAll(g) {
        const n = candles.length;
        for (let i = 0; i < n; i++) {
            const local = Math.min(1, Math.max(0, g * 1.8 - (i / n) * 0.8));
            write(i, easeOutBack(local));
        }
        flush();
    }

    function buildEma() {
        emaMeshes.forEach((m) => {
            chart.remove(m);
            m.geometry.dispose();
        });
        emaMeshes = [];
        if (candles.length < 4) return;
        const pts = ema(candles.map((c) => c.close), 9).map((v, i) => new THREE.Vector3(xOf(i), yOf(v), 0.06));
        const curve = new THREE.CatmullRomCurve3(pts);
        const segs = Math.min(420, pts.length * 6);
        emaMeshes = [
            new THREE.Mesh(new THREE.TubeGeometry(curve, segs, 0.028, 6, false), emaCore),
            new THREE.Mesh(new THREE.TubeGeometry(curve, segs, 0.1, 8, false), emaGlow),
        ];
        emaMeshes.forEach((m) => chart.add(m));
    }

    function updateLive() {
        const last = candles[candles.length - 1];
        const up = last.close >= (candles[candles.length - 2]?.close ?? last.open);
        const y = yOf(last.close);
        line.position.y = y;
        line.visible = true;
        lineMat.color.copy(up ? C.green : C.red);
        tag.sprite.position.set(xOf(candles.length - 1) + 1.8, y, 0.1);
        tag.sprite.visible = true;
        tag.draw(formatPrice(last.close), up);
        // ป้ายหัวกราฟวาดใหม่ไม่เกิน 4 ครั้ง/วินาที (ระหว่างราคาไหลจะเปลี่ยนทุกเฟรม)
        const now = performance.now();
        if (now - headerAt > 250) {
            headerAt = now;
            header.draw({ ...headerInfo, price: last.close });
        }
        pulse.position.set(xOf(candles.length - 1), y, 0.35);
        pulse.material.color.copy(up ? C.green : C.red);
        pulse.visible = true;
    }

    function setCandles(raw, { instant = false } = {}) {
        const list = normalizeCandles(raw).slice(-MAX);
        if (list.length < 2) return false;
        const first = !hasData;
        candles = list;
        spacing = W / candles.length;
        volMax = Math.max(...candles.map((c) => c.volume), 1e-9);
        rescale();
        live = candles[candles.length - 1].close;
        liveTarget = live;
        hasData = true;
        if (first) intro = instant ? 1 : 0;
        writeAll(first ? intro : 1);
        buildEma();
        updateLive();
        return true;
    }

    /** ข้อมูลหัวกราฟ: คู่เหรียญ, คำอธิบายกรอบเวลา, % เปลี่ยนแปลง 24 ชม. */
    function setHeader(info) {
        headerInfo = { ...headerInfo, ...info };
        header.draw({ ...headerInfo, price: hasData ? candles[candles.length - 1].close : info.price ?? null });
    }

    function setLivePrice(price) {
        const v = Number(price);
        if (hasData && Number.isFinite(v) && v > 0) liveTarget = v;
    }

    function setDepth(book) {
        depthMeshes.forEach((m) => {
            depth.remove(m);
            m.geometry.dispose();
            m.material.dispose();
        });
        depthMeshes = [];
        const bids = cumulativeDepth(book?.bids).slice(0, 40);
        const asks = cumulativeDepth(book?.asks).slice(0, 40);
        if (bids.length < 2 || asks.length < 2) return;
        const maxQ = Math.max(bids[bids.length - 1][1], asks[asks.length - 1][1]);
        const half = W / 2 + 2;
        const H = 5.2;
        const make = (levels, dir, color) => {
            const shape = new THREE.Shape();
            shape.moveTo(0, 0);
            levels.forEach(([, qq], i) => {
                const y = (qq / maxQ) * H;
                shape.lineTo(dir * (i / levels.length) * half, y);
                shape.lineTo(dir * ((i + 1) / levels.length) * half, y);
            });
            shape.lineTo(dir * half, 0);
            shape.lineTo(0, 0);
            const fill = new THREE.Mesh(
                new THREE.ShapeGeometry(shape),
                new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.14, depthWrite: false, side: THREE.DoubleSide }),
            );
            const edge = new THREE.Line(
                new THREE.BufferGeometry().setFromPoints(shape.getPoints().map((pt) => new THREE.Vector3(pt.x, pt.y, 0.01))),
                new THREE.LineBasicMaterial({ color, transparent: true, opacity: 0.7 }),
            );
            return [fill, edge];
        };
        depthMeshes = [...make(bids, -1, C.green), ...make(asks, 1, C.red)];
        depthMeshes.forEach((m) => depth.add(m));
    }

    // ── ชี้แท่ง ────────────────────────────────────────────────────────────
    const tmp = new THREE.Vector3();
    function pick(raycaster, camera) {
        if (!hasData || intro < 1) return;
        const hit = raycaster.intersectObjects([bodies, wicks], false)[0];
        const id = hit ? hit.instanceId : -1;
        if (id === hovered) return;
        const prev = hovered;
        hovered = id;
        if (prev >= 0 && prev < candles.length) write(prev, 1);
        if (id >= 0) write(id, 1);
        flush();
        if (id < 0) return opts.onHover?.(null);
        const c = candles[id];
        tmp.set(xOf(id), yOf(c.high) + 0.3, 0);
        chart.localToWorld(tmp).project(camera);
        opts.onHover?.({ candle: c, x: tmp.x * 0.5 + 0.5, y: -tmp.y * 0.5 + 0.5 });
    }

    function clearHover() {
        if (hovered < 0) return;
        const prev = hovered;
        hovered = -1;
        write(prev, 1);
        flush();
        opts.onHover?.(null);
    }

    function update(t, dt, focus) {
        if (hasData && intro < 1 && focus > 0.2) {
            intro = Math.min(1, intro + dt / 1.8);
            writeAll(intro);
        }
        if (hasData && liveTarget !== null && Math.abs(liveTarget - live) > 1e-9) {
            live += (liveTarget - live) * (1 - Math.exp(-4 * dt));
            if (Math.abs(liveTarget - live) < Math.abs(liveTarget) * 1e-6) live = liveTarget;
            const last = candles[candles.length - 1];
            last.close = live;
            last.high = Math.max(last.high, live);
            last.low = Math.min(last.low, live);
            if (live > hi || live < lo) {
                rescale();
                writeAll(1);
                buildEma();
            } else {
                write(candles.length - 1, 1);
                flush();
            }
            updateLive();
        }
        if (pulse.visible) {
            const k = 0.9 + Math.sin(t * 3.2) * 0.3;
            pulse.scale.set(k, k, 1);
        }
    }

    return {
        object: group,
        setCandles,
        setLivePrice,
        setHeader,
        setDepth,
        pick,
        clearHover,
        update,
        get hasData() {
            return hasData;
        },
        dispose() {
            header.dispose();
            tag.dispose();
            pulseTex.dispose();
        },
    };
}
