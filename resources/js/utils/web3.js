/**
 * TPIX TRADE - Web3 Utilities
 * Multi-chain config, contract ABIs, and helper functions
 * Developed by Xman Studio
 */

import { Contract, formatUnits, parseUnits, MaxUint256, JsonRpcProvider } from 'ethers';
import axios from 'axios';
import { TPIX_COIN_PNG } from '@/utils/brand';

// =============================================================================
// Chain Configuration Registry
// =============================================================================

/**
 * เชน BSC — ใช้ตรงจุดที่หมายถึง "BSC จริง ๆ" (PancakeSwap swap, คู่เทรด major)
 * อย่าเอาไปใช้แทน "เชนปริยาย" เพราะสองอย่างนี้แยกจากกันแล้ว
 */
export const BSC_CHAIN_ID = 56;

/**
 * เชนปริยายก่อนที่รายการเชนจากเซิร์ฟเวอร์จะโหลดเสร็จ
 *
 * ⚠️ ค่าจริงมาจาก `/api/v1/chains` → `meta.default_chain_id` (getDefaultChainId())
 * ซึ่งเป็น TPIX Chain ทันทีที่เชนเราพร้อม (status = live) ตามที่เจ้าของสั่งไว้
 * ค่านี้เป็นแค่ค่าตั้งต้นระหว่างรอโหลด — ห้ามใช้ตัดสินใจอะไรที่ส่งธุรกรรมออกไป
 */
export const DEFAULT_CHAIN_ID = 56;

let _defaultChainId = DEFAULT_CHAIN_ID;

/** เชนปริยายล่าสุดที่รู้ (ซิงก์จาก fetchSupportedChains) */
export function getDefaultChainId() {
    return _defaultChainId;
}

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
 * Rabby, OKX, Trust, Coinbase อ่านฟิลด์นี้และแสดงโลโก้เชน — MetaMask ไม่อ่าน (โชว์ตัวอักษรย่อแทน)
 *
 * ⚠️ ต้องเป็นโลโก้เหรียญชุดใหม่จาก brand.js (พร้อม ?v=) ไม่ใช่ไฟล์ตายตัว
 *    เดิมชี้ /images/tpix-logo-512.png (ตราทองเก่า) ทั้งที่ทั้งเว็บเปลี่ยนโลโก้ไปแล้ว
 *    กระเป๋าจึงได้โลโก้เก่าทุกครั้งที่เว็บเพิ่มเชนให้ · ที่อยู่ต้องเต็ม (https://) กระเป๋าดึงเองจากภายนอก
 */
export const TPIX_CHAIN_LOGO_URL = `https://tpix.online${TPIX_COIN_PNG}`;

/**
 * RPC สาธารณะของ TPIX Chain — rpc1 เข้าโหนดตรง
 *
 * ⚠️ ห้ามกลับไปใช้ rpc.tpix.online เป็นตัวหลักของกระเป๋า/เบราว์เซอร์
 *    ตัวนั้นอยู่หลัง Cloudflare bot rule ที่ตอบ 403 ให้ client ที่ไม่ใช่เบราว์เซอร์
 *    (แอปกระเป๋าบนมือถือหลายตัว) และตอบ 429 เมื่อยิงถี่ — กระเป๋าที่ได้ RPC นี้ไป
 *    จะอ่านยอดไม่ขึ้น/ส่งธุรกรรมไม่ออกเป็นพัก ๆ (เจ้าของสั่ง 2026-10-20: "เราใช้ rpc1")
 */
export const TPIX_RPC_URL = 'https://rpc1.tpix.online';

/**
 * TPIX Chain config — Polygon Edge, IBFT PoA, Chain ID 4289, gasless.
 * ใช้สำหรับ add chain เข้า wallet โดยตรง (ไม่ต้องรอ backend)
 */
export const TPIX_CHAIN_CONFIG = {
    chainId: '0x10c1', // 4289 in hex (ตัวเล็ก — บางกระเป๋าเทียบ chainId แบบตรงตัวอักษร)
    chainIdNum: 4289,
    chainName: 'TPIX Chain',
    nativeCurrency: {
        name: 'TPIX',
        symbol: 'TPIX',
        decimals: 18,
    },
    rpcUrls: [TPIX_RPC_URL],
    blockExplorerUrls: ['https://explorer.tpix.online'],
    iconUrls: [TPIX_CHAIN_LOGO_URL],
};

/** แปลง chainId ทุกรูปแบบ (4289 / '4289' / '0x10C1' / 4289n) เป็นตัวเลข — แปลงไม่ได้คืน NaN */
export function toChainIdNumber(id) {
    if (typeof id === 'bigint') return Number(id);
    if (typeof id === 'number') return id;
    if (typeof id === 'string') {
        const s = id.trim();
        return /^0x/i.test(s) ? parseInt(s, 16) : Number(s);
    }
    return NaN;
}

/** chainId ในรูป hex ตัวเล็กตาม EIP-3085/3326 */
export function toChainIdHex(id) {
    const n = toChainIdNumber(id);
    if (!Number.isSafeInteger(n) || n <= 0) throw new Error(`Invalid chain id: ${id}`);
    return '0x' + n.toString(16);
}

/** กระเป๋าตอบว่า "ไม่รู้จักเชนนี้" — แต่ละเจ้าใช้รหัสต่างกัน */
function isUnknownChainError(err) {
    return err?.code === 4902
        || err?.code === -32603
        || err?.data?.originalError?.code === 4902
        || /unrecognized chain|unknown chain|not added|wallet_addEthereumChain|try adding the chain/i.test(err?.message || '');
}

/** อ่าน chainId ที่กระเป๋าอยู่จริงตอนนี้ (ไม่เชื่อแค่ว่า request ไม่ error) */
async function readWalletChainId(p) {
    try {
        return toChainIdNumber(await p.request({ method: 'eth_chainId' }));
    } catch {
        return NaN;
    }
}

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
            // เชนปริยายตามที่เซิร์ฟเวอร์บอก (TPIX เมื่อเชนเราพร้อม) — ไม่เดาเองฝั่ง client
            const fromServer = Number(data?.meta?.default_chain_id);
            if (Number.isFinite(fromServer) && fromServer > 0) {
                _defaultChainId = fromServer;
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
        // ส่งแบบเดียวกับ switchToChain เสมอ (ผ่าน buildAddChainParams) — RPC = rpc1 ทุกทาง
        await p.request({
            method: 'wallet_addEthereumChain',
            params: [buildAddChainParams(TPIX_CHAIN_CONFIG)],
        });
        console.log('[TPIX] ✅ TPIX Chain (4289) added to wallet');
        return true;
    } catch (err) {
        // 4001 = ผู้ใช้กดปฏิเสธ · อื่น ๆ = กระเป๋าไม่รองรับ/มีเชนนี้แล้วแบบที่แก้ไม่ได้
        if (err?.code === 4001) {
            console.warn('[TPIX] User declined to add TPIX Chain');
        } else {
            console.warn('[TPIX] Could not add TPIX Chain:', err?.message);
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
    /*
     * รับได้ทั้ง config ฝั่งเว็บ (chainId '0x10c1', chainName, rpcUrls, blockExplorerUrls)
     * และของ /api/v1/chains (chainId 4289, name, rpc, explorer, icon)
     *
     * ★ เดิมทำ '0x' + chain.chainId.toString(16) ตรง ๆ — พอส่ง TPIX_CHAIN_CONFIG เข้ามา
     *   (chainId เป็น string hex อยู่แล้ว) ได้ '0x0x10C1' และ chainName เป็น undefined
     *   หน้า TokenFactory จึงเพิ่มเชนให้ผู้ใช้ไม่ได้เลย
     */
    const chainIdNum = toChainIdNumber(chain.chainIdNum ?? chain.chainId);
    const isTpix = chainIdNum === TPIX_CHAIN_CONFIG.chainIdNum;

    // MetaMask รับเฉพาะ https:// — ตัด null/http ออก
    const raw = chain.rpcUrls || chain.rpc || [];
    let rpcUrls = (Array.isArray(raw) ? raw : [raw])
        .filter(url => typeof url === 'string' && url.startsWith('https://'));

    if (isTpix) {
        // TPIX: rpc1 เป็นตัวแรกเสมอ (กระเป๋าส่วนใหญ่ใช้แค่ตัวแรก) ไม่ว่าแหล่งข้อมูลจะส่งอะไรมา
        // ตัด rpc.tpix.online ออก — กระเป๋ามือถือโดน Cloudflare ตอบ 403
        rpcUrls = [TPIX_RPC_URL];
    } else if (rpcUrls.length === 0) {
        if (chainIdNum === BSC_CHAIN_ID) rpcUrls = [...BSC_CHAIN_CONFIG.rpcUrls];
        else throw new Error(`No valid HTTPS RPC URL for chain ${chain.chainName || chain.name || chainIdNum}`);
    }

    const explorerRaw = chain.blockExplorerUrls || (chain.explorer ? [chain.explorer] : []);
    let blockExplorerUrls = explorerRaw.filter(u => typeof u === 'string' && u.startsWith('https://'));
    if (isTpix && blockExplorerUrls.length === 0) blockExplorerUrls = [...TPIX_CHAIN_CONFIG.blockExplorerUrls];

    // iconUrls: frontend config → backend config → known fallback for TPIX
    // /api/v1/chains ส่งชื่อฟิลด์ว่า `icon` — กระเป๋ารับเฉพาะที่อยู่เต็ม https:// ที่ดึงได้จากภายนอก
    const backendIcon = [chain.icon_url, chain.icon].find(u => typeof u === 'string' && u.startsWith('https://'));
    let iconUrls = chain.iconUrls || (backendIcon ? [backendIcon] : []);
    if (isTpix) iconUrls = [TPIX_CHAIN_LOGO_URL];

    const nativeCurrency = isTpix
        ? { ...TPIX_CHAIN_CONFIG.nativeCurrency }
        : (chain.nativeCurrency || { name: 'ETH', symbol: 'ETH', decimals: 18 });

    const params = {
        chainId: toChainIdHex(chainIdNum),
        chainName: isTpix ? TPIX_CHAIN_CONFIG.chainName : (chain.chainName || chain.name || `Chain ${chainIdNum}`),
        nativeCurrency: {
            name: String(nativeCurrency.name),
            symbol: String(nativeCurrency.symbol),
            decimals: Number(nativeCurrency.decimals ?? 18),
        },
        rpcUrls,
    };
    if (blockExplorerUrls.length > 0) params.blockExplorerUrls = blockExplorerUrls;
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
    const decimals = Number(token?.decimals);
    // EIP-747: symbol ≤ 11 ตัว · decimals 0–36 · ที่อยู่ต้องเป็น 20 ไบต์ — ผิดรูปกระเป๋าจะโชว์ยอดผิด
    if (!/^0x[0-9a-fA-F]{40}$/.test(String(token?.address || ''))
        || !String(token?.symbol || '').trim()
        || !Number.isInteger(decimals) || decimals < 0 || decimals > 36) {
        console.warn('[TPIX] addTokenToWallet: invalid token (address/symbol/decimals)');
        return false;
    }

    // ใช้ symbol ตามที่สัญญาประกาศ — ตัดเกิน 11 ตัวเพราะ MetaMask ปฏิเสธทั้งคำขอ
    // ไม่บังคับตัวใหญ่: MetaMask เทียบกับ symbol() บนเชนแล้วเตือนว่า "ไม่ตรง" ถ้าแปลงเอง
    const options = {
        address: token.address,
        symbol: String(token.symbol).trim().slice(0, 11),
        decimals,
    };
    // กระเป๋าดึงรูปเองจากภายนอก — ที่อยู่สัมพัทธ์ใช้ไม่ได้ เติมโดเมนให้
    const image = typeof token.image === 'string' ? token.image.trim() : '';
    if (image.startsWith('https://')) options.image = image;
    else if (image.startsWith('/') && typeof window !== 'undefined') options.image = `${window.location.origin}${image}`;

    try {
        // EIP-747 ใช้ params เป็น object · กระเป๋าเก่าบางตัว (Trust/OKX รุ่นแรก) รับแค่ array
        let wasAdded;
        try {
            wasAdded = await p.request({ method: 'wallet_watchAsset', params: { type: 'ERC20', options } });
        } catch (shapeErr) {
            if (shapeErr?.code === 4001) throw shapeErr;
            if (shapeErr?.code !== -32602) throw shapeErr;
            wasAdded = await p.request({ method: 'wallet_watchAsset', params: [{ type: 'ERC20', options }] });
        }
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

    const targetNum = toChainIdNumber(targetChainId);
    const hexChainId = toChainIdHex(targetNum);

    // อยู่เชนนั้นแล้ว — ไม่ต้องเด้งป๊อปอัพให้ผู้ใช้กดซ้ำ
    if (await readWalletChainId(injectedProvider) === targetNum) return;

    try {
        await injectedProvider.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: hexChainId }],
        });
    } catch (switchError) {
        if (!isUnknownChainError(switchError)) throw switchError;

        // กระเป๋ายังไม่มีเชนนี้ → เพิ่มให้ (TPIX ใช้ config ในเว็บได้เลย ไม่ต้องรอ API)
        if (!chainConfig) {
            chainConfig = targetNum === TPIX_CHAIN_CONFIG.chainIdNum
                ? TPIX_CHAIN_CONFIG
                : await getChainConfig(targetNum);
        }
        if (!chainConfig) {
            throw new Error(`Chain ${targetNum} is not supported.`);
        }

        await injectedProvider.request({
            method: 'wallet_addEthereumChain',
            params: [buildAddChainParams(chainConfig)],
        });

        // MetaMask สลับให้เองหลังเพิ่ม แต่ Trust/OKX/Coinbase บางรุ่น "เพิ่มอย่างเดียว"
        // ต้องขอสลับซ้ำ ไม่งั้นผู้เรียกเดินต่อบนเชนเดิมโดยคิดว่าสลับแล้ว
        if (await readWalletChainId(injectedProvider) !== targetNum) {
            await injectedProvider.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: hexChainId }],
            });
        }
    }

    // ยืนยันกับกระเป๋าจริง — บางตัวตอบสำเร็จทั้งที่ผู้ใช้ปิดหน้าต่างไป
    let now = await readWalletChainId(injectedProvider);
    if (Number.isFinite(now) && now !== targetNum) {
        // WalletConnect อัปเดต chainId ตาม event ที่มาช้ากว่าคำตอบเล็กน้อย — รอหนึ่งจังหวะก่อนตัดสิน
        await new Promise(r => setTimeout(r, 800));
        now = await readWalletChainId(injectedProvider);
    }
    if (Number.isFinite(now) && now !== targetNum) {
        const err = new Error(`Wallet is still on chain ${now}, expected ${targetNum}`);
        err.code = 'CHAIN_NOT_SWITCHED';
        throw err;
    }
}

/**
 * @deprecated Use switchToChain() instead. Kept for backward compatibility.
 */
export async function switchToBSC() {
    if (!window.ethereum) throw new Error('No wallet detected');
    await switchToChain(window.ethereum, BSC_CHAIN_ID, BSC_CHAIN_CONFIG);
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
 * Read-only BSC provider for swap quotes and balance reads.
 *
 * Swaps run ONLY on BSC/PancakeSwap, so prices and token balances must be read
 * from a BSC node REGARDLESS of which chain the user's wallet is currently on
 * (the wallet auto-switches to TPIX Chain 4289 on connect, where the BSC router
 * and token contracts don't exist). Using a dedicated BSC RPC means quotes and
 * balances are always real — never a fabricated/zero fallback.
 */
let _bscReadProvider = null;
export function getBscReadProvider() {
    if (!_bscReadProvider) {
        // staticNetwork avoids per-call eth_chainId round-trips for a fixed chain.
        _bscReadProvider = new JsonRpcProvider(BSC_CHAIN_CONFIG.rpcUrls[0], 56, { staticNetwork: true });
    }
    return _bscReadProvider;
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
 * explorer ของเชน — จากรายการเชนของเซิร์ฟเวอร์ก่อน แล้วค่อย config ในไฟล์นี้
 *
 * ⚠️ เดิมแคชยังไม่โหลด (หรือโหลดล้มจนเหลือแค่ BSC) แล้วตกไป BscScan ทุกเชน
 *    ธุรกรรมบน TPIX Chain จึงได้ลิงก์ BscScan ที่หาธุรกรรมไม่เจอ — เชนที่รู้จักในไฟล์นี้ต้องได้ explorer ของตัวเอง
 */
function explorerFor(chainId) {
    const id = Number(chainId);
    if (id && _chainCache) {
        const chain = _chainCache.find(c => Number(c.chainId) === id);
        if (chain?.explorer) return chain.explorer;
    }
    if (id === TPIX_CHAIN_CONFIG.chainIdNum) return TPIX_CHAIN_CONFIG.blockExplorerUrls[0];
    return BSC_CHAIN_CONFIG.blockExplorerUrls[0];
}

/**
 * Build block explorer transaction URL for any chain.
 * Falls back to BscScan if chain config not found (TPIX Chain uses its own explorer).
 * @param {string} txHash - Transaction hash
 * @param {number|null} chainId - Chain ID (defaults to BSC)
 */
export function getTxUrl(txHash, chainId = null) {
    return `${explorerFor(chainId)}/tx/${txHash}`;
}

/**
 * Build block explorer address URL for any chain.
 * @param {string} address - Wallet address
 * @param {number|null} chainId - Chain ID (defaults to BSC)
 */
export function getAddressUrl(address, chainId = null) {
    return `${explorerFor(chainId)}/address/${address}`;
}

/**
 * Re-export ethers utilities for convenience.
 */
export { formatUnits, parseUnits, MaxUint256, Contract };
