/// TPIX TRADE — Portfolio Screen
/// มูลค่าพอร์ต, สินทรัพย์, ประวัติเทรด
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
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
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
    return CustomScrollView(
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
            child: GlassCard(
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
                  Text(
                    '\$0.00',
                    style: AppTheme.mono(
                      fontSize: 36,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppColors.tradingGreenBg,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(
                      '+\$0.00 (0.00%)',
                      style: AppTheme.mono(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: AppColors.tradingGreen,
                      ),
                    ),
                  ),
                  const SizedBox(height: 20),

                  // Donut chart placeholder
                  SizedBox(
                    width: 140,
                    height: 140,
                    child: CustomPaint(
                      painter: _DonutChartPainter(),
                    ),
                  ),
                ],
              ),
            ),
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
              _buildAssetsList(),
              _buildHistoryList(locale),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildAssetsList() {
    // Placeholder — จะเชื่อมกับ API getWalletBalances
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.account_balance_wallet_outlined,
              color: AppColors.textTertiary.withValues(alpha: 0.3), size: 64),
          const SizedBox(height: 12),
          Text(
            'No assets yet',
            style: GoogleFonts.inter(
                fontSize: 14, color: AppColors.textTertiary),
          ),
        ],
      ),
    );
  }

  Widget _buildHistoryList(LocaleProvider locale) {
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

// ── Donut chart placeholder ──

class _DonutChartPainter extends CustomPainter {
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

    // Gradient arc (placeholder)
    final rect = Rect.fromCircle(center: center, radius: radius);
    final gradientPaint = Paint()
      ..shader = const SweepGradient(
        startAngle: -math.pi / 2,
        endAngle: math.pi * 1.5,
        colors: [
          AppColors.brandCyan,
          AppColors.brandPurple,
          AppColors.brandCyan,
        ],
      ).createShader(rect)
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round;

    // Draw partial arc (e.g., 65%)
    canvas.drawArc(
      rect,
      -math.pi / 2,
      2 * math.pi * 0.65,
      false,
      gradientPaint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
