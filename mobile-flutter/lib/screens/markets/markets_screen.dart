// TPIX TRADE — Markets Screen (Luxury Dark / Gilded Metal)
// The coin / market picker: search, All/Spot/Favorites tabs, sortable header,
// and a list of gilded market rows. Sits on the gunmetal+gold backdrop with
// ambient fireflies. All figures are real (MarketProvider); the favorites and
// row-tap behaviour are unchanged.
//
// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/config_provider.dart';
import '../../providers/market_provider.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/mini_sparkline.dart';
import '../../widgets/common/price_text.dart';
import '../../widgets/common/shimmer_loading.dart';
import '../../models/api_models.dart';

class MarketsScreen extends StatefulWidget {
  const MarketsScreen({super.key});

  @override
  State<MarketsScreen> createState() => _MarketsScreenState();
}

class _MarketsScreenState extends State<MarketsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final market = context.watch<MarketProvider>();
    final accent = context.watch<AccentProvider>();

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: Column(
            children: [
              // ── Header ──
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                child: Row(
                  children: [
                    Icon(Icons.show_chart_rounded,
                        size: 22, color: accent.g2),
                    const SizedBox(width: 10),
                    Text(
                      locale.t('nav.markets'),
                      style: GoogleFonts.inter(
                        fontSize: 24,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                        letterSpacing: -0.4,
                      ),
                    ),
                  ],
                ),
              ),

              // ── Search bar ──
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 4, 18, 12),
                child: TextField(
                  controller: _searchController,
                  onChanged: (v) => market.setSearchQuery(v),
                  style: GoogleFonts.inter(
                      color: AppColors.textPrimary, fontSize: 14),
                  cursorColor: accent.g2,
                  decoration: InputDecoration(
                    isDense: true,
                    filled: true,
                    fillColor: AppColors.bgInput,
                    hintText: locale.t('markets.search'),
                    hintStyle: GoogleFonts.inter(
                        color: AppColors.textTertiary, fontSize: 14),
                    prefixIcon: Icon(Icons.search_rounded,
                        color: accent.g2, size: 20),
                    suffixIcon: _searchController.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear_rounded,
                                color: AppColors.textTertiary, size: 18),
                            onPressed: () {
                              _searchController.clear();
                              market.setSearchQuery('');
                              setState(() {});
                            },
                          )
                        : null,
                    contentPadding: const EdgeInsets.symmetric(
                        horizontal: 16, vertical: 14),
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide:
                          const BorderSide(color: AppColors.bgCardBorder),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide: BorderSide(color: accent.g2, width: 1.5),
                    ),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide:
                          const BorderSide(color: AppColors.bgCardBorder),
                    ),
                  ),
                ),
              ),

              // ── Tabs: All / Spot / Favorites ──
              Container(
                margin: const EdgeInsets.symmetric(horizontal: 18),
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  color: AppColors.bgInput,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppColors.bgCardBorder, width: 1),
                ),
                child: TabBar(
                  controller: _tabController,
                  indicator: BoxDecoration(
                    gradient: accent.goldGradient,
                    borderRadius: BorderRadius.circular(9),
                  ),
                  indicatorSize: TabBarIndicatorSize.tab,
                  labelColor: AppColors.goldTextOn,
                  unselectedLabelColor: AppColors.textTertiary,
                  labelStyle: GoogleFonts.inter(
                      fontSize: 13, fontWeight: FontWeight.w700),
                  unselectedLabelStyle: GoogleFonts.inter(
                      fontSize: 13, fontWeight: FontWeight.w600),
                  dividerColor: Colors.transparent,
                  splashBorderRadius: BorderRadius.circular(9),
                  tabs: [
                    Tab(text: locale.t('markets.all')),
                    Tab(text: locale.t('markets.spot')),
                    Tab(text: locale.t('markets.favorites')),
                  ],
                ),
              ),

              const SizedBox(height: 12),

              // ── Sort header ──
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 22),
                child: Row(
                  children: [
                    _SortButton(
                      label: locale.isThai ? 'ชื่อ' : 'Name',
                      isActive: market.sortBy == 'name',
                      ascending: market.sortAsc,
                      onTap: () => market.setSortBy('name'),
                    ),
                    const Spacer(),
                    _SortButton(
                      label: locale.t('markets.price'),
                      isActive: market.sortBy == 'price',
                      ascending: market.sortAsc,
                      onTap: () => market.setSortBy('price'),
                    ),
                    const SizedBox(width: 18),
                    _SortButton(
                      label: locale.t('markets.change'),
                      isActive: market.sortBy == 'change',
                      ascending: market.sortAsc,
                      onTap: () => market.setSortBy('change'),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 6),

              // ── Ticker list ──
              Expanded(
                child: TabBarView(
                  controller: _tabController,
                  children: [
                    _TickerList(tickers: market.tickers, market: market),
                    _TickerList(tickers: market.tickers, market: market),
                    _TickerList(
                        tickers: market.favoriteTickers, market: market),
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

// ── Sort button ──

class _SortButton extends StatelessWidget {
  final String label;
  final bool isActive;
  final bool ascending;
  final VoidCallback onTap;

  const _SortButton({
    required this.label,
    required this.isActive,
    required this.ascending,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final color = isActive ? accent.g2 : AppColors.textTertiary;
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 11.5,
              fontWeight: isActive ? FontWeight.w700 : FontWeight.w500,
              color: color,
              letterSpacing: 0.2,
            ),
          ),
          if (isActive) ...[
            const SizedBox(width: 2),
            Icon(
              ascending
                  ? Icons.arrow_upward_rounded
                  : Icons.arrow_downward_rounded,
              size: 12,
              color: color,
            ),
          ],
        ],
      ),
    );
  }
}

// ── Ticker list ──

class _TickerList extends StatelessWidget {
  final List<Ticker> tickers;
  final MarketProvider market;

  const _TickerList({required this.tickers, required this.market});

  @override
  Widget build(BuildContext context) {
    if (market.isLoading && tickers.isEmpty) {
      return const ShimmerList();
    }

    if (tickers.isEmpty) {
      final locale = context.read<LocaleProvider>();
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.search_off_rounded,
                color: AppColors.textTertiary, size: 48),
            const SizedBox(height: 12),
            Text(
              locale.t('common.no_data'),
              style: GoogleFonts.inter(
                  color: AppColors.textTertiary, fontSize: 14),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(18, 6, 18, 110),
      itemCount: tickers.length,
      itemBuilder: (context, index) {
        final ticker = tickers[index];
        return _MarketRow(
          ticker: ticker,
          isFavorite: market.favorites.contains(ticker.symbol),
          onTap: () {
            // Pick the market, then open Trade (same shell branch → tab bar stays)
            market.selectPair(ticker.symbol);
            context.go('/trade');
          },
          onFavorite: () => market.toggleFavorite(ticker.symbol),
        );
      },
    );
  }
}

// ── Market row (mirrors Home _MarketRow) ──

class _MarketRow extends StatelessWidget {
  final Ticker ticker;
  final bool isFavorite;
  final VoidCallback onTap;
  final VoidCallback onFavorite;

  const _MarketRow({
    required this.ticker,
    required this.isFavorite,
    required this.onTap,
    required this.onFavorite,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final logoUrl = context
        .read<ConfigProvider>()
        .pairBySymbol(ticker.symbol)
        ?.baseLogo;
    final isTpix = ticker.baseAsset == 'TPIX';

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16),
          child: Ink(
            padding:
                const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: AppGradients.cardSubtle,
              border: Border.all(color: AppColors.bgCardBorder, width: 1),
            ),
            child: Row(
              children: [
                // Favorite star — gold accent when active (not a price colour)
                GestureDetector(
                  onTap: onFavorite,
                  behavior: HitTestBehavior.opaque,
                  child: Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Icon(
                      isFavorite
                          ? Icons.star_rounded
                          : Icons.star_border_rounded,
                      size: 18,
                      color: isFavorite ? accent.g2 : AppColors.textDisabled,
                    ),
                  ),
                ),

                CoinChip(
                  symbol: ticker.baseAsset,
                  size: 38,
                  logoUrl: logoUrl,
                ),
                const SizedBox(width: 12),

                // Name + pair + volume
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        ticker.baseAsset,
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Text(
                            ticker.displaySymbol,
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              color: AppColors.textTertiary,
                            ),
                          ),
                          const SizedBox(width: 6),
                          VolumeText(
                              volume: ticker.quoteVolume24h, fontSize: 10),
                        ],
                      ),
                    ],
                  ),
                ),

                // Mini sparkline (24h trend) — TPIX has no Binance feed
                if (!isTpix) ...[
                  MiniSparkline(
                    symbol: '${ticker.baseAsset}-${ticker.quoteAsset}',
                    width: 52,
                    height: 28,
                    isPositive: ticker.isPositive,
                  ),
                  const SizedBox(width: 12),
                ],

                // Price + change
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    PriceText(price: ticker.lastPrice, fontSize: 14),
                    const SizedBox(height: 4),
                    ChangeBadge(
                        changePercent: ticker.priceChangePercent,
                        fontSize: 10),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
