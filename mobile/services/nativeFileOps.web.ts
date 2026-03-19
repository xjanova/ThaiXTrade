/**
 * Web stub for native file operations
 * Stub สำหรับเว็บ - ฟังก์ชัน native จะไม่ทำงานบนเว็บ
 */

type ProgressCallback = (progress: {
  totalBytesWritten: number;
  totalBytesExpectedToWrite: number;
  percent: number;
}) => void;

export async function downloadApkNative(
  _url: string,
  _onProgress: ProgressCallback,
): Promise<string> {
  throw new Error('APK download is not supported on web / ไม่รองรับดาวน์โหลด APK บนเว็บ');
}

export async function installApkNative(_fileUri: string): Promise<void> {
  throw new Error('APK install is not supported on web / ไม่รองรับติดตั้ง APK บนเว็บ');
}
