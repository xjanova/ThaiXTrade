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
import { t } from '@/Composables/useTranslation';

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
 * @throws {Error} ข้อความตามภาษาที่เลือกอยู่ พร้อมแสดงผู้ใช้
 */
export function findKeystoreFor(fileText, address) {
    let parsed;
    try {
        parsed = JSON.parse(fileText);
    } catch {
        throw new Error(t('util.treasuryColdWallet.errors.notJson'));
    }

    const want = String(address).toLowerCase();

    const single = keystoreAddress(parsed);
    if (single) {
        if (single === want) return JSON.stringify(parsed);
        throw new Error(t('util.treasuryColdWallet.errors.wrongWallet', { address: single }));
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
        throw new Error(t('util.treasuryColdWallet.errors.walletNotInFile'));
    }

    throw new Error(t('util.treasuryColdWallet.errors.unknownFormat'));
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

/** ข้อความ error จาก ethers/RPC → ข้อความตามภาษาที่เลือกที่ผู้ใช้อ่านเข้าใจ (ห้ามโชว์ stack/raw ยาว ๆ) */
export function friendlySendError(error) {
    const raw = String(error?.shortMessage || error?.message || error || '');
    const msg = raw.toLowerCase();

    if (msg.includes('incorrect password') || msg.includes('invalid password')) return t('util.treasuryColdWallet.errors.wrongPassword');
    if (msg.includes('insufficient funds')) return t('util.treasuryColdWallet.errors.insufficientFunds');
    if (msg.includes('nonce too low') || msg.includes('already known')) {
        return t('util.treasuryColdWallet.errors.alreadySent');
    }
    if (msg.includes('failed to fetch') || msg.includes('network') || msg.includes('timeout')) {
        return t('util.treasuryColdWallet.errors.network');
    }

    return raw.length > 200 ? `${raw.slice(0, 200)}…` : raw || t('util.treasuryColdWallet.errors.unknown');
}
