/// TPIX TRADE — Profile Screen (Luxury Dark / Gilded Metal)
/// Detail screen pushed over the shell. Real backend profile (name/avatar/
/// referral/trades via WalletProvider), editable name/email + changeable
/// avatar, connect/disconnect, and a menu. No fabricated figures.
///
/// Developed by Xman Studio

import 'package:cached_network_image/cached_network_image.dart';
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
import '../../widgets/common/gradient_button.dart';
import '../../widgets/wallet/profile_edit_sheet.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  @override
  void initState() {
    super.initState();
    // Pull the latest backend profile when opening (screen is pushed fresh).
    final wallet = context.read<WalletProvider>();
    if (wallet.isConnected) wallet.loadProfile();
  }

  void _back() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/home');
    }
  }

  void _openConnect() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const WalletConnectSheet(),
    );
  }

  void _openEdit() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const ProfileEditSheet(),
    );
  }

  void _openAvatarPicker() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const AvatarPickerSheet(),
    );
  }

  Future<void> _disconnect(bool th) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: BorderSide(color: AppColors.tradingRed.withValues(alpha: 0.3)),
        ),
        title: Text(th ? 'ตัดการเชื่อมต่อ?' : 'Disconnect wallet?',
            style: GoogleFonts.inter(
                color: AppColors.textPrimary, fontSize: 16, fontWeight: FontWeight.w800)),
        content: Text(
          th
              ? 'จะออกจากระบบกระเป๋านี้ในแอป (กุญแจ/วลีกู้คืนของคุณไม่ถูกลบ)'
              : 'You will be signed out of this wallet in the app (your keys / recovery phrase are not deleted).',
          style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(th ? 'ยกเลิก' : 'Cancel',
                style: const TextStyle(color: AppColors.textTertiary)),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.tradingRed,
              foregroundColor: Colors.white,
            ),
            child: Text(th ? 'ตัดการเชื่อมต่อ' : 'Disconnect'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    await context.read<WalletProvider>().disconnect();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(th ? 'ตัดการเชื่อมต่อแล้ว' : 'Wallet disconnected'),
      duration: const Duration(seconds: 2),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    final th = locale.isThai;
    final connected = wallet.isConnected;

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(child: _buildHeader(th, connected)),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 8, 18, 4),
                  child: _ProfileCard(
                    wallet: wallet,
                    locale: locale,
                    onAvatarTap: connected ? _openAvatarPicker : null,
                  ),
                ),
              ),
              if (connected) ...[
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 14, 18, 6),
                    child: _StatRow(wallet: wallet, locale: locale),
                  ),
                ),
                SliverToBoxAdapter(child: _buildMenu(th)),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
                    child: GradientButton(
                      text: th ? 'ตัดการเชื่อมต่อกระเป๋า' : 'Disconnect Wallet',
                      variant: ButtonVariant.outline,
                      icon: Icons.logout_rounded,
                      onPressed: () => _disconnect(th),
                    ),
                  ),
                ),
              ] else
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
                    child: GradientButton(
                      text: th ? 'เชื่อมกระเป๋า' : 'Connect Wallet',
                      variant: ButtonVariant.gold,
                      icon: Icons.account_balance_wallet_rounded,
                      onPressed: _openConnect,
                    ),
                  ),
                ),
              const SliverToBoxAdapter(child: SizedBox(height: 40)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildHeader(bool th, bool connected) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(10, 10, 10, 4),
      child: Row(
        children: [
          _GlassIconButton(icon: Icons.chevron_left_rounded, onTap: _back),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              th ? 'โปรไฟล์' : 'Profile',
              style: GoogleFonts.inter(
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
                letterSpacing: -0.2,
              ),
            ),
          ),
          if (connected) ...[
            _GlassIconButton(icon: Icons.edit_outlined, onTap: _openEdit),
            const SizedBox(width: 6),
          ],
        ],
      ),
    );
  }

  Widget _buildMenu(bool th) {
    final items = <_MenuItem>[
      _MenuItem(
        icon: Icons.account_balance_wallet_outlined,
        label: th ? 'กระเป๋าที่เชื่อม' : 'Linked Wallets',
        onTap: _openConnect,
      ),
      _MenuItem(
        icon: Icons.shield_outlined,
        label: th ? 'บัญชีและความปลอดภัย' : 'Account & Security',
        onTap: () => context.push('/settings'),
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
  final VoidCallback? onAvatarTap;

  const _ProfileCard(
      {required this.wallet, required this.locale, required this.onAvatarTap});

  @override
  Widget build(BuildContext context) {
    final connected = wallet.isConnected;
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
          _GoldRingAvatar(avatarUrl: wallet.profileAvatar, onTap: onAvatarTap),
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
              locale.isThai ? 'ยังไม่ได้เชื่อมกระเป๋า' : 'No wallet connected',
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
  final String? avatarUrl;
  final VoidCallback? onTap;

  const _GoldRingAvatar({required this.avatarUrl, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final hasAvatar = avatarUrl != null && avatarUrl!.trim().isNotEmpty;

    Widget inner = DecoratedBox(
      decoration: const BoxDecoration(
        shape: BoxShape.circle,
        color: AppColors.bgGradBottom,
      ),
      child: ClipOval(
        child: hasAvatar
            ? CachedNetworkImage(
                imageUrl: avatarUrl!,
                fit: BoxFit.cover,
                width: 74,
                height: 74,
                errorWidget: (_, __, ___) => const Icon(
                    Icons.person_rounded, color: AppColors.gold1, size: 40),
                placeholder: (_, __) => const Icon(
                    Icons.person_rounded, color: AppColors.gold1, size: 40),
              )
            : const Icon(Icons.person_rounded, color: AppColors.gold1, size: 40),
      ),
    );

    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Stack(
        alignment: Alignment.bottomRight,
        children: [
          Container(
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
            child: inner,
          ),
          if (onTap != null)
            Container(
              width: 26,
              height: 26,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: accent.goldGradient,
                border: Border.all(color: AppColors.bgPrimary, width: 2),
              ),
              child: const Icon(Icons.photo_camera_rounded,
                  size: 13, color: AppColors.goldTextOn),
            ),
        ],
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
              style: AppTheme.mono(fontSize: 12.5, color: AppColors.gold1),
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
            decoration: BoxDecoration(shape: BoxShape.circle, color: dotColor),
          ),
          const SizedBox(width: 7),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w700,
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
    final p = wallet.profile;
    final assetCount = wallet.balances.length.toString();
    final trades = p != null ? '${p.totalTrades}' : '—';
    final network =
        wallet.activeChainId == 4289 ? 'TPIX' : wallet.activeChainId.toString();

    return Row(
      children: [
        Expanded(
          child: _StatTile(
            icon: Icons.swap_vert_rounded,
            value: trades,
            label: th ? 'เทรด' : 'Trades',
          ),
        ),
        const SizedBox(width: 10),
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
      ],
    );
  }
}

class _StatTile extends StatelessWidget {
  final IconData icon;
  final String value;
  final String label;

  const _StatTile(
      {required this.icon, required this.value, required this.label});

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
            style: AppTheme.mono(fontSize: 16, color: AppColors.textPrimary),
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
  const _MenuItem(
      {required this.icon, required this.label, required this.onTap});
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

// ── Avatar picker (preset generated avatars + custom URL) ──

class AvatarPickerSheet extends StatefulWidget {
  const AvatarPickerSheet({super.key});

  @override
  State<AvatarPickerSheet> createState() => _AvatarPickerSheetState();
}

class _AvatarPickerSheetState extends State<AvatarPickerSheet> {
  late final TextEditingController _urlCtrl;
  String? _selected;
  bool _saving = false;

  static String _gen(String style, String seed) =>
      'https://api.dicebear.com/9.x/$style/png?seed=${Uri.encodeComponent(seed)}&size=160';

  List<String> _presets(String seed) => [
        _gen('identicon', seed),
        _gen('bottts', seed),
        _gen('shapes', seed),
        _gen('rings', seed),
        _gen('glass', seed),
        _gen('thumbs', seed),
        _gen('pixel-art', seed),
        _gen('fun-emoji', seed),
      ];

  @override
  void initState() {
    super.initState();
    final cur = context.read<WalletProvider>().profileAvatar ?? '';
    _urlCtrl = TextEditingController(text: cur);
    _selected = cur.isEmpty ? null : cur;
  }

  @override
  void dispose() {
    _urlCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final value = (_selected ?? _urlCtrl.text).trim();
    if (value.isEmpty) {
      Navigator.pop(context);
      return;
    }
    setState(() => _saving = true);
    final ok = await context.read<WalletProvider>().updateProfile(avatar: value);
    if (!mounted) return;
    setState(() => _saving = false);
    final th = context.read<LocaleProvider>().isThai;
    if (ok) {
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(th ? 'เปลี่ยนรูปโปรไฟล์แล้ว' : 'Profile picture updated'),
        backgroundColor: AppColors.tradingGreen,
        duration: const Duration(seconds: 2),
      ));
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(th ? 'บันทึกไม่สำเร็จ' : 'Could not save'),
        duration: const Duration(seconds: 2),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final wallet = context.watch<WalletProvider>();
    final th = context.watch<LocaleProvider>().isThai;
    final seed = wallet.address ?? 'tpix-trader';
    final presets = _presets(seed);

    return Container(
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xF21A1C24), Color(0xF20E0F14)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: accent.goldBorder, width: 1.6)),
      ),
      padding: EdgeInsets.fromLTRB(
          20, 14, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 44,
                height: 4,
                decoration: BoxDecoration(
                  gradient: accent.goldGradient,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              th ? 'เปลี่ยนรูปโปรไฟล์' : 'Change picture',
              style: GoogleFonts.inter(
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 14),
            GridView.count(
              crossAxisCount: 4,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              children: [
                for (final url in presets)
                  _AvatarOption(
                    url: url,
                    selected: _selected == url,
                    accent: accent,
                    onTap: () => setState(() {
                      _selected = url;
                      _urlCtrl.text = url;
                    }),
                  ),
              ],
            ),
            const SizedBox(height: 16),
            Text(
              th ? 'หรือใส่ลิงก์รูปเอง' : 'Or paste an image URL',
              style: GoogleFonts.inter(
                  fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.textSecondary),
            ),
            const SizedBox(height: 8),
            Container(
              decoration: BoxDecoration(
                color: AppColors.bgInputStrong,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: accent.goldBorder, width: 1),
              ),
              child: TextField(
                controller: _urlCtrl,
                keyboardType: TextInputType.url,
                onChanged: (v) => setState(() => _selected = v.trim().isEmpty ? null : v.trim()),
                style: AppTheme.mono(fontSize: 13, color: AppColors.textPrimary),
                decoration: const InputDecoration(
                  hintText: 'https://…',
                  hintStyle: TextStyle(color: AppColors.textDisabled, fontSize: 13),
                  border: InputBorder.none,
                  contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 13),
                ),
              ),
            ),
            const SizedBox(height: 18),
            GradientButton(
              text: _saving
                  ? (th ? 'กำลังบันทึก…' : 'Saving…')
                  : (th ? 'บันทึก' : 'Save'),
              variant: ButtonVariant.gold,
              onPressed: _saving ? null : _save,
            ),
          ],
        ),
      ),
    );
  }
}

class _AvatarOption extends StatelessWidget {
  final String url;
  final bool selected;
  final AccentProvider accent;
  final VoidCallback onTap;

  const _AvatarOption({
    required this.url,
    required this.selected,
    required this.accent,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.all(2),
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: selected ? accent.goldGradient : null,
          color: selected ? null : AppColors.bgInputStrong,
          border: selected
              ? null
              : Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: ClipOval(
          child: CachedNetworkImage(
            imageUrl: url,
            fit: BoxFit.cover,
            placeholder: (_, __) => const ColoredBox(
              color: AppColors.bgTertiary,
              child: Icon(Icons.person_rounded,
                  color: AppColors.textTertiary, size: 22),
            ),
            errorWidget: (_, __, ___) => const ColoredBox(
              color: AppColors.bgTertiary,
              child: Icon(Icons.broken_image_rounded,
                  color: AppColors.textTertiary, size: 18),
            ),
          ),
        ),
      ),
    );
  }
}
