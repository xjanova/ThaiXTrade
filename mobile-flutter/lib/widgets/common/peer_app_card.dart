/// TPIX TRADE — Peer App Card
/// แสดงการ์ด "เปิด TPIX Wallet" ถ้าติดตั้งในเครื่อง (หรือ "ติดตั้ง" ถ้ายังไม่มี)
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

class _PeerAppCardState extends State<PeerAppCard> {
  Future<bool>? _installedFuture;

  @override
  void initState() {
    super.initState();
    _installedFuture = PeerApp.isWalletInstalled();
  }

  void _refresh() {
    PeerApp.clearCache();
    setState(() {
      _installedFuture = PeerApp.isWalletInstalled(forceRefresh: true);
    });
  }

  Future<void> _open() async {
    final wallet = context.read<WalletProvider>();
    final params = <String, String>{};
    if (wallet.address != null) {
      params['from'] = wallet.address!;
    }
    await PeerApp.openWallet(params: params.isEmpty ? null : params);
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();

    return FutureBuilder<bool>(
      future: _installedFuture,
      builder: (_, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const SizedBox.shrink();
        }

        final installed = snap.data == true;
        final title = installed
            ? locale.t('peer.open_wallet')
            : locale.t('peer.install_wallet');
        final desc = installed
            ? locale.t('peer.wallet_desc')
            : locale.t('peer.install_wallet_desc');

        return GlassCard(
          variant: GlassVariant.standard,
          borderRadius: 14,
          padding: const EdgeInsets.all(12),
          onTap: () {
            if (installed) {
              _open();
            } else {
              PeerApp.openWalletInstallPage();
            }
          },
          child: Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  gradient: AppGradients.brand,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(
                  installed
                      ? Icons.account_balance_wallet_rounded
                      : Icons.download_rounded,
                  color: Colors.white,
                  size: 18,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: GoogleFonts.inter(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    Text(
                      desc,
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                installed
                    ? Icons.arrow_forward_rounded
                    : Icons.open_in_new_rounded,
                color: AppColors.brandCyan,
                size: 18,
              ),
              const SizedBox(width: 4),
            ],
          ),
        );
      },
    );
  }
}
