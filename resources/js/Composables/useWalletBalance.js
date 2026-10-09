/**
 * TPIX TRADE - useWalletBalance Composable
 * Fetches real wallet balances from both blockchain (ethers.js)
 * and backend API, combining results for best coverage.
 *
 * ใช้ได้สองแบบ:
 *   useWalletBalance()                 → ยอดบนเชนที่กระเป๋าอยู่ตอนนี้ (พฤติกรรมเดิม — หน้า Portfolio)
 *   useWalletBalance({ chainId: 56 })  → ยอดบนเชนที่ระบุเสมอ ไม่ว่ากระเป๋าจะอยู่เชนไหน
 *
 * ทำไมต้องบังคับเชนได้: กระเป๋าสลับไป TPIX Chain อัตโนมัติตอนเชื่อม แต่เทรดจริงเกิดบน BSC
 * แท็บ "ยอดคงเหลือ" ในหน้าเทรดเลยโชว์ยอดบน TPIX ขณะที่ไม้วิ่งบน BSC — ผู้ใช้เห็นยอดไม่ขยับ
 * หลังเทรด แล้วคิดว่าเหรียญไม่เข้า
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, toValue } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import {
    getTokenBalance,
    getChainConfig,
    getBscReadProvider,
    NATIVE_TOKEN_ADDRESS,
    BSC_CHAIN_ID,
    BSC_CHAIN_CONFIG,
    TPIX_CHAIN_CONFIG,
} from '@/utils/web3';
import { t } from '@/Composables/useTranslation';
import axios from 'axios';

/** แปลงค่าที่ผู้เรียกส่งมา (number / ref / getter) เป็น chain id ที่ใช้ได้ หรือ null */
function resolveChainOption(option) {
    if (option === null || option === undefined) return null;
    const num = Number(toValue(option));
    return Number.isInteger(num) && num > 0 ? num : null;
}

/**
 * สกุลเงินหลักของเชน — เดิมฝัง 'BNB' ตายตัว กระเป๋าบน Ethereum เลยเห็น ETH ของตัวเองเป็น "BNB"
 * ไม่รู้จักเชน = null → ข้ามการแสดงผลสำรอง ดีกว่าติดชื่อเหรียญผิด
 */
async function nativeCurrencyOf(chainId) {
    if (chainId === BSC_CHAIN_ID) return BSC_CHAIN_CONFIG.nativeCurrency;
    if (chainId === TPIX_CHAIN_CONFIG.chainIdNum) return TPIX_CHAIN_CONFIG.nativeCurrency;
    try {
        const config = await getChainConfig(chainId);
        return config?.nativeCurrency?.symbol ? config.nativeCurrency : null;
    } catch {
        return null;
    }
}

export function useWalletBalance(options = {}) {
    const balances = ref([]);
    const totalBalanceUsd = ref(0);
    const isLoading = ref(false);
    const error = ref(null);
    /**
     * true เมื่อ backend ตอบ 403 (กระเป๋ายังไม่เซ็นยืนยัน / ลายเซ็นหมดอายุ 4 ชม.)
     * ผู้เรียกใช้โชว์ปุ่ม "ยืนยันกระเป๋า" แทนที่จะปล่อยให้ดูเหมือนยอดเป็นศูนย์
     */
    const needsVerification = ref(false);

    const walletStore = useWalletStore();

    /** เชนที่ใช้จริง — ที่ผู้เรียกบังคับไว้ ไม่งั้นตามกระเป๋า (ยังไม่รู้เชน = BSC ตามเดิม) */
    const chainId = computed(() => (
        resolveChainOption(options?.chainId)
        ?? (Number(walletStore.chainId) || BSC_CHAIN_ID)
    ));

    /** ลำดับคำขอ — ผลของคำขอเก่า (กระเป๋า/เชนก่อนหน้า) ห้ามทับผลล่าสุด */
    let fetchSeq = 0;
    /** ยอดที่โชว์อยู่เป็นของ "กระเป๋า|เชน" ไหน — สลับแล้วต้องไม่โชว์ยอดของชุดเก่าค้างไว้ */
    let loadedFor = null;

    /**
     * provider สำหรับอ่านยอดบนเชนที่ระบุ
     * BSC มี RPC ของเราเอง (ถูกต้องเสมอไม่ว่ากระเป๋าอยู่เชนไหน)
     * เชนอื่นใช้ provider ของกระเป๋าได้ "เฉพาะเมื่อกระเป๋าอยู่เชนนั้นจริง" — ไม่งั้นจะอ่านยอดผิดเชน
     */
    function readProviderFor(targetChainId) {
        if (targetChainId === BSC_CHAIN_ID) return getBscReadProvider();
        if (walletStore.provider && Number(walletStore.chainId) === targetChainId) return walletStore.provider;
        return null;
    }

    function clear() {
        balances.value = [];
        totalBalanceUsd.value = 0;
        error.value = null;
        needsVerification.value = false;
        loadedFor = null;
    }

    /**
     * Fetch balances from backend API (which calls blockchain RPC).
     */
    async function fetchBalances() {
        const seq = ++fetchSeq;

        if (!walletStore.isConnected || !walletStore.address) {
            clear();
            isLoading.value = false;
            return;
        }

        const address = walletStore.address;
        const targetChainId = chainId.value;
        const key = `${String(address).toLowerCase()}|${targetChainId}`;

        // สลับกระเป๋า/เชน: ล้างยอดของชุดเก่าทันที — รีเฟรชชุดเดิมคงยอดไว้ (ไม่กะพริบว่าง)
        if (loadedFor !== key) {
            balances.value = [];
            totalBalanceUsd.value = 0;
            needsVerification.value = false;
        }

        isLoading.value = true;
        error.value = null;

        try {
            const { data } = await axios.get('/api/v1/wallet/balances', {
                params: {
                    wallet_address: address,
                    chain_id: targetChainId,
                },
            });
            if (seq !== fetchSeq) return;

            needsVerification.value = false;
            if (data.success) {
                balances.value = data.data.balances || [];
                loadedFor = key;
            }
        } catch (err) {
            if (seq !== fetchSeq) return;

            needsVerification.value = err?.response?.status === 403;
            console.warn('Backend balance fetch failed, falling back to ethers.js:', err?.message);
            // อ่านยอดบนเชนไม่ต้องยืนยันกระเป๋า — ยังโชว์เหรียญหลักได้ระหว่างรอผู้ใช้เซ็น
            await fetchNativeBalanceDirect(seq, address, targetChainId, key);
        } finally {
            if (seq === fetchSeq) isLoading.value = false;
        }
    }

    /**
     * Fallback: fetch native balance using ethers.js directly.
     *
     * ยอดที่ค้างอยู่ตอนนี้เป็นของกระเป๋า|เชนเดียวกันเสมอ (สลับแล้วถูกล้างตั้งแต่ต้น fetchBalances)
     * อ่านไม่ได้จึงคงไว้ + ตั้ง error — ไม่ล้างจนแท็บว่างเพราะเน็ตสะดุดครั้งเดียว
     */
    async function fetchNativeBalanceDirect(seq, address, targetChainId, key) {
        // ไม่มีทางอ่านเชนนี้ได้ถูกต้อง (กระเป๋าอยู่คนละเชน) — ไม่เดา ไม่เอายอดของเชนอื่นมาแทน
        const provider = readProviderFor(targetChainId);
        if (!provider || !address) {
            error.value = t('trade.tabs.balanceFailed');
            return;
        }

        try {
            const native = await nativeCurrencyOf(targetChainId);
            if (seq !== fetchSeq) return;
            if (!native) {
                error.value = t('trade.tabs.balanceFailed');
                return;
            }

            const balance = await getTokenBalance(NATIVE_TOKEN_ADDRESS, address, provider);
            if (seq !== fetchSeq) return;

            balances.value = [{
                token_address: NATIVE_TOKEN_ADDRESS,
                symbol: native.symbol,
                name: native.name || native.symbol,
                decimals: native.decimals ?? 18,
                balance,
                is_native: true,
            }];
            loadedFor = key;
        } catch {
            if (seq !== fetchSeq) return;
            error.value = t('trade.tabs.balanceFailed');
        }
    }

    /**
     * Get a specific token balance using ethers.js (บนเชนเดียวกับที่ composable นี้ใช้).
     */
    async function getBalance(tokenAddress) {
        const provider = readProviderFor(chainId.value);
        if (!provider || !walletStore.address) return '0';

        try {
            return await getTokenBalance(tokenAddress, walletStore.address, provider);
        } catch {
            return '0';
        }
    }

    // Auto-fetch เมื่อเชื่อม/สลับ address หรือเชนที่ใช้เปลี่ยน
    // (โหมดบังคับเชน: กระเป๋าสลับเชนไม่ทำให้ chainId เปลี่ยน จึงไม่ยิงซ้ำโดยไม่จำเป็น)
    watch(
        [() => walletStore.address, chainId],
        ([newAddr]) => {
            if (newAddr) {
                fetchBalances();
            } else {
                // ทิ้งคำขอที่ค้างอยู่ด้วย ไม่งั้นผลของกระเป๋าเดิมจะโผล่กลับมาหลังตัดการเชื่อมต่อ
                fetchSeq++;
                clear();
                isLoading.value = false;
            }
        },
    );

    return {
        balances,
        totalBalanceUsd,
        isLoading,
        error,
        needsVerification,
        chainId,
        fetchBalances,
        getBalance,
    };
}
