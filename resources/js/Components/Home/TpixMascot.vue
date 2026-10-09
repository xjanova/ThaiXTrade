<script setup>
/**
 * TPIX TRADE — น้อง TPIX มาสคอตผู้นำทางบนหน้าแรก
 *
 * ตัวน้องเป็นภาพสไตล์ 3D เรนเดอร์ที่เจนจาก ChatGPT (ท่าละภาพ พื้นหลังโปร่งใส)
 * + คลิปเคลื่อนไหวจาก Grok (ยืนหายใจ · พูด · โบกมือ) — ตัวขับอยู่ใน spriteStage.js
 *
 * - ยืนที่ "จุดยืน" ของส่วนที่กำลังดู (องค์ประกอบ [data-mascot-stage].is-active ที่หน้าเป็นคนเปิด)
 *   เปลี่ยนส่วนเมื่อไหร่ บินไปจุดใหม่ แล้วพูดแนะนำส่วนนั้น (ส่วนละครั้ง)
 * - ไม่มีจุดยืน (เช่นเลื่อนถึง footer) → จอดมุมขวาล่าง
 * - บับเบิ้ลคำพูดมีช่องถาม → ส่งไปบอท AI ตัวเดียวกับหน้าต่างแชท (ประวัติร่วมกัน)
 * - ปุ่ม "ทัวร์" พาไล่ดูทุกส่วนทีละขั้น ผู้ใช้กด "ถัดไป" เอง ไม่แย่งการเลื่อนจอ
 * - ผู้ใช้เงียบไป น้องขยับเล่น → หาว → สัปหงก → หลับ (มี Zzz) · ขยับเมาส์/เลื่อน/แตะ = ตื่น (mascotIdle.js)
 *
 * ภาพหลักโหลดไม่ได้ → น้องหายไปเงียบๆ และปุ่มแชทลอยแบบเดิมกลับมาทำหน้าที่แทน
 *
 * Developed by Xman Studio
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTranslation } from '@/Composables/useTranslation';
import { useChatbot } from '@/Composables/useChatbot';
import { useMascot } from '@/Composables/useMascot';
import { whenSplashGone } from '@/Composables/useHomeSplash';
import { sfx } from '@/Components/Home3D/sound';
import {
    SECTIONS, TOUR_ORDER, POKE_LINES,
    dockHeight, placeBubble, graphemes, mouthFor, typeSpeed, cleanReply,
} from './mascotGuide';
import { STAGE_W, STAGE_H, POSES, CLIPS, FACE_SRC, createSpriteStage, canPlayAlphaVideo } from './spriteStage';
import { createIdleDirector, IDLE_TIMING } from './mascotIdle';

const props = defineProps({
    // จุดยืนของน้อง (กล่องว่างในหน้า) — ใส่ data-face="left|right" บอกว่าเนื้อหาอยู่ฝั่งไหนของน้อง
    stageSelector: { type: String, default: '[data-mascot-stage].is-active' },
    // ส่วนที่กำลังดู (หน้า 3D บอกเองจากตำแหน่งกล้อง) — null = ตรวจเองด้วย [data-guide]
    section: { type: String, default: null },
});

const { t, locale } = useTranslation();
const chat = useChatbot();
const mascot = useMascot();
// เข้าหน้าที่มีน้อง (รวมกลับมาจากหน้าอื่น) = น้องกลับมาเองเสมอ การซ่อนมีผลแค่รอบที่ดูอยู่
// ตั้งก่อน watch(mascot.hidden) ข้างล่าง → ไม่ไปกระตุ้นตัวเฝ้าดู แค่เริ่มต้นแบบแสดงตัว
mascot.hidden.value = false;

const bodyEl = ref(null);
const spriteEl = ref(null);
const hitEl = ref(null);
const bubbleEl = ref(null);
const inputEl = ref(null);

const ready = ref(false);
const failed = ref(false);
const flying = ref(false);
const collapsed = ref(false);
const bubbleSide = ref('left');
const askText = ref('');
const tourIndex = ref(-1);

const bubble = reactive({
    kind: 'line', // line | chat | thinking
    lineKey: null,
    full: '',
    shown: '',
    typing: false,
    visible: false,
    question: '',
    chips: [],
});

const showRoot = computed(() => !mascot.hidden.value && !failed.value);

// คลิปเคลื่อนไหวโหลดเฉพาะจอใหญ่ที่เล่นวิดีโอโปร่งใสได้ (มือถือเห็นน้องตัวเล็กมุมจอ ใช้ภาพนิ่งพอ)
const useClips = ref(false);

// ── สถานะที่ไม่ต้อง reactive (อัปเดตทุกเฟรม) ─────────────────────────────
let stage = null;
let animator = null;
let booting = false;
let alive = true;
let reduced = false;
let mode = null; // 'spot' | 'dock'
let spotKey = null; // จุดยืนที่ยืนอยู่ (element) หรือ 'dock'
let facing = -1; // -1 = เนื้อหาอยู่ทางซ้ายของน้อง, 1 = ทางขวา
let box = null; // { x, y, h } ตำแหน่งปัจจุบันบนจอ
let flight = null;
let glyphs = [];
let typed = 0;
let typedF = 0;
let speed = 40;
let bubbleDirty = true;
let bubbleW = 300;
let bubbleH = 120;
let lastSide = 'left';
let lastPose = null;
let collapseTimer = 0;
let greetTimer = 0;
let lastChatAt = 0;
let pokeN = 0;
let bubbleHover = false;
let currentSection = null;
let sectionObserver = null;
let pendingTour = false;
let pendingRecall = false;
const spoken = new Set();
const pointer = { x: 0, y: 0, at: 0 };
// ท่าว่าง: ผู้ใช้เงียบนานแค่ไหน น้องทำอะไร
const idle = createIdleDirector();
let wokeAt = 0;
let warmedFidgets = false;
let warmedSleep = false;
let zzzAcc = 0;
const headPt = { x: 0, y: 0 };

const NAV_TOP = 64;

/** ขอบล่างของแถบนำทางจริง (มีแถบราคา/ป้ายโฆษณาด้านบนที่เลื่อนหายได้) — บับเบิ้ลห้ามทับ */
function navBottom() {
    const nav = document.querySelector('nav');
    return nav ? Math.max(0, Math.round(nav.getBoundingClientRect().bottom)) : NAV_TOP;
}

async function boot() {
    if (stage || booting || !alive || mascot.hidden.value) return;
    booting = true;
    reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    useClips.value = !reduced && window.innerWidth >= 768 && canPlayAlphaVideo();
    await nextTick();
    booting = false;
    if (!alive || mascot.hidden.value || !spriteEl.value) return;
    stage = createSpriteStage(spriteEl.value, {
        reduced,
        clips: useClips.value,
        onFrame,
        onBroken: () => {
            markFailed();
            teardown();
        },
    });

    animator = stage.animator;
    // ช่องทางดีบักตอนพัฒนาเท่านั้น (build จริงตัดทิ้ง)
    if (import.meta.env.DEV) window.__tpixMascot = { stage, animator, idle, say, startTour, bubble, spoken, isBusy, get section() { return currentSection; } };
    mode = null;
    box = null;
    ready.value = true;
    mascot.active.value = true;
    stage.start();
    if (props.section === null) observeSections();
    else currentSection = props.section;

    // จอโหลดหน้าแรกยังบังอยู่ → เริ่มนับเวลาทักหลังจอหาย ไม่งั้นน้องพูดประโยคแรกจบใต้จอโหลด ผู้ใช้ไม่เห็น
    // (ไม่มีจอโหลด = ทักตามเวลาเดิม) · ผูกกับ stage รอบนี้ — ถูกซ่อน/บูตใหม่ระหว่างรอ ห้ามทักซ้อนสองรอบ
    const bootedStage = stage;
    whenSplashGone().then(() => {
        if (!alive || stage !== bootedStage) return;
        greetTimer = setTimeout(greet, 650);
    });
}

function greet() {
    if (pendingTour) {
        pendingTour = false;
        startTour();
    } else if (pendingRecall) {
        // ผู้ใช้กดเรียกกลับมา → โบกมือทักก่อน (บทแนะนำส่วนนี้ค่อยพูดรอบหน้า)
        pendingRecall = false;
        sfx.chime();
        showLine({ kind: 'line', lineKey: 'mascot.backLine', chips: [{ label: 'mascot.chips.tour', action: 'start-tour' }], pose: 'wave' });
    } else if (currentSection) {
        say(currentSection);
    }
}

function markFailed() {
    failed.value = true;
    mascot.supported.value = false;
    mascot.active.value = false;
}

function teardown() {
    clearTimeout(collapseTimer);
    clearTimeout(greetTimer);
    sectionObserver?.disconnect();
    sectionObserver = null;
    stage?.dispose();
    stage = null;
    animator = null;
    ready.value = false;
    mascot.active.value = false;
}

// ── ตำแหน่งบนจอ ─────────────────────────────────────────────────────────
function spotBox(vh) {
    const el = document.querySelector(props.stageSelector);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    if (r.width < 40 || r.height < 60 || r.bottom < 0 || r.top > vh) return null;
    const h = Math.min(r.height, 540);
    const w = (h * STAGE_W) / STAGE_H;
    return { x: r.left + (r.width - w) / 2, y: r.bottom - h, h, mode: 'spot', key: el, face: el.dataset.face === 'right' ? 1 : -1 };
}

function dockBox(vw, vh) {
    const h = dockHeight(vw);
    const w = (h * STAGE_W) / STAGE_H;
    return { x: vw - w - (vw < 640 ? 2 : 12), y: vh - h - 4, h, mode: 'dock', key: 'dock', face: -1 };
}

const ease = (x) => (x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2);

function onFrame(dt) {
    if (!bodyEl.value) return;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const target = spotBox(vh) || dockBox(vw, vh);

    if (target.key !== spotKey) {
        const first = spotKey === null;
        spotKey = target.key;
        mode = target.mode;
        facing = target.face;
        animator.setFacing?.(facing);
        if (!first && !reduced && box) {
            flight = { t: 0, dur: 0.95, from: { ...box } };
            flying.value = true;
            animator.setAction('fly', { dir: target.x >= box.x ? 1 : -1, hold: 60 });
            sfx.fly(target.x >= box.x ? 1 : -1);
        }
    }

    let b = target;
    if (flight) {
        flight.t = Math.min(1, flight.t + dt / flight.dur);
        const e = ease(flight.t);
        const f = flight.from;
        b = {
            x: f.x + (target.x - f.x) * e,
            y: f.y + (target.y - f.y) * e - Math.sin(Math.PI * flight.t) * Math.min(170, vh * 0.2),
            h: f.h + (target.h - f.h) * e,
        };
        if (flight.t >= 1) {
            flight = null;
            flying.value = false;
            onLanded();
        }
    }

    const prev = box;
    box = { x: b.x, y: b.y, h: b.h };
    if (prev && dt > 0) animator.setMotion((box.x - prev.x) / dt, (box.y - prev.y) / dt);

    const s = box.h / STAGE_H;
    const w = STAGE_W * s;
    bodyEl.value.style.transform = `translate3d(${box.x.toFixed(1)}px, ${box.y.toFixed(1)}px, 0) scale(${s.toFixed(4)})`;
    if (hitEl.value) {
        hitEl.value.style.transform = `translate3d(${(box.x + w * 0.25).toFixed(1)}px, ${(box.y + box.h * 0.06).toFixed(1)}px, 0) scale(${s.toFixed(4)})`;
    }
    if (flight && !reduced) sparkTrail(box.x + w * 0.5, box.y + box.h * 0.9, dt);

    // หัวน้องอยู่ตรงไหนบนจอ → ทิศมอง + ตำแหน่งบับเบิ้ล
    stage.head(headPt);
    const hx = box.x + headPt.x * s;
    const hy = box.y + headPt.y * s;
    if (performance.now() - pointer.at < 5000) {
        animator.setLook((pointer.x - hx) / (vw * 0.3), (pointer.y - hy) / (vh * 0.35));
    } else {
        animator.setLook(facing * 0.3, 0.08);
    }

    stepTyping(dt);
    positionBubble(hx, hy, w, vw, vh);
    stepIdle(dt, hx, hy, s);
}

// ── ท่าว่าง (หาว/หลับ/ขยับเล่น) ─────────────────────────────────────────
const SLEEPING = new Set(['doze', 'sleep']);

function stepIdle(dt, hx, hy, scale) {
    const cur = animator.current();
    // ถูกปลุกด้วยอย่างอื่น (เปลี่ยนส่วน → บิน/พูด) ระหว่างหลับ → ถือว่าตื่นแล้ว ไม่ต้องเล่นท่าตื่นซ้ำ
    if (idle.asleep && !SLEEPING.has(cur) && cur !== 'wake') idle.activity();
    const busy = !!flight || bubble.typing || tourIndex.value >= 0 || chat.isOpen.value || isBusy();
    const next = idle.update(dt, { busy, acting: cur !== 'idle' });
    if (next) playIdle(next);

    // คลิปท่าว่างโหลดเมื่อผู้ใช้เริ่มเงียบ (คนที่ไม่เคยอยู่นิ่งไม่ต้องเสียเน็ต)
    if (useClips.value && stage) {
        if (!warmedFidgets && idle.quiet > 5) {
            warmedFidgets = true;
            stage.warm(['look', 'stretch', 'twirl']);
        }
        if (!warmedSleep && idle.quiet > IDLE_TIMING.yawnAt - 15) {
            warmedSleep = true;
            stage.warm(['yawn', 'doze', 'sleep', 'wake']);
        }
    }

    if (SLEEPING.has(cur) && !reduced) zzz(dt, hx, hy, scale);
}

function playIdle(name) {
    lastPose = null;
    if (name === 'doze') {
        // จะหลับแล้ว: เก็บบับเบิ้ลบทแนะนำ ไม่ให้ค้างบังจอทั้งที่น้องหลับ
        if (bubble.visible && !collapsed.value && bubble.kind === 'line') collapse();
    }
    if (name === 'yawn') sfx.yawn();
    animator.setAction(name);
}

/** ผู้ใช้กลับมา (ขยับเมาส์/เลื่อน/แตะ/กดคีย์) */
function onActivity() {
    if (idle.activity() !== 'wake' || !animator) return;
    wokeAt = performance.now();
    zzzAcc = 0;
    sfx.wake();
    animator.setAction('wake');
}

// Zzz ลอยจากหัวตอนหลับ — สร้าง/ลบเอง (Web Animations API) เหมือนประกายตอนบิน
function zzz(dt, hx, hy, scale) {
    zzzAcc += dt;
    if (zzzAcc < 1.25) return;
    zzzAcc = 0;
    const el = document.createElement('i');
    const big = Math.random() < 0.5;
    const dir = facing > 0 ? 1 : -1;
    el.textContent = big ? 'Z' : 'z';
    el.setAttribute('aria-hidden', 'true');
    el.style.cssText =
        `position:fixed;left:${(hx - dir * 26 * scale).toFixed(0)}px;top:${(hy - 30 * scale).toFixed(0)}px;` +
        `font:italic 800 ${Math.round((big ? 26 : 18) * Math.max(0.6, scale))}px/1 ui-rounded,system-ui,sans-serif;` +
        'color:#bfeaff;text-shadow:0 0 10px rgb(34 211 238 / 0.9);pointer-events:none;z-index:46';
    document.body.appendChild(el);
    el.animate(
        [
            { transform: 'translate(-50%,-50%) scale(0.5) rotate(-12deg)', opacity: 0 },
            { transform: `translate(${-50 - dir * 40}%, -140%) scale(1) rotate(0deg)`, opacity: 1, offset: 0.3 },
            { transform: `translate(${-50 - dir * 130}%, -330%) scale(1.15) rotate(10deg)`, opacity: 0 },
        ],
        { duration: 2400, easing: 'ease-out' },
    ).onfinish = () => el.remove();
}

// ประกายวิ่งตามหลังตอนบิน — สร้าง/ลบเอง ไม่ต้องมี CSS (Web Animations API)
let sparkAcc = 0;
function sparkTrail(x, y, dt) {
    sparkAcc += dt;
    if (sparkAcc < 0.035) return;
    sparkAcc = 0;
    const el = document.createElement('i');
    const size = 4 + Math.random() * 7;
    const hue = Math.random() < 0.6 ? '190 95% 65%' : '45 95% 62%';
    el.setAttribute('aria-hidden', 'true');
    el.style.cssText = `position:fixed;left:${x.toFixed(0)}px;top:${(y + (Math.random() - 0.5) * 16).toFixed(0)}px;width:${size}px;height:${size}px;border-radius:999px;pointer-events:none;z-index:44;background:hsl(${hue});box-shadow:0 0 10px hsl(${hue})`;
    document.body.appendChild(el);
    el.animate(
        [
            { transform: 'translate(-50%,-50%) scale(1)', opacity: 1 },
            { transform: `translate(${(-50 + (Math.random() - 0.5) * 60).toFixed(0)}%, ${(30 + Math.random() * 60).toFixed(0)}%) scale(0.2)`, opacity: 0 },
        ],
        { duration: 800, easing: 'ease-out' },
    ).onfinish = () => el.remove();
}

function positionBubble(hx, hy, w, vw, vh) {
    const el = bubbleEl.value;
    if (!el || !bubble.visible) return;
    if (bubbleDirty) {
        bubbleW = el.offsetWidth || bubbleW;
        bubbleH = el.offsetHeight || bubbleH;
        bubbleDirty = false;
    }
    const p = placeBubble({
        head: { x: hx, y: hy },
        box: { x: box.x, y: box.y, w, h: box.h },
        bubble: { w: bubbleW, h: bubbleH },
        vw,
        vh,
        top: navBottom(),
        prefer: facing > 0 ? 'right' : 'left',
    });
    el.style.transform = `translate3d(${Math.round(p.x)}px, ${Math.round(p.y)}px, 0)`;
    el.style.setProperty('--tail', `${Math.round(p.tail)}px`);
    if (p.side !== lastSide) {
        lastSide = p.side;
        bubbleSide.value = p.side;
    }
}

function onLanded() {
    sfx.land();
    if (lastPose && (bubble.typing || performance.now() - lastPose.at < 6000)) {
        animator.setAction(lastPose.name, lastPose.opts);
    } else {
        animator.setAction('idle');
    }
    if (tourIndex.value < 0 && currentSection) say(currentSection);
}

// ── พูด ─────────────────────────────────────────────────────────────────
function poseOpts() {
    return {};
}

function showLine({ kind = 'line', lineKey = null, text = '', chips = [], question = '', pose = null, opts = null }) {
    const full = lineKey ? t(lineKey) : text;
    bubble.kind = kind;
    bubble.lineKey = lineKey;
    bubble.full = full;
    bubble.question = question;
    bubble.chips = chips;
    bubble.visible = true;
    collapsed.value = false;
    clearTimeout(collapseTimer);
    glyphs = graphemes(full);
    typed = 0;
    typedF = 0;
    speed = typeSpeed(glyphs.length);
    if (reduced || !animator) {
        bubble.shown = full;
        bubble.typing = false;
        onLineDone();
    } else {
        bubble.shown = '';
        bubble.typing = true;
        animator.setSpeaking(true);
    }
    if (pose && animator) {
        const o = opts || poseOpts(pose);
        animator.setAction(pose, o);
        lastPose = { name: pose, opts: o, at: performance.now() };
    }
    bubbleDirty = true;
}

function stepTyping(dt) {
    if (!bubble.typing) return;
    typedF += speed * dt;
    const n = Math.min(glyphs.length, Math.floor(typedF));
    if (n > typed) {
        for (let i = typed; i < n; i++) {
            const m = mouthFor(glyphs[i]);
            animator.pulseMouth(m);
            sfx.pip(m);
        }
        typed = n;
        bubble.shown = glyphs.slice(0, n).join('');
        bubbleDirty = true;
    }
    if (n >= glyphs.length) finishTyping();
}

function finishTyping() {
    if (!bubble.typing) return;
    bubble.typing = false;
    bubble.shown = bubble.full;
    animator?.setSpeaking(false);
    bubbleDirty = true;
    onLineDone();
}

function onLineDone() {
    clearTimeout(collapseTimer);
    // บทแนะนำพับเก็บเองเมื่ออ่านจบ ไม่บังเนื้อหา · คำตอบแชทค้างไว้ให้อ่าน
    if (bubble.kind === 'line' && tourIndex.value < 0) {
        collapseTimer = setTimeout(() => {
            if (!isBusy()) collapse();
        }, 9000);
    }
}

function isBusy() {
    return (
        chat.isLoading.value ||
        askText.value.trim() !== '' ||
        (inputEl.value && document.activeElement === inputEl.value) ||
        bubbleHover ||
        (bubble.kind !== 'line' && Date.now() - lastChatAt < 25000)
    );
}

/** พูดบทของส่วนนั้น (ส่วนละครั้ง เว้นแต่บังคับ) */
function say(key, { force = false } = {}) {
    const sec = SECTIONS[key];
    if (!sec || !animator) return false;
    if (!force && (spoken.has(key) || isBusy())) return false;
    spoken.add(key);
    showLine({ kind: 'line', lineKey: sec.line, chips: sec.chips, pose: sec.pose });
    return true;
}

function collapse() {
    if (bubble.typing) finishTyping();
    collapsed.value = true;
    bubbleDirty = true;
}

function expand(focus = false) {
    collapsed.value = false;
    bubble.visible = true;
    bubbleDirty = true;
    clearTimeout(collapseTimer);
    if (focus && window.matchMedia?.('(pointer: fine)').matches) {
        nextTick(() => inputEl.value?.focus({ preventScroll: true }));
    }
}

// ── ติดตามส่วนของหน้าที่กำลังดู ─────────────────────────────────────────
function observeSections() {
    sectionObserver?.disconnect();
    const visible = new Map();
    sectionObserver = new IntersectionObserver(
        (entries) => {
            for (const e of entries) visible.set(e.target.dataset.guide, e.isIntersecting);
            const key = TOUR_ORDER.find((k) => visible.get(k));
            if (!key || key === currentSection) return;
            currentSection = key;
            if (tourIndex.value >= 0 || flight || !ready.value) return;
            say(key);
        },
        { rootMargin: '-35% 0px -45% 0px', threshold: 0 },
    );
    document.querySelectorAll('[data-guide]').forEach((el) => sectionObserver.observe(el));
}

// ── ทัวร์ ────────────────────────────────────────────────────────────────
function startTour() {
    if (!animator) {
        pendingTour = true;
        return;
    }
    tourIndex.value = 0;
    tourStep();
}

function tourStep() {
    const i = tourIndex.value;
    const key = TOUR_ORDER[i];
    const sec = SECTIONS[key];
    if (!sec) return endTour();
    document.querySelector(`[data-guide="${key}"]`)?.scrollIntoView({
        behavior: reduced ? 'auto' : 'smooth',
        block: key === 'hero' ? 'start' : 'center',
    });
    const last = i === TOUR_ORDER.length - 1;
    const chips = [
        ...sec.chips.filter((c) => c.action !== 'start-tour'),
        last
            ? { label: 'mascot.tour.finish', action: 'tour-end' }
            : { label: 'mascot.tour.next', action: 'tour-next', params: { n: i + 2, total: TOUR_ORDER.length } },
    ];
    if (!last) chips.push({ label: 'mascot.tour.end', action: 'tour-end' });
    spoken.add(key);
    showLine({ kind: 'line', lineKey: sec.line, chips, pose: last ? 'cheer' : sec.pose });
}

function endTour() {
    tourIndex.value = -1;
    showLine({
        kind: 'line',
        lineKey: 'mascot.tour.bye',
        chips: [{ label: 'mascot.chips.startTrading', href: '/trade' }],
        pose: 'wave',
    });
}

// ── การกระทำของผู้ใช้ ───────────────────────────────────────────────────
let lastChipAt = 0;
function onChip(chip) {
    // กดเบิ้ล (ดับเบิลคลิก/นิ้วแตะซ้ำ) ต้องไม่ข้ามขั้นทัวร์
    const now = performance.now();
    if (now - lastChipAt < 400) return;
    lastChipAt = now;
    if (chip.action === 'tour-next') sfx.chime();
    else sfx.click();
    if (chip.href) {
        router.visit(chip.href);
        return;
    }
    switch (chip.action) {
        case 'start-tour':
            startTour();
            break;
        case 'tour-next':
            tourIndex.value = Math.min(TOUR_ORDER.length - 1, tourIndex.value + 1);
            tourStep();
            break;
        case 'tour-end':
            endTour();
            break;
        case 'open-chat':
            chat.open();
            break;
    }
}

function onPoke() {
    if (!animator) return;
    onActivity();
    // เพิ่งถูกปลุก (แตะตัวน้องตอนหลับ) → งัวเงียขอโทษ แทนบทจิ้มปกติ
    if (performance.now() - wokeAt < 900) {
        showLine({ kind: 'line', lineKey: 'mascot.wakeLine', chips: [{ label: 'mascot.chips.tour', action: 'start-tour' }] });
        expand(true);
        return;
    }
    sfx.poke();
    animator.poke();
    lastPose = { name: 'poke', opts: {}, at: performance.now() };
    const chatting = bubble.kind !== 'line' && Date.now() - lastChatAt < 25000;
    if (chatting || chat.isLoading.value) {
        expand(true);
        return;
    }
    showLine({
        kind: 'line',
        lineKey: POKE_LINES[pokeN++ % POKE_LINES.length],
        chips: [{ label: 'mascot.chips.tour', action: 'start-tour' }],
    });
    expand(true);
}

async function ask() {
    const text = askText.value.trim();
    if (!text || chat.isLoading.value) return;
    askText.value = '';
    tourIndex.value = -1;
    lastChatAt = Date.now();
    bubble.kind = 'thinking';
    bubble.lineKey = null;
    bubble.question = text;
    bubble.full = t('mascot.thinking');
    bubble.shown = bubble.full;
    bubble.typing = false;
    bubble.chips = [];
    bubble.visible = true;
    collapsed.value = false;
    bubbleDirty = true;
    clearTimeout(collapseTimer);
    animator?.setSpeaking(false);
    animator?.setAction('think', { hold: 60 });

    const reply = await chat.send(text, { language: locale.value, persona: 'mascot', errorText: t('mascot.error') });
    if (!alive || !reply) return;
    lastChatAt = Date.now();
    const chips = [];
    if (reply.navUrl) chips.push({ label: 'mascot.goThere', href: reply.navUrl });
    chips.push({ label: 'mascot.openChat', action: 'open-chat' });
    showLine({
        kind: 'chat',
        text: cleanReply(reply.text) || t('mascot.error'),
        chips,
        question: text,
        pose: reply.failed ? 'surprised' : reply.navUrl ? 'point' : 'idle',
    });
    if (!reply.failed) sfx.reply();
}

function onPointerMove(e) {
    pointer.x = e.clientX;
    pointer.y = e.clientY;
    pointer.at = performance.now();
    onActivity();
}

const ACTIVITY_EVENTS = ['scroll', 'wheel', 'keydown', 'touchstart'];

function onResize() {
    bubbleDirty = true;
    // จอเพิ่งกว้างพอ (หมุนแท็บเล็ต/ขยายหน้าต่าง) → เปิดคลิปเคลื่อนไหวทีหลังได้
    if (stage && !useClips.value && !reduced && window.innerWidth >= 768 && canPlayAlphaVideo()) {
        useClips.value = true;
        nextTick(() => stage?.attachClips());
    }
}

// ── วงจรชีวิต ────────────────────────────────────────────────────────────
onMounted(() => {
    mascot.present.value = true;
    window.addEventListener('pointermove', onPointerMove, { passive: true });
    window.addEventListener('resize', onResize, { passive: true });
    ACTIVITY_EVENTS.forEach((ev) => window.addEventListener(ev, onActivity, { passive: true }));
    if (!mascot.hidden.value) requestAnimationFrame(() => boot());
});

onBeforeUnmount(() => {
    alive = false;
    mascot.present.value = false;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('resize', onResize);
    ACTIVITY_EVENTS.forEach((ev) => window.removeEventListener(ev, onActivity));
    teardown();
});

watch(mascot.hidden, (h) => {
    if (h) teardown();
    else nextTick(() => boot());
});

// หน้า 3D บอกส่วนปัจจุบันมาเอง (ตามตำแหน่งกล้อง)
watch(
    () => props.section,
    (key) => {
        if (!key || key === currentSection) return;
        currentSection = key;
        // กำลังบินไปจุดใหม่ → พูดตอนลงจอด (onLanded) ไม่พูดกลางอากาศ
        if (tourIndex.value >= 0 || flight || !ready.value) return;
        say(key);
    },
);

watch(mascot.recallRequests, () => {
    if (animator) {
        // เรียกซ้ำตอนน้องอยู่บนจออยู่แล้ว = ทักเลย
        showLine({ kind: 'line', lineKey: 'mascot.backLine', chips: [{ label: 'mascot.chips.tour', action: 'start-tour' }], pose: 'wave' });
        expand();
    } else {
        pendingRecall = true;
    }
});

watch(mascot.tourRequests, () => {
    if (animator) startTour();
    else pendingTour = true;
});

// สลับภาษาระหว่างที่บทแนะนำค้างอยู่ → เปลี่ยนเป็นภาษาใหม่ทันที
watch(locale, () => {
    if (bubble.lineKey) {
        bubble.full = t(bubble.lineKey);
        bubble.shown = bubble.full;
        if (bubble.typing) {
            bubble.typing = false;
            animator?.setSpeaking(false);
        }
    }
    if (bubble.kind === 'thinking') bubble.full = bubble.shown = t('mascot.thinking');
    bubbleDirty = true;
});

watch(collapsed, () => {
    bubbleDirty = true;
});

function hideMascot() {
    sfx.click();
    mascot.hide();
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="showRoot"
            class="tpix-mascot"
            :class="{ 'is-ready': ready, 'is-flying': flying, 'is-chat-open': chat.isOpen.value }"
        >
            <div ref="bodyEl" class="tpix-mascot__body" aria-hidden="true">
                <div ref="spriteEl" class="tpix-mascot__sprite">
                    <img
                        v-for="(p, key) in POSES"
                        :key="key"
                        :data-pose="key"
                        :data-src="p.src"
                        alt=""
                        draggable="false"
                        decoding="async"
                        class="tpix-mascot__pose"
                    />
                    <template v-if="useClips">
                        <video
                            v-for="(c, key) in CLIPS"
                            :key="'clip-' + key"
                            :data-clip="key"
                            :data-src="c.src"
                            muted
                            :loop="!c.once"
                            playsinline
                            disablepictureinpicture
                            preload="none"
                            class="tpix-mascot__pose"
                            :class="{ 'is-pad': c.pad }"
                        ></video>
                    </template>
                </div>
            </div>

            <button
                ref="hitEl"
                type="button"
                class="tpix-mascot__hit"
                :aria-label="t('mascot.talkTo')"
                :title="t('mascot.talkTo')"
                @click="onPoke"
            />

            <div
                ref="bubbleEl"
                class="tpix-mascot__bubble"
                :class="[`is-${bubbleSide}`, { 'is-collapsed': collapsed, 'is-off': !bubble.visible || !ready }]"
                role="dialog"
                :aria-label="t('mascot.name')"
                @pointerenter="bubbleHover = true"
                @pointerleave="bubbleHover = false"
                @keydown.esc="collapse"
            >
                <button
                    v-if="collapsed"
                    type="button"
                    class="flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-white"
                    @click="expand(true)"
                >
                    <span class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></span>
                    {{ t('mascot.askPill') }}
                </button>

                <div v-else class="p-3.5">
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <p class="flex items-center gap-2 text-[11px] font-bold tracking-wide text-primary-300">
                            <span class="relative">
                                <img :src="FACE_SRC" alt="" class="w-7 h-7 rounded-full object-cover bg-primary-500/15 ring-1 ring-primary-400/40" />
                                <span class="absolute -bottom-0.5 -right-0.5 w-2 h-2 rounded-full bg-trading-green ring-2 ring-dark-900"></span>
                            </span>
                            {{ t('mascot.name') }}
                        </p>
                        <div class="flex items-center gap-0.5">
                            <button
                                type="button"
                                class="tpix-mascot__icon"
                                :title="t('mascot.openChat')"
                                :aria-label="t('mascot.openChat')"
                                @click="chat.open()"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                            </button>
                            <button
                                type="button"
                                class="tpix-mascot__icon"
                                :title="t('mascot.minimize')"
                                :aria-label="t('mascot.minimize')"
                                @click="collapse"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2.5" d="M5 12h14"/></svg>
                            </button>
                            <button
                                type="button"
                                class="tpix-mascot__icon"
                                :title="t('mascot.hide')"
                                :aria-label="t('mascot.hide')"
                                @click="hideMascot"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>

                    <p v-if="bubble.question" class="text-[11px] text-dark-400 mb-1 line-clamp-2">
                        “{{ bubble.question }}”
                    </p>

                    <div class="tpix-mascot__text thin-scrollbar" @click="finishTyping">
                        <p class="text-[13.5px] leading-relaxed text-white whitespace-pre-wrap" aria-hidden="true">{{ bubble.shown }}<span v-if="bubble.typing" class="tpix-mascot__caret"></span></p>
                        <span v-if="bubble.kind === 'thinking'" class="tpix-mascot__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                    </div>
                    <p class="sr-only" aria-live="polite">{{ bubble.typing ? '' : bubble.full }}</p>

                    <div v-if="!bubble.typing && bubble.chips.length" class="flex flex-wrap gap-1.5 mt-2.5">
                        <button
                            v-for="chip in bubble.chips"
                            :key="chip.label + (chip.href || chip.action)"
                            type="button"
                            class="tpix-mascot__chip"
                            :class="{ 'is-primary': chip.action === 'tour-next' || chip.label === 'mascot.goThere' }"
                            @click="onChip(chip)"
                        >
                            {{ t(chip.label, chip.params || {}) }}
                        </button>
                    </div>

                    <form class="flex items-center gap-1.5 mt-3" @submit.prevent="ask">
                        <input
                            ref="inputEl"
                            v-model="askText"
                            type="text"
                            maxlength="500"
                            enterkeyhint="send"
                            :placeholder="t('mascot.askPlaceholder')"
                            :aria-label="t('mascot.askPlaceholder')"
                            :disabled="chat.isLoading.value"
                            class="flex-1 min-w-0 bg-dark-800/80 border border-white/10 rounded-xl px-3 py-2 text-[13px] text-white placeholder-dark-500 focus:border-primary-500 outline-none disabled:opacity-60"
                        />
                        <button
                            type="submit"
                            class="shrink-0 w-9 h-9 rounded-xl bg-primary-500 text-white flex items-center justify-center hover:bg-primary-400 disabled:opacity-40 transition-colors"
                            :disabled="!askText.trim() || chat.isLoading.value"
                            :aria-label="t('mascot.send')"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </form>
                </div>
                <span class="tpix-mascot__tail" aria-hidden="true"></span>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.tpix-mascot {
    position: fixed;
    left: 0;
    top: 0;
    width: 0;
    height: 0;
    z-index: 45;
}

/* กล่องตัวน้อง: ขนาดฐาน 320×480 (สัดส่วนภาพ 2:3) แล้วย่อ/ขยายด้วย scale */
.tpix-mascot__body {
    position: fixed;
    left: 0;
    top: 0;
    width: 320px;
    height: 480px;
    transform-origin: 0 0;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.6s ease;
    will-change: transform;
}

.is-ready .tpix-mascot__body {
    opacity: 1;
}

.tpix-mascot__sprite {
    position: absolute;
    inset: 0;
    transform-origin: 50% 88%;
    will-change: transform;
    filter: drop-shadow(0 14px 18px rgb(0 0 0 / 0.35));
}

.tpix-mascot__pose {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: contain;
    opacity: 0;
    transition: opacity 0.16s ease;
    user-select: none;
    -webkit-user-drag: none;
}

.tpix-mascot__pose.is-on {
    opacity: 1;
}

/* คลิปที่มีขอบเผื่อ 8% ทุกด้าน: ขยายกล่องออก 8% ให้ตัวน้องในคลิปทับภาพนิ่งพอดี */
.tpix-mascot__pose.is-pad {
    left: -8%;
    top: -8%;
    width: 116%;
    height: 116%;
    max-width: none; /* Tailwind preflight ล็อก video ไว้ที่ max-width:100% */
}

/* พื้นที่กดตัวน้อง: ครึ่งกลางของภาพ แล้วย่อตามด้วย scale */
.tpix-mascot__hit {
    position: fixed;
    left: 0;
    top: 0;
    width: 160px;
    height: 420px;
    transform-origin: 0 0;
    border-radius: 45%;
    background: transparent;
    cursor: pointer;
    will-change: transform;
}

.tpix-mascot:not(.is-ready) .tpix-mascot__hit {
    display: none;
}

.tpix-mascot__hit:focus-visible {
    outline: 2px dashed rgb(var(--c-primary-400));
    outline-offset: 4px;
}

.tpix-mascot__bubble {
    position: fixed;
    left: 0;
    top: 0;
    width: min(320px, calc(100vw - 16px));
    border-radius: 18px;
    background: rgb(var(--c-dark-900) / 0.94);
    border: 1px solid rgb(var(--c-primary-500) / 0.35);
    box-shadow:
        0 18px 50px rgb(0 0 0 / 0.45),
        0 0 0 1px rgb(255 255 255 / 0.04) inset,
        0 0 28px rgb(var(--c-primary-500) / 0.15);
    backdrop-filter: blur(14px);
    transition: opacity 0.25s ease, width 0.2s ease;
    will-change: transform;
}

/* มือถือ: บับเบิ้ลแคบลงให้วางข้างตัวน้องได้ ไม่ต้องขึ้นไปทับหัว */
@media (max-width: 767px) {
    .tpix-mascot__bubble {
        width: min(250px, calc(100vw - 36vw - 16px));
    }
}

.tpix-mascot__bubble.is-collapsed {
    width: auto;
    border-radius: 999px;
}

.tpix-mascot__bubble.is-off,
.is-flying .tpix-mascot__bubble,
.is-chat-open .tpix-mascot__bubble {
    opacity: 0;
    pointer-events: none;
}

.tpix-mascot__tail {
    position: absolute;
    width: 14px;
    height: 14px;
    background: inherit;
    border: inherit;
    transform: rotate(45deg);
    pointer-events: none;
}

.is-left .tpix-mascot__tail {
    right: -8px;
    top: calc(var(--tail, 40px) - 7px);
    border-left: 0;
    border-bottom: 0;
}

.is-right .tpix-mascot__tail {
    left: -8px;
    top: calc(var(--tail, 40px) - 7px);
    border-right: 0;
    border-top: 0;
}

.is-above .tpix-mascot__tail {
    bottom: -8px;
    left: calc(var(--tail, 40px) - 7px);
    border-left: 0;
    border-top: 0;
}

.is-collapsed .tpix-mascot__tail {
    display: none;
}

.tpix-mascot__text {
    max-height: min(38vh, 260px);
    overflow-y: auto;
    cursor: default;
}

.tpix-mascot__caret {
    display: inline-block;
    width: 2px;
    height: 1em;
    margin-left: 2px;
    vertical-align: -2px;
    background: rgb(var(--c-primary-400));
    animation: tpix-caret 0.8s steps(2) infinite;
}

@keyframes tpix-caret {
    50% { opacity: 0; }
}

.tpix-mascot__dots {
    display: inline-flex;
    gap: 4px;
    margin-top: 6px;
}

.tpix-mascot__dots i {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: rgb(var(--c-primary-400));
    animation: tpix-dot 1s ease-in-out infinite;
}

.tpix-mascot__dots i:nth-child(2) { animation-delay: 0.15s; }
.tpix-mascot__dots i:nth-child(3) { animation-delay: 0.3s; }

@keyframes tpix-dot {
    0%, 100% { transform: translateY(0); opacity: 0.4; }
    50% { transform: translateY(-4px); opacity: 1; }
}

.tpix-mascot__chip {
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    color: rgb(var(--c-primary-200));
    background: rgb(var(--c-primary-500) / 0.1);
    border: 1px solid rgb(var(--c-primary-500) / 0.3);
    transition: background-color 0.2s ease, transform 0.2s ease;
}

.tpix-mascot__chip:hover {
    background: rgb(var(--c-primary-500) / 0.22);
    transform: translateY(-1px);
}

.tpix-mascot__chip.is-primary {
    color: #fff;
    background: linear-gradient(135deg, rgb(var(--c-primary-500)), rgb(var(--c-accent-500)));
    border-color: transparent;
}

.tpix-mascot__icon {
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    color: rgb(var(--c-dark-400));
    transition: color 0.2s ease, background-color 0.2s ease;
}

.tpix-mascot__icon:hover {
    color: #fff;
    background: rgb(255 255 255 / 0.08);
}

@media (prefers-reduced-motion: reduce) {
    .tpix-mascot__body,
    .tpix-mascot__pose,
    .tpix-mascot__bubble {
        transition: none;
    }
    .tpix-mascot__caret,
    .tpix-mascot__dots i {
        animation: none;
    }
}
</style>
