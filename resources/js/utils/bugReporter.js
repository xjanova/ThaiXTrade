/**
 * TPIX TRADE — ตัวรายงานบั๊กของหน้าเว็บ → ระบบกลาง xman studio (ผ่าน relay ของเรา)
 *
 * เจ้าของสั่ง: "ทำระบบ bug report … เพื่อให้ตรวจสอบได้ทันที ไม่เดา"
 *  - ดัก error ที่ไม่มีใครจับ (window.error / unhandledrejection / Vue errorHandler)
 *  - breadcrumb: การนำทาง + คำขอ API ที่ล้มเหลว 40 รายการล่าสุด แนบทุกรายงาน
 *  - ล้างความลับก่อนส่ง · กันซ้ำ 1 ชั่วโมงต่อรอยเดียวกัน · ไม่เกิน 5 รายงาน/นาที
 *
 * Developed by Xman Studio
 */
import axios from 'axios';
import { router } from '@inertiajs/vue3';

const ENDPOINT = '/api/v1/bug-reports';
const PRODUCT = 'tpix-web';
const MAX_CRUMBS = 40;
const DEDUPE_MS = 60 * 60 * 1000;
const PER_MINUTE = 5;

const crumbs = [];
const recent = new Map();
let minuteStart = Date.now();
let sentThisMinute = 0;

const version = () => document.querySelector('meta[name="app-version"]')?.content || 'web';

function deviceId() {
    try {
        let id = localStorage.getItem('tpix_web_device_id');
        if (!id) {
            id = Array.from(crypto.getRandomValues(new Uint8Array(16))).map(b => b.toString(16).padStart(2, '0')).join('');
            localStorage.setItem('tpix_web_device_id', id);
        }
        return id;
    } catch (_) {
        return 'unknown';
    }
}

const HEX64 = /(?<![0-9a-fA-F])(0x)?[0-9a-fA-F]{64}(?![0-9a-fA-F])/g;
const SIG = /0x[0-9a-fA-F]{130}/g;
const BEARER = /(Bearer\s+)[A-Za-z0-9._-]{8,}/gi;
const MNEMONIC = /\b(?:[a-z]{3,8}\s+){11,23}[a-z]{3,8}\b/g;

function scrub(text) {
    return String(text ?? '')
        .replace(SIG, m => `${m.slice(0, 10)}…[sig]`)
        .replace(HEX64, '[secret-64hex]')
        .replace(BEARER, '$1[token]')
        .replace(MNEMONIC, '[mnemonic?]');
}

export function breadcrumb(text) {
    const line = `${new Date().toISOString().slice(11, 19)} ${scrub(text)}`;
    crumbs.push(line.length > 200 ? `${line.slice(0, 200)}…` : line);
    if (crumbs.length > MAX_CRUMBS) crumbs.shift();
}

export function reportBug({ title, description, type = 'bug', severity = 'moderate', priority = 'medium', metadata = {}, stack = null, dedupe = true }) {
    const cleanTitle = scrub(title).slice(0, 250);
    const cleanStack = stack ? scrub(stack).slice(0, 12000) : null;
    const fingerprint = `${type}|${cleanTitle}|${cleanStack ? cleanStack.split('\n')[0] : ''}`;

    if (dedupe) {
        const last = recent.get(fingerprint);
        if (last && Date.now() - last < DEDUPE_MS) return false;
    }
    recent.set(fingerprint, Date.now());

    if (Date.now() - minuteStart > 60_000) { minuteStart = Date.now(); sentThisMinute = 0; }
    if (++sentThisMinute > PER_MINUTE) return false;

    const body = {
        product_name: PRODUCT,
        product_version: version(),
        app_version: version().slice(0, 20),
        os_version: navigator.userAgent.slice(0, 100),
        device_id: deviceId(),
        report_type: type,
        title: cleanTitle,
        description: scrub(description).slice(0, 20000),
        stack_trace: cleanStack,
        priority,
        severity,
        metadata: {
            url: location.href,
            viewport: `${window.innerWidth}x${window.innerHeight}`,
            language: navigator.language,
            breadcrumbs: [...crumbs],
            reported_at: new Date().toISOString(),
            ...metadata,
        },
    };

    try {
        // keepalive: ส่งให้ทันแม้หน้ากำลังปิด
        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
            keepalive: true,
        }).catch(() => {});
    } catch (_) { /* ไม่มีอะไรให้ทำ — รายงานบั๊กห้ามทำให้หน้าพังซ้ำ */ }

    return true;
}

/** ติดตั้งครั้งเดียวใน app.js หลังสร้าง Vue app */
export function installBugReporter(app) {
    breadcrumb(`page load ${location.pathname}`);

    window.addEventListener('error', (event) => {
        // error จากสคริปต์ข้ามโดเมนไม่มีรายละเอียด (Script error.) รายงานไปก็อ่านไม่ออก
        if (!event.message || event.message === 'Script error.') return;
        reportBug({
            title: event.message,
            description: `${event.message}\nat ${event.filename || '?'}:${event.lineno || 0}:${event.colno || 0}`,
            type: 'crash',
            severity: 'major',
            stack: event.error?.stack,
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        const message = reason?.message || String(reason);
        // axios ยกเลิก/หมดเวลา ไม่ใช่บั๊ก
        if (reason?.code === 'ERR_CANCELED') return;
        reportBug({
            title: `unhandled: ${message}`,
            description: message,
            type: 'crash',
            severity: 'moderate',
            stack: reason?.stack,
            metadata: { status: reason?.response?.status, url: reason?.config?.url },
        });
    });

    app.config.errorHandler = (err, instance, info) => {
        console.error('[TPIX] Vue Error:', err, info);
        reportBug({
            title: `vue: ${err?.message || String(err)}`,
            description: `${err?.message || String(err)}\ninfo: ${info}`,
            type: 'crash',
            severity: 'major',
            stack: err?.stack,
            metadata: { component: instance?.$options?.name || instance?.$?.type?.__name || null, info },
        });
    };

    router.on('navigate', (event) => breadcrumb(`navigate ${event.detail?.page?.url || ''}`));

    axios.interceptors.response.use(
        (response) => response,
        (error) => {
            const status = error?.response?.status;
            const url = error?.config?.url || '';
            if (status && url) breadcrumb(`api ${(error.config?.method || 'get').toUpperCase()} ${url} → ${status}`);
            return Promise.reject(error);
        },
    );
}
