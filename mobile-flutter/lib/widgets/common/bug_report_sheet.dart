/// TPIX TRADE — แผ่น "รายงานปัญหา" ให้ผู้ใช้ส่งเองทันทีที่เจอ
///
/// ส่งข้อความของผู้ใช้ + สภาพแอปตอนนั้น + breadcrumb 40 รายการล่าสุด เข้าระบบรายงานบั๊กกลาง
/// จุดประสงค์คือให้คนไล่บั๊กเห็น "ก่อนพังเกิดอะไร" โดยไม่ต้องถามผู้ใช้ซ้ำ
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../core/theme/app_colors.dart';
import '../../services/bug_reporter.dart';
import 'gradient_button.dart';

Future<void> showBugReportSheet(BuildContext context, {required bool isThai}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _BugReportSheet(isThai: isThai),
  );
}

class _BugReportSheet extends StatefulWidget {
  final bool isThai;
  const _BugReportSheet({required this.isThai});

  @override
  State<_BugReportSheet> createState() => _BugReportSheetState();
}

class _BugReportSheetState extends State<_BugReportSheet> {
  final _text = TextEditingController();
  bool _sending = false;
  bool _sent = false;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final message = _text.text.trim();
    if (message.isEmpty || _sending) return;
    setState(() => _sending = true);

    await BugReporter.I.report(
      title: message.length > 80 ? '${message.substring(0, 80)}…' : message,
      description: message,
      type: 'bug',
      severity: 'moderate',
      priority: 'medium',
      metadata: const {'source': 'user', 'category': 'user-report'},
      dedupe: false,
    );

    if (!mounted) return;
    setState(() {
      _sending = false;
      _sent = true;
    });
    await Future<void>.delayed(const Duration(milliseconds: 900));
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final th = widget.isThai;
    final crumbs = BugReporter.I.breadcrumbs;

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 44,
              height: 4,
              decoration: BoxDecoration(
                color: AppColors.textTertiary.withValues(alpha: 0.4),
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            th ? 'รายงานปัญหา' : 'Report a problem',
            style: GoogleFonts.inter(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
          ),
          const SizedBox(height: 6),
          Text(
            th
                ? 'เล่าสั้นๆ ว่ากดอะไรแล้วเกิดอะไร ระบบจะแนบสภาพแอปตอนนี้และเหตุการณ์ ${crumbs.length} รายการล่าสุดไปให้ทีมงานเอง (ไม่มีข้อมูลลับ)'
                : 'Briefly describe what you did and what happened. The app state and the last ${crumbs.length} events are attached automatically (no secrets).',
            style: GoogleFonts.inter(fontSize: 12, color: AppColors.textTertiary, height: 1.5),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _text,
            maxLines: 5,
            minLines: 3,
            maxLength: 2000,
            style: GoogleFonts.inter(color: AppColors.textPrimary, fontSize: 14),
            decoration: InputDecoration(
              filled: true,
              fillColor: AppColors.bgInput,
              counterText: '',
              hintText: th ? 'เช่น กดเชื่อม TPIX Wallet แล้วแอปเด้งกลับมาหน้าแรก' : 'e.g. Tapped Connect TPIX Wallet and the app jumped back to home',
              hintStyle: GoogleFonts.inter(color: AppColors.textDisabled, fontSize: 13),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: const BorderSide(color: AppColors.bgCardBorder),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: const BorderSide(color: AppColors.bgCardBorder),
              ),
            ),
          ),
          const SizedBox(height: 10),
          if (crumbs.isNotEmpty)
            Container(
              constraints: const BoxConstraints(maxHeight: 110),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppColors.bgInput,
                borderRadius: BorderRadius.circular(10),
              ),
              child: SingleChildScrollView(
                child: Text(
                  crumbs.reversed.take(8).join('\n'),
                  style: GoogleFonts.robotoMono(fontSize: 10, color: AppColors.textTertiary, height: 1.4),
                ),
              ),
            ),
          const SizedBox(height: 14),
          GradientButton(
            text: _sent
                ? (th ? 'ส่งแล้ว ขอบคุณ' : 'Sent — thank you')
                : _sending
                    ? (th ? 'กำลังส่ง…' : 'Sending…')
                    : (th ? 'ส่งรายงาน' : 'Send report'),
            icon: _sent ? Icons.check_rounded : Icons.send_rounded,
            onPressed: _sending || _sent ? null : _submit,
          ),
        ],
      ),
    );
  }
}
