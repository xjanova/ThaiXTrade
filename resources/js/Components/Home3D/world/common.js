/**
 * TPIX TRADE — ของใช้ร่วมของโลก 3D: สี, texture จาก canvas, ป้ายข้อความ, easing
 *
 * Developed by Xman Studio
 */

import * as THREE from 'three';

export const C = {
    green: new THREE.Color('#00c853'),
    red: new THREE.Color('#ff1744'),
    cyan: new THREE.Color('#22d3ee'),
    gold: new THREE.Color('#f6bd35'),
    violet: new THREE.Color('#8b5cf6'),
    white: new THREE.Color('#ffffff'),
};

export const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
export const smoothstep = (a, b, x) => {
    const t = clamp((x - a) / (b - a), 0, 1);
    return t * t * (3 - 2 * t);
};
export const easeOutBack = (x) => {
    if (x <= 0) return 0;
    if (x >= 1) return 1;
    const c1 = 1.4;
    return 1 + (c1 + 1) * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2);
};

export function canvasTexture(w, h, paint) {
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    paint?.(ctx, w, h);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;
    return { canvas, ctx, tex };
}

/** จุดเรืองแสงกลม (ใช้กับ sprite/points) */
export function glowTexture(inner = 'rgba(255,255,255,1)') {
    return canvasTexture(64, 64, (ctx) => {
        const g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
        g.addColorStop(0, inner);
        g.addColorStop(0.35, 'rgba(255,255,255,0.45)');
        g.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, 64, 64);
    }).tex;
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
}

/**
 * ป้ายข้อความลอย (sprite) — วาดใหม่ได้ด้วย set(lines)
 * lines: [{ text, color, size, weight, font }]
 */
export function createLabel({ width = 512, height = 160, scale = 3.2, accent = '#22d3ee' } = {}) {
    const t = canvasTexture(width, height);
    const mat = new THREE.SpriteMaterial({ map: t.tex, transparent: true, depthWrite: false, fog: false });
    const sprite = new THREE.Sprite(mat);
    sprite.scale.set(scale, (scale * height) / width, 1);
    sprite.renderOrder = 5;

    function set(lines, opts = {}) {
        const { ctx } = t;
        ctx.clearRect(0, 0, width, height);
        const bg = opts.bg ?? 'rgba(6, 14, 34, 0.82)';
        const border = opts.border ?? accent;
        roundRect(ctx, 6, 6, width - 12, height - 12, 22);
        ctx.fillStyle = bg;
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = border;
        ctx.globalAlpha = 0.75;
        ctx.stroke();
        ctx.globalAlpha = 1;
        const gap = (height - 12) / (lines.length + 1);
        lines.forEach((l, i) => {
            ctx.fillStyle = l.color ?? '#ffffff';
            ctx.font = `${l.weight ?? 700} ${l.size ?? 44}px ${l.font ?? 'ui-sans-serif, system-ui, "Noto Sans Thai", sans-serif'}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(l.text), width / 2, 6 + gap * (i + 1));
        });
        t.tex.needsUpdate = true;
    }

    return {
        sprite,
        set,
        dispose() {
            t.tex.dispose();
            mat.dispose();
        },
    };
}

/** วัสดุที่เรืองแสงตามสีของ instance (แท่งเทียน/แท่งตึก) */
export function glowingStandard({ boost = 0.45, ...params } = {}) {
    const mat = new THREE.MeshStandardMaterial({ roughness: 0.35, metalness: 0.15, ...params });
    mat.onBeforeCompile = (shader) => {
        shader.uniforms.uBoost = { value: boost };
        mat.userData.shader = shader;
        shader.fragmentShader = shader.fragmentShader
            .replace('#include <common>', '#include <common>\nuniform float uBoost;')
            .replace(
                '#include <emissivemap_fragment>',
                '#include <emissivemap_fragment>\n#ifdef USE_COLOR\n totalEmissiveRadiance += vColor.rgb * uBoost;\n#endif',
            );
    };
    mat.customProgramCacheKey = () => `glow-${boost}`;
    return mat;
}

let logoPromise = null;
/** โลโก้ TPIX (ใช้บนหน้าเหรียญ) */
export function loadLogo() {
    if (!logoPromise) {
        logoPromise = new Promise((resolve) => {
            const img = new Image();
            img.decoding = 'async';
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = '/tpixlogo.webp';
        });
    }
    return logoPromise;
}

/** หน้าเหรียญทอง + โลโก้ */
export function coinFaceTexture(size = 512) {
    const paint = (ctx, logo) => {
        const g = ctx.createRadialGradient(size * 0.4, size * 0.36, size * 0.05, size / 2, size / 2, size / 2);
        g.addColorStop(0, '#fff3c4');
        g.addColorStop(0.5, '#f2b632');
        g.addColorStop(1, '#a86b12');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, size, size);
        if (logo) ctx.drawImage(logo, size * 0.07, size * 0.07, size * 0.86, size * 0.86);
    };
    const t = canvasTexture(size, size, (ctx) => paint(ctx, null));
    loadLogo().then((img) => {
        if (!img) return;
        paint(t.ctx, img);
        t.tex.needsUpdate = true;
    });
    return t.tex;
}

/** โหลดภาพเป็น texture — ไม่มีไฟล์ = คืน null (ชิ้นนั้นใช้ของสำรอง) */
export function loadTexture(url) {
    return new Promise((resolve) => {
        new THREE.TextureLoader().load(
            url,
            (tex) => {
                tex.colorSpace = THREE.SRGBColorSpace;
                tex.anisotropy = 4;
                resolve(tex);
            },
            undefined,
            () => resolve(null),
        );
    });
}
