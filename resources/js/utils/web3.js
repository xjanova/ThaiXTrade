/**
 * TPIX TRADE - Web3 Utilities
 * Multi-chain config, contract ABIs, and helper functions
 * Developed by Xman Studio
 */

import { Contract, formatUnits, parseUnits, MaxUint256 } from 'ethers';
import axios from 'axios';

// =============================================================================
// Chain Configuration Registry
// =============================================================================

/**
 * Default chain ID (BSC). Must match config/chains.php 'default'.
 */
export const DEFAULT_CHAIN_ID = 56;

/**
 * BSC config (kept for backward compatibility).
 */
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

/**
 * Cached chain configs fetched from backend.
 */
let _chainCache = null;
let _chainCachePromise = null;

/**
 * Fetch all supported chains from backend API.
 * Results are cached for the session lifetime.
 */
export async function fetchSupportedChains() {
    if (_chainCache) return _chainCache;
    if (_chainCachePromise) return _chainCachePromise;

    _chainCachePromise = axios.get('/api/v1/chains')
        .then(({ data }) => {
            if (data.success && Array.isArray(data.data)) {
                _chainCache = data.data;
            } else {
                _chainCache = [];
            }
            return _chainCache;
        })
        .catch(() => {
            // Fallback: at least provide BSC
            _chainCache = [{
                chainId: 56,
                name: 'BNB Smart Chain',
                shortName: 'BSC',
                nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
                rpc: ['https://bsc-dataseed1.binance.org'],
                explorer: 'https://bscscan.com',
                color: '#F3BA2F',
                enabled: true,
            }];
            return _chainCache;
        })
        .finally(() => {
            _chainCachePromise = null;
        });

    return _chainCachePromise;
}

/**
 * Get chain config by chain ID. Returns null if not found.
 */
export async function getChainConfig(chainId) {
    const chains = await fetchSupportedChains();
    return chains.find(c => c.chainId === chainId) || null;
}

/**
 * Build EIP-3085 params for wallet_addEthereumChain from a backend chain config.
 */
export function buildAddChainParams(chain) {
    const rpcUrls = Array.isArray(chain.rpc) ? chain.rpc : [chain.rpc];
    const explorerUrls = chain.explorer ? [chain.explorer] : [];

    return {
        chainId: '0x' + chain.chainId.toString(16),
        chainName: chain.name,
        nativeCurrency: chain.nativeCurrency || { name: 'ETH', symbol: 'ETH', decimals: 18 },
        rpcUrls,
        blockExplorerUrls: explorerUrls,
    };
}

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
 * Switch wallet to a specific chain by chainId.
 * Adds the chain if not configured in the wallet (EIP-3085).
 * @param {object} injectedProvider - The raw wallet provider (window.ethereum etc.)
 * @param {number} targetChainId - Target chain ID (e.g. 56 for BSC)
 * @param {object|null} chainConfig - Optional pre-fetched chain config; fetched from API if null
 */
export async function switchToChain(injectedProvider, targetChainId, chainConfig = null) {
    if (!injectedProvider) throw new Error('No wallet detected');

    const hexChainId = '0x' + targetChainId.toString(16);

    try {
        await injectedProvider.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: hexChainId }],
        });
    } catch (switchError) {
        // Chain not added yet (error code 4902)
        if (switchError.code === 4902) {
            // Fetch chain config if not provided
            if (!chainConfig) {
                chainConfig = await getChainConfig(targetChainId);
            }
            if (!chainConfig) {
                throw new Error(`Chain ${targetChainId} is not supported.`);
            }

            await injectedProvider.request({
                method: 'wallet_addEthereumChain',
                params: [buildAddChainParams(chainConfig)],
            });
        } else {
            throw switchError;
        }
    }
}

/**
 * @deprecated Use switchToChain() instead. Kept for backward compatibility.
 */
export async function switchToBSC() {
    if (!window.ethereum) throw new Error('No wallet detected');
    await switchToChain(window.ethereum, DEFAULT_CHAIN_ID, BSC_CHAIN_CONFIG);
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
 * Build block explorer transaction URL for any chain.
 * Falls back to BscScan if chain config not found.
 * @param {string} txHash - Transaction hash
 * @param {number|null} chainId - Chain ID (defaults to BSC)
 */
export function getTxUrl(txHash, chainId = null) {
    // Synchronous lookup from cache; if no cache yet, fall back to BSC
    if (chainId && _chainCache) {
        const chain = _chainCache.find(c => c.chainId === chainId);
        if (chain?.explorer) {
            return `${chain.explorer}/tx/${txHash}`;
        }
    }
    return `${BSC_CHAIN_CONFIG.blockExplorerUrls[0]}/tx/${txHash}`;
}

/**
 * Build block explorer address URL for any chain.
 * @param {string} address - Wallet address
 * @param {number|null} chainId - Chain ID (defaults to BSC)
 */
export function getAddressUrl(address, chainId = null) {
    if (chainId && _chainCache) {
        const chain = _chainCache.find(c => c.chainId === chainId);
        if (chain?.explorer) {
            return `${chain.explorer}/address/${address}`;
        }
    }
    return `${BSC_CHAIN_CONFIG.blockExplorerUrls[0]}/address/${address}`;
}

/**
 * Re-export ethers utilities for convenience.
 */
export { formatUnits, parseUnits, MaxUint256, Contract };
