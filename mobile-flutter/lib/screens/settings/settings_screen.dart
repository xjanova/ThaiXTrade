/// TPIX TRADE — Settings Screen
/// Wallet management, chain selector, preferences, update
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
import '../../models/chain_config.dart';
import '../../services/biometric_service.dart';
import '../../services/update_service.dart';
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
      body: Container(
        decoration: const BoxDecoration(gradient: AppGradients.darkBg),
        child: SafeArea(
          bottom: false,
          child: CustomScrollView(
            slivers: [
              // Header
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                  child: Text(
                    locale.t('settings.title'),
                    style: GoogleFonts.inter(
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
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
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 100),
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
                  gradient: AppGradients.brand,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.account_balance_wallet_rounded,
                    color: Colors.white, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'TPIX Wallet',
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textPrimary,
                      ),
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
                  wallet.isVerified ? locale.t('wallet.verified') : locale.t('wallet.pending'),
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
                    fontWeight: FontWeight.w600,
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
                        : AppColors.brandCyan,
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
              color: AppColors.brandCyan.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.edit_rounded,
                color: AppColors.brandCyan, size: 16),
          ),
        ],
      ),
    );
  }

  void _openEditSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
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
        gradient: AppGradients.brand,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Center(
        child: Text(
          fallbackChar.toUpperCase(),
          style: GoogleFonts.inter(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: Colors.white,
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
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.all(20),
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
          const SizedBox(height: 14),
          Text(
            locale.t('settings.connect_wallet'),
            style: GoogleFonts.inter(
              fontSize: 16,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 16),
          GradientButton(
            text: locale.t('settings.connect_wallet'),
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
          Row(
            children: [
              const Icon(Icons.language_rounded,
                  color: AppColors.brandCyan, size: 18),
              const SizedBox(width: 8),
              Text(
                locale.t('settings.chain'),
                style: GoogleFonts.inter(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
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
                          fontWeight:
                              isActive ? FontWeight.w600 : FontWeight.w400,
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
              style: GoogleFonts.inter(
                  fontSize: 13, color: AppColors.brandCyan),
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
              style: GoogleFonts.inter(
                  fontSize: 13, color: AppColors.brandCyan),
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
              activeThumbColor: AppColors.brandCyan,
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
              activeThumbColor: AppColors.brandCyan,
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
              style: GoogleFonts.inter(
                  fontSize: 12, color: AppColors.brandCyan),
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
            Icon(icon, color: AppColors.brandCyan, size: 20),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                style: GoogleFonts.inter(
                  fontSize: 14,
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
              color: AppColors.brandCyan.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.system_update_rounded,
                color: AppColors.brandCyan, size: 20),
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
            style: AppTheme.mono(fontSize: 14, color: AppColors.brandCyan),
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
                valueColor:
                    const AlwaysStoppedAnimation(AppColors.brandCyan),
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
                color: Colors.white, size: 16),
            label: const Text('Download',
                style: TextStyle(color: Colors.white)),
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
