/// TPIX TRADE — AI Trade Screen (Luxury Dark / Gilded Metal)
/// Real, rule-based technical-analysis trading signals (confluence of
/// EMA 9/21 + RSI + MACD + volume) computed from live Binance candles, with
/// three user-selectable modes:
///   • Signals — view signals only
///   • Manual  — tap Execute to place a real order (with confirmation)
///   • Auto    — auto-execute qualifying signals while this screen is open,
///               guarded by risk level + max-per-trade + a kill-switch.
///
/// This is NOT a trained AI model and NOT financial advice — it surfaces
/// transparent technical signals. Auto-execute only runs while this screen is
/// open. Real orders go through the same order API as the Trade screen.
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/ai_trade_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/config_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../services/api_service.dart';
import '../../services/strategy_engine.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/price_text.dart';

const _kMajors = ['BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE', 'AVAX'];

Color _actionColor(SignalAction a) {
  switch (a) {
    case SignalAction.strongBuy:
    case SignalAction.buy:
      return AppColors.tradingGreen;
    case SignalAction.hold:
      return AppColors.gold2;
    case SignalAction.sell:
    case SignalAction.strongSell:
      return AppColors.tradingRed;
  }
}

String _actionLabel(SignalAction a, bool th) {
  switch (a) {
    case SignalAction.strongBuy:
      return th ? 'ซื้อแรง' : 'STRONG BUY';
    case SignalAction.buy:
      return th ? 'ซื้อ' : 'BUY';
    case SignalAction.hold:
      return th ? 'ถือ' : 'HOLD';
    case SignalAction.sell:
      return th ? 'ขาย' : 'SELL';
    case SignalAction.strongSell:
      return th ? 'ขายแรง' : 'STRONG SELL';
  }
}

class AiTradeScreen extends StatefulWidget {
  const AiTradeScreen({super.key});

  @override
  State<AiTradeScreen> createState() => _AiTradeScreenState();
}

class _AiTradeScreenState extends State<AiTradeScreen> {
  late final AiTradeProvider _provider;
  bool _started = false;

  @override
  void initState() {
    super.initState();
    _provider = context.read<AiTradeProvider>();
    _provider.executor = _executeOrder;
    // Make sure market tickers are available to build the watchlist.
    final market = context.read<MarketProvider>();
    if (market.allTickers.isEmpty) market.loadTickers();
  }

  @override
  void dispose() {
    _provider.stopAutoRefresh();
    _provider.executor = null;
    super.dispose();
  }

  // ── Execution (injected into the provider; also used by manual taps) ──

  /// Fresh execution price (avoids sizing a market order off a stale candle
  /// close). Falls back to the signal's last close if the lookup fails.
  Future<double> _resolvePrice(AiSignalEntry e) async {
    final p = await _provider.currentPrice(e.spec.binanceSymbol);
    return (p != null && p > 0) ? p : e.signal.lastClose;
  }

  /// Place a real market order for [quoteAmount] worth of [e] at [price].
  Future<bool> _placeOrder(AiSignalEntry e, double quoteAmount, double price) async {
    if (!mounted) return false;
    final wallet = context.read<WalletProvider>();
    final config = context.read<ConfigProvider>();
    final side = e.signal.action.orderSide;
    if (side == null) return false;
    if (!wallet.isConnected || wallet.address == null) return false;
    if (!config.canTrade) return false;
    if (price <= 0) return false;
    final amount = quoteAmount / price;
    if (amount <= 0) return false;

    if (!wallet.isVerified) {
      final ok = await wallet.verifyWithBackend();
      if (!mounted || !ok) return false;
    }
    try {
      final order = await ApiService().createOrder(
        pair: e.spec.tradePair,
        side: side,
        type: 'market',
        amount: amount,
        walletAddress: wallet.address!,
        chainId: 4289,
      );
      if (order != null) {
        if (mounted) context.read<WalletProvider>().loadPortfolio();
        return true;
      }
    } catch (_) {/* fall through */}
    return false;
  }

  /// Executor injected into the provider for AUTO mode (resolves a fresh price).
  Future<bool> _executeOrder(AiSignalEntry e, double quoteAmount) async {
    final price = await _resolvePrice(e);
    return _placeOrder(e, quoteAmount, price);
  }

  void _snack(String msg, {bool ok = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: ok ? AppColors.tradingGreen : null,
      duration: const Duration(seconds: 2),
    ));
  }

  Future<void> _manualExecute(AiSignalEntry e, bool th) async {
    final wallet = context.read<WalletProvider>();
    if (!wallet.isConnected) {
      _snack(th ? 'เชื่อมกระเป๋าก่อน' : 'Connect wallet first');
      return;
    }
    final side = e.signal.action.orderSide;
    if (side == null) {
      _snack(th ? 'ยังไม่มีสัญญาณซื้อ/ขาย' : 'No actionable signal');
      return;
    }
    final q = _provider.maxPerTrade;
    final price = await _resolvePrice(e); // fresh price for dialog + order
    if (!mounted) return;
    final base = price > 0 ? q / price : 0;
    final isBuy = side == 'buy';
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => _ConfirmDialog(
        th: th,
        title: '${_actionLabel(e.signal.action, th)} · ${e.spec.display}',
        side: side,
        quote: q,
        quoteSym: e.spec.quote,
        base: base.toDouble(),
        baseSym: e.spec.base,
        price: price,
        accent: isBuy,
      ),
    );
    if (confirmed != true) return;
    final ok = await _placeOrder(e, q, price);
    _snack(
      ok
          ? (th ? 'ส่งคำสั่งสำเร็จ' : 'Order placed')
          : (th ? 'ส่งคำสั่งไม่สำเร็จ' : 'Order failed'),
      ok: ok,
    );
  }

  Future<void> _selectMode(AiMode m, bool th) async {
    if (m == AiMode.auto && _provider.mode != AiMode.auto) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (_) => _AutoConfirmDialog(th: th, maxPerTrade: _provider.maxPerTrade),
      );
      if (ok != true) return;
    }
    _provider.setMode(m);
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final ai = context.watch<AiTradeProvider>();
    final market = context.watch<MarketProvider>();
    final config = context.read<ConfigProvider>();
    final th = locale.isThai;

    // Build the watchlist from live tradable majors (USDT pairs).
    final wl = <AiPairSpec>[];
    for (final t in market.allTickers) {
      if (t.quoteAsset != 'USDT' || !_kMajors.contains(t.baseAsset)) continue;
      wl.add(AiPairSpec(
        base: t.baseAsset,
        quote: t.quoteAsset,
        tradePair: t.symbol,
        logoUrl: config.pairBySymbol(t.symbol)?.baseLogo,
      ));
      if (wl.length >= 6) break;
    }
    _provider.setWatchlist(wl);
    if (!_started && wl.isNotEmpty) {
      _started = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _provider.startAutoRefresh();
      });
    }

    final top = ai.topSignal;

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(child: _buildHeader(th, ai)),
              SliverToBoxAdapter(child: _buildDisclaimer(th)),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 6, 18, 4),
                  child: _AiEngineCard(
                    pairCount: ai.signals.length,
                    refreshing: ai.isRefreshing,
                    th: th,
                  ),
                ),
              ),

              // Mode selector
              SliverToBoxAdapter(
                child: _buildSectionTitle(th ? 'โหมด' : 'Mode', Icons.tune_rounded),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 4),
                  child: _ModeSelector(
                    mode: ai.mode,
                    th: th,
                    onSelect: (m) => _selectMode(m, th),
                  ),
                ),
              ),

              // Top signal
              if (top != null) ...[
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    th ? 'สัญญาณเด่น' : 'Top Signal',
                    Icons.auto_awesome_rounded,
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 0, 18, 4),
                    child: _TopSignalCard(
                      entry: top,
                      th: th,
                      mode: ai.mode,
                      onExecute: () => _manualExecute(top, th),
                    ),
                  ),
                ),
              ],

              // Auto settings (risk + max per trade)
              SliverToBoxAdapter(
                child: _buildSectionTitle(
                  th ? 'ตั้งค่าออโต้' : 'Auto Settings',
                  Icons.bolt_rounded,
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 4),
                  child: _AutoSettingsCard(
                    th: th,
                    risk: ai.risk,
                    maxPerTrade: ai.maxPerTrade,
                    isAuto: ai.isAuto,
                    onRisk: (r) => _provider.setRisk(r),
                    onEditMax: () => _editMaxPerTrade(th, ai.maxPerTrade),
                  ),
                ),
              ),

              // Live signals
              SliverToBoxAdapter(
                child: _buildSectionTitle(
                  th ? 'สัญญาณสด' : 'Live Signals',
                  Icons.podcasts_rounded,
                ),
              ),
              SliverToBoxAdapter(child: _buildSignalList(ai, th)),

              // Auto execution log
              if (ai.autoLog.isNotEmpty) ...[
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    th ? 'บันทึกออโต้' : 'Auto Log',
                    Icons.receipt_long_rounded,
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 0, 18, 0),
                    child: Column(
                      children: [
                        for (final l in ai.autoLog.take(6))
                          _AutoLogRow(log: l, th: th),
                      ],
                    ),
                  ),
                ),
              ],

              const SliverToBoxAdapter(child: SizedBox(height: 110)),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _editMaxPerTrade(bool th, double current) async {
    final controller = TextEditingController(text: current.toStringAsFixed(0));
    final v = await showDialog<double>(
      context: context,
      builder: (_) => _MaxPerTradeDialog(th: th, controller: controller),
    );
    controller.dispose();
    if (v != null) _provider.setMaxPerTrade(v);
  }

  Widget _buildSignalList(AiTradeProvider ai, bool th) {
    if (ai.signals.isEmpty) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(18, 8, 18, 8),
        child: Row(
          children: [
            if (ai.isRefreshing)
              const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(
                    strokeWidth: 2, color: AppColors.gold2),
              )
            else
              const Icon(Icons.satellite_alt_rounded,
                  size: 16, color: AppColors.textTertiary),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                ai.error != null
                    ? (th
                        ? 'ดึงข้อมูลตลาดไม่สำเร็จ — ลองใหม่อีกครั้ง'
                        : 'Market feed unavailable — retrying')
                    : (th ? 'กำลังวิเคราะห์สัญญาณ…' : 'Analyzing signals…'),
                style: GoogleFonts.inter(
                    fontSize: 12.5, color: AppColors.textTertiary),
              ),
            ),
          ],
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 0, 18, 0),
      child: Column(
        children: [
          for (final e in ai.signals)
            _LiveSignalRow(
              entry: e,
              th: th,
              tappable: ai.mode == AiMode.manual,
              onTap: ai.mode == AiMode.manual ? () => _manualExecute(e, th) : null,
            ),
        ],
      ),
    );
  }

  // ── Header ──
  Widget _buildHeader(bool th, AiTradeProvider ai) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 6),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              gradient: accent.goldGradient,
              borderRadius: BorderRadius.circular(13),
              boxShadow: [
                BoxShadow(
                  color: accent.goldGlow.withValues(alpha: 0.4),
                  blurRadius: 16,
                  spreadRadius: -4,
                ),
              ],
            ),
            child: const Icon(Icons.auto_awesome_rounded,
                color: AppColors.goldTextOn, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  th ? 'เทรดด้วย AI' : 'AI Trade',
                  style: GoogleFonts.inter(
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    letterSpacing: -0.2,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  th ? 'สัญญาณเทคนิคจาก TPIX' : 'TECHNICAL SIGNALS BY TPIX',
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textTertiary,
                    letterSpacing: 1.2,
                  ),
                ),
              ],
            ),
          ),
          // Manual refresh
          GestureDetector(
            onTap: () => _provider.refresh(),
            behavior: HitTestBehavior.opaque,
            child: Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.bgCard,
                border: Border.all(color: AppColors.bgCardBorder, width: 1),
              ),
              child: Icon(
                Icons.refresh_rounded,
                color: ai.isRefreshing ? AppColors.gold2 : AppColors.textSecondary,
                size: 20,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildDisclaimer(bool th) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 0),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded,
              size: 13, color: AppColors.textTertiary),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              th
                  ? 'สัญญาณเทคนิคแบบกฎ (EMA·RSI·MACD·วอลุ่ม) — ไม่ใช่คำแนะนำการลงทุน'
                  : 'Rule-based technical signals (EMA·RSI·MACD·volume) — not financial advice',
              style: GoogleFonts.inter(
                fontSize: 10.5,
                fontWeight: FontWeight.w500,
                color: AppColors.textTertiary,
                letterSpacing: 0.2,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSectionTitle(String title, IconData icon) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 10),
      child: Row(
        children: [
          Icon(icon, size: 16, color: AppColors.gold2),
          const SizedBox(width: 8),
          Text(
            title,
            style: GoogleFonts.inter(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
              letterSpacing: -0.2,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Mode selector (Signals / Manual / Auto) ──

class _ModeSelector extends StatelessWidget {
  final AiMode mode;
  final bool th;
  final ValueChanged<AiMode> onSelect;

  const _ModeSelector(
      {required this.mode, required this.th, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final items = <(AiMode, String, IconData)>[
      (AiMode.signals, th ? 'สัญญาณ' : 'Signals', Icons.visibility_rounded),
      (AiMode.manual, th ? 'แมนนวล' : 'Manual', Icons.touch_app_rounded),
      (AiMode.auto, th ? 'ออโต้' : 'Auto', Icons.bolt_rounded),
    ];
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        color: AppColors.bgInputStrong,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          for (final it in items)
            Expanded(
              child: GestureDetector(
                onTap: () => onSelect(it.$1),
                behavior: HitTestBehavior.opaque,
                child: AnimatedContainer(
                  duration: accent.reduceMotion
                      ? Duration.zero
                      : const Duration(milliseconds: 160),
                  margin: EdgeInsets.only(right: it.$1 == AiMode.auto ? 0 : 4),
                  padding: const EdgeInsets.symmetric(vertical: 9),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    gradient: it.$1 == mode ? accent.goldGradient : null,
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(it.$3,
                          size: 14,
                          color: it.$1 == mode
                              ? AppColors.goldTextOn
                              : AppColors.textSecondary),
                      const SizedBox(width: 5),
                      Flexible(
                        child: Text(
                          it.$2,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: GoogleFonts.inter(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: it.$1 == mode
                                ? AppColors.goldTextOn
                                : AppColors.textSecondary,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

// ── AI Engine card (pulsing gold orb) ──

class _AiEngineCard extends StatefulWidget {
  final int pairCount;
  final bool refreshing;
  final bool th;
  const _AiEngineCard(
      {required this.pairCount, required this.refreshing, required this.th});

  @override
  State<_AiEngineCard> createState() => _AiEngineCardState();
}

class _AiEngineCardState extends State<_AiEngineCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2200),
    );
  }

  void _syncMotion(bool reduceMotion) {
    if (reduceMotion) {
      if (_pulse.isAnimating) _pulse.stop();
      _pulse.value = 0.5;
    } else if (!_pulse.isAnimating) {
      _pulse.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    _syncMotion(accent.reduceMotion);

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          AnimatedBuilder(
            animation: _pulse,
            builder: (_, child) {
              final t = _pulse.value;
              final glow = 0.25 + t * 0.45;
              final ring = 6.0 + t * 10.0;
              return Container(
                width: 58,
                height: 58,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: accent.goldGradient,
                  boxShadow: [
                    BoxShadow(
                      color: accent.goldGlow.withValues(alpha: glow),
                      blurRadius: 18 + ring,
                      spreadRadius: ring * 0.4,
                    ),
                  ],
                ),
                child: child,
              );
            },
            child: const Icon(Icons.psychology_rounded,
                color: AppColors.goldTextOn, size: 28),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  widget.th ? 'เครื่องยนต์ TPIX AI' : 'TPIX AI Engine',
                  style: GoogleFonts.inter(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    letterSpacing: -0.2,
                  ),
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Container(
                      width: 7,
                      height: 7,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: AppColors.tradingGreen,
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.tradingGreen,
                            blurRadius: 6,
                            spreadRadius: 0.5,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 6),
                    Flexible(
                      child: Text(
                        widget.refreshing
                            ? (widget.th ? 'กำลังวิเคราะห์…' : 'Analyzing…')
                            : (widget.th
                                ? 'วิเคราะห์ ${widget.pairCount} คู่ · สด'
                                : 'Analyzing ${widget.pairCount} pairs · live'),
                        style: GoogleFonts.inter(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textSecondary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ── Top signal card ──

class _TopSignalCard extends StatelessWidget {
  final AiSignalEntry entry;
  final bool th;
  final AiMode mode;
  final VoidCallback onExecute;

  const _TopSignalCard({
    required this.entry,
    required this.th,
    required this.mode,
    required this.onExecute,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final s = entry.signal;
    final color = _actionColor(s.action);
    final reasons = th ? s.reasonsTh : s.reasons;
    final canExecute = mode != AiMode.signals && s.action.orderSide != null;

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: AppGradients.glassCard,
        border: Border.all(color: color.withValues(alpha: 0.45), width: 1.4),
        boxShadow: [
          BoxShadow(
            color: color.withValues(alpha: 0.12),
            blurRadius: 24,
            spreadRadius: -8,
          ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CoinChip(symbol: entry.spec.base, size: 40, logoUrl: entry.spec.logoUrl),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      entry.spec.display,
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      _actionLabel(s.action, th),
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                        color: color,
                        letterSpacing: 0.4,
                      ),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    th ? 'ราคา' : 'Price',
                    style: GoogleFonts.inter(
                      fontSize: 9.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textTertiary,
                      letterSpacing: 0.4,
                    ),
                  ),
                  const SizedBox(height: 2),
                  PriceText(price: s.lastClose, fontSize: 14),
                ],
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Text(
                th ? 'ความมั่นใจ' : 'Confidence',
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
              const Spacer(),
              Text(
                '${s.confidencePercent}%',
                style: AppTheme.mono(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: accent.g1,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          _ConfidenceBar(value: s.confidence),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [for (final r in reasons.take(4)) _ReasonChip(label: r)],
          ),
          if (canExecute) ...[
            const SizedBox(height: 16),
            _ExecuteCta(
              isBuy: s.action.isBuy,
              label: th ? 'ทำตามสัญญาณ' : 'Execute Signal',
              onTap: onExecute,
            ),
          ],
        ],
      ),
    );
  }
}

/// Buy/sell-colored CTA for the top signal (green for buy, red for sell).
class _ExecuteCta extends StatelessWidget {
  final bool isBuy;
  final String label;
  final VoidCallback onTap;
  const _ExecuteCta(
      {required this.isBuy, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final grad = isBuy ? AppGradients.buy : AppGradients.sell;
    final glow = isBuy ? AppColors.tradingGreen : AppColors.tradingRed;
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        height: 48,
        width: double.infinity,
        decoration: BoxDecoration(
          gradient: grad,
          borderRadius: BorderRadius.circular(14),
          boxShadow: [
            BoxShadow(
              color: glow.withValues(alpha: 0.32),
              blurRadius: 14,
              offset: const Offset(0, 6),
            ),
          ],
        ),
        child: Center(
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.flash_on_rounded, color: Colors.white, size: 18),
              const SizedBox(width: 8),
              Text(
                label,
                style: GoogleFonts.inter(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                  letterSpacing: 0.3,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ConfidenceBar extends StatelessWidget {
  final double value; // 0..1
  const _ConfidenceBar({required this.value});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return LayoutBuilder(
      builder: (_, c) {
        final w = c.maxWidth * value.clamp(0.0, 1.0);
        return Stack(
          children: [
            Container(
              height: 8,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                color: AppColors.bgInputStrong,
              ),
            ),
            Container(
              height: 8,
              width: w,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                gradient: accent.goldGradient,
                boxShadow: [
                  BoxShadow(
                    color: accent.goldGlow.withValues(alpha: 0.4),
                    blurRadius: 8,
                    spreadRadius: -2,
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}

class _ReasonChip extends StatelessWidget {
  final String label;
  const _ReasonChip({required this.label});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: accent.g1,
        ),
      ),
    );
  }
}

// ── Auto settings card ──

class _AutoSettingsCard extends StatelessWidget {
  final bool th;
  final RiskLevel risk;
  final double maxPerTrade;
  final bool isAuto;
  final ValueChanged<RiskLevel> onRisk;
  final VoidCallback onEditMax;

  const _AutoSettingsCard({
    required this.th,
    required this.risk,
    required this.maxPerTrade,
    required this.isAuto,
    required this.onRisk,
    required this.onEditMax,
  });

  @override
  Widget build(BuildContext context) {
    final labels = th
        ? const ['อนุรักษ์', 'สมดุล', 'รุกหนัก']
        : const ['Conservative', 'Balanced', 'Aggressive'];
    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 20,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                isAuto ? Icons.bolt_rounded : Icons.bolt_outlined,
                size: 16,
                color: isAuto ? AppColors.gold2 : AppColors.textTertiary,
              ),
              const SizedBox(width: 6),
              Text(
                isAuto
                    ? (th ? 'ออโต้ทำงาน (ขณะเปิดหน้านี้)' : 'Auto active (while open)')
                    : (th ? 'ตั้งค่าออโต้' : 'Auto settings'),
                style: GoogleFonts.inter(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: isAuto ? AppColors.gold1 : AppColors.textSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            th ? 'ระดับความเสี่ยง' : 'RISK LEVEL',
            style: GoogleFonts.inter(
              fontSize: 10,
              fontWeight: FontWeight.w700,
              color: AppColors.textTertiary,
              letterSpacing: 1.2,
            ),
          ),
          const SizedBox(height: 8),
          _RiskSegmented(
            labels: labels,
            selected: risk.index,
            onSelect: (i) => onRisk(RiskLevel.values[i]),
          ),
          const SizedBox(height: 14),
          GestureDetector(
            onTap: onEditMax,
            behavior: HitTestBehavior.opaque,
            child: Row(
              children: [
                const Icon(Icons.shield_outlined,
                    size: 13, color: AppColors.textTertiary),
                const SizedBox(width: 6),
                Text(
                  th ? 'สูงสุดต่อรายการ' : 'Max per trade',
                  style: GoogleFonts.inter(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textSecondary,
                  ),
                ),
                const Spacer(),
                Text(
                  '${maxPerTrade.toStringAsFixed(0)} USDT',
                  style: AppTheme.mono(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.gold2,
                  ),
                ),
                const SizedBox(width: 4),
                const Icon(Icons.edit_rounded,
                    size: 13, color: AppColors.textTertiary),
              ],
            ),
          ),
          const SizedBox(height: 4),
          Text(
            th
                ? 'ออโต้จะส่งคำสั่งเมื่อความมั่นใจ ≥ ${(risk.minConfidence * 100).round()}%'
                : 'Auto fires when confidence ≥ ${(risk.minConfidence * 100).round()}%',
            style: GoogleFonts.inter(
              fontSize: 10.5,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }
}

class _RiskSegmented extends StatelessWidget {
  final List<String> labels;
  final int selected;
  final ValueChanged<int> onSelect;

  const _RiskSegmented({
    required this.labels,
    required this.selected,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        color: AppColors.bgInputStrong,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          for (int i = 0; i < labels.length; i++)
            Expanded(
              child: GestureDetector(
                onTap: () => onSelect(i),
                behavior: HitTestBehavior.opaque,
                child: AnimatedContainer(
                  duration: accent.reduceMotion
                      ? Duration.zero
                      : const Duration(milliseconds: 180),
                  margin: EdgeInsets.only(right: i == labels.length - 1 ? 0 : 4),
                  padding: const EdgeInsets.symmetric(vertical: 9),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    gradient: i == selected ? accent.goldGradient : null,
                  ),
                  child: Text(
                    labels[i],
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: i == selected
                          ? AppColors.goldTextOn
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

// ── Live signal row ──

class _LiveSignalRow extends StatelessWidget {
  final AiSignalEntry entry;
  final bool th;
  final bool tappable;
  final VoidCallback? onTap;

  const _LiveSignalRow({
    required this.entry,
    required this.th,
    required this.tappable,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final s = entry.signal;
    final color = _actionColor(s.action);
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: AppGradients.cardSubtle,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: Row(
          children: [
            CoinChip(symbol: entry.spec.base, size: 38, logoUrl: entry.spec.logoUrl),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.spec.display,
                  style: GoogleFonts.inter(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  th
                      ? 'ความมั่นใจ ${s.confidencePercent}%'
                      : 'Confidence ${s.confidencePercent}%',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
            const Spacer(),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  _actionLabel(s.action, th),
                  style: GoogleFonts.inter(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w800,
                    color: color,
                  ),
                ),
                const SizedBox(height: 3),
                PriceText(price: s.lastClose, fontSize: 11.5),
              ],
            ),
            if (tappable && s.action.orderSide != null) ...[
              const SizedBox(width: 8),
              const Icon(Icons.chevron_right_rounded,
                  size: 18, color: AppColors.textTertiary),
            ],
          ],
        ),
      ),
    );
  }
}

class _AutoLogRow extends StatelessWidget {
  final AiExecLog log;
  final bool th;
  const _AutoLogRow({required this.log, required this.th});

  @override
  Widget build(BuildContext context) {
    final isBuy = log.side == 'buy';
    final color = log.ok
        ? (isBuy ? AppColors.tradingGreen : AppColors.tradingRed)
        : AppColors.textTertiary;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: AppColors.bgCard,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          Icon(
            log.ok
                ? (isBuy ? Icons.arrow_upward_rounded : Icons.arrow_downward_rounded)
                : Icons.block_rounded,
            size: 14,
            color: color,
          ),
          const SizedBox(width: 8),
          Text(
            '${log.side.toUpperCase()} ${log.pair}',
            style: GoogleFonts.inter(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const Spacer(),
          Text(
            log.ok
                ? '${log.quoteAmount.toStringAsFixed(0)} USDT'
                : (th ? 'ข้าม' : 'skipped'),
            style: AppTheme.mono(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Dialogs ──

class _ConfirmDialog extends StatelessWidget {
  final bool th;
  final String title;
  final String side;
  final double quote;
  final String quoteSym;
  final double base;
  final String baseSym;
  final double price;
  final bool accent;

  const _ConfirmDialog({
    required this.th,
    required this.title,
    required this.side,
    required this.quote,
    required this.quoteSym,
    required this.base,
    required this.baseSym,
    required this.price,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) {
    final c = accent ? AppColors.tradingGreen : AppColors.tradingRed;
    return AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: AppColors.bgCardBorder),
      ),
      title: Text(title,
          style: GoogleFonts.inter(
              color: AppColors.textPrimary, fontSize: 16, fontWeight: FontWeight.w800)),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _row(th ? 'ประเภท' : 'Type', th ? 'ตลาด (market)' : 'Market'),
          _row(th ? 'ใช้เงิน' : 'Spend', '${quote.toStringAsFixed(2)} $quoteSym'),
          _row(th ? 'ประมาณ' : 'Approx', '≈ ${base.toStringAsFixed(6)} $baseSym'),
          _row(th ? 'ราคาล่าสุด' : 'Last price', price.toStringAsFixed(2)),
          const SizedBox(height: 8),
          Text(
            th
                ? 'นี่เป็นคำสั่งจริงผ่านระบบเทรด'
                : 'This places a real order via the trading system',
            style: GoogleFonts.inter(fontSize: 10.5, color: AppColors.textTertiary),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: Text(th ? 'ยกเลิก' : 'Cancel',
              style: const TextStyle(color: AppColors.textTertiary)),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: c,
            foregroundColor: Colors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: Text(th ? 'ยืนยัน' : 'Confirm'),
        ),
      ],
    );
  }

  Widget _row(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(k,
                style: GoogleFonts.inter(
                    fontSize: 12, color: AppColors.textSecondary)),
            Text(v,
                style: AppTheme.mono(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary)),
          ],
        ),
      );
}

class _AutoConfirmDialog extends StatelessWidget {
  final bool th;
  final double maxPerTrade;
  const _AutoConfirmDialog({required this.th, required this.maxPerTrade});

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: AppColors.tradingRed.withValues(alpha: 0.4)),
      ),
      title: Row(
        children: [
          const Icon(Icons.warning_amber_rounded,
              color: AppColors.tradingRed, size: 20),
          const SizedBox(width: 8),
          Text(th ? 'เปิดออโต้?' : 'Enable Auto?',
              style: GoogleFonts.inter(
                  color: AppColors.textPrimary,
                  fontSize: 16,
                  fontWeight: FontWeight.w800)),
        ],
      ),
      content: Text(
        th
            ? 'ออโต้จะ "ส่งคำสั่งซื้อ/ขายจริง" อัตโนมัติตามสัญญาณ ขณะที่เปิดหน้านี้ '
                'สูงสุด ${maxPerTrade.toStringAsFixed(0)} USDT ต่อรายการ '
                'หยุดได้ทุกเมื่อโดยสลับโหมด คุณยอมรับความเสี่ยงเอง'
            : 'Auto will place REAL buy/sell orders automatically based on signals '
                'while this screen is open, up to ${maxPerTrade.toStringAsFixed(0)} USDT per trade. '
                'Stop anytime by switching mode. You accept the risk.',
        style: GoogleFonts.inter(fontSize: 12.5, color: AppColors.textSecondary, height: 1.4),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: Text(th ? 'ยกเลิก' : 'Cancel',
              style: const TextStyle(color: AppColors.textTertiary)),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.gold2,
            foregroundColor: AppColors.goldTextOn,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: Text(th ? 'เปิดออโต้' : 'Enable Auto'),
        ),
      ],
    );
  }
}

class _MaxPerTradeDialog extends StatelessWidget {
  final bool th;
  final TextEditingController controller;
  const _MaxPerTradeDialog({required this.th, required this.controller});

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: AppColors.bgCardBorder),
      ),
      title: Text(th ? 'สูงสุดต่อรายการ (USDT)' : 'Max per trade (USDT)',
          style: GoogleFonts.inter(
              color: AppColors.textPrimary, fontSize: 15, fontWeight: FontWeight.w800)),
      content: TextField(
        controller: controller,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        autofocus: true,
        style: AppTheme.mono(fontSize: 16, color: AppColors.textPrimary),
        cursorColor: AppColors.gold2,
        decoration: InputDecoration(
          filled: true,
          fillColor: AppColors.bgInput,
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.bgCardBorder),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.gold2, width: 1.5),
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(th ? 'ยกเลิก' : 'Cancel',
              style: const TextStyle(color: AppColors.textTertiary)),
        ),
        ElevatedButton(
          onPressed: () {
            final v = double.tryParse(controller.text.trim());
            Navigator.pop(context, (v != null && v > 0) ? v : null);
          },
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.gold2,
            foregroundColor: AppColors.goldTextOn,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: Text(th ? 'บันทึก' : 'Save'),
        ),
      ],
    );
  }
}
