/// TPIX TRADE — Home Screen
/// Portfolio card, TPIX price, favorites, top gainers/losers
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/update_provider.dart';
import '../../services/update_service.dart';
import '../../widgets/common/coin_logo.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/peer_app_card.dart';
import '../../widgets/common/price_text.dart';
import '../../widgets/common/shimmer_loading.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';
import '../../models/api_models.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  @override
  void initState() {
    super.initState();
    context.read<MarketProvider>().startAutoRefresh();
  }

  @override
  void dispose() {
    // L3: หยุด auto-refresh เมื่อออกจาก Home tab — ประหยัด battery
    context.read<MarketProvider>().stopAutoRefresh();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    final market = context.watch<MarketProvider>();

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: AppGradients.darkBg),
        child: SafeArea(
          bottom: false,
          child: RefreshIndicator(
            color: AppColors.brandCyan,
            backgroundColor: AppColors.bgSecondary,
            onRefresh: () async {
              // U5: ใช้ silent mode ไม่ให้ shimmer flash เมื่อ pull-to-refresh
              await Future.wait([
                market.loadTickers(silent: true),
                market.loadTpixPrice(),
              ]);
            },
            child: CustomScrollView(
              slivers: [
                // Header
                SliverToBoxAdapter(child: _buildHeader(locale, wallet)),

                // Update Banner (ถ้ามี update ใหม่)
                SliverToBoxAdapter(child: _buildUpdateBanner(locale)),

                // Peer app card (ถ้า TPIX Wallet ติดตั้งในเครื่อง)
                const SliverToBoxAdapter(
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(16, 8, 16, 0),
                    child: PeerAppCard(),
                  ),
                ),

                // Portfolio / Connect Card
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                    child: wallet.isConnected
                        ? _buildPortfolioCard(wallet, market, locale)
                        : _buildConnectCard(locale),
                  ),
                ),

                // TPIX Price Card
                if (market.tpixPrice != null)
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                      child: _buildTpixPriceCard(market.tpixPrice!),
                    ),
                  ),

                // Top Gainers
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    locale.t('home.top_gainers'),
                    Icons.trending_up_rounded,
                    AppColors.tradingGreen,
                  ),
                ),
                SliverToBoxAdapter(
                  child: market.isLoading
                      ? const SizedBox(
                          height: 90,
                          child: Center(child: ShimmerBox(width: 200, height: 14)))
                      : _buildHorizontalTickers(market.topGainers),
                ),

                // Top Losers
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    locale.t('home.top_losers'),
                    Icons.trending_down_rounded,
                    AppColors.tradingRed,
                  ),
                ),
                SliverToBoxAdapter(
                  child: market.isLoading
                      ? const SizedBox(
                          height: 90,
                          child: Center(child: ShimmerBox(width: 200, height: 14)))
                      : _buildHorizontalTickers(market.topLosers),
                ),

                // Spacer for bottom nav
                const SliverToBoxAdapter(child: SizedBox(height: 100)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ── Update Banner ──

  Widget _buildUpdateBanner(LocaleProvider locale) {
    return Consumer<UpdateProvider>(
      builder: (_, update, __) {
        if (!update.hasUpdate) return const SizedBox.shrink();
        final result = update.result!;

        return Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: GlassCard(
            variant: GlassVariant.brand,
            borderRadius: 14,
            padding: const EdgeInsets.all(12),
            onTap: () => _showUpdateDialog(result, update.service, locale),
            child: Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    gradient: AppGradients.brand,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.system_update_rounded,
                      color: Colors.white, size: 18),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        locale.t('update.available'),
                        style: GoogleFonts.inter(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      Text(
                        'v${result.currentVersion} → v${result.latestVersion}',
                        style: AppTheme.mono(
                          fontSize: 11,
                          color: AppColors.brandCyan,
                        ),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close_rounded,
                      color: AppColors.textTertiary, size: 18),
                  onPressed: () => update.dismiss(),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  void _showUpdateDialog(
      UpdateResult result, UpdateService service, LocaleProvider locale) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => _HomeUpdateDialog(
          result: result, service: service, locale: locale),
    );
  }

  Widget _buildHeader(LocaleProvider locale, WalletProvider wallet) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
      child: Row(
        children: [
          // TPIX logo
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: Image.asset('assets/images/logo.webp',
                width: 36, height: 36, fit: BoxFit.cover),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ShaderMask(
                  shaderCallback: (bounds) =>
                      AppGradients.brand.createShader(bounds),
                  child: Text(
                    'TPIX TRADE',
                    style: GoogleFonts.inter(
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                      letterSpacing: 1,
                    ),
                  ),
                ),
                Text(
                  'Decentralized Exchange',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
          // Notification bell
          IconButton(
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(locale.t('common.coming_soon')),
                  duration: const Duration(seconds: 1),
                ),
              );
            },
            icon: const Icon(Icons.notifications_outlined,
                color: AppColors.textSecondary, size: 22),
          ),
        ],
      ),
    );
  }

  Widget _buildPortfolioCard(WalletProvider wallet, MarketProvider market, LocaleProvider locale) {
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: 20,
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.account_balance_wallet_rounded,
                  color: AppColors.brandCyan, size: 18),
              const SizedBox(width: 8),
              Text(
                wallet.shortAddress,
                style: AppTheme.mono(
                  fontSize: 13,
                  color: AppColors.textSecondary,
                ),
              ),
              const Spacer(),
              GestureDetector(
                onTap: () {
                  if (wallet.address != null) {
                    Clipboard.setData(ClipboardData(text: wallet.address!));
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(locale.t('wallet.address_copied')),
                        duration: const Duration(seconds: 1),
                      ),
                    );
                  }
                },
                child: const Icon(Icons.copy_rounded,
                    color: AppColors.textTertiary, size: 16),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Text(
            locale.isThai ? 'มูลค่าพอร์ต' : 'Portfolio Value',
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textTertiary,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            '\$${wallet.totalPortfolioValue.toStringAsFixed(2)}',
            style: AppTheme.mono(
              fontSize: 28,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            '${wallet.balances.length} ${locale.isThai ? 'สินทรัพย์' : 'assets'}',
            style: GoogleFonts.inter(
              fontSize: 11,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildConnectCard(LocaleProvider locale) {
    return GlassCard(
      variant: GlassVariant.brand,
      borderRadius: 20,
      padding: const EdgeInsets.all(24),
      onTap: () {
        showModalBottomSheet(
          context: context,
          isScrollControlled: true,
          useSafeArea: true, // กัน Android nav bar บัง wallet picker
          backgroundColor: Colors.transparent,
          builder: (_) => const WalletConnectSheet(),
        );
      },
      child: Column(
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              gradient: AppGradients.brand,
              borderRadius: BorderRadius.circular(16),
            ),
            child: const Icon(Icons.account_balance_wallet_rounded,
                color: Colors.white, size: 28),
          ),
          const SizedBox(height: 16),
          Text(
            locale.t('settings.connect_wallet'),
            style: GoogleFonts.inter(
              fontSize: 16,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            locale.isThai
                ? 'เชื่อมกระเป๋าเพื่อเริ่มเทรด'
                : 'Connect your wallet to start trading',
            style: GoogleFonts.inter(
              fontSize: 13,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTpixPriceCard(TpixPrice tpix) {
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Row(
        children: [
          // TPIX logo
          CoinLogo(symbol: 'TPIX', size: 40, borderRadius: 12),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'TPIX',
                  style: GoogleFonts.inter(
                    fontSize: 15,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
                Text(
                  'TPIX Chain',
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
              PriceText(price: tpix.price, fontSize: 15),
              const SizedBox(height: 2),
              ChangeBadge(changePercent: tpix.change24h),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildSectionTitle(String title, IconData icon, Color color) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 8),
      child: Row(
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 8),
          Text(
            title,
            style: GoogleFonts.inter(
              fontSize: 15,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildHorizontalTickers(List<Ticker> tickers) {
    if (tickers.isEmpty) {
      return const SizedBox(
        height: 90,
        child: Center(
          child: Text('No data', style: TextStyle(color: AppColors.textTertiary)),
        ),
      );
    }

    return SizedBox(
      height: 90,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: tickers.length,
        itemBuilder: (context, index) {
          final ticker = tickers[index];
          return _TickerMiniCard(ticker: ticker);
        },
      ),
    );
  }
}

class _TickerMiniCard extends StatelessWidget {
  final Ticker ticker;

  const _TickerMiniCard({required this.ticker});

  @override
  Widget build(BuildContext context) {
    return GlassContainer(
      borderRadius: 12,
      padding: const EdgeInsets.all(12),
      margin: const EdgeInsets.only(right: 10),
      child: SizedBox(
        width: 130,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Row(
              children: [
                Text(
                  ticker.baseAsset,
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
                Text(
                  '/${ticker.quoteAsset}',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
            const Spacer(),
            PriceText(
              price: ticker.lastPrice,
              fontSize: 13,
            ),
            const SizedBox(height: 4),
            ChangeBadge(
              changePercent: ticker.priceChangePercent,
              fontSize: 10,
            ),
          ],
        ),
      ),
    );
  }
}

// ── Home Update Dialog ──

class _HomeUpdateDialog extends StatefulWidget {
  final UpdateResult result;
  final UpdateService service;
  final LocaleProvider locale;

  const _HomeUpdateDialog({
    required this.result,
    required this.service,
    required this.locale,
  });

  @override
  State<_HomeUpdateDialog> createState() => _HomeUpdateDialogState();
}

class _HomeUpdateDialogState extends State<_HomeUpdateDialog> {
  bool _downloading = false;
  double _progress = 0;
  String _status = '';

  @override
  Widget build(BuildContext context) {
    final locale = widget.locale;
    return AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: AppColors.bgCardBorder),
      ),
      title: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: AppColors.brandCyan.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.system_update_rounded,
                color: AppColors.brandCyan, size: 20),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(locale.t('update.available'),
                style: const TextStyle(
                    color: AppColors.textPrimary, fontSize: 16)),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            'v${widget.result.currentVersion} → v${widget.result.latestVersion}',
            style: AppTheme.mono(fontSize: 14, color: AppColors.brandCyan),
          ),
          if (widget.result.releaseNotes != null) ...[
            const SizedBox(height: 12),
            Container(
              constraints: const BoxConstraints(maxHeight: 100),
              child: SingleChildScrollView(
                child: Text(
                  widget.result.releaseNotes!,
                  style: GoogleFonts.inter(
                      fontSize: 11, color: AppColors.textTertiary),
                ),
              ),
            ),
          ],
          if (_downloading) ...[
            const SizedBox(height: 16),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: _progress > 0 ? _progress : null,
                backgroundColor: AppColors.bgTertiary,
                valueColor:
                    const AlwaysStoppedAnimation(AppColors.brandCyan),
                minHeight: 5,
              ),
            ),
            const SizedBox(height: 6),
            Text(_status,
                style: GoogleFonts.inter(
                    fontSize: 11, color: AppColors.textTertiary)),
          ],
        ],
      ),
      actions: [
        if (!_downloading) ...[
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(locale.t('common.later'),
                style:
                    const TextStyle(color: AppColors.textTertiary)),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.download_rounded,
                color: Colors.white, size: 16),
            label: Text(locale.t('common.download'),
                style: const TextStyle(color: Colors.white)),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.brandCyan,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10)),
            ),
            onPressed: _startDownload,
          ),
        ],
      ],
    );
  }

  void _startDownload() async {
    final url = widget.result.apkDownloadUrl;
    if (url == null) {
      await widget.service.openDownloadPage();
      if (mounted) Navigator.pop(context);
      return;
    }

    setState(() {
      _downloading = true;
      _status = widget.locale.t('common.downloading');
    });

    final success = await widget.service.downloadAndInstall(
      url,
      widget.result.latestVersion ?? 'latest',
      expectedSize: widget.result.apkSize,
      onProgress: (received, total) {
        if (!mounted) return;
        if (total > 0) {
          setState(() {
            _progress = received / total;
            final mb = (received / 1024 / 1024).toStringAsFixed(1);
            final totalMb = (total / 1024 / 1024).toStringAsFixed(1);
            _status = '$mb / $totalMb MB';
          });
        }
      },
    );

    if (!mounted) return;
    if (success) {
      Navigator.pop(context);
    } else {
      await widget.service.openDownloadPage();
      if (mounted) Navigator.pop(context);
    }
  }
}
