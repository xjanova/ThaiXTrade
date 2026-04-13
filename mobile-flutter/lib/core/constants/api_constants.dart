/// TPIX TRADE — API Constants
/// Base URLs, endpoints, timeouts
///
/// Developed by Xman Studio

class ApiConstants {
  ApiConstants._();

  static const String baseUrl = 'https://tpix.online/api/v1';
  static const Duration timeout = Duration(seconds: 15);

  // ── Trading Pairs ──
  static const String pairs = '/pairs';

  // ── Wallet ──
  static const String walletConnect = '/wallet/connect';
  static const String walletSign = '/wallet/sign';
  static const String walletVerify = '/wallet/verify-signature';
  static const String walletBalances = '/wallet/balances';

  // ── Trading ──
  static const String tradingOrder = '/trading/order';
  static const String tradingOrders = '/trading/orders';
  static String tradingOrderCancel(String id) => '/trading/order/$id';
  static const String tradingHistory = '/trading/history';

  // ── Swap ──
  static const String swapQuote = '/swap/quote';

  // ── Market Data ──
  static const String marketTickers = '/market/tickers';
  static String marketOrderbook(String symbol) => '/market/orderbook/$symbol';
  static String marketKlines(String symbol) => '/market/klines/$symbol';

  // ── TPIX Price ──
  static const String tpixPrice = '/tpix/price';

  // ── Update ──
  static const String updateCheck = '/app/check-update';
}
