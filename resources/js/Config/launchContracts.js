/**
 * TPIX Launch — Bonding Curve contract addresses + ABIs
 *
 * Addresses ช่องว่างไว้ (zero address) จนกว่าจะ deploy บน TPIX mainnet
 * ดู TPIX-Coin/contracts/deployed-contracts.json เป็น source of truth
 * อัปเดตที่นี่หลัง deploy — หรือทำ auto-sync จาก registry ในอนาคต
 *
 * Developed by Xman Studio
 */

export const TPIX_CHAIN_ID = 4289;

export const LAUNCH_CONTRACTS = {
    /** WTPIX (Wrapped TPIX ERC-20, WETH9 pattern on TPIX chain) */
    WTPIX: '0x0000000000000000000000000000000000000000',

    /** USDT_TPIX (Bridged Tether on TPIX chain, 6 decimals) */
    USDT: '0x0000000000000000000000000000000000000000',

    /** TPIXBondingCurve (linear curve: $0.10 → $1.00, 700M supply) */
    BONDING_CURVE: '0x0000000000000000000000000000000000000000',
};

/**
 * Check ก่อน call ว่า contracts พร้อมใช้หรือยัง
 */
export function launchContractsDeployed() {
    const zero = '0x0000000000000000000000000000000000000000';
    return (
        LAUNCH_CONTRACTS.WTPIX !== zero &&
        LAUNCH_CONTRACTS.USDT !== zero &&
        LAUNCH_CONTRACTS.BONDING_CURVE !== zero
    );
}

// =============================================================================
// ABIs (minimal — เฉพาะที่ใช้)
// =============================================================================

export const BONDING_CURVE_ABI = [
    // read
    'function currentPrice() view returns (uint256)',
    'function quoteBuy(uint256 usdtIn) view returns (uint256 tpixOut)',
    'function quoteSell(uint256 tpixIn) view returns (uint256 usdtOut, uint256 fee)',
    'function totalSold() view returns (uint256)',
    'function totalRaised() view returns (uint256)',
    'function saleSupply() view returns (uint256)',
    'function startPrice() view returns (uint256)',
    'function endPrice() view returns (uint256)',
    'function migrationUsdtThreshold() view returns (uint256)',
    'function migrationTpixThreshold() view returns (uint256)',
    'function migrated() view returns (bool)',
    'function isMigrationReady() view returns (bool)',
    'function paused() view returns (bool)',
    'function bought(address) view returns (uint256)',
    // write
    'function buy(uint256 usdtIn, uint256 minTpixOut) returns (uint256 tpixOut)',
    'function sell(uint256 tpixIn, uint256 minUsdtOut) returns (uint256 usdtOut)',
    // events
    'event Bought(address indexed buyer, uint256 usdtIn, uint256 tpixOut, uint256 newPrice)',
    'event Sold(address indexed seller, uint256 tpixIn, uint256 usdtOut, uint256 fee, uint256 newPrice)',
];

export const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function decimals() view returns (uint8)',
    'function allowance(address owner, address spender) view returns (uint256)',
    'function approve(address spender, uint256 amount) returns (bool)',
    'function transfer(address to, uint256 amount) returns (bool)',
];

export const WTPIX_ABI = [
    ...ERC20_ABI,
    'function deposit() payable',
    'function withdraw(uint256 amount)',
];
