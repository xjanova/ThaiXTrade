/**
 * Wallet Store v3 — Simplified & Reliable
 *
 * สร้าง wallet ด้วย ethers.js -> เก็บ key ใน SecureStore
 * รองรับ: สร้างใหม่, import mnemonic
 * มี chain management: เพิ่ม/สลับ chain, TPIX Chain default
 *
 * v3 Changes:
 * - ลบ wallet detection (canOpenURL ไม่เสถียรบน Android 11+)
 * - ลบ external wallet deep link connect (ไม่มี WalletConnect v2)
 * - เพิ่ม error handling ที่ดีขึ้น
 * - ต้องมี crypto polyfill (expo-crypto) ก่อน import
 *
 * Developed by Xman Studio
 */

import { create } from 'zustand';
import { InteractionManager } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { shortenAddress, SUPPORTED_CHAINS, type ChainConfig } from '@/services/walletService';
import api from '@/services/api';
import { playConnectSound, playDisconnectSound, playErrorSound } from '@/utils/sounds';
import { setSecureItem, getSecureItem, deleteSecureItem } from '@/utils/secureStorage';

/**
 * Lazy import ethers.js — ไลบรารีใหญ่ (~1MB)
 * ไม่โหลดตอนเปิดแอป โหลดเฉพาะตอนใช้จริง (สร้าง/import wallet)
 * ป้องกัน startup lag บน Hermes
 */
let _ethers: typeof import('ethers') | null = null;
async function getEthers() {
  if (!_ethers) _ethers = await import('ethers');
  return _ethers;
}

/**
 * รอให้ animation/interaction จบก่อน แล้วค่อยทำ heavy work
 * ป้องกัน UI ค้างตอน modal กำลังเปิด
 */
function waitForUI(): Promise<void> {
  return new Promise(resolve => {
    InteractionManager.runAfterInteractions(() => {
      // delay อีก 50ms ให้ React commit render จริง
      setTimeout(resolve, 50);
    });
  });
}

// ========== Constants ==========
const SECURE_KEY_WALLET = 'tpix_wallet_key';
const SECURE_KEY_MNEMONIC = 'tpix_wallet_mnemonic';
const STORAGE_KEY_WALLET_STATE = 'tpix_wallet_state';
const STORAGE_KEY_CHAINS = 'tpix_user_chains';
const STORAGE_KEY_SETTINGS = 'tpix_user_settings';

// ========== Types ==========
export interface ConnectedWallet {
  address: string;
  shortAddress: string;
  providerId: string;
  providerName: string;
  chain: string;
  chainId: number;
  connectedAt: number;
  isEmbedded: boolean; // สร้างในแอป (มี private key)
}

export interface UserSettings {
  language: 'en' | 'th';
  currency: 'USD' | 'THB';
  defaultPair: string;
  defaultOrderType: 'limit' | 'market';
  slippage: number;
  biometricEnabled: boolean;
  pushNotifications: boolean;
  priceAlerts: boolean;
}

const DEFAULT_SETTINGS: UserSettings = {
  language: 'en',
  currency: 'USD',
  defaultPair: 'BTC-USDT',
  defaultOrderType: 'limit',
  slippage: 0.5,
  biometricEnabled: false,
  pushNotifications: true,
  priceAlerts: false,
};

interface WalletState {
  // Connection
  wallet: ConnectedWallet | null;
  isConnecting: boolean;
  connectError: string | null;
  isVerified: boolean;
  pendingMnemonic: string | null;

  // Modal
  isModalVisible: boolean;
  modalStep: 'choose' | 'backup' | 'import';

  // Chains
  activeChainId: number;
  userChains: ChainConfig[];

  // Settings
  settings: UserSettings;

  // Actions — Wallet
  createNewWallet: () => Promise<void>;
  importWallet: (mnemonic: string) => Promise<boolean>;
  disconnectWallet: () => void;
  signMessage: (message: string) => Promise<string | null>;
  verifyWithBackend: () => Promise<boolean>;
  loadSavedWallet: () => Promise<void>;
  confirmMnemonicBackup: () => void;

  // Actions — Modal
  showModal: () => void;
  hideModal: () => void;
  setModalStep: (step: 'choose' | 'backup' | 'import') => void;
  clearError: () => void;

  // Actions — Chains
  switchChain: (chainId: number) => void;
  addChain: (chain: ChainConfig) => Promise<void>;
  removeChain: (chainId: number) => Promise<void>;

  // Actions — Settings
  updateSettings: (partial: Partial<UserSettings>) => Promise<void>;
  loadSettings: () => Promise<void>;
}

export const useWalletStore = create<WalletState>((set, get) => ({
  wallet: null,
  isConnecting: false,
  connectError: null,
  isVerified: false,
  pendingMnemonic: null,

  isModalVisible: false,
  modalStep: 'choose',

  activeChainId: 4289, // TPIX Chain default
  userChains: [...SUPPORTED_CHAINS],

  settings: { ...DEFAULT_SETTINGS },

  // ========== สร้างกระเป๋าใหม่ด้วย ethers.js ==========
  createNewWallet: async () => {
    set({ isConnecting: true, connectError: null });
    try {
      // รอ UI render loading state ก่อน (ป้องกัน UI ค้าง)
      await waitForUI();

      // Lazy load ethers.js (ครั้งแรกอาจใช้เวลา ~1-2 วิ)
      const { ethers } = await getEthers();

      // สร้าง wallet จริงด้วย ethers (ต้องมี crypto polyfill)
      const wallet = ethers.Wallet.createRandom();
      const mnemonic = wallet.mnemonic?.phrase;
      if (!mnemonic) throw new Error('Failed to generate mnemonic');

      // เก็บ private key + mnemonic ใน SecureStore (native) หรือ AsyncStorage (web)
      await setSecureItem(SECURE_KEY_WALLET, wallet.privateKey);
      await setSecureItem(SECURE_KEY_MNEMONIC, mnemonic);

      const connectedWallet: ConnectedWallet = {
        address: wallet.address,
        shortAddress: shortenAddress(wallet.address),
        providerId: 'tpix-embedded',
        providerName: 'TPIX Wallet',
        chain: 'TPIX',
        chainId: 4289,
        connectedAt: Date.now(),
        isEmbedded: true,
      };

      await AsyncStorage.setItem(STORAGE_KEY_WALLET_STATE, JSON.stringify(connectedWallet));

      playConnectSound();
      set({
        wallet: connectedWallet,
        pendingMnemonic: mnemonic, // แสดงให้ user backup
        modalStep: 'backup',
        isConnecting: false,
        connectError: null,
        activeChainId: 4289,
      });

      // Register กับ backend (non-blocking)
      api.walletConnect({
        wallet_address: wallet.address,
        chain_id: 4289,
        wallet_type: 'tpix_embedded',
      }).then(() => get().verifyWithBackend()).catch(() => {});

    } catch (err) {
      playErrorSound();
      const message = err instanceof Error ? err.message : 'Failed to create wallet';
      set({ connectError: message, isConnecting: false });
    }
  },

  // ========== Import wallet จาก mnemonic ==========
  importWallet: async (mnemonic: string) => {
    set({ isConnecting: true, connectError: null });
    try {
      await waitForUI();
      const { ethers } = await getEthers();

      const trimmed = mnemonic.trim().toLowerCase();

      // Validate mnemonic
      if (!ethers.Mnemonic.isValidMnemonic(trimmed)) {
        set({ connectError: 'Invalid recovery phrase. Please check and try again.', isConnecting: false });
        return false;
      }

      const wallet = ethers.Wallet.fromPhrase(trimmed);

      await setSecureItem(SECURE_KEY_WALLET, wallet.privateKey);
      await setSecureItem(SECURE_KEY_MNEMONIC, trimmed);

      const connectedWallet: ConnectedWallet = {
        address: wallet.address,
        shortAddress: shortenAddress(wallet.address),
        providerId: 'tpix-embedded',
        providerName: 'TPIX Wallet',
        chain: 'TPIX',
        chainId: 4289,
        connectedAt: Date.now(),
        isEmbedded: true,
      };

      await AsyncStorage.setItem(STORAGE_KEY_WALLET_STATE, JSON.stringify(connectedWallet));

      playConnectSound();
      set({
        wallet: connectedWallet,
        pendingMnemonic: null,
        isConnecting: false,
        isModalVisible: false,
        connectError: null,
        activeChainId: 4289,
      });

      // Register + auto-verify (non-blocking)
      api.walletConnect({
        wallet_address: wallet.address,
        chain_id: 4289,
        wallet_type: 'tpix_embedded',
      }).then(() => get().verifyWithBackend()).catch(() => {});

      return true;
    } catch (err) {
      playErrorSound();
      const message = err instanceof Error ? err.message : 'Failed to import wallet';
      set({ connectError: message, isConnecting: false });
      return false;
    }
  },

  // ========== Sign message ด้วย private key ==========
  signMessage: async (message: string) => {
    try {
      const { ethers } = await getEthers();
      const privateKey = await getSecureItem(SECURE_KEY_WALLET);
      if (!privateKey) return null;
      const wallet = new ethers.Wallet(privateKey);
      return await wallet.signMessage(message);
    } catch {
      return null;
    }
  },

  // ========== Verify กับ backend (signature challenge) ==========
  verifyWithBackend: async () => {
    const { wallet, signMessage } = get();
    if (!wallet || !wallet.isEmbedded) {
      set({ isVerified: false });
      return false;
    }

    try {
      // Step 1: ขอ nonce (api service มี timeout 15s ในตัว)
      const signResponse = await api.walletRequestSignature(wallet.address);
      if (!signResponse?.data?.message) return false;

      // Step 2: Sign message ด้วย private key ที่เก็บใน SecureStore
      const signature = await signMessage(signResponse.data.message);
      if (!signature) return false;

      // Step 3: Verify signature กับ backend
      const verifyResponse = await api.walletVerifySignature({
        wallet_address: wallet.address,
        signature,
        nonce: signResponse.data.nonce,
      });

      const verified = verifyResponse?.data?.verified === true;
      set({ isVerified: verified });
      return verified;
    } catch {
      set({ isVerified: false });
      return false;
    }
  },

  // ========== โหลด wallet ที่เก็บไว้ ==========
  loadSavedWallet: async () => {
    try {
      const saved = await AsyncStorage.getItem(STORAGE_KEY_WALLET_STATE);
      if (saved) {
        const walletData: ConnectedWallet = JSON.parse(saved);
        if (walletData.isEmbedded) {
          const hasKey = await getSecureItem(SECURE_KEY_WALLET);
          if (!hasKey) {
            await AsyncStorage.removeItem(STORAGE_KEY_WALLET_STATE);
            return;
          }
        }
        set({ wallet: walletData, activeChainId: walletData.chainId });
      }

      const savedChains = await AsyncStorage.getItem(STORAGE_KEY_CHAINS);
      if (savedChains) {
        set({ userChains: JSON.parse(savedChains) });
      }
    } catch {
      // Silent fail
    }
  },

  // ========== ยืนยันว่า backup mnemonic แล้ว ==========
  confirmMnemonicBackup: () => {
    set({ pendingMnemonic: null, isModalVisible: false, modalStep: 'choose' });
  },

  // ========== Disconnect ==========
  disconnectWallet: () => {
    playDisconnectSound();
    deleteSecureItem(SECURE_KEY_WALLET).catch(() => {});
    deleteSecureItem(SECURE_KEY_MNEMONIC).catch(() => {});
    AsyncStorage.removeItem(STORAGE_KEY_WALLET_STATE).catch(() => {});
    api.clearToken();
    set({
      wallet: null,
      isVerified: false,
      connectError: null,
      pendingMnemonic: null,
    });
  },

  // ========== Modal ==========
  showModal: () => set({ isModalVisible: true, modalStep: 'choose', connectError: null }),
  hideModal: () => set({ isModalVisible: false, connectError: null, isConnecting: false }),
  setModalStep: (step) => set({ modalStep: step, connectError: null }),
  clearError: () => set({ connectError: null }),

  // ========== Chain Management ==========
  switchChain: (chainId: number) => {
    const { userChains, wallet } = get();
    const chain = userChains.find(c => c.chainId === chainId);
    if (!chain) return;

    set({ activeChainId: chainId });

    if (wallet) {
      const updated = { ...wallet, chain: chain.symbol, chainId };
      set({ wallet: updated });
      AsyncStorage.setItem(STORAGE_KEY_WALLET_STATE, JSON.stringify(updated)).catch(() => {});
    }
  },

  addChain: async (chain: ChainConfig) => {
    const { userChains } = get();
    if (userChains.find(c => c.chainId === chain.chainId)) return;
    const updated = [...userChains, chain];
    set({ userChains: updated });
    await AsyncStorage.setItem(STORAGE_KEY_CHAINS, JSON.stringify(updated));
  },

  removeChain: async (chainId: number) => {
    if (chainId === 4289) return; // ห้ามลบ TPIX Chain
    const { userChains, activeChainId } = get();
    const updated = userChains.filter(c => c.chainId !== chainId);
    set({ userChains: updated });
    await AsyncStorage.setItem(STORAGE_KEY_CHAINS, JSON.stringify(updated));
    if (activeChainId === chainId) {
      get().switchChain(4289);
    }
  },

  // ========== Settings ==========
  updateSettings: async (partial: Partial<UserSettings>) => {
    const current = get().settings;
    const updated = { ...current, ...partial };
    set({ settings: updated });
    await AsyncStorage.setItem(STORAGE_KEY_SETTINGS, JSON.stringify(updated));
  },

  loadSettings: async () => {
    try {
      const saved = await AsyncStorage.getItem(STORAGE_KEY_SETTINGS);
      if (saved) {
        set({ settings: { ...DEFAULT_SETTINGS, ...JSON.parse(saved) } });
      }
    } catch {
      // ใช้ default
    }
  },
}));

// ========== Deep link callback handler ==========
// รองรับ callback จาก TPIX Wallet app (เก็บไว้สำหรับอนาคต)
export async function handleWalletCallback(url: string): Promise<void> {
  try {
    const parsed = new URL(url);
    const path = parsed.pathname || parsed.hostname;

    if (path === 'wallet/connect' || path === '/wallet/connect') {
      const address = parsed.searchParams.get('address');
      const chain = parsed.searchParams.get('chain') || 'TPIX';
      const chainId = parseInt(parsed.searchParams.get('chainId') || '4289', 10);
      const provider = parsed.searchParams.get('provider') || 'external';

      // Validate address format (basic hex check แทน ethers.isAddress เพื่อไม่ต้อง lazy load)
      const isValidAddr = address && /^0x[0-9a-fA-F]{40}$/.test(address);
      if (isValidAddr) {
        const connectedWallet: ConnectedWallet = {
          address,
          shortAddress: shortenAddress(address),
          providerId: provider,
          providerName: provider,
          chain,
          chainId,
          connectedAt: Date.now(),
          isEmbedded: false,
        };

        await AsyncStorage.setItem(STORAGE_KEY_WALLET_STATE, JSON.stringify(connectedWallet));

        playConnectSound();
        useWalletStore.setState({
          wallet: connectedWallet,
          isConnecting: false,
          isModalVisible: false,
          connectError: null,
          activeChainId: chainId,
        });

        api.walletConnect({
          wallet_address: address,
          chain_id: chainId,
          wallet_type: provider,
        }).catch(() => {});
      }
    }
  } catch {
    // Invalid URL
  }
}
