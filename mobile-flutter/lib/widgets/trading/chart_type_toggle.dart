/// TPIX TRADE — Chart Type Toggle
/// สลับระหว่าง Candlestick กับ Line chart
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import '../../core/theme/app_colors.dart';

class ChartTypeToggle extends StatelessWidget {
  final String selected; // 'candle' | 'line'
  final ValueChanged<String> onChanged;

  const ChartTypeToggle({
    super.key,
    required this.selected,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgTertiary,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _buildButton(
            icon: Icons.candlestick_chart_rounded,
            value: 'candle',
          ),
          _buildButton(
            icon: Icons.show_chart_rounded,
            value: 'line',
          ),
        ],
      ),
    );
  }

  Widget _buildButton({required IconData icon, required String value}) {
    final isActive = selected == value;
    return GestureDetector(
      onTap: () => onChanged(value),
      child: Container(
        padding: const EdgeInsets.all(6),
        decoration: BoxDecoration(
          color: isActive
              ? AppColors.brandCyan.withValues(alpha: 0.18)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(6),
        ),
        child: Icon(
          icon,
          size: 16,
          color: isActive ? AppColors.brandCyan : AppColors.textTertiary,
        ),
      ),
    );
  }
}
