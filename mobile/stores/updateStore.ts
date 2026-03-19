/**
 * Update State Store
 * สโตร์สำหรับจัดการสถานะการอัปเดต
 *
 * Manages update check state, dismissed status, and auto-check logic.
 * จัดการสถานะการตรวจสอบอัปเดต, สถานะการปิด, และการตรวจสอบอัตโนมัติ
 */

import { create } from 'zustand';
import {
  checkForUpdate,
  type UpdateInfo,
  CURRENT_VERSION,
} from '@/services/updateService';

interface UpdateState {
  /** Update information / ข้อมูลอัปเดต */
  updateInfo: UpdateInfo | null;
  /** Whether the check is in progress / กำลังตรวจสอบอยู่หรือไม่ */
  isChecking: boolean;
  /** Whether the update modal is visible / แสดง modal อัปเดตอยู่หรือไม่ */
  showModal: boolean;
  /** Last check timestamp / เวลาตรวจสอบล่าสุด */
  lastCheckedAt: number | null;
  /** Error message if check failed / ข้อความ error ถ้าตรวจสอบไม่สำเร็จ */
  error: string | null;

  /** Check for updates / ตรวจสอบอัปเดต */
  checkUpdate: () => Promise<void>;
  /** Dismiss the update modal / ปิด modal อัปเดต */
  dismissModal: () => void;
  /** Show the update modal / แสดง modal อัปเดต */
  openModal: () => void;
}

// Minimum interval between auto-checks (4 hours)
// ระยะห่างขั้นต่ำระหว่างการตรวจสอบอัตโนมัติ (4 ชั่วโมง)
const CHECK_INTERVAL_MS = 4 * 60 * 60 * 1000;

export const useUpdateStore = create<UpdateState>((set, get) => ({
  updateInfo: null,
  isChecking: false,
  showModal: false,
  lastCheckedAt: null,
  error: null,

  checkUpdate: async () => {
    const { isChecking, lastCheckedAt } = get();

    // Prevent concurrent checks / ป้องกันการตรวจสอบพร้อมกัน
    if (isChecking) return;

    // Throttle auto-checks / จำกัดความถี่การตรวจสอบอัตโนมัติ
    if (lastCheckedAt && Date.now() - lastCheckedAt < CHECK_INTERVAL_MS) {
      return;
    }

    set({ isChecking: true, error: null });

    try {
      const info = await checkForUpdate();

      set({
        updateInfo: info,
        isChecking: false,
        lastCheckedAt: Date.now(),
        // Auto-show modal if update available / แสดง modal อัตโนมัติถ้ามีอัปเดต
        showModal: info.available,
      });
    } catch (err) {
      set({
        isChecking: false,
        error: 'Failed to check for updates / ตรวจสอบอัปเดตไม่สำเร็จ',
      });
    }
  },

  dismissModal: () => set({ showModal: false }),
  openModal: () => {
    const { updateInfo } = get();
    if (updateInfo?.available) {
      set({ showModal: true });
    }
  },
}));
