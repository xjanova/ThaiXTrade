/// TPIX TRADE — แถบเมนูล่างแบบมีรอยเว้า (ธีม Obsidian)
///
/// พอร์ตจาก TPIX Wallet (`wallet/lib/widgets/obsidian/notched_nav_bar.dart`):
///   - แถบกระจกสูง 78 มน 26 + รอยเว้าทรง U ตรงกลางรับปุ่มกลม
///     (จอกว้างขึ้น → รอยเว้าคงขนาดอยู่กลาง เส้นตรงสองข้างยืดออก)
///   - ขอบบนสะท้อนแสง · เส้นเรืองไล่สีตามรอยเว้า หายใจ 4 วิ
///   - ปุ่มกลาง 66px จมในรอยเว้า โผล่พ้นขอบแถบ 13px · วงแหวนไล่สีหมุน 3.2 วิ
///
/// ต่างจาก Wallet: ปุ่มกลางคือ "ตลาด" (ของเดิมของแอปเทรด) ไม่ใช่ปุ่มสแกน
///
/// 🔴 กรอบของวิดเจ็ตต้องครอบปุ่มกลางทั้งใบ (รวม 13px ที่โผล่พ้นแถบ)
///    Flutter ไม่ hit-test ส่วนที่ล้นนอกกรอบพ่อ — เห็นปุ่มเต็มใบแต่กดยอดปุ่มแล้วเงียบ
///
/// Developed by Xman Studio
library;

import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import 'obsidian_icons.dart';
import 'obsidian_surface.dart';

export 'obsidian_icons.dart' show ObsidianIcons;

class ObsidianNavItem {
  final String asset;
  final String label;
  final VoidCallback onTap;

  const ObsidianNavItem({
    required this.asset,
    required this.label,
    required this.onTap,
  });
}

class ObsidianNavBar extends StatefulWidget {
  static const Key centerButtonKey = Key('nav-center-button');

  /// 4 ช่อง: ซ้าย 2 / ขวา 2 — ปุ่มกลางอยู่ตรงกลางเอง
  final List<ObsidianNavItem> items;

  /// ช่องที่ผู้ใช้อยู่ (0..3) หรือ -1 เมื่ออยู่ที่ปุ่มกลาง
  final int currentIndex;
  final bool centerActive;

  final VoidCallback onCenterTap;
  final String centerLabel;

  const ObsidianNavBar({
    super.key,
    required this.items,
    required this.currentIndex,
    required this.centerActive,
    required this.onCenterTap,
    required this.centerLabel,
  });

  static const double barHeight = 78;
  static const double orbSize = 66;

  /// ปุ่มกลางโผล่พ้นขอบบนของแถบ = 20% ของปุ่ม
  static const double orbRise = 13;

  static double bottomGap(BuildContext context) =>
      math.max(12.0, MediaQuery.viewPaddingOf(context).bottom);

  /// รูปทรงแถบ (ความกว้าง [w]) — รอยเว้าคงขนาดอยู่กึ่งกลาง
  static Path barPath(double w) {
    final cx = w / 2;
    return Path()
      ..moveTo(26, 0)
      ..lineTo(cx - 58, 0)
      ..cubicTo(cx - 46, 0, cx - 40, 8, cx - 40, 20)
      ..arcToPoint(Offset(cx + 40, 20),
          radius: const Radius.circular(40), clockwise: false)
      ..cubicTo(cx + 40, 8, cx + 46, 0, cx + 58, 0)
      ..lineTo(w - 26, 0)
      ..quadraticBezierTo(w, 0, w, 26)
      ..lineTo(w, 52)
      ..quadraticBezierTo(w, 78, w - 26, 78)
      ..lineTo(26, 78)
      ..quadraticBezierTo(0, 78, 0, 52)
      ..lineTo(0, 26)
      ..quadraticBezierTo(0, 0, 26, 0)
      ..close();
  }

  /// เส้นเรืองตามขอบรอยเว้า (เส้นเปิด)
  static Path notchPath(double w) {
    final cx = w / 2;
    return Path()
      ..moveTo(cx - 67, 0.6)
      ..lineTo(cx - 58, 0.6)
      ..cubicTo(cx - 46, 0.6, cx - 40.4, 8.6, cx - 40.4, 20)
      ..arcToPoint(Offset(cx + 40.4, 20),
          radius: const Radius.circular(40.4), clockwise: false)
      ..cubicTo(cx + 40.4, 8.6, cx + 46, 0.6, cx + 58, 0.6)
      ..lineTo(cx + 67, 0.6);
  }

  @override
  State<ObsidianNavBar> createState() => _ObsidianNavBarState();
}

class _ObsidianNavBarState extends State<ObsidianNavBar>
    with TickerProviderStateMixin {
  /// หายใจ 4 วิ (0→1→0)
  late final AnimationController _breath;

  /// วงแหวนไล่สีหมุน 3.2 วิต่อรอบ
  late final AnimationController _spin;

  bool _reduceMotion = false;

  @override
  void initState() {
    super.initState();
    _breath =
        AnimationController(vsync: this, duration: const Duration(seconds: 2));
    _spin = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 3200));
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final reduce = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    if (reduce == _reduceMotion && (_breath.isAnimating || reduce)) return;
    _reduceMotion = reduce;
    if (reduce) {
      // ลดภาพเคลื่อนไหว → หยุดนิ่งที่ครึ่งทาง (ยังเรืองแสง แต่ไม่กะพริบ/หมุน)
      _breath
        ..stop()
        ..value = 0.5;
      _spin
        ..stop()
        ..value = 0;
    } else {
      _breath.repeat(reverse: true);
      _spin.repeat();
    }
  }

  @override
  void dispose() {
    _breath.dispose();
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    assert(widget.items.length == 4, 'ObsidianNavBar ต้องมี 4 ช่องพอดี');
    const rise = ObsidianNavBar.orbRise;
    const barH = ObsidianNavBar.barHeight;
    final gap = ObsidianNavBar.bottomGap(context);

    return MediaQuery.withClampedTextScaling(
      maxScaleFactor: 1.2,
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 0, 16, gap),
        child: SizedBox(
          height: rise + barH,
          child: LayoutBuilder(builder: (context, box) {
            final w = box.maxWidth;
            final cx = w / 2;
            return Stack(
              clipBehavior: Clip.none,
              children: [
                // ── เงาเบลอทรงแถบ ──
                Positioned(
                  left: 0,
                  top: rise,
                  width: w,
                  height: barH,
                  child: IgnorePointer(
                      child: CustomPaint(painter: _BarShadowPainter())),
                ),
                // ── ตัวแถบกระจก (ตัดตามรอยเว้า) ──
                Positioned(
                  left: 0,
                  top: rise,
                  width: w,
                  height: barH,
                  child: ClipPath(
                    clipper: _BarClipper(),
                    child: BackdropFilter(
                      filter: ui.ImageFilter.blur(sigmaX: 20, sigmaY: 20),
                      child: const DecoratedBox(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                            colors: [
                              Color(0xE6242733),
                              Color(0xF212141B),
                              Color(0xF70B0C11)
                            ],
                            stops: [0, 0.55, 1],
                          ),
                        ),
                        child: SizedBox.expand(),
                      ),
                    ),
                  ),
                ),
                // ── ขอบสะท้อนแสง + เส้นเรืองรอยเว้า ──
                Positioned(
                  left: 0,
                  top: rise,
                  width: w,
                  height: barH,
                  child: IgnorePointer(
                    child: AnimatedBuilder(
                      animation: _breath,
                      builder: (_, _) => CustomPaint(
                        painter: _BarRimPainter(
                            glow: 0.3 +
                                0.7 *
                                    Curves.easeInOut.transform(_breath.value)),
                      ),
                    ),
                  ),
                ),
                // ── 4 เมนู (ช่องกลางเว้นให้ปุ่มกลาง) ──
                Positioned(
                  left: 4,
                  right: 4,
                  top: rise + 6,
                  height: barH - 6,
                  child: Row(
                    children: [
                      for (var slot = 0; slot < 5; slot++)
                        Expanded(
                          child: slot == 2
                              ? const SizedBox.shrink()
                              : _NavCell(
                                  item: widget.items[slot < 2 ? slot : slot - 1],
                                  active: widget.currentIndex ==
                                      (slot < 2 ? slot : slot - 1),
                                ),
                        ),
                    ],
                  ),
                ),
                // ── ป้ายใต้ปุ่มกลาง ──
                Positioned(
                  left: cx - 50,
                  width: 100,
                  bottom: 3,
                  child: IgnorePointer(
                    child: Text(
                      widget.centerLabel,
                      textAlign: TextAlign.center,
                      maxLines: 1,
                      style: AppFonts.ui(
                        fontSize: 11,
                        fontWeight: widget.centerActive
                            ? FontWeight.w600
                            : FontWeight.w500,
                        color: widget.centerActive
                            ? AppColors.textPrimary
                            : ObsidianBrand.chrome,
                      ),
                    ),
                  ),
                ),
                // ── รัศมีรอบปุ่ม ──
                Positioned(
                  left: cx - 70,
                  top: rise - 50,
                  width: 140,
                  height: 140,
                  child: IgnorePointer(
                    child: AnimatedBuilder(
                      animation: _breath,
                      builder: (_, child) {
                        final b = Curves.easeInOut.transform(_breath.value);
                        return Opacity(
                          opacity: 0.25 + 0.65 * b,
                          child: Transform.scale(
                              scale: 0.85 + 0.23 * b, child: child),
                        );
                      },
                      child: const DecoratedBox(
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: RadialGradient(
                            colors: [
                              Color(0x8C8B5CF6),
                              Color(0x3822D3EE),
                              Color(0x00000000)
                            ],
                            stops: [0, 0.55, 1],
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                // ── ปุ่มกลาง ──
                Positioned(
                  left: cx - ObsidianNavBar.orbSize / 2,
                  top: 0,
                  width: ObsidianNavBar.orbSize,
                  height: ObsidianNavBar.orbSize,
                  child: Semantics(
                    selected: widget.centerActive,
                    child: ObsidianPressable(
                      key: ObsidianNavBar.centerButtonKey,
                      onTap: widget.onCenterTap,
                      semanticLabel: widget.centerLabel,
                      pressedScale: 0.94,
                      child: _CenterOrb(breath: _breath, spin: _spin),
                    ),
                  ),
                ),
              ],
            );
          }),
        ),
      ),
    );
  }
}

class _NavCell extends StatelessWidget {
  final ObsidianNavItem item;
  final bool active;

  const _NavCell({required this.item, required this.active});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: active,
      label: item.label,
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: item.onTap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            _Icon3D(item.asset, size: active ? 32 : 30, dimmed: !active, glow: active),
            const SizedBox(height: 2),
            Text(
              item.label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppFonts.ui(
                fontSize: 11,
                height: 1.3,
                fontWeight: active ? FontWeight.w600 : FontWeight.w400,
                color: active ? AppColors.textPrimary : AppColors.textTertiary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// ไอคอน 3D — ช่องที่ไม่ได้เลือกหรี่ลง ช่องที่เลือกเรืองม่วง
class _Icon3D extends StatelessWidget {
  final String asset;
  final double size;
  final bool dimmed;
  final bool glow;

  const _Icon3D(this.asset, {required this.size, this.dimmed = false, this.glow = false});

  @override
  Widget build(BuildContext context) {
    Widget img = Image.asset(
      asset,
      width: size,
      height: size,
      fit: BoxFit.contain,
      filterQuality: FilterQuality.medium,
      errorBuilder: (_, _, _) => SizedBox(width: size, height: size),
    );
    if (dimmed) img = Opacity(opacity: 0.55, child: img);
    if (!glow) return img;
    return DecoratedBox(
      decoration: const BoxDecoration(
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(color: Color(0x598B5CF6), blurRadius: 18, spreadRadius: -4),
        ],
      ),
      child: img,
    );
  }
}

/// ปุ่มกลม: เรืองแสงเต้น → วงแหวนไล่สีหมุน → จานดำ + ไอคอนกราฟ 3D + เงาวาว
class _CenterOrb extends StatelessWidget {
  final Animation<double> breath;
  final Animation<double> spin;

  const _CenterOrb({required this.breath, required this.spin});

  static const _calm = [
    CssShadow(0, 0, 10, 0, Color(0x598B5CF6)),
    CssShadow(0, 0, 0, 0, Color(0x0022D3EE)),
    CssShadow(0, 14, 26, -6, Color(0xE6000000)),
  ];
  static const _peak = [
    CssShadow(0, 0, 34, 10, Color(0xBF8B5CF6)),
    CssShadow(0, 0, 60, 18, Color(0x4D22D3EE)),
    CssShadow(0, 14, 26, -6, Color(0xE6000000)),
  ];

  static const _disc = ObsidianSurface(
    radius: 30,
    gradient: RadialGradient(
      center: Alignment(0, -0.3),
      radius: 0.82,
      colors: [Color(0xFF1E2230), Color(0xFF0A0B10)],
      stops: [0, 0.7],
    ),
    topHighlight: Color(0x2EFFFFFF),
    topHighlightWidth: 2,
    innerShadows: [CssShadow(0, -3, 6, 0, Color(0x99000000))],
  );

  @override
  Widget build(BuildContext context) {
    const size = ObsidianNavBar.orbSize;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Positioned.fill(
          child: AnimatedBuilder(
            animation: breath,
            builder: (_, _) {
              final b = Curves.easeInOut.transform(breath.value);
              return CustomPaint(
                painter: ObsidianSurfacePainter(ObsidianSurface(
                  radius: size / 2,
                  shadows: [
                    for (var i = 0; i < 3; i++)
                      CssShadow.lerp(_calm[i], _peak[i], b)
                  ],
                )),
              );
            },
          ),
        ),
        // วงแหวนไล่สีหมุน (เริ่มที่ 12 นาฬิกา)
        Positioned.fill(
          child: RepaintBoundary(
            child: AnimatedBuilder(
              animation: spin,
              builder: (_, _) => DecoratedBox(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: SweepGradient(
                    colors: const [
                      ObsidianBrand.cyan,
                      ObsidianBrand.violet,
                      ObsidianBrand.orange,
                      ObsidianBrand.cyan
                    ],
                    stops: const [0, 1 / 3, 2 / 3, 1],
                    transform: GradientRotation(
                        spin.value * 2 * math.pi - math.pi / 2),
                  ),
                ),
              ),
            ),
          ),
        ),
        Positioned.fill(
          left: 3,
          top: 3,
          right: 3,
          bottom: 3,
          child: ClipOval(
            child: CustomPaint(
              painter: const ObsidianSurfacePainter(_disc),
              child: Stack(
                children: [
                  const Center(
                    child: _Icon3D(ObsidianIcons.candles, size: 40),
                  ),
                  Positioned(
                    left: 6,
                    right: 6,
                    top: 3,
                    height: 24,
                    child: IgnorePointer(
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(999),
                          gradient: const LinearGradient(
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                            colors: [Color(0x38FFFFFF), Color(0x00FFFFFF)],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _BarClipper extends CustomClipper<Path> {
  @override
  Path getClip(Size size) => ObsidianNavBar.barPath(size.width);

  @override
  bool shouldReclip(_BarClipper oldClipper) => false;
}

class _BarShadowPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.translate(0, 14);
    canvas.drawPath(
      ObsidianNavBar.barPath(size.width),
      Paint()
        ..color = const Color(0xE6000000)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 14),
    );
    canvas.restore();
  }

  @override
  bool shouldRepaint(_BarShadowPainter oldDelegate) => false;
}

/// ขอบบนสะท้อนแสง + เส้นเรืองรอยเว้า (cyan → violet → orange)
class _BarRimPainter extends CustomPainter {
  final double glow;

  _BarRimPainter({required this.glow});

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final bar = ObsidianNavBar.barPath(w);

    canvas.save();
    canvas.clipPath(bar);
    canvas.drawPath(
      bar,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..shader = ui.Gradient.linear(
          Offset.zero,
          const Offset(0, ObsidianNavBar.barHeight),
          const [Color(0x38FFFFFF), Color(0x0DFFFFFF), Color(0x66000000)],
          const [0, 0.35, 1],
        ),
    );
    canvas.restore();

    final cx = w / 2;
    final notch = ObsidianNavBar.notchPath(w);
    Shader grad(List<Color> cs) => ui.Gradient.linear(
          Offset(cx - 67, 0),
          Offset(cx + 67, 0),
          cs,
          const [0, 0.18, 0.5, 0.82, 1],
        );
    List<Color> tint(Color c, double k) => [
          c.withValues(alpha: 0),
          c.withValues(alpha: k),
          c.withValues(alpha: k),
          c.withValues(alpha: k),
          c.withValues(alpha: 0)
        ];
    Paint stroke(Shader sh, [double sigma = 0]) => Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.4
      ..strokeCap = StrokeCap.round
      ..shader = sh
      ..maskFilter =
          sigma > 0 ? MaskFilter.blur(BlurStyle.normal, sigma) : null;

    canvas.drawPath(
        notch, stroke(grad(tint(ObsidianBrand.cyan, 0.7 * glow)), 5));
    canvas.drawPath(notch, stroke(grad(tint(ObsidianBrand.violet, glow)), 2));
    canvas.drawPath(
      notch,
      stroke(grad([
        ObsidianBrand.cyan.withValues(alpha: 0),
        ObsidianBrand.cyan.withValues(alpha: glow),
        ObsidianBrand.violet.withValues(alpha: glow),
        ObsidianBrand.orange.withValues(alpha: glow),
        ObsidianBrand.orange.withValues(alpha: 0),
      ])),
    );
  }

  @override
  bool shouldRepaint(_BarRimPainter oldDelegate) => oldDelegate.glow != glow;
}
