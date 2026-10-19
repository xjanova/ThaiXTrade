/// TPIX TRADE — App Background
/// The signature "luxury dark" backdrop: a gunmetal vertical gradient with a
/// soft champagne-gold halo bleeding down from the top, plus optional ambient
/// gold fireflies. Wrap any screen body in this to sit it on the brand surface.
///
/// ธีม Obsidian: พื้นดำ #07080C + คลิปเทควนลูปกราฟแท่งเทียน (ครอบครัวเดียวกับ TPIX Wallet) ครึ่งบน
/// ของจอ ไล่มืดลงล่าง — ตัวเล่นเดียวใช้ร่วมทุกหน้า ดู [ObsidianBackdropVideo]
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/gradients.dart';
import '../obsidian/obsidian_backdrop_video.dart';
import 'firefly_background.dart';

class AppBackground extends StatelessWidget {
  final Widget child;

  /// Show the ambient gold fireflies layer (default true; the layer itself
  /// also respects the user's AccentProvider toggle/reduce-motion settings).
  final bool fireflies;

  /// Obsidian: false = ภาพนิ่ง (หน้าที่มีกราฟสด ไม่ควรแย่งเครื่องถอดรหัสวิดีโอ)
  final bool animated;

  const AppBackground({
    super.key,
    required this.child,
    this.fireflies = true,
    this.animated = true,
  });

  /// สัดส่วนความสูงของภาพต่อจอ (mock ของ Wallet: 574 / 844)
  static const double _techHeightFactor = 574 / 844;

  @override
  Widget build(BuildContext context) {
    if (AppColors.palette.obsidian) return _obsidian();

    return DecoratedBox(
      decoration: BoxDecoration(gradient: AppGradients.appBackgroundBase),
      child: DecoratedBox(
        decoration: BoxDecoration(gradient: AppGradients.appBackgroundGlow),
        child: fireflies
            ? Stack(
                children: [
                  const Positioned.fill(child: FireflyBackground()),
                  child,
                ],
              )
            : child,
      ),
    );
  }

  Widget _obsidian() {
    final ground = AppColors.bgPrimary;
    return ColoredBox(
      color: ground,
      child: LayoutBuilder(
        builder: (context, box) {
          final h = box.maxHeight.isFinite ? box.maxHeight : 844.0;
          return Stack(
            fit: StackFit.expand,
            children: [
              Positioned(
                left: 0,
                right: 0,
                top: 0,
                height: h * _techHeightFactor,
                child: IgnorePointer(
                  child: ExcludeSemantics(
                    child: RepaintBoundary(
                      child: ObsidianBackdropLayer(animated: animated),
                    ),
                  ),
                ),
              ),
              // ไล่ดำทับ (ค่าจาก mock: 0 / 250 / 500 / 580 จากจอสูง 844)
              // ทึบกว่า Wallet — เส้นขอบฟ้ากราฟแท่งเทียนสว่างและอยู่ตรงหัวหน้าจอพอดี
              // คำอธิบายใต้หัวข้อ (หน้า AI/สลับ) ต้องอ่านออก
              Positioned.fill(
                child: IgnorePointer(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [
                          ground.withValues(alpha: 0.25),
                          ground.withValues(alpha: 0.38),
                          ground.withValues(alpha: 0.68),
                          ground.withValues(alpha: 0.78),
                          ground.withValues(alpha: 0.94),
                          ground,
                          ground,
                        ],
                        stops: const [
                          0.0,
                          70 / 844,
                          125 / 844,
                          250 / 844,
                          500 / 844,
                          580 / 844,
                          1.0,
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              if (fireflies) const Positioned.fill(child: FireflyBackground()),
              Positioned.fill(child: child),
            ],
          );
        },
      ),
    );
  }
}
