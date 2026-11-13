/// TPIX TRADE — API Service
/// HTTP client สำหรับสื่อสารกับ backend
/// ใช้ Dio + interceptor สำหรับ token / error handling
///
/// Developed by Xman Studio

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import '../core/constants/api_constants.dart';
import '../core/locale/app_locale.dart';
import '../models/api_models.dart';
import 'bug_reporter.dart';
import 'wallet_session.dart';

/// คำตอบของ `GET /swap/quote` แบบเก็บเหตุผล — ใช้ตัดสินแบบ fail-closed ก่อนส่งธุรกรรม
///
/// เดิมทุกความล้มเหลวยุบเหลือ null แล้วผู้เรียกเดาอัตรา 0.3% เอง — แอดมินปิดเชน/คู่ฉุกเฉิน
/// (404 `INVALID_CHAIN` / `INVALID_TOKEN` / `PAIR_DISABLED`) จึงไม่มีผลกับแอป
class SwapQuoteResponse {
  /// HTTP status — null = ยิงไม่ถึงเซิร์ฟเวอร์ (เน็ตหลุด/หมดเวลา)
  final int? status;

  /// `error.code` ของเซิร์ฟเวอร์ (เมื่อไม่สำเร็จ)
  final String? errorCode;

  /// เนื้อ `data.quote` (หรือ `data` เมื่อไม่มีชั้น quote) — null เมื่อไม่สำเร็จ
  final Map<String, dynamic>? quote;

  const SwapQuoteResponse({this.status, this.errorCode, this.quote});

  bool get ok => quote != null;
}

/// ผลของการแจ้งบันทึกไม้ (`POST /swap/execute`) หนึ่งครั้ง
enum SwapRecordOutcome {
  /// เซิร์ฟเวอร์มีแถวของไม้นี้แล้ว (200 / 201 / 202 ที่ recorded ไม่ใช่ false) — เอาออกจากคิว
  recorded,

  /// ปฏิเสธถาวร (409 ไม้ของกระเป๋าอื่น · 422 ไม้ไม่ลง/ข้อมูลผิดรูป) — ส่งกี่ครั้งก็ไม่ผ่าน เอาออกจากคิว
  rejected,

  /// 202 + recorded:false — เชนยังไม่เห็นธุรกรรม เก็บไว้ส่งใหม่
  notYetOnChain,

  /// 403 (ยังไม่ยืนยันกระเป๋า / ติดด่าน KYC) · 5xx · 404 · 429 — เก็บไว้ส่งใหม่
  retryLater,

  /// ยิงไม่ถึงเซิร์ฟเวอร์ — เก็บไว้ และหยุดส่งรายการถัดไปในรอบนี้
  offline,
}

extension SwapRecordOutcomeX on SwapRecordOutcome {
  /// เอาออกจากคิวได้ (สำเร็จ หรือปฏิเสธถาวร)
  bool get isDone =>
      this == SwapRecordOutcome.recorded || this == SwapRecordOutcome.rejected;
}

class ApiService {
  static final ApiService _instance = ApiService._();
  factory ApiService() => _instance;

  late final Dio _dio;
  String? _authToken;

  ApiService._() {
    _dio = Dio(BaseOptions(
      baseUrl: ApiConstants.baseUrl,
      connectTimeout: ApiConstants.timeout,
      receiveTimeout: ApiConstants.timeout,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        if (_authToken != null) {
          options.headers['Authorization'] = 'Bearer $_authToken';
        }
        // เซสชันกระเป๋าแบบยาว — ใช้แทนการเซ็นซ้ำทุกครั้งที่เปิดแอป
        options.headers.addAll(WalletSession.headers());
        // ภาษาของแอป — ข้อความที่เซิร์ฟเวอร์แปลให้ (SetLocale) ต้องตรงกับที่ผู้ใช้เลือก
        options.headers.addAll(AppLocale.headers());
        return handler.next(options);
      },
      onError: (error, handler) {
        debugPrint('[API] ${error.type.name}: ${error.response?.statusCode ?? '?'}');
        // path อย่างเดียว ไม่เอา query (มี wallet_address) — พอให้รู้ว่าคำขอไหนล้ม
        BugReporter.I.breadcrumb(
          'api ${error.requestOptions.method} ${error.requestOptions.path} → ${error.response?.statusCode ?? error.type.name}',
        );
        return handler.next(error);
      },
    ));
  }

  void setToken(String? token) => _authToken = token;
  void clearToken() => _authToken = null;

  // ── Generic request helpers ──

  /// คำตอบที่ไม่ใช่ JSON object (หน้า HTML ของ Cloudflare/SPA fallback, list เปล่า)
  /// = ไม่มีข้อมูล — เดิม cast ตรง ๆ แล้วโยน TypeError ทะลุไปถึงหน้าจอ
  static Map<String, dynamic>? _asMap(dynamic data) =>
      data is Map<String, dynamic> ? data : null;

  Future<Map<String, dynamic>?> _get(String path,
      {Map<String, dynamic>? queryParams}) async {
    try {
      final response = await _dio.get(path, queryParameters: queryParams);
      return _asMap(response.data);
    } on DioException catch (e) {
      debugPrint('[API] GET $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  Future<Map<String, dynamic>?> _post(String path,
      {Map<String, dynamic>? data}) async {
    try {
      final response = await _dio.post(path, data: data);
      return _asMap(response.data);
    } on DioException catch (e) {
      debugPrint('[API] POST $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  Future<Map<String, dynamic>?> _delete(String path,
      {Map<String, dynamic>? data}) async {
    try {
      final response = await _dio.delete(path, data: data);
      return _asMap(response.data);
    } on DioException catch (e) {
      debugPrint('[API] DELETE $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  Future<Map<String, dynamic>?> _put(String path,
      {Map<String, dynamic>? data}) async {
    try {
      final response = await _dio.put(path, data: data);
      return _asMap(response.data);
    } on DioException catch (e) {
      debugPrint('[API] PUT $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  /// POST ที่คืน HTTP status + เนื้อคำตอบเสมอ (ไม่ยุบความล้มเหลวเป็น null)
  ///
  /// `status == null` = ยิงไม่ถึงเซิร์ฟเวอร์ · `body == null` = คำตอบไม่ใช่ JSON object
  /// ใช้กับเส้นทางที่ผู้เรียกต้องแยกเหตุของความล้มเหลว (ค่าวางไม้ / ใบอนุญาตวางไม้)
  Future<({int? status, Map<String, dynamic>? body})> postJson(
    String path,
    Map<String, dynamic> data,
  ) async {
    try {
      final response = await _dio.post(
        path,
        data: data,
        options: Options(validateStatus: (_) => true),
      );
      return (status: response.statusCode, body: _asMap(response.data));
    } on DioException catch (e) {
      debugPrint('[API] POST $path: ${e.type.name}');
      return (status: null, body: null);
    }
  }

  // ── Config (fees + chains + pairs) ──

  /// Fetch fee config — pass [chainId] for chain-specific swap fee.
  /// Falls back to global fee if no chain-specific override exists in backend.
  Future<FeeConfig?> getFees({int? chainId}) async {
    final res = await _get(
      ApiConstants.fees,
      queryParams: chainId != null ? {'chain_id': chainId} : null,
    );
    if (res == null) return null;
    return FeeConfig.fromJson(res);
  }

  Future<List<ChainInfo>> getChains() async {
    final res = await _get(ApiConstants.chains);
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list
        .map((e) => ChainInfo.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// ที่อยู่สัญญา TPIX DEX + ธง ready จากทะเบียนบนเซิร์ฟเวอร์
  /// (แอปห้าม hardcode ที่อยู่ — deploy ใหม่แล้วแอปเก่าจะชี้ไปที่ตายทันที)
  Future<Map<String, dynamic>?> getDexConfig() async {
    final res = await _get(ApiConstants.dexConfig);
    if (res == null || res['success'] != true) return null;
    return res['data'] as Map<String, dynamic>?;
  }

  /// ทะเบียนคู่เทรดจากเซิร์ฟเวอร์ — **null = ขอไม่สำเร็จ** (แยกจาก [] = ไม่มีคู่เปิดอยู่จริง)
  ///
  /// หน้าเทรดใช้ตัดสินว่าคู่ไหนส่งเข้า PancakeSwap ได้ — โหลดไม่สำเร็จต้องบล็อก ไม่ใช่ถือว่า
  /// "ไม่มีข้อมูล = เทรดได้" (ดู bscPairGate)
  Future<List<TradingPairInfo>?> getPairs() async {
    final res = await _get(ApiConstants.pairs);
    if (res == null || res['success'] != true) return null;
    final list = res['data'];
    if (list is! List) return null;
    final pairs = <TradingPairInfo>[];
    for (final row in list) {
      if (row is! Map<String, dynamic>) continue;
      try {
        pairs.add(TradingPairInfo.fromJson(row));
      } catch (_) {
        // แถวเดียวผิดรูป — ข้าม ไม่ทำให้ทั้งรายการล้ม
      }
    }
    return pairs;
  }

  Future<List<Map<String, dynamic>>> getChainTokens(int chainId) async {
    final res = await _get(ApiConstants.chainTokens(chainId));
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list.cast<Map<String, dynamic>>();
  }

  // ── Market Data ──

  Future<List<Ticker>> getTickers() async {
    final res = await _get(ApiConstants.marketTickers);
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list
        .map((e) => Ticker.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<OrderBook?> getOrderBook(String symbol, {int limit = 20}) async {
    final res = await _get(
      ApiConstants.marketOrderbook(symbol),
      queryParams: {'limit': limit},
    );
    if (res == null || res['success'] != true) return null;
    return OrderBook.fromJson(res['data'] as Map<String, dynamic>);
  }

  Future<List<Kline>> getKlines(String symbol,
      {String interval = '1h', int limit = 100}) async {
    final res = await _get(
      ApiConstants.marketKlines(symbol),
      queryParams: {'interval': interval, 'limit': limit},
    );
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list.map((e) {
      if (e is List) return Kline.fromList(e);
      return Kline.fromJson(e as Map<String, dynamic>);
    }).toList();
  }

  // ── TPIX Price ──

  Future<TpixPrice?> getTpixPrice() async {
    final res = await _get(ApiConstants.tpixPrice);
    if (res == null || res['success'] != true) return null;
    return TpixPrice.fromJson(res['data'] as Map<String, dynamic>);
  }

  // ── Wallet ──

  /// [walletType] ต้องเป็นค่าที่เซิร์ฟเวอร์รับ (ดู WalletController::WALLET_TYPES)
  /// ⚠️ เดิมส่ง 'tpix_embedded' ตอนเซิร์ฟเวอร์ยังไม่รู้จัก → 422 ทุกครั้ง
  ///    การเชื่อมต่อจากแอปไม่เคยถูกบันทึกเลย (รายงานบั๊ก 3536)
  Future<Map<String, dynamic>?> walletConnect({
    required String walletAddress,
    required int chainId,
    String walletType = 'tpix_embedded',
  }) async {
    final res = await _post(ApiConstants.walletConnect, data: {
      'wallet_address': walletAddress,
      'chain_id': chainId,
      'wallet_type': walletType,
    });
    if (res == null || res['success'] != true) return null;
    // Save token if returned
    if (res['data']?['token'] != null) {
      setToken(res['data']['token'] as String);
    }
    return res['data'] as Map<String, dynamic>?;
  }

  Future<Map<String, dynamic>?> walletRequestSignature(
      String walletAddress) async {
    final res = await _post(ApiConstants.walletSign, data: {
      'wallet_address': walletAddress,
    });
    if (res == null || res['success'] != true) return null;
    return res['data'] as Map<String, dynamic>?;
  }

  Future<Map<String, dynamic>?> walletVerifySignature({
    required String walletAddress,
    required String signature,
    required String nonce,
    int? chainId,
    String? walletType,
  }) async {
    final res = await _post(ApiConstants.walletVerify, data: {
      'wallet_address': walletAddress,
      'signature': signature,
      'nonce': nonce,
      // ประกาศตัวว่าเป็นแอป → เซิร์ฟเวอร์ออกโทเคนเซสชัน 30 วันให้ (เว็บไม่ได้)
      'client': 'mobile',
      // เชน + ชนิดกระเป๋าจริง — ไม่ส่ง เซิร์ฟเวอร์จะบันทึกเป็น BSC/metamask ผิดๆ ทุกครั้ง
      'chain_id': ?chainId,
      'wallet_type': ?walletType,
    });
    if (res == null || res['success'] != true) return null;
    // Save token
    if (res['data']?['token'] != null) {
      setToken(res['data']['token'] as String);
    }
    final session = res['data']?['session_token'];
    if (session is String && session.isNotEmpty) {
      await WalletSession.save(session, walletAddress);
    }
    return res['data'] as Map<String, dynamic>?;
  }

  /// เซสชันที่เก็บไว้ยังใช้ได้ไหม — คืน HTTP status ของคำขอที่ต้องยืนยัน
  /// (200 = ใช้ได้ · 403 = หมดอายุ/ถูกเพิกถอน · null = ต่อเซิร์ฟเวอร์ไม่ได้ อย่าเพิ่งล้าง)
  Future<int?> probeWalletSession(String walletAddress) async {
    try {
      final response = await _dio.get(
        ApiConstants.walletProfile,
        queryParameters: {'wallet_address': walletAddress},
        options: Options(validateStatus: (_) => true),
      );
      return response.statusCode;
    } on DioException catch (e) {
      debugPrint('[API] probeWalletSession: ${e.type.name}');
      return null;
    }
  }

  /// ดึง user profile (name, email, avatar, preferences) — sync จาก backend
  /// ต้องเรียกหลัง verify wallet แล้ว (มี verified session)
  Future<UserProfile?> getProfile(String walletAddress) async {
    final res = await _get(ApiConstants.walletProfile, queryParams: {
      'wallet_address': walletAddress,
    });
    if (res == null || res['success'] != true) return null;
    return UserProfile.fromJson(res['data'] as Map<String, dynamic>);
  }

  /// อัปเดต profile + preferences — sync ไป backend
  /// รับ partial update: ส่งเฉพาะ field ที่ต้องการแก้
  Future<UserProfile?> updateProfile({
    required String walletAddress,
    String? name,
    String? email,
    String? avatar,
    Map<String, dynamic>? preferences,
  }) async {
    final body = <String, dynamic>{
      'wallet_address': walletAddress,
      if (name != null) 'name': name,
      if (email != null) 'email': email,
      if (avatar != null) 'avatar': avatar,
      if (preferences != null) 'preferences': preferences,
    };
    final res = await _put(ApiConstants.walletProfile, data: body);
    if (res == null || res['success'] != true) return null;
    return UserProfile.fromJson(res['data'] as Map<String, dynamic>);
  }

  /// ยอดจากเซิร์ฟเวอร์ — **null = ขอไม่สำเร็จ** (403 ยังไม่ยืนยันกระเป๋า / เน็ตหลุด)
  ///
  /// แยกจาก [] (ขอสำเร็จแต่ไม่มีเหรียญ) — ผู้เรียกจะได้คงยอดเดิมไว้แทนที่จะล้างเป็นศูนย์
  Future<List<TokenBalance>?> getWalletBalances(String walletAddress,
      {int chainId = 4289}) async {
    final res = await _get(ApiConstants.walletBalances, queryParams: {
      'wallet_address': walletAddress,
      'chain_id': chainId,
    });
    if (res == null || res['success'] != true) return null;
    final list = res['data']?['balances'] as List<dynamic>? ?? [];
    return list
        .whereType<Map<String, dynamic>>()
        .map((e) => TokenBalance.fromJson(e, chainId: chainId))
        .toList();
  }

  /// ราคา/สถิติ 24 ชม. ของคู่บน TPIX DEX (ราคากลางสดจาก reserve) — null = ขอไม่สำเร็จ
  Future<Map<String, dynamic>?> getDexTicker(String symbol) async {
    final res = await _get(ApiConstants.dexTicker(symbol));
    if (res == null || res['success'] != true) return null;
    return _asMap(res['data']);
  }

  /// แท่งเทียนของคู่บน TPIX DEX — รวมจากแท่ง 1 นาทีที่ dex:sync บันทึกจากพูลจริง
  Future<List<Kline>> getDexKlines(String symbol,
      {String interval = '1h', int limit = 100}) async {
    final res = await _get(
      ApiConstants.dexKlines(symbol),
      queryParams: {'interval': interval, 'limit': limit},
    );
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list.whereType<List<dynamic>>().map(Kline.fromList).toList();
  }

  /// ความลึกของพูล AMM (คำนวณจาก reserve จริง ไม่ใช่ออร์เดอร์ที่มีคนวาง)
  Future<OrderBook?> getDexOrderbook(String symbol, {int limit = 12}) async {
    final res = await _get(
      ApiConstants.dexOrderbook(symbol),
      queryParams: {'limit': limit},
    );
    if (res == null || res['success'] != true) return null;
    final data = _asMap(res['data']);
    return data == null ? null : OrderBook.fromJson(data);
  }

  /// คู่เทรดบนเชน TPIX ที่มีพูลจริง (/dex/pairs) — ใช้รู้ว่ามีโทเคนอะไรบนเชนให้อ่านยอด
  /// null = ขอไม่สำเร็จ
  Future<List<Map<String, dynamic>>?> getDexPairs() async {
    final res = await _get(ApiConstants.dexPairs);
    if (res == null || res['success'] != true) return null;
    return (res['data'] as List<dynamic>? ?? [])
        .whereType<Map<String, dynamic>>()
        .toList();
  }

  // ── Trading ──

  Future<TradeOrder?> createOrder({
    required String pair,
    required String side,
    required String type, // limit | market | stop-limit
    double? price,
    double? triggerPrice,
    required double amount,
    required String walletAddress,
    required int chainId,
  }) async {
    // C4: ส่ง price/amount เป็น String เพื่อรักษา precision (ไม่ใช้ double ตรง)
    final res = await _post(ApiConstants.tradingOrder, data: {
      'pair': pair,
      'side': side,
      'type': type,
      if (price != null) 'price': price.toStringAsFixed(8),
      if (triggerPrice != null)
        'trigger_price': triggerPrice.toStringAsFixed(8),
      'amount': amount.toStringAsFixed(8),
      'wallet_address': walletAddress,
      'chain_id': chainId,
    });
    if (res == null || res['success'] != true) return null;
    return TradeOrder.fromJson(res['data'] as Map<String, dynamic>);
  }

  /// คำสั่งที่เปิดอยู่ — รวมคำสั่งตั้งราคาของคู่บนเชน (`source: limit`)
  /// แยก "ไม่มีจริง" / "ต้องยืนยันกระเป๋า" / "โหลดไม่ได้" ออกจากกัน (ดู [ListLoad])
  Future<ListLoad<TradeOrder>> getOpenOrders(String walletAddress) =>
      _getOrderList(ApiConstants.tradingOrders, walletAddress);

  /// โหลดรายการคำสั่ง/ประวัติ — ไม่ยุบความล้มเหลวเป็นรายการว่าง
  Future<ListLoad<TradeOrder>> _getOrderList(
      String path, String walletAddress) async {
    try {
      final response = await _dio.get(
        path,
        queryParameters: {'wallet_address': walletAddress},
        options: Options(validateStatus: (_) => true),
      );
      return parseOrderList(response.statusCode, response.data);
    } on DioException catch (e) {
      debugPrint('[API] GET $path: ${e.type.name}');
      return ListLoad<TradeOrder>.failed();
    }
  }

  /// แปลงคำตอบของรายการคำสั่ง/ประวัติ — ฟังก์ชันเพียวไว้ทดสอบ
  ///
  /// 403 = ต้องยืนยันกระเป๋า · สำเร็จ = รายการ (แถวผิดรูปถูกข้าม ไม่ทำให้ทั้งรายการล้ม)
  /// อย่างอื่นทั้งหมด = โหลดไม่ได้ (ไม่ใช่ "ว่าง")
  @visibleForTesting
  static ListLoad<TradeOrder> parseOrderList(int? status, dynamic body) {
    if (status == 403) return ListLoad<TradeOrder>.needsVerify();
    final map = _asMap(body);
    if (status == null ||
        status < 200 ||
        status >= 300 ||
        map == null ||
        map['success'] != true) {
      return ListLoad<TradeOrder>.failed();
    }
    final list = map['data'];
    if (list is! List) return ListLoad<TradeOrder>.failed();
    final items = <TradeOrder>[];
    for (final row in list) {
      if (row is! Map) continue;
      try {
        items.add(TradeOrder.fromJson(
            row.map((k, v) => MapEntry(k.toString(), v))));
      } catch (_) {
        // แถวเดียวผิดรูป — ข้าม
      }
    }
    return ListLoad<TradeOrder>.ok(items);
  }

  Future<bool> cancelOrder(String orderId, String walletAddress) async {
    final res = await _delete(ApiConstants.tradingOrderCancel(orderId), data: {
      'wallet_address': walletAddress,
    });
    return res?['success'] == true;
  }

  /// ประวัติไม้ — แยก "ไม่มีจริง" / "ต้องยืนยันกระเป๋า" / "โหลดไม่ได้" (ดู [ListLoad])
  Future<ListLoad<TradeOrder>> getTradeHistory(String walletAddress) =>
      _getOrderList(ApiConstants.tradingHistory, walletAddress);

  // ── Swap ──

  /// ขออัตราค่าธรรมเนียม/slippage ของคู่จาก backend — คืนเหตุผลเสมอ ไม่ยุบเป็น null
  ///
  /// ผู้เรียก (BscSwapService.getQuote) ต้อง fail-closed: ไม่สำเร็จ = ไม่มีราคา = ไม่ส่งธุรกรรม
  /// [amount] ส่งเป็นข้อความทศนิยม (ไม่ใช่ double ดิบที่อาจออกมาเป็น 1e-7)
  Future<SwapQuoteResponse> getSwapQuote({
    required String fromToken,
    required String toToken,
    required String amount,
    required int chainId,
  }) async {
    try {
      final response = await _dio.get(
        ApiConstants.swapQuote,
        queryParameters: {
          'from_token': fromToken,
          'to_token': toToken,
          'amount': amount,
          'chain_id': chainId,
        },
        options: Options(validateStatus: (_) => true),
      );
      return parseSwapQuote(response.statusCode, response.data);
    } on DioException catch (e) {
      debugPrint('[API] GET ${ApiConstants.swapQuote}: ${e.type.name}');
      return const SwapQuoteResponse();
    }
  }

  /// แปลงคำตอบของ `/swap/quote` — ฟังก์ชันเพียวไว้ทดสอบ
  @visibleForTesting
  static SwapQuoteResponse parseSwapQuote(int? status, dynamic body) {
    final map = _asMap(body);
    final ok = status != null && status >= 200 && status < 300;
    if (!ok || map == null || map['success'] != true) {
      final err = map == null ? null : _asMap(map['error']);
      final code = err?['code'];
      return SwapQuoteResponse(
        status: status,
        errorCode: code is String && code.isNotEmpty ? code : null,
      );
    }
    final data = _asMap(map['data']);
    final quote = data == null ? null : (_asMap(data['quote']) ?? data);
    return SwapQuoteResponse(status: status, quote: quote);
  }

  /// ที่อยู่ fee collector + fee rate ของแพลตฟอร์ม — ใช้ตรวจแบบ fail-closed
  /// ก่อน swap จริง (ไม่มี collector = ไม่เทรด)
  Future<Map<String, dynamic>?> getTradingFeeInfo({required int chainId}) async {
    final res = await _get(ApiConstants.tradingFeeInfo,
        queryParams: {'chain_id': chainId});
    if (res == null || res['success'] != true) return null;
    return res['data'] as Map<String, dynamic>?;
  }

  /// แจ้งบันทึกไม้ที่ลงเชนแล้ว (`POST /swap/execute`) — เซิร์ฟเวอร์อ่านยอดจริงจากใบเสร็จบนเชนเอง
  ///
  /// [payload] ต้องตรงกับที่เว็บส่ง (useSwap.js) — สร้างด้วย `PendingSwapRecords.buildRecord`
  /// ไม่ throw: ทุกทางจบด้วย [SwapRecordOutcome] ให้คิว (PendingSwapRecords) ตัดสินว่าเก็บหรือทิ้ง
  ///
  /// ⚠️ เดิมคืน bool จาก `success` อย่างเดียว — 202 `recorded:false` (เชนยังไม่เห็นธุรกรรม)
  ///    ถูกนับเป็นสำเร็จ และ 403/5xx ถูกทิ้งเงียบ ไม้จริงหายจากประวัติ ค่าธรรมเนียมไม่เคยถูกตรวจ
  Future<SwapRecordOutcome> postSwapRecord(Map<String, dynamic> payload) async {
    try {
      final response = await _dio.post(
        ApiConstants.swapExecute,
        data: payload,
        options: Options(validateStatus: (_) => true),
      );
      return swapRecordOutcome(response.statusCode, response.data);
    } on DioException catch (e) {
      debugPrint('[API] POST ${ApiConstants.swapExecute}: ${e.type.name}');
      return SwapRecordOutcome.offline;
    }
  }

  /// ตัดสินผลจาก HTTP status + เนื้อคำตอบ — กติกาเดียวกับเว็บ (useSwap.js postSwapRecord)
  @visibleForTesting
  static SwapRecordOutcome swapRecordOutcome(int? status, dynamic body) {
    if (status == null) return SwapRecordOutcome.offline;
    if (status == 409 || status == 422) return SwapRecordOutcome.rejected;
    if (status < 200 || status >= 300) return SwapRecordOutcome.retryLater;
    final map = _asMap(body);
    // 2xx ที่อ่านไม่ออก/ไม่ success (หน้า HTML ของ CDN ฯลฯ) = ยังไม่รู้ว่าบันทึกแล้ว
    if (map == null || map['success'] != true) {
      return SwapRecordOutcome.retryLater;
    }
    final data = _asMap(map['data']);
    if (status == 202 && data?['recorded'] == false) {
      return SwapRecordOutcome.notYetOnChain;
    }
    return SwapRecordOutcome.recorded;
  }
}
