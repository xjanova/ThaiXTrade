/**
 * TPIX TRADE — Translation Composable
 * ระบบแปลภาษา — ใช้ t('key') ในทุก component
 * รองรับ TH/EN + เปลี่ยนภาษาแบบ reactive
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import th from '@/i18n/th.json';
import en from '@/i18n/en.json';

// ภาษาปัจจุบัน — default จาก localStorage หรือ 'th'
const currentLocale = ref(localStorage.getItem('tpix_locale') || 'th');

const messages = { th, en };

/**
 * ดึงค่า nested key จาก object (e.g. 'nav.home' → messages.th.nav.home)
 */
function getNestedValue(obj, path) {
    return path.split('.').reduce((current, key) => current?.[key], obj);
}

/**
 * แปลข้อความ — t('nav.home') → 'หน้าหลัก' (ถ้าภาษาไทย)
 */
function t(key, params = {}) {
    const msg = messages[currentLocale.value] || messages.th;
    let text = getNestedValue(msg, key);

    // Fallback ไป EN ถ้าไม่เจอใน locale ปัจจุบัน
    if (!text && currentLocale.value !== 'en') {
        text = getNestedValue(messages.en, key);
    }

    // Fallback ไป key เลย
    if (!text) return key;

    // แทนที่ {param} ด้วยค่าจริง
    if (typeof text === 'string' && params) {
        Object.entries(params).forEach(([k, v]) => {
            text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), v);
        });
    }

    return text;
}

/**
 * เปลี่ยนภาษา
 */
function setLocale(locale) {
    if (messages[locale]) {
        currentLocale.value = locale;
        localStorage.setItem('tpix_locale', locale);
        document.documentElement.lang = locale;
    }
}

/**
 * ภาษาที่รองรับ
 */
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

// Export สำหรับใช้นอก Vue (e.g. utils)
export { t, setLocale, currentLocale, availableLocales };
