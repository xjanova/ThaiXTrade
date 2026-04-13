/**
 * Wallet Service — Chain Config & Utilities
 * บริการกระเป๋าเงิน — ตั้งค่าเครือข่าย & ยูทิลิตี้
 *
 * v3: ลบ wallet detection (canOpenURL ไม่เสถียร)
 * เหลือเฉพาะ chain config + utility functions
 *
 * Developed by Xman Studio
 */

// --- Chain Configuration / ตั้งค่าเครือข่าย ---

export interface ChainConfig {
  chainId: number;
  name: string;
  symbol: string;
  rpcUrl: string;
  explorerUrl: string;
  iconColor: string;
  isDefault?: boolean;
}

export const SUPPORTED_CHAINS: ChainConfig[] = [
  {
    chainId: 4289,
    name: 'TPIX Chain',
    symbol: 'TPIX',
    rpcUrl: 'https://rpc.tpix.online',
    explorerUrl: 'https://explorer.tpix.online',
    iconColor: '#06B6D4',
    isDefault: true,
  },
  {
    chainId: 56,
    name: 'BNB Smart Chain',
    symbol: 'BSC',
    rpcUrl: 'https://bsc-dataseed1.binance.org',
    explorerUrl: 'https://bscscan.com',
    iconColor: '#F0B90B',
  },
  {
    chainId: 1,
    name: 'Ethereum',
    symbol: 'ETH',
    rpcUrl: 'https://eth.llamarpc.com',
    explorerUrl: 'https://etherscan.io',
    iconColor: '#627EEA',
  },
  {
    chainId: 137,
    name: 'Polygon',
    symbol: 'MATIC',
    rpcUrl: 'https://polygon-rpc.com',
    explorerUrl: 'https://polygonscan.com',
    iconColor: '#8247E5',
  },
];

// --- Utility Functions / ฟังก์ชันยูทิลิตี้ ---

/**
 * Shorten wallet address for display
 * ย่อ address กระเป๋าเงินสำหรับแสดงผล
 */
export function shortenAddress(address: string, chars = 4): string {
  if (!address || address.length < chars * 2 + 2) return address;
  return `${address.slice(0, chars + 2)}...${address.slice(-chars)}`;
}

/**
 * Get chain config by chainId
 * ดึง chain config จาก chainId
 */
export function getChainByChainId(chainId: number): ChainConfig | undefined {
  return SUPPORTED_CHAINS.find(c => c.chainId === chainId);
}

/**
 * Get explorer URL for a transaction or address
 * ดึง URL ของ explorer สำหรับ transaction หรือ address
 */
export function getExplorerUrl(chainId: number, hash: string, type: 'tx' | 'address' = 'address'): string {
  const chain = getChainByChainId(chainId);
  if (!chain) return '';
  return `${chain.explorerUrl}/${type}/${hash}`;
}
