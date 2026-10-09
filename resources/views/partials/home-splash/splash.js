/**
 * TPIX TRADE — จอโหลดหน้าแรก: หลอดดาวน์โหลด + น้อง TPIX ขี่เหรียญบินไปตามหลอด
 *
 * เจ้าของสั่ง: "หน้าแรกของเว็บควรมี หลอดดาวน์โหลด และอนิเมชั่นน้อง TPIX สวยๆ ระหว่างรอโหลด"
 *
 * ไฟล์นี้ฝังลงหน้าแบบ inline (body.blade.php) ไม่ผ่าน Vite — ต้องทำงานตั้งแต่เฟรมแรก
 * ก่อน JS ก้อนหลักมาถึง เพราะก้อนนั้นแหละคือสิ่งที่ผู้ใช้กำลังรอ
 * (ข้อความไทย/อังกฤษจึงอยู่ที่นี่ ไม่ได้อยู่ใน i18n/*.json — ไฟล์นั้นอยู่ในก้อน JS ที่ยังโหลดไม่เสร็จ)
 *
 * สองช่วง ใช้ element เดียวกันต่อเนื่อง ไม่มีจอกระพริบตอนส่งไม้:
 *   ช่วง A (ก่อน Vue ขึ้น) — วัดว่า JS โหลดไปกี่ % ไม่ได้จริง จึง "คืบ" เข้าหา 35% แบบชะลอลงเรื่อยๆ ไม่มีวันถึง
 *   ช่วง B (หลัง Vue ขึ้น) — Composables/useHomeSplash.js ส่งความคืบหน้าจริงมาทาง window.__tpixSplash
 *
 * กติกา:
 *   - ค่าในหลอดไม่ถอยหลังเด็ดขาด (set ค่าที่น้อยกว่าเดิม = ไม่สนใจ)
 *   - ก่อน done() ค้างไม่เกิน 99% — 100% ต้องหมายถึงพร้อมจริงเท่านั้น
 *   - ไม่ขังผู้ใช้: 12 วินาที (นับเฉพาะตอนแท็บมองเห็น) ยังไม่เสร็จ → จบเองแล้วจางออก
 *   - วาดด้วย transform ล้วน (GPU) ไม่แตะ layout ของหน้าข้างใต้
 *
 * window.__tpixSplash:
 *   set(pct, ceil?)  ค่าจริงขั้นต่ำ + เพดานที่ยอมให้คืบไปได้ระหว่างรองานชิ้นถัดไป (0–99)
 *   value()          ค่าที่หลอดกำลังมุ่งไป (ช่วง B ใช้เป็นจุดตั้งต้น)
 *   done()           ครบ 100% → น้องเชียร์ + ประกาย → จางออก → ถอดออกจากหน้า
 *   fail()           จางออกทันทีแบบไม่ฉลอง (เช่นผู้ใช้เปลี่ยนหน้าไปก่อนโหลดเสร็จ)
 *   onGone(cb)       เรียก cb เมื่อถอดออกจากหน้าแล้ว (น้องบนหน้า 3D รอทักทายหลังจอนี้หาย)
 *   active / gone    ยังรับค่าอยู่ไหม / ถอดออกไปแล้วหรือยัง
 *
 * Developed by Xman Studio
 */
(function () {
    'use strict';

    var root = document.getElementById('tpix-splash');
    if (!root || window.__tpixSplash) return;

    // เริ่มที่ 3% ไม่ใช่ 0 — หลอดว่างเปล่าดูเหมือนเว็บค้าง
    var START_AT = 3;
    // ช่วง A คืบเข้าหาค่านี้ (ยังไม่รู้ขนาด JS ที่เหลือ) — ช่วง B มาแทนเมื่อ Vue ขึ้น
    var STAGE_A_CEIL = 35;
    var MAX_BEFORE_DONE = 99;
    // ค่าคงที่เวลา (วินาที): คืบช้า ๆ · หลอดวิ่งตามค่าจริงแบบนุ่ม · ตอนจบวิ่งให้สุดหลอดเร็ว
    var CREEP_TAU = 1.6;
    var EASE_TAU = 0.16;
    var FINISH_TAU = 0.05;
    var HARD_TIMEOUT_MS = 12000;

    var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    // จบ: วิ่งถึง 100 → เชียร์ค้างให้เห็น → จาง (รวมไม่เกิน ~700ms หลังพร้อมจริง)
    // ผู้ใช้ขอลดการเคลื่อนไหว = ไม่มีเด้ง/ประกาย จางเฉย ๆ
    var T = reduced ? { fill: 0, hold: 140, fade: 260 } : { fill: 160, hold: 220, fade: 340 };

    var TEXT = {
        th: {
            label: 'กำลังโหลด TPIX TRADE',
            steps: ['กำลังเตรียมโลก TPIX…', 'กำลังโหลดฉาก 3 มิติ…', 'กำลังจัดวางกราฟกับเหรียญ…', 'เกือบพร้อมแล้วค่ะ ✨'],
            ready: 'พร้อมแล้ว ไปกันเลย!',
        },
        en: {
            label: 'Loading TPIX TRADE',
            steps: ['Preparing the TPIX world…', 'Loading the 3D scene…', 'Arranging charts & coins…', 'Almost ready ✨'],
            ready: "Ready — let's go!",
        },
    };
    // เปอร์เซ็นต์ที่เปลี่ยนประโยค (ลำดับเดียวกับ steps)
    var STEP_AT = [0, 30, 60, 85];

    // ลำดับเดียวกับ useTranslation: ที่ผู้ใช้เลือกไว้ → ค่าเริ่มของแอดมิน (meta) → ไทย
    function pickLang() {
        var saved = null;
        try {
            saved = window.localStorage.getItem('tpix_locale');
        } catch (e) {
            saved = null; // โหมดส่วนตัว/บล็อกที่เก็บข้อมูล
        }
        if (saved && TEXT[saved]) return saved;
        var meta = document.querySelector('meta[name="default-locale"]');
        var def = meta && meta.getAttribute('content');
        return def && TEXT[def] ? def : 'th';
    }

    var lang = pickLang();
    var text = TEXT[lang];

    function q(sel) {
        return root.querySelector(sel);
    }
    var bar = q('[data-splash-bar]');
    var fill = q('[data-splash-fill]');
    var fillBg = q('[data-splash-fill-bg]');
    var rider = q('[data-splash-rider]');
    var pctEl = q('[data-splash-pct]');
    var labelEl = q('[data-splash-label]');
    var cheer = q('[data-splash-cheer]');
    var mark = q('[data-splash-mark]');

    var nextFrame = window.requestAnimationFrame
        ? window.requestAnimationFrame.bind(window)
        : function (cb) {
              return setTimeout(function () {
                  cb(Date.now());
              }, 16);
          };
    var cancelFrame = window.cancelAnimationFrame ? window.cancelAnimationFrame.bind(window) : clearTimeout;

    var phase = 'loading'; // loading → done → leaving → gone
    var floor = START_AT; // ค่าจริงขั้นต่ำ (ไม่ถอย)
    var ceil = STAGE_A_CEIL; // เพดานที่ยอมให้คืบไปถึงระหว่างรอ
    var soft = START_AT; // ค่าที่คืบไปแล้ว
    var shown = START_AT; // ค่าที่วาดอยู่บนจอ (วิ่งตาม soft แบบนุ่ม)
    var raf = 0;
    var last = 0;
    var shownInt = -1;
    var labelNow = '';
    var timers = [];
    var goneCbs = [];

    function clampNum(v, lo, hi) {
        var n = Number(v);
        return isFinite(n) ? Math.min(hi, Math.max(lo, n)) : lo;
    }

    function later(fn, ms) {
        timers.push(setTimeout(fn, ms));
    }

    function clearTimers() {
        timers.splice(0).forEach(clearTimeout);
    }

    function setLabel(s) {
        if (s === labelNow) return;
        labelNow = s;
        if (labelEl) labelEl.textContent = s;
    }

    function stepText(n) {
        var i = STEP_AT.length - 1;
        while (i > 0 && n < STEP_AT[i]) i--;
        return text.steps[i];
    }

    // หลอดเติมแบบ "เปิดม่าน": กล่องสีเลื่อนออกมาจากซ้าย ส่วนลายไล่สีข้างในเลื่อนสวนทาง
    // → สีอยู่กับที่ (ฟ้าตอนเริ่ม ส้มอุ่นตอนครบ) หัวหลอดมนสวย และใช้ transform ล้วน
    function render(p) {
        var f = p / 100;
        if (fill) fill.style.transform = 'translate3d(' + ((f - 1) * 100).toFixed(3) + '%,0,0)';
        if (fillBg) fillBg.style.transform = 'translate3d(' + ((1 - f) * 100).toFixed(3) + '%,0,0)';
        // กล่องน้องกว้างเท่าหลอด → เลื่อน f*100% ของตัวเอง = หัวหลอดพอดี ไม่ต้องวัดขนาดจอ
        if (rider) rider.style.transform = 'translate3d(' + (f * 100).toFixed(3) + '%,0,0)';
        var n = Math.floor(p + 1e-6);
        if (n !== shownInt) {
            shownInt = n;
            if (pctEl) pctEl.textContent = n + '%';
            if (phase === 'loading') setLabel(stepText(n));
            if (bar) {
                bar.setAttribute('aria-valuenow', String(n));
                bar.setAttribute('aria-valuetext', n + '% · ' + labelNow);
            }
        }
    }

    function step(dt) {
        if (soft < floor) soft = floor;
        if (phase === 'loading' && ceil > soft) soft += (ceil - soft) * (1 - Math.exp(-dt / CREEP_TAU));
        var goal = phase === 'loading' ? soft : 100;
        var tau = phase === 'loading' ? EASE_TAU : FINISH_TAU;
        if (goal > shown) shown += (goal - shown) * (1 - Math.exp(-dt / tau));
        if (goal - shown < 0.05) shown = Math.max(shown, goal);
        render(shown);
    }

    function frame(now) {
        raf = 0;
        // เฟรมห่างเกิน 0.1 วิ (แท็บถูกซ่อน/เครื่องกำลังคอมไพล์ shader) = ไม่ให้หลอดกระโดด
        var dt = last ? Math.min(0.1, Math.max(0, (now - last) / 1000)) : 1 / 60;
        last = now;
        step(dt);
        if (phase === 'loading' || phase === 'done') raf = nextFrame(frame);
    }

    // ── กันขังผู้ใช้: นับเวลาเฉพาะตอนแท็บมองเห็น ────────────────────────────
    // เปิดลิงก์ในแท็บเบื้องหลัง = เบราว์เซอร์หยุดวาด ฉาก 3D ยังไม่บูตเป็นเรื่องปกติ ไม่ใช่โหลดค้าง
    var hardLeft = HARD_TIMEOUT_MS;
    var hardStart = 0;
    var hardTimer = 0;

    function armHard() {
        if (hardTimer || phase !== 'loading' || document.hidden) return;
        hardStart = Date.now();
        hardTimer = setTimeout(function () {
            hardTimer = 0;
            done();
        }, hardLeft);
    }

    function pauseHard() {
        if (!hardTimer) return;
        clearTimeout(hardTimer);
        hardTimer = 0;
        hardLeft = Math.max(0, hardLeft - (Date.now() - hardStart));
    }

    function onVisibility() {
        if (document.hidden) pauseHard();
        else armHard();
    }

    // เลื่อน/ปัดบนจอโหลด = ไม่ให้หน้าข้างใต้เลื่อนตาม (กลับมาจะได้เห็นฮีโร่ ไม่ใช่กลางทาง)
    // ไม่ล็อก overflow ของ body เพราะแถบเลื่อนหายแล้วหน้าข้างใต้จะขยับ (layout shift)
    function blockScroll(e) {
        if (e.cancelable) e.preventDefault();
    }

    function detach() {
        pauseHard();
        document.removeEventListener('visibilitychange', onVisibility);
        root.removeEventListener('wheel', blockScroll);
        root.removeEventListener('touchmove', blockScroll);
    }

    // ── จบ ─────────────────────────────────────────────────────────────────
    function set(pct, maybeCeil) {
        if (phase !== 'loading') return;
        var p = clampNum(pct, 0, MAX_BEFORE_DONE);
        if (p > floor) floor = p;
        var c = maybeCeil === undefined || maybeCeil === null ? floor : clampNum(maybeCeil, 0, MAX_BEFORE_DONE);
        ceil = Math.max(floor, c);
    }

    function done() {
        if (phase !== 'loading') return;
        phase = 'done';
        pauseHard();
        if (!raf) raf = nextFrame(frame);
        later(celebrate, T.fill);
    }

    function celebrate() {
        if (phase !== 'done') return;
        shown = 100;
        render(100);
        setLabel(text.ready);
        if (bar) bar.setAttribute('aria-valuetext', '100% · ' + text.ready);
        // ท่าเชียร์ยังโหลดไม่เสร็จ → คงท่าบินไว้ ดีกว่าน้องหายวับ
        if (cheer && cheer.complete && cheer.naturalWidth > 0) root.classList.add('is-cheer');
        root.classList.add('is-done');
        later(leave, T.hold);
    }

    function leave() {
        if (phase === 'leaving' || phase === 'gone') return;
        phase = 'leaving';
        detach();
        // จางแล้วกดทะลุได้ทันที ไม่ต้องรอถอดออก
        root.classList.add('is-leaving');
        later(remove, T.fade + 40);
    }

    function remove() {
        if (phase === 'gone') return;
        phase = 'gone';
        clearTimers();
        if (raf) cancelFrame(raf);
        raf = 0;
        if (root.parentNode) root.parentNode.removeChild(root);
        goneCbs.splice(0).forEach(function (cb) {
            try {
                cb();
            } catch (e) {
                // คนรอฝั่งแอปพัง ไม่ใช่เรื่องของจอโหลด
            }
        });
    }

    function fail() {
        if (phase === 'leaving' || phase === 'gone') return;
        clearTimers();
        leave();
    }

    window.__tpixSplash = {
        set: set,
        done: done,
        fail: fail,
        value: function () {
            return Math.max(soft, floor);
        },
        onGone: function (cb) {
            if (typeof cb !== 'function') return;
            if (phase === 'gone') cb();
            else goneCbs.push(cb);
        },
        get active() {
            return phase === 'loading';
        },
        get gone() {
            return phase === 'gone';
        },
        timing: T,
    };

    // ── เริ่ม ───────────────────────────────────────────────────────────────
    root.setAttribute('lang', lang);
    root.style.setProperty('--sp-fade', T.fade + 'ms');
    if (bar) bar.setAttribute('aria-label', text.label);
    // ไฟล์โลโก้ยังไม่มี/โหลดไม่ได้ → เอาออก เหลือแค่ตัวหนังสือ (ไม่ทิ้งไอคอนรูปแตก)
    if (mark) {
        mark.addEventListener('error', function () {
            if (mark.parentNode) mark.parentNode.removeChild(mark);
        });
    }
    // ท่าเชียร์ซ่อนอยู่ (opacity 0) เบราว์เซอร์จึงยังไม่ถอดรหัสภาพ — สั่งถอดไว้ก่อน สลับท่าตอน 100% จะไม่มีเฟรมว่าง
    if (cheer && cheer.decode) cheer.decode().catch(function () {});
    root.addEventListener('wheel', blockScroll, { passive: false });
    root.addEventListener('touchmove', blockScroll, { passive: false });
    document.addEventListener('visibilitychange', onVisibility);

    render(shown);
    root.hidden = false;
    raf = nextFrame(frame);
    armHard();
})();
