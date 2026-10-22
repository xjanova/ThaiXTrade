/**
 * TPIX TRADE — useFoodPassportWrite
 * คำขอเขียนของ FoodPassport (ลงทะเบียนสินค้า / mint ใบรับรอง) ในนามกระเป๋าที่เชื่อมอยู่
 *
 * - ใช้ axios ไม่ใช่ fetch: บนโดเมนของเว็บ Sanctum ตรวจ CSRF ทุกคำขอเขียน
 *   fetch ไม่ส่งโทเคน → 419 ทุกครั้ง, axios ส่ง X-XSRF-TOKEN จากคุกกี้ให้เอง
 * - ส่ง wallet_address — ฟิลด์เดียวที่ด่าน VerifyWalletOwnership ตรวจ
 *   ⚠️ เดิมส่งแค่ producer_address / owner_address ที่ด่านมองไม่เห็น เซิร์ฟเวอร์เลยไม่ตรวจอะไรเลย
 * - 403 เพราะยังไม่เซ็นยืนยันกระเป๋า → needsVerification ให้หน้าแสดงปุ่มเซ็น แทนข้อความ error ดิบ
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';

const VERIFY_CODES = ['WALLET_NOT_VERIFIED', 'WALLET_IP_MISMATCH'];

const MESSAGES = {
    NOT_PRODUCER: 'ทำได้เฉพาะกระเป๋าผู้ผลิตของสินค้านี้',
    WALLET_MISMATCH: 'กระเป๋าในคำขอไม่ตรงกับกระเป๋าที่ยืนยันตัวตน — ลองเชื่อมกระเป๋าใหม่',
    NOT_FOUND: 'ไม่พบสินค้านี้',
    MINT_FAILED: 'ต้องมีข้อมูล trace อย่างน้อย 2 จุดก่อน mint ใบรับรอง',
};

export function useFoodPassportWrite() {
    const walletStore = useWalletStore();
    const needsVerification = ref(false);
    const isVerifying = ref(false);

    /**
     * POST ในนามกระเป๋า $address
     * คืน { ok: true, data } หรือ { ok: false, message } — message เป็นภาษาไทยเสมอ ไม่ใช่ข้อความ exception
     * คืน { ok: false, stale: true } เมื่อสลับกระเป๋าระหว่างรอ (คำตอบนั้นเป็นของกระเป๋าเดิม ห้ามใช้)
     */
    async function post(url, address, body, fallback) {
        needsVerification.value = false;
        try {
            const { data } = await axios.post(url, { ...body, wallet_address: address });
            if (walletStore.address !== address) return { ok: false, stale: true };
            if (data?.success) return { ok: true, data: data.data };
            return { ok: false, message: fallback };
        } catch (e) {
            if (walletStore.address !== address) return { ok: false, stale: true };

            const status = e?.response?.status;
            const code = e?.response?.data?.error?.code;

            if (status === 403 && VERIFY_CODES.includes(code)) {
                needsVerification.value = true;
                return { ok: false, message: 'ต้องเซ็นยืนยันกระเป๋าก่อน แล้วกดอีกครั้ง' };
            }
            if (MESSAGES[code]) return { ok: false, message: MESSAGES[code] };
            if (status === 419) return { ok: false, message: 'หน้านี้เปิดค้างนานเกินไป — รีเฟรชหน้าแล้วลองใหม่' };
            if (status === 422) return { ok: false, message: 'ข้อมูลไม่ครบหรือไม่ถูกต้อง — ตรวจสอบแล้วลองใหม่' };
            if (status === 429) return { ok: false, message: 'ส่งคำขอถี่เกินไป — รอสักครู่แล้วลองใหม่' };
            if (!e?.response) return { ok: false, message: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ — ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่' };
            return { ok: false, message: fallback };
        }
    }

    /** เซ็นยืนยันกระเป๋า — แบบเดียวกับ OpenOrders.verifyWallet() คืน true เมื่อเซ็นผ่าน */
    async function verifyWallet() {
        if (isVerifying.value) return false;   // ป๊อปอัพขอลายเซ็นซ้อนกันไม่ได้

        isVerifying.value = true;
        try {
            const hasSigner = await walletStore.verifyOwnership();

            // กระเป๋าฝังที่ยังไม่ปลดล็อกไม่มี signer — ต้องใส่รหัสผ่านที่หน้าต่างเชื่อมกระเป๋า
            if (!hasSigner) {
                walletStore.openConnectModal();
                return false;
            }

            needsVerification.value = false;
            return true;
        } catch {
            // verifyOwnership จัดการ error ของตัวเองแล้ว — ปุ่มยืนยันยังอยู่ให้กดใหม่ได้
            return false;
        } finally {
            isVerifying.value = false;
        }
    }

    return { post, verifyWallet, needsVerification, isVerifying };
}
