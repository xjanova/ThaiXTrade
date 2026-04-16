/// TPIX TRADE — External Wallet Service (WalletConnect v2)
///
/// เชื่อมกับ external wallet (MetaMask, Trust, Rainbow, OKX, Coinbase)
/// ผ่าน Reown AppKit (WalletConnect v2 protocol)
///
/// State pattern:
///   1. init() — สร้าง AppKit instance (lazy, ครั้งเดียว)
///   2. connect() — เปิด modal/QR ให้ user เลือก wallet
///   3. signPersonalMessage() — ขอลายเซ็นจาก wallet
///   4. switchChain() — สลับเชนใน wallet
///   5. disconnect() — ยกเลิก session
///
/// ห้าม initialize ถ้า REOWN_PROJECT_ID ยังไม่ตั้ง — โยน [WalletConnectNotConfigured]
///
/// Developed by Xman Studio
library;

import 'package:flutter/foundation.dart';
import 'package:reown_appkit/reown_appkit.dart';
import '../core/config/reown_config.dart';

/// Exception เมื่อยังไม่ได้ตั้ง Project ID
class WalletConnectNotConfigured implements Exception {
  @override
  String toString() =>
      'REOWN_PROJECT_ID ยังไม่ตั้ง — สมัครฟรีที่ cloud.reown.com '
      'แล้วรันด้วย --dart-define=REOWN_PROJECT_ID=xxx';
}

/// ข้อมูล session ของ external wallet ที่เชื่อมแล้ว
class ExternalWalletSession {
  final String address; // 0x...
  final int chainId; // เช่น 56 (BSC), 4289 (TPIX)
  final String topic; // WC session topic
  final String? walletName; // ชื่อ wallet (MetaMask, Trust, ...)

  const ExternalWalletSession({
    required this.address,
    required this.chainId,
    required this.topic,
    this.walletName,
  });
}

class ExternalWalletService {
  ExternalWalletService._();
  static final ExternalWalletService _instance = ExternalWalletService._();
  factory ExternalWalletService() => _instance;

  ReownAppKitModal? _modal;
  bool _initializing = false;

  /// ตรวจว่า service พร้อมใช้งาน — ถ้ายังไม่ตั้ง Project ID จะ false
  bool get isConfigured => ReownConfig.isConfigured;

  /// AppKit modal ที่ initialize แล้ว — null ถ้ายังไม่ init
  ReownAppKitModal? get modal => _modal;

  /// Initialize AppKit (lazy, ครั้งเดียวต่อ app lifecycle)
  /// ต้อง pass BuildContext มาด้วยเพราะ modal ผูกกับ widget tree
  Future<void> init(dynamic context) async {
    if (_modal != null) return; // Already initialized
    if (!isConfigured) throw WalletConnectNotConfigured();
    if (_initializing) return; // ป้องกัน double-init จาก concurrent calls

    _initializing = true;
    try {
      _modal = ReownAppKitModal(
        context: context,
        projectId: ReownConfig.projectId,
        metadata: ReownConfig.metadata,
        // กำหนด chains ที่รองรับ — ใช้ CAIP-2 format
        // หมายเหตุ: chainsPresets ต้อง override เพื่อให้รองรับ TPIX Chain (4289)
        // ที่ไม่อยู่ใน default list ของ Reown
      );
      await _modal!.init();
    } catch (e) {
      _modal = null;
      debugPrint('ExternalWalletService init error: ${e.runtimeType}');
      rethrow;
    } finally {
      _initializing = false;
    }
  }

  /// เปิด modal ให้ user เลือก wallet — return null ถ้า cancel
  /// ต้องเรียก init() มาก่อน
  Future<ExternalWalletSession?> connect() async {
    if (_modal == null) {
      throw StateError('ExternalWalletService.init() ต้องเรียกก่อน');
    }

    try {
      await _modal!.openModalView();

      // หลัง user เลือก wallet + อนุญาต — modal จะ store session
      if (!_modal!.isConnected) return null;

      final session = _modal!.session;
      if (session == null) return null;

      final address = _modal!.session?.getAddress('eip155');
      final chainIdStr = _modal!.selectedChain?.chainId;

      if (address == null || chainIdStr == null) return null;

      final chainId = int.tryParse(chainIdStr);
      if (chainId == null) return null;

      return ExternalWalletSession(
        address: address,
        chainId: chainId,
        topic: session.topic ?? '',
        walletName: _modal!.session?.peer?.metadata.name,
      );
    } catch (e) {
      debugPrint('connect error: ${e.runtimeType}');
      return null;
    }
  }

  /// ขอลายเซ็น personal_sign จาก wallet ที่เชื่อมอยู่
  /// คืน null ถ้า user reject หรือ wallet ไม่ตอบ
  Future<String?> signPersonalMessage({
    required String address,
    required String message,
  }) async {
    if (_modal == null || !_modal!.isConnected) return null;

    try {
      final result = await _modal!.request(
        topic: _modal!.session!.topic!,
        chainId: _modal!.selectedChain!.chainId,
        request: SessionRequestParams(
          method: 'personal_sign',
          params: [message, address],
        ),
      );

      // result อาจเป็น String hex (0x...) หรือ Map พร้อม error
      if (result is String) return result;
      return null;
    } catch (e) {
      debugPrint('signPersonalMessage error: ${e.runtimeType}');
      return null;
    }
  }

  /// สลับเชนใน wallet (ขอ wallet เปลี่ยนเชน)
  /// บาง wallet จะ auto-add chain ถ้ายังไม่มี
  Future<bool> switchChain(int chainId) async {
    if (_modal == null || !_modal!.isConnected) return false;

    try {
      final chainInfo = ReownAppKitModalNetworks.getNetworkInfo(
        'eip155',
        chainId.toString(),
      );
      if (chainInfo == null) return false;
      await _modal!.selectChain(chainInfo);
      return true;
    } catch (e) {
      debugPrint('switchChain error: ${e.runtimeType}');
      return false;
    }
  }

  /// ตัดการเชื่อมต่อ + ลบ session
  Future<void> disconnect() async {
    if (_modal == null) return;
    try {
      if (_modal!.isConnected) {
        await _modal!.disconnect();
      }
    } catch (e) {
      debugPrint('disconnect error: ${e.runtimeType}');
    }
  }

  /// resume session จาก storage (Reown เก็บ session ของตัวเอง)
  /// คืน session เดิมถ้ามี — null ถ้าหมดอายุหรือไม่มี
  ExternalWalletSession? resumeSession() {
    if (_modal == null || !_modal!.isConnected) return null;

    final session = _modal!.session;
    if (session == null) return null;

    final address = session.getAddress('eip155');
    final chainIdStr = _modal!.selectedChain?.chainId;
    if (address == null || chainIdStr == null) return null;

    final chainId = int.tryParse(chainIdStr);
    if (chainId == null) return null;

    return ExternalWalletSession(
      address: address,
      chainId: chainId,
      topic: session.topic ?? '',
      walletName: session.peer?.metadata.name,
    );
  }
}
