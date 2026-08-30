/// TPIX TRADE — AI Monitor Atoms
/// ชิ้นส่วนหน้าตาที่ทุกแผงมอนิเตอร์ AI TRADE ใช้ร่วมกัน
/// (หัวข้อการ์ด · ชิป · ไทล์สถิติ · แถบเตือนที่มีทางออก · สถานะว่าง · โครงแถว)
///
/// ทุกชิ้นอ่านโทนโลหะจาก AccentProvider — ผู้ใช้สลับเป็นแพลทินัม/โรสโกลด์ได้จริง
/// เขียว/แดงใช้เฉพาะกำไรขาดทุนและฝั่งซื้อขายเท่านั้น
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../providers/accent_provider.dart';
import '../common/glass_card.dart';
import '../common/shimmer_loading.dart';

/// โทนของชิป/แถบเตือน — ไล่ความเข้มด้วยทอง ไม่ใช่เปลี่ยนสี
enum MonitorTone {
  /// เทากันเมทัล — ข้อมูลประกอบ
  neutral,

  /// ทองจาง — ข้อมูลที่ต้องสังเกต
  gold,

  /// ทองเต็ม — เรื่องที่ต้องอ่านก่อน
  goldStrong,

  /// เขียว — กำไร / ฝั่งซื้อ
  up,

  /// แดง — ขาดทุน / ฝั่งขาย / เรื่องอันตราย
  down,
}

/// การ์ดหนึ่งแผงของหน้ามอนิเตอร์ — หัวข้อ + ไอคอน + ปุ่มมุมขวา + เนื้อหา
class MonitorSection extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final Widget child;
  final GlassVariant variant;
  final EdgeInsetsGeometry? margin;

  const MonitorSection({
    super.key,
    required this.icon,
    required this.title,
    required this.child,
    this.subtitle,
    this.trailing,
    this.variant = GlassVariant.elevated,
    this.margin,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    return GlassCard(
      variant: variant,
      borderRadius: 16,
      margin: margin,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 30,
                height: 30,
                decoration: BoxDecoration(
                  color: accent.goldTint,
                  borderRadius: BorderRadius.circular(9),
                ),
                child: Icon(icon, color: accent.g2, size: 16),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: GoogleFonts.inter(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                        letterSpacing: -0.2,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if (subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(
                        subtitle!,
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: AppColors.textTertiary,
                          height: 1.35,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[
                const SizedBox(width: 8),
                trailing!,
              ],
            ],
          ),
          const SizedBox(height: 14),
          child,
        ],
      ),
    );
  }
}

/// ปุ่มไอคอนเล็กมุมขวาของแผง (รีเฟรช ฯลฯ) — มีสถานะกำลังทำงานในตัว
class MonitorIconButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback? onTap;
  final bool busy;
  final String? tooltip;

  const MonitorIconButton({
    super.key,
    required this.icon,
    this.onTap,
    this.busy = false,
    this.tooltip,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final disabled = busy || onTap == null;

    final button = Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: disabled ? null : onTap,
        borderRadius: BorderRadius.circular(10),
        child: Ink(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            color: AppColors.bgInputStrong,
            border: Border.all(color: AppColors.bgCardBorder, width: 1),
          ),
          child: Center(
            child: busy
                ? SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      valueColor: AlwaysStoppedAnimation<Color>(accent.g2),
                    ),
                  )
                : Icon(
                    icon,
                    size: 16,
                    color: disabled ? AppColors.textDisabled : accent.g2,
                  ),
          ),
        ),
      ),
    );

    if (tooltip == null) return button;
    return Tooltip(message: tooltip!, child: button);
  }
}

/// ชิปเล็กสำหรับป้ายกำกับ (การกระทำ · ระดับความเสี่ยง · โหมด)
class MonitorPill extends StatelessWidget {
  final String label;
  final IconData? icon;
  final MonitorTone tone;

  /// true = พื้นทึบตามโทน (ใช้กับป้ายที่ต้องเด่นที่สุดในแถว)
  final bool filled;
  final double fontSize;

  const MonitorPill({
    super.key,
    required this.label,
    this.icon,
    this.tone = MonitorTone.neutral,
    this.filled = false,
    this.fontSize = 10,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    Color bg;
    Color border;
    Color fg;

    switch (tone) {
      case MonitorTone.up:
        bg = AppColors.tradingGreenBg;
        border = AppColors.tradingGreen.withValues(alpha: 0.34);
        fg = AppColors.tradingGreen;
      case MonitorTone.down:
        bg = AppColors.tradingRedBg;
        border = AppColors.tradingRed.withValues(alpha: 0.34);
        fg = AppColors.tradingRed;
      case MonitorTone.goldStrong:
        bg = accent.g2.withValues(alpha: 0.26);
        border = accent.goldBorder;
        fg = accent.g1;
      case MonitorTone.gold:
        bg = accent.goldTint;
        border = accent.goldBorder;
        fg = accent.g1;
      case MonitorTone.neutral:
        bg = AppColors.bgInputStrong;
        border = AppColors.bgCardBorder;
        fg = AppColors.textSecondary;
    }

    if (filled && tone == MonitorTone.goldStrong) {
      // ป้ายที่ต้องเด่นสุด — พื้นทองเต็ม ตัวหนังสือเข้ม
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          gradient: accent.goldGradient,
        ),
        child: _content(AppColors.goldTextOn),
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: bg,
        border: Border.all(color: border, width: 1),
      ),
      child: _content(fg),
    );
  }

  Widget _content(Color fg) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: fontSize + 1.5, color: fg),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: fontSize,
              fontWeight: FontWeight.w700,
              color: fg,
              letterSpacing: 0.4,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ],
      );
}

/// ไทล์สถิติหนึ่งช่อง — ตัวเลข mono + ป้ายกำกับเล็ก
class MonitorStat extends StatelessWidget {
  final String label;
  final String value;
  final Color? valueColor;
  final String? hint;
  final IconData? icon;

  const MonitorStat({
    super.key,
    required this.label,
    required this.value,
    this.valueColor,
    this.hint,
    this.icon,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              if (icon != null) ...[
                Icon(icon, size: 12, color: accent.g2),
                const SizedBox(width: 5),
              ],
              Expanded(
                child: Text(
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
              ),
            ],
          ),
          const SizedBox(height: 7),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              style: AppTheme.mono(
                fontSize: 15,
                fontWeight: FontWeight.w700,
                color: valueColor ?? AppColors.textPrimary,
              ),
              maxLines: 1,
            ),
          ),
          if (hint != null) ...[
            const SizedBox(height: 3),
            Text(
              hint!,
              style: GoogleFonts.inter(
                fontSize: 9.5,
                color: AppColors.textTertiary,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ],
      ),
    );
  }
}

/// แถวคีย์–ค่า (ป้ายซ้าย ตัวเลข mono ขวา)
class MonitorKv extends StatelessWidget {
  final String label;
  final String value;
  final Color? valueColor;
  final double fontSize;

  const MonitorKv({
    super.key,
    required this.label,
    required this.value,
    this.valueColor,
    this.fontSize = 12,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Flexible(
            child: Text(
              label,
              style: GoogleFonts.inter(
                fontSize: fontSize,
                color: AppColors.textSecondary,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(width: 10),
          Text(
            value,
            style: AppTheme.mono(
              fontSize: fontSize + 0.5,
              fontWeight: FontWeight.w700,
              color: valueColor ?? AppColors.textPrimary,
            ),
            maxLines: 1,
          ),
        ],
      ),
    );
  }
}

/// แถบบอกเรื่องที่ต้องรู้ — **ต้องมีทางออกเสมอ** ไม่ใช่แค่บอกว่าพัง
class MonitorNotice extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? body;
  final MonitorTone tone;
  final String? actionLabel;
  final VoidCallback? onAction;
  final String? secondaryLabel;
  final VoidCallback? onSecondary;
  final bool busy;

  const MonitorNotice({
    super.key,
    required this.icon,
    required this.title,
    this.body,
    this.tone = MonitorTone.gold,
    this.actionLabel,
    this.onAction,
    this.secondaryLabel,
    this.onSecondary,
    this.busy = false,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final danger = tone == MonitorTone.down;

    final Color tint =
        danger ? AppColors.tradingRedBg : accent.goldTint;
    final Color edge = danger
        ? AppColors.tradingRed.withValues(alpha: 0.3)
        : accent.goldBorder;
    final Color iconColor = danger ? AppColors.tradingRed : accent.g2;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: tint,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: edge, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 18, color: iconColor),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: GoogleFonts.inter(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                        height: 1.3,
                      ),
                    ),
                    if (body != null) ...[
                      const SizedBox(height: 3),
                      Text(
                        body!,
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: AppColors.textSecondary,
                          height: 1.4,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (actionLabel != null || secondaryLabel != null) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                if (secondaryLabel != null)
                  Expanded(
                    child: OutlinedButton(
                      onPressed: busy ? null : onSecondary,
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        side: BorderSide(
                          color:
                              AppColors.textTertiary.withValues(alpha: 0.4),
                        ),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10),
                        ),
                      ),
                      child: Text(
                        secondaryLabel!,
                        style: GoogleFonts.inter(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ),
                  ),
                if (secondaryLabel != null && actionLabel != null)
                  const SizedBox(width: 8),
                if (actionLabel != null)
                  Expanded(
                    child: ElevatedButton(
                      onPressed: busy ? null : onAction,
                      style: ElevatedButton.styleFrom(
                        backgroundColor:
                            danger ? AppColors.tradingRed : accent.g2,
                        foregroundColor: danger
                            ? AppColors.white
                            : AppColors.goldTextOn,
                        disabledBackgroundColor: AppColors.bgTertiary,
                        disabledForegroundColor: AppColors.textDisabled,
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        elevation: 0,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10),
                        ),
                      ),
                      child: busy
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  AppColors.textDisabled,
                                ),
                              ),
                            )
                          : Text(
                              actionLabel!,
                              style: GoogleFonts.inter(
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// สถานะว่าง — ต้องบอกด้วยว่าจะทำให้มีข้อมูลได้ยังไง
class MonitorEmpty extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? body;

  const MonitorEmpty({
    super.key,
    required this.icon,
    required this.title,
    this.body,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 22, horizontal: 8),
      child: Column(
        children: [
          Icon(
            icon,
            size: 34,
            color: AppColors.textTertiary.withValues(alpha: 0.45),
          ),
          const SizedBox(height: 10),
          Text(
            title,
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppColors.textSecondary,
            ),
          ),
          if (body != null) ...[
            const SizedBox(height: 5),
            Text(
              body!,
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(
                fontSize: 11,
                color: AppColors.textTertiary,
                height: 1.45,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// โครงแถวมาตรฐานของลิสต์ในแผงมอนิเตอร์
class MonitorRow extends StatelessWidget {
  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;

  /// ขอบทองบางๆ ใช้เน้นแถวที่สำคัญกว่าเพื่อน (เช่น ไม้ที่บอทเพิ่งลงมือ)
  final bool highlighted;

  const MonitorRow({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
    this.highlighted = false,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    final decoration = BoxDecoration(
      borderRadius: BorderRadius.circular(14),
      gradient: AppGradients.cardSubtle,
      border: Border.all(
        color: highlighted ? accent.goldBorder : AppColors.bgCardBorder,
        width: 1,
      ),
    );

    if (onTap == null) {
      return Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: padding,
        decoration: decoration,
        child: child,
      );
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Ink(
            padding: padding,
            decoration: decoration,
            child: child,
          ),
        ),
      ),
    );
  }
}

/// แถบสัดส่วน (ความมั่นใจ / น้ำหนักของกลยุทธ์) — ไล่ทองตามโทนที่ผู้ใช้เลือก
class MonitorBar extends StatelessWidget {
  /// 0.0–1.0
  final double value;
  final double height;
  final Color? color;

  const MonitorBar({
    super.key,
    required this.value,
    this.height = 6,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final v = value.isNaN ? 0.0 : value.clamp(0.0, 1.0);

    return LayoutBuilder(
      builder: (_, c) {
        final w = c.maxWidth * v;
        return Stack(
          children: [
            Container(
              height: height,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                color: AppColors.bgInputStrong,
              ),
            ),
            Container(
              height: height,
              width: w,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                gradient: color == null ? accent.goldGradient : null,
                color: color,
              ),
            ),
          ],
        );
      },
    );
  }
}

/// โครงกระดูกระหว่างโหลดรอบแรก — ไม่ใช้สปินเนอร์เต็มจอกับการรีเฟรชย่อย
class MonitorSkeleton extends StatelessWidget {
  final int rows;
  final double rowHeight;

  const MonitorSkeleton({super.key, this.rows = 3, this.rowHeight = 54});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: List.generate(
        rows,
        (i) => Padding(
          padding: EdgeInsets.only(bottom: i == rows - 1 ? 0 : 8),
          child: LayoutBuilder(
            builder: (_, c) => ShimmerBox(
              width: c.maxWidth,
              height: rowHeight,
              borderRadius: 14,
            ),
          ),
        ),
      ),
    );
  }
}
