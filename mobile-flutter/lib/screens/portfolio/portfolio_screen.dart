/// TPIX TRADE — Portfolio Screen (Luxury Dark / Gilded Metal)
/// Net-worth hero with a gold/teal donut, holdings with real allocation bars,
/// and trade history — all on the gunmetal+gold backdrop with ambient
/// fireflies. Every figure is real (wallet provider); the only non-data label
/// is the "30d" pill, which is explicitly a PREVIEW (no historical series yet).
///
/// Developed by Xman Studio

import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/accent_provider.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/shimmer_loading.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';

class PortfolioScreen extends StatefulWidget {
  const PortfolioScreen({super.key});

  @override
  State<PortfolioScreen> createState() => _PortfolioScreenState();
}

class _PortfolioScreenState extends State<PortfolioScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late WalletProvider _wallet;
  String? _loadedFor; // address we've already loaded for (load once per connect)

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    // โหลด portfolio เมื่อ connect — รวมถึงตอน connect ทีหลังจาก tab อื่น
    // (หน้านี้ถูก preserve โดย StatefulShellBranch → initState ไม่ rerun)
    _wallet = context.read<WalletProvider>();
    _wallet.addListener(_maybeLoadPortfolio);
    _maybeLoadPortfolio();
  }

  void _maybeLoadPortfolio() {
    if (!mounted) return;
    final addr = _wallet.address;
    if (_wallet.isConnected && addr != null && addr != _loadedFor) {
      _loadedFor = addr; // set before load → guards listener re-entrancy
      _wallet.loadPortfolio();
    }
  }

  @override
  void dispose() {
    _wallet.removeListener(_maybeLoadPortfolio);
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: wallet.isConnected
              ? _buildConnectedView(locale, wallet)
              : _buildEmptyView(locale),
        ),
      ),
    );
  }

  Widget _buildConnectedView(LocaleProvider locale, WalletProvider wallet) {
    return RefreshIndicator(
      color: AppColors.gold2,
      backgroundColor: AppColors.bgSecondary,
      onRefresh: () => wallet.loadPortfolio(),
      child: NestedScrollView(
        headerSliverBuilder: (context, _) => [
          // Header
          SliverToBoxAdapter(child: _buildHeader(locale)),

          // Net worth hero
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 8, 18, 14),
              child: _NetWorthCard(wallet: wallet, locale: locale),
            ),
          ),

          // Tabs: Assets / History
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 0, 18, 4),
              child: _buildTabBar(locale),
            ),
          ),
        ],
        body: TabBarView(
          controller: _tabController,
          children: [
            _buildAssetsList(wallet, locale),
            _buildHistoryList(wallet, locale),
          ],
        ),
      ),
    );
  }

  // ── Header ──

  Widget _buildHeader(LocaleProvider locale) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
      child: Row(
        children: [
          Icon(Icons.pie_chart_rounded, size: 18, color: AppColors.gold2),
          const SizedBox(width: 8),
          Text(
            locale.t('portfolio.title'),
            style: GoogleFonts.inter(
              fontSize: 22,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              letterSpacing: -0.3,
            ),
          ),
        ],
      ),
    );
  }

  // ── Tab bar (gilded indicator) ──

  Widget _buildTabBar(LocaleProvider locale) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.bgInput,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: TabBar(
        controller: _tabController,
        indicator: BoxDecoration(
          gradient: accent.goldGradient,
          borderRadius: BorderRadius.circular(10),
          boxShadow: [
            BoxShadow(
              color: accent.goldGlow.withValues(alpha: 0.32),
              blurRadius: 14,
              spreadRadius: -4,
            ),
          ],
        ),
        indicatorSize: TabBarIndicatorSize.tab,
        labelColor: AppColors.goldTextOn, // dark text on gold
        unselectedLabelColor: AppColors.textSecondary,
        labelStyle: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w700),
        unselectedLabelStyle:
            GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600),
        dividerColor: Colors.transparent,
        splashBorderRadius: BorderRadius.circular(10),
        tabs: [
          Tab(text: locale.t('portfolio.assets')),
          Tab(text: locale.t('portfolio.history')),
        ],
      ),
    );
  }

  // ── Assets list ──

  Widget _buildAssetsList(WalletProvider wallet, LocaleProvider locale) {
    if (wallet.isLoadingPortfolio && wallet.balances.isEmpty) {
      return const ShimmerList(itemCount: 4);
    }

    if (wallet.balances.isEmpty) {
      return _EmptyState(
        icon: Icons.account_balance_wallet_outlined,
        label: locale.isThai ? 'ยังไม่มีสินทรัพย์' : 'No assets yet',
      );
    }

    final total = wallet.totalPortfolioValue;

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 120),
      itemCount: wallet.balances.length,
      itemBuilder: (_, i) {
        final token = wallet.balances[i];
        final usd = token.usdValue ?? 0;
        final allocation = total > 0 ? (usd / total).clamp(0.0, 1.0) : 0.0;
        return _AssetTile(token: token, allocation: allocation);
      },
    );
  }

  // ── History list ──

  Widget _buildHistoryList(WalletProvider wallet, LocaleProvider locale) {
    if (wallet.isLoadingPortfolio && wallet.tradeHistory.isEmpty) {
      return const ShimmerList(itemCount: 4);
    }

    if (wallet.tradeHistory.isEmpty) {
      return _EmptyState(
        icon: Icons.history_rounded,
        label: locale.t('common.no_data'),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 120),
      itemCount: wallet.tradeHistory.length,
      itemBuilder: (_, i) {
        final order = wallet.tradeHistory[i];
        return _HistoryTile(order: order);
      },
    );
  }

  // ── Empty (disconnected) view ──

  Widget _buildEmptyView(LocaleProvider locale) {
    final accent = context.watch<AccentProvider>();
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 40),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                gradient: accent.goldGradient,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [
                  BoxShadow(
                    color: accent.goldGlow.withValues(alpha: 0.4),
                    blurRadius: 24,
                    spreadRadius: -2,
                  ),
                ],
              ),
              child: const Icon(Icons.pie_chart_rounded,
                  color: AppColors.goldTextOn, size: 36),
            ),
            const SizedBox(height: 24),
            Text(
              locale.t('portfolio.title'),
              style: GoogleFonts.inter(
                fontSize: 22,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              locale.isThai
                  ? 'เชื่อมกระเป๋าเพื่อดูสินทรัพย์ของคุณ'
                  : 'Connect your wallet to view your assets',
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(
                fontSize: 14,
                color: AppColors.textTertiary,
              ),
            ),
            const SizedBox(height: 32),
            SizedBox(
              width: 220,
              child: GradientButton(
                text: locale.t('settings.connect_wallet'),
                icon: Icons.account_balance_wallet_rounded,
                onPressed: () {
                  showModalBottomSheet(
                    context: context,
                    isScrollControlled: true,
                    useSafeArea: true, // กัน Android nav bar บัง wallet picker
                    backgroundColor: Colors.transparent,
                    builder: (_) => const WalletConnectSheet(),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Net worth hero card ──

class _NetWorthCard extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _NetWorthCard({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final total = wallet.totalPortfolioValue;
    final assetCount =
        wallet.balances.where((b) => (b.usdValue ?? 0) > 0).length;
    final loading = wallet.isLoadingPortfolio && wallet.balances.isEmpty;

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                (locale.isThai ? 'มูลค่าสุทธิ' : 'NET WORTH'),
                style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textTertiary,
                  letterSpacing: 1.4,
                ),
              ),
              const Spacer(),
              // "30d" pill — gold-bordered. No historical series wired yet, so
              // this is an explicit PREVIEW chip (no fabricated change figure).
              _GoldPill(
                label: locale.isThai ? '30 วัน · พรีวิว' : '30D · PREVIEW',
                icon: Icons.timeline_rounded,
              ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              // Donut ring with asset count center
              SizedBox(
                width: 96,
                height: 96,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    CustomPaint(
                      size: const Size(96, 96),
                      painter: _DonutChartPainter(
                        values: wallet.balances
                            .where((b) => (b.usdValue ?? 0) > 0)
                            .map((b) => b.usdValue!)
                            .toList(),
                        // Gold slots track the active tone; teal/slate/amber
                        // stay fixed so up to 6 assets remain distinguishable.
                        colors: [
                          accent.g2,
                          AppColors.tradingGreen,
                          accent.g1,
                          const Color(0xFF6E8BB8),
                          accent.g3,
                          const Color(0xFFE0883C),
                        ],
                      ),
                    ),
                    Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          '$assetCount',
                          style: GoogleFonts.jetBrainsMono(
                            fontSize: 24,
                            fontWeight: FontWeight.w700,
                            color: AppColors.textPrimary,
                            height: 1.0,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          locale.isThai ? 'สินทรัพย์' : 'assets',
                          style: GoogleFonts.inter(
                            fontSize: 9.5,
                            fontWeight: FontWeight.w600,
                            color: AppColors.textTertiary,
                            letterSpacing: 0.6,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 20),
              // Net worth amount + address
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    loading
                        ? const ShimmerBox(width: 150, height: 34)
                        : _NetWorthAmount(value: total),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Icon(Icons.account_balance_wallet_rounded,
                            size: 12, color: AppColors.textTertiary),
                        const SizedBox(width: 6),
                        Flexible(
                          child: Text(
                            wallet.shortAddress,
                            style: AppTheme.mono(
                              fontSize: 11.5,
                              color: AppColors.textSecondary,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: accent.goldTint,
                        border:
                            Border.all(color: accent.goldBorder, width: 1),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.bolt_rounded, size: 11, color: accent.g2),
                          const SizedBox(width: 3),
                          Text(
                            locale.isThai ? 'ไม่มีค่าแก๊ส' : 'ZERO GAS',
                            style: GoogleFonts.inter(
                              fontSize: 9.5,
                              fontWeight: FontWeight.w700,
                              color: accent.g1,
                              letterSpacing: 0.8,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _NetWorthAmount extends StatelessWidget {
  final double value;
  const _NetWorthAmount({required this.value});

  @override
  Widget build(BuildContext context) {
    final s = value.toStringAsFixed(2);
    final dot = s.indexOf('.');
    final intPart = _group(s.substring(0, dot));
    final decPart = s.substring(dot); // ".74"

    return FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: [
          Text(
            '\$$intPart',
            style: GoogleFonts.jetBrainsMono(
              fontSize: 30,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
              letterSpacing: -0.5,
            ),
          ),
          Text(
            decPart,
            style: GoogleFonts.jetBrainsMono(
              fontSize: 19,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  String _group(String digits) {
    final buf = StringBuffer();
    final n = digits.length;
    for (int i = 0; i < n; i++) {
      if (i > 0 && (n - i) % 3 == 0) buf.write(',');
      buf.write(digits[i]);
    }
    return buf.toString();
  }
}

// ── Gold-bordered pill ──

class _GoldPill extends StatelessWidget {
  final String label;
  final IconData icon;

  const _GoldPill({required this.label, required this.icon});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1.2),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 11, color: accent.g2),
          const SizedBox(width: 4),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 9.5,
              fontWeight: FontWeight.w700,
              color: accent.g1,
              letterSpacing: 0.6,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Asset tile (with real allocation bar) ──

class _AssetTile extends StatelessWidget {
  final dynamic token; // TokenBalance
  final double allocation; // 0..1 share of net worth (real)

  const _AssetTile({required this.token, required this.allocation});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final pct = (allocation * 100);
    final hasValue = token.usdValue != null;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        children: [
          Row(
            children: [
              CoinChip(symbol: token.symbol, size: 38, logoUrl: token.logo),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      token.symbol,
                      style: GoogleFonts.inter(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      token.name,
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: AppColors.textTertiary,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  // USD value (mono)
                  Text(
                    hasValue
                        ? '\$${token.usdValue!.toStringAsFixed(2)}'
                        : '—',
                    style: AppTheme.mono(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  const SizedBox(height: 3),
                  // Quantity
                  Text(
                    '${token.balance.toStringAsFixed(4)} ${token.symbol}',
                    style: AppTheme.mono(
                      fontSize: 10.5,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ],
              ),
            ],
          ),
          // Thin gold allocation bar — real share of net worth
          if (hasValue) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final w = constraints.maxWidth * allocation;
                      return Stack(
                        children: [
                          Container(
                            height: 5,
                            decoration: BoxDecoration(
                              color: AppColors.bgInputStrong,
                              borderRadius: BorderRadius.circular(999),
                            ),
                          ),
                          Container(
                            height: 5,
                            width: w.clamp(0.0, constraints.maxWidth),
                            decoration: BoxDecoration(
                              gradient: accent.goldGradient,
                              borderRadius: BorderRadius.circular(999),
                            ),
                          ),
                        ],
                      );
                    },
                  ),
                ),
                const SizedBox(width: 10),
                Text(
                  '${pct.toStringAsFixed(pct >= 10 ? 0 : 1)}%',
                  style: AppTheme.mono(
                    fontSize: 10.5,
                    fontWeight: FontWeight.w700,
                    color: accent.g1,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

// ── History tile ──

class _HistoryTile extends StatelessWidget {
  final dynamic order; // TradeOrder

  const _HistoryTile({required this.order});

  @override
  Widget build(BuildContext context) {
    final isBuy = order.side == 'buy';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: isBuy ? AppColors.tradingGreenBg : AppColors.tradingRedBg,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(
              isBuy ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded,
              color: isBuy ? AppColors.tradingGreen : AppColors.tradingRed,
              size: 16,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${order.side.toString().toUpperCase()} ${order.pair}',
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${order.type} · ${order.status}',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                order.amount.toStringAsFixed(4),
                style: AppTheme.mono(fontSize: 13, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 3),
              Text(
                '${order.createdAt.month}/${order.createdAt.day}',
                style: AppTheme.mono(
                  fontSize: 10,
                  color: AppColors.textTertiary,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ── Shared empty state ──

class _EmptyState extends StatelessWidget {
  final IconData icon;
  final String label;

  const _EmptyState({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    return ListView(
      // ListView so RefreshIndicator pull-to-refresh still works when empty.
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(height: MediaQuery.of(context).size.height * 0.18),
        Icon(icon,
            color: AppColors.textTertiary.withValues(alpha: 0.3), size: 64),
        const SizedBox(height: 12),
        Center(
          child: Text(
            label,
            style: GoogleFonts.inter(
                fontSize: 14, color: AppColors.textTertiary),
          ),
        ),
      ],
    );
  }
}

// ── Donut chart — renders actual portfolio allocation ──

class _DonutChartPainter extends CustomPainter {
  final List<double> values;

  // Categorical palette for allocation arcs. The gold slots are passed in from
  // the active metal tone (AccentProvider) so the donut re-skins with the theme;
  // the teal + slate + amber slots stay fixed so 6 assets remain distinguishable.
  final List<Color> colors;

  _DonutChartPainter({required this.values, required this.colors});

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2 - 8;
    const strokeWidth = 14.0;

    // Background ring
    final bgPaint = Paint()
      ..color = AppColors.bgTertiary
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round;

    canvas.drawCircle(center, radius, bgPaint);

    if (values.isEmpty) return;

    final total = values.fold(0.0, (s, v) => s + v);
    if (total == 0) return;

    final rect = Rect.fromCircle(center: center, radius: radius);
    double startAngle = -math.pi / 2;

    for (int i = 0; i < values.length && i < colors.length; i++) {
      final sweepAngle = (values[i] / total) * 2 * math.pi;
      final paint = Paint()
        ..color = colors[i]
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round;

      canvas.drawArc(rect, startAngle, sweepAngle - 0.04, false, paint);
      startAngle += sweepAngle;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutChartPainter old) =>
      old.values.length != values.length ||
      old.colors.first != colors.first;
}
