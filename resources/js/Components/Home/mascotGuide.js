/**
 * TPIX TRADE — บทของน้อง TPIX บนหน้าแรก (ข้อมูลล้วน + ฟังก์ชันคำนวณ ไม่แตะ DOM)
 *
 * แต่ละส่วนของหน้าแรกมี data-guide="<key>" กำกับ น้องจะพูดประโยคของส่วนนั้น
 * พร้อมท่าทางและปุ่มพาไปหน้าที่เกี่ยวข้อง — บทพูดเป็นคีย์คำแปล สลับภาษาแล้วเปลี่ยนตาม
 * ประโยคแนะนำทั้งหมดเขียนไว้ล่วงหน้า ไม่เรียก AI (ไม่เปลืองโควตา ไม่ช้า)
 * AI ถูกเรียกเฉพาะตอนผู้ใช้พิมพ์ถามเองเท่านั้น
 *
 * Developed by Xman Studio
 */

/**
 * บทของแต่ละส่วน
 * pose: ท่าทางตอนพูด · chips: ปุ่มพาไปหน้าจริง (href ต้องมีใน routes/web.php)
 */
export const SECTIONS = {
    hero: {
        line: 'mascot.lines.hero',
        pose: 'wave',
        chips: [
            { label: 'mascot.chips.startTrading', href: '/trade' },
            { label: 'mascot.chips.tour', action: 'start-tour' },
        ],
    },
    node: {
        line: 'mascot.lines.node',
        pose: 'present',
        chips: [
            { label: 'mascot.chips.masternode', href: '/masternode' },
            { label: 'mascot.chips.nodeGuide', href: '/masternode/guide' },
        ],
    },
    markets: {
        line: 'mascot.lines.markets',
        pose: 'point',
        chips: [
            { label: 'mascot.chips.markets', href: '/markets' },
            { label: 'mascot.chips.startTrading', href: '/trade' },
        ],
    },
    sale: {
        line: 'mascot.lines.sale',
        pose: 'cheer',
        chips: [
            { label: 'mascot.chips.tokenSale', href: '/token-sale' },
            { label: 'mascot.chips.whitepaper', href: '/whitepaper' },
        ],
    },
    ecosystem: {
        line: 'mascot.lines.ecosystem',
        pose: 'point',
        chips: [
            { label: 'mascot.chips.explorer', href: '/explorer' },
            { label: 'mascot.chips.swap', href: '/swap' },
        ],
    },
    features: {
        line: 'mascot.lines.features',
        pose: 'present',
        chips: [
            { label: 'mascot.chips.aiTrade', href: '/ai-trade' },
            { label: 'mascot.chips.bridge', href: '/bridge' },
        ],
    },
    cta: {
        line: 'mascot.lines.cta',
        pose: 'cheer',
        chips: [
            { label: 'mascot.chips.startTrading', href: '/trade' },
            { label: 'mascot.chips.download', href: '/download' },
        ],
    },
};

/** ลำดับทัวร์ = ลำดับส่วนบนหน้า */
export const TOUR_ORDER = ['hero', 'node', 'markets', 'sale', 'ecosystem', 'features', 'cta'];

export const POKE_LINES = ['mascot.poke.1', 'mascot.poke.2', 'mascot.poke.3', 'mascot.poke.4'];

/** ความสูงตอนจอดมุมขวาล่าง ตามความกว้างจอ */
export function dockHeight(vw) {
    if (vw < 640) return 128;
    if (vw < 1024) return 160;
    return 196;
}

/**
 * วางบับเบิ้ลข้างหัวน้อง ไม่ให้ล้นจอ
 * วางฝั่งที่เนื้อหาอยู่ (prefer) ก่อน ถ้าที่ไม่พอ → ขึ้นไปวางเหนือหัว
 *
 * @param {{ head:{x:number,y:number}, box:{x:number,y:number,w:number,h:number},
 *           bubble:{w:number,h:number}, vw:number, vh:number, top?:number, prefer?:'left'|'right' }} a
 * @returns {{ x:number, y:number, side:'left'|'right'|'above', tail:number }}
 */
export function placeBubble({ head, box, bubble, vw, vh, top = 8, prefer = 'left' }) {
    const pad = 8;
    const clampY = (y) => Math.min(Math.max(y, top + pad), Math.max(top + pad, vh - bubble.h - pad));

    if (prefer === 'right') {
        const rightEdge = box.x + box.w * 0.7;
        if (vw - pad - rightEdge >= bubble.w) {
            const y = clampY(head.y - bubble.h * 0.62);
            const tail = Math.min(Math.max(head.y - y, 18), bubble.h - 18);
            return { x: rightEdge, y, side: 'right', tail };
        }
    }
    // ตัวน้องกินพื้นที่ราว 30–70% ของความกว้าง canvas → ชิดขอบซ้ายของตัว ไม่ลอยห่าง
    const leftEdge = box.x + box.w * 0.3;
    const room = leftEdge - pad;

    if (room >= bubble.w) {
        const x = leftEdge - bubble.w;
        const y = clampY(head.y - bubble.h * 0.62);
        // หางบับเบิ้ลชี้หาหัว (วัดจากขอบบนของบับเบิ้ล)
        const tail = Math.min(Math.max(head.y - y, 18), bubble.h - 18);
        return { x, y, side: 'left', tail };
    }

    const x = Math.min(Math.max(head.x - bubble.w * 0.72, pad), Math.max(pad, vw - bubble.w - pad));
    const y = clampY(box.y + box.h * 0.04 - bubble.h);
    const tail = Math.min(Math.max(head.x - x, 22), bubble.w - 22);
    return { x, y, side: 'above', tail };
}

/**
 * ตัดข้อความเป็นอักษรที่มองเห็น (grapheme)
 * ภาษาไทยมีสระบน/ล่าง/วรรณยุกต์ที่เป็นอักขระแยก — ถ้าตัดตาม code unit
 * ระหว่างพิมพ์ทีละตัวจะเห็นวรรณยุกต์ลอยเดี่ยวๆ
 */
export function graphemes(text) {
    const s = String(text ?? '');
    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
        const seg = new Intl.Segmenter('th', { granularity: 'grapheme' });
        return Array.from(seg.segment(s), (x) => x.segment);
    }
    return Array.from(s);
}

/** จังหวะอ้าปากตามตัวอักษร: เว้นวรรค/เครื่องหมาย = หุบ */
export function mouthFor(ch) {
    if (!ch || /[\s.,!?…:;()\-–—"'“”]/.test(ch)) return 0;
    if (/[aeiouAEIOUะ-ูเ-ไาำ]/.test(ch)) return 1;
    return 0.6;
}

/** ความเร็วพิมพ์ (อักษร/วินาที) — ข้อความยาวพิมพ์เร็วขึ้น ไม่ให้รอนาน */
export function typeSpeed(length) {
    return Math.min(95, Math.max(30, length / 2.4));
}

/**
 * คำตอบจาก AI มักมี markdown มาด้วย แต่บับเบิ้ลเป็นข้อความธรรมดา
 * ลอก ** / # / ` ออก ไม่ต้องแปลงเป็น HTML (กัน XSS ไปในตัว — Vue แสดงเป็นข้อความเสมอ)
 */
export function cleanReply(text) {
    return String(text ?? '')
        .replace(/\*\*(.+?)\*\*/g, '$1')
        .replace(/__(.+?)__/g, '$1')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/** href ภายในเว็บเท่านั้น — กันลิงก์ภายนอก/javascript: จากคำตอบ AI */
export function isInternalPath(path) {
    return typeof path === 'string' && /^\/(?!\/)[A-Za-z0-9\-/]*$/.test(path);
}
