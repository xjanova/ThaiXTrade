/// TPIX TRADE — Coin Logo Widget
/// แสดงโลโก้เหรียญจาก CDN พร้อม 3-tier fallback:
/// CoinCap CDN → CryptoLogos.cc → ตัวอักษรใน circle
///
/// Developed by Xman Studio

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme/app_colors.dart';
import '../../utils/crypto_logos.dart';

class CoinLogo extends StatefulWidget {
  final String symbol;
  final double size;
  final double borderRadius;

  const CoinLogo({
    super.key,
    required this.symbol,
    this.size = 36,
    this.borderRadius = 18,
  });

  @override
  State<CoinLogo> createState() => _CoinLogoState();
}

class _CoinLogoState extends State<CoinLogo> {
  bool _primaryFailed = false;

  @override
  void didUpdateWidget(CoinLogo old) {
    super.didUpdateWidget(old);
    if (old.symbol != widget.symbol) {
      _primaryFailed = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    // TPIX ecosystem → local asset
    if (CryptoLogos.isTpix(widget.symbol)) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(widget.borderRadius),
        child: Image.asset(
          'assets/images/logo.webp',
          width: widget.size,
          height: widget.size,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => _letterFallback(),
        ),
      );
    }

    final primaryUrl = CryptoLogos.getLogoUrl(widget.symbol);
    if (primaryUrl.isEmpty) return _letterFallback();

    final fallbackUrl = CryptoLogos.getFallbackUrl(widget.symbol);
    final url = _primaryFailed && fallbackUrl != null ? fallbackUrl : primaryUrl;

    return ClipRRect(
      borderRadius: BorderRadius.circular(widget.borderRadius),
      child: CachedNetworkImage(
        imageUrl: url,
        width: widget.size,
        height: widget.size,
        fit: BoxFit.cover,
        placeholder: (_, __) => _shimmerPlaceholder(),
        errorWidget: (_, __, ___) {
          // Primary failed → try fallback
          if (!_primaryFailed && fallbackUrl != null) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) setState(() => _primaryFailed = true);
            });
            return _shimmerPlaceholder();
          }
          return _letterFallback();
        },
      ),
    );
  }

  Widget _shimmerPlaceholder() {
    return Container(
      width: widget.size,
      height: widget.size,
      decoration: BoxDecoration(
        color: AppColors.bgTertiary,
        borderRadius: BorderRadius.circular(widget.borderRadius),
      ),
    );
  }

  Widget _letterFallback() {
    final letter = widget.symbol.isNotEmpty ? widget.symbol[0] : '?';
    // สี hash จาก symbol
    final hue = (widget.symbol.hashCode % 360).abs().toDouble();
    final color = HSLColor.fromAHSL(1, hue, 0.6, 0.5).toColor();

    return Container(
      width: widget.size,
      height: widget.size,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(widget.borderRadius),
      ),
      child: Center(
        child: Text(
          letter,
          style: GoogleFonts.inter(
            fontSize: widget.size * 0.4,
            fontWeight: FontWeight.w700,
            color: color,
          ),
        ),
      ),
    );
  }
}
