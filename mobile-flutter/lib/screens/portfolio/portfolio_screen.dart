/// TPIX TRADE — Portfolio Screen (Luxury Dark / Gilded Metal)
/// Net-worth hero with a gold/teal donut, holdings with real allocation bars,
/// and trade history — all on the gunmetal+gold backdrop with ambient
/// fireflies. Every figure is real (wallet provider); the only non-data label
/// is the "30d" pill, which is explicitly a PREVIEW (no historical series yet).
///
/// Developed by Xman Studio

import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../core/theme/app_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../models/api_models.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/accent_provider.dart';
import '../../services/token_list_service.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/buy_tpix_button.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/shimmer_loading.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';
import '../../widgets/obsidian/obsidian_icons.dart';

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

          // ซื้อ TPIX ด้วยบัตร + ทางลัดสะพาน USDT
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 0, 18, 14),
              child: Row(
                children: [
                  const Expanded(child: BuyTpixCard(compact: true)),
                  const SizedBox(width: 10),
                  _BridgeShortcut(locale: locale),
                ],
              ),
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
            style: AppFonts.ui(
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
        labelStyle: AppFonts.ui(fontSize: 13, fontWeight: FontWeight.w700),
        unselectedLabelStyle:
            AppFonts.ui(fontSize: 13, fontWeight: FontWeight.w600),
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

    final th = locale.isThai;
    final state = wallet.historyState;
    // โหลดไม่ได้ ≠ ไม่มีประวัติ — เดิม 403 / เน็ตหลุด ขึ้น "ไม่มีข้อมูล" ทั้งที่ผู้ใช้มีไม้จริง
    final needsVerify = state == ListLoadState.needsWalletVerify;
    final failed = state == ListLoadState.failed;

    if (wallet.tradeHistory.isEmpty) {
      if (needsVerify) {
        return _EmptyState(
          icon: Icons.lock_outline_rounded,
          label: th
              ? 'ยืนยันกระเป๋าเพื่อดูประวัติการเทรด'
              : 'Verify your wallet to see your trade history',
          actionLabel: th ? 'ยืนยันกระเป๋า' : 'Verify wallet',
          busy: _verifying,
          onAction: _verifyWallet,
        );
      }
      if (failed) {
        return _EmptyState(
          icon: Icons.cloud_off_rounded,
          label: th
              ? 'โหลดประวัติไม่สำเร็จ — ตรวจการเชื่อมต่อแล้วลองใหม่'
              : 'Could not load your history — check your connection and try again',
          actionLabel: th ? 'ลองใหม่' : 'Retry',
          busy: wallet.isLoadingPortfolio,
          onAction: () => wallet.loadPortfolio(),
        );
      }
      return _EmptyState(
        icon: Icons.history_rounded,
        label: locale.t('common.no_data'),
      );
    }

    // มีรายการเดิมอยู่แต่รอบล่าสุดโหลดไม่ได้ — โชว์ของเดิม พร้อมบอกว่าอาจไม่ล่าสุด
    final stale = needsVerify || failed;
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 120),
      itemCount: wallet.tradeHistory.length + (stale ? 1 : 0),
      itemBuilder: (_, i) {
        if (stale && i == 0) {
          return _StaleBanner(
            message: needsVerify
                ? (th
                    ? 'ยืนยันกระเป๋าเพื่ออัปเดตประวัติล่าสุด'
                    : 'Verify your wallet to refresh your history')
                : (th
                    ? 'อัปเดตประวัติไม่สำเร็จ — รายการนี้อาจไม่ล่าสุด'
                    : 'Could not refresh — this list may be out of date'),
            actionLabel: needsVerify
                ? (th ? 'ยืนยัน' : 'Verify')
                : (th ? 'ลองใหม่' : 'Retry'),
            onAction: needsVerify ? _verifyWallet : () => wallet.loadPortfolio(),
          );
        }
        final order = wallet.tradeHistory[i - (stale ? 1 : 0)];
        return _HistoryTile(order: order, isThai: th);
      },
    );
  }

  bool _verifying = false;

  /// เซ็นยืนยันกระเป๋ากับเซิร์ฟเวอร์ แล้วโหลดพอร์ตใหม่ — กันกดซ้ำ บอกผลเมื่อไม่ผ่าน
  Future<void> _verifyWallet() async {
    if (_verifying) return;
    setState(() => _verifying = true);
    var ok = false;
    try {
      ok = await _wallet.verifyWithBackend();
    } catch (_) {
      ok = false;
    }
    if (!mounted) return;
    setState(() => _verifying = false);
    if (ok) {
      // verifyWithBackend โหลดพอร์ตให้เองเมื่อผ่าน — เรียกซ้ำได้ผลรอบเดิม
      _wallet.loadPortfolio();
      return;
    }
    final th = context.read<LocaleProvider>().isThai;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(th
          ? 'ยืนยันกระเป๋าไม่สำเร็จ — ลองอีกครั้ง'
          : 'Wallet verification failed — try again'),
      duration: const Duration(seconds: 3),
    ));
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
            ObsidianIconSwap(icon: Icons.pie_chart_rounded, size: 80, child: Container(
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
              child: Icon(Icons.pie_chart_rounded,
                  color: AppColors.goldTextOn, size: 36),
            )),
            const SizedBox(height: 24),
            Text(
              locale.t('portfolio.title'),
              style: AppFonts.ui(
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
              style: AppFonts.ui(
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
            const SizedBox(height: 20),
            const BuyTpixCard(),
          ],
        ),
      ),
    );
  }
}

// ── ทางลัดสะพาน USDT (BSC ⇄ TPIX Chain) ──

class _BridgeShortcut extends StatelessWidget {
  final LocaleProvider locale;

  const _BridgeShortcut({required this.locale});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GlassCard(
      key: const ValueKey('portfolio-bridge'),
      variant: GlassVariant.standard,
      borderRadius: 14,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      onTap: () => context.push('/bridge'),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.hub_rounded, size: 22, color: accent.g2),
          const SizedBox(height: 4),
          Text(
            locale.isThai ? 'สะพาน USDT' : 'USDT Bridge',
            style: AppFonts.ui(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
        ],
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
                style: AppFonts.ui(
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
                          style: AppFonts.number(
                            fontSize: 24,
                            fontWeight: FontWeight.w700,
                            color: AppColors.textPrimary,
                            height: 1.0,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          locale.isThai ? 'สินทรัพย์' : 'assets',
                          style: AppFonts.ui(
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
                            style: AppFonts.ui(
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
            style: AppFonts.number(
              fontSize: 30,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
              letterSpacing: -0.5,
            ),
          ),
          Text(
            decPart,
            style: AppFonts.number(
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
            style: AppFonts.ui(
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
              // เหรียญบนเชน TPIX (ไม่ใช่ TPIX เนทีฟ): โลโก้จาก token list → เซิร์ฟเวอร์ → ตัวอักษร
              // ห้ามเดาจากชื่อ — เหรียญปลอมชื่อ USDC จะได้โลโก้ USDC จริงไปแอบอ้าง
              CoinChip(
                symbol: token.symbol,
                size: 38,
                logoUrl: token.chainId == 4289 && !token.isNative
                    ? (TokenListService().logoFor(token.contractAddress) ??
                        token.logo)
                    : token.logo,
                chainToken: token.chainId == 4289 && !token.isNative,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      token.symbol,
                      style: AppFonts.ui(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      token.name,
                      style: AppFonts.ui(
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
  final TradeOrder order;
  final bool isThai;

  const _HistoryTile({required this.order, this.isThai = false});

  @override
  Widget build(BuildContext context) {
    // ฝั่งที่ไม่ใช่ buy/sell (เช่น `swap`, `bridge`, ว่าง) = เป็นกลาง
    // เดิมอะไรที่ไม่ใช่ buy ถูกวาดเป็น "ขาย" สีแดงทั้งหมด
    final side = order.tradeSide;
    final sideColor = switch (side) {
      TradeSide.buy => AppColors.tradingGreen,
      TradeSide.sell => AppColors.tradingRed,
      TradeSide.neutral => AppColors.textSecondary,
    };
    final sideBg = switch (side) {
      TradeSide.buy => AppColors.tradingGreenBg,
      TradeSide.sell => AppColors.tradingRedBg,
      TradeSide.neutral => AppColors.bgInputStrong,
    };
    final sideIcon = switch (side) {
      TradeSide.buy => Icons.arrow_downward_rounded,
      TradeSide.sell => Icons.arrow_upward_rounded,
      TradeSide.neutral => Icons.swap_horiz_rounded,
    };
    final sideLabel = switch (side) {
      TradeSide.buy => isThai ? 'ซื้อ' : 'BUY',
      TradeSide.sell => isThai ? 'ขาย' : 'SELL',
      TradeSide.neutral => isThai ? 'สลับ' : 'SWAP',
    };
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
              color: sideBg,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(sideIcon, color: sideColor, size: 16),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '$sideLabel ${order.pair}',
                  style: AppFonts.ui(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${order.type} · ${order.status}',
                  style: AppFonts.ui(
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

  /// ปุ่มทางออก (ยืนยันกระเป๋า / ลองใหม่) — ไม่มี = สถานะว่างธรรมดา
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool busy;

  const _EmptyState({
    required this.icon,
    required this.label,
    this.actionLabel,
    this.onAction,
    this.busy = false,
  });

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
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: AppFonts.ui(
                fontSize: 14, height: 1.4, color: AppColors.textTertiary),
          ),
        ),
        if (actionLabel != null && onAction != null) ...[
          const SizedBox(height: 14),
          Center(
            child: TextButton(
              onPressed: busy ? null : onAction,
              style: TextButton.styleFrom(
                foregroundColor: AppColors.gold1,
                padding:
                    const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                  side: BorderSide(color: AppColors.goldBorder),
                ),
              ),
              child: Text(
                actionLabel!,
                style:
                    AppFonts.ui(fontSize: 13, fontWeight: FontWeight.w700),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

// ── แถบ "รายการนี้อาจไม่ล่าสุด" เหนือประวัติ ──

class _StaleBanner extends StatelessWidget {
  final String message;
  final String actionLabel;
  final VoidCallback onAction;

  const _StaleBanner({
    required this.message,
    required this.actionLabel,
    required this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(12, 6, 6, 6),
      decoration: BoxDecoration(
        color: AppColors.goldTint,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.goldBorder, width: 0.8),
      ),
      child: Row(
        children: [
          Icon(Icons.info_outline_rounded, size: 14, color: AppColors.gold2),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: AppFonts.ui(
                  fontSize: 11.5, height: 1.4, color: AppColors.textPrimary),
            ),
          ),
          TextButton(
            onPressed: onAction,
            style: TextButton.styleFrom(
              foregroundColor: AppColors.gold1,
              padding: const EdgeInsets.symmetric(horizontal: 8),
              minimumSize: const Size(0, 30),
            ),
            child: Text(
              actionLabel,
              style: AppFonts.ui(fontSize: 11.5, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
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
