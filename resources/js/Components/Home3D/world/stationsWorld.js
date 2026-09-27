/**
 * TPIX TRADE — วัตถุ 3D ของแต่ละสถานี (นอกจากกำแพงแท่งเทียน)
 *
 * ทุกฟังก์ชันคืน { object, update(t, dt, focus), ... }
 * focus = 0..1 ความใกล้ของกล้อง (1 = อยู่ที่สถานีนี้) ใช้เปิดแอนิเมชันเฉพาะตอนดูอยู่
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { C, clamp, smoothstep, glowTexture, coinFaceTexture, createLabel, loadTexture } from './common.js';

// ── เหรียญ TPIX ทอง (โลหะจริง สะท้อน environment) ─────────────────────────
export function buildCoin({ radius = 2.6, thickness = 0.36 } = {}) {
    const face = coinFaceTexture(512);
    const side = new THREE.MeshStandardMaterial({ color: '#e2a92b', metalness: 1, roughness: 0.28 });
    const faceMat = new THREE.MeshStandardMaterial({ map: face, metalness: 0.85, roughness: 0.32 });
    const geo = new THREE.CylinderGeometry(radius, radius, thickness, 96);
    const coin = new THREE.Mesh(geo, [side, faceMat, faceMat]);
    coin.rotation.x = Math.PI / 2;
    const rimGeo = new THREE.TorusGeometry(radius, thickness * 0.16, 10, 96);
    const rim = new THREE.Mesh(rimGeo, side);
    coin.add(rim);
    rim.rotation.x = Math.PI / 2;

    const group = new THREE.Group();
    group.add(coin);
    const glowTex = glowTexture('rgba(255,210,110,1)');
    const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: '#ffcf5a', transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
    halo.scale.setScalar(radius * 4.2);
    group.add(halo);

    return {
        object: group,
        update(t) {
            coin.rotation.z = t * 0.6;
            group.position.y = Math.sin(t * 1.1) * 0.25;
            halo.material.opacity = 0.28 + Math.sin(t * 2) * 0.07;
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
    edges.forEach(([a, b], i) => {
        pos.set([a.v.x, a.v.y, a.v.z, b.v.x, b.v.y, b.v.z], i * 6);
    });
    const lineGeo = new THREE.BufferGeometry();
    lineGeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    group.add(new THREE.LineSegments(lineGeo, new THREE.LineBasicMaterial({ color: '#5ee7ff', transparent: true, opacity: 0.4 })));

    // บล็อก (จุดเรืองแสง) วิ่งจากขอบเข้าหา validator
    const PULSES = 36;
    const pulsePos = new Float32Array(PULSES * 3);
    const pulseGeo = new THREE.BufferGeometry();
    pulseGeo.setAttribute('position', new THREE.BufferAttribute(pulsePos, 3));
    const pulseTex = glowTexture();
    const pulses = new THREE.Points(pulseGeo, new THREE.PointsMaterial({ map: pulseTex, color: '#fff3b0', size: 0.55, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    group.add(pulses);
    const seeds = Array.from({ length: PULSES }, (_, i) => ({ e: (i * 7) % edges.length, o: (i * 0.137) % 1 }));

    // แกนกลาง = บล็อกเชนหมุน
    const core = new THREE.Mesh(
        new THREE.IcosahedronGeometry(0.8, 1),
        new THREE.MeshStandardMaterial({ color: '#f6bd35', emissive: '#f6bd35', emissiveIntensity: 0.6, metalness: 0.8, roughness: 0.25, flatShading: true }),
    );
    core.position.y = 4.6;
    core.scale.setScalar(0.8);
    group.add(core);

    return {
        object: group,
        update(t, dt, focus) {
            group.rotation.y = t * 0.08;
            core.rotation.set(t * 0.4, t * 0.6, 0);
            if (focus <= 0) return;
            seeds.forEach((sd, i) => {
                const [a, b] = edges[sd.e];
                const k = (t * 0.35 + sd.o) % 1;
                pulsePos[i * 3] = a.v.x + (b.v.x - a.v.x) * k;
                pulsePos[i * 3 + 1] = a.v.y + (b.v.y - a.v.y) * k;
                pulsePos[i * 3 + 2] = a.v.z + (b.v.z - a.v.z) * k;
            });
            pulseGeo.attributes.position.needsUpdate = true;
        },
    };
}

// ── ตลาด: เสาราคาเหรียญยอดนิยม (ข้อมูลจริง) ─────────────────────────────
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
        pillars.push({ mesh, mat, label, h: 1, target: 1, x, z });
    }

    function setTickers(list) {
        // list: [{ symbol, price, change, isUp, rawChange, rawVolume }]
        const rows = (list || []).slice(0, slots);
        const maxVol = Math.max(1, ...rows.map((r) => r.rawVolume || 0));
        pillars.forEach((pl, i) => {
            const r = rows[i];
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

    return {
        object: group,
        setTickers,
        update(t, dt, focus) {
            pillars.forEach((pl, i) => {
                const goal = pl.target * (0.25 + 0.75 * smoothstep(0, 0.8, focus));
                pl.h += (goal - pl.h) * (1 - Math.exp(-4 * dt));
                pl.mesh.scale.y = Math.max(0.01, pl.h);
                pl.label.sprite.position.y = pl.h + 1.1 + Math.sin(t * 1.4 + i) * 0.1;
            });
        },
    };
}

// ── รอบขาย: กองเหรียญทอง 3 กอง (Private / Pre-Sale / Public) ────────────
export function buildSale() {
    const group = new THREE.Group();
    const stacks = [
        { label: 'Private', price: '$0.05', n: 7, x: -4.8 },
        { label: 'Pre-Sale', price: '$0.08', n: 11, x: 0 },
        { label: 'Public', price: '$0.10', n: 15, x: 4.8 },
    ];
    const total = stacks.reduce((a, s) => a + s.n, 0);
    const coinGeo = new THREE.CylinderGeometry(1.25, 1.25, 0.26, 48);
    const coinMat = new THREE.MeshStandardMaterial({ color: '#f0b429', metalness: 1, roughness: 0.3 });
    const mesh = new THREE.InstancedMesh(coinGeo, coinMat, total);
    mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    group.add(mesh);
    const coins = [];
    stacks.forEach((s) => {
        for (let i = 0; i < s.n; i++) {
            coins.push({ x: s.x + (Math.random() - 0.5) * 0.14, y: 0.13 + i * 0.28, z: (Math.random() - 0.5) * 0.14, rot: Math.random() * 6, delay: i * 0.05 + Math.abs(s.x) * 0.02 });
        }
        const label = createLabel({ width: 420, height: 150, scale: 3 });
        label.set([
            { text: s.label, size: 44, color: '#fcd34d' },
            { text: s.price, size: 48, color: '#ffffff', font: 'ui-monospace, Menlo, monospace' },
        ], { border: '#f6bd35' });
        label.sprite.position.set(s.x, 0.4 + s.n * 0.28 + 1.3, 0);
        group.add(label.sprite);
    });
    const m = new THREE.Matrix4();
    const q = new THREE.Quaternion();
    const e = new THREE.Euler();
    const v = new THREE.Vector3();
    const one = new THREE.Vector3();
    let built = 0; // 0..1 เหรียญร่วงลงมาเรียงกอง

    return {
        object: group,
        update(t, dt, focus) {
            if (focus > 0.3) built = Math.min(1, built + dt / 2.2);
            coins.forEach((c, i) => {
                const k = clamp((built * 1.6 - c.delay) / 0.6, 0, 1);
                const drop = (1 - k) * (1 - k) * 9;
                v.set(c.x, c.y + drop, c.z);
                e.set(0, c.rot + (1 - k) * 6 + t * 0.05, (1 - k) * 1.2);
                q.setFromEuler(e);
                const sz = k > 0 ? 1 : 0.0001;
                mesh.setMatrixAt(i, m.compose(v, q, one.set(sz, sz, sz)));
            });
            mesh.instanceMatrix.needsUpdate = true;
        },
    };
}

// ── ระบบนิเวศ: การ์ดภาพลอยเป็นโค้ง กดแล้วพาไปหน้านั้น ───────────────────
export function buildEcosystem(cards) {
    const group = new THREE.Group();
    const items = [];
    const geo = new THREE.PlaneGeometry(3.4, 4.4);
    cards.forEach((card, i) => {
        const a = -0.66 + (i / Math.max(1, cards.length - 1)) * 1.32;
        const x = Math.sin(a) * 8.5;
        const z = -Math.cos(a) * 8.5 + 7;
        const mat = new THREE.MeshBasicMaterial({ color: '#1e293b', transparent: true, opacity: 0.96, side: THREE.DoubleSide });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.set(x, 3.4, z);
        mesh.lookAt(0, 3.4, 18);
        mesh.userData.href = card.href;
        const frame = new THREE.LineSegments(
            new THREE.EdgesGeometry(geo),
            new THREE.LineBasicMaterial({ color: card.color || '#22d3ee', transparent: true, opacity: 0.8 }),
        );
        mesh.add(frame);
        const label = createLabel({ width: 480, height: 110, scale: 3.2, accent: card.color });
        label.set([{ text: card.title, size: 46 }], { border: card.color });
        label.sprite.position.set(0, -2.75, 0.05);
        mesh.add(label.sprite);
        group.add(mesh);
        items.push({ mesh, mat, frame, label, base: mesh.position.clone(), hover: 0, i });
        loadTexture(card.image).then((tex) => {
            if (!tex) return;
            mat.map = tex;
            mat.color.set('#ffffff');
            mat.needsUpdate = true;
        });
    });

    let hovered = null;
    return {
        object: group,
        meshes: items.map((it) => it.mesh),
        /** คืน href ของการ์ดที่ชี้อยู่ (หรือ null) */
        pick(raycaster) {
            const hit = raycaster.intersectObjects(items.map((it) => it.mesh), false)[0];
            hovered = hit ? items.find((it) => it.mesh === hit.object) : null;
            return hovered?.mesh.userData.href ?? null;
        },
        clearHover() {
            hovered = null;
        },
        update(t, dt) {
            items.forEach((it) => {
                it.hover += ((hovered === it ? 1 : 0) - it.hover) * (1 - Math.exp(-10 * dt));
                it.mesh.position.y = it.base.y + Math.sin(t * 1.2 + it.i) * 0.18 + it.hover * 0.35;
                it.mesh.scale.setScalar(1 + it.hover * 0.08);
                it.frame.material.opacity = 0.55 + it.hover * 0.45;
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
        const base = new THREE.Mesh(baseGeo, new THREE.MeshStandardMaterial({ color: '#0f1d3a', metalness: 0.7, roughness: 0.35 }));
        base.position.set(x, 0.2, z);
        const ring = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color: f.color }));
        ring.rotation.x = Math.PI / 2;
        ring.position.set(x, 0.42, z);
        const beam = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: f.color, transparent: true, opacity: 0.5, depthWrite: false, blending: THREE.AdditiveBlending }));
        beam.scale.set(3.4, 3.4, 1);
        beam.position.set(x, 1.1, z);
        // ของสำรองระหว่างรอภาพ (หรือถ้าภาพหาย): ทรงเรขาคณิตเรืองแสง
        const fallback = new THREE.Mesh(
            new THREE.OctahedronGeometry(0.9, 0),
            new THREE.MeshStandardMaterial({ color: f.color, emissive: f.color, emissiveIntensity: 0.6, metalness: 0.6, roughness: 0.25, flatShading: true }),
        );
        fallback.position.set(x, 2.6, z);
        const icon = new THREE.Sprite(new THREE.SpriteMaterial({ transparent: true, depthWrite: false }));
        icon.scale.set(3, 3, 1);
        icon.position.set(x, 2.8, z);
        icon.visible = false;
        group.add(base, ring, beam, fallback, icon);
        items.push({ icon, fallback, ring, x, z, i });
        loadTexture(f.image).then((tex) => {
            if (!tex) return;
            icon.material.map = tex;
            icon.material.needsUpdate = true;
            icon.visible = true;
            fallback.visible = false;
        });
    });
    return {
        object: group,
        update(t) {
            items.forEach((it) => {
                const bob = Math.sin(t * 1.5 + it.i * 0.9) * 0.22;
                it.icon.position.y = 2.8 + bob;
                it.fallback.position.y = 2.6 + bob;
                it.fallback.rotation.y = t * 0.8 + it.i;
                it.ring.scale.setScalar(1 + Math.sin(t * 2 + it.i) * 0.04);
            });
        },
    };
}

// ── ปิดท้าย: ประตูเหรียญทอง + ลำแสง + ประกายทองลอยขึ้น ───────────────────
export function buildGate() {
    const group = new THREE.Group();
    const ring = new THREE.Mesh(
        new THREE.TorusGeometry(5.2, 0.42, 24, 128),
        new THREE.MeshStandardMaterial({ color: '#f0b429', metalness: 1, roughness: 0.25 }),
    );
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
    const tex = glowTexture();
    const sparks = new THREE.Points(geo, new THREE.PointsMaterial({ map: tex, color: '#ffd76a', size: 0.35, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    group.add(sparks);

    return {
        object: group,
        update(t, dt, focus) {
            ring.rotation.z = t * 0.25;
            disc.material.opacity = 0.12 + Math.sin(t * 2) * 0.05 + focus * 0.1;
            if (focus <= 0) return;
            seeds.forEach((sd, i) => {
                pos[i * 3] = sd.x;
                pos[i * 3 + 1] = (t * sd.s + sd.o) % 14;
                pos[i * 3 + 2] = sd.z;
            });
            geo.attributes.position.needsUpdate = true;
        },
    };
}
