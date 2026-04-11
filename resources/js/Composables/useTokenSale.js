/**
 * TPIX TRADE - useTokenSale Composable
 * จัดการ logic การซื้อเหรียญ TPIX ผ่าน BSC
 * รวม wallet interaction, preview, purchase flow
 * Developed by Xman Studio
 */

import { ref, computed, watch } from 'vue';
import { parseUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenSaleStore } from '@/Stores/tokenSaleStore';

const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

// ที่อยู่ USDT contract บน BSC
const USDT_BSC = '0x55d398326f99059fF775485246999027B3197955';
// ที่อยู่ BUSD contract บน BSC
const BUSD_BSC = '0xe9e7CEA3DedcA5984780Bafc599bD69ADd087D56';

// ABI สำหรับ ERC-20 transfer
const ERC20_ABI = [
    'function transfer(address to, uint256 amount) returns (bool)',
    'function balanceOf(address account) view returns (uint256)',
    'function decimals() view returns (uint8)',
];

export function useTokenSale() {
    const walletStore = useWalletStore();
    const tokenSaleStore = useTokenSaleStore();

    // === State ===

    // สกุลเงินที่เลือกจ่าย
    const selectedCurrency = ref('BNB');

    // จำนวนเงินที่จะจ่าย
    const paymentAmount = ref('');

    // ข้อมูล preview (จำนวน TPIX ที่จะได้)
    const preview = ref(null);

    // สถานะต่างๆ
    const isLoadingPreview = ref(false);
    const isSendingTx = ref(false);
    const isSubmitting = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const purchaseResult = ref(null);

    // === Computed ===

    // สกุลเงินที่รับ (จาก sale config)
    const acceptCurrencies = computed(() => {
        return tokenSaleStore.sale?.accept_currencies || ['BNB', 'USDT'];
    });

    // phase ที่ active สำหรับซื้อ
    const currentPhase = computed(() => tokenSaleStore.activePhase);

    // ราคาต่อ TPIX ของ phase ปัจจุบัน (USD)
    const currentPrice = computed(() => {
        return currentPhase.value?.price_usd || 0;
    });

    // เช็คว่า phase ขายหมดหรือยัง
    const isSoldOut = computed(() => {
        if (!currentPhase.value) return true;
        return (currentPhase.value.remaining ?? 0) <= 0;
    });

    // เช็คว่าจำนวนที่สั่งเกิน remaining หรือไม่
    const exceedsRemaining = computed(() => {
        if (!preview.value || !currentPhase.value) return false;
        return preview.value.tpix_amount > (currentPhase.value.remaining ?? 0);
    });

    // ตรวจสอบว่ากรอกข้อมูลครบหรือยัง + เหรียญยังเหลือ
    const canPurchase = computed(() => {
        return (
            walletStore.isConnected &&
            walletStore.isBSC &&
            currentPhase.value &&
            currentPhase.value.status === 'active' &&
            !isSoldOut.value &&
            !exceedsRemaining.value &&
            parseFloat(paymentAmount.value) > 0 &&
            !isSendingTx.value &&
            !isSubmitting.value
        );
    });

    // === Actions ===

    /**
     * คำนวณ preview — จำนวน TPIX ที่จะได้รับ
     * เรียกทุกครั้งที่เปลี่ยน currency หรือ amount
     */
    async function calculatePreview() {
        const amount = parseFloat(paymentAmount.value);
        if (!amount || amount <= 0 || !currentPhase.value) {
            preview.value = null;
            return;
        }

        isLoadingPreview.value = true;
        error.value = null;

        try {
            const result = await tokenSaleStore.getPreview(
                currentPhase.value.id,
                selectedCurrency.value,
                amount
            );
            preview.value = result;
        } catch (err) {
            preview.value = null;
        } finally {
            isLoadingPreview.value = false;
        }
    }

    /**
     * ส่ง transaction จ่ายเงินบน BSC
     * - BNB: ส่ง native BNB ไปที่ sale wallet
     * - USDT/BUSD: เรียก transfer() ของ ERC-20 contract
     * @returns {string} tx hash
     */
    async function sendPaymentTransaction() {
        if (!walletStore.signer) throw new Error('กรุณาเชื่อมต่อ wallet ก่อน');
        if (!walletStore.isBSC) throw new Error('กรุณาสลับไปยัง BSC Network');

        // ดึง wallet address จาก API (เก็บใน tokenSaleStore)
        const SALE_WALLET = tokenSaleStore.sale?.sale_wallet_address || '';

        // ป้องกันส่งเงินไป zero address หรือ wallet ยังไม่ตั้งค่า
        if (!SALE_WALLET || SALE_WALLET === ZERO_ADDRESS || SALE_WALLET.length !== 42) {
            throw new Error('ระบบยังไม่พร้อมรับชำระเงิน กรุณาติดต่อทีมงาน (Sale wallet not configured)');
        }

        const amount = parseFloat(paymentAmount.value);
        if (!amount || amount <= 0) throw new Error('กรุณากรอกจำนวนเงิน');

        isSendingTx.value = true;
        error.value = null;

        try {
            let tx;

            if (selectedCurrency.value === 'BNB') {
                // ส่ง BNB (native token) ตรงไปที่ sale wallet
                tx = await walletStore.signer.sendTransaction({
                    to: SALE_WALLET,
                    value: parseUnits(amount.toString(), 18),
                });
            } else {
                // ส่ง USDT/BUSD ผ่าน ERC-20 transfer
                const tokenAddress = selectedCurrency.value === 'USDT' ? USDT_BSC : BUSD_BSC;
                const { Contract } = await import('ethers');
                const contract = new Contract(tokenAddress, ERC20_ABI, walletStore.signer);
                const decimals = await contract.decimals();
                tx = await contract.transfer(SALE_WALLET, parseUnits(amount.toString(), decimals));
            }

            // รอ transaction confirm
            txHash.value = tx.hash;
            await tx.wait();

            return tx.hash;
        } catch (err) {
            if (err.code === 4001 || err.code === 'ACTION_REJECTED') {
                error.value = 'ผู้ใช้ยกเลิก transaction';
            } else if (err.message?.includes('insufficient')) {
                error.value = 'ยอดเงินไม่เพียงพอ';
            } else {
                error.value = err.reason || err.message || 'การส่ง transaction ล้มเหลว';
            }
            throw err;
        } finally {
            isSendingTx.value = false;
        }
    }

    /**
     * ขั้นตอนซื้อเหรียญแบบเต็ม:
     * 1. ส่งเงินบน BSC → ได้ tx_hash
     * 2. ส่ง tx_hash ไป backend เพื่อ verify และบันทึก allocation
     *
     * ★ ถ้าขั้นที่ 1 สำเร็จแต่ขั้นที่ 2 ล้ม → เก็บ tx_hash ไว้ให้ retry ได้
     */
    async function executePurchase() {
        if (!canPurchase.value) return;

        isSubmitting.value = true;
        error.value = null;
        purchaseResult.value = null;

        try {
            // ขั้นที่ 1: ส่งเงินบน BSC
            const hash = await sendPaymentTransaction();

            // ★ เก็บ tx_hash ลง localStorage เผื่อ API ล้ม
            const pendingKey = `tpix_pending_purchase_${hash}`;
            const pendingData = {
                wallet_address: walletStore.address,
                phase_id: currentPhase.value.id,
                currency: selectedCurrency.value,
                amount: parseFloat(paymentAmount.value),
                tx_hash: hash,
                timestamp: Date.now(),
            };
            localStorage.setItem(pendingKey, JSON.stringify(pendingData));

            // ขั้นที่ 2: ส่ง tx_hash ไป backend (retry สูงสุด 3 ครั้ง)
            let result = null;
            let lastError = null;
            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    result = await tokenSaleStore.submitPurchase(pendingData);
                    break; // สำเร็จ
                } catch (apiErr) {
                    lastError = apiErr;
                    if (attempt < 3) {
                        await new Promise(r => setTimeout(r, 2000 * attempt)); // wait 2s, 4s
                    }
                }
            }

            if (result) {
                // สำเร็จ — ลบ pending
                localStorage.removeItem(pendingKey);
                purchaseResult.value = result;

                // รีเซ็ต form
                paymentAmount.value = '';
                preview.value = null;
                txHash.value = null;

                return result;
            } else {
                // API ล้มทั้ง 3 ครั้ง — แจ้งผู้ใช้พร้อม tx_hash
                error.value = `การบันทึกล้มเหลว แต่เงินถูกส่งไปแล้ว! กรุณาบันทึก TX Hash: ${hash} แล้วติดต่อทีมงาน หรือรีเฟรชหน้าเว็บเพื่อลองใหม่`;
                throw lastError;
            }
        } catch (err) {
            if (!error.value) {
                error.value = err.message || 'การซื้อล้มเหลว';
            }
            throw err;
        } finally {
            isSubmitting.value = false;
        }
    }

    /**
     * Retry pending purchases — เรียกตอนเข้าหน้า Token Sale
     * กู้คืนรายการที่จ่าย BSC สำเร็จแต่ API ยังไม่ได้บันทึก
     */
    async function retryPendingPurchases() {
        if (!walletStore.address) return;

        const keys = Object.keys(localStorage).filter(k => k.startsWith('tpix_pending_purchase_'));
        for (const key of keys) {
            try {
                const data = JSON.parse(localStorage.getItem(key));
                if (!data || data.wallet_address !== walletStore.address) continue;

                // ข้ามถ้าเก่าเกิน 24 ชม.
                if (Date.now() - data.timestamp > 86400000) {
                    localStorage.removeItem(key);
                    continue;
                }

                const result = await tokenSaleStore.submitPurchase(data);
                if (result) {
                    localStorage.removeItem(key);
                    console.log('[TokenSale] Recovered pending purchase:', data.tx_hash);
                }
            } catch {
                // ถ้า retry ไม่สำเร็จ จะลองอีกครั้งตอนเข้าหน้าใหม่
            }
        }
    }

    /**
     * รีเซ็ต state ของ form
     */
    function resetForm() {
        selectedCurrency.value = 'BNB';
        paymentAmount.value = '';
        preview.value = null;
        error.value = null;
        txHash.value = null;
        purchaseResult.value = null;
    }

    return {
        // State
        selectedCurrency,
        paymentAmount,
        preview,
        isLoadingPreview,
        isSendingTx,
        isSubmitting,
        error,
        txHash,
        purchaseResult,
        // Computed
        acceptCurrencies,
        currentPhase,
        currentPrice,
        canPurchase,
        isSoldOut,
        exceedsRemaining,
        // Actions
        calculatePreview,
        sendPaymentTransaction,
        executePurchase,
        retryPendingPurchases,
        resetForm,
    };
}
