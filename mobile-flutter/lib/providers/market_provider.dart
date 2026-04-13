/// TPIX TRADE — Market Data Provider
/// จัดการ tickers, orderbook, klines, favorites
///
/// Developed by Xman Studio

import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/api_models.dart';
import '../services/api_service.dart';

class MarketProvider extends ChangeNotifier {
  final ApiService _api = ApiService();

  // Tickers
  List<Ticker> _tickers = [];
  List<Ticker> _filteredTickers = [];
  bool _isLoading = false;
  String _searchQuery = '';
  String _sortBy = 'volume'; // volume, price, change
  bool _sortAsc = false;
  Set<String> _favorites = {};

  // Selected pair
  String _selectedPair = 'BTC-USDT';
  OrderBook? _orderBook;
  List<Kline> _klines = [];
  TpixPrice? _tpixPrice;

  Timer? _tickerTimer;

  // Getters
  List<Ticker> get tickers => _filteredTickers;
  List<Ticker> get allTickers => _tickers;
  bool get isLoading => _isLoading;
  String get searchQuery => _searchQuery;
  String get sortBy => _sortBy;
  bool get sortAsc => _sortAsc;
  Set<String> get favorites => _favorites;
  String get selectedPair => _selectedPair;
  OrderBook? get orderBook => _orderBook;
  List<Kline> get klines => _klines;
  TpixPrice? get tpixPrice => _tpixPrice;

  Ticker? get selectedTicker {
    try {
      return _tickers.firstWhere((t) => t.symbol == _selectedPair);
    } catch (_) {
      return null;
    }
  }

  List<Ticker> get topGainers {
    final sorted = List<Ticker>.from(_tickers)
      ..sort((a, b) => b.priceChangePercent.compareTo(a.priceChangePercent));
    return sorted.take(5).toList();
  }

  List<Ticker> get topLosers {
    final sorted = List<Ticker>.from(_tickers)
      ..sort((a, b) => a.priceChangePercent.compareTo(b.priceChangePercent));
    return sorted.take(5).toList();
  }

  List<Ticker> get favoriteTickers =>
      _tickers.where((t) => _favorites.contains(t.symbol)).toList();

  // ── Load Tickers ──

  Future<void> loadTickers({bool silent = false}) async {
    if (!silent) {
      _isLoading = true;
      notifyListeners();
    }

    try {
      _tickers = await _api.getTickers();
      _applyFilter();
    } catch (e) {
      debugPrint('loadTickers error: $e');
    }

    _isLoading = false;
    notifyListeners();
  }

  // ── Auto-refresh ──

  void startAutoRefresh() {
    _tickerTimer?.cancel();
    _tickerTimer = Timer.periodic(
      const Duration(seconds: 10),
      (_) => loadTickers(silent: true),
    );
  }

  void stopAutoRefresh() {
    _tickerTimer?.cancel();
    _tickerTimer = null;
  }

  // ── Search & Sort ──

  void setSearchQuery(String query) {
    _searchQuery = query.toUpperCase();
    _applyFilter();
    notifyListeners();
  }

  void setSortBy(String sortBy, {bool? ascending}) {
    if (_sortBy == sortBy && ascending == null) {
      _sortAsc = !_sortAsc;
    } else {
      _sortBy = sortBy;
      _sortAsc = ascending ?? false;
    }
    _applyFilter();
    notifyListeners();
  }

  void _applyFilter() {
    var list = List<Ticker>.from(_tickers);

    // Search
    if (_searchQuery.isNotEmpty) {
      list = list.where((t) =>
          t.baseAsset.contains(_searchQuery) ||
          t.symbol.contains(_searchQuery)).toList();
    }

    // Sort
    list.sort((a, b) {
      int cmp;
      switch (_sortBy) {
        case 'price':
          cmp = a.lastPrice.compareTo(b.lastPrice);
        case 'change':
          cmp = a.priceChangePercent.compareTo(b.priceChangePercent);
        case 'name':
          cmp = a.baseAsset.compareTo(b.baseAsset);
        default: // volume
          cmp = a.quoteVolume24h.compareTo(b.quoteVolume24h);
      }
      return _sortAsc ? cmp : -cmp;
    });

    _filteredTickers = list;
  }

  // ── Favorites (persisted) ──

  static const _keyFavorites = 'tpix_trade_favorites';

  void toggleFavorite(String symbol) {
    if (_favorites.contains(symbol)) {
      _favorites.remove(symbol);
    } else {
      _favorites.add(symbol);
    }
    notifyListeners();
    _saveFavorites();
  }

  Future<void> loadFavorites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final list = prefs.getStringList(_keyFavorites);
      if (list != null) {
        _favorites = list.toSet();
        notifyListeners();
      }
    } catch (_) {}
  }

  Future<void> _saveFavorites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setStringList(_keyFavorites, _favorites.toList());
    } catch (_) {}
  }

  // ── Select Pair ──

  Future<void> selectPair(String pair) async {
    _selectedPair = pair;
    notifyListeners();
    await Future.wait([
      loadOrderBook(),
      loadKlines(),
    ]);
  }

  // ── Order Book ──

  Future<void> loadOrderBook() async {
    try {
      _orderBook = await _api.getOrderBook(_selectedPair);
      notifyListeners();
    } catch (e) {
      debugPrint('loadOrderBook error: $e');
    }
  }

  // ── Klines ──

  Future<void> loadKlines({String interval = '1h', int limit = 100}) async {
    try {
      _klines = await _api.getKlines(_selectedPair,
          interval: interval, limit: limit);
      notifyListeners();
    } catch (e) {
      debugPrint('loadKlines error: $e');
    }
  }

  // ── TPIX Price ──

  Future<void> loadTpixPrice() async {
    try {
      _tpixPrice = await _api.getTpixPrice();
      notifyListeners();
    } catch (e) {
      debugPrint('loadTpixPrice error: $e');
    }
  }

  @override
  void dispose() {
    stopAutoRefresh();
    super.dispose();
  }
}
