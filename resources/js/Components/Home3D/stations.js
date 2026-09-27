/**
 * TPIX TRADE — สถานีของหน้าแรก 3D (ใช้ร่วมกันทั้งฝั่งโลก 3D และแผง HTML)
 *
 * เดินหน้าลึกเข้าไปในโลกกระดานเทรด (แกน -Z) สลับซ้าย/ขวา
 * panel: แผงข้อความอยู่ฝั่งไหนของจอ → วัตถุ 3D ถูกจัดให้อยู่อีกฝั่ง และน้อง TPIX ยืนริมจอฝั่งวัตถุ
 * len: ความยาวการเลื่อนของสถานี (หน่วย = ความสูงจอ)
 *
 * key ต้องตรงกับ SECTIONS ใน Components/Home/mascotGuide.js (บทพูดของน้อง)
 *
 * Developed by Xman Studio
 */

export const STATIONS = [
    { key: 'hero', len: 1.7, pos: [0, 0, 0], panel: 'left' },
    { key: 'node', len: 1.5, pos: [-24, 0, -44], panel: 'right' },
    { key: 'markets', len: 1.5, pos: [22, 0, -88], panel: 'left' },
    { key: 'sale', len: 1.5, pos: [-22, 0, -132], panel: 'right' },
    { key: 'ecosystem', len: 1.6, pos: [20, 0, -176], panel: 'left' },
    { key: 'features', len: 1.5, pos: [-20, 0, -220], panel: 'right' },
    { key: 'cta', len: 1.4, pos: [0, 0, -262], panel: 'center' },
];

/**
 * มุมกล้องของแต่ละสถานี: ดันจุดมองไปฝั่งแผงข้อความ → วัตถุไปอยู่อีกฝั่งของจอ
 * @param {object} st สถานี
 * @param {{ portrait?: boolean }} layout
 * @returns {{ pos: number[], look: number[] }}
 */
export function cameraFor(st, { portrait = false } = {}) {
    const [x, y, z] = st.pos;
    const side = st.panel === 'left' ? -1 : st.panel === 'right' ? 1 : 0;
    if (portrait) {
        // จอแนวตั้ง: แผงอยู่ด้านล่าง วัตถุอยู่ครึ่งบน → ถอยกล้อง มองต่ำลงเล็กน้อย
        return { pos: [x, y + 5.2, z + 22], look: [x, y + 1.2, z] };
    }
    return {
        pos: [x + side * 1.2, y + 3.9, z + 16.5],
        look: [x + side * 3.4, y + 3.1, z],
    };
}

/**
 * ความคืบหน้าการเดินทาง f (สถานีที่ i อยู่ตรงกลางพอดีเมื่อ f = i)
 * centers = ตำแหน่ง scroll ที่แต่ละสถานีอยู่กลางช่วงค้าง (sticky)
 */
export function journeyF(scroll, centers) {
    const n = centers.length;
    if (n === 0) return 0;
    if (n === 1) return 0;
    if (scroll <= centers[0]) {
        const span = centers[1] - centers[0] || 1;
        return (scroll - centers[0]) / span;
    }
    for (let i = 0; i < n - 1; i++) {
        if (scroll <= centers[i + 1]) {
            return i + (scroll - centers[i]) / (centers[i + 1] - centers[i] || 1);
        }
    }
    const span = centers[n - 1] - centers[n - 2] || 1;
    return n - 1 + (scroll - centers[n - 1]) / span;
}

const smooth = (x) => x * x * (3 - 2 * x);

/**
 * f → ตำแหน่งบนเส้นทางกล้อง (0..n-1) — ค้างที่สถานีช่วงกลาง แล้วค่อยบินต่อ
 * ช่วงค้างทำให้อ่านแผงข้อความได้โดยกล้องไม่ไหลไปมา
 */
export function pathParam(f, n, hold = 0.3) {
    if (n <= 1) return 0;
    const clamped = Math.min(n - 1, Math.max(0, f));
    const i = Math.min(n - 2, Math.floor(clamped));
    const frac = clamped - i;
    const g = smooth(Math.min(1, Math.max(0, (frac - hold) / (1 - 2 * hold))));
    return i + g;
}

/** ความชัดของแผงสถานี i (1 = อยู่ที่สถานีพอดี) */
export function panelVisibility(f, i) {
    const d = Math.abs(f - i);
    if (d <= 0.32) return 1;
    if (d >= 0.5) return 0;
    return 1 - smooth((d - 0.32) / 0.18);
}
