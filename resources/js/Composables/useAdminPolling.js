/**
 * TPIX TRADE — โพลข้อมูลหลังบ้านเป็นรอบ โดยเว้นรอบที่ตรงกับ Inertia visit
 *
 * ★ โพลที่ยิงเซิร์ฟเวอร์ในหลังบ้านทุกตัวต้องใช้ตัวนี้ ห้ามเรียก setInterval เอง
 *
 * ทุกคำขอบน session เดียวกันทำให้ flash เก่าลงหนึ่งขั้น ถ้าโพลแทรกระหว่าง POST ของฟอร์ม
 * กับ GET ที่ redirect กลับ error ใน flash จะหมดอายุก่อนถึงหน้า Inertia เห็นว่าไม่มี error
 * แล้วเรียก onSuccess ฟอร์มปิดเหมือนบันทึกสำเร็จ ทั้งที่ไม่มีอะไรถูกบันทึก
 *
 * ใช้ boolean ไม่ใช่ตัวนับ: Inertia มี visit ค้างได้ทีละอันอยู่แล้ว แต่ finish ยิงซ้ำได้
 * ตอน visit ใหม่แทรก ตัวนับจะติดลบแล้วเลิกหยุดโพลไปเลย
 *
 * ไม่กันโพลที่ออกไปแล้วก่อน visit เริ่ม ถ้าตัวนั้นช้ากว่า POST มันยังเขียน session ทับได้
 * และเห็นเฉพาะ visit ของแท็บนี้ แท็บอื่นบน session เดียวกันยังแทรกได้
 *
 * Developed by Xman Studio
 */
import { onMounted, onUnmounted } from 'vue';

export function useAdminPolling(callback, intervalMs) {
    let visitInFlight = false;
    let timer = null;

    const onVisitStart = () => { visitInFlight = true; };
    const onVisitFinish = () => { visitInFlight = false; };

    onMounted(() => {
        // ฟัง event ของ document ตรงๆ เหมือน progress bar ของ Inertia เอง
        document.addEventListener('inertia:start', onVisitStart);
        document.addEventListener('inertia:finish', onVisitFinish);

        timer = setInterval(() => {
            if (!visitInFlight) callback();
        }, intervalMs);
    });

    onUnmounted(() => {
        clearInterval(timer);
        document.removeEventListener('inertia:start', onVisitStart);
        document.removeEventListener('inertia:finish', onVisitFinish);
    });
}
