/// TPIX TRADE — Portfolio Screen
/// มูลค่าพอร์ต, สินทรัพย์, ประวัติเทรด — เชื่อมกับ API จริง
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
import '../../widgets/common/coin_logo.dart';
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

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    // โหลด portfolio data เมื่อเข้าหน้า
    final wallet = context.read<WalletProvider>();
    if (wallet.isConnected) {
      wallet.loadPortfolio();
    }
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: AppGradients.darkBg),
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
      color: AppColors.brandCyan,
      backgroundColor: AppColors.bgSecondary,
      onRefresh: () => wallet.loadPortfolio(),
      child: CustomScrollView(
        slivers: [
          // Header
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
              child: Text(
                locale.t('portfolio.title'),
                style: GoogleFonts.inter(
                  fontSize: 24,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
          ),

          // Total Value Card
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
              child: _buildTotalValueCard(wallet, locale),
            ),
          ),

          // Tabs: Assets / History
          SliverToBoxAdapter(
            child: Container(
              margin: const EdgeInsets.symmetric(horizontal: 16),
              decoration: BoxDecoration(
                color: AppColors.bgTertiary,
                borderRadius: BorderRadius.circular(10),
              ),
              child: TabBar(
                controller: _tabController,
                indicator: BoxDecoration(
                  gradient: AppGradients.brand,
                  borderRadius: BorderRadius.circular(8),
                ),
                indicatorSize: TabBarIndicatorSize.tab,
                indicatorPadding: const EdgeInsets.all(3),
                labelColor: Colors.white,
                unselectedLabelColor: AppColors.textTertiary,
                labelStyle:
                    GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600),
                dividerColor: Colors.transparent,
                tabs: [
                  Tab(text: locale.t('portfolio.assets')),
                  Tab(text: locale.t('portfolio.history')),
                ],
              ),
            ),
          ),

          // Tab content
          SliverFillRemaining(
            child: TabBarView(
              controller: _tabController,
              children: [
                _buildAssetsList(wallet, locale),
                _buildHistoryList(wallet, locale),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTotalValueCard(WalletProvider wallet, LocaleProvider locale) {
    final total = wallet.totalPortfolioValue;
    final formatted = '\$${total.toStringAsFixed(2)}';

    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: 20,
      padding: const EdgeInsets.all(24),
      child: Column(
        children: [
          Text(
            locale.t('portfolio.total_value'),
            style: GoogleFonts.inter(
              fontSize: 13,
              color: AppColors.textTertiary,
            ),
          ),
          const SizedBox(height: 8),
          wallet.isLoadingPortfolio && wallet.balances.isEmpty
              ? const ShimmerBox(width: 160, height: 36)
              : Text(
                  formatted,
                  style: AppTheme.mono(
                    fontSize: 36,
                    fontWeight: FontWeight.w700,
                  ),
                ),
          const SizedBox(height: 8),
          Text(
            wallet.shortAddress,
            style: AppTheme.mono(fontSize: 12, color: AppColors.textTertiary),
          ),
          if (wallet.balances.isNotEmpty) ...[
            const SizedBox(height: 20),
            SizedBox(
              width: 140,
              height: 140,
              child: CustomPaint(
                painter: _DonutChartPainter(
                  values: wallet.balances
                      .where((b) => (b.usdValue ?? 0) > 0)
                      .map((b) => b.usdValue!)
                      .toList(),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildAssetsList(WalletProvider wallet, LocaleProvider locale) {
    if (wallet.isLoadingPortfolio && wallet.balances.isEmpty) {
      return const ShimmerList(itemCount: 4);
    }

    if (wallet.balances.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.account_balance_wallet_outlined,
                color: AppColors.textTertiary.withValues(alpha: 0.3), size: 64),
            const SizedBox(height: 12),
            Text(
              locale.isThai ? 'ยังไม่มีสินทรัพย์' : 'No assets yet',
              style: GoogleFonts.inter(
                  fontSize: 14, color: AppColors.textTertiary),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
      itemCount: wallet.balances.length,
      itemBuilder: (_, i) {
        final token = wallet.balances[i];
        return _AssetTile(token: token);
      },
    );
  }

  Widget _buildHistoryList(WalletProvider wallet, LocaleProvider locale) {
    if (wallet.isLoadingPortfolio && wallet.tradeHistory.isEmpty) {
      return const ShimmerList(itemCount: 4);
    }

    if (wallet.tradeHistory.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.history_rounded,
                color: AppColors.textTertiary.withValues(alpha: 0.3), size: 64),
            const SizedBox(height: 12),
            Text(
              locale.t('common.no_data'),
              style: GoogleFonts.inter(
                  fontSize: 14, color: AppColors.textTertiary),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
      itemCount: wallet.tradeHistory.length,
      itemBuilder: (_, i) {
        final order = wallet.tradeHistory[i];
        return _HistoryTile(order: order);
      },
    );
  }

  Widget _buildEmptyView(LocaleProvider locale) {
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
                gradient: AppGradients.brand,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.glowCyan.withValues(alpha: 0.3),
                    blurRadius: 24,
                    spreadRadius: 2,
                  ),
                ],
              ),
              child: const Icon(Icons.pie_chart_rounded,
                  color: Colors.white, size: 36),
            ),
            const SizedBox(height: 24),
            Text(
              locale.t('portfolio.title'),
              style: GoogleFonts.inter(
                fontSize: 22,
                fontWeight: FontWeight.w700,
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
              width: 200,
              child: GradientButton(
                text: locale.t('settings.connect_wallet'),
                onPressed: () {
                  showModalBottomSheet(
                    context: context,
                    isScrollControlled: true,
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

// ── Asset tile ──

class _AssetTile extends StatelessWidget {
  final dynamic token; // TokenBalance

  const _AssetTile({required this.token});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: Row(
        children: [
          CoinLogo(
            symbol: token.symbol,
            size: 36,
            borderRadius: 10,
            logoUrl: token.logo,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  token.symbol,
                  style: GoogleFonts.inter(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
                Text(
                  token.name,
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
                token.balance.toStringAsFixed(4),
                style: AppTheme.mono(fontSize: 13),
              ),
              if (token.usdValue != null)
                Text(
                  '\$${token.usdValue!.toStringAsFixed(2)}',
                  style: AppTheme.mono(
                    fontSize: 11,
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

// ── History tile ──

class _HistoryTile extends StatelessWidget {
  final dynamic order; // TradeOrder

  const _HistoryTile({required this.order});

  @override
  Widget build(BuildContext context) {
    final isBuy = order.side == 'buy';
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: isBuy ? AppColors.tradingGreenBg : AppColors.tradingRedBg,
              borderRadius: BorderRadius.circular(8),
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
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
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
                style: AppTheme.mono(fontSize: 12),
              ),
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

// ── Donut chart — renders actual portfolio allocation ──

class _DonutChartPainter extends CustomPainter {
  final List<double> values;

  _DonutChartPainter({required this.values});

  static const _colors = [
    AppColors.brandCyan,
    AppColors.brandPurple,
    AppColors.tradingGreen,
    Color(0xFFFF9800),
    AppColors.tradingRed,
    Color(0xFF42A5F5),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2 - 8;
    const strokeWidth = 20.0;

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

    for (int i = 0; i < values.length && i < _colors.length; i++) {
      final sweepAngle = (values[i] / total) * 2 * math.pi;
      final paint = Paint()
        ..color = _colors[i]
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round;

      canvas.drawArc(rect, startAngle, sweepAngle - 0.04, false, paint);
      startAngle += sweepAngle;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutChartPainter old) =>
      old.values.length != values.length;
}
