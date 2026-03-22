/**
 * TPIX TRADE - Crypto Logo Helper
 * Multi-source fallback: CoinCap → CryptoLogos.cc → CoinGecko → Trust Wallet
 * Developed by Xman Studio
 */

// CDN sources (ไม่ต้อง auth, hotlink ได้)
const COINCAP_CDN = 'https://assets.coincap.io/assets/icons';
const CRYPTOLOGOS = 'https://cryptologos.cc/logos';
const TW_ASSETS = 'https://raw.githubusercontent.com/trustwallet/assets/master';

/**
 * Known symbol → CryptoLogos.cc slug mappings
 * ใช้เมื่อ CoinCap ไม่มีโลโก้
 */
const CRYPTOLOGOS_MAP = {
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
    CRO: 'cronos-cro-logo.png',
    ALGO: 'algorand-algo-logo.png',
    FTM: 'fantom-ftm-logo.png',
    MANA: 'decentraland-mana-logo.png',
    SAND: 'the-sandbox-sand-logo.png',
    AXS: 'axie-infinity-axs-logo.png',
    '1INCH': '1inch-1inch-logo.png',
    SUSHI: 'sushiswap-sushi-logo.png',
    COMP: 'compound-comp-logo.png',
    MKR: 'maker-mkr-logo.png',
    SNX: 'synthetix-network-token-snx-logo.png',
    GRT: 'the-graph-grt-logo.png',
    ENJ: 'enjin-coin-enj-logo.png',
    ZEC: 'zcash-zec-logo.png',
    PEPE: 'pepe-pepe-logo.png',
    WIF: 'dogwifhat-wif-logo.png',
    SUI: 'sui-sui-logo.png',
    SEI: 'sei-sei-logo.png',
    INJ: 'injective-inj-logo.png',
    TIA: 'celestia-tia-logo.png',
    RENDER: 'render-token-rndr-logo.png',
    FET: 'fetch-ai-fet-logo.png',
    TAO: 'bittensor-tao-logo.png',
    BONK: 'bonk-bonk-logo.png',
    FLOKI: 'floki-inu-floki-logo.png',
    ETC: 'ethereum-classic-etc-logo.png',
    XLM: 'stellar-xlm-logo.png',
    HBAR: 'hedera-hbar-logo.png',
    VET: 'vechain-vet-logo.png',
    ICP: 'internet-computer-icp-logo.png',
    RUNE: 'thorchain-rune-logo.png',
};

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
 * TPIX ecosystem logos (local assets)
 */
const LOCAL_LOGOS = {
    TPIX: '/logo.png',
};

/**
 * Special symbol mappings for CoinCap CDN
 */
const SYMBOL_MAP = {
    'MATIC': 'matic',
    '1INCH': '1inch',
    'WBNB': 'bnb',
    'WETH': 'eth',
    'WBTC': 'btc',
    'WTPIX': 'tpix',
};

/**
 * Get logo URL with multi-source fallback
 * Priority: Local → CoinCap → CryptoLogos.cc
 * @param {string} symbol
 * @returns {string}
 */
export function getCoinLogo(symbol) {
    if (!symbol) return '';
    const upper = symbol.toUpperCase().replace(/\/.*$/, '');

    // Local TPIX ecosystem logos
    if (LOCAL_LOGOS[upper]) return LOCAL_LOGOS[upper];

    // Map wrapped tokens to their base
    const mapped = SYMBOL_MAP[upper] || upper;
    const lower = mapped.toLowerCase();

    return `${COINCAP_CDN}/${lower}@2x.png`;
}

/**
 * Get a secondary fallback URL (CryptoLogos.cc)
 * Used when CoinCap image fails to load in <img @error>
 * @param {string} symbol
 * @returns {string|null}
 */
export function getCoinLogoFallback(symbol) {
    if (!symbol) return null;
    const upper = symbol.toUpperCase().replace(/\/.*$/, '');
    const mapped = SYMBOL_MAP[upper] || upper;

    // CryptoLogos.cc
    if (CRYPTOLOGOS_MAP[mapped]) {
        return `${CRYPTOLOGOS}/${CRYPTOLOGOS_MAP[mapped]}?v=040`;
    }

    // BSC Trust Wallet
    if (BSC_TOKEN_ADDRESSES[mapped]) {
        return getBSCTokenLogo(BSC_TOKEN_ADDRESSES[mapped]);
    }

    return null;
}

/**
 * Get BSC token logo from Trust Wallet Assets
 * @param {string} contractAddress
 * @returns {string}
 */
export function getBSCTokenLogo(contractAddress) {
    if (!contractAddress) return '';
    return `${TW_ASSETS}/blockchains/smartchain/assets/${contractAddress}/logo.png`;
}

/**
 * Get logo URL or null (component handles fallback)
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
    if (!symbol) return false;
    const upper = symbol.toUpperCase();
    return !!LOCAL_LOGOS[upper] || !!CRYPTOLOGOS_MAP[upper] || upper.length > 0;
}

/**
 * Extract the base symbol from a trading pair
 * @param {string} pair
 * @returns {string}
 */
export function getBaseSymbol(pair) {
    if (!pair) return '';
    return pair.split(/[\/\-]/)[0].toUpperCase();
}

/**
 * Get logo for a trading pair (returns base coin logo)
 * @param {string} pair
 * @returns {string}
 */
export function getPairLogo(pair) {
    return getCoinLogo(getBaseSymbol(pair));
}

export default {
    getCoinLogo,
    getCoinLogoFallback,
    getCoinLogoOrNull,
    getBSCTokenLogo,
    hasCoinLogo,
    getBaseSymbol,
    getPairLogo,
};
