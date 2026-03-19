/**
 * App Update Service
 * ระบบตรวจสอบและอัปเดตแอป
 *
 * Checks GitHub Releases for new APK versions and provides
 * download URLs for in-app update prompts.
 * ตรวจสอบ GitHub Releases สำหรับ APK เวอร์ชันใหม่
 * และให้ URL ดาวน์โหลดสำหรับแจ้งเตือนอัปเดตในแอป
 */

import Constants from 'expo-constants';
import { Platform, Linking } from 'react-native';

// GitHub repository info / ข้อมูล repository
const GITHUB_OWNER = 'xjanova';
const GITHUB_REPO = 'ThaiXTrade';
const GITHUB_API = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}`;

// Current app version / เวอร์ชันแอปปัจจุบัน
export const CURRENT_VERSION = Constants.expoConfig?.version ?? '1.0.0';
export const CURRENT_BUILD = Constants.expoConfig?.android?.versionCode ?? 1;

export interface UpdateInfo {
  /** Whether an update is available / มีอัปเดตหรือไม่ */
  available: boolean;
  /** Latest version string / เวอร์ชันล่าสุด */
  latestVersion: string;
  /** Current version string / เวอร์ชันปัจจุบัน */
  currentVersion: string;
  /** Release name/title / ชื่อ release */
  releaseName: string;
  /** Release notes/body / รายละเอียด release */
  releaseNotes: string;
  /** APK download URL / URL ดาวน์โหลด APK */
  downloadUrl: string | null;
  /** Release page URL / URL หน้า release */
  releaseUrl: string;
  /** Published date / วันที่เผยแพร่ */
  publishedAt: string;
  /** Whether this is mandatory / บังคับอัปเดตหรือไม่ */
  mandatory: boolean;
}

/**
 * Compare two semver version strings
 * เปรียบเทียบเวอร์ชัน semver สองตัว
 *
 * Returns: 1 if a > b, -1 if a < b, 0 if equal
 */
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
 * Extract version number from GitHub release tag
 * ดึงเลขเวอร์ชันจาก tag ของ GitHub release
 *
 * Handles formats like: "mobile-v1.0.1-build42-123", "v1.0.1", "1.0.1"
 */
function extractVersion(tag: string): string | null {
  const match = tag.match(/v?(\d+\.\d+\.\d+)/);
  return match ? match[1] : null;
}

/**
 * Check for updates from GitHub Releases
 * ตรวจสอบอัปเดตจาก GitHub Releases
 *
 * Fetches the latest release that has a mobile APK asset
 * and compares against current app version.
 * ดึง release ล่าสุดที่มีไฟล์ APK และเปรียบเทียบกับเวอร์ชันปัจจุบัน
 */
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
  };

  // Skip update check on web / ข้ามการตรวจสอบบนเว็บ
  if (Platform.OS === 'web') {
    return noUpdate;
  }

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

    if (!response.ok) {
      console.warn('[UpdateService] GitHub API error:', response.status);
      return noUpdate;
    }

    const releases: Array<{
      tag_name: string;
      name: string;
      body: string;
      html_url: string;
      published_at: string;
      draft: boolean;
      prerelease: boolean;
      assets: Array<{
        name: string;
        browser_download_url: string;
        size: number;
      }>;
    }> = await response.json();

    // Find the latest non-draft, non-prerelease mobile release with an APK
    // หา release ล่าสุดที่ไม่ใช่ draft/prerelease และมีไฟล์ APK
    const mobileRelease = releases.find((release) => {
      if (release.draft || release.prerelease) return false;
      const isMobileTag = release.tag_name.includes('mobile');
      const hasApk = release.assets.some((a) =>
        a.name.toLowerCase().endsWith('.apk'),
      );
      return isMobileTag && hasApk;
    });

    if (!mobileRelease) {
      return noUpdate;
    }

    const latestVersion = extractVersion(mobileRelease.tag_name);
    if (!latestVersion) {
      return noUpdate;
    }

    const isNewer = compareVersions(latestVersion, CURRENT_VERSION) > 0;
    const apkAsset = mobileRelease.assets.find((a) =>
      a.name.toLowerCase().endsWith('.apk'),
    );

    // Check if major version changed (mandatory update)
    // ตรวจว่า major version เปลี่ยนหรือไม่ (บังคับอัปเดต)
    const currentMajor = parseInt(CURRENT_VERSION.split('.')[0], 10);
    const latestMajor = parseInt(latestVersion.split('.')[0], 10);
    const mandatory = latestMajor > currentMajor;

    return {
      available: isNewer,
      latestVersion,
      currentVersion: CURRENT_VERSION,
      releaseName: mobileRelease.name || `v${latestVersion}`,
      releaseNotes: mobileRelease.body || '',
      downloadUrl: apkAsset?.browser_download_url ?? null,
      releaseUrl: mobileRelease.html_url,
      publishedAt: mobileRelease.published_at,
      mandatory,
    };
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      console.warn('[UpdateService] Update check timed out');
    } else {
      console.warn('[UpdateService] Update check failed:', error);
    }
    return noUpdate;
  }
}

/**
 * Open the APK download URL in the browser
 * เปิด URL ดาวน์โหลด APK ในเบราว์เซอร์
 */
export async function downloadUpdate(url: string): Promise<void> {
  const canOpen = await Linking.canOpenURL(url);
  if (canOpen) {
    await Linking.openURL(url);
  }
}

/**
 * Open the releases page on GitHub
 * เปิดหน้า releases บน GitHub
 */
export async function openReleasesPage(): Promise<void> {
  const url = `https://github.com/${GITHUB_OWNER}/${GITHUB_REPO}/releases`;
  await Linking.openURL(url);
}
