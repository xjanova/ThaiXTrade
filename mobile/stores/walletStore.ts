/**
 * Wallet Store — จัดการเชื่อมต่อกระเป๋าเงินจริง
 * รองรับ embedded wallet (สร้างในแอพ) + external wallet (deep link)
 * มี timeout, retry, backend registration, sound effects
 */

import { create } from 'zustand';
import {
  WalletProvider,
  WalletDetectionResult,
  detectInstalledWallets,
  openWalletApp,
  shortenAddress,
} from '@/services/walletService';
import api from '@/services/api';
import { playConnectSound, playDisconnectSound, playErrorSound } from '@/utils/sounds';

const CONNECT_TIMEOUT_MS = 10_000; // ลดเหลือ 10 วินาที (จาก 30s) เพื่อไม่ให้ค้างนาน

// ใช้เก็บ timer ID ของ connect timeout (อยู่นอก store เพื่อให้ล้างได้จากหลาย action)
let connectTimeoutId: ReturnType<typeof setTimeout> | null = null;

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
  createEmbeddedWallet: () => Promise<void>;
  disconnectWallet: () => void;
  showModal: () => void;
  hideModal: () => void;
  clearError: () => void;
}

/** สร้าง address แบบสุ่ม (ไม่ใช้ private key จริง — demo/trading wallet) */
function generateRandomAddress(): string {
  const bytes = new Uint8Array(20);
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < 20; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  const hex = Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('');
  return `0x${hex}`;
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
    const now = Date.now();
    const { lastDetectedAt } = get();
    if (lastDetectedAt && now - lastDetectedAt < 10_000) return;

    set({ isDetecting: true });
    try {
      const results = await detectInstalledWallets();
      set({ detectedWallets: results, lastDetectedAt: now });
    } catch {
      // Silent fail — เก็บผลลัพธ์เดิมไว้
    } finally {
      set({ isDetecting: false });
    }
  },

  /** สร้าง embedded wallet ภายในแอพ — เชื่อมต่อทันที ไม่ต้องรอ backend */
  createEmbeddedWallet: async () => {
    if (connectTimeoutId) { clearTimeout(connectTimeoutId); connectTimeoutId = null; }
    set({ isConnecting: true, connectError: null });

    try {
      const address = generateRandomAddress();

      // เชื่อมต่อทันที — ไม่รอ backend
      playConnectSound();
      set({
        wallet: {
          address,
          shortAddress: shortenAddress(address),
          providerId: 'tpix-embedded',
          providerName: 'TPIX Wallet',
          chain: 'ETH',
          connectedAt: Date.now(),
        },
        isConnecting: false,
        isModalVisible: false,
        connectError: null,
      });

      // ลงทะเบียนกับ backend แบบ fire-and-forget (ไม่ block UI)
      // ใช้ /wallet/connect (ไม่ใช่ /wallet/register ที่ไม่มีอยู่จริง)
      api.walletConnect({
        wallet_address: address,
        chain_id: 56, // Default BSC
        wallet_type: 'tpix_wallet',
      }).catch(() => {
        // ลงทะเบียนล้มเหลว — ไม่กระทบ UX
      });
    } catch {
      playErrorSound();
      set({
        connectError: 'Failed to create wallet. Please try again.',
        isConnecting: false,
      });
    }
  },

  connectWallet: async (provider: WalletProvider) => {
    set({ isConnecting: true, connectError: null });

    try {
      const opened = await openWalletApp(provider);

      if (!opened) {
        playErrorSound();
        set({
          connectError: `${provider.name} is not installed. Please install it or use TPIX Wallet instead.`,
          isConnecting: false,
        });
        return false;
      }

      // เปิด wallet app สำเร็จ — รอ deep link callback
      // ตั้ง timeout ป้องกันค้าง (ล้าง timer เก่าก่อน)
      if (connectTimeoutId) clearTimeout(connectTimeoutId);
      connectTimeoutId = setTimeout(() => {
        connectTimeoutId = null;
        const state = get();
        if (state.isConnecting) {
          set({
            isConnecting: false,
            connectError: 'Connection timed out. Please try again or use TPIX Wallet.',
          });
        }
      }, CONNECT_TIMEOUT_MS);

      return true;
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Connection failed';
      playErrorSound();
      set({
        connectError: message,
        isConnecting: false,
      });
      return false;
    }
  },

  disconnectWallet: () => {
    playDisconnectSound();
    set({
      wallet: null,
      connectError: null,
    });
    // Clear API token เมื่อ disconnect
    api.clearToken();
  },

  showModal: () => {
    set({ isModalVisible: true });
    get().detectWallets();
  },

  hideModal: () => {
    if (connectTimeoutId) { clearTimeout(connectTimeoutId); connectTimeoutId = null; }
    set({ isModalVisible: false, connectError: null, isConnecting: false });
  },

  clearError: () => {
    set({ connectError: null });
  },
}));

/**
 * Handle deep link callback from wallet app
 * เรียกใช้เมื่อแอปได้รับ deep link tpixtrade://wallet/connect?address=...
 */
export async function handleWalletCallback(url: string): Promise<void> {
  try {
    const parsed = new URL(url);
    const path = parsed.pathname || parsed.hostname;

    if (path === 'wallet/connect' || path === '/wallet/connect') {
      const address = parsed.searchParams.get('address');
      const chain = parsed.searchParams.get('chain') || 'ETH';
      const provider = parsed.searchParams.get('provider') || 'unknown';

      if (address) {
        // ล้าง connect timeout
        if (connectTimeoutId) { clearTimeout(connectTimeoutId); connectTimeoutId = null; }

        // เชื่อมต่อทันที — ไม่รอ backend (fire-and-forget เหมือน createEmbeddedWallet)
        playConnectSound();
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
          connectError: null,
        });

        // ลงทะเบียนกับ backend แบบ fire-and-forget (ไม่ block UI)
        api.walletConnect({
          wallet_address: address,
          chain_id: 56,
          wallet_type: provider,
        }).catch(() => {
          // ลงทะเบียนล้มเหลว — ไม่กระทบ UX
        });
      }
    }
  } catch {
    // Invalid URL, ignore
  }
}
