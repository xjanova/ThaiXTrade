/**
 * TPIX TRADE — Translation Composable
 * ระบบแปลภาษา — ใช้ t('key') ในทุก component
 * - User เลือกภาษาได้เอง (ไม่ต้องสมัครสมาชิก)
 * - Default จาก: 1) localStorage  2) admin settings (meta tag)  3) 'th'
 * - เปลี่ยนภาษา reactive ทันที (ไม่ต้อง reload)
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import th from '@/i18n/th.json';
import en from '@/i18n/en.json';

const messages = { th, en };

// ภาษาปัจจุบัน — อ่านจาก localStorage ก่อน ถ้าไม่มีใช้ admin default จาก meta tag
function getInitialLocale() {
    if (typeof window === 'undefined') return 'th';
    const saved = localStorage.getItem('tpix_locale');
    if (saved && messages[saved]) return saved;
    const meta = document.querySelector('meta[name="default-locale"]')?.content;
    if (meta && messages[meta]) return meta;
    return 'th';
}

const currentLocale = ref(getInitialLocale());

/**
 * บอกภาษาให้เซิร์ฟเวอร์รู้ — ข้อความที่เซิร์ฟเวอร์สร้าง (แจ้งผล, error, ป้ายในหลังบ้าน) จะได้ตรงกับปุ่ม TH/EN
 * ใช้คุกกี้เพราะไปกับทุกคำขอเอง ทั้ง axios, fetch และการโหลดหน้าเต็ม (อ่านที่ App\Http\Middleware\SetLocale)
 */
function syncServerLocale(locale) {
    if (typeof document === 'undefined') return;
    document.cookie = `tpix_locale=${locale}; path=/; max-age=31536000; SameSite=Lax`;
}

// ตั้ง lang attribute ใน <html>
if (typeof document !== 'undefined') {
    document.documentElement.lang = currentLocale.value;
    syncServerLocale(currentLocale.value);
}

/**
 * ดึงค่า nested key จาก object
 *
 * รับเฉพาะสตริง — เรียกด้วย undefined เมื่อไหร่ `path.split` จะโยน TypeError
 * ออกมากลางการ render แล้ว Vue ทิ้งทั้งต้นไม้เป็นจอว่าง
 *
 * เคสนี้เกิดจริงมาแล้ว: `t(tierLabel[s.tier])` โดยที่ map ไม่มีคีย์ของระดับนั้น
 * ป้ายเล็กๆ อันเดียวที่หาคำแปลไม่เจอ ทำให้ทั้งหน้าหายไป — ราคาที่แพงเกินเหตุ
 */
function getNestedValue(obj, path) {
    if (typeof path !== 'string' || path === '') return undefined;

    return path.split('.').reduce((current, key) => current?.[key], obj);
}

/**
 * แปลข้อความ — reactive! เปลี่ยน locale → ทุก {{ t('key') }} อัปเดตทันที
 * ใช้ currentLocale.value ใน function body → Vue track dependency
 */
function t(key, params = {}) {
    // อ่าน currentLocale.value เพื่อให้ Vue track reactive dependency
    const loc = currentLocale.value;
    const msg = messages[loc] || messages.th;
    let text = getNestedValue(msg, key);

    // Fallback ไป EN
    if (!text && loc !== 'en') {
        text = getNestedValue(messages.en, key);
    }

    // Fallback ไป key — คีย์ที่ไม่ใช่สตริงคืนค่าว่าง ดีกว่าโชว์ "undefined" ให้ผู้ใช้เห็น
    if (!text) return typeof key === 'string' ? key : '';

    // แทนที่ {param}
    if (typeof text === 'string' && params) {
        Object.entries(params).forEach(([k, v]) => {
            text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), v);
        });
    }

    return text;
}

/**
 * เปลี่ยนภาษา — reactive ทันที ไม่ต้อง reload
 */
function setLocale(locale) {
    if (messages[locale]) {
        currentLocale.value = locale;
        localStorage.setItem('tpix_locale', locale);
        document.documentElement.lang = locale;
        syncServerLocale(locale);
        // ข้อความที่เซิร์ฟเวอร์ส่งมากับหน้านี้แล้วยังเป็นภาษาเดิม — app.js ฟังอีเวนต์นี้แล้วโหลด props ใหม่
        window.dispatchEvent(new CustomEvent('tpix:locale-changed', { detail: { locale } }));
    }
}

const availableLocales = [
    { code: 'th', name: 'ไทย', flag: '🇹🇭' },
    { code: 'en', name: 'English', flag: '🇺🇸' },
];

/**
 * Composable export — ใช้ใน Vue components
 */
export function useTranslation() {
    const locale = computed(() => currentLocale.value);
    const isThaiLocale = computed(() => currentLocale.value === 'th');

    return {
        t,
        locale,
        isThaiLocale,
        setLocale,
        availableLocales,
        currentLocale,
    };
}

export { t, setLocale, currentLocale, availableLocales };
