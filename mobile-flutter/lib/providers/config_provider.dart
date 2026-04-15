/// TPIX TRADE — Config Provider
/// ดึง config จาก backend (fees, chains, pairs) — sync กับเว็บ tpix.online
/// Cache ใน memory + refresh ตอน chain switch หรือ manual refresh
///
/// Developed by Xman Studio

import 'package:flutter/foundation.dart';
import '../models/api_models.dart';
import '../models/chain_config.dart';
import '../services/api_service.dart';

/// การผสมข้อมูล chain จาก API (display) + static (supported for signing)
class DisplayChain {
  final int chainId;
  final String name;
  final String shortName;
  final String symbol;
  final bool supported; // true = มี ChainConfig ใน static list (switch ได้)
  final ChainConfig? config; // null ถ้ายังไม่ support

  const DisplayChain({
    required this.chainId,
    required this.name,
    required this.shortName,
    required this.symbol,
    required this.supported,
    this.config,
  });
}

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

  /// Merge API chains + static ChainConfig
  /// คืน list สำหรับ UI display — chain ที่ support จะ switch ได้
  /// chain ที่ไม่ support (จาก API) จะแสดง "Coming soon"
  List<DisplayChain> get displayChains {
    // Fallback: static list ถ้า API ยังไม่โหลด
    if (_chains.isEmpty) {
      return ChainConfig.all
          .map((c) => DisplayChain(
                chainId: c.chainId,
                name: c.name,
                shortName: c.shortName,
                symbol: c.symbol,
                supported: true,
                config: c,
              ))
          .toList();
    }
    // Merge: API provides name/symbol; static provides RPC+config for signing
    return _chains.map((apiChain) {
      ChainConfig? staticConfig;
      try {
        staticConfig =
            ChainConfig.all.firstWhere((c) => c.chainId == apiChain.chainId);
      } catch (_) {
        staticConfig = null;
      }
      return DisplayChain(
        chainId: apiChain.chainId,
        name: apiChain.name,
        shortName: apiChain.shortName.isNotEmpty
            ? apiChain.shortName
            : apiChain.symbol,
        symbol: apiChain.symbol,
        supported: staticConfig != null,
        config: staticConfig,
      );
    }).toList();
  }

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
  /// Partial failure tolerant — ถ้า endpoint หนึ่งพัง endpoint อื่นยังได้ผล
  Future<void> loadAll({bool silent = false}) async {
    if (!silent) {
      _isLoading = true;
      notifyListeners();
    }

    // รันพร้อมกันแต่ใช้ try-catch แยกแต่ละตัว — กัน partial failure
    final feesFuture = _api.getFees().catchError((e) {
      debugPrint('getFees: ${e.runtimeType}');
      return null as FeeConfig?;
    });
    final chainsFuture = _api.getChains().catchError((e) {
      debugPrint('getChains: ${e.runtimeType}');
      return <ChainInfo>[];
    });
    final pairsFuture = _api.getPairs().catchError((e) {
      debugPrint('getPairs: ${e.runtimeType}');
      return <TradingPairInfo>[];
    });

    final fees = await feesFuture;
    final chains = await chainsFuture;
    final pairs = await pairsFuture;

    // อัพเดตเฉพาะตัวที่ได้ผล — ไม่ overwrite ด้วย empty ถ้าเคยโหลดสำเร็จ
    if (fees != null) _fees = fees;
    if (chains.isNotEmpty) _chains = chains;
    if (pairs.isNotEmpty) _pairs = pairs;

    // ถ้าได้ของใหม่อย่างน้อย 1 ตัว ถือว่าโหลดสำเร็จ (update lastLoadedAt)
    if (fees != null || chains.isNotEmpty || pairs.isNotEmpty) {
      _lastLoadedAt = DateTime.now();
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
