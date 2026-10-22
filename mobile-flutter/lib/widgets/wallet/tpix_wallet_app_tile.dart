/// TPIX TRADE — ไทล์ "เชื่อม TPIX Wallet" (อันดับ 1 ในหน้าเลือกวิธีเชื่อมกระเป๋า)
///
/// เจ้าของสั่ง 2026-10-22: "ให้นำระบบเชื่อมต่อกระดานเทรดด้วย wallet app มาเป็นอันดับ 1 ในวิธีเชื่อมต่อ
/// และปรับปรุงโลโก้ให้สวยเด่น คือเน้นให้ใช้แอปมากกว่า"
/// ไอคอน = ไอคอนแอป TPIX Wallet ตัวจริง (TPIX-Coin wallet/assets/icon/tpix_icon_1024.png ย่อ 256px)
/// ตัวเดียวกับปุ่มบนเว็บ (public_html/images/brand/tpix-wallet-app.webp)
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import '../../providers/accent_provider.dart';
import '../common/glass_card.dart';

class TpixWalletAppTile extends StatelessWidget {
  final LocaleProvider locale;
  final bool connecting;
  final VoidCallback? onTap;

  const TpixWalletAppTile({
    super.key,
    required this.locale,
    required this.connecting,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 18,
      padding: const EdgeInsets.all(16),
      onTap: onTap,
      child: Row(
        children: [
          // ไอคอนแอป TPIX Wallet ตัวจริง (รูปเดียวกับบนหน้าจอมือถือ + ปุ่มบนเว็บ) — ไม่ใช่โลโก้แอปเทรด
          // เจ้าของสั่ง 2026-10-22: "ปรับปรุงโลโก้ให้สวยเด่น เน้นให้ใช้แอปมากกว่า"
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.white.withValues(alpha: 0.15)),
              boxShadow: [
                BoxShadow(
                  color: accent.goldGlow.withValues(alpha: 0.35),
                  blurRadius: 18,
                  spreadRadius: -4,
                ),
              ],
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(13),
              child: const Image(
                key: Key('tpix-wallet-app-icon'),
                image: AssetImage('assets/images/tpix_wallet_app.webp'),
                fit: BoxFit.cover,
              ),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Wrap ไม่ใช่ Row — ภาษาอังกฤษ "Connect TPIX Wallet" + ป้าย "RECOMMENDED" ล้นจอแคบ (320dp)
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      th ? 'เชื่อม TPIX Wallet' : 'Connect TPIX Wallet',
                      style: AppFonts.ui(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    Container(
                      key: const Key('tpix-wallet-recommended'),
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: accent.goldTint,
                        border: Border.all(color: accent.goldBorder, width: 1),
                      ),
                      child: Text(
                        th ? 'แนะนำ' : 'RECOMMENDED',
                        style: AppFonts.ui(
                          fontSize: 8.5,
                          fontWeight: FontWeight.w800,
                          color: accent.g1,
                          letterSpacing: 0.6,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  th ? 'แตะครั้งเดียว ปลอดภัย เชื่อมผ่านแอป' : 'One-tap secure link via the app',
                  style: AppFonts.ui(
                    fontSize: 11.5,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
          if (connecting)
            SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2, color: accent.g2),
            )
          else
            Icon(Icons.arrow_forward_ios_rounded, color: accent.g2, size: 15),
        ],
      ),
    );
  }
}
