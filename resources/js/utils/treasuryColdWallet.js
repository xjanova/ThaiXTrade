/**
 * TPIX TRADE — ตัวช่วยโอนจากกระเป๋าคลังแบบเซ็นในเบราว์เซอร์แอดมิน
 *
 * กระเป๋าคลัง (master wallet BIP-44) มีแต่ keystore ที่เข้ารหัสอยู่ในเครื่องเจ้าของ
 * หน้า /admin/treasury ให้แอดมินเลือกไฟล์นั้นจากเครื่องตัวเอง แล้วถอดรหัส+เซ็นในเบราว์เซอร์
 * ไฟล์และรหัสผ่านไม่ถูกส่งไปเซิร์ฟเวอร์ — เซิร์ฟเวอร์ยังคงไม่มีคีย์คลังแม้แต่ใบเดียว
 *
 * ฟังก์ชันในไฟล์นี้เป็น pure ทั้งหมด (ไม่แตะเครือข่าย/DOM) เพื่อให้เทสต์ได้ตรง ๆ
 *
 * Developed by Xman Studio
 */

import { getAddress, parseEther } from 'ethers';

/** ที่อยู่ใน keystore V3 (ฟิลด์ address ไม่ได้เข้ารหัส) — ไม่ใช่ keystore = null */
function keystoreAddress(candidate) {
    if (!candidate || typeof candidate !== 'object') return null;
    if (!candidate.crypto && !candidate.Crypto) return null;
    if (typeof candidate.address !== 'string') return null;

    const hex = candidate.address.toLowerCase().replace(/^0x/, '');
    return /^[0-9a-f]{40}$/.test(hex) ? `0x${hex}` : null;
}

/**
 * หา keystore ของกระเป๋า `address` จากเนื้อไฟล์ที่แอดมินเลือก
 *
 * รับได้สองแบบ:
 *  - keystore เดี่ยว (V3 JSON)
 *  - ไฟล์รวม master-wallet.keystores.json ({ role: "<keystore JSON>" } ที่ generate-master-wallet.js เขียน)
 *    → เลือกตัวที่ address ตรงให้เอง แอดมินไม่ต้องแยกไฟล์
 *
 * @returns {string} keystore JSON ของกระเป๋านั้น
 * @throws {Error} ข้อความภาษาไทยพร้อมแสดงผู้ใช้
 */
export function findKeystoreFor(fileText, address) {
    let parsed;
    try {
        parsed = JSON.parse(fileText);
    } catch {
        throw new Error('ไฟล์นี้ไม่ใช่ JSON — เลือกไฟล์ keystore (.json)');
    }

    const want = String(address).toLowerCase();

    const single = keystoreAddress(parsed);
    if (single) {
        if (single === want) return JSON.stringify(parsed);
        throw new Error(`ไฟล์นี้เป็น keystore ของ ${single} ไม่ใช่ของกระเป๋านี้`);
    }

    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        for (const value of Object.values(parsed)) {
            let entry = value;
            if (typeof value === 'string') {
                try {
                    entry = JSON.parse(value);
                } catch {
                    continue;
                }
            }
            if (keystoreAddress(entry) === want) return JSON.stringify(entry);
        }
        throw new Error('ในไฟล์นี้ไม่มี keystore ของกระเป๋านี้');
    }

    throw new Error('ไม่รู้จักรูปแบบไฟล์ — ต้องเป็น keystore หรือ master-wallet.keystores.json');
}

/**
 * ที่อยู่ปลายทางในรูป checksum — ผิดรูปแบบ = null
 * พิมพ์ผสมตัวเล็กใหญ่ต้อง checksum ถูก (กันพิมพ์ผิดตัวเดียวแล้วเงินไปที่อื่น)
 */
export function normalizeRecipient(raw) {
    const value = String(raw ?? '').trim();
    if (!/^0x[0-9a-fA-F]{40}$/.test(value)) return null;
    try {
        return getAddress(value);
    } catch {
        return null;
    }
}

/**
 * จำนวน TPIX ที่พิมพ์ → wei (bigint) · ผิดรูปแบบหรือเป็นศูนย์ = null
 * ยอมให้มีจุลภาคคั่นหลักพัน · ทศนิยมได้ไม่เกิน 18 ตำแหน่ง
 */
export function parseTpixAmount(raw) {
    const value = String(raw ?? '').trim().replace(/,/g, '');
    if (!/^\d+(\.\d{1,18})?$/.test(value)) return null;
    const wei = parseEther(value);
    return wei > 0n ? wei : null;
}

/** ข้อความ error จาก ethers/RPC → ภาษาไทยที่ผู้ใช้อ่านเข้าใจ (ห้ามโชว์ stack/raw ยาว ๆ) */
export function friendlySendError(error) {
    const raw = String(error?.shortMessage || error?.message || error || '');
    const msg = raw.toLowerCase();

    if (msg.includes('incorrect password') || msg.includes('invalid password')) return 'รหัสผ่าน keystore ไม่ถูกต้อง';
    if (msg.includes('insufficient funds')) return 'ยอดในกระเป๋าไม่พอ';
    if (msg.includes('nonce too low') || msg.includes('already known')) {
        return 'เชนแจ้งว่าธุรกรรมนี้ถูกส่งไปแล้ว — ตรวจที่ explorer ก่อน อย่ากดส่งซ้ำ';
    }
    if (msg.includes('failed to fetch') || msg.includes('network') || msg.includes('timeout')) {
        return 'ต่อเชนไม่ได้ — ตรวจอินเทอร์เน็ตแล้วลองใหม่';
    }

    return raw.length > 200 ? `${raw.slice(0, 200)}…` : raw || 'เกิดข้อผิดพลาดที่ไม่รู้จัก';
}
