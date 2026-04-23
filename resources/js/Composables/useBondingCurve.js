/**
 * useBondingCurve — TPIX Launch (on-chain bonding curve)
 *
 * รับผิดชอบ:
 *   - อ่าน state ของ curve จาก TPIX RPC (currentPrice, progress, migration)
 *   - quoteBuy/quoteSell preview
 *   - Buy flow: approve USDT → call curve.buy() พร้อม slippage protection
 *   - Sell flow: approve WTPIX → call curve.sell()
 *
 * UX scenarios covered (CLAUDE.md §A):
 *   - New user (empty state): state.loaded flag
 *   - Network timeout: try/catch + error state
 *   - Wrong chain: requires walletStore.chainId === 4289 ก่อน buy
 *   - Rapid taps: isBuying / isSelling flags block
 *   - setState after await: ใช้ .value check before updating
 *
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted } from 'vue';
import { BrowserProvider, JsonRpcProvider, Contract, parseUnits, formatUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import {
    LAUNCH_CONTRACTS,
    TPIX_CHAIN_ID,
    BONDING_CURVE_ABI,
    ERC20_ABI,
    launchContractsDeployed,
} from '@/Config/launchContracts';
import { TPIX_CHAIN_CONFIG } from '@/utils/web3';

const USDT_DECIMALS = 6;
const TPIX_DECIMALS = 18;
const DEFAULT_SLIPPAGE_BPS = 200; // 2%
const BPS = 10_000n;

export function useBondingCurve() {
    const walletStore = useWalletStore();

    // =========================================================================
    // State
    // =========================================================================

    const loaded = ref(false);
    const loadError = ref(null);
    const refreshing = ref(false);

    // Read-only state จาก contract
    const state = ref({
        currentPrice: 0n,        // USDT (6 dec) ต่อ 1 TPIX
        totalSold: 0n,           // TPIX (18 dec)
        totalRaised: 0n,         // USDT (6 dec)
        saleSupply: 0n,
        startPrice: 0n,
        endPrice: 0n,
        migrationUsdtThreshold: 0n,
        migrationTpixThreshold: 0n,
        migrated: false,
        isMigrationReady: false,
        paused: false,
    });

    // Form input
    const usdtAmount = ref('');
    const slippageBps = ref(DEFAULT_SLIPPAGE_BPS);

    // Preview (async quote)
    const quote = ref({ tpixOut: 0n, effectivePrice: 0n });
    const isQuoting = ref(false);

    // Tx state
    const isApproving = ref(false);
    const isBuying = ref(false);
    const isSelling = ref(false);
    const txHash = ref(null);
    const error = ref(null);

    // User-specific
    const usdtBalance = ref(0n);
    const wtpixBalance = ref(0n);
    const usdtAllowance = ref(0n);
    const userBought = ref(0n);

    let pollInterval = null;

    // =========================================================================
    // Computed
    // =========================================================================

    const readyToUse = computed(() => launchContractsDeployed());

    const saleActive = computed(() =>
        readyToUse.value && !state.value.migrated && !state.value.paused
    );

    const progressUsdt = computed(() => {
        const threshold = state.value.migrationUsdtThreshold;
        if (threshold === 0n) return 0;
        const pct = Number((state.value.totalRaised * 10000n) / threshold) / 100;
        return Math.min(pct, 100);
    });

    const progressTpix = computed(() => {
        const threshold = state.value.migrationTpixThreshold;
        if (threshold === 0n) return 0;
        const pct = Number((state.value.totalSold * 10000n) / threshold) / 100;
        return Math.min(pct, 100);
    });

    /** ใช้ค่า max ของสอง progress เพื่อแสดงว่าใกล้ migration แค่ไหน */
    const progressOverall = computed(() =>
        Math.max(progressUsdt.value, progressTpix.value)
    );

    const currentPriceFormatted = computed(() => {
        if (state.value.currentPrice === 0n) return '0.00';
        return parseFloat(formatUnits(state.value.currentPrice, USDT_DECIMALS)).toFixed(4);
    });

    const needsApproval = computed(() => {
        if (!usdtAmount.value || parseFloat(usdtAmount.value) <= 0) return false;
        try {
            const needed = parseUnits(usdtAmount.value, USDT_DECIMALS);
            return usdtAllowance.value < needed;
        } catch {
            return false;
        }
    });

    const canBuy = computed(() => {
        if (!walletStore.isConnected) return false;
        if (walletStore.chainId !== TPIX_CHAIN_ID) return false;
        if (!saleActive.value) return false;
        if (isBuying.value || isApproving.value) return false;
        if (!usdtAmount.value || parseFloat(usdtAmount.value) <= 0) return false;
        try {
            const needed = parseUnits(usdtAmount.value, USDT_DECIMALS);
            if (usdtBalance.value < needed) return false;
        } catch {
            return false;
        }
        return true;
    });

    // =========================================================================
    // Read helpers
    // =========================================================================

    function getReadProvider() {
        // ใช้ RPC โดยตรง (ไม่ต้องผ่าน wallet) — faster + ไม่ rate-limit
        return new JsonRpcProvider(TPIX_CHAIN_CONFIG.rpcUrls[0]);
    }

    function getCurveReader() {
        return new Contract(LAUNCH_CONTRACTS.BONDING_CURVE, BONDING_CURVE_ABI, getReadProvider());
    }

    function getUsdtReader() {
        return new Contract(LAUNCH_CONTRACTS.USDT, ERC20_ABI, getReadProvider());
    }

    function getWtpixReader() {
        return new Contract(LAUNCH_CONTRACTS.WTPIX, ERC20_ABI, getReadProvider());
    }

    // =========================================================================
    // Actions
    // =========================================================================

    async function refreshState() {
        if (!readyToUse.value) {
            loaded.value = true;
            return;
        }
        refreshing.value = true;
        loadError.value = null;
        try {
            const curve = getCurveReader();
            const [
                currentPrice,
                totalSold,
                totalRaised,
                saleSupply,
                startPrice,
                endPrice,
                migrationUsdtThreshold,
                migrationTpixThreshold,
                migrated,
                isMigrationReady,
                paused,
            ] = await Promise.all([
                curve.currentPrice(),
                curve.totalSold(),
                curve.totalRaised(),
                curve.saleSupply(),
                curve.startPrice(),
                curve.endPrice(),
                curve.migrationUsdtThreshold(),
                curve.migrationTpixThreshold(),
                curve.migrated(),
                curve.isMigrationReady(),
                curve.paused(),
            ]);

            state.value = {
                currentPrice,
                totalSold,
                totalRaised,
                saleSupply,
                startPrice,
                endPrice,
                migrationUsdtThreshold,
                migrationTpixThreshold,
                migrated,
                isMigrationReady,
                paused,
            };
            loaded.value = true;
        } catch (err) {
            loadError.value = err.message || 'ไม่สามารถโหลดข้อมูล bonding curve';
        } finally {
            refreshing.value = false;
        }
    }

    async function refreshUserBalances() {
        if (!readyToUse.value || !walletStore.address) return;
        try {
            const [usdt, wtpix, allowance, bought] = await Promise.all([
                getUsdtReader().balanceOf(walletStore.address),
                getWtpixReader().balanceOf(walletStore.address),
                getUsdtReader().allowance(walletStore.address, LAUNCH_CONTRACTS.BONDING_CURVE),
                getCurveReader().bought(walletStore.address),
            ]);
            usdtBalance.value = usdt;
            wtpixBalance.value = wtpix;
            usdtAllowance.value = allowance;
            userBought.value = bought;
        } catch (err) {
            // soft-fail — ไม่ block UI
            console.warn('[useBondingCurve] refreshUserBalances', err);
        }
    }

    /**
     * Calculate quote (TPIX out เมื่อจ่าย usdtAmount)
     * ใช้ contract's quoteBuy สำหรับความแม่นยำ (ราคาเฉลี่ย)
     */
    async function refreshQuote() {
        if (!usdtAmount.value || parseFloat(usdtAmount.value) <= 0 || !saleActive.value) {
            quote.value = { tpixOut: 0n, effectivePrice: 0n };
            return;
        }
        isQuoting.value = true;
        try {
            const usdtIn = parseUnits(usdtAmount.value, USDT_DECIMALS);
            const tpixOut = await getCurveReader().quoteBuy(usdtIn);
            // effective price = usdtIn (scaled to 1e18) / tpixOut
            // แต่ contract ใช้ 6-dec USDT ต่อ 18-dec TPIX
            // effectivePrice ใน 6-dec USDT = usdtIn × 1e18 / tpixOut
            const effectivePrice = tpixOut > 0n
                ? (usdtIn * (10n ** BigInt(TPIX_DECIMALS))) / tpixOut
                : 0n;
            quote.value = { tpixOut, effectivePrice };
        } catch (err) {
            quote.value = { tpixOut: 0n, effectivePrice: 0n };
        } finally {
            isQuoting.value = false;
        }
    }

    /** ensure user's wallet เชื่อมต่ออยู่ chain TPIX */
    async function ensureOnTpixChain() {
        if (walletStore.chainId === TPIX_CHAIN_ID) return true;
        try {
            await walletStore.switchChain(TPIX_CHAIN_ID);
            return walletStore.chainId === TPIX_CHAIN_ID;
        } catch (err) {
            error.value = 'กรุณาสลับเครือข่ายไปยัง TPIX Chain';
            return false;
        }
    }

    /** Approve USDT ให้ curve ดึงเงิน */
    async function approveUsdt() {
        if (!walletStore.signer) throw new Error('กรุณาเชื่อมต่อ wallet');
        if (!(await ensureOnTpixChain())) throw new Error('Wrong chain');

        isApproving.value = true;
        error.value = null;
        try {
            const usdt = new Contract(LAUNCH_CONTRACTS.USDT, ERC20_ABI, walletStore.signer);
            const amount = parseUnits(usdtAmount.value, USDT_DECIMALS);
            const tx = await usdt.approve(LAUNCH_CONTRACTS.BONDING_CURVE, amount);
            txHash.value = tx.hash;
            await tx.wait();
            await refreshUserBalances();
            return tx.hash;
        } catch (err) {
            error.value = normalizeError(err, 'Approve ล้มเหลว');
            throw err;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * ซื้อ TPIX จาก bonding curve — คำนวณ minTpixOut ตาม slippage
     * ต้อง approve ก่อนถ้า allowance ไม่พอ
     */
    async function buy() {
        if (!canBuy.value) return;
        if (!walletStore.signer) throw new Error('กรุณาเชื่อมต่อ wallet');
        if (!(await ensureOnTpixChain())) return;

        // Auto-approve ถ้ายังไม่ approve
        if (needsApproval.value) {
            await approveUsdt();
        }

        isBuying.value = true;
        error.value = null;
        try {
            const usdtIn = parseUnits(usdtAmount.value, USDT_DECIMALS);

            // คำนวณ minTpixOut ด้วย slippage tolerance
            const expected = await getCurveReader().quoteBuy(usdtIn);
            const minOut = (expected * (BPS - BigInt(slippageBps.value))) / BPS;

            const curve = new Contract(
                LAUNCH_CONTRACTS.BONDING_CURVE,
                BONDING_CURVE_ABI,
                walletStore.signer,
            );
            const tx = await curve.buy(usdtIn, minOut);
            txHash.value = tx.hash;
            const receipt = await tx.wait();

            // Reset form + refresh everything
            usdtAmount.value = '';
            quote.value = { tpixOut: 0n, effectivePrice: 0n };
            await Promise.all([refreshState(), refreshUserBalances()]);
            return receipt;
        } catch (err) {
            error.value = normalizeError(err, 'การซื้อล้มเหลว');
            throw err;
        } finally {
            isBuying.value = false;
        }
    }

    /**
     * ขาย TPIX กลับ curve (- 5% exit fee)
     * @param tpixInStr string — จำนวน TPIX (human-readable, เช่น '10.5')
     */
    async function sell(tpixInStr) {
        if (!walletStore.signer) throw new Error('กรุณาเชื่อมต่อ wallet');
        if (!(await ensureOnTpixChain())) return;

        isSelling.value = true;
        error.value = null;
        try {
            const tpixIn = parseUnits(tpixInStr, TPIX_DECIMALS);
            if (tpixIn <= 0n) throw new Error('จำนวนไม่ถูกต้อง');

            // Approve WTPIX ให้ curve ดึง
            const wtpix = new Contract(LAUNCH_CONTRACTS.WTPIX, ERC20_ABI, walletStore.signer);
            const allowance = await wtpix.allowance(
                walletStore.address,
                LAUNCH_CONTRACTS.BONDING_CURVE,
            );
            if (allowance < tpixIn) {
                const approveTx = await wtpix.approve(LAUNCH_CONTRACTS.BONDING_CURVE, tpixIn);
                await approveTx.wait();
            }

            // Quote + slippage
            const [expectedUsdt] = await getCurveReader().quoteSell(tpixIn);
            const minOut = (expectedUsdt * (BPS - BigInt(slippageBps.value))) / BPS;

            const curve = new Contract(
                LAUNCH_CONTRACTS.BONDING_CURVE,
                BONDING_CURVE_ABI,
                walletStore.signer,
            );
            const tx = await curve.sell(tpixIn, minOut);
            txHash.value = tx.hash;
            const receipt = await tx.wait();

            await Promise.all([refreshState(), refreshUserBalances()]);
            return receipt;
        } catch (err) {
            error.value = normalizeError(err, 'การขายล้มเหลว');
            throw err;
        } finally {
            isSelling.value = false;
        }
    }

    function normalizeError(err, fallback) {
        if (err.code === 4001 || err.code === 'ACTION_REJECTED') {
            return 'ผู้ใช้ยกเลิก transaction';
        }
        if (err.message?.includes('insufficient')) return 'ยอดเงินไม่เพียงพอ';
        if (err.reason) return err.reason;
        return err.shortMessage || err.message || fallback;
    }

    // =========================================================================
    // Lifecycle
    // =========================================================================

    function startPolling(intervalMs = 15000) {
        stopPolling();
        pollInterval = setInterval(() => {
            refreshState();
            if (walletStore.address) refreshUserBalances();
        }, intervalMs);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    onMounted(async () => {
        await refreshState();
        if (walletStore.address) refreshUserBalances();
        startPolling();
    });

    onUnmounted(() => {
        stopPolling();
    });

    return {
        // state
        state,
        loaded,
        loadError,
        refreshing,
        usdtAmount,
        slippageBps,
        quote,
        isQuoting,
        isApproving,
        isBuying,
        isSelling,
        txHash,
        error,
        usdtBalance,
        wtpixBalance,
        usdtAllowance,
        userBought,
        // computed
        readyToUse,
        saleActive,
        progressUsdt,
        progressTpix,
        progressOverall,
        currentPriceFormatted,
        needsApproval,
        canBuy,
        // actions
        refreshState,
        refreshUserBalances,
        refreshQuote,
        approveUsdt,
        buy,
        sell,
    };
}
