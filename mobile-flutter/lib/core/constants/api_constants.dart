/// TPIX TRADE — API Constants
/// Base URLs, endpoints, timeouts
///
/// Developed by Xman Studio

class ApiConstants {
  ApiConstants._();

  static const String baseUrl = 'https://tpix.online/api/v1';
  static const Duration timeout = Duration(seconds: 15);

  // ── Config (from backend) ──
  static const String fees = '/fees';
  static const String chains = '/chains';
  static String chainTokens(int chainId) => '/chains/$chainId/tokens';

  // ── Trading Pairs ──
  static const String pairs = '/market/pairs';

  // ── TPIX DEX (AMM บนเชน 4289) ──
  // config บอกที่อยู่สัญญา + ready — แอปต้อง fail-closed เมื่อ ready = false
  static const String dexConfig = '/dex/config';
  static const String dexPairs = '/dex/pairs';
  static String dexTicker(String symbol) => '/dex/ticker/$symbol';
  static String dexKlines(String symbol) => '/dex/klines/$symbol';
  static String dexOrderbook(String symbol) => '/dex/orderbook/$symbol';
  static String dexTrades(String symbol) => '/dex/trades/$symbol';

  // ── Wallet ──
  static const String walletConnect = '/wallet/connect';
  static const String walletSign = '/wallet/sign';
  static const String walletVerify = '/wallet/verify-signature';
  static const String walletBalances = '/wallet/balances';
  static const String walletProfile = '/wallet/profile';
  static const String walletTransactions = '/wallet/transactions';

  // ── Trading ──
  static const String tradingOrder = '/trading/order';
  static const String tradingOrders = '/trading/orders';
  static String tradingOrderCancel(String id) => '/trading/order/$id';
  static const String tradingHistory = '/trading/history';

  // ── Swap ──
  static const String swapQuote = '/swap/quote';
  static const String swapExecute = '/swap/execute';
  // fee-info = ที่อยู่ fee collector (fail-closed: ไม่มี = ห้ามเทรด)
  static const String tradingFeeInfo = '/trading/fee-info';

  // ── Market Data ──
  static const String marketTickers = '/market/tickers';
  static String marketOrderbook(String symbol) => '/market/orderbook/$symbol';
  static String marketKlines(String symbol) => '/market/klines/$symbol';

  // ── TPIX Price + Internal Order Book ──
  // ใช้สำหรับ TPIX-USDT pair เท่านั้น (ไม่ผ่าน Binance)
  static const String tpixPrice = '/tpix/price';
  static const String tpixOrderbook = '/tpix/orderbook';
  static const String tpixTrades = '/tpix/trades';
  static const String tpixKlines = '/tpix/klines';

  // ── Update ──
  static const String updateCheck = '/app/check-update';
}
