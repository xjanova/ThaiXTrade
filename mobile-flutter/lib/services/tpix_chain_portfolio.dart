/// TPIX TRADE — ยอดเหรียญบนเชน TPIX อ่านตรงจากเชน (ไม่พึ่งเซิร์ฟเวอร์)
///
/// ทำไมต้องมี: เดิมหน้า Portfolio/Home เอายอดจาก /api/v1/wallet/balances อย่างเดียว
///   - endpoint นั้นตอบ 403 WALLET_NOT_VERIFIED ถ้ากระเป๋ายังไม่เซ็นยืนยัน
///   - และถามตามเชนที่แอปเลือกอยู่ (มักเป็น 56) → ไม่เห็นเหรียญบนเชน TPIX เลย
///   ผลคือผู้ใช้ที่ถือ TPIX/USDT บนเชน 4289 เห็น "ยังไม่มีสินทรัพย์" และมูลค่ารวม $0
///
/// ตอนนี้: อ่าน TPIX (native) + WTPIX + USDT ของเชน TPIX + โทเคนในคู่ของ /dex/pairs
/// ตรงจาก RPC เสมอ (เชน 4289 ไม่ว่าแอปเลือกเชนไหน) แล้วค่อยรวมกับข้อมูลเซิร์ฟเวอร์ถ้ามี
///
/// กติกา "ห้ามโชว์ 0 เพราะอ่านพลาด": อ่านเหรียญไหนไม่สำเร็จ = คงยอดที่อ่านได้ล่าสุดไว้
/// ไม่เคยอ่านได้เลย = ไม่แสดงแถวนั้น (ไม่รู้ ≠ ศูนย์)
///
/// Developed by Xman Studio
library;

import 'package:flutter/foundation.dart';

import '../models/api_models.dart';
import '../models/chain_config.dart';
import 'api_service.dart';
import 'tpix_dex_service.dart';

/// เหรียญนี้คิดมูลค่าเป็น USD อย่างไร
enum TpixPriceKind {
  /// TPIX / WTPIX — ราคาจาก /tpix/price หรือพูล
  tpix,

  /// USDT ของเชน TPIX — 1 USD
  usd,

  /// ยังไม่รู้ราคา — แสดงจำนวนแต่ไม่นับเข้ามูลค่ารวม
  unknown,
}

/// โทเคนบนเชน TPIX ที่จะอ่านยอด
class TpixChainToken {
  final String address; // 0x0 = native TPIX
  final String symbol;
  final int decimals;
  final String? logo;
  final TpixPriceKind priceKind;

  const TpixChainToken({
    required this.address,
    required this.symbol,
    required this.decimals,
    this.logo,
    this.priceKind = TpixPriceKind.unknown,
  });

  bool get isNative => TpixDexService.isNative(address);

  /// กุญแจเดียวกับ [TokenBalance.key] — ใช้รวมกับยอดจากเซิร์ฟเวอร์ได้ตรงตัว
  String get key =>
      '${ChainConfig.tpix.chainId}:${isNative ? 'native' : address.toLowerCase()}';
}

class TpixChainPortfolio {
  final TpixDexService _dex;
  final ApiService _api;

  TpixChainPortfolio({TpixDexService? dex, ApiService? api})
      : _dex = dex ?? TpixDexService(),
        _api = api ?? ApiService();

  String? _owner;
  Map<String, TokenBalance> _last = {};
  double? _lastTpixUsd;

  List<Map<String, dynamic>>? _pairs;
  DateTime? _pairsAt;

  /// ราคา TPIX ล่าสุดที่รู้ (USD) — null = ยังไม่เคยได้
  double? get tpixUsd => _lastTpixUsd;

  /// ล้างทุกอย่าง — เรียกตอนตัดการเชื่อมต่อ/เปลี่ยนกระเป๋า
  void reset() {
    _owner = null;
    _last = {};
  }

  /// อ่านยอดบนเชน TPIX ของ [owner] — คืนรายการที่รวมค่าล่าสุดที่อ่านได้แล้ว
  Future<List<TokenBalance>> load(String owner) async {
    if (_owner?.toLowerCase() != owner.toLowerCase()) {
      _owner = owner;
      _last = {};
    }

    final tokens = await _tokenList();
    final priceFuture = _tpixPrice();
    final reads = await Future.wait(tokens.map((t) => _dex.getBalance(
          address: t.address,
          walletAddress: owner,
          decimals: t.decimals,
        )));
    final price = await priceFuture;

    // ผู้ใช้เปลี่ยนกระเป๋าระหว่างรอ — ทิ้งผลของกระเป๋าเก่า
    if (_owner?.toLowerCase() != owner.toLowerCase()) return const [];

    _last = applyReads(
      previous: _last,
      tokens: tokens,
      reads: reads,
      tpixUsd: price,
    );
    return ordered(_last);
  }

  /// เหรียญที่จะอ่าน: TPIX → USDT → WTPIX → โทเคนอื่นในคู่บนเชน (ไม่ซ้ำ)
  Future<List<TpixChainToken>> _tokenList() async {
    final cfg = await _dex.loadConfig();
    final pairs = await _dexPairs();

    final tokens = <String, TpixChainToken>{};
    void add(TpixChainToken t) {
      final k = t.isNative ? 'native' : t.address.toLowerCase();
      tokens.putIfAbsent(k, () => t);
    }

    add(const TpixChainToken(
      address: TpixDexService.nativeAddress,
      symbol: 'TPIX',
      decimals: 18,
      priceKind: TpixPriceKind.tpix,
    ));

    final usdt = cfg.usdt;
    final wtpix = cfg.wtpix;
    if (cfg.ready && usdt != null) {
      final tok = await _verified(usdt, 'USDT', 6, TpixPriceKind.usd,
          logo: _logoFor(pairs, usdt));
      if (tok != null) add(tok);
    }
    if (cfg.ready && wtpix != null) {
      final tok = await _verified(wtpix, 'WTPIX', 18, TpixPriceKind.tpix);
      if (tok != null) add(tok);
    }

    for (final p in pairs) {
      for (final side in const ['base', 'quote']) {
        final addr = p['${side}_address'] as String?;
        final sym = p['${side}_asset'] as String?;
        if (addr == null || sym == null || TpixDexService.isNative(addr)) {
          continue;
        }
        if (tokens.containsKey(addr.toLowerCase())) continue;
        final kind = (usdt != null && addr.toLowerCase() == usdt.toLowerCase())
            ? TpixPriceKind.usd
            : TpixPriceKind.unknown;
        final tok = await _verified(
          addr,
          sym,
          (p['${side}_decimals'] as num?)?.toInt() ?? 18,
          kind,
          logo: p['${side}_logo'] as String?,
        );
        if (tok != null) add(tok);
      }
    }
    return tokens.values.toList();
  }

  /// decimals ต้องมาจากเชน — ค่าจาก API ผิดทีเดียวยอดเพี้ยนเป็นล้านเท่า
  Future<TpixChainToken?> _verified(
    String address,
    String symbol,
    int fallbackDecimals,
    TpixPriceKind kind, {
    String? logo,
  }) async {
    try {
      final v = await _dex.verifyToken(
          symbol: symbol, address: address, fallbackDecimals: fallbackDecimals);
      return TpixChainToken(
        address: address,
        symbol: symbol,
        decimals: v.decimals,
        logo: logo,
        priceKind: kind,
      );
    } catch (_) {
      // ตรวจไม่ได้รอบนี้ — ข้ามไปก่อน ยอดเดิมของเหรียญนี้ยังคงอยู่ใน _last
      return null;
    }
  }

  String? _logoFor(List<Map<String, dynamic>> pairs, String address) {
    final a = address.toLowerCase();
    for (final p in pairs) {
      if ((p['quote_address'] as String?)?.toLowerCase() == a) {
        return p['quote_logo'] as String?;
      }
      if ((p['base_address'] as String?)?.toLowerCase() == a) {
        return p['base_logo'] as String?;
      }
    }
    return null;
  }

  /// /dex/pairs แคช 5 นาที — ขอไม่สำเร็จใช้ชุดเดิม
  Future<List<Map<String, dynamic>>> _dexPairs() async {
    final fresh = _pairsAt != null &&
        DateTime.now().difference(_pairsAt!) < const Duration(minutes: 5);
    if (fresh && _pairs != null) return _pairs!;
    try {
      final list = await _api.getDexPairs();
      if (list != null) {
        _pairs = list
            .where((p) =>
                p['is_active'] != false &&
                ((p['chain_id'] as num?)?.toInt() ?? ChainConfig.tpix.chainId) ==
                    ChainConfig.tpix.chainId)
            .toList();
        _pairsAt = DateTime.now();
      }
    } catch (e) {
      debugPrint('TpixChainPortfolio pairs: ${e.runtimeType}');
    }
    return _pairs ?? const [];
  }

  /// ราคา TPIX: เซิร์ฟเวอร์ก่อน → พูลบนเชน → ค่าล่าสุดที่เคยได้
  Future<double?> _tpixPrice() async {
    try {
      final p = await _api.getTpixPrice();
      if (p != null && p.price > 0) return _lastTpixUsd = p.price;
    } catch (_) {}
    try {
      final pool = await _dex.tpixUsdtPoolPrice();
      if (pool != null && pool > 0) return _lastTpixUsd = pool;
    } catch (_) {}
    return _lastTpixUsd;
  }

  // ── ฟังก์ชันล้วน (ทดสอบได้โดยไม่แตะเชน) ──

  /// ใส่ผลอ่านรอบนี้ทับของเดิม — อ่านไม่ได้ (null) = คงค่าเดิม
  ///
  /// native แสดงเสมอแม้เป็น 0 (ตรงกับเซิร์ฟเวอร์) · โทเคนอื่นแสดงเฉพาะเมื่อมียอด
  /// [tpixUsd] = null หมายถึงไม่เคยรู้ราคาเลย (ผู้เรียกส่งราคาล่าสุดมาแทนเสมอ)
  /// → แถว TPIX ไม่มีมูลค่า USD (แสดง —) ดีกว่าเดาเป็น 0
  static Map<String, TokenBalance> applyReads({
    required Map<String, TokenBalance> previous,
    required List<TpixChainToken> tokens,
    required List<double?> reads,
    double? tpixUsd,
  }) {
    assert(tokens.length == reads.length);
    final out = Map<String, TokenBalance>.of(previous);
    final kinds = <String, TpixPriceKind>{};

    for (var i = 0; i < tokens.length && i < reads.length; i++) {
      final t = tokens[i];
      kinds[t.key] = t.priceKind;
      final r = reads[i];
      if (r == null) continue; // อ่านพลาด — คงยอดเดิม (หรือไม่มีแถวถ้าไม่เคยอ่านได้)
      if (!t.isNative && r <= 0) {
        out.remove(t.key);
        continue;
      }
      out[t.key] = TokenBalance(
        symbol: t.symbol,
        name: '${t.symbol} · ${ChainConfig.tpix.name}',
        balance: r,
        contractAddress: t.isNative ? null : t.address,
        usdValue: usdValueFor(t.priceKind, r, tpixUsd),
        logo: t.logo,
        chainId: ChainConfig.tpix.chainId,
        decimals: t.decimals,
        isNative: t.isNative,
      );
    }

    // ยอดที่คงไว้จากรอบก่อน — ราคาใหม่มาแล้วก็คิดมูลค่าใหม่ให้ด้วย
    if (tpixUsd != null) {
      for (final e in out.entries.toList()) {
        final kind = kinds[e.key];
        if (kind == null) continue;
        final usd = usdValueFor(kind, e.value.balance, tpixUsd);
        if (usd != null && usd != e.value.usdValue) {
          out[e.key] = e.value.copyWith(usdValue: usd);
        }
      }
    }
    return out;
  }

  static double? usdValueFor(TpixPriceKind kind, double balance, double? tpixUsd) {
    switch (kind) {
      case TpixPriceKind.usd:
        return balance;
      case TpixPriceKind.tpix:
        return tpixUsd != null && tpixUsd > 0 ? balance * tpixUsd : null;
      case TpixPriceKind.unknown:
        return null;
    }
  }

  /// เรียง: TPIX ก่อน แล้วตามมูลค่า USD มาก → น้อย แล้วตามชื่อ
  static List<TokenBalance> ordered(Map<String, TokenBalance> byKey) {
    final list = byKey.values.toList()
      ..sort((a, b) {
        if (a.isNative != b.isNative) return a.isNative ? -1 : 1;
        final ua = a.usdValue ?? -1;
        final ub = b.usdValue ?? -1;
        if (ua != ub) return ub.compareTo(ua);
        return a.symbol.compareTo(b.symbol);
      });
    return list;
  }

  /// รวมยอดจากเชน TPIX กับยอดจากเซิร์ฟเวอร์ — ไม่ซ้ำกัน
  ///
  /// ยอดบนเชน TPIX ที่อ่านเองสดกว่าและไม่ติด 403 → ชนะเสมอเมื่อเป็นเหรียญเดียวกัน
  /// ยอดเชนอื่น (เช่น BSC) จากเซิร์ฟเวอร์เก็บไว้ทั้งหมด
  static List<TokenBalance> merge({
    required List<TokenBalance> onchain,
    required List<TokenBalance> server,
  }) {
    final seen = <String>{for (final b in onchain) b.key};
    return [
      ...onchain,
      for (final b in server)
        if (seen.add(b.key)) b,
    ];
  }
}
