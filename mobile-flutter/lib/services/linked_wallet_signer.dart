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
import 'package:url_launcher/url_launcher.dart';

class LinkedWalletSigner {
  LinkedWalletSigner._();
  static final LinkedWalletSigner _instance = LinkedWalletSigner._();
  factory LinkedWalletSigner() => _instance;

  /// Pending sign requests — nonce → Completer
  /// Cleared when callback arrives or timeout fires.
  final Map<String, Completer<String?>> _pending = {};

  /// Reactive pending count — UI listens to this via ValueListenableBuilder
  /// to show/hide the "Waiting for TPIX Wallet" banner.
  final ValueNotifier<int> _pendingCount = ValueNotifier<int>(0);
  ValueListenable<int> get pendingCount => _pendingCount;
  bool get hasPending => _pendingCount.value > 0;

  /// User-facing timeout for completing the sign in the wallet app.
  static const _timeout = Duration(seconds: 90);

  /// Wallet app scheme. Hard-coded — only TPIX Wallet supports this protocol.
  static const _walletScheme = 'tpixwallet';

  /// Callback URL the wallet app will open with the result.
  static const _callbackUrl = 'tpixtrade://sign-result';

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
  Future<String?> requestSignature(String message) async {
    final nonce = _generateNonce();
    final completer = Completer<String?>();
    _pending[nonce] = completer;
    _pendingCount.value = _pending.length;

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
        return null;
      }
    } catch (e) {
      debugPrint('LinkedWalletSigner.requestSignature: ${e.runtimeType}');
      _removePending(nonce);
      return null;
    }

    // Wait for callback OR timeout
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
    _pendingCount.value = _pending.length;

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

  /// Cancel ALL pending signs — used by the banner's Cancel button.
  /// Resolves their Completers to null so awaiting calls return without
  /// hanging until the natural 90s timeout.
  void cancelAllPending() {
    for (final c in _pending.values) {
      if (!c.isCompleted) c.complete(null);
    }
    _pending.clear();
    _pendingCount.value = 0;
  }

  /// Internal — remove + update reactive count
  void _removePending(String nonce) {
    _pending.remove(nonce);
    _pendingCount.value = _pending.length;
  }

  /// Called by DeepLinkService when `tpixtrade://sign-result?...` arrives.
  /// Resolves the matching pending request (if any).
  ///
  /// Accepts:
  ///   - nonce + signature → success
  ///   - nonce + error     → failure (returns null)
  ///   - unknown nonce     → ignore (could be stale or spoofed)
  void completeSignature({
    required String nonce,
    String? signature,
    String? error,
  }) {
    final completer = _pending.remove(nonce);
    _pendingCount.value = _pending.length;
    if (completer == null) {
      // Unknown nonce — either timed out already or spoofed. Ignore.
      debugPrint('LinkedWalletSigner: callback for unknown nonce');
      return;
    }
    if (completer.isCompleted) return;

    if (signature != null && _isValidSignature(signature)) {
      completer.complete(signature);
    } else {
      // User rejected, error, or invalid signature format
      debugPrint('LinkedWalletSigner: sign failed (error=$error)');
      completer.complete(null);
    }
  }

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
}
