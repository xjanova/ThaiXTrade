/**
 * App Update Service
 * ระบบตรวจสอบและอัปเดตแอป
 *
 * ใช้ API ของเราเอง (tpix.online) แทน GitHub โดยตรง
 * เพื่อให้ repo เป็น private ได้ ไม่ต้องเปิดให้คนอื่นเห็น
 */

import Constants from 'expo-constants';
import { Platform, Linking } from 'react-native';
import { downloadApkNative, installApkNative } from './nativeFileOps';

// API base URL / URL หลักของ API
const API_BASE = Constants.expoConfig?.extra?.apiBaseUrl
  ?? 'https://tpix.online/api/v1';

// Current app version / เวอร์ชันแอปปัจจุบัน
export const CURRENT_VERSION = Constants.expoConfig?.version ?? '1.0.0';
export const CURRENT_BUILD = Constants.expoConfig?.android?.versionCode ?? 1;

export interface UpdateInfo {
  available: boolean;
  latestVersion: string;
  currentVersion: string;
  releaseName: string;
  releaseNotes: string;
  downloadUrl: string | null;
  releaseUrl: string;
  publishedAt: string;
  mandatory: boolean;
  fileSize: number;
}

export type DownloadProgressCallback = (progress: {
  totalBytesWritten: number;
  totalBytesExpectedToWrite: number;
  percent: number;
}) => void;

export function compareVersions(a: string, b: string): number {
  const partsA = a.replace(/^v/, '').split('.').map(Number);
  const partsB = b.replace(/^v/, '').split('.').map(Number);
  for (let i = 0; i < Math.max(partsA.length, partsB.length); i++) {
    const numA = partsA[i] ?? 0;
    const numB = partsB[i] ?? 0;
    if (numA > numB) return 1;
    if (numA < numB) return -1;
  }
  return 0;
}

/**
 * Check for updates via our own API
 * ตรวจสอบอัปเดตผ่าน API ของเราเอง (ไม่เรียก GitHub โดยตรง)
 */
export async function checkForUpdate(): Promise<UpdateInfo> {
  const noUpdate: UpdateInfo = {
    available: false,
    latestVersion: CURRENT_VERSION,
    currentVersion: CURRENT_VERSION,
    releaseName: '',
    releaseNotes: '',
    downloadUrl: null,
    releaseUrl: `${API_BASE}/app/latest`,
    publishedAt: '',
    mandatory: false,
    fileSize: 0,
  };

  if (Platform.OS === 'web') return noUpdate;

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10_000);

    const response = await fetch(
      `${API_BASE}/app/update-check?version=${CURRENT_VERSION}&platform=${Platform.OS}`,
      {
        headers: {
          Accept: 'application/json',
          'User-Agent': 'TPIX-TRADE-Mobile',
        },
        signal: controller.signal,
      },
    );
    clearTimeout(timeoutId);

    if (!response.ok) return noUpdate;

    const json = await response.json();
    if (!json.success || !json.data) return noUpdate;

    const data = json.data;

    return {
      available: data.available ?? false,
      latestVersion: data.latest_version ?? CURRENT_VERSION,
      currentVersion: CURRENT_VERSION,
      releaseName: data.release_name ?? '',
      releaseNotes: data.release_notes ?? '',
      downloadUrl: data.download_url ?? null,
      releaseUrl: `${API_BASE}/app/latest`,
      publishedAt: data.published_at ?? '',
      mandatory: data.mandatory ?? false,
      fileSize: data.file_size ?? 0,
    };
  } catch {
    return noUpdate;
  }
}

/**
 * Download APK with progress / ดาวน์โหลด APK พร้อมแถบความคืบหน้า
 */
export async function downloadApk(
  url: string,
  onProgress: DownloadProgressCallback,
): Promise<string> {
  if (Platform.OS === 'web') {
    await Linking.openURL(url);
    throw new Error('Web download not supported');
  }
  return downloadApkNative(url, onProgress);
}

/**
 * Install APK from local file / ติดตั้ง APK จากไฟล์ในเครื่อง
 */
export async function installApk(fileUri: string): Promise<void> {
  if (Platform.OS !== 'android') {
    await Linking.openURL(fileUri);
    return;
  }
  return installApkNative(fileUri);
}

export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

export async function openReleasesPage(): Promise<void> {
  await Linking.openURL('https://tpix.online/download');
}
