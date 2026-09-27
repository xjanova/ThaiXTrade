/**
 * TPIX TRADE — ตัวขับเคลื่อนน้อง TPIX (ภาพเจนจาก ChatGPT + คลิปเคลื่อนไหวจาก Grok)
 *
 * ตัวละครคือภาพ PNG/WebP พื้นหลังโปร่งใส ท่าละใบ (สไตล์ 3D เรนเดอร์) วางซ้อนกันแล้วสลับท่า
 * ท่าที่มีคลิป (ยืนหายใจ · พูด · โบกมือ) จะเล่นคลิป WebM โปร่งใสแทนภาพนิ่งเมื่อเครื่องรองรับ
 * ความมีชีวิตที่เหลือทำด้วยโค้ด: ลอยขึ้นลง โยกตัว หายใจ เอียง 3 มิติตามเมาส์ เด้งตอนเปลี่ยนท่า
 *
 * ไม่พึ่ง WebGL — ทำงานได้ทุกเครื่อง ถ้าคลิปเล่นไม่ได้ (Safari/iOS ไม่รองรับ VP9 โปร่งใส,
 * ผู้ใช้ขอลดการเคลื่อนไหว, โหมดประหยัดเน็ต) ก็ใช้ภาพนิ่งแทนเงียบๆ
 *
 * Developed by Xman Studio
 */

/** ขนาดฐานของตัวละคร (px CSS) = สัดส่วนภาพ 2:3 ที่ ChatGPT ส่งมา */
export const STAGE_W = 320;
export const STAGE_H = 480;

const V = 1; // เปลี่ยนภาพชื่อเดิมเมื่อไหร่ให้บวกเลขนี้ (Cloudflare แคชไฟล์ใน public_html 1 ปี)

/** ท่านิ่ง: head = ตำแหน่งหัว (สัดส่วนของภาพ) ไว้วางบับเบิ้ลและทิศมอง */
export const POSES = {
    idle: { src: `/images/mascot/idle.webp?v=${V}`, head: [0.49, 0.13] },
    welcome: { src: `/images/mascot/welcome.webp?v=${V}`, head: [0.49, 0.14] },
    point: { src: `/images/mascot/point.webp?v=${V}`, head: [0.56, 0.15] },
    present: { src: `/images/mascot/present.webp?v=${V}`, head: [0.42, 0.12] },
    cheer: { src: `/images/mascot/cheer.webp?v=${V}`, head: [0.5, 0.14] },
    think: { src: `/images/mascot/think.webp?v=${V}`, head: [0.46, 0.16] },
    fly: { src: `/images/mascot/fly.webp?v=${V}`, head: [0.36, 0.26] },
};

/** ภาพหน้าน้อง (อวาตาร์ในบับเบิ้ล/หน้าต่างแชท) */
export const FACE_SRC = `/images/mascot/face.webp?v=${V}`;

/** คลิปวนลูป (จาก Grok) — ไม่มีไฟล์ก็ไม่เป็นไร ใช้ภาพนิ่งของท่านั้นแทน */
export const CLIPS = {
    idle: { src: `/videos/mascot/idle.webm?v=${V}`, pose: 'idle' },
    talk: { src: `/videos/mascot/talk.webm?v=${V}`, pose: 'idle' },
    wave: { src: `/videos/mascot/wave.webm?v=${V}`, pose: 'welcome' },
};

export const ACTIONS = ['idle', 'talk', 'wave', 'point', 'present', 'cheer', 'think', 'fly', 'surprised', 'poke'];

/** ท่าที่จบเองตามเวลา (วินาที) */
export const ACTION_TIME = { wave: 2.8, point: 3.8, present: 4.5, cheer: 2.2, surprised: 1.6, poke: 1.3 };

/** การกระทำ → ภาพนิ่งที่ใช้ */
export const ACTION_POSE = {
    idle: 'idle',
    talk: 'idle',
    wave: 'welcome',
    point: 'point',
    present: 'present',
    cheer: 'cheer',
    think: 'think',
    fly: 'fly',
    surprised: 'think',
    poke: 'cheer',
};

/** การกระทำ → คลิป (ถ้ามี) */
export const ACTION_CLIP = { idle: 'idle', talk: 'talk', wave: 'wave' };

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const damp = (cur, target, k, dt) => cur + (target - cur) * (1 - Math.exp(-k * dt));

/** เครื่องนี้แสดงวิดีโอโปร่งใส (VP9 alpha ใน WebM) ได้ไหม — WebKit ทุกตัวแสดงพื้นดำ จึงตัดทิ้ง */
export function canPlayAlphaVideo(nav = typeof navigator === 'undefined' ? null : navigator) {
    if (!nav) return false;
    const ua = nav.userAgent || '';
    const webkitOnly = /iP(hone|ad|od)/.test(ua) || (/Safari\//.test(ua) && !/Chrome|Chromium|CriOS|Edg|Firefox|FxiOS/.test(ua));
    if (webkitOnly) return false;
    if (nav.connection?.saveData) return false;
    if (typeof document === 'undefined') return false;
    const v = document.createElement('video');
    return v.canPlayType?.('video/webm; codecs="vp09.00.10.08"') !== '' || v.canPlayType?.('video/webm; codecs="vp9"') !== '';
}

/**
 * ตัวคุมท่าทาง — ไม่แตะ DOM (เทสต์ได้ใน node)
 * คำนวณว่าตอนนี้ควรแสดงภาพ/คลิปไหน และค่าการเคลื่อนไหวของตัว
 */
export function createSpriteAnimator(opts = {}) {
    const reduced = !!opts.reduced;
    const calm = reduced ? 0 : 1;
    const hasClip = opts.hasClip || (() => false);

    const s = {
        action: 'idle',
        actionOpts: {},
        actionT: 0,
        until: 0,
        speaking: false,
        mouth: 0,
        look: { x: 0, y: 0 },
        lookSmooth: { x: 0, y: 0 },
        motion: { vx: 0, vy: 0 },
        hop: 0,
        pop: 0,
        lean: 0,
        pose: 'idle',
        clip: null,
        mirror: false,
        facing: -1,
        time: 0,
    };

    function setAction(name, o = {}) {
        if (!ACTIONS.includes(name)) name = 'idle';
        const changed = name !== s.action;
        s.action = name;
        s.actionOpts = o;
        s.actionT = 0;
        s.until = o.hold ?? ACTION_TIME[name] ?? 0;
        if (name === 'cheer' || name === 'poke') s.hop = 1;
        if (changed) s.pop = 1;
    }

    return {
        state: s,
        setAction,
        poke: () => setAction('poke'),
        setSpeaking(on) {
            s.speaking = !!on;
        },
        pulseMouth(level = 1) {
            s.mouth = Math.max(s.mouth, clamp(level, 0, 1));
        },
        setLook(x, y) {
            s.look.x = clamp(x, -1, 1);
            s.look.y = clamp(y, -1, 1);
        },
        setMotion(vx, vy) {
            s.motion.vx = vx;
            s.motion.vy = vy;
        },
        /** เนื้อหาอยู่ฝั่งไหนของน้อง: -1 ซ้าย, 1 ขวา */
        setFacing(dir) {
            s.facing = dir > 0 ? 1 : -1;
        },
        current: () => s.action,
        update(dt) {
            dt = Math.min(dt, 0.1);
            s.time += dt;
            s.actionT += dt;
            if (s.until && s.actionT >= s.until) setAction(s.speaking ? 'talk' : 'idle');
            if (s.speaking && s.action === 'idle') setAction('talk');
            if (!s.speaking && s.action === 'talk') setAction('idle');

            // ภาพ/คลิปของท่านี้
            const clipKey = calm ? ACTION_CLIP[s.action] : null;
            s.clip = clipKey && hasClip(clipKey) ? clipKey : null;
            s.pose = s.clip ? CLIPS[s.clip].pose : ACTION_POSE[s.action];
            // ภาพทุกท่าหัน/ชี้ไปทางซ้าย → เนื้อหาอยู่ขวามือของน้อง (หรือบินไปทางขวา) ต้องพลิก
            s.mirror = s.action === 'fly' ? (s.actionOpts.dir ?? -1) > 0 : s.facing > 0;

            s.hop = Math.max(0, s.hop - dt * 2.4);
            s.pop = Math.max(0, s.pop - dt * 5);
            s.mouth = Math.max(0, s.mouth - dt * 6);
            s.lookSmooth.x = damp(s.lookSmooth.x, s.look.x, 5, dt);
            s.lookSmooth.y = damp(s.lookSmooth.y, s.look.y, 5, dt);
            s.lean = damp(s.lean, clamp(s.motion.vx / 1800, -1, 1), 6, dt);
            return s;
        },
        /** ค่าการเคลื่อนไหวของตัว (หน่วย px ของ stage / องศา) */
        transform() {
            const t = s.time;
            const hop = s.hop > 0 ? Math.sin((1 - s.hop) * Math.PI) * 22 : 0;
            const talkBob = s.clip === 'talk' ? 0 : s.mouth * 3;
            return {
                y: (Math.sin(t * 1.6) * 7 - hop - talkBob) * (calm || 0),
                rot: (Math.sin(t * 1.1) * 1.3 + s.lean * 7) * (calm || 0),
                scaleX: 1 + s.pop * 0.05 * calm,
                scaleY: 1 + (Math.sin(t * 2.3) * 0.008 + s.pop * 0.05 + s.mouth * 0.012) * (calm || 0),
                tiltY: s.lookSmooth.x * 10 * (calm || 0.4),
                tiltX: -s.lookSmooth.y * 6 * (calm || 0.4),
            };
        },
    };
}

/**
 * ผูกตัวคุมท่าทางเข้ากับ DOM
 * @param {HTMLElement} spriteEl กล่องที่มี img[data-pose] และ video[data-clip] อยู่ข้างใน
 * @param {{ reduced?: boolean, clips?: boolean, onFrame?: (dt:number, t:number)=>void, onBroken?: ()=>void }} opts
 */
export function createSpriteStage(spriteEl, opts = {}) {
    const reduced = !!opts.reduced;
    const imgs = {};
    const vids = {};
    const ready = new Set();
    const listeners = [];
    const on = (el, ev, fn) => {
        el.addEventListener(ev, fn);
        listeners.push(() => el.removeEventListener(ev, fn));
    };

    spriteEl.querySelectorAll('img[data-pose]').forEach((img) => {
        imgs[img.dataset.pose] = img;
        on(img, 'error', () => {
            img.dataset.broken = '1';
            // ภาพหลักโหลดไม่ได้ = ไม่มีตัวให้แสดง → ให้คอมโพเนนต์ถอยไปใช้ปุ่มแชทเดิม
            if (img.dataset.pose === 'idle') opts.onBroken?.();
        });
    });
    /** ผูกคลิปที่อยู่ใน DOM ตอนนี้ (เรียกซ้ำได้ — เช่นจอเพิ่งกว้างพอจะเปิดคลิป) */
    function attachClips() {
        spriteEl.querySelectorAll('video[data-clip]').forEach((v) => {
            if (vids[v.dataset.clip] === v) return;
            vids[v.dataset.clip] = v;
            on(v, 'canplay', () => ready.add(v.dataset.clip));
            on(v, 'error', () => ready.delete(v.dataset.clip));
            if (v.readyState >= 3) ready.add(v.dataset.clip);
        });
    }
    if (opts.clips) attachClips();

    const animator = createSpriteAnimator({ reduced, hasClip: (k) => ready.has(k) });

    function load(el) {
        if (el && !el.getAttribute('src') && el.dataset.src) el.src = el.dataset.src;
    }

    /** โหลดล่วงหน้า: ภาพ/คลิปหลักทันที ที่เหลือตอนเครื่องว่าง */
    function preload() {
        load(imgs.idle);
        load(imgs.welcome);
        // ท่าอื่น + คลิป (~1 MB ต่อคลิป) โหลดตอนเครื่องว่าง ไม่แย่งเน็ตกับการเปิดหน้า
        const rest = () => {
            Object.values(imgs).forEach(load);
            Object.values(vids).forEach((v) => {
                load(v);
                v.load?.();
            });
        };
        if ('requestIdleCallback' in window) window.requestIdleCallback(rest, { timeout: 2500 });
        else setTimeout(rest, 1500);
    }

    let shownPose = null;
    let shownClip = null;

    function show(pose, clip) {
        if (pose !== shownPose) {
            load(imgs[pose]);
            for (const [k, img] of Object.entries(imgs)) {
                img.classList.toggle('is-on', k === pose && !clip && img.dataset.broken !== '1');
            }
            // ภาพท่านี้เสีย → ใช้ท่ายืนแทน ดีกว่าหายทั้งตัว
            if (!clip && imgs[pose]?.dataset.broken === '1') imgs.idle?.classList.add('is-on');
            shownPose = pose;
        }
        if (clip !== shownClip) {
            for (const [k, v] of Object.entries(vids)) {
                const onNow = k === clip;
                v.classList.toggle('is-on', onNow);
                if (onNow) {
                    v.currentTime = 0;
                    v.play?.().catch(() => ready.delete(k));
                } else {
                    v.pause?.();
                }
            }
            if (!clip) imgs[pose]?.classList.add('is-on');
            else Object.values(imgs).forEach((img) => img.classList.remove('is-on'));
            shownClip = clip;
        }
    }

    function apply() {
        const s = animator.state;
        show(s.pose, s.clip);
        const m = animator.transform();
        spriteEl.style.transform =
            `perspective(900px) translate3d(0, ${m.y.toFixed(1)}px, 0) rotate(${m.rot.toFixed(2)}deg) ` +
            `rotateY(${m.tiltY.toFixed(2)}deg) rotateX(${m.tiltX.toFixed(2)}deg) ` +
            `scale(${((s.mirror ? -1 : 1) * m.scaleX).toFixed(4)}, ${m.scaleY.toFixed(4)})`;
    }

    /** ตำแหน่งหัวของท่าปัจจุบัน (px ของ stage) */
    function head(out = { x: 0, y: 0 }) {
        const s = animator.state;
        const [hx, hy] = POSES[s.pose]?.head || POSES.idle.head;
        out.x = (s.mirror ? 1 - hx : hx) * STAGE_W;
        out.y = hy * STAGE_H + animator.transform().y;
        return out;
    }

    let raf = 0;
    let running = false;
    let last = 0;
    let time = 0;

    function frame(now) {
        raf = requestAnimationFrame(frame);
        const dt = last ? Math.min(0.1, (now - last) / 1000) : 1 / 60;
        last = now;
        step(dt);
    }

    function step(dt) {
        time += dt;
        opts.onFrame?.(dt, time);
        animator.update(dt);
        apply();
    }

    function start() {
        if (running) return;
        running = true;
        last = 0;
        raf = requestAnimationFrame(frame);
    }

    function stop() {
        running = false;
        cancelAnimationFrame(raf);
        Object.values(vids).forEach((v) => v.pause?.());
    }

    const onVisibility = () => {
        if (document.hidden) stop();
        else {
            start();
            if (shownClip) vids[shownClip]?.play?.().catch(() => {});
        }
    };
    document.addEventListener('visibilitychange', onVisibility);

    preload();

    return {
        animator,
        head,
        attachClips() {
            attachClips();
            const rest = () => Object.values(vids).forEach((v) => {
                load(v);
                v.load?.();
            });
            if ('requestIdleCallback' in window) window.requestIdleCallback(rest, { timeout: 2500 });
            else setTimeout(rest, 1500);
        },
        start,
        stop,
        renderOnce: (dt = 1 / 60) => step(dt),
        dispose() {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
            listeners.forEach((off) => off());
            Object.values(vids).forEach((v) => {
                v.removeAttribute('src');
                v.load?.();
            });
        },
    };
}
