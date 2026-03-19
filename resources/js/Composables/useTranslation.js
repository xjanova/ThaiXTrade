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

// ตั้ง lang attribute ใน <html>
if (typeof document !== 'undefined') {
    document.documentElement.lang = currentLocale.value;
}

/**
 * ดึงค่า nested key จาก object
 */
function getNestedValue(obj, path) {
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

    // Fallback ไป key
    if (!text) return key;

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
