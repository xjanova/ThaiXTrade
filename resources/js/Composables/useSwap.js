/**
 * TPIX TRADE - useSwap Composable
 * Handles swap quotes, execution, and token approvals (BSC / PancakeSwap V2).
 *
 * Key invariants (see audit 2026-06-19):
 *  - Prices & balances are read from a dedicated BSC RPC, never the wallet's
 *    provider — so quotes work even while the wallet is on TPIX Chain (4289)
 *    and a quote is NEVER fabricated to a 1:1 fallback.
 *  - The ~0.3% platform fee is taken from the INPUT token and RESERVED: only
 *    (amount - fee) is routed through the swap, so the fee transfer always
 *    succeeds (even on MAX) and the displayed numbers match what lands on-chain.
 *  - minOut reflects slippage ONLY (the fee never reduces the router output).
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Contract, parseUnits, formatUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import {
    PANCAKE_ROUTER_ADDRESS,
    PANCAKE_ROUTER_ABI,
    WBNB_ADDRESS,
    isNativeToken,
    getRoutingAddress,
    getTokenBalance,
    getAllowance,
    approveToken as approveTokenUtil,
    getAmountsOut,
    getBscReadProvider,
    getTxUrl,
} from '@/utils/web3';
import axios from 'axios';

// Swaps run on BSC only.
const SWAP_CHAIN_ID = 56;

/**
 * Convert a human float amount to wei. Fractional precision is capped to avoid
 * float garbage in the low digits; the input-side fee reserve provides the
 * safety margin so a MAX swap can never exceed the on-chain balance.
 */
function toWei(value, decimals) {
    const prec = Math.min(decimals, 12);
    return parseUnits(Number(value).toFixed(prec), decimals);
}

/**
 * Map raw ethers / router errors to clean, user-facing messages.
 * Never surface raw exception strings (project rule).
 */
function friendlyError(err) {
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') return 'Transaction rejected by user.';
    const msg = (err?.reason || err?.shortMessage || err?.message || '').toString().toLowerCase();
    if (msg.includes('no liquidity')) return 'No liquidity available for this token pair.';
    if (msg.includes('insufficient_output_amount')) return 'Price moved too much — increase slippage and try again.';
    if (msg.includes('insufficient_a_amount') || msg.includes('insufficient_b_amount')) return 'Price moved too much — please try again.';
    if (msg.includes('expired')) return 'The swap expired before confirming. Please try again.';
    if (msg.includes('transfer_from_failed') || msg.includes('transferfrom')) return 'Token transfer failed — check your balance and approval.';
    if (msg.includes('insufficient')) return 'Insufficient balance for this swap.';
    if (msg.includes('missing revert data') || msg.includes('call_exception')) return 'Swap failed on-chain. Try a smaller amount or higher slippage.';
    if (msg.includes('user rejected') || msg.includes('user denied')) return 'Transaction rejected by user.';
    if (msg.includes('timeout') || msg.includes('timed out')) return 'Network timed out. Please try again.';
    return 'Swap failed. Please try again.';
}

export function useSwap() {
    const isLoadingQuote = ref(false);
    const isExecuting = ref(false);
    const isApproving = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const txStatus = ref(null); // 'pending', 'confirmed', 'failed'

    /**
     * Get a swap quote: real on-chain price from PancakeSwap (read via a BSC RPC)
     * plus the platform fee taken from the input side. Returns null (no quote) on
     * any failure — never a fabricated rate.
     */
    async function getQuote(fromToken, toToken, amount) {
        error.value = null;

        const grossAmount = parseFloat(amount);
        if (!grossAmount || grossAmount <= 0) {
            return null;
        }

        isLoadingQuote.value = true;

        try {
            const fromDecimals = fromToken.decimals || 18;
            const toDecimals = toToken.decimals || 18;

            // 1) Fee / slippage config from backend (needed to size the swap input).
            let backendQuote = null;
            try {
                const { data } = await axios.get('/api/v1/swap/quote', {
                    params: {
                        from_token: fromToken.address,
                        to_token: toToken.address,
                        amount: grossAmount,
                        chain_id: SWAP_CHAIN_ID,
                    },
                });
                if (data.success) backendQuote = data.data.quote;
            } catch (apiErr) {
                console.warn('Backend quote unavailable:', apiErr.message);
            }
            const feeRate = backendQuote?.fee_rate ?? 0.3;
            const slippage = backendQuote?.slippage ?? 0.5;
            const priceImpact = backendQuote?.price_impact ?? 0;

            // 2) Platform fee is taken from the INPUT token; only (amount - fee) is swapped.
            const feeAmount = grossAmount * (feeRate / 100);
            const swapInput = grossAmount - feeAmount;
            if (swapInput <= 0) {
                error.value = 'Amount is too small after fees.';
                return null;
            }
            const grossWei = toWei(grossAmount, fromDecimals);
            const amountInSwapWei = toWei(swapInput, fromDecimals);
            if (amountInSwapWei <= 0n) {
                error.value = 'Amount is too small to swap.';
                return null;
            }
            // Fee = the remainder, so (swap + fee) sums EXACTLY to the gross the user
            // holds. The fee transfer therefore can never exceed the on-chain balance
            // (even on a MAX swap) and can't revert by a rounding wei.
            const feeWei = grossWei > amountInSwapWei ? grossWei - amountInSwapWei : 0n;

            // 3) Build routing path (direct, or via WBNB for liquidity).
            const fromAddr = getRoutingAddress(fromToken.address);
            const toAddr = getRoutingAddress(toToken.address);
            let path;
            if (fromAddr.toLowerCase() === WBNB_ADDRESS.toLowerCase() ||
                toAddr.toLowerCase() === WBNB_ADDRESS.toLowerCase()) {
                path = [fromAddr, toAddr];
            } else {
                path = [fromAddr, WBNB_ADDRESS, toAddr];
            }

            // 4) REAL on-chain price from a BSC node — independent of the wallet's chain.
            const readProvider = getBscReadProvider();
            let amountOut;
            try {
                amountOut = await getAmountsOut(amountInSwapWei, path, readProvider);
            } catch (routerErr) {
                console.warn('Router getAmountsOut failed:', routerErr.message);
                // Pair may lack a direct WBNB hop — try the direct path once.
                if (path.length === 3) {
                    try {
                        path = [fromAddr, toAddr];
                        amountOut = await getAmountsOut(amountInSwapWei, path, readProvider);
                    } catch {
                        throw new Error('No liquidity available for this token pair.');
                    }
                } else {
                    throw new Error('No liquidity available for this token pair.');
                }
            }
            // Never proceed without a real on-chain amount (no 1:1 fabrication).
            if (amountOut == null) {
                throw new Error('No liquidity available for this token pair.');
            }

            // Fee is already deducted from the input, so the router output IS what
            // the user receives — no second haircut on the output side.
            const rawOutputAmount = parseFloat(formatUnits(amountOut, toDecimals));
            const netOutput = rawOutputAmount;
            const minimumReceived = netOutput * (1 - slippage / 100);
            const exchangeRate = netOutput / grossAmount; // effective rate per total token paid

            return {
                amountIn: grossAmount,        // total the user pays (swap + fee)
                swapInput,                    // amount actually routed through PancakeSwap
                amountOut: rawOutputAmount,
                netOutput,
                exchangeRate,
                feeRate,
                feeAmount,
                priceImpact,
                slippage,
                minimumReceived: Math.max(minimumReceived, 0),
                path,
                rawAmountOut: amountOut,       // BigInt — router output for the swapped amount
                amountInSwapWei,              // BigInt — exact wei routed through the swap
                feeWei,                       // BigInt — reserved platform fee (input token)
            };
        } catch (err) {
            error.value = friendlyError(err);
            return null;
        } finally {
            isLoadingQuote.value = false;
        }
    }

    /**
     * Check if the router has sufficient allowance to spend the token.
     * Read against the BSC RPC so it is correct regardless of the wallet's chain.
     */
    async function checkAllowance(tokenAddress, amount, decimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return false;
        if (isNativeToken(tokenAddress)) return true;

        const allowance = await getAllowance(
            tokenAddress,
            walletStore.address,
            PANCAKE_ROUTER_ADDRESS,
            getBscReadProvider(),
        );
        return allowance >= toWei(parseFloat(amount) || 0, decimals);
    }

    /**
     * Approve the router to spend tokens (must be on BSC — caller switches first).
     */
    async function approveToken(tokenAddress) {
        const walletStore = useWalletStore();
        if (!walletStore.signer) throw new Error('Wallet not connected');

        isApproving.value = true;
        error.value = null;

        try {
            const tx = await approveTokenUtil(tokenAddress, PANCAKE_ROUTER_ADDRESS, walletStore.signer);
            return tx;
        } catch (err) {
            error.value = err.code === 4001 ? 'Approval rejected by user.' : 'Failed to approve token.';
            throw err;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * Execute the swap on PancakeSwap (BSC). The caller must already have switched
     * the wallet to BSC. Swaps (amount - fee), then transfers the reserved fee.
     */
    async function executeSwap(fromToken, toToken, amount, quote, slippage = 0.5) {
        const walletStore = useWalletStore();

        if (!walletStore.signer || !walletStore.address) {
            error.value = 'Please connect your wallet first.';
            throw new Error(error.value);
        }
        // Safety: never execute against a missing/ fabricated quote.
        if (!quote || quote.rawAmountOut == null || quote.amountInSwapWei == null) {
            error.value = 'Price quote unavailable — please re-enter the amount.';
            throw new Error(error.value);
        }
        // Defensive: swaps must run on BSC (caller switches before calling).
        if (walletStore.chainId !== SWAP_CHAIN_ID) {
            error.value = 'Please switch to BSC to swap.';
            throw new Error(error.value);
        }

        isExecuting.value = true;
        error.value = null;
        txHash.value = null;
        txStatus.value = 'pending';

        try {
            const amountInSwap = quote.amountInSwapWei;      // BigInt — (amount - fee)
            const feeWei = quote.feeWei || 0n;

            // FAIL-CLOSED: ต้องรู้ที่อยู่ fee collector ก่อนส่ง swap — ถ้าแพลตฟอร์ม
            // ยังไม่ตั้งค่า (หรือ backend ล่ม) ห้าม swap เพื่อไม่ให้เกิดเคส
            // "swap สำเร็จแต่เก็บ fee ไม่ได้" ซึ่งตรวจย้อนหลังไม่ได้
            let feeCollectorAddress = null;
            if (feeWei > 0n) {
                try {
                    const { data: feeInfo } = await axios.get('/api/v1/trading/fee-info', {
                        params: { chain_id: SWAP_CHAIN_ID },
                    });
                    feeCollectorAddress = feeInfo?.data?.fee_collector || null;
                } catch {
                    feeCollectorAddress = null;
                }
                const isValidCollector = typeof feeCollectorAddress === 'string'
                    && feeCollectorAddress.startsWith('0x')
                    && feeCollectorAddress.length === 42;
                if (!isValidCollector) {
                    error.value = 'Swap is temporarily unavailable. Please try again later.';
                    const abortErr = new Error(error.value);
                    abortErr.isFriendly = true; // ข้อความนี้แสดงต่อ user ได้เลย — อย่าให้ friendlyError ทับ
                    throw abortErr;
                }
            }

            // minOut from SLIPPAGE ONLY — the fee never reduces the router output.
            const slipFactorBps = BigInt(Math.max(0, Math.floor((1 - slippage / 100) * 10000)));
            const minOut = (quote.rawAmountOut * slipFactorBps) / 10000n;
            if (minOut <= 0n) {
                error.value = 'Slippage too high — please adjust and retry.';
                const slipErr = new Error(error.value);
                slipErr.isFriendly = true;
                throw slipErr;
            }

            const path = quote.path;
            const deadline = Math.floor(Date.now() / 1000) + 1200; // 20 minutes
            const router = new Contract(PANCAKE_ROUTER_ADDRESS, PANCAKE_ROUTER_ABI, walletStore.signer);

            let tx;
            if (isNativeToken(fromToken.address)) {
                // BNB → Token
                tx = await router.swapExactETHForTokens(
                    minOut, path, walletStore.address, deadline,
                    { value: amountInSwap },
                );
            } else if (isNativeToken(toToken.address)) {
                // Token → BNB
                tx = await router.swapExactTokensForETH(
                    amountInSwap, minOut, path, walletStore.address, deadline,
                );
            } else {
                // Token → Token
                tx = await router.swapExactTokensForTokens(
                    amountInSwap, minOut, path, walletStore.address, deadline,
                );
            }

            txHash.value = tx.hash;
            const receipt = await tx.wait();
            txStatus.value = receipt.status === 1 ? 'confirmed' : 'failed';

            // Collect the RESERVED platform fee — the user still holds it because
            // we only swapped (amount - fee), so this transfer cannot run dry.
            // (fee collector ถูก validate ไว้แล้วก่อนส่ง swap ด้านบน)
            let feeCollected = false;
            if (receipt.status === 1 && feeWei > 0n && feeCollectorAddress) {
                try {
                    if (isNativeToken(fromToken.address)) {
                        const feeTx = await walletStore.signer.sendTransaction({
                            to: feeCollectorAddress,
                            value: feeWei,
                        });
                        await feeTx.wait();
                    } else {
                        const tokenAbi = ['function transfer(address to, uint256 amount) returns (bool)'];
                        const tokenContract = new Contract(fromToken.address, tokenAbi, walletStore.signer);
                        const feeTx = await tokenContract.transfer(feeCollectorAddress, feeWei);
                        await feeTx.wait();
                    }
                    feeCollected = true;
                } catch (feeErr) {
                    // Fee collection failed (e.g. user rejected the 2nd prompt) —
                    // the swap already succeeded, so don't block it.
                    console.warn('Fee collection skipped (swap still succeeded):', feeErr.message);
                }
            }

            // Record on backend (best-effort).
            try {
                await axios.post('/api/v1/swap/execute', {
                    from_token: fromToken.address,
                    to_token: toToken.address,
                    from_amount: quote.amountIn,
                    to_amount: quote.netOutput,
                    fee_amount: feeCollected ? quote.feeAmount : 0,
                    tx_hash: tx.hash,
                    chain_id: SWAP_CHAIN_ID,
                    wallet_address: walletStore.address,
                });
            } catch (apiErr) {
                console.warn('Failed to record swap on backend:', apiErr.message);
            }

            return {
                hash: tx.hash,
                status: txStatus.value,
                url: getTxUrl(tx.hash, SWAP_CHAIN_ID),
            };
        } catch (err) {
            txStatus.value = 'failed';
            // error ที่เรา throw เองมีข้อความ user-facing อยู่แล้ว — ไม่ต้อง map ซ้ำ
            error.value = err.isFriendly ? err.message : friendlyError(err);
            throw err;
        } finally {
            isExecuting.value = false;
        }
    }

    /**
     * Get a token balance for the connected wallet (always read from BSC).
     * Returns the full-precision formatted string.
     */
    async function getBalance(tokenAddress) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return '0';

        try {
            return await getTokenBalance(tokenAddress, walletStore.address, getBscReadProvider());
        } catch {
            return '0';
        }
    }

    /**
     * Reset swap state.
     */
    function reset() {
        error.value = null;
        txHash.value = null;
        txStatus.value = null;
    }

    return {
        isLoadingQuote,
        isExecuting,
        isApproving,
        error,
        txHash,
        txStatus,
        getQuote,
        checkAllowance,
        approveToken,
        executeSwap,
        getBalance,
        reset,
    };
}
