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

// ที่อยู่ wallet สำหรับรับเงิน Token Sale (BSC)
// ⚠️ ต้องตั้งค่า VITE_SALE_WALLET_ADDRESS ใน .env ก่อน deploy!
const SALE_WALLET = import.meta.env.VITE_SALE_WALLET_ADDRESS || '';
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

    // ตรวจสอบว่ากรอกข้อมูลครบหรือยัง
    const canPurchase = computed(() => {
        return (
            walletStore.isConnected &&
            walletStore.isBSC &&
            currentPhase.value &&
            currentPhase.value.status === 'active' &&
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
     */
    async function executePurchase() {
        if (!canPurchase.value) return;

        isSubmitting.value = true;
        error.value = null;
        purchaseResult.value = null;

        try {
            // ขั้นที่ 1: ส่งเงินบน BSC
            const hash = await sendPaymentTransaction();

            // ขั้นที่ 2: ส่ง tx_hash ไป backend
            const result = await tokenSaleStore.submitPurchase({
                wallet_address: walletStore.address,
                phase_id: currentPhase.value.id,
                currency: selectedCurrency.value,
                amount: parseFloat(paymentAmount.value),
                tx_hash: hash,
            });

            purchaseResult.value = result;

            // รีเซ็ต form หลังซื้อสำเร็จ
            paymentAmount.value = '';
            preview.value = null;
            txHash.value = null;

            return result;
        } catch (err) {
            // error ถูกตั้งค่าใน sendPaymentTransaction หรือ submitPurchase แล้ว
            if (!error.value) {
                error.value = err.message || 'การซื้อล้มเหลว';
            }
            throw err;
        } finally {
            isSubmitting.value = false;
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
        // Actions
        calculatePreview,
        sendPaymentTransaction,
        executePurchase,
        resetForm,
    };
}
