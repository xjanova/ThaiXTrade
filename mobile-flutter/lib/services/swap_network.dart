/// TPIX TRADE — กติกาเลือกเครือข่ายของหน้าสลับเหรียญ (ฟังก์ชันล้วน ทดสอบได้โดยไม่แตะเชน)
///
/// หน้าสลับเหรียญมีสองเครือข่ายให้ผู้ใช้เลือกเองชัด ๆ:
///   - BSC (56) ผ่าน PancakeSwap — เส้นทางเดิมทุกขั้น ไม่ผ่าน "เชนปริยาย" ของกระเป๋าเด็ดขาด
///   - TPIX Chain (4289) ผ่าน TPIX DEX — ใช้ [TpixDexService] ตัวเดียวกับหน้าเทรด
///     (quote / price impact / minOut / approve / บันทึกไม้ — ไม่มีคณิตสวอปซ้ำในไฟล์นี้)
///
/// ทุกกติกาเป็นแบบ fail-closed: อะไรไม่ชัด = ไม่เปิดโหมด TPIX หรือไม่ใส่เหรียญนั้นในรายการ
/// ผู้ใช้ยังสลับบน BSC ได้เหมือนเดิมทุกอย่าง
///
/// Developed by Xman Studio
library;

import '../models/api_models.dart';
import '../models/chain_config.dart';
import 'bsc_swap_service.dart' show SwapException;
import 'tpix_dex_service.dart';

/// เครือข่ายที่หน้าสลับเหรียญจะส่งธุรกรรมจริง
enum SwapNetwork { bsc, tpix }

/// เหรียญบนเชน TPIX ที่เลือกสลับได้ในหน้าสลับเหรียญ
class TpixSwapToken {
  /// ชื่อที่แสดง (ตัวพิมพ์ใหญ่) — ไม่ซ้ำกันในรายการ (ชื่อซ้ำถูกตัดทิ้งตั้งแต่สร้างรายการ)
  final String symbol;

  /// ที่อยู่บนเชน ตัวพิมพ์เล็ก · 0x0 = TPIX เนทีฟ
  final String address;

  /// decimals จากเซิร์ฟเวอร์ — เป็นแค่ค่าตั้งต้น ของจริงอ่านจากเชนตอน quote เสมอ
  final int decimals;

  /// โลโก้ที่แอดมินรับรอง/จากเซิร์ฟเวอร์ (null = ใช้ตัวอักษร)
  final String? logo;

  /// สกุลหลักที่ยืนยันตัวตนจากที่อยู่แล้ว (TPIX เนทีฟ / USDT ตัวจริงของทะเบียน DEX)
  /// — เท่านั้นที่ยอมให้ใช้โลโก้มาตรฐานตามชื่อ เหรียญอื่นห้ามเดาโลโก้จากชื่อ
  final bool verifiedMajor;

  const TpixSwapToken({
    required this.symbol,
    required this.address,
    required this.decimals,
    this.logo,
    this.verifiedMajor = false,
  });

  bool get native => TpixDexService.isNative(address);

  /// ตัวระบุเหรียญในรายการ — ใช้ที่อยู่ ไม่ใช้ชื่อ (ชื่อปลอมกันได้ ที่อยู่ปลอมไม่ได้)
  String get key => address;

  @override
  String toString() => 'TpixSwapToken($symbol, $address)';
}

class SwapNetworkRules {
  SwapNetworkRules._();

  /// BSC = 56 ตายตัว — ห้ามอ่านจาก default chain ของกระเป๋า/โปรไฟล์ที่ซิงก์จากเซิร์ฟเวอร์
  static const int bscChainId = 56;

  static final RegExp _addressRe = RegExp(r'^0x[0-9a-fA-F]{40}$');
  static final RegExp _thaiRe = RegExp(r'[฀-๿]');

  // ── โหมด TPIX เปิดได้ไหม ──

  /// ทะเบียนสัญญาบนเซิร์ฟเวอร์พร้อมให้สวอปบนเชน TPIX จริงไหม
  ///
  /// ต้องครบทุกข้อ: เซิร์ฟเวอร์บอก ready · chain id ตรงกับเชน TPIX ที่แอปอ่าน RPC อยู่
  /// (ไม่งั้นราคาอ่านจากเชนหนึ่งแต่ส่งธุรกรรมไปอีกเชน) · มีที่อยู่ router + WTPIX ที่ถูกรูปแบบ
  static bool tpixDexUsable(TpixDexConfig cfg) =>
      cfg.ready &&
      cfg.chainId == ChainConfig.tpix.chainId &&
      _isContract(cfg.router) &&
      _isContract(cfg.wtpix);

  /// เปิดตัวเลือก "TPIX Chain" ในหน้าสลับเหรียญได้ไหม — ต้องมีเหรียญให้เลือกอย่างน้อยสองตัว
  static bool tpixModeAvailable(TpixDexConfig cfg, List<TpixSwapToken> tokens) =>
      tpixDexUsable(cfg) && tokens.length >= 2;

  /// chain id ที่ธุรกรรมของเครือข่ายนี้ต้องลง — null = ห้ามส่ง (โหมด TPIX ยังไม่พร้อม)
  static int? chainIdFor(SwapNetwork network, TpixDexConfig cfg) {
    switch (network) {
      case SwapNetwork.bsc:
        return bscChainId;
      case SwapNetwork.tpix:
        return tpixDexUsable(cfg) ? cfg.chainId : null;
    }
  }

  // ── รายการเหรียญโหมด TPIX ──

  /// สร้างรายการเหรียญบนเชน TPIX จากคู่เทรดของเซิร์ฟเวอร์
  ///
  /// [serverPairs] = /market/pairs (ConfigProvider) — รับเฉพาะคู่บนเชน TPIX จริง
  ///   (networkChainId ตรงกับทะเบียน DEX · execution_mode = onchain · เปิดใช้งาน)
  ///   ⚠️ `chainId` ของ TradingPairInfo คือ PK ของตาราง chains ไม่ใช่ chain id — ห้ามใช้เทียบ
  /// [verifiedPairs] = คู่บนเชนที่ MarketProvider ตรวจแล้วจาก /dex/pairs
  ///   (ขาจริง · ชื่อไม่กำกวม · ไม่ชนกับคู่ตลาดหลัก) — กติกาเดียวกับหน้าเทรด:
  ///   ชื่อคู่ต้องอยู่ในชุดนี้ และที่อยู่สองขาต้องตรงกัน (ยกเว้น TPIX-USDT ที่ตรวจกับทะเบียนเอง)
  /// [approvedLogo] = โลโก้จาก token list ที่แอดมินรับรอง (มาก่อนโลโก้จากคู่)
  static List<TpixSwapToken> buildTpixTokens({
    required TpixDexConfig dex,
    required List<TradingPairInfo> serverPairs,
    required Map<String, DexPairInfo> verifiedPairs,
    String? Function(String address)? approvedLogo,
  }) {
    if (!tpixDexUsable(dex)) return const [];

    final usdt = _normAddress(dex.usdt);
    final wtpix = _normAddress(dex.wtpix);
    final byKey = <String, TpixSwapToken>{};
    final coveredSymbols = <String>{};

    void addLeg(String asset, String address, int decimals, String? pairLogo) {
      final symbol = asset.toUpperCase();
      final key = TpixDexService.isNative(address)
          ? TpixDexService.nativeAddress
          : address;
      final isMajor = (symbol == 'TPIX' && key == TpixDexService.nativeAddress) ||
          (symbol == 'USDT' && key == usdt);
      byKey.putIfAbsent(
        key,
        () => TpixSwapToken(
          symbol: symbol,
          address: key,
          decimals: decimals,
          logo: approvedLogo?.call(key) ?? pairLogo,
          verifiedMajor: isMajor,
        ),
      );
    }

    bool legsOk(String baseAsset, String? baseAddr, int baseDec,
        String quoteAsset, String? quoteAddr, int quoteDec) {
      if (baseAddr == null || quoteAddr == null) return false;
      if (baseAsset.toUpperCase() == quoteAsset.toUpperCase()) return false;
      return _legOk(baseAsset, baseAddr, baseDec, usdt: usdt, wtpix: wtpix) &&
          _legOk(quoteAsset, quoteAddr, quoteDec, usdt: usdt, wtpix: wtpix);
    }

    // 1) คู่จาก /market/pairs ที่ผ่านกติกาเดียวกับหน้าเทรด
    for (final p in serverPairs) {
      if (p.networkChainId != dex.chainId || !p.isOnchain || !p.isActive) {
        continue;
      }
      final symbol = p.symbol.toUpperCase();
      final verified = verifiedPairs[symbol];
      // ชื่อคู่ที่ไม่อยู่ในชุดที่ตรวจแล้ว = ชนกับคู่ตลาดหลัก/กำกวม — ห้ามพาไปเทรดบน DEX
      if (verified == null && symbol != 'TPIX-USDT') continue;

      final baseAddr = _normLeg(p.baseAddress);
      final quoteAddr = _normLeg(p.quoteAddress);
      if (verified != null &&
          (baseAddr != _normLeg(verified.baseAddress) ||
              quoteAddr != _normLeg(verified.quoteAddress))) {
        continue; // ชื่อเดียวกันแต่คนละเหรียญ
      }
      if (symbol == 'TPIX-USDT' &&
          !(baseAddr == TpixDexService.nativeAddress &&
              usdt != null &&
              quoteAddr == usdt)) {
        continue; // TPIX-USDT ต้องเป็น TPIX เนทีฟกับ USDT ตัวจริงของทะเบียนเท่านั้น
      }
      if (!legsOk(p.baseAsset, baseAddr, p.baseDecimals, p.quoteAsset,
          quoteAddr, p.quoteDecimals)) {
        continue;
      }
      addLeg(p.baseAsset, baseAddr!, p.baseDecimals, p.baseLogo);
      addLeg(p.quoteAsset, quoteAddr!, p.quoteDecimals, null);
      coveredSymbols.add(symbol);
    }

    // 2) คู่ที่เพิ่งเปิดหลังแอปโหลด /market/pairs (แคชตั้งแต่ splash) — ใช้ชุดที่ตลาดตรวจแล้ว
    for (final e in verifiedPairs.entries) {
      final v = e.value;
      if (coveredSymbols.contains(e.key.toUpperCase())) continue;
      if (!v.isActive || v.chainId != dex.chainId) continue;
      final baseAddr = _normLeg(v.baseAddress);
      final quoteAddr = _normLeg(v.quoteAddress);
      if (!legsOk(v.baseAsset, baseAddr, v.baseDecimals, v.quoteAsset,
          quoteAddr, v.quoteDecimals)) {
        continue;
      }
      addLeg(v.baseAsset, baseAddr!, v.baseDecimals, v.baseLogo);
      addLeg(v.quoteAsset, quoteAddr!, v.quoteDecimals, v.quoteLogo);
    }

    // 3) ชื่อซ้ำคนละที่อยู่ = ผู้ใช้แยกไม่ออกว่าตัวไหนของจริง → ไม่แสดงทั้งหมด (fail-closed)
    final countBySymbol = <String, int>{};
    for (final t in byKey.values) {
      countBySymbol[t.symbol] = (countBySymbol[t.symbol] ?? 0) + 1;
    }
    final list = byKey.values
        .where((t) => countBySymbol[t.symbol] == 1)
        .toList()
      ..sort(_compareTokens);
    return List.unmodifiable(list);
  }

  /// คู่เริ่มต้นเมื่อเข้าโหมด TPIX: จ่าย TPIX → รับ USDT (ถ้ามี) — คืนเป็น key ของเหรียญ
  static ({String? from, String? to}) defaultTpixSelection(
      List<TpixSwapToken> tokens) {
    if (tokens.length < 2) return (from: null, to: null);
    final from = tokens.firstWhere((t) => t.native, orElse: () => tokens.first);
    final to = tokens.firstWhere(
      (t) => t.symbol == 'USDT' && t.verifiedMajor && t.key != from.key,
      orElse: () => tokens.firstWhere((t) => t.key != from.key),
    );
    return (from: from.key, to: to.key);
  }

  /// สองเหรียญนี้คือสินทรัพย์เดียวกันในสายตาของ router ไหม
  /// (TPIX เนทีฟวิ่งเป็น WTPIX ในเส้นทาง — TPIX ↔ WTPIX คือห่อ/แกะ ไม่ใช่สวอป)
  static bool sameDexAsset(TpixSwapToken a, TpixSwapToken b, TpixDexConfig cfg) {
    final wtpix = _normAddress(cfg.wtpix);
    String routed(TpixSwapToken t) => t.native ? (wtpix ?? t.key) : t.key;
    return routed(a) == routed(b);
  }

  // ── "ใช้สูงสุด" ──

  /// ยอดสูงสุดที่จ่ายได้จากยอดคงเหลือ (หน่วยเล็กสุด) — เหลือค่าแก๊สไว้เฉพาะเมื่อจำเป็น
  ///
  /// - โทเคน ERC-20: ใช้ได้เต็มยอด (แก๊สจ่ายด้วยเหรียญเนทีฟ ไม่ได้หักจากโทเคน)
  /// - เหรียญเนทีฟบนเชนที่ค่าแก๊สเป็น 0 (เชน TPIX): ใช้ได้เต็มยอดเช่นกัน
  /// - เหรียญเนทีฟบนเชนที่มีค่าแก๊ส: หัก [gasReserveWei] ไว้ (ติดลบ = 0)
  static BigInt maxSpendableWei({
    required BigInt balanceWei,
    required bool native,
    required bool gasless,
    BigInt? gasReserveWei,
  }) {
    if (balanceWei <= BigInt.zero) return BigInt.zero;
    if (!native || gasless) return balanceWei;
    final left = balanceWei - (gasReserveWei ?? BigInt.zero);
    return left > BigInt.zero ? left : BigInt.zero;
  }

  // ── ข้อความผิดพลาดจากกระเป๋า ──

  /// แปลง [WalletProvider.error] เป็นข้อความตามภาษาของแอป
  ///
  /// ข้อความของกระเป๋าส่วนใหญ่เป็นภาษาไทยล้วน — ผู้ใช้ภาษาอังกฤษต้องไม่เห็นภาษาไทยโผล่มา
  /// และห้ามโชว์ข้อความดิบแบบ `Exception: ...` เด็ดขาด → ใช้ [fallback] สองภาษาแทน
  static String walletErrorText(
      String? walletError, bool isThai, SwapException fallback) {
    final e = walletError?.trim();
    if (e == null || e.isEmpty) return fallback.message(isThai);
    if (e.contains('Exception') || e.contains('Error:')) {
      return fallback.message(isThai);
    }
    final hasThai = _thaiRe.hasMatch(e);
    return hasThai == isThai ? e : fallback.message(isThai);
  }

  // ── ภายใน ──

  static bool _isContract(String? address) {
    final a = _normAddress(address);
    return a != null && !TpixDexService.isNative(a);
  }

  /// ที่อยู่รูปแบบถูก → ตัวพิมพ์เล็ก · ผิดรูปแบบ/ว่าง → null
  static String? _normAddress(String? address) {
    final a = address?.trim();
    if (a == null || !_addressRe.hasMatch(a)) return null;
    return a.toLowerCase();
  }

  /// ที่อยู่ขาของคู่: ว่าง/null = TPIX เนทีฟ (ตามที่ฐานข้อมูลเก็บ) · ผิดรูปแบบ = null (ทิ้งคู่)
  static String? _normLeg(String? address) {
    final a = address?.trim() ?? '';
    if (a.isEmpty || TpixDexService.isNative(a)) {
      return TpixDexService.nativeAddress;
    }
    return _normAddress(a);
  }

  /// ขาของคู่เป็นของจริงตามชื่อไหม — ชื่อสกุลหลักต้องตรงที่อยู่ในทะเบียน
  static bool _legOk(String asset, String address, int decimals,
      {required String? usdt, required String? wtpix}) {
    final symbol = asset.toUpperCase();
    if (!DexPairInfo.isSafeAsset(symbol)) return false;
    if (decimals < 0 || decimals > 36) return false;
    final native = TpixDexService.isNative(address);
    switch (symbol) {
      case 'TPIX':
        return native;
      case 'WTPIX':
        // ห่อ/แกะไม่ใช่สวอป — ผู้ใช้สลับด้วย TPIX เนทีฟเสมอ (router จัดการ WTPIX ให้เอง)
        return false;
      case 'USDT':
        return usdt != null && address == usdt;
      default:
        // เหรียญอื่นต้องเป็นสัญญาจริง และห้ามเป็น WTPIX ที่ตั้งชื่อใหม่
        return !native && address != wtpix;
    }
  }

  static int _rank(TpixSwapToken t) {
    if (t.native) return 0;
    if (t.symbol == 'USDT' && t.verifiedMajor) return 1;
    return 2;
  }

  static int _compareTokens(TpixSwapToken a, TpixSwapToken b) {
    final r = _rank(a).compareTo(_rank(b));
    return r != 0 ? r : a.symbol.compareTo(b.symbol);
  }
}
