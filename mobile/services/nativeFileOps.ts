/**
 * Native file operations for APK download & install (Android)
 * การจัดการไฟล์ native สำหรับดาวน์โหลดและติดตั้ง APK (Android)
 *
 * This file is only imported on native platforms.
 * ไฟล์นี้จะถูก import เฉพาะบน native platform เท่านั้น
 */

import {
  cacheDirectory,
  getInfoAsync,
  deleteAsync,
  createDownloadResumable,
  getContentUriAsync,
} from 'expo-file-system/build/legacy/FileSystem';
import * as IntentLauncher from 'expo-intent-launcher';

type ProgressCallback = (progress: {
  totalBytesWritten: number;
  totalBytesExpectedToWrite: number;
  percent: number;
}) => void;

/**
 * Download APK with progress / ดาวน์โหลด APK พร้อมแสดงความคืบหน้า
 */
export async function downloadApkNative(
  url: string,
  onProgress: ProgressCallback,
): Promise<string> {
  const fileName = 'TPIX-TRADE-update.apk';
  const fileUri = `${cacheDirectory}${fileName}`;

  // Delete old file if exists / ลบไฟล์เก่าถ้ามี
  const fileInfo = await getInfoAsync(fileUri);
  if (fileInfo.exists) {
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

  const result = await downloadResumable.downloadAsync();
  if (!result?.uri) {
    throw new Error('Download failed / ดาวน์โหลดล้มเหลว');
  }
  return result.uri;
}

/**
 * Install APK via Android intent / ติดตั้ง APK ผ่าน Android intent
 */
export async function installApkNative(fileUri: string): Promise<void> {
  const contentUri = await getContentUriAsync(fileUri);

  await IntentLauncher.startActivityAsync(
    'android.intent.action.VIEW',
    {
      data: contentUri,
      flags: 1, // FLAG_GRANT_READ_URI_PERMISSION
      type: 'application/vnd.android.package-archive',
    },
  );
}
