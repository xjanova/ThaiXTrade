/// TPIX TRADE — AI Analytics
/// สรุปผลย้อนหลังของบอทในกระเป๋านี้ — ภาพรวม + แยกตามกลยุทธ์ / คู่เทรด / ระดับความเสี่ยง
///
/// กับดักที่แผงนี้กันไว้ให้แล้ว:
///   • `winRate == null` (ยังไม่มีไม้ปิด) → แสดง "—" ไม่ใช่ 0%
///   • `profitFactor == null` มีสองความหมาย: ยังไม่มีไม้ปิด กับ ยังไม่เคยขาดทุนเลย
///     ทั้งสองกรณีแปลว่า "ตัดสินไม่ได้" ห้ามแปลว่าดีเลิศ
///   • `wins + losses` อาจน้อยกว่า `closed` เพราะไม้ที่กำไร 0 พอดีไม่ถูกนับฝั่งไหน
///   • `maxDrawdown` เป็นบวกเสมอ (ขนาดของการขาดทุน) — ใส่เครื่องหมายลบให้อ่านถูก
///
/// ข้อมูลมาจาก GET /api/v1/ai-bot/analytics — แผงนี้ไม่ยิง API เอง
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

class AiAnalyticsPanel extends StatefulWidget {
  final AiBotAnalytics? analytics;
  final bool loading;
  final bool refreshing;

  /// ข้อความความล้มเหลวที่แปลแล้วจากผู้เรียก
  final String? errorMessage;
  final VoidCallback? onRetry;
  final VoidCallback? onRefresh;

  /// "demo" | "live" — บอกผู้ใช้ว่าสถิตินี้มาจากโหมดไหน
  final String mode;

  /// แปลงรหัสกลยุทธ์เป็นชื่อที่อ่านได้ (จาก catalog)
  final String Function(String code)? strategyLabelOf;

  final GlassVariant variant;
  final EdgeInsetsGeometry? margin;

  const AiAnalyticsPanel({
    super.key,
    this.analytics,
    this.loading = false,
    this.refreshing = false,
    this.errorMessage,
    this.onRetry,
    this.onRefresh,
    this.mode = 'demo',
    this.strategyLabelOf,
    this.variant = GlassVariant.elevated,
    this.margin,
  });

  @override
  State<AiAnalyticsPanel> createState() => _AiAnalyticsPanelState();
}

class _AiAnalyticsPanelState extends State<AiAnalyticsPanel> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();

    return MonitorSection(
      icon: Icons.query_stats_rounded,
      title: locale.t('aiTrade.analyticsTitle'),
      subtitle: locale.t('aiTrade.analyticsSub'),
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
    final a = widget.analytics;

    if (widget.errorMessage != null && a == null) {
      return MonitorNotice(
        icon: Icons.cloud_off_rounded,
        title: th ? 'โหลดสถิติไม่สำเร็จ' : 'Could not load the statistics',
        body: widget.errorMessage,
        actionLabel: widget.onRetry == null ? null : locale.t('common.retry'),
        onAction: widget.onRetry,
        busy: widget.loading,
      );
    }

    if (a == null) return const MonitorSkeleton(rows: 3, rowHeight: 62);

    if (a.isEmpty) {
      return MonitorEmpty(
        icon: Icons.insights_rounded,
        title: locale.t('aiTrade.analyticsEmpty'),
        body: widget.mode == 'live'
            ? (th
                ? 'โหมดจริงยังไม่เคยมีไม้ที่ส่งจริง — ตอนนี้ระบบเสนอเป็นสัญญาณให้ยืนยันเท่านั้น'
                : 'Live mode has no executed trades — it only proposes signals for now')
            : locale.t('aiTrade.demoStartHint'),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _tabs(context, locale),
        const SizedBox(height: 14),
        if (_tab == 0)
          _overall(locale, a.overall)
        else
          _breakdown(locale, _listFor(a)),
      ],
    );
  }

  List<AnalyticsSummary> _listFor(AiBotAnalytics a) {
    switch (_tab) {
      case 1:
        return a.byStrategy;
      case 2:
        return a.byPair;
      default:
        return a.byRisk;
    }
  }

  Widget _tabs(BuildContext context, LocaleProvider locale) {
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    final labels = th
        ? ['ภาพรวม', 'กลยุทธ์', 'คู่เทรด', 'ความเสี่ยง']
        : ['Overall', 'Strategy', 'Pair', 'Risk'];

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
                onTap: () => setState(() => _tab = i),
                behavior: HitTestBehavior.opaque,
                child: AnimatedContainer(
                  duration: accent.reduceMotion
                      ? Duration.zero
                      : const Duration(milliseconds: 180),
                  margin:
                      EdgeInsets.only(right: i == labels.length - 1 ? 0 : 4),
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    gradient: i == _tab ? accent.goldGradient : null,
                  ),
                  child: Text(
                    labels[i],
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: i == _tab
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

  // ── ภาพรวม ──────────────────────────────────────────────

  Widget _overall(LocaleProvider locale, AnalyticsSummary s) {
    final th = locale.isThai;

    // ไม้ที่ปิดแบบเสมอ (กำไร 0 พอดี) ไม่ถูกนับทั้งฝั่งชนะและแพ้
    final ties = s.closed - s.wins - s.losses;

    final pfValue = !s.canJudge
        ? Fmt.dash
        : (s.profitFactorUnknown
            ? Fmt.dash
            : Fmt.number(s.profitFactor!, digits: 2));
    final pfHint = !s.canJudge
        ? locale.t('aiTrade.statUndecided')
        : (s.profitFactorUnknown ? locale.t('aiTrade.statNoLoss') : null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoPnl'),
                value: Fmt.signedUsd(s.realizedPnl),
                valueColor: pnlColor(s.realizedPnl),
                icon: Icons.account_balance_wallet_rounded,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoWinRate'),
                value: Fmt.pct(s.winRate),
                icon: Icons.emoji_events_rounded,
                hint: s.canJudge
                    ? '${s.wins}W / ${s.losses}L'
                        '${ties > 0 ? (th ? ' / เสมอ $ties' : ' / ${ties}T') : ''}'
                    : null,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.demoTrades'),
                value: Fmt.intGrouped(s.trades),
                hint:
                    '${locale.t('aiTrade.demoClosed')} ${Fmt.intGrouped(s.closed)}',
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.statExpectancy'),
                value: s.expectancy == null
                    ? Fmt.dash
                    : Fmt.signedUsd(s.expectancy, digits: 4),
                valueColor: pnlColor(s.expectancy),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: MonitorStat(
                label: locale.t('aiTrade.statProfitFactor'),
                value: pfValue,
                hint: pfHint,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MonitorStat(
                // เซิร์ฟเวอร์ส่งมาเป็นบวกเสมอ — ใส่ลบให้เอง จะได้ไม่อ่านสลับ
                label: locale.t('aiTrade.statMaxDrawdown'),
                value: s.maxDrawdown == null
                    ? Fmt.dash
                    : '-${Fmt.usd(s.maxDrawdown!.abs())}',
                valueColor: (s.maxDrawdown ?? 0) > 0
                    ? AppColors.tradingRed
                    : AppColors.textPrimary,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            color: AppColors.bgInput,
            border: Border.all(color: AppColors.bgCardBorder, width: 1),
          ),
          child: Column(
            children: [
              MonitorKv(
                label: locale.t('aiTrade.statAvgPnl'),
                value:
                    s.avgPnl == null ? Fmt.dash : Fmt.signedUsd(s.avgPnl, digits: 4),
                valueColor: pnlColor(s.avgPnl),
              ),
              MonitorKv(
                label: locale.t('aiTrade.statBestTrade'),
                value: s.bestTrade == null
                    ? Fmt.dash
                    : Fmt.signedUsd(s.bestTrade),
                valueColor: pnlColor(s.bestTrade),
              ),
              MonitorKv(
                label: locale.t('aiTrade.statWorstTrade'),
                value: s.worstTrade == null
                    ? Fmt.dash
                    : Fmt.signedUsd(s.worstTrade),
                valueColor: pnlColor(s.worstTrade),
              ),
              MonitorKv(
                label: locale.t('aiTrade.demoFees'),
                value: Fmt.usd(s.totalFees, digits: 4),
              ),
              MonitorKv(
                label: locale.t('aiTrade.statSlippage'),
                value: Fmt.usd(s.totalSlippage, digits: 4),
              ),
              MonitorKv(
                label: locale.t('aiTrade.statTotalCost'),
                value: Fmt.usd(s.totalCost, digits: 4),
                valueColor: AppColors.textSecondary,
              ),
            ],
          ),
        ),
        if (s.firstTradeAt != null || s.lastTradeAt != null) ...[
          const SizedBox(height: 10),
          Text(
            th
                ? 'เก็บสถิติตั้งแต่ ${Fmt.dateTime(s.firstTradeAt, th)} ถึง ${Fmt.dateTime(s.lastTradeAt, th)}'
                : 'From ${Fmt.dateTime(s.firstTradeAt, th)} to ${Fmt.dateTime(s.lastTradeAt, th)}',
            style: AppTheme.mono(fontSize: 10, color: AppColors.textTertiary),
          ),
        ],
        const SizedBox(height: 8),
        Text(
          th
              ? 'ต้นทุนรวมคือส่วนที่กินกำไรจริง — กลยุทธ์ที่เข้าออกถี่ต้องมีเป้ากำไรต่อไม้สูงกว่าต้นทุนไปกลับ ไม่งั้นขาดทุนแม้ทายถูก'
              : 'Total cost is what actually eats the profit — fast strategies must target more than the round-trip cost, or they lose even when right.',
          style: GoogleFonts.inter(
            fontSize: 10.5,
            color: AppColors.textTertiary,
            height: 1.45,
          ),
        ),
      ],
    );
  }

  // ── แยกตามกลุ่ม ─────────────────────────────────────────

  Widget _breakdown(LocaleProvider locale, List<AnalyticsSummary> rows) {
    final th = locale.isThai;

    if (rows.isEmpty) {
      return MonitorEmpty(
        icon: Icons.donut_large_rounded,
        title: th ? 'ยังไม่มีข้อมูลกลุ่มนี้' : 'No data in this view',
        body: th
            ? 'ต้องมีไม้ที่เทรดแล้วอย่างน้อยหนึ่งไม้ถึงจะแยกกลุ่มได้'
            : 'At least one trade is needed before it can be grouped',
      );
    }

    final peak = rows
        .map((r) => r.realizedPnl.abs())
        .fold<double>(0, (a, b) => a > b ? a : b);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final r in rows)
          MonitorRow(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        _keyLabel(locale, r.key),
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
                      Fmt.signedUsd(r.realizedPnl),
                      style: AppTheme.mono(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: pnlColor(r.realizedPnl),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                MonitorBar(
                  value: peak <= 0 ? 0 : r.realizedPnl.abs() / peak,
                  height: 5,
                  color: r.realizedPnl == 0 ? null : pnlColor(r.realizedPnl),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        [
                          '${th ? 'ไม้' : 'trades'} ${r.trades}',
                          '${th ? 'ปิด' : 'closed'} ${r.closed}',
                          '${th ? 'ชนะ' : 'win'} ${Fmt.pct(r.winRate)}',
                        ].join(' · '),
                        style: AppTheme.mono(
                          fontSize: 10,
                          color: AppColors.textTertiary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '${th ? 'ต้นทุน' : 'cost'} ${Fmt.usd(r.totalCost)}',
                      style: AppTheme.mono(
                        fontSize: 10,
                        color: AppColors.textTertiary,
                      ),
                      maxLines: 1,
                    ),
                  ],
                ),
              ],
            ),
          ),
      ],
    );
  }

  String _keyLabel(LocaleProvider locale, String? key) {
    if (key == null || key.isEmpty) {
      return locale.isThai ? 'ไม่ระบุ' : 'Unspecified';
    }
    if (_tab == 1) return widget.strategyLabelOf?.call(key) ?? key;
    if (_tab == 3) return locale.riskLevelText(key);
    return key;
  }
}
