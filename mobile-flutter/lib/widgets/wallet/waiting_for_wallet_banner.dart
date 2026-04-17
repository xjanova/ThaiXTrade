/// TPIX TRADE — Waiting For TPIX Wallet Banner
///
/// แสดงเมื่อ Trade ส่ง sign request ไป TPIX Wallet แล้วรอ callback —
/// ช่วย user รู้ว่าต้องไปแอพ wallet เพื่อ confirm + ปุ่มเปิด wallet ซ้ำ +
/// ปุ่ม cancel ถ้าเปลี่ยนใจ
///
/// Mount ครั้งเดียวที่ root ของแต่ละ tab (home, portfolio, trade) — auto
/// hide เมื่อไม่มี pending sign
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../services/linked_wallet_signer.dart';

class WaitingForWalletBanner extends StatelessWidget {
  const WaitingForWalletBanner({super.key});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: LinkedWalletSigner().pendingCount,
      builder: (_, count, __) {
        if (count == 0) return const SizedBox.shrink();
        return const _Banner();
      },
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner();

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            AppColors.brandCyan.withValues(alpha: 0.18),
            AppColors.brandPurple.withValues(alpha: 0.12),
          ],
        ),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: AppColors.brandCyan.withValues(alpha: 0.4),
          width: 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(
                  strokeWidth: 2.5,
                  valueColor: AlwaysStoppedAnimation(AppColors.brandCyan),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      locale.isThai
                          ? 'รอการยืนยันจาก TPIX Wallet'
                          : 'Waiting for TPIX Wallet',
                      style: GoogleFonts.inter(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      locale.isThai
                          ? 'ไปที่แอพ TPIX Wallet แล้วกด "เซ็นชื่อ"'
                          : 'Open TPIX Wallet and tap "Sign"',
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () => LinkedWalletSigner().cancelAllPending(),
                  style: OutlinedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    side: BorderSide(
                      color: AppColors.textTertiary.withValues(alpha: 0.4),
                    ),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                  child: Text(
                    locale.t('common.cancel'),
                    style: GoogleFonts.inter(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: () async {
                    final ok =
                        await LinkedWalletSigner().reopenWalletApp();
                    if (!context.mounted) return;
                    if (!ok) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(
                            locale.isThai
                                ? 'ไม่พบแอพ TPIX Wallet — โปรดติดตั้ง'
                                : 'TPIX Wallet not found — please install',
                          ),
                        ),
                      );
                    }
                  },
                  icon: const Icon(Icons.open_in_new_rounded, size: 14),
                  label: Text(
                    locale.isThai ? 'เปิด Wallet' : 'Open Wallet',
                    style: GoogleFonts.inter(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.brandCyan,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
