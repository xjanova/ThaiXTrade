/// TPIX TRADE — กล่องยืนยันของ AI TRADE
/// ทุกการกระทำที่ย้อนกลับไม่ได้ต้องผ่านที่นี่ก่อน: ลบบอท · สลับไปโหมดจริง ·
/// หยุดบอทที่ยังถือของอยู่ · ล้างพอร์ตทดลอง
///
/// เซิร์ฟเวอร์ไม่ถามซ้ำให้เลย (ลบบอทที่กำลังเดินและถือของอยู่ได้ทันที) กล่อง
/// พวกนี้จึงเป็นด่านสุดท้ายจริงๆ — ต้องบอกให้ครบว่าจะเสียอะไรไปบ้าง
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../providers/accent_provider.dart';

/// ยืนยันลบบอท — เตือนเพิ่มเมื่อยังถือของอยู่ / กำลังเดินอยู่
Future<bool> showDeleteBotDialog(
  BuildContext context, {
  required String botName,
  bool hasPosition = false,
  bool isRunning = false,
}) async {
  final th = context.read<LocaleProvider>().isThai;

  final warnings = <String>[
    if (isRunning)
      th
          ? 'บอทกำลังทำงานอยู่ — จะถูกหยุดและลบทิ้งทันที'
          : 'The bot is running — it stops and is removed immediately',
    if (hasPosition)
      th
          ? 'ยังมีของที่ถืออยู่ ไม้นี้จะถูกทิ้งโดยไม่มีใครดูแลต่อ'
          : 'It still holds an open position — that position is abandoned',
    th
        ? 'ประวัติไม้ของบอทตัวนี้จะหายจากพอร์ตทดลองด้วย'
        : 'This bot\'s trade history disappears from the demo portfolio too',
  ];

  return await _confirm(
    context,
    danger: true,
    icon: Icons.delete_outline_rounded,
    title: th ? 'ลบบอทตัวนี้?' : 'Delete this bot?',
    subject: botName,
    bullets: warnings,
    footnote: th ? 'การลบย้อนกลับไม่ได้' : 'Deleting cannot be undone',
    confirmLabel: th ? 'ลบบอท' : 'Delete bot',
    cancelLabel: th ? 'ยกเลิก' : 'Cancel',
  );
}

/// ยืนยันสลับไปโหมดจริง — เป็นการตัดสินใจเรื่องเงินจริง ต้องกดอย่างตั้งใจ
Future<bool> showSwitchToLiveDialog(
  BuildContext context, {
  required String botName,
  bool hasDemoPosition = false,
}) async {
  final th = context.read<LocaleProvider>().isThai;

  return await _confirm(
    context,
    danger: false,
    icon: Icons.bolt_rounded,
    title: th ? 'สลับเป็นโหมดจริง?' : 'Switch to live mode?',
    subject: botName,
    bullets: [
      th
          ? 'โหมดจริงจะเสนอสัญญาณให้คุณกดยืนยันในกระเป๋าเอง ระบบไม่ถือกุญแจของคุณ จึงส่งคำสั่งแทนไม่ได้'
          : 'Live mode proposes signals for you to confirm in your own wallet. We never hold your keys, so we cannot place orders for you.',
      if (hasDemoPosition)
        th
            ? 'ไม้ที่ถืออยู่ในโหมดทดลองจะไม่ตามไปด้วย — ยังค้างอยู่และกลับมาเห็นได้เมื่อสลับกลับ'
            : 'Your demo position does not follow — it stays put and reappears when you switch back',
    ],
    footnote: th
        ? 'สลับกลับเป็นโหมดทดลองได้ทุกเมื่อ'
        : 'You can switch back to demo at any time',
    confirmLabel: th ? 'ใช้โหมดจริง' : 'Go live',
    cancelLabel: th ? 'ยกเลิก' : 'Cancel',
  );
}

/// ยืนยันหยุดบอทที่ยังถือของอยู่ (หยุดแล้วไม่มีใครดูแลไม้ต่อ)
Future<bool> showStopBotDialog(
  BuildContext context, {
  required String botName,
}) async {
  final th = context.read<LocaleProvider>().isThai;

  return await _confirm(
    context,
    danger: false,
    icon: Icons.stop_circle_outlined,
    title: th ? 'หยุดบอทตัวนี้?' : 'Stop this bot?',
    subject: botName,
    bullets: [
      th
          ? 'บอทยังถือของอยู่ — หยุดแล้วจะไม่มีใครดูแลไม้นี้ต่อ ทั้งตัดขาดทุนและทำกำไร'
          : 'It still holds a position — once stopped, nothing manages that trade, stop-loss or take-profit',
      th
          ? 'การหยุดจะคืนโควตาบอทให้ (การพักไม่คืน)'
          : 'Stopping frees a bot slot in your quota (pausing does not)',
    ],
    footnote: th
        ? 'ถ้าแค่อยากหยุดชั่วคราว ให้กด "พัก" แทน'
        : 'If you only want a break, use Pause instead',
    confirmLabel: th ? 'หยุดบอท' : 'Stop bot',
    cancelLabel: th ? 'ยกเลิก' : 'Cancel',
  );
}

/// ยืนยันล้างพอร์ตทดลอง — ลบประวัติไม้ทั้งหมดของกระเป๋า
Future<bool> showResetDemoDialog(
  BuildContext context, {
  required int resetsLeft,
}) async {
  final th = context.read<LocaleProvider>().isThai;

  return await _confirm(
    context,
    danger: true,
    icon: Icons.restart_alt_rounded,
    title: th ? 'ล้างพอร์ตทดลอง?' : 'Reset the demo portfolio?',
    subject: th ? 'ทุกพอร์ตกลับไปตั้งต้น' : 'All portfolios back to start',
    bullets: [
      th
          ? 'ประวัติการเทรดทดลองทั้งหมดจะหายไป รวมถึงของที่ถืออยู่'
          : 'All demo trade history is deleted, open positions included',
      th
          ? 'สถิติในหน้าวิเคราะห์จะว่างเปล่าตามไปด้วย เพราะอ่านจากไม้ชุดเดียวกัน'
          : 'Your analytics go empty too — they read from the same trades',
    ],
    footnote: th
        ? 'ล้างได้อีก $resetsLeft ครั้งวันนี้'
        : '$resetsLeft resets left today',
    confirmLabel: th ? 'ล้างพอร์ต' : 'Reset',
    cancelLabel: th ? 'ยกเลิก' : 'Cancel',
  );
}

// ─────────────────────────────────────────────────────────
// เปลือกกล่องยืนยันร่วม
// ─────────────────────────────────────────────────────────

Future<bool> _confirm(
  BuildContext context, {
  required bool danger,
  required IconData icon,
  required String title,
  required String subject,
  required List<String> bullets,
  required String footnote,
  required String confirmLabel,
  required String cancelLabel,
}) async {
  final accent = context.read<AccentProvider>();
  final tone = danger ? AppColors.tradingRed : accent.g2;

  final result = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(
          color: danger
              ? AppColors.tradingRed.withValues(alpha: 0.3)
              : accent.goldBorder,
        ),
      ),
      titlePadding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
      contentPadding: const EdgeInsets.fromLTRB(20, 0, 20, 4),
      title: Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: tone.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, size: 17, color: tone),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              style: GoogleFonts.inter(
                fontSize: 16,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
                letterSpacing: -0.2,
              ),
            ),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(
              color: AppColors.bgInputStrong,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.bgCardBorder, width: 1),
            ),
            child: Text(
              subject,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          const SizedBox(height: 12),
          for (final line in bullets)
            Padding(
              padding: const EdgeInsets.only(bottom: 7),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 5),
                    child: Container(
                      width: 4,
                      height: 4,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: tone,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      line,
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        height: 1.4,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 2),
          Text(
            footnote,
            style: GoogleFonts.inter(
              fontSize: 10.5,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
      actionsPadding: const EdgeInsets.fromLTRB(14, 6, 14, 12),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(dialogContext, false),
          child: Text(
            cancelLabel,
            style: GoogleFonts.inter(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
            ),
          ),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(dialogContext, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: tone,
            foregroundColor:
                danger ? AppColors.white : AppColors.goldTextOn,
            elevation: 0,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          child: Text(
            confirmLabel,
            style: GoogleFonts.inter(
              fontSize: 13,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
      ],
    ),
  );

  return result == true;
}
