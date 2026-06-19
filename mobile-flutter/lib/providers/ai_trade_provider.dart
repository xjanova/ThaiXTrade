/// TPIX TRADE — AI Trade Provider
/// Generates REAL trading signals from live Binance candles using the
/// confluence StrategyEngine, and (optionally) executes them.
///
/// Modes (user-selectable, persisted):
///   • signals — show signals only, nothing trades
///   • manual  — user taps Execute on a signal → one real order
///   • auto    — auto-execute qualifying signals while this screen is open,
///               with guardrails: confidence threshold by risk level,
///               max-per-trade cap, dedupe (one order per direction change
///               per pair), and a hard kill-switch (set mode away from auto).
///
/// Execution itself is injected via [executor] so this provider stays free of
/// wallet/API coupling. Auto-exec only runs while the screen drives the refresh
/// timer (startAutoRefresh/stopAutoRefresh) — never silently in the background.
///
/// Developed by Xman Studio

import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/strategy_engine.dart';

enum AiMode { signals, manual, auto }

enum RiskLevel { conservative, balanced, aggressive }

extension RiskLevelX on RiskLevel {
  /// Minimum confidence (0..1) an auto-trade must meet.
  double get minConfidence {
    switch (this) {
      case RiskLevel.conservative:
        return 0.65;
      case RiskLevel.balanced:
        return 0.45;
      case RiskLevel.aggressive:
        return 0.30;
    }
  }

  /// Conservative only auto-trades STRONG signals.
  bool get requiresStrong => this == RiskLevel.conservative;
}

/// A tradable pair + how to fetch its candles + place its order.
class AiPairSpec {
  final String base; // BTC
  final String quote; // USDT
  final String tradePair; // backend pair symbol for createOrder (ticker.symbol)
  final String? logoUrl;

  const AiPairSpec({
    required this.base,
    required this.quote,
    required this.tradePair,
    this.logoUrl,
  });

  String get display => '$base/$quote';
  String get binanceSymbol => '$base$quote'.toUpperCase();
}

class AiSignalEntry {
  final AiPairSpec spec;
  final StrategySignal signal;
  const AiSignalEntry(this.spec, this.signal);
}

class AiExecLog {
  final String pair;
  final String side; // buy | sell
  final double baseAmount;
  final double quoteAmount;
  final bool ok;
  final String reason; // short status

  const AiExecLog({
    required this.pair,
    required this.side,
    required this.baseAmount,
    required this.quoteAmount,
    required this.ok,
    required this.reason,
  });
}

/// Executor signature: place [quoteAmount] worth of [entry]'s order.
/// Returns true on a confirmed order. The caller owns wallet/verify/balance.
typedef AiExecutor = Future<bool> Function(
    AiSignalEntry entry, double quoteAmount);

class AiTradeProvider extends ChangeNotifier {
  static const _kMode = 'ai_mode';
  static const _kRisk = 'ai_risk';
  static const _kMax = 'ai_max_per_trade';

  static const String candleInterval = '1h';
  static const int candleLimit = 120;
  static const Duration refreshEvery = Duration(seconds: 90);

  final Dio _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 10),
  ));

  // ── State ──
  AiMode _mode = AiMode.manual;
  RiskLevel _risk = RiskLevel.balanced;
  double _maxPerTrade = 50; // quote (USDT) per auto/manual order

  List<AiPairSpec> _watchlist = const [];
  List<AiSignalEntry> _signals = const [];
  bool _refreshing = false;
  DateTime? _lastRefresh;
  String? _error;

  final List<AiExecLog> _autoLog = [];
  // Dedupe auto-exec: last executed action per tradePair.
  final Map<String, SignalAction> _lastAutoAction = {};

  AiExecutor? executor;
  Timer? _timer;

  AiTradeProvider() {
    _load();
  }

  // ── Getters ──
  AiMode get mode => _mode;
  RiskLevel get risk => _risk;
  double get maxPerTrade => _maxPerTrade;
  List<AiSignalEntry> get signals => _signals;
  bool get isRefreshing => _refreshing;
  DateTime? get lastRefresh => _lastRefresh;
  String? get error => _error;
  List<AiExecLog> get autoLog => List.unmodifiable(_autoLog);
  bool get isAuto => _mode == AiMode.auto;

  /// Highest-confidence actionable signal (buy/sell), else the first entry.
  AiSignalEntry? get topSignal {
    if (_signals.isEmpty) return null;
    final actionable = _signals
        .where((e) => e.signal.action.orderSide != null)
        .toList()
      ..sort((a, b) => b.signal.confidence.compareTo(a.signal.confidence));
    return actionable.isNotEmpty ? actionable.first : _signals.first;
  }

  // ── Settings mutations (persist + notify) ──
  Future<void> setMode(AiMode m) async {
    if (_mode == m) return;
    _mode = m;
    // Leaving auto = kill-switch: forget dedupe so re-enabling re-evaluates.
    if (m != AiMode.auto) _lastAutoAction.clear();
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setInt(_kMode, m.index);
  }

  Future<void> setRisk(RiskLevel r) async {
    if (_risk == r) return;
    _risk = r;
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setInt(_kRisk, r.index);
  }

  Future<void> setMaxPerTrade(double v) async {
    final clamped = v.clamp(1.0, 1000000.0);
    if (_maxPerTrade == clamped) return;
    _maxPerTrade = clamped;
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setDouble(_kMax, clamped);
  }

  void setWatchlist(List<AiPairSpec> list) {
    _watchlist = list;
  }

  // ── Refresh lifecycle ──
  void startAutoRefresh() {
    _timer ??= Timer.periodic(refreshEvery, (_) => refresh());
    // Kick an immediate refresh on (re)entry.
    refresh();
  }

  void stopAutoRefresh() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> refresh() async {
    if (_refreshing || _watchlist.isEmpty) return;
    _refreshing = true;
    _error = null;
    notifyListeners();

    final out = <AiSignalEntry>[];
    try {
      for (final spec in _watchlist) {
        final candles = await _fetchCandles(spec.binanceSymbol);
        if (candles.length < StrategyEngine.minCandles) continue;
        out.add(AiSignalEntry(spec, StrategyEngine.evaluate(candles)));
      }
      // Strongest actionable first; holds sink to the bottom.
      out.sort((a, b) {
        final aAct = a.signal.action.orderSide != null ? 1 : 0;
        final bAct = b.signal.action.orderSide != null ? 1 : 0;
        if (aAct != bAct) return bAct - aAct;
        return b.signal.confidence.compareTo(a.signal.confidence);
      });
      _signals = out;
      _lastRefresh = DateTime.now();
    } catch (e) {
      _error = 'feed_unavailable';
      if (kDebugMode) debugPrint('AiTrade refresh error: ${e.runtimeType}');
    } finally {
      _refreshing = false;
      notifyListeners();
    }

    if (_mode == AiMode.auto) {
      await _runAutoExec();
    }
  }

  Future<List<Candle>> _fetchCandles(String binanceSymbol) async {
    final url =
        'https://api.binance.com/api/v3/klines?symbol=$binanceSymbol&interval=$candleInterval&limit=$candleLimit';
    final res = await _dio.get(url);
    if (res.statusCode != 200 || res.data is! List) return const [];
    return (res.data as List)
        .map((k) => Candle(
              open: double.tryParse(k[1].toString()) ?? 0,
              high: double.tryParse(k[2].toString()) ?? 0,
              low: double.tryParse(k[3].toString()) ?? 0,
              close: double.tryParse(k[4].toString()) ?? 0,
              volume: double.tryParse(k[5].toString()) ?? 0,
            ))
        .where((c) => c.close > 0)
        .toList();
  }

  /// Fresh spot price at execution time (Binance ticker). Used so a market
  /// order's base-amount isn't computed from a stale candle close.
  Future<double?> currentPrice(String binanceSymbol) async {
    try {
      final res = await _dio.get(
          'https://api.binance.com/api/v3/ticker/price?symbol=$binanceSymbol');
      if (res.statusCode == 200 && res.data is Map) {
        return double.tryParse(res.data['price'].toString());
      }
    } catch (_) {/* fall back to caller's reference price */}
    return null;
  }

  // ── Auto-execute (guardrailed) ──
  Future<void> _runAutoExec() async {
    final exec = executor;
    if (exec == null) return;
    try {
      for (final entry in _signals) {
        // Kill-switch — re-checked EVERY iteration (mode may flip mid-loop).
        if (_mode != AiMode.auto) return;

        final s = entry.signal;
        final side = s.action.orderSide;
        if (side == null) continue; // hold
        if (s.confidence < _risk.minConfidence) continue;
        if (_risk.requiresStrong && !s.action.isStrong) continue;

        // Dedupe: only fire when the verdict direction changes for this pair.
        final key = entry.spec.tradePair;
        if (_lastAutoAction[key] == s.action) continue;

        bool ok = false;
        try {
          ok = await exec(entry, _maxPerTrade);
        } catch (_) {
          ok = false;
        }
        // Re-check kill-switch after the await before recording/continuing.
        if (_mode != AiMode.auto) return;

        if (ok) _lastAutoAction[key] = s.action;
        _autoLog.insert(
          0,
          AiExecLog(
            pair: entry.spec.display,
            side: side,
            baseAmount: s.lastClose > 0 ? _maxPerTrade / s.lastClose : 0,
            quoteAmount: _maxPerTrade,
            ok: ok,
            reason: ok ? 'executed' : 'skipped',
          ),
        );
        if (_autoLog.length > 30) _autoLog.removeRange(30, _autoLog.length);
        notifyListeners();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('AiTrade auto-exec error: ${e.runtimeType}');
    }
  }

  // ── Persistence ──
  Future<void> _load() async {
    try {
      final p = await SharedPreferences.getInstance();
      final mi = p.getInt(_kMode);
      if (mi != null && mi >= 0 && mi < AiMode.values.length) {
        final loaded = AiMode.values[mi];
        // SAFETY: never auto-resume Auto across app restarts — it must be
        // explicitly re-enabled each session (in-memory dedupe is empty on
        // restart, so resuming could re-fire current signals).
        _mode = loaded == AiMode.auto ? AiMode.manual : loaded;
      }
      final ri = p.getInt(_kRisk);
      if (ri != null && ri >= 0 && ri < RiskLevel.values.length) {
        _risk = RiskLevel.values[ri];
      }
      _maxPerTrade = p.getDouble(_kMax) ?? _maxPerTrade;
      notifyListeners();
    } catch (_) {
      // Defaults are valid.
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
