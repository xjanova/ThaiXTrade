/**
 * Update State Store
 * สโตร์สำหรับจัดการสถานะการอัปเดต
 *
 * Flow เหมือน TPIX Wallet (TPIX-Coin/update_service.dart):
 * Check → Download (progress + cancel) → Validate → Install → Auto-fallback to browser
 *
 * มี timeout ป้องกันค้าง + cancel สำหรับยกเลิกดาวน์โหลด
 * Developed by Xman Studio
 */

import { create } from 'zustand';
import {
  checkForUpdate,
  downloadApk,
  cancelDownload as cancelDownloadService,
  installApk,
  openDownloadPage,
  type UpdateInfo,
} from '@/services/updateService';

type DownloadStatus = 'idle' | 'downloading' | 'completed' | 'installing' | 'error';

const DOWNLOAD_TIMEOUT_MS = 5 * 60 * 1000; // 5 นาที — APK อาจใหญ่ 50-150MB
const INSTALL_TIMEOUT_MS = 30_000;  // 30 วินาที
const CHECK_INTERVAL_MS = 4 * 60 * 60 * 1000;

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
  /** Cancel download in progress / ยกเลิกดาวน์โหลด */
  cancelDownload: () => void;
  /** Install downloaded APK / ติดตั้ง APK ที่ดาวน์โหลดแล้ว */
  startInstall: () => Promise<void>;
  /** Dismiss the update modal / ปิด modal อัปเดต */
  dismissModal: () => void;
  /** Show the update modal / แสดง modal อัปเดต */
  openModal: () => void;
  /** Reset download state / รีเซ็ตสถานะดาวน์โหลด */
  resetDownload: () => void;
}

// ใช้เก็บ flag ยกเลิก (อยู่นอก store เพื่อให้ closure ใน startDownload เข้าถึงได้)
let downloadCancelled = false;

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
    // รีเซ็ต isChecking ด้วยเพื่อป้องกันค้าง (กรณี check ก่อนหน้า error แต่ flag ไม่ถูกรีเซ็ต)
    set({ lastCheckedAt: null, isChecking: false });
    await get().checkUpdate();
  },

  startDownload: async () => {
    const { updateInfo, downloadStatus } = get();

    // ถ้าไม่มี downloadUrl → fallback to browser ทันที (เหมือน wallet: if apkUrl == null)
    if (!updateInfo?.downloadUrl) {
      try {
        await openDownloadPage();
        set({ showModal: false });
      } catch {
        set({ downloadStatus: 'error', error: 'ไม่พบลิงก์ดาวน์โหลด' });
      }
      return;
    }

    if (downloadStatus === 'downloading') return;

    downloadCancelled = false;

    set({
      downloadStatus: 'downloading',
      downloadPercent: 0,
      downloadedBytes: 0,
      totalBytes: updateInfo.fileSize,
      error: null,
      localFileUri: null,
    });

    try {
      // ครอบด้วย timeout 5 นาที (เหมือน wallet: receiveTimeout = 5 minutes)
      const downloadPromise = downloadApk(updateInfo.downloadUrl, (progress) => {
        if (downloadCancelled) return;
        set({
          downloadPercent: Math.round(progress.percent),
          downloadedBytes: progress.totalBytesWritten,
          totalBytes: progress.totalBytesExpectedToWrite,
        });
      });

      let downloadTimer: ReturnType<typeof setTimeout>;
      const timeoutPromise = new Promise<never>((_, reject) => {
        downloadTimer = setTimeout(() => reject(new Error('Download timed out')), DOWNLOAD_TIMEOUT_MS);
      });

      const uri = await Promise.race([downloadPromise, timeoutPromise]).finally(() => clearTimeout(downloadTimer!));

      // ตรวจสอบว่าถูกยกเลิกระหว่าง download หรือไม่
      if (downloadCancelled) return;

      set({
        downloadStatus: 'completed',
        downloadPercent: 100,
        localFileUri: uri,
      });
    } catch (err) {
      if (downloadCancelled) return;
      const message = err instanceof Error ? err.message : 'ดาวน์โหลดล้มเหลว';
      set({
        downloadStatus: 'error',
        error: message,
      });
    }
  },

  cancelDownload: () => {
    downloadCancelled = true;
    cancelDownloadService(); // ยกเลิก native download จริง
    set({
      downloadStatus: 'idle',
      downloadPercent: 0,
      downloadedBytes: 0,
      error: null,
    });
  },

  startInstall: async () => {
    const { localFileUri } = get();
    if (!localFileUri) return;

    set({ downloadStatus: 'installing' });
    try {
      const installPromise = installApk(localFileUri);
      let installTimer: ReturnType<typeof setTimeout>;
      const timeoutPromise = new Promise<boolean>((resolve) => {
        installTimer = setTimeout(() => resolve(false), INSTALL_TIMEOUT_MS);
      });

      const success = await Promise.race([installPromise, timeoutPromise]).finally(() => clearTimeout(installTimer!));

      if (success) {
        // Install intent launched — dialog จะปิดเอง (user เห็น system installer)
        set({ showModal: false });
      } else {
        // Install ไม่สำเร็จ → fallback to browser (เหมือน wallet: _fallbackToBrowser)
        try {
          await openDownloadPage();
          set({ showModal: false, downloadStatus: 'idle' });
        } catch {
          set({
            downloadStatus: 'error',
            error: 'ติดตั้งไม่สำเร็จ — กรุณาดาวน์โหลดจากเว็บไซต์',
          });
        }
      }
    } catch (err) {
      // Download/install failed → fallback to browser (เหมือน wallet)
      try {
        await openDownloadPage();
        set({ showModal: false, downloadStatus: 'idle' });
      } catch {
        const message = err instanceof Error ? err.message : 'ติดตั้งล้มเหลว';
        set({ downloadStatus: 'error', error: message });
      }
    }
  },

  dismissModal: () => set({ showModal: false }),
  openModal: () => {
    const { updateInfo } = get();
    if (updateInfo?.available) set({ showModal: true });
  },
  resetDownload: () => {
    downloadCancelled = true;
    set({
      downloadStatus: 'idle',
      downloadPercent: 0,
      downloadedBytes: 0,
      totalBytes: 0,
      localFileUri: null,
      error: null,
    });
  },
}));
