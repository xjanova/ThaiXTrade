/// TPIX TRADE — Timeframe Selector
/// ปุ่มเลือก timeframe 7 ระดับ: 1m, 5m, 15m, 1H, 4H, 1D, 1W
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme/app_colors.dart';

class TimeframeSelector extends StatelessWidget {
  final String selected;
  final ValueChanged<String> onChanged;

  static const timeframes = ['1m', '5m', '15m', '1h', '4h', '1d', '1w'];
  static const labels = ['1m', '5m', '15m', '1H', '4H', '1D', '1W'];

  const TimeframeSelector({
    super.key,
    required this.selected,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 30,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 4),
        itemCount: timeframes.length,
        separatorBuilder: (_, __) => const SizedBox(width: 4),
        itemBuilder: (_, i) {
          final tf = timeframes[i];
          final isActive = selected == tf;
          return GestureDetector(
            onTap: () => onChanged(tf),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: isActive
                    ? AppColors.brandCyan.withValues(alpha: 0.18)
                    : AppColors.bgTertiary,
                borderRadius: BorderRadius.circular(6),
                border: Border.all(
                  color: isActive
                      ? AppColors.brandCyan.withValues(alpha: 0.4)
                      : Colors.transparent,
                ),
              ),
              child: Center(
                child: Text(
                  labels[i],
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: isActive ? FontWeight.w600 : FontWeight.w400,
                    color: isActive ? AppColors.brandCyan : AppColors.textTertiary,
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
