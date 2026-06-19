/// TPIX TRADE — Shell Screen
/// Floating "gilded glass" bottom navigation: a frosted gold-edged bar with a
/// 70px gold center button that straddles the bar. 5 destinations.
///
/// NOTE: destinations still map to the existing branches
/// (Home · Markets · [Trade] · Portfolio · Settings). The handoff's
/// Home·AI·Market·Swap·Wallet wiring lands in the screen-by-screen phase
/// once the AI Trade / Swap / Wallet screens exist.
///
/// Developed by Xman Studio

import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../core/theme/app_colors.dart';
import '../providers/accent_provider.dart';
import '../core/locale/locale_provider.dart';
import '../widgets/wallet/waiting_for_wallet_banner.dart';

/// Inactive nav icon/label tint.
const Color _kInactive = Color(0xFF6B6A63);

class ShellScreen extends StatelessWidget {
  final StatefulNavigationShell navigationShell;

  const ShellScreen({super.key, required this.navigationShell});

  void _onTap(int index) {
    navigationShell.goBranch(
      index,
      initialLocation: index == navigationShell.currentIndex,
    );
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final current = navigationShell.currentIndex;

    return Scaffold(
      extendBody: true,
      body: Stack(
        children: [
          navigationShell,
          const Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(child: WaitingForWalletBanner()),
          ),
          // Floating gilded nav bar
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: _FloatingNavBar(
              currentIndex: current,
              onTap: _onTap,
              labels: [
                locale.t('nav.home'),
                locale.t('nav.ai'),
                locale.t('nav.market'),
                locale.t('nav.swap'),
                locale.t('nav.wallet'),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _FloatingNavBar extends StatelessWidget {
  final int currentIndex;
  final void Function(int) onTap;
  final List<String> labels;

  const _FloatingNavBar({
    required this.currentIndex,
    required this.onTap,
    required this.labels,
  });

  static const double _barHeight = 62;
  static const double _centerSize = 70;

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        child: SizedBox(
          height: _barHeight + 14, // headroom for the straddling button
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              // The bar itself
              Positioned(
                left: 0,
                right: 0,
                bottom: 0,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(22),
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                    child: Container(
                      height: _barHeight,
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(22),
                        gradient: const LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [Color(0xE61C1F29), Color(0xF20C0D11)],
                        ),
                        border: Border.all(color: accent.goldBorder, width: 1.6),
                        boxShadow: const [
                          BoxShadow(
                            color: Color(0x99000000),
                            blurRadius: 24,
                            offset: Offset(0, 10),
                          ),
                        ],
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: _NavItem(
                              icon: Icons.home_rounded,
                              label: labels[0],
                              isActive: currentIndex == 0,
                              activeColor: accent.g2,
                              onTap: () => onTap(0),
                            ),
                          ),
                          Expanded(
                            child: _NavItem(
                              icon: Icons.auto_awesome_rounded,
                              label: labels[1],
                              isActive: currentIndex == 1,
                              activeColor: accent.g2,
                              onTap: () => onTap(1),
                            ),
                          ),
                          // center slot reserved for the straddling button
                          const SizedBox(width: _centerSize),
                          Expanded(
                            child: _NavItem(
                              icon: Icons.swap_horiz_rounded,
                              label: labels[3],
                              isActive: currentIndex == 3,
                              activeColor: accent.g2,
                              onTap: () => onTap(3),
                            ),
                          ),
                          Expanded(
                            child: _NavItem(
                              icon: Icons.account_balance_wallet_rounded,
                              label: labels[4],
                              isActive: currentIndex == 4,
                              activeColor: accent.g2,
                              onTap: () => onTap(4),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              // Center "Market" gold button — straddles the bar (±4px)
              Positioned(
                bottom: _barHeight / 2 - _centerSize / 2,
                left: 0,
                right: 0,
                child: Center(
                  child: _MarketButton(
                    size: _centerSize,
                    label: labels[2],
                    isActive: currentIndex == 2,
                    gradient: accent.goldGradient,
                    glow: accent.goldGlow,
                    onTap: () => onTap(2),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Regular nav item ──

class _NavItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool isActive;
  final Color activeColor;
  final VoidCallback onTap;

  const _NavItem({
    required this.icon,
    required this.label,
    required this.isActive,
    required this.activeColor,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final color = isActive ? activeColor : _kInactive;
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: SizedBox(
        height: double.infinity,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 22, color: color),
            const SizedBox(height: 3),
            Text(
              label,
              style: TextStyle(
                fontSize: 9,
                fontWeight: isActive ? FontWeight.w700 : FontWeight.w500,
                color: color,
                letterSpacing: 0.3,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}

// ── Central gold "Market" button (straddles the bar) ──

class _MarketButton extends StatelessWidget {
  final double size;
  final String label;
  final bool isActive;
  final LinearGradient gradient;
  final Color glow;
  final VoidCallback onTap;

  const _MarketButton({
    required this.size,
    required this.label,
    required this.isActive,
    required this.gradient,
    required this.glow,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: gradient,
          border: Border.all(color: const Color(0x40FFFFFF), width: 2),
          boxShadow: [
            BoxShadow(
              color: glow.withValues(alpha: isActive ? 0.6 : 0.4),
              blurRadius: 22,
              spreadRadius: -2,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(
              Icons.candlestick_chart_rounded,
              size: 24,
              color: AppColors.goldTextOn,
            ),
            const SizedBox(height: 1),
            Text(
              label,
              style: const TextStyle(
                fontSize: 8.5,
                fontWeight: FontWeight.w800,
                color: AppColors.goldTextOn,
                letterSpacing: 0.2,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}
