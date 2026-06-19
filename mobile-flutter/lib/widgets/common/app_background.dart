/// TPIX TRADE — App Background
/// The signature "luxury dark" backdrop: a gunmetal vertical gradient with a
/// soft champagne-gold halo bleeding down from the top, plus optional ambient
/// gold fireflies. Wrap any screen body in this to sit it on the brand surface.
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import '../../core/theme/gradients.dart';
import 'firefly_background.dart';

class AppBackground extends StatelessWidget {
  final Widget child;

  /// Show the ambient gold fireflies layer (default true; the layer itself
  /// also respects the user's AccentProvider toggle/reduce-motion settings).
  final bool fireflies;

  const AppBackground({super.key, required this.child, this.fireflies = true});

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(gradient: AppGradients.appBackgroundBase),
      child: DecoratedBox(
        decoration: const BoxDecoration(gradient: AppGradients.appBackgroundGlow),
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
}
