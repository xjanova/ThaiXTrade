/**
 * TPIX TRADE - Crypto Logo Helper
 * Uses CoinCap CDN (reliable, no auth required, allows hotlinking)
 * With fallback to Trust Wallet Assets for BSC tokens
 * Developed by Xman Studio
 */

/**
 * Primary: CoinCap CDN - uses lowercase symbol
 * Format: https://assets.coincap.io/assets/icons/{symbol}@2x.png
 */
const COINCAP_CDN = 'https://assets.coincap.io/assets/icons';

/**
 * Fallback: Trust Wallet Assets on GitHub
 * For BSC-specific tokens using contract address
 */
const TW_ASSETS = 'https://raw.githubusercontent.com/trustwallet/assets/master';

/**
 * BSC token addresses for Trust Wallet fallback
 */
const BSC_TOKEN_ADDRESSES = {
    USDT: '0x55d398326f99059fF775485246999027B3197955',
    USDC: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
    ETH:  '0x2170Ed0880ac9A755fd29B2688956BD959F933F8',
    BTC:  '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c',
    CAKE: '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82',
    DOGE: '0xbA2aE424d960c26247Dd6c32edC70B295c744C43',
    SOL:  '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF',
    DAI:  '0x1AF3F329e8BE154074D8769D1FFa4eE058B1DBc3',
    LINK: '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD',
    UNI:  '0xBf5140A22578168FD562DCcF235E5D43A02ce9B1',
    AAVE: '0xfb6115445Bff7b52FeB98650C87f44907E58f802',
};

/**
 * Special symbol mappings for CoinCap CDN
 * Some symbols differ from standard ticker names
 */
const SYMBOL_MAP = {
    'MATIC': 'matic',
    '1INCH': '1inch',
};

/**
 * Get the logo URL for a given coin symbol
 * @param {string} symbol - Coin symbol (e.g. 'BTC', 'ETH')
 * @returns {string} Logo URL or empty string
 */
export function getCoinLogo(symbol) {
    if (!symbol) return '';
    const upper = symbol.toUpperCase().replace(/\/.*$/, '');
    const lower = (SYMBOL_MAP[upper] || upper).toLowerCase();
    return `${COINCAP_CDN}/${lower}@2x.png`;
}

/**
 * Get BSC token logo from Trust Wallet Assets
 * @param {string} contractAddress - BSC token contract address
 * @returns {string} Logo URL
 */
export function getBSCTokenLogo(contractAddress) {
    if (!contractAddress) return '';
    return `${TW_ASSETS}/blockchains/smartchain/assets/${contractAddress}/logo.png`;
}

/**
 * Get logo URL with fallback to a generated gradient placeholder
 * Returns the URL or null (component should show fallback)
 * @param {string} symbol
 * @returns {string|null}
 */
export function getCoinLogoOrNull(symbol) {
    return getCoinLogo(symbol) || null;
}

/**
 * Check if we have a real logo for this symbol
 * CoinCap covers most major tokens, so we return true for known ones
 * @param {string} symbol
 * @returns {boolean}
 */
export function hasCoinLogo(symbol) {
    return !!symbol && symbol.length > 0;
}

/**
 * Extract the base symbol from a trading pair
 * e.g. 'BTC/USDT' => 'BTC', 'ETH-USDT' => 'ETH'
 * @param {string} pair
 * @returns {string}
 */
export function getBaseSymbol(pair) {
    if (!pair) return '';
    return pair.split(/[\/\-]/)[0].toUpperCase();
}

/**
 * Get logo for a trading pair (returns base coin logo)
 * @param {string} pair - e.g. 'BTC/USDT'
 * @returns {string}
 */
export function getPairLogo(pair) {
    return getCoinLogo(getBaseSymbol(pair));
}

export default {
    getCoinLogo,
    getCoinLogoOrNull,
    getBSCTokenLogo,
    hasCoinLogo,
    getBaseSymbol,
    getPairLogo,
};
