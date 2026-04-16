/// TPIX TRADE — Wallet Connect Bottom Sheet
/// สร้างกระเป๋าใหม่ / Import / Backup mnemonic
///
/// Developed by Xman Studio

import 'dart:async';
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../common/glass_card.dart';
import '../common/gradient_button.dart';

class WalletConnectSheet extends StatefulWidget {
  const WalletConnectSheet({super.key});

  @override
  State<WalletConnectSheet> createState() => _WalletConnectSheetState();
}

class _WalletConnectSheetState extends State<WalletConnectSheet> {
  _SheetStep _step = _SheetStep.choose;
  final _mnemonicController = TextEditingController();
  Timer? _clipboardClearTimer;

  @override
  void dispose() {
    _mnemonicController.dispose();
    _clipboardClearTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();

    // Auto-switch to backup step when wallet has pending mnemonic
    if (wallet.pendingMnemonic != null && _step != _SheetStep.backup) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() => _step = _SheetStep.backup);
      });
    }

    // Auto-close when connected and no pending backup
    if (wallet.isConnected && wallet.pendingMnemonic == null && _step != _SheetStep.choose) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pop(context);
      });
    }

    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.85,
          ),
          decoration: const BoxDecoration(
            color: Color(0xF20A0E1A),
            borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
            border: Border(
              top: BorderSide(color: Color(0x1AFFFFFF)),
            ),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Handle bar
              Container(
                width: 40,
                height: 4,
                margin: const EdgeInsets.only(top: 12),
                decoration: BoxDecoration(
                  color: AppColors.textTertiary,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),

              // Content
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: _buildContent(locale, wallet),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildContent(LocaleProvider locale, WalletProvider wallet) {
    switch (_step) {
      case _SheetStep.choose:
        return _buildChooseStep(locale, wallet);
      case _SheetStep.import:
        return _buildImportStep(locale, wallet);
      case _SheetStep.backup:
        return _buildBackupStep(locale, wallet);
    }
  }

  // ── Step 1: Choose ──

  Widget _buildChooseStep(LocaleProvider locale, WalletProvider wallet) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Title
        Center(
          child: Text(
            locale.t('settings.connect_wallet'),
            style: GoogleFonts.inter(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
        ),
        const SizedBox(height: 24),

        // Create new wallet
        GlassCard(
          variant: GlassVariant.brand,
          borderRadius: 16,
          padding: const EdgeInsets.all(20),
          onTap: wallet.isConnecting ? null : () => wallet.createWallet(),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  gradient: AppGradients.brand,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Icon(Icons.add_rounded,
                    color: Colors.white, size: 24),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      locale.t('wallet.create'),
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    Text(
                      locale.isThai
                          ? 'สร้างกระเป๋าใหม่บน TPIX Chain'
                          : 'Create new wallet on TPIX Chain',
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              if (wallet.isConnecting)
                const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: AppColors.brandCyan,
                  ),
                )
              else
                const Icon(Icons.arrow_forward_ios_rounded,
                    color: AppColors.textTertiary, size: 16),
            ],
          ),
        ),

        const SizedBox(height: 12),

        // Import wallet
        GlassCard(
          variant: GlassVariant.standard,
          borderRadius: 16,
          padding: const EdgeInsets.all(20),
          onTap: wallet.isConnecting
              ? null
              : () => setState(() => _step = _SheetStep.import),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: AppColors.bgTertiary,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.bgCardBorder),
                ),
                child: const Icon(Icons.download_rounded,
                    color: AppColors.brandCyan, size: 24),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      locale.t('wallet.import'),
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    Text(
                      locale.isThai
                          ? 'นำเข้าด้วย 12 คำกู้คืน'
                          : 'Import with 12-word recovery phrase',
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.arrow_forward_ios_rounded,
                  color: AppColors.textTertiary, size: 16),
            ],
          ),
        ),

        const SizedBox(height: 12),

        // External Wallet (WalletConnect v2 — MetaMask, Trust, Rainbow, ...)
        GlassCard(
          variant: GlassVariant.standard,
          borderRadius: 16,
          padding: const EdgeInsets.all(20),
          onTap: wallet.isConnecting
              ? null
              : () => wallet.connectExternalWallet(context),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: AppColors.bgTertiary,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.bgCardBorder),
                ),
                child: const Icon(Icons.account_balance_wallet_outlined,
                    color: AppColors.brandCyan, size: 24),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      locale.isThai
                          ? 'เชื่อมกระเป๋าภายนอก'
                          : 'Connect External Wallet',
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    Text(
                      locale.isThai
                          ? 'MetaMask, Trust, Rainbow, OKX (100+)'
                          : 'MetaMask, Trust, Rainbow, OKX (100+)',
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.arrow_forward_ios_rounded,
                  color: AppColors.textTertiary, size: 16),
            ],
          ),
        ),

        // Error message
        if (wallet.error != null) ...[
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.tradingRedBg,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              children: [
                const Icon(Icons.error_outline_rounded,
                    color: AppColors.tradingRed, size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    wallet.error!,
                    style: GoogleFonts.inter(
                        fontSize: 13, color: AppColors.tradingRed),
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  // ── Step 2: Import ──

  Widget _buildImportStep(LocaleProvider locale, WalletProvider wallet) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Back + Title
        Row(
          children: [
            IconButton(
              onPressed: () => setState(() => _step = _SheetStep.choose),
              icon: const Icon(Icons.arrow_back_rounded,
                  color: AppColors.textSecondary),
            ),
            Expanded(
              child: Text(
                locale.t('wallet.import'),
                style: GoogleFonts.inter(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
          ],
        ),

        const SizedBox(height: 20),

        // Mnemonic input
        Container(
          decoration: BoxDecoration(
            color: AppColors.bgInput,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.bgCardBorder),
          ),
          child: TextField(
            controller: _mnemonicController,
            maxLines: 4,
            style: AppTheme.mono(fontSize: 14, color: AppColors.textPrimary),
            decoration: InputDecoration(
              hintText: locale.t('wallet.import_hint'),
              hintStyle: GoogleFonts.inter(
                  fontSize: 14, color: AppColors.textDisabled),
              border: InputBorder.none,
              contentPadding: const EdgeInsets.all(16),
            ),
          ),
        ),

        const SizedBox(height: 20),

        GradientButton(
          text: locale.t('wallet.import_button'),
          isLoading: wallet.isConnecting,
          onPressed: wallet.isConnecting
              ? null
              : () => wallet.importWallet(_mnemonicController.text),
        ),

        if (wallet.error != null) ...[
          const SizedBox(height: 12),
          Text(
            wallet.error!,
            style: GoogleFonts.inter(fontSize: 13, color: AppColors.tradingRed),
            textAlign: TextAlign.center,
          ),
        ],
      ],
    );
  }

  // ── Step 3: Backup ──

  Widget _buildBackupStep(LocaleProvider locale, WalletProvider wallet) {
    final words = wallet.pendingMnemonic?.split(' ') ?? [];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(
          child: Text(
            locale.t('wallet.backup'),
            style: GoogleFonts.inter(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
        ),
        const SizedBox(height: 8),

        // Warning
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AppColors.tradingYellow.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
                color: AppColors.tradingYellow.withValues(alpha: 0.2)),
          ),
          child: Row(
            children: [
              const Icon(Icons.warning_rounded,
                  color: AppColors.tradingYellow, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  locale.t('wallet.backup_warning'),
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    color: AppColors.tradingYellow,
                  ),
                ),
              ),
            ],
          ),
        ),

        const SizedBox(height: 20),

        // Mnemonic words grid
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.bgInput,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.bgCardBorder),
          ),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: List.generate(words.length, (i) {
              return Container(
                padding: const EdgeInsets.symmetric(
                    horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: AppColors.bgTertiary,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  '${i + 1}. ${words[i]}',
                  style: AppTheme.mono(
                    fontSize: 13,
                    color: AppColors.textPrimary,
                  ),
                ),
              );
            }),
          ),
        ),

        const SizedBox(height: 12),

        // Copy button
        TextButton.icon(
          onPressed: () {
            if (wallet.pendingMnemonic != null) {
              Clipboard.setData(
                  ClipboardData(text: wallet.pendingMnemonic!));
              // C1: Auto-clear clipboard หลัง 60 วินาที ป้องกัน mnemonic ค้าง
              _clipboardClearTimer?.cancel();
              _clipboardClearTimer = Timer(
                const Duration(seconds: 60),
                () => Clipboard.setData(const ClipboardData(text: '')),
              );
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(
                    '${locale.t('common.copied')} '
                    '(${locale.isThai ? 'จะลบอัตโนมัติใน 60 วินาที' : 'auto-clears in 60s'})',
                  ),
                  duration: const Duration(seconds: 2),
                ),
              );
            }
          },
          icon: const Icon(Icons.copy_rounded,
              color: AppColors.brandCyan, size: 16),
          label: Text(
            locale.isThai ? 'คัดลอก' : 'Copy',
            style: GoogleFonts.inter(
                fontSize: 13, color: AppColors.brandCyan),
          ),
        ),

        const SizedBox(height: 16),

        GradientButton(
          text: locale.t('wallet.confirm_backup'),
          onPressed: () {
            // Clear clipboard ทันทีเมื่อ confirm backup
            _clipboardClearTimer?.cancel();
            Clipboard.setData(const ClipboardData(text: ''));
            wallet.confirmMnemonicBackup();
            Navigator.pop(context);
          },
        ),
      ],
    );
  }
}

enum _SheetStep { choose, import, backup }
