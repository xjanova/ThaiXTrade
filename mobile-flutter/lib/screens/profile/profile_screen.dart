/// TPIX TRADE — Profile Screen (Luxury Dark / Gilded Metal)
/// Detail screen pushed over the shell: header with back chevron, a gilded
/// profile card (gold-ring avatar · address pill · status badge), three stat
/// tiles built from REAL wallet data only, and a menu list. No fabricated
/// balances/PnL/tiers — figures come straight from WalletProvider.
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/accent_provider.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/glass_card.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  void _comingSoon(BuildContext context, LocaleProvider locale) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(locale.t('common.coming_soon')),
        duration: const Duration(seconds: 1),
      ),
    );
  }

  void _back(BuildContext context) {
    // Pushed via go_router — pop if we can, otherwise fall back to settings.
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/settings');
    }
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
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(child: _buildHeader(context, locale)),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 8, 18, 4),
                  child: _ProfileCard(wallet: wallet, locale: locale),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 14, 18, 6),
                  child: _StatRow(wallet: wallet, locale: locale),
                ),
              ),
              SliverToBoxAdapter(child: _buildMenu(context, locale)),
              const SliverToBoxAdapter(child: SizedBox(height: 40)),
            ],
          ),
        ),
      ),
    );
  }

  // ── Header (back · title · edit) ──

  Widget _buildHeader(BuildContext context, LocaleProvider locale) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(10, 10, 10, 4),
      child: Row(
        children: [
          _GlassIconButton(
            icon: Icons.chevron_left_rounded,
            onTap: () => _back(context),
          ),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              locale.isThai ? 'โปรไฟล์' : 'Profile',
              style: GoogleFonts.inter(
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
                letterSpacing: -0.2,
              ),
            ),
          ),
          _GlassIconButton(
            icon: Icons.edit_outlined,
            onTap: () => _comingSoon(context, locale),
          ),
          const SizedBox(width: 6),
        ],
      ),
    );
  }

  // ── Menu list ──

  Widget _buildMenu(BuildContext context, LocaleProvider locale) {
    final th = locale.isThai;
    final items = <_MenuItem>[
      _MenuItem(
        icon: Icons.shield_outlined,
        label: th ? 'บัญชีและความปลอดภัย' : 'Account & Security',
        onTap: () => _comingSoon(context, locale),
      ),
      _MenuItem(
        icon: Icons.account_balance_wallet_outlined,
        label: th ? 'กระเป๋าที่เชื่อม' : 'Linked Wallets',
        onTap: () => _comingSoon(context, locale),
      ),
      _MenuItem(
        icon: Icons.notifications_outlined,
        label: th ? 'การแจ้งเตือน' : 'Notifications',
        onTap: () => _comingSoon(context, locale),
      ),
      _MenuItem(
        icon: Icons.settings_outlined,
        label: th ? 'ตั้งค่า' : 'Settings',
        onTap: () => context.push('/settings'),
      ),
    ];

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 8, 18, 0),
      child: GlassCard(
        variant: GlassVariant.standard,
        borderRadius: 18,
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
        child: Column(
          children: [
            for (int i = 0; i < items.length; i++) ...[
              _MenuRow(item: items[i]),
              if (i != items.length - 1)
                const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 14),
                  child: Divider(height: 1, color: AppColors.divider),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

// ── Profile card (hero) ──

class _ProfileCard extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _ProfileCard({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    final connected = wallet.isConnected;
    // Real display name if the verified profile carries one; otherwise a
    // clearly-generic label (NOT a fabricated identity).
    final realName = wallet.profileName;
    final displayName = (realName != null && realName.trim().isNotEmpty)
        ? realName.trim()
        : (locale.isThai ? 'ผู้ใช้ TPIX' : 'TPIX Trader');

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          const _GoldRingAvatar(),
          const SizedBox(height: 14),
          Text(
            displayName,
            style: GoogleFonts.inter(
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              letterSpacing: -0.2,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 10),
          if (connected)
            _AddressPill(wallet: wallet, locale: locale)
          else
            Text(
              locale.isThai
                  ? 'ยังไม่ได้เชื่อมกระเป๋า'
                  : 'No wallet connected',
              style: GoogleFonts.inter(
                fontSize: 12.5,
                fontWeight: FontWeight.w500,
                color: AppColors.textSecondary,
              ),
            ),
          const SizedBox(height: 12),
          _StatusBadge(connected: connected, locale: locale),
        ],
      ),
    );
  }
}

class _GoldRingAvatar extends StatelessWidget {
  const _GoldRingAvatar();

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      width: 80,
      height: 80,
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: accent.goldGradient,
        boxShadow: [
          BoxShadow(
            color: accent.goldGlow.withValues(alpha: 0.4),
            blurRadius: 20,
            spreadRadius: -4,
          ),
        ],
      ),
      child: const DecoratedBox(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: AppColors.bgGradBottom,
        ),
        child: Icon(Icons.person_rounded, color: AppColors.gold1, size: 40),
      ),
    );
  }
}

class _AddressPill extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _AddressPill({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: () {
        final addr = wallet.address;
        if (addr == null) return;
        Clipboard.setData(ClipboardData(text: addr));
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(locale.t('wallet.address_copied')),
            duration: const Duration(seconds: 1),
          ),
        );
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          color: accent.goldTint,
          border: Border.all(color: accent.goldBorder, width: 1.2),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              wallet.shortAddress,
              style: AppTheme.mono(
                fontSize: 12.5,
                color: AppColors.gold1,
              ),
            ),
            const SizedBox(width: 8),
            Icon(Icons.copy_rounded, size: 13, color: accent.g2),
          ],
        ),
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  final bool connected;
  final LocaleProvider locale;

  const _StatusBadge({required this.connected, required this.locale});

  @override
  Widget build(BuildContext context) {
    // Connected uses the price-up green ONLY as a live status dot signal,
    // not as a generic accent; disconnected stays on neutral text tones.
    final dotColor =
        connected ? AppColors.tradingGreen : AppColors.textTertiary;
    final label = connected
        ? (locale.isThai ? 'เชื่อมต่อแล้ว' : 'Connected')
        : (locale.isThai ? 'ยังไม่เชื่อมต่อ' : 'Not connected');

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: connected ? AppColors.tradingGreenBg : AppColors.bgInput,
        border: Border.all(
          color: connected
              ? AppColors.tradingGreen.withValues(alpha: 0.3)
              : AppColors.bgCardBorder,
          width: 1,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: dotColor,
            ),
          ),
          const SizedBox(width: 7),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              // Green is reserved for the status dot only — keep the label neutral.
              color: AppColors.textSecondary,
              letterSpacing: 0.4,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Stat tiles (REAL data only) ──

class _StatRow extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _StatRow({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    final th = locale.isThai;
    // All three figures are real:
    //  • assets   = number of token balances loaded for this wallet
    //  • network  = active chain short name (TPIX Chain = id 4289)
    //  • since    = brand founding year (static fact, not a per-user metric)
    final assetCount = wallet.balances.length.toString();
    final network = wallet.activeChainId == 4289
        ? 'TPIX'
        : wallet.activeChainId.toString();

    return Row(
      children: [
        Expanded(
          child: _StatTile(
            icon: Icons.pie_chart_outline_rounded,
            value: assetCount,
            label: th ? 'สินทรัพย์' : 'Assets',
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatTile(
            icon: Icons.hub_outlined,
            value: network,
            label: th ? 'เครือข่าย' : 'Network',
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatTile(
            icon: Icons.event_outlined,
            // Brand founding year (platform fact), labelled "Est." so it's not
            // mistaken for a per-user account age.
            value: '2024',
            label: th ? 'ก่อตั้ง' : 'Est.',
          ),
        ),
      ],
    );
  }
}

class _StatTile extends StatelessWidget {
  final IconData icon;
  final String value;
  final String label;

  const _StatTile({
    required this.icon,
    required this.value,
    required this.label,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        children: [
          Icon(icon, size: 18, color: accent.g2),
          const SizedBox(height: 8),
          Text(
            value,
            style: AppTheme.mono(
              fontSize: 16,
              color: AppColors.textPrimary,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 3),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 10.5,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 0.4,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );
  }
}

// ── Menu row ──

class _MenuItem {
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  const _MenuItem({
    required this.icon,
    required this.label,
    required this.onTap,
  });
}

class _MenuRow extends StatelessWidget {
  final _MenuItem item;

  const _MenuRow({required this.item});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: item.onTap,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          child: Row(
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(11),
                  color: accent.goldTint,
                  border: Border.all(color: accent.goldBorder, width: 1),
                ),
                child: Icon(item.icon, size: 18, color: accent.g2),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Text(
                  item.label,
                  style: GoogleFonts.inter(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
              const Icon(Icons.chevron_right_rounded,
                  size: 20, color: AppColors.textTertiary),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Glass icon button (header) ──

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
        child: Icon(icon, color: AppColors.textSecondary, size: 22),
      ),
    );
  }
}
