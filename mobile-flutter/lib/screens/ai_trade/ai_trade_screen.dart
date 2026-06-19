/// TPIX TRADE — AI Trade Screen (Luxury Dark / Gilded Metal)
/// A PREVIEW / BETA surface for the upcoming "TPIX AI" trading copilot.
///
/// IMPORTANT — there is NO AI backend yet. Everything here is an illustrative
/// preview: signals, confidences and "expected move" figures are obvious
/// sample values, never real PnL or executed trades. The pair symbols may be
/// pulled from the live MarketProvider so the chips look real, but the AI
/// verdicts attached to them are sample data. Nothing on this screen executes
/// an order — every action shows a "coming soon" snackbar.
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../core/locale/locale_provider.dart';
import '../../providers/accent_provider.dart';
import '../../providers/market_provider.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/coin_chip.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';

class AiTradeScreen extends StatefulWidget {
  const AiTradeScreen({super.key});

  @override
  State<AiTradeScreen> createState() => _AiTradeScreenState();
}

class _AiTradeScreenState extends State<AiTradeScreen> {
  // Local-only UI state (preview surface — nothing is persisted/executed).
  bool _autoExecute = false;
  int _riskIndex = 1; // 0 = Conservative, 1 = Balanced, 2 = Aggressive

  void _comingSoon(LocaleProvider locale) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          locale.isThai
              ? 'TPIX AI กำลังจะมาเร็ว ๆ นี้ (พรีวิว)'
              : 'TPIX AI is coming soon (preview)',
        ),
        duration: const Duration(milliseconds: 1400),
      ),
    );
  }

  /// Build the sample signals. Pair symbols come from live market data when
  /// available, but the action / confidence / move are illustrative samples.
  List<_AiSignal> _sampleSignals(MarketProvider market) {
    // Fixed sample verdicts (preview). We map them onto whatever symbols the
    // market currently has, falling back to canonical majors when offline.
    const blueprint = [
      _SignalSeed('BTC', _AiAction.strongBuy, 87, '+4.2%'),
      _SignalSeed('ETH', _AiAction.buy, 72, '+2.6%'),
      _SignalSeed('SOL', _AiAction.hold, 55, '±0.0%'),
      _SignalSeed('BNB', _AiAction.sell, 64, '-1.8%'),
    ];

    // Live market symbols that we have a real ticker for (lets the gold coin
    // chips render true logos). Verdicts stay illustrative regardless.
    final live = market.allTickers.map((t) => t.baseAsset).toSet();
    final ordered = [
      ...blueprint.where((s) => live.contains(s.symbol)),
      ...blueprint.where((s) => !live.contains(s.symbol)),
    ];
    return ordered
        .map((s) => _AiSignal(
              symbol: s.symbol,
              pair: '${s.symbol}/USDT',
              action: s.action,
              confidence: s.confidence,
              expectedMove: s.expectedMove,
            ))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final market = context.watch<MarketProvider>();
    final th = locale.isThai;

    final signals = _sampleSignals(market);
    final top = signals.first;

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(child: _buildHeader(th)),
              SliverToBoxAdapter(child: _buildPreviewNote(th)),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 6, 18, 4),
                  child: _AiEngineCard(pairCount: signals.length, th: th),
                ),
              ),
              SliverToBoxAdapter(
                child: _buildSectionTitle(
                  th ? 'สัญญาณเด่น' : 'Top Signal',
                  Icons.auto_awesome_rounded,
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 4),
                  child: _TopSignalCard(
                    signal: top,
                    th: th,
                    onExecute: () => _comingSoon(locale),
                    onBookmark: () => _comingSoon(locale),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: _buildSectionTitle(
                  th ? 'ทำรายการอัตโนมัติ' : 'Auto-Execute',
                  Icons.bolt_rounded,
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 4),
                  child: _AutoExecuteCard(
                    th: th,
                    enabled: _autoExecute,
                    riskIndex: _riskIndex,
                    // Preview only — reflect the toggle but make clear nothing
                    // auto-executes yet (no AI trading backend).
                    onToggle: (v) {
                      setState(() => _autoExecute = v);
                      if (v) _comingSoon(locale);
                    },
                    onRisk: (i) => setState(() => _riskIndex = i),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: _buildSectionTitle(
                  th ? 'สัญญาณสด' : 'Live Signals',
                  Icons.podcasts_rounded,
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 0),
                  child: Column(
                    children: [
                      for (final s in signals)
                        _LiveSignalRow(
                          signal: s,
                          th: th,
                          onTap: () => _comingSoon(locale),
                        ),
                    ],
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 10, 18, 0),
                  child: _AskAiBar(th: th, onSend: () => _comingSoon(locale)),
                ),
              ),
              const SliverToBoxAdapter(child: SizedBox(height: 110)),
            ],
          ),
        ),
      ),
    );
  }

  // ── Header ──

  Widget _buildHeader(bool th) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 6),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              gradient: accent.goldGradient,
              borderRadius: BorderRadius.circular(13),
              boxShadow: [
                BoxShadow(
                  color: accent.goldGlow.withValues(alpha: 0.4),
                  blurRadius: 16,
                  spreadRadius: -4,
                ),
              ],
            ),
            child: const Icon(Icons.auto_awesome_rounded,
                color: AppColors.goldTextOn, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  th ? 'เทรดด้วย AI' : 'AI Trade',
                  style: GoogleFonts.inter(
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    letterSpacing: -0.2,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  'POWERED BY TPIX AI',
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textTertiary,
                    letterSpacing: 1.4,
                  ),
                ),
              ],
            ),
          ),
          const _BetaPill(),
        ],
      ),
    );
  }

  Widget _buildPreviewNote(bool th) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 0),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded,
              size: 13, color: AppColors.textTertiary),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              th
                  ? 'พรีวิว — สัญญาณทั้งหมดเป็นตัวอย่างเพื่อการสาธิต'
                  : 'Preview — signals are illustrative',
              style: GoogleFonts.inter(
                fontSize: 10.5,
                fontWeight: FontWeight.w500,
                color: AppColors.textTertiary,
                letterSpacing: 0.2,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSectionTitle(String title, IconData icon) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 10),
      child: Row(
        children: [
          Icon(icon, size: 16, color: AppColors.gold2),
          const SizedBox(width: 8),
          Text(
            title,
            style: GoogleFonts.inter(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
              letterSpacing: -0.2,
            ),
          ),
        ],
      ),
    );
  }
}

// ── BETA pill ──

class _BetaPill extends StatelessWidget {
  const _BetaPill();

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        gradient: accent.goldGradient,
        borderRadius: BorderRadius.circular(999),
        boxShadow: [
          BoxShadow(
            color: accent.goldGlow.withValues(alpha: 0.35),
            blurRadius: 12,
            spreadRadius: -4,
          ),
        ],
      ),
      child: Text(
        'BETA',
        style: GoogleFonts.inter(
          fontSize: 10,
          fontWeight: FontWeight.w800,
          color: AppColors.goldTextOn,
          letterSpacing: 1.2,
        ),
      ),
    );
  }
}

// ── AI Engine card (pulsing gold orb) ──

class _AiEngineCard extends StatefulWidget {
  final int pairCount;
  final bool th;
  const _AiEngineCard({required this.pairCount, required this.th});

  @override
  State<_AiEngineCard> createState() => _AiEngineCardState();
}

class _AiEngineCardState extends State<_AiEngineCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2200),
    );
  }

  void _syncMotion(bool reduceMotion) {
    if (reduceMotion) {
      if (_pulse.isAnimating) _pulse.stop();
      _pulse.value = 0.5; // static mid-glow
    } else if (!_pulse.isAnimating) {
      _pulse.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    _syncMotion(accent.reduceMotion);

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          AnimatedBuilder(
            animation: _pulse,
            builder: (_, child) {
              final t = _pulse.value; // 0..1
              final glow = 0.25 + t * 0.45;
              final ring = 6.0 + t * 10.0;
              return Container(
                width: 58,
                height: 58,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: accent.goldGradient,
                  boxShadow: [
                    BoxShadow(
                      color: accent.goldGlow.withValues(alpha: glow),
                      blurRadius: 18 + ring,
                      spreadRadius: ring * 0.4,
                    ),
                  ],
                ),
                child: child,
              );
            },
            child: const Icon(Icons.psychology_rounded,
                color: AppColors.goldTextOn, size: 28),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  widget.th ? 'เครื่องยนต์ TPIX AI' : 'TPIX AI Engine',
                  style: GoogleFonts.inter(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    letterSpacing: -0.2,
                  ),
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Container(
                      width: 7,
                      height: 7,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: AppColors.tradingGreen,
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.tradingGreen,
                            blurRadius: 6,
                            spreadRadius: 0.5,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 6),
                    Flexible(
                      child: Text(
                        widget.th
                            ? 'กำลังวิเคราะห์ ${widget.pairCount} คู่ · พรีวิว'
                            : 'Analyzing ${widget.pairCount} pairs · preview',
                        style: GoogleFonts.inter(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textSecondary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ── Top signal card ──

class _TopSignalCard extends StatelessWidget {
  final _AiSignal signal;
  final bool th;
  final VoidCallback onExecute;
  final VoidCallback onBookmark;

  const _TopSignalCard({
    required this.signal,
    required this.th,
    required this.onExecute,
    required this.onBookmark,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    // Green-tinted border (signal is bullish): trading-green reserved use.
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: AppGradients.glassCard,
        border: Border.all(color: AppColors.tradingGreen.withValues(alpha: 0.45), width: 1.4),
        boxShadow: [
          BoxShadow(
            color: AppColors.tradingGreen.withValues(alpha: 0.12),
            blurRadius: 24,
            spreadRadius: -8,
          ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CoinChip(symbol: signal.symbol, size: 40),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      signal.pair,
                      style: GoogleFonts.inter(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      signal.action.label(th),
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                        color: signal.action.color,
                        letterSpacing: 0.4,
                      ),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    th ? 'การเคลื่อนไหวคาด' : 'Exp. move',
                    style: GoogleFonts.inter(
                      fontSize: 9.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textTertiary,
                      letterSpacing: 0.4,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    signal.expectedMove,
                    style: AppTheme.mono(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: signal.action.color,
                    ),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Text(
                th ? 'ความมั่นใจ' : 'Confidence',
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
              const Spacer(),
              Text(
                '${signal.confidence}%',
                style: AppTheme.mono(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: accent.g1,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          _ConfidenceBar(value: signal.confidence / 100),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final r in signal.action.reasoning(th)) _ReasonChip(label: r),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: GradientButton(
                  text: th ? 'ทำตามสัญญาณ' : 'Execute Signal',
                  variant: ButtonVariant.buy,
                  icon: Icons.flash_on_rounded,
                  height: 48,
                  onPressed: onExecute,
                ),
              ),
              const SizedBox(width: 10),
              _SquareIconButton(
                icon: Icons.bookmark_border_rounded,
                onTap: onBookmark,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ConfidenceBar extends StatelessWidget {
  final double value; // 0..1
  const _ConfidenceBar({required this.value});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return LayoutBuilder(
      builder: (_, c) {
        final w = (c.maxWidth * value.clamp(0.0, 1.0));
        return Stack(
          children: [
            Container(
              height: 8,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                color: AppColors.bgInputStrong,
              ),
            ),
            Container(
              height: 8,
              width: w,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                gradient: accent.goldGradient,
                boxShadow: [
                  BoxShadow(
                    color: accent.goldGlow.withValues(alpha: 0.4),
                    blurRadius: 8,
                    spreadRadius: -2,
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}

class _ReasonChip extends StatelessWidget {
  final String label;
  const _ReasonChip({required this.label});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: accent.g1,
        ),
      ),
    );
  }
}

class _SquareIconButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;
  const _SquareIconButton({required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: 48,
        height: 48,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          color: AppColors.bgCard,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: Icon(icon, color: AppColors.textSecondary, size: 20),
      ),
    );
  }
}

// ── Auto-Execute card ──

class _AutoExecuteCard extends StatelessWidget {
  final bool th;
  final bool enabled;
  final int riskIndex;
  final ValueChanged<bool> onToggle;
  final ValueChanged<int> onRisk;

  const _AutoExecuteCard({
    required this.th,
    required this.enabled,
    required this.riskIndex,
    required this.onToggle,
    required this.onRisk,
  });

  @override
  Widget build(BuildContext context) {
    final risks = th
        ? const ['อนุรักษ์', 'สมดุล', 'รุกหนัก']
        : const ['Conservative', 'Balanced', 'Aggressive'];

    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 20,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      th ? 'ทำรายการอัตโนมัติ' : 'Auto-Execute',
                      style: GoogleFonts.inter(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      th
                          ? 'ให้ AI ทำตามสัญญาณให้อัตโนมัติ (พรีวิว)'
                          : 'Let AI act on signals automatically (preview)',
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        fontWeight: FontWeight.w500,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              _GoldSwitch(value: enabled, onChanged: onToggle),
            ],
          ),
          const SizedBox(height: 16),
          Text(
            th ? 'ระดับความเสี่ยง' : 'RISK LEVEL',
            style: GoogleFonts.inter(
              fontSize: 10,
              fontWeight: FontWeight.w700,
              color: AppColors.textTertiary,
              letterSpacing: 1.2,
            ),
          ),
          const SizedBox(height: 8),
          _RiskSegmented(
            labels: risks,
            selected: riskIndex,
            onSelect: onRisk,
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              const Icon(Icons.shield_outlined,
                  size: 13, color: AppColors.textTertiary),
              const SizedBox(width: 6),
              Text(
                th ? 'สูงสุดต่อรายการ' : 'Max per trade',
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary,
                ),
              ),
              const Spacer(),
              Text(
                '250 TPIX',
                style: AppTheme.mono(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: AppColors.gold2,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _GoldSwitch extends StatelessWidget {
  final bool value;
  final ValueChanged<bool> onChanged;
  const _GoldSwitch({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final reduceMotion = accent.reduceMotion;
    return GestureDetector(
      onTap: () => onChanged(!value),
      behavior: HitTestBehavior.opaque,
      child: AnimatedContainer(
        duration: reduceMotion ? Duration.zero : const Duration(milliseconds: 180),
        width: 50,
        height: 28,
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          gradient: value ? accent.goldGradient : null,
          color: value ? null : AppColors.bgInputStrong,
          border: Border.all(
            color: value ? accent.goldBorder : AppColors.bgCardBorder,
            width: 1,
          ),
        ),
        child: AnimatedAlign(
          duration:
              reduceMotion ? Duration.zero : const Duration(milliseconds: 180),
          alignment: value ? Alignment.centerRight : Alignment.centerLeft,
          child: Container(
            width: 22,
            height: 22,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: value ? AppColors.goldTextOn : AppColors.textSecondary,
            ),
          ),
        ),
      ),
    );
  }
}

class _RiskSegmented extends StatelessWidget {
  final List<String> labels;
  final int selected;
  final ValueChanged<int> onSelect;

  const _RiskSegmented({
    required this.labels,
    required this.selected,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        color: AppColors.bgInputStrong,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          for (int i = 0; i < labels.length; i++)
            Expanded(
              child: GestureDetector(
                onTap: () => onSelect(i),
                behavior: HitTestBehavior.opaque,
                child: AnimatedContainer(
                  duration: accent.reduceMotion
                      ? Duration.zero
                      : const Duration(milliseconds: 180),
                  margin: EdgeInsets.only(right: i == labels.length - 1 ? 0 : 4),
                  padding: const EdgeInsets.symmetric(vertical: 9),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    gradient: i == selected ? accent.goldGradient : null,
                  ),
                  child: Text(
                    labels[i],
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: i == selected
                          ? AppColors.goldTextOn
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

// ── Live signal row ──

class _LiveSignalRow extends StatelessWidget {
  final _AiSignal signal;
  final bool th;
  final VoidCallback onTap;

  const _LiveSignalRow({
    required this.signal,
    required this.th,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: AppGradients.cardSubtle,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: Row(
          children: [
            CoinChip(symbol: signal.symbol, size: 38),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  signal.pair,
                  style: GoogleFonts.inter(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  th
                      ? 'ความมั่นใจ ${signal.confidence}%'
                      : 'Confidence ${signal.confidence}%',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
            const Spacer(),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  signal.action.label(th),
                  style: GoogleFonts.inter(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w800,
                    color: signal.action.color,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  signal.expectedMove,
                  style: AppTheme.mono(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ── Ask AI bar ──

class _AskAiBar extends StatelessWidget {
  final bool th;
  final VoidCallback onSend;
  const _AskAiBar({required this.th, required this.onSend});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: onSend,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          color: AppColors.bgInputStrong,
          border: Border.all(color: accent.goldBorder, width: 1),
        ),
        child: Row(
          children: [
            const Icon(Icons.auto_awesome_rounded,
                size: 16, color: AppColors.textTertiary),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                th ? 'ถาม TPIX AI ได้ทุกเรื่อง…' : 'Ask TPIX AI anything…',
                style: GoogleFonts.inter(
                  fontSize: 13,
                  fontWeight: FontWeight.w500,
                  color: AppColors.textTertiary,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            const SizedBox(width: 8),
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: accent.goldGradient,
                boxShadow: [
                  BoxShadow(
                    color: accent.goldGlow.withValues(alpha: 0.4),
                    blurRadius: 12,
                    spreadRadius: -4,
                  ),
                ],
              ),
              child: const Icon(Icons.arrow_upward_rounded,
                  size: 18, color: AppColors.goldTextOn),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Models (preview sample data) ──

enum _AiAction { strongBuy, buy, hold, sell }

extension _AiActionX on _AiAction {
  Color get color {
    switch (this) {
      case _AiAction.strongBuy:
      case _AiAction.buy:
        return AppColors.tradingGreen;
      case _AiAction.hold:
        return AppColors.gold2;
      case _AiAction.sell:
        return AppColors.tradingRed;
    }
  }

  String label(bool th) {
    switch (this) {
      case _AiAction.strongBuy:
        return th ? 'ซื้อแรง' : 'STRONG BUY';
      case _AiAction.buy:
        return th ? 'ซื้อ' : 'BUY';
      case _AiAction.hold:
        return th ? 'ถือ' : 'HOLD';
      case _AiAction.sell:
        return th ? 'ขาย' : 'SELL';
    }
  }

  // Illustrative reasoning tags (preview only).
  List<String> reasoning(bool th) {
    switch (this) {
      case _AiAction.strongBuy:
        return th
            ? ['โมเมนตัมขาขึ้น', 'วอลุ่มพุ่ง', 'RSI ฟื้นตัว']
            : ['Bullish momentum', 'Volume spike', 'RSI recovery'];
      case _AiAction.buy:
        return th
            ? ['เทรนด์ขึ้น', 'แนวรับแข็ง']
            : ['Uptrend', 'Strong support'];
      case _AiAction.hold:
        return th ? ['ช่วงสะสม', 'รอยืนยัน'] : ['Consolidating', 'Awaiting confirm'];
      case _AiAction.sell:
        return th
            ? ['โมเมนตัมอ่อนตัว', 'ทดสอบแนวต้าน']
            : ['Weakening momentum', 'Resistance test'];
    }
  }
}

class _SignalSeed {
  final String symbol;
  final _AiAction action;
  final int confidence;
  final String expectedMove;
  const _SignalSeed(
      this.symbol, this.action, this.confidence, this.expectedMove);
}

class _AiSignal {
  final String symbol;
  final String pair;
  final _AiAction action;
  final int confidence;
  final String expectedMove;
  const _AiSignal({
    required this.symbol,
    required this.pair,
    required this.action,
    required this.confidence,
    required this.expectedMove,
  });
}
