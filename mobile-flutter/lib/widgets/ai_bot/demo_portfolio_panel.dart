/// TPIX TRADE — AI Demo Portfolio
/// พอร์ตทดลองของบอท: เงินสด · ของที่ถือ · กำไรที่ปิดแล้ว · กำไรที่ยังไม่ปิด · รวม
///
/// ⚠️ บทเรียนที่ห้ามทำซ้ำ (จากฝั่งเว็บ):
///   1) เคยตีมูลค่าของที่ถือด้วย "ราคาทุน" → ซื้อที่ $100 ราคาร่วงเหลือ $50
///      พอร์ตยังโชว์เต็ม $100 ขาดทุนโผล่ก็ต่อเมื่อบอทยอมปิดไม้ ซึ่งอาจไม่เกิดเลย
///   2) เคยโชว์เฉพาะกำไรที่ปิดแล้ว → ไม้ที่กำลังติดลบหายไปจากสายตาทั้งก้อน
///      ผู้ใช้ตัดสินใจ "เช่าจริงไหม" จากตัวเลขที่สวยเกินจริง
///   ⇒ แผงนี้โชว์ทั้งสองก้อน **และผลรวม** เสมอ และขึ้นป้ายเตือนทุกครั้งที่มีไม้
///      ซึ่งยังตีราคาไม่ได้ (`priced: false`) เพราะกำไรลอยของไม้นั้นยังไม่จริง
///
/// ข้อมูลมาจาก GET /api/v1/ai-bot/demo — แผงนี้ไม่ยิง API เอง
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../models/ai_bot_models.dart';
import '../../providers/accent_provider.dart';
import '../common/glass_card.dart';
import 'monitor_atoms.dart';
import 'monitor_format.dart';

class AiDemoPortfolioPanel extends StatefulWidget {
  final AiBotDemo? demo;
  final bool loading;
  final bool refreshing;

  /// ข้อความความล้มเหลวที่แปลแล้วจากผู้เรียก
  final String? errorMessage;
  final VoidCallback? onRetry;
  final VoidCallback? onRefresh;

  /// ล้างพอร์ตทดลอง — ผู้เรียกต้องถามยืนยันเองก่อน (ลบประวัติไม้ถาวร)
  final VoidCallback? onReset;
  final bool resetting;

  /// แปลงรหัสกลยุทธ์เป็นชื่อที่อ่านได้ (จาก catalog) — ไม่ส่งมาก็ใช้รหัสดิบ
  final String Function(String code)? strategyLabelOf;

  final GlassVariant variant;
  final EdgeInsetsGeometry? margin;

  const AiDemoPortfolioPanel({
    super.key,
    this.demo,
    this.loading = false,
    this.refreshing = false,
    this.errorMessage,
    this.onRetry,
    this.onRefresh,
    this.onReset,
    this.resetting = false,
    this.strategyLabelOf,
    this.variant = GlassVariant.elevated,
    this.margin,
  });

  @override
  State<AiDemoPortfolioPanel> createState() => _AiDemoPortfolioPanelState();
}

class _AiDemoPortfolioPanelState extends State<AiDemoPortfolioPanel> {
  static const int _tradePreview = 6;
  static const int _positionPreview = 4;

  bool _allTrades = false;
  bool _allPositions = false;

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();

    return MonitorSection(
      icon: Icons.science_rounded,
      title: locale.t('aiTrade.demoTitle'),
      subtitle: locale.t('aiTrade.demoHint'),
      variant: widget.variant,
      margin: widget.margin,
      trailing: MonitorIconButton(
        icon: Icons.refresh_rounded,
        busy: widget.refreshing,
        onTap: widget.onRefresh,
        tooltip: locale.t('aiTrade.marketViewRefresh'),
      ),
      child: _body(context, locale),
    );
  }

  Widget _body(BuildContext context, LocaleProvider locale) {
    final th = locale.isThai;
    final demo = widget.demo;

    if (widget.errorMessage != null && demo == null) {
      return MonitorNotice(
        icon: Icons.cloud_off_rounded,
        title: th ? 'โหลดพอร์ตทดลองไม่สำเร็จ' : 'Could not load the portfolio',
        body: widget.errorMessage,
        actionLabel: widget.onRetry == null ? null : locale.t('common.retry'),
        onAction: widget.onRetry,
        busy: widget.loading,
      );
    }

    if (demo == null) return const MonitorSkeleton(rows: 3, rowHeight: 68);

    final s = demo.summary;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _headline(context, locale, demo),
        const SizedBox(height: 14),

        // เงินสด / ของที่ถือ
        Row(
          children: [
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoBalance'),
                value: Fmt.usd(demo.account.balance),
                icon: Icons.savings_rounded,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoOpenPositions'),
                value: Fmt.usd(s.positionsValue),
                icon: Icons.inventory_2_rounded,
                hint: th
                    ? '${demo.positions.length} ไม้ที่ยังเปิดอยู่'
                    : '${demo.positions.length} open',
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),

        // กำไรที่ปิดแล้ว / ยังไม่ปิด — ต้องอยู่คู่กันเสมอ
        Row(
          children: [
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoPnl'),
                value: Fmt.signedUsd(s.realizedPnl),
                valueColor: pnlColor(s.realizedPnl),
                icon: Icons.check_circle_outline_rounded,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoUnrealized'),
                value: Fmt.signedUsd(s.unrealizedPnl),
                valueColor: pnlColor(s.unrealizedPnl),
                icon: Icons.timelapse_rounded,
                hint: demo.hasUnpriced ? locale.t('aiTrade.unpriced') : null,
              ),
            ),
          ],
        ),

        if (demo.hasUnpriced) ...[
          const SizedBox(height: 12),
          MonitorNotice(
            icon: Icons.help_outline_rounded,
            title:
                th ? 'บางไม้ยังตีราคาไม่ได้' : 'Some positions are not priced',
            body: th
                ? 'ดึงราคาตลาดของบางคู่ไม่ได้ (เช่น TPIX/USDT ที่ไม่มีบนตลาดอ้างอิง) '
                    'ตัวเลขกำไรที่ยังไม่ปิดของไม้เหล่านั้นจึงยังไม่ใช่ของจริง'
                : 'Market prices are unavailable for some pairs (e.g. TPIX/USDT), '
                    'so their open P&L numbers are not real yet.',
          ),
        ],

        const SizedBox(height: 12),
        _statsRow(locale, demo),

        const SizedBox(height: 16),
        _positionsBlock(locale, demo),

        if (demo.portfolios.length > 1) ...[
          const SizedBox(height: 16),
          _bucketsBlock(locale, demo),
        ],

        const SizedBox(height: 16),
        _tradesBlock(locale, demo),

        const SizedBox(height: 14),
        Text(
          locale.tp('aiTrade.demoAssumptions', {
            'fee': Fmt.number(demo.account.feeRate, digits: 2),
            'slippage': demo.account.slippageBps,
          }),
          style: GoogleFonts.inter(
            fontSize: 10.5,
            color: AppColors.textTertiary,
            height: 1.45,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          th
              ? 'ต้นทุนไปกลับราว ${demo.account.roundTripBps.toStringAsFixed(0)} bps ต่อรอบ — '
                  'กลยุทธ์ที่เข้าออกถี่ต้องตั้งเป้ากำไรต่อไม้สูงกว่านี้ ไม่งั้นขาดทุนแม้ทายถูก'
              : 'About ${demo.account.roundTripBps.toStringAsFixed(0)} bps round trip — '
                  'fast strategies must target more than that, or they lose even when right.',
          style: GoogleFonts.inter(
            fontSize: 10.5,
            color: AppColors.textTertiary,
            height: 1.45,
          ),
        ),

        if (widget.onReset != null) ...[
          const SizedBox(height: 12),
          _resetRow(locale, demo),
        ],
      ],
    );
  }

  // ── ยอดรวม + ผลรวมสุทธิ ──────────────────────────────────

  Widget _headline(
    BuildContext context,
    LocaleProvider locale,
    AiBotDemo demo,
  ) {
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    final total = demo.totalPnl;
    final totalPct = demo.account.startingBalance > 0
        ? (total / demo.account.startingBalance) * 100
        : 0.0;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            locale.t('aiTrade.demoEquity'),
            style: GoogleFonts.inter(
              fontSize: 10.5,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 1.2,
            ),
          ),
          const SizedBox(height: 6),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(child: _BigAmount(value: demo.equity)),
              const SizedBox(width: 10),
              MonitorPill(
                label: Fmt.signedPct(totalPct),
                icon: total >= 0
                    ? Icons.north_east_rounded
                    : Icons.south_east_rounded,
                tone: total >= 0 ? MonitorTone.up : MonitorTone.down,
                fontSize: 11,
              ),
            ],
          ),
          const SizedBox(height: 10),
          const Divider(color: AppColors.divider, height: 1),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      locale.t('aiTrade.demoTotalPnl'),
                      style: GoogleFonts.inter(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textSecondary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      th
                          ? 'รวมไม้ที่ยังไม่ปิดแล้ว — ตัวเลขนี้คือของจริงที่ควรใช้ตัดสินใจ'
                          : 'Includes open positions — this is the number to judge by',
                      style: GoogleFonts.inter(
                        fontSize: 10,
                        color: AppColors.textTertiary,
                        height: 1.35,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Text(
                Fmt.signedUsd(total),
                style: AppTheme.mono(
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: pnlColor(total),
                ),
                maxLines: 1,
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            '${locale.t('aiTrade.demoStarting')} '
            '${Fmt.usd(demo.account.startingBalance, digits: 0)}',
            style: AppTheme.mono(fontSize: 10.5, color: AppColors.textTertiary),
          ),
        ],
      ),
    );
  }

  Widget _statsRow(LocaleProvider locale, AiBotDemo demo) {
    final s = demo.summary;
    return Row(
      children: [
        Expanded(
          child: MonitorStat(
            label: locale.t('aiTrade.demoTrades'),
            value: Fmt.intGrouped(s.tradeCount),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: MonitorStat(
            label: locale.t('aiTrade.demoClosed'),
            value: Fmt.intGrouped(s.closedCount),
            hint: s.closedCount > 0 ? '${s.wins}W / ${s.losses}L' : null,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: MonitorStat(
            // null = ยังไม่มีไม้ปิด → "—" ไม่ใช่ 0%
            label: locale.t('aiTrade.demoWinRate'),
            value: Fmt.pct(s.winRate),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: MonitorStat(
            label: locale.t('aiTrade.demoFees'),
            value: Fmt.usd(s.totalFees),
          ),
        ),
      ],
    );
  }

  // ── ของที่ถืออยู่ ────────────────────────────────────────

  Widget _positionsBlock(LocaleProvider locale, AiBotDemo demo) {
    final th = locale.isThai;
    final all = demo.positions;
    final shown = _allPositions ? all : all.take(_positionPreview).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _blockHeader(locale.t('aiTrade.demoOpenPositions'), count: all.length),
        const SizedBox(height: 10),
        if (all.isEmpty)
          MonitorEmpty(
            icon: Icons.inbox_rounded,
            title: locale.t('aiTrade.demoNoPositions'),
            body: th
                ? 'บอทจะเข้าไม้เมื่อเงื่อนไขของกลยุทธ์ครบและด่านความเสี่ยงเปิดทาง'
                : 'The bot enters only when its rules line up and the risk gate allows it',
          )
        else ...[
          for (final p in shown) _PositionRow(position: p, locale: locale),
          if (all.length > shown.length)
            _moreButton(
              th
                  ? 'ดูอีก ${all.length - shown.length} ไม้'
                  : 'Show ${all.length - shown.length} more',
              () => setState(() => _allPositions = true),
            ),
        ],
      ],
    );
  }

  // ── ผลแยกรายกลยุทธ์ ─────────────────────────────────────

  Widget _bucketsBlock(LocaleProvider locale, AiBotDemo demo) {
    final buckets = [...demo.portfolios]..sort((a, b) => b.pnl.compareTo(a.pnl));
    final peak =
        buckets.map((b) => b.pnl.abs()).fold<double>(0, (a, b) => a > b ? a : b);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _blockHeader(
          locale.t('aiTrade.demoPortfolios'),
          hint: locale.t('aiTrade.demoPortfoliosSub'),
        ),
        const SizedBox(height: 10),
        for (final b in buckets)
          MonitorRow(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        _strategyName(locale, b.strategy),
                        style: GoogleFonts.inter(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      Fmt.signedUsd(b.pnl),
                      style: AppTheme.mono(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: pnlColor(b.pnl),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                MonitorBar(
                  value: peak <= 0 ? 0 : b.pnl.abs() / peak,
                  height: 5,
                  color: b.pnl == 0 ? null : pnlColor(b.pnl),
                ),
                const SizedBox(height: 7),
                Text(
                  '${locale.t('aiTrade.demoBalance')} ${Fmt.usd(b.balance)} · '
                  '${locale.t('aiTrade.demoStarting')} '
                  '${Fmt.usd(b.startingBalance, digits: 0)} · '
                  '${Fmt.signedPct(b.pnlPct)}',
                  style:
                      AppTheme.mono(fontSize: 10, color: AppColors.textTertiary),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
      ],
    );
  }

  // ── ไม้ล่าสุด ────────────────────────────────────────────

  Widget _tradesBlock(LocaleProvider locale, AiBotDemo demo) {
    final th = locale.isThai;
    final all = demo.trades;
    final shown = _allTrades ? all : all.take(_tradePreview).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _blockHeader(
          locale.t('aiTrade.tradeLog'),
          count: all.isEmpty ? null : all.length,
          hint: th ? 'ส่งมาสูงสุด 60 ไม้ล่าสุด' : 'last 60 trades',
        ),
        const SizedBox(height: 10),
        if (all.isEmpty)
          MonitorEmpty(
            icon: Icons.receipt_long_rounded,
            title: locale.t('aiTrade.tradeLogEmpty'),
            body: locale.t('aiTrade.demoStartHint'),
          )
        else ...[
          for (final t in shown) _TradeRow(trade: t, locale: locale),
          if (all.length > shown.length)
            _moreButton(
              th
                  ? 'ดูอีก ${all.length - shown.length} ไม้'
                  : 'Show ${all.length - shown.length} more',
              () => setState(() => _allTrades = true),
            ),
        ],
      ],
    );
  }

  // ── ล้างพอร์ต ───────────────────────────────────────────

  Widget _resetRow(LocaleProvider locale, AiBotDemo demo) {
    final left = demo.account.resetsLeft;
    final canReset = left > 0 && !widget.resetting;

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                left > 0
                    ? locale.tp('aiTrade.demoResetsLeft', {'count': left})
                    : locale.t('aiTrade.demoResetNone'),
                style: GoogleFonts.inter(
                  fontSize: 10.5,
                  color: AppColors.textTertiary,
                  height: 1.4,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                locale.t('aiTrade.demoResetWarnStats'),
                style: GoogleFonts.inter(
                  fontSize: 10,
                  color: AppColors.textTertiary,
                  height: 1.4,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 10),
        OutlinedButton(
          onPressed: canReset ? widget.onReset : null,
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            side: BorderSide(
              color: AppColors.tradingRed.withValues(alpha: 0.35),
            ),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: widget.resetting
              ? const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor:
                        AlwaysStoppedAnimation<Color>(AppColors.tradingRed),
                  ),
                )
              : Text(
                  locale.t('aiTrade.demoReset'),
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: canReset
                        ? AppColors.tradingRed
                        : AppColors.textDisabled,
                  ),
                ),
        ),
      ],
    );
  }

  // ── ชิ้นส่วนย่อย ────────────────────────────────────────

  Widget _blockHeader(String title, {int? count, String? hint}) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Text(
          title,
          style: GoogleFonts.inter(
            fontSize: 12.5,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        if (count != null) ...[
          const SizedBox(width: 6),
          Text(
            '($count)',
            style: AppTheme.mono(fontSize: 11, color: AppColors.textTertiary),
          ),
        ],
        const Spacer(),
        if (hint != null)
          Flexible(
            child: Text(
              hint,
              textAlign: TextAlign.right,
              style: GoogleFonts.inter(
                fontSize: 10,
                color: AppColors.textTertiary,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
      ],
    );
  }

  Widget _moreButton(String label, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(top: 2),
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Center(
            child: Text(
              label,
              style: GoogleFonts.inter(
                fontSize: 11.5,
                fontWeight: FontWeight.w700,
                color: AppColors.textSecondary,
              ),
            ),
          ),
        ),
      ),
    );
  }

  String _strategyName(LocaleProvider locale, String? code) {
    if (code == null || code.isEmpty) {
      return locale.t('aiTrade.demoPortfolioLegacy');
    }
    return widget.strategyLabelOf?.call(code) ?? code;
  }
}

// ── ยอดใหญ่ (ทศนิยมจางกว่า) ─────────────────────────────

class _BigAmount extends StatelessWidget {
  final double value;

  const _BigAmount({required this.value});

  @override
  Widget build(BuildContext context) {
    final s = Fmt.number(value);
    final dot = s.indexOf('.');
    final intPart = dot < 0 ? s : s.substring(0, dot);
    final decPart = dot < 0 ? '' : s.substring(dot);

    return FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: [
          Text(
            '\$$intPart',
            style: GoogleFonts.jetBrainsMono(
              fontSize: 28,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
              letterSpacing: -0.5,
            ),
          ),
          Text(
            decPart,
            style: GoogleFonts.jetBrainsMono(
              fontSize: 18,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }
}

// ── หนึ่งไม้ที่ถืออยู่ ────────────────────────────────────

class _PositionRow extends StatelessWidget {
  final DemoPosition position;
  final LocaleProvider locale;

  const _PositionRow({required this.position, required this.locale});

  @override
  Widget build(BuildContext context) {
    final p = position;
    final th = locale.isThai;

    return MonitorRow(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Row(
                  children: [
                    Flexible(
                      child: Text(
                        p.pair.isEmpty ? Fmt.dash : p.pair,
                        style: AppTheme.mono(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (p.entryCount > 1) ...[
                      const SizedBox(width: 6),
                      MonitorPill(
                        label:
                            '${locale.t('aiTrade.entryCount')} ${p.entryCount}',
                        fontSize: 9,
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              // ตีราคาไม่ได้ → ห้ามโชว์ 0.00 ให้เข้าใจผิดว่าเสมอตัว
              if (!p.priced)
                MonitorPill(
                  label: locale.t('aiTrade.unpriced'),
                  icon: Icons.help_outline_rounded,
                  tone: MonitorTone.gold,
                  fontSize: 9,
                )
              else
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      Fmt.signedUsd(p.unrealizedPnl),
                      style: AppTheme.mono(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: pnlColor(p.unrealizedPnl),
                      ),
                      maxLines: 1,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      Fmt.signedPct(p.unrealizedPct),
                      style: AppTheme.mono(
                        fontSize: 10,
                        fontWeight: FontWeight.w600,
                        color: pnlColor(p.unrealizedPnl),
                      ),
                      maxLines: 1,
                    ),
                  ],
                ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            [
              '${th ? 'จำนวน' : 'Qty'} ${Fmt.amount(p.quantity)}',
              '${locale.t('aiTrade.entryPrice')} ${Fmt.price(p.entryPrice)}',
              if (p.priced)
                '${th ? 'ราคาตอนนี้' : 'Now'} ${Fmt.price(p.currentPrice)}',
            ].join(' · '),
            style: AppTheme.mono(fontSize: 10, color: AppColors.textTertiary),
            maxLines: 2,
          ),
          const SizedBox(height: 5),
          Row(
            children: [
              Expanded(
                child: Text(
                  [
                    if (p.botName != null && p.botName!.isNotEmpty) p.botName!,
                    '${locale.t('aiTrade.costBasis')} ${Fmt.usd(p.costBasis)}',
                  ].join(' · '),
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    color: AppColors.textTertiary,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (p.openedAt != null) ...[
                const SizedBox(width: 8),
                Text(
                  locale.agoText(p.openedAt),
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    color: AppColors.textTertiary,
                  ),
                  maxLines: 1,
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

// ── หนึ่งไม้ในประวัติ ─────────────────────────────────────

class _TradeRow extends StatelessWidget {
  final DemoTrade trade;
  final LocaleProvider locale;

  const _TradeRow({required this.trade, required this.locale});

  @override
  Widget build(BuildContext context) {
    final t = trade;
    final th = locale.isThai;

    return MonitorRow(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              MonitorPill(
                label: t.isBuy
                    ? locale.t('aiTrade.tradeBuy')
                    : locale.t('aiTrade.tradeSell'),
                icon: t.isBuy
                    ? Icons.arrow_downward_rounded
                    : Icons.arrow_upward_rounded,
                tone: t.isBuy ? MonitorTone.up : MonitorTone.down,
                fontSize: 9.5,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  t.pair.isEmpty ? Fmt.dash : t.pair,
                  style: AppTheme.mono(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 8),
              // ไม้ซื้อยังไม่มีกำไรขาดทุน (null) → ขีด ไม่ใช่ 0
              Text(
                t.isClosed ? Fmt.signedUsd(t.realizedPnl) : Fmt.dash,
                style: AppTheme.mono(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: t.isClosed
                      ? pnlColor(t.realizedPnl)
                      : AppColors.textTertiary,
                ),
                maxLines: 1,
              ),
            ],
          ),
          if (t.reason.trim().isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              t.reason,
              style: GoogleFonts.inter(
                fontSize: 11,
                height: 1.4,
                color: AppColors.textSecondary,
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],
          const SizedBox(height: 6),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${Fmt.amount(t.quantity)} @ ${Fmt.price(t.price)} · '
                  '${th ? 'ค่าธรรมเนียม' : 'fee'} ${Fmt.usd(t.fee)}',
                  style:
                      AppTheme.mono(fontSize: 10, color: AppColors.textTertiary),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 8),
              Text(
                Fmt.dateTime(t.createdAt, th),
                style:
                    AppTheme.mono(fontSize: 10, color: AppColors.textTertiary),
                maxLines: 1,
              ),
            ],
          ),
        ],
      ),
    );
  }
}
