/**
 * Wallet Store - State management for wallet connections
 * จัดการสถานะกระเป๋าเงิน
 */

import { create } from 'zustand';
import {
  WalletProvider,
  WalletDetectionResult,
  detectInstalledWallets,
  openWalletApp,
  shortenAddress,
} from '@/services/walletService';

export interface ConnectedWallet {
  address: string;
  shortAddress: string;
  providerId: string;
  providerName: string;
  chain: string;
  connectedAt: number;
}

interface WalletState {
  // Connection state / สถานะการเชื่อมต่อ
  wallet: ConnectedWallet | null;
  isConnecting: boolean;
  connectError: string | null;

  // Detection state / สถานะการตรวจจับ
  detectedWallets: WalletDetectionResult[];
  isDetecting: boolean;
  lastDetectedAt: number | null;

  // Modal state / สถานะ modal
  isModalVisible: boolean;

  // Actions / การดำเนินการ
  detectWallets: () => Promise<void>;
  connectWallet: (provider: WalletProvider) => Promise<boolean>;
  disconnectWallet: () => void;
  showModal: () => void;
  hideModal: () => void;
  clearError: () => void;
}

export const useWalletStore = create<WalletState>((set, get) => ({
  wallet: null,
  isConnecting: false,
  connectError: null,

  detectedWallets: [],
  isDetecting: false,
  lastDetectedAt: null,

  isModalVisible: false,

  detectWallets: async () => {
    // Throttle: skip if detected within 10 seconds
    // จำกัดความถี่: ข้ามถ้าตรวจจับไปแล้วภายใน 10 วินาที
    const now = Date.now();
    const { lastDetectedAt } = get();
    if (lastDetectedAt && now - lastDetectedAt < 10_000) return;

    set({ isDetecting: true });
    try {
      const results = await detectInstalledWallets();
      set({ detectedWallets: results, lastDetectedAt: now });
    } catch {
      // Silent fail - keep previous results
      // ล้มเหลวเงียบ - เก็บผลลัพธ์เดิมไว้
    } finally {
      set({ isDetecting: false });
    }
  },

  connectWallet: async (provider: WalletProvider) => {
    set({ isConnecting: true, connectError: null });
    try {
      const opened = await openWalletApp(provider);
      if (!opened) {
        set({
          connectError: `Cannot open ${provider.name}. Please install it first. / ไม่สามารถเปิด ${provider.name} ได้ กรุณาติดตั้งก่อน`,
          isConnecting: false,
        });
        return false;
      }

      // In a real implementation, we'd wait for the callback
      // ในการใช้งานจริง เราจะรอ callback จากกระเป๋าเงิน
      // For now, set a pending state
      set({ isConnecting: false });
      return true;
    } catch {
      set({
        connectError: 'Connection failed / การเชื่อมต่อล้มเหลว',
        isConnecting: false,
      });
      return false;
    }
  },

  disconnectWallet: () => {
    set({
      wallet: null,
      connectError: null,
    });
  },

  showModal: () => {
    set({ isModalVisible: true });
    // Trigger detection when opening modal
    // ตรวจจับเมื่อเปิด modal
    get().detectWallets();
  },

  hideModal: () => {
    set({ isModalVisible: false, connectError: null });
  },

  clearError: () => {
    set({ connectError: null });
  },
}));

/**
 * Handle deep link callback from wallet app
 * จัดการ deep link callback จากแอปกระเป๋าเงิน
 *
 * Call this when the app receives a tpixtrade:// deep link
 * เรียกใช้เมื่อแอปได้รับ deep link tpixtrade://
 */
export function handleWalletCallback(url: string): void {
  // Parse the callback URL
  // แยก URL callback
  try {
    const parsed = new URL(url);
    const path = parsed.pathname || parsed.hostname;

    if (path === 'wallet/connect' || path === '/wallet/connect') {
      const address = parsed.searchParams.get('address');
      const chain = parsed.searchParams.get('chain') || 'ETH';
      const provider = parsed.searchParams.get('provider') || 'unknown';

      if (address) {
        useWalletStore.setState({
          wallet: {
            address,
            shortAddress: shortenAddress(address),
            providerId: provider,
            providerName: provider,
            chain,
            connectedAt: Date.now(),
          },
          isConnecting: false,
          isModalVisible: false,
        });
      }
    }
  } catch {
    // Invalid URL, ignore
  }
}
