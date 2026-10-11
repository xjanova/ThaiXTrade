/// TPIX TRADE — รายการโทเคนที่แอดมินรับรองแล้วของเชน TPIX (Uniswap Token List)
///
/// ที่มา: https://tpix.online/tokenlist.json
///   {name, timestamp, version, tokens:[{chainId, address, symbol, name, decimals, logoURI}]}
/// มีเฉพาะเหรียญที่แอดมินรับรอง — ใช้เป็นแหล่งโลโก้อันดับแรก และบอกพอร์ตว่ามีเหรียญอะไร
/// บนเชนให้อ่านยอดบ้าง (เช่น IDTC ที่ซื้อจากรอบขายก่อนจะมีพูล)
///
/// ต้องทำงานได้แม้ endpoint ยังไม่เปิด (วันนี้ตอบ 404 เป็นหน้า HTML):
///   ขอไม่สำเร็จ/รูปแบบผิด = รายการว่าง (หรือคงชุดเดิม) ไม่โยน error ไม่ทำให้หน้าใดล้ม
///
/// Developed by Xman Studio
library;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

/// โทเคนหนึ่งตัวในรายการที่ผ่านการตรวจรูปแบบแล้ว
class TokenListEntry {
  final int chainId;
  final String address; // ตัวพิมพ์ตามรายการ
  final String symbol;
  final String name;
  final int decimals;

  /// https เท่านั้น — null = ไม่มีโลโก้/รูปแบบไม่ปลอดภัย
  final String? logoUri;

  const TokenListEntry({
    required this.chainId,
    required this.address,
    required this.symbol,
    required this.name,
    required this.decimals,
    this.logoUri,
  });
}

class TokenListService {
  TokenListService._();
  static final TokenListService _instance = TokenListService._();
  factory TokenListService() => _instance;

  static const String url = 'https://tpix.online/tokenlist.json';
  static const int tpixChainId = 4289;

  static final _addressRe = RegExp(r'^0x[0-9a-fA-F]{40}$');
  static final _symbolRe = RegExp(r'^[A-Za-z0-9]{1,20}$');

  final Dio _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 8),
    receiveTimeout: const Duration(seconds: 8),
    // 404 (ยังไม่เปิด) ไม่ใช่ข้อผิดพลาดของแอป — อ่านสถานะเองแล้วข้ามไป
    validateStatus: (_) => true,
    headers: {'Accept': 'application/json'},
  ));

  /// ที่อยู่ (ตัวเล็ก) → โทเคนบนเชน TPIX
  Map<String, TokenListEntry> _byAddress = const {};
  DateTime? _loadedAt;
  Future<void>? _inFlight;

  /// เคยโหลดสำเร็จอย่างน้อยหนึ่งครั้ง
  bool get loaded => _loadedAt != null;

  List<TokenListEntry> get tokens => _byAddress.values.toList(growable: false);

  TokenListEntry? byAddress(String? address) =>
      address == null ? null : _byAddress[address.toLowerCase()];

  /// โลโก้ของโทเคนจากรายการที่แอดมินรับรอง — null = ไม่อยู่ในรายการ/ไม่มีโลโก้
  String? logoFor(String? address) => byAddress(address)?.logoUri;

  /// โหลดรายการ (แคช 10 นาที · คำขอซ้อนกันได้ future เดียว) — ไม่เคยโยน error
  Future<void> refresh({bool force = false}) {
    final fresh = _loadedAt != null &&
        DateTime.now().difference(_loadedAt!) < const Duration(minutes: 10);
    if (!force && fresh) return Future.value();
    return _inFlight ??= _load().whenComplete(() => _inFlight = null);
  }

  Future<void> _load() async {
    try {
      final res = await _dio.get<dynamic>(url);
      if (res.statusCode != 200) return; // ยังไม่เปิด (404) — คงชุดเดิม
      final parsed = parse(res.data);
      if (parsed == null) return;
      _byAddress = parsed;
      _loadedAt = DateTime.now();
    } catch (e) {
      debugPrint('TokenList: ${e.runtimeType}');
    }
  }

  /// แปลงเนื้อหารายการ → เฉพาะโทเคนเชน TPIX ที่รูปแบบถูกต้อง
  /// null = ไม่ใช่ token list (เช่นหน้า HTML ของ 404)
  @visibleForTesting
  static Map<String, TokenListEntry>? parse(dynamic body) {
    if (body is! Map) return null;
    final list = body['tokens'];
    if (list is! List) return null;
    final out = <String, TokenListEntry>{};
    for (final raw in list) {
      if (raw is! Map) continue;
      final chainId = raw['chainId'];
      final address = raw['address'];
      final symbol = raw['symbol'];
      final decimals = raw['decimals'];
      if (chainId is! int || chainId != tpixChainId) continue;
      if (address is! String || !_addressRe.hasMatch(address)) continue;
      if (symbol is! String || !_symbolRe.hasMatch(symbol)) continue;
      if (decimals is! int || decimals < 0 || decimals > 36) continue;
      final name = raw['name'];
      out.putIfAbsent(
        address.toLowerCase(),
        () => TokenListEntry(
          chainId: chainId,
          address: address,
          symbol: symbol.toUpperCase(),
          name: name is String ? name : symbol,
          decimals: decimals,
          logoUri: httpsOnly(raw['logoURI']),
        ),
      );
    }
    return out;
  }

  /// URL รูปที่ยอมให้โหลด: https และมี host เท่านั้น (ไม่รับ http/data/javascript/ipfs)
  static String? httpsOnly(dynamic raw) {
    if (raw is! String) return null;
    final s = raw.trim();
    if (s.isEmpty || s.length > 512) return null;
    final uri = Uri.tryParse(s);
    if (uri == null || uri.scheme != 'https' || uri.host.isEmpty) return null;
    return s;
  }
}
