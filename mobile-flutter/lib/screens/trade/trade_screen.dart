/// TPIX TRADE — Trade Screen
/// Chart + Order Book + Trade Form (Buy/Sell)
///
/// Developed by Xman Studio

import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../services/api_service.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/price_text.dart';
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
  final _priceController = TextEditingController();
  final _amountController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _orderTypeTab = TabController(length: 2, vsync: this);
    // Load order book + klines for selected pair
    final market = context.read<MarketProvider>();
    market.loadOrderBook();
    market.loadKlines();
  }

  @override
  void dispose() {
    _orderTypeTab.dispose();
    _priceController.dispose();
    _amountController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final market = context.watch<MarketProvider>();
    final wallet = context.watch<WalletProvider>();
    final ticker = market.selectedTicker;

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: AppGradients.darkBg),
        child: SafeArea(
          bottom: false,
          child: Column(
            children: [
              // Pair selector + price
              _buildPairHeader(market, ticker),

              // Main content
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.only(bottom: 100),
                  child: Column(
                    children: [
                      // Mini chart placeholder
                      _buildMiniChart(market),

                      const SizedBox(height: 12),

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

  Widget _buildPairHeader(MarketProvider market, Ticker? ticker) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Row(
        children: [
          // Pair name
          GestureDetector(
            onTap: () => _showPairPicker(market),
            child: Row(
              children: [
                Text(
                  ticker?.displaySymbol ?? market.selectedPair,
                  style: GoogleFonts.inter(
                    fontSize: 20,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(width: 4),
                const Icon(Icons.keyboard_arrow_down_rounded,
                    color: AppColors.textTertiary, size: 20),
              ],
            ),
          ),
          const Spacer(),
          if (ticker != null) ...[
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                PriceText(
                  price: ticker.lastPrice,
                  change: ticker.priceChangePercent,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                ),
                ChangeBadge(changePercent: ticker.priceChangePercent),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ── Mini chart placeholder ──

  Widget _buildMiniChart(MarketProvider market) {
    return Container(
      height: 180,
      margin: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: market.klines.isEmpty
          ? const Center(
              child: Text('Chart', style: TextStyle(color: AppColors.textTertiary)),
            )
          : CustomPaint(
              painter: _MiniChartPainter(klines: market.klines),
              size: Size.infinite,
            ),
    );
  }

  // ── Order book ──

  Widget _buildOrderBook(MarketProvider market, LocaleProvider locale) {
    final ob = market.orderBook;

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 14,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            locale.t('trade.orderbook'),
            style: GoogleFonts.inter(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 10),

          // Header
          Row(
            children: [
              Expanded(
                child: Text(locale.t('trade.price'),
                    style: _obHeaderStyle),
              ),
              Expanded(
                child: Text(locale.t('trade.amount'),
                    style: _obHeaderStyle,
                    textAlign: TextAlign.right),
              ),
              Expanded(
                child: Text(locale.t('trade.total'),
                    style: _obHeaderStyle,
                    textAlign: TextAlign.right),
              ),
            ],
          ),
          const SizedBox(height: 6),

          // Asks (sell) — top 5
          if (ob != null)
            ...ob.asks.take(5).toList().reversed.map(
                  (entry) => _OrderBookRow(
                    entry: entry,
                    isBid: false,
                    maxQty: _maxQty(ob.asks),
                  ),
                ),

          // Spread
          Container(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (ob != null && ob.asks.isNotEmpty && ob.bids.isNotEmpty)
                  Text(
                    'Spread: ${(ob.asks.first.price - ob.bids.first.price).toStringAsFixed(2)}',
                    style: AppTheme.mono(
                      fontSize: 10,
                      color: AppColors.textTertiary,
                    ),
                  ),
              ],
            ),
          ),

          // Bids (buy) — top 5
          if (ob != null)
            ...ob.bids.take(5).map(
                  (entry) => _OrderBookRow(
                    entry: entry,
                    isBid: true,
                    maxQty: _maxQty(ob.bids),
                  ),
                ),

          if (ob == null)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 20),
              child: Center(
                child: Text('Loading...',
                    style: TextStyle(color: AppColors.textTertiary, fontSize: 12)),
              ),
            ),
        ],
      ),
    );
  }

  double _maxQty(List<OrderBookEntry> entries) {
    if (entries.isEmpty) return 1;
    return entries.take(5).fold(0.0, (max, e) => e.quantity > max ? e.quantity : max);
  }

  TextStyle get _obHeaderStyle => GoogleFonts.inter(
        fontSize: 10,
        fontWeight: FontWeight.w500,
        color: AppColors.textTertiary,
      );

  // ── Trade form ──

  Widget _buildTradeForm(
      LocaleProvider locale, WalletProvider wallet, MarketProvider market) {
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          // Buy / Sell toggle
          Row(
            children: [
              Expanded(
                child: GestureDetector(
                  onTap: () => setState(() => _isBuy = true),
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    decoration: BoxDecoration(
                      gradient: _isBuy ? AppGradients.buy : null,
                      color: _isBuy ? null : AppColors.bgTertiary,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Center(
                      child: Text(
                        locale.t('trade.buy'),
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: _isBuy ? Colors.white : AppColors.textTertiary,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: GestureDetector(
                  onTap: () => setState(() => _isBuy = false),
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    decoration: BoxDecoration(
                      gradient: !_isBuy ? AppGradients.sell : null,
                      color: !_isBuy ? null : AppColors.bgTertiary,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Center(
                      child: Text(
                        locale.t('trade.sell'),
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color:
                              !_isBuy ? Colors.white : AppColors.textTertiary,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),

          const SizedBox(height: 14),

          // Order type tabs
          Container(
            decoration: BoxDecoration(
              color: AppColors.bgTertiary,
              borderRadius: BorderRadius.circular(8),
            ),
            child: TabBar(
              controller: _orderTypeTab,
              indicator: BoxDecoration(
                color: AppColors.bgSecondary,
                borderRadius: BorderRadius.circular(6),
                border: Border.all(color: AppColors.bgCardBorder),
              ),
              indicatorSize: TabBarIndicatorSize.tab,
              indicatorPadding: const EdgeInsets.all(2),
              labelColor: AppColors.textPrimary,
              unselectedLabelColor: AppColors.textTertiary,
              labelStyle:
                  GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.w600),
              dividerColor: Colors.transparent,
              tabs: [
                Tab(text: locale.t('trade.limit'), height: 32),
                Tab(text: locale.t('trade.market'), height: 32),
              ],
            ),
          ),

          const SizedBox(height: 14),

          // Price input
          _TradeInput(
            label: locale.t('trade.price'),
            controller: _priceController,
            suffix: market.selectedTicker?.quoteAsset ?? 'USDT',
          ),

          const SizedBox(height: 10),

          // Amount input
          _TradeInput(
            label: locale.t('trade.amount'),
            controller: _amountController,
            suffix: market.selectedTicker?.baseAsset ?? 'BTC',
          ),

          const SizedBox(height: 10),

          // Amount shortcuts
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: ['25%', '50%', '75%', '100%'].map((pct) {
              return GestureDetector(
                onTap: () {
                  // ใส่ % ของ max amount ตาม price ปัจจุบัน (placeholder)
                  final factor = double.parse(pct.replaceAll('%', '')) / 100;
                  final current = double.tryParse(_amountController.text) ?? 0;
                  if (current > 0) {
                    _amountController.text = (current * factor).toStringAsFixed(4);
                  }
                },
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.bgTertiary,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: AppColors.bgCardBorder),
                  ),
                  child: Text(pct,
                      style: GoogleFonts.inter(
                          fontSize: 11, color: AppColors.textSecondary)),
                ),
              );
            }).toList(),
          ),

          const SizedBox(height: 16),

          // Submit button
          GradientButton(
            text: _isBuy
                ? '${locale.t('trade.buy')} ${market.selectedTicker?.baseAsset ?? ''}'
                : '${locale.t('trade.sell')} ${market.selectedTicker?.baseAsset ?? ''}',
            variant: _isBuy ? ButtonVariant.buy : ButtonVariant.sell,
            isLoading: _isSubmitting,
            onPressed: wallet.isConnected && !_isSubmitting ? () => _submitOrder() : null,
          ),

          if (!wallet.isConnected) ...[
            const SizedBox(height: 10),
            Text(
              locale.t('settings.connect_wallet'),
              style: GoogleFonts.inter(
                  fontSize: 12, color: AppColors.textTertiary),
            ),
          ],
        ],
      ),
    );
  }

  void _showPairPicker(MarketProvider market) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
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

  Future<void> _submitOrder() async {
    // C3: Guard double-tap — เช็ค synchronous ก่อน async
    if (_isSubmitting) return;

    final wallet = context.read<WalletProvider>();
    final market = context.read<MarketProvider>();
    final locale = context.read<LocaleProvider>();

    if (!wallet.isConnected || wallet.address == null) return;

    final priceText = _priceController.text.trim();
    final amountText = _amountController.text.trim();
    final isMarket = _orderTypeTab.index == 1;

    // Validate amount
    final amount = double.tryParse(amountText);
    if (amount == null || amount <= 0) {
      _showSnack(locale.isThai ? 'กรุณาใส่จำนวนที่ถูกต้อง' : 'Please enter a valid amount');
      return;
    }

    // Validate price (limit order only)
    double? price;
    if (!isMarket) {
      price = double.tryParse(priceText);
      if (price == null || price <= 0) {
        _showSnack(locale.isThai ? 'กรุณาใส่ราคาที่ถูกต้อง' : 'Please enter a valid price');
        return;
      }
    }

    setState(() => _isSubmitting = true);

    try {
      final order = await ApiService().createOrder(
        pair: market.selectedPair,
        side: _isBuy ? 'buy' : 'sell',
        type: isMarket ? 'market' : 'limit',
        price: price,
        amount: amount,
        walletAddress: wallet.address!,
        chainId: wallet.activeChainId,
      );

      if (!mounted) return;

      if (order != null) {
        _priceController.clear();
        _amountController.clear();
        _showSnack(
          locale.isThai
              ? 'สร้างออเดอร์สำเร็จ'
              : 'Order placed successfully',
          isSuccess: true,
        );
        // Reload order book
        market.loadOrderBook();
      } else {
        _showSnack(locale.isThai ? 'สร้างออเดอร์ไม่สำเร็จ' : 'Failed to place order');
      }
    } catch (e) {
      if (!mounted) return;
      _showSnack(locale.isThai ? 'เกิดข้อผิดพลาด' : 'An error occurred');
    }

    if (mounted) setState(() => _isSubmitting = false);
  }

  void _showSnack(String msg, {bool isSuccess = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: isSuccess ? AppColors.tradingGreen : null,
        duration: const Duration(seconds: 2),
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
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgInput,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 12),
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
              decoration: const InputDecoration(
                border: InputBorder.none,
                isDense: true,
                contentPadding: EdgeInsets.symmetric(vertical: 10),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Text(suffix,
              style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
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
    final barColor = isBid
        ? AppColors.tradingGreenBg
        : AppColors.tradingRedBg;
    final textColor = isBid ? AppColors.tradingGreen : AppColors.tradingRed;

    return Stack(
      children: [
        // Background fill bar
        Positioned.fill(
          child: Align(
            alignment: isBid ? Alignment.centerLeft : Alignment.centerRight,
            child: FractionallySizedBox(
              widthFactor: fillRatio.clamp(0, 1),
              child: Container(color: barColor),
            ),
          ),
        ),
        // Content
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  entry.price.toStringAsFixed(2),
                  style: AppTheme.mono(fontSize: 11, color: textColor),
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

// ── Simple mini chart painter ──

class _MiniChartPainter extends CustomPainter {
  final List<Kline> klines;

  _MiniChartPainter({required this.klines});

  @override
  void paint(Canvas canvas, Size size) {
    if (klines.isEmpty) return;

    final prices = klines.map((k) => k.close).toList();
    final minPrice = prices.reduce((a, b) => a < b ? a : b);
    final maxPrice = prices.reduce((a, b) => a > b ? a : b);
    final priceRange = maxPrice - minPrice;
    if (priceRange == 0) return;

    final isPositive = prices.last >= prices.first;
    final lineColor = isPositive
        ? const Color(0xFF00C853)
        : const Color(0xFFFF1744);
    final fillColor = lineColor.withValues(alpha: 0.1);

    final linePaint = Paint()
      ..color = lineColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;

    final path = Path();
    final fillPath = Path();

    for (int i = 0; i < prices.length; i++) {
      final x = (i / (prices.length - 1)) * size.width;
      final y = size.height - ((prices[i] - minPrice) / priceRange) * (size.height - 20) - 10;

      if (i == 0) {
        path.moveTo(x, y);
        fillPath.moveTo(x, size.height);
        fillPath.lineTo(x, y);
      } else {
        path.lineTo(x, y);
        fillPath.lineTo(x, y);
      }
    }

    // Fill gradient area
    fillPath.lineTo(size.width, size.height);
    fillPath.close();

    final fillPaint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [fillColor, fillColor.withValues(alpha: 0)],
      ).createShader(Rect.fromLTWH(0, 0, size.width, size.height));

    canvas.drawPath(fillPath, fillPaint);
    canvas.drawPath(path, linePaint);
  }

  @override
  bool shouldRepaint(covariant _MiniChartPainter old) {
    if (old.klines.length != klines.length) return true;
    if (klines.isEmpty) return false;
    // เช็คราคาล่าสุดเปลี่ยนไหม — ไม่ต้อง deep compare ทั้ง list
    return old.klines.last.close != klines.last.close;
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
    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.7,
          ),
          decoration: const BoxDecoration(
            color: Color(0xF20A0E1A),
            borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
            border: Border(top: BorderSide(color: Color(0x1AFFFFFF))),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Handle bar
              Container(
                width: 40, height: 4,
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
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.bgCardBorder),
                  ),
                  child: TextField(
                    onChanged: (v) => setState(() => _search = v),
                    style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
                    decoration: InputDecoration(
                      hintText: 'Search pairs...',
                      hintStyle: GoogleFonts.inter(fontSize: 14, color: AppColors.textDisabled),
                      prefixIcon: const Icon(Icons.search_rounded,
                          color: AppColors.textTertiary, size: 20),
                      border: InputBorder.none,
                      contentPadding: const EdgeInsets.symmetric(vertical: 12),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              // List
              Flexible(
                child: ListView.builder(
                  itemCount: _filtered.length,
                  padding: const EdgeInsets.only(bottom: 24),
                  itemBuilder: (_, i) {
                    final t = _filtered[i];
                    final isSelected = t.symbol == widget.market.selectedPair;
                    return ListTile(
                      dense: true,
                      selected: isSelected,
                      selectedTileColor: AppColors.brandCyan.withValues(alpha: 0.08),
                      title: Text(
                        t.displaySymbol,
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: isSelected ? FontWeight.w600 : FontWeight.w400,
                          color: isSelected ? AppColors.brandCyan : AppColors.textPrimary,
                        ),
                      ),
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          PriceText(price: t.lastPrice, fontSize: 13),
                          ChangeBadge(changePercent: t.priceChangePercent, fontSize: 10),
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
