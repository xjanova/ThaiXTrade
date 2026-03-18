/**
 * TPIX TRADE - Token Sale Store Tests
 * ทดสอบ Pinia store สำหรับระบบขายเหรียญ TPIX
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useTokenSaleStore } from '@/Stores/tokenSaleStore';

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

import axios from 'axios';

describe('TokenSaleStore', () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useTokenSaleStore();
        vi.clearAllMocks();
    });

    // =========================================================================
    // Initial State — ค่าเริ่มต้น
    // =========================================================================

    describe('initial state', () => {
        it('has null sale', () => {
            expect(store.sale).toBeNull();
        });

        it('has null stats', () => {
            expect(store.stats).toBeNull();
        });

        it('has empty purchases', () => {
            expect(store.purchases).toEqual([]);
        });

        it('has false loading states', () => {
            expect(store.isLoadingSale).toBe(false);
            expect(store.isLoadingStats).toBe(false);
            expect(store.isPurchasing).toBe(false);
        });

        it('has null error', () => {
            expect(store.error).toBeNull();
        });
    });

    // =========================================================================
    // Computed — ค่าคำนวณ
    // =========================================================================

    describe('computed properties', () => {
        it('activePhase returns null when no sale', () => {
            expect(store.activePhase).toBeNull();
        });

        it('activePhase finds active phase from sale', () => {
            store.sale = {
                phases: [
                    { id: 1, name: 'Private', status: 'ended' },
                    { id: 2, name: 'Public', status: 'active' },
                ],
            };
            expect(store.activePhase.id).toBe(2);
            expect(store.activePhase.name).toBe('Public');
        });

        it('phases returns empty array when no sale', () => {
            expect(store.phases).toEqual([]);
        });

        it('percentSold returns 0 when no sale', () => {
            expect(store.percentSold).toBe(0);
        });

        it('percentSold returns sale percent_sold', () => {
            store.sale = { percent_sold: 45.5 };
            expect(store.percentSold).toBe(45.5);
        });

        it('totalRemaining calculates correctly', () => {
            store.sale = { total_supply: 1000, total_sold: 300 };
            expect(store.totalRemaining).toBe(700);
        });

        it('totalPurchased sums purchases', () => {
            store.purchases = [
                { tpix_amount: 1000 },
                { tpix_amount: 2000 },
                { tpix_amount: 500 },
            ];
            expect(store.totalPurchased).toBe(3500);
        });

        it('endsAt returns Date when sale has ends_at', () => {
            store.sale = { ends_at: '2026-12-31T23:59:59Z' };
            expect(store.endsAt).toBeInstanceOf(Date);
        });

        it('endsAt returns null when no sale', () => {
            expect(store.endsAt).toBeNull();
        });
    });

    // =========================================================================
    // Actions — โหลดข้อมูล
    // =========================================================================

    describe('fetchSale', () => {
        it('loads sale data from API', async () => {
            const mockSale = {
                id: 1,
                name: 'TPIX Token Sale',
                status: 'active',
                phases: [{ id: 1, name: 'Public', status: 'active' }],
            };

            axios.get.mockResolvedValueOnce({
                data: { success: true, data: mockSale },
            });

            await store.fetchSale();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/token-sale');
            expect(store.sale).toEqual(mockSale);
            expect(store.isLoadingSale).toBe(false);
        });

        it('sets error on API failure', async () => {
            axios.get.mockRejectedValueOnce(new Error('Network Error'));

            await store.fetchSale();

            expect(store.error).toBe('ไม่สามารถโหลดข้อมูลรอบขายได้');
            expect(store.isLoadingSale).toBe(false);
        });
    });

    describe('fetchStats', () => {
        it('loads stats from API', async () => {
            const mockStats = {
                total_supply: 700000000,
                total_sold: 50000,
                total_raised_usd: 5000,
                buyers_count: 10,
            };

            axios.get.mockResolvedValueOnce({
                data: { success: true, data: mockStats },
            });

            await store.fetchStats();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/token-sale/stats');
            expect(store.stats).toEqual(mockStats);
        });
    });

    describe('fetchPurchases', () => {
        it('loads purchases for wallet', async () => {
            const wallet = '0xabcdef0123456789abcdef0123456789abcdef01';
            const mockPurchases = [
                { id: 'uuid-1', tpix_amount: 1000, status: 'confirmed' },
            ];

            axios.get.mockResolvedValueOnce({
                data: { success: true, data: mockPurchases },
            });

            await store.fetchPurchases(wallet);

            expect(axios.get).toHaveBeenCalledWith(`/api/v1/token-sale/purchases/${wallet}`);
            expect(store.purchases).toEqual(mockPurchases);
        });

        it('skips if no wallet address', async () => {
            await store.fetchPurchases(null);
            expect(axios.get).not.toHaveBeenCalled();
        });
    });

    describe('getPreview', () => {
        it('returns preview data', async () => {
            const mockPreview = {
                tpix_amount: 1000,
                payment_usd_value: 100,
            };

            axios.post.mockResolvedValueOnce({
                data: { success: true, data: mockPreview },
            });

            const result = await store.getPreview(1, 'USDT', 100);

            expect(axios.post).toHaveBeenCalledWith('/api/v1/token-sale/preview', {
                phase_id: 1,
                currency: 'USDT',
                amount: 100,
            });
            expect(result).toEqual(mockPreview);
        });

        it('returns null on failure', async () => {
            axios.post.mockRejectedValueOnce(new Error('Error'));

            const result = await store.getPreview(1, 'USDT', 100);
            expect(result).toBeNull();
        });
    });

    // =========================================================================
    // Reset — รีเซ็ต
    // =========================================================================

    describe('reset', () => {
        it('clears all state', () => {
            store.sale = { id: 1 };
            store.stats = { total_sold: 100 };
            store.purchases = [{ id: 1 }];
            store.error = 'some error';

            store.reset();

            expect(store.sale).toBeNull();
            expect(store.stats).toBeNull();
            expect(store.purchases).toEqual([]);
            expect(store.error).toBeNull();
        });
    });
});
