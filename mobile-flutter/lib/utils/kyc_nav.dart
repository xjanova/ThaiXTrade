/// TPIX TRADE — ทางเข้าหน้า "ยืนยันตัวตน" จากทุกที่ในแอป
///
/// รวมไว้จุดเดียวเพราะมีกติกาที่ต้องเหมือนกันทุกทางเข้า
/// (ด่าน KYC_REQUIRED ของบอท · เมนูโปรไฟล์ · deep link กลับจาก Thaiprompt):
///   • อยู่หน้า `/kyc` อยู่แล้ว = ไม่ push ซ้อน (deep link มาตอนหน้าเปิดค้างอยู่
///     เป็นเส้นทางปกติ — ผู้ใช้กดยืนยันจากหน้านี้แล้วถูกส่งกลับมาที่เดิม)
///   • หา router ไม่เจอจริงๆ เท่านั้นถึงจะถอยไปเปิดหน้าเว็บ — หน้าเว็บผู้ใช้ไม่ได้
///     ล็อกอินค้างไว้ ต้องเชื่อมกระเป๋าบนเว็บใหม่ทั้งหมด จึงเป็นทางสุดท้าย
///
/// Developed by Xman Studio
library;

import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../models/kyc_models.dart';

class KycNav {
  KycNav._();

  static const String route = '/kyc';

  /// พาไปหน้ายืนยันตัวตนในแอป
  ///
  /// คืน true = อยู่ในแอป (push แล้ว หรืออยู่หน้านั้นอยู่แล้ว)
  /// คืน false = หา router ไม่เจอ ต้องถอยไปเปิดหน้าเว็บแทน
  static Future<bool> open(BuildContext? context) async {
    final router =
        (context != null && context.mounted) ? GoRouter.maybeOf(context) : null;
    if (router != null) {
      try {
        if (!isShowing(router)) router.push(route);
        return true;
      } catch (e) {
        debugPrint('KycNav.open: ${e.runtimeType}');
      }
    }

    try {
      await launchUrl(
        Uri.parse(KycStatus.defaultWebKycUrl),
        mode: LaunchMode.externalApplication,
      );
    } catch (e) {
      debugPrint('KycNav.web: ${e.runtimeType}');
    }
    return false;
  }

  /// หน้าบนสุดตอนนี้คือ `/kyc` หรือเปล่า
  ///
  /// ⚠️ ดูที่ `lastOrNull.matchedLocation` ไม่ใช่ `currentConfiguration.uri`
  ///    go_router 14 ไม่อัปเดต `uri` ตอน push (ยังเป็นหน้าที่อยู่ใต้ลงไป)
  ///    ถ้าเช็คจาก uri จะ push ซ้อนทุกครั้งที่ deep link มาตอนหน้านี้เปิดอยู่
  static bool isShowing(GoRouter router) {
    try {
      return router.routerDelegate.currentConfiguration.lastOrNull
              ?.matchedLocation ==
          route;
    } catch (_) {
      return false;
    }
  }
}

/// Developed by Xman Studio
