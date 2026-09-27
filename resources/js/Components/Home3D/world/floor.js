/**
 * TPIX TRADE — พื้นกระดานเทรด (กริดเรืองแสง) + เมืองแท่งกราฟสองข้างทาง
 *
 * พื้น: shader เส้นกริด 1 หน่วย/5 หน่วย จางตามระยะจากกล้อง + แสงสแกนวิ่งเหมือนจอรีเฟรช
 * เมือง: แท่งกราฟเขียว/แดงหลายร้อยแท่ง (InstancedMesh ชุดเดียว = draw call เดียว)
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';
import { C, glowingStandard } from './common.js';

export function buildFloor() {
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
