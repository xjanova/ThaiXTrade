/// TPIX TRADE — Deep Link Service
/// รับ tpixtrade://connect?address=... จาก Wallet → auto-link wallet
/// รับ tpixtrade://trade?pair=BTC-USDT → เปิดหน้า trade pair นั้น
///
/// Developed by Xman Studio

import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/locale/locale_provider.dart';
import '../core/theme/app_colors.dart';
import '../providers/market_provider.dart';
import '../providers/wallet_provider.dart';
import 'linked_wallet_signer.dart';
import 'package:provider/provider.dart';

class DeepLinkService {
  static final DeepLinkService _instance = DeepLinkService._();
  factory DeepLinkService() => _instance;
  DeepLinkService._();

  final AppLinks _appLinks = AppLinks();
  StreamSubscription<Uri>? _sub;
  GlobalKey<NavigatorState>? _navKey;

  // Buffer สำหรับ deep-link ที่มาก่อน init() เสร็จ (router redirect ส่งเข้ามา)
  Uri? _pendingFromRouter;

  // Dedupe — กัน handle URI เดียวกัน 2 ครั้ง (router-fallback + getInitialLink)
  String? _lastHandledKey;

  /// เรียกครั้งเดียวใน splash / main
  Future<void> init(GlobalKey<NavigatorState> navKey) async {
    _navKey = navKey;

    // Flush pending จาก router redirect (มาก่อน init)
    if (_pendingFromRouter != null) {
      final pending = _pendingFromRouter!;
      _pendingFromRouter = null;
      WidgetsBinding.instance.addPostFrameCallback((_) => _handle(pending));
    }

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
    String? host;
    if (qp.containsKey('address') && qp.containsKey('chain')) {
      host = 'connect';
    } else if (qp.containsKey('nonce') && qp.containsKey('signature')) {
      host = 'sign-result';
    } else if (qp.containsKey('nonce') && qp.containsKey('error')) {
      host = 'sign-result';
    } else if (qp.containsKey('pair')) {
      host = 'trade';
    }
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

  void dispose() {
    _sub?.cancel();
    _sub = null;
  }

  void _handle(Uri uri) {
    // ยอมรับเฉพาะ tpixtrade:// scheme
    if (uri.scheme != 'tpixtrade') return;

    // Dedupe — กัน handle URI เดียวกัน 2 ครั้ง (router-fallback + getInitialLink
    // อาจส่ง deep-link เดียวกันมาทั้งคู่ตอนเปิดแอพจาก wallet)
    final key = '${uri.host}:${uri.query}';
    if (key == _lastHandledKey) {
      debugPrint('DeepLink: dedup ${uri.host}');
      return;
    }
    _lastHandledKey = key;

    // Log เฉพาะ scheme + host (ไม่ log query params ที่มี address/signature)
    debugPrint('DeepLink: ${uri.scheme}://${uri.host}');

    // sign-result ไม่ต้องใช้ context — route ตรงไปที่ signer
    if (uri.host == 'sign-result') {
      _handleSignResult(uri);
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
      case 'open':
      default:
        // แค่เปิดแอพเฉยๆ — ไม่ต้องทำอะไร
        break;
    }
  }

  /// `tpixtrade://sign-result?nonce=<n>&signature=0x...` หรือ `&error=user_rejected`
  /// ส่งต่อให้ LinkedWalletSigner resolve pending Future
  void _handleSignResult(Uri uri) {
    final nonce = uri.queryParameters['nonce'];
    if (nonce == null || nonce.isEmpty) return;

    LinkedWalletSigner().completeSignature(
      nonce: nonce,
      signature: uri.queryParameters['signature'],
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

    // Auto-link โดยไม่ต้องเปิด picker — wallet app ส่ง address มาแล้ว trust
    final wallet = context.read<WalletProvider>();
    final ok = await wallet.linkFromDeepLink(
      address: address,
      chainId: chain,
      walletName: walletName,
    );

    if (!context.mounted) return;

    if (ok) {
      final short = '${address.substring(0, 6)}...${address.substring(address.length - 4)}';
      _showSnack(
        context,
        _isThai(context)
            ? 'เชื่อม ${walletName ?? 'TPIX Wallet'} แล้ว — $short'
            : 'Linked ${walletName ?? 'TPIX Wallet'} — $short',
        isSuccess: true,
      );
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
