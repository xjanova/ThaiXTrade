/// TPIX TRADE — Config Provider
/// ดึง config จาก backend (fees, chains, pairs) — sync กับเว็บ tpix.online
/// Cache ใน memory + refresh ตอน chain switch หรือ manual refresh
///
/// Developed by Xman Studio

import 'package:flutter/foundation.dart';
import '../models/api_models.dart';
import '../services/api_service.dart';

class ConfigProvider extends ChangeNotifier {
  final ApiService _api = ApiService();

  FeeConfig? _fees;
  List<ChainInfo> _chains = [];
  List<TradingPairInfo> _pairs = [];
  bool _isLoading = false;
  DateTime? _lastLoadedAt;

  FeeConfig? get fees => _fees;
  List<ChainInfo> get chains => _chains;
  List<TradingPairInfo> get pairs => _pairs;
  bool get isLoading => _isLoading;
  bool get isReady => _fees != null && _chains.isNotEmpty;

  /// ค่า fee rate ที่ใช้งานจริง (% ของ trade amount)
  double get swapFeePercent => _fees?.swapFeePercent ?? 0.3;
  String get feeCollectorWallet => _fees?.swapFeeWallet ?? '';
  bool get canTrade => _fees?.swapEnabled == true;

  /// หา trading pair ตาม symbol (BTC-USDT)
  TradingPairInfo? pairBySymbol(String symbol) {
    try {
      return _pairs.firstWhere((p) => p.symbol == symbol);
    } catch (_) {
      return null;
    }
  }

  /// หา chain ตาม chainId
  ChainInfo? chainById(int chainId) {
    try {
      return _chains.firstWhere((c) => c.chainId == chainId);
    } catch (_) {
      return null;
    }
  }

  /// Fee rate ของ pair — ใช้ override ก่อน ถ้าไม่มีใช้ global fee %
  double feeRateForPair(String symbol) {
    final pair = pairBySymbol(symbol);
    return pair?.feeRateOverride ?? swapFeePercent;
  }

  /// Load ทุก config — เรียกตอน splash
  Future<void> loadAll({bool silent = false}) async {
    if (!silent) {
      _isLoading = true;
      notifyListeners();
    }

    try {
      // ดึง 3 endpoints parallel
      final results = await Future.wait([
        _api.getFees(),
        _api.getChains(),
        _api.getPairs(),
      ]);
      _fees = results[0] as FeeConfig?;
      _chains = results[1] as List<ChainInfo>;
      _pairs = results[2] as List<TradingPairInfo>;
      _lastLoadedAt = DateTime.now();
    } catch (e) {
      debugPrint('ConfigProvider.loadAll: ${e.runtimeType}');
    }

    _isLoading = false;
    notifyListeners();
  }

  /// Refresh ถ้าเก่ากว่า 5 นาที
  Future<void> refreshIfStale() async {
    if (_lastLoadedAt == null) {
      await loadAll(silent: true);
      return;
    }
    if (DateTime.now().difference(_lastLoadedAt!) > const Duration(minutes: 5)) {
      await loadAll(silent: true);
    }
  }

  /// Validate order amount — คืน null ถ้า OK, String ถ้ามี error (localized key)
  String? validateOrderAmount(String symbol, double amount) {
    final pair = pairBySymbol(symbol);
    if (pair == null) return null; // ถ้าไม่มี config ก็ปล่อย (backend จะ validate)
    if (pair.minTradeAmount > 0 && amount < pair.minTradeAmount) {
      return 'trade.below_min';
    }
    if (pair.maxTradeAmount > 0 && amount > pair.maxTradeAmount) {
      return 'trade.above_max';
    }
    return null;
  }
}
