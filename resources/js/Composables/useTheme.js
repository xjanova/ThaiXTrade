/**
 * TPIX TRADE — สลับธีมทั้งเว็บ
 *
 * ธีมเปลี่ยนที่ตัวแปร CSS ตัวเดียว (แอตทริบิวต์ data-theme บน <html>)
 * ไม่ใช่ที่คลาสในไฟล์ .vue — เพราะสีถูกใช้ผ่านคลาสของ Tailwind ราว 6,900 จุด
 * ซึ่ง tailwind.config.js อ้างเป็น rgb(var(--c-*) / <alpha-value>) ให้หมดแล้ว
 *
 * Developed by Xman Studio
 */

import { ref, readonly } from 'vue';

const STORAGE_KEY = 'tpix_theme';

export const THEMES = [
    {
        id: 'classic',
        nameTh: 'กระจกเข้ม',
        nameEn: 'Glass Dark',
        taglineTh: 'ฟ้า-ม่วง บนกระจกฝ้า',
        taglineEn: 'Cyan & violet on frosted glass',
        // สีตัวอย่างสำหรับปุ่มเลือกธีม (พื้น, สีเน้น, ขึ้น, ลง)
        swatch: ['#0f172a', '#06b6d4', '#00c853', '#ff1744'],
    },
    {
        id: 'xp-silver',
        nameTh: 'เงินคลาสสิก',
        nameEn: 'XP Silver',
        taglineTh: 'โครเมียมเงิน ขอบนูน 3 มิติ',
        taglineEn: 'Silver chrome · 3D bevels',
        swatch: ['#f1f1f5', '#316ac5', '#12874e', '#c4342a'],
    },
];

const DEFAULT_ID = 'classic';

function isKnown(id) {
    return THEMES.some((t) => t.id === id);
}

/** อ่านค่าที่บันทึกไว้ — localStorage อาจโยน error ในโหมดส่วนตัว */
export function storedThemeId() {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        return isKnown(saved) ? saved : DEFAULT_ID;
    } catch {
        return DEFAULT_ID;
    }
}

/**
 * ติดธีมลง <html>
 *
 * ธีมเดิมไม่ตั้งแอตทริบิวต์เลย (ใช้ค่าใน :root) เพื่อให้ CSS ของธีมเดิม
 * ไม่ต้องพึ่งแอตทริบิวต์ — ถ้าสคริปต์พังหรือยังไม่รัน หน้าเว็บก็ยังถูกอยู่ดี
 */
export function applyTheme(id) {
    const next = isKnown(id) ? id : DEFAULT_ID;
    const el = document.documentElement;

    if (next === DEFAULT_ID) {
        el.removeAttribute('data-theme');
    } else {
        el.setAttribute('data-theme', next);
    }

    return next;
}

const current = ref(DEFAULT_ID);

export function useTheme() {
    const setTheme = (id) => {
        const applied = applyTheme(id);
        current.value = applied;
        try {
            localStorage.setItem(STORAGE_KEY, applied);
        } catch {
            // เขียนไม่ได้ก็ไม่เป็นไร ธีมยังเปลี่ยนในหน้านี้ แค่ไม่ถูกจำไว้
        }
    };

    const init = () => {
        current.value = applyTheme(storedThemeId());
    };

    return {
        themes: THEMES,
        current: readonly(current),
        setTheme,
        init,
    };
}
