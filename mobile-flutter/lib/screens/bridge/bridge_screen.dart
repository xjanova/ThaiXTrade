/// TPIX TRADE — Bridge Screen
/// Cross-chain asset bridge — ใช้ /api/v1/fees bridge config
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/config_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';

class BridgeScreen extends StatefulWidget {
  const BridgeScreen({super.key});

  @override
  State<BridgeScreen> createState() => _BridgeScreenState();
}

class _BridgeScreenState extends State<BridgeScreen> {
  final _amountController = TextEditingController();
  int _fromChainId = 4289; // TPIX
  int _toChainId = 56; // BSC

  @override
  void initState() {
    super.initState();
    _amountController.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final config = context.watch<ConfigProvider>();
    final wallet = context.watch<WalletProvider>();
    final fees = config.fees;

    return Scaffold(
      appBar: AppBar(
        title: Text(locale.t('bridge.title')),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: Container(
        decoration: const BoxDecoration(gradient: AppGradients.darkBg),
        child: SafeArea(
          bottom: false,
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                // Bridge disabled warning
                if (fees == null || !fees.bridgeEnabled) ...[
                  _buildDisabledCard(locale),
                  const SizedBox(height: 16),
                ],

                // Bridge form
                GlassCard(
                  variant: GlassVariant.elevated,
                  borderRadius: 16,
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    children: [
                      _buildChainPicker(
                        label: locale.t('bridge.from_chain'),
                        selected: _fromChainId,
                        onSelect: (id) => setState(() {
                          if (id == _toChainId) _toChainId = _fromChainId;
                          _fromChainId = id;
                        }),
                        config: config,
                      ),
                      const SizedBox(height: 8),
                      IconButton(
                        icon: const Icon(Icons.swap_vert_rounded,
                            color: AppColors.brandCyan),
                        onPressed: () => setState(() {
                          final tmp = _fromChainId;
                          _fromChainId = _toChainId;
                          _toChainId = tmp;
                        }),
                      ),
                      _buildChainPicker(
                        label: locale.t('bridge.to_chain'),
                        selected: _toChainId,
                        onSelect: (id) => setState(() {
                          if (id == _fromChainId) _fromChainId = _toChainId;
                          _toChainId = id;
                        }),
                        config: config,
                      ),
                      const SizedBox(height: 16),

                      // Amount input
                      Container(
                        decoration: BoxDecoration(
                          color: AppColors.bgInput,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: AppColors.bgCardBorder),
                        ),
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        child: Row(
                          children: [
                            Text(locale.t('bridge.amount'),
                                style: GoogleFonts.inter(
                                    fontSize: 12,
                                    color: AppColors.textTertiary)),
                            const SizedBox(width: 8),
                            Expanded(
                              child: TextField(
                                controller: _amountController,
                                keyboardType:
                                    const TextInputType.numberWithOptions(
                                        decimal: true),
                                style: AppTheme.mono(fontSize: 14),
                                textAlign: TextAlign.right,
                                decoration: const InputDecoration(
                                  border: InputBorder.none,
                                  isDense: true,
                                  contentPadding:
                                      EdgeInsets.symmetric(vertical: 10),
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Text('TPIX',
                                style: GoogleFonts.inter(
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                    color: AppColors.textSecondary)),
                          ],
                        ),
                      ),

                      const SizedBox(height: 12),

                      // Min/max info
                      if (fees != null) ...[
                        _buildInfoRow(
                          locale.t('bridge.min_amount'),
                          fees.bridgeMinAmount.toStringAsFixed(2),
                        ),
                        _buildInfoRow(
                          locale.t('bridge.max_amount'),
                          fees.bridgeMaxAmount.toStringAsFixed(2),
                        ),
                        _buildInfoRow(
                          locale.t('bridge.fee'),
                          '${fees.bridgeFeePercent.toStringAsFixed(2)}% (min ${fees.bridgeMinFee.toStringAsFixed(2)})',
                        ),
                        _buildInfoRow(
                          locale.t('bridge.estimated_time'),
                          '~${fees.bridgeEstimatedMinutes} ${locale.t('bridge.minutes')}',
                        ),
                      ],

                      const SizedBox(height: 16),

                      GradientButton(
                        text: locale.t('bridge.submit'),
                        onPressed:
                            (fees?.bridgeEnabled == true && wallet.isConnected)
                                ? _submitBridge
                                : null,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildChainPicker({
    required String label,
    required int selected,
    required ValueChanged<int> onSelect,
    required ConfigProvider config,
  }) {
    final chains = config.displayChains.where((c) => c.supported).toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: GoogleFonts.inter(
                fontSize: 11, color: AppColors.textTertiary)),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: chains.map((chain) {
            final isActive = chain.chainId == selected;
            final color = chain.config?.color ?? AppColors.textTertiary;
            return GestureDetector(
              onTap: () => onSelect(chain.chainId),
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: isActive
                      ? color.withValues(alpha: 0.18)
                      : AppColors.bgTertiary,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(
                      color: isActive
                          ? color.withValues(alpha: 0.5)
                          : AppColors.bgCardBorder),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 8,
                      height: 8,
                      decoration:
                          BoxDecoration(color: color, shape: BoxShape.circle),
                    ),
                    const SizedBox(width: 6),
                    Text(chain.shortName,
                        style: GoogleFonts.inter(
                          fontSize: 12,
                          fontWeight:
                              isActive ? FontWeight.w600 : FontWeight.w400,
                          color:
                              isActive ? color : AppColors.textSecondary,
                        )),
                  ],
                ),
              ),
            );
          }).toList(),
        ),
      ],
    );
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Text(label,
              style: GoogleFonts.inter(
                  fontSize: 11, color: AppColors.textTertiary)),
          const Spacer(),
          Text(value,
              style: AppTheme.mono(fontSize: 11, color: AppColors.textPrimary)),
        ],
      ),
    );
  }

  Widget _buildDisabledCard(LocaleProvider locale) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.tradingRed.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.tradingRed.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          const Icon(Icons.warning_amber_rounded,
              color: AppColors.tradingRed, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              locale.t('bridge.disabled'),
              style: GoogleFonts.inter(
                  fontSize: 12,
                  color: AppColors.tradingRed,
                  fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }

  void _submitBridge() {
    final locale = context.read<LocaleProvider>();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(locale.isThai
            ? 'ฟีเจอร์บริดจ์กำลังพัฒนา — ติดตามเร็วๆ นี้'
            : 'Bridge feature coming soon'),
        duration: const Duration(seconds: 2),
      ),
    );
  }
}
