/// TPIX TRADE — ผิวกระจกขอบนูนของธีม Obsidian
///
/// พอร์ตจาก TPIX Wallet (`wallet/lib/widgets/obsidian/obsidian_panel.dart`) ให้
/// แผง/ปุ่มของสองแอปเป็นสูตรเดียวกันเป๊ะ:
///   พื้นไล่สี + `inset 0 1px 0` (ไฮไลต์ขาวขอบบน) + `inset 0 -1px 0` (เงาดำขอบล่าง)
///   + เงานอกแบบ CSS (วาดเฉพาะ "นอก" แผง — แผงโปร่ง 6% ต้องไม่มีเงาดำทะลุขึ้นมา)
///
/// BoxDecoration ทำ inset shadow ไม่ได้ และ BoxShadow วาดเงาใต้แผงด้วย
/// (แผงโปร่งเลยดูหม่นกว่า mock) → วาดเองด้วย [ObsidianSurfacePainter]
///
/// Developed by Xman Studio
library;

import 'package:flutter/foundation.dart' show listEquals;
import 'package:flutter/material.dart';

/// เงาแบบ CSS `box-shadow: x y blur spread color`
///
/// blur ของ CSS คือ 2σ ส่วน BoxShadow.blurRadius ถูกแปลงเป็น σ ด้วย 0.577r + 0.5
/// ใส่เลขเดียวกันจะได้เงาฟุ้งต่างกันเกือบเท่าตัว — คลาสนี้ใช้ σ ตามนิยาม CSS
@immutable
class CssShadow {
  final double x;
  final double y;
  final double blur;
  final double spread;
  final Color color;

  const CssShadow(this.x, this.y, this.blur, this.spread, this.color);

  double get sigma => blur / 2;
  Offset get offset => Offset(x, y);

  @override
  bool operator ==(Object other) =>
      other is CssShadow &&
      other.x == x &&
      other.y == y &&
      other.blur == blur &&
      other.spread == spread &&
      other.color == color;

  @override
  int get hashCode => Object.hash(x, y, blur, spread, color);

  static CssShadow lerp(CssShadow a, CssShadow b, double t) => CssShadow(
        a.x + (b.x - a.x) * t,
        a.y + (b.y - a.y) * t,
        a.blur + (b.blur - a.blur) * t,
        a.spread + (b.spread - a.spread) * t,
        Color.lerp(a.color, b.color, t)!,
      );
}

/// สเปกพื้นผิวหนึ่งชิ้น (ตรงกับ inline style ของ mock)
@immutable
class ObsidianSurface {
  final double radius;
  final Gradient? gradient;
  final Color? color;

  /// เงานอก (CSS box-shadow ไม่มี inset)
  final List<CssShadow> shadows;

  /// `inset 0 Npx 0 color` — ไฮไลต์ขอบบน
  final Color? topHighlight;
  final double topHighlightWidth;

  /// `inset 0 -Npx 0 color` — เงาขอบล่าง
  final Color? bottomShade;
  final double bottomShadeWidth;

  /// `inset 0 0 0 Npx color` — วงขอบด้านใน
  final Color? ring;
  final double ringWidth;

  /// `inset x y blur color` — เงาจมด้านใน (ช่องกรอก)
  final List<CssShadow> innerShadows;

  const ObsidianSurface({
    required this.radius,
    this.gradient,
    this.color,
    this.shadows = const [],
    this.topHighlight,
    this.topHighlightWidth = 1,
    this.bottomShade,
    this.bottomShadeWidth = 1,
    this.ring,
    this.ringWidth = 1,
    this.innerShadows = const [],
  });

  ObsidianSurface copyWith({
    double? radius,
    Gradient? gradient,
    Color? ring,
    double? ringWidth,
    List<CssShadow>? shadows,
  }) =>
      ObsidianSurface(
        radius: radius ?? this.radius,
        gradient: gradient ?? this.gradient,
        color: color,
        shadows: shadows ?? this.shadows,
        topHighlight: topHighlight,
        topHighlightWidth: topHighlightWidth,
        bottomShade: bottomShade,
        bottomShadeWidth: bottomShadeWidth,
        ring: ring ?? this.ring,
        ringWidth: ringWidth ?? this.ringWidth,
        innerShadows: innerShadows,
      );

  @override
  bool operator ==(Object other) =>
      other is ObsidianSurface &&
      other.radius == radius &&
      other.gradient == gradient &&
      other.color == color &&
      listEquals(other.shadows, shadows) &&
      other.topHighlight == topHighlight &&
      other.topHighlightWidth == topHighlightWidth &&
      other.bottomShade == bottomShade &&
      other.bottomShadeWidth == bottomShadeWidth &&
      other.ring == ring &&
      other.ringWidth == ringWidth &&
      listEquals(other.innerShadows, innerShadows);

  @override
  int get hashCode => Object.hash(
      radius,
      gradient,
      color,
      Object.hashAll(shadows),
      topHighlight,
      topHighlightWidth,
      bottomShade,
      bottomShadeWidth,
      ring,
      ringWidth,
      Object.hashAll(innerShadows));

  // ── สูตรสำเร็จจาก mock ของ Wallet ──

  /// แผงเนื้อหา — ขาว 6%→2%
  static const panel = ObsidianSurface(
    radius: 22,
    gradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0x0FFFFFFF), Color(0x05FFFFFF)],
    ),
    topHighlight: Color(0x1AFFFFFF), // .10
    bottomShade: Color(0x80000000), // .5
    shadows: [CssShadow(0, 20, 40, -24, Color(0xE6000000))],
  );

  /// ปุ่มกระจกนูน / การ์ดที่เด่นกว่าแผงปกติ — ขาว 10%→3%
  static const raised = ObsidianSurface(
    radius: 16,
    gradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0x1AFFFFFF), Color(0x08FFFFFF)],
    ),
    topHighlight: Color(0x2EFFFFFF), // .18
    bottomShade: Color(0x80000000),
    shadows: [CssShadow(0, 10, 24, -10, Color(0xE6000000))],
  );

  /// บัตรโลหะเข้ม (การ์ดยอดพอร์ต / การ์ดเด่น) — ไล่โลหะ + ขอบเงิน + เรืองม่วงจาง ๆ
  static const metal = ObsidianSurface(
    radius: 22,
    gradient: LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [Color(0xFF232838), Color(0xFF12141B), Color(0xFF0A0B10)],
      stops: [0.0, 0.55, 1.0],
    ),
    topHighlight: Color(0x38FFFFFF), // .22
    bottomShade: Color(0x99000000),
    ring: Color(0x14FFFFFF),
    shadows: [
      CssShadow(0, 24, 44, -20, Color(0x598B5CF6)), // violet .35
      CssShadow(0, 18, 34, -16, Color(0xE6000000)),
    ],
  );

  /// ปุ่มรองสีเข้มทึบ
  static const dark = ObsidianSurface(
    radius: 13,
    gradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0xFF232733), Color(0xFF161920)],
    ),
    topHighlight: Color(0x1FFFFFFF), // .12
    bottomShade: Color(0x99000000), // .6
    shadows: [CssShadow(0, 8, 16, -8, Color(0xE6000000))],
  );

  /// ช่องกรอกแบบยุบลง
  static const field = ObsidianSurface(
    radius: 18,
    gradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0xFF08090D), Color(0xFF11131A)],
    ),
    innerShadows: [CssShadow(0, 3, 8, 0, Color(0xBF000000))],
  );

  /// ปุ่มหลักสีขาวนูน เรืองม่วงด้านล่าง
  static const primaryButton = ObsidianSurface(
    radius: 19,
    gradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0xFFFFFFFF), Color(0xFFD9DCE3)],
    ),
    topHighlight: Color(0xFFFFFFFF),
    bottomShade: Color(0x2E000000), // .18
    bottomShadeWidth: 2,
    shadows: [
      CssShadow(0, 18, 34, -12, Color(0xA68B5CF6)), // violet .65
      CssShadow(0, 10, 20, -10, Color(0xE6000000)),
    ],
  );

  /// ปุ่มซื้อ/ขาย — ไล่สีของทิศราคา + ไฮไลต์บนแบบเดียวกับปุ่มหลัก
  static ObsidianSurface trade({
    required Color light,
    required Color dark,
    required double radius,
  }) =>
      ObsidianSurface(
        radius: radius,
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [light, dark],
        ),
        topHighlight: const Color(0x66FFFFFF),
        bottomShade: const Color(0x33000000),
        bottomShadeWidth: 2,
        shadows: [
          CssShadow(0, 16, 30, -12, dark.withValues(alpha: 0.6)),
          const CssShadow(0, 10, 20, -10, Color(0xE6000000)),
        ],
      );
}

/// วาด [ObsidianSurface] ตามความหมายของ CSS
class ObsidianSurfacePainter extends CustomPainter {
  final ObsidianSurface s;

  const ObsidianSurfacePainter(this.s);

  static RRect _spread(RRect r, double d) {
    if (d == 0) return r;
    final rect = r.outerRect.inflate(d);
    final rad = (r.tlRadiusX + d).clamp(0.0, double.infinity);
    return RRect.fromRectAndRadius(
      rect.width <= 0 || rect.height <= 0
          ? Rect.fromCenter(center: rect.center, width: 0, height: 0)
          : rect,
      Radius.circular(rad),
    );
  }

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final rr = RRect.fromRectAndRadius(rect, Radius.circular(s.radius));

    // 1) เงานอก — ตัดส่วนที่อยู่ใต้แผงทิ้ง (CSS ไม่วาด box-shadow ใต้กล่อง)
    if (s.shadows.isNotEmpty) {
      canvas.save();
      var reach = 0.0;
      for (final sh in s.shadows) {
        final r = sh.blur * 1.5 + sh.spread.abs() + sh.x.abs() + sh.y.abs();
        if (r > reach) reach = r;
      }
      final outside = Path()
        ..fillType = PathFillType.evenOdd
        ..addRect(rect.inflate(reach + 4))
        ..addRRect(rr);
      canvas.clipPath(outside);
      for (final sh in s.shadows) {
        canvas.drawRRect(
          _spread(rr.shift(sh.offset), sh.spread),
          Paint()
            ..color = sh.color
            ..maskFilter = sh.sigma > 0
                ? MaskFilter.blur(BlurStyle.normal, sh.sigma)
                : null,
        );
      }
      canvas.restore();
    }

    // 2) พื้น
    final fill = Paint();
    if (s.gradient != null) {
      fill.shader = s.gradient!.createShader(rect);
    } else if (s.color != null) {
      fill.color = s.color!;
    }
    if (s.gradient != null || s.color != null) canvas.drawRRect(rr, fill);

    // 3) เอฟเฟกต์ด้านใน (ตัดให้อยู่ในแผง)
    final needInner = s.topHighlight != null ||
        s.bottomShade != null ||
        s.ring != null ||
        s.innerShadows.isNotEmpty;
    if (!needInner) return;
    canvas.save();
    canvas.clipRRect(rr);
    final whole = Path()..addRRect(rr);

    for (final sh in s.innerShadows) {
      // เงาจม = ทุกอย่างนอก "แผงที่เลื่อนตาม offset" แล้วเบลอ
      final hole = Path()..addRRect(_spread(rr.shift(sh.offset), -sh.spread));
      final ring = Path()
        ..fillType = PathFillType.evenOdd
        ..addRect(rect.inflate(sh.blur * 2 + 8))
        ..addPath(hole, Offset.zero);
      canvas.drawPath(
        ring,
        Paint()
          ..color = sh.color
          ..maskFilter =
              sh.sigma > 0 ? MaskFilter.blur(BlurStyle.normal, sh.sigma) : null,
      );
    }
    if (s.topHighlight != null) {
      final strip = Path.combine(
        PathOperation.difference,
        whole,
        Path()..addRRect(rr.shift(Offset(0, s.topHighlightWidth))),
      );
      canvas.drawPath(strip, Paint()..color = s.topHighlight!);
    }
    if (s.bottomShade != null) {
      final strip = Path.combine(
        PathOperation.difference,
        whole,
        Path()..addRRect(rr.shift(Offset(0, -s.bottomShadeWidth))),
      );
      canvas.drawPath(strip, Paint()..color = s.bottomShade!);
    }
    if (s.ring != null) {
      canvas.drawRRect(
        rr.deflate(s.ringWidth / 2),
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = s.ringWidth
          ..color = s.ring!,
      );
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(ObsidianSurfacePainter oldDelegate) => oldDelegate.s != s;
}

/// ปุ่มกดได้แบบ "ยุบเล็กน้อย" ตอนนิ้วแตะ (แทน ripple ที่ไม่เข้ากับวัตถุ 3D)
class ObsidianPressable extends StatefulWidget {
  final Widget child;
  final VoidCallback? onTap;
  final String? semanticLabel;

  /// ขนาดตอนกดค้าง (1 = ไม่ยุบ)
  final double pressedScale;

  const ObsidianPressable({
    super.key,
    required this.child,
    this.onTap,
    this.semanticLabel,
    this.pressedScale = 0.97,
  });

  @override
  State<ObsidianPressable> createState() => _ObsidianPressableState();
}

class _ObsidianPressableState extends State<ObsidianPressable> {
  bool _down = false;

  void _set(bool v) {
    if (_down != v && mounted) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) {
    final enabled = widget.onTap != null;
    return Semantics(
      button: true,
      enabled: enabled,
      label: widget.semanticLabel,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: widget.onTap,
        onTapDown: enabled ? (_) => _set(true) : null,
        onTapUp: enabled ? (_) => _set(false) : null,
        onTapCancel: enabled ? () => _set(false) : null,
        child: AnimatedScale(
          scale: _down ? widget.pressedScale : 1,
          duration: const Duration(milliseconds: 110),
          curve: Curves.easeOut,
          child: widget.child,
        ),
      ),
    );
  }
}
