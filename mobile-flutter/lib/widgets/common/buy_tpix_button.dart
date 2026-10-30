/// TPIX TRADE — ปุ่ม "ซื้อ TPIX" (เปิดหน้า token sale บนเว็บ)
///
/// การจ่ายด้วยบัตรเกิดบน tpix.online/token-sale ผ่าน Stripe เท่านั้น — แอปไม่แตะข้อมูลบัตรเลย
/// จึงเปิดในเบราว์เซอร์ภายนอก (ไม่ใช่ WebView ในแอป) ให้ผู้ใช้เห็นโดเมนจริงในแถบที่อยู่
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import '../../providers/accent_provider.dart';
import 'glass_card.dart';

/// หน้าซื้อเหรียญบนเว็บ — ตรึงไว้ในแอป (ไม่รับ URL จากเซิร์ฟเวอร์)
const String kTokenSaleUrl = 'https://tpix.online/token-sale';

/// ตัวเปิดลิงก์ภายนอก (เทสต์ใส่ตัวปลอมได้)
typedef ExternalOpener = Future<bool> Function(Uri uri);

Future<bool> _launchExternal(Uri uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

/// เปิดหน้า token sale ในเบราว์เซอร์ — เปิดไม่ได้ = แจ้งผู้ใช้ (ไม่ throw)
Future<void> openTokenSale(BuildContext context, {ExternalOpener? opener}) async {
  final locale = context.read<LocaleProvider>();
  final messenger = ScaffoldMessenger.maybeOf(context);
  var ok = false;
  try {
    ok = await (opener ?? _launchExternal)(Uri.parse(kTokenSaleUrl));
  } catch (e) {
    debugPrint('openTokenSale: ${e.runtimeType}');
  }
  if (!ok) {
    messenger
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(locale.t('buyTpix.openFailed'))));
  }
}

/// การ์ด/ปุ่ม "ซื้อ TPIX" — [compact] = แถบเตี้ยสำหรับวางในหน้าเทรด/ตลาด
class BuyTpixCard extends StatelessWidget {
  final bool compact;
  final ExternalOpener? opener;

  const BuyTpixCard({super.key, this.compact = false, this.opener});

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final iconSize = compact ? 34.0 : 42.0;

    return GlassCard(
      key: const ValueKey('buy-tpix'),
      variant: GlassVariant.gold,
      borderRadius: compact ? 14 : 18,
      padding: EdgeInsets.symmetric(horizontal: 14, vertical: compact ? 10 : 14),
      onTap: () => openTokenSale(context, opener: opener),
      child: Semantics(
        button: true,
        label: locale.t('buyTpix.title'),
        child: Row(
          children: [
            Container(
              width: iconSize,
              height: iconSize,
              decoration: BoxDecoration(
                gradient: accent.goldGradient,
                borderRadius: BorderRadius.circular(compact ? 10 : 12),
              ),
              child: Icon(Icons.credit_card_rounded, size: compact ? 18 : 22, color: AppColors.goldTextOn),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    locale.t('buyTpix.title'),
                    style: AppFonts.ui(
                      fontSize: compact ? 14 : 15.5,
                      fontWeight: FontWeight.w800,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    locale.t(compact ? 'buyTpix.short' : 'buyTpix.subtitle'),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppFonts.ui(fontSize: 11.5, color: AppColors.textSecondary),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Icon(Icons.open_in_new_rounded, size: 18, color: accent.g2),
          ],
        ),
      ),
    );
  }
}
