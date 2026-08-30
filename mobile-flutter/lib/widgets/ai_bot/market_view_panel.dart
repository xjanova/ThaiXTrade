/// TPIX TRADE — AI Market View
/// มุมมองตลาดที่ AI ใช้ประกอบการตัดสินใจจริง (ไม่ใช่คำแนะนำให้อ่านเล่น)
///
/// กติกาที่ต้องรักษา:
///   • `enabled == false` → ซ่อนทั้งแผง (ระบบวิเคราะห์ถูกปิดที่เซิร์ฟเวอร์)
///   • `enabled == true` แต่ `view == null` → **ห้ามซ่อน** ต้องบอกเหตุผลที่เซิร์ฟเวอร์ส่งมา
///     พร้อมย้ำว่าบอทยังทำงานปกติด้วยกฎล้วน
///   • `shadow == true` → ต้องบอกตรงๆ ว่า AI คิดแต่ยังไม่มีผลต่อการเทรด
///
/// ข้อมูลมาจาก GET /api/v1/ai-bot/market-view (เรียกได้โดยไม่ต้องมีกระเป๋า)
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

class AiMarketViewPanel extends StatelessWidget {
  final AiMarketView? marketView;
  final bool loading;

  /// ข้อความความล้มเหลวที่แปลแล้วจากผู้เรียก
  final String? errorMessage;
  final VoidCallback? onRetry;
  final VoidCallback? onRefresh;

  final GlassVariant variant;
  final EdgeInsetsGeometry? margin;

  const AiMarketViewPanel({
    super.key,
    this.marketView,
    this.loading = false,
    this.errorMessage,
    this.onRetry,
    this.onRefresh,
    this.variant = GlassVariant.elevated,
    this.margin,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final mv = marketView;

    // ปิดระบบวิเคราะห์ที่เซิร์ฟเวอร์ → ไม่ต้องมีแผงนี้บนจอเลย
    if (mv != null && !mv.enabled) return const SizedBox.shrink();

    // ยังไม่รู้ผลและไม่ได้กำลังโหลด → ไม่วาดอะไร (กันแผงกะพริบตอนเข้าหน้า)
    if (mv == null && !loading && errorMessage == null) {
      return const SizedBox.shrink();
    }

    return MonitorSection(
      icon: Icons.insights_rounded,
      title: locale.t('aiTrade.marketViewTitle'),
      subtitle: locale.t('aiTrade.marketViewSub'),
      variant: variant,
      margin: margin,
      trailing: MonitorIconButton(
        icon: Icons.refresh_rounded,
        busy: loading,
        onTap: onRefresh,
        tooltip: locale.t('aiTrade.marketViewRefresh'),
      ),
      child: _body(context, locale, mv),
    );
  }

  Widget _body(
    BuildContext context,
    LocaleProvider locale,
    AiMarketView? mv,
  ) {
    final th = locale.isThai;

    if (errorMessage != null && mv == null) {
      return MonitorNotice(
        icon: Icons.cloud_off_rounded,
        title:
            th ? 'โหลดมุมมองตลาดไม่สำเร็จ' : 'Could not load the market view',
        body: errorMessage,
        actionLabel: onRetry == null ? null : locale.t('common.retry'),
        onAction: onRetry,
        busy: loading,
      );
    }

    if (mv == null) return const MonitorSkeleton(rows: 2, rowHeight: 64);

    final view = mv.view;
    if (view == null) {
      // เปิดระบบไว้ แต่รอบวิเคราะห์ยังไม่มีผล — ต้องอธิบาย ไม่ใช่หายไปเฉยๆ
      return MonitorNotice(
        icon: Icons.schedule_rounded,
        title: mv.reason ?? locale.t('aiTrade.marketViewNone'),
        body: locale.t('aiTrade.marketViewStillRules'),
        actionLabel:
            onRefresh == null ? null : locale.t('aiTrade.marketViewRefresh'),
        onAction: onRefresh,
        busy: loading,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (mv.shadow) ...[
          MonitorNotice(
            icon: Icons.visibility_off_rounded,
            title: th ? 'อยู่ในช่วงเก็บสถิติ' : 'Shadow mode',
            body: locale.t('aiTrade.marketViewShadow'),
          ),
          const SizedBox(height: 12),
        ],
        _stanceRow(locale, view),
        if (view.summary != null && view.summary!.trim().isNotEmpty) ...[
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              color: AppColors.bgInput,
              border: Border.all(color: AppColors.bgCardBorder, width: 1),
            ),
            child: Text(
              view.summary!,
              style: GoogleFonts.inter(
                fontSize: 12,
                height: 1.5,
                color: AppColors.textSecondary,
              ),
            ),
          ),
        ],
        if (view.shortlist.isNotEmpty) ...[
          const SizedBox(height: 14),
          _blockLabel(locale.t('aiTrade.marketShortlist')),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final s in view.shortlist)
                MonitorPill(label: s, tone: MonitorTone.gold, fontSize: 10.5),
            ],
          ),
        ],
        if (view.coins.isNotEmpty) ...[
          const SizedBox(height: 14),
          _blockLabel(locale.t('aiTrade.marketCoins')),
          const SizedBox(height: 8),
          // จำกัดความสูงแล้วให้เลื่อนในตัว — บางรอบ AI ตอบมาหลายสิบเหรียญ
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 264),
            child: SingleChildScrollView(
              child: Column(
                children: [
                  for (final c in view.coins) _CoinRow(coin: c, locale: locale),
                ],
              ),
            ),
          ),
        ],
        if (view.headlines.isNotEmpty) ...[
          const SizedBox(height: 14),
          _blockLabel(locale.t('aiTrade.newsHeadlines')),
          const SizedBox(height: 6),
          for (final h in view.headlines)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Padding(
                    padding: EdgeInsets.only(top: 5, right: 7),
                    child: SizedBox(
                      width: 4,
                      height: 4,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: AppColors.textTertiary,
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: Text(
                      h,
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        height: 1.4,
                        color: AppColors.textSecondary,
                      ),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
        ],
        const SizedBox(height: 14),
        _footer(context, locale, view),
      ],
    );
  }

  Widget _blockLabel(String text) => Text(
        text,
        style: GoogleFonts.inter(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: AppColors.textTertiary,
          letterSpacing: 0.4,
        ),
      );

  Widget _stanceRow(LocaleProvider locale, AiMarketViewBody v) {
    return Row(
      children: [
        Expanded(
          child: MonitorStat(
            label: locale.t('aiTrade.marketStance'),
            value: locale.marketRegimeText(v.regime),
            icon: Icons.explore_rounded,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: MonitorStat(
            label: locale.t('aiTrade.marketConfidence'),
            value: '${v.confidencePct}%',
            icon: Icons.speed_rounded,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: MonitorStat(
            label: locale.t('aiTrade.marketSizeMultiplier'),
            value: '${v.sizeMultiplier.toStringAsFixed(2)}×',
            icon: Icons.straighten_rounded,
          ),
        ),
      ],
    );
  }

  Widget _footer(
    BuildContext context,
    LocaleProvider locale,
    AiMarketViewBody v,
  ) {
    final accent = context.watch<AccentProvider>();
    final scope = v.scope == 'strategic'
        ? locale.t('aiTrade.marketScopeLong')
        : locale.t('aiTrade.marketScopeShort');

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              MonitorBar(value: v.confidence, height: 5),
              const SizedBox(height: 8),
              Text(
                '${v.model ?? (locale.isThai ? 'ไม่ระบุรุ่น' : 'unknown model')} · $scope',
                style:
                    AppTheme.mono(fontSize: 10, color: AppColors.textTertiary),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
        const SizedBox(width: 10),
        Text(
          locale.tp('aiTrade.marketAssessedAgo', {
            'ago': locale.agoText(v.createdAt),
          }),
          style: GoogleFonts.inter(
            fontSize: 10.5,
            fontWeight: FontWeight.w600,
            // ใกล้หมดอายุ = ทองเตือน (ไม่ใช้แดง เพราะไม่ใช่ความเสียหาย)
            color: v.expiringSoon ? accent.g1 : AppColors.textTertiary,
          ),
          maxLines: 1,
        ),
      ],
    );
  }
}

class _CoinRow extends StatelessWidget {
  final CoinView coin;
  final LocaleProvider locale;

  const _CoinRow({required this.coin, required this.locale});

  @override
  Widget build(BuildContext context) {
    final c = coin;

    return MonitorRow(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                c.symbol,
                style: AppTheme.mono(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(width: 8),
              MonitorPill(
                label: locale.coinStanceText(c.stance),
                tone: _stanceTone(c.stance),
                fontSize: 9.5,
              ),
              const Spacer(),
              Text(
                Fmt.signedPct(c.score * 100, digits: 0),
                style: AppTheme.mono(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ],
          ),
          if (c.why.trim().isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              c.why,
              style: GoogleFonts.inter(
                fontSize: 11,
                height: 1.4,
                color: AppColors.textSecondary,
              ),
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ],
      ),
    );
  }

  /// ท่าที "ควรออก/เลี่ยง" ใช้ทอง ไม่ใช่แดง — แดงสงวนให้ราคาลง/ขาดทุน
  MonitorTone _stanceTone(String stance) {
    switch (stance) {
      case 'buy':
        return MonitorTone.goldStrong;
      case 'exit':
      case 'avoid':
        return MonitorTone.gold;
      default:
        return MonitorTone.neutral;
    }
  }
}
