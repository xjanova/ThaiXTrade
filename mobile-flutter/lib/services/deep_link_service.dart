/// TPIX TRADE — Deep Link Service
/// รับ tpixtrade://connect?address=... จาก Wallet → เสนอให้ user เชื่อม
/// รับ tpixtrade://trade?pair=BTC-USDT → เปิดหน้า trade pair นั้น
///
/// Developed by Xman Studio

import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';

import '../core/locale/locale_provider.dart';
import '../core/theme/app_colors.dart';
import '../providers/market_provider.dart';
import 'package:provider/provider.dart';

class DeepLinkService {
  static final DeepLinkService _instance = DeepLinkService._();
  factory DeepLinkService() => _instance;
  DeepLinkService._();

  final AppLinks _appLinks = AppLinks();
  StreamSubscription<Uri>? _sub;
  GlobalKey<NavigatorState>? _navKey;

  /// เรียกครั้งเดียวใน splash / main
  Future<void> init(GlobalKey<NavigatorState> navKey) async {
    _navKey = navKey;

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

  void dispose() {
    _sub?.cancel();
    _sub = null;
  }

  void _handle(Uri uri) {
    // ยอมรับเฉพาะ tpixtrade:// scheme
    if (uri.scheme != 'tpixtrade') return;

    final ctx = _navKey?.currentContext;
    if (ctx == null) return;

    // Log เฉพาะ scheme + host (ไม่ log query params ที่มี address)
    debugPrint('DeepLink: ${uri.scheme}://${uri.host}');

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

  void _handleConnect(BuildContext context, Uri uri) {
    final address = uri.queryParameters['address'];
    if (address == null || !_isValidAddress(address)) return;

    final chain = int.tryParse(uri.queryParameters['chain'] ?? '4289') ?? 4289;
    // Whitelist chain IDs ที่รองรับ
    if (![1, 56, 137, 4289].contains(chain)) return;

    final locale = context.read<LocaleProvider>();
    final short = '${address.substring(0, 6)}...${address.substring(address.length - 4)}';

    showDialog(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: const BorderSide(color: AppColors.bgCardBorder),
        ),
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: AppColors.brandCyan.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(10),
              ),
              child: const Icon(Icons.account_balance_wallet_rounded,
                  color: AppColors.brandCyan, size: 20),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                locale.t('peer.connect_title'),
                style: const TextStyle(
                  color: AppColors.textPrimary,
                  fontSize: 16,
                ),
              ),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              locale.t('peer.connect_desc'),
              style: GoogleFonts.inter(
                fontSize: 13,
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.bgTertiary,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                short,
                style: GoogleFonts.jetBrainsMono(
                  fontSize: 14,
                  color: AppColors.brandCyan,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(
              locale.t('common.cancel'),
              style: const TextStyle(color: AppColors.textTertiary),
            ),
          ),
          ElevatedButton(
            onPressed: () {
              // TODO future: auto-import address เข้า wallet provider
              // ตอนนี้แค่เปิดหน้า wallet connect ให้ user ใส่ mnemonic/private key ของ address นี้
              Navigator.pop(ctx);
            },
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.brandCyan,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
              ),
            ),
            child: Text(
              locale.t('peer.connect_accept'),
              style: const TextStyle(color: Colors.white),
            ),
          ),
        ],
      ),
    );
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
