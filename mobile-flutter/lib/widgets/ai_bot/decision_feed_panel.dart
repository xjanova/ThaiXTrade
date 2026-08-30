/// TPIX TRADE — AI Decision Feed
/// ฟีด "AI คิดอะไรอยู่" — ทุกครั้งที่บอทคิด รวมรอบที่ตัดสินใจไม่ทำอะไร
///
/// ทำไมต้องมี: เดิมเจ้าของบอทเห็นแค่ `last_reason` ของรอบล่าสุดรอบเดียว
/// ซึ่งถูกเขียนทับทุกรอบ — คนที่จ่ายเงินเช่าจึงมอนิเตอร์อะไรไม่ได้เลย
/// และรอบที่ "ไม่ทำอะไร" คือสิ่งที่บอกว่าบอททำงานถูกหรือเปล่า มากกว่ารอบที่เข้าไม้หลายสิบเท่า
///
/// ข้อมูลมาจาก GET /api/v1/ai-bot/decisions (เลื่อนย้อนหลังด้วย cursor)
/// แผงนี้ไม่ยิง API เอง — ผู้เรียกคุมจังหวะโหลดทั้งหมด
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

class AiDecisionFeedPanel extends StatelessWidget {
  /// รายการที่โหลดมาแล้วทั้งหมด (ใหม่→เก่า ตามที่เซิร์ฟเวอร์ส่งมา)
  final List<AiBotDecision> decisions;

  /// โหลดรอบแรก (ยังไม่มีอะไรบนจอ)
  final bool loading;

  /// กำลังโหลดหน้าถัดไป
  final bool loadingMore;

  /// กำลังรีเฟรชทับของเดิม (ไม่ล้างจอ)
  final bool refreshing;

  /// ยังมีของเก่าให้เลื่อนดูอีกไหม
  final bool hasMore;

  /// ข้อความความล้มเหลวที่แปลแล้วจากผู้เรียก — ควรมาคู่กับ [onRetry] เสมอ
  final String? errorMessage;
  final VoidCallback? onRetry;

  final VoidCallback? onRefresh;
  final VoidCallback? onLoadMore;

  /// กรองเฉพาะรอบที่ลงมือจริง (ซื้อ/ขาย/ส่งสัญญาณ)
  final bool actedOnly;
  final ValueChanged<bool>? onActedOnlyChanged;

  /// ชื่อบอทที่กำลังกรองอยู่ — โชว์ให้ผู้ใช้รู้ว่าเห็นไม่ครบทั้งกระเป๋า
  final String? filteredBotName;
  final VoidCallback? onClearBotFilter;

  final DateTime? lastUpdatedAt;
  final GlassVariant variant;
  final EdgeInsetsGeometry? margin;

  const AiDecisionFeedPanel({
    super.key,
    this.decisions = const [],
    this.loading = false,
    this.loadingMore = false,
    this.refreshing = false,
    this.hasMore = false,
    this.errorMessage,
    this.onRetry,
    this.onRefresh,
    this.onLoadMore,
    this.actedOnly = false,
    this.onActedOnlyChanged,
    this.filteredBotName,
    this.onClearBotFilter,
    this.lastUpdatedAt,
    this.variant = GlassVariant.elevated,
    this.margin,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final th = locale.isThai;

    return MonitorSection(
      icon: Icons.psychology_alt_rounded,
      title: th ? 'AI คิดอะไรอยู่' : 'What the AI is thinking',
      subtitle: _subtitle(locale, th),
      variant: variant,
      margin: margin,
      trailing: MonitorIconButton(
        icon: Icons.refresh_rounded,
        busy: refreshing || (loading && decisions.isNotEmpty),
        onTap: onRefresh,
        tooltip: locale.t('aiTrade.marketViewRefresh'),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (onActedOnlyChanged != null) ...[
            _ActedFilter(
              actedOnly: actedOnly,
              onChanged: onActedOnlyChanged!,
              isThai: th,
            ),
            const SizedBox(height: 12),
          ],
          if (filteredBotName != null) ...[
            _BotFilterChip(
              name: filteredBotName!,
              onClear: onClearBotFilter,
              isThai: th,
            ),
            const SizedBox(height: 12),
          ],
          _body(locale, th),
        ],
      ),
    );
  }

  String _subtitle(LocaleProvider locale, bool th) {
    if (lastUpdatedAt == null) {
      return th
          ? 'ทุกครั้งที่บอทคิด รวมรอบที่ตัดสินใจไม่ทำอะไร'
          : 'Every cycle the bot thinks — including the ones it skipped';
    }
    return '${locale.t('aiTrade.lastUpdated')} ${locale.agoText(lastUpdatedAt)}';
  }

  Widget _body(LocaleProvider locale, bool th) {
    // ล้มเหลว: ต้องบอกเหตุผล + ให้ทางออกเสมอ ห้ามปล่อยจอเปล่า
    if (errorMessage != null && decisions.isEmpty) {
      return MonitorNotice(
        icon: Icons.cloud_off_rounded,
        title:
            th ? 'โหลดประวัติการตัดสินใจไม่สำเร็จ' : 'Could not load the feed',
        body: errorMessage,
        actionLabel: onRetry == null ? null : locale.t('common.retry'),
        onAction: onRetry,
        busy: loading,
      );
    }

    if (loading && decisions.isEmpty) {
      return const MonitorSkeleton(rows: 4, rowHeight: 74);
    }

    if (decisions.isEmpty) {
      return MonitorEmpty(
        icon: Icons.hourglass_empty_rounded,
        title: actedOnly
            ? (th ? 'ยังไม่มีรอบที่ลงมือ' : 'No acted cycles yet')
            : locale.t('aiTrade.noDecisionYet'),
        body: actedOnly
            ? (th
                ? 'ลองดู "ทุกรอบ" — บอทอาจกำลังเฝ้าตลาดอยู่โดยยังไม่เข้าไม้'
                : 'Switch to "All cycles" — the bot may be watching without entering')
            : (th
                ? 'สร้างบอทแล้วกดเริ่ม พอบอทเดินรอบแรก เหตุผลทุกรอบจะขึ้นที่นี่'
                : 'Create a bot and start it. Every cycle it runs shows up here.'),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // รีเฟรชทับของเดิมไม่สำเร็จ — เตือนเบาๆ ไม่ล้างรายการทิ้ง (กันจอกระพริบ)
        if (errorMessage != null) ...[
          MonitorNotice(
            icon: Icons.error_outline_rounded,
            title: th ? 'อัปเดตล่าสุดไม่สำเร็จ' : 'Latest refresh failed',
            body: errorMessage,
            actionLabel: onRetry == null ? null : locale.t('common.retry'),
            onAction: onRetry,
            busy: refreshing,
          ),
          const SizedBox(height: 12),
        ],
        for (final d in decisions) _DecisionRow(decision: d, locale: locale),
        if (hasMore) ...[
          const SizedBox(height: 2),
          _LoadMoreButton(busy: loadingMore, onTap: onLoadMore, isThai: th),
        ] else ...[
          const SizedBox(height: 4),
          Center(
            child: Text(
              th ? 'ถึงจุดเริ่มต้นของประวัติแล้ว' : 'Start of history',
              style: GoogleFonts.inter(
                fontSize: 10.5,
                color: AppColors.textTertiary,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

// ── ตัวกรอง "ทุกรอบ / เฉพาะที่ลงมือ" ──────────────────────

class _ActedFilter extends StatelessWidget {
  final bool actedOnly;
  final ValueChanged<bool> onChanged;
  final bool isThai;

  const _ActedFilter({
    required this.actedOnly,
    required this.onChanged,
    required this.isThai,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final labels =
        isThai ? ['ทุกรอบ', 'เฉพาะที่ลงมือ'] : ['All cycles', 'Acted only'];

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
                onTap: () => onChanged(i == 1),
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
                    gradient:
                        (i == 1) == actedOnly ? accent.goldGradient : null,
                  ),
                  child: Text(
                    labels[i],
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: (i == 1) == actedOnly
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

class _BotFilterChip extends StatelessWidget {
  final String name;
  final VoidCallback? onClear;
  final bool isThai;

  const _BotFilterChip({
    required this.name,
    required this.onClear,
    required this.isThai,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Row(
        children: [
          Icon(Icons.filter_alt_rounded, size: 14, color: accent.g2),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              isThai ? 'ดูเฉพาะบอท “$name”' : 'Filtered to "$name"',
              style: GoogleFonts.inter(
                fontSize: 11.5,
                fontWeight: FontWeight.w600,
                color: AppColors.textPrimary,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (onClear != null)
            GestureDetector(
              onTap: onClear,
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: const EdgeInsets.only(left: 8),
                child: Text(
                  isThai ? 'ดูทั้งหมด' : 'Clear',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: accent.g1,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _LoadMoreButton extends StatelessWidget {
  final bool busy;
  final VoidCallback? onTap;
  final bool isThai;

  const _LoadMoreButton({
    required this.busy,
    required this.onTap,
    required this.isThai,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final disabled = busy || onTap == null;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: disabled ? null : onTap,
        borderRadius: BorderRadius.circular(12),
        child: Ink(
          padding: const EdgeInsets.symmetric(vertical: 11),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            color: AppColors.bgInputStrong,
            border: Border.all(color: AppColors.bgCardBorder, width: 1),
          ),
          child: Center(
            child: busy
                ? SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      valueColor: AlwaysStoppedAnimation<Color>(accent.g2),
                    ),
                  )
                : Text(
                    isThai ? 'ดูย้อนหลังเพิ่ม' : 'Load older',
                    style: GoogleFonts.inter(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: disabled ? AppColors.textDisabled : accent.g1,
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}

// ── หนึ่งรอบความคิด ────────────────────────────────────────

class _DecisionRow extends StatelessWidget {
  final AiBotDecision decision;
  final LocaleProvider locale;

  const _DecisionRow({required this.decision, required this.locale});

  bool get _isThai => locale.isThai;

  @override
  Widget build(BuildContext context) {
    final d = decision;
    final hasReason = d.reason.trim().isNotEmpty;

    return MonitorRow(
      // เน้นเฉพาะรอบที่ลงมือจริง — รอบ hold มีเยอะกว่ามาก ถ้าเน้นหมดก็เท่ากับไม่เน้น
      highlighted: d.acted || d.action == 'signal',
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // เวลา — ความกว้างคงที่ ไม่ให้แถวเต้นตอนรีเฟรช
          SizedBox(
            width: 46,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  Fmt.hm(d.createdAt),
                  style: AppTheme.mono(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                  maxLines: 1,
                ),
                const SizedBox(height: 2),
                Text(
                  Fmt.dayMonth(d.createdAt, _isThai),
                  style: GoogleFonts.inter(
                    fontSize: 9.5,
                    color: AppColors.textTertiary,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    _actionPill(d.action),
                    if (d.pair.isNotEmpty)
                      Text(
                        d.pair,
                        style: AppTheme.mono(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                    if (d.mode == 'live')
                      MonitorPill(
                        label: locale.t('aiTrade.modeLiveShort'),
                        tone: MonitorTone.goldStrong,
                        fontSize: 9,
                      ),
                    _riskPill(d.riskLevel),
                    if (d.hasPosition)
                      MonitorPill(
                        label: _isThai ? 'ถือของอยู่' : 'In position',
                        icon: Icons.inventory_2_rounded,
                        fontSize: 9,
                      ),
                  ],
                ),
                const SizedBox(height: 7),
                // เหตุผล — ของจริงที่ผู้ใช้อยากรู้ ไม่ตัดบรรทัดทิ้ง
                Text(
                  hasReason
                      ? d.reason
                      : (_isThai
                          ? 'บอทไม่ได้บันทึกเหตุผลของรอบนี้'
                          : 'No reason recorded for this cycle'),
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    height: 1.45,
                    color: hasReason
                        ? AppColors.textSecondary
                        : AppColors.textTertiary,
                  ),
                ),
                const SizedBox(height: 7),
                _footer(d),
                if (d.signalMeta != null) ...[
                  const SizedBox(height: 7),
                  _MetaChips(meta: d.signalMeta!),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _actionPill(String action) {
    final label = locale.decisionActionText(action);
    switch (action) {
      case 'buy':
        return MonitorPill(
          label: label,
          icon: Icons.trending_up_rounded,
          tone: MonitorTone.up,
        );
      case 'sell':
        return MonitorPill(
          label: label,
          icon: Icons.trending_down_rounded,
          tone: MonitorTone.down,
        );
      case 'signal':
        return MonitorPill(
          label: label,
          icon: Icons.notifications_active_rounded,
          tone: MonitorTone.goldStrong,
          filled: true,
        );
      case 'error':
        return MonitorPill(
          label: label,
          icon: Icons.report_gmailerrorred_rounded,
          tone: MonitorTone.goldStrong,
        );
      case 'stopped':
        return MonitorPill(
          label: label,
          icon: Icons.pause_circle_outline_rounded,
        );
      default:
        return MonitorPill(label: label, icon: Icons.remove_rounded);
    }
  }

  Widget _riskPill(String level) {
    final intensity = riskLevelIntensity(level);
    return MonitorPill(
      label: locale.riskLevelText(level),
      icon: intensity >= 2 ? Icons.warning_amber_rounded : Icons.shield_rounded,
      tone: intensity == 0
          ? MonitorTone.neutral
          : (intensity == 1 ? MonitorTone.gold : MonitorTone.goldStrong),
      filled: intensity == 3,
      fontSize: 9,
    );
  }

  Widget _footer(AiBotDecision d) {
    final parts = <String>[
      if (d.botName != null && d.botName!.isNotEmpty) d.botName!,
      if (d.strategyLabel(_isThai).isNotEmpty) d.strategyLabel(_isThai),
      if (d.timeframe.isNotEmpty) d.timeframe,
    ];

    return Row(
      children: [
        Expanded(
          child: Text(
            parts.join(' · '),
            style: AppTheme.mono(
              fontSize: 10,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
        if (d.price != null) ...[
          const SizedBox(width: 8),
          Text(
            '@ ${Fmt.price(d.price)}',
            style: AppTheme.mono(
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
              color: AppColors.textSecondary,
            ),
            maxLines: 1,
          ),
        ],
      ],
    );
  }
}

/// ตัวเลขประกอบการตัดสินใจของกลยุทธ์ — แสดงเฉพาะค่าที่อ่านรู้เรื่อง (ตัวเลข/สวิตช์)
class _MetaChips extends StatelessWidget {
  final Map<String, dynamic> meta;

  const _MetaChips({required this.meta});

  @override
  Widget build(BuildContext context) {
    final entries = <MapEntry<String, String>>[];

    meta.forEach((k, v) {
      if (entries.length >= 4) return;
      if (v == null || v is Map || v is List) return;
      final text = v is num
          ? Fmt.number(v.toDouble(), digits: v is int ? 0 : 4)
          : v.toString();
      if (text.isEmpty || text.length > 18) return;
      entries.add(MapEntry(k.replaceAll('_', ' '), text));
    });

    if (entries.isEmpty) return const SizedBox.shrink();

    return Wrap(
      spacing: 6,
      runSpacing: 5,
      children: [
        for (final e in entries)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(8),
              color: AppColors.bgInput,
              border: Border.all(color: AppColors.bgCardBorder, width: 1),
            ),
            child: Text(
              '${e.key} ${e.value}',
              style: AppTheme.mono(
                fontSize: 9.5,
                fontWeight: FontWeight.w600,
                color: AppColors.textTertiary,
              ),
              maxLines: 1,
            ),
          ),
      ],
    );
  }
}
