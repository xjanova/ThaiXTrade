/**
 * TPIX TRADE — ลิงก์ไลฟ์/คลิปของมาสเตอร์ Copy Trade: ต้นทางที่ยอมให้เป็นลิงก์และกรอบวิดีโอ
 *
 * เซิร์ฟเวอร์ประกอบลิงก์เองจากรหัสวิดีโออยู่แล้ว (MediaLink) — หน้าเว็บตรวจซ้ำอีกชั้น:
 * ลิงก์ที่ไม่ขึ้นต้นด้วยต้นทางพวกนี้ไม่ถูกวาดเป็น href หรือ src เลย (javascript: ไม่มีทางหลุดมาถึง DOM)
 * ต้นทางของกรอบวิดีโอต้องตรงกับ frame-src ของ CSP ทั้งสองที่ (SecurityHeaders.php · public_html/.htaccess)
 *
 * Developed by Xman Studio
 */

export const MEDIA_LINK_ORIGINS = ['https://www.youtube.com/', 'https://www.facebook.com/', 'https://www.tiktok.com/'];

export const MEDIA_EMBED_ORIGINS = [
    'https://www.youtube-nocookie.com/embed/',
    'https://www.facebook.com/plugins/video.php?',
    'https://www.tiktok.com/embed/v2/',
];

export const PROVIDER_NAMES = { youtube: 'YouTube', facebook: 'Facebook', tiktok: 'TikTok' };

const startsWithAny = (url, origins) => typeof url === 'string' && origins.some((origin) => url.startsWith(origin));

/** ลิงก์เปิดดูที่ผู้ให้บริการ — null ถ้าไม่ใช่ต้นทางที่รู้จัก */
export const safeLink = (url) => (startsWithAny(url, MEDIA_LINK_ORIGINS) ? url : null);

/** ลิงก์เครื่องเล่นสำหรับฝังในหน้า — null ถ้าไม่ใช่ต้นทางที่รู้จัก */
export const safeEmbed = (url) => (startsWithAny(url, MEDIA_EMBED_ORIGINS) ? url : null);

export const providerName = (provider) => PROVIDER_NAMES[provider] ?? String(provider ?? '');
