/**
 * TPIX TRADE - useSwap Composable
 * Handles swap quotes, execution, and token approvals
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Contract, parseUnits, formatUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import {
    PANCAKE_ROUTER_ADDRESS,
    PANCAKE_ROUTER_ABI,
    WBNB_ADDRESS,
    NATIVE_TOKEN_ADDRESS,
    isNativeToken,
    getRoutingAddress,
    getTokenBalance,
    getAllowance,
    approveToken as approveTokenUtil,
    getAmountsOut,
    getTxUrl,
} from '@/utils/web3';
import axios from 'axios';

export function useSwap() {
    const isLoadingQuote = ref(false);
    const isExecuting = ref(false);
    const isApproving = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const txStatus = ref(null); // 'pending', 'confirmed', 'failed'

    /**
     * Get a swap quote combining on-chain price from PancakeSwap
     * and fee breakdown from the backend.
     */
    async function getQuote(fromToken, toToken, amount) {
        const walletStore = useWalletStore();
        error.value = null;

        if (!amount || parseFloat(amount) <= 0) {
            return null;
        }

        isLoadingQuote.value = true;

        try {
            const fromDecimals = fromToken.decimals || 18;
            const toDecimals = toToken.decimals || 18;
            const amountIn = parseUnits(amount.toString(), fromDecimals);

            // Build routing path
            const fromAddr = getRoutingAddress(fromToken.address);
            const toAddr = getRoutingAddress(toToken.address);

            let path;
            if (fromAddr.toLowerCase() === WBNB_ADDRESS.toLowerCase() ||
                toAddr.toLowerCase() === WBNB_ADDRESS.toLowerCase()) {
                path = [fromAddr, toAddr];
            } else {
                // Route through WBNB for better liquidity
                path = [fromAddr, WBNB_ADDRESS, toAddr];
            }

            // Get on-chain price from PancakeSwap router
            let amountOut;
            if (walletStore.provider) {
                try {
                    amountOut = await getAmountsOut(amountIn, path, walletStore.provider);
                } catch (routerErr) {
                    // Pair might not exist or insufficient liquidity
                    console.warn('Router getAmountsOut failed:', routerErr.message);
                    // Try direct path if routed through WBNB
                    if (path.length === 3) {
                        try {
                            path = [fromAddr, toAddr];
                            amountOut = await getAmountsOut(amountIn, path, walletStore.provider);
                        } catch {
                            throw new Error('No liquidity available for this token pair.');
                        }
                    } else {
                        throw new Error('No liquidity available for this token pair.');
                    }
                }
            }

            // Get fee breakdown from backend
            let backendQuote = null;
            try {
                const chainId = walletStore.chainId || 56;
                const { data } = await axios.get('/api/v1/swap/quote', {
                    params: {
                        from_token: fromToken.address,
                        to_token: toToken.address,
                        amount: parseFloat(amount),
                        chain_id: chainId,
                    },
                });
                if (data.success) {
                    backendQuote = data.data.quote;
                }
            } catch (apiErr) {
                console.warn('Backend quote unavailable:', apiErr.message);
            }

            // Calculate output values
            const rawOutputAmount = amountOut
                ? parseFloat(formatUnits(amountOut, toDecimals))
                : parseFloat(amount); // fallback

            const feeRate = backendQuote?.fee_rate ?? 0.3;
            const feeAmount = parseFloat(amount) * (feeRate / 100);
            const priceImpact = backendQuote?.price_impact ?? 0;
            const slippage = backendQuote?.slippage ?? 0.5;

            // The on-chain amount already reflects the real exchange rate
            const exchangeRate = rawOutputAmount / parseFloat(amount);
            const netOutput = rawOutputAmount * (1 - feeRate / 100);
            const minimumReceived = netOutput * (1 - slippage / 100);

            return {
                amountIn: parseFloat(amount),
                amountOut: rawOutputAmount,
                netOutput,
                exchangeRate,
                feeRate,
                feeAmount,
                priceImpact,
                slippage,
                minimumReceived: Math.max(minimumReceived, 0),
                path,
                rawAmountOut: amountOut,
            };
        } catch (err) {
            error.value = err.message || 'Failed to get quote.';
            return null;
        } finally {
            isLoadingQuote.value = false;
        }
    }

    /**
     * Check if the router has sufficient allowance to spend the token.
     */
    async function checkAllowance(tokenAddress, amount, decimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.provider || !walletStore.address) return false;
        if (isNativeToken(tokenAddress)) return true;

        const allowance = await getAllowance(
            tokenAddress,
            walletStore.address,
            PANCAKE_ROUTER_ADDRESS,
            walletStore.provider,
        );
        const amountWei = parseUnits(amount.toString(), decimals);
        return allowance >= amountWei;
    }

    /**
     * Approve the router to spend tokens.
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
            if (err.code === 4001) {
                error.value = 'Approval rejected by user.';
            } else {
                error.value = 'Failed to approve token.';
            }
            throw err;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * Execute the swap transaction on PancakeSwap.
     */
    async function executeSwap(fromToken, toToken, amount, quote, slippage = 0.5) {
        const walletStore = useWalletStore();

        if (!walletStore.signer || !walletStore.address) {
            error.value = 'Please connect your wallet first.';
            throw new Error(error.value);
        }

        isExecuting.value = true;
        error.value = null;
        txHash.value = null;
        txStatus.value = 'pending';

        try {
            const fromDecimals = fromToken.decimals || 18;
            const toDecimals = toToken.decimals || 18;
            const amountIn = parseUnits(amount.toString(), fromDecimals);

            // Calculate minimum output with slippage + fee
            const feeRate = quote.feeRate || 0.3;
            const slippageFactor = 1 - (slippage / 100) - (feeRate / 100);
            const minOut = quote.rawAmountOut
                ? (quote.rawAmountOut * BigInt(Math.floor(slippageFactor * 10000))) / 10000n
                : 0n;

            const path = quote.path;
            const deadline = Math.floor(Date.now() / 1000) + 1200; // 20 minutes

            const router = new Contract(PANCAKE_ROUTER_ADDRESS, PANCAKE_ROUTER_ABI, walletStore.signer);

            let tx;
            if (isNativeToken(fromToken.address)) {
                // BNB → Token
                tx = await router.swapExactETHForTokens(
                    minOut,
                    path,
                    walletStore.address,
                    deadline,
                    { value: amountIn },
                );
            } else if (isNativeToken(toToken.address)) {
                // Token → BNB
                tx = await router.swapExactTokensForETH(
                    amountIn,
                    minOut,
                    path,
                    walletStore.address,
                    deadline,
                );
            } else {
                // Token → Token
                tx = await router.swapExactTokensForTokens(
                    amountIn,
                    minOut,
                    path,
                    walletStore.address,
                    deadline,
                );
            }

            txHash.value = tx.hash;

            // Wait for confirmation
            const receipt = await tx.wait();
            txStatus.value = receipt.status === 1 ? 'confirmed' : 'failed';

            // Record on backend
            try {
                await axios.post('/api/v1/swap/execute', {
                    from_token: fromToken.address,
                    to_token: toToken.address,
                    from_amount: parseFloat(amount),
                    to_amount: quote.netOutput,
                    fee_amount: quote.feeAmount,
                    tx_hash: tx.hash,
                    chain_id: walletStore.chainId || 56,
                    wallet_address: walletStore.address,
                });
            } catch (apiErr) {
                console.warn('Failed to record swap on backend:', apiErr.message);
            }

            return {
                hash: tx.hash,
                status: txStatus.value,
                url: getTxUrl(tx.hash),
            };
        } catch (err) {
            txStatus.value = 'failed';
            if (err.code === 4001 || err.code === 'ACTION_REJECTED') {
                error.value = 'Transaction rejected by user.';
            } else if (err.message?.includes('insufficient')) {
                error.value = 'Insufficient balance for this swap.';
            } else {
                error.value = err.reason || err.message || 'Swap failed. Please try again.';
            }
            throw err;
        } finally {
            isExecuting.value = false;
        }
    }

    /**
     * Get token balance for the connected wallet.
     */
    async function getBalance(tokenAddress) {
        const walletStore = useWalletStore();
        if (!walletStore.provider || !walletStore.address) return '0';

        try {
            return await getTokenBalance(tokenAddress, walletStore.address, walletStore.provider);
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
