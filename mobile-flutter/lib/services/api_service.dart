/// TPIX TRADE — API Service
/// HTTP client สำหรับสื่อสารกับ backend
/// ใช้ Dio + interceptor สำหรับ token / error handling
///
/// Developed by Xman Studio

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import '../core/constants/api_constants.dart';
import '../models/api_models.dart';

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
        return handler.next(options);
      },
      onError: (error, handler) {
        debugPrint('[API] ${error.type.name}: ${error.response?.statusCode ?? '?'}');
        return handler.next(error);
      },
    ));
  }

  void setToken(String? token) => _authToken = token;
  void clearToken() => _authToken = null;

  // ── Generic request helpers ──

  Future<Map<String, dynamic>?> _get(String path,
      {Map<String, dynamic>? queryParams}) async {
    try {
      final response = await _dio.get(path, queryParameters: queryParams);
      return response.data as Map<String, dynamic>?;
    } on DioException catch (e) {
      debugPrint('[API] GET $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  Future<Map<String, dynamic>?> _post(String path,
      {Map<String, dynamic>? data}) async {
    try {
      final response = await _dio.post(path, data: data);
      return response.data as Map<String, dynamic>?;
    } on DioException catch (e) {
      debugPrint('[API] POST $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  Future<Map<String, dynamic>?> _delete(String path,
      {Map<String, dynamic>? data}) async {
    try {
      final response = await _dio.delete(path, data: data);
      return response.data as Map<String, dynamic>?;
    } on DioException catch (e) {
      debugPrint('[API] DELETE $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
    }
  }

  Future<Map<String, dynamic>?> _put(String path,
      {Map<String, dynamic>? data}) async {
    try {
      final response = await _dio.put(path, data: data);
      return response.data as Map<String, dynamic>?;
    } on DioException catch (e) {
      debugPrint('[API] PUT $path: ${e.response?.statusCode ?? e.type.name}');
      return null;
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

  Future<List<TradingPairInfo>> getPairs() async {
    final res = await _get(ApiConstants.pairs);
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list
        .map((e) => TradingPairInfo.fromJson(e as Map<String, dynamic>))
        .toList();
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

  // ── TPIX Price + Internal Order Book ──

  Future<TpixPrice?> getTpixPrice() async {
    final res = await _get(ApiConstants.tpixPrice);
    if (res == null || res['success'] != true) return null;
    return TpixPrice.fromJson(res['data'] as Map<String, dynamic>);
  }

  /// TPIX OrderBook — ใช้ internal matching engine (ไม่ผ่าน Binance)
  /// สำหรับ TPIX-USDT pair เท่านั้น
  Future<OrderBook?> getTpixOrderbook({int limit = 20}) async {
    final res = await _get(
      ApiConstants.tpixOrderbook,
      queryParams: {'limit': limit},
    );
    if (res == null || res['success'] != true) return null;
    return OrderBook.fromJson(res['data'] as Map<String, dynamic>);
  }

  /// TPIX Klines (candlestick) — สร้างจาก internal trades
  Future<List<Kline>> getTpixKlines({
    String interval = '1h',
    int limit = 100,
  }) async {
    final res = await _get(
      ApiConstants.tpixKlines,
      queryParams: {'interval': interval, 'limit': limit},
    );
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list.map((e) {
      if (e is List) return Kline.fromList(e);
      return Kline.fromJson(e as Map<String, dynamic>);
    }).toList();
  }

  /// TPIX recent trades — สำหรับแสดงใน trade history feed
  Future<List<Map<String, dynamic>>> getTpixTrades({int limit = 50}) async {
    final res = await _get(
      ApiConstants.tpixTrades,
      queryParams: {'limit': limit},
    );
    if (res == null || res['success'] != true) return [];
    return (res['data'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
  }

  // ── Wallet ──

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
  }) async {
    final res = await _post(ApiConstants.walletVerify, data: {
      'wallet_address': walletAddress,
      'signature': signature,
      'nonce': nonce,
    });
    if (res == null || res['success'] != true) return null;
    // Save token
    if (res['data']?['token'] != null) {
      setToken(res['data']['token'] as String);
    }
    return res['data'] as Map<String, dynamic>?;
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

  Future<List<TokenBalance>> getWalletBalances(String walletAddress,
      {int chainId = 4289}) async {
    final res = await _get(ApiConstants.walletBalances, queryParams: {
      'wallet_address': walletAddress,
      'chain_id': chainId,
    });
    if (res == null || res['success'] != true) return [];
    final list = res['data']?['balances'] as List<dynamic>? ?? [];
    return list
        .map((e) => TokenBalance.fromJson(e as Map<String, dynamic>))
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

  Future<List<TradeOrder>> getOpenOrders(String walletAddress) async {
    final res = await _get(ApiConstants.tradingOrders, queryParams: {
      'wallet_address': walletAddress,
    });
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list
        .map((e) => TradeOrder.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<bool> cancelOrder(String orderId, String walletAddress) async {
    final res = await _delete(ApiConstants.tradingOrderCancel(orderId), data: {
      'wallet_address': walletAddress,
    });
    return res?['success'] == true;
  }

  Future<List<TradeOrder>> getTradeHistory(String walletAddress) async {
    final res = await _get(ApiConstants.tradingHistory, queryParams: {
      'wallet_address': walletAddress,
    });
    if (res == null || res['success'] != true) return [];
    final list = res['data'] as List<dynamic>? ?? [];
    return list
        .map((e) => TradeOrder.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  // ── Swap ──

  Future<Map<String, dynamic>?> getSwapQuote({
    required String fromToken,
    required String toToken,
    required double amount,
    required int chainId,
    double slippage = 0.5,
  }) async {
    final res = await _get(ApiConstants.swapQuote, queryParams: {
      'from_token': fromToken,
      'to_token': toToken,
      'amount': amount,
      'chain_id': chainId,
      'slippage': slippage,
    });
    if (res == null || res['success'] != true) return null;
    return res['data'] as Map<String, dynamic>?;
  }
}
