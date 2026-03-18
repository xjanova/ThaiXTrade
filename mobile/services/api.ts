const API_BASE = 'https://tpixtrade.com/api/v1';

interface ApiResponse<T> {
  success: boolean;
  data: T;
  meta?: {
    page: number;
    per_page: number;
    total: number;
  };
}

interface ApiError {
  success: false;
  error: {
    code: string;
    message: string;
  };
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

  private async request<T>(
    endpoint: string,
    options: RequestInit = {}
  ): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...((options.headers as Record<string, string>) || {}),
    };

    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }

    const response = await fetch(`${this.baseUrl}${endpoint}`, {
      ...options,
      headers,
    });

    if (!response.ok) {
      const error: ApiError = await response.json().catch(() => ({
        success: false,
        error: { code: 'NETWORK_ERROR', message: 'Network request failed' },
      }));
      throw new Error(error.error?.message || `HTTP ${response.status}`);
    }

    return response.json();
  }

  // Chains
  async getChains() {
    return this.request<ApiResponse<any[]>>('/chains');
  }

  async getChain(id: number) {
    return this.request<ApiResponse<any>>(`/chains/${id}`);
  }

  // Trading pairs
  async getPairs() {
    return this.request<ApiResponse<any[]>>('/pairs');
  }

  async getPair(symbol: string) {
    return this.request<ApiResponse<any>>(`/pairs/${symbol}`);
  }

  // Orders
  async createOrder(data: {
    pair: string;
    side: 'buy' | 'sell';
    type: 'limit' | 'market';
    price?: number;
    amount: number;
  }) {
    return this.request<ApiResponse<any>>('/orders', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  }

  async getOrders() {
    return this.request<ApiResponse<any[]>>('/orders');
  }

  async cancelOrder(id: string) {
    return this.request<ApiResponse<any>>(`/orders/${id}`, {
      method: 'DELETE',
    });
  }
}

export const api = new ApiService();
export default api;
