/**
 * TPIX TRADE — วัตถุ 3D ของแต่ละสถานี (นอกจากกำแพงแท่งเทียน)
 *
 * ทุกฟังก์ชันคืน { object, update(t, dt, focus), targets, setHover(key, hit) }
 *   focus    0..1 ความใกล้ของกล้อง (1 = อยู่ที่สถานีนี้) ใช้เปิดแอนิเมชันเฉพาะตอนดูอยู่
 *   targets  [{ object, key }] ชิ้นที่ "กดได้" — world/index.js ยิง raycast ใส่แล้วแปลง key เป็นลิงก์
 *   setHover เน้นชิ้นที่เมาส์ชี้อยู่ (ขยาย/เรืองแสง) · key = null คือเลิกชี้
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { C, clamp, smoothstep, glowTexture, coinFaceTexture, createLabel, loadTexture, loadImage, canvasTexture } from './common.js';

const approach = (cur, target, k, dt) => cur + (target - cur) * (1 - Math.exp(-k * dt));

/** ติดป้าย key ให้วัตถุ (และลูกทุกชิ้น) — raycast โดนลูกชิ้นไหนก็ย้อนหา key ได้ */
function tag(object, key) {
    object.traverse((o) => {
        o.userData.hitKey = key;
    });
    return { object, key };
}

// ── เหรียญ TPIX ทอง (โลหะจริง สะท้อน environment) ─────────────────────────
export function buildCoin({ radius = 2.6, thickness = 0.36 } = {}) {
    const face = coinFaceTexture(512);
    const side = new THREE.MeshStandardMaterial({ color: '#e2a92b', metalness: 1, roughness: 0.28 });
    const faceMat = new THREE.MeshStandardMaterial({ map: face, metalness: 0.85, roughness: 0.32 });
    const geo = new THREE.CylinderGeometry(radius, radius, thickness, 96);
    const coin = new THREE.Mesh(geo, [side, faceMat, faceMat]);
    coin.rotation.x = Math.PI / 2;
    const rim = new THREE.Mesh(new THREE.TorusGeometry(radius, thickness * 0.16, 10, 96), side);
    coin.add(rim);
    rim.rotation.x = Math.PI / 2;

    const group = new THREE.Group();
    group.add(coin);
    const glowTex = glowTexture('rgba(255,210,110,1)');
    const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: '#ffcf5a', transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
    halo.scale.setScalar(radius * 4.2);
    group.add(halo);

    let hover = 0;
    let hovered = false;
    let spin = 0;
    return {
        object: group,
        targets: [tag(coin, 'coin')],
        setHover(key) {
            hovered = key === 'coin';
        },
        update(t, dt) {
            hover = approach(hover, hovered ? 1 : 0, 8, dt);
            spin += dt * (0.6 + hover * 2.4);
            coin.rotation.z = spin;
            coin.scale.setScalar(1 + hover * 0.08);
            group.position.y = Math.sin(t * 1.1) * 0.25;
            halo.material.opacity = 0.28 + Math.sin(t * 2) * 0.07 + hover * 0.35;
        },
    };
}

// ── เครือข่าย Master Node: Validator / Sentinel / Light + บล็อกวิ่งตามเส้น ─
export function buildNodes() {
    const group = new THREE.Group();
    const tiers = [
        { n: 4, r: 1.4, y: 4.6, size: 0.36, color: '#ff4d6d' },
        { n: 10, r: 3.3, y: 3.9, size: 0.22, color: '#a78bfa' },
        { n: 28, r: 5.4, y: 3.0, size: 0.12, color: '#22d3ee' },
    ];
    const haloTex = glowTexture();
    const nodes = [];
    const sphere = new THREE.SphereGeometry(1, 24, 16);
    tiers.forEach((tier, ti) => {
        const mat = new THREE.MeshBasicMaterial({ color: tier.color, toneMapped: false });
        const mesh = new THREE.InstancedMesh(sphere, mat, tier.n);
        const m = new THREE.Matrix4();
        for (let i = 0; i < tier.n; i++) {
            const a = (i / tier.n) * Math.PI * 2 + ti * 0.4;
            const y = tier.y + Math.sin(i * 1.7) * 0.8;
            const v = new THREE.Vector3(Math.cos(a) * tier.r, y, Math.sin(a) * tier.r);
            nodes.push({ v, tier: ti });
            m.compose(v, new THREE.Quaternion(), new THREE.Vector3().setScalar(tier.size));
            mesh.setMatrixAt(i, m);
            if (ti < 2) {
                const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: haloTex, color: tier.color, transparent: true, opacity: 0.55, depthWrite: false, blending: THREE.AdditiveBlending }));
                halo.position.copy(v);
                halo.scale.setScalar(tier.size * 7);
                halo.raycast = () => {};
                group.add(halo);
            }
        }
        group.add(mesh);
    });

    // เชื่อม: light → sentinel ใกล้สุด, sentinel → validator ใกล้สุด, validator ต่อกันเป็นวง
    const edges = [];
    const nearest = (from, tier) =>
        nodes.filter((n) => n.tier === tier).reduce((a, b) => (a.v.distanceTo(from.v) < b.v.distanceTo(from.v) ? a : b));
    nodes.forEach((n) => {
        if (n.tier === 2) edges.push([n, nearest(n, 1)]);
        if (n.tier === 1) edges.push([n, nearest(n, 0)]);
    });
    const vals = nodes.filter((n) => n.tier === 0);
    vals.forEach((n, i) => edges.push([n, vals[(i + 1) % vals.length]]));

    const pos = new Float32Array(edges.length * 6);
    edges.forEach(([a, b], i) => pos.set([a.v.x, a.v.y, a.v.z, b.v.x, b.v.y, b.v.z], i * 6));
    const lineGeo = new THREE.BufferGeometry();
    lineGeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    const lineMat = new THREE.LineBasicMaterial({ color: '#5ee7ff', transparent: true, opacity: 0.4 });
    const lines = new THREE.LineSegments(lineGeo, lineMat);
    lines.raycast = () => {};
    group.add(lines);

    // บล็อก (จุดเรืองแสง) วิ่งจากขอบเข้าหา validator
    const PULSES = 36;
    const pulsePos = new Float32Array(PULSES * 3);
    const pulseGeo = new THREE.BufferGeometry();
    pulseGeo.setAttribute('position', new THREE.BufferAttribute(pulsePos, 3));
    const pulses = new THREE.Points(pulseGeo, new THREE.PointsMaterial({ map: glowTexture(), color: '#fff3b0', size: 0.55, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    pulses.raycast = () => {};
    group.add(pulses);
    const seeds = Array.from({ length: PULSES }, (_, i) => ({ e: (i * 7) % edges.length, o: (i * 0.137) % 1 }));

    // แกนกลาง = บล็อกเชนหมุน
    const coreMat = new THREE.MeshStandardMaterial({ color: '#f6bd35', emissive: '#f6bd35', emissiveIntensity: 0.6, metalness: 0.8, roughness: 0.25, flatShading: true });
    const core = new THREE.Mesh(new THREE.IcosahedronGeometry(0.8, 1), coreMat);
    core.position.y = 4.6;
    core.scale.setScalar(0.8);
    group.add(core);

    // พื้นที่กดทั้งเครือข่าย (ลูกบอลใสครอบ) — จุดเล็กๆ กดโดนยาก
    const hitBall = new THREE.Mesh(new THREE.SphereGeometry(6, 16, 12), new THREE.MeshBasicMaterial({ visible: false }));
    hitBall.position.y = 3.8;
    group.add(hitBall);

    let hover = 0;
    let hovered = false;
    return {
        object: group,
        targets: [tag(hitBall, 'nodes')],
        setHover(key) {
            hovered = key === 'nodes';
        },
        update(t, dt, focus) {
            hover = approach(hover, hovered ? 1 : 0, 8, dt);
            group.rotation.y = t * (0.08 + hover * 0.25);
            core.rotation.set(t * 0.4, t * 0.6, 0);
            core.scale.setScalar(0.8 + hover * 0.35);
            coreMat.emissiveIntensity = 0.6 + hover * 0.9;
            lineMat.opacity = 0.4 + hover * 0.35;
            if (focus <= 0) return;
            seeds.forEach((sd, i) => {
                const [a, b] = edges[sd.e];
                const k = (t * (0.35 + hover * 0.5) + sd.o) % 1;
                pulsePos[i * 3] = a.v.x + (b.v.x - a.v.x) * k;
                pulsePos[i * 3 + 1] = a.v.y + (b.v.y - a.v.y) * k;
                pulsePos[i * 3 + 2] = a.v.z + (b.v.z - a.v.z) * k;
            });
            pulseGeo.attributes.position.needsUpdate = true;
        },
    };
}

// ── ตลาด: เสาราคาเหรียญยอดนิยม (ข้อมูลจริง) กดแล้วไปกระดานเหรียญนั้น ────
export function buildTickers() {
    const group = new THREE.Group();
    const geo = new THREE.BoxGeometry(1, 1, 1);
    geo.translate(0, 0.5, 0);
    const slots = 8;
    const pillars = [];
    for (let i = 0; i < slots; i++) {
        const a = -0.9 + (i / (slots - 1)) * 1.8;
        const x = Math.sin(a) * 10;
        const z = -Math.cos(a) * 10 + 4;
        const mat = new THREE.MeshStandardMaterial({ color: C.green, emissive: C.green, emissiveIntensity: 0.22, roughness: 0.4, metalness: 0.35, transparent: true, opacity: 0.9 });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.set(x, 0, z);
        mesh.scale.set(1.1, 0.01, 1.1);
        const label = createLabel({ width: 420, height: 170, scale: 2.5 });
        label.sprite.position.set(x, 1, z);
        group.add(mesh, label.sprite);
        pillars.push({ mesh, mat, label, h: 1, target: 1, x, z, symbol: null, hover: 0 });
    }

    function setTickers(list) {
        // list: [{ symbol, price, change, isUp, rawChange, rawVolume }]
        const rows = (list || []).slice(0, slots);
        const maxVol = Math.max(1, ...rows.map((r) => r.rawVolume || 0));
        pillars.forEach((pl, i) => {
            const r = rows[i];
            pl.symbol = r?.symbol ?? null;
            const key = pl.symbol ? `pair:${pl.symbol}` : null;
            pl.mesh.userData.hitKey = key;
            pl.label.sprite.userData.hitKey = key;
            if (!r) {
                pl.target = 0.01;
                pl.label.sprite.visible = false;
                return;
            }
            const byChange = Math.abs(r.rawChange || 0);
            pl.target = 0.8 + Math.min(4.2, byChange * 0.35 + ((r.rawVolume || 0) / maxVol) * 2.2);
            const c = r.isUp ? C.green : C.red;
            pl.mat.color.copy(c).multiplyScalar(0.7);
            pl.mat.emissive.copy(c);
            pl.label.sprite.visible = true;
            pl.label.set(
                [
                    { text: r.symbol, size: 50, color: '#ffffff' },
                    { text: `$${r.price}`, size: 38, weight: 600, color: '#cbd5e1', font: 'ui-monospace, Menlo, monospace' },
                    { text: r.change, size: 36, color: r.isUp ? '#00e676' : '#ff5252' },
                ],
                { border: r.isUp ? '#00c853' : '#ff1744' },
            );
        });
    }

    let hovered = null;
    return {
        object: group,
        // key ของเสาเปลี่ยนตามข้อมูล (pair:BTC) — ตั้งใน setTickers
        targets: pillars.flatMap((pl) => [{ object: pl.mesh, key: null }, { object: pl.label.sprite, key: null }]),
        setTickers,
        setHover(key) {
            hovered = key;
        },
        update(t, dt, focus) {
            pillars.forEach((pl, i) => {
                const on = hovered && pl.symbol && hovered === `pair:${pl.symbol}`;
                pl.hover = approach(pl.hover, on ? 1 : 0, 10, dt);
                const goal = pl.target * (0.25 + 0.75 * smoothstep(0, 0.8, focus));
                pl.h = approach(pl.h, goal, 4, dt);
                const w = 1.1 + pl.hover * 0.35;
                pl.mesh.scale.set(w, Math.max(0.01, pl.h + pl.hover * 0.4), w);
                pl.mat.emissiveIntensity = 0.22 + pl.hover * 0.6;
                pl.label.sprite.position.y = pl.h + pl.hover * 0.4 + 1.1 + Math.sin(t * 1.4 + i) * 0.1;
                const s = 2.5 * (1 + pl.hover * 0.18);
                pl.label.sprite.scale.set(s, (s * 170) / 420, 1);
            });
        },
    };
}

// ── รอบขาย: กองเหรียญทอง 3 กอง (Private / Pre-Sale / Public) ────────────
export function buildSale({ onCoinLand } = {}) {
    const group = new THREE.Group();
    const stacks = [
        { label: 'Private', price: '$0.05', n: 7, x: -4.8 },
        { label: 'Pre-Sale', price: '$0.08', n: 11, x: 0 },
        { label: 'Public', price: '$0.10', n: 15, x: 4.8 },
    ];
    const total = stacks.reduce((a, s) => a + s.n, 0);
    const coinGeo = new THREE.CylinderGeometry(1.25, 1.25, 0.26, 48);
    const coinMat = new THREE.MeshStandardMaterial({ color: '#f0b429', metalness: 1, roughness: 0.3, emissive: '#000000' });
    const mesh = new THREE.InstancedMesh(coinGeo, coinMat, total);
    mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    mesh.frustumCulled = false;
    group.add(mesh);
    const coins = [];
    const labels = [];
    stacks.forEach((s) => {
        for (let i = 0; i < s.n; i++) {
            coins.push({ x: s.x + (Math.random() - 0.5) * 0.14, y: 0.13 + i * 0.28, z: (Math.random() - 0.5) * 0.14, rot: Math.random() * 6, delay: i * 0.05 + Math.abs(s.x) * 0.02, landed: false });
        }
        const label = createLabel({ width: 420, height: 150, scale: 3 });
        label.set([
            { text: s.label, size: 44, color: '#fcd34d' },
            { text: s.price, size: 48, color: '#ffffff', font: 'ui-monospace, Menlo, monospace' },
        ], { border: '#f6bd35' });
        label.sprite.position.set(s.x, 0.4 + s.n * 0.28 + 1.3, 0);
        group.add(label.sprite);
        labels.push(label.sprite);
    });
    // พื้นที่กด: กล่องใสครอบทั้งสามกอง
    const hitBox = new THREE.Mesh(new THREE.BoxGeometry(14, 6.5, 3.5), new THREE.MeshBasicMaterial({ visible: false }));
    hitBox.position.y = 3;
    group.add(hitBox);

    const m = new THREE.Matrix4();
    const q = new THREE.Quaternion();
    const e = new THREE.Euler();
    const v = new THREE.Vector3();
    const one = new THREE.Vector3();
    let built = 0; // 0..1 เหรียญร่วงลงมาเรียงกอง
    let hover = 0;
    let hovered = false;

    return {
        object: group,
        targets: [tag(hitBox, 'sale')],
        setHover(key) {
            hovered = key === 'sale';
        },
        update(t, dt, focus) {
            if (focus > 0.3) built = Math.min(1, built + dt / 2.2);
            hover = approach(hover, hovered ? 1 : 0, 8, dt);
            coinMat.emissive.setRGB(0.35 * hover, 0.22 * hover, 0);
            labels.forEach((sp, i) => {
                const s = 3 * (1 + hover * 0.12);
                sp.scale.set(s, (s * 150) / 420, 1);
                sp.position.y = 0.4 + stacks[i].n * 0.28 + 1.3 + hover * 0.25 + Math.sin(t * 2 + i) * 0.06 * hover;
            });
            coins.forEach((c, i) => {
                const k = clamp((built * 1.6 - c.delay) / 0.6, 0, 1);
                if (k >= 1 && !c.landed) {
                    c.landed = true;
                    onCoinLand?.();
                }
                const drop = (1 - k) * (1 - k) * 9;
                // ชี้อยู่: เหรียญบนสุดของแต่ละกองเด้งเบาๆ
                const bounce = hover * Math.max(0, Math.sin(t * 6 + i * 0.7)) * 0.05 * (c.y / 4);
                v.set(c.x, c.y + drop + bounce, c.z);
                e.set(0, c.rot + (1 - k) * 6 + t * (0.05 + hover * 0.6), (1 - k) * 1.2);
                q.setFromEuler(e);
                const sz = k > 0 ? 1 : 0.0001;
                mesh.setMatrixAt(i, m.compose(v, q, one.set(sz, sz, sz)));
            });
            mesh.instanceMatrix.needsUpdate = true;
        },
    };
}

// ── ระบบนิเวศ: การ์ดลอยเป็นโค้ง — ภาพ + ชื่อ + คำอธิบาย + ปุ่มในตัวการ์ด ──

const CARD_W = 512;
const CARD_H = 704;

/** ตัดบรรทัดภาษาไทยตามคำ (Intl.Segmenter) ไม่ให้ขาดกลางคำ */
function wrapLines(ctx, text, maxWidth, maxLines) {
    const seg = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter('th', { granularity: 'word' }) : null;
    const words = seg ? Array.from(seg.segment(String(text)), (s) => s.segment) : String(text).split(/(\s+)/);
    const lines = [];
    let line = '';
    for (const w of words) {
        const next = line + w;
        if (ctx.measureText(next).width > maxWidth && line.trim()) {
            lines.push(line.trim());
            line = w.trimStart();
            if (lines.length === maxLines) break;
        } else {
            line = next;
        }
    }
    if (lines.length < maxLines && line.trim()) lines.push(line.trim());
    if (lines.length === maxLines && words.join('').length > lines.join('').length + 1) {
        lines[maxLines - 1] = lines[maxLines - 1].replace(/.{1,2}$/, '') + '…';
    }
    return lines;
}

function roundRectPath(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

function paintCard(ctx, card, img) {
    const W = CARD_W;
    const H = CARD_H;
    const font = 'ui-sans-serif, system-ui, "Noto Sans Thai", "Leelawadee UI", sans-serif';
    ctx.clearRect(0, 0, W, H);
    ctx.save();
    roundRectPath(ctx, 6, 6, W - 12, H - 12, 34);
    ctx.clip();
    const bg = ctx.createLinearGradient(0, 0, 0, H);
    bg.addColorStop(0, '#0e1a36');
    bg.addColorStop(1, '#070d1f');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, W, H);
    // ภาพหัวการ์ด (ครอปแบบ cover)
    const imgH = H * 0.54;
    if (img) {
        const s = Math.max(W / img.width, imgH / img.height);
        const w = img.width * s;
        const h = img.height * s;
        ctx.drawImage(img, (W - w) / 2, (imgH - h) / 2, w, h);
    }
    const fade = ctx.createLinearGradient(0, imgH * 0.45, 0, imgH + 4);
    fade.addColorStop(0, 'rgba(7,13,31,0)');
    fade.addColorStop(1, 'rgba(7,13,31,1)');
    ctx.fillStyle = fade;
    ctx.fillRect(0, imgH * 0.45, W, imgH * 0.56);
    // แถบสีประจำการ์ด
    ctx.fillStyle = card.color;
    ctx.fillRect(40, imgH + 18, 64, 6);
    // ชื่อ
    ctx.fillStyle = '#ffffff';
    ctx.font = `800 46px ${font}`;
    ctx.textBaseline = 'top';
    wrapLines(ctx, card.title, W - 80, 1).forEach((l) => ctx.fillText(l, 40, imgH + 40));
    // คำอธิบาย
    ctx.fillStyle = '#b8c4d8';
    ctx.font = `500 27px ${font}`;
    wrapLines(ctx, card.desc, W - 80, 2).forEach((l, i) => ctx.fillText(l, 40, imgH + 104 + i * 38));
    // ปุ่ม
    const bw = 230;
    const bh = 58;
    const bx = 40;
    const by = H - bh - 36;
    roundRectPath(ctx, bx, by, bw, bh, 29);
    const btn = ctx.createLinearGradient(bx, 0, bx + bw, 0);
    btn.addColorStop(0, card.color);
    btn.addColorStop(1, '#8b5cf6');
    ctx.fillStyle = btn;
    ctx.fill();
    ctx.fillStyle = '#ffffff';
    ctx.font = `700 26px ${font}`;
    ctx.textBaseline = 'middle';
    ctx.fillText(card.cta, bx + 26, by + bh / 2 + 1);
    ctx.restore();
    // ขอบการ์ด
    roundRectPath(ctx, 6, 6, W - 12, H - 12, 34);
    ctx.lineWidth = 5;
    ctx.strokeStyle = card.color;
    ctx.globalAlpha = 0.85;
    ctx.stroke();
    ctx.globalAlpha = 1;
}

/**
 * @param {{ key:string, title:string, desc:string, cta:string, image:string, color:string }[]} cards
 */
export function buildEcosystem(cards) {
    const group = new THREE.Group();
    const items = [];
    const geo = new THREE.PlaneGeometry(3.7, (3.7 * CARD_H) / CARD_W);
    const glowTex = glowTexture();
    cards.forEach((card, i) => {
        const a = -0.66 + (i / Math.max(1, cards.length - 1)) * 1.32;
        const x = Math.sin(a) * 8.8;
        const z = -Math.cos(a) * 8.8 + 7;
        const t = canvasTexture(CARD_W, CARD_H);
        const mat = new THREE.MeshBasicMaterial({ map: t.tex, transparent: true, side: THREE.DoubleSide, toneMapped: false });
        const pivot = new THREE.Group();
        pivot.position.set(x, 3.6, z);
        pivot.lookAt(0, 3.6, 18);
        const mesh = new THREE.Mesh(geo, mat);
        pivot.add(mesh);
        const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: card.color, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
        glow.scale.set(7, 8.5, 1);
        glow.position.z = -0.2;
        glow.raycast = () => {};
        pivot.add(glow);
        group.add(pivot);
        const item = { card, pivot, mesh, glow, tex: t, img: null, hover: 0, tiltX: 0, tiltY: 0, tx: 0, ty: 0, i };
        items.push(item);
        tag(mesh, `eco:${card.key}`);
        paintCard(t.ctx, card, null);
        t.tex.needsUpdate = true;
        // ผ่าน loadImage → นับในหลอดดาวน์โหลดหน้าแรก · ภาพเสีย = การ์ดไม่มีรูป (ข้อความยังอยู่)
        loadImage(card.image).then((img) => {
            if (!img) return;
            item.img = img;
            paintCard(t.ctx, item.card, img);
            t.tex.needsUpdate = true;
        });
    });

    let hovered = null;
    return {
        object: group,
        targets: items.map((it) => ({ object: it.mesh, key: `eco:${it.card.key}` })),
        /** hit.uv = จุดบนการ์ดที่เมาส์ชี้ → เอียงการ์ดเข้าหาจุดนั้น */
        setHover(key, hit) {
            hovered = key;
            const it = items.find((x) => `eco:${x.card.key}` === key);
            if (it && hit?.uv) {
                it.ty = (hit.uv.x - 0.5) * 0.5;
                it.tx = -(hit.uv.y - 0.5) * 0.4;
            }
        },
        /** สลับภาษา: วาดข้อความบนการ์ดใหม่ */
        relabel(next) {
            next.forEach((c) => {
                const it = items.find((x) => x.card.key === c.key);
                if (!it) return;
                it.card = { ...it.card, ...c };
                paintCard(it.tex.ctx, it.card, it.img);
                it.tex.tex.needsUpdate = true;
            });
        },
        update(t, dt) {
            items.forEach((it) => {
                const on = hovered === `eco:${it.card.key}`;
                it.hover = approach(it.hover, on ? 1 : 0, 10, dt);
                if (!on) {
                    it.tx = 0;
                    it.ty = 0;
                }
                it.tiltX = approach(it.tiltX, it.tx, 10, dt);
                it.tiltY = approach(it.tiltY, it.ty, 10, dt);
                it.mesh.position.set(0, Math.sin(t * 1.2 + it.i) * 0.16 + it.hover * 0.35, it.hover * 1.1);
                it.mesh.rotation.set(it.tiltX, it.tiltY, 0);
                it.mesh.scale.setScalar(1 + it.hover * 0.06);
                it.glow.material.opacity = 0.12 + it.hover * 0.55;
            });
        },
    };
}

// ── จุดเด่น: ไอคอน 3D (ภาพจาก ChatGPT) บนแท่นเรืองแสง ─────────────────────
export function buildFeatures(features) {
    const group = new THREE.Group();
    const items = [];
    const baseGeo = new THREE.CylinderGeometry(1.3, 1.5, 0.4, 48);
    const ringGeo = new THREE.TorusGeometry(1.45, 0.05, 8, 64);
    const glowTex = glowTexture();
    features.forEach((f, i) => {
        const x = (i - (features.length - 1) / 2) * 3.9;
        const z = Math.abs(i - (features.length - 1) / 2) * -1.2;
        const unit = new THREE.Group();
        unit.position.set(x, 0, z);
        const base = new THREE.Mesh(baseGeo, new THREE.MeshStandardMaterial({ color: '#0f1d3a', metalness: 0.7, roughness: 0.35 }));
        base.position.y = 0.2;
        const ringMat = new THREE.MeshBasicMaterial({ color: f.color, transparent: true, opacity: 0.85 });
        const ring = new THREE.Mesh(ringGeo, ringMat);
        ring.rotation.x = Math.PI / 2;
        ring.position.y = 0.42;
        const beam = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: f.color, transparent: true, opacity: 0.5, depthWrite: false, blending: THREE.AdditiveBlending }));
        beam.scale.set(3.4, 3.4, 1);
        beam.position.y = 1.1;
        beam.raycast = () => {};
        // ของสำรองระหว่างรอภาพ (หรือถ้าภาพหาย): ทรงเรขาคณิตเรืองแสง
        const fallback = new THREE.Mesh(
            new THREE.OctahedronGeometry(0.9, 0),
            new THREE.MeshStandardMaterial({ color: f.color, emissive: f.color, emissiveIntensity: 0.6, metalness: 0.6, roughness: 0.25, flatShading: true }),
        );
        fallback.position.y = 2.6;
        const icon = new THREE.Sprite(new THREE.SpriteMaterial({ transparent: true, depthWrite: false }));
        icon.scale.set(3, 3, 1);
        icon.position.y = 2.8;
        icon.visible = false;
        // พื้นที่กดทั้งแท่น+ไอคอน
        const hit = new THREE.Mesh(new THREE.CylinderGeometry(1.6, 1.6, 4.6, 16), new THREE.MeshBasicMaterial({ visible: false }));
        hit.position.y = 2.2;
        unit.add(base, ring, beam, fallback, icon, hit);
        group.add(unit);
        tag(hit, `feature:${f.key}`);
        items.push({ key: `feature:${f.key}`, icon, fallback, ring, ringMat, beam, hover: 0, i });
        loadTexture(f.image).then((tex) => {
            if (!tex) return;
            icon.material.map = tex;
            icon.material.needsUpdate = true;
            icon.visible = true;
            fallback.visible = false;
        });
    });
    let hovered = null;
    return {
        object: group,
        targets: items.map((it) => ({ object: group, key: it.key })).slice(0, 0).concat(
            group.children.map((unit, i) => ({ object: unit.children[unit.children.length - 1], key: items[i].key })),
        ),
        setHover(key) {
            hovered = key;
        },
        update(t, dt) {
            items.forEach((it) => {
                it.hover = approach(it.hover, hovered === it.key ? 1 : 0, 10, dt);
                const bob = Math.sin(t * 1.5 + it.i * 0.9) * 0.22 + it.hover * 0.45;
                const s = 3 * (1 + it.hover * 0.22);
                it.icon.scale.set(s, s, 1);
                it.icon.position.y = 2.8 + bob;
                it.fallback.position.y = 2.6 + bob;
                it.fallback.rotation.y = t * (0.8 + it.hover * 3) + it.i;
                it.ring.scale.setScalar(1 + Math.sin(t * 2 + it.i) * 0.04 + it.hover * 0.12);
                it.ringMat.opacity = 0.85;
                it.beam.material.opacity = 0.5 + it.hover * 0.4;
            });
        },
    };
}

// ── ปิดท้าย: ประตูเหรียญทอง + ลำแสง + ประกายทองลอยขึ้น ───────────────────
export function buildGate() {
    const group = new THREE.Group();
    const ringMat = new THREE.MeshStandardMaterial({ color: '#f0b429', metalness: 1, roughness: 0.25, emissive: '#000000' });
    const ring = new THREE.Mesh(new THREE.TorusGeometry(5.2, 0.42, 24, 128), ringMat);
    ring.position.y = 5.8;
    group.add(ring);
    const disc = new THREE.Mesh(
        new THREE.CircleGeometry(4.9, 96),
        new THREE.MeshBasicMaterial({ color: '#22d3ee', transparent: true, opacity: 0.18, side: THREE.DoubleSide, depthWrite: false, blending: THREE.AdditiveBlending }),
    );
    disc.position.y = 5.8;
    group.add(disc);
    const beam = new THREE.Mesh(
        new THREE.CylinderGeometry(4.6, 4.6, 40, 64, 1, true),
        new THREE.MeshBasicMaterial({ color: '#5ee7ff', transparent: true, opacity: 0.06, side: THREE.DoubleSide, depthWrite: false, blending: THREE.AdditiveBlending }),
    );
    beam.position.y = 20;
    beam.raycast = () => {};
    group.add(beam);

    const N = 220;
    const pos = new Float32Array(N * 3);
    const seeds = [];
    for (let i = 0; i < N; i++) {
        const a = Math.random() * Math.PI * 2;
        const r = Math.random() * 5;
        seeds.push({ x: Math.cos(a) * r, z: Math.sin(a) * r, s: 0.6 + Math.random() * 1.4, o: Math.random() * 14 });
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    const sparks = new THREE.Points(geo, new THREE.PointsMaterial({ map: glowTexture(), color: '#ffd76a', size: 0.35, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    sparks.raycast = () => {};
    group.add(sparks);

    let hover = 0;
    let hovered = false;
    return {
        object: group,
        targets: [tag(disc, 'gate'), tag(ring, 'gate')],
        setHover(key) {
            hovered = key === 'gate';
        },
        update(t, dt, focus) {
            hover = approach(hover, hovered ? 1 : 0, 8, dt);
            ring.rotation.z = t * (0.25 + hover * 1.2);
            ringMat.emissive.setRGB(0.3 * hover, 0.2 * hover, 0);
            disc.material.opacity = 0.12 + Math.sin(t * 2) * 0.05 + focus * 0.1 + hover * 0.25;
            if (focus <= 0) return;
            seeds.forEach((sd, i) => {
                pos[i * 3] = sd.x;
                pos[i * 3 + 1] = (t * sd.s * (1 + hover * 1.5) + sd.o) % 14;
                pos[i * 3 + 2] = sd.z;
            });
            geo.attributes.position.needsUpdate = true;
        },
    };
}
