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
 * URL โลโก้ TPIX Chain — ใช้ใน iconUrls ของ wallet_addEthereumChain (EIP-3085)
 * IPFS pin: bafybeiby5mwnwdi53fye4iurjxlddfzonsj67ejl4sjy7qda53za6jlgo4 (512x512 PNG)
 * Rabby, OKX, Trust อ่านฟิลด์นี้และแสดงโลโก้เชน — MetaMask ปัจจุบัน ignore (bug มายาวนาน)
 */
export const TPIX_CHAIN_LOGO_URL = 'https://tpix.online/images/tpix-logo-512.png';

/**
 * TPIX Chain config — Polygon Edge, IBFT PoA, Chain ID 4289, gasless.
 * ใช้สำหรับ add chain เข้า wallet โดยตรง (ไม่ต้องรอ backend)
 */
export const TPIX_CHAIN_CONFIG = {
    chainId: '0x10C1', // 4289 in hex
    chainIdNum: 4289,
    chainName: 'TPIX Chain',
    nativeCurrency: {
        name: 'TPIX',
        symbol: 'TPIX',
        decimals: 18,
    },
    rpcUrls: ['https://rpc.tpix.online'],
    blockExplorerUrls: ['https://explorer.tpix.online'],
    iconUrls: [TPIX_CHAIN_LOGO_URL],
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
 * เพิ่ม TPIX Chain เข้ากระเป๋าอัตโนมัติ (MetaMask, Trust Wallet, etc.)
 * ใช้ EIP-3085 wallet_addEthereumChain — ถ้า chain มีอยู่แล้วจะข้าม (ไม่ error)
 * เรียกอัตโนมัติหลัง connect wallet สำเร็จ
 */
export async function addTPIXChainToWallet(providerOrWindow = null) {
    const p = providerOrWindow || (typeof window !== 'undefined' ? window.ethereum : null);
    if (!p?.request) return false;

    try {
        await p.request({
            method: 'wallet_addEthereumChain',
            params: [{
                chainId: TPIX_CHAIN_CONFIG.chainId,
                chainName: TPIX_CHAIN_CONFIG.chainName,
                nativeCurrency: TPIX_CHAIN_CONFIG.nativeCurrency,
                rpcUrls: TPIX_CHAIN_CONFIG.rpcUrls,
                blockExplorerUrls: TPIX_CHAIN_CONFIG.blockExplorerUrls,
                iconUrls: TPIX_CHAIN_CONFIG.iconUrls,
            }],
        });
        console.log('[TPIX] ✅ TPIX Chain (4289) added to wallet');
        return true;
    } catch (err) {
        // 4902 = chain ถูกเพิ่มแล้ว (ปกติ), 4001 = user ปฏิเสธ
        if (err.code === 4001) {
            console.warn('[TPIX] User declined to add TPIX Chain');
        } else {
            console.warn('[TPIX] Could not add TPIX Chain:', err.message);
        }
        return false;
    }
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
    // Support both `rpc` (backend) and `rpcUrls` (frontend config) field names
    const raw = chain.rpcUrls || chain.rpc || [];
    const rawRpc = Array.isArray(raw) ? raw : [raw];
    // MetaMask requires valid HTTPS URLs only — filter out null/http
    let rpcUrls = rawRpc.filter(url => typeof url === 'string' && url.startsWith('https://'));
    // Fallback: use known RPC for common chains
    if (rpcUrls.length === 0) {
        if (chain.chainId === 56 || chain.chainId === '0x38') rpcUrls = ['https://bsc-dataseed1.binance.org'];
        else if (chain.chainId === 4289 || chain.chainId === '0x10C1') rpcUrls = ['https://rpc.tpix.online'];
        else throw new Error(`No valid HTTPS RPC URL for chain ${chain.name || chain.chainId}`);
    }
    const explorerUrls = chain.blockExplorerUrls || (chain.explorer ? [chain.explorer] : []);

    // iconUrls: frontend config → backend config → known fallback for TPIX
    let iconUrls = chain.iconUrls || (chain.icon_url ? [chain.icon_url] : []);
    if (iconUrls.length === 0 && (chain.chainId === 4289 || chain.chainId === '0x10C1')) {
        iconUrls = [TPIX_CHAIN_LOGO_URL];
    }

    const params = {
        chainId: '0x' + chain.chainId.toString(16),
        chainName: chain.name,
        nativeCurrency: chain.nativeCurrency || { name: 'ETH', symbol: 'ETH', decimals: 18 },
        rpcUrls,
        blockExplorerUrls: explorerUrls,
    };
    if (iconUrls.length > 0) params.iconUrls = iconUrls;
    return params;
}

/**
 * เพิ่ม ERC20 token เข้ากระเป๋าผู้ใช้ผ่าน EIP-747 wallet_watchAsset
 * รองรับ MetaMask, Rabby, OKX, Trust, Coinbase Wallet
 *
 * @param {object} token - Token metadata
 * @param {string} token.address - Contract address (required)
 * @param {string} token.symbol - Token symbol, 2-11 chars (required)
 * @param {number} token.decimals - Token decimals, usually 18 (required)
 * @param {string} [token.image] - Logo URL (optional but strongly recommended)
 * @param {object} [providerOrWindow=window.ethereum] - Wallet provider (optional)
 * @returns {Promise<boolean>} true ถ้าเพิ่มสำเร็จ, false ถ้าผู้ใช้ปฏิเสธหรือ error
 */
export async function addTokenToWallet(token, providerOrWindow = null) {
    const p = providerOrWindow || (typeof window !== 'undefined' ? window.ethereum : null);
    if (!p?.request) {
        console.warn('[TPIX] No wallet provider detected');
        return false;
    }
    if (!token?.address || !token?.symbol || token?.decimals == null) {
        console.warn('[TPIX] addTokenToWallet: missing required fields (address/symbol/decimals)');
        return false;
    }

    try {
        const wasAdded = await p.request({
            method: 'wallet_watchAsset',
            params: {
                type: 'ERC20',
                options: {
                    address: token.address,
                    symbol: String(token.symbol).toUpperCase().slice(0, 11),
                    decimals: Number(token.decimals),
                    image: token.image || undefined,
                },
            },
        });
        if (wasAdded) {
            console.log(`[TPIX] ✅ Token ${token.symbol} added to wallet`);
        }
        return !!wasAdded;
    } catch (err) {
        if (err.code === 4001) {
            console.warn(`[TPIX] User declined to add token ${token.symbol}`);
        } else {
            console.warn(`[TPIX] Could not add token ${token.symbol}:`, err.message);
        }
        return false;
    }
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
        // Chain not added yet — MetaMask uses 4902, some wallets use -32603 or other codes
        const needsAdd = switchError.code === 4902
            || switchError.code === -32603
            || switchError?.data?.originalError?.code === 4902
            || /unrecognized chain|wallet_addEthereumChain/i.test(switchError.message);
        if (needsAdd) {
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
