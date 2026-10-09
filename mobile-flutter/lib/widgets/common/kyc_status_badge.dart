/// TPIX TRADE — ป้ายสถานะยืนยันตัวตน (ใช้ในเมนูโปรไฟล์)
///
/// อ่านจาก [KycStatus] ล่าสุดที่หน้า /kyc โหลดไว้ก่อน เพราะสดกว่า — ถ้ายังไม่เคย
/// เปิดหน้านั้นในรอบนี้ ถอยไปใช้ `kyc_status` จากโปรไฟล์ (มากับ /wallet/profile)
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../models/kyc_models.dart';
import '../../providers/accent_provider.dart';

/// สถานะที่ป้ายแสดงได้
enum KycBadgeState { approved, pending, rejected, none }

/// ตัดสินป้ายจากข้อมูลที่มี — [status] (สดกว่า) มาก่อน [profileKycStatus] เสมอ
KycBadgeState kycBadgeStateOf({KycStatus? status, String? profileKycStatus}) {
  if (status != null) {
    if (status.isApproved) return KycBadgeState.approved;
    final sub = status.submission;
    final link = status.thaipromptLink;
    if (sub?.isPending == true || link?.isPending == true) {
      return KycBadgeState.pending;
    }
    if (sub?.isRejected == true) return KycBadgeState.rejected;
    return KycBadgeState.none;
  }
  switch (profileKycStatus?.trim().toLowerCase()) {
    case 'approved':
      return KycBadgeState.approved;
    case 'pending':
      return KycBadgeState.pending;
    case 'rejected':
      return KycBadgeState.rejected;
    default:
      return KycBadgeState.none;
  }
}

class KycStatusBadge extends StatelessWidget {
  final KycBadgeState state;

  const KycStatusBadge({super.key, required this.state});

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();

    /*
     * ⚠️ "ผ่านแล้ว" ใช้ทอง ไม่ใช้เขียว — เขียว/แดงในแอปนี้สงวนไว้ให้ราคาขึ้น/ลง
     *    และกำไร/ขาดทุน (เหตุผลเดียวกับ SnackBar ของหน้า AI TRADE)
     *    แดงใช้เฉพาะ "ไม่ผ่าน" ซึ่งเป็นเรื่องที่ผู้ใช้ต้องรีบจัดการจริง
     */
    final (String key, Color fg, Color bg, Color edge, IconData icon) = switch (state) {
      KycBadgeState.approved => (
          'kyc.badge.approved',
          accent.g2,
          accent.goldTint,
          accent.goldBorder,
          Icons.verified_rounded,
        ),
      KycBadgeState.pending => (
          'kyc.badge.pending',
          AppColors.textSecondary,
          AppColors.bgInput,
          AppColors.bgCardBorder,
          Icons.hourglass_top_rounded,
        ),
      KycBadgeState.rejected => (
          'kyc.badge.rejected',
          AppColors.tradingRed,
          AppColors.tradingRedBg,
          AppColors.tradingRed.withValues(alpha: 0.35),
          Icons.error_outline_rounded,
        ),
      KycBadgeState.none => (
          'kyc.badge.none',
          AppColors.textTertiary,
          AppColors.bgInput,
          AppColors.bgCardBorder,
          Icons.shield_outlined,
        ),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: edge, width: 1),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: fg),
          const SizedBox(width: 4),
          Text(
            locale.t(key),
            style: GoogleFonts.inter(
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
              color: fg,
              letterSpacing: 0.2,
            ),
          ),
        ],
      ),
    );
  }
}

/// Developed by Xman Studio
