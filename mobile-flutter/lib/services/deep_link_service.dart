/// TPIX TRADE — Deep Link Service
/// รับ tpixtrade://connect?address=... จาก Wallet → auto-link wallet
/// รับ tpixtrade://trade?pair=BTC-USDT → เปิดหน้า trade pair นั้น
/// รับ tpixtrade://kyc?result=ok&completion=... → แลกผล KYC จาก Thaiprompt แล้วเปิดหน้า /kyc
///
/// Developed by Xman Studio

import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/locale/locale_provider.dart';
import '../core/theme/app_colors.dart';
import '../models/kyc_models.dart';
import '../providers/kyc_store.dart';
import '../providers/market_provider.dart';
import '../providers/wallet_provider.dart';
import '../utils/kyc_nav.dart';
import 'bug_reporter.dart';
import 'kyc_api.dart';
import 'linked_wallet_signer.dart';
import 'package:provider/provider.dart';

class DeepLinkService {
  static final DeepLinkService _instance = DeepLinkService._();
  factory DeepLinkService() => _instance;
  DeepLinkService._();

  final AppLinks _appLinks = AppLinks();
  StreamSubscription<Uri>? _sub;
  GlobalKey<NavigatorState>? _navKey;

  // subscribe stream + อ่าน initial link ไปแล้วหรือยัง (ครั้งเดียวต่อโปรเซส)
  bool _initialized = false;

  // Buffer สำหรับ deep-link ที่มาก่อน init() เสร็จ (router redirect ส่งเข้ามา)
  Uri? _pendingFromRouter;

  // Dedupe — กัน handle URI เดียวกัน 2 ครั้ง (router-fallback + getInitialLink)
  //
  // ⚠️ ต้องมีกรอบเวลา — เดิมจำคีย์ล่าสุดไว้ตลอดอายุโปรเซส ผู้ใช้กด "เปิด TPIX Trade"
  //    จากกระเป๋าซ้ำด้วยที่อยู่เดิม (URL เหมือนเดิมทุกตัวอักษร) จึงถูกเมินเงียบๆ
  //    แอปเปิดขึ้นมาแล้วไม่ทำอะไร = "ค้าง" ในสายตาผู้ใช้
  String? _lastHandledKey;
  DateTime? _lastHandledAt;
  static const _dedupeWindow = Duration(seconds: 3);

  /// เรียกครั้งเดียวใน splash / main
  Future<void> init(GlobalKey<NavigatorState> navKey) async {
    _navKey = navKey;

    // Flush pending จาก router redirect (มาก่อน init)
    if (_pendingFromRouter != null) {
      final pending = _pendingFromRouter!;
      _pendingFromRouter = null;
      WidgetsBinding.instance.addPostFrameCallback((_) => _handle(pending));
    }

    /*
     * เรียกซ้ำได้ (splash ถูกสร้างใหม่ก็เรียกมาอีก) แต่ subscribe stream + อ่าน
     * initial link แค่ครั้งเดียวต่อโปรเซส — ไม่งั้นทุก link ถูก handle สองรอบ และ
     * initial link ของโปรเซสถูกเล่นซ้ำ (nonce ที่ใช้ไปแล้ว → ยืนยันล้มโดยไม่มีเหตุ)
     */
    if (_initialized) return;
    _initialized = true;

    // จัดการ link ที่เปิดแอพตอนแรก
    try {
      final initial = await _appLinks.getInitialLink();
      if (initial != null) _handle(initial);
    } catch (e) {
      debugPrint('DeepLinkService.initial: ${e.runtimeType}');
    }

    // Listen สำหรับ link ที่มาตอน app กำลังรันอยู่
    _sub = _appLinks.uriLinkStream.listen(
      _handle,
      onError: (_) {},
    );
  }

  /// Fallback สำหรับเคสที่ Flutter framework รับ Android/iOS intent มาก่อน
  /// `app_links` — go_router ได้ initial location แค่ "/?address=...&chain=..."
  /// (host หายระหว่าง URL parsing) → infer host จาก query keys + reconstruct
  ///
  /// เรียกจาก go_router redirect ตอน path="/" + มี query
  void handleRouterFallback(Uri uri) {
    final qp = uri.queryParameters;
    final host = inferHostFromQuery(qp);
    if (host == null) return;

    final reconstructed = Uri(
      scheme: 'tpixtrade',
      host: host,
      queryParameters: qp,
    );

    // ถ้า navigator ยังไม่ register → buffer ไว้ให้ init() flush
    if (_navKey == null) {
      _pendingFromRouter = reconstructed;
      return;
    }

    // Defer 1 frame เพื่อรอ navigator settle หลัง redirect
    WidgetsBinding.instance.addPostFrameCallback((_) => _handle(reconstructed));
  }

  /// เดา host ของ deep link จากชื่อคีย์ใน query (host หายไประหว่างทาง)
  ///
  /// แยกเป็นฟังก์ชันเพียวไว้ทดสอบ — ลำดับการเช็คคือสัญญา: ลิงก์ที่มีอยู่ก่อนต้องได้
  /// สิทธิ์ก่อนเสมอ การเพิ่ม host ใหม่ต้องไม่ทำให้ลิงก์เดิมถูกเดาผิด
  static String? inferHostFromQuery(Map<String, String> qp) {
    if (qp.containsKey('address') && qp.containsKey('chain')) return 'connect';
    // tx-result ฝัง kind=tx ไว้ใน callback URL — ต้องเช็คก่อน sign-result
    // เพราะ error case มี nonce+error เหมือนกัน (แยกไม่ออกถ้าไม่มี kind)
    if (qp['kind'] == 'tx') return 'tx-result';
    if (qp.containsKey('nonce') && qp.containsKey('signature')) {
      return 'sign-result';
    }
    if (qp.containsKey('nonce') && qp.containsKey('error')) return 'sign-result';
    if (qp.containsKey('pair')) return 'trade';
    // ผลจาก Thaiprompt (tpixtrade://kyc?result=…&completion=…) — คีย์สองตัวนี้
    // ไม่มีลิงก์ไหนใช้ (connect/sign-result/tx-result/trade) จึงไม่ชนกัน
    // วางไว้ท้ายสุดให้ลิงก์เดิมทุกตัวได้สิทธิ์ก่อนเสมอ
    if (qp.containsKey('completion') || qp.containsKey('result')) return 'kyc';
    return null;
  }

  void dispose() {
    _sub?.cancel();
    _sub = null;
    _initialized = false;
  }

  void _handle(Uri uri) {
    // ยอมรับเฉพาะ tpixtrade:// scheme
    if (uri.scheme != 'tpixtrade') return;

    // Dedupe — กัน handle URI เดียวกัน 2 ครั้ง (router-fallback + getInitialLink
    // อาจส่ง deep-link เดียวกันมาทั้งคู่ตอนเปิดแอพจาก wallet)
    final key = '${uri.host}:${uri.query}';
    final now = DateTime.now();
    if (key == _lastHandledKey &&
        _lastHandledAt != null &&
        now.difference(_lastHandledAt!) < _dedupeWindow) {
      debugPrint('DeepLink: dedup ${uri.host}');
      return;
    }
    _lastHandledKey = key;
    _lastHandledAt = now;

    // Log เฉพาะ scheme + host (ไม่ log query params ที่มี address/signature)
    debugPrint('DeepLink: ${uri.scheme}://${uri.host}');
    BugReporter.I.breadcrumb('deeplink ${uri.host} keys=${uri.queryParameters.keys.join(',')}');

    // sign-result / tx-result ไม่ต้องใช้ context — route ตรงไปที่ signer
    if (uri.host == 'sign-result') {
      _handleSignResult(uri);
      return;
    }
    if (uri.host == 'tx-result') {
      _handleTxResult(uri);
      return;
    }

    final ctx = _navKey?.currentContext;
    if (ctx == null) return;

    switch (uri.host) {
      case 'connect':
        _handleConnect(ctx, uri);
        break;
      case 'trade':
        _handleTrade(ctx, uri);
        break;
      case 'kyc':
        _handleKyc(ctx, uri);
        break;
      case 'open':
      default:
        // แค่เปิดแอพเฉยๆ — ไม่ต้องทำอะไร
        break;
    }
  }

  /// `tpixtrade://sign-result?nonce=<n>&signature=0x...` หรือ `&error=user_rejected`
  /// ส่งต่อให้ LinkedWalletSigner resolve pending Future
  ///
  /// ถ้าไม่มี Future รออยู่ (แอปถูกเปิดใหม่ระหว่างที่ผู้ใช้ไปเซ็นในกระเป๋า) ดูคำขอที่
  /// จดไว้บนดิสก์ — เป็นการยืนยันกระเป๋าก็ทำต่อให้จบตรงนี้ ไม่ทิ้งลายเซ็นที่ผู้ใช้
  /// อุตส่าห์กดยืนยันมาแล้วให้หายไปเฉยๆ
  Future<void> _handleSignResult(Uri uri) async {
    final nonce = uri.queryParameters['nonce'];
    if (nonce == null || nonce.isEmpty) return;

    final signature = uri.queryParameters['signature'];
    final signer = LinkedWalletSigner();

    final handled = signer.completeSignature(
      nonce: nonce,
      signature: signature,
      error: uri.queryParameters['error'],
    );
    if (handled) return;

    final persisted = await signer.takePersisted(nonce);
    if (persisted == null) {
      // นี่คือเคสที่เคยต้องเดา — callback มาถึงแต่ไม่มีใครรอ รายงานพร้อม breadcrumb ให้เห็นทันที
      BugReporter.I.report(
        title: 'sign-result มาถึงแต่ไม่มีคำขอรออยู่',
        description: 'callback จากกระเป๋ามาถึงแอปที่ไม่มี Completer ในหน่วยความจำ และไม่มีคำขอที่จดไว้บนดิสก์ (nonce ไม่รู้จักหรือหมดอายุ)',
        metadata: {'has_signature': signature != null, 'error': uri.queryParameters['error']},
      );
      return;
    }
    BugReporter.I.breadcrumb('sign-result recovered from disk tag=${persisted['tag']}');

    if (signature == null || !signer.isValidSignature(signature)) {
      debugPrint('DeepLink: persisted sign request ended without signature');
      return;
    }

    final ctx = _navKey?.currentContext;
    if (ctx == null) return;

    if (persisted['tag'] == 'verify') {
      final wallet = ctx.read<WalletProvider>();
      final ok = await wallet.completeVerificationFromCallback(
        signature: signature,
        meta: persisted['meta'] as Map<String, dynamic>?,
      );

      final ctx2 = _navKey?.currentContext;
      if (ctx2 == null) return;
      _showSnack(
        ctx2,
        ok
            ? (_isThai(ctx2) ? 'ยืนยันกระเป๋าแล้ว' : 'Wallet verified')
            : (_isThai(ctx2)
                ? 'ยืนยันกระเป๋าไม่สำเร็จ — ลองกดยืนยันใหม่อีกครั้ง'
                : 'Wallet verification failed — please try again'),
        isSuccess: ok,
      );
    }
  }

  /// `tpixtrade://tx-result?kind=tx&nonce=<n>&txhash=0x...` หรือ `&error=...`
  /// ผลจากการส่งธุรกรรมผ่าน TPIX Wallet (เทรดจริงบน BSC ของ linked wallet)
  void _handleTxResult(Uri uri) {
    final nonce = uri.queryParameters['nonce'];
    if (nonce == null || nonce.isEmpty) return;

    LinkedWalletSigner().completeTransaction(
      nonce: nonce,
      txHash: uri.queryParameters['txhash'],
      error: uri.queryParameters['error'],
    );
  }

  Future<void> _handleConnect(BuildContext context, Uri uri) async {
    final address = uri.queryParameters['address'];
    if (address == null || !_isValidAddress(address)) {
      _showSnack(context, _isThai(context)
          ? 'ลิงก์ไม่ถูกต้อง — ไม่พบ address'
          : 'Invalid link — missing address');
      return;
    }

    final chain = int.tryParse(uri.queryParameters['chain'] ?? '4289') ?? 4289;
    // Whitelist chain IDs ที่รองรับ
    if (![1, 56, 137, 4289].contains(chain)) {
      _showSnack(context, _isThai(context)
          ? 'เครือข่าย $chain ไม่รองรับ'
          : 'Chain $chain not supported');
      return;
    }

    final walletName = uri.queryParameters['wallet']; // optional source app name

    // กระเป๋ารุ่นใหม่แนบลายเซ็นยืนยันมาด้วย → เชื่อม+ยืนยันจบในฮอปเดียว
    final nonce = uri.queryParameters['nonce'];
    final signature = uri.queryParameters['signature'];
    // นับว่า "แนบลายเซ็นมา" ด้วยเกณฑ์เดียวกับ WalletProvider — ลายเซ็นรูปแบบผิดไม่นับ
    final signed = nonce != null &&
        nonce.isNotEmpty &&
        signature != null &&
        RegExp(r'^0x[a-fA-F0-9]{130}$').hasMatch(signature);

    // Auto-link โดยไม่ต้องเปิด picker — wallet app ส่ง address มาแล้ว trust
    final wallet = context.read<WalletProvider>();
    final ok = await wallet.linkFromDeepLink(
      address: address,
      chainId: chain,
      walletName: walletName,
      nonce: nonce,
      signature: signature,
    );

    // linkFromDeepLink รอผลยืนยันลายเซ็นให้จบก่อนคืนค่า → isVerified ตรงนี้คือของจริง
    final verified = wallet.isVerified;
    BugReporter.I.breadcrumb(
      'connect ok=$ok signed=$signed verified=$verified chain=$chain wallet=${walletName ?? '-'}',
    );
    if (!ok) {
      BugReporter.I.report(
        title: 'เชื่อมกระเป๋าจาก deep link ไม่สำเร็จ',
        description: 'linkFromDeepLink คืน false — chain=$chain signed=$signed',
      );
    }

    if (!context.mounted) return;

    if (ok) {
      final short = '${address.substring(0, 6)}...${address.substring(address.length - 4)}';
      final name = walletName ?? 'TPIX Wallet';
      final th = _isThai(context);
      // บอกตามจริง: เชื่อม+ยืนยันจบ / เชื่อมแล้วแต่ลายเซ็นที่แนบมาไม่ผ่าน / เชื่อมอย่างเดียว
      // (เดิมขึ้น "เชื่อมและยืนยันแล้ว" ทันทีที่เห็นลายเซ็น ทั้งที่เซิร์ฟเวอร์ยังไม่ได้ตอบ)
      final String msg;
      if (verified) {
        msg = th
            ? 'เชื่อมและยืนยัน $name แล้ว — $short'
            : 'Linked and verified $name — $short';
      } else if (signed) {
        msg = th
            ? 'เชื่อม $name แล้ว แต่ยืนยันลายเซ็นไม่ผ่าน — กดยืนยันอีกครั้งตอนเทรด'
            : 'Linked $name, but the signature could not be verified — verify again when trading';
      } else {
        msg = th ? 'เชื่อม $name แล้ว — $short' : 'Linked $name — $short';
      }
      _showSnack(context, msg, isSuccess: verified || !signed);
      // ไปหน้า portfolio เพื่อให้ user เห็น balance ทันที
      try {
        GoRouter.of(context).go('/portfolio');
      } catch (_) {}
    } else {
      _showSnack(context, _isThai(context)
          ? 'เชื่อม wallet ไม่สำเร็จ'
          : 'Failed to link wallet');
    }
  }

  /// `tpixtrade://kyc?result=ok&completion=<48 ตัว>` — Thaiprompt ส่งผลการอนุญาตกลับมา
  ///
  /// เปิดหน้า /kyc ก่อนเลย (ผู้ใช้เห็นแถบ "กำลังเชื่อมบัญชี Thaiprompt…" ทันที ไม่ใช่
  /// แอปเปิดมาเฉยๆ แล้วรอ) จากนั้นแลกรหัสผ่าน [KycStore] ซึ่งหน้า /kyc ฟังอยู่ —
  /// หน้าที่เปิดค้างไว้ก่อนแล้วจึงอัปเดตเองโดยไม่ต้องโหลดใหม่
  ///
  /// ⚠️ ห้าม log ค่า completion — _handle พิมพ์แค่ host + ชื่อคีย์ และ
  ///    KycReturnLink.toString() ไม่พิมพ์รหัส
  Future<void> _handleKyc(BuildContext context, Uri uri) async {
    final link = KycReturnLink.parse(uri);
    if (link == null) return;

    // อ่านกระเป๋าก่อน await — ใช้ context ข้ามช่วงรอไม่ได้
    String? wallet;
    try {
      wallet = context.read<WalletProvider>().address;
    } catch (_) {}

    await KycNav.open(context);
    BugReporter.I.breadcrumb('kyc return ${link.result.name}');

    switch (link.result) {
      case KycReturnResult.ok:
        final outcome = await KycStore.I.complete(
          api: KycApi(),
          wallet: wallet,
          completion: link.completion!,
        );
        BugReporter.I.breadcrumb('kyc complete → ${outcome.name}');

        final key = outcome.messageKey;
        if (key != null) _snackKey(key, isSuccess: outcome.isSuccess);
      case KycReturnResult.denied:
        _snackKey('kyc.link.denied');
      case KycReturnResult.open:
        // ลิงก์เปล่าจากหน้าเว็บ — เปิดหน้ายืนยันตัวตนให้แล้วด้านบน ไม่มีผลอะไรต้องแจ้ง
        break;
      case KycReturnResult.expired:
      case KycReturnResult.error:
      case KycReturnResult.invalid:
        _snackKey('kyc.link.expired');
    }
  }

  /// แจ้งผลหลังช่วงรอ — ใช้ context ล่าสุดของ navigator เสมอ (ตัวที่ได้ก่อนรออาจหายไปแล้ว)
  void _snackKey(String key, {bool isSuccess = false}) {
    final ctx = _navKey?.currentContext;
    if (ctx == null || !ctx.mounted) return;
    _showSnack(ctx, _t(ctx, key), isSuccess: isSuccess);
  }

  /// ข้อความจาก locale — ไม่มี LocaleProvider (ไม่น่าเกิด) ก็ยังต้องมีข้อความให้ผู้ใช้
  String _t(BuildContext context, String key) {
    try {
      return context.read<LocaleProvider>().t(key);
    } catch (_) {
      return key;
    }
  }

  bool _isThai(BuildContext context) {
    try {
      return context.read<LocaleProvider>().isThai;
    } catch (_) {
      return false;
    }
  }

  void _showSnack(BuildContext context, String msg, {bool isSuccess = false}) {
    try {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(msg),
          backgroundColor: isSuccess ? AppColors.tradingGreen : null,
          duration: const Duration(seconds: 3),
        ),
      );
    } catch (_) {
      // ScaffoldMessenger ไม่พร้อม (เช่น deep link มาตอน splash) — ignore
    }
  }

  void _handleTrade(BuildContext context, Uri uri) {
    final pair = uri.queryParameters['pair'];
    if (pair == null || !_isValidPair(pair)) return;

    try {
      context.read<MarketProvider>().selectPair(pair);
      GoRouter.of(context).go('/trade');
    } catch (e) {
      debugPrint('DeepLink trade: ${e.runtimeType}');
    }
  }

  // ── Validators (security) ──

  bool _isValidAddress(String s) {
    // Ethereum address: 0x + 40 hex chars
    return RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(s);
  }

  bool _isValidPair(String s) {
    // BASE-QUOTE format: BTC-USDT
    return RegExp(r'^[A-Z0-9]{2,10}-[A-Z0-9]{2,10}$').hasMatch(s);
  }
}
