/**
 * TPIX DEX (AMM บน TPIX Chain 4289) — contract addresses + ABIs
 *
 * addresses ใน dexContracts.json ถูกเขียนทับอัตโนมัติโดย
 * TPIX-Coin/contracts/scripts/deploy-dex.js ตอน deploy mainnet
 * (ก่อน deploy ทุกตัวเป็น zero address → isDexConfigured() = false
 *  หน้า UI ต้อง fail-closed แบบเดียวกับ fee_collector_wallet ใน Swap)
 *
 * Developed by Xman Studio
 */

import deployed from './dexContracts.json';

const ZERO = '0x0000000000000000000000000000000000000000';

export const TPIX_DEX = {
    CHAIN_ID: deployed.chainId ?? 4289,
    RPC: deployed.rpc ?? 'https://rpc.tpix.online',
    WTPIX: deployed.WTPIX ?? ZERO,
    USDT: deployed.USDT ?? ZERO,
    FACTORY: deployed.FACTORY ?? ZERO,
    ROUTER: deployed.ROUTER ?? ZERO,
};

/** DEX พร้อมใช้เมื่อ address หลักครบทุกตัว (deploy แล้ว) */
export function isDexConfigured() {
    return [TPIX_DEX.WTPIX, TPIX_DEX.USDT, TPIX_DEX.FACTORY, TPIX_DEX.ROUTER]
        .every((a) => a && a !== ZERO);
}

/** Router02 — เฉพาะ function ที่ frontend ใช้ */
export const TPIX_DEX_ROUTER_ABI = [
    'function WETH() view returns (address)',
    'function factory() view returns (address)',
    'function getAmountsOut(uint256 amountIn, address[] path) view returns (uint256[] amounts)',
    'function getAmountsIn(uint256 amountOut, address[] path) view returns (uint256[] amounts)',
    'function swapExactETHForTokens(uint256 amountOutMin, address[] path, address to, uint256 deadline) payable returns (uint256[] amounts)',
    'function swapExactTokensForETH(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
    'function swapExactTokensForTokens(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
    'function addLiquidityETH(address token, uint256 amountTokenDesired, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) payable returns (uint256 amountToken, uint256 amountETH, uint256 liquidity)',
    'function removeLiquidityETH(address token, uint256 liquidity, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) returns (uint256 amountToken, uint256 amountETH)',
];

export const TPIX_DEX_FACTORY_ABI = [
    'function getPair(address tokenA, address tokenB) view returns (address pair)',
    'function allPairsLength() view returns (uint256)',
    'function feeTo() view returns (address)',
];

export const TPIX_DEX_PAIR_ABI = [
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
];

export const TPIX_DEX_ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address owner, address spender) view returns (uint256)',
    'function approve(address spender, uint256 amount) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];
