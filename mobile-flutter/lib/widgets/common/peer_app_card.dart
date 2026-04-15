/// TPIX TRADE — Peer App Card
/// แสดงการ์ด "เปิด TPIX Wallet" ถ้าติดตั้งในเครื่อง
/// หรือการ์ด "ติดตั้ง TPIX Wallet" (เด่นกว่า) ถ้ายังไม่ติดตั้ง
/// Re-check อัตโนมัติเมื่อ user กลับเข้าแอพ (หลังไปติดตั้ง)
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../utils/peer_app.dart';
import 'glass_card.dart';

class PeerAppCard extends StatefulWidget {
  const PeerAppCard({super.key});

  @override
  State<PeerAppCard> createState() => _PeerAppCardState();
}

class _PeerAppCardState extends State<PeerAppCard>
    with WidgetsBindingObserver {
  bool? _installed;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _check();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // User กลับเข้าแอพ (หลังไปเปิด Play Store/เบราว์เซอร์) → re-check
    if (state == AppLifecycleState.resumed) {
      _check(forceRefresh: true);
    }
  }

  Future<void> _check({bool forceRefresh = false}) async {
    if (forceRefresh) PeerApp.clearCache();
    final installed = await PeerApp.isWalletInstalled(forceRefresh: forceRefresh);
    if (mounted) setState(() => _installed = installed);
  }

  Future<void> _open() async {
    final wallet = context.read<WalletProvider>();
    final params = <String, String>{};
    if (wallet.address != null) params['from'] = wallet.address!;
    await PeerApp.openWallet(params: params.isEmpty ? null : params);
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();

    // ยังไม่รู้ผล — ไม่แสดงอะไร (กัน flicker)
    if (_installed == null) return const SizedBox.shrink();

    return _installed!
        ? _buildInstalledCard(locale)
        : _buildInstallPromptCard(locale);
  }

  /// การ์ดตอนติดตั้งแล้ว — compact + subtle
  Widget _buildInstalledCard(LocaleProvider locale) {
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 14,
      padding: const EdgeInsets.all(12),
      onTap: _open,
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              gradient: AppGradients.brand,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.account_balance_wallet_rounded,
                color: Colors.white, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  locale.t('peer.open_wallet'),
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                Text(
                  locale.t('peer.wallet_desc'),
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
          const Icon(Icons.arrow_forward_rounded,
              color: AppColors.brandCyan, size: 18),
          const SizedBox(width: 4),
        ],
      ),
    );
  }

  /// การ์ดตอนยังไม่ติดตั้ง — prominent + clear CTA
  Widget _buildInstallPromptCard(LocaleProvider locale) {
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            AppColors.brandCyan.withValues(alpha: 0.12),
            AppColors.brandPurple.withValues(alpha: 0.12),
          ],
        ),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: AppColors.brandCyan.withValues(alpha: 0.4),
          width: 1.2,
        ),
        boxShadow: [
          BoxShadow(
            color: AppColors.brandCyan.withValues(alpha: 0.15),
            blurRadius: 16,
            spreadRadius: 1,
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: PeerApp.openWalletInstallPage,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    gradient: AppGradients.brand,
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: [
                      BoxShadow(
                        color: AppColors.brandCyan.withValues(alpha: 0.4),
                        blurRadius: 12,
                        spreadRadius: 1,
                      ),
                    ],
                  ),
                  child: const Icon(Icons.account_balance_wallet_rounded,
                      color: Colors.white, size: 24),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        locale.t('peer.install_wallet'),
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        locale.t('peer.install_wallet_desc'),
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    gradient: AppGradients.brand,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.download_rounded,
                          color: Colors.white, size: 14),
                      const SizedBox(width: 4),
                      Text(
                        locale.t('common.download'),
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
