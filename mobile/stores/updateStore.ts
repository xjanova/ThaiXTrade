/**
 * Update State Store
 * สโตร์สำหรับจัดการสถานะการอัปเดต
 *
 * Manages update check, download progress, and installation.
 * จัดการการตรวจสอบอัปเดต, ความคืบหน้าดาวน์โหลด, และการติดตั้ง
 */

import { create } from 'zustand';
import {
  checkForUpdate,
  downloadApk,
  installApk,
  type UpdateInfo,
} from '@/services/updateService';

type DownloadStatus = 'idle' | 'downloading' | 'completed' | 'installing' | 'error';

interface UpdateState {
  /** Update information / ข้อมูลอัปเดต */
  updateInfo: UpdateInfo | null;
  /** Whether the check is in progress / กำลังตรวจสอบอยู่หรือไม่ */
  isChecking: boolean;
  /** Whether the update modal is visible / แสดง modal อัปเดตอยู่หรือไม่ */
  showModal: boolean;
  /** Last check timestamp / เวลาตรวจสอบล่าสุด */
  lastCheckedAt: number | null;
  /** Error message / ข้อความ error */
  error: string | null;

  /** Download status / สถานะการดาวน์โหลด */
  downloadStatus: DownloadStatus;
  /** Download progress 0-100 / ความคืบหน้า 0-100 */
  downloadPercent: number;
  /** Bytes downloaded / จำนวนไบต์ที่ดาวน์โหลดแล้ว */
  downloadedBytes: number;
  /** Total bytes to download / จำนวนไบต์ทั้งหมด */
  totalBytes: number;
  /** Local file URI after download / URI ไฟล์ในเครื่องหลังดาวน์โหลด */
  localFileUri: string | null;

  /** Check for updates / ตรวจสอบอัปเดต */
  checkUpdate: () => Promise<void>;
  /** Force check (ignore throttle) / ตรวจสอบแบบบังคับ */
  forceCheck: () => Promise<void>;
  /** Start downloading APK / เริ่มดาวน์โหลด APK */
  startDownload: () => Promise<void>;
  /** Install downloaded APK / ติดตั้ง APK ที่ดาวน์โหลดแล้ว */
  startInstall: () => Promise<void>;
  /** Dismiss the update modal / ปิด modal อัปเดต */
  dismissModal: () => void;
  /** Show the update modal / แสดง modal อัปเดต */
  openModal: () => void;
  /** Reset download state / รีเซ็ตสถานะดาวน์โหลด */
  resetDownload: () => void;
}

const CHECK_INTERVAL_MS = 4 * 60 * 60 * 1000;

export const useUpdateStore = create<UpdateState>((set, get) => ({
  updateInfo: null,
  isChecking: false,
  showModal: false,
  lastCheckedAt: null,
  error: null,
  downloadStatus: 'idle',
  downloadPercent: 0,
  downloadedBytes: 0,
  totalBytes: 0,
  localFileUri: null,

  checkUpdate: async () => {
    const { isChecking, lastCheckedAt } = get();
    if (isChecking) return;
    if (lastCheckedAt && Date.now() - lastCheckedAt < CHECK_INTERVAL_MS) return;

    set({ isChecking: true, error: null });
    try {
      const info = await checkForUpdate();
      set({
        updateInfo: info,
        isChecking: false,
        lastCheckedAt: Date.now(),
        showModal: info.available,
      });
    } catch {
      set({ isChecking: false, error: 'ตรวจสอบอัปเดตไม่สำเร็จ' });
    }
  },

  forceCheck: async () => {
    set({ lastCheckedAt: null });
    await get().checkUpdate();
  },

  startDownload: async () => {
    const { updateInfo, downloadStatus } = get();
    if (!updateInfo?.downloadUrl) return;
    if (downloadStatus === 'downloading') return;

    set({
      downloadStatus: 'downloading',
      downloadPercent: 0,
      downloadedBytes: 0,
      totalBytes: updateInfo.fileSize,
      error: null,
      localFileUri: null,
    });

    try {
      const uri = await downloadApk(updateInfo.downloadUrl, (progress) => {
        set({
          downloadPercent: progress.percent,
          downloadedBytes: progress.totalBytesWritten,
          totalBytes: progress.totalBytesExpectedToWrite,
        });
      });

      set({
        downloadStatus: 'completed',
        downloadPercent: 100,
        localFileUri: uri,
      });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'ดาวน์โหลดล้มเหลว';
      set({
        downloadStatus: 'error',
        error: message,
      });
    }
  },

  startInstall: async () => {
    const { localFileUri } = get();
    if (!localFileUri) return;

    set({ downloadStatus: 'installing' });
    try {
      await installApk(localFileUri);
    } catch (err) {
      const message = err instanceof Error ? err.message : 'ติดตั้งล้มเหลว';
      set({ downloadStatus: 'error', error: message });
    }
  },

  dismissModal: () => set({ showModal: false }),
  openModal: () => {
    const { updateInfo } = get();
    if (updateInfo?.available) set({ showModal: true });
  },
  resetDownload: () => set({
    downloadStatus: 'idle',
    downloadPercent: 0,
    downloadedBytes: 0,
    totalBytes: 0,
    localFileUri: null,
    error: null,
  }),
}));
