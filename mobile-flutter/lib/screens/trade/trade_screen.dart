/// TPIX TRADE — Trade Screen (Luxury Dark / Gilded Metal)
/// Pair header · gilded chart card with gold timeframe pills · depth-bar order
/// book · Buy/Sell order form. Sits on the gunmetal+gold backdrop with ambient
/// fireflies. All trading data is real (market + config + wallet providers).
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:typed_data';
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/config_provider.dart';
import '../../services/api_service.dart';
import '../../services/bsc_swap_service.dart';
import '../../models/bsc_trade_tokens.dart';
import '../../utils/crypto_logos.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/coin_logo.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/price_text.dart';
import '../../widgets/trading/trading_chart.dart';
import '../../widgets/trading/chart_type_toggle.dart';
import '../../models/api_models.dart';

class TradeScreen extends StatefulWidget {
  const TradeScreen({super.key});

  @override
  State<TradeScreen> createState() => _TradeScreenState();
}

class _TradeScreenState extends State<TradeScreen>
    with SingleTickerProviderStateMixin {
  late TabController _orderTypeTab;
  bool _isBuy = true;
  bool _isSubmitting = false;
  String _timeframe = '1h';
  String _chartType = 'candle';
  final _priceController = TextEditingController();
  final _amountController = TextEditingController();
  final _triggerPriceController = TextEditingController();
  final _chartKey = GlobalKey<TradingChartState>();

  // ── เทรดจริงบน BSC (สอดคล้องกับเว็บ) ──
  final _swapService = BscSwapService();
  // ยอดคงเหลือของ base/quote อ่านตรงจาก BSC RPC (null = ยังไม่โหลด)
  double? _bscBaseBalance;
  double? _bscQuoteBalance;
  String? _bscBalanceKey; // pair|address ล่าสุดที่โหลดไว้ — กันโหลดซ้ำ
  // Preview ราคาจริงจาก PancakeSwap router (debounce)
  BscSwapQuote? _marketQuote;
  bool _quotingPreview = false;
  Timer? _previewDebounce;
  int _previewSeq = 0;

  // Gold timeframe pills surfaced on the chart card.
  static const _pillTimeframes = ['15m', '1h', '4h', '1d', '1w'];
  static const _pillLabels = ['15m', '1H', '4H', '1D', '1W'];

  // index: 0=limit, 1=market, 2=stop-limit
  String get _orderType =>
      ['limit', 'market', 'stop-limit'][_orderTypeTab.index];
  bool get _isMarket => _orderTypeTab.index == 1;
  bool get _isStopLimit => _orderTypeTab.index == 2;

  @override
  void initState() {
    super.initState();
    // เริ่มที่ Market — คู่ major เทรดจริงได้เฉพาะ market order
    // (limit/stop-limit เปิดพร้อม TPIX Chain)
    _orderTypeTab = TabController(length: 3, vsync: this, initialIndex: 1);
    // Tab change ใช้ handler พิเศษ (ล้างค่าไม่เกี่ยวข้อง + rebuild)
    _orderTypeTab.addListener(_handleOrderTypeChange);
    _priceController.addListener(_rebuildOnInputChange);
    _amountController.addListener(_rebuildOnInputChange);
    _triggerPriceController.addListener(_rebuildOnInputChange);
    final market = context.read<MarketProvider>();
    market.loadOrderBook();
    market.loadKlines();
    // Refresh config ถ้า stale (เข้าหน้า trade อาจห่างจาก splash นาน)
    // + sync ConfigProvider กับ chain ปัจจุบันของ wallet (กัน fee mismatch
    //   หลัง backend preferences sync เปลี่ยน default_chain_id)
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final config = context.read<ConfigProvider>();
      final wallet = context.read<WalletProvider>();
      config.refreshIfStale();
      if (wallet.isConnected) {
        config.setActiveChain(wallet.activeChainId);
      }
    });
  }

  void _rebuildOnInputChange() {
    if (mounted) setState(() {});
    _schedulePreview();
  }

  // ── เทรดจริงบน BSC — helpers ──

  String _baseOf(MarketProvider market) =>
      market.selectedTicker?.baseAsset ??
      CryptoLogos.baseSymbol(market.selectedPair);

  String _quoteOf(MarketProvider market) {
    final t = market.selectedTicker;
    if (t != null && t.quoteAsset.isNotEmpty) return t.quoteAsset;
    final parts = market.selectedPair.split('-');
    return parts.length > 1 ? parts[1] : 'USDT';
  }

  bool _isTpixPairNow(MarketProvider market) =>
      CryptoLogos.isTpix(_baseOf(market));

  /// คู่นี้ execute จริงบน BSC ได้ไหม — base+quote ต้องอยู่ใน registry
  /// (ตรรกะเดียวกับเว็บ: TPIX pair = รอเชน TPIX, คู่แปลก = ยังไม่เปิด)
  bool _isBscTradableNow(MarketProvider market) =>
      !_isTpixPairNow(market) &&
      isBscTradablePair(_baseOf(market), _quoteOf(market));

  /// โหลดยอด base/quote จาก BSC RPC — เรียกซ้ำได้ (กันโหลดซ้ำด้วย key)
  void _maybeReloadBscBalances(MarketProvider market, WalletProvider wallet) {
    if (!_isBscTradableNow(market)) return;
    final key = '${market.selectedPair}|${wallet.address ?? ''}';
    if (key == _bscBalanceKey) return;
    _bscBalanceKey = key;
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadBscBalances());
  }

  Future<void> _loadBscBalances() async {
    final market = context.read<MarketProvider>();
    final wallet = context.read<WalletProvider>();
    if (!wallet.isConnected || !_isBscTradableNow(market)) {
      if (mounted) {
        setState(() {
          _bscBaseBalance = null;
          _bscQuoteBalance = null;
        });
      }
      return;
    }
    final base = _baseOf(market);
    final quote = _quoteOf(market);
    final address = wallet.address!;
    final results = await Future.wait([
      _swapService.getBalance(base, address),
      _swapService.getBalance(quote, address),
    ]);
    if (!mounted) return;
    setState(() {
      _bscBaseBalance = results[0];
      _bscQuoteBalance = results[1];
    });
  }

  /// ขอ quote จริงจาก router แบบ debounce (ตามที่ผู้ใช้พิมพ์จำนวน)
  void _schedulePreview() {
    _previewDebounce?.cancel();
    _previewDebounce =
        Timer(const Duration(milliseconds: 500), _refreshPreview);
  }

  Future<void> _refreshPreview() async {
    if (!mounted) return;
    final market = context.read<MarketProvider>();
    final wallet = context.read<WalletProvider>();

    if (!_isBscTradableNow(market) || !_isMarket) {
      if (_marketQuote != null || _quotingPreview) {
        setState(() {
          _marketQuote = null;
          _quotingPreview = false;
        });
      }
      return;
    }

    final amount = double.tryParse(_amountController.text.trim()) ?? 0;
    final price = market.selectedTicker?.lastPrice ?? 0;
    // buy: input จริงคือยอด quote (USDT) = amount × ราคาตลาด
    final input = _isBuy ? amount * price : amount;
    if (amount <= 0 || input <= 0) {
      if (_marketQuote != null || _quotingPreview) {
        setState(() {
          _marketQuote = null;
          _quotingPreview = false;
        });
      }
      return;
    }

    final seq = ++_previewSeq;
    setState(() => _quotingPreview = true);
    try {
      final fromSym = _isBuy ? _quoteOf(market) : _baseOf(market);
      final toSym = _isBuy ? _baseOf(market) : _quoteOf(market);
      final quote = await _swapService.getQuote(
        fromSymbol: fromSym,
        toSymbol: toSym,
        amount: input,
        slippageOverride: wallet.slippage,
      );
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _marketQuote = quote;
        _quotingPreview = false;
      });
    } catch (_) {
      if (!mounted || seq != _previewSeq) return;
      setState(() {
        _marketQuote = null;
        _quotingPreview = false;
      });
    }
  }

  /// ล้างค่า price/trigger ที่ไม่เกี่ยวข้องเมื่อ user สลับ tab
  /// กัน phantom data (เช่น กรอก price ใน Limit แล้วสลับไป Market
  /// ค่า price จะถูกซ่อนแต่ไม่หาย → กลับมา Limit เห็นค่าเก่า)
  /// Guard: TabController fires 2x (indexIsChanging + indexChanged) — รันตอน settled เท่านั้น
  void _handleOrderTypeChange() {
    if (!mounted) return;
    if (_orderTypeTab.indexIsChanging) return; // รอ animation เสร็จก่อน
    if (_isMarket && _priceController.text.isNotEmpty) {
      _priceController.clear();
    }
    if (!_isStopLimit && _triggerPriceController.text.isNotEmpty) {
      _triggerPriceController.clear();
    }
    // Rebuild เพื่อ show/hide inputs (ถ้า controller ว่างอยู่แล้ว clear ไม่ trigger)
    setState(() {});
    _schedulePreview();
  }

  @override
  void dispose() {
    _previewDebounce?.cancel();
    _orderTypeTab.removeListener(_handleOrderTypeChange);
    _priceController.removeListener(_rebuildOnInputChange);
    _amountController.removeListener(_rebuildOnInputChange);
    _triggerPriceController.removeListener(_rebuildOnInputChange);
    _orderTypeTab.dispose();
    _priceController.dispose();
    _amountController.dispose();
    _triggerPriceController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final market = context.watch<MarketProvider>();
    final wallet = context.watch<WalletProvider>();
    final ticker = market.selectedTicker;
    final isTpix = CryptoLogos.isTpix(
      CryptoLogos.baseSymbol(market.selectedPair),
    );

    // คู่ major → โหลดยอดจาก BSC สำหรับฟอร์มเทรด (โหลดครั้งเดียวต่อ pair/wallet)
    _maybeReloadBscBalances(market, wallet);

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: Column(
            children: [
              _buildPairHeader(market, ticker, locale),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.only(bottom: 110),
                  child: Column(
                    children: [
                      // Gilded chart card — gold timeframe pills + chart widget
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
                        child: _buildChartCard(market, locale, isTpix),
                      ),

                      const SizedBox(height: 14),

                      // Order book
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: _buildOrderBook(market, locale),
                      ),

                      const SizedBox(height: 16),

                      // Trade form
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: _buildTradeForm(locale, wallet, market),
                      ),

                      // Open Orders
                      if (wallet.isConnected && wallet.openOrders.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                          child: _buildOpenOrders(wallet, locale),
                        ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Pair header ──

  Widget _buildPairHeader(
      MarketProvider market, Ticker? ticker, LocaleProvider locale) {
    final isFav =
        ticker != null && market.favorites.contains(ticker.symbol);

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 6),
      child: Row(
        children: [
          GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: () => _showPairPicker(market),
            child: Row(
              children: [
                CoinChip(
                  symbol: ticker?.baseAsset ??
                      CryptoLogos.baseSymbol(market.selectedPair),
                  size: 40,
                  logoUrl: ticker == null
                      ? null
                      : context
                          .read<ConfigProvider>()
                          .pairBySymbol(ticker.symbol)
                          ?.baseLogo,
                ),
                const SizedBox(width: 11),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Text(
                          ticker?.displaySymbol ?? market.selectedPair,
                          style: GoogleFonts.inter(
                            fontSize: 19,
                            fontWeight: FontWeight.w800,
                            color: AppColors.textPrimary,
                            letterSpacing: -0.3,
                          ),
                        ),
                        const SizedBox(width: 4),
                        const Icon(Icons.keyboard_arrow_down_rounded,
                            color: AppColors.textTertiary, size: 20),
                      ],
                    ),
                    const SizedBox(height: 1),
                    // คู่ major เทรดจริงบน BSC — คู่ TPIX รอเชน TPIX เปิด
                    Text(
                      _isBscTradableNow(market)
                          ? 'BSC · PancakeSwap'
                          : 'TPIX Chain · Coming soon',
                      style: GoogleFonts.inter(
                        fontSize: 10.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textTertiary,
                        letterSpacing: 0.4,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          // Favorite star (real — MarketProvider.toggleFavorite)
          if (ticker != null)
            GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => market.toggleFavorite(ticker.symbol),
              child: Padding(
                padding: const EdgeInsets.all(4),
                child: Icon(
                  isFav ? Icons.star_rounded : Icons.star_border_rounded,
                  size: 22,
                  color: isFav
                      ? context.watch<AccentProvider>().g2
                      : AppColors.textTertiary,
                ),
              ),
            ),
          const Spacer(),
          if (ticker != null)
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                PriceText(
                  price: ticker.lastPrice,
                  change: ticker.priceChangePercent,
                  fontSize: 17,
                  fontWeight: FontWeight.w700,
                ),
                const SizedBox(height: 3),
                ChangeBadge(changePercent: ticker.priceChangePercent),
              ],
            ),
        ],
      ),
    );
  }

  // ── Chart card (gilded hero) ──

  Widget _buildChartCard(
      MarketProvider market, LocaleProvider locale, bool isTpix) {
    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: AppTheme.radiusHero,
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 14),
      child: Column(
        children: [
          // Timeframe pills + chart-type toggle row
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 30,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    padding: EdgeInsets.zero,
                    itemCount: _pillTimeframes.length,
                    separatorBuilder: (_, _) => const SizedBox(width: 6),
                    itemBuilder: (_, i) => _TimeframePill(
                      label: _pillLabels[i],
                      active: _timeframe == _pillTimeframes[i],
                      onTap: () {
                        if (_timeframe == _pillTimeframes[i]) return;
                        setState(() => _timeframe = _pillTimeframes[i]);
                        _chartKey.currentState
                            ?.changeTimeframe(_pillTimeframes[i]);
                      },
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              ChartTypeToggle(
                selected: _chartType,
                onChanged: (type) {
                  setState(() => _chartType = type);
                  _chartKey.currentState?.setChartType(type);
                },
              ),
            ],
          ),
          const SizedBox(height: 12),
          // TradingView Chart (WebView) — unchanged logic
          ClipRRect(
            borderRadius: BorderRadius.circular(AppTheme.radiusLg),
            child: TradingChart(
              key: _chartKey,
              symbol: market.selectedPair,
              interval: _timeframe,
              isTpix: isTpix,
              height: 300,
            ),
          ),
        ],
      ),
    );
  }

  // ── Order book ──

  Widget _buildOrderBook(MarketProvider market, LocaleProvider locale) {
    final ob = market.orderBook;

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: AppTheme.radiusLg,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.menu_rounded, size: 15, color: AppColors.gold2),
              const SizedBox(width: 7),
              Text(
                locale.t('trade.orderbook'),
                style: GoogleFonts.inter(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // Header
          Row(
            children: [
              Expanded(
                child: Text(locale.t('trade.price'), style: _obHeaderStyle),
              ),
              Expanded(
                child: Text(locale.t('trade.amount'),
                    style: _obHeaderStyle, textAlign: TextAlign.right),
              ),
              Expanded(
                child: Text(locale.t('trade.total'),
                    style: _obHeaderStyle, textAlign: TextAlign.right),
              ),
            ],
          ),
          const SizedBox(height: 6),

          // Asks (sell) — top 5, depth bars grow from the right
          if (ob != null)
            ...ob.asks.take(5).toList().reversed.map(
                  (entry) => _OrderBookRow(
                    entry: entry,
                    isBid: false,
                    maxQty: _maxQty(ob.asks),
                  ),
                ),

          // Spread — gold line in the middle
          if (ob != null && ob.asks.isNotEmpty && ob.bids.isNotEmpty)
            Container(
              margin: const EdgeInsets.symmetric(vertical: 6),
              padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 10),
              decoration: BoxDecoration(
                color: AppColors.goldTint,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: AppColors.goldBorder,
                  width: 0.8,
                ),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    locale.t('common.spread'),
                    style: GoogleFonts.inter(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: AppColors.gold2,
                      letterSpacing: 0.4,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    (ob.asks.first.price - ob.bids.first.price)
                        .toStringAsFixed(2),
                    style: AppTheme.mono(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.gold2,
                    ),
                  ),
                ],
              ),
            ),

          // Bids (buy) — top 5, depth bars grow from the left
          if (ob != null)
            ...ob.bids.take(5).map(
                  (entry) => _OrderBookRow(
                    entry: entry,
                    isBid: true,
                    maxQty: _maxQty(ob.bids),
                  ),
                ),

          if (ob == null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 20),
              child: Center(
                child: Text(locale.t('common.loading'),
                    style: const TextStyle(
                        color: AppColors.textTertiary, fontSize: 12)),
              ),
            ),
        ],
      ),
    );
  }

  double _maxQty(List<OrderBookEntry> entries) {
    if (entries.isEmpty) return 1;
    return entries
        .take(5)
        .fold(0.0, (max, e) => e.quantity > max ? e.quantity : max);
  }

  TextStyle get _obHeaderStyle => GoogleFonts.inter(
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: AppColors.textTertiary,
        letterSpacing: 0.3,
      );

  // ── Trade form ──

  Widget _buildTradeForm(
      LocaleProvider locale, WalletProvider wallet, MarketProvider market) {
    final ticker = market.selectedTicker;
    final baseAsset = ticker?.baseAsset ?? 'BTC';
    final quoteAsset = ticker?.quoteAsset ?? 'USDT';
    final tradable = _isBscTradableNow(market);

    // คู่ที่ยังเทรดจริงไม่ได้ (TPIX รอเชน / คู่ไม่มี token บน BSC)
    // → โชว์ Coming Soon แทนฟอร์ม (เห็นไว้ก่อน กดไม่ได้ — ตรงกับเว็บ)
    if (!tradable) {
      return _buildComingSoonCard(locale, market);
    }

    // Available balance — คู่ major อ่านยอดจริงจาก BSC RPC โดยตรง
    // (ไม่ใช่ portfolio off-chain เดิม เพราะเทรด settle บน BSC จริง)
    final availAsset = _isBuy ? quoteAsset : baseAsset;
    final bscBalance = _isBuy ? _bscQuoteBalance : _bscBaseBalance;
    final availBalance = bscBalance ?? 0.0;

    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: AppTheme.radiusXl,
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          // Segmented Buy / Sell
          Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              color: AppColors.bgInputStrong,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.bgCardBorder),
            ),
            child: Row(
              children: [
                Expanded(
                  child: _SideTab(
                    label: locale.t('trade.buy'),
                    active: _isBuy,
                    isBuy: true,
                    onTap: () => setState(() => _isBuy = true),
                  ),
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: _SideTab(
                    label: locale.t('trade.sell'),
                    active: !_isBuy,
                    isBuy: false,
                    onTap: () => setState(() => _isBuy = false),
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 14),

          // Order type tabs (Limit / Market / Stop-limit)
          Container(
            decoration: BoxDecoration(
              color: AppColors.bgInputStrong,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.bgCardBorder),
            ),
            child: TabBar(
              controller: _orderTypeTab,
              // Limit/Stop-limit ยังไม่เปิดสำหรับเทรดจริงบน BSC (AMM ทำ
              // limit order ไม่ได้) — เห็นไว้ก่อน กดแล้วเด้งกลับ Market
              onTap: (index) {
                if (index != 1) {
                  _orderTypeTab.index = 1;
                  _showSnack(locale.isThai
                      ? 'Limit/Stop-limit เปิดพร้อม TPIX Chain — ตอนนี้ใช้ Market'
                      : 'Limit & stop-limit open with TPIX Chain — use Market for now');
                }
              },
              indicator: BoxDecoration(
                color: AppColors.bgTertiary,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.goldBorder, width: 1),
              ),
              indicatorSize: TabBarIndicatorSize.tab,
              indicatorPadding: const EdgeInsets.all(3),
              labelColor: AppColors.gold1,
              unselectedLabelColor: AppColors.textTertiary,
              labelStyle:
                  GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.w700),
              unselectedLabelStyle:
                  GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.w500),
              dividerColor: Colors.transparent,
              tabs: [
                Tab(height: 34, child: _soonTabLabel(locale.t('trade.limit'))),
                Tab(text: locale.t('trade.market'), height: 34),
                Tab(
                    height: 34,
                    child: _soonTabLabel(locale.t('trade.stop_limit'))),
              ],
            ),
          ),

          const SizedBox(height: 14),

          // Available balance row
          Row(
            children: [
              Icon(Icons.account_balance_wallet_rounded,
                  size: 13, color: AppColors.textTertiary),
              const SizedBox(width: 6),
              Text(
                locale.isThai ? 'ยอดที่ใช้ได้' : 'Available',
                style: GoogleFonts.inter(
                    fontSize: 11.5, color: AppColors.textTertiary),
              ),
              const Spacer(),
              Text(
                wallet.isConnected
                    ? '${_fmtAmount(availBalance)} $availAsset'
                    : '—',
                style: AppTheme.mono(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),

          const SizedBox(height: 12),

          // Trigger Price input (stop-limit only) — อธิบาย semantics
          if (_isStopLimit) ...[
            _TradeInput(
              label: locale.t('trade.trigger_price'),
              controller: _triggerPriceController,
              suffix: quoteAsset,
            ),
            const SizedBox(height: 4),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Text(
                _isBuy
                    ? (locale.isThai
                        ? 'ทริกเกอร์เมื่อราคาขึ้นถึง (ซื้อตอนราคาขึ้นทะลุ)'
                        : 'Triggers when price rises to this level (buy breakout)')
                    : (locale.isThai
                        ? 'ทริกเกอร์เมื่อราคาลงถึง (ขายตอนราคาหลุด)'
                        : 'Triggers when price falls to this level (sell stop)'),
                style: GoogleFonts.inter(
                  fontSize: 10,
                  color: AppColors.textTertiary,
                  fontStyle: FontStyle.italic,
                ),
              ),
            ),
            const SizedBox(height: 10),
          ],

          // Price input (ซ่อนใน market order)
          if (!_isMarket) ...[
            _TradeInput(
              label: locale.t('trade.price'),
              controller: _priceController,
              suffix: quoteAsset,
            ),
            const SizedBox(height: 10),
          ],

          // Amount input
          _TradeInput(
            label: locale.t('trade.amount'),
            controller: _amountController,
            suffix: baseAsset,
          ),
          const SizedBox(height: 12),

          // Amount quick chips: 25/50/75/100% of available balance
          Row(
            children: ['25%', '50%', '75%', '100%'].map((pct) {
              final isLast = pct == '100%';
              return Expanded(
                child: Padding(
                  padding: EdgeInsets.only(right: isLast ? 0 : 8),
                  child: _QuickChip(
                    label: pct,
                    onTap: () => _applyQuickPercent(
                      pct,
                      wallet,
                      market,
                      availBalance,
                    ),
                  ),
                ),
              );
            }).toList(),
          ),

          const SizedBox(height: 12),

          // Market preview — ตัวเลขจริงจาก PancakeSwap router
          // (แทน fee summary เดิมเมื่อมี quote เพราะ fee ใน quote คือของจริง)
          if (_isMarket && (_marketQuote != null || _quotingPreview))
            _buildMarketPreview(locale, market)
          else
            _buildFeeSummary(locale, market),

          const SizedBox(height: 14),

          // Submit CTA — green for Buy, red for Sell
          GradientButton(
            text: _isBuy
                ? '${locale.t('trade.buy')} $baseAsset'
                : '${locale.t('trade.sell')} $baseAsset',
            variant: _isBuy ? ButtonVariant.buy : ButtonVariant.sell,
            isLoading: _isSubmitting,
            onPressed: wallet.isConnected && !_isSubmitting
                ? () => _submitOrder()
                : null,
          ),

          // แถบบอกว่าเทรดจริงบน BSC — ให้ผู้ใช้รู้ว่าเหรียญเข้ากระเป๋าจริง
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.bolt_rounded, size: 12, color: AppColors.gold2),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  locale.isThai
                      ? 'คำสั่ง Market ทำงานจริงบน BSC ผ่าน PancakeSwap'
                      : 'Market orders execute for real on BSC via PancakeSwap',
                  style: GoogleFonts.inter(
                      fontSize: 10, color: AppColors.textTertiary),
                  textAlign: TextAlign.center,
                ),
              ),
            ],
          ),

          // Linked wallet — เทรดผ่าน TPIX Wallet: แอพจะสลับไปให้ยืนยัน
          // แต่ละธุรกรรม (approve → swap → fee) แล้วเด้งกลับมาเอง
          if (wallet.isConnected && wallet.isLinkedWallet) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: AppColors.goldTint,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AppColors.goldBorder, width: 0.8),
              ),
              child: Row(
                children: [
                  Icon(Icons.swap_calls_rounded,
                      size: 14, color: AppColors.gold2),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      locale.isThai
                          ? 'จะเปิด TPIX Wallet ให้ยืนยันธุรกรรมทีละรายการ แล้วกลับมาที่นี่อัตโนมัติ'
                          : 'TPIX Wallet will open to confirm each transaction, then return here automatically.',
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],

          if (!wallet.isConnected) ...[
            const SizedBox(height: 10),
            Text(
              locale.t('settings.connect_wallet'),
              style: GoogleFonts.inter(
                  fontSize: 12, color: AppColors.textTertiary),
            ),
          ],

          // Linked-wallet hint — แจ้ง user ว่า submit จะส่ง sign request ไป
          // TPIX Wallet (กัน user งงว่าทำไมแอพสลับไป-มา)
          if (wallet.isConnected &&
              wallet.isLinkedWallet &&
              !wallet.isVerified) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: AppColors.goldTint,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AppColors.goldBorder, width: 0.8),
              ),
              child: Row(
                children: [
                  Icon(Icons.info_outline_rounded,
                      size: 14, color: AppColors.gold2),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      locale.isThai
                          ? 'ครั้งแรก: แอพจะเปิด TPIX Wallet ให้เซ็นยืนยันตัวตน'
                          : 'First time: TPIX Wallet will open to confirm your identity',
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// Quick-percent fill: prefer % of available balance; if balance unknown
  /// (not connected / zero), fall back to % of the current amount entry.
  void _applyQuickPercent(
    String pct,
    WalletProvider wallet,
    MarketProvider market,
    double availBalance,
  ) {
    final factor = double.parse(pct.replaceAll('%', '')) / 100;

    if (wallet.isConnected && availBalance > 0) {
      // For Buy, available is in quote → convert to base amount via price.
      if (_isBuy) {
        final px = _isMarket
            ? (market.selectedTicker?.lastPrice ?? 0)
            : (double.tryParse(_priceController.text) ?? 0);
        if (px > 0) {
          _amountController.text =
              (availBalance * factor / px).toStringAsFixed(4);
          return;
        }
        // No usable price yet — leave amount untouched.
        return;
      }
      // Sell → available already in base asset.
      _amountController.text = (availBalance * factor).toStringAsFixed(4);
      return;
    }

    // Fallback: scale whatever the user already typed.
    final current = double.tryParse(_amountController.text) ?? 0;
    if (current > 0) {
      _amountController.text = (current * factor).toStringAsFixed(4);
    }
  }

  String _fmtAmount(double v) {
    if (v >= 1000) return v.toStringAsFixed(2);
    if (v >= 1) return v.toStringAsFixed(4);
    return v.toStringAsFixed(6);
  }

  /// ป้ายแท็บ order type ที่ยังไม่เปิด (Limit/Stop-limit) — เห็นแต่กดไม่ได้
  Widget _soonTabLabel(String label) {
    return FittedBox(
      fit: BoxFit.scaleDown,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Opacity(opacity: 0.55, child: Text(label)),
          const SizedBox(width: 3),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 1),
            decoration: BoxDecoration(
              color: const Color(0xFFF59E0B).withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(3),
            ),
            child: Text(
              'Soon',
              style: GoogleFonts.inter(
                fontSize: 7,
                fontWeight: FontWeight.w700,
                color: const Color(0xFFF59E0B),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// คู่ที่ยังไม่เปิดเทรด (TPIX รอเชน) — แผง Coming Soon แทนฟอร์ม
  Widget _buildComingSoonCard(LocaleProvider locale, MarketProvider market) {
    final pair = market.selectedTicker?.displaySymbol ?? market.selectedPair;
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: AppTheme.radiusXl,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 28),
      child: Column(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: AppColors.goldTint,
              shape: BoxShape.circle,
              border: Border.all(color: AppColors.goldBorder, width: 1),
            ),
            child: Icon(Icons.lock_clock_rounded,
                size: 24, color: AppColors.gold2),
          ),
          const SizedBox(height: 12),
          Text(
            '$pair — Coming Soon',
            style: GoogleFonts.inter(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            locale.isThai
                ? 'เปิดเทรดพร้อม TPIX Chain เร็วๆ นี้\nระหว่างนี้เทรดคู่เหรียญหลักได้จริงบน BSC'
                : 'Trading opens with TPIX Chain launch.\nMeanwhile, trade major pairs live on BSC.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              fontSize: 11.5,
              height: 1.5,
              color: AppColors.textTertiary,
            ),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: const Color(0xFFF59E0B).withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'TPIX Chain — Coming Soon',
              style: GoogleFonts.inter(
                fontSize: 10,
                fontWeight: FontWeight.w700,
                color: const Color(0xFFF59E0B),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Preview ตัวเลขจริงจาก router — You receive / Min received / Fee
  Widget _buildMarketPreview(LocaleProvider locale, MarketProvider market) {
    final q = _marketQuote;
    final toSym = _isBuy ? _baseOf(market) : _quoteOf(market);
    final fromSym = _isBuy ? _quoteOf(market) : _baseOf(market);

    Widget row(String label, String? value, {Color? valueColor}) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            Text(label,
                style: GoogleFonts.inter(
                    fontSize: 11, color: AppColors.textTertiary)),
            const Spacer(),
            if (value == null)
              const SizedBox(
                width: 60,
                height: 10,
                child: LinearProgressIndicator(minHeight: 2),
              )
            else
              Text(value,
                  style: AppTheme.mono(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: valueColor ?? AppColors.textSecondary,
                  )),
          ],
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.goldBorder, width: 0.8),
      ),
      child: Column(
        children: [
          row(
            locale.isThai ? 'จะได้รับ ≈' : 'You receive ≈',
            q == null ? null : '${_fmtAmount(q.netOutput)} $toSym',
            valueColor: AppColors.textPrimary,
          ),
          row(
            locale.isThai ? 'ขั้นต่ำที่ได้รับ' : 'Min received',
            q == null ? null : '${_fmtAmount(q.minReceived)} $toSym',
          ),
          row(
            locale.isThai
                ? 'ค่าธรรมเนียม (${q?.feeRate.toStringAsFixed(2) ?? '—'}%)'
                : 'Fee (${q?.feeRate.toStringAsFixed(2) ?? '—'}%)',
            q == null ? null : '${_fmtAmount(q.feeAmount)} $fromSym',
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              Icon(Icons.verified_rounded, size: 10, color: AppColors.gold2),
              const SizedBox(width: 4),
              Text(
                locale.isThai
                    ? 'ราคาจริงจาก PancakeSwap (BSC)'
                    : 'Live price from PancakeSwap (BSC)',
                style: GoogleFonts.inter(
                    fontSize: 9, color: AppColors.textTertiary),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ── Fee Summary ──

  Widget _buildFeeSummary(LocaleProvider locale, MarketProvider market) {
    final config = context.watch<ConfigProvider>();
    if (!config.isReady) return const SizedBox.shrink();

    final feeRate = config.feeRateForPair(market.selectedPair);
    final pair = config.pairBySymbol(market.selectedPair);

    // คำนวณ estimated fee จาก amount ที่ user กรอก
    final amount = double.tryParse(_amountController.text) ?? 0;
    final price = double.tryParse(_priceController.text) ?? 0;
    final total = (_orderTypeTab.index == 1) // market
        ? amount * (market.selectedTicker?.lastPrice ?? 0)
        : amount * price;
    final feeAmount = total * (feeRate / 100);
    final quoteAsset = market.selectedTicker?.quoteAsset ?? 'USDT';

    // แสดง warning ถ้าเกิน min/max
    String? warning;
    if (pair != null && amount > 0) {
      if (pair.minTradeAmount > 0 && amount < pair.minTradeAmount) {
        warning = locale.isThai
            ? 'ต่ำกว่าขั้นต่ำ ${pair.minTradeAmount}'
            : 'Below min ${pair.minTradeAmount}';
      } else if (pair.maxTradeAmount > 0 && amount > pair.maxTradeAmount) {
        warning = locale.isThai
            ? 'เกินสูงสุด ${pair.maxTradeAmount}'
            : 'Above max ${pair.maxTradeAmount}';
      }
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: warning != null
              ? AppColors.tradingRed
              : AppColors.bgCardBorder,
        ),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Icon(Icons.receipt_long_rounded,
                  size: 13, color: AppColors.textTertiary),
              const SizedBox(width: 6),
              Text(
                locale.isThai ? 'ค่าธรรมเนียม' : 'Fee',
                style: GoogleFonts.inter(
                  fontSize: 11,
                  color: AppColors.textTertiary,
                ),
              ),
              const Spacer(),
              Text(
                '${feeRate.toStringAsFixed(2)}%',
                style: AppTheme.mono(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.gold2,
                ),
              ),
              if (feeAmount > 0 && amount > 0) ...[
                const SizedBox(width: 6),
                Text(
                  '(~${feeAmount.toStringAsFixed(2)} $quoteAsset)',
                  style: AppTheme.mono(
                      fontSize: 10, color: AppColors.textTertiary),
                ),
              ],
            ],
          ),
          if (warning != null) ...[
            const SizedBox(height: 6),
            Row(
              children: [
                const Icon(Icons.warning_amber_rounded,
                    size: 12, color: AppColors.tradingRed),
                const SizedBox(width: 6),
                Text(
                  warning,
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    color: AppColors.tradingRed,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ── Open Orders ──

  Widget _buildOpenOrders(WalletProvider wallet, LocaleProvider locale) {
    final orders = wallet.openOrders
        .where((o) => o.pair == context.read<MarketProvider>().selectedPair)
        .toList();

    if (orders.isEmpty) return const SizedBox.shrink();

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: AppTheme.radiusLg,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.pending_actions_rounded,
                  size: 15, color: AppColors.gold2),
              const SizedBox(width: 7),
              Text(
                locale.t('trade.open_orders'),
                style: GoogleFonts.inter(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          ...orders.map((order) => _OpenOrderRow(
                order: order,
                onCancel: () => _cancelOrder(order.id, wallet),
              )),
        ],
      ),
    );
  }

  Future<void> _cancelOrder(String orderId, WalletProvider wallet) async {
    if (wallet.address == null) return;
    final ok = await ApiService().cancelOrder(orderId, wallet.address!);
    if (!mounted) return;
    if (ok) {
      wallet.loadPortfolio();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order cancelled')),
      );
    }
  }

  // ── Pair picker ──

  void _showPairPicker(MarketProvider market) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true, // กัน Android nav bar บัง content ด้านล่าง
      backgroundColor: Colors.transparent,
      builder: (_) => _PairPickerSheet(
        market: market,
        onSelect: (pair) {
          market.selectPair(pair);
          Navigator.pop(context);
        },
      ),
    );
  }

  // ── Submit order ──
  // Dispatcher — แยกเส้นทางเหมือนเว็บ:
  //  คู่ major (BSC registry) → market order execute จริงผ่าน PancakeSwap
  //  คู่ TPIX               → Coming Soon (รอเชน TPIX — ฟอร์มถูกซ่อนอยู่แล้ว)
  //  คู่อื่น                → internal order book เดิม (เปิดคืนพร้อม TPIX Chain)

  Future<void> _submitOrder() async {
    if (_isSubmitting) return;

    final wallet = context.read<WalletProvider>();
    final market = context.read<MarketProvider>();
    final locale = context.read<LocaleProvider>();
    final config = context.read<ConfigProvider>();

    if (!wallet.isConnected || wallet.address == null) return;

    if (_isBscTradableNow(market)) {
      await _executeBscMarketOrder(wallet, market, locale);
      return;
    }

    if (_isTpixPairNow(market)) {
      // กันไว้อีกชั้น — ฟอร์ม TPIX ถูกแทนด้วย Coming Soon card อยู่แล้ว
      _showSnack(locale.isThai
          ? 'คู่ TPIX เปิดเทรดพร้อม TPIX Chain — เร็วๆ นี้'
          : 'TPIX pairs open with TPIX Chain — coming soon');
      return;
    }

    // ตรวจก่อนว่า backend พร้อมเทรด (มี fee wallet ตั้งค่าแล้ว)
    if (!config.canTrade) {
      _showSnack(locale.isThai
          ? 'ระบบยังไม่พร้อมให้เทรด — ติดต่อผู้ดูแล'
          : 'Platform not ready — contact admin');
      return;
    }

    final priceText = _priceController.text.trim();
    final amountText = _amountController.text.trim();
    final triggerText = _triggerPriceController.text.trim();
    final orderType = _orderType; // 'limit' | 'market' | 'stop-limit'

    final amount = double.tryParse(amountText);
    if (amount == null || amount <= 0) {
      _showSnack(locale.t('trade.invalid_amount'));
      return;
    }

    // Validate min/max จาก TradingPair config
    final pair = config.pairBySymbol(market.selectedPair);
    if (pair != null) {
      if (pair.minTradeAmount > 0 && amount < pair.minTradeAmount) {
        _showSnack(locale.isThai
            ? 'จำนวนต่ำกว่าขั้นต่ำ ${pair.minTradeAmount}'
            : 'Amount below min ${pair.minTradeAmount}');
        return;
      }
      if (pair.maxTradeAmount > 0 && amount > pair.maxTradeAmount) {
        _showSnack(locale.isThai
            ? 'จำนวนเกินสูงสุด ${pair.maxTradeAmount}'
            : 'Amount above max ${pair.maxTradeAmount}');
        return;
      }
    }

    // Trade เป็น index/proxy — ทุกคู่ register บน TPIX chain (4289)
    // ราคาดึง realtime จาก Binance, balance off-chain ใน DB ระบบ trade
    // → ไม่ต้องสลับ wallet chain เพื่อเทรด (chain_id เป็น metadata เท่านั้น)
    // wallet จะอยู่เชนไหนก็เทรดได้ — chain switching ใช้สำหรับ Bridge/Send/Receive ต่างหาก

    double? price;
    if (orderType != 'market') {
      price = double.tryParse(priceText);
      if (price == null || price <= 0) {
        _showSnack(locale.t('trade.invalid_price'));
        return;
      }
    }

    // Stop-limit ต้องมี trigger price
    double? triggerPrice;
    if (orderType == 'stop-limit') {
      triggerPrice = double.tryParse(triggerText);
      if (triggerPrice == null || triggerPrice <= 0) {
        _showSnack(locale.t('trade.invalid_trigger'));
        return;
      }
    }

    setState(() => _isSubmitting = true);

    // ถ้ายังไม่ verified → verify ก่อนเพื่อได้ auth token
    if (!wallet.isVerified) {
      final ok = await wallet.verifyWithBackend();
      if (!mounted) return;
      if (!ok) {
        setState(() => _isSubmitting = false);
        _showSnack(locale.isThai
            ? 'ยืนยันกระเป๋าไม่สำเร็จ — ลองอีกครั้ง'
            : 'Wallet verification failed — try again');
        return;
      }
    }

    try {
      final order = await ApiService().createOrder(
        pair: market.selectedPair,
        side: _isBuy ? 'buy' : 'sell',
        type: orderType,
        price: price,
        triggerPrice: triggerPrice,
        amount: amount,
        walletAddress: wallet.address!,
        chainId: 4289, // TPIX-only — see comment above pre-submit block
      );

      if (!mounted) return;

      if (order != null) {
        _priceController.clear();
        _amountController.clear();
        _showSnack(locale.t('trade.order_success'), isSuccess: true);
        market.loadOrderBook();
        wallet.loadPortfolio();
      } else {
        _showSnack(locale.t('trade.order_failed'));
      }
    } catch (e) {
      if (!mounted) return;
      _showSnack(locale.t('common.error'));
    }

    if (mounted) setState(() => _isSubmitting = false);
  }

  /// Market order เทรดจริงบน BSC ผ่าน PancakeSwap — ลำดับเหมือนเว็บทุกขั้น:
  /// ตรวจ token on-chain → quote จริง → กันราคาเพี้ยน (>10% ไม่ส่ง)
  /// → approve ถ้าจำเป็น → swap → เก็บ fee → บันทึก backend → refresh ยอด
  Future<void> _executeBscMarketOrder(
    WalletProvider wallet,
    MarketProvider market,
    LocaleProvider locale,
  ) async {
    if (!_isMarket) {
      _showSnack(locale.isThai
          ? 'Limit/Stop-limit เปิดพร้อม TPIX Chain — ตอนนี้ใช้ Market'
          : 'Limit & stop-limit open with TPIX Chain — use Market for now');
      return;
    }

    final amount = double.tryParse(_amountController.text.trim());
    if (amount == null || amount <= 0) {
      _showSnack(locale.t('trade.invalid_amount'));
      return;
    }

    final marketPrice = market.selectedTicker?.lastPrice ?? 0;
    if (marketPrice <= 0) {
      _showSnack(locale.isThai
          ? 'ราคาตลาดยังไม่พร้อม ลองใหม่อีกครั้ง'
          : 'Market price unavailable — try again.');
      return;
    }

    final base = _baseOf(market);
    final quote = _quoteOf(market);
    final fromSym = _isBuy ? quote : base;
    final toSym = _isBuy ? base : quote;
    // buy: จ่าย quote (USDT) = จำนวน base × ราคาตลาด, sell: จ่าย base ตรงๆ
    final inputAmount = _isBuy ? amount * marketPrice : amount;

    setState(() => _isSubmitting = true);

    try {
      // 1) Quote จริงจาก router (ตรวจ token กับ on-chain ในตัว — fail-closed)
      final swapQuote = await _swapService.getQuote(
        fromSymbol: fromSym,
        toSymbol: toSym,
        amount: inputAmount,
        slippageOverride: wallet.slippage,
      );

      // 2) กันราคา on-chain เพี้ยนจากราคาตลาดเกิน 10% (สภาพคล่องบาง/pool ผิดปกติ)
      if (swapQuote.netOutput > 0) {
        final effPrice = _isBuy
            ? swapQuote.amountIn / swapQuote.netOutput // จ่าย USDT ต่อ 1 base
            : swapQuote.netOutput / swapQuote.amountIn; // ได้ USDT ต่อ 1 base
        final deviation = (effPrice - marketPrice).abs() / marketPrice;
        if (deviation > 0.10) {
          throw const SwapException(
            'On-chain price differs too much from market price. Try a smaller amount.',
            'ราคาบนเชนต่างจากราคาตลาดมากเกินไป ลองจำนวนน้อยลง',
          );
        }
      }

      // 3) Approve router ถ้า allowance ไม่พอ (เฉพาะ token ไม่ใช่ BNB)
      if (!swapQuote.fromToken.native) {
        final hasAllowance = await _swapService.hasAllowance(
          swapQuote.fromToken,
          wallet.address!,
          swapQuote.amountInSwapWei,
        );
        if (!hasAllowance) {
          if (mounted) {
            _showSnack(locale.isThai
                ? 'กำลังขอ approve $fromSym — ยืนยันในกระเป๋า'
                : 'Approving $fromSym — confirm in your wallet');
          }
          final approveHash = await wallet.sendBscTransaction(
            to: swapQuote.fromToken.address,
            data: _swapService.approveCalldata(),
            summary: 'Approve $fromSym for PancakeSwap router',
          );
          if (approveHash == null) {
            throw const SwapException(
                'Approval rejected.', 'การ approve ถูกปฏิเสธ');
          }
          final approved = await _swapService.waitConfirmed(approveHash);
          if (!approved) {
            throw const SwapException(
                'Approval not confirmed — try again.',
                'การ approve ยังไม่ยืนยัน ลองใหม่อีกครั้ง');
          }
        }
      }

      // 4) ส่ง swap จริง — service เก็บ fee + บันทึก backend ให้ครบ
      // ห่อ sender เพื่อแนบ summary ให้ TPIX Wallet โชว์ตอนยืนยัน (linked)
      final actionText =
          '${_isBuy ? 'Buy' : 'Sell'} $base — TPIX Trade market order';
      final result = await _swapService.executeMarketSwap(
        quote: swapQuote,
        walletAddress: wallet.address!,
        sendTx: ({required String to, Uint8List? data, BigInt? value}) =>
            wallet.sendBscTransaction(
          to: to,
          data: data,
          value: value,
          summary: actionText,
        ),
      );

      if (!mounted) return;

      _amountController.clear();
      _marketQuote = null;
      final received = '${_fmtAmount(swapQuote.netOutput)} $toSym';
      _showSnack(
        result.confirmed
            ? (locale.isThai
                ? '${_isBuy ? 'ซื้อ' : 'ขาย'}สำเร็จ ≈ $received บน BSC'
                : '${_isBuy ? 'Bought' : 'Sold'} ≈ $received on BSC')
            : (locale.isThai
                ? 'ส่งธุรกรรมแล้ว กำลังรอยืนยันบนเชน'
                : 'Transaction sent — awaiting confirmation'),
        isSuccess: result.confirmed,
        actionLabel: locale.isThai ? 'ดู tx' : 'View tx',
        onAction: () => launchUrl(
          Uri.parse(result.explorerUrl),
          mode: LaunchMode.externalApplication,
        ),
      );

      // Refresh ยอดจริงจาก BSC + portfolio
      _bscBalanceKey = null;
      _loadBscBalances();
      wallet.loadPortfolio();
    } on SwapException catch (e) {
      if (mounted) _showSnack(e.message(locale.isThai));
    } catch (_) {
      if (mounted) {
        _showSnack(wallet.error ?? locale.t('common.error'));
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  void _showSnack(
    String msg, {
    bool isSuccess = false,
    String? actionLabel,
    VoidCallback? onAction,
  }) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: isSuccess ? AppColors.tradingGreen : null,
        // มีปุ่ม action (เช่นลิงก์ดู tx) ให้ค้างนานขึ้นพอที่จะกด
        duration: Duration(seconds: actionLabel != null ? 8 : 2),
        action: actionLabel != null && onAction != null
            ? SnackBarAction(label: actionLabel, onPressed: onAction)
            : null,
      ),
    );
  }
}

// ── Buy / Sell segmented tab ──

class _SideTab extends StatelessWidget {
  final String label;
  final bool active;
  final bool isBuy;
  final VoidCallback onTap;

  const _SideTab({
    required this.label,
    required this.active,
    required this.isBuy,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accentColor =
        isBuy ? AppColors.tradingGreen : AppColors.tradingRed;
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 11),
        decoration: BoxDecoration(
          gradient:
              active ? (isBuy ? AppGradients.buy : AppGradients.sell) : null,
          borderRadius: BorderRadius.circular(11),
          boxShadow: active
              ? [
                  BoxShadow(
                    color: accentColor.withValues(alpha: 0.30),
                    blurRadius: 14,
                    offset: const Offset(0, 4),
                  ),
                ]
              : null,
        ),
        child: Center(
          child: Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: active ? AppColors.white : AppColors.textTertiary,
            ),
          ),
        ),
      ),
    );
  }
}

// ── Gold timeframe pill ──

class _TimeframePill extends StatelessWidget {
  final String label;
  final bool active;
  final VoidCallback onTap;

  const _TimeframePill({
    required this.label,
    required this.active,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          gradient: active ? accent.goldGradient : null,
          color: active ? null : AppColors.bgInputStrong,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: active ? Colors.transparent : AppColors.bgCardBorder,
            width: 1,
          ),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 11.5,
            fontWeight: active ? FontWeight.w800 : FontWeight.w500,
            color: active ? AppColors.goldTextOn : AppColors.textTertiary,
          ),
        ),
      ),
    );
  }
}

// ── Quick percent chip ──

class _QuickChip extends StatelessWidget {
  final String label;
  final VoidCallback onTap;

  const _QuickChip({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: AppColors.bgInputStrong,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: AppColors.bgCardBorder),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
      ),
    );
  }
}

// ── Trade input field ──

class _TradeInput extends StatelessWidget {
  final String label;
  final TextEditingController controller;
  final String suffix;

  const _TradeInput({
    required this.label,
    required this.controller,
    required this.suffix,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgInput,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      child: Row(
        children: [
          Text(label,
              style: GoogleFonts.inter(
                  fontSize: 12, color: AppColors.textTertiary)),
          const SizedBox(width: 8),
          Expanded(
            child: TextField(
              controller: controller,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              style: AppTheme.mono(fontSize: 14),
              textAlign: TextAlign.right,
              cursorColor: accent.g2,
              decoration: const InputDecoration(
                border: InputBorder.none,
                isDense: true,
                hintText: '0.00',
                hintStyle: TextStyle(color: AppColors.textDisabled),
                contentPadding: EdgeInsets.symmetric(vertical: 12),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Text(suffix,
              style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textSecondary)),
        ],
      ),
    );
  }
}

// ── Order book row ──

class _OrderBookRow extends StatelessWidget {
  final OrderBookEntry entry;
  final bool isBid;
  final double maxQty;

  const _OrderBookRow({
    required this.entry,
    required this.isBid,
    required this.maxQty,
  });

  @override
  Widget build(BuildContext context) {
    final fillRatio = maxQty > 0 ? entry.quantity / maxQty : 0.0;
    final barColor =
        isBid ? AppColors.tradingGreenBg : AppColors.tradingRedBg;
    final textColor = isBid ? AppColors.tradingGreen : AppColors.tradingRed;

    return Stack(
      children: [
        // Depth bar — bids fill from the left, asks fill from the right.
        Positioned.fill(
          child: Align(
            alignment: isBid ? Alignment.centerLeft : Alignment.centerRight,
            child: FractionallySizedBox(
              widthFactor: fillRatio.clamp(0, 1),
              child: Container(
                decoration: BoxDecoration(
                  color: barColor,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 3, horizontal: 2),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  entry.price.toStringAsFixed(2),
                  style: AppTheme.mono(
                      fontSize: 11, fontWeight: FontWeight.w700, color: textColor),
                ),
              ),
              Expanded(
                child: Text(
                  entry.quantity.toStringAsFixed(4),
                  style: AppTheme.mono(
                      fontSize: 11, color: AppColors.textSecondary),
                  textAlign: TextAlign.right,
                ),
              ),
              Expanded(
                child: Text(
                  entry.total.toStringAsFixed(2),
                  style: AppTheme.mono(
                      fontSize: 11, color: AppColors.textTertiary),
                  textAlign: TextAlign.right,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ── Open Order Row ──

class _OpenOrderRow extends StatelessWidget {
  final TradeOrder order;
  final VoidCallback onCancel;

  const _OpenOrderRow({required this.order, required this.onCancel});

  @override
  Widget build(BuildContext context) {
    final isBuy = order.isBuy;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Container(
            width: 6,
            height: 30,
            decoration: BoxDecoration(
              color: isBuy ? AppColors.tradingGreen : AppColors.tradingRed,
              borderRadius: BorderRadius.circular(3),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${order.side.toUpperCase()} ${order.type}',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: isBuy
                        ? AppColors.tradingGreen
                        : AppColors.tradingRed,
                  ),
                ),
                Text(
                  '${order.amount.toStringAsFixed(4)} @ ${order.price?.toStringAsFixed(2) ?? 'market'}',
                  style: AppTheme.mono(
                      fontSize: 10, color: AppColors.textTertiary),
                ),
              ],
            ),
          ),
          GestureDetector(
            onTap: onCancel,
            behavior: HitTestBehavior.opaque,
            child: Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: AppColors.tradingRedBg,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                'Cancel',
                style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    color: AppColors.tradingRed),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Pair Picker Bottom Sheet ──

class _PairPickerSheet extends StatefulWidget {
  final MarketProvider market;
  final ValueChanged<String> onSelect;

  const _PairPickerSheet({required this.market, required this.onSelect});

  @override
  State<_PairPickerSheet> createState() => _PairPickerSheetState();
}

class _PairPickerSheetState extends State<_PairPickerSheet> {
  String _search = '';

  List<Ticker> get _filtered {
    final q = _search.toUpperCase();
    if (q.isEmpty) return widget.market.allTickers;
    return widget.market.allTickers
        .where((t) => t.baseAsset.contains(q) || t.symbol.contains(q))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.7,
          ),
          decoration: BoxDecoration(
            color: AppColors.bgElevated,
            borderRadius:
                const BorderRadius.vertical(top: Radius.circular(24)),
            border: Border(top: BorderSide(color: accent.goldBorder, width: 1.2)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40,
                height: 4,
                margin: const EdgeInsets.only(top: 12, bottom: 16),
                decoration: BoxDecoration(
                  color: AppColors.textTertiary,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              // Search
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Container(
                  decoration: BoxDecoration(
                    color: AppColors.bgInput,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppColors.bgCardBorder),
                  ),
                  child: TextField(
                    onChanged: (v) => setState(() => _search = v),
                    cursorColor: accent.g2,
                    style: GoogleFonts.inter(
                        fontSize: 14, color: AppColors.textPrimary),
                    decoration: InputDecoration(
                      hintText: context
                          .read<LocaleProvider>()
                          .t('common.search_pairs'),
                      hintStyle: GoogleFonts.inter(
                          fontSize: 14, color: AppColors.textDisabled),
                      prefixIcon: const Icon(Icons.search_rounded,
                          color: AppColors.textTertiary, size: 20),
                      border: InputBorder.none,
                      contentPadding:
                          const EdgeInsets.symmetric(vertical: 12),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              Flexible(
                child: ListView.builder(
                  itemCount: _filtered.length,
                  padding: const EdgeInsets.only(bottom: 24),
                  itemBuilder: (_, i) {
                    final t = _filtered[i];
                    final isSelected =
                        t.symbol == widget.market.selectedPair;
                    return ListTile(
                      dense: true,
                      selected: isSelected,
                      selectedTileColor: accent.goldTint,
                      leading: CoinLogo(
                        symbol: t.baseAsset,
                        size: 28,
                        borderRadius: 8,
                        logoUrl: context
                            .read<ConfigProvider>()
                            .pairBySymbol(t.symbol)
                            ?.baseLogo,
                      ),
                      title: Text(
                        t.displaySymbol,
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: isSelected
                              ? FontWeight.w700
                              : FontWeight.w400,
                          color: isSelected
                              ? accent.g1
                              : AppColors.textPrimary,
                        ),
                      ),
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          PriceText(price: t.lastPrice, fontSize: 13),
                          ChangeBadge(
                              changePercent: t.priceChangePercent,
                              fontSize: 10),
                        ],
                      ),
                      onTap: () => widget.onSelect(t.symbol),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
