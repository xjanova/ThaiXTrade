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
  // ชื่อคู่มาจากข้อมูลบนเชน — encode เสมอ ห้ามต่อสตริงดิบเข้า path
  static String dexTicker(String symbol) =>
      '/dex/ticker/${Uri.encodeComponent(symbol)}';
  static String dexKlines(String symbol) =>
      '/dex/klines/${Uri.encodeComponent(symbol)}';
  static String dexOrderbook(String symbol) =>
      '/dex/orderbook/${Uri.encodeComponent(symbol)}';
  static String dexTrades(String symbol) =>
      '/dex/trades/${Uri.encodeComponent(symbol)}';

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

  // ── TPIX Price ──
  // ราคา/ความลึก/กราฟของคู่บนเชน TPIX มาจาก /dex/* (ดูด้านบน)
  // /tpix/orderbook (สมุดภายในที่ว่างตลอด) และ /tpix/klines (สร้างแท่งตัวอย่างเองเมื่อไม่มีข้อมูล)
  // เลิกใช้แล้ว · /app/check-update ไม่มีอยู่จริง (ตัวเช็กอัปเดตใช้ /app/update-check)
  static const String tpixPrice = '/tpix/price';
}
