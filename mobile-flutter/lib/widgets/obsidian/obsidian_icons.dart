/// TPIX TRADE — ไอคอน 3D ของธีม Obsidian
///
/// ชุดภาพ: ไอคอนโครเมียมขอบนีออน cyan→violet→orange
///   - ใช้ร่วมกับ TPIX Wallet (home, swap, wallet, bridge, settings, language …)
///   - ชุดเทรดที่เจนเพิ่มด้วยต้นแบบสไตล์เดียวกัน (aibot, candles, orderbook,
///     portfolio, markets, kyc, profile, rocket, advisor)
///
/// จุดเรียกใช้เดิมส่ง [IconData] ของ Material อยู่แล้ว → [ObsidianIcons.forIcon]
/// แปลงเป็นภาพ 3D ให้ ถ้าไม่มีคู่ก็ใช้ไอคอนเดิม (ไม่มีจุดไหนกลายเป็นช่องว่าง)
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';

import '../../core/theme/app_colors.dart';

abstract final class ObsidianIcons {
  static const _dir = 'assets/images/obsidian';

  static const home = '$_dir/ic_home.webp';
  static const ai = '$_dir/ic_aibot.webp';
  static const chart = '$_dir/ic_chart.webp';
  static const candles = '$_dir/ic_candles.webp';
  static const swap = '$_dir/ic_swap.webp';
  static const wallet = '$_dir/ic_wallet.webp';
  static const markets = '$_dir/ic_markets.webp';
  static const orderbook = '$_dir/ic_orderbook.webp';
  static const portfolio = '$_dir/ic_portfolio.webp';
  static const kyc = '$_dir/ic_kyc.webp';
  static const profile = '$_dir/ic_profile.webp';
  static const rocket = '$_dir/ic_rocket.webp';
  static const advisor = '$_dir/ic_advisor.webp';
  static const network = '$_dir/ic_network.webp';
  static const language = '$_dir/ic_language.webp';
  static const theme = '$_dir/ic_theme.webp';
  static const fingerprint = '$_dir/ic_fingerprint.webp';
  static const shield = '$_dir/ic_shield.webp';
  static const bell = '$_dir/ic_bell.webp';
  static const info = '$_dir/ic_info.webp';
  static const link = '$_dir/ic_link.webp';
  static const update = '$_dir/ic_update.webp';
  static const bug = '$_dir/ic_bug.webp';
  static const lock = '$_dir/ic_lock.webp';
  static const coins = '$_dir/ic_coins.webp';
  static const activity = '$_dir/ic_activity.webp';
  static const bridge = '$_dir/ic_bridge.webp';
  static const settings = '$_dir/ic_settings.webp';
  static const node = '$_dir/ic_node.webp';
  static const qr = '$_dir/ic_qr.webp';
  static const copy = '$_dir/ic_copy.webp';
  static const share = '$_dir/ic_share.webp';
  static const send = '$_dir/ic_send.webp';
  static const receive = '$_dir/ic_receive.webp';

  /// Material → 3D (เทียบด้วย codePoint เพราะ rounded/outlined คนละตัวแต่ความหมายเดียวกัน)
  static final Map<int, String> _byIcon = {
    for (final i in [
      Icons.account_balance_wallet_rounded,
      Icons.account_balance_wallet_outlined,
      Icons.account_balance_wallet,
    ])
      i.codePoint: wallet,
    // auto_awesome ไม่แปลง — ใช้ทั้งหัว AI TRADE และสวิตช์ประกายแสง ความหมายต่างกัน
    for (final i in [Icons.smart_toy_rounded, Icons.smart_toy_outlined]) i.codePoint: ai,
    for (final i in [Icons.candlestick_chart_rounded, Icons.candlestick_chart_outlined])
      i.codePoint: candles,
    // trending_up/down ไม่แปลง — ใช้เป็นตัวบอกทิศราคา (เขียว/แดง) ต้องคงสีไว้
    for (final i in [Icons.show_chart_rounded, Icons.insights_rounded, Icons.public_rounded])
      i.codePoint: markets,
    for (final i in [Icons.pie_chart_rounded, Icons.pie_chart_outline_rounded, Icons.donut_large_rounded])
      i.codePoint: portfolio,
    for (final i in [Icons.badge_rounded, Icons.badge_outlined, Icons.how_to_reg_rounded])
      i.codePoint: kyc,
    for (final i in [Icons.person_rounded, Icons.person_outline_rounded, Icons.account_circle_rounded])
      i.codePoint: profile,
    Icons.rocket_launch_rounded.codePoint: rocket,
    for (final i in [Icons.lightbulb_outline_rounded, Icons.lightbulb_rounded, Icons.tips_and_updates_rounded])
      i.codePoint: advisor,
    for (final i in [Icons.swap_horiz_rounded, Icons.swap_vert_rounded, Icons.sync_alt_rounded])
      i.codePoint: swap,
    for (final i in [Icons.translate_rounded, Icons.language_rounded]) i.codePoint: language,
    for (final i in [Icons.palette_rounded, Icons.style_rounded]) i.codePoint: theme,
    Icons.fingerprint_rounded.codePoint: fingerprint,
    for (final i in [
      Icons.verified_user_rounded,
      Icons.verified_user_outlined,
      Icons.security_rounded,
      Icons.shield_rounded,
    ])
      i.codePoint: shield,
    for (final i in [
      Icons.notifications_outlined,
      Icons.notifications_active_rounded,
      Icons.notifications_rounded,
    ])
      i.codePoint: bell,
    for (final i in [Icons.info_outline_rounded, Icons.info_rounded]) i.codePoint: info,
    Icons.link_rounded.codePoint: link,
    for (final i in [Icons.system_update_rounded, Icons.update_rounded]) i.codePoint: update,
    for (final i in [Icons.bug_report_outlined, Icons.bug_report_rounded]) i.codePoint: bug,
    for (final i in [Icons.lock_outline_rounded, Icons.lock_rounded, Icons.lock_clock_rounded])
      i.codePoint: lock,
    for (final i in [Icons.attach_money_rounded, Icons.paid_rounded, Icons.toll_rounded])
      i.codePoint: coins,
    Icons.history_rounded.codePoint: activity,
    for (final i in [Icons.settings_rounded, Icons.settings_outlined]) i.codePoint: settings,
    Icons.home_rounded.codePoint: home,
    Icons.hub_rounded.codePoint: bridge,
    Icons.lan_rounded.codePoint: network,
    Icons.dns_rounded.codePoint: node,
    Icons.qr_code_rounded.codePoint: qr,
  };

  /// ภาพ 3D ของ [icon] ในธีมปัจจุบัน — null = ไม่ใช่ Obsidian หรือไม่มีคู่
  static String? forIcon(IconData icon) =>
      AppColors.palette.obsidian ? _byIcon[icon.codePoint] : null;
}

/// ไอคอน 3D หนึ่งตัว (ภาพโปร่งใส) — หรี่ลงได้สำหรับสถานะไม่เลือก
class Icon3D extends StatelessWidget {
  final String asset;
  final double size;
  final bool dimmed;

  const Icon3D(this.asset, {super.key, required this.size, this.dimmed = false});

  @override
  Widget build(BuildContext context) {
    final dpr = MediaQuery.maybeDevicePixelRatioOf(context) ?? 3;
    Widget img = Image.asset(
      asset,
      width: size,
      height: size,
      fit: BoxFit.contain,
      // ถอดรหัสเท่าที่จอใช้ — ไฟล์ 160–256px ไม่ต้องเต็มขนาดในแรม
      cacheWidth: (size * dpr).ceil().clamp(16, 256),
      filterQuality: FilterQuality.medium,
      excludeFromSemantics: true,
      errorBuilder: (_, _, _) => SizedBox(width: size, height: size),
    );
    if (dimmed) img = Opacity(opacity: 0.55, child: img);
    return img;
  }
}

/// ไอคอนที่สลับเป็น 3D เองในธีม Obsidian — วางแทน `Icon(...)` ได้ตรง ๆ
///
/// ภาพ 3D มีแสงเรืองรอบตัว จึงวาดใหญ่กว่า [size] เล็กน้อยแต่กินพื้นที่เท่าเดิม
/// (เลย์เอาต์รอบข้างไม่ขยับ)
class ThemedIcon extends StatelessWidget {
  final IconData icon;
  final double size;
  final Color? color;

  const ThemedIcon(this.icon, {super.key, this.size = 20, this.color});

  @override
  Widget build(BuildContext context) {
    final asset = ObsidianIcons.forIcon(icon);
    if (asset == null) return Icon(icon, size: size, color: color);
    return SizedBox(
      width: size,
      height: size,
      child: OverflowBox(
        maxWidth: size * 1.45,
        maxHeight: size * 1.45,
        child: Icon3D(asset, size: size * 1.45),
      ),
    );
  }
}

/// กล่องไอคอนหน้าหัวข้อ/แถวเมนู
///
/// ธีมอื่น: กล่องสีจาง + ไอคอนเส้นเหมือนเดิม
/// Obsidian: ไอคอน 3D เต็มกล่อง ไม่มีพื้นกล่อง (ตัวไอคอนมีมิติในตัวอยู่แล้ว)
class IconTile extends StatelessWidget {
  final IconData icon;
  final double size;
  final double iconSize;
  final double radius;
  final Color? background;
  final Color? color;

  const IconTile({
    super.key,
    required this.icon,
    this.size = 34,
    this.iconSize = 18,
    this.radius = 10,
    this.background,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final asset = ObsidianIcons.forIcon(icon);
    if (asset != null) {
      return SizedBox(
        width: size,
        height: size,
        child: OverflowBox(
          maxWidth: size * 1.25,
          maxHeight: size * 1.25,
          child: Icon3D(asset, size: size * 1.25),
        ),
      );
    }
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: background ?? AppColors.goldTint,
        borderRadius: BorderRadius.circular(radius),
      ),
      child: Icon(icon, color: color ?? AppColors.gold2, size: iconSize),
    );
  }
}

/// สลับ "กล่องไล่สี + ไอคอนเส้น" ทั้งก้อนเป็นไอคอน 3D ในธีม Obsidian
///
/// ใช้กับกล่องไอคอนใหญ่ของหน้าว่าง/การ์ดเด่น ที่มีพื้นไล่สีทองเฉพาะตัว —
/// ธีมอื่นได้ [child] เดิมทุกพิกเซล
class ObsidianIconSwap extends StatelessWidget {
  final IconData icon;
  final double size;
  final Widget child;

  const ObsidianIconSwap({
    super.key,
    required this.icon,
    required this.size,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    final asset = ObsidianIcons.forIcon(icon);
    if (asset == null) return child;
    return SizedBox(
      width: size,
      height: size,
      child: OverflowBox(
        maxWidth: size * 1.3,
        maxHeight: size * 1.3,
        child: Icon3D(asset, size: size * 1.3),
      ),
    );
  }
}
