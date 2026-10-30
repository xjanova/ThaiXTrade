/**
 * TPIX TRADE — ที่อยู่ไฟล์แบรนด์ จุดเดียวของทั้งเว็บ (โลโก้แพลตฟอร์ม + โลโก้เหรียญ TPIX)
 *
 * ต้นฉบับอยู่ public_html/images/brand/ — โลโก้เหรียญ TPIX ใช้ตราเดียวกับแพลตฟอร์ม
 * (เจ้าของสั่ง: "โลโก้เหรียญเปลี่ยนให้หมดทุกจุด") ขนาดเล็กใช้ตัวย่อ ขนาดใหญ่ใช้ตราเต็ม
 *
 * ⚠️ BRAND_VERSION ต้องบวกทุกครั้งที่แก้ไฟล์ในโฟลเดอร์นั้น — รูปใน public_html เสิร์ฟด้วย
 *    max-age หนึ่งปีและชื่อไฟล์ไม่มีแฮช Cloudflare จะจ่ายตัวเก่าต่อ (เคยวัดได้ cf-cache-status HIT)
 *    จอโหลดหน้าแรก (views/partials/home-splash/body.blade.php) ฝัง ?v= เดียวกันไว้ — มีเทสต์คุม
 *
 * Developed by Xman Studio
 */

export const BRAND_VERSION = 2;

/** /images/brand/<file>?v=<BRAND_VERSION> */
export const brandAsset = (file) => `/images/brand/${file}?v=${BRAND_VERSION}`;

/** โลโก้เหรียญ TPIX แบบเวกเตอร์ — <img> ในหน้าเว็บทุกขนาดที่ ≤ 48px (รายการเหรียญ กระเป๋า เชน) */
export const TPIX_COIN_ICON = brandAsset('tpix-trade-icon.svg');

/** โลโก้เหรียญแบบภาพ — ที่ที่ไม่รับ SVG: กระเป๋าภายนอก (WalletConnect), canvas */
export const TPIX_COIN_PNG = brandAsset('tpix-coin.png');

/** ตราเต็มแบบภาพ — วาดลงหน้าเหรียญในฉาก 3D (canvas ต้องรู้ขนาดภาพแน่นอน) */
export const TPIX_MARK_PNG = brandAsset('tpix-trade-mark.png');

/**
 * ไอคอนแอป TPIX Wallet — รูปเดียวกับที่อยู่บนหน้าจอมือถือของผู้ใช้ (วงแหวนไล่สี + ตรา TP)
 * ต้นฉบับ TPIX-Coin `wallet/assets/icon/tpix_icon_1024.png` ย่อเป็น 256px · แอปเปลี่ยนไอคอน = สร้างไฟล์นี้ใหม่
 * (ไม่ใช่ /images/logowallet.webp — ตัวนั้นเป็นตราทองรุ่นเก่าก่อนแอปเปลี่ยนไอคอน)
 */
export const TPIX_WALLET_APP_ICON = brandAsset('tpix-wallet-app.webp');
