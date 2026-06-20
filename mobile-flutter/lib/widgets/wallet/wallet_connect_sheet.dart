/// TPIX TRADE — Wallet Connect Bottom Sheet (Luxury Dark / Gilded Metal)
/// Gilded "scanner viewport" + provider chips + manual recovery-phrase import,
/// on the gunmetal sheet backdrop. All connect logic is real (Reown AppKit /
/// WalletConnect, in-app create/import, mnemonic backup). No live camera scanner
/// exists in this app, so the viewport renders the TPIX emblem with an animated
/// gold scan line (static when reduce-motion is on) rather than a fake camera.
///
/// Developed by Xman Studio

import 'dart:async';
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../utils/peer_app.dart';
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
  bool _showPhrase = false;
  bool _connectingTpix = false;

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
    final accent = context.watch<AccentProvider>();

    // Auto-switch to backup step when wallet has pending mnemonic
    if (wallet.pendingMnemonic != null && _step != _SheetStep.backup) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() => _step = _SheetStep.backup);
      });
    }

    // Auto-close when connected and no pending backup
    if (wallet.isConnected &&
        wallet.pendingMnemonic == null &&
        _step != _SheetStep.choose) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pop(context);
      });
    }

    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.9,
          ),
          decoration: BoxDecoration(
            // Gunmetal sheet fill + faint top gold halo
            gradient: const LinearGradient(
              colors: [Color(0xF21A1C24), Color(0xF20E0F14)],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
            borderRadius:
                const BorderRadius.vertical(top: Radius.circular(24)),
            border: Border(
              top: BorderSide(color: accent.goldBorder, width: kGoldEdgeWidth),
            ),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Gold handle bar
              Container(
                width: 44,
                height: 4,
                margin: const EdgeInsets.only(top: 12),
                decoration: BoxDecoration(
                  gradient: accent.goldGradient,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),

              // Content
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(22, 18, 22, 26),
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

  // ── Step 1: Connect (scanner viewport + providers + manual import) ──

  Widget _buildChooseStep(LocaleProvider locale, WalletProvider wallet) {
    final th = locale.isThai;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Title
        Center(
          child: ShaderMask(
            shaderCallback: (b) =>
                context.read<AccentProvider>().goldGradient.createShader(b),
            child: Text(
              locale.t('settings.connect_wallet'),
              style: GoogleFonts.inter(
                fontSize: 20,
                fontWeight: FontWeight.w800,
                color: Colors.white,
                letterSpacing: 0.2,
              ),
            ),
          ),
        ),
        const SizedBox(height: 4),
        Center(
          child: Text(
            th
                ? 'เชื่อม TPIX Wallet (แนะนำ) หรือใช้กระเป๋าอื่น'
                : 'Connect TPIX Wallet (recommended), or use another wallet',
            style: GoogleFonts.inter(fontSize: 12, color: AppColors.textTertiary),
            textAlign: TextAlign.center,
          ),
        ),
        const SizedBox(height: 18),

        // ── PRIMARY: Connect TPIX Wallet (one-tap link to the TPIX Wallet app) ──
        _TpixWalletTile(
          locale: locale,
          connecting: _connectingTpix,
          onTap: _connectingTpix ? null : _connectTpixWallet,
        ),

        const SizedBox(height: 18),

        // Scanner viewport — TPIX Wallet can scan this to connect.
        Center(child: _ScannerViewport(locale: locale)),
        const SizedBox(height: 8),
        Center(
          child: Text(
            th
                ? 'หรือเปิด TPIX Wallet แล้วสแกนรหัสนี้'
                : 'Or open TPIX Wallet and scan this code',
            style: GoogleFonts.inter(fontSize: 11, color: AppColors.textTertiary),
            textAlign: TextAlign.center,
          ),
        ),

        const SizedBox(height: 22),

        // ── Other wallets (Reown AppKit / WalletConnect) ──
        _SectionLabel(text: th ? 'กระเป๋าอื่น' : 'Other wallets'),
        const SizedBox(height: 10),
        Row(
          children: [
            _ProviderChip(
              label: 'MetaMask',
              color: const Color(0xFFE2761B),
              icon: Icons.account_balance_wallet_rounded,
              onTap: wallet.isConnecting
                  ? null
                  : () => wallet.connectExternalWallet(context),
            ),
            const SizedBox(width: 10),
            _ProviderChip(
              label: 'Trust',
              color: const Color(0xFF3375BB),
              icon: Icons.shield_rounded,
              onTap: wallet.isConnecting
                  ? null
                  : () => wallet.connectExternalWallet(context),
            ),
            const SizedBox(width: 10),
            _ProviderChip(
              label: 'Coinbase',
              color: const Color(0xFF0052FF),
              icon: Icons.circle_outlined,
              onTap: wallet.isConnecting
                  ? null
                  : () => wallet.connectExternalWallet(context),
            ),
            const SizedBox(width: 10),
            _ProviderChip(
              label: 'WalletConnect',
              color: const Color(0xFF3B99FC),
              icon: Icons.qr_code_rounded,
              onTap: wallet.isConnecting
                  ? null
                  : () => wallet.connectExternalWallet(context),
            ),
          ],
        ),

        const SizedBox(height: 18),

        // Divider — "or import recovery phrase"
        _OrDivider(
          text: th ? 'หรือนำเข้าวลีกู้คืน' : 'or import recovery phrase',
        ),

        const SizedBox(height: 16),

        // Recovery-phrase import card
        _ManualImportCard(
          locale: locale,
          controller: _mnemonicController,
          showPhrase: _showPhrase,
          onToggleShow: () => setState(() => _showPhrase = !_showPhrase),
          onPaste: _pasteFromClipboard,
        ),

        // Error message
        if (wallet.error != null) ...[
          const SizedBox(height: 14),
          _ErrorBanner(message: wallet.error!),
        ],

        const SizedBox(height: 18),

        // Import CTA
        GradientButton(
          text: th ? 'นำเข้าวลีกู้คืน' : 'Import Recovery Phrase',
          variant: ButtonVariant.gold,
          icon: Icons.lock_rounded,
          isLoading: wallet.isConnecting,
          onPressed: wallet.isConnecting ? null : _onConnectSecurely,
        ),

        const SizedBox(height: 12),

        // Lock footnote
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.lock_outline_rounded,
                size: 13, color: AppColors.textTertiary),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                th
                    ? 'กุญแจถูกเข้ารหัสในเครื่อง ไม่เคยส่งออกจากอุปกรณ์'
                    : 'Keys are encrypted on-device, never transmitted',
                style: GoogleFonts.inter(
                  fontSize: 10.5,
                  color: AppColors.textTertiary,
                ),
                textAlign: TextAlign.center,
              ),
            ),
          ],
        ),
      ],
    );
  }

  /// Connect via the TPIX Wallet app: open it (one-tap link) if installed,
  /// otherwise route to the install page. The Wallet calls back
  /// `tpixtrade://connect?address=...` which DeepLinkService completes.
  Future<void> _connectTpixWallet() async {
    setState(() => _connectingTpix = true);
    final installed = await PeerApp.isWalletInstalled(forceRefresh: true);
    if (!mounted) return;
    final th = context.read<LocaleProvider>().isThai;
    if (installed) {
      final from = context.read<WalletProvider>().address;
      final opened = await PeerApp.openWallet(
        path: 'connect',
        params: from != null ? {'from': from} : null,
      );
      if (!mounted) return;
      setState(() => _connectingTpix = false);
      if (opened) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(th
              ? 'ยืนยันการเชื่อมต่อใน TPIX Wallet แล้วระบบจะเชื่อมให้อัตโนมัติ'
              : 'Approve the connection in TPIX Wallet — you’ll be linked automatically'),
          duration: const Duration(seconds: 3),
        ));
      }
    } else {
      setState(() => _connectingTpix = false);
      await _promptInstallTpixWallet(th);
    }
  }

  Future<void> _promptInstallTpixWallet(bool th) async {
    final go = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: AppColors.bgCardBorder),
        ),
        title: Text(th ? 'ยังไม่ได้ติดตั้ง TPIX Wallet' : 'TPIX Wallet not installed',
            style: GoogleFonts.inter(
                color: AppColors.textPrimary, fontSize: 16, fontWeight: FontWeight.w800)),
        content: Text(
          th
              ? 'ติดตั้ง TPIX Wallet เพื่อเชื่อมต่อแบบแตะครั้งเดียว หรือใช้กระเป๋าอื่น/นำเข้าวลีกู้คืนด้านล่าง'
              : 'Install TPIX Wallet for one-tap connect, or use another wallet / import a recovery phrase below.',
          style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(th ? 'ภายหลัง' : 'Later',
                style: const TextStyle(color: AppColors.textTertiary)),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.gold2,
              foregroundColor: AppColors.goldTextOn,
            ),
            child: Text(th ? 'ติดตั้ง' : 'Install'),
          ),
        ],
      ),
    );
    if (go == true) await PeerApp.openWalletInstallPage();
  }

  /// Primary CTA: if the field already holds a phrase, import it directly;
  /// otherwise route to the full-screen manual import step.
  void _onConnectSecurely() {
    final wallet = context.read<WalletProvider>();
    final text = _mnemonicController.text.trim();
    if (text.isNotEmpty) {
      wallet.importWallet(text);
    } else {
      setState(() => _step = _SheetStep.import);
    }
  }

  Future<void> _pasteFromClipboard() async {
    final data = await Clipboard.getData(Clipboard.kTextPlain);
    if (!mounted) return;
    final text = data?.text?.trim();
    if (text != null && text.isNotEmpty) {
      setState(() {
        _mnemonicController.text = text;
        _showPhrase = false;
      });
    }
  }

  // ── Step 2: Import (full recovery-phrase entry) ──

  Widget _buildImportStep(LocaleProvider locale, WalletProvider wallet) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Back + Title
        Row(
          children: [
            _BackButton(
              onTap: () => setState(() => _step = _SheetStep.choose),
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(
                locale.t('wallet.import'),
                style: GoogleFonts.inter(
                  fontSize: 20,
                  fontWeight: FontWeight.w800,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
          ],
        ),

        const SizedBox(height: 20),

        // Mnemonic input
        _GoldFieldShell(
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
          variant: ButtonVariant.gold,
          icon: Icons.download_rounded,
          isLoading: wallet.isConnecting,
          onPressed: wallet.isConnecting
              ? null
              : () => wallet.importWallet(_mnemonicController.text),
        ),

        if (wallet.error != null) ...[
          const SizedBox(height: 14),
          _ErrorBanner(message: wallet.error!),
        ],
      ],
    );
  }

  // ── Step 3: Backup ──

  Widget _buildBackupStep(LocaleProvider locale, WalletProvider wallet) {
    final words = wallet.pendingMnemonic?.split(' ') ?? [];
    final accent = context.read<AccentProvider>();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(
          child: Text(
            locale.t('wallet.backup'),
            style: GoogleFonts.inter(
              fontSize: 20,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
            ),
          ),
        ),
        const SizedBox(height: 12),

        // Warning (gold-toned)
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: accent.goldTint,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: accent.goldBorder, width: 1),
          ),
          child: Row(
            children: [
              Icon(Icons.warning_amber_rounded, color: accent.g2, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  locale.t('wallet.backup_warning'),
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            ],
          ),
        ),

        const SizedBox(height: 18),

        // Mnemonic words grid
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            gradient: AppGradients.cardSubtle,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.bgCardBorder, width: 1),
          ),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: List.generate(words.length, (i) {
              return Container(
                padding: const EdgeInsets.symmetric(
                    horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: AppColors.bgInputStrong,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppColors.bgCardBorder, width: 1),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      '${i + 1}',
                      style: AppTheme.mono(
                        fontSize: 11,
                        color: accent.g2,
                      ),
                    ),
                    const SizedBox(width: 6),
                    Text(
                      words[i],
                      style: AppTheme.mono(
                        fontSize: 13,
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ],
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
          icon: Icon(Icons.copy_rounded, color: accent.g2, size: 16),
          label: Text(
            locale.isThai ? 'คัดลอก' : 'Copy',
            style: GoogleFonts.inter(fontSize: 13, color: accent.g2),
          ),
        ),

        const SizedBox(height: 14),

        GradientButton(
          text: locale.t('wallet.confirm_backup'),
          variant: ButtonVariant.gold,
          icon: Icons.check_circle_rounded,
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

// ── Scanner viewport (gilded, animated scan line) ──

class _ScannerViewport extends StatefulWidget {
  final LocaleProvider locale;
  const _ScannerViewport({required this.locale});

  @override
  State<_ScannerViewport> createState() => _ScannerViewportState();
}

class _ScannerViewportState extends State<_ScannerViewport>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2200),
    );
  }

  void _syncMotion(bool reduceMotion) {
    if (reduceMotion) {
      if (_controller.isAnimating) _controller.stop();
    } else {
      if (!_controller.isAnimating) _controller.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    _syncMotion(accent.reduceMotion);

    // Reown does not expose a raw WalletConnect URI here, so render the TPIX
    // emblem (the spec's fallback) rather than a fabricated QR / live camera.
    const double size = 196;

    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        alignment: Alignment.center,
        children: [
          // Radial-dark backing
          Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(20),
              gradient: const RadialGradient(
                center: Alignment.center,
                radius: 0.9,
                colors: [Color(0xFF15171F), Color(0xFF090A0E)],
              ),
              border: Border.all(color: accent.goldBorder, width: kGoldEdgeWidth),
            ),
          ),

          // Functional link-request QR (TPIX Wallet scans this to connect)
          const _EmblemPlaceholder(),

          // 4 gold corner brackets
          ..._corners(accent),

          // Animated gold scan line (static when reduce-motion)
          if (accent.reduceMotion)
            const _ScanLine(t: 0.5)
          else
            AnimatedBuilder(
              animation: _controller,
              builder: (context, _) => _ScanLine(t: _controller.value),
            ),
        ],
      ),
    );
  }

  List<Widget> _corners(AccentProvider accent) {
    const double pad = 10;
    return [
      Positioned(top: pad, left: pad, child: _Corner(accent: accent, corner: _CornerPos.tl)),
      Positioned(top: pad, right: pad, child: _Corner(accent: accent, corner: _CornerPos.tr)),
      Positioned(bottom: pad, left: pad, child: _Corner(accent: accent, corner: _CornerPos.bl)),
      Positioned(bottom: pad, right: pad, child: _Corner(accent: accent, corner: _CornerPos.br)),
    ];
  }
}

/// Functional link-request QR rendered inside the viewport.
class _EmblemPlaceholder extends StatelessWidget {
  const _EmblemPlaceholder();

  @override
  Widget build(BuildContext context) {
    // Functional link-request QR: the TPIX Wallet app scans this and calls back
    // `tpixtrade://connect?address=...`, which DeepLinkService completes.
    return Container(
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: AppColors.textPrimary,
        borderRadius: BorderRadius.circular(10),
      ),
      child: QrImageView(
        data: 'tpixtrade://connect',
        version: QrVersions.auto,
        size: 108,
        eyeStyle: const QrEyeStyle(
          eyeShape: QrEyeShape.square,
          color: AppColors.bgPrimary,
        ),
        dataModuleStyle: const QrDataModuleStyle(
          dataModuleShape: QrDataModuleShape.square,
          color: AppColors.bgPrimary,
        ),
      ),
    );
  }
}

enum _CornerPos { tl, tr, bl, br }

class _Corner extends StatelessWidget {
  final AccentProvider accent;
  final _CornerPos corner;
  const _Corner({required this.accent, required this.corner});

  @override
  Widget build(BuildContext context) {
    const double len = 26;
    const double w = 3;
    final c = accent.g2;
    final top = corner == _CornerPos.tl || corner == _CornerPos.tr;
    final left = corner == _CornerPos.tl || corner == _CornerPos.bl;

    BorderSide side() => BorderSide(color: c, width: w);
    return SizedBox(
      width: len,
      height: len,
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border(
            top: top ? side() : BorderSide.none,
            bottom: !top ? side() : BorderSide.none,
            left: left ? side() : BorderSide.none,
            right: !left ? side() : BorderSide.none,
          ),
          borderRadius: BorderRadius.only(
            topLeft: corner == _CornerPos.tl
                ? const Radius.circular(8)
                : Radius.zero,
            topRight: corner == _CornerPos.tr
                ? const Radius.circular(8)
                : Radius.zero,
            bottomLeft: corner == _CornerPos.bl
                ? const Radius.circular(8)
                : Radius.zero,
            bottomRight: corner == _CornerPos.br
                ? const Radius.circular(8)
                : Radius.zero,
          ),
        ),
      ),
    );
  }
}

class _ScanLine extends StatelessWidget {
  final double t; // 0..1
  const _ScanLine({required this.t});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    const double inset = 16;
    const double travel = 196 - inset * 2;
    return Positioned(
      left: inset,
      right: inset,
      top: inset + travel * t,
      child: Container(
        height: 2.5,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(2),
          gradient: LinearGradient(
            colors: [
              accent.g2.withValues(alpha: 0.0),
              accent.g1,
              accent.g2.withValues(alpha: 0.0),
            ],
          ),
          boxShadow: [
            BoxShadow(
              color: accent.goldGlow.withValues(alpha: 0.6),
              blurRadius: 10,
              spreadRadius: 1,
            ),
          ],
        ),
      ),
    );
  }
}

// ── Provider chip ──

class _ProviderChip extends StatelessWidget {
  final String label;
  final Color color;
  final IconData icon;
  final VoidCallback? onTap;

  const _ProviderChip({
    required this.label,
    required this.color,
    required this.icon,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final disabled = onTap == null;
    return Expanded(
      child: Opacity(
        opacity: disabled ? 0.5 : 1,
        child: GestureDetector(
          onTap: onTap,
          behavior: HitTestBehavior.opaque,
          child: Column(
            children: [
              Container(
                height: 48,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(14),
                  color: color.withValues(alpha: 0.16),
                  border: Border.all(
                      color: color.withValues(alpha: 0.5), width: 1.2),
                ),
                child: Icon(icon, color: color, size: 22),
              ),
              const SizedBox(height: 6),
              Text(
                label,
                style: GoogleFonts.inter(
                  fontSize: 9.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Primary: Connect TPIX Wallet tile ──

class _TpixWalletTile extends StatelessWidget {
  final LocaleProvider locale;
  final bool connecting;
  final VoidCallback? onTap;

  const _TpixWalletTile({
    required this.locale,
    required this.connecting,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 18,
      padding: const EdgeInsets.all(16),
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            padding: const EdgeInsets.all(2),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: accent.goldGradient,
              boxShadow: [
                BoxShadow(
                  color: accent.goldGlow.withValues(alpha: 0.4),
                  blurRadius: 16,
                  spreadRadius: -4,
                ),
              ],
            ),
            child: const DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.bgGradBottom,
              ),
              child: Padding(
                padding: EdgeInsets.all(7),
                child: Image(
                  image: AssetImage('assets/images/logo.webp'),
                  fit: BoxFit.contain,
                ),
              ),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(
                      th ? 'เชื่อม TPIX Wallet' : 'Connect TPIX Wallet',
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: accent.goldTint,
                        border: Border.all(color: accent.goldBorder, width: 1),
                      ),
                      child: Text(
                        th ? 'แนะนำ' : 'BEST',
                        style: GoogleFonts.inter(
                          fontSize: 8.5,
                          fontWeight: FontWeight.w800,
                          color: accent.g1,
                          letterSpacing: 0.6,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  th ? 'แตะครั้งเดียว ปลอดภัย เชื่อมผ่านแอป' : 'One-tap secure link via the app',
                  style: GoogleFonts.inter(
                    fontSize: 11.5,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
          if (connecting)
            SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2, color: accent.g2),
            )
          else
            Icon(Icons.arrow_forward_ios_rounded, color: accent.g2, size: 15),
        ],
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  final String text;
  const _SectionLabel({required this.text});

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: GoogleFonts.inter(
        fontSize: 10,
        fontWeight: FontWeight.w700,
        color: AppColors.textTertiary,
        letterSpacing: 1.2,
      ),
    );
  }
}

// ── "or import manually" divider ──

class _OrDivider extends StatelessWidget {
  final String text;
  const _OrDivider({required this.text});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Row(
      children: [
        Expanded(
          child: Container(
            height: 1,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  AppColors.divider,
                  accent.goldBorder,
                ],
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Text(
            text,
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 0.4,
            ),
          ),
        ),
        Expanded(
          child: Container(
            height: 1,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  accent.goldBorder,
                  AppColors.divider,
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

// ── Manual recovery-phrase (private key) card ──

class _ManualImportCard extends StatelessWidget {
  final LocaleProvider locale;
  final TextEditingController controller;
  final bool showPhrase;
  final VoidCallback onToggleShow;
  final Future<void> Function() onPaste;

  const _ManualImportCard({
    required this.locale,
    required this.controller,
    required this.showPhrase,
    required this.onToggleShow,
    required this.onPaste,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Label row + eye toggle + paste chip
        Row(
          children: [
            Icon(Icons.key_rounded, size: 14, color: accent.g2),
            const SizedBox(width: 6),
            Text(
              th ? 'วลีกู้คืน (Recovery Phrase)' : 'Recovery Phrase',
              style: GoogleFonts.inter(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            const Spacer(),
            GestureDetector(
              onTap: onToggleShow,
              behavior: HitTestBehavior.opaque,
              child: Icon(
                showPhrase
                    ? Icons.visibility_off_rounded
                    : Icons.visibility_rounded,
                size: 17,
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(width: 12),
            _PasteChip(label: th ? 'วาง' : 'Paste', onTap: onPaste),
          ],
        ),
        const SizedBox(height: 10),

        // Masked field
        _GoldFieldShell(
          child: TextField(
            controller: controller,
            obscureText: !showPhrase,
            maxLines: showPhrase ? 3 : 1,
            style: AppTheme.mono(fontSize: 13.5, color: AppColors.textPrimary),
            decoration: InputDecoration(
              hintText: th
                  ? 'พิมพ์ 12 คำที่คั่นด้วยช่องว่าง'
                  : 'Enter your 12-word phrase, space-separated',
              hintStyle: GoogleFonts.inter(
                  fontSize: 12.5, color: AppColors.textDisabled),
              border: InputBorder.none,
              contentPadding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
            ),
          ),
        ),
        const SizedBox(height: 10),

        // Warning row
        Row(
          children: [
            Icon(Icons.shield_outlined, size: 13, color: accent.g2),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                th
                    ? 'อย่าแชร์วลีกู้คืนกับใคร — ใครก็ตามที่มีวลีนี้ ควบคุมกระเป๋าได้'
                    : 'Never share your phrase — anyone with it controls your wallet',
                style: GoogleFonts.inter(
                  fontSize: 10.5,
                  color: AppColors.textTertiary,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _PasteChip extends StatelessWidget {
  final String label;
  final Future<void> Function() onTap;
  const _PasteChip({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: () => onTap(),
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: accent.goldTint,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: accent.goldBorder, width: 1),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.content_paste_rounded, size: 12, color: accent.g1),
            const SizedBox(width: 4),
            Text(
              label,
              style: GoogleFonts.inter(
                fontSize: 10.5,
                fontWeight: FontWeight.w700,
                color: accent.g1,
                letterSpacing: 0.3,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Shared field shell (dark fill + gold hairline) ──

class _GoldFieldShell extends StatelessWidget {
  final Widget child;
  const _GoldFieldShell({required this.child});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: child,
    );
  }
}

// ── Error banner ──

class _ErrorBanner extends StatelessWidget {
  final String message;
  const _ErrorBanner({required this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.tradingRedBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
            color: AppColors.tradingRed.withValues(alpha: 0.3), width: 1),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline_rounded,
              color: AppColors.tradingRed, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: GoogleFonts.inter(
                  fontSize: 13, color: AppColors.tradingRed),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Back button ──

class _BackButton extends StatelessWidget {
  final VoidCallback onTap;
  const _BackButton({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: 38,
        height: 38,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: AppColors.bgCard,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: const Icon(Icons.arrow_back_rounded,
            color: AppColors.textSecondary, size: 19),
      ),
    );
  }
}
