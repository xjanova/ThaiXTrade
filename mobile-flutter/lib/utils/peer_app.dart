/// TPIX TRADE — Peer App Discovery
/// ตรวจว่า TPIX Wallet ติดตั้งอยู่บนเครื่องเดียวกันไหม
/// ใช้ canLaunchUrl() + package visibility จาก AndroidManifest
///
/// Developed by Xman Studio

import 'package:flutter/foundation.dart';
import 'package:url_launcher/url_launcher.dart';

class PeerApp {
  PeerApp._();

  static const String _walletScheme = 'tpixwallet';
  // หน้าดาวน์โหลดกลางของ tpix.online — มี APK ทั้ง Trade + Wallet + Masternode
  static const String _walletInstallUrl = 'https://tpix.online/download';
  static const String _walletPackage = 'com.xmanstudio.tpix_wallet';

  // Cache — ถ้าเพิ่งตรวจไปภายใน 5 นาที ใช้ผลเดิม
  static bool? _cachedInstalled;
  static DateTime? _cachedAt;
  static const _cacheTtl = Duration(minutes: 5);

  /// ตรวจว่า TPIX Wallet ติดตั้งอยู่ในเครื่องไหม
  static Future<bool> isWalletInstalled({bool forceRefresh = false}) async {
    if (!forceRefresh && _cachedInstalled != null && _cachedAt != null) {
      if (DateTime.now().difference(_cachedAt!) < _cacheTtl) {
        return _cachedInstalled!;
      }
    }

    try {
      final uri = Uri.parse('$_walletScheme://ping');
      final installed = await canLaunchUrl(uri);
      _cachedInstalled = installed;
      _cachedAt = DateTime.now();
      return installed;
    } catch (e) {
      debugPrint('PeerApp.isWalletInstalled: ${e.runtimeType}');
      return false;
    }
  }

  /// เปิด TPIX Wallet พร้อม query params (optional)
  /// คืน true ถ้าเปิดสำเร็จ, false ถ้า wallet ไม่ได้ติดตั้ง/เปิดไม่ได้
  static Future<bool> openWallet({
    String path = '',
    Map<String, String>? params,
  }) async {
    try {
      final uri = Uri(
        scheme: _walletScheme,
        host: path.isEmpty ? 'open' : path,
        queryParameters: params,
      );
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (e) {
      debugPrint('PeerApp.openWallet: ${e.runtimeType}');
      return false;
    }
  }

  /// เปิดหน้าดาวน์โหลด Wallet (ถ้ายังไม่ได้ติดตั้ง)
  static Future<void> openWalletInstallPage() async {
    try {
      await launchUrl(
        Uri.parse(_walletInstallUrl),
        mode: LaunchMode.externalApplication,
      );
    } catch (e) {
      debugPrint('PeerApp.openWalletInstallPage: ${e.runtimeType}');
    }
  }

  /// Clear cache (สำหรับตอน pull-to-refresh)
  static void clearCache() {
    _cachedInstalled = null;
    _cachedAt = null;
  }
}
