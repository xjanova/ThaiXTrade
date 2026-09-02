/// TPIX TRADE — Linked Wallet Signer
///
/// Orchestrates the deep-link sign protocol when the active wallet is
/// kind=WalletKind.linked (came from TPIX Wallet via tpixtrade://connect).
///
/// Flow:
///   1. Trade asks WalletProvider.signMessage for linked wallet
///   2. WalletProvider delegates here → requestSignature()
///   3. We generate a nonce, register a Completer, and launch
///      `tpixwallet://sign?message=<msg>&nonce=<n>&callback=tpixtrade://sign-result`
///   4. User confirms in TPIX Wallet → wallet signs → opens
///      `tpixtrade://sign-result?nonce=<n>&signature=0x...`
///   5. DeepLinkService routes that back here → completeSignature()
///   6. Completer resolves with the signature (or null on timeout/reject)
///
/// Security:
///   - Nonce is cryptographically random (16 bytes hex) — prevents replay
///   - Pending requests timeout after 90s — prevents leaked Completers
///   - Only accepts callbacks for nonces we issued (no spoofing)
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

class LinkedWalletSigner {
  LinkedWalletSigner._();
  static final LinkedWalletSigner _instance = LinkedWalletSigner._();
  factory LinkedWalletSigner() => _instance;

  /// Pending sign requests — nonce → Completer
  /// Cleared when callback arrives or timeout fires.
  final Map<String, Completer<String?>> _pending = {};

  /// คำขอที่ "จดไว้บนดิสก์" — nonce → { tag, meta, at }
  ///
  /// ระหว่างที่ผู้ใช้ไปเซ็นใน TPIX Wallet Android อาจฆ่าโปรเซสของเรา (หน่วยความจำ
  /// ต่ำ หรือ launchMode ผิดในรุ่นเก่า) พอ callback มาถึงจะเป็นแอปที่เพิ่งเปิดใหม่
  /// ไม่มี Completer ให้ resolve — ผลลัพธ์จะถูกทิ้งเงียบๆ และผู้ใช้ต้องเซ็นซ้ำ
  /// จดไว้บนดิสก์แล้ว DeepLinkService ใช้ [takePersisted] ทำงานต่อให้จบได้
  static const _persistKey = 'linked_sign_pending_v1';

  /// อายุสูงสุดของคำขอที่จดไว้ — nonce ฝั่งเซิร์ฟเวอร์อายุ 5 นาที จดนานกว่านั้นไร้ประโยชน์
  static const _persistTtl = Duration(minutes: 5);

  /// Pending TRANSACTION requests — nonce → Completer(tx hash)
  /// แยกจาก _pending เพราะรูปแบบผลลัพธ์ต่างกัน (hash 66 ตัว vs signature 132)
  final Map<String, Completer<String?>> _pendingTx = {};

  /// Reactive pending count — UI listens to this via ValueListenableBuilder
  /// to show/hide the "Waiting for TPIX Wallet" banner.
  final ValueNotifier<int> _pendingCount = ValueNotifier<int>(0);
  ValueListenable<int> get pendingCount => _pendingCount;
  bool get hasPending => _pendingCount.value > 0;

  /// User-facing timeout for completing the sign in the wallet app.
  static const _timeout = Duration(seconds: 90);

  /// Timeout ของ transaction — นานกว่า sign เพราะ wallet ต้องยืนยัน
  /// แล้ว broadcast ขึ้นเชนจริงก่อนจะได้ hash กลับมา
  static const _txTimeout = Duration(seconds: 180);

  /// Wallet app scheme. Hard-coded — only TPIX Wallet supports this protocol.
  static const _walletScheme = 'tpixwallet';

  /// Callback URL the wallet app will open with the result.
  static const _callbackUrl = 'tpixtrade://sign-result';

  /// Callback ของ transaction — มี kind=tx ฝังไว้เพื่อให้ router-fallback
  /// (เคส Android strip host) แยกจาก sign-result ได้จาก query key
  static const _txCallbackUrl = 'tpixtrade://tx-result?kind=tx';

  /// Re-open the TPIX Wallet app (for the "Open Wallet" button on the
  /// waiting banner — when user dismissed the wallet without signing).
  Future<bool> reopenWalletApp() async {
    try {
      return await launchUrl(
        Uri.parse('$_walletScheme://open'),
        mode: LaunchMode.externalApplication,
      );
    } catch (_) {
      return false;
    }
  }

  /// Ask the linked wallet app to sign a message.
  ///
  /// Returns the 0x-prefixed hex signature, or null if the user rejected,
  /// the wallet app didn't respond, or the request timed out.
  ///
  /// [tag] + [meta] = จดคำขอไว้บนดิสก์ เพื่อให้แอปที่ถูกเปิดใหม่ทำงานต่อจาก
  /// callback ได้ (เช่น tag 'verify' + meta {nonce ฝั่งเซิร์ฟเวอร์, address})
  Future<String?> requestSignature(
    String message, {
    String? tag,
    Map<String, dynamic>? meta,
  }) async {
    final nonce = _generateNonce();
    final completer = Completer<String?>();
    _pending[nonce] = completer;
    _updateCount();

    if (tag != null) await _persist(nonce, tag: tag, meta: meta);

    // Build sign URL — message + callback are URL-encoded
    final uri = Uri(
      scheme: _walletScheme,
      host: 'sign',
      queryParameters: {
        'message': message,
        'nonce': nonce,
        'callback': _callbackUrl,
        'from': 'trade',
      },
    );

    try {
      final launched = await launchUrl(
        uri,
        mode: LaunchMode.externalApplication,
      );
      if (!launched) {
        _removePending(nonce);
        await _forgetPersisted(nonce);
        return null;
      }
    } catch (e) {
      debugPrint('LinkedWalletSigner.requestSignature: ${e.runtimeType}');
      _removePending(nonce);
      await _forgetPersisted(nonce);
      return null;
    }

    // Wait for callback OR timeout
    try {
      final result = await completer.future.timeout(_timeout);
      await _forgetPersisted(nonce);
      return result;
    } on TimeoutException {
      _removePending(nonce);
      // ไม่ลบที่จดไว้ — โปรเซสนี้หมดเวลาแล้ว แต่ callback อาจมาถึงแอปที่เปิดใหม่ทีหลัง
      return null;
    } catch (_) {
      _removePending(nonce);
      await _forgetPersisted(nonce);
      return null;
    }
  }

  // ── คำขอที่จดไว้บนดิสก์ ─────────────────────────────────────────

  Future<Map<String, dynamic>> _readPersisted() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_persistKey);
      if (raw == null || raw.isEmpty) return {};
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : {};
    } catch (_) {
      return {};
    }
  }

  Future<void> _writePersisted(Map<String, dynamic> all) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (all.isEmpty) {
        await prefs.remove(_persistKey);
      } else {
        await prefs.setString(_persistKey, jsonEncode(all));
      }
    } catch (_) {}
  }

  Future<void> _persist(
    String nonce, {
    required String tag,
    Map<String, dynamic>? meta,
  }) async {
    final all = await _readPersisted();
    final now = DateTime.now().millisecondsSinceEpoch;
    // ทิ้งของเก่าที่หมดอายุไปด้วย ไม่ให้กองสะสม
    all.removeWhere((_, v) =>
        v is! Map || now - ((v['at'] as num?)?.toInt() ?? 0) > _persistTtl.inMilliseconds);
    all[nonce] = {'tag': tag, 'meta': meta ?? const {}, 'at': now};
    await _writePersisted(all);
  }

  Future<void> _forgetPersisted(String nonce) async {
    final all = await _readPersisted();
    if (all.remove(nonce) != null) await _writePersisted(all);
  }

  /// หยิบคำขอที่จดไว้ของ nonce นี้ (แล้วลบทิ้ง) — null ถ้าไม่มีหรือหมดอายุ
  ///
  /// ใช้เมื่อ callback มาถึงแต่ไม่มี Completer ในหน่วยความจำ (แอปถูกเปิดใหม่)
  Future<Map<String, dynamic>?> takePersisted(String nonce) async {
    final all = await _readPersisted();
    final entry = all.remove(nonce);
    await _writePersisted(all);
    if (entry is! Map) return null;

    final at = (entry['at'] as num?)?.toInt() ?? 0;
    if (DateTime.now().millisecondsSinceEpoch - at > _persistTtl.inMilliseconds) {
      return null;
    }

    return {
      'tag': entry['tag']?.toString() ?? '',
      'meta': entry['meta'] is Map
          ? Map<String, dynamic>.from(entry['meta'] as Map)
          : <String, dynamic>{},
    };
  }

  /// Ask the linked wallet app to sign an EIP-712 typed-data structure.
  ///
  /// The [typedData] map follows the EIP-712 TypedData format:
  /// ```
  /// {
  ///   "types": { "EIP712Domain": [...], "Order": [...] },
  ///   "primaryType": "Order",
  ///   "domain": { "name": "TPIX Trade", "version": "1", "chainId": 4289 },
  ///   "message": { "pair": "BTC-USDT", "amount": "0.5", ... }
  /// }
  /// ```
  ///
  /// Wallet shows the structured message for user review before signing.
  /// Currently signs as personal_sign(json) — same as WalletConnect's
  /// simplified handler. Full EIP-712 struct hashing is a future upgrade.
  ///
  /// Returns the 0x-prefixed hex signature, or null on reject/timeout.
  Future<String?> requestTypedSignature(Map<String, dynamic> typedData) async {
    final nonce = _generateNonce();
    final completer = Completer<String?>();
    _pending[nonce] = completer;
    _updateCount();

    final typedJson = jsonEncode(typedData);
    final uri = Uri(
      scheme: _walletScheme,
      host: 'sign-typed',
      queryParameters: {
        'typed': typedJson,
        'nonce': nonce,
        'callback': _callbackUrl,
        'from': 'trade',
      },
    );

    try {
      final launched = await launchUrl(
        uri,
        mode: LaunchMode.externalApplication,
      );
      if (!launched) {
        _removePending(nonce);
        return null;
      }
    } catch (e) {
      debugPrint('LinkedWalletSigner.requestTypedSignature: ${e.runtimeType}');
      _removePending(nonce);
      return null;
    }

    try {
      return await completer.future.timeout(_timeout);
    } on TimeoutException {
      _removePending(nonce);
      return null;
    } catch (_) {
      _removePending(nonce);
      return null;
    }
  }

  /// Ask the linked wallet app to sign AND broadcast a transaction (BSC).
  ///
  /// Wallet เปิด confirm sheet ให้ผู้ใช้ตรวจ (เครือข่าย/ปลายทาง/จำนวน/את data)
  /// ยืนยันแล้ว wallet เซ็นด้วย key ของตัวเอง broadcast ขึ้นเชน แล้วส่ง
  /// tx hash กลับผ่าน `tpixtrade://tx-result?kind=tx&nonce=..&txhash=0x..`
  ///
  /// คืน tx hash (0x + 64 hex) หรือ null เมื่อผู้ใช้ปฏิเสธ/หมดเวลา/ส่งไม่ได้
  Future<String?> requestTransaction({
    required String to,
    String? valueHex, // 0x... (wei)
    String? dataHex, // 0x... calldata
    int chainId = 56,
    String? summary, // ข้อความอธิบาย action ให้ผู้ใช้อ่านใน wallet
  }) async {
    final nonce = _generateNonce();
    final completer = Completer<String?>();
    _pendingTx[nonce] = completer;
    _updateCount();

    final txJson = jsonEncode({
      'chainId': chainId,
      'to': to,
      if (valueHex != null) 'value': valueHex,
      if (dataHex != null) 'data': dataHex,
      if (summary != null) 'summary': summary,
    });

    final uri = Uri(
      scheme: _walletScheme,
      host: 'sign-tx',
      queryParameters: {
        'tx': txJson,
        'nonce': nonce,
        'callback': _txCallbackUrl,
        'from': 'trade',
      },
    );

    try {
      final launched = await launchUrl(
        uri,
        mode: LaunchMode.externalApplication,
      );
      if (!launched) {
        _removePendingTx(nonce);
        return null;
      }
    } catch (e) {
      debugPrint('LinkedWalletSigner.requestTransaction: ${e.runtimeType}');
      _removePendingTx(nonce);
      return null;
    }

    try {
      return await completer.future.timeout(_txTimeout);
    } on TimeoutException {
      _removePendingTx(nonce);
      return null;
    } catch (_) {
      _removePendingTx(nonce);
      return null;
    }
  }

  /// Called by DeepLinkService when `tpixtrade://tx-result?...` arrives.
  void completeTransaction({
    required String nonce,
    String? txHash,
    String? error,
  }) {
    final completer = _pendingTx.remove(nonce);
    _updateCount();
    if (completer == null) {
      debugPrint('LinkedWalletSigner: tx callback for unknown nonce');
      return;
    }
    if (completer.isCompleted) return;

    if (txHash != null && _isValidTxHash(txHash)) {
      completer.complete(txHash);
    } else {
      debugPrint('LinkedWalletSigner: tx failed (error=$error)');
      completer.complete(null);
    }
  }

  /// Cancel ALL pending signs — used by the banner's Cancel button.
  /// Resolves their Completers to null so awaiting calls return without
  /// hanging until the natural 90s timeout.
  void cancelAllPending() {
    for (final c in _pending.values) {
      if (!c.isCompleted) c.complete(null);
    }
    for (final c in _pendingTx.values) {
      if (!c.isCompleted) c.complete(null);
    }
    _pending.clear();
    _pendingTx.clear();
    _pendingCount.value = 0;
  }

  /// Internal — remove + update reactive count
  void _removePending(String nonce) {
    _pending.remove(nonce);
    _updateCount();
  }

  void _removePendingTx(String nonce) {
    _pendingTx.remove(nonce);
    _updateCount();
  }

  void _updateCount() {
    _pendingCount.value = _pending.length + _pendingTx.length;
  }

  /// Called by DeepLinkService when `tpixtrade://sign-result?...` arrives.
  /// Resolves the matching pending request (if any).
  ///
  /// Accepts:
  ///   - nonce + signature → success
  ///   - nonce + error     → failure (returns null)
  ///   - unknown nonce     → ignore (could be stale or spoofed)
  ///
  /// คืน true เมื่อมีคำขอในหน่วยความจำรออยู่จริง — false = ไม่รู้จัก nonce
  /// (ผู้เรียกไปดู [takePersisted] ต่อได้ว่าเป็นคำขอของแอปที่ถูกเปิดใหม่ไหม)
  bool completeSignature({
    required String nonce,
    String? signature,
    String? error,
  }) {
    final completer = _pending.remove(nonce);
    _updateCount();
    if (completer == null) {
      // Unknown nonce — either timed out already or spoofed. Ignore.
      debugPrint('LinkedWalletSigner: callback for unknown nonce');
      return false;
    }
    if (completer.isCompleted) return true;

    if (signature != null && _isValidSignature(signature)) {
      completer.complete(signature);
    } else {
      // User rejected, error, or invalid signature format
      debugPrint('LinkedWalletSigner: sign failed (error=$error)');
      completer.complete(null);
    }
    return true;
  }

  bool isValidSignature(String s) => _isValidSignature(s);

  /// 16 random bytes → 32 hex chars (collision-free for our use case)
  String _generateNonce() {
    final rng = Random.secure();
    final bytes = List<int>.generate(16, (_) => rng.nextInt(256));
    return bytes
        .map((b) => b.toRadixString(16).padLeft(2, '0'))
        .join();
  }

  /// 0x + 130 hex chars (65 bytes — r + s + v)
  bool _isValidSignature(String s) {
    return RegExp(r'^0x[a-fA-F0-9]{130}$').hasMatch(s);
  }

  /// 0x + 64 hex chars — transaction hash
  bool _isValidTxHash(String s) {
    return RegExp(r'^0x[a-fA-F0-9]{64}$').hasMatch(s);
  }
}
