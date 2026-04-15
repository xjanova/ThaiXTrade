/// TPIX TRADE — Crypto Logo Helper
/// Multi-source fallback: CoinCap → CryptoLogos.cc → Trust Wallet
/// Port จาก resources/js/utils/cryptoLogos.js
///
/// Developed by Xman Studio

class CryptoLogos {
  CryptoLogos._();

  // Primary: spothq curated icon set — 600+ coins, GitHub raw (never down)
  static const _spothq =
      'https://raw.githubusercontent.com/spothq/cryptocurrency-icons/master/128/color';
  static const _coincapCdn = 'https://assets.coincap.io/assets/icons';
  static const _cryptoLogos = 'https://cryptologos.cc/logos';
  static const _twAssets =
      'https://raw.githubusercontent.com/trustwallet/assets/master';

  /// CryptoLogos.cc slug mappings
  static const _slugMap = <String, String>{
    'BTC': 'bitcoin-btc-logo.png',
    'ETH': 'ethereum-eth-logo.png',
    'BNB': 'bnb-bnb-logo.png',
    'USDT': 'tether-usdt-logo.png',
    'USDC': 'usd-coin-usdc-logo.png',
    'SOL': 'solana-sol-logo.png',
    'XRP': 'xrp-xrp-logo.png',
    'ADA': 'cardano-ada-logo.png',
    'DOGE': 'dogecoin-doge-logo.png',
    'DOT': 'polkadot-new-dot-logo.png',
    'MATIC': 'polygon-matic-logo.png',
    'AVAX': 'avalanche-avax-logo.png',
    'LINK': 'chainlink-link-logo.png',
    'UNI': 'uniswap-uni-logo.png',
    'ATOM': 'cosmos-atom-logo.png',
    'LTC': 'litecoin-ltc-logo.png',
    'FIL': 'filecoin-fil-logo.png',
    'APT': 'aptos-apt-logo.png',
    'ARB': 'arbitrum-arb-logo.png',
    'OP': 'optimism-ethereum-op-logo.png',
    'NEAR': 'near-protocol-near-logo.png',
    'AAVE': 'aave-aave-logo.png',
    'CAKE': 'pancakeswap-cake-logo.png',
    'TRX': 'tron-trx-logo.png',
    'SHIB': 'shiba-inu-shib-logo.png',
    'DAI': 'multi-collateral-dai-dai-logo.png',
    'CRO': 'cronos-cro-logo.png',
    'ALGO': 'algorand-algo-logo.png',
    'FTM': 'fantom-ftm-logo.png',
    'MANA': 'decentraland-mana-logo.png',
    'SAND': 'the-sandbox-sand-logo.png',
    'AXS': 'axie-infinity-axs-logo.png',
    '1INCH': '1inch-1inch-logo.png',
    'SUSHI': 'sushiswap-sushi-logo.png',
    'COMP': 'compound-comp-logo.png',
    'MKR': 'maker-mkr-logo.png',
    'SNX': 'synthetix-network-token-snx-logo.png',
    'GRT': 'the-graph-grt-logo.png',
    'ENJ': 'enjin-coin-enj-logo.png',
    'ZEC': 'zcash-zec-logo.png',
    'PEPE': 'pepe-pepe-logo.png',
    'WIF': 'dogwifhat-wif-logo.png',
    'SUI': 'sui-sui-logo.png',
    'SEI': 'sei-sei-logo.png',
    'INJ': 'injective-inj-logo.png',
    'TIA': 'celestia-tia-logo.png',
    'RENDER': 'render-token-rndr-logo.png',
    'FET': 'fetch-ai-fet-logo.png',
    'TAO': 'bittensor-tao-logo.png',
    'BONK': 'bonk-bonk-logo.png',
    'FLOKI': 'floki-inu-floki-logo.png',
    'ETC': 'ethereum-classic-etc-logo.png',
    'XLM': 'stellar-xlm-logo.png',
    'HBAR': 'hedera-hbar-logo.png',
    'VET': 'vechain-vet-logo.png',
    'ICP': 'internet-computer-icp-logo.png',
    'RUNE': 'thorchain-rune-logo.png',
  };

  /// BSC token addresses for Trust Wallet fallback
  static const _bscAddresses = <String, String>{
    'USDT': '0x55d398326f99059fF775485246999027B3197955',
    'USDC': '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
    'ETH': '0x2170Ed0880ac9A755fd29B2688956BD959F933F8',
    'BTC': '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c',
    'CAKE': '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82',
    'DOGE': '0xbA2aE424d960c26247Dd6c32edC70B295c744C43',
    'SOL': '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF',
    'DAI': '0x1AF3F329e8BE154074D8769D1FFa4eE058B1DBc3',
    'LINK': '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD',
    'UNI': '0xBf5140A22578168FD562DCcF235E5D43A02ce9B1',
    'AAVE': '0xfb6115445Bff7b52FeB98650C87f44907E58f802',
  };

  /// Wrapped token → base symbol
  static const _symbolMap = <String, String>{
    'MATIC': 'matic',
    '1INCH': '1inch',
    'WBNB': 'bnb',
    'WETH': 'eth',
    'WBTC': 'btc',
    'WTPIX': 'tpix',
  };

  /// เป็น TPIX ecosystem token หรือไม่
  static bool isTpix(String symbol) {
    final s = symbol.toUpperCase();
    return s == 'TPIX' || s == 'WTPIX';
  }

  /// Primary logo URL (spothq GitHub — 600+ curated coins)
  static String getLogoUrl(String symbol) {
    if (symbol.isEmpty) return '';
    final upper = symbol.toUpperCase().split(RegExp(r'[/\-]')).first;

    // TPIX ใช้ local asset
    if (isTpix(upper)) return '';

    final mapped = _symbolMap[upper] ?? upper;
    return '$_spothq/${mapped.toLowerCase()}.png';
  }

  /// Fallback 1 URL (CoinCap CDN)
  static String? getFallbackUrl(String symbol) {
    if (symbol.isEmpty) return null;
    final upper = symbol.toUpperCase().split(RegExp(r'[/\-]')).first;
    if (isTpix(upper)) return null;

    final mapped = _symbolMap[upper] ?? upper;
    return '$_coincapCdn/${mapped.toLowerCase()}@2x.png';
  }

  /// Fallback 2 URL (CryptoLogos.cc → Trust Wallet BSC) — final tier
  static String? getFallback2Url(String symbol) {
    if (symbol.isEmpty) return null;
    final upper = symbol.toUpperCase().split(RegExp(r'[/\-]')).first;
    final mapped = _symbolMap[upper] ?? upper;

    // CryptoLogos.cc
    final slug = _slugMap[mapped];
    if (slug != null) return '$_cryptoLogos/$slug?v=040';

    // Trust Wallet BSC
    final addr = _bscAddresses[mapped];
    if (addr != null) {
      return '$_twAssets/blockchains/smartchain/assets/$addr/logo.png';
    }

    return null;
  }

  /// ดึง base symbol จาก pair string (เช่น "BTC-USDT" → "BTC")
  static String baseSymbol(String pair) {
    if (pair.isEmpty) return '';
    return pair.split(RegExp(r'[/\-]')).first.toUpperCase();
  }
}
