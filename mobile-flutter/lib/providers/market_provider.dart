/// TPIX TRADE — Market Data Provider
/// จัดการ tickers, orderbook, klines, favorites
/// รองรับ WebSocket real-time updates จาก Binance
///
/// Developed by Xman Studio

import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/api_models.dart';
import '../services/api_service.dart';
import '../services/binance_ws.dart';
import '../services/tpix_dex_service.dart';

class MarketProvider extends ChangeNotifier {
  final ApiService _api = ApiService();
  final BinanceWS _ws = BinanceWS();
  final TpixDexService _dex = TpixDexService();

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
  StreamSubscription? _tickerSub;
  StreamSubscription? _depthSub;

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

  /// ราคา TPIX ตอนนี้เป็นแค่ราคาอ้างอิง (แอดมินตั้ง/ค่าเริ่มต้น) ไม่ใช่ราคาที่ซื้อขายได้จริง
  /// — หน้าจอต้องบอกผู้ใช้ตรง ๆ ไม่แสดงเหมือนราคาตลาด
  bool get tpixPriceIsIndicative =>
      _tpixPrice != null && !_tpixPrice!.isMarket;

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

  /// รายการสำหรับหน้าแรก — ไม่ขึ้นกับคำค้นในหน้า Markets
  /// (เดิมหน้าแรกใช้รายการที่กรองแล้ว พิมพ์ค้นในหน้า Markets หน้าแรกก็หายตาม)
  List<Ticker> get homeTickers {
    final list = List<Ticker>.from(_tickers)
      ..sort((a, b) => b.quoteVolume24h.compareTo(a.quoteVolume24h));
    return list;
  }

  // ── คู่บนเชน TPIX (TPIX DEX) ──
  //
  // เหรียญลูกจาก Token Factory (เช่น IDTC) ได้คู่ IDTC-USDT / IDTC-TPIX เมื่อมีพูลบน DEX
  // และแอดมินลิสต์แล้ว (dex:sync สร้างให้) — tickers ของเซิร์ฟเวอร์มาจาก Binance
  // จึงไม่มีคู่พวกนี้เลย ต้องเติมจาก /dex/pairs + /dex/ticker เอง
  // ไม่งั้นผู้ใช้หาคู่ไม่เจอในตลาด/ค้นหา และหน้าเทรดไปดึงกราฟจาก Binance ที่ไม่มีคู่นี้

  /// symbol → คู่บนเชน TPIX ที่เปิดเทรดอยู่ (รวม TPIX-USDT)
  Map<String, DexPairInfo> _dexPairs = {};
  final Map<String, Ticker> _dexTickers = {};
  DateTime? _dexPairsAt;

  /// คู่นี้ใช้ข้อมูลจาก TPIX DEX (ราคา/กราฟ/ความลึก) — TPIX-USDT นับเสมอแม้รายการยังโหลดไม่เสร็จ
  bool isDexPair(String symbol) {
    final s = symbol.toUpperCase();
    return s == 'TPIX-USDT' || _dexPairs.containsKey(s);
  }

  DexPairInfo? dexPairInfo(String symbol) => _dexPairs[symbol.toUpperCase()];

  /// โลโก้ของเหรียญฝั่ง base ในคู่บนเชน TPIX (https เท่านั้น) — null = ใช้ตัวอักษรแทน
  String? dexBaseLogo(String symbol) =>
      _dexPairs[symbol.toUpperCase()]?.baseLogo;

  /// เหรียญนี้เป็นเหรียญบนเชน TPIX (ห้ามเดาโลโก้จากชื่อบน CDN — ชื่อซ้ำเหรียญดังได้)
  bool isChainAsset(String baseAsset) {
    final b = baseAsset.toUpperCase();
    for (final p in _dexPairs.values) {
      if (p.baseAsset == b) return true;
    }
    return false;
  }

  Future<void> _refreshDexMarkets() async {
    try {
      final fresh = _dexPairsAt != null &&
          DateTime.now().difference(_dexPairsAt!) < const Duration(minutes: 1);
      if (!fresh) {
        final list = await _api.getDexPairs();
        if (list != null) {
          final next = <String, DexPairInfo>{};
          for (final raw in list) {
            final p = DexPairInfo.tryParse(raw);
            // พูลว่าง/ถูกเลิกลิสต์ (is_active = false) ไม่ขึ้นตลาด — ชื่อซ้ำเอาตัวแรก
            if (p == null || !p.isActive || p.chainId != 4289) continue;
            next.putIfAbsent(p.symbol, () => p);
          }
          _dexPairs = next;
          _dexPairsAt = DateTime.now();
          _dexTickers.removeWhere((k, _) => !next.containsKey(k));
        }
      }

      // TPIX-USDT มีราคาจาก /tpix/price แยกอยู่แล้ว (ดู _patchTpixTicker)
      final symbols =
          _dexPairs.keys.where((s) => s != 'TPIX-USDT').toList(growable: false);
      final results = await Future.wait(symbols.map((s) => _api
          .getDexTicker(s)
          .then<Map<String, dynamic>?>((v) => v)
          .catchError((Object _) => null)));
      for (var i = 0; i < symbols.length; i++) {
        final pair = _dexPairs[symbols[i]];
        if (pair == null) continue;
        final data = results[i];
        if (data == null) {
          // ขอไม่สำเร็จรอบนี้ — คงแถวเดิม ไม่มีแถวเดิมก็ขึ้นแถวไม่มีราคา (แสดง —)
          _dexTickers.putIfAbsent(pair.symbol, () => _emptyDexTicker(pair));
          continue;
        }
        final noLiquidity = data['has_liquidity'] == false;
        _dexTickers[pair.symbol] = Ticker.fromJson({
          ...data,
          'symbol': pair.symbol,
          'base_asset': pair.baseAsset,
          'quote_asset': pair.quoteAsset,
          // พูลว่าง = ไม่มีราคา (0 → แสดง —) ไม่ใช่ราคา 0 จริง
          if (noLiquidity) 'price': 0,
          if (noLiquidity) 'lastPrice': 0,
        }).copyWith(isDex: true);
      }
    } catch (e) {
      debugPrint('refreshDexMarkets error: ${e.runtimeType}');
    }
  }

  static Ticker _emptyDexTicker(DexPairInfo p) => Ticker(
        symbol: p.symbol,
        baseAsset: p.baseAsset,
        quoteAsset: p.quoteAsset,
        lastPrice: 0,
        priceChange: 0,
        priceChangePercent: 0,
        high24h: 0,
        low24h: 0,
        volume24h: 0,
        quoteVolume24h: 0,
        isDex: true,
      );

  /// ใส่แถวคู่บนเชน TPIX ลงรายการ — ชื่อชนกับคู่หลักเดิม (มีคนตั้งเหรียญชื่อ BTC) คู่เดิมชนะเสมอ
  void _mergeDexTickers() {
    _tickers.removeWhere((t) =>
        t.isDex &&
        t.symbol != 'TPIX-USDT' &&
        !_dexTickers.containsKey(t.symbol));
    for (final e in _dexTickers.entries) {
      final idx = _tickers.indexWhere((t) => t.symbol == e.key);
      if (idx < 0) {
        _tickers.add(e.value);
      } else if (_tickers[idx].isDex) {
        _tickers[idx] = e.value;
      }
    }
  }

  // ── Load Tickers ──

  Future<void> loadTickers({bool silent = false}) async {
    if (!silent) {
      _isLoading = true;
      notifyListeners();
    }

    try {
      final serverFuture = _api.getTickers();
      await _refreshDexMarkets();
      final server = await serverFuture;
      if (_disposed) return;
      // ขอไม่สำเร็จ (ได้ [] กลับมา) ระหว่างรีเฟรช = คงรายการเดิม ไม่ล้างจอเป็นว่าง
      if (server.isNotEmpty || _tickers.isEmpty) {
        _tickers = server.where((t) => !t.isDex).toList();
      }
      _mergeDexTickers();
      _ensureTpixFirst();
      _applyFilter();
    } catch (e) {
      debugPrint('loadTickers error: ${e.runtimeType}');
    }

    _isLoading = false;
    notifyListeners();
  }

  /// ให้ TPIX-USDT อยู่ต้นสุดเสมอ (ตรงกับเว็บที่ force-prepend TPIX)
  void _ensureTpixFirst() {
    final hasTpix = _tickers.any((t) => t.symbol == 'TPIX-USDT');
    if (hasTpix) _patchTpixTicker();
    if (!hasTpix) {
      _tickers.insert(
        0,
        Ticker(
          symbol: 'TPIX-USDT',
          baseAsset: 'TPIX',
          quoteAsset: 'USDT',
          lastPrice: _tpixPrice?.price ?? 0,
          priceChange: 0,
          priceChangePercent: _tpixPrice?.change24h ?? 0,
          high24h: 0,
          low24h: 0,
          volume24h: 0,
          quoteVolume24h: _tpixPrice?.volume24h ?? 0,
          isDex: true,
        ),
      );
    } else {
      // ย้าย TPIX-USDT ไปต้นสุด
      final idx = _tickers.indexWhere((t) => t.symbol == 'TPIX-USDT');
      if (idx > 0) {
        final tpix = _tickers.removeAt(idx);
        _tickers.insert(0, tpix);
      }
    }
  }

  // ── Auto-refresh (HTTP fallback + WebSocket) ──

  void startAutoRefresh() {
    // HTTP polling (backup ทุก 30 วินาที)
    _tickerTimer?.cancel();
    _tickerTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) {
        loadTickers(silent: true);
        // TPIX ไม่อยู่ใน tickers ของเซิร์ฟเวอร์ — ราคามาจากพูลผ่าน /tpix/price แยก
        loadTpixPrice(retries: 0);
      },
    );

    // WebSocket real-time tickers
    _ws.connectTickers();
    _tickerSub?.cancel();
    _tickerSub = _ws.tickerStream.listen(_onTickerUpdate);
  }

  void _onTickerUpdate(Map<String, dynamic> data) {
    // Binance miniTicker format: { s: "BTCUSDT", c: "67000.00", ... }
    final symbol = data['s'] as String?;
    if (symbol == null) return;

    // หา matching ticker — Binance ส่ง "BTCUSDT" ต้อง match กับ "BTC-USDT"
    for (int i = 0; i < _tickers.length; i++) {
      // คู่บนเชน TPIX ไม่ได้อยู่บน Binance — ชื่อบังเอิญตรงกันก็ห้ามเอาราคา Binance มาทับ
      if (_tickers[i].isDex) continue;
      final binSymbol = _tickers[i].symbol.replaceAll('-', '');
      if (binSymbol == symbol) {
        final price = double.tryParse(data['c']?.toString() ?? '') ?? 0;
        // miniTicker: v = ปริมาณเป็นเหรียญ base · q = ปริมาณเป็น quote (USDT)
        // เดิมเอา v ไปใส่ quoteVolume24h → ปริมาณ/การเรียงตามปริมาณปนหน่วยกันหลังติ๊กแรก
        final quoteVolume = double.tryParse(data['q']?.toString() ?? '');
        final open = double.tryParse(data['o']?.toString() ?? '') ?? 0;
        if (price > 0) {
          _tickers[i] = _tickers[i].copyWith(
            lastPrice: price,
            quoteVolume24h: quoteVolume,
            priceChangePercent: open > 0 ? (price - open) / open * 100 : null,
          );
          _applyFilter();
          notifyListeners();
        }
        break;
      }
    }
  }

  void stopAutoRefresh() {
    _tickerTimer?.cancel();
    _tickerTimer = null;
    _tickerSub?.cancel();
    _tickerSub = null;
    _ws.pause();
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

    if (_searchQuery.isNotEmpty) {
      list = list
          .where((t) =>
              t.baseAsset.contains(_searchQuery) ||
              t.symbol.contains(_searchQuery))
          .toList();
    }

    list.sort((a, b) {
      int cmp;
      switch (_sortBy) {
        case 'price':
          cmp = a.lastPrice.compareTo(b.lastPrice);
        case 'change':
          cmp = a.priceChangePercent.compareTo(b.priceChangePercent);
        case 'name':
          cmp = a.baseAsset.compareTo(b.baseAsset);
        default:
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

  /// คู่ที่เลือกอยู่บนเชน TPIX — ข้อมูลมาจาก /dex/* (ไม่ใช่ Binance)
  bool get _isDexSelected => isDexPair(_selectedPair);

  Future<void> selectPair(String pair) async {
    _selectedPair = pair;
    // ข้อมูลของคู่เก่าห้ามค้างโชว์เป็นของคู่ใหม่ระหว่างโหลด
    _orderBook = null;
    _klines = [];
    notifyListeners();

    // Subscribe Binance WebSocket depth — เฉพาะ pair ที่อยู่บน Binance
    // คู่บนเชน TPIX ไม่ได้อยู่บน Binance ใช้ HTTP แทน
    _depthSub?.cancel();
    _depthSub = null;
    if (!_isDexSelected) {
      _ws.subscribePair(pair);
      _depthSub = _ws.depthStream.listen(_onDepthUpdate);
    }

    await Future.wait([
      loadOrderBook(),
      loadKlines(),
    ]);
  }

  void _onDepthUpdate(Map<String, dynamic> data) {
    // ผู้ใช้สลับไปคู่บนเชน TPIX แล้ว — depth ของ Binance ที่ค้างในท่อห้ามมาทับ
    if (_isDexSelected) return;
    // Binance depth20 format: { bids: [[price, qty], ...], asks: [[price, qty], ...] }
    try {
      final bids = (data['bids'] as List<dynamic>?)
              ?.map((e) => OrderBookEntry.fromList(e as List<dynamic>))
              .toList() ??
          [];
      final asks = (data['asks'] as List<dynamic>?)
              ?.map((e) => OrderBookEntry.fromList(e as List<dynamic>))
              .toList() ??
          [];
      if (bids.isNotEmpty || asks.isNotEmpty) {
        _orderBook = OrderBook(bids: bids, asks: asks);
        notifyListeners();
      }
    } catch (_) {}
  }

  // ── Order Book ──

  /// ความลึกของคู่ที่เลือก
  ///
  /// คู่บนเชน TPIX ใช้ /dex/orderbook (ความลึกจาก reserve จริงของพูล)
  /// เดิมคู่ TPIX ใช้ /tpix/orderbook = สมุดของ matching engine ภายในที่ไม่มีใครวางออร์เดอร์
  /// (limit order ยังไม่เปิด) จึงว่างเปล่าตลอด
  Future<void> loadOrderBook() async {
    final pair = _selectedPair;
    try {
      final book = isDexPair(pair)
          ? await _api.getDexOrderbook(pair)
          : await _api.getOrderBook(pair);
      if (_disposed || pair != _selectedPair) return; // เปลี่ยนคู่ระหว่างรอ
      _orderBook = book;
      notifyListeners();
    } catch (e) {
      debugPrint('loadOrderBook error: ${e.runtimeType}');
    }
  }

  // ── Klines ──

  /// แท่งเทียนของคู่ที่เลือก — คู่บนเชน TPIX ใช้ /dex/klines (แท่งจากราคาพูลจริงทุกนาที)
  /// ไม่ใช้ /tpix/klines เพราะช่วงที่ไม่มีแท่งจริง เซิร์ฟเวอร์สร้างแท่งตัวอย่างขึ้นมาเอง
  Future<void> loadKlines({String interval = '1h', int limit = 100}) async {
    final pair = _selectedPair;
    try {
      final klines = isDexPair(pair)
          ? await _api.getDexKlines(pair, interval: interval, limit: limit)
          : await _api.getKlines(pair, interval: interval, limit: limit);
      if (_disposed || pair != _selectedPair) return;
      _klines = klines;
      notifyListeners();
    } catch (e) {
      debugPrint('loadKlines error: ${e.runtimeType}');
    }
  }

  // ── TPIX Price ──

  Future<void>? _tpixPriceInFlight;
  bool _disposed = false;

  /// โหลดราคา TPIX แล้วเขียนลงแถว TPIX-USDT ทันที
  ///
  /// ⚠️ เดิมราคานี้โหลดแข่งกับ loadTickers ตอนเปิดแอป — ถ้า tickers มาก่อน แถว TPIX
  ///    ถูกสร้างด้วยราคา 0 แล้วค้างอยู่อย่างนั้นจนรอบรีเฟรช 30 วิถัดไป (ปุ่มซื้อใช้ไม่ได้)
  ///    ตอนนี้ใครเสร็จทีหลังก็เขียนราคาลงแถวให้ถูกเสมอ + ลองซ้ำเมื่อพลาด
  /// เรียกซ้อนกันได้ — ได้คำขอเดียว
  Future<void> loadTpixPrice({int retries = 2}) {
    return _tpixPriceInFlight ??= _loadTpixPrice(retries).whenComplete(() {
      _tpixPriceInFlight = null;
    });
  }

  Future<void> _loadTpixPrice(int retries) async {
    for (var attempt = 0; attempt <= retries; attempt++) {
      try {
        TpixPrice? price;
        try {
          price = await _api.getTpixPrice();
        } catch (e) {
          debugPrint('loadTpixPrice server: ${e.runtimeType}');
        }
        if (_disposed) return;
        // เซิร์ฟเวอร์ไม่มีราคาตลาด (source: admin = ค่าที่ตั้งไว้/0.18) หรือไม่ตอบ
        // → อ่านราคากลางจากพูล WTPIX/USDT บนเชนเองก่อน ราคาตั้งไว้ใช้เมื่อไม่มีพูลจริง ๆ
        if (price == null || price.price <= 0 || !price.isMarket) {
          final pool = await _dex.tpixUsdtPoolPrice();
          if (_disposed) return;
          if (pool != null && pool > 0) price = TpixPrice.fromPool(pool);
        }
        if (price != null && price.price > 0) {
          _tpixPrice = price;
          _patchTpixTicker();
          _applyFilter();
          notifyListeners();
          return;
        }
      } catch (e) {
        debugPrint('loadTpixPrice error: ${e.runtimeType}');
      }
      if (attempt < retries) {
        // เน็ตสะดุดตอนเปิดแอปบ่อย — รอสั้น ๆ แล้วลองใหม่ (2 วิ, 4 วิ)
        await Future.delayed(Duration(seconds: 2 * (attempt + 1)));
        if (_disposed) return;
      }
    }
  }

  /// เขียนราคา TPIX ล่าสุดลงแถว TPIX-USDT (ถ้ามีแถวและรู้ราคาแล้ว)
  void _patchTpixTicker() {
    final p = _tpixPrice;
    if (p == null || p.price <= 0) return;
    final idx = _tickers.indexWhere((t) => t.symbol == 'TPIX-USDT');
    if (idx < 0) return;
    final t = _tickers[idx];
    // /tpix/price อ่านจากพูลบนเชน (source: dex) — เป็นราคาจริงของคู่นี้ ทับได้เสมอ
    _tickers[idx] = Ticker(
      symbol: t.symbol,
      baseAsset: t.baseAsset,
      quoteAsset: t.quoteAsset,
      lastPrice: p.price,
      priceChange: t.priceChange,
      priceChangePercent: p.change24h,
      high24h: t.high24h,
      low24h: t.low24h,
      volume24h: t.volume24h,
      quoteVolume24h: p.volume24h,
      isDex: true,
    );
  }

  @override
  void dispose() {
    _disposed = true;
    stopAutoRefresh();
    _depthSub?.cancel();
    super.dispose();
  }
}
