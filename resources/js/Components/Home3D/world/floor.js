/**
 * TPIX TRADE — พื้นกระดานเทรด + เมืองแท่งกราฟสองข้างทาง
 *
 * พื้น (เครื่องแรง): กระจกดำมันวาวสะท้อนฉาก (Reflector) — เงาเบลอนุ่มแบบพื้นขัดเงาในเกม ไม่ใช่กระจกใสแจ๋ว
 *   จางลงตามระยะ + เส้นกริด 1/5 หน่วยและแสงสแกนเดิมวาดทับใน shader เดียวกัน (draw call เดียว)
 *   ความละเอียดภาพสะท้อน = ครึ่งหนึ่งของจอ และลดตามขั้นคุณภาพของ engine อัตโนมัติ
 * พื้น (มือถือ/เครื่องอ่อน): เส้นกริดโปร่งใสแบบเดิม — ภาพสะท้อนต้องวาดฉากซ้ำอีกรอบ หนักเกินไป
 * เมือง: แท่งกราฟเขียว/แดงหลายร้อยแท่ง (InstancedMesh ชุดเดียว = draw call เดียว)
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { Reflector } from 'three/examples/jsm/objects/Reflector.js';
import { C, glowingStandard } from './common.js';

const FLOOR_W = 260;
const FLOOR_D = 420;

// ส่วน shader ที่ใช้ร่วม: เส้นกริด + แสงสแกน (เหมือนพื้นเดิมทุกอย่าง)
const GRID_GLSL = /* glsl */ `
    float grid(vec2 p, float w) {
        vec2 g = abs(fract(p - 0.5) - 0.5) / fwidth(p);
        return 1.0 - min(min(g.x, g.y) / w, 1.0);
    }
    float gridAlpha(vec3 world, float t, float dim, out float scan) {
        vec2 p = world.xz;
        float g1 = grid(p * 0.5, 1.0);
        float g2 = grid(p * 0.1, 1.3);
        float d = distance(world.xz, cameraPosition.xz);
        float fade = smoothstep(70.0, 8.0, d);
        scan = smoothstep(1.4, 0.0, abs(mod(p.y + t * 9.0, 60.0) - 30.0));
        return (g1 * 0.35 + g2 * 0.8) * fade * (0.26 + scan * 0.5) * (1.0 - dim);
    }
`;

const MIRROR_SHADER = {
    name: 'TpixMirrorFloor',
    uniforms: {
        color: { value: null },
        tDiffuse: { value: null },
        textureMatrix: { value: null },
        uTime: { value: 0 },
        uDim: { value: 0 },
        uGrid: { value: new THREE.Color('#22d3ee') },
        uBase: { value: new THREE.Color('#03060f') },
        // ปลายพื้นกลืนเข้าสีหมอกของฉาก (world/index.js · FOG_TINT)
        uSky: { value: new THREE.Color('#0b1538') },
        uTexel: { value: new THREE.Vector2(1 / 512, 1 / 512) },
        uStrength: { value: 0.55 },
    },
    vertexShader: /* glsl */ `
        uniform mat4 textureMatrix;
        varying vec4 vUv;
        varying vec3 vWorld;
        void main() {
            vUv = textureMatrix * vec4(position, 1.0);
            vec4 w = modelMatrix * vec4(position, 1.0);
            vWorld = w.xyz;
            gl_Position = projectionMatrix * viewMatrix * w;
        }
    `,
    fragmentShader: /* glsl */ `
        uniform sampler2D tDiffuse;
        uniform float uTime;
        uniform float uDim;
        uniform vec3 uGrid;
        uniform vec3 uBase;
        uniform vec3 uSky;
        uniform vec2 uTexel;
        uniform float uStrength;
        varying vec4 vUv;
        varying vec3 vWorld;
        ${GRID_GLSL}
        void main() {
            vec2 uv = vUv.xy / vUv.w;
            float d = distance(vWorld.xz, cameraPosition.xz);
            // ยิ่งไกลยิ่งเบลอ = พื้นขัดเงา ไม่ใช่กระจก
            float r = 1.5 + d * 0.06;
            vec3 refl = texture2D(tDiffuse, uv).rgb * 0.2;
            refl += texture2D(tDiffuse, uv + vec2( 1.0,  0.0) * uTexel * r).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2(-1.0,  0.0) * uTexel * r).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2( 0.0,  1.0) * uTexel * r).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2( 0.0, -1.0) * uTexel * r).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2( 0.7,  0.7) * uTexel * r * 1.6).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2(-0.7,  0.7) * uTexel * r * 1.6).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2( 0.7, -0.7) * uTexel * r * 1.6).rgb * 0.1;
            refl += texture2D(tDiffuse, uv + vec2(-0.7, -0.7) * uTexel * r * 1.6).rgb * 0.1;
            float nearK = smoothstep(120.0, 6.0, d);
            vec3 col = uBase + refl * uStrength * (0.3 + 0.7 * nearK);
            float scan;
            float a = gridAlpha(vWorld, uTime, uDim, scan);
            col += uGrid * (1.0 + scan) * a;
            // ปลายพื้นกลืนเข้าหมอก/ขอบฟ้า
            col = mix(uSky, col, smoothstep(170.0, 60.0, d));
            col *= 1.0 - uDim * 0.6;
            gl_FragColor = vec4(col, 1.0);
            #include <tonemapping_fragment>
            #include <colorspace_fragment>
        }
    `,
};

/**
 * @param {{ renderer?: THREE.WebGLRenderer, reflect?: boolean }} opts
 */
export function buildFloor({ renderer = null, reflect = false, quality = () => 0 } = {}) {
    if (reflect && renderer) return buildMirrorFloor(renderer, quality);
    return buildGridFloor();
}

// ขั้นคุณภาพของ engine ที่เริ่มงดวาดภาพสะท้อน (ขั้นต่ำสุด) — ปิดเงาก่อน ดีกว่าโดนสลับไปหน้าธรรมดา
const MIRROR_OFF_LEVEL = 3;

function buildMirrorFloor(renderer, quality) {
    const size = new THREE.Vector2();
    const rtSize = () => {
        renderer.getDrawingBufferSize(size);
        return [Math.max(256, Math.round(size.x * 0.5)), Math.max(256, Math.round(size.y * 0.5))];
    };
    const [w0, h0] = rtSize();
    const mirror = new Reflector(new THREE.PlaneGeometry(FLOOR_W, FLOOR_D), {
        textureWidth: w0,
        textureHeight: h0,
        clipBias: 0.003,
        multisample: 0,
        color: '#ffffff',
        shader: MIRROR_SHADER,
    });
    mirror.rotation.x = -Math.PI / 2;
    mirror.position.set(0, 0, -130);
    mirror.renderOrder = -2;
    mirror.raycast = () => {};
    const u = mirror.material.uniforms;
    u.uTexel.value.set(1 / w0, 1 / h0);
    const renderReflection = mirror.onBeforeRender;
    let mirrorOn = true;
    mirror.onBeforeRender = function (...args) {
        if (mirrorOn) renderReflection.apply(this, args);
    };
    let lastW = w0;
    let lastH = h0;
    return {
        object: mirror,
        reflective: true,
        update(t, dim = 0) {
            u.uTime.value = t;
            u.uDim.value = dim;
            mirrorOn = quality() < MIRROR_OFF_LEVEL;
            u.uStrength.value = mirrorOn ? 0.55 : 0;
            // engine ลด/เพิ่มคุณภาพ (pixel ratio) หรือหน้าต่างเปลี่ยนขนาด → ภาพสะท้อนตาม
            const [w, h] = rtSize();
            if (w !== lastW || h !== lastH) {
                mirror.getRenderTarget().setSize(w, h);
                u.uTexel.value.set(1 / w, 1 / h);
                lastW = w;
                lastH = h;
            }
        },
        dispose() {
            mirror.dispose();
        },
    };
}

function buildGridFloor() {
    const mat = new THREE.ShaderMaterial({
        transparent: true,
        depthWrite: false,
        uniforms: {
            uTime: { value: 0 },
            uColor: { value: new THREE.Color('#22d3ee') },
            uDim: { value: 0 },
        },
        vertexShader: /* glsl */ `
            varying vec3 vWorld;
            void main() {
                vec4 w = modelMatrix * vec4(position, 1.0);
                vWorld = w.xyz;
                gl_Position = projectionMatrix * viewMatrix * w;
            }
        `,
        fragmentShader: /* glsl */ `
            uniform float uTime;
            uniform vec3 uColor;
            uniform float uDim;
            varying vec3 vWorld;
            float grid(vec2 p, float w) {
                vec2 g = abs(fract(p - 0.5) - 0.5) / fwidth(p);
                return 1.0 - min(min(g.x, g.y) / w, 1.0);
            }
            void main() {
                vec2 p = vWorld.xz;
                float g1 = grid(p * 0.5, 1.0);
                float g2 = grid(p * 0.1, 1.3);
                float d = distance(vWorld.xz, cameraPosition.xz);
                float fade = smoothstep(70.0, 8.0, d);
                float scan = smoothstep(1.4, 0.0, abs(mod(p.y + uTime * 9.0, 60.0) - 30.0));
                float a = (g1 * 0.35 + g2 * 0.8) * fade * (0.26 + scan * 0.5) * (1.0 - uDim);
                gl_FragColor = vec4(uColor * (1.0 + scan), a);
                #include <colorspace_fragment>
            }
        `,
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(260, 420), mat);
    mesh.rotation.x = -Math.PI / 2;
    mesh.position.set(0, 0, -130);
    mesh.renderOrder = -2;
    return {
        object: mesh,
        update(t, dim = 0) {
            mat.uniforms.uTime.value = t;
            mat.uniforms.uDim.value = dim;
        },
    };
}

/** เมืองแท่งกราฟ — วางตามแนวทางเดินทั้งสองข้าง เว้นช่องกลางให้กล้องบิน */
export function buildCity(stations, seed = 7) {
    let s = seed;
    const rand = () => {
        s = (s * 16807) % 2147483647;
        return (s - 1) / 2147483646;
    };

    const spots = [];
    for (let z = 30; z > -300; z -= 2.6) {
        // จุดกลางทางเดินที่ z นี้ (ประมาณจากสถานีใกล้สุด)
        const near = stations.reduce((a, b) => (Math.abs(b.pos[2] - z) < Math.abs(a.pos[2] - z) ? b : a));
        const cx = near.pos[0] * 0.6;
        for (const side of [-1, 1]) {
            if (rand() < 0.25) continue;
            const x = cx + side * (34 + rand() * 38);
            spots.push([x, z + (rand() - 0.5) * 2]);
        }
    }

    const geo = new THREE.BoxGeometry(1, 1, 1);
    geo.translate(0, 0.5, 0);
    const mat = glowingStandard({ boost: 0.28, roughness: 0.5, transparent: true, opacity: 0.9 });
    const mesh = new THREE.InstancedMesh(geo, mat, spots.length);
    const m = new THREE.Matrix4();
    const col = new THREE.Color();
    const heights = [];
    spots.forEach(([x, z], i) => {
        // ความสูงแบบเดินสุ่ม ให้ดูเป็นกราฟต่อเนื่อง ไม่ใช่เสาสุ่มกระจัดกระจาย
        const h = 1 + Math.abs(Math.sin(z * 0.07 + x * 0.03)) * 9 + rand() * 5;
        heights.push(h);
        m.compose(new THREE.Vector3(x, 0, z), new THREE.Quaternion(), new THREE.Vector3(1.3, h, 1.3));
        mesh.setMatrixAt(i, m);
        col.copy(rand() < 0.58 ? C.green : C.red).multiplyScalar(0.55 + rand() * 0.35);
        mesh.setColorAt(i, col);
    });
    mesh.instanceMatrix.needsUpdate = true;
    mesh.instanceColor.needsUpdate = true;

    return { object: mesh };
}
