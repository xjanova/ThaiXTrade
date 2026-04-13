/// TPIX TRADE — Markets Screen
/// รายการเหรียญ: ค้นหา, filter, sort, favorites
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/market_provider.dart';
import '../../widgets/common/coin_logo.dart';
import '../../widgets/common/glass_card.dart';
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

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: AppGradients.darkBg),
        child: SafeArea(
          bottom: false,
          child: Column(
            children: [
              // Header
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                child: Text(
                  locale.t('nav.markets'),
                  style: GoogleFonts.inter(
                    fontSize: 24,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),

              // Search bar
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
                child: GlassContainer(
                  borderRadius: 12,
                  padding: EdgeInsets.zero,
                  child: TextField(
                    controller: _searchController,
                    onChanged: (v) => market.setSearchQuery(v),
                    style: GoogleFonts.inter(
                        color: AppColors.textPrimary, fontSize: 14),
                    decoration: InputDecoration(
                      hintText: locale.t('markets.search'),
                      hintStyle: GoogleFonts.inter(
                          color: AppColors.textDisabled, fontSize: 14),
                      prefixIcon: const Icon(Icons.search_rounded,
                          color: AppColors.textTertiary, size: 20),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear_rounded,
                                  color: AppColors.textTertiary, size: 18),
                              onPressed: () {
                                _searchController.clear();
                                market.setSearchQuery('');
                              },
                            )
                          : null,
                      border: InputBorder.none,
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 12),
                    ),
                  ),
                ),
              ),

              // Tabs: All / Spot / Favorites
              Container(
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
                  labelStyle: GoogleFonts.inter(
                      fontSize: 13, fontWeight: FontWeight.w600),
                  dividerColor: Colors.transparent,
                  tabs: [
                    Tab(text: locale.t('markets.all')),
                    Tab(text: locale.t('markets.spot')),
                    Tab(text: locale.t('markets.favorites')),
                  ],
                ),
              ),

              const SizedBox(height: 8),

              // Sort header
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: Row(
                  children: [
                    _SortButton(
                      label: 'Name',
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
                    const SizedBox(width: 16),
                    _SortButton(
                      label: locale.t('markets.change'),
                      isActive: market.sortBy == 'change',
                      ascending: market.sortAsc,
                      onTap: () => market.setSortBy('change'),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 4),

              // Ticker list
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
    return GestureDetector(
      onTap: onTap,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: isActive ? FontWeight.w600 : FontWeight.w400,
              color: isActive ? AppColors.brandCyan : AppColors.textTertiary,
            ),
          ),
          if (isActive)
            Icon(
              ascending ? Icons.arrow_upward : Icons.arrow_downward,
              size: 12,
              color: AppColors.brandCyan,
            ),
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
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.search_off_rounded,
                color: AppColors.textTertiary, size: 48),
            const SizedBox(height: 12),
            Text(
              context.read<LocaleProvider>().t('common.no_data'),
              style: GoogleFonts.inter(
                  color: AppColors.textTertiary, fontSize: 14),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(bottom: 100),
      itemCount: tickers.length,
      itemBuilder: (context, index) {
        final ticker = tickers[index];
        return _TickerItem(
          ticker: ticker,
          isFavorite: market.favorites.contains(ticker.symbol),
          onTap: () => market.selectPair(ticker.symbol),
          onFavorite: () => market.toggleFavorite(ticker.symbol),
        );
      },
    );
  }
}

// ── Ticker item row ──

class _TickerItem extends StatelessWidget {
  final Ticker ticker;
  final bool isFavorite;
  final VoidCallback onTap;
  final VoidCallback onFavorite;

  const _TickerItem({
    required this.ticker,
    required this.isFavorite,
    required this.onTap,
    required this.onFavorite,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
        child: Row(
          children: [
            // Favorite star
            GestureDetector(
              onTap: onFavorite,
              child: Icon(
                isFavorite ? Icons.star_rounded : Icons.star_border_rounded,
                size: 18,
                color: isFavorite
                    ? AppColors.tradingYellow
                    : AppColors.textDisabled,
              ),
            ),
            const SizedBox(width: 10),

            // Coin logo จาก CDN
            CoinLogo(symbol: ticker.baseAsset, size: 36),
            const SizedBox(width: 10),

            // Name
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    ticker.baseAsset,
                    style: GoogleFonts.inter(
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  Row(
                    children: [
                      Text(
                        ticker.quoteAsset,
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: AppColors.textTertiary,
                        ),
                      ),
                      const SizedBox(width: 6),
                      VolumeText(volume: ticker.quoteVolume24h, fontSize: 10),
                    ],
                  ),
                ],
              ),
            ),

            // Price
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                PriceText(price: ticker.lastPrice, fontSize: 13),
                const SizedBox(height: 4),
                ChangeBadge(changePercent: ticker.priceChangePercent),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
