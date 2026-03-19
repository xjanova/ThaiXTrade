/**
 * App Update Service
 * ระบบตรวจสอบและอัปเดตแอป
 *
 * Checks GitHub Releases for new APK versions, downloads APK
 * with progress tracking, and triggers installation.
 * ตรวจสอบ GitHub Releases สำหรับ APK เวอร์ชันใหม่, ดาวน์โหลด APK
 * พร้อมติดตามความคืบหน้า, และเริ่มการติดตั้ง
 */

import Constants from 'expo-constants';
import { Platform, Linking } from 'react-native';
// Platform-split: .web.ts stub on web, native impl on Android/iOS
// แยกตาม platform: .web.ts stub บนเว็บ, native impl บน Android/iOS
import { downloadApkNative, installApkNative } from './nativeFileOps';

// GitHub repository info / ข้อมูล repository
const GITHUB_OWNER = 'xjanova';
const GITHUB_REPO = 'ThaiXTrade';
const GITHUB_API = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}`;

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

function extractVersion(tag: string): string | null {
  const match = tag.match(/v?(\d+\.\d+\.\d+)/);
  return match ? match[1] : null;
}

export async function checkForUpdate(): Promise<UpdateInfo> {
  const noUpdate: UpdateInfo = {
    available: false,
    latestVersion: CURRENT_VERSION,
    currentVersion: CURRENT_VERSION,
    releaseName: '',
    releaseNotes: '',
    downloadUrl: null,
    releaseUrl: `https://github.com/${GITHUB_OWNER}/${GITHUB_REPO}/releases`,
    publishedAt: '',
    mandatory: false,
    fileSize: 0,
  };

  if (Platform.OS === 'web') return noUpdate;

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10_000);

    const response = await fetch(`${GITHUB_API}/releases`, {
      headers: {
        Accept: 'application/vnd.github.v3+json',
        'User-Agent': 'TPIX-TRADE-Mobile',
      },
      signal: controller.signal,
    });
    clearTimeout(timeoutId);
    if (!response.ok) return noUpdate;

    const releases: Array<{
      tag_name: string;
      name: string;
      body: string;
      html_url: string;
      published_at: string;
      draft: boolean;
      prerelease: boolean;
      assets: Array<{ name: string; browser_download_url: string; size: number }>;
    }> = await response.json();

    const mobileRelease = releases.find((r) => {
      if (r.draft || r.prerelease) return false;
      return r.tag_name.includes('mobile') && r.assets.some((a) => a.name.toLowerCase().endsWith('.apk'));
    });
    if (!mobileRelease) return noUpdate;

    const latestVersion = extractVersion(mobileRelease.tag_name);
    if (!latestVersion) return noUpdate;

    const isNewer = compareVersions(latestVersion, CURRENT_VERSION) > 0;
    const apkAsset = mobileRelease.assets.find((a) => a.name.toLowerCase().endsWith('.apk'));
    const currentMajor = parseInt(CURRENT_VERSION.split('.')[0], 10);
    const latestMajor = parseInt(latestVersion.split('.')[0], 10);

    return {
      available: isNewer,
      latestVersion,
      currentVersion: CURRENT_VERSION,
      releaseName: mobileRelease.name || `v${latestVersion}`,
      releaseNotes: mobileRelease.body || '',
      downloadUrl: apkAsset?.browser_download_url ?? null,
      releaseUrl: mobileRelease.html_url,
      publishedAt: mobileRelease.published_at,
      mandatory: latestMajor > currentMajor,
      fileSize: apkAsset?.size ?? 0,
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
  await Linking.openURL(`https://github.com/${GITHUB_OWNER}/${GITHUB_REPO}/releases`);
}
