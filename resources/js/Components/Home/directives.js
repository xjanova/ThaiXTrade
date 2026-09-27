/**
 * TPIX TRADE — ลูกเล่น 3D ของการ์ดบนหน้าแรก (สไตล์ XMAN Studio)
 *
 * v-tilt การ์ดเอียงเข้าหาเมาส์ + แสงสะท้อนวิ่งตามตัวชี้ (เฉพาะเมาส์/ปากกา — นิ้วไม่มี hover ให้ตาม)
 * ปิดเองเมื่อผู้ใช้ขอลดการเคลื่อนไหว
 *
 * Developed by Xman Studio
 */

const canHover = () => typeof window !== 'undefined' && window.matchMedia?.('(pointer: fine)').matches;
const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

export const vTilt = {
    mounted(el, binding) {
        if (!canHover() || reducedMotion()) return;
        const max = binding.value?.max ?? 8;
        el.classList.add('tilt-3d');
        const glare = document.createElement('span');
        glare.className = 'tilt-3d__glare';
        glare.setAttribute('aria-hidden', 'true');
        el.appendChild(glare);

        let raf = 0;
        let px = 0.5;
        let py = 0.5;
        const apply = () => {
            raf = 0;
            el.style.setProperty('--gx', `${(px * 100).toFixed(1)}%`);
            el.style.setProperty('--gy', `${(py * 100).toFixed(1)}%`);
            const ax = (0.5 - py) * 2;
            const ay = (px - 0.5) * 2;
            el.style.transform = `perspective(900px) rotateX(${(ax * max).toFixed(2)}deg) rotateY(${(ay * max).toFixed(2)}deg) translateZ(0)`;
        };
        const onMove = (e) => {
            const r = el.getBoundingClientRect();
            px = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
            py = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
            el.classList.add('is-tilting');
            if (!raf) raf = requestAnimationFrame(apply);
        };
        const onLeave = () => {
            cancelAnimationFrame(raf);
            raf = 0;
            el.classList.remove('is-tilting');
            el.style.transform = '';
        };
        el.addEventListener('pointermove', onMove);
        el.addEventListener('pointerleave', onLeave);
        el.__tilt = { onMove, onLeave, glare, cancel: () => cancelAnimationFrame(raf) };
    },
    unmounted(el) {
        const t = el.__tilt;
        if (!t) return;
        t.cancel();
        el.removeEventListener('pointermove', t.onMove);
        el.removeEventListener('pointerleave', t.onLeave);
        t.glare.remove();
        delete el.__tilt;
    },
};
