/// TPIX TRADE — Home Screen (Luxury Dark / Gilded Metal)
/// Top bar · balance hero · ad slider · markets list, on the gunmetal+gold
/// backdrop with ambient fireflies. All data is real (wallet + market); the
/// banners carry true product value-props, not mock figures.
///
/// Developed by Xman Studio

import 'dart:async';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/update_provider.dart';
import '../../providers/accent_provider.dart';
import '../../services/update_service.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/mini_sparkline.dart';
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

  void _openWalletSheet() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const WalletConnectSheet(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    final market = context.watch<MarketProvider>();

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: RefreshIndicator(
            color: AppColors.gold2,
            backgroundColor: AppColors.bgSecondary,
            onRefresh: () async {
              // U5: silent mode — ไม่ให้ shimmer flash เมื่อ pull-to-refresh
              await Future.wait([
                market.loadTickers(silent: true),
                market.loadTpixPrice(),
              ]);
            },
            child: CustomScrollView(
              slivers: [
                SliverToBoxAdapter(child: _buildHeader(locale, wallet)),

                // Update banner (เมื่อมีเวอร์ชันใหม่)
                SliverToBoxAdapter(child: _buildUpdateBanner(locale)),

                // Balance hero
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 8, 18, 4),
                    child: _BalanceHero(
                      wallet: wallet,
                      locale: locale,
                      onConnect: _openWalletSheet,
                    ),
                  ),
                ),

                // Ad / promo slider
                SliverToBoxAdapter(
                  child: _AdBannerSlider(locale: locale),
                ),

                // Peer app card (ถ้า TPIX Wallet ติดตั้งในเครื่อง)
                const SliverToBoxAdapter(
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(18, 4, 18, 0),
                    child: PeerAppCard(),
                  ),
                ),

                // Markets
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    locale.isThai ? 'ตลาด' : 'Markets',
                    Icons.show_chart_rounded,
                  ),
                ),
                SliverToBoxAdapter(child: _buildMarketsList(market)),

                // Top gainers / losers (real movers — kept as quick strips)
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    locale.t('home.top_gainers'),
                    Icons.trending_up_rounded,
                    accent: AppColors.tradingGreen,
                  ),
                ),
                SliverToBoxAdapter(
                  child: market.isLoading && market.topGainers.isEmpty
                      ? const _StripShimmer()
                      : _buildHorizontalTickers(market.topGainers),
                ),
                SliverToBoxAdapter(
                  child: _buildSectionTitle(
                    locale.t('home.top_losers'),
                    Icons.trending_down_rounded,
                    accent: AppColors.tradingRed,
                  ),
                ),
                SliverToBoxAdapter(
                  child: market.isLoading && market.topLosers.isEmpty
                      ? const _StripShimmer()
                      : _buildHorizontalTickers(market.topLosers),
                ),

                // clear floating tab bar
                const SliverToBoxAdapter(child: SizedBox(height: 110)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ── Top bar ──

  Widget _buildHeader(LocaleProvider locale, WalletProvider wallet) {
    final greeting = _greeting(locale.isThai);
    final who = wallet.isConnected
        ? wallet.shortAddress
        : (locale.isThai ? 'ยินดีต้อนรับ' : 'WELCOME');

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 8),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Image.asset('assets/images/logo.webp',
                width: 42, height: 42, fit: BoxFit.cover),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(
                      'TPIX ',
                      style: GoogleFonts.inter(
                        fontSize: 19,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                        letterSpacing: 0.5,
                      ),
                    ),
                    ShaderMask(
                      shaderCallback: (b) =>
                          AppGradients.gold.createShader(b),
                      child: Text(
                        'TRADE',
                        style: GoogleFonts.inter(
                          fontSize: 19,
                          fontWeight: FontWeight.w800,
                          color: Colors.white,
                          letterSpacing: 0.5,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 1),
                Text(
                  '$greeting · $who',
                  style: GoogleFonts.inter(
                    fontSize: 10.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                    letterSpacing: 1.0,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
          _GlassIconButton(
            icon: Icons.settings_rounded,
            onTap: () => context.push('/settings'),
          ),
          const SizedBox(width: 10),
          // Gold avatar — opens the Profile detail screen
          GestureDetector(
            onTap: () => context.push('/profile'),
            child: const _GoldAvatar(),
          ),
        ],
      ),
    );
  }

  String _greeting(bool th) {
    final h = DateTime.now().hour;
    if (h < 12) return th ? 'อรุณสวัสดิ์' : 'GOOD MORNING';
    if (h < 18) return th ? 'สวัสดีตอนบ่าย' : 'GOOD AFTERNOON';
    return th ? 'สวัสดีตอนเย็น' : 'GOOD EVENING';
  }

  // ── Update banner ──

  Widget _buildUpdateBanner(LocaleProvider locale) {
    return Consumer<UpdateProvider>(
      builder: (_, update, __) {
        if (!update.hasUpdate) return const SizedBox.shrink();
        final result = update.result!;

        return Padding(
          padding: const EdgeInsets.fromLTRB(18, 8, 18, 0),
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
                    gradient: AppGradients.gold,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.system_update_rounded,
                      color: AppColors.goldTextOn, size: 18),
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
                          color: AppColors.gold2,
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
      builder: (_) =>
          _HomeUpdateDialog(result: result, service: service, locale: locale),
    );
  }

  // ── Markets list ──

  Widget _buildMarketsList(MarketProvider market) {
    final rows = <Widget>[];

    // TPIX pinned first (native token)
    final tpix = market.tpixPrice;
    if (tpix != null) {
      rows.add(_MarketRow(
        symbol: 'TPIX',
        name: 'TPIX',
        pair: 'TPIX Chain',
        price: tpix.price,
        changePercent: tpix.change24h,
        showSparkline: false,
        onTap: () {
          market.selectPair('TPIX-USDT');
          context.go('/trade');
        },
      ));
    }

    final tickers = market.tickers.take(8).toList();
    if (tickers.isEmpty && market.isLoading) {
      return const Padding(
        padding: EdgeInsets.fromLTRB(18, 0, 18, 0),
        child: Column(
          children: [
            _RowShimmer(),
            _RowShimmer(),
            _RowShimmer(),
          ],
        ),
      );
    }

    for (final t in tickers) {
      if (t.baseAsset == 'TPIX') continue; // avoid dup with pinned
      rows.add(_MarketRow(
        symbol: t.baseAsset,
        name: t.baseAsset,
        pair: t.displaySymbol,
        price: t.lastPrice,
        changePercent: t.priceChangePercent,
        sparklineSymbol: '${t.baseAsset}-${t.quoteAsset}',
        showSparkline: true,
        onTap: () {
          market.selectPair(t.symbol);
          context.go('/trade');
        },
      ));
    }

    if (rows.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18),
        child: Text(
          'No market data',
          style: GoogleFonts.inter(
              fontSize: 13, color: AppColors.textTertiary),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 0, 18, 0),
      child: Column(children: rows),
    );
  }

  Widget _buildSectionTitle(String title, IconData icon, {Color? accent}) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 10),
      child: Row(
        children: [
          Icon(icon, size: 16, color: accent ?? AppColors.gold2),
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

  Widget _buildHorizontalTickers(List<Ticker> tickers) {
    if (tickers.isEmpty) {
      return SizedBox(
        height: 92,
        child: Center(
          child: Text('No data',
              style: GoogleFonts.inter(color: AppColors.textTertiary)),
        ),
      );
    }

    return SizedBox(
      height: 92,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 18),
        itemCount: tickers.length,
        itemBuilder: (context, index) =>
            _TickerMiniCard(ticker: tickers[index]),
      ),
    );
  }
}

// ── Gold avatar (top bar) ──

class _GoldAvatar extends StatelessWidget {
  const _GoldAvatar();

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      width: 40,
      height: 40,
      padding: const EdgeInsets.all(2),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: accent.goldGradient,
        boxShadow: [
          BoxShadow(
            color: accent.goldGlow.withValues(alpha: 0.4),
            blurRadius: 12,
            spreadRadius: -2,
          ),
        ],
      ),
      child: const DecoratedBox(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: AppColors.bgGradBottom,
        ),
        child: Icon(Icons.person_rounded,
            color: AppColors.gold1, size: 22),
      ),
    );
  }
}

class _GlassIconButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;

  const _GlassIconButton({required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: AppColors.bgCard,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: Icon(icon, color: AppColors.textSecondary, size: 20),
      ),
    );
  }
}

// ── Balance hero ──

class _BalanceHero extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;
  final VoidCallback onConnect;

  const _BalanceHero({
    required this.wallet,
    required this.locale,
    required this.onConnect,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final connected = wallet.isConnected;

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(20),
      onTap: connected ? null : onConnect,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                locale.isThai ? 'มูลค่ารวม' : 'TOTAL BALANCE',
                style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textTertiary,
                  letterSpacing: 1.4,
                ),
              ),
              const Spacer(),
              if (connected)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    color: accent.goldTint,
                    border: Border.all(color: accent.goldBorder, width: 1.2),
                  ),
                  child: Row(
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
          const SizedBox(height: 14),
          if (connected) ...[
            _BalanceAmount(value: wallet.totalPortfolioValue),
            const SizedBox(height: 10),
            Row(
              children: [
                Icon(Icons.account_balance_wallet_rounded,
                    size: 13, color: AppColors.textTertiary),
                const SizedBox(width: 6),
                Text(
                  '${wallet.balances.length} ${locale.isThai ? 'สินทรัพย์' : 'assets'}  ·  ${wallet.shortAddress}',
                  style: AppTheme.mono(
                    fontSize: 11.5,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ] else ...[
            Text(
              locale.isThai
                  ? 'เชื่อมกระเป๋าเพื่อดูยอดคงเหลือ'
                  : 'Connect wallet to view your balance',
              style: GoogleFonts.inter(
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 9),
                  decoration: BoxDecoration(
                    gradient: accent.goldGradient,
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: [
                      BoxShadow(
                        color: accent.goldGlow.withValues(alpha: 0.4),
                        blurRadius: 18,
                        spreadRadius: -6,
                      ),
                    ],
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.account_balance_wallet_rounded,
                          size: 16, color: AppColors.goldTextOn),
                      const SizedBox(width: 8),
                      Text(
                        locale.t('settings.connect_wallet'),
                        style: GoogleFonts.inter(
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                          color: AppColors.goldTextOn,
                        ),
                      ),
                    ],
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

class _BalanceAmount extends StatelessWidget {
  final double value;
  const _BalanceAmount({required this.value});

  @override
  Widget build(BuildContext context) {
    final s = value.toStringAsFixed(2);
    final dot = s.indexOf('.');
    final intPart = _group(s.substring(0, dot));
    final decPart = s.substring(dot); // ".74"

    return Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      children: [
        Text(
          '\$$intPart',
          style: GoogleFonts.jetBrainsMono(
            fontSize: 34,
            fontWeight: FontWeight.w600,
            color: AppColors.textPrimary,
            letterSpacing: -0.5,
          ),
        ),
        Text(
          decPart,
          style: GoogleFonts.jetBrainsMono(
            fontSize: 22,
            fontWeight: FontWeight.w600,
            color: AppColors.textTertiary,
          ),
        ),
      ],
    );
  }

  String _group(String digits) {
    // Strip a leading sign before grouping so the '-' isn't treated as a digit.
    final neg = digits.startsWith('-');
    final s = neg ? digits.substring(1) : digits;
    final buf = StringBuffer();
    final n = s.length;
    for (int i = 0; i < n; i++) {
      if (i > 0 && (n - i) % 3 == 0) buf.write(',');
      buf.write(s[i]);
    }
    return neg ? '-$buf' : buf.toString();
  }
}

// ── Ad / promo slider ──

class _AdBannerSlider extends StatefulWidget {
  final LocaleProvider locale;
  const _AdBannerSlider({required this.locale});

  @override
  State<_AdBannerSlider> createState() => _AdBannerSliderState();
}

class _AdBannerSliderState extends State<_AdBannerSlider> {
  final _controller = PageController();
  Timer? _timer;
  int _index = 0;
  int _count = 0;

  void _syncAutoplay(bool reduceMotion) {
    final wantTimer = !reduceMotion && _count > 1;
    if (wantTimer && _timer == null) {
      _timer = Timer.periodic(const Duration(milliseconds: 3500), (_) {
        if (!mounted || !_controller.hasClients || _count <= 1) return;
        final next = (_index + 1) % _count;
        _controller.animateToPage(
          next,
          duration: const Duration(milliseconds: 450),
          curve: Curves.easeInOut,
        );
      });
    } else if (!wantTimer && _timer != null) {
      _timer!.cancel();
      _timer = null;
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  List<_BannerData> _banners(bool th) => [
        _BannerData(
          tag: th ? 'ฟรีค่าธรรมเนียม' : 'ZERO FEE',
          title: th ? 'สลับเหรียญไม่มีค่าแก๊ส' : 'Zero-gas swaps',
          sub: th
              ? 'เทรดบน TPIX Chain ไม่มีค่าธรรมเนียมเครือข่าย'
              : 'Trade on TPIX Chain with no network fees',
          emblem: '⚡',
        ),
        _BannerData(
          tag: th ? 'หลายเชน' : 'MULTI-CHAIN',
          title: th ? 'BTC · ETH · BNB · SOL' : 'BTC · ETH · BNB · SOL',
          sub: th
              ? 'เข้าถึงตลาดคริปโตหลักจากที่เดียว'
              : 'Access major crypto markets in one app',
          emblem: '🌐',
        ),
        _BannerData(
          tag: th ? 'ปลอดภัย' : 'NON-CUSTODIAL',
          title: th ? 'กุญแจของคุณ คริปโตของคุณ' : 'Your keys, your crypto',
          sub: th
              ? 'กุญแจถูกเข้ารหัสในเครื่อง ไม่เคยส่งออก'
              : 'Keys encrypted on-device, never transmitted',
          emblem: '🔐',
        ),
      ];

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final banners = _banners(widget.locale.isThai);
    _count = banners.length;
    _syncAutoplay(accent.reduceMotion);

    return Column(
      children: [
        SizedBox(
          height: 104,
          child: PageView.builder(
            controller: _controller,
            itemCount: banners.length,
            onPageChanged: (i) => setState(() => _index = i),
            itemBuilder: (_, i) => _BannerCard(data: banners[i]),
          ),
        ),
        const SizedBox(height: 10),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(banners.length, (i) {
            final active = i == _index;
            return AnimatedContainer(
              duration: accent.reduceMotion
                  ? Duration.zero
                  : const Duration(milliseconds: 250),
              margin: const EdgeInsets.symmetric(horizontal: 3),
              width: active ? 18 : 6,
              height: 6,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                gradient: active ? accent.goldGradient : null,
                color: active ? null : const Color(0x2EFFFFFF),
              ),
            );
          }),
        ),
      ],
    );
  }
}

class _BannerData {
  final String tag, title, sub, emblem;
  const _BannerData({
    required this.tag,
    required this.title,
    required this.sub,
    required this.emblem,
  });
}

class _BannerCard extends StatelessWidget {
  final _BannerData data;
  const _BannerCard({required this.data});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(20),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            gradient: AppGradients.glassCard,
            border: Border.all(color: accent.goldBorder, width: 1.4),
          ),
          child: Stack(
            children: [
              // giant faint emblem bottom-right
              Positioned(
                right: 10,
                bottom: -14,
                child: Text(
                  data.emblem,
                  style: const TextStyle(
                      fontSize: 78, color: Color(0x14FFFFFF)),
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: accent.goldTint,
                        border:
                            Border.all(color: accent.goldBorder, width: 1),
                      ),
                      child: Text(
                        data.tag,
                        style: GoogleFonts.inter(
                          fontSize: 9,
                          fontWeight: FontWeight.w800,
                          color: accent.g1,
                          letterSpacing: 0.8,
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      data.title,
                      style: GoogleFonts.inter(
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                        letterSpacing: -0.2,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      data.sub,
                      style: GoogleFonts.inter(
                        fontSize: 11.5,
                        color: AppColors.textSecondary,
                      ),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Market row (vertical list) ──

class _MarketRow extends StatelessWidget {
  final String symbol;
  final String name;
  final String pair;
  final double price;
  final double changePercent;
  final String? sparklineSymbol;
  final bool showSparkline;
  final VoidCallback? onTap;

  const _MarketRow({
    required this.symbol,
    required this.name,
    required this.pair,
    required this.price,
    required this.changePercent,
    this.sparklineSymbol,
    this.showSparkline = true,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final positive = changePercent >= 0;
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
          CoinChip(symbol: symbol, size: 38),
          const SizedBox(width: 12),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                name,
                style: GoogleFonts.inter(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                pair,
                style: GoogleFonts.inter(
                  fontSize: 11,
                  color: AppColors.textTertiary,
                ),
              ),
            ],
          ),
          const Spacer(),
          if (showSparkline && sparklineSymbol != null) ...[
            MiniSparkline(
              symbol: sparklineSymbol!,
              width: 52,
              height: 28,
              isPositive: positive,
            ),
            const SizedBox(width: 12),
          ],
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              PriceText(price: price, fontSize: 14),
              const SizedBox(height: 4),
              ChangeBadge(changePercent: changePercent, fontSize: 10),
            ],
          ),
        ],
      ),
      ),
    );
  }
}

// ── Horizontal mini ticker (gainers / losers) ──

class _TickerMiniCard extends StatelessWidget {
  final Ticker ticker;
  const _TickerMiniCard({required this.ticker});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 150,
      margin: const EdgeInsets.only(right: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            children: [
              CoinChip(symbol: ticker.baseAsset, size: 28),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  ticker.baseAsset,
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const Spacer(),
          PriceText(price: ticker.lastPrice, fontSize: 13),
          const SizedBox(height: 4),
          ChangeBadge(changePercent: ticker.priceChangePercent, fontSize: 10),
        ],
      ),
    );
  }
}

// ── Shimmers ──

class _StripShimmer extends StatelessWidget {
  const _StripShimmer();
  @override
  Widget build(BuildContext context) {
    return const SizedBox(
      height: 92,
      child: Center(child: ShimmerBox(width: 200, height: 14)),
    );
  }
}

class _RowShimmer extends StatelessWidget {
  const _RowShimmer();
  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: const [
          ShimmerBox(width: 38, height: 38, borderRadius: 19),
          SizedBox(width: 12),
          ShimmerBox(width: 90, height: 14),
          Spacer(),
          ShimmerBox(width: 60, height: 14),
        ],
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
              color: AppColors.gold2.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.system_update_rounded,
                color: AppColors.gold2, size: 20),
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
            style: AppTheme.mono(fontSize: 14, color: AppColors.gold2),
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
                valueColor: const AlwaysStoppedAnimation(AppColors.gold2),
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
                style: const TextStyle(color: AppColors.textTertiary)),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.download_rounded,
                color: AppColors.goldTextOn, size: 16),
            label: Text(locale.t('common.download'),
                style: const TextStyle(color: AppColors.goldTextOn)),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.gold2,
              foregroundColor: AppColors.goldTextOn,
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
