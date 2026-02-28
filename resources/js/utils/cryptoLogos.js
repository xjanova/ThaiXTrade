/**
 * TPIX TRADE - Crypto Logo Helper
 * Real coin logos from CoinGecko CDN (trusted source)
 * Developed by Xman Studio
 */

const COINGECKO_CDN = 'https://assets.coingecko.com/coins/images';

/**
 * Map of coin symbols to their CoinGecko image paths
 * Format: { symbol: [coingecko_id, filename] }
 */
const COIN_MAP = {
    // Major coins
    BTC: [1, 'bitcoin'],
    ETH: [279, 'ethereum'],
    BNB: [825, 'bnb-icon2_2x'],
    SOL: [4128, 'solana'],
    XRP: [44, 'xrp-symbol-white-128'],
    ADA: [975, 'cardano'],
    DOGE: [5, 'dogecoin'],
    DOT: [12171, 'polkadot'],
    MATIC: [4713, 'polygon'],
    AVAX: [12559, 'Avalanche_Circle_RedWhite_Trans'],
    USDT: [325, 'Tether'],
    USDC: [6319, 'usdc'],
    DAI: [9956, 'dai-multi-collateral'],
    LINK: [877, 'chainlink-new-logo'],
    UNI: [12504, 'uniswap-logo'],
    AAVE: [12645, 'aave-token'],
    LTC: [2, 'litecoin'],
    TRX: [1094, 'tron-logo'],
    ATOM: [1481, 'cosmos_hub'],
    NEAR: [10365, 'near'],
    ARB: [16547, 'photo_2023-03-29_21.33.25'],
    OP: [25244, 'Optimism'],
    APT: [26455, 'aptos'],
    SUI: [28453, 'sui'],
    FTM: [4001, 'fantom'],
    ALGO: [4030, 'algorand'],

    // Meme coins
    PEPE: [29850, 'pepe-token'],
    BONK: [28600, 'bonk'],
    WIF: [33566, 'dogwifhat'],
    FLOKI: [16746, 'PNG_image'],
    SHIB: [11939, 'shiba'],

    // DeFi tokens
    CRV: [12124, 'curve-dao-token'],
    MKR: [1364, 'maker'],
    COMP: [12124, 'compound-governance-token'],
    SUSHI: [12271, 'sushi'],
    CAKE: [7186, 'pancakeswap-token'],
    '1INCH': [8104, '1inch'],
};

/**
 * Get the logo URL for a given coin symbol
 * @param {string} symbol - Coin symbol (e.g. 'BTC', 'ETH')
 * @param {'small'|'thumb'|'standard'} size - Image size
 * @returns {string} Logo URL or empty string
 */
export function getCoinLogo(symbol, size = 'small') {
    const upper = symbol?.toUpperCase()?.replace(/\/.*$/, '') || '';
    const coin = COIN_MAP[upper];

    if (!coin) return '';

    const [id, filename] = coin;
    return `${COINGECKO_CDN}/${id}/${size}/${filename}.png`;
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
 * @param {string} symbol
 * @returns {boolean}
 */
export function hasCoinLogo(symbol) {
    const upper = symbol?.toUpperCase()?.replace(/\/.*$/, '') || '';
    return !!COIN_MAP[upper];
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
 * @param {'small'|'thumb'|'standard'} size
 * @returns {string}
 */
export function getPairLogo(pair, size = 'small') {
    return getCoinLogo(getBaseSymbol(pair), size);
}

export default {
    getCoinLogo,
    getCoinLogoOrNull,
    hasCoinLogo,
    getBaseSymbol,
    getPairLogo,
};
