/**
 * TPIX TRADE - useTpixDex Composable
 * Swap on-chain จริงบน TPIX Chain (4289) ผ่าน TPIXDEXRouter02 (AMM แบบ UniV2)
 *
 * Invariants (แบบเดียวกับ useSwap.js ฝั่ง BSC):
 *  - quote อ่านจาก RPC ของ TPIX chain โดยตรง ไม่ใช่ provider ของ wallet
 *    → ได้ราคาแม้ wallet อยู่คนละเชน และไม่ fabricate rate 1:1 เด็ดขาด
 *  - fail-closed: ถ้า DEX ยังไม่ deploy (isDexConfigured() = false) ทุก action
 *    คืน error ชัดเจน ไม่เดา address
 *  - fee ของกระดาน = 0.3% ใน pool (LP + protocol feeTo) — ไม่มี fee transaction
 *    แยกให้ user ปฏิเสธได้เหมือนฝั่ง BSC swap
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Contract, JsonRpcProvider, parseUnits, formatUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import {
    TPIX_DEX,
    TPIX_DEX_ROUTER_ABI,
    TPIX_DEX_ERC20_ABI,
    isDexConfigured,
} from '@/Config/dexContracts';

const NATIVE = 'native'; // sentinel แทน native TPIX coin ใน path ที่ user เลือก

let _readProvider = null;
function getTpixReadProvider() {
    if (!_readProvider) {
        _readProvider = new JsonRpcProvider(TPIX_DEX.RPC, TPIX_DEX.CHAIN_ID, { staticNetwork: true });
    }
    return _readProvider;
}

/** แปลง float เป็น wei — cap precision กัน float garbage (แบบเดียวกับ useSwap) */
function toWei(value, decimals) {
    const prec = Math.min(decimals, 12);
    return parseUnits(Number(value).toFixed(prec), decimals);
}

/** map error ดิบเป็นข้อความ user-facing — ห้ามโชว์ raw exception (project rule) */
function friendlyError(err) {
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') return 'Transaction rejected by user.';
    const msg = (err?.reason || err?.shortMessage || err?.message || '').toString().toLowerCase();
    if (msg.includes('pair_not_found') || msg.includes('insufficient_liquidity')) return 'No liquidity available for this pair yet.';
    if (msg.includes('insufficient_output_amount')) return 'Price moved too much — increase slippage and try again.';
    if (msg.includes('expired')) return 'The swap expired before confirming. Please try again.';
    if (msg.includes('transfer_from_failed') || msg.includes('transferfrom')) return 'Token transfer failed — check your balance and approval.';
    if (msg.includes('insufficient')) return 'Insufficient balance for this swap.';
    if (msg.includes('user rejected') || msg.includes('user denied')) return 'Transaction rejected by user.';
    if (msg.includes('timeout') || msg.includes('timed out')) return 'Network timed out. Please try again.';
    return 'Swap failed. Please try again.';
}

/** สร้าง path จริงบนเชน — NATIVE ถูกแทนด้วย WTPIX */
function buildPath(fromToken, toToken) {
    const from = fromToken === NATIVE ? TPIX_DEX.WTPIX : fromToken;
    const to = toToken === NATIVE ? TPIX_DEX.WTPIX : toToken;
    if (from.toLowerCase() === to.toLowerCase()) return null;
    // ถ้าไม่มีคู่ตรง router จะ revert PAIR_NOT_FOUND — caller ลอง route ผ่าน WTPIX ได้
    return [from, to];
}

export function useTpixDex() {
    const isLoadingQuote = ref(false);
    const isExecuting = ref(false);
    const isApproving = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const txStatus = ref(null); // 'pending' | 'confirmed' | 'failed'

    /**
     * ขอ quote จริงจาก router (RPC ของ TPIX chain)
     * @param fromToken 'native' หรือ ERC-20 address
     * @param toToken   'native' หรือ ERC-20 address
     * @param amount    จำนวน (human units)
     * @param decimals  { from, to } — decimals ของแต่ละฝั่ง (native = 18)
     * @returns {amountOut, path, amounts} หรือ null ถ้าไม่มีราคา (ไม่ fabricate)
     */
    async function getQuote(fromToken, toToken, amount, decimals = { from: 18, to: 18 }) {
        error.value = null;
        if (!isDexConfigured()) {
            error.value = 'TPIX DEX is not deployed yet.';
            return null;
        }
        if (!amount || Number(amount) <= 0) return null;

        isLoadingQuote.value = true;
        try {
            const path = buildPath(fromToken, toToken);
            if (!path) return null;

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, getTpixReadProvider());
            const amountIn = toWei(amount, decimals.from);

            let amounts;
            try {
                amounts = await router.getAmountsOut(amountIn, path);
            } catch (directErr) {
                // ไม่มีคู่ตรง → ลอง route ผ่าน WTPIX (token↔token เท่านั้น)
                if (fromToken !== NATIVE && toToken !== NATIVE) {
                    const hopPath = [path[0], TPIX_DEX.WTPIX, path[1]];
                    amounts = await router.getAmountsOut(amountIn, hopPath);
                    return {
                        amountOut: formatUnits(amounts[amounts.length - 1], decimals.to),
                        path: hopPath,
                        amounts,
                    };
                }
                throw directErr;
            }

            return {
                amountOut: formatUnits(amounts[amounts.length - 1], decimals.to),
                path,
                amounts,
            };
        } catch (err) {
            error.value = friendlyError(err);
            return null;
        } finally {
            isLoadingQuote.value = false;
        }
    }

    /** เช็ค allowance ของ ERC-20 ต่อ router (native ไม่ต้อง approve) */
    async function needsApproval(tokenAddress, amount, decimals = 18) {
        if (tokenAddress === NATIVE) return false;
        const walletStore = useWalletStore();
        if (!walletStore.address) return false;
        try {
            const token = new Contract(tokenAddress, TPIX_DEX_ERC20_ABI, getTpixReadProvider());
            const allowance = await token.allowance(walletStore.address, TPIX_DEX.ROUTER);
            return allowance < toWei(amount, decimals);
        } catch {
            return true; // อ่านไม่ได้ → บังคับ approve (fail-closed)
        }
    }

    /** approve token ให้ router */
    async function approveToken(tokenAddress, amount, decimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.signer) throw new Error('Wallet not connected');

        isApproving.value = true;
        error.value = null;
        try {
            const token = new Contract(tokenAddress, TPIX_DEX_ERC20_ABI, walletStore.signer);
            const tx = await token.approve(TPIX_DEX.ROUTER, toWei(amount, decimals));
            await tx.wait();
            return true;
        } catch (err) {
            error.value = friendlyError(err);
            return false;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * Execute swap บน TPIX chain
     * @param fromToken 'native' | ERC-20 address
     * @param toToken   'native' | ERC-20 address
     * @param amount    จำนวนฝั่ง input (human units)
     * @param options   { slippagePct = 0.5, decimals = {from:18,to:18}, path = null }
     * @returns {txHash, amountOut} หรือ null ถ้าพลาด (อ่าน error.value)
     */
    async function executeSwap(fromToken, toToken, amount, options = {}) {
        const { slippagePct = 0.5, decimals = { from: 18, to: 18 } } = options;
        const walletStore = useWalletStore();

        error.value = null;
        txHash.value = null;
        txStatus.value = null;

        if (!isDexConfigured()) {
            error.value = 'TPIX DEX is not deployed yet.';
            return null;
        }
        if (!walletStore.signer || !walletStore.address) {
            error.value = 'Please connect your wallet first.';
            return null;
        }
        // Wrong-chain guard — swap นี้อยู่บน TPIX chain เท่านั้น
        if (Number(walletStore.chainId) !== TPIX_DEX.CHAIN_ID) {
            error.value = 'Please switch your wallet to TPIX Chain (4289).';
            return null;
        }

        isExecuting.value = true;
        try {
            // quote สดก่อนยิงจริง เพื่อคำนวณ minOut จาก slippage
            const quote = options.path
                ? { path: options.path, amounts: null }
                : await getQuote(fromToken, toToken, amount, decimals);
            if (!quote) {
                if (!error.value) error.value = 'No liquidity available for this pair yet.';
                return null;
            }

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const amountIn = toWei(amount, decimals.from);
            const path = quote.path;
            const amounts = quote.amounts ?? (await router.getAmountsOut(amountIn, path));
            const quotedOut = amounts[amounts.length - 1];
            // minOut = quote × (1 - slippage) — คิดเป็น basis points กัน float precision
            const bps = BigInt(Math.round(slippagePct * 100));
            const minOut = (quotedOut * (10000n - bps)) / 10000n;
            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;

            let tx;
            if (fromToken === NATIVE) {
                tx = await router.swapExactETHForTokens(
                    minOut, path, walletStore.address, deadline,
                    { value: amountIn },
                );
            } else if (toToken === NATIVE) {
                tx = await router.swapExactTokensForETH(
                    amountIn, minOut, path, walletStore.address, deadline,
                );
            } else {
                tx = await router.swapExactTokensForTokens(
                    amountIn, minOut, path, walletStore.address, deadline,
                );
            }

            txHash.value = tx.hash;
            txStatus.value = 'pending';
            const receipt = await tx.wait();
            txStatus.value = receipt.status === 1 ? 'confirmed' : 'failed';

            if (receipt.status !== 1) {
                error.value = 'Swap failed on-chain.';
                return null;
            }

            return {
                txHash: tx.hash,
                amountOut: formatUnits(quotedOut, decimals.to),
            };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /** balance ของ token (หรือ native) บน TPIX chain — อ่านผ่าน read RPC */
    async function getBalance(tokenAddress) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return '0';
        try {
            const provider = getTpixReadProvider();
            if (tokenAddress === NATIVE) {
                return formatUnits(await provider.getBalance(walletStore.address), 18);
            }
            const token = new Contract(tokenAddress, TPIX_DEX_ERC20_ABI, provider);
            const [raw, dec] = await Promise.all([
                token.balanceOf(walletStore.address),
                token.decimals(),
            ]);
            return formatUnits(raw, dec);
        } catch {
            return '0';
        }
    }

    return {
        // state
        isLoadingQuote,
        isExecuting,
        isApproving,
        error,
        txHash,
        txStatus,
        // constants
        NATIVE,
        // actions
        getQuote,
        needsApproval,
        approveToken,
        executeSwap,
        getBalance,
        isDexConfigured,
    };
}
