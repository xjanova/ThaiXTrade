/// TPIX TRADE — BSC Trade Token Registry (mobile)
/// แผนที่เหรียญ major → token address บน BSC สำหรับ market order ที่
/// execute จริงผ่าน PancakeSwap V2 — ต้องตรงกับฝั่งเว็บ
/// (resources/js/Config/bscTradeTokens.js) เสมอเพื่อความสอดคล้อง
///
/// ความปลอดภัย:
///  - decimals ในไฟล์นี้เป็นค่า fallback สำหรับแสดงผลเท่านั้น — ค่าจริงอ่านจาก
///    on-chain ผ่าน BscSwapService.verifyToken() ทุกครั้งก่อนเทรด
///  - onchainSymbols = symbol() ที่ยอมรับได้จาก contract จริง ถ้าไม่ตรง
///    = address ผิด → บล็อกเทรดทันที (fail-closed)
///
/// Developed by Xman Studio
library;

import 'api_models.dart';

/// Sentinel address ของเหรียญ native (BNB) — ตรงกับที่เว็บใช้
const String kNativeTokenAddress = '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE';

/// WBNB — ใช้เป็น hop กลางของ routing path
const String kWbnbAddress = '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c';

/// PancakeSwap V2 Router
const String kPancakeRouterAddress = '0x10ED43C718714eb63d5aA57B78B54704E256024E';

class BscTradeToken {
  final String symbol; // ชื่อที่แสดงในแอพ (ตรงกับ pair ใน backend)
  final String name;
  final String address;
  final int decimals; // fallback เท่านั้น — ค่าจริงอ่านจากเชน
  final bool native;
  final List<String> onchainSymbols; // symbol() บนเชนที่ยอมรับได้

  const BscTradeToken({
    required this.symbol,
    required this.name,
    required this.address,
    required this.decimals,
    this.native = false,
    this.onchainSymbols = const [],
  });
}

/// Registry — Binance-Peg / canonical addresses บน BSC mainnet (chain 56)
/// ต้อง sync กับเว็บ: บางตัว symbol บนเชนไม่ตรงชื่อที่โชว์ (POL→MATIC,
/// USDT→BSC-USD บางแหล่ง)
const Map<String, BscTradeToken> kBscTradeTokens = {
  'BNB': BscTradeToken(
    symbol: 'BNB', name: 'BNB',
    address: kNativeTokenAddress, decimals: 18, native: true,
  ),
  'USDT': BscTradeToken(
    symbol: 'USDT', name: 'Tether USD',
    address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18,
    onchainSymbols: ['USDT', 'BSC-USD'],
  ),
  'USDC': BscTradeToken(
    symbol: 'USDC', name: 'USD Coin',
    address: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', decimals: 18,
    onchainSymbols: ['USDC'],
  ),
  'BTC': BscTradeToken(
    symbol: 'BTC', name: 'Bitcoin (BTCB)',
    address: '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', decimals: 18,
    onchainSymbols: ['BTCB'],
  ),
  'ETH': BscTradeToken(
    symbol: 'ETH', name: 'Ethereum',
    address: '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', decimals: 18,
    onchainSymbols: ['ETH'],
  ),
  'SOL': BscTradeToken(
    symbol: 'SOL', name: 'Solana',
    address: '0x570A5D26f7765Ecb712C0924E4De545B89fD43dF', decimals: 18,
    onchainSymbols: ['SOL'],
  ),
  'XRP': BscTradeToken(
    symbol: 'XRP', name: 'XRP',
    address: '0x1D2F0da169ceB9fC7B3144628dB156f3F6c60dBE', decimals: 18,
    onchainSymbols: ['XRP'],
  ),
  'DOGE': BscTradeToken(
    symbol: 'DOGE', name: 'Dogecoin',
    address: '0xbA2aE424d960c26247Dd6c32edC70B295c744C43', decimals: 8,
    onchainSymbols: ['DOGE'],
  ),
  'ADA': BscTradeToken(
    symbol: 'ADA', name: 'Cardano',
    address: '0x3EE2200Efb3400fAbB9AacF31297cBdD1d435D47', decimals: 18,
    onchainSymbols: ['ADA'],
  ),
  'POL': BscTradeToken(
    symbol: 'POL', name: 'Polygon (ex-MATIC)',
    address: '0xCC42724C6683B7E57334c4E856f4c9965ED682bD', decimals: 18,
    onchainSymbols: ['MATIC', 'POL'],
  ),
  'AVAX': BscTradeToken(
    symbol: 'AVAX', name: 'Avalanche',
    address: '0x1CE0c2827e2eF14D5C4f29a091d735A204794041', decimals: 18,
    onchainSymbols: ['AVAX'],
  ),
  'DOT': BscTradeToken(
    symbol: 'DOT', name: 'Polkadot',
    address: '0x7083609fCE4d1d8Dc0C979AAb8c869Ea2C873402', decimals: 18,
    onchainSymbols: ['DOT'],
  ),
  'LINK': BscTradeToken(
    symbol: 'LINK', name: 'Chainlink',
    address: '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD', decimals: 18,
    onchainSymbols: ['LINK'],
  ),
  'UNI': BscTradeToken(
    symbol: 'UNI', name: 'Uniswap',
    address: '0xBf5140A22578168FD562DCcF235E5D43A02ce9B1', decimals: 18,
    onchainSymbols: ['UNI'],
  ),
  'LTC': BscTradeToken(
    symbol: 'LTC', name: 'Litecoin',
    address: '0x4338665CBB7B2485A8855A139b75D5e34AB0DB94', decimals: 18,
    onchainSymbols: ['LTC'],
  ),
  'TRX': BscTradeToken(
    symbol: 'TRX', name: 'TRON',
    address: '0xCE7de646e7208a4Ef112cb6ed5038FA6cC6b12e3', decimals: 6,
    onchainSymbols: ['TRX'],
  ),
  'ATOM': BscTradeToken(
    symbol: 'ATOM', name: 'Cosmos',
    address: '0x0Eb3a705fc54725037CC9e008bDede697f62F335', decimals: 18,
    onchainSymbols: ['ATOM'],
  ),
  'NEAR': BscTradeToken(
    symbol: 'NEAR', name: 'NEAR Protocol',
    address: '0x1Fa4a73a3F0133f0025378af00236f3aBDEE5D63', decimals: 18,
    onchainSymbols: ['NEAR'],
  ),
  'SHIB': BscTradeToken(
    symbol: 'SHIB', name: 'Shiba Inu',
    address: '0x2859e4544C4bB03966803b044A93563Bd2D0DD4D', decimals: 18,
    onchainSymbols: ['SHIB'],
  ),
  'PEPE': BscTradeToken(
    symbol: 'PEPE', name: 'Pepe',
    address: '0x25d887Ce7a35172C62FeBFD67a1856F20FaEbB00', decimals: 18,
    onchainSymbols: ['PEPE'],
  ),
};

/// คืน token จาก registry (ไม่ตรวจ on-chain) — null ถ้าเทรดบน BSC ไม่ได้
BscTradeToken? bscTradeToken(String? symbol) {
  if (symbol == null || symbol.isEmpty) return null;
  return kBscTradeTokens[symbol.toUpperCase()];
}

/// ทั้ง base และ quote อยู่ในทะเบียนเหรียญ BSC ของแอปไหม
///
/// ⚠️ ผ่านข้อนี้ **ยังไม่ได้แปลว่าเทรดได้** — ต้องผ่าน [bscPairGate] ด้วย (คู่ต้องเปิดอยู่บน
/// เซิร์ฟเวอร์ เป็นเชน 56 แบบ onchain และที่อยู่เหรียญตรงกับทะเบียน)
bool isBscTradablePair(String? baseSymbol, String? quoteSymbol) {
  return bscTradeToken(baseSymbol) != null && bscTradeToken(quoteSymbol) != null;
}

/// สถานะของคู่ที่จะส่งเข้า PancakeSwap — ตัดสินจากทะเบียนในแอป **และ** ทะเบียนคู่บนเซิร์ฟเวอร์
enum BscPairGate {
  /// ส่งคำสั่งได้: เซิร์ฟเวอร์ยืนยันว่าคู่นี้เปิดอยู่บน BSC แบบ onchain และที่อยู่เหรียญตรงกัน
  tradable,

  /// ไม่ใช่คู่ในทะเบียนเหรียญ BSC ของแอป (คู่บนเชน TPIX / คู่แปลก)
  notInRegistry,

  /// ทะเบียนคู่จากเซิร์ฟเวอร์ยังโหลดไม่เสร็จ — ยังตัดสินไม่ได้ ห้ามส่ง
  checking,

  /// โหลดทะเบียนคู่ไม่สำเร็จ — ยังตัดสินไม่ได้ ห้ามส่ง (ลองใหม่ได้)
  checkFailed,

  /// เซิร์ฟเวอร์ไม่มีคู่นี้ / ปิดอยู่ / อยู่เชนอื่น / เป็นคู่ดัชนี / ที่อยู่เหรียญไม่ตรงทะเบียน
  unavailable,
}

/// เหรียญ native เก็บได้ทั้ง 0x000… และ 0xEeee… — ถือเป็นตัวเดียวกัน
const List<String> _kNativeAddresses = [
  '0x0000000000000000000000000000000000000000',
  '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
];

/// ที่อยู่เหรียญเดียวกันไหม (ไม่สนตัวพิมพ์เล็ก/ใหญ่ · ค่าว่าง = ไม่ตรงเสมอ)
bool sameBscTokenAddress(String? a, String? b) {
  String norm(String? v) {
    final lower = (v ?? '').trim().toLowerCase();
    return _kNativeAddresses.contains(lower) ? 'native' : lower;
  }

  final na = norm(a);
  return na.isNotEmpty && na == norm(b);
}

/// คู่นี้ส่งเข้า PancakeSwap ได้จริงไหม — เชนของคู่ต้องมาจากเซิร์ฟเวอร์ ไม่ใช่เดาจากชื่อ
/// (กติกาเดียวกับ `isBscTradable` / `bscPairPending` ใน Trade.vue ของเว็บ)
///
/// ⚠️ เดิมดูแค่ว่า symbol อยู่ในทะเบียนของแอป — แอดมินปิดคู่บน BSC ฉุกเฉิน หรือย้ายคู่ไปเชนอื่น
///    แอปก็ยังส่งเข้า PancakeSwap อยู่ดี
///
/// [serverPair] = แถวของคู่นี้จาก `/market/pairs` (null = ไม่มีในรายการ)
/// [pairsLoaded] = เคยโหลดทะเบียนคู่สำเร็จแล้ว · [pairsLoadFailed] = ครั้งล่าสุดโหลดไม่สำเร็จ
/// ระหว่างที่ยังไม่รู้ (กำลังโหลด/โหลดพลาด) = ห้ามส่ง พร้อมเหตุผลที่บอกได้ ไม่ใช่ปล่อยผ่านเงียบ ๆ
BscPairGate bscPairGate({
  required String? baseSymbol,
  required String? quoteSymbol,
  required TradingPairInfo? serverPair,
  required bool pairsLoaded,
  bool pairsLoadFailed = false,
}) {
  final base = bscTradeToken(baseSymbol);
  final quote = bscTradeToken(quoteSymbol);
  if (base == null || quote == null) return BscPairGate.notInRegistry;

  if (serverPair == null) {
    if (!pairsLoaded) {
      return pairsLoadFailed ? BscPairGate.checkFailed : BscPairGate.checking;
    }
    return BscPairGate.unavailable;
  }

  if (!serverPair.isActive ||
      serverPair.networkChainId != 56 ||
      !serverPair.isOnchain) {
    return BscPairGate.unavailable;
  }
  if (!sameBscTokenAddress(serverPair.baseAddress, base.address) ||
      !sameBscTokenAddress(serverPair.quoteAddress, quote.address)) {
    return BscPairGate.unavailable;
  }
  return BscPairGate.tradable;
}
