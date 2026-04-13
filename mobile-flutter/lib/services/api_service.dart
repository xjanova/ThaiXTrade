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
    required String type,
    double? price,
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
