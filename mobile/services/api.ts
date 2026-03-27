/**
 * API Service for TPIX TRADE
 * บริการ API สำหรับ TPIX TRADE
 *
 * Handles all HTTP communication with the backend.
 * จัดการการสื่อสาร HTTP ทั้งหมดกับเซิร์ฟเวอร์
 */

import Constants from 'expo-constants';

// Use environment config, fallback to default
// ใช้ค่าจาก environment config, ถ้าไม่มีจะใช้ค่าเริ่มต้น
const API_BASE =
  Constants.expoConfig?.extra?.apiBaseUrl ??
  (process.env.EXPO_PUBLIC_API_BASE_URL || 'https://tpixtrade.com/api/v1');

// Request timeout in milliseconds / ระยะเวลา timeout ของ request (มิลลิวินาที)
const REQUEST_TIMEOUT_MS = 15_000;

// --- Response Types / ประเภทข้อมูลที่ตอบกลับ ---

export interface Chain {
  id: number;
  name: string;
  rpcUrl: string;
  explorerUrl: string;
  nativeCurrency: {
    name: string;
    symbol: string;
    decimals: number;
  };
}

export interface TradingPair {
  symbol: string;
  name: string;
  baseAsset: string;
  quoteAsset: string;
  price: number;
  change24h: number;
  high24h: number;
  low24h: number;
  volume24h: string;
}

export interface Order {
  id: string;
  pair: string;
  side: 'buy' | 'sell';
  type: 'limit' | 'market';
  price: number | null;
  amount: number;
  total: number;
  status: 'open' | 'filled' | 'cancelled';
  createdAt: string;
}

export interface ApiResponse<T> {
  success: boolean;
  data: T;
  meta?: {
    page: number;
    per_page: number;
    total: number;
  };
}

export interface ApiError {
  success: false;
  error: {
    code: string;
    message: string;
  };
}

/**
 * Creates a fetch request with timeout support
 * สร้าง fetch request พร้อมรองรับ timeout
 */
function fetchWithTimeout(
  url: string,
  options: RequestInit,
  timeoutMs: number,
): Promise<Response> {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  return fetch(url, {
    ...options,
    signal: controller.signal,
  }).finally(() => clearTimeout(timeoutId));
}

class ApiService {
  private baseUrl: string;
  private token: string | null = null;

  constructor(baseUrl: string = API_BASE) {
    this.baseUrl = baseUrl;
  }

  setToken(token: string) {
    this.token = token;
  }

  clearToken() {
    this.token = null;
  }

  async request<T>(
    endpoint: string,
    options: RequestInit = {},
  ): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...((options.headers as Record<string, string>) || {}),
    };

    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }

    // Wrap fetch with try/catch for network errors
    // ครอบ fetch ด้วย try/catch สำหรับ error ของเครือข่าย
    let response: Response;
    try {
      response = await fetchWithTimeout(
        `${this.baseUrl}${endpoint}`,
        { ...options, headers },
        REQUEST_TIMEOUT_MS,
      );
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        throw new Error('Request timeout / การเชื่อมต่อหมดเวลา');
      }
      throw new Error('Network error - please check your connection / ข้อผิดพลาดเครือข่าย กรุณาตรวจสอบการเชื่อมต่อ');
    }

    if (!response.ok) {
      const error: ApiError = await response.json().catch(() => ({
        success: false as const,
        error: { code: 'NETWORK_ERROR', message: 'Network request failed / การร้องขอล้มเหลว' },
      }));
      throw new Error(error.error?.message || `HTTP ${response.status}`);
    }

    return response.json();
  }

  // Chains / เชน
  async getChains() {
    return this.request<ApiResponse<Chain[]>>('/chains');
  }

  async getChain(id: number) {
    return this.request<ApiResponse<Chain>>(`/chains/${id}`);
  }

  // Trading pairs / คู่เทรด
  async getPairs() {
    return this.request<ApiResponse<TradingPair[]>>('/pairs');
  }

  async getPair(symbol: string) {
    return this.request<ApiResponse<TradingPair>>(`/pairs/${encodeURIComponent(symbol)}`);
  }

  // Wallet verification / การยืนยันตัวตนกระเป๋า
  // ต้อง verify ก่อนจึงจะส่ง POST (createOrder, swap) ได้
  async walletConnect(data: { wallet_address: string; chain_id: number; wallet_type?: string }) {
    return this.request<ApiResponse<{ wallet_address: string; user_id: number; is_new: boolean }>>('/wallet/connect', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async walletRequestSignature(walletAddress: string) {
    return this.request<ApiResponse<{ message: string; nonce: string }>>('/wallet/sign', {
      method: 'POST',
      body: JSON.stringify({ wallet_address: walletAddress }),
    });
  }

  async walletVerifySignature(data: { wallet_address: string; signature: string; nonce: string }) {
    return this.request<ApiResponse<{ wallet_address: string; verified: boolean }>>('/wallet/verify-signature', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  // Fee info / ข้อมูลค่าธรรมเนียม
  async getFeeInfo(chainId: number = 56) {
    return this.request<ApiResponse<{ fee_rate: number; fee_collector: string; max_fee_rate: number }>>(
      `/trading/fee-info?chain_id=${chainId}&wallet_address=`,
    );
  }

  // Orders / คำสั่งซื้อขาย (ใช้ route /trading/order ตาม backend)
  async createOrder(data: {
    pair: string;
    side: 'buy' | 'sell';
    type: 'limit' | 'market';
    price?: number;
    amount: number;
    wallet_address: string;
    chain_id: number;
    total?: number;
    trigger_price?: number;
  }) {
    return this.request<ApiResponse<Order>>('/trading/order', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async getOrders(walletAddress: string) {
    return this.request<ApiResponse<Order[]>>(`/trading/orders?wallet_address=${encodeURIComponent(walletAddress)}`);
  }

  async cancelOrder(id: string, walletAddress: string) {
    return this.request<ApiResponse<Order>>(`/trading/order/${encodeURIComponent(id)}`, {
      method: 'DELETE',
      body: JSON.stringify({ wallet_address: walletAddress }),
    });
  }

  async getTradeHistory(walletAddress: string) {
    return this.request<ApiResponse<any[]>>(`/trading/history?wallet_address=${encodeURIComponent(walletAddress)}`);
  }
}

export const api = new ApiService();
export default api;
