/// TPIX TRADE — External Wallet Service (WalletConnect v2)
///
/// เชื่อมกับ external wallet (MetaMask, Trust, Rainbow, OKX, Coinbase)
/// ผ่าน Reown AppKit (WalletConnect v2 protocol)
///
/// State pattern:
///   1. init() — ลงทะเบียนเชน TPIX กับ AppKit แล้วสร้าง modal (lazy, ครั้งเดียว)
///   2. connect() — เปิด modal/QR ให้ user เลือก wallet
///   3. signPersonalMessage() — ขอลายเซ็นจาก wallet
///   4. switchChain() — สลับเชนใน wallet (ไม่รู้จักเชน = ขอเพิ่มเชนให้)
///   5. disconnect() — ยกเลิก session
///
/// ⚠️ เชน TPIX (4289) ไม่อยู่ในรายการเชนตั้งต้นของ Reown — ต้อง [registerTpixNetwork]
///    **ก่อน** สร้าง ReownAppKitModal เพราะ modal อ่านรายการเชนไปทำ optionalNamespaces
///    ตั้งแต่ใน constructor เดิมไม่ได้ลงทะเบียนเลย → หาเชนไม่เจอ สลับเชนไม่ได้
///    ผู้ใช้ MetaMask/Trust เทรดบนเชน TPIX ไม่ได้สักไม้
///
/// ห้าม initialize ถ้า REOWN_PROJECT_ID ยังไม่ตั้ง — โยน [WalletConnectNotConfigured]
///
/// Developed by Xman Studio
library;

import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:reown_appkit/reown_appkit.dart';
import '../core/config/reown_config.dart';
import '../models/chain_config.dart';

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

  /// init ที่กำลังวิ่ง/วิ่งเสร็จแล้ว — คำขอที่ซ้อนกันรอ future เดียวกัน
  Future<void>? _initFuture;

  /// context ที่อยู่ตลอดอายุแอป (navigator ราก) — ตั้งจาก app.dart
  ///
  /// ⚠️ Reown เก็บ context ที่ได้ตอนสร้าง modal ไว้ตลอด และ openModalView เงียบหาย
  ///    (ไม่ขึ้น modal ไม่ error) ถ้า context นั้นถูกถอดจากต้นไม้แล้ว
  ///    เดิมส่ง context ของ bottom sheet ที่ปิดไปแล้ว → กดเชื่อมรอบสองไม่มีอะไรเกิดขึ้น
  static BuildContext? Function()? hostContext;

  /// ตรวจว่า service พร้อมใช้งาน — ถ้ายังไม่ตั้ง Project ID จะ false
  bool get isConfigured => ReownConfig.isConfigured;

  /// AppKit modal ที่ initialize แล้ว — null ถ้ายังไม่ init
  ReownAppKitModal? get modal => _modal;

  // ── เชน TPIX สำหรับ Reown ──

  /// ข้อมูลเชน TPIX ในรูปที่ Reown ใช้ — ค่ามาจาก ChainConfig กลางที่เดียว
  static ReownAppKitModalNetworkInfo get tpixNetwork {
    const c = ChainConfig.tpix;
    return ReownAppKitModalNetworkInfo(
      name: c.name,
      chainId: '${c.chainId}',
      currency: c.symbol,
      rpcUrl: c.rpcUrl,
      extraRpcUrls: c.fallbackRpcs,
      explorerUrl: c.explorerUrl,
      chainIcon: ReownConfig.tpixCoinIcon,
    );
  }

  /// ลงทะเบียนเชน TPIX กับ Reown (เรียกซ้ำได้ ไม่เพิ่มซ้ำ)
  ///
  /// ต้องเรียกก่อนสร้าง ReownAppKitModal — modal อ่านรายการเชนตอนสร้างเพื่อขอสิทธิ์
  /// eip155:4289 จาก wallet ตั้งแต่ตอนเชื่อม
  static void registerTpixNetwork() {
    final id = '${ChainConfig.tpix.chainId}';
    if (ReownAppKitModalNetworks.getNetworkInfo('eip155', id) != null) return;
    ReownAppKitModalNetworks.addSupportedNetworks('eip155', [tpixNetwork]);
  }

  /// พารามิเตอร์ wallet_addEthereumChain (EIP-3085) — มี iconUrls ให้ wallet โชว์โลโก้
  ///
  /// ไม่ใช้ toRawJson() ของ Reown เพราะไม่มี iconUrls → MetaMask โชว์เชนเป็นตัวอักษรเปล่า
  static Map<String, dynamic> addChainParams(ChainConfig chain) => {
        'chainId': '0x${chain.chainId.toRadixString(16)}',
        'chainName': chain.name,
        'nativeCurrency': {
          'name': chain.symbol,
          'symbol': chain.symbol,
          'decimals': chain.decimals,
        },
        'rpcUrls': [chain.rpcUrl, ...chain.fallbackRpcs],
        'blockExplorerUrls': [chain.explorerUrl],
        if (chain.chainId == ChainConfig.tpix.chainId)
          'iconUrls': [ReownConfig.tpixCoinIcon],
      };

  /// แปลง chain id จาก Reown เป็นตัวเลข — รองรับทั้ง 'eip155:4289' และ '4289'
  ///
  /// ⚠️ Reown 1.7 คืน selectedChain.chainId เป็นแบบ CAIP-2 ('eip155:56') เสมอ
  ///    เดิม int.tryParse ตรง ๆ ได้ null ทุกครั้ง → connect() คืน null เหมือนผู้ใช้กดยกเลิก
  ///    และ selectedChainId ไม่เคยตรงกับเชนไหนเลย ส่งธุรกรรมผ่าน WalletConnect ไม่ได้สักใบ
  static int? parseChainId(String? raw) {
    if (raw == null) return null;
    final s = raw.trim();
    if (s.isEmpty) return null;
    final idx = s.lastIndexOf(':');
    return int.tryParse(idx >= 0 ? s.substring(idx + 1) : s);
  }

  /// ผู้ใช้กดปฏิเสธใน wallet (ไม่ใช่ wallet ไม่รู้จักเชน)
  static bool isUserRejected(Object e) {
    final reject = RegExp(r'\b(rejected|cancelled|canceled|disapproved|denied)\b',
        caseSensitive: false);
    if (e is JsonRpcError) {
      final code = e.code ?? 0;
      if (code == 4001 || (code >= 5000 && code <= 5003)) return true;
      return reject.hasMatch(e.message ?? '');
    }
    return reject.hasMatch(e.toString());
  }

  /// Initialize AppKit (lazy, ครั้งเดียวต่อ app lifecycle)
  ///
  /// เรียกซ้อนกันได้ — ทุกคนรอ init รอบเดียวกัน (เดิมตัวที่สองคืนทันทีก่อน modal พร้อม)
  /// ใช้ [hostContext] ถ้ามี เพราะ context ที่ส่งมา (เช่นของ bottom sheet) ตายก่อน modal
  /// ⚠️ init ของ Reown รอ relay ต่อติดก่อนจบ — ผู้เรียกควรใส่ timeout เอง
  ///    (session ที่เก็บไว้ถูกโหลดก่อนช่วงรอ relay จึงอ่าน [resumeSession] ได้แม้ยังไม่จบ)
  Future<void> init(BuildContext context) async {
    if (!isConfigured) throw WalletConnectNotConfigured();
    await (_initFuture ??= _doInit(context));
  }

  Future<void> _doInit(BuildContext context) async {
    final host = hostContext?.call();
    final ctx = (host != null && host.mounted) ? host : context;
    try {
      // ก่อนสร้าง modal เสมอ — ไม่งั้น session ที่ขอไปจะไม่มี eip155:4289
      registerTpixNetwork();
      final modal = ReownAppKitModal(
        context: ctx,
        projectId: ReownConfig.projectId,
        metadata: ReownConfig.metadata,
      );
      // ตั้งก่อน init จบ — สถานะ initializing ของ Reown เปิด modal/อ่าน session ได้แล้ว
      _modal = modal;
      await modal.init();
    } catch (e) {
      _modal = null;
      _initFuture = null; // ล้มแล้วให้ครั้งหน้าลองใหม่ได้ ไม่ค้างผลล้มไว้ตลอด
      debugPrint('ExternalWalletService init error: ${e.runtimeType}');
      rethrow;
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
      final chainId = parseChainId(_modal!.selectedChain?.chainId);

      if (address == null || chainId == null) return null;

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

  /// ที่อยู่ที่ wallet ใช้อยู่ตอนนี้ — ผู้ใช้อาจสลับบัญชีใน wallet ระหว่างทาง
  String? get currentAddress => _modal?.session?.getAddress('eip155');

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

  /// ขอให้ wallet ส่งธุรกรรม (eth_sendTransaction) บนเชน [chainId]
  /// คืน tx hash หรือ null ถ้า user reject / wallet ไม่ตอบ
  /// caller ต้อง switchChain() ไปเชนเป้าหมายก่อน
  ///
  /// ระบุเชนในคำขอตรง ๆ (ไม่หยิบจาก selectedChain) — ถ้าผู้ใช้สลับเชนใน wallet
  /// ระหว่างทาง คำขอก็ยังผูกกับเชนที่ตั้งใจ ไม่หลุดไปส่งบนเชนอื่น
  Future<String?> sendTransaction({
    required int chainId,
    required String from,
    required String to,
    String? valueHex, // 0x... (wei) — null = ไม่ส่ง value
    String? dataHex, // 0x... calldata — null = โอนธรรมดา
  }) async {
    if (_modal == null || !_modal!.isConnected) return null;

    try {
      final tx = <String, String>{
        'from': from,
        'to': to,
        'value': ?valueHex,
        'data': ?dataHex,
        // เชน TPIX เป็น legacy gas ราคา 0 — บอก wallet ไว้ก่อน ไม่งั้นบางตัวไปเดา
        // EIP-1559 แล้วเรียก eth_feeHistory ที่เชนนี้ไม่รองรับจนค้าง
        if (chainId == ChainConfig.tpix.chainId) 'gasPrice': '0x0',
      };
      final result = await _modal!.request(
        topic: _modal!.session!.topic!,
        chainId: 'eip155:$chainId',
        request: SessionRequestParams(
          method: 'eth_sendTransaction',
          params: [tx],
        ),
      );

      if (result is String && result.startsWith('0x')) return result;
      return null;
    } catch (e) {
      debugPrint('sendTransaction error: ${e.runtimeType}');
      return null;
    }
  }

  /// เชน (id ตัวเลข) ที่ wallet เลือกอยู่ตอนนี้ — null ถ้าไม่รู้
  int? get selectedChainId => parseChainId(_modal?.selectedChain?.chainId);

  /// เชนที่ wallet อนุญาตใน session นี้แล้ว (CAIP-2)
  List<String> get _approvedEvmChains =>
      _modal?.getApprovedChains(namespace: 'eip155') ?? const [];

  /// สลับเชนใน wallet — คืน true เมื่อ wallet อยู่บนเชนนั้นและส่งคำขอบนเชนนั้นได้แล้ว
  ///
  /// ลำดับ: เชนอนุญาตใน session อยู่แล้ว → สลับในแอปเฉย ๆ (WC v2 ผูกเชนต่อคำขอ)
  ///        ยังไม่อนุญาต → wallet_switchEthereumChain → wallet ไม่รู้จักเชน
  ///        → wallet_addEthereumChain (มี iconUrls) → รอ wallet อัปเดต session
  /// ผู้ใช้กดปฏิเสธ = false ทันที ไม่ไปขอเพิ่มเชนต่อ
  Future<bool> switchChain(int chainId) async {
    final modal = _modal;
    if (modal == null || !modal.isConnected) return false;
    if (chainId == ChainConfig.tpix.chainId) registerTpixNetwork();

    final caip = 'eip155:$chainId';
    try {
      final chainInfo =
          ReownAppKitModalNetworks.getNetworkInfo('eip155', '$chainId');
      if (chainInfo == null) return false;

      if (_approvedEvmChains.contains(caip)) {
        await modal.selectChain(chainInfo);
        return selectedChainId == chainId;
      }

      final topic = modal.session?.topic;
      // คำขอสลับ/เพิ่มเชนต้องส่งบนเชนที่ session อนุญาตอยู่แล้ว
      final current = modal.selectedChain?.chainId;
      final approved = _approvedEvmChains;
      final via = (current != null && approved.contains(current))
          ? current
          : (approved.isNotEmpty ? approved.first : null);
      if (topic == null || via == null) return false;

      final config = ChainConfig.all.firstWhere(
        (c) => c.chainId == chainId,
        orElse: () => ChainConfig.tpix,
      );
      if (config.chainId != chainId) return false;

      try {
        await modal.request(
          topic: topic,
          chainId: via,
          request: SessionRequestParams(
            method: 'wallet_switchEthereumChain',
            params: [
              {'chainId': '0x${chainId.toRadixString(16)}'},
            ],
          ),
        );
      } catch (e) {
        if (isUserRejected(e)) return false;
        // 4902 = wallet ไม่รู้จักเชน (บาง wallet ส่งรหัสอื่น) — ขอเพิ่มเชนพร้อมสลับ
        await modal.request(
          topic: topic,
          chainId: via,
          request: SessionRequestParams(
            method: 'wallet_addEthereumChain',
            params: [addChainParams(config)],
          ),
        );
      }

      // wallet ส่ง session_update ตามมาทีหลังคำตอบ — รอสั้น ๆ ให้ eip155:4289 เข้า session
      // ก่อนเลือกเชน ไม่งั้นคำขอถัดไปโดน "chain not supported" จากฝั่ง sign client
      for (var i = 0; i < 20 && !_approvedEvmChains.contains(caip); i++) {
        await Future.delayed(const Duration(milliseconds: 250));
      }
      if (!_approvedEvmChains.contains(caip)) {
        debugPrint('switchChain: wallet did not add $caip to the session');
        return false;
      }
      await modal.selectChain(chainInfo);
      return selectedChainId == chainId;
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
    final chainId = parseChainId(_modal!.selectedChain?.chainId);
    if (address == null || chainId == null) return null;

    return ExternalWalletSession(
      address: address,
      chainId: chainId,
      topic: session.topic ?? '',
      walletName: session.peer?.metadata.name,
    );
  }
}
