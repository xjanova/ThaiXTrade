/**
 * Native file operations for APK download & install (Android)
 * การจัดการไฟล์ native สำหรับดาวน์โหลดและติดตั้ง APK (Android)
 *
 * ใช้แนวทางเดียวกับ TPIX Wallet (TPIX-Coin):
 * - Download APK with progress + cancel support
 * - Validate file size > 1KB (ป้องกันไฟล์เสีย)
 * - Install via Android intent
 * - Fallback to browser if install fails
 *
 * Developed by Xman Studio
 */

import {
  cacheDirectory,
  getInfoAsync,
  deleteAsync,
  createDownloadResumable,
  getContentUriAsync,
  type DownloadResumable,
} from 'expo-file-system';
import * as IntentLauncher from 'expo-intent-launcher';

type ProgressCallback = (progress: {
  totalBytesWritten: number;
  totalBytesExpectedToWrite: number;
  percent: number;
}) => void;

// เก็บ reference สำหรับยกเลิกดาวน์โหลด (เหมือน CancelToken ของ Dio ใน wallet)
let activeDownload: DownloadResumable | null = null;

// Minimum file size for valid APK (เหมือน wallet ที่ check > 1024 bytes)
const MIN_APK_SIZE = 1024;

/**
 * Cancel active download / ยกเลิกดาวน์โหลดที่กำลังทำอยู่
 * เหมือน CancelToken.cancel() ใน TPIX Wallet
 */
export function cancelActiveDownload(): void {
  if (activeDownload) {
    activeDownload.pauseAsync().catch(() => {});
    activeDownload = null;
  }
}

/**
 * Download APK with progress / ดาวน์โหลด APK พร้อมแสดงความคืบหน้า
 *
 * Flow เหมือน wallet: download → validate size → return path
 * ถ้าไฟล์เสียหรือเล็กเกินไป → throw error → caller จะ fallback to browser
 */
export async function downloadApkNative(
  url: string,
  onProgress: ProgressCallback,
): Promise<string> {
  const fileName = 'TPIX-TRADE-update.apk';
  const fileUri = `${cacheDirectory}${fileName}`;

  // Clean up old APK / ลบไฟล์เก่าถ้ามี (เหมือน wallet: oldFile.deleteSync())
  const oldFileInfo = await getInfoAsync(fileUri);
  if (oldFileInfo.exists) {
    await deleteAsync(fileUri, { idempotent: true });
  }

  const downloadResumable = createDownloadResumable(
    url,
    fileUri,
    {},
    (dp) => {
      const { totalBytesWritten, totalBytesExpectedToWrite } = dp;
      const percent = totalBytesExpectedToWrite > 0
        ? Math.round((totalBytesWritten / totalBytesExpectedToWrite) * 100)
        : 0;
      onProgress({ totalBytesWritten, totalBytesExpectedToWrite, percent });
    },
  );

  // เก็บ reference สำหรับ cancel
  activeDownload = downloadResumable;

  try {
    const result = await downloadResumable.downloadAsync();
    if (!result?.uri) {
      throw new Error('Download failed — no file URI returned');
    }

    // Validate file: exists + size > 1KB (เหมือน wallet: file.lengthSync() < 1024)
    const fileInfo = await getInfoAsync(result.uri);
    if (!fileInfo.exists) {
      throw new Error('Downloaded file not found');
    }
    if ('size' in fileInfo && typeof fileInfo.size === 'number' && fileInfo.size < MIN_APK_SIZE) {
      // ไฟล์เล็กเกินไป — อาจเป็น error page หรือไฟล์เสีย
      await deleteAsync(result.uri, { idempotent: true }).catch(() => {});
      throw new Error('Downloaded file is too small — may be corrupted');
    }

    return result.uri;
  } finally {
    activeDownload = null;
  }
}

/**
 * Install APK via Android intent / ติดตั้ง APK ผ่าน Android intent
 * เหมือน OpenFilex.open(filePath) ใน wallet
 *
 * Returns true if intent was launched, false if failed
 * ถ้า false → caller จะ fallback to browser (เหมือน wallet)
 */
export async function installApkNative(fileUri: string): Promise<boolean> {
  try {
    const contentUri = await getContentUriAsync(fileUri);

    await IntentLauncher.startActivityAsync(
      'android.intent.action.VIEW',
      {
        data: contentUri,
        flags: 1, // FLAG_GRANT_READ_URI_PERMISSION
        type: 'application/vnd.android.package-archive',
      },
    );
    return true;
  } catch {
    // Install intent failed — caller should fallback to browser
    return false;
  }
}
