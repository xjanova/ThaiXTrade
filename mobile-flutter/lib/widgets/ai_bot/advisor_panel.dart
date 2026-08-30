/// TPIX TRADE — AI Advisor
/// คำแนะนำจากที่ปรึกษา AI ที่อ่านสถิติย้อนหลังของบอทในกระเป๋านี้
///
/// สิ่งที่ต้องไม่ลืม:
///   • เซิร์ฟเวอร์ตอบ HTTP 200 เสมอ แม้ขอคำแนะนำไม่สำเร็จ — `ok:false` + `reason`
///     คือ "สถานะปกติ" ไม่ใช่ error ของแอพ (prod ยังไม่มีคีย์ผู้ให้บริการ จึงเจอตลอด)
///   • ต้องมีคำเตือนเสมอว่า คำแนะนำนี้ **ไม่มีผลต่อการเทรดจริง** บอทยังเดินตามกฎที่ตั้งไว้
///   • ผลถูกแคชฝั่งเซิร์ฟเวอร์ 15 นาที และมีเพดานเรียกต่อนาที → ปุ่มต้องกันกดรัว
///
/// ข้อมูลมาจาก POST /api/v1/ai-bot/advice — แผงนี้ไม่ยิง API เอง
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

class AiAdvisorPanel extends StatelessWidget {
  final AiBotAdvice? advice;

  /// กำลังถามที่ปรึกษาอยู่ — ปุ่มต้องปิดตัวเองระหว่างนี้ (กันกดรัวจนโดนเพดาน)
  final bool asking;

  /// ขอคำแนะนำได้ไหม (เช่น เชื่อมกระเป๋าแล้วหรือยัง)
  final bool canAsk;

  /// เหตุผลที่ยังขอไม่ได้ — ต้องมีเมื่อ [canAsk] เป็น false ไม่ปล่อยปุ่มเทาเปล่าๆ
  final String? blockedReason;

  /// ความล้มเหลวระดับเครือข่าย (คนละเรื่องกับ ok:false ของเซิร์ฟเวอร์)
  final String? errorMessage;

  final VoidCallback? onAsk;

  final GlassVariant variant;
  final EdgeInsetsGeometry? margin;

  const AiAdvisorPanel({
    super.key,
    this.advice,
    this.asking = false,
    this.canAsk = false,
    this.blockedReason,
    this.errorMessage,
    this.onAsk,
    this.variant = GlassVariant.elevated,
    this.margin,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final th = locale.isThai;
    final accent = context.watch<AccentProvider>();

    return MonitorSection(
      icon: Icons.lightbulb_outline_rounded,
      title: locale.t('aiTrade.advisorTitle'),
      subtitle: locale.t('aiTrade.advisorSub'),
      variant: variant,
      margin: margin,
      trailing: _AskButton(
        label: asking
            ? locale.t('aiTrade.advisorAsking')
            : ((advice?.ok ?? false)
                ? locale.t('aiTrade.advisorRefresh')
                : locale.t('aiTrade.advisorAsk')),
        busy: asking,
        enabled: canAsk && !asking,
        onTap: onAsk,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (!canAsk && blockedReason != null) ...[
            MonitorNotice(
              icon: Icons.lock_outline_rounded,
              title: blockedReason!,
            ),
            const SizedBox(height: 12),
          ],
          if (errorMessage != null) ...[
            MonitorNotice(
              icon: Icons.cloud_off_rounded,
              title: th ? 'ขอคำแนะนำไม่สำเร็จ' : 'Could not reach the advisor',
              body: errorMessage,
              actionLabel: onAsk == null ? null : locale.t('common.retry'),
              onAction: canAsk ? onAsk : null,
              busy: asking,
            ),
            const SizedBox(height: 12),
          ],
          _content(locale, accent),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(
                Icons.info_outline_rounded,
                size: 13,
                color: AppColors.textTertiary,
              ),
              const SizedBox(width: 7),
              Expanded(
                child: Text(
                  locale.t('aiTrade.advisorDisclaimer'),
                  style: GoogleFonts.inter(
                    fontSize: 10.5,
                    color: AppColors.textTertiary,
                    height: 1.45,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _content(LocaleProvider locale, AccentProvider accent) {
    final th = locale.isThai;

    if (asking && advice == null) {
      return const MonitorSkeleton(rows: 1, rowHeight: 88);
    }

    final a = advice;
    if (a == null) {
      return MonitorEmpty(
        icon: Icons.forum_outlined,
        title: th ? 'ยังไม่ได้ขอคำแนะนำ' : 'No advice requested yet',
        body: th
            ? 'กดปุ่มมุมขวาบน ที่ปรึกษาจะอ่านสถิติของบอทคุณแล้วสรุปให้'
            : 'Tap the button above — the advisor reads your record and summarises',
      );
    }

    if (!a.ok) {
      // ok:false เป็นสถานะปกติของระบบ ไม่ใช่ความผิดพลาดของแอพ
      return MonitorNotice(
        icon: Icons.pending_actions_rounded,
        title: a.reason ??
            (th
                ? 'ยังให้คำแนะนำไม่ได้ตอนนี้'
                : 'Advice is unavailable right now'),
        body: th
            ? 'บอทยังทำงานตามปกติ — คำแนะนำเป็นของเสริม ไม่มีผลต่อการตัดสินใจของบอท'
            : 'The bots keep working — advice is optional and never drives their decisions',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            color: accent.goldTint,
            border: Border.all(color: accent.goldBorder, width: 1),
          ),
          child: Text(
            a.text,
            style: GoogleFonts.inter(
              fontSize: 12.5,
              height: 1.55,
              color: AppColors.textPrimary,
            ),
          ),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Icon(Icons.memory_rounded, size: 12, color: accent.g2),
            const SizedBox(width: 6),
            Text(
              a.provider,
              style: AppTheme.mono(
                fontSize: 10,
                fontWeight: FontWeight.w600,
                color: AppColors.textTertiary,
              ),
            ),
            const Spacer(),
            Text(
              th ? 'คำตอบถูกเก็บไว้ราว 15 นาที' : 'Cached for about 15 minutes',
              style: GoogleFonts.inter(
                fontSize: 10,
                color: AppColors.textTertiary,
              ),
              maxLines: 1,
            ),
          ],
        ),
      ],
    );
  }
}

class _AskButton extends StatelessWidget {
  final String label;
  final bool busy;
  final bool enabled;
  final VoidCallback? onTap;

  const _AskButton({
    required this.label,
    required this.busy,
    required this.enabled,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final active = enabled && onTap != null;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: active ? onTap : null,
        borderRadius: BorderRadius.circular(10),
        child: Ink(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            gradient: active ? accent.goldGradient : null,
            color: active ? null : AppColors.bgTertiary,
            border: Border.all(
              color: active ? Colors.transparent : AppColors.bgCardBorder,
              width: 1,
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (busy)
                const SizedBox(
                  width: 12,
                  height: 12,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor:
                        AlwaysStoppedAnimation<Color>(AppColors.textDisabled),
                  ),
                )
              else
                Icon(
                  Icons.auto_awesome_rounded,
                  size: 13,
                  color: active ? AppColors.goldTextOn : AppColors.textDisabled,
                ),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color:
                        active ? AppColors.goldTextOn : AppColors.textDisabled,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
