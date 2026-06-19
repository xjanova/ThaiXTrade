/// TPIX TRADE — Settings Screen (Luxury Dark / Gilded Metal)
/// Appearance (metal finish + ambient prefs), wallet management, chain
/// selector, preferences, about & update — on the gunmetal+gold backdrop.
/// All data is real (wallet + config + package info). The metal-finish
/// switcher re-skins every gold surface via AccentProvider.
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../providers/config_provider.dart';
import '../../providers/accent_provider.dart';
import '../../services/biometric_service.dart';
import '../../services/update_service.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/wallet/profile_edit_sheet.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

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
              // Header (pushed detail route → show a back chevron)
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(8, 12, 20, 8),
                  child: Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.arrow_back_ios_new_rounded,
                            color: AppColors.textPrimary, size: 18),
                        onPressed: () =>
                            context.canPop() ? context.pop() : context.go('/home'),
                      ),
                      Text(
                        locale.t('settings.title'),
                        style: GoogleFonts.inter(
                          fontSize: 24,
                          fontWeight: FontWeight.w800,
                          color: AppColors.textPrimary,
                          letterSpacing: -0.3,
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              // Wallet section
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
                  child: wallet.isConnected
                      ? _WalletCard(wallet: wallet, locale: locale)
                      : _ConnectWalletCard(locale: locale),
                ),
              ),

              // Profile section — แสดงเฉพาะตอน verified แล้ว (มี profile sync)
              if (wallet.isConnected && wallet.isVerified)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                    child: _ProfileCard(wallet: wallet, locale: locale),
                  ),
                ),

              // Appearance — Metal Finish switcher + ambient prefs
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                  child: _AppearanceCard(locale: locale),
                ),
              ),

              // Chain selector
              if (wallet.isConnected)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                    child: _ChainSelector(wallet: wallet, locale: locale),
                  ),
                ),

              // Preferences
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                  child: _PreferencesCard(locale: locale, wallet: wallet),
                ),
              ),

              // About & Update
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 110),
                  child: _AboutCard(locale: locale),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Section header (icon tile + label) ──

class _SectionHeader extends StatelessWidget {
  final IconData icon;
  final String title;

  const _SectionHeader({required this.icon, required this.title});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 30,
          height: 30,
          decoration: BoxDecoration(
            color: AppColors.goldTint,
            borderRadius: BorderRadius.circular(9),
          ),
          child: Icon(icon, color: AppColors.gold2, size: 16),
        ),
        const SizedBox(width: 10),
        Text(
          title,
          style: GoogleFonts.inter(
            fontSize: 14.5,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
            letterSpacing: -0.2,
          ),
        ),
      ],
    );
  }
}

// ── Appearance card (Metal Finish + ambient prefs) ──

class _AppearanceCard extends StatelessWidget {
  final LocaleProvider locale;

  const _AppearanceCard({required this.locale});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionHeader(
            icon: Icons.palette_rounded,
            title: th ? 'พื้นผิวโลหะ' : 'Metal Finish',
          ),
          const SizedBox(height: 14),

          // Swatches — one per metal tone
          Row(
            children: [
              for (final entry in kMetalPalettes.entries) ...[
                Expanded(
                  child: _MetalSwatch(
                    tone: entry.key,
                    palette: entry.value,
                    selected: accent.tone == entry.key,
                    isThai: th,
                    onTap: () =>
                        context.read<AccentProvider>().setTone(entry.key),
                  ),
                ),
                if (entry.key != kMetalPalettes.keys.last)
                  const SizedBox(width: 10),
              ],
            ],
          ),

          const SizedBox(height: 18),

          // Gold Fireflies toggle
          _ToggleRow(
            icon: Icons.auto_awesome_rounded,
            title: th ? 'หิ่งห้อยทอง' : 'Gold Fireflies',
            subtitle: th
                ? 'อนุภาคทองลอยพื้นหลัง'
                : 'Ambient gold particles in the background',
            value: accent.showFireflies,
            onChanged: (v) =>
                context.read<AccentProvider>().setShowFireflies(v),
            accent: accent,
          ),

          const Divider(color: AppColors.divider, height: 22),

          // Reduce Motion toggle
          _ToggleRow(
            icon: Icons.motion_photos_off_rounded,
            title: th ? 'ลดการเคลื่อนไหว' : 'Reduce Motion',
            subtitle: th
                ? 'หยุดอนิเมชันที่เล่นวนซ้ำ'
                : 'Stop looping animations',
            value: accent.reduceMotion,
            onChanged: (v) =>
                context.read<AccentProvider>().setReduceMotion(v),
            accent: accent,
          ),
        ],
      ),
    );
  }
}

class _MetalSwatch extends StatelessWidget {
  final MetalTone tone;
  final MetalPalette palette;
  final bool selected;
  final bool isThai;
  final VoidCallback onTap;

  const _MetalSwatch({
    required this.tone,
    required this.palette,
    required this.selected,
    required this.isThai,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Column(
        children: [
          AspectRatio(
            aspectRatio: 1.55,
            child: Container(
              decoration: BoxDecoration(
                gradient: palette.gradient,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: selected
                      ? AppColors.white
                      : AppColors.white.withValues(alpha: 0.12),
                  width: selected ? 2.5 : 1,
                ),
                boxShadow: selected
                    ? [
                        BoxShadow(
                          color: palette.glow.withValues(alpha: 0.5),
                          blurRadius: 16,
                          spreadRadius: -4,
                        ),
                      ]
                    : null,
              ),
              child: selected
                  ? const Align(
                      alignment: Alignment.topRight,
                      child: Padding(
                        padding: EdgeInsets.all(5),
                        child: _CheckBadge(),
                      ),
                    )
                  : null,
            ),
          ),
          const SizedBox(height: 7),
          Text(
            isThai ? palette.labelTh : palette.label,
            textAlign: TextAlign.center,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              fontSize: 10.5,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              color: selected
                  ? AppColors.textPrimary
                  : AppColors.textSecondary,
              letterSpacing: -0.1,
            ),
          ),
        ],
      ),
    );
  }
}

class _CheckBadge extends StatelessWidget {
  const _CheckBadge();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 18,
      height: 18,
      decoration: const BoxDecoration(
        shape: BoxShape.circle,
        color: AppColors.goldTextOn, // dark chip on the bright gold swatch
      ),
      child: const Icon(Icons.check_rounded,
          color: AppColors.gold1, size: 13),
    );
  }
}

class _ToggleRow extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;
  final AccentProvider accent;

  const _ToggleRow({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.onChanged,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: AppColors.goldTint,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(icon, color: AppColors.gold2, size: 18),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: GoogleFonts.inter(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                subtitle,
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  color: AppColors.textTertiary,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 8),
        Switch(
          value: value,
          activeThumbColor: AppColors.goldTextOn,
          activeTrackColor: accent.g2,
          inactiveThumbColor: AppColors.textSecondary,
          inactiveTrackColor: AppColors.bgTertiary,
          onChanged: onChanged,
        ),
      ],
    );
  }
}

// ── Connected wallet card ──

class _WalletCard extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _WalletCard({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.brand,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  gradient: AppGradients.gold,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.account_balance_wallet_rounded,
                    color: AppColors.goldTextOn, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            _walletDisplayName(wallet),
                            style: GoogleFonts.inter(
                              fontSize: 15,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary,
                            ),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        if (wallet.isLinkedWallet) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: AppColors.goldTint,
                              borderRadius: BorderRadius.circular(4),
                              border: Border.all(
                                color: AppColors.goldBorder,
                                width: 0.5,
                              ),
                            ),
                            child: Text(
                              locale.isThai ? 'ลิงก์' : 'LINKED',
                              style: GoogleFonts.inter(
                                fontSize: 9,
                                fontWeight: FontWeight.w700,
                                color: AppColors.gold2,
                                letterSpacing: 0.5,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    Text(
                      wallet.shortAddress,
                      style: AppTheme.mono(
                        fontSize: 12,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
              // Verified / pending — trading green/red is the correct semantic
              // signal here (status, not a generic accent).
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: wallet.isVerified
                      ? AppColors.tradingGreenBg
                      : AppColors.tradingRedBg,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  wallet.isVerified
                      ? locale.t('wallet.verified')
                      : locale.t('wallet.pending'),
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                    color: wallet.isVerified
                        ? AppColors.tradingGreen
                        : AppColors.tradingRed,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            child: GradientButton(
              text: locale.t('settings.disconnect'),
              variant: ButtonVariant.outline,
              height: 40,
              onPressed: () => _showDisconnectDialog(context),
            ),
          ),
        ],
      ),
    );
  }

  /// แสดงชื่อ wallet ตาม kind:
  /// - linked: 'TPIX Wallet' (หรือชื่อจาก deep link param)
  /// - walletConnect: 'MetaMask' / 'Trust' / etc.
  /// - embedded: 'TPIX Wallet' (in-app default)
  String _walletDisplayName(WalletProvider w) {
    final ext = w.externalWalletName;
    if (ext != null && ext.isNotEmpty) return ext;
    return 'TPIX Wallet';
  }

  void _showDisconnectDialog(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: AppColors.bgCardBorder),
        ),
        title: Text(
          locale.t('settings.disconnect'),
          style: const TextStyle(color: AppColors.textPrimary),
        ),
        content: Text(
          locale.isThai
              ? 'คุณต้องการยกเลิกการเชื่อมต่อกระเป๋าหรือไม่?'
              : 'Are you sure you want to disconnect your wallet?',
          style: const TextStyle(color: AppColors.textSecondary),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(locale.t('common.cancel'),
                style: const TextStyle(color: AppColors.textTertiary)),
          ),
          TextButton(
            onPressed: () async {
              await wallet.disconnect();
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: Text(locale.t('common.confirm'),
                style: const TextStyle(color: AppColors.tradingRed)),
          ),
        ],
      ),
    );
  }
}

// ── Profile card (sync ↔ backend) ──

class _ProfileCard extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _ProfileCard({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    final profile = wallet.profile;
    final name = profile?.name;
    final email = profile?.email;
    final hasName = name != null && name.isNotEmpty;
    final hasEmail = email != null && email.isNotEmpty;

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      onTap: () => _openEditSheet(context),
      child: Row(
        children: [
          // Avatar / placeholder
          _ProfileAvatar(
            avatarUrl: profile?.avatar,
            fallbackChar: hasName ? name[0] : 'T',
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  hasName ? name : locale.t('profile.guest'),
                  style: GoogleFonts.inter(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  hasEmail ? email : locale.t('profile.set_email'),
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    color: hasEmail
                        ? AppColors.textSecondary
                        : AppColors.gold2,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
          // Edit chevron
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: AppColors.goldTint,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.edit_rounded,
                color: AppColors.gold2, size: 16),
          ),
        ],
      ),
    );
  }

  void _openEditSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true, // กัน Android nav bar บัง content
      backgroundColor: Colors.transparent,
      builder: (_) => const ProfileEditSheet(),
    );
  }
}

class _ProfileAvatar extends StatelessWidget {
  final String? avatarUrl;
  final String fallbackChar;

  const _ProfileAvatar({this.avatarUrl, required this.fallbackChar});

  @override
  Widget build(BuildContext context) {
    if (avatarUrl != null && avatarUrl!.isNotEmpty) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(14),
        child: Image.network(
          avatarUrl!,
          width: 44,
          height: 44,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => _placeholder(),
          loadingBuilder: (_, child, progress) =>
              progress == null ? child : _placeholder(),
        ),
      );
    }
    return _placeholder();
  }

  Widget _placeholder() {
    return Container(
      width: 44,
      height: 44,
      decoration: BoxDecoration(
        gradient: AppGradients.gold,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Center(
        child: Text(
          fallbackChar.toUpperCase(),
          style: GoogleFonts.inter(
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: AppColors.goldTextOn,
          ),
        ),
      ),
    );
  }
}

// ── Connect wallet card ──

class _ConnectWalletCard extends StatelessWidget {
  final LocaleProvider locale;

  const _ConnectWalletCard({required this.locale});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 18,
      padding: const EdgeInsets.all(20),
      child: Column(
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              gradient: AppGradients.gold,
              borderRadius: BorderRadius.circular(16),
            ),
            child: const Icon(Icons.account_balance_wallet_rounded,
                color: AppColors.goldTextOn, size: 28),
          ),
          const SizedBox(height: 14),
          Text(
            locale.t('settings.connect_wallet'),
            style: GoogleFonts.inter(
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 16),
          GradientButton(
            text: locale.t('settings.connect_wallet'),
            icon: Icons.account_balance_wallet_rounded,
            onPressed: () => _showConnectSheet(context),
          ),
        ],
      ),
    );
  }

  void _showConnectSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true, // กัน Android nav bar บัง wallet picker
      backgroundColor: Colors.transparent,
      builder: (_) => const WalletConnectSheet(),
    );
  }
}

// ── Chain selector ──

class _ChainSelector extends StatelessWidget {
  final WalletProvider wallet;
  final LocaleProvider locale;

  const _ChainSelector({required this.wallet, required this.locale});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 14,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionHeader(
            icon: Icons.hub_rounded,
            title: locale.t('settings.chain'),
          ),
          const SizedBox(height: 12),
          // Dynamic chain list จาก /api/v1/chains (10 chains) + fallback static
          Consumer<ConfigProvider>(
            builder: (_, config, __) => Wrap(
              spacing: 8,
              runSpacing: 8,
              children: config.displayChains.map((chain) {
                final isActive = chain.chainId == wallet.activeChainId;
                final color = chain.config?.color ?? AppColors.textTertiary;
                return GestureDetector(
                  onTap: chain.supported
                      ? () {
                          wallet.switchChain(chain.chainId);
                          // Refetch chain-specific fees ทันที
                          config.setActiveChain(chain.chainId);
                        }
                      : () {
                          // Chain ยัง not supported ใน mobile
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(locale.isThai
                                  ? 'กำลังเปิดบน mobile เร็วๆ นี้: ${chain.name}'
                                  : 'Coming soon on mobile: ${chain.name}'),
                              duration: const Duration(seconds: 2),
                            ),
                          );
                        },
                  child: Opacity(
                    opacity: chain.supported ? 1.0 : 0.5,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 8),
                      decoration: BoxDecoration(
                        color: isActive
                            ? color.withValues(alpha: 0.15)
                            : AppColors.bgTertiary,
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(
                          color: isActive
                              ? color.withValues(alpha: 0.4)
                              : AppColors.bgCardBorder,
                        ),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 8,
                            height: 8,
                            decoration: BoxDecoration(
                              color: color,
                              shape: BoxShape.circle,
                            ),
                          ),
                          const SizedBox(width: 6),
                          Text(
                            chain.shortName,
                            style: GoogleFonts.inter(
                              fontSize: 12,
                              fontWeight: isActive
                                  ? FontWeight.w600
                                  : FontWeight.w400,
                              color: isActive
                                  ? color
                                  : AppColors.textSecondary,
                            ),
                          ),
                          if (!chain.supported) ...[
                            const SizedBox(width: 4),
                            const Icon(Icons.access_time_rounded,
                                size: 10, color: AppColors.textTertiary),
                          ],
                        ],
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Preferences ──

class _PreferencesCard extends StatelessWidget {
  final LocaleProvider locale;
  final WalletProvider wallet;

  const _PreferencesCard({required this.locale, required this.wallet});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 14,
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(
        children: [
          // Language
          _SettingsTile(
            icon: Icons.translate_rounded,
            title: locale.t('settings.language'),
            trailing: Text(
              locale.isThai ? 'ไทย' : 'English',
              style: GoogleFonts.inter(fontSize: 13, color: AppColors.gold2),
            ),
            onTap: () => locale.toggle(),
          ),

          const Divider(color: AppColors.divider, height: 1, indent: 52),

          // Currency
          _SettingsTile(
            icon: Icons.attach_money_rounded,
            title: locale.t('settings.currency'),
            trailing: Text(
              wallet.currency,
              style: GoogleFonts.inter(fontSize: 13, color: AppColors.gold2),
            ),
            onTap: () {
              final newCurrency = wallet.currency == 'USD' ? 'THB' : 'USD';
              wallet.updateSettings(currency: newCurrency);
            },
          ),

          const Divider(color: AppColors.divider, height: 1, indent: 52),

          // Biometric — S8: ต้อง authenticate ก่อนปิด
          _SettingsTile(
            icon: Icons.fingerprint_rounded,
            title: locale.t('settings.biometric'),
            trailing: Switch(
              value: wallet.biometricEnabled,
              activeThumbColor: AppColors.goldTextOn,
              activeTrackColor: accent.g2,
              inactiveThumbColor: AppColors.textSecondary,
              inactiveTrackColor: AppColors.bgTertiary,
              onChanged: (v) async {
                if (!v && wallet.biometricEnabled) {
                  // ปิด biometric → ต้อง verify ก่อน
                  final ok = await BiometricService().authenticate(
                    locale.isThai
                        ? 'ยืนยันเพื่อปิดการใช้ลายนิ้วมือ'
                        : 'Verify to disable biometric',
                  );
                  if (!ok) return;
                }
                wallet.updateSettings(biometricEnabled: v);
              },
            ),
          ),

          const Divider(color: AppColors.divider, height: 1, indent: 52),

          // Notifications
          _SettingsTile(
            icon: Icons.notifications_outlined,
            title: locale.t('settings.notifications'),
            trailing: Switch(
              value: wallet.pushNotifications,
              activeThumbColor: AppColors.goldTextOn,
              activeTrackColor: accent.g2,
              inactiveThumbColor: AppColors.textSecondary,
              inactiveTrackColor: AppColors.bgTertiary,
              onChanged: (v) => wallet.updateSettings(pushNotifications: v),
            ),
          ),
        ],
      ),
    );
  }
}

// ── About & Update ──

class _AboutCard extends StatelessWidget {
  final LocaleProvider locale;
  // M7: Cache Future เพื่อไม่ให้ FutureBuilder สร้างใหม่ทุก build
  static final _packageInfoFuture = PackageInfo.fromPlatform();

  const _AboutCard({required this.locale});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 14,
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(
        children: [
          // Bridge
          _SettingsTile(
            icon: Icons.swap_horiz_rounded,
            title: locale.t('bridge.title'),
            trailing: const Icon(Icons.chevron_right_rounded,
                color: AppColors.textTertiary, size: 20),
            onTap: () => context.push('/bridge'),
          ),

          const Divider(color: AppColors.divider, height: 1, indent: 52),

          _SettingsTile(
            icon: Icons.system_update_rounded,
            title: locale.t('settings.check_update'),
            trailing: const Icon(Icons.chevron_right_rounded,
                color: AppColors.textTertiary, size: 20),
            onTap: () => _checkUpdate(context),
          ),

          const Divider(color: AppColors.divider, height: 1, indent: 52),

          _SettingsTile(
            icon: Icons.info_outline_rounded,
            title: locale.t('settings.about'),
            trailing: FutureBuilder<PackageInfo>(
              future: _packageInfoFuture,
              builder: (_, snap) => Text(
                snap.hasData ? 'v${snap.data!.version}' : '...',
                style: GoogleFonts.inter(
                    fontSize: 12, color: AppColors.textTertiary),
              ),
            ),
          ),

          const Divider(color: AppColors.divider, height: 1, indent: 52),

          _SettingsTile(
            icon: Icons.code_rounded,
            title: 'Xman Studio',
            trailing: Text(
              'xmanstudio.com',
              style: GoogleFonts.inter(fontSize: 12, color: AppColors.gold2),
            ),
          ),
        ],
      ),
    );
  }

  void _checkUpdate(BuildContext context) async {
    final service = UpdateService();

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(locale.t('update.checking')),
        duration: const Duration(seconds: 1),
      ),
    );

    final result = await service.checkForUpdate();
    if (!context.mounted) return;

    if (result.available) {
      _showUpdateDialog(context, result, service);
    } else if (result.pendingApkBuild) {
      // มี tag ใหม่บน GitHub แต่ APK ยังไม่ build/attach — แจ้งผู้ใช้
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            locale.isThai
                ? 'เวอร์ชั่น ${result.latestVersion} กำลัง build — ลองใหม่ภายหลัง'
                : 'v${result.latestVersion} is being built — try again shortly',
          ),
          duration: const Duration(seconds: 4),
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(locale.t('update.latest')),
          duration: const Duration(seconds: 2),
        ),
      );
    }
  }

  void _showUpdateDialog(
      BuildContext context, UpdateResult result, UpdateService service) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _UpdateDialog(result: result, service: service),
    );
  }
}

// ── Settings tile ──

class _SettingsTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final Widget? trailing;
  final VoidCallback? onTap;

  const _SettingsTile({
    required this.icon,
    required this.title,
    this.trailing,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Row(
          children: [
            Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(
                color: AppColors.goldTint,
                borderRadius: BorderRadius.circular(9),
              ),
              child: Icon(icon, color: AppColors.gold2, size: 17),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                style: GoogleFonts.inter(
                  fontSize: 14,
                  fontWeight: FontWeight.w500,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
            if (trailing != null) trailing!,
          ],
        ),
      ),
    );
  }
}

// ── Update dialog ──

class _UpdateDialog extends StatefulWidget {
  final UpdateResult result;
  final UpdateService service;

  const _UpdateDialog({required this.result, required this.service});

  @override
  State<_UpdateDialog> createState() => _UpdateDialogState();
}

class _UpdateDialogState extends State<_UpdateDialog> {
  bool _downloading = false;
  double _progress = 0;
  String _statusText = '';

  @override
  Widget build(BuildContext context) {
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
              color: AppColors.goldTint,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.system_update_rounded,
                color: AppColors.gold2, size: 20),
          ),
          const SizedBox(width: 10),
          const Expanded(
            child: Text('Update Available',
                style: TextStyle(color: AppColors.textPrimary, fontSize: 16)),
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
              constraints: const BoxConstraints(maxHeight: 80),
              child: SingleChildScrollView(
                child: Text(
                  widget.result.releaseNotes!,
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.textTertiary),
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
            Text(_statusText,
                style: GoogleFonts.inter(
                    fontSize: 11, color: AppColors.textTertiary)),
          ],
        ],
      ),
      actions: [
        if (!_downloading) ...[
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Later',
                style: TextStyle(color: AppColors.textTertiary)),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.download_rounded,
                color: AppColors.goldTextOn, size: 16),
            label: const Text('Download',
                style: TextStyle(color: AppColors.goldTextOn)),
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
      _statusText = 'Downloading...';
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
            _statusText = '$mb / $totalMb MB';
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
