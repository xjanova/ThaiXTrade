/**
 * Wallet Store v2 — กระเป๋าจริง + Chain Management
 *
 * สร้าง wallet ด้วย ethers.js → เก็บ key ใน SecureStore
 * รองรับ: สร้างใหม่, import mnemonic, external wallet (deep link)
 * มี chain management: เพิ่ม/สลับ chain, TPIX Chain default
 *
 * Developed by Xman Studio
 */

import { create } from 'zustand';
import * as SecureStore from 'expo-secure-store';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { ethers } from 'ethers';
import {
  WalletProvider,
  WalletDetectionResult,
  detectInstalledWallets,
  openWalletApp,
  shortenAddress,
  SUPPORTED_CHAINS,
  type ChainConfig,
} from '@/services/walletService';
import api from '@/services/api';
import { playConnectSound, playDisconnectSound, playErrorSound } from '@/utils/sounds';

// ========== Constants ==========
const SECURE_KEY_WALLET = 'tpix_wallet_key';
const SECURE_KEY_MNEMONIC = 'tpix_wallet_mnemonic';
const STORAGE_KEY_WALLET_STATE = 'tpix_wallet_state';
const STORAGE_KEY_CHAINS = 'tpix_user_chains';
const STORAGE_KEY_SETTINGS = 'tpix_user_settings';
const CONNECT_TIMEOUT_MS = 10_000;

let connectTimeoutId: ReturnType<typeof setTimeout> | null = null;

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

  // Mnemonic (ชั่วคราว — แสดงให้ user backup แล้วลบ)
  pendingMnemonic: string | null;

  // Detection
  detectedWallets: WalletDetectionResult[];
  isDetecting: boolean;
  lastDetectedAt: number | null;

  // Modal
  isModalVisible: boolean;
  modalStep: 'choose' | 'create' | 'import' | 'backup';

  // Chains
  activeChainId: number;
  userChains: ChainConfig[];

  // Settings
  settings: UserSettings;

  // Actions — Wallet
  createNewWallet: () => Promise<void>;
  importWallet: (mnemonic: string) => Promise<boolean>;
  connectExternalWallet: (provider: WalletProvider) => Promise<boolean>;
  disconnectWallet: () => void;
  signMessage: (message: string) => Promise<string | null>;
  verifyWithBackend: () => Promise<boolean>;
  loadSavedWallet: () => Promise<void>;
  confirmMnemonicBackup: () => void;

  // Actions — Detection
  detectWallets: () => Promise<void>;

  // Actions — Modal
  showModal: () => void;
  hideModal: () => void;
  setModalStep: (step: 'choose' | 'create' | 'import' | 'backup') => void;
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

  detectedWallets: [],
  isDetecting: false,
  lastDetectedAt: null,

  isModalVisible: false,
  modalStep: 'choose',

  activeChainId: 4289, // TPIX Chain default
  userChains: [...SUPPORTED_CHAINS],

  settings: { ...DEFAULT_SETTINGS },

  // ========== สร้างกระเป๋าใหม่ด้วย ethers.js ==========
  createNewWallet: async () => {
    set({ isConnecting: true, connectError: null });
    try {
      // สร้าง wallet จริงด้วย ethers
      const wallet = ethers.Wallet.createRandom();
      const mnemonic = wallet.mnemonic?.phrase;
      if (!mnemonic) throw new Error('Failed to generate mnemonic');

      // เก็บ private key + mnemonic ใน SecureStore (encrypted by OS)
      await SecureStore.setItemAsync(SECURE_KEY_WALLET, wallet.privateKey);
      await SecureStore.setItemAsync(SECURE_KEY_MNEMONIC, mnemonic);

      const address = wallet.address;
      const connectedWallet: ConnectedWallet = {
        address,
        shortAddress: shortenAddress(address),
        providerId: 'tpix-embedded',
        providerName: 'TPIX Wallet',
        chain: 'TPIX',
        chainId: 4289,
        connectedAt: Date.now(),
        isEmbedded: true,
      };

      // Save state
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

      // Register กับ backend (fire-and-forget)
      api.walletConnect({
        wallet_address: address,
        chain_id: 4289,
        wallet_type: 'tpix_embedded',
      }).catch(() => {});

    } catch (err) {
      playErrorSound();
      set({
        connectError: err instanceof Error ? err.message : 'Failed to create wallet',
        isConnecting: false,
      });
    }
  },

  // ========== Import wallet จาก mnemonic ==========
  importWallet: async (mnemonic: string) => {
    set({ isConnecting: true, connectError: null });
    try {
      const trimmed = mnemonic.trim().toLowerCase();

      // Validate mnemonic
      if (!ethers.Mnemonic.isValidMnemonic(trimmed)) {
        set({ connectError: 'Invalid mnemonic phrase. Please check and try again.', isConnecting: false });
        return false;
      }

      const wallet = ethers.Wallet.fromPhrase(trimmed);

      // เก็บ key
      await SecureStore.setItemAsync(SECURE_KEY_WALLET, wallet.privateKey);
      await SecureStore.setItemAsync(SECURE_KEY_MNEMONIC, trimmed);

      const address = wallet.address;
      const connectedWallet: ConnectedWallet = {
        address,
        shortAddress: shortenAddress(address),
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

      api.walletConnect({
        wallet_address: address,
        chain_id: 4289,
        wallet_type: 'tpix_embedded',
      }).catch(() => {});

      return true;
    } catch (err) {
      playErrorSound();
      set({
        connectError: err instanceof Error ? err.message : 'Failed to import wallet',
        isConnecting: false,
      });
      return false;
    }
  },

  // ========== เชื่อมต่อ external wallet (deep link) ==========
  connectExternalWallet: async (provider: WalletProvider) => {
    set({ isConnecting: true, connectError: null });
    try {
      const opened = await openWalletApp(provider);
      if (!opened) {
        playErrorSound();
        set({
          connectError: `${provider.name} is not installed. Please install it or create a new wallet.`,
          isConnecting: false,
        });
        return false;
      }

      // ตั้ง timeout ป้องกันค้าง
      if (connectTimeoutId) clearTimeout(connectTimeoutId);
      connectTimeoutId = setTimeout(() => {
        connectTimeoutId = null;
        const state = get();
        if (state.isConnecting) {
          set({
            isConnecting: false,
            connectError: 'Connection timed out. Please try again.',
          });
        }
      }, CONNECT_TIMEOUT_MS);

      return true;
    } catch (err) {
      playErrorSound();
      set({
        connectError: err instanceof Error ? err.message : 'Connection failed',
        isConnecting: false,
      });
      return false;
    }
  },

  // ========== Sign message ด้วย private key ==========
  signMessage: async (message: string) => {
    try {
      const privateKey = await SecureStore.getItemAsync(SECURE_KEY_WALLET);
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
      // External wallet — auto verified by backend
      set({ isVerified: true });
      return true;
    }

    try {
      // Step 1: ขอ nonce
      const signResponse = await api.walletRequestSignature(wallet.address);
      if (!signResponse?.data?.message) return false;

      // Step 2: Sign message
      const signature = await signMessage(signResponse.data.message);
      if (!signature) return false;

      // Step 3: Verify
      const verifyResponse = await api.walletVerifySignature({
        wallet_address: wallet.address,
        signature,
        nonce: signResponse.data.nonce,
      });

      const verified = verifyResponse?.data?.verified === true;
      set({ isVerified: verified });
      return verified;
    } catch {
      return false;
    }
  },

  // ========== โหลด wallet ที่เก็บไว้ ==========
  loadSavedWallet: async () => {
    try {
      const saved = await AsyncStorage.getItem(STORAGE_KEY_WALLET_STATE);
      if (saved) {
        const walletData: ConnectedWallet = JSON.parse(saved);
        // ตรวจว่ายังมี key อยู่ไหม (embedded wallet)
        if (walletData.isEmbedded) {
          const hasKey = await SecureStore.getItemAsync(SECURE_KEY_WALLET);
          if (!hasKey) {
            await AsyncStorage.removeItem(STORAGE_KEY_WALLET_STATE);
            return;
          }
        }
        set({ wallet: walletData, activeChainId: walletData.chainId });
      }

      // โหลด chains ที่ user เพิ่มไว้
      const savedChains = await AsyncStorage.getItem(STORAGE_KEY_CHAINS);
      if (savedChains) {
        set({ userChains: JSON.parse(savedChains) });
      }
    } catch {
      // Silent fail — เริ่มใหม่
    }
  },

  // ========== ยืนยันว่า backup mnemonic แล้ว ==========
  confirmMnemonicBackup: () => {
    set({ pendingMnemonic: null, isModalVisible: false, modalStep: 'choose' });
  },

  // ========== Disconnect ==========
  disconnectWallet: () => {
    playDisconnectSound();
    SecureStore.deleteItemAsync(SECURE_KEY_WALLET).catch(() => {});
    SecureStore.deleteItemAsync(SECURE_KEY_MNEMONIC).catch(() => {});
    AsyncStorage.removeItem(STORAGE_KEY_WALLET_STATE).catch(() => {});
    api.clearToken();
    set({
      wallet: null,
      isVerified: false,
      connectError: null,
      pendingMnemonic: null,
    });
  },

  // ========== Detection ==========
  detectWallets: async () => {
    const now = Date.now();
    const { lastDetectedAt } = get();
    if (lastDetectedAt && now - lastDetectedAt < 10_000) return;

    set({ isDetecting: true });
    try {
      const results = await detectInstalledWallets();
      set({ detectedWallets: results, lastDetectedAt: now });
    } catch {
      // Silent
    } finally {
      set({ isDetecting: false });
    }
  },

  // ========== Modal ==========
  showModal: () => {
    set({ isModalVisible: true, modalStep: 'choose', connectError: null });
    get().detectWallets();
  },
  hideModal: () => {
    if (connectTimeoutId) { clearTimeout(connectTimeoutId); connectTimeoutId = null; }
    set({ isModalVisible: false, connectError: null, isConnecting: false });
  },
  setModalStep: (step) => set({ modalStep: step, connectError: null }),
  clearError: () => set({ connectError: null }),

  // ========== Chain Management ==========
  switchChain: (chainId: number) => {
    const { userChains, wallet } = get();
    const chain = userChains.find(c => c.chainId === chainId);
    if (!chain) return;

    set({ activeChainId: chainId });

    // อัปเดต wallet state ด้วย
    if (wallet) {
      const updated = { ...wallet, chain: chain.symbol, chainId };
      set({ wallet: updated });
      AsyncStorage.setItem(STORAGE_KEY_WALLET_STATE, JSON.stringify(updated)).catch(() => {});
    }
  },

  addChain: async (chain: ChainConfig) => {
    const { userChains } = get();
    if (userChains.find(c => c.chainId === chain.chainId)) return; // มีอยู่แล้ว
    const updated = [...userChains, chain];
    set({ userChains: updated });
    await AsyncStorage.setItem(STORAGE_KEY_CHAINS, JSON.stringify(updated));
  },

  removeChain: async (chainId: number) => {
    // ห้ามลบ TPIX Chain
    if (chainId === 4289) return;
    const { userChains, activeChainId } = get();
    const updated = userChains.filter(c => c.chainId !== chainId);
    set({ userChains: updated });
    await AsyncStorage.setItem(STORAGE_KEY_CHAINS, JSON.stringify(updated));
    // ถ้าลบ chain ที่กำลังใช้ → สลับไป TPIX
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
export async function handleWalletCallback(url: string): Promise<void> {
  try {
    const parsed = new URL(url);
    const path = parsed.pathname || parsed.hostname;

    if (path === 'wallet/connect' || path === '/wallet/connect') {
      const address = parsed.searchParams.get('address');
      const chain = parsed.searchParams.get('chain') || 'ETH';
      const chainId = parseInt(parsed.searchParams.get('chainId') || '56', 10);
      const provider = parsed.searchParams.get('provider') || 'external';

      if (address && ethers.isAddress(address)) {
        if (connectTimeoutId) { clearTimeout(connectTimeoutId); connectTimeoutId = null; }

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
