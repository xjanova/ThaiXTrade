/**
 * TPIX TRADE — กันเครื่องมือนักพัฒนา (DevTools / F12) บนหน้าเว็บจริง
 *
 * เจ้าของสั่ง: "เว็บเราห้าม dev tool หรือ f12" → เลือกแบบเข้มสุด
 *   "ปิดทุกอย่าง และการก๊อป ให้สร้างเครื่องมือคลิ๊กไว้ในเว็บเราแทน" · ไม่กันหลังบ้าน (/admin)
 *
 * ⚠️ เป็นด่านกันคนทั่วไปเท่านั้น — ทุกอย่างที่ส่งถึงเบราว์เซอร์ คนตั้งใจจริงดูได้เสมอ
 *    ความปลอดภัยจริงต้องอยู่ที่เซิร์ฟเวอร์ (ตรวจสิทธิ์/ลายเซ็น/ข้อมูลทุกคำขอ) เหมือนเดิม
 *
 * 1) คีย์ลัด: F12 · Ctrl/⌘+Shift+I/J/C/K · ⌘+⌥+I/J/C/U · Ctrl/⌘+U (ดูซอร์ส) · Ctrl/⌘+S (บันทึกหน้า)
 * 2) คลิกขวา: ปิดทั้งหน้า ยกเว้นช่องกรอก (คลิกขวาวางข้อความได้ตามปกติ)
 * 3) คัดลอกแบบปกติ (Ctrl+C / เมนูเบราว์เซอร์) ปิด ยกเว้นในช่องกรอก → ใช้เครื่องมือคัดลอกของเว็บแทน:
 *    เลือกข้อความแล้วมีปุ่ม "คัดลอก" ลอยขึ้นใกล้ๆ · ปุ่มคัดลอกที่มีตามหน้า (navigator.clipboard) ใช้ได้ตามเดิม
 * 4) เปิด DevTools ผ่านเมนูเบราว์เซอร์ (กันด้วยคีย์ไม่ได้): คำสั่ง debugger หยุดเฉพาะตอน DevTools เปิดอยู่
 *    → จับเวลาได้ช้าผิดปกติ = เปิดอยู่ → จอบังเต็มหน้าจนกว่าจะปิด
 *    ไม่ใช้วิธีวัดขนาดหน้าต่าง — แถบข้างของ Chrome/Edge ทำให้ผู้ใช้ปกติโดนบังผิดๆ
 *
 * เปิดเฉพาะ build จริง (npm run dev ในเครื่องไม่กัน)
 *
 * Developed by Xman Studio
 */

const DEVTOOLS_KEYS = ['KeyI', 'KeyJ', 'KeyC', 'KeyK'];
const MAC_DEVTOOLS_KEYS = ['KeyI', 'KeyJ', 'KeyC', 'KeyU'];
const PAUSE_MS = 200; // debugger ไม่หยุด = ใช้เวลาไม่ถึง 1 ms · หยุด = รอคนกด resume

/** คีย์ลัดที่เปิด DevTools / ดูซอร์ส / บันทึกหน้า */
export function isBlockedShortcut(e) {
    if (e.key === 'F12' || e.code === 'F12') return true;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.shiftKey && DEVTOOLS_KEYS.includes(e.code)) return true;
    if (e.metaKey && e.altKey && MAC_DEVTOOLS_KEYS.includes(e.code)) return true;
    if (mod && !e.shiftKey && !e.altKey && (e.code === 'KeyU' || e.code === 'KeyS')) return true;
    return false;
}

/** ช่องกรอก — ต้องคลิกขวา/คัดลอก/วางได้ตามปกติ */
export function isEditable(el) {
    const node = el && el.nodeType === 1 ? el : el?.parentElement;
    return !!node?.closest?.('input, textarea, select, [contenteditable=""], [contenteditable="true"]');
}

/** หน้าที่ต้องกัน (หลังบ้านไม่กัน — ทีมงานใช้ DevTools ตรวจปัญหา) */
export function isGuardedPath(path) {
    return !/^\/admin(\/|$)/.test(path || '/');
}

const lang = () => (document.documentElement.lang === 'en' ? 'en' : 'th');
const TEXT = {
    th: {
        copy: '📋 คัดลอก',
        copied: '✓ คัดลอกแล้ว',
        blockedTitle: 'ปิดเครื่องมือนักพัฒนาก่อนนะคะ',
        blockedBody: 'เพื่อความปลอดภัยของบัญชีและเงินของคุณ TPIX TRADE ไม่อนุญาตให้เปิด DevTools ระหว่างใช้งาน ปิดแล้วหน้าเว็บจะกลับมาใช้ได้ทันที',
    },
    en: {
        copy: '📋 Copy',
        copied: '✓ Copied',
        blockedTitle: 'Please close the developer tools',
        blockedBody: 'To keep your account and funds safe, TPIX TRADE does not allow DevTools while in use. Close them and the page works again right away.',
    },
};

let installed = false;

/**
 * @param {{ force?: boolean }} opts force = เปิดแม้ไม่ใช่ build จริง (ทดสอบ)
 */
export function installDevtoolsGuard(opts = {}) {
    if (installed || typeof window === 'undefined') return;
    if (!import.meta.env.PROD && !opts.force) return;
    installed = true;

    const guarded = () => isGuardedPath(window.location.pathname);

    // ── 1) คีย์ลัด ─────────────────────────────────────────────────────────
    window.addEventListener(
        'keydown',
        (e) => {
            if (!guarded() || !isBlockedShortcut(e)) return;
            e.preventDefault();
            e.stopImmediatePropagation();
        },
        true,
    );

    // ── 2) คลิกขวา · ลากรูปออกไปเปิดแท็บใหม่ ─────────────────────────────
    window.addEventListener(
        'contextmenu',
        (e) => {
            if (guarded() && !isEditable(e.target)) e.preventDefault();
        },
        true,
    );
    window.addEventListener(
        'dragstart',
        (e) => {
            if (guarded() && e.target?.tagName === 'IMG') e.preventDefault();
        },
        true,
    );

    // ── 3) คัดลอกแบบปกติปิด → เครื่องมือคัดลอกของเว็บ ─────────────────────
    const blockCopy = (e) => {
        if (!guarded() || isEditable(e.target) || isEditable(document.activeElement)) return;
        e.preventDefault();
        showCopyPill(true); // ผู้ใช้กด Ctrl+C → ชี้ให้เห็นปุ่มคัดลอกของเว็บ
    };
    window.addEventListener('copy', blockCopy, true);
    window.addEventListener('cut', blockCopy, true);

    const pill = document.createElement('button');
    pill.type = 'button';
    pill.setAttribute('data-tpix-copy', '');
    pill.style.cssText =
        'position:fixed;z-index:2147483000;display:none;padding:6px 12px;border-radius:999px;' +
        'font:600 12px/1.2 system-ui,sans-serif;color:#fff;background:rgba(15,23,42,.94);' +
        'border:1px solid rgba(34,211,238,.55);box-shadow:0 8px 24px rgba(0,0,0,.4);cursor:pointer;' +
        'transform:translate(-50%,0);white-space:nowrap';
    // กดปุ่มแล้วข้อความที่เลือกต้องไม่หลุด
    pill.addEventListener('mousedown', (e) => e.preventDefault());
    let pillText = '';
    let hideTimer = 0;

    function selectedText() {
        const sel = window.getSelection?.();
        if (!sel || sel.isCollapsed || !sel.rangeCount) return null;
        if (isEditable(sel.anchorNode)) return null; // ในช่องกรอกใช้คัดลอกปกติได้อยู่แล้ว
        const text = sel.toString().trim();
        if (!text) return null;
        return { text, rect: sel.getRangeAt(0).getBoundingClientRect() };
    }

    function showCopyPill(nudge = false) {
        if (!guarded()) return hidePill();
        const s = selectedText();
        if (!s) return hidePill();
        pillText = s.text;
        pill.textContent = TEXT[lang()].copy;
        if (!pill.isConnected) document.body.appendChild(pill);
        pill.style.display = 'block';
        const touch = window.matchMedia?.('(pointer: coarse)').matches;
        // จอสัมผัส: วางใต้ข้อความ (เมนูเลือกข้อความของมือถืออยู่ด้านบน) · เมาส์: วางเหนือข้อความ
        const y = touch ? s.rect.bottom + 12 : s.rect.top - 40;
        pill.style.left = `${Math.min(window.innerWidth - 60, Math.max(60, s.rect.left + s.rect.width / 2))}px`;
        pill.style.top = `${Math.min(window.innerHeight - 44, Math.max(8, y))}px`;
        if (nudge) pill.animate?.([{ transform: 'translate(-50%,0) scale(1)' }, { transform: 'translate(-50%,0) scale(1.15)' }, { transform: 'translate(-50%,0) scale(1)' }], { duration: 320 });
    }

    function hidePill() {
        pill.style.display = 'none';
    }

    async function writeClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // บางเบราว์เซอร์/หน้าต่างไม่ให้ใช้ clipboard API → คัดลอกผ่านช่องกรอกซ่อน (ช่องกรอกได้รับอนุญาต)
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            let ok = false;
            try {
                ok = document.execCommand('copy');
            } catch {
                ok = false;
            }
            ta.remove();
            return ok;
        }
    }

    pill.addEventListener('click', async () => {
        if (!pillText) return;
        const ok = await writeClipboard(pillText);
        if (!ok) return;
        pill.textContent = TEXT[lang()].copied;
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hidePill, 1200);
    });

    let selTimer = 0;
    document.addEventListener('selectionchange', () => {
        clearTimeout(selTimer);
        selTimer = setTimeout(() => showCopyPill(), 180);
    });
    window.addEventListener('scroll', hidePill, { passive: true, capture: true });

    // ── 4) ตรวจจับ DevTools ที่เปิดผ่านเมนู ────────────────────────────────
    // สร้างตอนรัน — ตัวย่อโค้ดตอน build ลบคำสั่ง debugger ที่เขียนตรงๆ ทิ้ง (CSP อนุญาต unsafe-eval อยู่แล้ว)
    let trap = null;
    try {
        trap = new Function('debugger');
    } catch {
        trap = null;
    }

    const overlay = document.createElement('div');
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.style.cssText =
        'position:fixed;inset:0;z-index:2147483600;display:none;align-items:center;justify-content:center;' +
        'padding:24px;background:rgba(2,6,23,.92);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);' +
        'color:#e2e8f0;text-align:center;font:15px/1.6 system-ui,sans-serif';

    function showOverlay() {
        const t = TEXT[lang()];
        overlay.innerHTML = '';
        const box = document.createElement('div');
        box.style.cssText = 'max-width:420px';
        const icon = document.createElement('div');
        icon.textContent = '🛡️';
        icon.style.cssText = 'font-size:44px;margin-bottom:12px';
        const h = document.createElement('p');
        h.textContent = t.blockedTitle;
        h.style.cssText = 'font-size:20px;font-weight:700;color:#fff;margin:0 0 8px';
        const p = document.createElement('p');
        p.textContent = t.blockedBody;
        p.style.cssText = 'margin:0;color:#94a3b8';
        box.append(icon, h, p);
        overlay.append(box);
        if (!overlay.isConnected) document.body.appendChild(overlay);
        overlay.style.display = 'flex';
    }

    function hideOverlay() {
        overlay.style.display = 'none';
    }

    if (trap) {
        setInterval(() => {
            if (document.hidden) return;
            if (!guarded()) return hideOverlay();
            const t0 = performance.now();
            trap();
            if (performance.now() - t0 > PAUSE_MS) showOverlay();
            else hideOverlay();
        }, 1000);
    }
}
