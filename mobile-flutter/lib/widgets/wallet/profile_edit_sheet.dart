/// TPIX TRADE — Profile Edit Bottom Sheet
/// แก้ไข name, email, avatar — sync ไป backend ผ่าน WalletProvider
///
/// Developed by Xman Studio

import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/wallet_provider.dart';
import '../common/gradient_button.dart';

class ProfileEditSheet extends StatefulWidget {
  const ProfileEditSheet({super.key});

  @override
  State<ProfileEditSheet> createState() => _ProfileEditSheetState();
}

class _ProfileEditSheetState extends State<ProfileEditSheet> {
  late final TextEditingController _nameCtrl;
  late final TextEditingController _emailCtrl;
  late final TextEditingController _avatarCtrl;

  bool _saving = false;
  String? _localError;

  static final _emailRegex = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void initState() {
    super.initState();
    final p = context.read<WalletProvider>().profile;
    _nameCtrl = TextEditingController(text: p?.name ?? '');
    _emailCtrl = TextEditingController(text: p?.email ?? '');
    _avatarCtrl = TextEditingController(text: p?.avatar ?? '');
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    _avatarCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final locale = context.read<LocaleProvider>();
    final wallet = context.read<WalletProvider>();

    final name = _nameCtrl.text.trim();
    final email = _emailCtrl.text.trim();
    final avatar = _avatarCtrl.text.trim();

    // Validation
    if (name.length > 50) {
      setState(() => _localError = locale.t('profile.name_too_long'));
      return;
    }
    if (email.isNotEmpty && !_emailRegex.hasMatch(email)) {
      setState(() => _localError = locale.t('profile.invalid_email'));
      return;
    }

    setState(() {
      _saving = true;
      _localError = null;
    });

    final ok = await wallet.updateProfile(
      // ส่งเฉพาะที่กรอก — empty = ไม่แก้
      name: name.isEmpty ? null : name,
      email: email.isEmpty ? null : email,
      avatar: avatar.isEmpty ? null : avatar,
    );

    if (!mounted) return;
    setState(() => _saving = false);

    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(locale.t('profile.update_success')),
          backgroundColor: AppColors.tradingGreen,
          duration: const Duration(seconds: 2),
        ),
      );
      Navigator.pop(context);
    } else {
      setState(() {
        _localError = wallet.error ?? locale.t('profile.update_failed');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    final p = wallet.profile;

    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          decoration: BoxDecoration(
            color: AppColors.bgElevated.withValues(alpha: 0.95),
            border: Border(
              top: BorderSide(
                color: AppColors.brandCyan.withValues(alpha: 0.2),
              ),
            ),
          ),
          padding: EdgeInsets.only(
            left: 20,
            right: 20,
            top: 16,
            bottom: 20 + MediaQuery.of(context).viewInsets.bottom,
          ),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Drag handle
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: AppColors.textTertiary.withValues(alpha: 0.3),
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                // Title
                Text(
                  locale.t('profile.title'),
                  style: GoogleFonts.inter(
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 16),

                // Wallet address (read-only) — ระบุ user ใน backend
                _ReadOnlyRow(
                  label: locale.t('settings.wallet'),
                  value: wallet.shortAddress,
                  copyValue: wallet.address,
                  monospace: true,
                ),
                const SizedBox(height: 12),

                // Referral code (read-only)
                if (p?.referralCode != null) ...[
                  _ReadOnlyRow(
                    label: locale.t('profile.referral_code'),
                    value: p!.referralCode!,
                    copyValue: p.referralCode,
                    monospace: true,
                  ),
                  const SizedBox(height: 16),
                ],

                // Name
                _FieldLabel(text: locale.t('profile.name')),
                const SizedBox(height: 6),
                _TextField(
                  controller: _nameCtrl,
                  hintText: locale.t('profile.set_name'),
                  maxLength: 50,
                ),
                const SizedBox(height: 14),

                // Email
                _FieldLabel(text: locale.t('profile.email')),
                const SizedBox(height: 6),
                _TextField(
                  controller: _emailCtrl,
                  hintText: 'name@example.com',
                  keyboardType: TextInputType.emailAddress,
                ),
                const SizedBox(height: 14),

                // Avatar URL
                _FieldLabel(text: locale.t('profile.avatar')),
                const SizedBox(height: 6),
                _TextField(
                  controller: _avatarCtrl,
                  hintText: 'https://...',
                  keyboardType: TextInputType.url,
                ),
                const SizedBox(height: 18),

                // Stats row (read-only)
                if (p != null)
                  Row(
                    children: [
                      Expanded(
                        child: _StatChip(
                          label: locale.t('profile.total_trades'),
                          value: '${p.totalTrades}',
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: _StatChip(
                          label: locale.t('profile.kyc_status'),
                          value: p.kycStatus.toUpperCase(),
                        ),
                      ),
                    ],
                  ),

                if (_localError != null) ...[
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppColors.tradingRedBg,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.error_outline,
                            color: AppColors.tradingRed, size: 16),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            _localError!,
                            style: GoogleFonts.inter(
                              fontSize: 12,
                              color: AppColors.tradingRed,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],

                const SizedBox(height: 20),

                // Save button
                SizedBox(
                  width: double.infinity,
                  child: GradientButton(
                    text: _saving
                        ? locale.t('common.loading')
                        : locale.t('common.save'),
                    onPressed: _saving ? null : _save,
                  ),
                ),
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: TextButton(
                    onPressed: _saving ? null : () => Navigator.pop(context),
                    child: Text(
                      locale.t('common.cancel'),
                      style: GoogleFonts.inter(
                        color: AppColors.textTertiary,
                      ),
                    ),
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

// ── Helpers ──

class _FieldLabel extends StatelessWidget {
  final String text;
  const _FieldLabel({required this.text});

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: GoogleFonts.inter(
        fontSize: 12,
        fontWeight: FontWeight.w600,
        color: AppColors.textSecondary,
      ),
    );
  }
}

class _TextField extends StatelessWidget {
  final TextEditingController controller;
  final String? hintText;
  final TextInputType? keyboardType;
  final int? maxLength;

  const _TextField({
    required this.controller,
    this.hintText,
    this.keyboardType,
    this.maxLength,
  });

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      keyboardType: keyboardType,
      maxLength: maxLength,
      style: GoogleFonts.inter(
        fontSize: 14,
        color: AppColors.textPrimary,
      ),
      decoration: InputDecoration(
        hintText: hintText,
        hintStyle: GoogleFonts.inter(
          color: AppColors.textDisabled,
          fontSize: 14,
        ),
        filled: true,
        fillColor: AppColors.bgTertiary,
        counterText: '',
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide(
            color: AppColors.bgCardBorder.withValues(alpha: 0.5),
          ),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.brandCyan, width: 1.5),
        ),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      ),
    );
  }
}

class _ReadOnlyRow extends StatelessWidget {
  final String label;
  final String value;
  final String? copyValue;
  final bool monospace;

  const _ReadOnlyRow({
    required this.label,
    required this.value,
    this.copyValue,
    this.monospace = false,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.read<LocaleProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bgTertiary.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Text(
            '$label  ',
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textTertiary,
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: monospace
                  ? AppTheme.mono(fontSize: 13)
                  : GoogleFonts.inter(
                      fontSize: 13, color: AppColors.textPrimary),
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (copyValue != null && copyValue!.isNotEmpty)
            GestureDetector(
              onTap: () {
                Clipboard.setData(ClipboardData(text: copyValue!));
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(locale.t('common.copied')),
                    duration: const Duration(seconds: 1),
                  ),
                );
              },
              child: const Padding(
                padding: EdgeInsets.symmetric(horizontal: 4),
                child: Icon(Icons.copy_rounded,
                    size: 14, color: AppColors.brandCyan),
              ),
            ),
        ],
      ),
    );
  }
}

class _StatChip extends StatelessWidget {
  final String label;
  final String value;

  const _StatChip({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.bgTertiary.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 10,
              color: AppColors.textTertiary,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            style: AppTheme.mono(fontSize: 13, color: AppColors.brandCyan),
          ),
        ],
      ),
    );
  }
}
