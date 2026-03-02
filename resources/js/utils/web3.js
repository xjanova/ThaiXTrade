/**
 * TPIX TRADE - Web3 Utilities
 * BSC chain config, contract ABIs, and helper functions
 * Developed by Xman Studio
 */

import { Contract, formatUnits, parseUnits, MaxUint256 } from 'ethers';

// =============================================================================
// BSC Chain Configuration
// =============================================================================

export const BSC_CHAIN_CONFIG = {
    chainId: '0x38', // 56 in hex
    chainIdNum: 56,
    chainName: 'BNB Smart Chain',
    nativeCurrency: {
        name: 'BNB',
        symbol: 'BNB',
        decimals: 18,
    },
    rpcUrls: ['https://bsc-dataseed1.binance.org', 'https://bsc-dataseed2.binance.org'],
    blockExplorerUrls: ['https://bscscan.com'],
};

// Native BNB placeholder address (used by DEX frontends)
export const NATIVE_TOKEN_ADDRESS = '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE';

// Wrapped BNB on BSC
export const WBNB_ADDRESS = '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c';

// PancakeSwap V2 Router on BSC
export const PANCAKE_ROUTER_ADDRESS = '0x10ED43C718714eb63d5aA57B78B54704E256024E';

// PancakeSwap V2 Factory on BSC
export const PANCAKE_FACTORY_ADDRESS = '0xcA143Ce32Fe78f1f7019d7d551a6402fC5350c73';

// =============================================================================
// ABIs (Minimal)
// =============================================================================

export const ERC20_ABI = [
    'function name() view returns (string)',
    'function symbol() view returns (string)',
    'function decimals() view returns (uint8)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address owner) view returns (uint256)',
    'function allowance(address owner, address spender) view returns (uint256)',
    'function approve(address spender, uint256 amount) returns (bool)',
    'function transfer(address to, uint256 amount) returns (bool)',
];

export const PANCAKE_ROUTER_ABI = [
    'function getAmountsOut(uint256 amountIn, address[] memory path) view returns (uint256[] memory amounts)',
    'function swapExactTokensForTokens(uint256 amountIn, uint256 amountOutMin, address[] calldata path, address to, uint256 deadline) returns (uint256[] memory amounts)',
    'function swapExactETHForTokens(uint256 amountOutMin, address[] calldata path, address to, uint256 deadline) payable returns (uint256[] memory amounts)',
    'function swapExactTokensForETH(uint256 amountIn, uint256 amountOutMin, address[] calldata path, address to, uint256 deadline) returns (uint256[] memory amounts)',
    'function WETH() view returns (address)',
];

// =============================================================================
// Helper Functions
// =============================================================================

/**
 * Prompt MetaMask to switch to BSC, adding the chain if not configured.
 */
export async function switchToBSC() {
    if (!window.ethereum) throw new Error('No wallet detected');

    try {
        await window.ethereum.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: BSC_CHAIN_CONFIG.chainId }],
        });
    } catch (switchError) {
        // Chain not added yet (error code 4902)
        if (switchError.code === 4902) {
            await window.ethereum.request({
                method: 'wallet_addEthereumChain',
                params: [{
                    chainId: BSC_CHAIN_CONFIG.chainId,
                    chainName: BSC_CHAIN_CONFIG.chainName,
                    nativeCurrency: BSC_CHAIN_CONFIG.nativeCurrency,
                    rpcUrls: BSC_CHAIN_CONFIG.rpcUrls,
                    blockExplorerUrls: BSC_CHAIN_CONFIG.blockExplorerUrls,
                }],
            });
        } else {
            throw switchError;
        }
    }
}

/**
 * Format a wallet address to shortened form: 0x1234...5678
 */
export function formatAddress(addr) {
    if (!addr) return '';
    return `${addr.slice(0, 6)}...${addr.slice(-4)}`;
}

/**
 * Check if address is the native BNB placeholder.
 */
export function isNativeToken(address) {
    return address?.toLowerCase() === NATIVE_TOKEN_ADDRESS.toLowerCase();
}

/**
 * Get the actual token address for routing (WBNB for native BNB).
 */
export function getRoutingAddress(address) {
    return isNativeToken(address) ? WBNB_ADDRESS : address;
}

/**
 * Get ERC20 token balance for an address.
 * Returns formatted string (human-readable).
 */
export async function getTokenBalance(tokenAddress, walletAddress, provider) {
    if (!provider || !walletAddress) return '0';

    // Native BNB balance
    if (isNativeToken(tokenAddress)) {
        const balance = await provider.getBalance(walletAddress);
        return formatUnits(balance, 18);
    }

    // ERC20 balance
    const contract = new Contract(tokenAddress, ERC20_ABI, provider);
    const [balance, decimals] = await Promise.all([
        contract.balanceOf(walletAddress),
        contract.decimals(),
    ]);
    return formatUnits(balance, decimals);
}

/**
 * Get ERC20 allowance for spender.
 */
export async function getAllowance(tokenAddress, ownerAddress, spenderAddress, provider) {
    if (isNativeToken(tokenAddress)) return MaxUint256; // Native doesn't need approval
    const contract = new Contract(tokenAddress, ERC20_ABI, provider);
    return contract.allowance(ownerAddress, spenderAddress);
}

/**
 * Approve ERC20 token spending (max approval).
 */
export async function approveToken(tokenAddress, spenderAddress, signer) {
    const contract = new Contract(tokenAddress, ERC20_ABI, signer);
    const tx = await contract.approve(spenderAddress, MaxUint256);
    await tx.wait();
    return tx;
}

/**
 * Get swap amounts out from PancakeSwap router.
 * Returns the expected output amount (BigInt).
 */
export async function getAmountsOut(amountIn, path, provider) {
    const router = new Contract(PANCAKE_ROUTER_ADDRESS, PANCAKE_ROUTER_ABI, provider);
    const amounts = await router.getAmountsOut(amountIn, path);
    return amounts[amounts.length - 1];
}

/**
 * Build BscScan transaction URL.
 */
export function getTxUrl(txHash) {
    return `${BSC_CHAIN_CONFIG.blockExplorerUrls[0]}/tx/${txHash}`;
}

/**
 * Re-export ethers utilities for convenience.
 */
export { formatUnits, parseUnits, MaxUint256, Contract };
