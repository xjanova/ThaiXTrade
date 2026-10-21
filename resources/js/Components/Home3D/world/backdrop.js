/**
 * TPIX TRADE — ฉากหลังไกลแบบในเกม (skybox)
 *
 * สองชั้น ตามกล้องไปตลอด (ไม่มี parallax) จึงดูไกลสุดลูกหูลูกตาเหมือนฉากหลังในเกม:
 *   1. ท้องฟ้าไล่สี — กรมท่าเข้มด้านบน → แสงฟ้า/ม่วงจาง ๆ ที่ขอบฟ้า (shader ไม่ต้องโหลดรูป)
 *   2. พาโนรามาเมืองการเงินยามค่ำ (ภาพ ChatGPT 16:9) พันรอบทรงกระบอก 4 รอบแบบสะท้อนกลับ
 *      (MirroredRepeat) — รอยต่อจึงไม่เห็น · ภาพออกแบบให้ขอบฟ้าตรงและซ้าย/ขวาหนาแน่นเท่ากัน
 *
 * ไม่ใช้หมอกของฉาก (fog: false) — ฉากหลังต้องโผล่พ้นหมอก ส่วนความ "ไกล/ขมุกขมัว" ฝังอยู่ในภาพแล้ว
 * ธีมกระดานเทรด ไม่ใช่อวกาศ (เจ้าของกำหนด 2026-09-27) → ไม่มีดาว/ดาวเคราะห์
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { loadTexture } from './common.js';

// เปลี่ยนภาพชื่อเดิมต้องบวกเลขนี้ — Cloudflare แคชรูปนาน 1 ปี
const BACKDROP_URL = '/images/home3d/backdrop.webp?v=1';
// ขอบฟ้าในภาพอยู่ที่ 42.7% นับจากล่าง (วัดจากแถวที่สว่างที่สุด)
const HORIZON_V = 0.427;
const RADIUS = 300;
const REPEAT = 4;
// ภาพ 16:9 หนึ่งรอบกินมุม 90° → สูงเท่ากับ (เส้นรอบวง/4) × 9/16 จึงไม่ยืดสัดส่วน
const HEIGHT = ((2 * Math.PI * RADIUS) / REPEAT) * (9 / 16);

export function buildBackdrop() {
    const group = new THREE.Group();

    const skyMat = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        depthWrite: false,
        fog: false,
        uniforms: {
            uTop: { value: new THREE.Color('#02050f') },
            uMid: { value: new THREE.Color('#071233') },
            uGlow: { value: new THREE.Color('#1e3a8a') },
            uDim: { value: 0 },
        },
        vertexShader: /* glsl */ `
            varying vec3 vDir;
            void main() {
                vDir = normalize(position);
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: /* glsl */ `
            uniform vec3 uTop;
            uniform vec3 uMid;
            uniform vec3 uGlow;
            uniform float uDim;
            varying vec3 vDir;
            void main() {
                float h = vDir.y;
                vec3 col = mix(uMid, uTop, smoothstep(0.05, 0.6, h));
                // แสงขอบฟ้าบาง ๆ เหนือเส้นขอบฟ้า
                col += uGlow * exp(-abs(h - 0.02) * 18.0) * 0.55;
                // ใต้ขอบฟ้ามืดลง (พื้นสะท้อนของเราอยู่ตรงนั้น)
                col *= mix(1.0, 0.35, smoothstep(0.0, -0.25, h));
                col *= 1.0 - uDim * 0.85;
                gl_FragColor = vec4(col, 1.0);
                #include <colorspace_fragment>
            }
        `,
    });
    const sky = new THREE.Mesh(new THREE.SphereGeometry(RADIUS + 40, 32, 16), skyMat);
    sky.renderOrder = -20;
    sky.raycast = () => {};

    const panoMat = new THREE.MeshBasicMaterial({
        side: THREE.BackSide,
        transparent: true,
        opacity: 0,
        depthWrite: false,
        fog: false,
        toneMapped: false,
    });
    const pano = new THREE.Mesh(new THREE.CylinderGeometry(RADIUS, RADIUS, HEIGHT, 96, 1, true), panoMat);
    pano.renderOrder = -19;
    pano.raycast = () => {};

    group.add(sky, pano);

    let ready = false;
    loadTexture(BACKDROP_URL).then((tex) => {
        if (!tex) return; // ภาพหาย → เหลือท้องฟ้าไล่สีอย่างเดียว ไม่พัง
        tex.colorSpace = THREE.SRGBColorSpace;
        tex.wrapS = THREE.MirroredRepeatWrapping;
        tex.repeat.set(REPEAT, 1);
        tex.anisotropy = 4;
        panoMat.map = tex;
        panoMat.needsUpdate = true;
        ready = true;
    });

    let fadeIn = 0;
    return {
        object: group,
        /**
         * ตามกล้องทุกเฟรม — ขอบฟ้าของภาพอยู่ต่ำกว่าระดับสายตาเล็กน้อย (เหมือนมองเมืองจากที่สูง)
         * @param {THREE.Camera} camera
         */
        update(camera, dt, dim = 0) {
            sky.position.copy(camera.position);
            const horizonY = camera.position.y - 5;
            pano.position.set(camera.position.x, horizonY + (0.5 - HORIZON_V) * HEIGHT, camera.position.z);
            if (ready) fadeIn = Math.min(1, fadeIn + dt * 1.5);
            panoMat.opacity = fadeIn * (1 - dim * 0.9);
            skyMat.uniforms.uDim.value = dim;
        },
    };
}
