/**
 * TPIX TRADE — ตัวช่วยตัดสินว่า "เฟสขายเหรียญนี้เปิดให้ซื้อจริงไหม"
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ที่ต้องมีไฟล์นี้: ป้าย status อย่างเดียวเชื่อไม่ได้
 * ═══════════════════════════════════════════════════════════════════════════
 * เกิดขึ้นจริงบน production — เฟส "Private Sale" ค้างสถานะ active ไว้ทั้งที่
 * ends_at ผ่านมา 3 เดือนแล้ว เพราะไม่มีอะไรในระบบเลื่อนสถานะให้อัตโนมัติ
 * ผลคือทุกจุดในหน้าเว็บที่เช็คแค่ `status === 'active'` พร้อมใจกันบอกว่าซื้อได้:
 *
 *   - tokenSaleStore.activePhase  → หยิบเฟสที่ปิดแล้วมาเป็นเฟสปัจจุบัน
 *   - useTokenSale.canPurchase    → เปิดปุ่มจ่ายเงิน
 *   - PhaseCard.canSelect         → ปุ่ม "Select Phase" กดได้ + ป้าย "Active Now"
 *
 * ผู้ใช้จึงโอน BNB/USDT บน BSC จริง แล้วหลังบ้านค่อยปฏิเสธว่า phase has ended
 * — ลำดับการซื้อคือ "จ่ายก่อน แล้วค่อยยื่น tx_hash" จึงย้อนกลับไม่ได้
 *
 * เขียนไว้ที่เดียวแล้ว import ไปใช้ทุกจุด เพื่อไม่ให้เงื่อนไขแตกกันอีก
 * ต้องตรงกับ TokenSaleService::assertPhaseOpen() ฝั่งเซิร์ฟเวอร์เสมอ
 *
 * ⚠️ ตัวนี้เป็นแค่ด่านหน้าจอ — ด่านจริงอยู่ที่เซิร์ฟเวอร์
 *    มีไว้กันไม่ให้ผู้ใช้เดินไปถึงจุดที่เสียเงิน ไม่ใช่กันการโจมตี
 *
 * Developed by Xman Studio.
 */

/**
 * เฟสนี้เปิดขายอยู่จริงไหม (status active + อยู่ในช่วงวันที่กำหนด)
 *
 * @param {object|null} phase เฟสจาก /api/v1/token-sale
 * @returns {boolean}
 */
export function isPhaseOpen(phase) {
    if (!phase || phase.status !== 'active') return false;

    const now = Date.now();

    // starts_at / ends_at เป็น ISO-8601 จาก API — null = ไม่จำกัดด้านนั้น
    // ต้องเช็คทั้งสองด้าน: ด้านล่างกันเฟสอนาคตที่ถูกตั้ง active ไว้ก่อนเวลา
    if (phase.starts_at && Date.parse(phase.starts_at) > now) return false;
    if (phase.ends_at && Date.parse(phase.ends_at) < now) return false;

    return true;
}

/**
 * เฟสนี้ "ป้ายบอกว่า active แต่จริงๆ ปิดไปแล้ว" หรือเปล่า
 *
 * ใช้แยกข้อความอธิบายผู้ใช้ให้ตรงเหตุ — "รอบขายนี้ปิดแล้ว" ต่างจาก "ยังไม่เปิด"
 *
 * @param {object|null} phase
 * @returns {boolean}
 */
export function isPhaseStale(phase) {
    return !!phase && phase.status === 'active' && !isPhaseOpen(phase);
}

/**
 * ป้ายสถานะที่ "ตรงกับความจริง" ไม่ใช่ตรงกับคอลัมน์ status
 *
 * เดิม PhaseCard อ่าน status ตรงๆ จึงขึ้น "Active Now" สีเขียวให้เฟสที่ปิดไปแล้ว
 * ซึ่งเป็นสัญญาณแรกที่ทำให้ผู้ใช้เชื่อว่าซื้อได้
 *
 * @param {object} phase
 * @returns {{key: string, label: string}}
 */
export function phaseDisplayStatus(phase) {
    if (!phase) return { key: 'unknown', label: '—' };

    if ((phase.remaining ?? 0) <= 0 && phase.status === 'active') {
        return { key: 'sold_out', label: 'Sold Out' };
    }

    if (phase.status === 'active') {
        if (isPhaseOpen(phase)) {
            return { key: 'active', label: 'Active Now' };
        }

        // active แต่ยังไม่ถึงเวลา = ยังไม่เปิด · active แต่เลยเวลา = ปิดแล้ว
        const notStarted = phase.starts_at && Date.parse(phase.starts_at) > Date.now();

        return notStarted
            ? { key: 'upcoming', label: 'Coming Soon' }
            : { key: 'completed', label: 'Closed' };
    }

    switch (phase.status) {
        case 'upcoming': return { key: 'upcoming', label: 'Coming Soon' };
        case 'completed': return { key: 'completed', label: 'Completed' };
        case 'cancelled': return { key: 'completed', label: 'Cancelled' };
        case 'sold_out': return { key: 'sold_out', label: 'Sold Out' };
        default: return { key: 'unknown', label: phase.status };
    }
}
