/**
 * Crypto Logo Helper สำหรับ Mobile
 * ใช้ CDN เดียวกับเว็บ: CoinCap → CryptoLogos.cc fallback
 */

const COINCAP_CDN = 'https://assets.coincap.io/assets/icons';
const CRYPTOLOGOS = 'https://cryptologos.cc/logos';

/** CryptoLogos.cc slug mapping สำหรับ fallback */
const CRYPTOLOGOS_MAP: Record<string, string> = {
  BTC: 'bitcoin-btc-logo.png',
  ETH: 'ethereum-eth-logo.png',
  BNB: 'bnb-bnb-logo.png',
  USDT: 'tether-usdt-logo.png',
  USDC: 'usd-coin-usdc-logo.png',
  SOL: 'solana-sol-logo.png',
  XRP: 'xrp-xrp-logo.png',
  ADA: 'cardano-ada-logo.png',
  DOGE: 'dogecoin-doge-logo.png',
  DOT: 'polkadot-new-dot-logo.png',
  MATIC: 'polygon-matic-logo.png',
  AVAX: 'avalanche-avax-logo.png',
  LINK: 'chainlink-link-logo.png',
  UNI: 'uniswap-uni-logo.png',
  ATOM: 'cosmos-atom-logo.png',
  LTC: 'litecoin-ltc-logo.png',
  FIL: 'filecoin-fil-logo.png',
  APT: 'aptos-apt-logo.png',
  ARB: 'arbitrum-arb-logo.png',
  OP: 'optimism-ethereum-op-logo.png',
  NEAR: 'near-protocol-near-logo.png',
  AAVE: 'aave-aave-logo.png',
  CAKE: 'pancakeswap-cake-logo.png',
  TRX: 'tron-trx-logo.png',
  SHIB: 'shiba-inu-shib-logo.png',
  DAI: 'multi-collateral-dai-dai-logo.png',
  PEPE: 'pepe-pepe-logo.png',
  SUI: 'sui-sui-logo.png',
  SEI: 'sei-sei-logo.png',
  INJ: 'injective-inj-logo.png',
  RENDER: 'render-token-rndr-logo.png',
  FET: 'fetch-ai-fet-logo.png',
  ETC: 'ethereum-classic-etc-logo.png',
  XLM: 'stellar-xlm-logo.png',
  HBAR: 'hedera-hbar-logo.png',
  ICP: 'internet-computer-icp-logo.png',
};

/** แปลง wrapped token → base symbol */
const SYMBOL_MAP: Record<string, string> = {
  MATIC: 'matic',
  '1INCH': '1inch',
  WBNB: 'bnb',
  WETH: 'eth',
  WBTC: 'btc',
  WTPIX: 'tpix',
};

/** ดึง base symbol จากคู่เทรด เช่น "BTC/USDT" → "BTC" */
export function getBaseSymbol(pair: string): string {
  if (!pair) return '';
  return pair.split(/[/\-]/)[0].toUpperCase();
}

/** ดึง URL โลโก้เหรียญ (CoinCap CDN) */
export function getCoinLogo(symbol: string): string {
  if (!symbol) return '';
  const upper = symbol.toUpperCase().replace(/\/.*$/, '');
  if (upper === 'TPIX') return 'https://tpixtrade.com/tpixlogo.webp';
  const mapped = SYMBOL_MAP[upper] || upper;
  return `${COINCAP_CDN}/${mapped.toLowerCase()}@2x.png`;
}

/** Fallback URL เมื่อ CoinCap ไม่มีโลโก้ */
export function getCoinLogoFallback(symbol: string): string | null {
  if (!symbol) return null;
  const upper = symbol.toUpperCase().replace(/\/.*$/, '');
  const mapped = SYMBOL_MAP[upper] || upper;
  if (CRYPTOLOGOS_MAP[mapped]) {
    return `${CRYPTOLOGOS}/${CRYPTOLOGOS_MAP[mapped]}?v=040`;
  }
  return null;
}
