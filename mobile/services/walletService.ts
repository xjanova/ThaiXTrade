/**
 * Wallet Detection & Connection Service
 * บริการตรวจจับ & เชื่อมต่อกระเป๋าเงิน
 *
 * Automatically detects installed wallet apps via deep linking,
 * works from any browser on both Android and iOS.
 * ตรวจจับแอปกระเป๋าเงินที่ติดตั้งอัตโนมัติผ่าน deep linking
 * ทำงานได้จากทุกเบราว์เซอร์ทั้ง Android และ iOS
 */

import { Linking, Platform } from 'react-native';

// --- Wallet Provider Definitions / รายชื่อกระเป๋าเงินที่รองรับ ---

export interface WalletProvider {
  id: string;
  name: string;
  icon: string; // Ionicons name
  iconColor: string;
  /** Deep link scheme to check if app is installed / สคีม deep link เพื่อตรวจสอบว่าแอปติดตั้งหรือไม่ */
  deepLinkScheme: string;
  /** URL to open the wallet app / URL สำหรับเปิดแอปกระเป๋าเงิน */
  connectUrl: string;
  /** WalletConnect compatible / รองรับ WalletConnect */
  walletConnectSupported: boolean;
  /** Android package name for intent detection / ชื่อแพ็กเกจ Android */
  androidPackage?: string;
  /** iOS app store ID / ID แอปใน App Store */
  iosAppStoreId?: string;
  /** Universal link for detection / ลิงก์สากลสำหรับตรวจจับ */
  universalLink?: string;
  /** Supported chains / เครือข่ายที่รองรับ */
  supportedChains: string[];
  /** Download URLs / ลิงก์ดาวน์โหลด */
  downloadUrl: {
    android: string;
    ios: string;
  };
}

export const WALLET_PROVIDERS: WalletProvider[] = [
  {
    id: 'metamask',
    name: 'MetaMask',
    icon: 'diamond-outline',
    iconColor: '#F6851B',
    deepLinkScheme: 'metamask://',
    connectUrl: 'metamask://dapp/tpixtrade.com',
    walletConnectSupported: true,
    androidPackage: 'io.metamask',
    iosAppStoreId: '1438144202',
    universalLink: 'https://metamask.app.link',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'ARBITRUM', 'OPTIMISM', 'AVALANCHE', 'BASE'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=io.metamask',
      ios: 'https://apps.apple.com/app/metamask/id1438144202',
    },
  },
  {
    id: 'trustwallet',
    name: 'Trust Wallet',
    icon: 'shield-checkmark-outline',
    iconColor: '#3375BB',
    deepLinkScheme: 'trust://',
    connectUrl: 'trust://open_url?coin_id=60&url=https://tpixtrade.com',
    walletConnectSupported: true,
    androidPackage: 'com.wallet.crypto.trustapp',
    iosAppStoreId: '1288339409',
    universalLink: 'https://link.trustwallet.com',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'SOL', 'TRON', 'AVALANCHE', 'ARBITRUM'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=com.wallet.crypto.trustapp',
      ios: 'https://apps.apple.com/app/trust-wallet/id1288339409',
    },
  },
  {
    id: 'coinbase',
    name: 'Coinbase Wallet',
    icon: 'logo-bitcoin',
    iconColor: '#0052FF',
    deepLinkScheme: 'cbwallet://',
    connectUrl: 'cbwallet://dapp?url=https://tpixtrade.com',
    walletConnectSupported: true,
    androidPackage: 'org.toshi',
    iosAppStoreId: '1278383455',
    universalLink: 'https://go.cb-w.com',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'ARBITRUM', 'OPTIMISM', 'BASE', 'AVALANCHE'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=org.toshi',
      ios: 'https://apps.apple.com/app/coinbase-wallet/id1278383455',
    },
  },
  {
    id: 'tokenpocket',
    name: 'TokenPocket',
    icon: 'wallet-outline',
    iconColor: '#2980FE',
    deepLinkScheme: 'tpoutside://',
    connectUrl: 'tpoutside://open?params={"url":"https://tpixtrade.com","chain":"BSC"}',
    walletConnectSupported: true,
    androidPackage: 'vip.mytokenpocket',
    iosAppStoreId: '1436028753',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'TRON', 'SOL', 'AVALANCHE'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=vip.mytokenpocket',
      ios: 'https://apps.apple.com/app/tokenpocket/id1436028753',
    },
  },
  {
    id: 'okx',
    name: 'OKX Wallet',
    icon: 'globe-outline',
    iconColor: '#FFFFFF',
    deepLinkScheme: 'okx://',
    connectUrl: 'okx://wallet/dapp/url?dappUrl=https://tpixtrade.com',
    walletConnectSupported: true,
    androidPackage: 'com.okinc.okex.gp',
    iosAppStoreId: '1327268470',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'SOL', 'TRON', 'ARBITRUM', 'OPTIMISM'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=com.okinc.okex.gp',
      ios: 'https://apps.apple.com/app/okx/id1327268470',
    },
  },
  {
    id: 'phantom',
    name: 'Phantom',
    icon: 'flash-outline',
    iconColor: '#AB9FF2',
    deepLinkScheme: 'phantom://',
    connectUrl: 'phantom://browse/https://tpixtrade.com',
    walletConnectSupported: false,
    androidPackage: 'app.phantom',
    iosAppStoreId: '1598432977',
    universalLink: 'https://phantom.app/ul',
    supportedChains: ['SOL', 'ETH', 'POLYGON', 'BASE'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=app.phantom',
      ios: 'https://apps.apple.com/app/phantom/id1598432977',
    },
  },
  {
    id: 'safepal',
    name: 'SafePal',
    icon: 'hardware-chip-outline',
    iconColor: '#4A21EF',
    deepLinkScheme: 'safepalwallet://',
    connectUrl: 'safepalwallet://dapp?url=https://tpixtrade.com',
    walletConnectSupported: true,
    androidPackage: 'io.safepal.wallet',
    iosAppStoreId: '1548297139',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'TRON', 'SOL', 'AVALANCHE'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=io.safepal.wallet',
      ios: 'https://apps.apple.com/app/safepal/id1548297139',
    },
  },
  {
    id: 'bitget',
    name: 'Bitget Wallet',
    icon: 'swap-horizontal-outline',
    iconColor: '#00F0FF',
    deepLinkScheme: 'bitkeep://',
    connectUrl: 'bitkeep://bkconnect?action=dapp&url=https://tpixtrade.com',
    walletConnectSupported: true,
    androidPackage: 'com.bitkeep.wallet',
    iosAppStoreId: '1395301115',
    supportedChains: ['ETH', 'BSC', 'POLYGON', 'ARBITRUM', 'OPTIMISM', 'SOL'],
    downloadUrl: {
      android: 'https://play.google.com/store/apps/details?id=com.bitkeep.wallet',
      ios: 'https://apps.apple.com/app/bitget-wallet/id1395301115',
    },
  },
];

// --- Detection Result / ผลการตรวจจับ ---

export interface WalletDetectionResult {
  provider: WalletProvider;
  installed: boolean;
  /** Detection method used / วิธีการตรวจจับที่ใช้ */
  method: 'deep_link' | 'user_agent' | 'universal_link' | 'assumed';
}

/**
 * Detect if a specific wallet app is installed by checking its deep link scheme
 * ตรวจจับว่าแอปกระเป๋าเงินติดตั้งหรือไม่ โดยตรวจสอบ deep link scheme
 */
async function canOpenDeepLink(url: string): Promise<boolean> {
  try {
    return await Linking.canOpenURL(url);
  } catch {
    return false;
  }
}

/**
 * Detect installed wallet apps on the device
 * ตรวจจับแอปกระเป๋าเงินที่ติดตั้งบนอุปกรณ์
 *
 * Uses multiple detection strategies:
 * 1. Deep link scheme checking (primary - works on all browsers)
 * 2. Universal link detection (fallback for iOS)
 * 3. User agent detection (for in-app browsers)
 *
 * ใช้หลายวิธีในการตรวจจับ:
 * 1. ตรวจสอบ deep link scheme (หลัก - ใช้ได้กับทุกเบราว์เซอร์)
 * 2. ตรวจจับ universal link (สำรองสำหรับ iOS)
 * 3. ตรวจจับจาก user agent (สำหรับ in-app browser)
 */
export async function detectInstalledWallets(): Promise<WalletDetectionResult[]> {
  const results: WalletDetectionResult[] = [];

  // Check all wallet providers in parallel
  // ตรวจสอบกระเป๋าเงินทั้งหมดพร้อมกัน
  const detectionPromises = WALLET_PROVIDERS.map(async (provider) => {
    // Strategy 1: Deep link scheme check (works from any browser)
    // กลยุทธ์ที่ 1: ตรวจสอบ deep link scheme (ใช้ได้จากทุกเบราว์เซอร์)
    const canOpenScheme = await canOpenDeepLink(provider.deepLinkScheme);
    if (canOpenScheme) {
      return { provider, installed: true, method: 'deep_link' as const };
    }

    // Strategy 2: Universal link check (iOS fallback)
    // กลยุทธ์ที่ 2: ตรวจสอบ universal link (สำรองสำหรับ iOS)
    if (provider.universalLink && Platform.OS === 'ios') {
      const canOpenUniversal = await canOpenDeepLink(provider.universalLink);
      if (canOpenUniversal) {
        return { provider, installed: true, method: 'universal_link' as const };
      }
    }

    return { provider, installed: false, method: 'deep_link' as const };
  });

  const detectionResults = await Promise.all(detectionPromises);
  results.push(...detectionResults);

  // Strategy 3: User agent detection (detect in-app browser)
  // กลยุทธ์ที่ 3: ตรวจจับ user agent (ตรวจจับ in-app browser)
  const inAppWallet = detectInAppBrowser();
  if (inAppWallet) {
    // Move detected in-app wallet to top and mark as installed
    // ย้ายกระเป๋าที่ตรวจพบจาก in-app browser ไปด้านบนสุด
    const idx = results.findIndex((r) => r.provider.id === inAppWallet);
    if (idx !== -1) {
      results[idx].installed = true;
      results[idx].method = 'user_agent';
    }
  }

  // Sort: installed first, then alphabetically
  // เรียงลำดับ: ที่ติดตั้งก่อน จากนั้นเรียงตามตัวอักษร
  results.sort((a, b) => {
    if (a.installed !== b.installed) return a.installed ? -1 : 1;
    return a.provider.name.localeCompare(b.provider.name);
  });

  return results;
}

/**
 * Detect if running inside a wallet's in-app browser
 * ตรวจจับว่ากำลังรันภายใน in-app browser ของกระเป๋าเงินหรือไม่
 *
 * Checks the user agent string for wallet-specific identifiers.
 * Works regardless of which browser opened the DApp.
 * ตรวจสอบ user agent string สำหรับตัวระบุเฉพาะของกระเป๋าเงิน
 * ทำงานได้ไม่ว่าจะเปิดจากเบราว์เซอร์ไหน
 */
function detectInAppBrowser(): string | null {
  if (Platform.OS === 'web') {
    const ua = (typeof navigator !== 'undefined' && navigator.userAgent) || '';
    const uaLower = ua.toLowerCase();

    // Each wallet's in-app browser has a unique user agent signature
    // In-app browser ของแต่ละกระเป๋ามี signature ของ user agent ที่ไม่ซ้ำกัน
    if (uaLower.includes('metamask') || (typeof window !== 'undefined' && (window as any).ethereum?.isMetaMask)) {
      return 'metamask';
    }
    if (uaLower.includes('trust') || (typeof window !== 'undefined' && (window as any).ethereum?.isTrust)) {
      return 'trustwallet';
    }
    if (uaLower.includes('coinbasebrowser') || (typeof window !== 'undefined' && (window as any).ethereum?.isCoinbaseWallet)) {
      return 'coinbase';
    }
    if (uaLower.includes('tokenpocket') || (typeof window !== 'undefined' && (window as any).ethereum?.isTokenPocket)) {
      return 'tokenpocket';
    }
    if (uaLower.includes('okex') || uaLower.includes('okapp')) {
      return 'okx';
    }
    if (uaLower.includes('phantom')) {
      return 'phantom';
    }
    if (uaLower.includes('safepal')) {
      return 'safepal';
    }
    if (uaLower.includes('bitkeep') || uaLower.includes('bitget')) {
      return 'bitget';
    }
  }

  // Native app: check injected providers (React Native WebView)
  // แอปเนทีฟ: ตรวจสอบ provider ที่ถูก inject (React Native WebView)
  if (typeof globalThis !== 'undefined' && (globalThis as any).ethereum) {
    const eth = (globalThis as any).ethereum;
    if (eth.isMetaMask) return 'metamask';
    if (eth.isTrust) return 'trustwallet';
    if (eth.isCoinbaseWallet) return 'coinbase';
    if (eth.isTokenPocket) return 'tokenpocket';
  }

  return null;
}

/**
 * Open a wallet app for connection
 * เปิดแอปกระเป๋าเงินเพื่อเชื่อมต่อ
 */
export async function openWalletApp(provider: WalletProvider): Promise<boolean> {
  try {
    const canOpen = await Linking.canOpenURL(provider.connectUrl);
    if (canOpen) {
      await Linking.openURL(provider.connectUrl);
      return true;
    }

    // Fallback: try deep link scheme directly
    // สำรอง: ลอง deep link scheme โดยตรง
    const canOpenScheme = await Linking.canOpenURL(provider.deepLinkScheme);
    if (canOpenScheme) {
      await Linking.openURL(provider.deepLinkScheme);
      return true;
    }

    return false;
  } catch {
    return false;
  }
}

/**
 * Open store to download a wallet app
 * เปิดร้านค้าเพื่อดาวน์โหลดแอปกระเป๋าเงิน
 */
export async function openWalletDownload(provider: WalletProvider): Promise<void> {
  const url = Platform.OS === 'ios'
    ? provider.downloadUrl.ios
    : provider.downloadUrl.android;
  await Linking.openURL(url);
}

/**
 * Get wallet providers filtered by chain
 * ดึงรายชื่อกระเป๋าเงินตามเครือข่าย
 */
export function getWalletsByChain(chainSymbol: string): WalletProvider[] {
  return WALLET_PROVIDERS.filter((w) =>
    w.supportedChains.includes(chainSymbol.toUpperCase()),
  );
}

/**
 * Shorten wallet address for display
 * ย่อ address กระเป๋าเงินสำหรับแสดงผล
 */
export function shortenAddress(address: string, chars = 4): string {
  if (!address || address.length < chars * 2 + 2) return address;
  return `${address.slice(0, chars + 2)}...${address.slice(-chars)}`;
}
