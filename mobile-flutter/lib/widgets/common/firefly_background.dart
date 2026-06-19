/// TPIX TRADE — Ambient Gold Fireflies
/// ~16 small radial-gold dots drifting behind content. Density, on/off and
/// reduce-motion are driven by AccentProvider. Tinted to the active metal tone.
///
/// Cheap: one looping controller + a CustomPainter. Static (no controller)
/// when reduce-motion is on; renders nothing when fireflies are disabled.
///
/// Developed by Xman Studio

import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/accent_provider.dart';

class FireflyBackground extends StatefulWidget {
  const FireflyBackground({super.key});

  @override
  State<FireflyBackground> createState() => _FireflyBackgroundState();
}

class _FireflyBackgroundState extends State<FireflyBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late List<_Firefly> _flies;
  int _seededCount = -1;

  @override
  void initState() {
    super.initState();
    // ~22s loop — slow ambient drift. Started lazily in build() only when
    // animation is actually wanted (honors reduce-motion / fireflies-off).
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 22),
    );
    _flies = const [];
  }

  void _ensureFlies(int count) {
    if (_seededCount == count) return;
    _seededCount = count;
    // Deterministic layout (fixed seed) so dots don't jump on rebuild.
    final rnd = math.Random(0xF1FE);
    _flies = List.generate(count, (i) {
      return _Firefly(
        baseX: rnd.nextDouble(),
        baseY: rnd.nextDouble(),
        radius: 0.8 + rnd.nextDouble() * 1.8, // 0.8–2.6 px
        drift: 0.04 + rnd.nextDouble() * 0.10, // fraction of size
        speed: 0.5 + rnd.nextDouble() * 1.0,
        phase: rnd.nextDouble(),
      );
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    // Drive the controller only when we actually animate — this honors
    // reduce-motion at runtime (toggling the setting pauses/resumes here).
    final shouldAnimate = accent.animateFireflies && accent.fireflyDensity > 0;
    if (shouldAnimate) {
      if (!_controller.isAnimating) _controller.repeat();
    } else if (_controller.isAnimating) {
      _controller.stop();
    }

    if (!accent.showFireflies || accent.fireflyDensity <= 0) {
      return const SizedBox.shrink();
    }
    _ensureFlies(accent.fireflyDensity);

    final painter = _FireflyPainter(
      flies: _flies,
      color: accent.g1,
      // Wiring the controller as `repaint` makes the painter re-run each tick.
      // Null → static render (reduce-motion), no per-frame repaint cost.
      progress: accent.animateFireflies ? _controller : null,
    );

    return IgnorePointer(
      child: RepaintBoundary(
        child: CustomPaint(painter: painter, size: Size.infinite),
      ),
    );
  }
}

class _Firefly {
  final double baseX, baseY, radius, drift, speed, phase;
  const _Firefly({
    required this.baseX,
    required this.baseY,
    required this.radius,
    required this.drift,
    required this.speed,
    required this.phase,
  });
}

class _FireflyPainter extends CustomPainter {
  final List<_Firefly> flies;
  final Color color;
  final Animation<double>? progress;

  _FireflyPainter({
    required this.flies,
    required this.color,
    this.progress,
  }) : super(repaint: progress);

  bool get animate => progress != null;
  double get t => progress?.value ?? 0.25;

  @override
  void paint(Canvas canvas, Size size) {
    const twoPi = math.pi * 2;
    for (final f in flies) {
      final angle = twoPi * (t * f.speed + f.phase);
      final dx = animate ? math.sin(angle) * f.drift * size.width * 0.12 : 0.0;
      final dy = animate ? math.cos(angle) * f.drift * size.height * 0.12 : 0.0;
      final cx = f.baseX * size.width + dx;
      final cy = f.baseY * size.height + dy;

      // Opacity pulse 0.15–0.7
      final pulse = animate ? (0.5 + 0.5 * math.sin(angle * 1.3)) : 0.55;
      final opacity = 0.15 + 0.55 * pulse;

      final paint = Paint()
        ..color = color.withValues(alpha: opacity)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, f.radius * 1.2);
      canvas.drawCircle(Offset(cx, cy), f.radius, paint);
    }
  }

  @override
  bool shouldRepaint(_FireflyPainter old) =>
      old.color != color ||
      old.progress != progress ||
      old.flies != flies;
}
