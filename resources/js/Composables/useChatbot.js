/**
 * TPIX TRADE — สถานะแชทกลางของผู้ช่วย AI
 *
 * หน้าต่างแชทลอย (AIChatbot) และบับเบิ้ลของน้อง TPIX คุยกับบอทตัวเดียวกัน
 * ประวัติจึงต้องอยู่ที่เดียว — ถามในบับเบิ้ลแล้วกด "เปิดหน้าต่างแชท" ต้องเห็นบทสนทนาเดิมต่อกัน
 * เก็บระดับโมดูล: เปลี่ยนหน้า (Inertia) แล้วประวัติยังอยู่ รีโหลดหน้าแล้วเริ่มใหม่
 *
 * Developed by Xman Studio
 */

import { ref, readonly } from 'vue';
import axios from 'axios';

/** ส่งประวัติให้บอทกี่ข้อความล่าสุด (ต้องไม่เกิน max ฝั่ง ChatbotController) */
export const HISTORY_LIMIT = 6;
const REQUEST_TIMEOUT_MS = 45000;
/** assistant = ผู้ช่วยทั่วไป · mascot = น้อง TPIX ในบับเบิ้ล (ตอบสั้น) · mascot-chat = น้อง TPIX ในหน้าต่างแชท */
const PERSONAS = ['assistant', 'mascot', 'mascot-chat'];
const MAX_LEN = 1000;

const messages = ref([]);
const isLoading = ref(false);
const isOpen = ref(false);
let seq = 0;

/** ลิงก์ภายในเว็บเท่านั้น — ข้อความจาก AI ห้ามพาออกนอกเว็บหรือรันสคริปต์ */
function safeNav(path) {
    return typeof path === 'string' && /^\/(?!\/)[A-Za-z0-9\-/]*$/.test(path) ? path : null;
}

function historyForApi() {
    return messages.value
        .filter((m) => !m.failed)
        .slice(-HISTORY_LIMIT)
        .map((m) => ({ role: m.role, text: String(m.text).slice(0, MAX_LEN) }));
}

/**
 * ส่งคำถาม
 * @param {string} text
 * @param {{ language?: string, persona?: 'assistant'|'mascot'|'mascot-chat', errorText?: string }} opts
 * @returns {Promise<object|null>} ข้อความตอบกลับ (null = ไม่ได้ส่ง เพราะว่างหรือกำลังรออยู่)
 */
async function send(text, opts = {}) {
    const msg = String(text ?? '').trim().slice(0, MAX_LEN);
    if (!msg || isLoading.value) return null;

    const history = historyForApi();
    messages.value.push({ id: ++seq, role: 'user', text: msg });
    isLoading.value = true;

    let reply;
    try {
        const { data } = await axios.post('/api/v1/chatbot', {
            message: msg,
            language: opts.language === 'en' ? 'en' : 'th',
            persona: PERSONAS.includes(opts.persona) ? opts.persona : 'assistant',
            history,
        }, {
            // AI ค้าง/เน็ตหลุด ต้องไม่ล็อกช่องถามไว้ที่ "กำลังคิด" ตลอดไป
            timeout: REQUEST_TIMEOUT_MS,
        });
        const ok = !!(data?.success && data?.data?.success !== false && data?.data?.message);
        reply = {
            id: ++seq,
            role: 'bot',
            text: ok ? data.data.message : (data?.data?.message || opts.errorText || ''),
            navUrl: ok ? safeNav(data.data.navigation) : null,
            failed: !ok,
        };
    } catch {
        reply = { id: ++seq, role: 'bot', text: opts.errorText || '', navUrl: null, failed: true };
    } finally {
        isLoading.value = false;
    }

    messages.value.push(reply);
    return reply;
}

export function useChatbot() {
    return {
        messages: readonly(messages),
        isLoading: readonly(isLoading),
        isOpen,
        send,
        open: () => { isOpen.value = true; },
        close: () => { isOpen.value = false; },
    };
}

/** สำหรับเทสต์เท่านั้น */
export function __resetChatbot() {
    messages.value = [];
    isLoading.value = false;
    isOpen.value = false;
    seq = 0;
}
